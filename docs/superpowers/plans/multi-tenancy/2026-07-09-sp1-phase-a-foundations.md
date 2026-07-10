# Thallo Multi-Tenancy SP1 — Phase A: Cross-Repo Foundations

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the `TenantContextRunner` contract, a framework insert-hook primitive, and the tenancy binding + insert-stamper — the write-side scoping foundation every later phase depends on.

**Architecture:** Mirror the framework's existing read-side `Connection::addTableHook` with a write-side `QueryBuilder::addInsertHook` primitive that mutates the insert payload before execution. The `glueful/tenancy` extension registers a stamper hook that fills `tenant_uuid` on inserts into registered tenant-owned tables, and binds a new contract-neutral `TenantContextRunner` (deterministic-ordered, fail-fast `forEachTenant`) so no-ORM apps can establish tenant context without referencing extension concretes.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5, Glueful framework query builder / DI, `glueful/extension-contracts` seams.

**Spec:** [../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md](../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md) §5.2, §5.3, §13.

## Global Constraints

- Work on `dev` directly in each repo; no feature branches.
- No AI/Anthropic attribution anywhere (commits, comments, PR text). No `Co-Authored-By`.
- **Hold all commits until explicit go-ahead** — the commit steps below are written for completeness but are NOT executed until the user says so.
- Every PHP file: `declare(strict_types=1);`, `final class` where not designed for extension, constructor DI, `use`-imports (no inline FQCNs).
- `composer phpcs` must be clean (warnings count as failures) in each repo before a task is done.
- Repos & paths:
  - contracts: `/Users/michaeltawiahsowah/Sites/glueful/extensions/contracts` — namespace `Glueful\Extensions\Contracts\`, tests `Glueful\Extensions\Contracts\Tests\`.
  - framework: `/Users/michaeltawiahsowah/Sites/glueful/framework` — namespace `Glueful\`.
  - tenancy: `/Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy` — namespace `Glueful\Extensions\Tenancy\`.
- Sequence is fixed: **A1 → A2 → A3 → A4** (A3 consumes A1; A4 consumes A2 + the extension's existing registry).

---

### Task A1: `TenantContextRunner` contract

**Repo:** `glueful/extension-contracts`

**Files:**
- Create: `src/Tenancy/TenantContextRunner.php`
- Test: `tests/Tenancy/TenantContextRunnerContractTest.php`

**Interfaces:**
- Produces: `interface Glueful\Extensions\Contracts\Tenancy\TenantContextRunner` with `runAsTenant(string $tenantUuid, callable $fn): mixed`, `runAsSystem(callable $fn): mixed`, `forEachTenant(callable $fn): void`.

- [ ] **Step 1: Write the failing test** — a fake implementation proves the signatures compile and behave.

`tests/Tenancy/TenantContextRunnerContractTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tests\Tenancy;

use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use PHPUnit\Framework\TestCase;

