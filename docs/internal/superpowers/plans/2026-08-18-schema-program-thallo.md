# Schema-on-Enable Program — Plan 3 of 3: The Thallo Program

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Deviation (2026-08-18, user decision during execution):** the legacy-alias receipt
> machinery was DROPPED after Task 3 completed — beta.2 is not running anywhere, so
> beta.2 → beta.3 in-place upgrades are unsupported rather than normalized. Framework
> 1.80.0 (pre-publication) removed `legacyAliases`/`ReceiptNormalizer`/
> `migrate:normalize-receipts`; the pack manifests dropped their aliases; Task 3's
> `Beta2UpgradeTest` was deleted; the dev database's 29 aliased receipts were rewritten
> to canonical sources in place (checksum-verified, with the 8 pre-beta.2 drifted seed
> receipts verifier-adopted). Every later mention of aliases/normalization in Tasks 4–11
> is void.

**Goal:** Thallo consumes the published schema-on-enable stack (framework 1.79.0 + the Plan 2
pin-set), audits all 13 library packs and adopts the 12 that declare Glueful providers into the
manifest contract with legacy aliases and receipt normalization, makes provision a locked and
failure-aware single pass, drives all
extension toggling through the shared executor (SPA + CLI, production allowed), maps
capabilities to owning packages, swaps tenancy onto a protected entry point of that same
executor, proves the beta.2 upgrade path without replaying old migrations, and opts Thallo into
strict package-manifest enforcement on framework 1.80.0 once nothing in Thallo needs the legacy
lane.

**Architecture:** The Thallo work consumes published APIs; one final framework 1.80.0 release
adds the complete-provision and protected-lifecycle executor entry points before Thallo consumes
them. Packs become `core`-mode descriptors
(they are libraries — no enable event) with `legacyAliases` carrying today's exact ledger
sources; `migrate:normalize-receipts` rewrites the beta.2 ledger to descriptor identities.
The app-local `app`/`app:dependent` sources stay on the manager's app path and the (permanent)
ownerless-provider append — they are the application's own schema, not package schema. Framework
1.80 makes undeclared-package refusal host-opt-in (Thallo enables it); a framework-wide default
cannot flip in a minor while beta.2's `^1.78.3` constraint can resolve 1.80.

**Recon facts this plan is built on** (verified 2026-08-18 against the dev database and the
working tree): live ledger sources and their receipt counts are
`app` 21, `app:dependent` 11 (registered by `app/Providers/ThalloServiceProvider.php` with an
explicit source), `migrations` 3 (thallo-render's bare `loadMigrationsFrom(__DIR__.'/../migrations')`
— basename-derived source at DEFAULT priority), and `thallo-analytics` 4, `thallo-collections` 3,
`thallo-commerce` 5, `thallo-navigation` 3, `thallo-seo` 2, `thallo-tenancy` 6,
`thallo-workflow` 3 (all DEPENDENT, explicit sources). Schema-free packs: thallo-account,
thallo-importers, thallo-search, thallo-subscriptions (providers, no migrations);
thallo-contracts has NO `extra.glueful` at all — out of contract scope, untouched.
`ExtensionAdminController` refuses production at `toggle()` and uses `ExtensionStateWriter`
directly (line ~284). `Thallo\Contracts\Capability\Capability` is
`(id, requires, label, description)`; the registry lives in thallo-contracts with
`App\Capabilities\DefaultCapabilityRegistry`. Thallo currently pins framework `^1.78.4` and
pre-adoption extension minors.

## Global Constraints

