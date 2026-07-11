# Thallo Multi-Tenancy SP1 — Phase B2b: System Workers, Lint & Cross-Tenant Sweep

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Finish raw-PDO scoping correctness: make the two genuine cross-tenant background workers safe under the pinned **system-path** model (schedule runner carries tenant per drained row + fails closed; retention pruner is uuid-keyed and system-designated), add a regression lint that catches new/unscoped raw sites (with targeted SQL assertions, not just a smoke check), and prove end-to-end cross-tenant isolation with a capstone sweep across every fixed repo.

**Prerequisite:** Phase **B2a** complete (TenantScope helper, `TenantOracleTestCase` harness, and the per-repo raw fixes are green).

**Architecture:** The schedule claim/reclaim/finalize SQL stays a **named system path** (raw PDO deliberately bypasses the guard to drain all tenants), but each drained row's `tenant_uuid` is carried into the downstream scoped publish; when the tenancy runner is bound, a row missing `tenant_uuid` is a scoping bug and **fails closed**. The retention pruner deletes by global nano-id uuids, so a cross-tenant scan is already correct — it is documentation + a proof, not a predicate.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5, Postgres (`app_test`), `TenantContextRunner`, `TenantOracleTestCase` (from B2a).

**Spec:** [../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md](../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md) §7.2. Worker model pinned by the user: system-path, carry tenant per row.

## Global Constraints

- Work on `dev` directly. No AI/Anthropic attribution. **Hold all commits until explicit go-ahead.**
- `declare(strict_types=1)`, `final class`, constructor DI, `use`-imports (no inline FQCNs).
- `composer phpcs` clean before a task is done (warnings are failures).
- **Single-tenant parity:** tenancy-off paths (runner null) emit today's exact behavior.
- **Worker model (pinned):** claim/scan stay global (system path); carry each drained row's `tenant_uuid` into the downstream scoped write. No per-tenant predicates on claim/scan SQL.
- Oracle tests run under `THALLO_TENANCY_DEV_LINK=1`; they skip cleanly otherwise.

---

### Task B2b.1: Schedule runner — carry tenant per drained row + fail closed

The claim/reclaim/finalize SQL in `ScheduleRepository` stays a named system path. `ScheduleRunner` runs each drained row's publish/unpublish in that row's tenant context. **P1:** when the tenancy runner is bound, a claimed row with an empty `tenant_uuid` must NOT publish unscoped — it fails closed.

**Files:**
- Modify: `app/Content/Scheduling/ScheduleRunner.php`
- Modify: `app/Content/Repositories/ScheduleRepository.php` (doc note + confirm `RETURNING *` yields `tenant_uuid`)
- Test: `tests/Integration/Content/ScheduleRunnerTenantScopeTest.php`
- Test: `tests/Integration/Content/ScheduleRepositoryTenantColumnTest.php`

- [ ] **Step 1: Prove the claim carries tenant_uuid** — failing test first (via `TenantOracleTestCase`):
```php
public function testClaimResultIncludesTenantUuid(): void
{
    $repo = $this->container()->get(\App\Content\Repositories\ScheduleRepository::class);
    $this->runAsTenant('ten000000001', function () use ($repo): void {
        // schedule a due publish for an entry owned by tenant 1 (builder insert → stamped)
        $repo->schedule(/* entry, locale, run_at in the past, action=publish */);
    });
    $rows = $repo->claimDuePending(10, 'lock-token-x'); // system-path drain (no tenant context)
    self::assertNotSame([], $rows);
    foreach ($rows as $row) {
        self::assertArrayHasKey('tenant_uuid', $row, 'claim RETURNING * must yield tenant_uuid');
        self::assertNotSame('', (string) $row['tenant_uuid']);
    }
}
```
`claimDuePending` uses `RETURNING *`, so once the retrofit has added the `tenant_uuid` column (the harness stand-in adds it to `entry_schedules`), the column is returned automatically — no SQL change. If the harness does NOT retrofit `entry_schedules`, add it to the additive set in `TenantOracleTestCase`.

- [ ] **Step 2: Inject a nullable runner + wrap the publish, fail closed**

Add `?TenantContextRunner $tenants = null` to `ScheduleRunner` (import `Glueful\Extensions\Contracts\Tenancy\TenantContextRunner`). Rewrite the loop:
```php
        foreach ($this->schedules->claimDuePending($limit, $lockToken) as $row) {
            $tenantUuid = isset($row['tenant_uuid']) ? (string) $row['tenant_uuid'] : '';
            [$status, $reason] = $this->runRow($row, $tenantUuid);
            $this->schedules->markOutcome((int) $row['id'], $status, $reason, $lockToken);
        }
```
```php
    /**
     * @param array<string,mixed> $row
     * @return array{0: ScheduleStatus, 1: ?string}
     */
    private function runRow(array $row, string $tenantUuid): array
    {
        $action = function () use ($row): array {
            // ... existing per-row publish/unpublish body verbatim, returning [status, reason] ...
        };

        if ($this->tenants !== null) {
            if ($tenantUuid === '') {
                // Tenancy is active but the claimed row carries no tenant — the claim SELECT must
                // return tenant_uuid; a missing one is a scoping bug. FAIL CLOSED rather than run
                // the publish unscoped (which would write into the '' partition).
                return [ScheduleStatus::Failed, 'schedule row missing tenant_uuid under active tenancy'];
            }
            return $this->tenants->runAsTenant($tenantUuid, $action);
        }

        return $action(); // tenancy off — single-tenant, unchanged
    }
```
(`markOutcome` stays OUTSIDE the tenant wrap — it writes `entry_schedules` by global `id` as the system-path worker.)

