# Thallo Multi-Tenancy SP1 — Phase B2a: Raw-PDO Scoping Fixes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Make every request-path raw-PDO (`getPDO()`) SQL that reads or writes a tenant-owned table tenant-correct — raw PDO bypasses BOTH the framework insert hook (write-stamping, A2) AND the tenancy read guard. Each fix ships with its **own two-tenant isolation test** made real by an oracle harness that lands early (right after the scope helper), so every task is independently green.

**Architecture:** A neutral, decoupled scoping seam. Content packs never depend on `glueful/tenancy`; they depend only on the contract package `glueful/extension-contracts` and read the current tenant through `CurrentTenantResolver` (bound only when tenancy is active). A single fail-closed helper `TenantScope::current(?CurrentTenantResolver, ApplicationContext): ?string` returns `null` in single-tenant mode (resolver unbound → autowired null), the tenant uuid when scoping is on, and throws when on-but-empty. Each raw query conditionally appends `tenant_uuid` only on the `!== null` branch — so single-tenant behavior is byte-identical to today.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5, Postgres (`app_test`), `glueful/extension-contracts` (`CurrentTenantResolver`, `TenantContextRunner`), Glueful autowiring (nullable-unbound → `null`, verified in `ReflectionResolver`/`ContainerCompiler`).

**Spec:** [../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md](../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md) §7.2, §5.2. Sibling: `PHASE-B1-EXECUTION.md` (dev-link + audit).

## Global Constraints

- Work on `dev` directly. No AI/Anthropic attribution. **Hold all commits until explicit go-ahead.**
- `declare(strict_types=1)`, `final class`, constructor DI, `use`-imports (no inline FQCNs).
- `composer phpcs` clean before a task is done (warnings are failures).
- **Single-tenant parity is sacred:** with tenancy OFF (`TenantScope::current()` → `null`), every query emits exactly today's SQL. Predicates are added ONLY on the `!== null` branch.
- **Builder paths are already covered** by the guard + insert hook — do NOT touch `->table(...)` calls. This phase edits ONLY `getPDO()` SQL.
- **Dev linkage:** the oracle harness needs `glueful/tenancy` autoloadable + enabled for a dedicated boot. Use the explicit env-flag opt-in `THALLO_TENANCY_DEV_LINK=1` (see `PHASE-B1-EXECUTION.md`) — never hardcode a PSR-4 line in `tests/bootstrap.php`. The oracle base class `markTestSkipped`s when the tenancy binding is absent, so the default `composer test` stays deterministic.

---

### Task B2a.1: Registry widened-unique metadata fix

The audit found upsert conflict targets whose backing unique constraints are NOT in `ThalloTenantTables::$widened_uniques`; the Phase C retrofit would not widen them, so adding `tenant_uuid` to the conflict target would reference a non-existent constraint. Fix the single source first.

**Files:**
- Modify: `packages/thallo-tenancy/src/ThalloTenantTables.php`
- Modify: `tests/Unit/Tenancy/ThalloTenantTablesTest.php`

**Verified constraints (from migrations):**
- `workflow_review_states`: `unique(['entry_uuid','locale'], 'uniq_workflow_state_entry_locale')` → `['uniq_workflow_state_entry_locale', ['tenant_uuid','entry_uuid','locale']]`.
- `analytics_daily`: `unique(['day','event','subject'])` (unnamed) → `[null, ['tenant_uuid','day','event','subject']]`.
- `analytics_active_actors`: `unique(['day','metric','actor_type','actor_id_hash'])` (unnamed) → `[null, ['tenant_uuid','day','metric','actor_type','actor_id_hash']]`.

- [ ] **Step 1: Add the failing assertion** to `ThalloTenantTablesTest`:
```php
    public function testUpsertTablesHaveWidenedUniques(): void
    {
        $all = ThalloTenantTables::all();
        self::assertSame(
            [['uniq_workflow_state_entry_locale', ['tenant_uuid', 'entry_uuid', 'locale']]],
            $all['workflow_review_states']['widened_uniques'],
        );
        self::assertSame(
            [[null, ['tenant_uuid', 'day', 'event', 'subject']]],
            $all['analytics_daily']['widened_uniques'],
        );
        self::assertSame(
            [[null, ['tenant_uuid', 'day', 'metric', 'actor_type', 'actor_id_hash']]],
            $all['analytics_active_actors']['widened_uniques'],
        );
    }
```

