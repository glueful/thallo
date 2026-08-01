# Thallo Multi-Tenancy SP1 — Phase C: Schema Retrofit & Settings Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the enable-time schema retrofit engine (add `tenant_uuid`, backfill, widen uniques, rebuild the three PK/inline-unique tables), the uniqueness preflight, operation-scoped default-tenant provisioning, a query-interceptor write-barrier, and the settings system/site split — turning a single-tenant Thallo install into a row-level multi-tenant one, idempotently and resumably.

**Architecture:** A `SchemaRetrofit` orchestrator (in the `thallo-tenancy` pack) drives the single `ThalloTenantTables` registry. It raises a **write-barrier** implemented as a `QueryInterceptorInterface` at `QueryExecutor::executeStatement()` — the one chokepoint **all** builder mutations (insert/insertBatch/upsert/update/delete/forceDelete/restore) funnel through — so every builder writer (HTTP/CLI/queue/scheduler/backfill) is refused while active; the retrofit's own raw-PDO DDL/DML bypasses it, and the handful of enumerated raw-PDO write sites get explicit gates. It provisions the **default tenant + owner membership** through a neutral contract (never concrete extension models), proves uniqueness, moves legacy **system settings** out of the soon-to-be-scoped `settings` table, then per owned table advances a **persisted phase ladder** backed by live introspection. Three tables (`regions`/`settings`/`entry_redirects`) take a **staged, recoverable copy-rebuild** with reality-first recovery. All DDL flows through a **driver-specific `RetrofitDdl`** (PostgreSQL only; the retrofit fail-closes on non-`pgsql`). **Barrier posture:** raised before the first mutation, kept UP through completion; **Phase E** lowers it atomically with the transition to `on` and owns full write-quiescence of long-lived processes. Only a preflight failure *before* any mutation lowers it. The retrofit is a service invoked by an operation — **never an ambient migration**.

**Tech Stack:** PHP 8.3, Glueful framework (context-first; `QueryExecutor::addQueryInterceptor`, hook-reset APIs), PostgreSQL only (retrofit fail-closes on non-`pgsql`), PHPUnit 10, `glueful/tenancy` (soft dep, dev-linked), `Thallo\Tenancy` pack, `glueful/extension-contracts`, `thallo-contracts`.

## Global Constraints

- Work on `dev` directly; **HOLD all commits** until explicit go-ahead. Never stage/commit `CLAUDE.md`.
- No AI/Anthropic attribution anywhere. `declare(strict_types=1)` + `final class` + constructor DI + `use`-imports.
- `composer phpcs` clean (warnings = failures, 120-char lines) before a backend task is done.
- Dev-DB/test-DB migrations + seeds are **local-only**, never committed.
- Pack services in `TenancyServiceProvider::services()`; app services in `ThalloServiceProvider::services()`; each with a `use` import.
- The retrofit ships **no ambient migration** adding `tenant_uuid` (spec §7.4); never runs on plain `migrate:run`.
- **Contract-only rule (spec §4):** Thallo/pack code must not import concrete `Glueful\Extensions\Tenancy\*`. Provisioning goes through a neutral contract.
- **Driver support (retrofit):** PostgreSQL only; the retrofit fail-closes on non-`pgsql` (`UnsupportedRetrofitDriverException`) via `RetrofitDdl`. Thallo v1 is Postgres-only (JSONB, expression indexes, PG CHECK constraints); the inert MySQL dialect has been removed.
- **Write-barrier:** a `QueryInterceptorInterface` covering all builder mutations at `executeStatement()`, plus explicit `assertWritable()` gates at every raw-PDO **write** site over owned data and at runner/job chokepoints. **There is no framework global-middleware stack** — do not invent one. **The barrier's `active()` MUST be a process-local boolean, never a DB read** — `SystemFlags::get()` runs a builder SELECT, which re-enters the interceptor, so reading persistence inside `before()`/`active()` recurses infinitely. Persistence is read once at boot via `refresh()` and on `begin()`/`end()`; coarse `assertWritable()` gates may re-read fresh persistence (they are off the per-query hot path). **Layering:** the interceptor (in-memory `active()`) covers this process and any process that boots during the window; already-running long-lived workers are caught by their coarse `assertWritable()` gates (fresh persisted read). Full pre-emptive quiescence of long-lived processes is **Phase E's** job.
- **Process-static hooks leak across boots.** `Connection::$insertHooks`/`$tableHooks` and `QueryExecutor::$interceptors` are process-global, `boot()` has **no** double-registration guard. Every test boot MUST first clear them; teardown MUST clear them (so stale closures bound to a dropped throwaway DB never run in later classes). Reset APIs (all **static**): `Connection::clearInsertHooks()`, `Connection::clearTableHooks()`, `QueryExecutor::clearQueryInterceptors()`, `TenantTableRegistry::clear()`, `CurrentContext::clear()`. **Do NOT call `TenantContext::clear()`** — it is an *instance* method over per-request `ApplicationContext::requestState` (dies with the boot); calling it statically is a fatal error and there is nothing process-global to reset there. Because these clears are blanket (no named removal), the retrofit harness classes run under a **dedicated `tenancy-retrofit` PHPUnit testsuite invoked separately** (memory: `@runInSeparateProcess` is broken by `Framework::boot()`), so a teardown clear can never strip interceptors from a following non-tenancy class in the same process.
- Retrofit/tenancy-on tests are opt-in via `THALLO_TENANCY_DEV_LINK=1` and run against a **dedicated throwaway PostgreSQL DB** (name from `THALLO_RETROFIT_TEST_DB`, must end `_test`) — never the shared suite DB; teardown drops it and restores env.

---

## MANDATORY ACCEPTANCE TESTS (pinned — DO NOT REMOVE during handoff)

Deferred here from Phase B2; both run on the **real** engine via `RetrofittedTenantTestCase` (Task 13):
1. **`TenantKeyCoexistenceTest`** (Task 14) — two tenants persist **identical business keys** with no collision, across slug (`content_types`), composite (`entry_routes`), and ON-CONFLICT (`workflow_review_states`) shapes.
2. **`CrossTenantSchedulerPublishTest`** (Task 15) — a **system scheduler** drains due rows for two tenants and **publishes each inside the correct tenant context**, on the fully retrofitted content graph.

---

## Task map (dependency-ordered — no cycles)

| # | Task | Depends on |
|---|---|---|
| 0 | Verification gate | — |
| 1 | Settings split + reconciler | — |
| 2 | `SchemaIntrospector` | — |
| 3 | `RetrofitProgress` | — |
| 4 | `RetrofitDdl` strategy | — |
| 5 | **Narrow throwaway harness** (`RetrofitHarnessTestCase`) | 2 |
| 6 | `UniquenessPreflight` | 4, 5 |
| 7 | `TenantProvisioner` + `DefaultTenant` | 5 |
| 8 | Write-barrier (process-local interceptor + raw-write gates + lint) + `filter_indexes` classification | 5 |
| 9 | `AdditiveRetrofit` | 2,3,4,5,7 |
| 10 | `TableRebuilder` | 2,3,4,5,7 |
| 11 | `RetrofitDiagnostics` (narrow-state tested) | 2,5 |
| 12 | `SchemaRetrofit` orchestrator | 1,6,7,8,9,10,11 |
| 13 | **Retrofitted two-boot harness** (`RetrofittedTenantTestCase`) | 12 |
| 14 | ACCEPTANCE A | 13 |
| 15 | ACCEPTANCE B | 13 |
| 16 | Both-ways regression (Postgres-only; MySQL gate dropped) | all |

---

## Interfaces produced (cross-task contract)

