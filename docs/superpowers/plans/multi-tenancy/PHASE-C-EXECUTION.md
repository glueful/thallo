# Phase C — Execution Notes

Running log for the SP1 Phase C retrofit build. Local-only; not committed unless folded into the plan.

## Task 0 — Verification gate (SystemFlags durability & locking)

- **Table-backed:** `SystemFlags` reads/writes `thallo_system_flags` (PK `key`), created by
  `packages/thallo-tenancy/migrations/001_CreateSystemFlagsTable.php`.
- **Ambiently migrated:** the migration ships in the pack's `migrations/` dir and is registered via the
  provider's `loadMigrationsFrom` (runs on plain `migrate:run`), so tenancy runtime state is readable
  before tenant resolution / before enablement.
- **`put()` is NON-atomic:** `SystemFlags::put()` does a read (`->first()`) then an `insert`/`update` with
  no row lock or CAS. Two concurrent enablement operations could interleave. **Accepted for Phase C** —
  the retrofit is a single synchronous operation; the compare-and-set enablement lock is **Phase E**.
- The barrier flag (`tenancy.retrofit_active`) rides the same store; the guard's in-memory `active()`
  never reads it on the hot path (recursion), only `refresh()`/`assertWritable()` do.

## Task 1 — Settings split (DONE, held)
- SystemChannel contract + SystemKeys + SystemKeyReconciler; SystemFlags implements SystemChannel;
  SettingsStore/SetupService route system keys to the channel. `OK (5 tests, 15 assertions)`; phpcs clean.
- **Open watch:** reconciler is invoked only by the Task-12 orchestrator (enablement). A pre-existing
  tenancy-OFF dev DB holding `installed`/`admin_url` in `settings` reads as absent until reconciled.
  Fresh installs are consistent. If we want the split backward-compatible for OFF installs, wire a
  boot-time `reconcile()` — DECISION DEFERRED (not in plan; pre-production, local DBs only).

## Tasks 2/3/4 — engine units (DONE, held)
- SchemaIntrospector (6 tests), RetrofitProgress (12), RetrofitDdl pg+mysql (14). Combined 32/32 green; phpcs clean.
- **CROSS-CUTTING FINDING (affects Tasks 9 & 11):** on the live schema only `id` is NOT NULL; business
  columns (e.g. `content_types.slug`) are **nullable** (framework schema builder defaults strings to
  nullable). Task 9 AdditiveRetrofit must set NOT NULL only on the added `tenant_uuid` (after backfill),
  and Task 11 diagnostics/coherence must NOT assume business columns are NOT NULL — key coherence on
  `tenant_uuid` presence/NOT-NULL + widened unique only.
- **Contract notes for later tasks:** RetrofitProgress phases are snake_case constants — use
  `RetrofitProgress::COLUMN_ADDED` etc., never string literals. `RetrofitDdl::autoIncrementPk($col)`
  returns a FULL quoted column-def fragment (`"id" BIGSERIAL PRIMARY KEY`), consume as a column def in
  Task 10. mysql `dropUniqueCandidates` is unguarded `DROP INDEX` — Task 9 re-checks via `uniqueExists`.
- All three tests run under the default Integration suite for now (read-only catalog / truncated
  thallo_system_flags) — they will still pass once the `tenancy-retrofit` suite (Task 5) exists.

## Task 5 — Narrow throwaway harness (DONE, held; VERIFIED BY REAL RUN)
- `tests/Support/RetrofitHarnessTestCase.php` + `HarnessSmokeTest`. Smoke test ACTUALLY RAN (not skipped):
  `OK (2 tests, 2 assertions)` with `THALLO_TENANCY_DEV_LINK=1`. Proven: tenancy dev-link present at
  `vendor/glueful/tenancy/src/`, test pg user has `rolcreatedb`, template clone + drop lifecycle works.
- Template DB `thallo_retrofit_template_test` (migrated once per run via `scripts/run-test-migrations.php`
  in a clean child process, gated by a `to_regclass('public.content_types')` probe); throwaway cloned
  per class via `CREATE DATABASE … TEMPLATE …`, dropped in teardown. Template persists across runs.
- `tenancy-retrofit` `<testsuite>` added to `phpunit.xml` → run retrofit classes with
  `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --testsuite tenancy-retrofit`. Default `composer test`
  (no env) SKIPs them. Do NOT run the DEFAULT suite WITH the env set (would run retrofit tests
  destructively in a shared process) — that's an unsupported invocation.