- [ ] **Step 2: Run → FAIL** (`vendor/bin/phpunit tests/Unit/Tenancy/ThalloTenantTablesTest.php`).

- [ ] **Step 3: Update the registry rows**
```php
            'analytics_facts' => self::row($inst),
            'analytics_daily' => self::row($inst, [[null, ['tenant_uuid', 'day', 'event', 'subject']]]),
            'analytics_active_actors' => self::row(
                $inst,
                [[null, ['tenant_uuid', 'day', 'metric', 'actor_type', 'actor_id_hash']]],
            ),
```
```php
            'workflow_review_states' => self::row(
                $inst,
                [['uniq_workflow_state_entry_locale', ['tenant_uuid', 'entry_uuid', 'locale']]],
            ),
            'workflow_transitions' => self::row($inst),
```

- [ ] **Step 4: Run → PASS.** phpcs the two files.
- [ ] **Step 5: Commit** (HOLD): `Widen upsert-backed uniques in ThalloTenantTables (workflow/analytics)`

---

### Task B2a.2: Tenant-scope seam in `glueful/extension-contracts`

**Files:**
- Create: `extensions/contracts/src/Tenancy/TenantContextRequiredException.php`
- Create: `extensions/contracts/src/Tenancy/TenantScope.php`
- Test: `extensions/contracts/tests/Unit/Tenancy/TenantScopeTest.php`

**Interfaces:**
- Produces: `Glueful\Extensions\Contracts\Tenancy\TenantScope::current(?CurrentTenantResolver $resolver, ApplicationContext $context): ?string` — `null` when `$resolver === null`; the uuid when resolved; throws `TenantContextRequiredException` on `''` (fail-closed).

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tests\Unit\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRequiredException;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use PHPUnit\Framework\TestCase;

final class TenantScopeTest extends TestCase
{
    private function ctx(): ApplicationContext
    {
        return $this->createStub(ApplicationContext::class);
    }

    public function testNullResolverMeansSingleTenant(): void
    {
        self::assertNull(TenantScope::current(null, $this->ctx()));
    }

    public function testResolvedUuidIsReturned(): void
    {
        $resolver = new class implements CurrentTenantResolver {
            public function tenantUuid(ApplicationContext $context): string
            {
                return 'ten000000001';
            }
        };
        self::assertSame('ten000000001', TenantScope::current($resolver, $this->ctx()));
    }

    public function testEmptyTenantFailsClosed(): void
    {
        $resolver = new class implements CurrentTenantResolver {
            public function tenantUuid(ApplicationContext $context): string
            {
                return '';
            }
        };
        $this->expectException(TenantContextRequiredException::class);
        TenantScope::current($resolver, $this->ctx());
    }
}
```

- [ ] **Step 2: Run → FAIL** (classes missing).

- [ ] **Step 3: Create the exception**
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

use RuntimeException;

/**
 * Thrown when a tenant-scoped query runs in tenant mode with no resolved tenant ('' from the
 * resolver). Fail-closed: never scope or stamp the '' partition (CurrentTenantResolver contract).
 */
final class TenantContextRequiredException extends RuntimeException
{
}
```

- [ ] **Step 4: Create the helper**
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Fail-closed tenant-scope resolution for raw-SQL consumers. Builder paths are auto-scoped by the
 * tenancy read guard/insert hook; raw PDO is not, so raw consumers call this to decide whether to
 * append a tenant_uuid predicate.
 *
 * null  → tenancy inactive (resolver unbound → autowired null): emit pre-tenancy SQL unchanged.
 * uuid  → scoping on.
 * throw → on-but-empty (fail-closed).
 */
