# Admin-Settable Public Origin (base domain + default hosts) — Design

**Status:** Design (spec review) · **Date:** 2026-07-12 · **Track:** Multi-tenancy follow-up
**Repos:** `glueful/framework` (one new primitive) → `glueful/thallo` (feature). No `contracts`/`tenancy`-engine change.
**Posture:** HELD (no commits), `dev`.

## Goal

Let an operator set multi-tenancy's **public origin** — the routing **base domain** and the
**default tenant hosts** — from the admin UI, persisted, instead of hand-editing `.env`. This
removes the `.env` edit + guesswork that today blocks activating full resolution (the Resolution →
Activate button 422s with "At least one default tenant host must be configured", and the fix lives
only in `TENANCY_DEFAULT_HOSTS`/`TENANCY_BASE_DOMAIN` + a restart the UI never surfaces).

## Honest scope — what this does and does NOT change

- **Removes** the `.env` hand-edit: base domain + hosts become persisted admin settings, editable in
  the workspaces settings page above the Resolution panel.
- **Does NOT remove the restart.** The `awaiting_fresh_boot → full` barrier in
  `ResolutionActivationStore` is keyed on **process identity** (`bootId`), deliberately — the process
  that arms resolution cannot complete it. That is independent of where hosts come from and stays.
  The activation flow already models this with its "Reload and continue" step; this feature makes the
  *whole* flow UI-driven around that existing restart, rather than promising a fixed restart count.
- **`.env` stays a fallback.** When the persisted values are unset, the env-sourced
  `config('tenancy.public_origin.*')` values stand unchanged. Existing installs (and the current
  `lemma` `.env`) keep working byte-for-byte.

## Architecture

**Persist → hydrate → (unchanged) consume.**

1. **Persist** the base domain, default hosts, and a monotonically-changing **origin revision** in the
   existing system-global `SystemFlags` store (`thallo_system_flags`, string values, `get/put/forget`).
2. **Hydrate at boot:** a Thallo-owned `PublicOriginStore` (shared/singleton) reads the persisted
   values, first snapshots the underlying file/env fallback and then, when a flag is set,
   **overrides** `config('tenancy.public_origin.base_domain')` and
   `config('tenancy.public_origin.default_hosts')` via the new framework override primitive, before the
   request-time resolver chain is constructed. It records, for this process's lifetime, **which revision
   it hydrated** and the boot-applied values. The admin surface distinguishes the persisted **desired**
   origin (flag when present, otherwise captured fallback) from the current process's **applied** origin;
   after a changed PUT those intentionally differ until restart.
3. **Consume unchanged:** every existing reader keeps reading `config('tenancy.public_origin.*')` —
   `FullResolutionActivation::requiredHosts()` (`packages/thallo-tenancy/src/Resolution/FullResolutionActivation.php:203`),
   `ThalloFullResolutionReadiness` (`:36`), the vendor request-time `SubdomainResolver`
   (`vendor/glueful/tenancy/src/Resolution/Resolvers/SubdomainResolver.php:25`), `WorkspaceSignupService`,
   and the blob URL provider. **No consumer changes.** This is why hydration (not consumer read-through)
   is required: the `SubdomainResolver` lives in vendor and cannot be edited.

### Release surface — the one framework change (call-out)

The framework config repository (`ApplicationContext`) has **no runtime `set()` that wins over file
config** — only `mergeConfigDefaults()`, which merges *under* the file (file wins), and
`config/tenancy.php` already declares `public_origin`. So the override cannot be done with today's API.

**Framework addition:** `ApplicationContext::overrideConfig(string $key, mixed $value): void` — a
dot-path, **process-local boot override** with explicit precedence
`extension defaults < file/env config < process override`. Overrides live in their own
`configOverrides` layer (never by mutating `loadedConfigs`), survive `clearConfigCache()`, and are
merged over the loaded top-level config on every reload. Writing a nested key invalidates the entire
top-level namespace (`tenancy` here), including cached parent reads such as
`tenancy.public_origin`, not only the exact leaf. The method rejects calls after
`ApplicationContext::markBooted()` so callers cannot create split-brain services by changing config
mid-request. General-purpose and reusable; tenancy is the first caller. Release chain:
**framework → thallo**, vendor-first (edit the app's vendored framework copy first, test live, then
port to framework source + release). Pin the framework version in `thallo/composer.json` only after
the framework is published. `ApplicationContext::markBooted()` currently has no caller, so the same
framework task MUST call it from `Framework::boot()` after all boot phases complete and immediately
before `Framework::$booted = true`; otherwise the post-boot guard would be inert.