```
Thallo\Contracts\Settings\SystemChannel                 get/put/forget
Thallo\Contracts\Tenancy\WriteBarrier                    assertWritable():void   (for raw-PDO gates)
App\Settings\SystemKeys / SystemKeyReconciler            const KEYS; isSystem(); reconcile():list<string>
Glueful\Extensions\Contracts\Tenancy\TenantProvisioner   provisionDefault(ApplicationContext,string $tenantUuid,string $slug,string $name,string $ownerUserUuid):string ; hasAnyTenant(ApplicationContext):bool
Thallo\Tenancy\Retrofit\RetrofitDdl / RetrofitDdlFactory driver/quote/addNullableColumn/setNotNull/dropUniqueCandidates/createUnique/createIndex/renameTable/autoIncrementPk ; for(string):RetrofitDdl
Thallo\Tenancy\Retrofit\SchemaIntrospector              uniqueName/uniqueExists/indexExists/columnNotNull/driver
Thallo\Tenancy\Retrofit\RetrofitProgress                const COLUMN_ADDED..WIDENED_UNIQUE_ADDED, REBUILD_CREATED/SWAPPED/REBUILT ; phaseOf/reached/mark/reset/snapshot
Thallo\Tenancy\Retrofit\UniquenessPreflight             check():PreflightReport
Thallo\Tenancy\Retrofit\DefaultTenant                   ensure(string,string,string):string  uuid():?string
Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard        refresh/begin/end/active(bool,in-mem)/assertWritable(fresh)/runInternal(callable)  (implements WriteBarrier)
Thallo\Tenancy\Retrofit\RetrofitInProgressException      extends Glueful\Http\Exceptions\Server\ServiceUnavailableException  (pins 503)
Thallo\Tenancy\Retrofit\RetrofitWriteBarrierInterceptor before(string,array):void   (QueryInterceptorInterface; isOwned-before-active)
Thallo\Tenancy\Retrofit\AdditiveRetrofit                apply(string):void
Thallo\Tenancy\Retrofit\TableRebuilder                  rebuild(string $table, ?callable $failpoint = null):void
Thallo\Tenancy\Retrofit\RetrofitDiagnostics             checkTables():array  checkAgreement():array{ok,detail}  check():array
Thallo\Tenancy\Retrofit\SchemaRetrofit                  run(string,string,string):RetrofitReport
```

---

### Task 0: Verification gate — SystemFlags durability & locking (no code)

- [ ] Confirm `SystemFlags` is table-backed + ambiently migrated; note `put()` is non-atomic (CAS lock is Phase E); record in `docs/superpowers/plans/multi-tenancy/PHASE-C-EXECUTION.md`. No commit.

---

### Task 1: Settings system/site split with verified data-move

*(Unchanged from the prior revision.)* Create `packages/thallo-contracts/src/Settings/SystemChannel.php`, `app/Settings/SystemKeys.php`, `app/Settings/SystemKeyReconciler.php`; `SystemFlags implements SystemChannel` + bind; route `SettingsStore`/`SetupService` (system keys → channel, incl. `isInstalled()`); reconciler with **channel-wins** precedence + verify-before-delete. Test `SettingsSplitTest` (system writes bypass `settings`; unknown keys → SITE; reconciler moves legacy rows, channel value never clobbered, idempotent).

- [ ] TDD as previously specified. **Commit** (HOLD): `Split settings into system channel + site table with verified data-move`

---

### Task 2: `SchemaIntrospector` — driver-switched introspection

Create `packages/thallo-tenancy/src/Retrofit/SchemaIntrospector.php` + test. `uniqueName/uniqueExists/indexExists/columnNotNull/driver`, PostgreSQL-only (fail-closes on non-`pgsql`), set-equal column comparison. *(Full body as in the prior revision.)*
- [ ] TDD. **Commit** (HOLD): `Add driver-switched schema introspector for retrofit`

---

### Task 3: `RetrofitProgress` — persisted phase ladder

Create `packages/thallo-tenancy/src/Retrofit/RetrofitProgress.php` + test. Constants `COLUMN_ADDED, BACKFILLED, NOT_NULL, NARROW_UNIQUE_DROPPED, WIDENED_UNIQUE_ADDED, REBUILD_CREATED, REBUILD_SWAPPED, REBUILT`; ranked `ORDER`; `phaseOf/reached/mark/reset/snapshot` over a `{table:phase}` JSON in `SystemFlags`. *(Full body as in the prior revision.)*
- [ ] TDD. **Commit** (HOLD): `Add persisted per-table retrofit phase ladder`

---

### Task 4: `RetrofitDdl` — driver strategy (PostgreSQL only; reject others)

Create `RetrofitDdl` (interface), `PostgresRetrofitDdl`, `RetrofitDdlFactory`, `UnsupportedRetrofitDriverException` + test. Methods: `driver/quote/addNullableColumn/setNotNull/dropUniqueCandidates/createUnique/createIndex/renameTable/autoIncrementPk`. pgsql: `"x"`, `ALTER COLUMN … SET NOT NULL`, `[DROP CONSTRAINT IF EXISTS, DROP INDEX IF EXISTS]`, `ALTER TABLE … RENAME TO`, `BIGSERIAL PRIMARY KEY`. The factory admits only `pgsql`; `for('mysql')`/`for('sqlite')`/others throw — Thallo v1 is Postgres-only and the retrofit fail-closes on non-`pgsql`. *(Full body as in the prior revision.)*
- [ ] TDD. **Commit** (HOLD): `Add driver-specific retrofit DDL strategy`

---

### Task 5: Narrow throwaway harness — `RetrofitHarnessTestCase`

The engine-unit base. Boots ONE app against a **dedicated throwaway PostgreSQL DB**, tenancy extension bound, **scoping OFF** (narrow schema). Every retrofit engine test (Tasks 6–12) extends this — so their DDL and their narrow-unique drops never touch the shared suite DB. **Clears process-global hooks before booting and in teardown** (no leaked closures), and **drops the throwaway DB + restores env** in teardown.

**Files:** create `tests/Support/RetrofitHarnessTestCase.php`; smoke test `tests/Integration/Tenancy/Retrofit/HarnessSmokeTest.php`.

**Confirmed wiring:** DB name via env `DB_PGSQL_DATABASE` (read by `config/database.php` + `run-test-migrations.php`); throwaway created by a Postgres **template clone** (migrate `thallo_retrofit_template_test` once per run, then `CREATE DATABASE <db> TEMPLATE …` per class); boot via `bootAppWithConfigOverride('serviceproviders', …)` after setting the env.

- [ ] **Step 1: Implement the base:**
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Execution\QueryExecutor;
use Psr\Container\ContainerInterface;

/**
 * Engine-unit base: boots ONE app against a DEDICATED THROWAWAY PostgreSQL DB with tenancy BOUND but
 * scoping OFF (narrow schema). Isolates all retrofit DDL from the shared suite DB. Because process-
 * global hooks accumulate (boot() has no idempotency guard) and closures bind to this throwaway
 * connection, we CLEAR every tenancy hook/registry/context before booting AND in teardown, and drop
 * the throwaway DB + restore env on the way out.
 */
abstract class RetrofitHarnessTestCase extends AppTestCase
{
    protected static ?ApplicationContext $engineApp = null;
    private static string $priorDb = '';
    private static string $priorPooling = '';
    protected static string $throwawayDb = '';