final class TenantScope
{
    public static function current(?CurrentTenantResolver $resolver, ApplicationContext $context): ?string
    {
        if ($resolver === null) {
            return null;
        }
        $uuid = $resolver->tenantUuid($context);
        if ($uuid === '') {
            throw new TenantContextRequiredException(
                'A tenant-scoped raw query ran with no resolved tenant (fail-closed).',
            );
        }
        return $uuid;
    }
}
```

- [ ] **Step 5: Run → PASS. phpcs.** Note in the contracts CHANGELOG that `TenantScope` pins into the release alongside `TenantContextRunner`.
- [ ] **Step 6: Commit** (HOLD, contracts repo): `Add TenantScope fail-closed raw-SQL scoping helper`

---

### Task B2a.3: `TenantOracleTestCase` harness (lands early)

The two-tenant harness that turns every subsequent repo-fix test real. It must exist BEFORE the repo fixes so each fix ships independently green (P1).

**Ordering reality + stand-in pins (user-pinned):** owned tables gain their `tenant_uuid` column from the Phase C retrofit, which comes AFTER B2. So this harness applies a **minimal test-only additive column add** as an explicit Phase C stand-in. Hard rules:
- The stand-in is confined to `TenantOracleTestCase` and named `applyMinimalTenantColumnsForOracle()` — never mistaken for production retrofit.
- It covers ONLY the additive owned tables that B2 tests actually exercise (an explicit allowlist), NOT the whole owned set: `seo_meta`, `navigation_menus`, `navigation_items`, `analytics_daily`, `analytics_active_actors`, `workflow_review_states`, `workflow_transitions`, `block_type_migrations`, `entry_schema_migrations`, `entry_schedules`, `entry_versions`, `entry_publications`, `entries`.
- It does NOT fake `rebuild` behavior for `regions`/`settings`/`entry_redirects` — those are excluded entirely.
- B2 tests assert scoping logic ONLY — never Phase C rebuild/idempotency/backfill semantics.
- Phase C still owns the real retrofit and must NOT depend on this harness.

**Files:**
- Create: `tests/Support/TenantOracleTestCase.php`
- Test (smoke): `tests/Integration/Tenancy/OracleHarnessSmokeTest.php`

**Interfaces:**
- Produces: `App\Tests\Support\TenantOracleTestCase` (extends the app's second-boot base) exposing:
  - `runAsTenant(string $uuid, callable $fn): mixed` — via `TenantContextRunner::runAsTenant`.
  - `runAsSystem(callable $fn): mixed` — via `TenantContextRunner::runAsSystem`.
  - `container(): ContainerInterface` — bound to the tenancy-ENABLED boot.
  - class-level `markTestSkipped` when `!interface_exists(CurrentTenantResolver::class)` or the binding is unresolved (default suite stays green).
  - seeds two `tenants` rows: `ten000000001`, `ten000000002`.

- [ ] **Step 1: Write the smoke test**
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\TenantOracleTestCase;

final class OracleHarnessSmokeTest extends TenantOracleTestCase
{
    public function testRunAsTenantEstablishesScope(): void
    {
        $a = $this->runAsTenant('ten000000001', fn (): string => $this->currentTenantUuid());
        $b = $this->runAsTenant('ten000000002', fn (): string => $this->currentTenantUuid());
        self::assertSame('ten000000001', $a);
        self::assertSame('ten000000002', $b);
    }

    public function testOwnedTableHasTenantColumnAfterHarnessRetrofit(): void
    {
        self::assertTrue(
            $this->connection()->getSchemaBuilder()->hasColumn('seo_meta', 'tenant_uuid'),
            'harness additive retrofit must add tenant_uuid to exercised owned tables',
        );
    }
}
```
(`currentTenantUuid()` = a protected helper resolving `CurrentTenantResolver::tenantUuid()`; add it to the base class.)

- [ ] **Step 2: Build `TenantOracleTestCase`**