- All Thallo work on `dev` in `/Users/michaeltawiahsowah/Sites/glueful/thallo`; commit locally, never push. No `Co-Authored-By` trailers. Framework closure work in the framework repo on a branch off `dev`.
- Thallo test gates: `COMPOSER_PROCESS_TIMEOUT=0 composer test` (~10 min, never concurrent, foreground/background-single), `composer test:distribution`, `composer phpcs`, `composer boundaries`; SPA: `pnpm test`, `pnpm type-check` in `admin/` when SPA files change.
- Ledger identity is sacred: every pack descriptor uses `id: "default"` (source = package name `glueful/thallo-*`) with `legacyAliases` equal to TODAY'S exact ledger source. Normalization is checksum-verified; nothing is ever dropped.
- Pack descriptors are ALL `mode: "core"` (libraries have no enable event — spec B2): their tables keep provisioning with every install, exactly as the commerce pack's "on INSTALL, not enable" comment always promised.
- Priorities preserve today's byte ordering: `dependent` for the seven explicit packs, `default` for thallo-render (its bare call used the DEFAULT parameter).
- `app` and `app:dependent` are application schema, not package schema: they stay on the manager's app path and the app-local provider append. The framework strict-policy work (Task 4) keeps the ownerless-provider append lane open PERMANENTLY for exactly this.
- Effect-proof verifiers per Plan 2's bar: create migrations prove their tables+columns; ALTER/seed migrations prove their specific effect; every basename gets an isolated deliberately-incomplete predecessor fixture plus the same fixture after `up()`; unknown basenames refuse. A current fresh-install chain is never accepted as the pre-effect proof merely because it is convenient.
- Existing alias receipts MUST be normalized before any global pending read under the new descriptor inventory. `migrate:run` is not alias-aware and is never the first post-update command for beta.1/beta.2 installs.
- Effective capability enablement is `requested_enabled && owner_available`; `enabled()` and every existing `isEnabled()` consumer inherit that rule. Owner availability failures are fail-closed but never make pre-provision boot throw.

---

### Task 1: Repin the stack

**Files:**
- Modify: `composer.json` (framework `^1.79`, aegis `^1.15`, audit `^1.4`, commerce `^1.13`,
  email-notification `^1.13`, i18n `^1.2`, import-export `^1.2`, media `^1.2`,
  meilisearch `^1.7`, payvia `^2.8`, subscriptions `^2.3`, tenancy `^2.1`, users' entry if
  present `^2.4`)
- Modify: `composer.lock`, `CHANGELOG.md` (Unreleased: requirement bumps + one-line program
  pointer)

- [ ] **Step 1:** Apply the constraint bumps; `composer update` the glueful set with
  `--with-all-dependencies`; verify `composer show` reports the exact Plan 2 pin-set (framework
  v1.79.0, extension-contracts v1.5.1 transitively).
- [ ] **Step 2:** Full gates: `COMPOSER_PROCESS_TIMEOUT=0 composer test` (expect the current
  green bar — the packs still boot via the legacy append; the engines' descriptors register via
  the container factory), `composer test:distribution`, `composer phpcs`, `composer boundaries`.
  Investigate ANY failure before proceeding — this task changes only dependency versions.
- [ ] **Step 3:** Commit — `chore(deps): schema-on-enable stack — framework ^1.79 and the Plan 2 extension pin-set`.

---

### Task 2: Pack adoption — 8 schema-owning + 4 schema-free manifests

