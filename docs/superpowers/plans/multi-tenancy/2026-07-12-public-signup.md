# Public Self-Serve Signup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship dual-mode public signup — member signup (join the resolved store, per-workspace switch) and workspace signup (found a workspace, platform switch) — verify-first, abuse-controlled, both OFF by default.

**Architecture:** A system-global `signup_intents` store (`pending → email_verified → provisioning → consumed`; expiry is a condition, hard-deleted on observation) carries every flow, including authenticated workspace creation (enters at `email_verified`). An intent-bound verifier reuses the users extension's OTP/notification primitives with new intent-keyed storage; a rotating hash-only continuation token family (grace slot + idempotency keys) makes provisioning retryable after commit-before-response failures. Member activation runs inside `TenantContextRunner::runAsTenant()` with `SignupRolePolicy` revalidation; workspace provisioning is a resumable saga around the existing create→seed→activate flow. Abuse: layered rate limits, atomic daily caps, a fail-closed `SignupChallenge` seam, enumeration-neutral responses, and an audit/telemetry split.

**Tech Stack:** PHP 8.3+, PostgreSQL, `glueful/tenancy ^2.0.0` (no engine change — `TenantAdministration`, `TenantContextRunner`, 2A authority suffice), `glueful/users` (OTP + notification primitives, `UserRepository`, profiles), Thallo packs + admin SPA, PHPUnit against real PostgreSQL.

## Global Constraints

- **HOLD ALL COMMITS.** Stage only. `dev`. No attribution, no tags, never stage `CLAUDE.md`.
- **Thallo-only** (confirmed: engine ^2.0.0 seams suffice; no contracts/engine/framework change).
- **Both capabilities OFF by default**; with both off, every existing suite is byte-identical.
- **PHP style:** `declare(strict_types=1)`, `final`, constructor DI, `use`-imports, phpcs clean. SPA: setup stores, `data-testid`, no tail-piped tsc.
- **Verify-first:** no user row, membership, tenant, slug, or active anything before email proof. Users created with `email_verified_at` set; `first_name`/`last_name` (required for anonymous; ≤100 chars — the profiles-table width) applied via the users-ext profile path.
- **States:** `pending → email_verified → provisioning → consumed`; **expiry is a condition, not a status** — expired non-consumed intents are hard-deleted at first observation or by the sweep (password hashes are transferable credentials). `completion_outcome ∈ activated|workspace_active|existing_account_handoff`.
- **`SignupRolePolicy`:** eligible = active + assignable (2A authority) ∧ not `owner` ∧ effective set (2A `EffectiveRoleMatrix::capabilitiesFor`) contains none of `tenant.roles.manage`, `tenant.members.manage`, `tenant.domains.manage`. Runs at setting-save AND activation.
- **Continuation token family:** hash-only; `current_hash` + short-lived `previous_hash`/`previous_operation_id`/`previous_valid_until` grace slot; every continuation mutation carries a client idempotency key + canonical payload hash and persists `none → in_progress → complete`. DB-only mutations rotate + complete in their transaction. The workspace saga records `in_progress` and resumes from persisted intent state. A previous token is accepted at most once, in-window, with the identical operation id + payload hash; payload substitution/reuse invalidates the family; email re-verification is the final recovery; consumed with the intent.
- **Route profiles:** member intent/join = strict public host resolution only; workspace intent = no workspace selection (host/headers ignored); verify/continuation = target exclusively from the intent.
- **Existing accounts:** never password/profile-mutated by an intent; handoff outcome + authenticated join.
- **Abuse:** per-IP + normalized-email intent limits; per-intent verifier attempt lockout; resend keyed intent+email+IP; **global daily caps per capability via an atomic shared counter** (never process memory); `SignupChallenge` seam (no-op default, **fail-closed** when a configured provider is unavailable); enumeration-neutral responses.
- **Audit after commit** for persisted transitions; **immediate best-effort security telemetry** for failed OTP/challenge/rate events; plaintext OTPs/tokens are never stored anywhere.
- **Hard enable-time email-channel validation** for both switches + diagnostics drift reporting.

---

## File Structure