Responsibilities (verify each API against `AppTestCase::bootWithOverride` + `TenancyTestCase` in the tenancy extension):
1. `setUpBeforeClass`: skip the whole class unless the tenancy binding is available; otherwise boot a SECOND app with `glueful/tenancy` appended to `enabled[]` and `SystemFlags` `tenancy.enabled=1` (reuse the `config/testing/{file}.php` override mechanism `AppTestCase` already uses).
2. `applyMinimalTenantColumnsForOracle()` (idempotent, per-class): iterate the **explicit B2 allowlist** above (NOT the whole owned set, NOT `regions`/`settings`/`entry_redirects`). For each table, if `!hasColumn(table, 'tenant_uuid')`: `ALTER TABLE <t> ADD COLUMN tenant_uuid VARCHAR(191)` (nullable — no backfill). Then for each `widened_uniques` entry in `ThalloTenantTables::all()[<t>]`, **additively** `CREATE UNIQUE INDEX IF NOT EXISTS <name|derived> ON <t> (<cols…>)` — needed so the scoped `ON CONFLICT (tenant_uuid, …)` targets resolve. **Do NOT drop the pre-existing narrow unique, and do NOT reverse anything.** Rationale: `app_test` is process-shared; dropping a narrow unique would break non-tenant upserts (their `ON CONFLICT (entry_uuid, locale)`) in later test classes. Keeping both is safe — the widened index is permissive (NULL-tenant rows stay distinct in Postgres), so non-tenant behavior is unchanged. Raw `ALTER`/`CREATE INDEX` via `getPDO()` is fine (test infra). This is a column+index-add stand-in ONLY — it does NOT reproduce Phase C's backfill/rebuild/idempotency/narrow-drop semantics, and B2 tests must not assert them.

   **Test-writing consequence (pin):** because the narrow unique stays, B2 scope tests prove **isolation with DISTINCT per-tenant natural keys** (tenant A writes keyA, tenant B writes keyB; A cannot see B's rows and vice-versa). They do NOT assert same-key coexistence across tenants — that is a widened-unique/retrofit property owned by Phase C.
3. `setUp`: truncate the exercised owned tables + `thallo_system_flags` (extend `AppTestCase`'s cleanup), re-seed the two tenants, arm the tenancy guard in **throw** mode.
4. `runAsTenant`/`runAsSystem`/`currentTenantUuid`/`container` accessors.

- [ ] **Step 3: Wire the oracle DB migrations** — in `scripts/run-test-migrations.php` (or a dedicated oracle bootstrap) ensure the tenancy extension's own migrations (the `tenants` table etc.) are applied for the oracle boot. LOCAL/CI harness only.

- [ ] **Step 4: Run** `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit tests/Integration/Tenancy/OracleHarnessSmokeTest.php` → PASS; without the flag → whole class SKIPS. phpcs.
- [ ] **Step 5: Commit** (HOLD): `Add early two-tenant oracle harness (TenantOracleTestCase)`

---

### Task B2a.4: `seo_meta` upsert (`SeoMetaRepository`)

**Files:**
- Modify: `packages/thallo-seo/composer.json` (add `glueful/extension-contracts` require)
- Modify: `packages/thallo-seo/src/Meta/SeoMetaRepository.php`
- Test: `tests/Integration/Seo/SeoMetaTenantScopeTest.php`

**Injection pattern (verbatim across B2a.4–B2a.8):** add `ApplicationContext $context` + `?CurrentTenantResolver $tenants = null` to the constructor; `$tenant = TenantScope::current($this->tenants, $this->context);` at the top of each raw method; branch on `$tenant !== null`. Imports:
```php
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
```

- [ ] **Step 1: Write the failing test** (real now — harness exists)
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Support\TenantOracleTestCase;
use Thallo\Seo\Meta\SeoMetaRepository;

final class SeoMetaTenantScopeTest extends TenantOracleTestCase
{
    public function testUpsertIsIsolatedPerTenant(): void
    {
        $repo = $this->container()->get(SeoMetaRepository::class);
        // DISTINCT keys per tenant (isolation proof, not same-key coexistence — see harness pin).
        $this->runAsTenant(self::$tenantAUuid, fn () => $repo->upsert('entry-a-1', 'en', ['title' => 'A title']));
        $this->runAsTenant(self::$tenantBUuid, fn () => $repo->upsert('entry-b-1', 'en', ['title' => 'B title']));

        // Each tenant sees only its own row; the other tenant's entry is invisible.
        self::assertSame('A title', $this->runAsTenant(self::$tenantAUuid, fn () => $repo->find('entry-a-1', 'en')['title']));
        self::assertNull($this->runAsTenant(self::$tenantAUuid, fn () => $repo->find('entry-b-1', 'en')));
        self::assertSame('B title', $this->runAsTenant(self::$tenantBUuid, fn () => $repo->find('entry-b-1', 'en')['title']));
        self::assertNull($this->runAsTenant(self::$tenantBUuid, fn () => $repo->find('entry-a-1', 'en')));
    }
}
```
(Tenant uuids come from `self::$tenantAUuid`/`self::$tenantBUuid` seeded by the harness — nano-ids, not literals.)

- [ ] **Step 2: Run → FAIL** (same `(entry,locale)` in a second tenant hits the un-widened unique / cross-tenant read).
- [ ] **Step 3: Add the contracts require** to `packages/thallo-seo/composer.json`.
- [ ] **Step 4: Modify `SeoMetaRepository`** — ctor gains context + resolver; `find()` (builder) unchanged. In `upsert()`, before `$cols`:
```php
        $conflict = ['entry_uuid', 'locale'];
        $tenant = TenantScope::current($this->tenants, $this->context);
        if ($tenant !== null) {
            $insert['tenant_uuid'] = $tenant;       // added to cols + values automatically
            array_unshift($conflict, 'tenant_uuid'); // widened unique target
        }

        $cols = array_keys($insert);
        $sql = 'INSERT INTO seo_meta (' . implode(', ', $cols) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            . ' ON CONFLICT (' . implode(', ', $conflict) . ') DO UPDATE SET ' . implode(', ', $sets);
        $this->db->getPDO()->prepare($sql)->execute(array_values($insert));
```
(`tenant_uuid` is not in `$payload`, so it never enters `DO UPDATE SET` — the row's tenant is immutable.)

- [ ] **Step 5: Run → PASS** (`THALLO_TENANCY_DEV_LINK=1 …`). phpcs.
- [ ] **Step 6: Commit** (HOLD): `Scope seo_meta upsert to tenant_uuid (raw PDO)`

---

### Task B2a.5: Navigation raw sites (`MenuRepository`)

**Files:**
- Modify: `packages/thallo-navigation/composer.json` (add `glueful/extension-contracts`)
- Modify: `packages/thallo-navigation/src/MenuRepository.php`
- Test: `tests/Integration/Navigation/MenuTenantScopeTest.php`

Ctor gains `ApplicationContext $context` + `?CurrentTenantResolver $tenants = null`. Per-method changes (only the `$tenant !== null` branch adds SQL):

- [ ] **createMenu** — scope MAX(position) so position is dense per tenant:
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $sql = 'SELECT COALESCE(MAX(position), -1) AS m FROM navigation_menus';
        $params = [];
        if ($tenant !== null) {
            $sql .= ' WHERE tenant_uuid = ?';
            $params[] = $tenant;
        }
        $stmt = $this->db->getPDO()->prepare($sql);
        $stmt->execute($params);
        $max = $stmt->fetch(\PDO::FETCH_ASSOC);
```
(The following `insert($row)` is a builder call → stamped; do not add `tenant_uuid` to `$row`.)

- [ ] **listMenus** — scope BOTH the menu filter AND the joined items (P2: prevents cross-tenant item-count drift):
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $join = $tenant === null
            ? ' LEFT JOIN navigation_items i ON i.menu_uuid = m.uuid'
            : ' LEFT JOIN navigation_items i ON i.menu_uuid = m.uuid AND i.tenant_uuid = m.tenant_uuid';
        $where = $tenant === null ? '' : ' WHERE m.tenant_uuid = ?';
        $sql = 'SELECT m.slug, m.name, m.lock_version, COUNT(i.id) AS item_count'
            . ' FROM navigation_menus m' . $join . $where
            . ' GROUP BY m.id, m.slug, m.name, m.lock_version, m.position'
            . ' ORDER BY m.position ASC, m.slug ASC';
        $stmt = $this->db->getPDO()->prepare($sql);
        $stmt->execute($tenant === null ? [] : [$tenant]);
```
(Change `->query(...)` to `prepare(...)->execute(...)` so the predicate binds.)

- [ ] **reorderMenus (CRITICAL)** — `slug` is only unique per tenant once widened:
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $where = $tenant === null ? 'WHERE slug = ?' : 'WHERE slug = ? AND tenant_uuid = ?';
        $stmt = $pdo->prepare("UPDATE navigation_menus SET position = ?, updated_at = ? {$where}");
        $now = gmdate('Y-m-d H:i:s');
        foreach (array_values($slugs) as $i => $slug) {
            $stmt->execute($tenant === null ? [$i, $now, $slug] : [$i, $now, $slug, $tenant]);
        }
```

- [ ] **itemsOf** — `AND tenant_uuid = ?`:
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $extra = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $stmt = $this->db->getPDO()->prepare(
            'SELECT uuid, parent_uuid, position, kind, entry_uuid, url, icon, labels, descriptions'
            . ' FROM navigation_items WHERE menu_uuid = ?' . $extra . ' ORDER BY position ASC, id ASC'
        );
        $stmt->execute($tenant === null ? [$menuUuid] : [$menuUuid, $tenant]);
```

- [ ] **deleteMenu** — both DELETEs `AND tenant_uuid = ?`:
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $extra = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $uuid = (string) $menu['uuid'];
        $pdo->prepare('DELETE FROM navigation_items WHERE menu_uuid = ?' . $extra)
            ->execute($tenant === null ? [$uuid] : [$uuid, $tenant]);
        $pdo->prepare('DELETE FROM navigation_menus WHERE uuid = ?' . $extra)
            ->execute($tenant === null ? [$uuid] : [$uuid, $tenant]);
```

- [ ] **replaceTree** — guard UPDATE + item DELETE `AND tenant_uuid = ?`:
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $extra = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $guard = $pdo->prepare(
            'UPDATE navigation_menus SET lock_version = lock_version + 1, updated_at = ?'
            . ' WHERE uuid = ? AND lock_version = ?' . $extra
        );
        $guardParams = [gmdate('Y-m-d H:i:s'), $menuUuid, $lockVersion];
        if ($tenant !== null) {
            $guardParams[] = $tenant;
        }
        $guard->execute($guardParams);
        // ... rowCount()===0 check unchanged ...
        $pdo->prepare('DELETE FROM navigation_items WHERE menu_uuid = ?' . $extra)
            ->execute($tenant === null ? [$menuUuid] : [$menuUuid, $tenant]);
```
(`findMenu`/`renameMenu` are builder → unchanged; the `insert($row + [...])` loop is a builder call → stamped.)

- [ ] **Test** `MenuTenantScopeTest` (via harness): both tenants create menu slug `main`; assert `listMenus` isolates per tenant with correct per-tenant item counts, `reorderMenus(['main'])` in A doesn't move B's `main`, `findMenu('main')` resolves per tenant. Run PASS. phpcs.
- [ ] **Commit** (HOLD): `Scope navigation raw-PDO reads/writes to tenant_uuid`

---

### Task B2a.6: Analytics upserts + reads (`AnalyticsRecorder`, `AnalyticsQuery`)

**Files:**
- Modify: `packages/thallo-analytics/composer.json` (add `glueful/extension-contracts`)
- Modify: `packages/thallo-analytics/src/Facts/AnalyticsRecorder.php`
- Modify: `packages/thallo-analytics/src/Query/AnalyticsQuery.php`
- Test: `tests/Integration/Analytics/AnalyticsTenantScopeTest.php`

`AnalyticsRecorder` ctor gains `ApplicationContext $context` + `?CurrentTenantResolver $tenants = null` (keeps `Connection`, `ActorHasher`, `LoggerInterface`). It is best-effort — `TenantScope::current()` throwing on empty-when-on is caught by the existing `catch (Throwable)`; an analytics write with no tenant context is dropped + logged, not fatal (see the explicit test below). `record()`'s `analytics_facts` insert is a builder call → auto-stamped, unchanged.

- [ ] **bumpDaily**:
```php
    private function bumpDaily(string $day, string $event, string $subject): void
    {
        $tenant = TenantScope::current($this->tenants, $this->context);
        $cols = ['day', 'event', 'subject', 'count'];
        $vals = [$day, $event, $subject, 1];
        $conflict = ['day', 'event', 'subject'];
        if ($tenant !== null) {
            array_unshift($cols, 'tenant_uuid');
            array_unshift($vals, $tenant);
            array_unshift($conflict, 'tenant_uuid');
        }
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO analytics_daily (' . implode(', ', $cols) . ") VALUES ({$ph})"
            . ' ON CONFLICT (' . implode(', ', $conflict) . ')'
            . ' DO UPDATE SET count = analytics_daily.count + 1';
        $this->connection->getPDO()->prepare($sql)->execute($vals);
    }
```

- [ ] **touchActiveUser** (`metric`/`actor_type` stay SQL literals):
```php
    private function touchActiveUser(string $day, string $hash): void
    {
        $tenant = TenantScope::current($this->tenants, $this->context);
        $cols = ['day', 'metric', 'actor_type', 'actor_id_hash'];
        $vals = [$day, 'active_users', 'user', $hash];
        $conflict = ['day', 'metric', 'actor_type', 'actor_id_hash'];
        if ($tenant !== null) {
            array_unshift($cols, 'tenant_uuid');
            array_unshift($vals, $tenant);
            array_unshift($conflict, 'tenant_uuid');
        }
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO analytics_active_actors (' . implode(', ', $cols) . ") VALUES ({$ph})"
            . ' ON CONFLICT (' . implode(', ', $conflict) . ') DO NOTHING';
        $this->connection->getPDO()->prepare($sql)->execute($vals);
    }
```

- [ ] **AnalyticsQuery** — ctor gains context + resolver; each raw SELECT (`activeUsersByDay`, `breakdown`, `summary` active-users) appends `AND tenant_uuid = ?` when scoped. Example (`breakdown`):
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $sql = 'SELECT subject, SUM(count) AS total FROM analytics_daily'
            . " WHERE event = ? AND subject <> '__total__' AND day >= ? AND day <= ?" . $scope
            . ' GROUP BY subject ORDER BY total DESC, subject ASC LIMIT ' . $limit;
        $params = [$event, $from, $to];
        if ($tenant !== null) {
            $params[] = $tenant;
        }
```
Apply identically to `activeUsersByDay` and the `summary` active-users COUNT. Builder-based `countsByDay`/`summary` totals are auto-guarded — unchanged.

- [ ] **Test 1** `AnalyticsTenantScopeTest::testCountsIsolatedPerTenant`: record the same event under two tenants; assert `breakdown`/`summary` per tenant see only their own counts, and `analytics_daily` holds one row per tenant for the same `(day,event,subject)`.
- [ ] **Test 2 (P2)** `testNoTenantContextDropsAndWarns`: tenancy ON but NO tenant context (`runAsSystem`); call `record(...)`; assert (a) no new `analytics_daily`/`analytics_active_actors` rows, and (b) a warning was logged (inject a capturing logger via the harness). Proves fail-closed doesn't silently look like "analytics just missing."
- [ ] phpcs. **Commit** (HOLD): `Scope analytics raw upserts + queries to tenant_uuid`

---

### Task B2a.7: Workflow upsert + reads (`WorkflowStateRepository`)

**Files:**
- Modify: `packages/thallo-workflow/composer.json` (add `glueful/extension-contracts`)
- Modify: `packages/thallo-workflow/src/WorkflowStateRepository.php`
- Test: `tests/Integration/Workflow/WorkflowStateTenantScopeTest.php`

Ctor gains context + resolver. `find`/`stateOf`/`record` are builder → unchanged.

- [ ] **setState**:
```php
        $conflict = ['entry_uuid', 'locale'];
        $tenant = TenantScope::current($this->tenants, $this->context);
        if ($tenant !== null) {
            $insert['tenant_uuid'] = $tenant;
            array_unshift($conflict, 'tenant_uuid');
        }
        $cols = array_keys($insert);
        $sql = 'INSERT INTO workflow_review_states (' . implode(', ', $cols) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            . ' ON CONFLICT (' . implode(', ', $conflict) . ') DO UPDATE SET ' . implode(', ', $sets);
        $this->db->getPDO()->prepare($sql)->execute(array_values($insert));
```

- [ ] **queuePage** (both COUNT + page SELECT; `NULLS LAST` stays):
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = ?';

        $count = $pdo->prepare('SELECT COUNT(*) FROM workflow_review_states WHERE state = ?' . $scope);
        $count->execute($tenant === null ? [$state] : [$state, $tenant]);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT entry_uuid, locale, state, submitted_by, submitted_at FROM workflow_review_states'
            . ' WHERE state = ?' . $scope . ' ORDER BY submitted_at ASC NULLS LAST, id ASC LIMIT ? OFFSET ?'
        );
        $stmt->execute(
            $tenant === null
                ? [$state, $perPage, ($page - 1) * $perPage]
                : [$state, $tenant, $perPage, ($page - 1) * $perPage],
        );
```

- [ ] **history**:
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $stmt = $this->db->getPDO()->prepare(
            'SELECT from_state, to_state, action, actor_uuid, note, created_at'
            . ' FROM workflow_transitions WHERE entry_uuid = ? AND locale = ?' . $scope
            . ' ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute($tenant === null ? [$entryUuid, $locale] : [$entryUuid, $locale, $tenant]);
```

- [ ] **Test** `WorkflowStateTenantScopeTest` (via harness): two tenants `setState` the same `(entry,locale)` to different states; assert `stateOf`/`queuePage` isolate per tenant and both rows coexist under the widened unique. PASS. phpcs.
- [ ] **Commit** (HOLD): `Scope workflow raw upsert + queue/history reads to tenant_uuid`

---

### Task B2a.8: App-content defense UPDATEs (`BlockMigrationRepository`, `MigrationRepository`)

Both `incrementDone()` update by global `uuid`; add `AND tenant_uuid = :tenant` for parity + future-proofing. (`app/` may reference `Glueful\Extensions\Contracts\Tenancy\*` directly.)

**Files:**
- Modify: `app/Content/Blocks/Migration/BlockMigrationRepository.php`
- Modify: `app/Content/Repositories/MigrationRepository.php`
- Test: `tests/Integration/Content/MigrationTenantScopeTest.php` (two-tenant `incrementDone` isolation)

- [ ] **BlockMigrationRepository::incrementDone** (ctor gains context + resolver; keeps `Connection` + `BlockTypeRepository`):
```php
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = :tenant';
        $stmt = $this->db->getPDO()->prepare(
            'UPDATE block_type_migrations SET work_items_done = work_items_done + 1'
            . ' WHERE uuid = :uuid' . $scope
        );
        $params = [':uuid' => $uuid];
        if ($tenant !== null) {
            $params[':tenant'] = $tenant;
        }
        $stmt->execute($params);
```
- [ ] **MigrationRepository::incrementDone** — identical shape against `entry_schema_migrations`.
- [ ] Test + phpcs. **Commit** (HOLD): `Add tenant_uuid guard to raw incrementDone UPDATEs`

---

## Phase B2a self-review checklist

- **Harness lands early (B2a.3)** so every repo fix (B2a.4–B2a.8) ships its real two-tenant test and is independently green. ✅
- **Single-tenant parity:** all fixes branch on `$tenant !== null`. ✅
- **Decoupling:** content packs gain only `glueful/extension-contracts`. ✅
- **Metadata coherence:** B2a.1 widens the three upsert-backed uniques before the upsert fixes use them. ✅
- **P2 join scoping** (`i.tenant_uuid = m.tenant_uuid`) in `listMenus`. ✅
- **P2 analytics no-tenant** behavior explicitly tested (warn + no rows). ✅
- **Retrofit ordering** made explicit: the harness applies a minimal test-only additive retrofit as a Phase C stand-in over additive owned tables. ✅

**Deferred to B2b:** system workers (schedule runner carry-tenant + fail-closed; retention pruner system-path), the raw-PDO regression lint (with targeted SQL assertions), and the final cross-repo oracle sweep.