- **Process rule for remaining tasks:** Tasks 6/7/8 all edit `TenancyServiceProvider::services()` — run
  them SEQUENTIALLY (parallel edits to one provider file risk clobbering registrations).

## Tasks 6/7/8 — preflight, provisioning, barrier (DONE, held; all ran for real)
- Task 6 UniquenessPreflight: `OK (2 tests, 8 assertions)`. Scans owned tables' widened business keys
  (minus tenant_uuid), NULL-excluded (NULLS-DISTINCT). Injects RetrofitDdlFactory (no concrete DDL
  service bound until Task 12).
- Task 7 TenantProvisioner (contracts) + ContractTenantProvisioner (tenancy) + DefaultTenant (pack):
  thallo `OK (3,14)`, tenancy `OK (3,13)`. Contract-only respected; operation-scoped uuid; pre-existing
  block before uuid recording. status/role set via raw update (not fillable). Caught a real varchar(12)
  truncation → confirms live-schema execution.
- Task 8 Write-barrier: `--testsuite tenancy-retrofit` **OK (34,66)**; job closed-shape+lint **OK (17,55)**;
  **FULL default suite OK (1605 tests, 0 failures, 23 opt-in skips)** — nullable gates don't disturb
  tenancy-off. Guard is a SHARED singleton (WriteBarrier factory → same instance); interceptor does
  mutation→owned→active ordering (no recursion). filter_indexes = DELIBERATELY-GLOBAL (plan default).
  Lint: SCOPED (6 request writers) / SYSTEM_WRITERS (ScheduleRepository, VersionPruner) / GLOBAL_BY_PROOF
  (EnsureFilterIndexesJob) / SYSTEM_READERS (AnalyticsQuery, VersionRepository, TemplateRepository,
  RowRepository, SchemaIntrospector, UniquenessPreflight).
  - NOTE: plan snippet `return $reconcile();` in a `:void` handle fatals in PHP — implemented as
    statement-body `$reconcile(); return;`. Semantics identical (fail-closed shape preserved).
  - NOTE: interceptor over-blocks a non-owned write that merely MENTIONS an owned table name as a string
    literal — accepted by design (barrier window is brief).

## Tasks 9/10 — additive + rebuild paths (DONE, held; ran for real)
- Task 9 AdditiveRetrofit: `OK (3,16)`. 5-phase ladder (COLUMN_ADDED→BACKFILLED→NOT_NULL→
  NARROW_UNIQUE_DROPPED→WIDENED_UNIQUE_ADDED), double-guarded (progress + live introspection). NOT NULL
  on tenant_uuid only. Added `SchemaIntrospector::columnExists()` (Task 2 re-run green). tenant_uuid type
  `varchar(12)`.
- Task 10 TableRebuilder: `OK (3,26)`; suite `OK (40,108)`. Reality-first staged swap for
  regions/settings/entry_redirects; failpoint between the two renames; reality-first recovery (to_regclass).
  entry_redirects: surrogate id kept + FRESH sequence via setval (avoids backup-owned-sequence trap);
  all 3 CHECKs verbatim; inline uuid unique + widened business unique. Introspected live DB for exact
  types/nullability.

## SCOPE DECISION (2026-07-10): Thallo v1 is POSTGRES-ONLY
- Thallo v1 targets Postgres (JSONB, expression/filter indexes, PG CHECK constraints). The retrofit is
  Postgres-only; the MySQL dialect was review-round creep. **Task 16 DROPS the MySQL gate** → it is just
  the OFF/ON regression + phpcs. TableRebuilder's pgsql-typed CREATE bodies are correct-by-scope (no gap).
- Per user: FINISH BUILDING first, then CLEAN UP (Task 17). Leave `MysqlRetrofitDdl` inert for now; the
  factory will reject non-pgsql after cleanup. Do not invest further in MySQL.

## Tasks 11/12 — diagnostics + orchestrator (DONE, held; ran for real)
- Task 11 RetrofitDiagnostics: `OK (2,6)`; suite 42. Coherence = tenant_uuid present/NOT-NULL + widened
  unique(s) (REBUILD_PK for regions/settings); no business-column assumption. checkAgreement compares
  flag vs reality without deadlock.