> The plan MUST re-confirm, as its first step, that no winning-override seam already exists and that
> `overrideConfig()` values are visible to later `config($context, $key)` reads in the same process.

## Data model — `SystemFlags` keys

| Key | Type (serialized) | Meaning |
|---|---|---|
| `tenancy.public_origin.base_domain` | string (normalized host) | subdomain-routing base; unset ⇒ file/env config fallback |
| `tenancy.public_origin.default_hosts` | comma-joined normalized hosts | default-tenant apex hosts; unset ⇒ file/env config fallback |
| `tenancy.public_origin.revision` | string token (`bin2hex(random_bytes(16))`) | bumped on every semantically changed write; drives the stale-activation gate |

All values are strings (the store has no array type); `default_hosts` is comma-joined on write and
split on read, matching how `config/tenancy.php:6-10` already parses `TENANCY_DEFAULT_HOSTS`.

## Binding requirements (the four pins)

### Pin 1 — Prevent stale activation (origin revision gate)

Model on the existing `bootId` barrier (`ResolutionActivationStore::markAwaitingFreshBoot()`/
`completeFull()`, `packages/thallo-tenancy/src/Resolution/ResolutionActivationStore.php:55,87`).

- Every public-origin write first normalizes and compares the semantic values. An unchanged PUT is
  idempotent and does **not** write or require a restart. Every changed write bumps
  `tenancy.public_origin.revision` to a fresh token inside the same transaction as the value writes.
- Public-origin writes use the same `EnablementLock` as resolution activation, with lock ordering
  **EnablementLock → database transaction**. The write re-reads the resolution step after acquiring
  the lock, then compares/persists. This closes the check-to-activate race: if the PUT wins, the next
  activation sees the new revision and requires a fresh boot; if activation wins and advances out of
  `INACTIVE`, the PUT re-check sees a non-INACTIVE step and returns 409. Because the lock uses
  `pg_try_advisory_lock`, a truly simultaneous contender may instead receive the existing lock-conflict
  409 and retry; the invariant is that activation can never proceed against a concurrently changed,
  unhydrated origin.
- `PublicOriginStore` records the revision it hydrated at boot into **process-local** state
  (`private readonly ?string $hydratedRevision`), NOT into SystemFlags — exactly like `bootId` is
  per-process.
