# Schema-on-Enable Program — Plan 3 of 3: The Thallo Program

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Thallo consumes the published schema-on-enable stack (framework 1.79.0 + the Plan 2
pin-set), adopts its 13 library packs into the manifest contract with legacy aliases and
receipt normalization, makes provision a single complete pass, drives all extension toggling
through the shared executor (SPA + CLI, production allowed), maps capabilities to owning
packages, swaps tenancy onto the shared executor, proves the beta.2 upgrade path, and closes
the framework's compatibility window (1.80.0) once nothing needs it.

**Architecture:** Pure consumption of the published APIs. Packs become `core`-mode descriptors
(they are libraries — no enable event) with `legacyAliases` carrying today's exact ledger
sources; `migrate:normalize-receipts` rewrites the beta.2 ledger to descriptor identities.
The app-local `app`/`app:dependent` sources stay on the manager's app path and the (permanent)
ownerless-provider append — they are the application's own schema, not package schema.

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
- `app` and `app:dependent` are application schema, not package schema: they stay on the manager's app path and the app-local provider append. The framework closure (Task 9) keeps the ownerless-provider append lane open PERMANENTLY for exactly this.
- Effect-proof verifiers per Plan 2's bar: create migrations prove their tables+columns; ALTER/seed migrations prove their specific effect; sequential predecessor fixtures; unknown basenames refuse.

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
  8 verifiers through the sequential predecessor fixture on SQLite (Plan 2's pattern, one file —
  the packs share the repo's test harness)

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

(Adjust each verifier namespace to the pack's real PSR-4 root — read it from that pack's
composer.json autoload block; the table's class names are the intended basenames.)
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
  `PackVerifierBehaviorTest` (sequential predecessor fixture per pack). Run red.
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
  `composer update` → `php glueful migrate:run` (bootstrap: the `extension_operations` core
  migration) → `php glueful migrate:normalize-receipts` → `php glueful migrate:verify`.

- [ ] **Step 1:** Build the fixture: a SQLite ledger seeded with EXACTLY the recon source map
  (app 21 / app:dependent 11 / migrations 3 / thallo-* counts / glueful/* counts), checksums
  taken from the CURRENT shipped files (the fixture builder computes them live — never
  hardcoded).
- [ ] **Step 2:** The test drives, in order: `ReceiptNormalizer::normalize()` → every alias row
  (`thallo-*`, `migrations`) rewritten to `glueful/thallo-*` identities, ZERO refusals, `app`/
  `app:dependent`/`glueful/*` rows untouched; then `SchemaReadiness` classifies every pack
  descriptor Ready; then `AdoptionService::classify()` reports Ready across the board; row
  count before == after (nothing dropped). Mutate one fixture checksum → that descriptor
  Divergent, everything else unaffected.
- [ ] **Step 3:** Run green; full gates; commit — `test(schema): beta.2 ledger normalizes losslessly to descriptor identities`.

---

### Task 4: Provision is one complete pass

**Files:**
- Modify: framework repo `src/Installer/Installer.php` (branch `schema-closure` off `dev`):
  when `$this->context !== null`, build the migration manager via
  `MigrationManagerFactory::create($this->context, $migrationConnection)` so provision's single
  `migrate()` covers `globalSources()` — app + every core descriptor + enabled-posture
  `on_enable` — instead of the app dir alone (this closes the known "provision applies 18 of
  99" ledger item). The context-less unit-test path keeps the bare manager.
- Test (framework): `tests/Unit/Installer/InstallerFullPassTest.php` — a context + fixture
  manifest with one core descriptor: installer's migrate step applies BOTH the app migration and
  the descriptor migration; ledger-less unit path (no context) unchanged.
- Test (thallo): extend `tests/Integration/Setup/` — provision on a fresh database leaves
  `migrate:status` with ZERO pending for every core + enabled source (the create-admin catch-up
  pass becomes a no-op belt, not the workhorse).

- [ ] Steps: red test in framework → implement → framework gates → commit (framework);
  then the Thallo-side integration test → green → gates → commit (thallo). The framework
  change rides Task 9's 1.80.0.

---

### Task 5: Thallo admin surface through the shared executor

**Files:**
- Modify: `app/Http/Controllers/ExtensionAdminController.php` — delete the production refusal;
  replace the direct `ExtensionStateWriter` + `writeCacheNow()` block with the container's
  `Glueful\Extensions\Schema\ExtensionSchemaExecutor` (`enable($package,'admin-api')` /
  `disable(...)`); surface the operation record (id/status/failed_migration/error) in the
  response; map `SchemaNotBootstrappedException`/`UndeclaredSchemaException`/
  `LockContentionException` to 409 with their remedy messages. Keep existing authority +
  host-writability checks and the audit trail (add the operation id).
- Modify: the extensions LIST endpoint in the same controller — each row gains
  `schema_state` (`ready|pending|divergent` from `SchemaReadiness::forPackage()`, or `none`
  for schema-free) and `cli_command` (`php glueful extensions:enable <package>`).
- Modify (SPA): the admin extensions page (`admin/src/...` — locate the existing extensions
  settings view) — render the schema state chip, disable the toggle while an operation is
  running or the schema is divergent (tooltip carries the reasons + CLI command), refresh the
  list after a toggle response, show `failed_migration` on failure.
- Test: controller tests (spy executor per Plan 1's `ExtensionsControllerTest` pattern):
  production no longer refused; delegation with actor `admin-api`; 409 mappings; list rows
  carry `schema_state`. SPA: extend the page's existing vitest spec for the new states.

- [ ] Steps: red → implement → `composer test` + `pnpm test` + `pnpm type-check` → commit —
  `feat(admin): extension toggling drives the shared schema executor; production enablement with truthful operation states`.

---

### Task 6: Capability → owning package (spec B3)

**Files:**
- Modify: `packages/thallo-contracts/src/Capability/Capability.php` — add
  `public readonly ?string $owningPackage = null` (syntax-validated: `vendor/name` shape when
  non-null).
- Modify: `packages/thallo-contracts/src/Capability/CapabilityRegistry.php` +
  `app/Capabilities/DefaultCapabilityRegistry.php` — registration keeps absent-package
  capabilities registered-but-unavailable; expose `availability(Capability): {available: bool,
  reason: ?string}` resolving owner installed (PackageManifest candidates) + enabled
  (EnabledProviders) + schema-ready (SchemaReadiness), with the refusal naming the owning
  package and the exact remedy (`php glueful extensions:enable <package>` or the SPA action).
- Modify: every engine-backed capability registration to declare its owner — derive the mapping
  by reading each registration site (commerce capabilities → `glueful/commerce`, subscriptions →
  `glueful/subscriptions`, search → `glueful/meilisearch`, etc.); genuinely app-only
  capabilities stay ownerless. Record the evidence in the commit body. Capability IDs are never
  used to infer ownership.
- Modify: the capability toggle path (wherever `thallo.<cap>` flags flip — the admin settings
  flow) to refuse enabling an unavailable capability with the availability reason. This
  SUPERSEDES the T2-era `container->has(CatalogService)` route-gate heuristic where they overlap
  — keep that gate as defense-in-depth.
- Test: a truth-table unit test (owner absent / installed-disabled / enabled-not-ready /
  ready ⇒ available) + a toggle-refusal test.

- [ ] Steps: red → implement → gates → commit — `feat(capabilities): explicit owning-package availability — refusals name the extension and remedy`.

---

### Task 7: Tenancy enforcement through the shared executor (spec B6)

**Files:**
- Modify: `packages/thallo-tenancy/src/Enablement/ExtensionActivation.php` — replace
  `app($this->context, MigrationManager::class)->migrate()` with a source-scoped, lock-held run:
  acquire `MigrationLockInterface::acquireAll([...tenancy's descriptor sources...])`, run
  `migrateSources()` for exactly those sources, finally release; surface the
  `MigrationRunReport` failure (file + error) into the state machine's `recordFailure` path.
- The `TenancyEnablement` step machine, its protected toggle, and its steps stay EXACTLY as
  they are — only the migration executor underneath changes.
- Test: extend the existing tenancy enablement tests — a failing tenancy migration records the
  failing basename; a held lock surfaces contention as a step failure, not a hang (waitSeconds
  bounded); no other source is migrated by the tenancy flow (source-scope proof).

- [ ] Steps: red → implement → gates → commit — `feat(tenancy): enforcement migrations run source-scoped under the shared migration lock`.

---

### Task 8: Beta.3 changelog + docs

**Files:** `CHANGELOG.md`, `README.md`/`docs/` upgrade + production pages,
`docs/internal/OUTSTANDING.md`.

- [ ] **Step 1:** Write the beta.3 changelog section: the behavioral-change entry PROMINENTLY
  covering both installation histories (existing beta.1/beta.2 installs: run
  migrate:run → normalize-receipts → verify once after upgrading; fresh installs: provision is
  one complete pass), production extension toggling now allowed through the executor with
  operation records, capability availability naming owning extensions, and the dependency
  pin-set.
- [ ] **Step 2:** OUTSTANDING reconciliation: tick the "provision migrates app-tier only"
  ledger item (fixed by Task 4); tick/annotate the schema-on-enable pre-launch gate.
- [ ] **Step 3:** Commit — `docs: beta.3 schema-on-enable program notes`.

---

### Task 9: Close the compatibility window (framework 1.80.0)

**Files (framework repo, branch `schema-closure`, together with Task 4's commit):**
- Modify: `src/Extensions/ServiceProvider.php::loadMigrationsFrom()` — the lanes become:
  descriptor-covered path → validate-and-return (unchanged); provider owned by a DECLARED
  package registering an undescribed path → throw (unchanged); provider owned by an UNDECLARED
  Glueful package → **throw `UndeclaredSchemaException`** (the legacy append lane closes —
  every first-party package now declares); ownerless app-local provider → append (PERMANENT
  lane: application schema like Thallo's `app:dependent` lives here by design).
- Modify: `PackageManifest::migrationDescriptors()` docblock + `undeclaredGluefulPackages()`
  stays (diagnostics), but the executor/readiness/adoption checks are already fail-closed — no
  change there.
- Test: update `SingleInventoryTest`'s "undeclared package still appends" case to assert the
  THROW; the app-local append case stays green.
- Modify: `CHANGELOG.md` — `## [1.80.0]`: Installer full-pass migrate (Task 4), undeclared
  Glueful packages can no longer register migrations at boot (declare descriptors or
  `migrations: "none"`), app-local providers unaffected. UPGRADE NOTE: consumers must be on the
  Plan 2/3 adopted releases.
- Then (thallo): bump framework to `^1.80`, `composer update glueful/framework`, full gates
  (proves Thallo boots with the window closed: packs declared, `app:dependent` on the app-local
  lane).

- [ ] Steps: red framework tests → implement → framework full gates → local ff-merge to
  framework dev → **STOP for the human 1.80.0 publication** → thallo repin + full gates →
  commit (thallo).

---

### Task 10: Final matrix + ledger

- [ ] **Step 1:** The full verification matrix, serialized: Thallo full PHP suite; distribution
  smoke; SPA tests + type-check; `composer boundaries`; `composer phpcs`; the Task 3 upgrade
  test; a fresh-provision integration check (Task 4's); phpstan where configured.
- [ ] **Step 2:** Ledger append: program completion record, final version matrix, the
  compatibility-window closure state, and what remains for the beta.3 cut (the release sitting
  itself uses the existing RELEASING.md runbook — bake, verify-dist-archive, tag, artifact gate).
- [ ] **Step 3:** Commit any stragglers. The beta.3 release cut is a separate human-gated
  sitting per the runbook — NOT part of this plan.

## Self-review (completed)

- **Spec coverage:** B2 core-mode packs + plain consequence (Task 2); B1 aliases + verified
  normalization (Tasks 2-3); B8 provision single pass + SPA states (Tasks 4-5); B5 SPA+CLI
  through one executor with production enablement + truthful states (Task 5); B3 capability
  ownership (Task 6); B6 tenancy shared executor with the protected machine intact (Task 7);
  B7 adoption/verify classification exercised by the fixture test (Task 3); the promised
  compatibility-window closure with the app-local lane made explicit and permanent (Task 9);
  beta.3 notes covering both histories (Task 8).
- **Identity safety:** alias table comes from the LIVE ledger (including the surprise
  `migrations` source and `app:dependent`); `app`/`app:dependent` stay application schema on a
  permanent lane; normalization is checksum-verified and drop-free, proven on a fixture built
  from current-file checksums.
- **Sequencing:** Tasks 4+9 share one framework branch and one 1.80.0 release with a single
  human publication stop; everything else is Thallo-local commits.
- **Placeholders:** per-pack effect inventories and the capability-owner mapping are derived
  data with the extraction method stated and Plan 2's worked patterns referenced by exact task;
  the alias/priority/verifier table is fully pinned here.