- [ ] **Step 3: Doc the system-path carve-out** on `ScheduleRepository::claimDuePending/reclaimStale/markOutcome`: they are the named system/maintenance path that intentionally scans/writes `entry_schedules` cross-tenant; tenant correctness is carried per row into the publish.

- [ ] **Step 4: Tests**
  - `ScheduleRunnerTenantScopeTest`: two tenants each schedule a due publish for the same `(entry,locale)`; run the runner once; assert each entry is published in ITS OWN tenant partition (read back via `runAsTenant`).
  - **Fail-closed test:** with the runner bound, feed a claimed row whose `tenant_uuid` is `''` (fake the schedules repo or null the column) and assert the outcome is `Failed` + nothing was published.
  - Existing single-tenant `ScheduleRunnerTest` stays green (runner null path).
- [ ] phpcs. **Commit** (HOLD): `Carry tenant per drained schedule row into the publish + fail closed (system path)`

---

### Task B2b.2: Retention pruner — system-path designation + cross-tenant proof

The pruner deletes by global nano-id uuids (`entry_versions.uuid`, `entry_publications.version_uuid`), so a cross-tenant scan is already correct. Pin the invariant.

**Files:**
- Modify: `app/Content/Retention/VersionPruner.php` (doc note only)
- Test: `tests/Integration/Content/VersionPrunerTenantScopeTest.php`

- [ ] **Step 1: Doc note** on `lineages()`/`computeDeletable()`/`deleteGuarded()`: they run as a system path over globally-unique uuids; if `entry_versions.uuid` ever stops being globally unique, add `tenant_uuid` scoping to all three.
- [ ] **Step 2: Test** — seed two tenants, each with an over-retention version history for the same `entry_uuid`/`locale` shape but distinct uuids; run the pruner; assert each tenant keeps exactly its retained set and no cross-tenant version is deleted or preserved by the other tenant's publication.
- [ ] **Commit** (HOLD): `Pin retention pruner cross-tenant correctness (system path)`

---

### Task B2b.3: Raw-PDO regression lint (with targeted SQL assertions)

Fails when a new `getPDO()` site appears unclassified, when a scoped site loses `tenant_uuid` (smoke), OR when a known-critical scoping construct disappears (targeted). **P2:** the smoke check alone would pass on a stray comment mentioning `tenant_uuid`; the targeted assertions require the actual scoping code.

**Files:**
- Test: `tests/Unit/Tenancy/RawPdoScopingLintTest.php`

**Note on assertion targets:** the upsert SQL is built dynamically (the literal `ON CONFLICT (tenant_uuid, …)` never appears as a source string), so the targeted assertions check the **construction code** that produces the widened target/predicate — the exact fragments introduced in B2a.

- [ ] **Step 1: Write the lint**
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;

/**
 * Guards the raw-PDO surface. Raw SQL bypasses the tenancy guard/hook, so every getPDO() site over
 * an owned table must carry tenant_uuid scoping (or be an explicitly-listed system path). A NEW
 * getPDO() file forces a conscious classification here.
 */
final class RawPdoScopingLintTest extends TestCase
{
    /** Owned-table raw sites that MUST reference tenant_uuid (smoke). */
    private const SCOPED = [
        'packages/thallo-seo/src/Meta/SeoMetaRepository.php',
        'packages/thallo-navigation/src/MenuRepository.php',
        'packages/thallo-analytics/src/Facts/AnalyticsRecorder.php',
        'packages/thallo-analytics/src/Query/AnalyticsQuery.php',
        'packages/thallo-workflow/src/WorkflowStateRepository.php',
        'app/Content/Blocks/Migration/BlockMigrationRepository.php',
        'app/Content/Repositories/MigrationRepository.php',
    ];

    /** Named system paths / non-owned / DDL / advisory-lock — reviewed, no per-row predicate. */
    private const SYSTEM_PATHS = [
        'app/Content/Repositories/ScheduleRepository.php',
        'app/Content/Retention/VersionPruner.php',
        'app/Content/Repositories/VersionRepository.php',
        'app/Content/Indexing/EnsureFilterIndexesJob.php',
        'packages/thallo-render/src/Templates/TemplateRepository.php',
        'packages/thallo-collections/src/Data/RowRepository.php',
    ];