    /**
     * Clear ALL tenancy process-global state so a boot registers exactly one fresh set. Only STATIC
     * resets belong here. TenantContext::clear() is deliberately absent — it is an INSTANCE method over
     * per-request ApplicationContext::requestState (dies with the boot); calling it statically is fatal.
     */
    protected static function resetTenancyGlobals(): void
    {
        Connection::clearInsertHooks();
        Connection::clearTableHooks();
        QueryExecutor::clearQueryInterceptors();
        if (class_exists(\Glueful\Extensions\Tenancy\Query\TenantTableRegistry::class)) {
            \Glueful\Extensions\Tenancy\Query\TenantTableRegistry::clear();
            \Glueful\Extensions\Tenancy\Context\CurrentContext::clear(); // static process-pointer reset
        }
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (getenv('THALLO_TENANCY_DEV_LINK') !== '1') {
            self::markTestSkipped('Retrofit harness is opt-in (THALLO_TENANCY_DEV_LINK=1).');
        }
        self::registerTenancyAutoloaderOrSkip(); // copy TenantOracleTestCase's private autoloader + class_exists guard

        self::$throwawayDb = getenv('THALLO_RETROFIT_TEST_DB') ?: 'thallo_retrofit_test';
        if (!str_ends_with(self::$throwawayDb, '_test')) {
            self::fail('Throwaway retrofit DB name must end with _test.');
        }
        self::createThrowawayFromTemplate(self::$throwawayDb); // maintenance PDO: DROP/CREATE ... TEMPLATE (migrate template once/run)

        self::$priorDb = (string) getenv('DB_PGSQL_DATABASE');
        self::$priorPooling = (string) getenv('DB_POOLING_ENABLED');
        self::putEnv('DB_PGSQL_DATABASE', self::$throwawayDb);
        self::putEnv('DB_POOLING_ENABLED', 'false');

        self::resetTenancyGlobals(); // drop the shared app's hooks before our first boot
        $base = require dirname(__DIR__, 2) . '/config/serviceproviders.php';
        $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'];
        self::$engineApp = self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers]);

        self::$engineApp->getContainer()->get(Connection::class)->getPDO()->exec(
            "INSERT INTO users (uuid, username, email, status)
             VALUES ('user00000001', 'owner', 'owner@example.test', 'active') ON CONFLICT (uuid) DO NOTHING"
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$engineApp = null;
        self::resetTenancyGlobals();           // stop stale throwaway-bound closures leaking into later classes
        self::dropThrowaway(self::$throwawayDb); // maintenance PDO: terminate connections + DROP DATABASE
        self::putEnv('DB_PGSQL_DATABASE', self::$priorDb);
        self::putEnv('DB_POOLING_ENABLED', self::$priorPooling);
        parent::tearDownAfterClass();
    }

    protected function container(): ContainerInterface
    {
        return self::$engineApp?->getContainer() ?? parent::container();
    }

    protected function appContext(): ApplicationContext
    {
        return self::$engineApp ?? parent::appContext();
    }

    protected function connection(): Connection
    {
        return $this->container()->get(Connection::class);
    }

    private static function putEnv(string $k, string $v): void
    {
        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
    }

    // createThrowawayFromTemplate($db): maintenance PDO to the 'postgres' db; ensure a migrated template
    //   (shell `DB_PGSQL_DATABASE=thallo_retrofit_template_test APP_ENV=testing php scripts/run-test-migrations.php`
    //    once per run), then DROP DATABASE IF EXISTS $db; CREATE DATABASE $db TEMPLATE thallo_retrofit_template_test.
    // dropThrowaway($db): terminate other backends (pg_terminate_backend) then DROP DATABASE IF EXISTS $db.
    // registerTenancyAutoloaderOrSkip(): copy TenantOracleTestCase's targeted autoloader + class_exists skip.
    // NOTE: no SoftDeleteHandler reset needed — its deleted_at cache is keyed by the connection-specific
    //   cache namespace, so the throwaway connection cannot poison another DB's cache.
}
```
**Testsuite isolation.** Add a `tenancy-retrofit` `<testsuite>` to `phpunit.xml.dist` covering `tests/Integration/Tenancy/Retrofit/` (+ the two acceptance tests). Because `resetTenancyGlobals()` blanket-clears process-global interceptors/hooks, these classes MUST run in their own PHPUnit invocation so a teardown clear never strips interceptors from a following non-tenancy class. Task 16's ON regression invokes this suite separately (`--testsuite tenancy-retrofit`), matching the existing dedicated-tenancy-invocation pattern (memory: per-test-migrations harness). `@runInSeparateProcess` is NOT usable (memory: broken by `Framework::boot()`).
- [ ] **Step 2: Smoke test** (`HarnessSmokeTest extends RetrofitHarnessTestCase`): `SELECT current_database()` equals `self::$throwawayDb` (proves isolation); a narrow owned table (`content_types`) has **no** `tenant_uuid` column yet (scoping off, unretrofitted).
- [ ] **Step 3: Run → PASS** (`THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit tests/Integration/Tenancy/Retrofit/HarnessSmokeTest.php`), phpcs. Record the template/clone + SoftDeleteHandler notes in `PHASE-C-EXECUTION.md`.
- [ ] **Commit** (HOLD): `Add narrow throwaway-DB retrofit harness (hook-reset + DB disposal)`

---

### Task 6: `UniquenessPreflight` — duplicate detection (RetrofitDdl-quoted)

Create `UniquenessPreflight` (inject `Connection` + `RetrofitDdl`; quote via `RetrofitDdl::quote()`), `PreflightReport`, `UniquenessPreflightException`. Test **extends `RetrofitHarnessTestCase`** (narrow throwaway DB — the interruption test drops `content_types`' narrow unique via `RetrofitDdl::dropUniqueCandidates`, inserts duplicates, asserts the preflight blocks; polluting the throwaway DB is harmless). *(Impl + test as in the prior revision, now on the harness.)*
- [ ] TDD. **Commit** (HOLD): `Add retrofit uniqueness preflight (driver-quoted, interruption-realistic test)`

---

### Task 7: `TenantProvisioner` contract + `DefaultTenant` (operation-scoped; pre-existing block)

Create `extensions/contracts/src/Tenancy/TenantProvisioner.php` (`provisionDefault(ctx, $tenantUuid, $slug, $name, $owner)` idempotent by uuid; `hasAnyTenant(ctx)`); `extensions/tenancy/src/Bridge/ContractTenantProvisioner.php` + bind; `packages/thallo-tenancy/src/Retrofit/DefaultTenant.php` (pre-records `tenancy.provisioning_tenant_uuid` before provisioning, reuses it on retry — never a bare slug; **blocks** via `PreexistingTenantException` on fresh-start-with-existing-tenants) + `PreexistingTenantException.php`. Tests: extension idempotency-by-uuid; `DefaultTenantTest extends RetrofitHarnessTestCase` (tenant+owner+pointer; crash-then-reuse-intended-uuid; pre-existing block). *(Impl as in the prior revision; DefaultTenantTest now on the harness.)*
- [ ] TDD. **Commit** (HOLD): `Add operation-scoped tenant provisioning contract + default-tenant`

---

### Task 8: Write-barrier — process-local interceptor + raw-write gates + lint; `filter_indexes` classification

**No framework change.** All builder mutations funnel through `QueryExecutor::executeStatement()` → `runInterceptors()`. Register a `QueryInterceptorInterface` that throws for a mutation targeting an owned table while the barrier is active — covering insert/insertBatch/upsert/**update/delete/forceDelete/restore** uniformly. **Critical:** the guard's `active()` is a **process-local boolean**, never a DB read — `SystemFlags::get()` runs a builder SELECT that re-enters this very interceptor, so reading persistence inside `before()` would recurse forever (see `SystemFlags.php:89`). Persistence is read once via `refresh()` at boot and updated in memory by `begin()`/`end()`. The interceptor evaluates **owned-table match BEFORE `active()`** so non-owned/SELECT traffic never even consults the flag. The retrofit's own raw PDO bypasses `QueryExecutor` (intended). Every raw-PDO **write** site over owned data — request-path *and system-path* — gets an explicit `assertWritable()` gate, which re-reads **fresh** persisted state (off the hot path) so an already-running worker sees a mid-flight `begin()`.

**Files:**
- Create `Thallo\Contracts\Tenancy\WriteBarrier` (in `packages/thallo-contracts`) — `assertWritable(): void`
- Create `RetrofitMaintenanceGuard.php` (implements `WriteBarrier`), `RetrofitInProgressException.php` (**extends `Glueful\Http\Exceptions\Server\ServiceUnavailableException`** — pins 503, no handler edit needed), `RetrofitWriteBarrierInterceptor.php`
- Modify `packages/thallo-tenancy/src/TenancyServiceProvider.php` `boot()` — resolve the guard, call `$guard->refresh()`, then register the interceptor **unconditionally** (outside the `tenancyEnabled` gate)
- Gate runners: `ScheduleRunner::run()`, `BackfillRunner::run()`, `BlockBackfillRunner::run()` (inject `?WriteBarrier`, early-return when `assertWritable()` throws / guarded)
- Gate **request-path** raw-write sites (B2 lint SCOPED): `SeoMetaRepository`, `MenuRepository`, `AnalyticsRecorder`, `WorkflowStateRepository`, `BlockMigrationRepository`, `MigrationRepository` — inject `?WriteBarrier`, `assertWritable()` at the top of each raw write method
- Gate **system-path** raw-write sites (the Review gap — these mutate owned data outside `QueryExecutor`, and runner gates do not cover already-running jobs or direct calls). Inject `?WriteBarrier` and put a **fresh `assertWritable()` immediately before each raw mutation** (not once at a long-running entry point — that minimizes the check-to-execute window; Phase E quiescence handles the residual race):
  - `VersionPruner`: before `deleteGuarded()` (or before each delete batch) — raw `DELETE FROM entry_versions`
  - `ScheduleRepository`: **all three** raw `UPDATE entry_schedules` writers — `claimDuePending()` (`:165`), `reclaimStale()` (`:201`), `markOutcome()` (`:226`). Gating only `claimDuePending` leaves an already-running scheduler able to `reclaimStale`/`markOutcome` after the barrier rises.
  - `EnsureFilterIndexesJob`: before the raw `CREATE/DROP INDEX … ON entry_versions` DDL **and** before each builder `filter_indexes` registry mutation
- **`filter_indexes` classification**: keep it **out** of the owned set as **deliberately-global** infrastructure, with the proof documented in `ThalloTenantTables` and asserted by the lint (below). Proof: (a) `content_type_uuid` is a globally-unique 12-char nano-id owned by exactly one tenant, so the `(content_type_uuid, field)` unique can never collide across tenants — no widening needed for coexistence; (b) the rows catalog **global physical schema** — `CREATE INDEX CONCURRENTLY ON entry_versions (<expr>)` is one shared object serving every tenant's (already tenant-scoped) `entry_versions` rows; owning the registry would misrepresent a shared index as per-tenant; (c) access is **authorized by a tenant-scoped `content_types` lookup** — the job reconciles only a `content_type_uuid` it reached through the owning tenant's context (see next bullet), so registry access is gated by owned-table authorization, not by the weaker assumption that a tenant "only knows" its own uuids. *(If review prefers strict ownership instead, the override is: add `tenant_uuid` + widen to `(tenant_uuid, content_type_uuid, field)` + resolve the owning tenant from `content_type_uuid` in the job — flagged in Self-Review as the one open decision.)*
- **`EnsureFilterIndexesJob` tenant context** (Review-5 [P1]): the job calls `ContentTypeRepository::schemaFor()`, which reads the tenant-**owned** `content_types`. It currently runs with no tenant context, so once tenancy is on that read is unscoped/fail-closed. Fix via neutral contracts (no concrete `Tenancy\*` import):
  - **Dispatch** — via a small shared `App\Content\Indexing\FilterIndexJobDispatcher` (so the controller and backfill runner cannot diverge), constructed with the `ApplicationContext` + a `?CurrentTenantResolver` (container `has()` guarded). The real contract is `CurrentTenantResolver::tenantUuid(ApplicationContext $context): string` — it returns `''` (never null), so **normalize `''` to null** before the payload: `$uuid = $resolver?->tenantUuid($context); $tenantUuid = ($uuid === null || $uuid === '') ? null : $uuid; $this->queue->push(EnsureFilterIndexesJob::class, ['content_type_uuid' => $typeUuid, 'tenant_uuid' => $tenantUuid]);`. Both push sites (`BackfillRunner.php:55`, `ContentTypeController.php:277`) call the context-owning dispatcher (the dispatcher holds the `ApplicationContext`, not the callers).
  - **Handle** — a **deterministic closed shape**; only an explicit `null` may take the unscoped path. Missing, empty, whitespace, non-string, and malformed values all **throw** — none can select tenancy-off:
    ```php
    $reconcile = fn () => $this->reconcile($db, $types, $typeUuid, $logger);

    if (!array_key_exists('tenant_uuid', $data)) {
        throw new \InvalidArgumentException('EnsureFilterIndexesJob: tenant_uuid is required.');
    }
    $tenantUuid = $data['tenant_uuid'];

    if ($tenantUuid === null) {
        return $reconcile(); // ONLY an explicit null is the tenancy-off payload
    }
    if (!is_string($tenantUuid) || preg_match('/\A[0-9A-Za-z]{12}\z/', $tenantUuid) !== 1) {
        throw new \InvalidArgumentException('EnsureFilterIndexesJob: invalid tenant_uuid.');
    }
    if (!$container->has(TenantContextRunner::class)) {
        throw new \RuntimeException('EnsureFilterIndexesJob: tenant runner unavailable for a tenant-bearing job.');
    }
    return $container->get(TenantContextRunner::class)->runAsTenant($tenantUuid, $reconcile);
    ```
    A tenant-bearing job with a missing binding **must throw** — reconciling directly would read owned `content_types` unscoped. Barrier gates (above) sit inside `reconcile()` regardless. Phase E's legacy-job drain removes old no-`tenant_uuid` payloads (which now throw) **before** transitioning on — the drain handles legacy jobs; it does not weaken this permanent boundary.
  - **Task-8 unit tests:** payload carries the resolved `tenant_uuid` (and explicit `null` when off); **explicit `null` → direct reconcile**; and each of **missing key**, **empty string**, **whitespace**, **non-string**, **malformed (wrong length/charset)** → **throws** (never the unscoped path); **valid 12-char uuid + missing `TenantContextRunner` binding → throws**.
  - **Test placement** (avoids a task cycle): the code above (dispatch + handle) lands in **Task 8**; its two-tenant verification needs the retrofitted two-tenant harness, so `FilterIndexJobTenantContextTest extends RetrofittedTenantTestCase` is added **after Task 13** (alongside the acceptance tests). It: gives two tenants each a content type with a same-named filterable `field`; enqueues+runs each job with its tenant's payload; asserts each job loads **only its owning** content type's schema and writes/reads **only its own** `filter_indexes` rows (the other tenant's row untouched). A Task-8 unit test may still assert the payload now carries `tenant_uuid` and that a null `tenant_uuid` reconciles directly (tenancy-off path).
- Update the lint (`RawPdoScopingLintTest`): split SYSTEM_PATHS into **SYSTEM_READERS** (raw, read-only / non-owned — no gate) and **SYSTEM_WRITERS** (raw writes to owned data — MUST contain `assertWritable(`); require every SCOPED entry to ALSO contain `assertWritable(`; add a `GLOBAL_BY_PROOF` list (`filter_indexes`-touching sites documented global) so a new owned-table raw writer forces a conscious read-vs-write classification.
- Tests: `RetrofitWriteBarrierTest`, `ScheduleRunnerBarrierTest`, `AlreadyRunningWorkerBarrierTest` (extend `RetrofitHarnessTestCase`)

- [ ] **Step 1: Failing tests** — a builder **UPDATE** and **DELETE** (not just INSERT) to an owned table throw while active; a non-owned table + SELECT pass; the scheduler refuses; and a **fresh persisted `begin()` from "another process"** is seen by a coarse `assertWritable()` gate even though this process's in-memory flag is stale.
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofitHarnessTestCase;
use Glueful\Database\Connection;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;

final class RetrofitWriteBarrierTest extends RetrofitHarnessTestCase
{
    private function guard(): RetrofitMaintenanceGuard
    {
        return $this->container()->get(RetrofitMaintenanceGuard::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard()->end();
        // seed a row while the barrier is DOWN
        $this->connection()->getPDO()->exec(
            "INSERT INTO content_types (uuid, slug, name, status, schema, schema_version, created_at)
             VALUES ('ctbar0000001', 's', 'S', 'active', '[]', 1, now()) ON CONFLICT (uuid) DO NOTHING"
        );
    }

    public function testUpdateToOwnedTableBlockedWhileActive(): void
    {
        $this->guard()->begin();
        $this->expectException(RetrofitInProgressException::class);
        // UPDATE is NOT an insert-hook path — the interceptor is what catches it.
        $this->connection()->table('content_types')->where(['uuid' => 'ctbar0000001'])->update(['name' => 'Z']);
    }

    public function testDeleteToOwnedTableBlockedWhileActive(): void
    {
        $this->guard()->begin();
        $this->expectException(RetrofitInProgressException::class);
        $this->connection()->table('content_types')->where(['uuid' => 'ctbar0000001'])->delete();
    }

    public function testNonOwnedTableAndSelectUnaffected(): void
    {
        $this->guard()->begin();
        $this->connection()->table('tenants')->insert(['uuid' => 'tnbar0000001', 'slug' => 'b', 'name' => 'B', 'status' => 'active']);
        self::assertNotNull($this->connection()->table('content_types')->where(['uuid' => 'ctbar0000001'])->first()); // SELECT passes
    }

    public function testActiveIsProcessLocalAndDoesNotRecurse(): void
    {
        // begin() clears the SystemFlags cache; the NEXT query would, under a DB-reading active(),
        // re-enter the interceptor infinitely. A plain SELECT after begin() must complete (no recursion).
        $this->guard()->begin();
        $this->connection()->getPDO()->exec("SELECT 1"); // raw: sanity
        self::assertNotNull($this->connection()->table('content_types')->where(['uuid' => 'ctbar0000001'])->first());
    }

    public function testCoarseGateSeesFreshPersistedBeginFromAnotherProcess(): void
    {
        // Simulate another process flipping persistence WITHOUT touching this guard's in-memory flag.
        $this->guard()->end();                                           // in-memory + persisted OFF
        $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class)
            ->put('tenancy.retrofit_active', '1');                        // persisted ON only
        // The hot-path interceptor's in-memory active() is still false here, but the coarse gate re-reads.
        $this->expectException(RetrofitInProgressException::class);
        $this->guard()->assertWritable();
    }

    public function testCoarseGateClearsStaleActiveAfterRemoteEnd(): void
    {
        $flags = $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class);
        // Remote begin → local gate throws AND leaves in-memory active=true.
        $flags->put('tenancy.retrofit_active', '1');
        try {
            $this->guard()->assertWritable();
            self::fail('expected barrier');
        } catch (RetrofitInProgressException) {
        }
        self::assertTrue($this->guard()->active());
        // Remote end → the NEXT gate must clear stale active and let an owned builder write through.
        $flags->forget('tenancy.retrofit_active');
        $this->guard()->assertWritable();          // no throw
        self::assertFalse($this->guard()->active()); // synced down
        $this->connection()->table('content_types')
            ->where(['uuid' => 'ctbar0000001'])->update(['name' => 'ok']); // owned write now succeeds
        self::assertSame('ok', $this->connection()->table('content_types')
            ->where(['uuid' => 'ctbar0000001'])->first()['name']);
    }
}
```
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement.** `WriteBarrier` contract:
```php
<?php

declare(strict_types=1);

namespace Thallo\Contracts\Tenancy;

/** Raw-PDO write sites call assertWritable() to honor the retrofit barrier (builder writes are covered
 *  automatically by the query interceptor). Throws when the retrofit is in progress. */
interface WriteBarrier
{
    public function assertWritable(): void;
}
```
`RetrofitInProgressException` simply extends the framework 503 (no handler edit):
```php
final class RetrofitInProgressException extends \Glueful\Http\Exceptions\Server\ServiceUnavailableException
{
    public function __construct()
    {
        parent::__construct('Tenancy retrofit in progress — writes are temporarily unavailable.', retryAfter: 30);
    }
}
```
`RetrofitMaintenanceGuard` — **`active()` is in-memory; only `refresh()`/`assertWritable()` touch persistence** (`SystemFlags` builder reads), and neither is on the per-query hot path so neither recurses:
```php
final class RetrofitMaintenanceGuard implements WriteBarrier
{
    private const KEY = 'tenancy.retrofit_active';
    private bool $active = false; // hot-path state; NEVER read from the DB inside the interceptor

    public function __construct(private readonly SystemFlags $flags)
    {
    }

    /** Called ONCE at boot, before the interceptor is registered — safe to read persistence. */
    public function refresh(): void
    {
        $this->flags->clearCache();
        $this->active = $this->flags->get(self::KEY) === '1';
    }

    public function begin(): void
    {
        $this->flags->put(self::KEY, '1'); // persisted for other processes
        $this->active = true;              // in-memory for this process's interceptor
    }

    public function end(): void
    {
        $this->flags->forget(self::KEY);
        $this->active = false;
    }

    /** Hot path: in-memory only (a DB read here would re-enter the interceptor → infinite recursion). */
    public function active(): bool
    {
        return $this->active;
    }

    /**
     * Coarse boundary (raw-write sites, runners, jobs): re-read FRESH persisted state so an
     * already-running worker sees a mid-flight begin() OR end() from another process. The SELECT this
     * issues fires the interceptor's before(), but before() consults the in-memory bool (no recursion)
     * and, being a SELECT, is ignored regardless. Sync $active in BOTH directions — a worker that saw
     * the barrier rise must also see it fall, or it would reject writes forever after a remote end().
     */
    public function assertWritable(): void
    {
        $this->flags->clearCache();
        $persistedActive = $this->flags->get(self::KEY) === '1';
        $this->active = $persistedActive; // refresh in-memory both ways
        if ($persistedActive) {
            throw new RetrofitInProgressException();
        }
    }

    /**
     * Narrowly-scoped bypass for the retrofit's OWN builder writes while the barrier is up (e.g. the
     * settings reconciler's DELETE from the soon-to-be-owned `settings` table). Lowers only THIS
     * process's in-memory flag; the persisted flag stays '1' so other processes remain blocked. The
     * retrofit is single-threaded synchronous, so no concurrent write can slip through the window.
     */
    public function runInternal(callable $fn): mixed
    {
        $prev = $this->active;
        $this->active = false;
        try {
            return $fn();
        } finally {
            $this->active = $prev;
        }
    }
}
```
The interceptor — **owned-table match BEFORE `active()`**, so non-owned/SELECT traffic never consults the guard:
```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Execution\QueryInterceptorInterface;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Barrier at the single mutation chokepoint (QueryExecutor::executeStatement → runInterceptors). Fires
 * for INSERT/UPDATE/DELETE/UPSERT; SELECTs and non-owned mutations return immediately WITHOUT consulting
 * the guard. When active and the statement mutates an owned table, it throws — refusing every builder
 * writer uniformly. The retrofit's own raw PDO bypasses QueryExecutor, so the engine is unaffected.
 */
final class RetrofitWriteBarrierInterceptor implements QueryInterceptorInterface
{
    /** @var list<string>|null */
    private static ?array $owned = null;

    public function __construct(private readonly RetrofitMaintenanceGuard $guard)
    {
    }

    public function before(string $sql, array $bindings): void
    {
        $lower = strtolower(ltrim($sql));
        $isMutation = str_starts_with($lower, 'insert')
            || str_starts_with($lower, 'update')
            || str_starts_with($lower, 'delete');
        if (!$isMutation) {
            return; // SELECT / DDL-through-builder: never touch active()
        }
        $padded = ' ' . $lower . ' ';
        foreach (self::owned() as $table) {
            // Model table-name matching on TenantQueryGuard::tenantOwnedWriteTarget. Owned-table check
            // BEFORE active() so non-owned mutations skip the guard entirely.
            if (preg_match('/[\s"`\']' . preg_quote($table, '/') . '[\s"`\'(]/', $padded) === 1) {
                if ($this->guard->active()) {
                    throw new RetrofitInProgressException();
                }
                return;
            }
        }
    }

    /** @return list<string> */
    private static function owned(): array
    {
        return self::$owned ??= array_map('strtolower', ThalloTenantTables::tableNames());
    }
}
```
Boot registration (order matters — `refresh()` reads persistence *before* the interceptor exists, so that read cannot recurse):
```php
$guard = app($context, RetrofitMaintenanceGuard::class);
$guard->refresh();
QueryExecutor::addQueryInterceptor(app($context, RetrofitWriteBarrierInterceptor::class));
```
Gate the runners with `$this->barrier?->assertWritable();` (fresh persisted read → covers already-running jobs) and the request-path + system-path raw-write sites likewise. Register `RetrofitMaintenanceGuard` as **`shared: true`** and bind `WriteBarrier::class → RetrofitMaintenanceGuard` **to the same shared instance** (`['class' => RetrofitMaintenanceGuard::class, 'shared' => true]`) — the interceptor, every `WriteBarrier`-injected gate, and the orchestrator MUST see the same in-memory `active` flag, or `begin()` in the orchestrator won't be visible to the interceptor. Register `RetrofitWriteBarrierInterceptor` (pack `services()`).
- [ ] **Step 3: Implement** the contract, guard, exception, interceptor, boot registration, all gates, the `filter_indexes` global-by-proof docblock in `ThalloTenantTables`, and the lint reclassification.
- [ ] **Step 4: Run → PASS**, phpcs.

> **Posture note (layered):** the interceptor's in-memory `active()` covers this process and any process that boots during the window (each boot calls `refresh()`). Already-running long-lived workers are caught by their coarse `assertWritable()` gates (fresh persisted read). Full pre-emptive quiescence of long-lived processes is **Phase E's** job.

- [ ] **Commit** (HOLD): `Add process-local retrofit write-barrier (interceptor + raw-write gates + lint)`

---

### Task 9: `AdditiveRetrofit` — additive per-table path

Create `AdditiveRetrofit` (inject `Connection`, `SchemaIntrospector`, `RetrofitProgress`, `DefaultTenant`, `RetrofitDdl`; all DDL via `RetrofitDdl`; drop-loop uses `dropUniqueCandidates` + post-drop `uniqueExists` assertion). Test **extends `RetrofitHarnessTestCase`** — seeds a narrow `content_types` row, `ensure()` the default tenant, `apply('content_types')`, asserts NOT NULL + backfill + widened unique + narrow gone + idempotent re-apply. *(Impl + test as in the prior revision, now on the harness; the private-method-override problem is gone — the harness doesn't have the oracle stand-in.)*
- [ ] TDD. **Commit** (HOLD): `Add additive per-table retrofit path (idempotent, resumable, driver-aware)`

---

### Task 10: `TableRebuilder` — staged, recoverable rebuild + real failpoint test

Create `TableRebuilder` with the **reality-first staged swap** and `rebuild(string $table, ?callable $failpoint = null)` (failpoint fired right after `original → _backup`). Test **extends `RetrofitHarnessTestCase`**: `regions` (rows/PK + widened-PK coexistence), `entry_redirects` (inline uuid-unique + `status IN (301,302,308)` CHECK + widened business unique, with **complete valid** inserts), and the **real recovery test** (force the mid-swap crash, assert canonical missing + `_backup` present, fresh rebuilder recovers). Recovery recognizes an already-widened canonical (never recopies) and rebuilds a missing `_new` before renaming the original away. `entry_redirects` CHECKs reproduce migration 010 **verbatim**. *(Impl + tests as in the prior revision.)*
- [ ] TDD. **Commit** (HOLD): `Add staged recoverable rebuild for regions/settings/entry_redirects`

---

### Task 11: `RetrofitDiagnostics` — `checkTables()` + `checkAgreement()` (narrow-state tested)

Create `RetrofitDiagnostics` split into `checkTables()` (per-table coherence), `checkAgreement()` (flag vs. reality), and `check()` (both). Test **extends `RetrofitHarnessTestCase`** (narrow state, before the orchestrator exists): on a narrow DB, `checkTables()` reports the tables as **not** coherent (no `tenant_uuid` yet) and `checkAgreement()` is **ok** (schema_state `none` agrees with no widened tables); after manually widening one table, agreement flips. (The fully-widened agreement is asserted by the orchestrator test, Task 12.)
- [ ] **Step 1: Failing test** (narrow-state):
```php
public function testNarrowStateAgreesAndTablesNotYetCoherent(): void
{
    $d = $this->container()->get(\Thallo\Tenancy\Retrofit\RetrofitDiagnostics::class);
    $tables = $d->checkTables();
    self::assertFalse($tables['content_types']['ok']); // narrow → not coherent
    self::assertTrue($d->checkAgreement()['ok']);       // schema_state none + no widened tables → agree
}
```
- [ ] **Step 2: Implement** `checkTables()` / `checkAgreement()` / `check()`. *(Body as in the prior revision.)*
- [ ] TDD. **Commit** (HOLD): `Add split retrofit diagnostics (tables + flag/schema agreement)`

---

### Task 12: `SchemaRetrofit` orchestrator

Create `SchemaRetrofit` + `RetrofitReport`. `run()`: driver gate → `guard->begin()` → `defaultTenant->ensure()` (writes to the non-owned `tenants`/`tenant_memberships` registry — not barrier-blocked) → `preflight->check()` (**fail → `guard->end()` + throw**) → **`guard->runInternal(fn () => $reconciler->reconcile())`** → per present owned table rebuild/additive (raw PDO, bypasses the barrier) → `diagnostics->checkTables()` all ok → set `schema_state=widened` → `diagnostics->checkAgreement()` ok → **barrier stays UP** → report.

> **Why `runInternal` around the reconciler (Review-4 [P1]):** `SystemKeyReconciler::reconcile()` DELETEs migrated system keys from the tenant-**owned** `settings` table via the builder while the barrier is up — the interceptor would reject the retrofit's own cleanup. `runInternal` lowers only THIS process's in-memory flag for that call (persisted flag stays `1`, other processes stay blocked), then restores it. Provisioning and preflight need no bypass (registry writes are non-owned; preflight is read-only). Per-table rebuild/additive need none (raw PDO bypasses `QueryExecutor`).

Test **extends `RetrofitHarnessTestCase`** (runs `run()` against the narrow throwaway DB; asserts sampled additive + rebuild tables widened, `schema_state=widened`, idempotent re-run, resume-after-interrupt, barrier still up). **Add `testRetrofitMovesLegacySystemKeyWhileBarrierUp()`:** seed a legacy `installed` row directly into `settings` before `run()`; assert `run()` completes (the reconciler DELETE was NOT rejected), the key now lives in the system channel (`thallo_system_flags`), and the `settings` row is gone. *(Rest of impl as in the prior revision.)* Register `SchemaRetrofit` + all engine services + a `RetrofitDdl` service (`RetrofitDdlFactory::for($driver)`) in the pack `services()`.
- [ ] TDD. **Commit** (HOLD): `Add schema retrofit orchestrator`

---

### Task 13: Retrofitted two-boot harness — `RetrofittedTenantTestCase`

The acceptance base. Runs the **real** retrofit under scoping-off (boot1), then transitions to scoping-on via a **fresh boot** (enablement is read once at boot), **resetting process-global hooks between boots**, lowers the barrier through boot2, and seeds two tenants. A post-transition test proves the lowered barrier + stamper work with no stale hooks.

**Files:** create `tests/Support/RetrofittedTenantTestCase.php`; test `tests/Integration/Tenancy/Retrofit/PostTransitionStamperTest.php`.

- [ ] **Step 1: Implement:**
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;

/**
 * Acceptance base. Boot1 (parent, scoping OFF): run the real retrofit under the barrier → widened
 * schema, schema_state=widened, barrier UP. Then set tenancy.enabled=1, RESET all process-global hooks
 * (boot() has no idempotency guard — a second boot would otherwise stack a duplicate guard/stamper/
 * interceptor), and BOOT2 FRESH against the SAME throwaway DB so the read-hook/stamper/guard + table
 * registration arm. Lower the barrier through boot2 (emulating Phase E's transition to `on`) and seed
 * two tenants. All accessors resolve from boot2.
 */
abstract class RetrofittedTenantTestCase extends RetrofitHarnessTestCase
{
    protected static ?ApplicationContext $onApp = null;
    protected static string $defaultTenantUuid = '';
    protected static string $tenantAUuid = '';
    protected static string $tenantBUuid = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$engineApp === null) {
            return;
        }

        // Boot1: real retrofit (raises the barrier inside run()).
        $report = self::$engineApp->getContainer()->get(\Thallo\Tenancy\Retrofit\SchemaRetrofit::class)
            ->run('tenant-1', 'Tenant 1', 'user00000001');
        self::$defaultTenantUuid = $report->defaultTenantUuid();

        // Flip enablement (write via boot1), then reset hooks and BOOT2 FRESH so scoping arms cleanly.
        self::$engineApp->getContainer()->get(\Thallo\Tenancy\System\SystemFlags::class)->put('tenancy.enabled', '1');
        self::resetTenancyGlobals();
        $base = require dirname(__DIR__, 2) . '/config/serviceproviders.php';
        $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'];
        self::$onApp = self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers]);

        // Lower the barrier THROUGH boot2 (Phase E's transition to `on`), then seed tenants A/B.
        self::$onApp->getContainer()->get(\Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard::class)->end();
        $provisioner = self::$onApp->getContainer()
            ->get(\Glueful\Extensions\Contracts\Tenancy\TenantProvisioner::class);
        self::$tenantAUuid = \Glueful\Helpers\Utils::generateNanoID(12);
        self::$tenantBUuid = \Glueful\Helpers\Utils::generateNanoID(12);
        $provisioner->provisionDefault(self::$onApp, self::$tenantAUuid, 'tenant-a', 'Tenant A', 'user00000001');
        $provisioner->provisionDefault(self::$onApp, self::$tenantBUuid, 'tenant-b', 'Tenant B', 'user00000001');
    }

    public static function tearDownAfterClass(): void
    {
        self::$onApp = null;
        parent::tearDownAfterClass(); // resets hooks + drops throwaway DB + restores env
    }

    protected function container(): ContainerInterface
    {
        return self::$onApp?->getContainer() ?? parent::container();
    }

    protected function appContext(): ApplicationContext
    {
        return self::$onApp ?? parent::appContext();
    }

    protected function connection(): Connection
    {
        return $this->container()->get(Connection::class);
    }

    protected function runAsTenant(string $tenantUuid, callable $fn): mixed
    {
        return $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class)
            ->runAsTenant($tenantUuid, $fn);
    }

    protected function runAsSystem(callable $fn): mixed
    {
        return $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class)
            ->runAsSystem($fn);
    }
}
```
- [ ] **Step 2: Post-transition stamper test** (`PostTransitionStamperTest extends RetrofittedTenantTestCase`) — proves that after a clean second boot the barrier is DOWN and the insert-hook **stamps a missing value** (hook *count* is not observable, so no claim about it). It must use a **builder insert that OMITS `tenant_uuid`** — `SeoMetaRepository::upsert()` supplies `tenant_uuid` explicitly, so its success would NOT prove stamping:
```php
public function testLoweredBarrierStampsOmittedTenantUuidAfterTransition(): void
{
    // Builder insert with NO tenant_uuid in the payload → the fresh insert-hook must inject it.
    $this->runAsTenant(self::$tenantAUuid, function (): void {
        $this->connection()->table('content_types')->insert([
            'uuid' => 'ctstamp00001', 'slug' => 'stamped', 'name' => 'Stamped',
            'status' => 'active', 'schema' => '[]', 'schema_version' => 1, 'created_at' => date('Y-m-d H:i:s'),
            // deliberately NO 'tenant_uuid'
        ]);
    });
    // Inspect via raw PDO (unscoped) so the read itself proves the value the hook wrote.
    $row = $this->connection()->getPDO()
        ->query("SELECT tenant_uuid FROM content_types WHERE uuid = 'ctstamp00001'")->fetch(\PDO::FETCH_ASSOC);
    self::assertSame(self::$tenantAUuid, $row['tenant_uuid']); // stamped by the fresh boot2 stamper, barrier down
}
```
- [ ] **Step 3: Filter-index job tenant-context test** — the two-tenant verification for the Task-8 `EnsureFilterIndexesJob` change (deferred here because it needs the two-tenant retrofitted harness). Create `tests/Integration/Tenancy/Retrofit/FilterIndexJobTenantContextTest.php` (`extends RetrofittedTenantTestCase`): give tenant A and tenant B each a content type carrying a same-named filterable `field`; dispatch+run each job with its own `tenant_uuid` payload through the fail-closed `handle()`; assert each run loads **only its owning** content type's schema and writes/reads **only its own** `filter_indexes` rows (the other tenant's row untouched). Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit tests/Integration/Tenancy/Retrofit/FilterIndexJobTenantContextTest.php` → PASS.
- [ ] **Step 4: Run → PASS** (`PostTransitionStamperTest` + `FilterIndexJobTenantContextTest`), phpcs.
- [ ] **Commit** (HOLD): `Add retrofitted two-boot harness + post-transition proof + filter-index tenant-context test`

---

### Task 14: MANDATORY ACCEPTANCE A — identical business keys, every key shape

Test `tests/Integration/Tenancy/TenantKeyCoexistenceTest.php` (extends `RetrofittedTenantTestCase`): same slug in two tenants (`content_types`) → 2 rows; same `(content_type_uuid, locale, slug)` (`entry_routes`) → 2 rows; `workflow_review_states::setState` same `(entry, locale)` in two tenants → two independent states. *(Full test as previously drafted.)*
- [ ] **Run → PASS**, phpcs. **Commit** (HOLD): `Add MANDATORY acceptance: identical business keys coexist across tenants`

---

### Task 15: MANDATORY ACCEPTANCE B — cross-tenant scheduler publish

Test `tests/Integration/Tenancy/CrossTenantSchedulerPublishTest.php` (extends `RetrofittedTenantTestCase`): per tenant create type + draft entry + due publish under `runAsTenant`; `runAsSystem(fn () => ScheduleRunner::run())` drains both and publishes each in its own tenant; assert each `findPublication` visible only in its own tenant. Set `scheduler_enabled` on via the channel in `setUp`. *(Full test as previously drafted.)*
- [ ] **Run → PASS**, phpcs. **Commit** (HOLD): `Add MANDATORY acceptance: cross-tenant scheduler publish`

---

### Task 16: Full regression both ways (Postgres-only — MySQL gate dropped)

> **SCOPE (2026-07-10):** Thallo v1 is **Postgres-only** (JSONB, expression/filter indexes, PG CHECK constraints). The MySQL gate is **removed** — the retrofit targets Postgres and fail-closes on non-`pgsql` (`UnsupportedRetrofitDriverException`). The inert `MysqlRetrofitDdl` is cleaned up in the deferred cleanup task. Task 16 is now just the both-ways regression.

- [ ] **Step 2: Full regression BOTH ways**
  - **OFF:** `composer test` — green; the `tenancy-retrofit` suite SKIPs (opt-in env unset); **shared `app_test` untouched** (real retrofit ran only against the throwaway DB, which is dropped in teardown); settings split runs OFF too.
  - **ON:** `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --testsuite tenancy-retrofit` — the retrofit + acceptance classes RUN against the throwaway DB in their **own PHPUnit process** (so the teardown blanket-clear of process-global interceptors cannot poison a following non-tenancy class); the post-transition stamper test + hook resets prove no leak; phpcs clean.

Run: `composer test 2>&1 | tail -5 && THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --testsuite tenancy-retrofit 2>&1 | tail -5 && composer phpcs 2>&1 | tail -3`
- [ ] **Commit** (HOLD): `Add full-path MySQL retrofit gate + Phase C both-ways regression`

---

## Self-Review

- **Review-6 findings resolved:** (1) dispatch uses the **real contract** `CurrentTenantResolver::tenantUuid(ApplicationContext): string` (returns `''`, never null), through a shared `FilterIndexJobDispatcher` that normalizes `''`→null before the payload — no per-caller drift. (2) `handle()` is a **deterministic closed shape**: **only an explicit `null` `tenant_uuid`** reconciles directly; missing key, empty, whitespace, non-string, and malformed (not a 12-char `[0-9A-Za-z]` nano-id) all **throw**, as does a valid uuid with no `TenantContextRunner` binding — no path reaches the unscoped read except explicit tenancy-off. Phase E's legacy-job drain removes old no-payload jobs (which now throw) before transition without weakening this permanent boundary. (3) `FilterIndexJobTenantContextTest` is now an **explicit Task-13 step** (Step 3) with a run command, not just prose under Task 8.
- **Review-5 findings resolved:** (1) **`assertWritable()` syncs both ways** — it now sets `$active = $persistedActive` before deciding, so a worker that saw a remote `begin()` also sees the later remote `end()` and stops rejecting; `testCoarseGateClearsStaleActiveAfterRemoteEnd` (remote begin → throw → remote end → pass → owned write succeeds). (2) **all three `ScheduleRepository` raw writers gated** (`claimDuePending`/`reclaimStale`/`markOutcome`), and the general rule is now a **fresh `assertWritable()` immediately before each raw mutation** (VersionPruner per delete batch, EnsureFilterIndexesJob before DDL + each registry write) to minimize the check-to-execute window (residual race → Phase E quiescence). (3) **`EnsureFilterIndexesJob` gets tenant context** — `tenant_uuid` is carried in the queue payload from both dispatch sites (via the neutral `CurrentTenantResolver`; `''`→null normalized) and reconciliation runs inside `TenantContextRunner::runAsTenant`, so the owned `content_types` read (`schemaFor`) and registry access are tenant-authorized; a two-tenant test proves each job touches only its owning type/rows. The `filter_indexes`-global proof now rests on that **tenant-scoped `content_types` authorization**, not on a tenant "only knowing" its uuids.
- **Review-4 findings resolved:** (1) **recursion killed** — `active()` is a process-local bool; persistence is read only by `refresh()` (once at boot, before the interceptor exists) and by the coarse `assertWritable()` (off the hot path), and the interceptor checks owned-table match *before* `active()`; a `testActiveIsProcessLocalAndDoesNotRecurse` guard proves a query after `begin()` completes. (2) **self-blocked reconciler fixed** — the orchestrator wraps `reconciler->reconcile()` in `guard->runInternal()` (lowers only this process's in-memory flag; persisted stays `1`), with `testRetrofitMovesLegacySystemKeyWhileBarrierUp()`. (3) **raw-write coverage completed** — `assertWritable()` gates added to the system-path writers `VersionPruner`, **all three `ScheduleRepository` writers (`claimDuePending`/`reclaimStale`/`markOutcome`)**, and `EnsureFilterIndexesJob` (fresh persisted read → covers already-running jobs), and the lint now splits SYSTEM_READERS vs SYSTEM_WRITERS and requires a gate on every owned-table raw writer. (4) **`filter_indexes`** classified **deliberately-global** with a documented three-part proof (globally-unique `content_type_uuid` partitions the unique; the physical indexes are shared `entry_versions` objects; reads key by owned type uuids) — asserted by the lint; its writer is still barrier-gated for the raw `entry_versions` DDL. *(This is the one open decision — Self-Review flags the strict-ownership override.)*
- **Review-4 [P2] resolved:** invalid static `TenantContext::clear()` removed from `resetTenancyGlobals()` (kept only static resets); the post-transition test now uses a **`tenant_uuid`-omitting builder insert** so success actually proves stamping; harness classes run under a **dedicated `tenancy-retrofit` testsuite** invoked separately (blanket interceptor-clear can't poison later non-tenancy classes; `@runInSeparateProcess` unusable per `Framework::boot()`); `RetrofitInProgressException` **extends `ServiceUnavailableException`** (503 pinned, no handler edit); SoftDeleteHandler reset dropped — its cache is connection-namespaced.
- **Review-3 carry-overs (still in force):** interceptor covers all builder mutation verbs; two-boot harness with hook resets between boots + teardown; task cycle removed (narrow harness Task 5 → engine tasks → retrofitted harness Task 13); MySQL gate runs the full `SchemaRetrofit::run()` + teardown restores env and drops the throwaway DB.
- **Earlier carry-overs:** split diagnostics; operation-scoped provisioning + pre-existing block; `RetrofitDdl`-quoted preflight; reality-first rebuild recovery with a real failpoint; contract-only provisioning; barrier stays UP for Phase E.
- **Scope boundary:** enable state machine (CAS lock, transition to `on`, barrier-lower, full quiescence) is Phase E; cache-segment + full `diagnose` command are Phase F. Phase C hands off with the barrier UP and `schema_state=widened`.
- **One open decision for review:** `filter_indexes` treated as deliberately-global (proof above). If you want strict per-tenant ownership instead, the override is scoped in Task 8 (add `tenant_uuid` + widen `(tenant_uuid, content_type_uuid, field)` + resolve owning tenant from `content_type_uuid` in the job).
```
