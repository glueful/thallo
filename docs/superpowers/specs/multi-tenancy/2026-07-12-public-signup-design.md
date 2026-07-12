# Public Self-Serve Signup — Design

**Status:** spec in review (HELD — not committed)
**Slice:** Bucket 2, item 2B (public self-serve signup) — **dual-mode**: for plain single-store
Thallo AND tenancy-enabled installs.
**Release chain:** Thallo-only expected (engine is `glueful/tenancy ^2.0.0`; `TenantAdministration`,
`TenantContextRunner`, the 2A role authority, and the users extension's OTP/notification primitives
cover the needs — confirm at plan time).
**Date:** 2026-07-12

---

## §0 Context — as-built (source-verified)

- **No public registration exists anywhere.** `glueful/users` ships account *lifecycle* only
  (verify-email/OTP, resend, forgot/reset password via `AccountController`; an `EmailVerification`
  service) plus admin-created users (Thallo `UserAdminController::store`, `users.create` permission,
  pre-verified). Workspaces are operator-provisioned (`TenantManagementController::create` behind
  `BootstrapTenantCreationGuard`). 2B builds the product's **first unauthenticated write surface**.
- **`EmailVerification` stores OTPs keyed by email only** and consumes on verification — it cannot
  distinguish simultaneous member/workspace intents for one email. `generateOTP()` is reusable,
  but `sendVerificationEmail()` is not a pure sender: it first writes that same email-keyed OTP.
  Signup therefore uses the framework OTP primitive plus a Thallo-owned notification sender that
  renders the existing verification template without invoking the email-keyed store.
- **Workspace creation is deliberately not one transaction:** `create()` commits a `provisioning`
  tenant + owner membership; `TenantSeedActivator` then seeds in its own transaction and calls
  `markActive()`; a seeding failure leaves a repairable provisioning tenant
  (`thallo:tenant:seed` repair path).
- **`tenants.slug` unique constraint is the final slug arbiter**; `TenantAdministration::create()`
  validates reserved labels + host shape. The released-host **cooldown governs domain claims, not
  tenant slugs**. `UserRepository::create()` **requires `username`**; users-table unique constraints
  are authoritative for email/username.
- **Infra to reuse:** `SingleStoreTenant::resolve()` (single-store mode), strict public host
  resolution (the collections rule: no header/param workspace selection on public surfaces),
  `TenantContextRunner::runAsTenant()`, the 2A role authority + assignable-roles surface,
  `enforcementActive()`, `rate_limit` middleware, slice-2 lifecycle, after-commit audit
  (`TenancyLifecycleAudit` style), `thallo:tenancy:diagnose`.

---

## §1 Two capabilities & switches (both OFF by default)

Signup is **two distinct capabilities** — different authorization and lifecycle operations, separate
endpoints, configuration, rate limits, verification policies, and UI entry points. `/signup`
semantics never change when an install later enables tenancy.

- **Member signup — "join this site/workspace."** Per-workspace switch in workspace settings:
  `signup.members.enabled` (default off) + `signup.members.role`. In single-store mode the single
  store's settings govern plain Thallo — the same feature, not a special case. The granted role is
  configurable among roles accepted by a dedicated **`SignupRolePolicy`** (default `viewer`). A
  role is eligible only when it is active + assignable under the 2A authority, is not `owner`, and
  its effective capability set contains none of the governance capabilities
  `tenant.roles.manage`, `tenant.members.manage`, or `tenant.domains.manage`. The same policy runs
  when the setting is saved and again at activation; a later role edit can therefore make a
  previously selected role ineligible without granting it to a new signup.
  Every settings read/write runs under `TenantContextRunner::runAsTenant($tenantUuid, ...)`; the
  explicit UUID is never merely advisory to a `SettingsStore` scoped by some other request tenant.
- **Workspace signup — "found a new workspace."** Platform-level switch
  (`tenancy.signup.workspaces.enabled` + admin surface), persisted through the unscoped
  `SystemChannel`/`SystemFlags` channel with config as the default, meaningful only when
  `enforcementActive()`; refused otherwise.

**Enable-time validation (hard):** either switch refuses to enable unless a working
email-verification channel is available (verify-first is load-bearing, so this is a hard dependency
— unlike the forgot-password soft posture). Missing channel with a switch on surfaces in
`thallo:tenancy:diagnose`.

---

## §2 Signup intents, verifier & states