- `app/Signup/SignupConfig.php` — switches (workspace settings via `SettingsStore`; platform via `SystemChannel`/flags) + enable-time email-channel validation.
- `app/Signup/SignupRolePolicy.php` — role eligibility.
- `app/Signup/SignupIntentRepository.php` — intent CRUD, state transitions, expiry-as-condition hard-delete, locks.
- `app/Signup/SignupVerifier.php` — intent-keyed OTP using `OTP::hashOTP()`/`verifyHashedOTP()`.
- `app/Signup/SignupMailSender.php` — sends the users verification template through `NotificationService` without invoking the users extension's email-keyed OTP store.
- `app/Signup/ContinuationTokens.php` — token family (issue/rotate/grace/consume/invalidate).
- `app/Signup/SignupThrottle.php` — layered limits + atomic daily caps.
- `app/Signup/SignupChallenge.php` (contract) + `NullSignupChallenge.php` — challenge seam.
- `app/Signup/SignupTelemetry.php` — immediate best-effort security telemetry.
- `app/Signup/MemberSignupService.php`, `app/Signup/WorkspaceSignupService.php` — the flows.
- `app/Http/Controllers/SignupController.php` + `routes/signup.php` — endpoints per the §6 table.
- `packages/thallo-tenancy/migrations/006_CreateSignupTables.php` — intents + verifier + continuation + counters.
- `config/schedule.php` — intent sweep entry; `packages/thallo-tenancy/src/Enablement/TenancyDiagnostics.php` — switch/channel drift.
- SPA: workspace settings (member-signup toggle + policy-filtered role picker), platform settings (workspace-signup toggle) + tests.
- Tests under `tests/Integration/Signup/` + `tests/Unit/Signup/`.

---

### Task 1: Config, switches & `SignupRolePolicy`

**Files:**
- Create: `app/Signup/SignupConfig.php`, `app/Signup/SignupRolePolicy.php`
- Modify: `config/thallo.php` (or the verified app feature-config home; add the config default for the exact system key `tenancy.signup.workspaces.enabled`), `packages/thallo-tenancy/src/Enablement/TenancyDiagnostics.php`
- Register both in `ThalloServiceProvider::services()`.
- Test: `tests/Integration/Signup/SignupConfigTest.php`, `tests/Unit/Signup/SignupRolePolicyTest.php`

**Interfaces:**
- Produces:
  - `SignupConfig::memberSignupEnabled(string $tenantUuid): bool`; `memberSignupRole(string $tenantUuid): string` (default `viewer`); `setMemberSignup(string $tenantUuid, bool $enabled, string $role): void` — every `SettingsStore` read/write runs inside `TenantContextRunner::runAsTenant($tenantUuid, ...)`, including admin and diagnostics calls; validates the role via `SignupRolePolicy` and refuses enablement without a working email channel (`RuntimeException` with a clear message).
  - `SignupConfig::workspaceSignupEnabled(): bool`; `setWorkspaceSignup(bool $enabled): void` — persists the exact `tenancy.signup.workspaces.enabled` key through the unscoped `SystemChannel` (config default false), same email-channel gate; reads require `enforcementActive()` to report true.
  - `SignupConfig::emailChannelAvailable(): bool` — probes the email-notification capability (verify the exact probe the forgot-password path uses; reuse it).
  - `SignupRolePolicy::isEligible(string $tenantUuid, string $roleSlug): bool`; `eligibleRoles(string $tenantUuid): list<string>` — active+assignable (2A authority/lifecycle), not `owner`, and `EffectiveRoleMatrix::capabilitiesFor($tenantUuid, $roleSlug)` ∩ `{tenant.roles.manage, tenant.members.manage, tenant.domains.manage}` = ∅.

- [ ] **Step 1: Failing tests** — policy: `viewer` eligible; `owner` never; a custom role granted `tenant.members.manage` ineligible; a disabled custom role ineligible. Config: defaults off; two tenants persist/read distinct member settings even when the caller begins outside tenant context; enabling member signup with an ineligible role throws; enabling either switch without the email channel throws; the workspace key round-trips through `SystemChannel` under the exact pinned name and reads false when `enforcementActive()` is false even if persisted true.
- [ ] **Step 2:** verify failure.
- [ ] **Step 3:** implement both classes (read `app/Settings/SettingsStore.php`, `TenantContextRunner`, and `SystemChannel` first; wrap every tenant-setting operation explicitly); add the diagnostics drift check (switch on + channel missing → flagged, member checks run under their target tenant).
- [ ] **Step 4:** run → PASS; phpcs. **Step 5: Stage (HOLD).**

---

### Task 2: `signup_intents` schema + repository + sweep