**Files (all inside this repo):**
- Modify: `packages/<pack>/composer.json` for all 12 packs listed below (thallo-contracts untouched)
- Create: `packages/<pack>/src/Schema/<Name>SchemaVerifier.php` for the 8 schema-owning packs
- Modify: the 8 providers that call `loadMigrationsFrom()` (remove the call + unused import)
- Test: `packages/<pack>/tests/.../SchemaManifestTest.php` per pack (in each pack's existing test
  layout; Thallo consumer tests live under `tests/` at the repo root when the pack has no own
  suite — mirror where that pack's existing tests sit)
- Test: one repo-level behavior test `tests/Unit/Schema/PackVerifierBehaviorTest.php` driving all
  8 verifiers through isolated per-basename fixtures on SQLite (Plan 2's pattern, one file — the
  packs share the repo's test harness, but never schema state). Driver-specific effects get the
  repository's PostgreSQL-gated sibling.

**Exact descriptor values** (id `default`, `mode: "core"` everywhere):

| Pack | priority | legacyAliases | verifier class |
|---|---|---|---|
| thallo-analytics | dependent | ["thallo-analytics"] | `Thallo\Analytics\Schema\AnalyticsSchemaVerifier` |
| thallo-collections | dependent | ["thallo-collections"] | `Thallo\Collections\Schema\CollectionsSchemaVerifier` |
| thallo-commerce | dependent | ["thallo-commerce"] | `Thallo\Commerce\Schema\CommerceLinkSchemaVerifier` |
| thallo-navigation | dependent | ["thallo-navigation"] | `Thallo\Navigation\Schema\NavigationSchemaVerifier` |
| thallo-render | default | ["migrations"] | `Thallo\Render\Schema\RenderSchemaVerifier` |
| thallo-seo | dependent | ["thallo-seo"] | `Thallo\Seo\Schema\SeoSchemaVerifier` |
| thallo-tenancy | dependent | ["thallo-tenancy"] | `Thallo\Tenancy\Schema\TenancySchemaVerifier` |
| thallo-workflow | dependent | ["thallo-workflow"] | `Thallo\Workflow\Schema\WorkflowSchemaVerifier` |

The verifier FQCNs above are exact: all eight composer manifests were checked and expose the
shown `Thallo\<Pack>\` PSR-4 root. Do not rename or "adjust" them during implementation.
Schema-free (`"migrations": "none"`): thallo-account, thallo-importers, thallo-search,
thallo-subscriptions. Every pack also declares `requires` `{"glueful": ">=1.79.0", "extensions": []}`
— packs are wired by Thallo's provider list, not extension dependency edges; record the
resolver evidence per Plan 2's rule.

- [ ] **Step 1:** For each schema-owning pack, derive the effect inventory exactly as Plan 2
  Task 2 Step 1 (recursive discovery, per-migration effects: created tables+columns, ALTER'd
  columns, seed invariants — inspect each `up()`; thallo-tenancy's 6 include the tenant-table
  set seen in the beta gate: `block_type_migrations` etc. belong to whichever pack migration
  creates them — attribute by reading the files, never by assumption).
- [ ] **Step 2:** Write each pack's `SchemaManifestTest` (Plan 2's template: descriptor shape
  asserting `core` + the pack's priority + its alias list; verifier conformance; recursive
  basename coverage; provider-no-longer-registers) and the shared
  `PackVerifierBehaviorTest`. For EACH basename, build a fresh isolated schema containing its
  prerequisites but missing at least one effect owned by that migration and assert false; apply
  that migration's `up()` and assert true. Never reuse a prior basename's database. Add a pgsql
  sibling wherever SQLite cannot prove the effect (named indexes/constraints/nullability). Run
  red.
- [ ] **Step 3:** Implement manifests, verifiers, provider-call removals. Run green.
- [ ] **Step 4:** Confirm the container factory now registers the pack descriptors: a repo test
  asserting `MigrationManagerFactory::create($context)`-built manager has
  `hasSource('glueful/thallo-render')` etc. for all 8, and that `pendingForSources()` sees their
  files (fresh sqlite).
- [ ] **Step 5:** Full Thallo gates. Commit — `feat(schema): pack manifests — core descriptors with legacy aliases, effect verifiers, providers register nothing`.

---

### Task 3: Receipt normalization + beta.2-fixture upgrade proof

**Files:**
- Test: `tests/Integration/Schema/Beta2UpgradeTest.php`
- Modify: `docs/upgrading.md` (or the repo's upgrade doc): the beta.3 upgrade sequence is
  `composer update` → `php glueful migrate:normalize-receipts` → `php glueful migrate:run`
  (now applies the new `extension_operations` core migration and any other genuinely-new files)
  → `php glueful migrate:verify`. The normalizer needs only the existing `migrations` ledger and
  schema-independent migration lock; it deliberately runs before executor bootstrap exists. The
  published instructions use `&&` (or an explicit exit-code check): ANY normalization refusal
  stops the sequence before `migrate:run`.

- [ ] **Step 1:** Build an actual beta.2-start fixture from tag `v1.0.0-beta.2`, not from a
  post-1.79 live ledger: seed its exact `(source, basename, checksum)` receipts (app 21 /
  app:dependent 11 / migrations 3 / the seven thallo-* sources and the framework/engine sources
  present in beta.2). Compute checksums from the current corresponding files only after asserting
  those files are byte-identical to the beta.2 tag/package artifacts. Explicitly assert the
  fixture has NO receipt for 1.79's `glueful/framework:extensions` migration.
- [ ] **Step 2:** Drive the production order. First `ReceiptNormalizer::normalize()` rewrites
  every alias row (`thallo-*`, `migrations`) to `glueful/thallo-*`, with ZERO refusals; `app` /
  `app:dependent` / already-canonical `glueful/*` rows stay byte-identical and total row count is
  unchanged. Then invoke the real `migrate:run --force` command harness: it snapshots
  `globalSources()`, locks that snapshot, takes the fresh `pendingForSources()` read, and passes
  those files to `migrate()`. Assert no pack migration basename runs a second time and only
  migrations absent from beta.2 (including `extension_operations`) are applied. Finally run the
  verify command/service and assert `SchemaReadiness` plus `AdoptionService::classify()` report
  Ready across the whole inventory.
- [ ] **Step 3:** Add the stop case: mutate one alias receipt checksum. Normalization refuses it,
  leaves that row under its alias, may commit the independently verified rewrites (the current
  normalizer's deliberate partial-progress contract), and exits non-zero. The scripted upgrade
  aborts BEFORE the global migration read, so the divergent alias can never be papered over by a
  canonical replay. After repair, rerunning normalization completes idempotently; the refused
  descriptor remains Divergent until then and the others remain Ready.
- [ ] **Step 4:** Run green; full gates; commit — `test(schema): beta.2 ledger normalizes before migration and upgrades without replay`.

---

### Task 4: Framework 1.80.0 — complete provision, protected executor lane, strict-manifest opt-in

**Files (framework repo, branch `schema-closure` off `dev`):**
- Modify: `src/Installer/Installer.php`
- Modify: `src/Extensions/Schema/ExtensionSchemaExecutor.php`
- Modify: `src/Extensions/ServiceProvider.php::loadMigrationsFrom()`
- Modify: framework `config/extensions.php` default + Thallo `config/extensions.php`
- Test: `tests/Unit/Installer/InstallerFullPassTest.php`
- Test: `tests/Unit/Installer/InstallerMigrationLockTest.php`
- Test: `tests/Integration/Extensions/ExtensionSchemaExecutorTest.php`
- Test: `tests/Unit/Extensions/Schema/SingleInventoryTest.php`
- Modify: framework `CHANGELOG.md`
- Then (after publication) modify Thallo `composer.json`, `composer.lock`

- [ ] **Step 1 — installer red tests:** with a context, the installer must build through
  `MigrationManagerFactory::create($context, $migrationConnection)`, snapshot
  `globalSources()`, acquire the migration lock over EVERY source in that snapshot, take a fresh
  `pendingForSources($snapshot)` read inside the lock, and run `migrateSources($snapshot)`. Tests
  prove app + a fixture core descriptor both apply, a held descriptor-source lock refuses
  provision, and locks release after success and failure. A context-less unit path retains a bare
  manager whose only global source is `app` and follows the same custody sequence.
- [ ] **Step 2 — truthful failure:** a descriptor migration that fails must make the installer's
  migrate step FAILED with its basename/error (and manual-repair wording when
  `requiresManualRepair` is true). It must never report install success from a
  `MigrationRunReport` containing a failure, and later migrations stay pending because
  `migrateSources()` stops at the first failure.
- [ ] **Step 3 — protected shared entry point:** add
  `ExtensionSchemaExecutor::migrateProtected(string $package, string $actor): ExtensionOperation`.
  It accepts ONLY a package whose provider is protected by `ProtectedProviders`, asserts executor
  bootstrap, locks pending core sources plus that package's descriptor sources, records a
  `protected_migrate` operation, uses the same private migrate/report/readiness path as
  `enable()`, and returns the truthful succeeded/failed/manual_repair operation. It NEVER writes
  extension state or recompiles the provider cache. Refactor `enable()` to share the migration
  helper; tests prove a non-protected package is refused and the protected path cannot toggle
  provider state.
- [ ] **Step 4 — strict-manifest host policy:** add the framework config
  `extensions.schema.require_declared_packages` (default FALSE in 1.80 for minor-version
  compatibility). `loadMigrationsFrom()` keeps descriptor-covered validate-and-return and
  declared-package contradiction behavior. For a provider owned by an UNDECLARED installed
  Glueful package: strict host ⇒ throw `UndeclaredSchemaException`; compatibility host ⇒ retain
  the 1.79 append. An ownerless app-local provider appends in BOTH modes. Update
  `SingleInventoryTest` to prove the four combinations, including a class physically contained
  in an undeclared vendor package and a genuine root-app provider. Record that the framework-wide
  default flips only in the next major release.
- [ ] **Step 5:** Run framework full gates; commit the installer, protected lane, and strict policy as
  separate reviewable commits on `schema-closure`; fast-forward framework `dev`; STOP for the
  human v1.80.0 publication. Changelog names all three changes, the default-false compatibility
  posture, and the requirement that a host adopt every package before opting into strict mode.
- [ ] **Step 6 — consume only after publication:** bump Thallo to framework `^1.80`, update the
  dist lock, set `extensions.schema.require_declared_packages=true` in Thallo, and run full Thallo
  gates. No temporary path repository and no Thallo commit that references an unpublished API.
  Commit — `chore(deps): framework 1.80 — complete provision, protected execution, strict manifests`.
- [ ] **Step 7 — Thallo provision acceptance:** extend `tests/Integration/Setup/` so fresh
  provision leaves zero pending files across every core + enabled source; seed a failing fixture
  descriptor and prove provision fails rather than declaring success. The create-admin catch-up
  pass is a no-op belt, not the workhorse. Commit — `test(setup): provision is a locked complete pass with truthful failures`.

---

### Task 5: Thallo admin surface through the shared executor

**Files:**
- Modify: `app/Http/Controllers/ExtensionAdminController.php` — delete the production refusal;
  add the host-writability precondition that is MISSING today by resolving
  `Glueful\Extensions\Install\HostCapability::forToggle()` (409 before executor on refusal),
  then replace the direct `ExtensionStateWriter` + `writeCacheNow()` block with the container's
  `Glueful\Extensions\Schema\ExtensionSchemaExecutor` (`enable($package,'admin-api')` /
  `disable(...)`). Keep the route's existing authority and protected-provider refusal. Surface
  the operation record (id/status/failed_migration/error) and audit its id/status. Match the
  framework controller's terminal semantics exactly: `succeeded` and `enabled_cache_stale` are
  HTTP 200 (the latter carries its warning); `failed`/`manual_repair` are 409 with the operation
  payload; `SchemaNotBootstrappedException`/`UndeclaredSchemaException`/
  `LockContentionException` are 409 with their remedy messages.
- Modify: the extensions LIST endpoint in the same controller — each row gains
  `schema_state`, `schema_reasons`, and `cli_command`. State is a closed aggregation:
  `undeclared` when `extra.glueful.migrations` is absent; `none` when it is explicitly `none`;
  otherwise `divergent` if ANY descriptor is divergent, else `pending` if any is pending, else
  `ready`. An undeclared third-party package remains listable and can never make the whole endpoint
  throw; its reason tells the package author to declare descriptors or `migrations: "none"`.
- Modify (SPA): `admin/src/pages/extensions/components/InstalledExtensions.vue`,
  `admin/src/queries/extensions.ts`, and `admin/src/queries/extensions.spec.ts` — render the
  schema state chip, disable the toggle while an operation is
  running or the schema is divergent (tooltip carries the reasons + CLI command), refresh the
  list after a toggle response, show `failed_migration` on failure.
- Test: `tests/Integration/Http/ExtensionAdminControllerTest.php` (spy executor per Plan 1's
  `ExtensionsControllerTest` pattern):
  production no longer refused; an unwritable host refuses before executor invocation;
  delegation with actor `admin-api`; all terminal-status/409 mappings; declared multi-descriptor
  precedence; explicit-none; undeclared package remains visible. SPA: extend the page's existing
  vitest spec for the new states and reasons.

- [ ] Steps: red → implement → `composer test` + `pnpm test` + `pnpm type-check` → commit —
  `feat(admin): extension toggling drives the shared schema executor; production enablement with truthful operation states`.

---

### Task 6: Capability → owning package (spec B3)

**Files:**
- Modify: `packages/thallo-contracts/src/Capability/Capability.php` — add
  `public readonly ?string $owningPackage = null` (syntax-validated: `vendor/name` shape when
  non-null).
- Create: `packages/thallo-contracts/src/Capability/CapabilityAvailability.php` (closed value:
  `available`, `reason`, `remedy`) and
  `packages/thallo-contracts/src/Capability/CapabilityAvailabilityResolver.php` (one
  `resolve(Capability): CapabilityAvailability` method). Add
  `CapabilityRegistry::availability(string $id): CapabilityAvailability` and
  `isRequestedEnabled(string $id): bool`; no anonymous array-shape contract.
- Create: `app/Capabilities/ExtensionCapabilityAvailabilityResolver.php`. For an ownerless
  capability return available. For an owned one: resolve exactly one extension candidate by the
  explicit Composer package, require its provider in `EnabledProviders`, aggregate
  `SchemaReadiness::forPackage()` with divergent > pending > ready, and return a reason/remedy
  naming the package. Missing ledger/database during pre-provision boot is caught and returned as
  unavailable (never an exception from provider boot); explicit `migrations: none` is ready.
- Modify: `app/Capabilities/DefaultCapabilityRegistry.php` and its factory. The registry keeps
  every registered capability in `all()`, including absent-owner capabilities. Requested state
  continues to come from the existing config/settings override map in this task. Effective state is DEFINED as
  `isRequestedEnabled(id) && availability(id).available`; both `isEnabled()` and `enabled()` use
  that exact expression so every existing route/provider/SPA consumer fails closed automatically.
  Memoize requested/availability results for the registry's request/boot lifetime so repeated
  provider gates do not repeat ledger queries. Preserve direct-construction compatibility for
  existing tests/hosts: a missing resolver means ownerless capabilities remain available while an
  owned capability fails closed with "owner availability resolver unavailable".
- Modify the registration sites with this CLOSED owner table (verified from their pack contracts;
  capability IDs are never used to infer it):

  | Capability | owningPackage |
  |---|---|
  | `thallo.accounts` | `glueful/users` |
  | `thallo.commerce` | `glueful/commerce` |
  | `thallo.importers` | `glueful/import-export` |
  | `thallo.search` | `glueful/meilisearch` |
  | `thallo.subscriptions` | `glueful/subscriptions` |
  | `thallo.tenancy` | `glueful/tenancy` |
  | `thallo.analytics`, `thallo.collections`, `thallo.navigation`, `thallo.render`, `thallo.seo`, `thallo.workflow` | `null` (Thallo app/library-owned) |

  Optional secondary integrations (Payvia for subscriptions payments; Users/Aegis for individual
  import adapters) keep their own degraded feature checks; the singular owner names the engine
  whose activation defines the capability itself.
- Test: resolver truth table (owner absent / installed-disabled / enabled-not-ready / divergent /
  explicit-none / ready); registry proof that requested-on but unavailable is excluded from
  `isEnabled()` and `enabled()` while remaining in `all()`; missing-ledger and unreachable-DB boot
  are fail-closed/non-throwing; existing auth-only discovery response remains byte-compatible.

- [ ] Steps: red → implement contracts/resolver → wire effective registry + all twelve owner
  declarations → full PHP/boundary gates → commit —
  `feat(capabilities): explicit owner availability gates effective capability state`.

---

### Task 7: One system-scoped capability switchboard + operator surface

**Files:**
- Create: `app/Capabilities/CapabilityStateStore.php`, backed by the unscoped
  `Thallo\Contracts\Settings\SystemChannel`, with keys
  `capability.<full-id>.enabled`; add `capability.` to `SystemKeys::PREFIXES`. Read order is
  canonical system key → the existing `search_enabled` system key for `thallo.search` only →
  `config('thallo.capabilities')` → default true. Writes read back before reporting success; the
  first successful `thallo.search` write removes the legacy key, so there is one authority rather
  than two. Before the system table exists, reads fail-soft to config; writes fail explicitly.
- Modify: the `DefaultCapabilityRegistry` factory to source requested state from
  `CapabilityStateStore`; its per-request memo remains authoritative for that boot and a write
  affects the next request after the cache reset.
- Modify: `GeneralSettings::searchEnabled()` and `GeneralSettingsController` to delegate Search
  reads/writes to `CapabilityStateStore` (preserving the current route-cache/manifest purge on an
  effective flip) instead of persisting a second `search_enabled` authority.
- Modify: `CapabilityAdminController` + `routes/admin.php`. Preserve the existing auth-only
  `GET /v1/admin/capabilities` response as the effective-capability feed used by every workspace
  admin. Add operator-only (`content_permission:system.access`) management endpoints:
  `GET /v1/admin/capabilities/manage` lists ALL registered capabilities with requested/effective/
  availability/reason/remedy; `PUT /v1/admin/capabilities/{id}` accepts
  `App\Http\DTOs\UpdateCapabilityStateData` (new `RequestData` DTO with one required
  `#[Rule('required|boolean')] readonly bool $enabled`). The `{id}` must exactly match a
  registered capability or return 404; request text never becomes an arbitrary system key. Disable
  is always allowed; enable refuses 409 when unavailable; successful flips clear RouteCache and
  RouteManifest. The unsafe route carries the existing authenticated-admin CSRF policy and is
  covered by the route-policy inventory test. Add the management controls through
  `admin/src/queries/capabilityManagement.ts` and
  `admin/src/pages/extensions/components/CapabilityManagement.vue`, mounted from
  `admin/src/pages/extensions/index.vue`; they are not exposed to ordinary workspace users.
- Test: state-store precedence + Search legacy cutover; management API
  authority/CSRF/enable-refusal/disable-always in
  `tests/Integration/Http/CapabilityAdminApiTest.php`; existing auth-only discovery response
  unchanged. Add `admin/src/queries/capabilityManagement.spec.ts` and
  `admin/src/__tests__/extensionsCapabilityManagement.spec.ts` for query behavior, state labels,
  unavailable-enable refusal, and always-available disable.

- [ ] Steps: red → implement store and Search cutover → wire operator API/SPA → full PHP + SPA +
  route-policy gates → commit —
  `feat(capabilities): one system-scoped switchboard with operator management`.

---

### Task 8: Tenancy enforcement through the shared executor (spec B6)

**Files:**
- Modify: `packages/thallo-tenancy/src/Enablement/ExtensionActivation.php` — replace
  `app($this->context, MigrationManager::class)->migrate()` with
  `ExtensionSchemaExecutor::migrateProtected(ExtensionActivation::PACKAGE,
  'tenancy-enablement')`. The exact package is `glueful/tenancy`; the executor also includes any
  still-pending core prerequisites, which covers the core `glueful/thallo-tenancy` descriptor
  without a name-derived source list in Thallo. Surface the returned `ExtensionOperation` status,
  failedMigration, and error through `ExtensionActivationContract::migrate()` into the existing
  state machine's `recordFailure` path.
- The `TenancyEnablement` step machine, its protected toggle, and its steps stay EXACTLY as
  they are — only the migration executor underneath changes. `migrateProtected()` records the
  core-owned operation but NEVER performs `activate()`; the later protected activation step keeps
  sole custody of the provider-state write.
- Test: extend the existing tenancy enablement tests — a failing tenancy migration records the
  failing basename and operation id; a held lock surfaces contention as a step failure, not a hang
  (waitSeconds bounded); no disabled non-core extension source is migrated; provider state is
  unchanged after migrate and changes only in the existing activation step.

- [ ] Steps: red → implement against the published framework 1.80 API from Task 4 → gates →
  commit — `feat(tenancy): protected enablement migrates through the shared schema executor`.

---

### Task 9: Beta.3 changelog + docs

**Files:** `CHANGELOG.md`, `README.md`/`docs/` upgrade + production pages,
`docs/internal/OUTSTANDING.md`.

- [ ] **Step 1:** Write the beta.3 changelog section: the behavioral-change entry PROMINENTLY
  covering both installation histories (existing beta.1/beta.2 installs: run
  normalize-receipts → migrate:run → verify once after upgrading; explicitly warn that reversing
  the first two commands can replay alias-backed migrations; fresh installs: provision is one
  locked, failure-aware complete pass), production extension toggling now allowed through the
  executor with operation records, capability availability naming owning extensions, the
  operator-only system-scoped capability switchboard, and the dependency pin-set.
- [ ] **Step 2:** OUTSTANDING reconciliation: tick the "provision migrates app-tier only"
  ledger item (fixed by Task 4); tick/annotate the schema-on-enable pre-launch gate.
- [ ] **Step 3:** Commit — `docs: beta.3 schema-on-enable program notes`.

---

### Task 10: End-to-end policy acceptance

**Files:**
- Test: `tests/Integration/Schema/SchemaProgramAcceptanceTest.php`
- Test: existing distribution-defaults and setup harnesses

- [ ] **Step 1 — closed inventory:** boot on framework 1.80 with Thallo's strict policy and assert every installed package
  with `extra.glueful` declares descriptors or explicit `none`, every descriptor source/path is
  registered exactly once, `undeclaredGluefulPackages()` is empty, and `app:dependent` remains a
  legacy source owned only by the root-app `ThalloServiceProvider`.
- [ ] **Step 2 — enable matrix:** for each first-party extension, prove disabled means its
  `on_enable` files are absent from `globalSources()`; executor enable applies only pending core +
  target files and marks state last; disable preserves tables; re-enable catches up. Tenancy is
  refused by the generic endpoint and succeeds only through its protected machine.
- [ ] **Step 3 — capability matrix:** the six engine-owned capabilities are registered but
  ineffective for absent/disabled/pending/divergent owners and become effective when ready; the
  six app/library-owned capabilities are unaffected by extension state. Prove the existing
  auth-only capability feed includes only effective IDs and the operator feed includes every
  registered ID with its reason.
- [ ] **Step 4 — both installation histories:** run Task 3's beta.2 upgrade choreography and
  Task 4's fresh-provision choreography in separate databases. Both finish with identical Ready
  descriptor inventories, zero pending global files, no dropped rows, and no alias source rows.
- [ ] **Step 5:** run distribution-defaults boot so absent optional providers and a missing ledger
  fail closed without throwing. Commit — `test(schema): accept fresh, beta-upgrade, enablement, and capability policy matrices`.

---

### Task 11: Final matrix + ledger

- [ ] **Step 1:** The full verification matrix, serialized: Thallo full PHP suite; distribution
  smoke; SPA tests + type-check; `composer boundaries`; `composer phpcs`; the Task 3 upgrade
  test; a fresh-provision integration check (Task 4's); phpstan where configured.
- [ ] **Step 2:** Ledger append: program completion record, final version matrix, Thallo's strict
  manifest-policy state plus the framework-major default-flip follow-up, and what remains for the
  beta.3 cut (the release sitting
  itself uses the existing RELEASING.md runbook — bake, verify-dist-archive, tag, artifact gate).
- [ ] **Step 3:** Commit any stragglers. The beta.3 release cut is a separate human-gated
  sitting per the runbook — NOT part of this plan.

## Self-review (completed)

- **Spec coverage:** B2 core-mode packs + plain consequence (Task 2); B1 aliases + verified
  normalization (Tasks 2-3); B8 provision single pass + SPA states (Tasks 4-5); B5 SPA+CLI
  through one executor with production enablement + truthful states (Task 5); B3 capability
  ownership/effective gating (Task 6) plus the operator switchboard (Task 7); B6 tenancy shared
  executor with the protected machine intact (Task 8);
  B7 adoption/verify classification exercised by the fixture test (Task 3); the promised
  Thallo strict-manifest closure with the app-local lane made explicit and permanent (Task 4),
  with the complete cross-policy proof in Task 10;
  beta.3 notes covering both histories (Task 9).
- **Identity safety:** the alias table comes from the beta.2 ledger (including the surprise
  `migrations` source and `app:dependent`); `app`/`app:dependent` stay application schema on a
  permanent lane; normalization is checksum-verified and drop-free and runs before any global
  pending read. The upgrade test starts without post-beta framework receipts.
- **Sequencing:** Task 4 is the single framework 1.80 publication stop and Thallo consumes only
  the published dist. The framework default stays compatible for beta.2; Thallo opts into strict
  manifests only in the adopted beta.3 tree. Tasks 5-10 therefore compile and test from a clean
  checkout without a hidden path repository.
- **Closed inventories:** alias/priority/verifier values and the complete capability-owner table
  are pinned. Per-pack verifier effects remain derived from migration `up()` bodies, but the
  extraction method and isolated negative/positive proof contract are executable and identical to
  Plan 2's reviewed bar.