**`signup_intents`** (system-global, structured columns — no generic `target` blob): `uuid`, `kind`
(`member|workspace`), `origin` (`anonymous|authenticated`), `email` (normalized), `username`,
`first_name`, `last_name` (required for anonymous signup; trimmed, length-bounded to the
profiles-table column widths), `password_hash` (hashed at anonymous intent creation — plaintext
never stored; null for authenticated origin), `tenant_uuid` (member target; null for workspace),
`desired_slug` + `workspace_name` (workspace kind), `result_user_uuid` + `result_tenant_uuid`
(persisted by the provisioning saga), `status`, `completion_outcome` (`activated|workspace_active|
existing_account_handoff`, null until consumed), `expires_at` (TTL ~24h), request metadata for
telemetry, timestamps.

**State machine:** `pending → email_verified → provisioning → consumed`. Expiry is a condition,
not a persisted status: an expired non-consumed intent is unusable and is hard-deleted at first
observation or by the scheduled sweep. Authenticated workspace intents enter directly at
`email_verified`; an existing-account handoff reaches `consumed` with its explicit outcome rather
than pretending account or membership provisioning succeeded.
- Reaching `consumed` atomically clears `password_hash` and request metadata and deletes verifier +
  continuation rows. Sanitized completion history has a short retention window and is then
  hard-deleted; no successful or handoff intent retains a transferable password hash.
- OTP verification happens outside any provisioning transaction and consumes verifier state, so the
  proof is **persisted first** (`email_verified`) — provisioning is retryable after database or
  seeding failures **without another OTP**.
- **Expired intents are hard-deleted promptly** (scheduled sweep + checked-at-use): their password
  hashes are transferable credentials and must not linger.

**Intent-bound verifier** (new): keyed by `intent_uuid`, hashes and verifies with
`Glueful\Security\OTP::{hashOTP,verifyHashedOTP}`, stores attempt count (lockout on exhaustion),
expiry, and single-use consumption. It sends through the Thallo signup mailer above; HMAC/SHA-256 is
not sufficient for the six-digit code. Distinguishes simultaneous member/workspace intents for one
email.

**Continuation credential:** issued when the intent reaches `email_verified`; stored **hash-only**,
intent-bound, TTL = intent expiry. **Rotates on every accepted provisioning or conflict-repair
request** (previous value invalidated for normal use); permanently consumed when the intent reaches
`consumed`. Every continuation mutation carries a client-generated idempotency key stored with the
rotation result. Rotation uses a token family with `current_hash` plus a short-lived
`previous_hash`, `previous_operation_id`, and `previous_valid_until` grace slot: the previous token
is accepted at most once during the grace window, only with the identical operation id, and only to
recover the same canonical payload after a commit-before-response failure. Operation state is
persisted as `none → in_progress → complete`: DB-only mutation, rotation, and completion share one
transaction; the workspace saga commits `in_progress` with its result UUIDs and resumes from that
state. Recovery rotates to a fresh current token and cannot change the operation payload. Reuse
outside that exact case invalidates the family. An email re-verification operation is the final recovery path: it
invalidates the entire family, performs a fresh intent-bound OTP proof, and issues a new
continuation. The consumed OTP is never accepted again.

---

## §3 Member signup

- **`POST /v1/signup/member`** (public; strict public **host** resolution — tenancy-off →
  `SingleStoreTenant::resolve()`, tenancy-on → the host-resolved workspace; never header/param
  selection): check the workspace's switch → create intent + send verification. Response is neutral
  (existing account, pending intent, unknown email all look alike).