final class TenantContextRunnerContractTest extends TestCase
{
    public function testFakeRunnerHonoursTheContract(): void
    {
        $runner = new class implements TenantContextRunner {
            /** @var list<string> */
            public array $seen = [];

            public function runAsTenant(string $tenantUuid, callable $fn): mixed
            {
                $this->seen[] = "tenant:$tenantUuid";
                return $fn();
            }

            public function runAsSystem(callable $fn): mixed
            {
                $this->seen[] = 'system';
                return $fn();
            }

            public function forEachTenant(callable $fn): void
            {
                foreach (['a', 'b'] as $uuid) {
                    $fn($uuid);
                }
            }
        };

        self::assertSame(42, $runner->runAsTenant('t1', static fn (): int => 42));
        self::assertSame('ok', $runner->runAsSystem(static fn (): string => 'ok'));

        $uuids = [];
        $runner->forEachTenant(static function (string $u) use (&$uuids): void {
            $uuids[] = $u;
        });

        self::assertSame(['a', 'b'], $uuids);
        self::assertSame(['tenant:t1', 'system'], $runner->seen);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/contracts && vendor/bin/phpunit tests/Tenancy/TenantContextRunnerContractTest.php`
Expected: FAIL — `Interface "Glueful\Extensions\Contracts\Tenancy\TenantContextRunner" not found`.

- [ ] **Step 3: Write the interface**

`src/Tenancy/TenantContextRunner.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

/**
 * Runs a callable inside an explicit tenant / system context.
 *
 * The neutral seam a no-ORM consumer uses to establish tenant scoping around work that must
 * be stamped and read-scoped (seeders, sync, background jobs, CLI). Implementations are
 * bound by the tenancy extension; consumers depend ONLY on this interface, never on the
 * extension's concrete context classes.
 *
 * Contract:
 *  - runAsTenant(): $fn runs with $tenantUuid as the current tenant; reads are scoped and
 *    writes are stamped to it. Returns whatever $fn returns.
 *  - runAsSystem(): $fn runs in an explicit bypass context — no tenant scoping. For trusted
 *    cross-tenant / infrastructure work (retrofit, enablement). Returns whatever $fn returns.
 *  - forEachTenant(): invokes $fn once per active tenant, each inside that tenant's context.
 *    Iteration is DETERMINISTICALLY ordered (creation date, then name, then uuid) and
 *    FAIL-FAST: on the first failure it stops and surfaces the offending tenant uuid. A
 *    "continue on error" mode is a caller/CLI concern, never this contract's default.
 */
interface TenantContextRunner
{
    public function runAsTenant(string $tenantUuid, callable $fn): mixed;

    public function runAsSystem(callable $fn): mixed;

    /** @param callable(string $tenantUuid): void $fn */
    public function forEachTenant(callable $fn): void;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/contracts && vendor/bin/phpunit tests/Tenancy/TenantContextRunnerContractTest.php`
Expected: PASS.

- [ ] **Step 5: phpcs**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/contracts && composer phpcs`
Expected: no errors/warnings on the two new files.

- [ ] **Step 6: Commit** (HOLD — do not run until told)

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/extensions/contracts
git add src/Tenancy/TenantContextRunner.php tests/Tenancy/TenantContextRunnerContractTest.php
git commit -m "Add TenantContextRunner tenancy contract"
```

---

### Task A2: Framework insert-hook primitive

**Repo:** `glueful/framework`

**Files:**
- Modify: `src/Database/Connection.php` (add the insert-hook registry + public API + payload-validating applier, next to the existing `$tableHooks`)
- Modify: `src/Database/QueryBuilder.php` (apply hooks in `insert`/`insertBatch`/`upsert`; batch column-consistency check)
- Test: `tests/Unit/Database/InsertHookTest.php`

**Interfaces:**
- Produces: `Connection::addInsertHook(\Closure $hook): void`, `Connection::clearInsertHooks(): void`, and `Connection::applyInsertHooks(string $table, array<string,mixed> $data): array<string,mixed>`. `$hook` has shape `fn(string $table, array<string,mixed> $data): array<string,mixed>` and MUST return an associative array. Applied to every `insert()`, each row of `insertBatch()`, and the `$data` of `upsert()` BEFORE validation/execution. No-op when none registered.

**Design decision (from review):** the registry lives on **`Connection`** (public facade, symmetric with `addTableHook`) — not on `QueryBuilder` — for lifecycle/reset control. It remains a **process-level static** by deliberate choice: the tenancy extension registers one hook at boot that must apply across every `Connection`/pooled connection, which per-instance registration can't guarantee. The static inherits the framework's documented **shared-nothing (PHP-FPM) runtime assumption** — identical to `Connection::$tableHooks` and `CurrentContext` — and MUST expose `clearInsertHooks()` so app-boot/test-teardown can reset it (concurrent runtimes like Swoole/RoadRunner would need per-fiber storage; that is a documented limitation, not handled here). `QueryBuilder` calls the static applier; the payload only exists at `insert()` time, so the *application point* stays in `QueryBuilder`.

For reference, the three write methods currently are (`src/Database/QueryBuilder.php:622/635/682`):
```php
public function insert(array $data): int {
    $table = $this->state->getTableOrFail();
    $this->queryValidator->validateInsert($table, $data);
    return $this->insertBuilder->insert($table, $data);
}
public function insertBatch(array $rows): int {
    $table = $this->state->getTableOrFail();
    if ($rows === []) { throw new \InvalidArgumentException('No rows provided for batch insert'); }
    return $this->insertBuilder->insertBatch($table, $rows);
}
public function upsert(array $data, array $updateColumns): int {
    $table = $this->state->getTableOrFail();
    $this->queryValidator->validateInsert($table, $data);
    return $this->insertBuilder->upsert($table, $data, $updateColumns);
}
```

- [ ] **Step 1: Write the failing test**

`tests/Unit/Database/InsertHookTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

final class InsertHookTest extends TestCase
{
    protected function tearDown(): void
    {
        Connection::clearInsertHooks();
        parent::tearDown();
    }

    private function sqliteConnection(): Connection
    {
        $conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $conn->getPDO()->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, tenant_uuid TEXT)');
        return $conn;
    }

    public function testInsertHookStampsMissingColumn(): void
    {
        Connection::addInsertHook(static function (string $table, array $data): array {
            if ($table === 'widgets' && !isset($data['tenant_uuid'])) {
                $data['tenant_uuid'] = 'T-STAMP';
            }
            return $data;
        });

        $conn = $this->sqliteConnection();
        $conn->table('widgets')->insert(['name' => 'a']);

        self::assertSame('T-STAMP', $conn->table('widgets')->where('name', 'a')->first()['tenant_uuid']);
    }

    public function testInsertBatchStampsEveryRow(): void
    {
        Connection::addInsertHook(static function (string $table, array $data): array {
            $data['tenant_uuid'] = 'T-BATCH';
            return $data;
        });

        $conn = $this->sqliteConnection();
        $conn->table('widgets')->insertBatch([['name' => 'a'], ['name' => 'b']]);

        $rows = $conn->table('widgets')->orderBy('name', 'asc')->get();
        self::assertSame(['T-BATCH', 'T-BATCH'], array_column($rows, 'tenant_uuid'));
    }

    public function testNoHookRegisteredIsNoOp(): void
    {
        $conn = $this->sqliteConnection();
        $conn->table('widgets')->insert(['name' => 'a', 'tenant_uuid' => 'kept']);

        self::assertSame('kept', $conn->table('widgets')->where('name', 'a')->first()['tenant_uuid']);
    }

    public function testHookReturningNonAssociativeArrayThrows(): void
    {
        Connection::addInsertHook(static fn (string $table, array $data): array => array_values($data)); // list-shaped

        $conn = $this->sqliteConnection();
        $this->expectException(\UnexpectedValueException::class);
        $conn->table('widgets')->insert(['name' => 'a']);
    }

    public function testInsertBatchWithInconsistentColumnsAfterHooksThrows(): void
    {
        // A hook that adds a column to only some rows makes the batch column set non-uniform.
        Connection::addInsertHook(static function (string $table, array $data): array {
            if (($data['name'] ?? null) === 'b') {
                $data['extra'] = 1;
            }
            return $data;
        });

        $conn = $this->sqliteConnection();
        $this->expectException(\UnexpectedValueException::class);
        $conn->table('widgets')->insertBatch([['name' => 'a'], ['name' => 'b']]);
    }

    public function testInsertBatchNormalizesRowKeyOrderToFirstRow(): void
    {
        // Same column SET, different key ORDER per row: uniformity passes (set compare) and
        // normalization must reorder row 2 to the first row's column order, so values land in
        // the right columns rather than being bound positionally by the wrong order.
        $conn = $this->sqliteConnection();
        $conn->table('widgets')->insertBatch([
            ['name' => 'a', 'tenant_uuid' => 'T1'],
            ['tenant_uuid' => 'T2', 'name' => 'b'], // deliberately reversed key order
        ]);

        $rows = $conn->table('widgets')->orderBy('name', 'asc')->get();
        self::assertSame(['a', 'b'], array_column($rows, 'name'));
        self::assertSame(['T1', 'T2'], array_column($rows, 'tenant_uuid'), 'values stayed aligned to their columns');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/framework && vendor/bin/phpunit tests/Unit/Database/InsertHookTest.php`
Expected: FAIL — `Call to undefined method Glueful\Database\Connection::addInsertHook()`.

- [ ] **Step 3: Add the registry + validating applier to `Connection`**

In `src/Database/Connection.php`, directly after the existing `$tableHooks` block (`addTableHook`/`clearTableHooks`, ~line 585-595), add:
```php
    /**
     * Process-level INSERT hooks — the write-side mirror of $tableHooks. Each runs inside
     * QueryBuilder::insert()/insertBatch()/upsert() BEFORE validation/execution, receiving
     * (string $table, array<string,mixed> $row) and returning the possibly-mutated row.
     * Opt-in (none registered => no-op); runs in registration order.
     *
     * Shared-nothing runtime assumption (same as $tableHooks / CurrentContext): one request
     * per process. Reset via clearInsertHooks() at app-boot/test-teardown. A concurrent
     * runtime (Swoole/RoadRunner/fibers) would need per-fiber storage — not handled here.
     *
     * @var array<int, \Closure(string, array<string,mixed>):array<string,mixed>>
     */
    private static array $insertHooks = [];

    public static function addInsertHook(\Closure $hook): void
    {
        self::$insertHooks[] = $hook;
    }

    public static function clearInsertHooks(): void
    {
        self::$insertHooks = [];
    }

    /**
     * Run every insert hook over one row, validating each hook's return is an associative
     * array (column => value). A hook returning a non-array or a list-shaped array is a
     * programming error and fails LOUDLY here, before SQL generation.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function applyInsertHooks(string $table, array $data): array
    {
        foreach (self::$insertHooks as $hook) {
            $data = $hook($table, $data);
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                throw new \UnexpectedValueException(sprintf(
                    'Insert hook for table "%s" must return an associative array (column => value); got %s.',
                    $table,
                    get_debug_type($data),
                ));
            }
        }
        return $data;
    }
```

- [ ] **Step 4: Apply hooks in `QueryBuilder` write methods (with batch consistency check)**

In `src/Database/QueryBuilder.php`, add `use Glueful\Database\Connection;` if not present, then:
```php
    public function insert(array $data): int
    {
        $table = $this->state->getTableOrFail();
        $data = Connection::applyInsertHooks($table, $data);
        $this->queryValidator->validateInsert($table, $data);

        return $this->insertBuilder->insert($table, $data);
    }

    public function insertBatch(array $rows): int
    {
        $table = $this->state->getTableOrFail();

        if ($rows === []) {
            throw new \InvalidArgumentException('No rows provided for batch insert');
        }

        $rows = array_map(
            static fn (array $row): array => Connection::applyInsertHooks($table, $row),
            $rows,
        );

        // The insert builder assumes a uniform column set across a batch and binds values
        // positionally against the first row's columns. Two steps, in order:
        //
        //   1. UNIFORMITY: compare each row's columns as a SET (order-independent) against the
        //      first row's. Hooks could add a column to only some rows — that is an error, so
        //      fail loudly here, before SQL generation.
        //   2. NORMALIZE: reorder every row's keys to the first row's column order, so a hook
        //      that returns the same columns in a different order cannot misalign values.
        $reference = array_keys($rows[0]);       // first row's column ORDER (authoritative)
        $referenceSet = $reference;
        sort($referenceSet);                     // first row's column SET (for comparison)

        foreach ($rows as $i => $row) {
            $keys = array_keys($row);
            sort($keys);
            if ($keys !== $referenceSet) {
                throw new \UnexpectedValueException(sprintf(
                    'Insert hooks produced an inconsistent column set for batch insert into "%s" at row %d.',
                    $table,
                    $i,
                ));
            }
        }

        // Every row has exactly the reference columns (set equality above), so array_replace
        // over a reference-ordered template rewrites each row into the first row's key order
        // while taking the row's own values.
        $template = array_fill_keys($reference, null);
        $rows = array_map(static fn (array $row): array => array_replace($template, $row), $rows);

        return $this->insertBuilder->insertBatch($table, $rows);
    }

    public function upsert(array $data, array $updateColumns): int
    {
        $table = $this->state->getTableOrFail();
        $data = Connection::applyInsertHooks($table, $data);
        $this->queryValidator->validateInsert($table, $data);

        return $this->insertBuilder->upsert($table, $data, $updateColumns);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/framework && vendor/bin/phpunit tests/Unit/Database/InsertHookTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Guard against regressions in the existing DB suite**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/framework && vendor/bin/phpunit tests/Unit/Database`
Expected: PASS (hooks inert with none registered; existing insert paths unchanged).

- [ ] **Step 7: phpcs + phpstan on the changed files**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/framework && composer phpcs && vendor/bin/phpstan analyse src/Database/Connection.php src/Database/QueryBuilder.php`
Expected: clean (match each file's existing level).

- [ ] **Step 8: Commit** (HOLD)

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/framework
git add src/Database/Connection.php src/Database/QueryBuilder.php tests/Unit/Database/InsertHookTest.php
git commit -m "Add opt-in Connection insert-hook primitive (validated write-side payload mutation)"
```

> **Release note (do not act now):** this is the framework change SP1 pins. The tenancy + contracts versions carrying A3/A1 require the framework release that includes this commit. **Also wire `Connection::clearInsertHooks()` into the framework's test bootstrap/teardown** (next to any `clearTableHooks()` reset) so cross-test hook leakage can't occur.

---

### Task A3: `TenantContextRunner` binding in tenancy

**Repo:** `glueful/tenancy`

**Files:**
- Create: `src/Bridge/ContractTenantRunner.php`
- Create: `src/Exceptions/TenantIterationException.php`
- Modify: `src/TenancyServiceProvider.php` (add the contract binding in `services()`)
- Test: `tests/Bridge/ContractTenantRunnerTest.php`

**Interfaces:**
- Consumes: `Glueful\Extensions\Contracts\Tenancy\TenantContextRunner` (A1); the extension's `Bypass\Tenancy::runAsTenant(Tenant|string, callable): mixed` / `runAsSystem(callable): mixed` (static); `Context\CurrentContext::{set,get,clear}(?ApplicationContext)`; `Models\Tenant::query($context)`.
- Produces: `Glueful\Extensions\Tenancy\Bridge\ContractTenantRunner` bound to the `TenantContextRunner` contract in the container.

**Context for the implementer:** `Bypass\Tenancy`'s methods call `requireContext()` = `CurrentContext::get()` or throw — so a `CurrentContext` must be set. In a request it's set by the `tenant` middleware; from CLI/background it is not. The bridge therefore ensures a `CurrentContext` exists (setting it from the injected `ApplicationContext`, clearing after) before delegating. `forEachTenant` must be **deterministic + fail-fast** — the extension's own `Scheduling\ForEachTenant` is neither (unordered, continue-on-error), so do NOT reuse it here.

- [ ] **Step 1: Write the failing test**

`tests/Bridge/ContractTenantRunnerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Bridge;

use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantRunner;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantIterationException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Tests\TenancyTestCase; // existing base that boots an app context + sqlite

final class ContractTenantRunnerTest extends TenancyTestCase
{
    private function runner(): ContractTenantRunner
    {
        return new ContractTenantRunner($this->context());
    }

    protected function tearDown(): void
    {
        CurrentContext::clear();
        parent::tearDown();
    }

    public function testImplementsContract(): void
    {
        self::assertInstanceOf(TenantContextRunner::class, $this->runner());
    }

    public function testRunAsTenantSetsCurrentTenantAndReturnsValue(): void
    {
        $t = Tenant::create($this->context(), ['uuid' => 'ten000000001', 'slug' => 'acme', 'name' => 'Acme', 'status' => 'active']);

        $seen = $this->runner()->runAsTenant($t->uuid, function () {
            $ctx = CurrentContext::get();
            self::assertNotNull($ctx);
            return $ctx->getRequestState('tenancy.tenant')?->uuid;
        });

        self::assertSame('ten000000001', $seen);
    }

    public function testForEachTenantIsDeterministicAndFailFast(): void
    {
        // Seed out of order; expect creation-date/name/uuid ordering.
        Tenant::create($this->context(), ['uuid' => 'ten000000002', 'slug' => 'b', 'name' => 'Beta', 'status' => 'active']);
        Tenant::create($this->context(), ['uuid' => 'ten000000001', 'slug' => 'a', 'name' => 'Alpha', 'status' => 'active']);

        $order = [];
        $this->runner()->forEachTenant(function (string $uuid) use (&$order): void {
            $order[] = $uuid;
        });
        self::assertSame(['ten000000002', 'ten000000001'], $order, 'ordered by created_at then name then uuid');

        // Fail-fast: throwing inside the callback stops iteration and surfaces the tenant uuid.
        $hit = [];
        try {
            $this->runner()->forEachTenant(function (string $uuid) use (&$hit): void {
                $hit[] = $uuid;
                throw new \RuntimeException('boom');
            });
            self::fail('expected TenantIterationException');
        } catch (TenantIterationException $e) {
            self::assertSame('ten000000002', $e->tenantUuid);
            self::assertCount(1, $hit, 'stopped after the first failing tenant');
        }
    }

    public function testPriorContextIsRestoredAfterSuccessAndException(): void
    {
        $a = Tenant::create($this->context(), ['uuid' => 'ten0000000A', 'slug' => 'a', 'name' => 'A', 'status' => 'active']);
        $b = Tenant::create($this->context(), ['uuid' => 'ten0000000B', 'slug' => 'b', 'name' => 'B', 'status' => 'active']);

        // Establish an outer context pinned to tenant A (as the middleware would in a request).
        CurrentContext::set($this->context());
        $this->context()->setRequestState('tenancy.tenant', $a);

        // Nested runAsTenant(B) on success must restore A afterwards.
        $this->runner()->runAsTenant($b->uuid, static fn (): int => 1);
        self::assertSame('ten0000000A', CurrentContext::get()?->getRequestState('tenancy.tenant')?->uuid, 'A restored after success');

        // Nested runAsTenant(B) that throws must ALSO restore A (no tenant-B leak).
        try {
            $this->runner()->runAsTenant($b->uuid, static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('expected the inner throw to propagate');
        } catch (\RuntimeException) {
            // expected
        }
        self::assertSame('ten0000000A', CurrentContext::get()?->getRequestState('tenancy.tenant')?->uuid, 'A restored after exception');
        self::assertNull(CurrentContext::get()?->getRequestState('tenancy.bypass'), 'no bypass state leaked');
    }
}
```
(If the base class name differs, use the extension's existing test base — check `tests/` for the class that boots an `ApplicationContext` + in-memory sqlite and exposes `context()`. This restoration test is the guard against a failed tenant sync leaking one tenant's context into the next — it relies on `Bypass\Tenancy`'s own save→set→try/finally→restore of `tenancy.tenant`/`tenancy.bypass`; if it fails, the bug is in the bridge/`Bypass` composition, not the test.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy && vendor/bin/phpunit tests/Bridge/ContractTenantRunnerTest.php`
Expected: FAIL — `Class "Glueful\Extensions\Tenancy\Bridge\ContractTenantRunner" not found`.

- [ ] **Step 3: Create the exception**

`src/Exceptions/TenantIterationException.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/**
 * Thrown by TenantContextRunner::forEachTenant when work for a tenant fails. Carries the
 * offending tenant uuid so fail-fast callers can report exactly where iteration stopped.
 */
final class TenantIterationException extends \RuntimeException
{
    public function __construct(
        public readonly string $tenantUuid,
        \Throwable $previous,
    ) {
        // Preserve the cause's code when it is a usable int (PDOException etc. may use a
        // string SQLSTATE — fall back to 0 there rather than passing a non-int to the parent).
        $code = is_int($previous->getCode()) ? $previous->getCode() : 0;

        parent::__construct(
            sprintf('forEachTenant failed for tenant "%s": %s', $tenantUuid, $previous->getMessage()),
            $code,
            $previous,
        );
    }
}
```

- [ ] **Step 4: Create the bridge**

`src/Bridge/ContractTenantRunner.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Tenancy\Bypass\Tenancy;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantIterationException;
use Glueful\Extensions\Tenancy\Models\Tenant;

/**
 * Binds the neutral TenantContextRunner contract over the extension's Bypass\Tenancy.
 *
 * Bypass\Tenancy's static methods require a CurrentContext (they call requireContext()).
 * In a request the `tenant` middleware sets it; from CLI/background it is not set, so this
 * bridge ensures one exists (from the injected ApplicationContext) before delegating.
 */
final class ContractTenantRunner implements TenantContextRunner
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function runAsTenant(string $tenantUuid, callable $fn): mixed
    {
        return $this->withContext(static fn (): mixed => Tenancy::runAsTenant($tenantUuid, $fn));
    }

    public function runAsSystem(callable $fn): mixed
    {
        return $this->withContext(static fn (): mixed => Tenancy::runAsSystem($fn));
    }

    /** @param callable(string $tenantUuid): void $fn */
    public function forEachTenant(callable $fn): void
    {
        $tenants = Tenant::query($this->context)
            ->where('status', 'active')
            ->orderBy('created_at', 'asc')
            ->orderBy('name', 'asc')
            ->orderBy('uuid', 'asc')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $this->runAsTenant($tenant->uuid, static function () use ($fn, $tenant): void {
                    $fn($tenant->uuid);
                });
            } catch (\Throwable $e) {
                throw new TenantIterationException($tenant->uuid, $e);
            }
        }
    }

    /**
     * Ensure a CurrentContext exists for Bypass\Tenancy::requireContext(). If one is already
     * active (a live request), reuse it untouched; otherwise set + clear around $fn.
     */
    private function withContext(callable $fn): mixed
    {
        if (CurrentContext::get() !== null) {
            return $fn();
        }

        CurrentContext::set($this->context);
        try {
            return $fn();
        } finally {
            CurrentContext::clear();
        }
    }
}
```

> **Verify during implementation:** that `Tenant::query(...)` returns rows as objects exposing `->uuid` (ORM models do). If `get()` returns arrays in this codebase's ORM, adjust `$tenant->uuid` / the `forEachTenant` closure accordingly. Also confirm `orderBy($col, 'asc')` is the Builder's signature (it is used across the framework); if it is single-arg, drop the direction.

- [ ] **Step 5: Bind it in the service provider**

In `src/TenancyServiceProvider.php`, add the import and the binding alongside the existing contract bindings.

Add imports (top of file, with the other `use` lines):
```php
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantRunner;
```

Add to the array returned by `services()` (next to the `CurrentTenantResolver::class` / `TenantTableRegistryContract::class` entries):
```php
            TenantContextRunner::class => [
                'class' => ContractTenantRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
```
(`autowire` because, unlike the other two bridges, this one takes an `ApplicationContext` constructor arg.)

- [ ] **Step 6: Run test to verify it passes**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy && vendor/bin/phpunit tests/Bridge/ContractTenantRunnerTest.php`
Expected: PASS.

- [ ] **Step 7: Full tenancy suite + phpcs**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy && vendor/bin/phpunit && composer phpcs`
Expected: PASS, clean.

- [ ] **Step 8: Commit** (HOLD)

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy
git add src/Bridge/ContractTenantRunner.php src/Exceptions/TenantIterationException.php src/TenancyServiceProvider.php tests/Bridge/ContractTenantRunnerTest.php
git commit -m "Bind TenantContextRunner contract (deterministic, fail-fast forEachTenant)"
```

---

### Task A4: Insert-stamper hook registration in tenancy

**Repo:** `glueful/tenancy`

**Files:**
- Create: `src/Query/TenantInsertStamper.php`
- Modify: `src/TenancyServiceProvider.php` (register the hook in `boot()`, next to `registerTableHook()`)
- Test: `tests/Query/TenantInsertStamperTest.php`

**Interfaces:**
- Consumes: `Connection::addInsertHook()` (A2); `Query\TenantTableRegistry::isTenantOwned()`; `Context\CurrentContext`; request-state keys `tenancy.tenant`, `tenancy.bypass`; `Exceptions\TenantScopeViolationException`.
- Produces: `TenantInsertStamper::hook(): \Closure` — the closure registered via `Connection::addInsertHook`, and `TenancyServiceProvider::boot()` registering it under the same `tenancy.enabled` gate as the read hook.

**Context for the implementer:** mirror the read-side `registerTableHook()` (`src/TenancyServiceProvider.php:148-175`) and the guard's bypass/context checks (`src/Query/TenantQueryGuard.php:46-68`). Semantics:
- Not a tenant-owned table → return row unchanged.
- No `CurrentContext` → unchanged (documented boot/migration exception; safe because the widened `tenant_uuid NOT NULL` rejects an unstamped app write at the DB — app writes must always carry context via the required middleware or `runAsTenant`/`runAsSystem`, never rely on this branch).
- Explicit bypass set (`runAsSystem`/`forAnyTenant`) → unchanged (a supplied cross-tenant uuid passes through — system work is trusted).
- Current tenant present, **no** supplied `tenant_uuid` → stamp it.
- Current tenant present, supplied `tenant_uuid` **matches** current → keep it.
- Current tenant present, supplied `tenant_uuid` **differs** from current (not bypass) → **throw** `TenantScopeViolationException` (the hook holds the payload, so it enforces cross-tenant writes directly rather than deferring to the SQL-text guard).
- **Fail closed:** a live context, no bypass, tenant-owned table, but no current tenant → **throw** (an unscoped write is corruption, so this throws in all environments — a deliberate divergence from the read guard's prod-never-throws posture, reviewed and confirmed).

- [ ] **Step 1: Write the failing test**

`tests/Query/TenantInsertStamperTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Query;

use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantScopeViolationException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Query\TenantInsertStamper;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\Tests\TenancyTestCase;

final class TenantInsertStamperTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantTableRegistry::register('posts'); // a tenant-owned table for the test
    }

    protected function tearDown(): void
    {
        CurrentContext::clear();
        parent::tearDown();
    }

    private function stamp(string $table, array $data): array
    {
        return (TenantInsertStamper::hook())($table, $data);
    }

    public function testStampsMissingTenantUuidFromCurrentTenant(): void
    {
        $t = Tenant::create($this->context(), ['uuid' => 'ten000000001', 'slug' => 's', 'name' => 'N', 'status' => 'active']);
        CurrentContext::set($this->context());
        $this->context()->setRequestState('tenancy.tenant', $t);

        self::assertSame('ten000000001', $this->stamp('posts', ['title' => 'x'])['tenant_uuid']);
    }

    public function testKeepsSuppliedTenantUuidWhenItMatchesCurrent(): void
    {
        $t = Tenant::create($this->context(), ['uuid' => 'ten000000001', 'slug' => 's', 'name' => 'N', 'status' => 'active']);
        CurrentContext::set($this->context());
        $this->context()->setRequestState('tenancy.tenant', $t);

        // Supplying the CURRENT tenant is fine — preserved, not rejected.
        self::assertSame('ten000000001', $this->stamp('posts', ['title' => 'x', 'tenant_uuid' => 'ten000000001'])['tenant_uuid']);
    }

    public function testRejectsSuppliedWrongTenantUuid(): void
    {
        $t = Tenant::create($this->context(), ['uuid' => 'ten000000001', 'slug' => 's', 'name' => 'N', 'status' => 'active']);
        CurrentContext::set($this->context());
        $this->context()->setRequestState('tenancy.tenant', $t);

        // Supplying a DIFFERENT tenant is a cross-tenant write => throw (unless bypass/system).
        $this->expectException(TenantScopeViolationException::class);
        $this->stamp('posts', ['title' => 'x', 'tenant_uuid' => 'ten000000999']);
    }

    public function testBypassAllowsCrossTenantSuppliedUuid(): void
    {
        CurrentContext::set($this->context());
        $this->context()->setRequestState('tenancy.bypass', 'system');

        // Under system bypass the stamper is a no-op — a supplied uuid passes through untouched.
        self::assertSame('ten000000999', $this->stamp('posts', ['title' => 'x', 'tenant_uuid' => 'ten000000999'])['tenant_uuid']);
    }

    public function testNonTenantTableUnchanged(): void
    {
        CurrentContext::set($this->context());
        self::assertArrayNotHasKey('tenant_uuid', $this->stamp('unregistered_table', ['a' => 1]));
    }

    public function testNoCurrentContextIsNoOp(): void
    {
        CurrentContext::clear();
        self::assertArrayNotHasKey('tenant_uuid', $this->stamp('posts', ['title' => 'x']));
    }

    public function testBypassIsNoOp(): void
    {
        CurrentContext::set($this->context());
        $this->context()->setRequestState('tenancy.bypass', 'system');
        self::assertArrayNotHasKey('tenant_uuid', $this->stamp('posts', ['title' => 'x']));
    }

    public function testFailsClosedWhenContextButNoTenant(): void
    {
        CurrentContext::set($this->context()); // live context, no bypass, no tenant.tenant
        $this->expectException(TenantScopeViolationException::class);
        $this->stamp('posts', ['title' => 'x']);
    }
}
```
(Confirm the request-state setter name — the guard reads `getRequestState('tenancy.bypass')` / `getRequestState('tenancy.tenant')`; use the matching setter, e.g. `setRequestState(...)`, or set via `TenantContext` as the middleware does.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy && vendor/bin/phpunit tests/Query/TenantInsertStamperTest.php`
Expected: FAIL — `Class "...TenantInsertStamper" not found`.

- [ ] **Step 3: Create the stamper**

`src/Query/TenantInsertStamper.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Query;

use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantScopeViolationException;
use Glueful\Extensions\Tenancy\Models\Tenant;

/**
 * Write-side counterpart to the read table-hook: stamps the current tenant's tenant_uuid onto
 * inserts into registered tenant-owned tables. Registered via QueryBuilder::addInsertHook so
 * every builder insert/insertBatch/upsert flows through it. Raw PDO writes bypass this (and the
 * guard) and must be handled explicitly by the consuming app.
 */
final class TenantInsertStamper
{
    /** @return \Closure(string, array<string,mixed>):array<string,mixed> */
    public static function hook(): \Closure
    {
        return static function (string $table, array $data): array {
            if (!TenantTableRegistry::isTenantOwned($table)) {
                return $data;
            }

            $ctx = CurrentContext::get();
            if ($ctx === null) {
                // DOCUMENTED EXCEPTION: no CurrentContext => migrations / boot / CLI without a
                // runAsTenant wrapper. We do NOT throw here (that would break framework
                // migrations that touch these tables). It is SAFE because, once the schema is
                // widened, tenant_uuid is NOT NULL — an unstamped app write fails LOUDLY at the
                // DB, it cannot silently persist an unscoped row. Application writes must always
                // carry context (the required `tenant` middleware in a request; runAsTenant/
                // runAsSystem for seeders/jobs/CLI) and must NEVER rely on this no-op branch.
                return $data;
            }

            // Explicit bypass (runAsSystem / forAnyTenant): unscoped write is intentional.
            if ($ctx->getRequestState('tenancy.bypass') !== null) {
                return $data;
            }

            $tenant = $ctx->getRequestState('tenancy.tenant');
            if (!$tenant instanceof Tenant) {
                // Live context, no bypass, tenant-owned write, but no tenant resolved => leak.
                throw new TenantScopeViolationException(sprintf(
                    'Insert into tenant-owned table "%s" with no current tenant (fail-closed).',
                    $table,
                ));
            }

            $supplied = $data['tenant_uuid'] ?? null;
            if ($supplied !== null && $supplied !== '') {
                // The hook holds the payload directly, so it enforces cross-tenant writes here
                // rather than relying on the SQL-text guard: a supplied tenant_uuid that differs
                // from the current tenant (and we are NOT in bypass) is a boundary violation.
                if ((string) $supplied !== $tenant->uuid) {
                    throw new TenantScopeViolationException(sprintf(
                        'Insert into tenant-owned table "%s" supplied tenant_uuid "%s" while current tenant is "%s".',
                        $table,
                        (string) $supplied,
                        $tenant->uuid,
                    ));
                }

                return $data; // matches the current tenant — leave as supplied
            }

            $data['tenant_uuid'] = $tenant->uuid;

            return $data;
        };
    }
}
```

- [ ] **Step 4: Register it in boot()**

In `src/TenancyServiceProvider.php` `boot()`, inside the existing `if (\config($context, 'tenancy.enabled', true) === true) { ... }` block, right after `self::registerTableHook();` / the `QueryExecutor::addQueryInterceptor(...)` line, add:
```php
                // Write-side stamper: fill tenant_uuid on builder inserts into owned tables.
                Connection::addInsertHook(TenantInsertStamper::hook());
```
Add the imports at the top (`Connection` is already imported by the provider — it uses it in `registerTableHook()`; add only the stamper):
```php
use Glueful\Extensions\Tenancy\Query\TenantInsertStamper;
```

> The read hook uses `Connection::addTableHook`; the write stamper uses the symmetric `Connection::addInsertHook` (A2). Registering both under the one `tenancy.enabled` gate keeps read-scoping and write-stamping activated together.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy && vendor/bin/phpunit tests/Query/TenantInsertStamperTest.php`
Expected: PASS (8 tests).

- [ ] **Step 6: Full tenancy suite + phpcs**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy && vendor/bin/phpunit && composer phpcs`
Expected: PASS, clean.

- [ ] **Step 7: Commit** (HOLD)

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy
git add src/Query/TenantInsertStamper.php src/TenancyServiceProvider.php tests/Query/TenantInsertStamperTest.php
git commit -m "Stamp tenant_uuid on builder inserts via the framework insert-hook"
```

---

## Phase A self-review checklist (run before handing off)

- **Spec coverage:** A1 = §13.1 + the `TenantContextRunner` seam (§5.2); A2 = §5.3 "framework insert-hook primitive"; A3 = §13.3 binding + deterministic/fail-fast `forEachTenant`; A4 = §5.3 stamper + fail-closed. ✅
- **Type consistency:** `Connection::addInsertHook(\Closure)` / `clearInsertHooks()` / `applyInsertHooks()` used identically in A2 (definition), A4 (registration via `Connection::addInsertHook`), and Phase B (Thallo relies on the extension having registered it). `TenantContextRunner` method names match A1 across A3. `TenantIterationException->tenantUuid` matches the test.
- **Review fixes folded in:** insert-hook registry lives on `Connection` (facade + `clearInsertHooks` lifecycle + documented shared-nothing) not `QueryBuilder`; `applyInsertHooks` validates associative-array returns + `insertBatch` enforces a uniform column set; A3 adds a context-restoration test (success + exception) and preserves the cause's exception code; A4 rejects a supplied wrong-tenant uuid and documents the no-context branch as safe-by-NOT-NULL.
- **Verification flags to resolve in-code (not placeholders — concrete checks):** the ORM row accessor shape (`$tenant->uuid` vs array) and the `orderBy` arity in A3; the request-state setter name in A4's test. Both point at exact existing references to check against.
- **No hard cross-repo dependency inversion:** A3/A4 depend on A1 (contracts) + A2 (framework) — the fixed sequence honors that.

---

## Execution record (2026-07-09, commits HELD)

All four tasks implemented and green:

| Task | Repo | Result |
|---|---|---|
| A1 | contracts | 1 test / 4 assertions; phpcs clean. Files under `src/Tenancy/` + `tests/Unit/Tenancy/`. |
| A2 | framework | 6 tests; full `tests/Unit/Database` suite 256 pass (52 skipped, normal); phpcs clean; phpstan clean on both changed files. |
| A3 | tenancy | 4 tests / 9 assertions; full suite 137 pass; phpcs clean. Test at `tests/Integration/`. |
| A4 | tenancy | 8 tests; full suite 137 pass; phpcs clean. |

**Verification flags resolved in-code:** (1) `Tenant` is an ORM Model → `get()` returns model instances, `$tenant->uuid` works; (2) `orderBy(string, string='asc')` two-arg confirmed on the ORM `Builder`; (3) request-state is set via `TenantContext::setTenant()/setBypass()` (wrapping `ApplicationContext::setRequestState`) — used in the tests instead of a raw setter.

**Two plan code-block corrections made during execution (this doc's snippets predate them):**
1. **A2 test sqlite config** — the real `Connection` test config is `['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:'], 'pooling' => ['enabled' => false]]` (matching `tests/Unit/Database/ConnectionTableHookTest.php`), not `['driver' => 'sqlite', 'database' => ':memory:']`.
2. **A3 ordering test** — `created_at` is DB-populated and two tenants created in the same tick can tie, making the order timing-dependent. The shipped test sets `created_at` explicitly per tenant so the primary-sort assertion is deterministic.

**Cross-repo note:** the tenancy repo's vendored framework (a real copy, not a symlink) predated A2, so `Connection::addInsertHook` was not present there. No tenancy test exercises `TenancyServiceProvider::boot()`, so the suite is green regardless; the boot() registration is correct code whose framework dependency is pinned at release.

**Dev linkage established (2026-07-09):** to validate the cross-repo wiring end-to-end without releasing, `extensions/tenancy/vendor/glueful/framework` is symlinked to the local framework (1.66.3 + A2); the original is backed up at `vendor/glueful/framework.orig-1.65.3`. Revert with `rm framework && mv framework.orig-1.65.3 framework` (it's under gitignored `vendor/`, never committed). Against the real framework the full suite passes **139 tests**, and a new `tests/Integration/TenantInsertStampingE2ETest.php` proves a real `Connection::table('posts')->insert(...)` is stamped with the current tenant (and cross-tenant supplied uuid rejected). That e2e test `markTestSkipped`s when `Connection::addInsertHook` is absent, so pre-A2 CI stays green. **Framework release stays deferred to SP1 completion**; the same linkage pattern will be needed for Thallo's vendor in Phase B (framework + extension-contracts + tenancy).