- Task 12 SchemaRetrofit orchestrator: `OK (4,23)`; suite `OK (46,137)`. Exact ordering: driver-gate →
  begin → ensure → preflight(fail→end+throw) → runInternal(reconcile ONLY) → per-table rebuild/additive
  → checkTables → schema_state=widened → checkAgreement → barrier STAYS UP on success. Legacy-key move +
  idempotent re-run tested. rebuild vs additive by `special_backfill==='rebuild'`.
  - NOTE (accepted): SchemaRetrofit injects concrete `App\Settings\SystemKeyReconciler` (pack→app). OK —
    thallo-tenancy is Thallo's own pack, already coupled to Thallo tables via ThalloTenantTables.
  - NOTE: retrofit tests must lower the barrier in setUp before AppTestCase's owned-table DELETEs
    (successful run() leaves barrier UP by design).

## Task 13 — two-boot harness + proofs (DONE, held; ran for real)
- RetrofittedTenantTestCase + PostTransitionStamperTest + FilterIndexJobTenantContextTest: `OK (2,14)`;
  suite `OK (48,151)`. Two-boot sequence works: retrofit(boot1, barrier up) → enable → resetTenancyGlobals
  → boot2 fresh (scoping arms) → barrier down → seed tenants A/B via neutral contract. Stamper proof
  (omitting-insert → stamped) PASSES — validates hook-reset-between-boots.
- **CRITICAL for Tasks 14/15:** `tenants`/`tenant_memberships`/`filter_indexes` are NOT truncated per-test
  (tenants A/B must survive — `runAsTenant` active-checks the tenant, throws TenantNotFoundException if
  wiped). Owned CONTENT tables ARE truncated per-method (clean slate). No `setUp()` override needed
  (truncation runs with null CurrentContext → hooks early-return; enablement read once at boot).
- Acceptance classes each run the full two-boot retrofit in setUpBeforeClass (expensive). Do NOT run
  Task 14/15 as PARALLEL subagents — they collide on the shared throwaway DB name (DROP/CREATE race).

## Tasks 14/15 — MANDATORY acceptance (DONE, held; ran for real)
- Task 14 TenantKeyCoexistence: `OK (3,32)`. slug/composite/ON-CONFLICT shapes coexist across tenants;
  workflow no-clobber proves widened ON-CONFLICT target. Added the file to phpunit.xml tenancy-retrofit suite.
- Task 15 CrossTenantSchedulerPublish: `OK (1,25)`; suite `OK (52,208)`. System scheduler drains both
  tenants; ScheduleRunner::fireScoped re-enters runAsTenant per row; per-tenant publication visibility proven.
- Lint regression FIXED (directly): retrofit-engine getPDO sites (AdditiveRetrofit/TableRebuilder/
  TenancyServiceProvider driver-detect) added as RETROFIT_ENGINE classification (barrier-exempt by design).
  Lint green (5,29).

## !!! DISCOVERED B2 GAP (follow-up task; NOT a Phase C blocker) !!!
- ~21 `table('owned as x')` ALIAS builder reads (entry_drafts/entry_publications/published_entry_references)
  in DeliveryRepository, BackfillRunner, BlockBackfillRunner, BlockUsageScanner, BlockMigrationService,
  PublishedReferenceRepository, ReferenceFilterResolver, MigrationService FAIL-CLOSE under tenancy-on
  (read-hook matches exact table names → TenantQueryGuard throws). Task 15 fixed BlockMigrationRepository::
  activeAny; the rest remain. Off-by-default so not a Phase C blocker, but breaks delivery/backfill/publish
  under tenancy-on. Fix = de-alias all sites OR make the tenancy read-hook alias-aware + add lint coverage.

## Task 16 — Both-ways regression (DONE, held) — PHASE C COMPLETE
- OFF `composer test`: **OK — 1605 tests, 16877 assertions, 0 failures, 39 skips** (opt-in retrofit/
  acceptance classes skip cleanly; lint green).
- ON `--testsuite tenancy-retrofit`: **OK — 52 tests, 208 assertions**.
- phpcs: clean (930/930).
- Phase C hands off with the barrier design intact: a successful `SchemaRetrofit::run()` leaves the barrier
  UP and `schema_state=widened`; **Phase E** lowers it atomically with the transition to `on`.

## ALIAS GAP — FIXED (2026-07-10; better than de-aliasing)
- Root cause: the tenancy auto-injection table hook matched only EXACT owned-table names, so
  `table('entry_publications as p')` went unscoped → TenantQueryGuard fail-closed. Fixed IN THE EXTENSION
  (dogfooding): `TenancyServiceProvider::registerTableHook()` now parses `table [as] alias`, checks
  ownership on the real name, and injects `alias.tenant_uuid = ?` (valid SQL + join-safe). One fix covers
  all ~21 sites + future ones. `splitTableAlias()` helper added.