**Files:**
- Create: `packages/thallo-tenancy/migrations/006_CreateSignupTables.php` (next free number — verify), `app/Signup/SignupIntentRepository.php`
- Modify: `config/schedule.php` (sweep entry, daily), the pack/app provider for a `SignupIntentSweepJob`.
- Test: `tests/Integration/Signup/SignupIntentRepositoryTest.php`

**Interfaces:**
- Produces:
  - Tables (system-global, no cross-package FK per pack convention): `signup_intents` (`uuid`, `kind` `member|workspace`, `origin` `anonymous|authenticated`, `email` normalized, `username`, `first_name` s100 null, `last_name` s100 null, `password_hash` null, `tenant_uuid` s12 null, `desired_slug` s64 null, `workspace_name` s160 null, `result_user_uuid` s12 null, `result_tenant_uuid` s12 null, `status` s20, `completion_outcome` s32 null, `expires_at`, `request_ip_hash` s64 null, `consumed_at` null, `created_at`/`updated_at`; indexes on email, status, expires_at); `signup_verifiers` (`intent_uuid` unique, `otp_hash`, `attempts` int, `expires_at`, `consumed_at` null); `signup_continuations` (`intent_uuid` unique, `current_hash`, `previous_hash` null, `previous_operation_id` null, `previous_valid_until` null, `last_operation_id` null, `last_operation_payload_hash` null, `last_operation_status` `in_progress|complete` null, `last_operation_result` jsonb null, `updated_at`); `signup_rate_counters` (`dimension`, `bucket_hash`, `window_start`, `count`; unique `(dimension,bucket_hash,window_start)`); `signup_daily_counters` (`capability` s20, `day` date, `count` int; unique `(capability, day)`).
  - `SignupIntentRepository::create(array $fields): string`; `lockForUpdate(string $uuid): ?array` (row-locked read that **hard-deletes and returns null when expired** — expiry is a condition); `transition(string $uuid, string $from, string $to): bool` (guarded CAS update); `setResults(...)`, `consume(string $uuid, string $outcome): void`; `hardDelete(string $uuid): void`; `sweepExpired(): int`; `sweepConsumedBefore(DateTimeImmutable $cutoff): int`.
  - `consume()` is one transaction: record outcome/`consumed_at`, null `password_hash` + request metadata, and explicitly delete verifier + continuation rows. Completion history retains no transferable credential. A short configurable retention sweep then hard-deletes sanitized consumed rows.
- [ ] **Step 1: Failing tests** — CAS transitions (0-rows → false); `lockForUpdate` on an expired intent deletes the row and returns null (verifier + continuation rows explicitly deleted in the same transaction); sweep prunes only expired non-consumed rows; `consume` records the outcome while nulling password/request metadata and deleting verifier/continuation rows for both successful provisioning and existing-account handoff; consumed-retention sweep deletes only sanitized rows older than its cutoff.
- [ ] **Step 2–4:** verify failure → implement (migration mirrors pack style; sweep job follows the slice-3 sweep-job pattern with the kill-switch read and prunes expired intents, retention-expired sanitized consumed intents, and obsolete rate-counter windows; schedule entry per `config/schedule.php` shape) → PASS. **Step 5: Stage (HOLD).**

---

### Task 3: Intent-bound verifier + continuation token family

**Files:**
- Create: `app/Signup/SignupVerifier.php`, `app/Signup/SignupMailSender.php`, `app/Signup/ContinuationTokens.php`
- Test: `tests/Integration/Signup/SignupVerifierTest.php`, `tests/Integration/Signup/ContinuationTokensTest.php`