- `PublicOriginStore::assertFreshForActivation(): void` clears the `SystemFlags` read cache before
  loading the persisted revision (a remote HTTP/CLI process may have changed it), then throws a distinct
  `EnablementException` ("Public origin changed since this process started — restart required before
  activating.") when `!hash_equals((string)$hydratedRevision, (string)$flags->get(revision))`.
- **`FullResolutionActivation::advance()` MUST call `assertFreshForActivation()` under the enablement
  lock but before its failure-recording `try/catch`; `retry()` MUST call it before
  `ResolutionActivationStore::retry()`.** Staleness is a restart requirement, not an activation
  failure: it must propagate without changing `INACTIVE` to `FAILED` or mutating a retry pointer. A
  `restart_required` UI hint is insufficient — activation must hard-refuse against stale config, not
  rely on the SPA.
- `status()` surfaces the staleness as a boolean (e.g. `origin_restart_required`) so the SPA can guide
  the restart without promising a count.

### Pin 2 — Restrict writes server-side (lifecycle)

- Public-origin **writes are allowed only while resolution activation has NOT begun**: `store->step()
  === ResolutionActivationStep::INACTIVE`.
- Reject writes (HTTP **409**, enumeration-neutral message) for every other step —
  `MAPPING_HOSTS`, `VERIFYING_WIRING`, `REBUILDING_ROUTES`, `AWAITING_FRESH_BOOT`, `FULL`, and `FAILED`
  (FAILED holds a resume pointer). Enforced **server-side**, independent of whether the SPA hides the
  form. Editing an active/`full` public origin is a **separate lifecycle design** (see Out of scope).
- A failed attempt has an explicit `FullResolutionActivation::resetFailed(): array` recovery action.
  Under the enablement lock, and only from `FAILED`, it lists the default tenant's domains and releases
  mappings whose normalized hosts are in the currently configured `default_hosts`, then clears the
  route cache and atomically clears failure/failed-from/awaiting-boot state and returns the step to
  `INACTIVE`. Resolution is not `FULL`, so required-host protection is not active; normal
  `releaseDomain()` records cooldown ownership for the same default tenant, allowing that tenant to
  reclaim a retained host later. Any cleanup failure leaves the machine in `FAILED`. The operator can
  then correct the origin and start again. `deactivate()` remains a `FULL`-only transition and is not
  presented as failed-state recovery.

### Pin 3 — Reuse host normalization (no new regex)

- Reject any input containing `:` before normalization (configured origins are hostnames, never
  `host:port`; this also rejects bracketless address forms) without adding a domain regex. Then
  normalize every host and the base domain through the existing
  `Glueful\Extensions\Tenancy\Resolution\HostNormalizer::normalize()`
  (`vendor/glueful/tenancy/src/Resolution/HostNormalizer.php:13`) — it lowercases/trims,
  rejects IPs, wildcards (`*`), IPv6-bracket forms, and malformed/single-label hosts (throws
  `InvalidHostException`). **Do not introduce another domain regex.**
- **Deduplicate** normalized hosts; reject an empty resulting list.
- Sort the normalized host set before comparison/persistence. Host order has no routing meaning, so
  reordering the same set is an idempotent no-op and must not bump the revision.
- Enforce reserved/system-host rules via
  `HostNormalizer::validateForRegistration($host, $publicOrigin, $allowBaseDomain)` (`:45`), passing the
  effective **proposed** `public_origin` (new normalized base + existing `reserved_labels`). Validate
  default hosts with `$allowBaseDomain = true`: the base/apex is the canonical default-tenant host and
  MUST be accepted, while `www./api./admin.<base>` remain rejected by the reserved-label check. The base
  domain itself is validated by `normalize()` only.
- On any `InvalidHostException`, return a field-scoped **422** naming the offending value.

### Pin 4 — Hydrate early enough

- Hydration MUST run in a provider `boot()` that executes **before** the request-time resolver chain is
  constructed. Verified: the resolver chain is a **lazy container factory** resolved at request time
  (`vendor/glueful/tenancy/src/TenancyServiceProvider.php:90-95`), so any `boot()` qualifies; the
  always-on `TenancyControlPlaneProvider` (app provider, `config/serviceproviders.php:13`) boots before
  the extension providers.
- The plan MUST verify **provider ordering** (which provider is guaranteed to boot whenever tenancy is
  active) and the config repository's runtime override behavior, and choose the hydration provider so it
  runs in every install where resolution can occur, without editing the vendor engine (prefer a
  Thallo-owned provider `boot()`; if none boots early enough in all cases, escalate before touching
  vendor).
- **Test:** after a fresh boot, a persisted override **wins over** the `.env`/config value; with the
  persisted values unset, the env/config value is used unchanged.

## Admin API

Add to `packages/thallo-tenancy/routes/enablement.php`, in the existing `/v1/admin` group, with the
identical guard stack `auth` + `tenant_system` + `content_permission:tenancy.manage`:

- `GET  /v1/admin/tenancy/public-origin` → status: desired `base_domain`, `default_hosts` (array),
  boot-applied `applied_base_domain`, `applied_default_hosts`,
  each value's **source** (`flag` | `config` | `unset`), current `step`, and
  `origin_restart_required`. The framework cannot distinguish an env-derived config-file value from a
  literal config-file value, so the fallback is truthfully reported as `config`, not `env`.
- `PUT  /v1/admin/tenancy/public-origin` → body `{ base_domain: string|null, default_hosts: string[] }`.
  Validates (Pin 3), enforces the write-lifecycle gate (Pin 2 → 409), and, when values changed,
  persists all values + bumps the revision in one transaction (Pin 1), then returns the new status.
  Enumeration-neutral, structured errors.
  `null` base means "remove the persisted override and use the captured file/env fallback"; validation
  evaluates hosts against that fallback base, not against the old hydrated override. Missing keys,
  non-string/non-null base values, non-array hosts, and any non-string host entry are field-scoped 422s,
  never silently coerced or dropped.
- `POST /v1/admin/tenancy/resolution/reset` → invokes `resetFailed()`; accepted only at `FAILED`, uses
  the same guard stack, returns 409 for lifecycle/lock conflicts, and returns the normal resolution
  status envelope on success.

Backed by a thin `PublicOriginController` + a `PublicOriginService`/`PublicOriginStore` that owns
read/write/normalize/hydrate/revision. Mirror the body-validated `TenancyEnablementController::confirm`
pattern (`packages/thallo-tenancy/src/Http/Controllers/TenancyEnablementController.php:39-60`) and
`Response::success`/`Response::error`/`Response::validation` envelopes.

## Admin SPA

- **Query:** `admin/src/queries/publicOrigin.ts` — GET/PUT over `authFetch` mirroring
  `admin/src/queries/signupSettings.ts` (unwrap `data.public_origin`; `fetchPublicOrigin()` +
  `savePublicOrigin({ base_domain, default_hosts })`).
- **Component:** `admin/src/components/tenancy/PublicOriginSettings.vue` — mirror
  `FirstTenantConfirmForm.vue` (props/emit/`UFormField`+`UInput`, parent owns busy/errors) and the
  `WorkspaceSignupSettings.vue` card language. A base-domain `UInput` + a hosts editor (comma/line list
  → `string[]`), dirty-aware Save, server-error surfacing, `data-testid` hooks
  (`public-origin/base-domain/hosts/save`).
  The form edits desired values. While `origin_restart_required` is true it may also show the applied
  values as the currently running configuration, so a successful save never appears to revert to the
  old boot snapshot.
- **Placement:** on `admin/src/pages/settings/workspaces/index.vue`, **directly above the Resolution
  panel** (it is resolution's prerequisite), shown once multi-workspace mode is enabled and resolution
  is not yet `full`. Freeze the form (read-only) when the write-lifecycle gate would reject
  (`step !== 'inactive'`), and surface `origin_restart_required` as a "restart to continue" note derived
  from status — never a hardcoded restart count.
- At `FAILED`, the Resolution panel exposes **Reset activation**. It calls the reset endpoint, explains
  that current required-host mappings will be released, and reloads both resolution and public-origin
  status. The form becomes editable only after the server returns `INACTIVE`.
- **Wiring:** the Resolution panel's "hosts required" 422 becomes actionable because the origin form
  sits right above it.

## Flow (restart count derived by the state machine, not promised by the UI)

```
save changed origin (revision bumps; an unchanged PUT is a no-op)
  → activation refuses without changing INACTIVE: this process hydrated an older revision
    → origin_restart_required
  → restart (fresh process hydrates new revision; assertFreshForActivation passes)
  → advance: mapping_hosts → verifying_wiring → rebuilding_routes → markAwaitingFreshBoot (stamps bootId)
  → awaiting_fresh_boot
  → restart/reload (fresh process, new bootId)
  → completeFull → full
```

Whether this is one restart or two depends on **when** activation begins relative to the last origin
save (if the current process already hydrated the latest revision, the revision gate is already
satisfied and only the `bootId` restart remains). The SPA reflects `origin_restart_required` and
`fresh_boot_required` from `status()`; it must not assert a fixed number of restarts.

## Out of scope (follow-up)

Editing base domain / default hosts **after** resolution is `full` — this requires re-mapping and
re-verifying live default-tenant domains (add/remove `addPreverifiedDomain` wiring, host cooldown) and
is a separate lifecycle design. v1 covers the **setup** path only (edit while `INACTIVE`).

## Testing

- **Regression (must be byte-identical):** with both persisted values unset, every existing tenancy
  suite passes unchanged and `config('tenancy.public_origin.*')` resolves exactly as today (env/file).
- **Hydration:** persisted override wins over `.env` after a fresh boot; unset ⇒ env fallback; override
  visible to `SubdomainResolver` at request time.
- **Framework primitive:** `overrideConfig()` wins over file config and is visible to later `config()`
  reads in-process; overrides survive `clearConfigCache()`; parent and child cached reads are both
  invalidated; `Framework::boot()` marks the context booted; post-boot override attempts are rejected;
  no existing config test regresses.
- **Pin 1:** activation refuses when the process's hydrated revision ≠ a fresh persisted revision,
  leaves the step/failure pointer unchanged, and passes after a fresh hydrate. A semantically unchanged
  PUT does not bump the revision. A two-session write-vs-activate test proves the shared lock permits
  write-first → restart required, activation-first → PUT 409, or immediate lock-contention 409 followed
  by a safe retry, never stale activation.
- **Pin 2:** writes accepted at `INACTIVE`; rejected 409 at every other step (incl. `FAILED`, `FULL`).
  Failed reset releases only the configured required-host mappings, remains FAILED if cleanup fails,
  and returns to editable INACTIVE only after complete cleanup.
- **Pin 3:** IPs, `*` wildcards, `:port`, single-label, and `www./api./admin.<base>` are rejected; the
  base/apex itself is accepted as a default host; valid hosts normalized + deduped; empty list rejected.
- **Desired/applied:** changed PUT returns the new desired values while preserving the old applied
  values until restart; clearing a flag falls back to the pre-hydration config snapshot; host-set
  reordering is a no-op.
- **API/SPA:** GET/PUT contract, guard stack, enumeration-neutral errors; component dirty/save/freeze
  behavior and `origin_restart_required` note (vitest, jsdom).

## Release chain

1. Framework: add `ApplicationContext::overrideConfig()`; test; **release** (framework skill).
2. Thallo: build the feature against the vendored framework copy first; after the framework is
   published, pin `thallo/composer.json` and swap off the vendored copy.