- Tests: extension `AutoInjectionTest` +2 (aliased read + aliased join) → 142→144 green; thallo alias
  regression folded into `TenantKeyCoexistenceTest` (+2 methods). Extension symlinked at
  vendor/glueful/tenancy → live. Both-ways green: OFF 1605/0-fail/39-skip; ON 54/217.
- Audit confirmed every aliased owned table is the PRIMARY `->table()`; owned tables in join-only position
  all hang off an owned primary, so primary-scoping + correlated joins keep results isolated.
- NOTE: Task 15's BlockMigrationRepository de-alias is now redundant (hook handles it) but harmless — left as-is.

## Task 17 — MySQL cleanup (DONE, held; Thallo v1 is Postgres-only — project fact)
- Deleted `MysqlRetrofitDdl.php`. `RetrofitDdlFactory::for()` now returns `PostgresRetrofitDdl` for 'pgsql'
  and throws `UnsupportedRetrofitDriverException` ("The tenancy schema retrofit is PostgreSQL-only (pgsql).")
  for mysql/sqlite/anything else.
- `RetrofitDdl` interface + `PostgresRetrofitDdl`: dropped MySQL wording; **dropped the MySQL-only `$type`
  param from `setNotNull`** (sole caller `AdditiveRetrofit::setNotNull` line 110 updated).
- `SchemaIntrospector`: stripped mysql/sqlite branches; Postgres-only with private `assertPgsql()` guard
  (throws if `driver() !== 'pgsql'`); `driver()` still returns the real PDO driver name.
- `TableRebuilder`: removed the dead mysql branch + `infoSchemaTableExists`.
- Tests: `RetrofitDdlTest` for('mysql')/for('sqlite')/for('oracle') now assert the throw → OK (14, 14).
- Plan doc: 5 driver-support statements corrected to Postgres-only.
- Verified: retrofit suite OK (54, 217); SchemaIntrospectorTest OK (6, 12); tests/Unit/Tenancy OK (28, 170);
  no lingering mysql/mariadb/MODIFY/AUTO_INCREMENT refs in `src/Retrofit/`; phpcs clean (8/8). No commit.

## DEFERRED / FOLLOW-UP (all commits still HELD)
- FOLLOW-UP (#382) — two-boot harness connection-lifecycle fragility — **FIXED (root cause found).**
  Root cause was NOT Connection::$instances / pool (both proven inert: pooling off, $instances never
  populated). The real leaked static is **`Glueful\Repository\BaseRepository::$sharedConnection`** — a
  process-global Connection memoised across ALL repositories and NEVER reset between framework boots. When
  a two-boot class drops its throwaway DB in teardown, that static still points at the now-terminated
  Connection; the next class's `AppTestCase::setUp → grantSeedActorBypass` builds RoleRepository
  context-only and reuses the dead conn → "PDOException: no connection to the server".
  Fix: `RetrofitHarnessTestCase::resetSharedRepositoryConnection()` nulls that static (reflection — no
  framework reset seam) in BOTH tearDownAfterClass (after DROP) and setUpBeforeClass (before boot). Now
  order-independent: worst-case order (CrossTenant first, then all three other two-boot classes) 8/8 green;
  full tenancy-retrofit 54/217; OFF composer test 1605/0-fail/39-skip. The ordering workaround (CrossTenant
  last; alias tests folded into TenantKey) is no longer load-bearing — left as-is (harmless, saves a 5th boot).
  NOTE: while investigating, a `git checkout src/Database/Connection.php` in the framework accidentally
  reverted HELD (uncommitted) insert-hook code (addInsertHook/clearInsertHooks/applyInsertHooks). It was
  reconstructed from tests/Unit/Database/InsertHookTest.php + the QueryBuilder diff and re-verified
  (InsertHookTest 6/6, all Connection::/QueryExecutor:: statics resolve, OFF suite unchanged). Framework
  remains uncommitted/held.
  Longer-term: a public `BaseRepository::resetSharedConnection()` framework seam would beat reflection.
- Task 1 open watch: boot-time reconcile for OFF installs with legacy `installed` in `settings` (DECISION).

## Environment / harness notes
- Maintenance creds resolved from `DB_PGSQL_HOST/PORT/USERNAME/PASSWORD` (defaults 127.0.0.1:5432 postgres).
- (to be filled during Task 16) MySQL gate DSN/setup.