- **`POST /v1/signup/verify`** (verification + activation; the target comes **exclusively from the
  intent** — this endpoint performs no request-tenant resolution): validate OTP → persist
  `email_verified` → then, under the intent lock and **inside
  `TenantContextRunner::runAsTenant($intent->tenant_uuid, …)`** (verification may arrive on a
  central endpoint without the original host context): **revalidate policy** — workspace still
  active, member signup still enabled, configured role still accepted by `SignupRolePolicy` (never
  a snapshot) → create the user (**with `email_verified_at` already set**, and the intent's
  `first_name`/`last_name` applied through the users extension's existing profile path — the same
  mechanism as admin creation's `applyProfile`) → activate the
  membership via `TenantAdministration::addMember` (2A authority + engine locks apply) → consume the
  intent. Username conflict at provisioning → conflict response; the `email_verified` intent may
  submit a replacement username via its continuation credential (users-table uniques authoritative).
- **Existing accounts:** an intent matching an existing email **never touches that account's
  password**. The verification email routes to sign-in; the authenticated
  **`POST /v1/signup/member/join`** endpoint (same strict host resolution, same
  `runAsTenant` + policy revalidation, same role config) lets signed-in users join a workspace with
  member signup enabled.
- **Same-email race:** if another intent creates the users-table row after this intent was verified
  but before its provisioning transaction wins, the email unique violation is classified as an
  existing-account handoff, not a retryable user-creation failure. No password/profile mutation or
  membership grant occurs; the intent is consumed with `completion_outcome=existing_account_handoff`
  and the recipient is directed to sign in and use the authenticated join path.

---

## §4 Workspace signup (resumable saga, not one transaction)

- **`POST /v1/signup/workspace`** (public; **system/public route profile with no workspace selected
  from host or headers**; platform switch; challenge + caps): validate desired slug shape/reserved
  labels (the `create()` rules) with a **non-binding availability hint**; create intent
  (`desired_slug` is expiring **intent, not a reservation**) + send verification.
- **Verification → provisioning saga** (target exclusively from the intent):
  1. **Transaction:** lock the intent → atomically recheck slug (`tenants.slug` unique is the
     arbiter; first verified claimant wins) and username (users uniques authoritative) → create the
     user (`email_verified_at` set; `first_name`/`last_name` applied via the profile path) → create
     the `provisioning` tenant + owner membership → persist
     `result_user_uuid` + `result_tenant_uuid` on the intent → status `provisioning` → commit.
  2. **Seed** using the existing transactional seeder (`TenantSeedActivator` → `markActive()`).
  3. Mark the intent `consumed` after activation.
  - **Recovery:** once the UUIDs are committed on the intent, a retry (continuation credential)
    **reuses them** and calls the idempotent seed-repair path — it never creates a second user or
    tenant. A seed failure leaves user + provisioning tenant + verified intent repairable — the
    same posture as operator-created workspaces.
  - **Slug conflict** on recheck → clear conflict response; the intent retries with a new slug via
    its (rotated) continuation credential until it expires.
- **Authenticated workspace creation ships in v1** (a user who joined one workspace can found
  another): an authenticated endpoint using the **same** platform switch, challenge/caps, slug
  checks, provisioning saga, and seeder — skipping email verification because the account is
  already verified. It requires a non-null `email_verified_at`, not authentication alone. It creates
  the same durable `signup_intents` record with `origin=authenticated`, nullable password/profile
  inputs, `result_user_uuid` prefilled from the authenticated principal, and initial status
  `email_verified`; retries and seed recovery therefore use the identical persisted saga rather
  than an in-memory special path. No host/header workspace selection on this route either.
- **Same-email race:** if a member and workspace intent for one email provision concurrently, the
  users-table unique constraint selects one creator. The losing anonymous intent follows the
  existing-account handoff and never applies its submitted password. After sign-in, the user may
  start authenticated workspace creation; the losing anonymous intent is not silently rebound to
  the account.

---

## §5 Abuse controls, telemetry & enumeration hardening

- **Rate layers:** per-IP + normalized-email limits on intent creation; per-intent verifier attempt
  limits (lockout); **resend limits keyed by intent + normalized email + IP** (new users have no
  account); **global daily caps per capability**. Every counter is atomic across workers (verified
  atomic cache increment or PostgreSQL upsert); read-then-write cache counters and process memory
  are forbidden. Raw identifiers are hashed before becoming counter keys.
- **`SignupChallenge` seam:** `validate(request): pass|fail`, no-op default; **fail closed** when a
  provider is configured but its binding/configuration is unavailable. CAPTCHA vendor integrations
  stay outside core Thallo.
- **Enumeration hardening:** neutral responses for existing accounts, pending intents, and unknown
  emails alike.
- **Audit vs telemetry (split):** successful persisted transitions (intent created, verified,
  member activated, workspace provisioned, conflicts resolved) emit **after commit**; failed OTPs,
  challenge rejections, and rate-limit hits have no commit and are recorded **immediately through
  best-effort security telemetry**. Neither path ever stores OTPs, challenge tokens, or raw
  sensitive request data.

---

## §6 Surfaces & route profiles

JSON endpoints only — public signup *pages* are theme/site-owned; core ships no signup HTML.

| Route | Auth | Tenant selection |
|---|---|---|
| `POST /v1/signup/member` | public | **strict host resolution only** |
| `POST /v1/signup/member/join` | authenticated | strict host resolution only |
| `POST /v1/signup/workspace` | public | **none** (system/public profile; no host/header selection) |
| `POST /v1/signup/verify` (+ continuation retry/repair/re-verification) | public (OTP / continuation credential) | **exclusively from the intent** |
| authenticated workspace create | authenticated | none |

Admin surfaces: workspace settings gain the member-signup toggle + server-derived role picker
(roles accepted by `SignupRolePolicy`); platform settings gain the workspace-signup toggle; both refuse
enablement without the email channel; diagnostics reports switch/channel incoherence. SPA: setup
stores, `data-testid` hooks.

---

## §7 Modes & lifecycle interactions

Member signup works in every mode via the resolved store (single-store off-mode/compat; host-resolved
workspace when `enforcementActive()`). Workspace signup requires `enforcementActive()`.
Signup-created workspaces are ordinary tenants — slice-2 trash/purge, 2A roles, domain
re-verification apply unchanged. The intent sweep is housekeeping (expiry checked at use); the
prompt hard-delete of expired intents is a security requirement (§2).

---

## §8 Failure modes

- Switch enabled without an email channel → refused at enable time; diagnostics flags drift.
- OTP verified, then DB/seed failure → `email_verified`/`provisioning` intent + continuation
  credential allow retry without a new OTP; workspace retries reuse the persisted UUIDs.
- Rotation committed but its response was lost → the one-use previous-token grace recovers the same
  intent; suspicious reuse invalidates the family; fresh email verification can mint a new family
  without accepting the consumed OTP.
- Two verified claimants for one slug → unique-constraint arbiter; loser gets a conflict + retries
  with a new slug via a rotated continuation token.
- Username taken at provisioning (either kind) → conflict + replacement username via continuation.
- Role disabled / signup switched off / workspace suspended between intent and activation →
  activation-time revalidation refuses.
- Existing-account email → password never overwritten; sign-in route + authenticated join.
- Authenticated workspace creation by an account without `email_verified_at` → refused before an
  intent or tenant is created.
- Concurrent intents for one email → one users-table insert wins; every loser follows the
  existing-account handoff and never mutates the winning account.
- Verifier exhaustion / challenge failure / rate-limit hit → refused + security telemetry (no audit
  rows without commits).
- Verification without host context → correct workspace via the intent + `runAsTenant`.
- Central endpoint given `X-Tenant-Id` or a host → ignored; the intent is the only target source.
- Intent expiry (a condition, not a stored status) → hard-deleted; continuation + OTP unusable.

---

## §9 Testing

- Intent state machine: `pending → email_verified → provisioning → consumed`; retry-after-seed-failure
  without a new OTP; hard-delete on expiry (row gone, not flagged).
- Verifier: intent-keyed disambiguation of simultaneous member+workspace intents for one email;
  `OTP::hashOTP` at rest; no users-extension email-keyed OTP created; attempt lockout; single-use.
- Continuation: hash-only at rest; rotates on each accepted provisioning/conflict-repair request
  (old value normally refused); commit-before-response recovery accepts the previous hash at most
  once inside its grace window and only for the stored idempotency key/payload; replay or payload
  substitution invalidates the family; email re-verification replaces a lost family; consumed
  permanently at `consumed`.
- Member: activation inside `runAsTenant` with policy revalidation (disabled role / disabled signup
  / suspended workspace / role rejected by `SignupRolePolicy` → refused); setting writes reject the
  same governance-capability set; `email_verified_at` set on creation;
  `first_name`/`last_name` land on the profile (both signup kinds); missing/overlong name fields are
  422 at intent creation; authenticated join honors the same rules; single-store mode joins the
  single store.
- Workspace saga: UUIDs persisted then reused on retry (no duplicate user/tenant); slug race
  arbiter; username conflict + replacement; authenticated create skips verification but shares
  switch/caps/saga through an `origin=authenticated` intent and refuses an unverified account;
  seed-failure repair parity with operator flow; concurrent anonymous intents for one email produce
  one user and explicit existing-account handoffs for the losers.
- Abuse: per-IP/email/intent/resend limits; atomic global caps (two workers cannot exceed the cap);
  challenge fail-closed on misconfiguration; enumeration-neutral responses (byte-comparable).
- Switches: the workspace-signup switch round-trips through `SystemChannel` with config fallback;
  neither tenant scoping nor a process restart changes its value.
- Route profiles: member honors host only; workspace/verify ignore host + headers.
- Audit/telemetry split: no audit rows for uncommitted failures; telemetry rows for OTP/challenge/
  rate failures; no plaintext OTPs or continuation credentials stored anywhere.
- Credential lifecycle: both successful and existing-account-handoff consumption scrub the password
  hash/request metadata and delete verifier/continuation rows; sanitized consumed rows age out.
- Regression: full off/on suites; both capabilities off by default leave every existing suite
  byte-identical.

---

## §10 Out of scope

Bundled CAPTCHA integrations; social/OAuth signup; invitation flows (operator-invites-member is a
separate feature); paid plans/quotas/billing; custom per-workspace verification policies beyond
on/off; public signup HTML pages in core.