    /**
     * Targeted: file => list of required source fragments proving the ACTUAL scoping construct
     * (not just a comment). Fragments are the exact strings introduced by the B2a fixes.
     * @var array<string, list<string>>
     */
    private const REQUIRED_FRAGMENTS = [
        'packages/thallo-seo/src/Meta/SeoMetaRepository.php' => [
            "array_unshift(\$conflict, 'tenant_uuid')",
            "\$insert['tenant_uuid'] = \$tenant",
        ],
        'packages/thallo-workflow/src/WorkflowStateRepository.php' => [
            "array_unshift(\$conflict, 'tenant_uuid')",
            ' AND tenant_uuid = ?',
        ],
        'packages/thallo-analytics/src/Facts/AnalyticsRecorder.php' => [
            "array_unshift(\$conflict, 'tenant_uuid')",
        ],
        'packages/thallo-analytics/src/Query/AnalyticsQuery.php' => [
            ' AND tenant_uuid = ?',
        ],
        'packages/thallo-navigation/src/MenuRepository.php' => [
            'slug = ? AND tenant_uuid = ?',   // reorderMenus (critical)
            'i.tenant_uuid = m.tenant_uuid',  // listMenus join scoping
        ],
        'app/Content/Blocks/Migration/BlockMigrationRepository.php' => [
            ' AND tenant_uuid = :tenant',
        ],
        'app/Content/Repositories/MigrationRepository.php' => [
            ' AND tenant_uuid = :tenant',
        ],
    ];

    public function testEveryScopedRawSiteReferencesTenantUuid(): void
    {
        foreach (self::SCOPED as $rel) {
            $body = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            self::assertStringContainsString('tenant_uuid', $body, "$rel: no tenant_uuid scoping");
        }
    }

    public function testCriticalScopingConstructsArePresent(): void
    {
        foreach (self::REQUIRED_FRAGMENTS as $rel => $fragments) {
            $body = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            foreach ($fragments as $fragment) {
                self::assertStringContainsString(
                    $fragment,
                    $body,
                    "$rel lost its critical scoping construct: {$fragment}",
                );
            }
        }
    }

    public function testNoUnclassifiedGetPdoSites(): void
    {
        $known = array_merge(self::SCOPED, self::SYSTEM_PATHS);
        $root = dirname(__DIR__, 3);
        $found = [];
        foreach (['app', 'packages'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), 'getPDO()')) {
                    $found[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }
        sort($found);
        self::assertSame(
            [],
            array_values(array_diff($found, $known)),
            'New getPDO() site(s) must be classified in this lint.',
        );
    }
}
```

- [ ] **Step 2: Run → PASS** (all B2a fixes present; allowlist matches the audit). If a fragment string differs from what B2a actually shipped, reconcile the lint to the real source (the fragments are the contract — keep them exact). phpcs.
- [ ] **Commit** (HOLD): `Add raw-PDO tenant-scoping regression lint (smoke + targeted)`

---

### Task B2b.4: Capstone — cross-repo cross-tenant isolation sweep

One integration test that exercises every fixed surface together and asserts the fail-closed and coexistence invariants end-to-end.

**Files:**
- Create: `tests/Integration/Tenancy/CrossTenantIsolationTest.php`

- [ ] **Step 1: Write the sweep** (extends `TenantOracleTestCase`):
  - For a representative BUILDER repo (e.g. entries via the content repository) AND each fixed RAW repo (seo, navigation, analytics, workflow, block/schema migration):
    - write a natural-key-colliding row in tenant A and tenant B;
    - assert reads in A never see B's rows and vice-versa;
    - assert the widened uniques allow the same natural key in both tenants (no unique violation).
  - **Fail-closed:** assert a scoped read/write with NO tenant context (`runAsSystem`) throws `TenantContextRequiredException` for the request-path repos.
  - **Scheduler:** two tenants schedule the same `(entry,locale)`; run the runner; assert per-tenant publication.
- [ ] **Step 2: Full regression BOTH ways**
  - `composer test` (tenancy OFF): everything green; all oracle/`*TenantScope`/sweep tests SKIP.
  - `THALLO_TENANCY_DEV_LINK=1 composer test` (tenancy ON boot available): oracle tests RUN, isolation proven.
  - phpcs clean.
- [ ] **Step 3: Commit** (HOLD): `Add cross-repo two-tenant isolation sweep (capstone)`

---

## Phase B2b self-review checklist

- **Worker model (pinned):** claim/scan stay global; tenant carried per row into the scoped publish; pruner uuid-keyed. ✅
- **Fail-closed (P1):** runner bound + row missing `tenant_uuid` → `Failed`, never an unscoped publish; proven by test + a claim-carries-tenant_uuid test. ✅
- **Lint hardened (P2):** smoke check + targeted required-fragment assertions (upsert conflict construction, critical `reorderMenus`/join scoping) + unclassified-site guard. ✅
- **Determinism:** default `composer test` unchanged; oracle/sweep skip without the env-flag dev-link. ✅
- **Capstone** exercises every fixed surface + fail-closed end-to-end. ✅

## Cross-phase note

B2b consumes the `TenantContextRunner` contract (Phase A) and `TenantOracleTestCase` (B2a.3). The harness's additive `entry_schedules` retrofit (for `RETURNING *` to yield `tenant_uuid`) must be present — if B2a.3 scoped its additive set narrowly, extend it here. Phase C's real retrofit engine supersedes the harness stand-in.
