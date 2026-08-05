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
- Gates per task, IN THE FOREGROUND: full PHP suite, `composer phpcs`, `composer boundaries`; SPA tasks add `npx vitest run`, `npm run -s type-check`, `npm run -s build`. Before Task 1, capture a SAME-CHECKOUT baseline of every full gate in the SDD ledger without changing or staging the user's worktree. Green is the target. A pre-existing failure may be carried only when its exact test name, assertion/error, and count reproduce byte-for-byte from that baseline; any new, missing, or changed failure BLOCKS the task. Stale ledgers and remembered failure counts are never evidence.
- Conventional commits, ONE per task; NO AI-attribution trailers; stage files explicitly (the user's uncommitted `admin/src/pages/commerce/settings/components/StorePanel.vue` WIP must never be staged, if still present).
- Execution note: the authoring session's subagent budget is exhausted — execute via SDD in a fresh session (ledger: `.superpowers/sdd/2026-08-05-platform-payments-settings/progress.md`) or directly.

---

### Task 1: SystemKeys prefix routing + `SettingsStore::all()` filtering

**Files:**
- Modify: `app/Settings/SystemKeys.php`, `app/Settings/SettingsStore.php`
- Modify: `tests/Integration/Commerce/CommercePaymentsEndpointTest.php` (the existing controller now routes its Payvia writes through `SystemChannel`; update only raw-storage cleanup/assertions so the full suite remains meaningful until Task 6 ports the endpoint contract)
- Test: `tests/Integration/Settings/SystemKeyPrefixRoutingTest.php` (new)

**Interfaces:**
- Produces: `SystemKeys::PREFIXES = ['payvia.']` (list<string>); `SystemKeys::isSystem(string $key): bool` returns true for exact `KEYS` members OR any key starting with a `PREFIXES` entry. `SettingsStore::get()/putMany()/forget()` already branch on `isSystem()` (lines ~52/66/100 — unchanged mechanics, now prefix-aware). `SettingsStore::all()` (line ~40) FILTERS every exact- or prefix-classified key out of the returned tenant map (spec §2: closes the raw-read path).

- [ ] **Step 1: Failing tests:** `payvia.default_gateway` and `payvia.gateways.stripe.secret_key` route get/putMany/forget to the `SystemChannel` (recording double); a pre-seeded tenant-owned `payvia.*` row is invisible through `get()` AND absent from `all()`; exact system keys (`admin_url`) behave as before; non-payvia keys (`commerce.store_name`) unaffected in all four methods; `isSystem()` unit matrix (prefix boundary: `payvia.` prefix matches, bare `payvia` and `payviax.foo` do not). Update `CommercePaymentsEndpointTest` to clean/assert `thallo_system_flags` for Payvia keys after this routing change while preserving every behavioral assertion; this is a storage-location correction, not an early endpoint rewrite.
- [ ] **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full gates. **Step 5:** Commit `feat(settings): system-channel prefix routing for payvia keys`.

### Task 2: `PlatformPaymentSettingsStore`

**Files:**
- Create: `app/Settings/PlatformPaymentSettingsStore.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (binding, shared)
- Test: `tests/Integration/Settings/PlatformPaymentSettingsStoreTest.php` (new)

**Interfaces:**
- Produces: `final class PlatformPaymentSettingsStore { __construct(SystemChannel $system, EncryptionService $encryption) }` with `get(string $key): ?string`, `putMany(array<string,string> $pairs): void`, `forget(string $key): void`, and the deliberately narrow `importEncryptedForMigration(string $key, string $ciphertext): void`. Secret subkeys (`secret_key`, `webhook_secret` — same recognition as `SettingsStorePayviaOverride`, ported) are encrypted by `putMany()` with AAD = the full key string; non-secret keys stay plain. `importEncryptedForMigration()` accepts ONLY a recognized secret key, requires `isEncrypted($ciphertext)`, proves `decrypt($ciphertext, aad: $key)` succeeds, then writes those EXACT ciphertext bytes to `SystemChannel` without re-encryption; invalid input throws before writing. Reads are null-never-throw: undecryptable/tampered/storage-throw ⇒ null. No container lookup/service locator is permitted — `EncryptionService` is constructor-injected.

- [ ] **Step 1: Failing tests:** secret write ⇒ stored value in `thallo_system_flags` is ciphertext (not the plaintext substring), read round-trips; ciphertext written under the SAME key by the legacy commerce path decrypts through this store (AAD-compat proof: encrypt with `EncryptionService` using the legacy AAD, import, read via the store); migration import preserves byte-for-byte ciphertext equality; import rejects a non-secret key, plaintext, malformed ciphertext, and wrong-AAD ciphertext without writing; tampered stored ciphertext ⇒ null (no throw); non-secret keys stored plain; forget deletes; SystemChannel throw ⇒ null read.
- [ ] RED → implement → GREEN + gates → commit `feat(settings): platform payment settings store over the system channel`.

### Task 3: `LegacyPlatformPaymentSettingsReader`

**Files:**
- Create: `app/Settings/LegacyPlatformPaymentSettingsReader.php`, `app/Settings/LegacyPlatformPaymentSettingsRepository.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (production repository uses table `settings`; reader is a shared read-only facade)
- Test: `tests/Integration/Settings/LegacyPlatformPaymentReaderTest.php` (new)

**Interfaces:**
- Produces (spec §2 verbatim): `LegacyPlatformPaymentSettingsReader` is a read-only, TEMPORARY facade with `value(string $key): ?string`, `raw(string $key): ?array{key:string,tenant_uuid:?string,stored_value:string,decrypted_value:?string,decryptable:bool}`, and sanitized `conflicts(): array<string,list<array{tenant_uuid:string,key:string}>>` (no values leave this diagnostic surface).
- `LegacyPlatformPaymentSettingsRepository` owns the physical-table mechanics shared by the reader and Task 5: `candidateRaw(string $key)`, internal `conflictRows()` (same raw shape, values never printed), and `deleteExact(string $key, ?string $tenantUuid, string $expectedStoredValue): void`. Its constructor takes an internal table name defaulting to `settings`, validates it as a safe identifier, and exists so tests can use isolated temporary tables without altering the shared application table. `deleteExact()` is a compare-and-delete whose WHERE includes the same schema-shape/tenant predicate AND the expected stored bytes; a 0-row result (missing or concurrently changed) fails loudly, so verification can never delete a value it did not inspect.
- Schema introspection uses the real schema builder's `hasColumn($table, 'tenant_uuid')`: PRE-RETROFIT (column absent) ⇒ the one unscoped row is the candidate; POST-RETROFIT ⇒ only the row whose tenant equals the persisted `tenancy.default_tenant_uuid` (read via `SystemFlags::defaultTenantUuid()` — the same pointer `SingleStoreTenant` trusts). Direct repository queries ONLY — never `SettingsStore`, never `runAsTenant()`, never a current-tenant helper (grep-proof this in review). Secret decryption uses injected `EncryptionService` with the unchanged AAD; undecryptable ⇒ null for `value()` but remains a real `decryptable=false` raw row for migration verification.

- [ ] **Step 1: Failing tests:** create two uniquely named temporary tables through the real schema builder and drop them in `finally`: one pre-retrofit shape (`key,value,updated_at`, no `tenant_uuid`) and one post-retrofit shape (`tenant_uuid,key,value,updated_at`, composite key). Construct repositories with those explicit table names. Prove unscoped candidate selection; default-workspace candidate selection; OTHER workspace row never returned by `value()` but enumerated by sanitized `conflicts()`/internal `conflictRows()`; no default pointer ⇒ null; tenant-context independence inside `runAsTenant(otherWorkspace)`; undecryptable secret ⇒ `value()` null + raw `decryptable=false`; `deleteExact()` deletes only the located row whose stored bytes still equal the verified bytes, and refuses a mismatched locator or concurrently changed value. The shared production `settings` table is never altered by these schema-shape tests.
- [ ] RED → implement → GREEN + gates → commit `feat(settings): legacy platform payment compatibility reader`.

### Task 4: `PlatformPayviaSettingsOverride` + app binding + Commerce removal (THE CRUX)

**Files:**
- Create: `app/Settings/PlatformPayviaSettingsOverride.php`, `tests/Integration/Settings/PlatformPayviaOverrideCachedBootTest.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (bind `PayviaSettingsOverride::class`; mechanism per the cached-boot constraint), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (REMOVE the `PayviaSettingsOverride` binding + `makePayviaSettingsOverride()`)
- Delete: `packages/thallo-commerce/src/Settings/SettingsStorePayviaOverride.php` only
- Test: `tests/Integration/Settings/PlatformPayviaOverrideTest.php` (new); modify `tests/Integration/Commerce/CommercePaymentsEndpointTest.php` only where it imports/asserts the removed override so its still-shipped controller contract remains covered until Task 6 ports it

**Interfaces:**
- Produces: `final class PlatformPayviaSettingsOverride implements PayviaSettingsOverride` — whitelist ported verbatim (`payvia.default_gateway` + `payvia.gateways.{id}.{enabled|secret_key|webhook_secret}` for ids in the `payvia.gateways` CONFIG map; ops knobs never editable); resolution order per Global Constraints (store → marker-absent reader → null); marker `payments.platform_credentials_migrated` read directly from `SystemChannel`; null-never-throw absolutely; ZERO capability gates.
- Binding: before choosing the mechanism, empirically verify which app-level hook runs on a cached-provider production boot (the `BootsFromExtensionProviderCache` trait from the checkout program's tests drives that mode); pin the chosen mechanism with a cached-boot test asserting `get(PayviaSettingsOverride::class) instanceof PlatformPayviaSettingsOverride` in BOTH boot modes. Commerce's binding removed in this same commit — no first-wins window may exist in history.

- [ ] **Step 1: Failing tests:** platform value wins (seeded via the store); marker absent + no platform value ⇒ legacy candidate served (both schema shapes — reuse Task 3 fixtures); marker present ⇒ legacy IGNORED, absent key ⇒ null (config/env applies); marker present can never regress even with legacy rows still present; whitelist refusals (unknown key, unconfigured gateway id, ops knob) ⇒ null; capability independence — resolution byte-identical with `thallo.commerce` on/off/provider-absent AND `thallo.subscriptions` on/off (boot-override harness); tenant-context independence — resolution inside `runAsTenant(hostileWorkspace)` (workspace carrying hostile `payvia.*` settings rows) returns platform values for keys AND webhook secrets, covering the self-serve checkout config-read path and `WebhookService` signature verification; cached-boot binding test; commerce pack contains NO reference to `PayviaSettingsOverride` after removal (grep assertion). Update `CommercePaymentsEndpointTest`'s ops-knob seam assertion to resolve the app-owned override from the container; do not delete or weaken its controller tests in this task.
- [ ] RED → implement → GREEN + full gates → commit `feat(settings): app-owned platform payvia override with marker-gated cutover`.

### Task 5: Migration command

**Files:**
- Create: `app/Settings/Console/MigratePlatformPaymentCredentialsCommand.php` (`thallo:payments:migrate-platform-credentials`)
- Modify: `app/Providers/ThalloServiceProvider.php` (add the command to `consoleCommandServices()` and the explicit `$this->commands([...])` list in `boot()`, matching the app's existing command registration)
- Test: `tests/Integration/Settings/PlatformPaymentMigrationTest.php` (new)

**Interfaces:**
- Consumes: Tasks 2/3 (`PlatformPaymentSettingsStore::{get,putMany,importEncryptedForMigration}`, `LegacyPlatformPaymentSettingsReader::{value,raw,conflicts}`, and the internal `LegacyPlatformPaymentSettingsRepository` for exact raw-row enumeration/deletion), plus `SystemChannel` for the marker.
- Behavior (spec §2 Migration verbatim — the Global Constraints bullet restates it): for a secret adopted from the default/unscoped candidate, validate the raw row then call `importEncryptedForMigration()`; for an adopted non-secret, call `putMany()`. Verification of an ADOPTED value is TWO-part before success/prune: the platform store's decrypted value equals the legacy decrypted value, AND a secret's raw `SystemChannel` value is byte-identical to the legacy ciphertext. Missing/undecryptable on either side is failure, never `null == null`. A pre-existing platform value is preserved; if it differs from the default legacy row, prune leaves that row in place with a diagnostic rather than guessing that it is obsolete. Other-workspace conflicts are reported and completion REFUSED by default; `--acknowledge-workspace-conflicts` is the explicit authority to discard them and NEVER compares/adopts their values as platform credentials. `--prune-legacy` is a separate second step: re-enumerate each row, apply the applicable adopted-value verification or conflict acknowledgement, then compare-and-delete through `deleteExact(..., expectedStoredValue)`; a concurrent change aborts. Marker written LAST only when every candidate key is accounted for and conflicts are absent/acknowledged; idempotent partial + completed reruns; prints key names and tenant UUIDs only — NEVER values.

- [ ] **Step 1: Failing tests (the spec §3 migration matrix, complete):** platform-preserved; pre-retrofit unscoped adopted; post-retrofit default-workspace adopted; schema shape detected from the DATABASE using Task 3's isolated fixtures; pre-existing platform value differing from default legacy stays platform-authoritative and the legacy row is not pruned; other-workspace conflict ⇒ reported + completion refused + marker NOT written; acknowledged conflicts ⇒ completion + marker and optional exact-row prune but never adoption/value comparison; corrupted source ⇒ verification failure aborts (marker not written); corrupted COPY (tamper the system-channel row between copy and verify via a hook/double) ⇒ prune aborted; marker-last ordering (crash simulation: verification-failure path leaves marker absent + compatibility reads still live); partial rerun idempotent; completed rerun idempotent + prune still handles verified leftovers; exact ciphertext bytes before/after are identical; a row changed after verification makes compare-and-delete fail and remains present; output contains no secret material (regex sweep of captured output).
- [ ] RED → implement → GREEN + gates → commit `feat(settings): conservative platform payment credential migration`.

### Task 6: Neutral controller + Commerce endpoint retirement

**Files:**
- Create: `app/Http/Controllers/PlatformPaymentsSettingsController.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (shared/autowired controller service), `routes/admin.php` (new app-owned `GET|PUT /v1/admin/settings/payments` group with `['auth','tenant_system','content_permission:tenancy.manage']`, names `thallo.settings.payments.{show,update}`)
- Modify: `packages/thallo-commerce/routes/admin-routes.php` (REMOVE the two `/payments` routes); delete `packages/thallo-commerce/src/Http/PaymentsSettingsController.php`; port every still-relevant assertion from `tests/Integration/Commerce/CommercePaymentsEndpointTest.php` to the new API test, then delete that old test; update every route-inventory/OpenAPI pin (`AdminOpenApiGateTest` included)
- Test: `tests/Integration/Settings/PlatformPaymentsSettingsApiTest.php` (new)

**Interfaces:**
- Preserve the retiring controller's response contract exactly while changing only the URL/authority/storage owner: `GET /v1/admin/settings/payments` ⇒ `{mode:'manual'|'gateway', default_gateway:{value:?string,default:string,overridden:bool}|null, gateways:list<{id:string,enabled:{value:bool,default:bool,overridden:bool},secret_key:{set:bool,source:'settings'|'env'|null},webhook_secret:{set:bool,source:'settings'|'env'|null},default:bool,webhook_url:?string}>}`. Keep the canonical-origin-derived `webhook_url`, list ordering, gateway `id`, and `mode`; the SPA consumes all four today.
- GET reports the SAME effective source runtime uses during cutover. Platform rows win. Before the marker, a served legacy default/unscoped value reports `overridden=true` or secret `{set:true,source:'settings'}`; config/env reports `source:'env'`; after the marker legacy rows are invisible. Do not derive GET state from `PlatformPaymentSettingsStore` alone while compatibility fallback is live.
- `PUT` accepts the existing nested body shape and validates gateway ids against the config map. Secret semantics are pinned, not deferred: field ABSENT = unchanged; `null` OR blank string = `forget()`; nonblank string = encrypt/store. Unknown gateway/field, ops knob, malformed enabled, or overlength secret ⇒ 422. Every write goes through `PlatformPaymentSettingsStore` ONLY.

- [ ] **Step 1: Failing tests:** authority matrix (platform operator 200; workspace `billing.manage`-only actor 403; anonymous 401); byte-shape parity fixture for `mode`, ordered gateway list, ids/default flags and `webhook_url`; GET secret state boolean-only + response contains no secret substring; pre-marker legacy-only GET reports the effective override/presence that Payvia actually uses, while post-marker ignores it; PUT round-trip through Task 4's override; absent/null/blank secret matrix; full 422 matrix (unknown gateway/field, ops knob, non-bool enabled, overlength secret); writes land in the system channel and NEVER touch `settings`; every assertion from `CommercePaymentsEndpointTest` is either ported with the new authority/storage expectation or explicitly replaced before that file is deleted; commerce `/v1/admin/commerce/payments` routes GONE (404/405 via the commerce truth-table idiom) and commerce suite green after retirement.
- [ ] RED → implement → GREEN + full gates → commit `feat(settings): neutral platform payments API, commerce payments endpoints retired`.

### Task 7: SPA — Settings → Payments + Commerce page retirement

**Files:**
- Create: `admin/src/pages/settings/payments.vue`, `admin/src/queries/platformPayments.ts`
- Modify: `admin/src/registry/coreModule.ts` (add the Payments child beside Workspaces), `admin/src/navigation/shapeTenancyNav.ts` (gate `/settings/payments` on `manage_platform`, exactly like `/settings/workspaces`), delete `admin/src/pages/commerce/settings/components/PaymentsPanel.vue`, remove the Commerce Payments tab/import from `admin/src/pages/commerce/settings/index.vue`, move payment query ownership out of `admin/src/queries/commerceSettings.ts` and its query key, and repoint any commerce-settings link to `/settings/payments` only under `manage_platform`. CAREFUL: `admin/src/pages/commerce/settings/components/StorePanel.vue` is the user's uncommitted WIP; do not touch or stage it.
- Test: `admin/src/__tests__/platform-payments.spec.ts` (new) + update commerce settings/nav specs pinning the removed page

**Interfaces:**
- Page (`definePage` requiresAuth; platform gating via the tenancy-access store like `/settings/workspaces`): preserve the shipped Payments panel behavior, including manual/gateway states and copyable webhook URLs; default-gateway select, per-gateway enabled toggle + write-only secret fields with boolean presence indicators (`set`/`source` badges, never a masked value), the §1.3 limitation notice as a PROMINENT alert with the spec's copy verbatim, save via PUT with 422 rendering verbatim. Query normalization consumes Task 6's preserved list response and mirrors the established pinia-colada conventions.

- [ ] **Step 1: Failing vitest specs:** page states (loading/error/loaded); secret presence badges boolean-only (no value ever rendered — assert against a seeded fixture); limitation notice present with the exact copy; save round-trip + 422 surface; nav shows Payments only under `manage_platform` (matrix: platform operator sees it, workspace-only admin does not); commerce settings no longer renders a Payments tab and the platform-authority link appears only for `manage_platform`.
- [ ] RED → implement → GREEN (`npx vitest run`, `type-check`, `build`; any pre-existing worktree failure must match the same-checkout baseline rule exactly, never a remembered exception) → commit `feat(settings): platform payments SPA page, commerce payments page retired`.

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
- **Placeholder scan:** clean — every step names exact keys, flags, files, registration hooks, orders, and assertions, or cites the spec section whose verbatim text governs.
- **Type consistency:** `PlatformPaymentSettingsStore.{get,putMany,forget,importEncryptedForMigration}` identical in Tasks 2/4/5/6; `LegacyPlatformPaymentSettingsReader.{value,raw,conflicts}` and repository raw/delete operations identical in Tasks 3/5; marker key string `payments.platform_credentials_migrated` identical in Tasks 4/5/8; route names `thallo.settings.payments.{show,update}` identical in Tasks 6/7.
- **Sequencing:** 1 → 2 → 3 → 4 (consumes 1–3) → 5 (consumes 2–3) → 6 (consumes 2+4) → 7 (consumes 6) → 8 (consumes all). Single repo, no gates.