**Interfaces:**
- Produces:
  - `SignupMailSender::sendVerification(string $email, string $otp): void` — sends the existing verification template through `NotificationService` directly. It **must not call** `EmailVerification::sendVerificationEmail()`, because that method writes its own email-keyed OTP. A regression test asserts the users extension's `email_verification:<email>` cache key is never created.
  - `SignupVerifier::issue(string $intentUuid, string $email): void` — `EmailVerification::generateOTP()` → `Glueful\Security\OTP::hashOTP()` → store on `signup_verifiers` → `SignupMailSender::sendVerification()`. `verify()` uses `OTP::verifyHashedOTP()`; HMAC/SHA-256 is forbidden for the six-digit code. Verification is attempt-limited (config, default 5), single-use, expiry-checked; failures go to `SignupTelemetry`.
  - `ContinuationTokens::issue(string $intentUuid): string`; `authorizeInTransaction(string $intentUuid, string $token, string $operationId, string $canonicalPayloadHash): ContinuationGrant`; `completeInTransaction(string $intentUuid, string $operationId, array $result): string`; `invalidate()`; `reissueViaReverification()`. Final consumption is owned by `SignupIntentRepository::consume()`, which removes the family in the same transaction as intent scrubbing.
  - Callers open the transaction first. `authorizeInTransaction()` row-locks the token family, rotates current→previous, and writes `last_operation_{id,payload_hash,status=in_progress}` in that same transaction. A rollback therefore rolls back authorization and rotation. DB-only mutation + `completeInTransaction()` commit atomically. A workspace provisioning transaction commits `in_progress` alongside persisted intent/result UUID state; replay with the previous token + identical operation/payload resumes from that state and cannot alter the payload. `complete` records `status=complete` + a secret-free result (continuation plaintext is never written to `last_operation_result`). A completed commit whose response is lost can be replayed once with the previous token to obtain the recorded result and a separately generated fresh current token. Different operation/payload, second reuse, or expired grace invalidates the family.
- [ ] **Step 1: Failing tests** — issuing an intent creates no users-extension email-keyed OTP; two simultaneous intents for one email verify independently; OTP hash verifies through `OTP::verifyHashedOTP`; wrong OTP × limit → lockout + telemetry; OTP single-use. Continuation: failure before transaction commit leaves T1 current; committed `in_progress` saga accepts T1 once for the identical operation/payload and resumes; DB-only op A commits mutation+complete+rotation atomically; response-loss replay returns its recorded result and a fresh token; changed payload/op, second previous-token use, or post-window use invalidates the family; consume ends everything; re-verification issues a fresh chain.
- [ ] **Step 2–4:** fail → implement → PASS. **Step 5: Stage (HOLD).**

---

### Task 4: Abuse primitives — throttle, atomic caps, challenge seam, telemetry

**Files:**
- Create: `app/Signup/SignupThrottle.php`, `app/Signup/SignupChallenge.php` (interface), `app/Signup/NullSignupChallenge.php`, `app/Signup/SignupTelemetry.php`
- Test: `tests/Integration/Signup/SignupThrottleTest.php`, `tests/Unit/Signup/SignupChallengeTest.php`

**Interfaces:**
- Produces:
  - `SignupThrottle::allowIntent(string $capability, string $ip, string $email): bool`; `allowResend(string $intentUuid, string $email, string $ip): bool`; `consumeDailyCap(string $capability): bool`. Every dimension is atomic across workers: use the framework limiter only if its increment is verified atomic for the configured backend; otherwise use `signup_rate_counters` with one `INSERT … ON CONFLICT … DO UPDATE … WHERE count < :cap RETURNING` per hashed IP/email/intent window. Daily caps use the same PostgreSQL pattern against `signup_daily_counters`. A read-then-write cache fallback is forbidden.
  - `SignupChallenge::validate(Request $request): bool`; `NullSignupChallenge` returns true; the provider binding is config-keyed — when config names a provider that the container cannot supply (or its config is incomplete), the resolver returns a **fail-closed** challenge (always false) rather than the null one.
  - `SignupTelemetry::record(string $event, array $context): void` — immediate, best-effort (never throws), no OTPs/tokens/passwords/raw IPs or emails in context; writes to the security telemetry/logger channel, **never** `TenancyLifecycleAudit` or another persisted lifecycle-audit recorder. Successful committed transitions use the separate after-commit audit path.
- [ ] **Step 1: Failing tests** — two independent connections racing each per-IP, per-email, resend, and daily dimension never exceed its cap; raw IP/email values never appear in counter keys; misconfigured challenge provider fails closed; null default passes; telemetry never throws with the recorder absent.
- [ ] **Step 2–4:** fail → implement → PASS. **Step 5: Stage (HOLD).**

---

### Task 5: Member signup flow (intent → verify/activate → authenticated join)

**Files:**
- Create: `app/Signup/MemberSignupService.php`, `app/Http/Controllers/SignupController.php` (member endpoints), `routes/signup.php` (loaded from the app routes bootstrap — verify how `routes/*.php` register).
- Test: `tests/Integration/Signup/MemberSignupFlowTest.php`

