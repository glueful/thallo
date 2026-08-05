# Platform Payments Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Payvia gateway credentials become app-owned, platform-scoped (system channel), with a neutral Settings → Payments surface, a marker-gated deployment-safe cutover, and a conservative migration — retiring Commerce's ownership of them.

**Architecture:** One Thallo repo, no upstream releases. Foundation first (SystemKeys prefix routing + the platform store + the legacy compatibility reader), then the crux rebinding (app-owned `PlatformPayviaSettingsOverride` with the marker-gated resolution order, Commerce binding removed in the same commit), then the migration command, the neutral controller/SPA, and end-to-end regressions + docs.

**Tech Stack:** PHP 8.3 / Glueful framework (app-level `app/`, packages/thallo-commerce retirement), Vue 3 + vitest (admin SPA).

**Spec:** `docs/internal/superpowers/specs/2026-08-05-platform-payments-settings-design.md` — §1 rulings and §2 component contracts govern verbatim; §3's test list is distributed into the tasks below.

## Global Constraints

- Repo `/Users/michaeltawiahsowah/Sites/glueful/thallo`, branch `dev`. Single-repo program; no publication gates.
- Resolution order (spec §2 Binding, verbatim): (1) `PlatformPaymentSettingsStore`; (2) only while `payments.platform_credentials_migrated` is absent, `LegacyPlatformPaymentSettingsReader`; (3) `null` → Payvia config/env. Platform writes never dual-write to legacy storage; a marked installation can never regress to a legacy value.
- Neither source is ever selected via ambient tenant context; the reader never calls `SettingsStore`, `runAsTenant()`, or any current-tenant helper.
- Secrets: encrypted at rest exactly as today (framework `EncryptionService`, AAD = the full settings key string — ciphertext migrates verbatim); boolean-only `{set, source}` on every read surface; no secret-derived material (plaintext/prefix/suffix/hash) ever crosses a response boundary.
- Migration semantics per spec §2 Migration verbatim: platform values always preserved; pre-retrofit unscoped row / post-retrofit persisted-default-workspace row adopted only where absent; other-workspace rows NEVER adopted (`--acknowledge-workspace-conflicts` acknowledges obsolescence; no `--adopt-from`); verification is real-value comparison (missing/undecryptable = failure, never null==null); `--prune-legacy` separate + re-verifies; marker written LAST; partial/completed reruns idempotent.
- No capability dependency: credential resolution byte-identical with `thallo.commerce`/`thallo.subscriptions` on, off, absent.
- The app binding must be effective on cached production boots (established rule: EXTENSION-provider `register()` never runs there; verify empirically which app-level hook runs in BOTH boot modes before choosing, and pin it with a cached-boot test mirroring `EngineNativeRoutesCachedBootTest`'s harness).
- Gates per task, IN THE FOREGROUND: full PHP suite (the 8 stale-`.env` Paystack environmental failures are a known baseline IF still present — compare by name against the Task-14 list in the checkout program's ledger), `composer phpcs`, `composer boundaries`; SPA tasks add `npx vitest run` (1 known StorePanel.vue user-WIP failure may persist), `npm run -s type-check`, `npm run -s build`.
- Conventional commits, ONE per task; NO AI-attribution trailers; stage files explicitly (the user's uncommitted `admin/src/pages/commerce/settings/components/StorePanel.vue` WIP must never be staged, if still present).
- Execution note: the authoring session's subagent budget is exhausted — execute via SDD in a fresh session (ledger: `.superpowers/sdd/2026-08-05-platform-payments-settings/progress.md`) or directly.

---

### Task 1: SystemKeys prefix routing + `SettingsStore::all()` filtering

**Files:**
- Modify: `app/Settings/SystemKeys.php`, `app/Settings/SettingsStore.php`
- Test: `tests/Integration/Settings/SystemKeyPrefixRoutingTest.php` (new)

**Interfaces:**
- Produces: `SystemKeys::PREFIXES = ['payvia.']` (list<string>); `SystemKeys::isSystem(string $key): bool` returns true for exact `KEYS` members OR any key starting with a `PREFIXES` entry. `SettingsStore::get()/putMany()/forget()` already branch on `isSystem()` (lines ~52/66/100 — unchanged mechanics, now prefix-aware). `SettingsStore::all()` (line ~40) FILTERS every exact- or prefix-classified key out of the returned tenant map (spec §2: closes the raw-read path).

- [ ] **Step 1: Failing tests:** `payvia.default_gateway` and `payvia.gateways.stripe.secret_key` route get/putMany/forget to the `SystemChannel` (recording double); a pre-seeded tenant-owned `payvia.*` row is invisible through `get()` AND absent from `all()`; exact system keys (`admin_url`) behave as before; non-payvia keys (`commerce.store_name`) unaffected in all four methods; `isSystem()` unit matrix (prefix boundary: `payvia.` prefix matches, bare `payvia` and `payviax.foo` do not).
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(settings): system-channel prefix routing for payvia keys`.

### Task 2: `PlatformPaymentSettingsStore`

**Files:**
- Create: `app/Settings/PlatformPaymentSettingsStore.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (binding, shared)
- Test: `tests/Integration/Settings/PlatformPaymentSettingsStoreTest.php` (new)

**Interfaces:**
- Produces: `final class PlatformPaymentSettingsStore { __construct(ApplicationContext $context, SystemChannel $system) }` with `get(string $key): ?string`, `putMany(array<string,string> $pairs): void`, `forget(string $key): void`. Secret subkeys (`secret_key`, `webhook_secret` — same `SECRET_SUBKEYS` recognition as `SettingsStorePayviaOverride`, ported) encrypted with `EncryptionService`, AAD = the full key string; non-secret keys plain. Reads null-never-throw: undecryptable/tampered/storage-throw ⇒ null. Lift the encryption/decryption code from `packages/thallo-commerce/src/Settings/SettingsStorePayviaOverride.php` — same idiom, same AAD, so legacy ciphertext round-trips.

- [ ] **Step 1: Failing tests:** secret write ⇒ stored value in `thallo_system_flags` is ciphertext (not the plaintext substring), read round-trips; ciphertext written under the SAME key by the legacy commerce path decrypts through this store (AAD-compat proof: encrypt via the ported helper, read via the store); tampered ciphertext ⇒ null (no throw); non-secret keys stored plain; forget deletes; SystemChannel throw ⇒ null read.
- [ ] RED → implement → GREEN + gates → commit `feat(settings): platform payment settings store over the system channel`.

### Task 3: `LegacyPlatformPaymentSettingsReader`

**Files:**
- Create: `app/Settings/LegacyPlatformPaymentSettingsReader.php`
- Test: `tests/Integration/Settings/LegacyPlatformPaymentReaderTest.php` (new)

**Interfaces:**
- Produces (spec §2 verbatim): read-only, TEMPORARY. `value(string $key): ?string` (candidate row's decrypted value or null) and `conflicts(): array<string, list<array{tenant_uuid: string, key: string}>>` (other-workspace `payvia.*` rows — diagnostic data for Task 5, values never returned). Schema introspection each call (exception-safe `hasColumn('settings', 'tenant_uuid')`): PRE-RETROFIT (column absent) ⇒ the one unscoped row is the candidate; POST-RETROFIT ⇒ only the row whose tenant equals the persisted `tenancy.default_tenant_uuid` (read via `SystemFlags::defaultTenantUuid()` — the same pointer `SingleStoreTenant` trusts). Direct `db($context)->table('settings')` queries ONLY — never `SettingsStore`, never `runAsTenant()`, never a current-tenant helper (grep-proof this in review). Secret decryption via the same ported AAD helper; undecryptable ⇒ null for `value()` but still a real row for the migration's verification bookkeeping (expose `raw(string $key): ?array{value: string, decryptable: bool}` for Task 5).

- [ ] **Step 1: Failing tests:** pre-retrofit fixture (drop/absent tenant_uuid column via a scoped schema fixture — or a dedicated table double mirroring the pre-006 shape; choose the approach the harness supports and document) ⇒ unscoped row adopted; post-retrofit ⇒ default-workspace row adopted, OTHER workspace's row never returned by `value()` but enumerated by `conflicts()`; no default pointer persisted ⇒ null (no throw); tenant-context independence: `value()` inside `runAsTenant(otherWorkspace)` returns the same candidate; undecryptable secret ⇒ value() null + raw() decryptable=false.
- [ ] RED → implement → GREEN + gates → commit `feat(settings): legacy platform payment compatibility reader`.

### Task 4: `PlatformPayviaSettingsOverride` + app binding + Commerce removal (THE CRUX)

**Files:**
- Create: `app/Settings/PlatformPayviaSettingsOverride.php`, `tests/Integration/Settings/PlatformPayviaOverrideCachedBootTest.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (bind `PayviaSettingsOverride::class`; mechanism per the cached-boot constraint), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (REMOVE the `PayviaSettingsOverride` binding + `makePayviaSettingsOverride()`)
- Delete: `packages/thallo-commerce/src/Settings/SettingsStorePayviaOverride.php` + its test file
- Test: `tests/Integration/Settings/PlatformPayviaOverrideTest.php` (new) + update any commerce test pinning the removed binding

**Interfaces:**
- Produces: `final class PlatformPayviaSettingsOverride implements PayviaSettingsOverride` — whitelist ported verbatim (`payvia.default_gateway` + `payvia.gateways.{id}.{enabled|secret_key|webhook_secret}` for ids in the `payvia.gateways` CONFIG map; ops knobs never editable); resolution order per Global Constraints (store → marker-absent reader → null); marker `payments.platform_credentials_migrated` read directly from `SystemChannel`; null-never-throw absolutely; ZERO capability gates.
- Binding: before choosing the mechanism, empirically verify which app-level hook runs on a cached-provider production boot (the `BootsFromExtensionProviderCache` trait from the checkout program's tests drives that mode); pin the chosen mechanism with a cached-boot test asserting `get(PayviaSettingsOverride::class) instanceof PlatformPayviaSettingsOverride` in BOTH boot modes. Commerce's binding removed in this same commit — no first-wins window may exist in history.

- [ ] **Step 1: Failing tests:** platform value wins (seeded via the store); marker absent + no platform value ⇒ legacy candidate served (both schema shapes — reuse Task 3 fixtures); marker present ⇒ legacy IGNORED, absent key ⇒ null (config/env applies); marker present can never regress even with legacy rows still present; whitelist refusals (unknown key, unconfigured gateway id, ops knob) ⇒ null; capability independence — resolution byte-identical with `thallo.commerce` on/off/provider-absent AND `thallo.subscriptions` on/off (boot-override harness); tenant-context independence — resolution inside `runAsTenant(hostileWorkspace)` (workspace carrying hostile `payvia.*` settings rows) returns platform values for keys AND webhook secrets, covering the self-serve checkout config-read path and `WebhookService` signature verification; cached-boot binding test; commerce pack contains NO reference to `PayviaSettingsOverride` after removal (grep assertion).
- [ ] RED → implement → GREEN + full gates → commit `feat(settings): app-owned platform payvia override with marker-gated cutover`.

### Task 5: Migration command

**Files:**
- Create: `app/Console/Commands/MigratePlatformPaymentCredentialsCommand.php` (`thallo:payments:migrate-platform-credentials`; register per the app's existing console discovery — find the idiom used by other `thallo:*` commands)
- Test: `tests/Integration/Settings/PlatformPaymentMigrationTest.php` (new)

**Interfaces:**
- Consumes: Tasks 2/3 (`PlatformPaymentSettingsStore`, `LegacyPlatformPaymentSettingsReader::{value,raw,conflicts}`), the marker key.
- Behavior (spec §2 Migration verbatim — the Global Constraints bullet restates it): copy-then-verify per key (decrypt through the NEW store, compare against the legacy read — missing/undecryptable on either side = failed verification); conflicts reported and completion REFUSED by default; `--acknowledge-workspace-conflicts` acknowledges obsolescence (never adopts); `--prune-legacy` separate second step, re-verifies every affected key first, requires the acknowledge flag to discard non-default workspace rows; marker written LAST only when every candidate key is accounted for and conflicts are absent/acknowledged; idempotent partial + completed reruns; prints key names and tenant UUIDs only — NEVER values.

- [ ] **Step 1: Failing tests (the spec §3 migration matrix, complete):** platform-preserved; pre-retrofit unscoped adopted; post-retrofit default-workspace adopted; schema shape detected from the DATABASE (both fixtures); other-workspace conflict ⇒ reported + completion refused + marker NOT written; acknowledged conflicts ⇒ completion + marker; corrupted source ⇒ verification failure aborts (marker not written); corrupted COPY (tamper the system-channel row between copy and verify via a hook/double) ⇒ prune aborted; marker-last ordering (crash simulation: verification-failure path leaves marker absent + compatibility reads still live); partial rerun idempotent; completed rerun idempotent + prune still handles verified leftovers; ciphertext round-trip without re-encryption; output contains no secret material (regex sweep of captured output).
- [ ] RED → implement → GREEN + gates → commit `feat(settings): conservative platform payment credential migration`.

### Task 6: Neutral controller + Commerce endpoint retirement

**Files:**
- Create: `app/Http/Controllers/PlatformPaymentsSettingsController.php`; route registrations in the app's platform admin routes (find where `/v1/admin/settings/*`-style app routes live — the tenancy access route in `routes/admin.php` is the nearest precedent; group `['auth','tenant_system','content_permission:tenancy.manage']`, names `thallo.settings.payments.{show,update}`)
- Modify: `packages/thallo-commerce/routes/admin-routes.php` (REMOVE the two `/payments` routes), delete `packages/thallo-commerce/src/Http/PaymentsSettingsController.php` + its tests; update every commerce test pinning the removed routes (route-inventory pins, `AdminOpenApiGateTest` if it enumerates them)
- Test: `tests/Integration/Settings/PlatformPaymentsSettingsApiTest.php` (new)

**Interfaces:**
- `GET /v1/admin/settings/payments` ⇒ `{default_gateway: {value, default, overridden}, gateways: {<id>: {enabled: {value, default, overridden}, secret_key: {set: bool, source: 'settings'|'env'|null}, webhook_secret: {set, source}}}}` — mirror the retiring commerce controller's shapes (read it first; spec says boolean-only secret state is the EXISTING shape — verify and preserve). `PUT` accepts the whitelisted keys only; validates gateway ids against the config map; secrets write-only (empty string ⇒ forget? — mirror the commerce controller's clear semantics, document); 422 on unknown/invalid; writes through `PlatformPaymentSettingsStore` ONLY.

- [ ] **Step 1: Failing tests:** authority matrix (platform operator 200; workspace `billing.manage`-only actor 403; anonymous 401); GET secret state boolean-only + response contains no secret substring (seed a known secret, regex the body); PUT round-trip (value visible via Payvia resolution — through the Task 4 override); 422 matrix (unknown key, unconfigured gateway, ops knob, non-bool enabled); writes land in the system channel and NEVER touch the `settings` table (row-count pin); commerce `/v1/admin/commerce/payments` routes GONE (404/405 via the commerce truth-table idiom) and commerce suite green after retirement.
- [ ] RED → implement → GREEN + full gates → commit `feat(settings): neutral platform payments API, commerce payments endpoints retired`.

### Task 7: SPA — Settings → Payments + Commerce page retirement

**Files:**
- Create: `admin/src/pages/settings/payments.vue`, `admin/src/queries/platformPayments.ts`
- Modify: the settings nav registration (find where `/settings/workspaces` and `/settings/accounts` are declared + `shapeTenancyNav.ts`'s `manage_platform` gating — payments joins the same gate), delete the Commerce Payments page/tab (`admin/src/pages/commerce/settings/` payments portion — CAREFUL: `StorePanel.vue` in that directory is the user's uncommitted WIP; do not touch it) and repoint any commerce-settings link to `/settings/payments` rendered only under `manage_platform`
- Test: `admin/src/__tests__/platform-payments.spec.ts` (new) + update commerce settings/nav specs pinning the removed page

**Interfaces:**
- Page (`definePage` requiresAuth; platform gating via the tenancy-access store like `/settings/workspaces`): default-gateway select, per-gateway enabled toggle + write-only secret fields with boolean presence indicators (`set`/`source` badges, never a masked value), the §1.3 limitation notice as a PROMINENT alert with the spec's copy verbatim, save via PUT with 422 rendering verbatim. Query module mirrors the established pinia-colada conventions.

- [ ] **Step 1: Failing vitest specs:** page states (loading/error/loaded); secret presence badges boolean-only (no value ever rendered — assert against a seeded fixture); limitation notice present with the exact copy; save round-trip + 422 surface; nav shows Payments only under `manage_platform` (matrix: platform operator sees it, workspace-only admin does not); commerce settings no longer renders a Payments tab and the platform-authority link appears only for `manage_platform`.
- [ ] RED → implement → GREEN (`npx vitest run` modulo the known StorePanel WIP failure if still present, `type-check`, `build`) → commit `feat(settings): platform payments SPA page, commerce payments page retired`.

### Task 8: End-to-end regressions + docs

**Files:**
- Create: `tests/Integration/Settings/PlatformPaymentsRegressionTest.php`
- Modify: `docs/internal/OUTSTANDING.md` (Recently-shipped entry; ONE follow-up entry "Workspace merchant connections" covering the §4 cluster: workspace-owned gateway connections, Payvia `platform|workspace:{uuid}` merchant scopes, per-connection webhook routing identifying the connection BEFORE signature verification, paid-membership revenue), `docs/internal/DISTRIBUTION.md` (payments-ownership note), the commerce store-settings spec (annotate §3.6's enforcement-time obligation as satisfied by this program, with a pointer)
- Test: the regression file itself

- [ ] **Step 1: Failing/pinning tests (spec §3 Regression + Cutover rows):** commerce storefront checkout AND subscriptions self-serve checkout both originate against PLATFORM credentials while running under ambient workspace context with hostile workspace `payvia.*` rows seeded (recording gateway doubles assert the resolved secret); webhook signature verification resolves the platform webhook secret under no-tenant context; cutover sequence end-to-end: fresh install (no legacy, no marker) ⇒ env; legacy-only pre-marker ⇒ legacy value serves BOTH consumers; migrate ⇒ marker ⇒ platform store serves, legacy ignored.
- [ ] **Step 2:** Docs edits per above. **Step 3:** FULL gates (PHP + phpcs + boundaries + vitest + type-check + build). **Step 4:** Commit `feat(settings): platform payments regressions and shipped docs`.

---

## Self-Review

- **Spec coverage:** §1.1 → Tasks 1/2/4; §1.2 → Task 5; §1.3 → Task 7 (copy verbatim); §1.4 → Task 4 tests; §1.5/§4 → Task 8 docs; §1.6 + §2 Binding order → Task 4; §2 store → Task 2; §2 prefix/all() → Task 1; §2 reader → Task 3; §2 migration → Task 5; §2 controller → Task 6; §2 SPA → Task 7; §2 commerce retirement → Tasks 4 (binding/class) + 6 (endpoints) + 7 (page); §3 test rows distributed: capability-independence + tenant-context-independence + cutover → Task 4, migration matrix → Task 5, prefix → Task 1, controller → Task 6, SPA → Task 7, regression → Task 8. No gaps.
- **Placeholder scan:** clean — every step names exact keys, flags, orders, and assertions, or cites the spec section whose verbatim text governs; the two find-the-idiom instructions (console discovery, settings-nav declaration) name the concrete precedents to copy.
- **Type consistency:** `PlatformPaymentSettingsStore.{get,putMany,forget}` identical in Tasks 2/4/5/6; `LegacyPlatformPaymentSettingsReader.{value,raw,conflicts}` identical in Tasks 3/4/5; marker key string `payments.platform_credentials_migrated` identical in Tasks 4/5/8; route names `thallo.settings.payments.{show,update}` identical in Tasks 6/7.
- **Sequencing:** 1 → 2 → 3 → 4 (consumes 1–3) → 5 (consumes 2–3) → 6 (consumes 2+4) → 7 (consumes 6) → 8 (consumes all). Single repo, no gates.