**Interfaces:**
- Consumes: Tasks 1–4 + `SingleStoreTenant::resolve()`, the host-resolved tenant (public resolution), `TenantContextRunner::runAsTenant`, `TenantAdministration::addMember`, `UserRepository::create` (+ the profile path — verify whether `create()` writes `first_name`/`last_name` itself via `userProfileFields` or needs the separate profile write Thallo's `applyProfile` does; mirror whichever is real), `email_verified_at` stamping (verify the column/mechanism the users ext uses).
- Produces:
  - `POST /v1/signup/member` — public group (strict host resolution; `rate_limit` + challenge + throttle + daily cap): resolve target store (off-mode `SingleStoreTenant`, on-mode host-resolved; header/param selection impossible by route profile) → switch check → **enumeration-neutral 202** always; internally: existing email → intent recorded for handoff (verification email routes to sign-in); else intent `pending` + verifier issue.
  - `POST /v1/signup/verify` — no tenant resolution: OTP verify → CAS `pending → email_verified` → issue continuation → for member kind, proceed inside `runAsTenant($intent['tenant_uuid'])` and one outer application-connection transaction: `lockForUpdate` → **revalidate** (workspace active, switch still on, role still `SignupRolePolicy`-eligible) → create user (`email_verified_at` set; names via profile path) → `addMember` with the configured role → `consume('activated')` (including credential scrubbing). Existing-account intent → `consume('existing_account_handoff')` in a clean transaction with no account mutation. Username conflict → 409 + replacement-username continuation operation.
  - `POST /v1/signup/member/join` — authenticated; same strict host rule; same `runAsTenant` + policy revalidation; adds membership for the signed-in (email-verified) user; no intent needed.
- [ ] **Step 1: Failing tests** — single-store off-mode join lands in the single store; on-mode host-resolved workspace; switch off → neutral 202 but nothing persisted; role disabled between intent and verify → activation refused (intent stays `email_verified` for operator inspection or expiry); user/profile/membership/consume participate in one transaction and a failpoint rolls all four back; user created with `email_verified_at` + profile names; existing email → handoff outcome, password untouched and submitted hash scrubbed; authenticated join honors policy; `X-Tenant-Id` on verify ignored.
- [ ] **Step 2–4:** fail → implement → PASS. **Step 5: Stage (HOLD).**

---

### Task 6: Workspace signup saga (anonymous + authenticated origins)

**Files:**
- Create: `app/Signup/WorkspaceSignupService.php`; extend `SignupController` + `routes/signup.php`.
- Test: `tests/Integration/Signup/WorkspaceSignupSagaTest.php`, `tests/Integration/Signup/SignupProvisioningTransactionTest.php`

**Interfaces:**
- Consumes: Tasks 1–4, `TenantAdministration::create`, `TenantSeedActivator::seedAndActivate` + the seed-repair path (`TenantSeedRepair` — verify idempotence contract), `enforcementActive()`.
- Produces:
  - `POST /v1/signup/workspace` — public, **no workspace selection** (system/public profile): platform switch (+ `enforcementActive()`) → challenge/throttle/caps → slug shape + reserved-label validation (the `create()` rules) + non-binding availability hint → intent `pending` (anonymous) → verifier issue. **Authenticated variant** (same route, authenticated request, or a sibling route — implementer picks; document): requires the account's email be verified; creates the intent with `origin=authenticated`, `password_hash` null, `result_user_uuid` = the account, entering directly at `email_verified` with a continuation issued.
  - Saga (verify endpoint / continuation operations; target exclusively from the intent):
    1. One outer transaction on the application connection: continuation `authorizeInTransaction()` + intent `lockForUpdate` → CAS `email_verified → provisioning` → recheck slug (`tenants.slug` unique arbiter — catch 23505 as the race loser) + username (anonymous only; users uniques authoritative) → create user + profile (anonymous only) → `TenantAdministration::create($c, slug, name, $userUuid)` → persist `result_user_uuid`/`result_tenant_uuid` and the continuation operation as `in_progress` → commit. Nested transactions must participate as savepoints on this same connection.
    2. `TenantSeedActivator::seedAndActivate($tenantUuid, $ownerUuid)` (its own transactions; `markActive` inside).
    3. `consume('workspace_active')` + rotate-consume the continuation.
  - **Retry** (continuation, same operation id + payload hash): if the operation is `in_progress` and `result_*` UUIDs exist → skip step 1, run the idempotent seed-repair, consume. Never a second user/tenant. A completed response-loss replay returns the recorded result rather than seeding again.
  - **Slug conflict** → 409 + `change_slug` continuation operation (new slug validated, intent updated, retry); **username conflict** → `change_username` operation.
- [ ] **Step 1a: Transaction-participation proof** — capture the outer PDO identity/transaction level; assert user, profile, tenant, owner membership, intent UUIDs/status, and continuation `in_progress` all use that connection. Fail after each write boundary and prove every one rolls back together. Then prove seeding begins only after that commit and retains the deliberate repairable-provisioning posture.
- [ ] **Step 1b: Flow tests** — happy path end-to-end (anonymous): workspace active, owner membership, starter seeded, outcome recorded; seed failure (failpoint) → retry via continuation reuses UUIDs, no duplicates, repair completes; two verified claimants for one slug → one wins, loser gets 409 + succeeds with a new slug; a member intent and workspace intent for the same email race through provisioning → exactly one user row, loser is consumed as `existing_account_handoff`, winning password/profile remain unchanged; authenticated origin skips OTP, requires verified email, same durable saga; switch off / enforcement inactive → refused.
- [ ] **Step 2–4:** fail → implement → PASS. **Step 5: Stage (HOLD).**

---

### Task 7: Admin surfaces + SPA

**Files:** workspace settings page (member-signup toggle + role picker fed by `SignupRolePolicy::eligibleRoles` via a small endpoint), platform settings (workspace-signup toggle), SPA store + `admin/src/__tests__/signupSettings.spec.ts`. Verify current settings-page structure first; follow `workspaceRoles.spec.ts` harness.

- [ ] Failing SPA tests (toggle renders, role picker lists only eligible roles, enable-without-email-channel renders the 422) → implement → `pnpm test` + `pnpm type-check` green. Stage (HOLD).

---

### Task 8: Regression, live smoke, docs

- [ ] **Both-off equivalence:** full off/on suites green with both switches off; grep-level check that no existing route/middleware changed.
- [ ] **Raw-PDO classification:** update `app/Content/Starter/RawPdoWriteAudit.php` and `tests/Unit/Tenancy/RawPdoScopingLintTest.php` for every new `getPDO()`/raw statement. Classify `signup_intents`, `signup_verifiers`, `signup_continuations`, `signup_rate_counters`, and `signup_daily_counters` explicitly as system-global signup readers/writers; assert they are not added to `ThalloTenantTables`; preserve the unclassified-site failure so a new raw mutation cannot bypass review.
- [ ] **Full suites + phpcs + boundaries.**
- [ ] **Live smoke on `lemma`:** migrate; enable member signup on the single store (with the email channel configured); walk intent → OTP → activation; verify the profile row; try the enumeration-neutral duplicate; disable and confirm 202-neutral-but-inert.
- [ ] **Docs:** ops guide (switch semantics, email-channel dependency, abuse knobs, continuation recovery, sweep); tracking index → 2B implemented (HELD) — **Bucket 2 complete**.
- [ ] Stage (HOLD); no commits.

---

## Self-Review

**1. Spec coverage:** §1 switches/policy/enable-validation → Task 1. §2 intents/verifier/continuation family/hard-delete-expiry/outcomes → Tasks 2, 3. §3 member flow incl. existing-account handoff + authenticated join + `runAsTenant` + profile names → Task 5. §4 saga + persisted UUIDs + conflicts + authenticated-origin-on-the-saga → Task 6. §5 abuse/telemetry split → Task 4 (consumed by 5–6). §6 route profiles + admin surfaces + no-HTML → Tasks 5–7. §7 modes/lifecycle → Tasks 1, 5, 6 tests. §8/§9 failure modes + tests → distributed per task + Task 8. §10 out of scope respected. ✅

**2. Placeholder scan:** Tasks 7 (SPA) and several "verify the exact seam" notes name concrete files/greps and fallbacks — deliberate verify-at-task-time steps. The authenticated-workspace route shape is delegated to the implementer with a document-it instruction (spec left the path open). No TBDs.

**3. Type consistency:** `SignupIntentRepository::{create,lockForUpdate,transition,setResults,consume,hardDelete,sweepExpired,sweepConsumedBefore}` consistent across Tasks 2, 5, 6; `SignupVerifier::{issue,verify}`, `SignupMailSender::sendVerification`, and `ContinuationTokens::{issue,authorizeInTransaction,completeInTransaction,invalidate,reissueViaReverification}` consistent across Tasks 3, 5, 6; `SignupThrottle`/`SignupChallenge`/`SignupTelemetry` consistent across Tasks 4–6; `SignupConfig`/`SignupRolePolicy` consistent across Tasks 1, 5, 7.
