# Workspace Deletion & Host-Retention Implementation Plan

**Revision:** 2 — contract-audited, purge concurrency/audit/HTTP corrections integrated.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a reversible two-phase workspace deletion (trash → purge) plus a per-host cooldown ledger that closes the domain-reassignment/squatting gap for every host-release path.

**Architecture:** The engine (`glueful/extension-contracts` + `glueful/tenancy`) owns the authoritative identity/domain lifecycle: new `TenantAdministration` methods (`deleteTenant`/`restoreTenant`/`beginPurge`/`purgeTenantRecord`), a cooldown-aware `TenantDomainAdministration::releaseDomain()` that `removeDomain()` delegates to, claim-time cooldown enforcement, a `released_hosts` ledger with per-host advisory locking, and three framework `BaseEvent`s dispatched after commit. Thallo (the `thallo-tenancy` pack) owns product-data destruction: a `PurgeResourceRegistry` of `prepare→purge→verify` handlers, a durable system-global purge-run ledger, a checkpointed `PurgeJob`, and the admin surface (delete/restore/purge with typed-confirmation + selected-workspace guards). The seam: Thallo's job purges every registered resource and only calls the engine's `purgeTenantRecord()` when all handlers verify green.

**Tech Stack:** PHP 8.3+, PostgreSQL (JSONB, advisory locks), Glueful framework (Connection/afterCommit, QueueManager/Job, EventService, StorageManager), Aegis RBAC, Vue 3 + Pinia setup-stores + @pinia/colada (admin SPA), vitest/jsdom, PHPUnit integration tests against real PostgreSQL.

## Global Constraints

- **Release chain:** `glueful/extension-contracts` → `glueful/tenancy` → Thallo. Vendor-first dev: edit `vendor/glueful/extension-contracts` and `vendor/glueful/tenancy` in place, test live in Thallo, then port to source repos and release in order. Pin engine versions in Thallo **only after** they are published. No framework release is needed (storage deletion already exposed).
- **PostgreSQL-only.** JSONB, `pg_advisory_xact_lock(hashtextextended(?, 0))`, `SELECT … FOR UPDATE`, `GREATEST()` are all permitted and used.
- **HOLD ALL COMMITS.** Do not run any `git commit`/`git add` until the user gives an explicit go-ahead. The commit steps below stage work but MUST NOT execute until told. Work on `dev` directly.
- **No AI/Anthropic attribution** anywhere (commit messages, PR bodies, comments). No `Co-Authored-By`, no "Generated with Claude Code".
- **Never stage/commit `CLAUDE.md`.** Use explicit `git add <paths>`.
- **No git tags** (the user creates them). No Packagist publishing (the user does it).
- **PHP style:** `declare(strict_types=1)`, `final` classes, constructor DI, `use`-imports (no inline FQCNs in method bodies), `composer phpcs` clean (120-char lines; warnings fail).
- **Config single source:** cooldown/retention/auto-purge live under the engine's `tenancy.*`. Thallo never duplicates host lists.
- **Pre-launch migration folding:** new `tenants` columns go into the engine's existing `001_CreateTenantsTable`, not an ALTER migration. New tables (`released_hosts`, purge-run ledger) get their own migrations.
- **Include-deleted access is mandatory** for restore/beginPurge/purge-status/final-purge: use raw
  PDO for reads of trashed rows, never `Tenant::query()` or builder `get()/first()` (builder reads
  auto-apply `deleted_at IS NULL`). Guarded builder updates may follow a raw locked read; final
  removal uses `forceDelete()` or raw PDO `DELETE`.
- **Hard purge means `forceDelete()`/raw `DELETE`.** Glueful's builder `delete()` soft-deletes any
  table carrying `deleted_at`; it is forbidden in destructive purge handlers and for the final
  tenant row. Purging tenants are inactive, and the verified `runAsTenant()` contract rejects them,
  so the job is a named `runAsSystem()` maintenance path. Every destructive/read query in a handler
  must carry an explicit `tenant_uuid` (or captured globally-unique blob UUID) predicate; the
  two-tenant acceptance test proves the non-target survives.
- **Queue failures must remain failures.** A purge job records its durable failure checkpoint and
  rethrows; it never catches-and-returns, which would make `Job::fire()` delete the queue job as
  successful.
- **SPA:** setup-store Pinia only, `@pinia/colada`, `authFetch`/`client` are the only header-injection points, `data-testid` hooks, no `UAuthForm`, vitest jsdom.

---

## File Structure

**Engine — contracts (`vendor/glueful/extension-contracts/src/Tenancy/`):**
- `TenantAdministration.php` — MODIFY: add 4 lifecycle mutations plus an include-deleted lifecycle read.
- `TenantDomainAdministration.php` — MODIFY: add `releaseDomain()` + atomic
  `overrideCooldownAndClaim()` signatures.
- `HostCooldownException.php` — CREATE: neutral structured conflict carrying `availableAfter()`.

**Engine — implementation (`vendor/glueful/tenancy/src/`):**
- `Bridge/ContractTenantAdministration.php` — MODIFY: implement lifecycle methods + guards.
- `Bridge/ContractTenantDomainAdministration.php` — MODIFY: `releaseDomain()`, `removeDomain()` delegation, claim-time cooldown, override.
- `Cooldown/ReleasedHostRepository.php` — CREATE: ledger upsert/lookup/consume + per-host lock helper.
- `Events/TenantDeleted.php`, `Events/TenantRestored.php`, `Events/HostReleased.php` — CREATE: framework `BaseEvent`s.
- `Exceptions/FinalWorkspaceException.php`, `Exceptions/RequiredHostOwnedException.php`, `Exceptions/TenantLifecycleException.php` — CREATE.
- `migrations/001_CreateTenantsTable.php` — MODIFY: fold `deleted_from_status` + `purge_after`.
- `migrations/004_CreateReleasedHostsTable.php` — CREATE.
- `config/tenancy.php` — MODIFY: add cooldown/retention/auto-purge keys.

**Thallo — pack (`packages/thallo-tenancy/src/`):**
- `Purge/PurgeHandler.php` — CREATE: handler interface (id/dependencies/prepare/purge/verify).
- `Purge/PurgeResourceRegistry.php` — CREATE: registry + topological ordering.
- `Purge/Handlers/{TablesPurgeHandler,MediaPurgeHandler,CachePurgeHandler,CollectionsPurgeHandler}.php` — CREATE.
- `Purge/PurgeRunRepository.php` — CREATE: durable system-global run ledger.
- `Purge/PurgeJob.php` — CREATE: checkpointed background job.
- `Purge/PurgeCoordinator.php` — CREATE: request→run+beginPurge atomic + afterCommit dispatch + recovery.
- `Purge/CooldownSweepJob.php` — CREATE: scheduled tombstone pruner.
- `packages/thallo-contracts/src/Tenancy/TenancyLifecycleAudit.php` — CREATE: neutral best-effort
  audit seam consumed by the pack.
- `app/Support/TenancyLifecycleAudit.php` — CREATE: app implementation over the optional audit
  recorder.
- `app/Http/Controllers/TenantHostCooldownController.php` — CREATE: canonical-superuser-only
  override surface.
- `Http/Controllers/TenantManagementController.php` — MODIFY: `destroy`/`restore`/`purge` + guards.
- `migrations/002_CreateTenantPurgeRunsTable.php` — CREATE.
- `config/tenancy.php` (Thallo overlay) — unchanged (config lives in engine).
- `ThalloTenancyServiceProvider` (the pack provider) — MODIFY: register purge services, job, sweep schedule.
- `routes/enablement.php` — MODIFY: add delete/restore/purge routes.

**Thallo — admin SPA (`admin/src/`):**
- `queries/tenants.ts` / existing tenancy queries — MODIFY: delete/restore/purge calls and cooldown
  conflict mapping via `authFetch`.
- `pages/workspaces/index.vue`, `pages/workspaces/[uuid]/domains.vue`, and
  `components/tenancy/TenantPurgeModal.vue` — MODIFY/CREATE.

**Operations:**
- `docs/operations/tenancy.md` — CREATE: daily cooldown-sweep cron and purge recovery commands.

**Tests (Thallo, live against vendored engine):**
- `tests/Integration/Tenancy/TenantLifecycleTest.php`, `HostCooldownTest.php`, `PurgeTenantRecordTest.php`, `PurgePipelineTest.php`, `TenantLifecycleGatesTest.php` — CREATE.
- `tests/Integration/Tenancy/TenantDeletionHostRetentionAcceptanceTest.php` — CREATE: mandatory
  two-workspace lifecycle/isolation journey.
- `admin/src/__tests__/` — SPA vitest specs.

---

### Task 1: Fold lifecycle columns into the tenants migration + add engine config keys

**Files:**
- Modify: `vendor/glueful/tenancy/migrations/001_CreateTenantsTable.php`
- Modify: `vendor/glueful/tenancy/config/tenancy.php`
- Test: `tests/Integration/Tenancy/TenantLifecycleTest.php` (schema probe)

**Interfaces:**
- Produces: `tenants.deleted_from_status` (`string(32)` nullable), `tenants.purge_after` (`timestamp` nullable); config keys `tenancy.domains.release_cooldown_days` (int, default 30), `tenancy.tenants.trash_retention_days` (int, default 30), `tenancy.tenants.auto_purge_enabled` (bool, default false).

- [ ] **Step 1: Write the failing schema test**

Create `tests/Integration/Tenancy/TenantLifecycleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;

final class TenantLifecycleTest extends AppTestCase
{
    public function testTenantsTableHasLifecycleColumns(): void
    {
        $columns = $this->connection()->getPDO()
            ->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'tenants'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('deleted_from_status', $columns);
        self::assertContains('purge_after', $columns);
    }

    public function testCooldownAndRetentionConfigDefaults(): void
    {
        $c = $this->appContext();
        self::assertSame(30, (int) config($c, 'tenancy.domains.release_cooldown_days'));
        self::assertSame(30, (int) config($c, 'tenancy.tenants.trash_retention_days'));
        self::assertFalse((bool) config($c, 'tenancy.tenants.auto_purge_enabled'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=TenantLifecycleTest tests/Integration/Tenancy/TenantLifecycleTest.php`
Expected: FAIL — columns absent / config keys null. (Run `composer test:migrate` first if the schema isn't built.)

- [ ] **Step 3: Fold the columns into the create-table migration**

In `vendor/glueful/tenancy/migrations/001_CreateTenantsTable.php`, inside the `createTable('tenants', …)` closure, add the two columns after `deleted_at`:

```php
            $table->timestamp('deleted_at')->nullable();
            // Two-phase deletion (trash → purge): the status the tenant held before soft-delete
            // (active|suspended), so restore recovers the exact prior state; and the restore
            // deadline (deleted_at + trash_retention_days). Folded pre-launch, not an ALTER.
            $table->string('deleted_from_status', 32)->nullable();
            $table->timestamp('purge_after')->nullable();
```

- [ ] **Step 4: Add the engine config keys**

In `vendor/glueful/tenancy/config/tenancy.php`, add to the returned array (before the closing `];`):

```php
    'domains' => [
        // Cooldown a released custom host stays reserved before another tenant may claim it.
        'release_cooldown_days' => (int) env('TENANCY_HOST_COOLDOWN_DAYS', 30),
    ],
    'tenants' => [
        // Restore window: purge_after = deleted_at + this. After it, restore is refused.
        'trash_retention_days' => (int) env('TENANCY_TRASH_RETENTION_DAYS', 30),
        // Scheduled auto-purge stays OFF until the pipeline earns operational history; operators
        // purge early with typed confirmation.
        'auto_purge_enabled' => (bool) env('TENANCY_AUTO_PURGE_ENABLED', false),
    ],
```

- [ ] **Step 5: Rebuild the local + test schema (local-only)**

The tenants table already exists in the local/test DBs. Since this is pre-launch, the test database
is rebuilt from scratch so the folded migration is exercised exactly:

```bash
composer test:reset-db
composer test:migrate
```

For the local development database, first export/record the existing tenant, membership, and domain
rows. Then run a **throwaway local-only sync script** that adds the two nullable columns when absent;
delete that script after verification. This preserves local content while keeping the shipped
migration folded. Do not use an unresolved `--step`, drop `tenants` (which cascades engine rows), or
ship an ALTER migration. The task records the exact sync SQL and row-count verification in its
execution notes before running it.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=TenantLifecycleTest tests/Integration/Tenancy/TenantLifecycleTest.php`
Expected: PASS.

- [ ] **Step 7: Stage (HOLD — do not commit)**

```bash
git add vendor/glueful/tenancy/migrations/001_CreateTenantsTable.php \
        vendor/glueful/tenancy/config/tenancy.php \
        tests/Integration/Tenancy/TenantLifecycleTest.php
# DO NOT COMMIT — commits are held until explicit go-ahead.
```

---

### Task 2: `released_hosts` migration + `ReleasedHostRepository`

**Files:**
- Create: `vendor/glueful/tenancy/migrations/004_CreateReleasedHostsTable.php`
- Create: `vendor/glueful/tenancy/src/Cooldown/ReleasedHostRepository.php`
- Create: `vendor/glueful/extension-contracts/src/Tenancy/HostCooldownException.php`
- Test: `tests/Integration/Tenancy/HostCooldownTest.php`

**Interfaces:**
- Produces:
  - `ReleasedHostRepository::lockHost(ApplicationContext $c, string $host): void` — takes the per-host xact advisory lock.
  - `ReleasedHostRepository::lockHosts(ApplicationContext $c, array $hosts): void` — normalize/dedupe/sort/lock in order.
  - `ReleasedHostRepository::upsertTombstone(ApplicationContext $c, string $host, string $releasedByTenant, string $retainedUntil): void` — `GREATEST`-merged.
  - `ReleasedHostRepository::activeTombstone(ApplicationContext $c, string $host, string $now): ?array{host:string,released_by_tenant:string,retained_until:string}` — returns a tombstone with `retained_until > now`, else null.
  - `ReleasedHostRepository::consume(ApplicationContext $c, string $host): void` — deletes the tombstone (successful-claim consumption).
  - `ReleasedHostRepository::pruneExpired(ApplicationContext $c, string $now): int` — housekeeping;
    each candidate is rechecked under the same per-host advisory lock used by claim/release.
  - `HostCooldownException` (contract package, extends `\DomainException`) with
    `->availableAfter(): string`; Thallo can map it without importing the concrete extension.
- Consumes: `HostNormalizer::normalize()` (existing), `Connection::getPDO()` (existing).

Add a structural regression assertion that `released_hosts` is absent from
`ThalloTenantTables::tableNames()` and from the extension tenant-table registry. The ledger must
remain system-global after its releasing tenant row is gone.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Tenancy/HostCooldownTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;

final class HostCooldownTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec("DELETE FROM released_hosts WHERE host LIKE '%.cooldown.test'");
        parent::tearDown();
    }

    private function repo(): ReleasedHostRepository
    {
        return $this->container()->get(ReleasedHostRepository::class);
    }

    public function testUpsertNeverShortensRetainedUntil(): void
    {
        $c = $this->appContext();
        $repo = $this->repo();
        $host = 'a.cooldown.test';

        db($c)->transaction(function () use ($c, $repo, $host): void {
            $repo->lockHost($c, $host);
            $repo->upsertTombstone($c, $host, 'tenantAAAAAA', '2999-01-01 00:00:00');
            $repo->upsertTombstone($c, $host, 'tenantAAAAAA', '2000-01-01 00:00:00');
        }); // earlier same-owner release must not shorten the window

        $row = $repo->activeTombstone($c, $host, '2026-01-01 00:00:00');
        self::assertNotNull($row);
        self::assertSame('2999-01-01 00:00:00', substr((string) $row['retained_until'], 0, 19));
    }

    public function testActiveTombstoneIgnoresExpired(): void
    {
        $c = $this->appContext();
        $repo = $this->repo();
        $host = 'b.cooldown.test';
        db($c)->transaction(function () use ($c, $repo, $host): void {
            $repo->lockHost($c, $host);
            $repo->upsertTombstone($c, $host, 'tenantAAAAAA', '2020-01-01 00:00:00');
        });

        self::assertNull($repo->activeTombstone($c, $host, '2026-01-01 00:00:00'));
    }

    public function testUpsertCannotTransferReleaseOwnership(): void
    {
        $c = $this->appContext();
        $repo = $this->repo();
        $host = 'owner.cooldown.test';

        $this->expectException(\LogicException::class);
        db($c)->transaction(function () use ($c, $repo, $host): void {
            $repo->lockHost($c, $host);
            $repo->upsertTombstone($c, $host, 'tenantAAAAAA', '2999-01-01 00:00:00');
            $repo->upsertTombstone($c, $host, 'tenantBBBBBB', '2999-01-02 00:00:00');
        });
    }

    public function testConsumeDeletesTombstone(): void
    {
        $c = $this->appContext();
        $repo = $this->repo();
        $host = 'c.cooldown.test';
        db($c)->transaction(function () use ($c, $repo, $host): void {
            $repo->lockHost($c, $host);
            $repo->upsertTombstone($c, $host, 'tenantAAAAAA', '2999-01-01 00:00:00');
            $repo->consume($c, $host);
        });

        self::assertNull($repo->activeTombstone($c, $host, '2026-01-01 00:00:00'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=HostCooldownTest tests/Integration/Tenancy/HostCooldownTest.php`
Expected: FAIL — `released_hosts` table + `ReleasedHostRepository` do not exist.

- [ ] **Step 3: Create the migration**

Create `vendor/glueful/tenancy/migrations/004_CreateReleasedHostsTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Host-cooldown ledger. When a custom host is released (removeDomain / purge), a tombstone
 * reserves it for release_cooldown_days so it cannot be silently reclaimed by a different
 * tenant. released_by_tenant is a plain indexed scalar (NO FK): the releasing tenant may be
 * purged, leaving the tombstone valid.
 */
final class CreateReleasedHostsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('released_hosts')) {
            return;
        }

        $schema->createTable('released_hosts', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('host', 255);
            $table->string('released_by_tenant', 12);
            $table->timestamp('retained_until');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->unique('host');
            $table->index('released_by_tenant');
            $table->index('retained_until');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('released_hosts');
    }

    public function getDescription(): string
    {
        return 'Creates the host-cooldown tombstone ledger.';
    }
}
```

- [ ] **Step 4: Create the exception**

Create `vendor/glueful/extension-contracts/src/Tenancy/HostCooldownException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

/**
 * A host is in cooldown and claimed by a different tenant. Carries only when it becomes
 * available — never the prior owner (that would leak workspace identity).
 */
final class HostCooldownException extends \DomainException
{
    public function __construct(private readonly string $availableAfter)
    {
        parent::__construct('Host is in cooldown and cannot be claimed yet.');
    }

    public function availableAfter(): string
    {
        return $this->availableAfter;
    }
}
```

- [ ] **Step 5: Create the repository**

Create `vendor/glueful/tenancy/src/Cooldown/ReleasedHostRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Cooldown;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;

/**
 * Reads/writes the released_hosts cooldown ledger and provides per-host serialization via
 * PostgreSQL transaction-scoped advisory locks. Every method assumes it runs inside the
 * caller's transaction.
 */
final class ReleasedHostRepository
{
    private const LOCK_PREFIX = 'tenancy:host:';

    /** Take the per-host xact advisory lock. Serializes claim/release/reclaim/override on one host. */
    public function lockHost(ApplicationContext $c, string $host): void
    {
        if (db($c)->transactionLevel() === 0) {
            throw new \LogicException('Host advisory locks require an active transaction.');
        }
        $stmt = db($c)->getPDO()->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))');
        $stmt->execute([self::LOCK_PREFIX . HostNormalizer::normalize($host)]);
    }

    /**
     * Lock multiple hosts deadlock-free: normalize, dedupe, sort lexically, then lock in order.
     *
     * @param list<string> $hosts
     */
    public function lockHosts(ApplicationContext $c, array $hosts): void
    {
        $normalized = [];
        foreach ($hosts as $host) {
            $normalized[HostNormalizer::normalize($host)] = true;
        }
        $ordered = array_keys($normalized);
        sort($ordered, SORT_STRING);
        foreach ($ordered as $host) {
            $this->lockHost($c, $host);
        }
    }

    /**
     * Upsert a tombstone. GREATEST(existing, new) guarantees a same-owner transaction retry never
     * shortens the reservation. A different owner against an existing tombstone is an invariant
     * violation: a successful intervening claim must have consumed the old row first. Caller must
     * hold lockHost($host) inside an active transaction.
     */
    public function upsertTombstone(
        ApplicationContext $c,
        string $host,
        string $releasedByTenant,
        string $retainedUntil
    ): void {
        $host = HostNormalizer::normalize($host);
        if (db($c)->transactionLevel() === 0) {
            throw new \LogicException('Cooldown mutation requires an active transaction and host lock.');
        }
        $existing = db($c)->getPDO()->prepare(
            'SELECT released_by_tenant FROM released_hosts WHERE host = ?'
        );
        $existing->execute([$host]);
        $owner = $existing->fetchColumn();
        if ($owner !== false && $owner !== $releasedByTenant) {
            throw new \LogicException('Existing host tombstone belongs to a different release owner.');
        }
        $stmt = db($c)->getPDO()->prepare(
            'INSERT INTO released_hosts (host, released_by_tenant, retained_until, created_at) '
            . 'VALUES (?, ?, ?, CURRENT_TIMESTAMP) '
            . 'ON CONFLICT (host) DO UPDATE SET '
            . 'retained_until = GREATEST(released_hosts.retained_until, EXCLUDED.retained_until)'
        );
        $stmt->execute([$host, $releasedByTenant, $retainedUntil]);
    }

    /**
     * The tombstone for $host if it is still in cooldown at $now, else null. Claim correctness
     * never depends on the sweeper: expiry is decided by comparing retained_until to $now here.
     *
     * @return array{host:string,released_by_tenant:string,retained_until:string}|null
     */
    public function activeTombstone(ApplicationContext $c, string $host, string $now): ?array
    {
        $host = HostNormalizer::normalize($host);
        $stmt = db($c)->getPDO()->prepare(
            'SELECT host, released_by_tenant, retained_until FROM released_hosts '
            . 'WHERE host = ? AND retained_until > ?'
        );
        $stmt->execute([$host, $now]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Delete the tombstone for a host — called when a claim succeeds, in the claim's transaction. */
    public function consume(ApplicationContext $c, string $host): void
    {
        if (db($c)->transactionLevel() === 0) {
            throw new \LogicException('Cooldown consumption requires an active transaction and host lock.');
        }
        $host = HostNormalizer::normalize($host);
        $stmt = db($c)->getPDO()->prepare('DELETE FROM released_hosts WHERE host = ?');
        $stmt->execute([$host]);
    }

    /** Housekeeping: prune expired tombstones. Never affects claim correctness. */
    public function pruneExpired(ApplicationContext $c, string $now): int
    {
        $stmt = db($c)->getPDO()->prepare(
            'SELECT host FROM released_hosts WHERE retained_until <= ? ORDER BY host'
        );
        $stmt->execute([$now]);
        $deleted = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $host) {
            db($c)->transaction(function () use ($c, $now, $host, &$deleted): void {
                $this->lockHost($c, (string) $host);
                $delete = db($c)->getPDO()->prepare(
                    'DELETE FROM released_hosts WHERE host = ? AND retained_until <= ?'
                );
                $delete->execute([$host, $now]);
                $deleted += $delete->rowCount();
            });
        }

        return $deleted;
    }
}
```

- [ ] **Step 6: Register the repository in the engine DI**

The verified engine provider uses `services()` with `class/shared/autowire`; add:

```php
            \Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository::class => [
                'class' => \Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository::class,
                'shared' => true,
            ],
```

(If the provider auto-wires zero-arg classes, this may already resolve — the test in Step 7 confirms.)

- [ ] **Step 7: Migrate and run the test**

```bash
composer test:migrate
vendor/bin/phpunit --filter=HostCooldownTest tests/Integration/Tenancy/HostCooldownTest.php
```
Expected: PASS (3 tests).

- [ ] **Step 8: Stage (HOLD)**

```bash
git add vendor/glueful/tenancy/migrations/004_CreateReleasedHostsTable.php \
        vendor/glueful/tenancy/src/Cooldown/ \
        vendor/glueful/tenancy/src/TenancyServiceProvider.php \
        tests/Integration/Tenancy/HostCooldownTest.php
# HOLD.
```

---

### Task 3: Engine lifecycle events (`TenantDeleted`/`TenantRestored`/`HostReleased`)

**Files:**
- Create: `vendor/glueful/tenancy/src/Events/TenantDeleted.php`
- Create: `vendor/glueful/tenancy/src/Events/TenantRestored.php`
- Create: `vendor/glueful/tenancy/src/Events/HostReleased.php`
- Test: `tests/Integration/Tenancy/TenantLifecycleTest.php` (append)

**Interfaces:**
- Produces:
  - `TenantDeleted(string $tenantUuid, string $deletedFromStatus)` — public readonly props `tenantUuid`, `deletedFromStatus`.
  - `TenantRestored(string $tenantUuid, string $restoredToStatus)` — public readonly props `tenantUuid`, `restoredToStatus`.
  - `HostReleased(string $host, string $releasedByTenant, string $retainedUntil)` — public readonly props `host`, `releasedByTenant`, `retainedUntil`.
- Consumes: `Glueful\Events\Contracts\BaseEvent` (framework, PSR-14).

- [ ] **Step 1: Write the failing test**

Append to `tests/Integration/Tenancy/TenantLifecycleTest.php`:

```php
    public function testLifecycleEventsCarryPayload(): void
    {
        $deleted = new \Glueful\Extensions\Tenancy\Events\TenantDeleted('tenantAAAAAA', 'active');
        self::assertSame('tenantAAAAAA', $deleted->tenantUuid);
        self::assertSame('active', $deleted->deletedFromStatus);

        $restored = new \Glueful\Extensions\Tenancy\Events\TenantRestored('tenantAAAAAA', 'suspended');
        self::assertSame('suspended', $restored->restoredToStatus);

        $host = new \Glueful\Extensions\Tenancy\Events\HostReleased('a.example.com', 'tenantAAAAAA', '2026-08-01 00:00:00');
        self::assertSame('a.example.com', $host->host);
        self::assertSame('tenantAAAAAA', $host->releasedByTenant);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=testLifecycleEventsCarryPayload`
Expected: FAIL — event classes not found.

- [ ] **Step 3: Create the three events**

Create `vendor/glueful/tenancy/src/Events/TenantDeleted.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A tenant transitioned active|suspended → deleted (trash). Dispatched only after commit. */
final class TenantDeleted extends BaseEvent
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $deletedFromStatus,
    ) {
        parent::__construct();
    }
}
```

Create `vendor/glueful/tenancy/src/Events/TenantRestored.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A tenant was restored deleted → its prior status. Dispatched only after commit. */
final class TenantRestored extends BaseEvent
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $restoredToStatus,
    ) {
        parent::__construct();
    }
}
```

Create `vendor/glueful/tenancy/src/Events/HostReleased.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A custom host was released and tombstoned for cooldown. Dispatched only after commit. */
final class HostReleased extends BaseEvent
{
    public function __construct(
        public readonly string $host,
        public readonly string $releasedByTenant,
        public readonly string $retainedUntil,
    ) {
        parent::__construct();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=testLifecycleEventsCarryPayload`
Expected: PASS.

- [ ] **Step 5: Stage (HOLD)**

```bash
git add vendor/glueful/tenancy/src/Events/ tests/Integration/Tenancy/TenantLifecycleTest.php
# HOLD.
```

---

### Task 4: `deleteTenant()` — trash transition + engine safety gates

**Files:**
- Modify: `vendor/glueful/extension-contracts/src/Tenancy/TenantAdministration.php`
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php`
- Create: `vendor/glueful/tenancy/src/Exceptions/FinalWorkspaceException.php`
- Create: `vendor/glueful/tenancy/src/Exceptions/RequiredHostOwnedException.php`
- Create: `vendor/glueful/tenancy/src/Exceptions/TenantLifecycleException.php`
- Test: `tests/Integration/Tenancy/TenantLifecycleGatesTest.php`

**Interfaces:**
- Produces: `TenantAdministration::deleteTenant(ApplicationContext $c, string $tenantUuid): void`.
  - Refuses (throws `FinalWorkspaceException`) if the target is the last non-deleted `provisioning|active|suspended` workspace.
  - Refuses (throws `RequiredHostOwnedException`) if the target owns any configured `tenancy.public_origin.default_hosts`.
  - Refuses (throws `TenantLifecycleException`) if the target is not currently `active|suspended`.
  - On success: `status=deleted`, `deleted_at=now`, `deleted_from_status=<prior>`, `purge_after=now + trash_retention_days`; dispatches `TenantDeleted` after commit.
- Consumes: `ReleasedHostRepository` (not yet — this task only reads domains); `EventService` (framework); `config()`, `db()->transaction()`, `db()->afterCommit()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Tenancy/TenantLifecycleGatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Tenancy\Exceptions\FinalWorkspaceException;
use Glueful\Helpers\Utils;

final class TenantLifecycleGatesTest extends AppTestCase
{
    /** @var list<string> */
    private array $tenants = [];

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach ($this->tenants as $uuid) {
            $pdo->prepare('DELETE FROM tenant_memberships WHERE tenant_uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM tenants WHERE uuid = ?')->execute([$uuid]);
        }
        $this->tenants = [];
        parent::tearDown();
    }

    private function admin(): TenantAdministration
    {
        return $this->container()->get(TenantAdministration::class);
    }

    /** Insert an active tenant directly (bypassing provisioning) with a distinct owner. */
    private function seedActiveTenant(): string
    {
        $c = $this->appContext();
        $uuid = $this->admin()->create($c, 'ws-' . strtolower(Utils::generateNanoID(6)), 'WS', Utils::generateNanoID(12));
        $this->admin()->markActive($c, $uuid);
        $this->tenants[] = $uuid;
        return $uuid;
    }

    public function testDeleteMovesTenantToTrashWithPriorStatus(): void
    {
        $c = $this->appContext();
        // Two workspaces so neither is "final".
        $keep = $this->seedActiveTenant();
        $target = $this->seedActiveTenant();

        $this->admin()->deleteTenant($c, $target);

        $row = $this->connection()->getPDO()
            ->query("SELECT status, deleted_at, deleted_from_status, purge_after FROM tenants WHERE uuid = " .
                $this->connection()->getPDO()->quote($target))
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('deleted', $row['status']);
        self::assertNotNull($row['deleted_at']);
        self::assertSame('active', $row['deleted_from_status']);
        self::assertNotNull($row['purge_after']);
    }

    public function testDeleteRefusesFinalWorkspace(): void
    {
        $c = $this->appContext();
        $pdo = $this->connection()->getPDO();
        $only = $this->seedActiveTenant();
        $existing = $pdo->query(
            "SELECT uuid,status,deleted_at FROM tenants "
            . "WHERE uuid <> " . $pdo->quote($only)
            . " AND status IN ('provisioning','active','suspended') AND deleted_at IS NULL"
        )->fetchAll(\PDO::FETCH_ASSOC);
        try {
            foreach ($existing as $row) {
                $pdo->prepare("UPDATE tenants SET status='deleted', deleted_at=CURRENT_TIMESTAMP WHERE uuid=?")
                    ->execute([$row['uuid']]);
            }
            try {
                $this->admin()->deleteTenant($c, $only);
                self::fail('final workspace deletion must be refused');
            } catch (FinalWorkspaceException) {
                self::addToAssertionCount(1);
            }
        } finally {
            foreach ($existing as $row) {
                $pdo->prepare('UPDATE tenants SET status=?, deleted_at=? WHERE uuid=?')
                    ->execute([$row['status'], $row['deleted_at'], $row['uuid']]);
            }
        }
    }
}
```

Add two more acceptance methods before Step 2:
- configure a `tenancy.public_origin.default_hosts` host owned by the target and assert
  `RequiredHostOwnedException` with the normalized blocking host;
- use two independent PostgreSQL sessions to attempt deletion of the last two candidates
  concurrently and assert exactly one commits while the other is refused after the locked-row
  recount. Both tests restore any pre-existing rows/config in `finally` blocks.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=TenantLifecycleGatesTest`
Expected: FAIL — `deleteTenant` not declared on the contract / not implemented; `FinalWorkspaceException` missing.

- [ ] **Step 3: Create the exceptions**

Create `vendor/glueful/tenancy/src/Exceptions/TenantLifecycleException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/** A tenant lifecycle transition was refused because the tenant is in the wrong state. */
final class TenantLifecycleException extends \RuntimeException
{
}
```

Create `vendor/glueful/tenancy/src/Exceptions/FinalWorkspaceException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/** Refused: deleting the last non-deleted workspace would leave the platform with none. */
final class FinalWorkspaceException extends \DomainException
{
}
```

Create `vendor/glueful/tenancy/src/Exceptions/RequiredHostOwnedException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/** Refused: the workspace owns a configured required default host; remap it before deleting. */
final class RequiredHostOwnedException extends \DomainException
{
    /** @param list<string> $hosts */
    public function __construct(public readonly array $hosts)
    {
        parent::__construct('Workspace owns required default host(s): ' . implode(', ', $hosts));
    }
}
```

- [ ] **Step 4: Add the contract signature**

In `vendor/glueful/extension-contracts/src/Tenancy/TenantAdministration.php`, add after `markActive()`:

```php
    /**
     * Soft-delete (trash) a tenant: active|suspended → deleted. Retains domains (reserved),
     * records the prior status and a restore deadline. Refuses the final workspace or one owning
     * a required default host.
     */
    public function deleteTenant(ApplicationContext $c, string $tenantUuid): void;

    /** Restore a trashed tenant to its prior status. Only deleted AND now <= purge_after. */
    public function restoreTenant(ApplicationContext $c, string $tenantUuid): void;

    /** Claim the point of no return: deleted → purging. Required before any product-data destruction. */
    public function beginPurge(ApplicationContext $c, string $tenantUuid): void;

    /**
     * Include-deleted lifecycle projection used by restore/purge status surfaces and crash recovery.
     *
     * @return array{uuid:string,slug:string,name:string,status:string,deleted_at:?string,
     *     deleted_from_status:?string,purge_after:?string}|null
     */
    public function getTenantLifecycle(ApplicationContext $c, string $tenantUuid): ?array;

    /**
     * Final engine purge (requires purging): one transaction — tombstone + release every host,
     * delete domains/memberships explicitly, hard-delete the tenant row. NOT FK-cascade.
     */
    public function purgeTenantRecord(ApplicationContext $c, string $tenantUuid): void;
```

- [ ] **Step 5: Implement `deleteTenant()` + gates in the bridge**

In `vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php`, add these imports at the top:

```php
use Glueful\Events\EventService;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Events\TenantDeleted;
use Glueful\Extensions\Tenancy\Exceptions\FinalWorkspaceException;
use Glueful\Extensions\Tenancy\Exceptions\RequiredHostOwnedException;
use Glueful\Extensions\Tenancy\Exceptions\TenantLifecycleException;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
```

Add the method (place after `markActive()`):

```php
    public function deleteTenant(ApplicationContext $c, string $tenantUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid): void {
            // Gate 1: final-workspace. PostgreSQL forbids FOR UPDATE on an aggregate, so lock the
            // candidate rows in deterministic order, then count the locked set.
            $this->assertNotFinalWorkspace($c, $tenantUuid);

            // Lock + read the target's current status (include-deleted: raw table, no ORM scope).
            $prior = $this->lockTenantStatus($c, $tenantUuid);
            if ($prior === null || !in_array($prior, ['active', 'suspended'], true)) {
                throw new TenantLifecycleException('deleteTenant requires an active or suspended tenant.');
            }

            // Gate 2: required-default-host ownership.
            $this->assertNotRequiredHostOwner($c, $tenantUuid);

            $retentionDays = (int) config($c, 'tenancy.tenants.trash_retention_days', 30);
            $now = gmdate('Y-m-d H:i:s');
            $purgeAfter = gmdate('Y-m-d H:i:s', time() + $retentionDays * 86400);

            $changed = db($c)->table('tenants')
                ->where('uuid', $tenantUuid)
                ->where('status', $prior)
                ->update([
                    'status' => 'deleted',
                    'deleted_at' => $now,
                    'deleted_from_status' => $prior,
                    'purge_after' => $purgeAfter,
                    'updated_at' => $now,
                ]);
            if ($changed === 0) {
                throw new TenantLifecycleException('deleteTenant lost the status race.');
            }

            db($c)->afterCommit(static function () use ($c, $tenantUuid, $prior): void {
                app($c, EventService::class)->dispatch(new TenantDeleted($tenantUuid, $prior));
            });
        });
    }

    public function getTenantLifecycle(ApplicationContext $c, string $tenantUuid): ?array
    {
        $stmt = db($c)->getPDO()->prepare(
            'SELECT uuid, slug, name, status, deleted_at, deleted_from_status, purge_after '
            . 'FROM tenants WHERE uuid = ?'
        );
        $stmt->execute([$tenantUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Lock the tenant's row and return its status, seeing soft-deleted rows (raw table access
     * bypasses the ORM soft-delete scope, matching listTenantsForUser's raw-SQL idiom).
     */
    private function lockTenantStatus(ApplicationContext $c, string $tenantUuid): ?string
    {
        $sql = 'SELECT status FROM tenants WHERE uuid = ?';
        if (db($c)->getDriverName() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = db($c)->getPDO()->prepare($sql);
        $stmt->execute([$tenantUuid]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    private function assertNotFinalWorkspace(ApplicationContext $c, string $tenantUuid): void
    {
        $sql = "SELECT uuid FROM tenants "
            . "WHERE status IN ('provisioning','active','suspended') AND deleted_at IS NULL "
            . 'ORDER BY uuid';
        if (db($c)->getDriverName() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = db($c)->getPDO()->query($sql);
        $uuids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (count($uuids) <= 1 && in_array($tenantUuid, $uuids, true)) {
            throw new FinalWorkspaceException('Cannot delete the final workspace.');
        }
    }

    private function assertNotRequiredHostOwner(ApplicationContext $c, string $tenantUuid): void
    {
        $configured = config($c, 'tenancy.public_origin.default_hosts', []);
        $required = [];
        foreach (is_array($configured) ? $configured : [] as $host) {
            if (is_string($host) && $host !== '') {
                $required[HostNormalizer::normalize($host)] = true;
            }
        }
        if ($required === []) {
            return;
        }
        $stmt = db($c)->getPDO()->prepare('SELECT host FROM tenant_domains WHERE tenant_uuid = ?');
        $stmt->execute([$tenantUuid]);
        $owned = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $host) {
            $normalized = HostNormalizer::normalize((string) $host);
            if (isset($required[$normalized])) {
                $owned[] = $normalized;
            }
        }
        if ($owned !== []) {
            throw new RequiredHostOwnedException($owned);
        }
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=TenantLifecycleGatesTest`
Expected: PASS (2 tests). (`restoreTenant`/`beginPurge`/`purgeTenantRecord` are declared on the contract but not yet implemented — the bridge will not satisfy the interface until Tasks 5 & 8. To keep the bridge instantiable meanwhile, add temporary stub bodies that `throw new TenantLifecycleException('not implemented')` for the three methods, replaced in Tasks 5 & 8. Add the stubs now so the class is concrete.)

Add temporary stubs directly after `deleteTenant()`:

```php
    public function restoreTenant(ApplicationContext $c, string $tenantUuid): void
    {
        throw new TenantLifecycleException('restoreTenant not yet implemented.');
    }

    public function beginPurge(ApplicationContext $c, string $tenantUuid): void
    {
        throw new TenantLifecycleException('beginPurge not yet implemented.');
    }

    public function purgeTenantRecord(ApplicationContext $c, string $tenantUuid): void
    {
        throw new TenantLifecycleException('purgeTenantRecord not yet implemented.');
    }
```

Re-run: `vendor/bin/phpunit --filter=TenantLifecycleGatesTest` → PASS.

- [ ] **Step 7: phpcs + stage (HOLD)**

```bash
composer phpcs -- vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php vendor/glueful/tenancy/src/Exceptions/
git add vendor/glueful/extension-contracts/src/Tenancy/TenantAdministration.php \
        vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php \
        vendor/glueful/tenancy/src/Exceptions/ \
        tests/Integration/Tenancy/TenantLifecycleGatesTest.php
# HOLD.
```

Note: the vendored engine has its own phpcs config; if `composer phpcs` in the app doesn't cover `vendor/`, run the check when porting to the source repo. Match the surrounding code style regardless.

---

### Task 5: `restoreTenant()` + `beginPurge()`

**Files:**
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php`
- Test: `tests/Integration/Tenancy/TenantLifecycleTest.php` (append)

**Interfaces:**
- Produces:
  - `restoreTenant()` — guarded `deleted → deleted_from_status`, only when `now <= purge_after`; clears `deleted_at`/`deleted_from_status`/`purge_after`; dispatches `TenantRestored(uuid, restoredToStatus)` after commit; throws `TenantLifecycleException` otherwise.
  - `beginPurge()` — guarded `deleted → purging`; throws `TenantLifecycleException` if not `deleted`.
  - `listTenants()` becomes an include-deleted raw projection returning lifecycle fields
    (`deleted_at`, `deleted_from_status`, `purge_after`) so trash can be restored; `getTenant()` and
    request resolution remain soft-delete scoped.
- Consumes: `TenantRestored` event, `lockTenantStatus()` (Task 4).

- [ ] **Step 1: Write the failing test**

Append to `tests/Integration/Tenancy/TenantLifecycleTest.php` (add `use` for `TenantAdministration`, `Utils`, and a tenant tear-down like the gates test — mirror that helper). Add:

```php
    public function testRestoreRecoversPriorStatusWithinWindow(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration::class);
        $keep = $this->makeActiveTenant($admin);      // keeps the platform non-final
        $target = $this->makeActiveTenant($admin);
        $admin->suspend($c, $target);                 // prior status = suspended
        $admin->deleteTenant($c, $target);

        $admin->restoreTenant($c, $target);

        $row = $this->tenantRow($target);
        self::assertSame('suspended', $row['status']);
        self::assertNull($row['deleted_at']);
        self::assertNull($row['deleted_from_status']);
        self::assertNull($row['purge_after']);
    }

    public function testRestoreRefusedAfterPurgeAfter(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration::class);
        $this->makeActiveTenant($admin);
        $target = $this->makeActiveTenant($admin);
        $admin->deleteTenant($c, $target);
        // Force the window closed.
        $this->connection()->getPDO()->prepare("UPDATE tenants SET purge_after = '2000-01-01 00:00:00' WHERE uuid = ?")
            ->execute([$target]);

        $this->expectException(\Glueful\Extensions\Tenancy\Exceptions\TenantLifecycleException::class);
        $admin->restoreTenant($c, $target);
    }

    public function testBeginPurgeRequiresDeleted(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration::class);
        $this->makeActiveTenant($admin);
        $target = $this->makeActiveTenant($admin);

        // active → beginPurge is refused
        try {
            $admin->beginPurge($c, $target);
            self::fail('beginPurge should refuse a non-deleted tenant');
        } catch (\Glueful\Extensions\Tenancy\Exceptions\TenantLifecycleException) {
        }

        $admin->deleteTenant($c, $target);
        $admin->beginPurge($c, $target);
        self::assertSame('purging', $this->tenantRow($target)['status']);
    }
```

Add these helpers to the test class (and a `$tenants` array + `tearDown` cleanup mirroring `TenantLifecycleGatesTest`):

```php
    private function makeActiveTenant(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration $admin): string
    {
        $c = $this->appContext();
        $uuid = $admin->create($c, 'ws-' . strtolower(\Glueful\Helpers\Utils::generateNanoID(6)), 'WS', \Glueful\Helpers\Utils::generateNanoID(12));
        $admin->markActive($c, $uuid);
        $this->tenants[] = $uuid;
        return $uuid;
    }

    /** @return array<string,mixed> */
    private function tenantRow(string $uuid): array
    {
        $stmt = $this->connection()->getPDO()->prepare('SELECT * FROM tenants WHERE uuid = ?');
        $stmt->execute([$uuid]);
        /** @var array<string,mixed> */
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
```

Add `testListTenantsIncludesDeletedLifecycleProjection`: delete a non-final tenant, assert the
unfiltered list and `listTenants($c, 'deleted')` both include its exact `purge_after`, while
`getTenant($c, $uuid)` remains null. Implement `listTenants()` with raw PDO ordered by
`created_at, uuid`; accept the optional status only from
`provisioning|active|suspended|deleted|purging`, bind it as a parameter, and project existing fields
plus the three lifecycle fields. This is the only list surface that intentionally sees trash.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=TenantLifecycleTest`
Expected: FAIL — restore/beginPurge stubs throw "not yet implemented".

- [ ] **Step 3: Implement `restoreTenant()` and `beginPurge()`**

Replace the two stubs in `ContractTenantAdministration.php`. Add the import:

```php
use Glueful\Extensions\Tenancy\Events\TenantRestored;
```

Replace `restoreTenant()`:

```php
    public function restoreTenant(ApplicationContext $c, string $tenantUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid): void {
            // Read prior status + window under lock, seeing the soft-deleted row.
            $sql = 'SELECT status, deleted_from_status, purge_after FROM tenants WHERE uuid = ?';
            if (db($c)->getDriverName() !== 'sqlite') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = db($c)->getPDO()->prepare($sql);
            $stmt->execute([$tenantUuid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false || $row['status'] !== 'deleted') {
                throw new TenantLifecycleException('restoreTenant requires a deleted tenant.');
            }
            if ($row['purge_after'] !== null && gmdate('Y-m-d H:i:s') > (string) $row['purge_after']) {
                throw new TenantLifecycleException('Restore window has expired.');
            }
            $restoreTo = $row['deleted_from_status'] ?? null;
            if (!is_string($restoreTo) || !in_array($restoreTo, ['active', 'suspended'], true)) {
                throw new TenantLifecycleException('Deleted tenant has invalid prior-status metadata.');
            }

            $changed = db($c)->table('tenants')
                ->where('uuid', $tenantUuid)
                ->where('status', 'deleted')
                ->update([
                    'status' => $restoreTo,
                    'deleted_at' => null,
                    'deleted_from_status' => null,
                    'purge_after' => null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            if ($changed === 0) {
                throw new TenantLifecycleException('restoreTenant lost the status race.');
            }

            db($c)->afterCommit(static function () use ($c, $tenantUuid, $restoreTo): void {
                app($c, EventService::class)->dispatch(new TenantRestored($tenantUuid, $restoreTo));
            });
        });
    }
```

Replace `beginPurge()`:

```php
    public function beginPurge(ApplicationContext $c, string $tenantUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid): void {
            $status = $this->lockTenantStatus($c, $tenantUuid);
            if ($status !== 'deleted') {
                throw new TenantLifecycleException('beginPurge requires a deleted tenant.');
            }
            $changed = db($c)->table('tenants')
                ->where('uuid', $tenantUuid)
                ->where('status', 'deleted')
                ->update(['status' => 'purging', 'updated_at' => gmdate('Y-m-d H:i:s')]);
            if ($changed === 0) {
                throw new TenantLifecycleException('beginPurge lost the status race.');
            }
        });
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=TenantLifecycleTest`
Expected: PASS.

- [ ] **Step 5: Stage (HOLD)**

```bash
git add vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php \
        tests/Integration/Tenancy/TenantLifecycleTest.php
# HOLD.
```

---

### Task 6: `releaseDomain()` + `removeDomain()` delegation (host-cooldown release)

**Files:**
- Modify: `vendor/glueful/extension-contracts/src/Tenancy/TenantDomainAdministration.php`
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php`
- Test: `tests/Integration/Tenancy/HostCooldownTest.php` (append)

**Interfaces:**
- Produces: `TenantDomainAdministration::releaseDomain(ApplicationContext $c, string $domainUuid): void` — captures `{domain_uuid, tenant_uuid, host}`, deletes the `tenant_domains` row, upserts a cooldown tombstone (`retained_until = now + release_cooldown_days`, GREATEST-merged), all under the per-host lock in one transaction; dispatches `HostReleased` after commit. `removeDomain()` now delegates to it (after `assertNotRequiredHost`).
- Consumes: `ReleasedHostRepository` (Task 2), `HostReleased` event (Task 3), `assertNotRequiredHost()` (existing).

- [ ] **Step 1: Write the failing test**

Append to `tests/Integration/Tenancy/HostCooldownTest.php` (add `use` for `TenantAdministration`, `TenantDomainAdministration`, `Utils`, and a tenants/domains teardown):

```php
    public function testRemoveDomainWritesCooldownTombstone(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration::class);
        $domains = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration::class);

        $tenant = $admin->create($c, 'rel-' . strtolower(\Glueful\Helpers\Utils::generateNanoID(6)), 'Rel', \Glueful\Helpers\Utils::generateNanoID(12));
        $this->tenants[] = $tenant;
        $host = strtolower(\Glueful\Helpers\Utils::generateNanoID(8)) . '.cooldown.test';
        $domainUuid = $domains->addPreverifiedDomain($c, $tenant, $host);

        $domains->removeDomain($c, $domainUuid);

        // Row gone.
        self::assertNull($domains->getDomain($c, $domainUuid));
        // Tombstone present with the releasing tenant recorded.
        $row = $this->repo()->activeTombstone($c, $host, gmdate('Y-m-d H:i:s'));
        self::assertNotNull($row);
        self::assertSame($tenant, $row['released_by_tenant']);
    }
```

(Ensure the appended test's `tearDown` also deletes seeded tenants/domains; extend the existing `tearDown`.)

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=testRemoveDomainWritesCooldownTombstone`
Expected: FAIL — `removeDomain` hard-deletes without a tombstone; `releaseDomain` not declared.

- [ ] **Step 3: Add the contract signature**

In `vendor/glueful/extension-contracts/src/Tenancy/TenantDomainAdministration.php`, add after `removeDomain()`:

```php
    /**
     * Cooldown-aware host release: delete the domain row and tombstone its host for the configured
     * cooldown so a different tenant cannot immediately reclaim it. removeDomain() delegates here.
     */
    public function releaseDomain(ApplicationContext $c, string $domainUuid): void;

    /**
     * Highest-trust override: consume cooldown and create a pending domain for the named tenant
     * under one host lock. Callers separately enforce product authorization and audit.
     *
     * @return array{uuid:string,token:string}
     */
    public function overrideCooldownAndClaim(
        ApplicationContext $c,
        string $tenantUuid,
        string $host
    ): array;
```

- [ ] **Step 4: Implement `releaseDomain()` and delegate `removeDomain()`**

In `ContractTenantDomainAdministration.php`, add a constructor dependency on the repository and the imports. Change the constructor:

```php
use Glueful\Events\EventService;
use Glueful\Extensions\Contracts\Tenancy\HostCooldownException;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Events\HostReleased;
```

```php
    public function __construct(
        private readonly DnsTxtLookup $dns = new DnsTxtLookup(),
        private readonly ReleasedHostRepository $cooldown = new ReleasedHostRepository(),
    ) {
    }
```

The verified engine provider autowires this bridge. Preserve `DnsTxtLookup` as the first constructor
argument for existing direct tests; add `ReleasedHostRepository` second with a stateless default,
and add focused constructor tests for both default and injected repositories.

Replace `removeDomain()`:

```php
    public function removeDomain(ApplicationContext $c, string $domainUuid): void
    {
        $this->assertNotRequiredHost($c, $domainUuid);
        $this->releaseDomain($c, $domainUuid);
    }

    public function releaseDomain(ApplicationContext $c, string $domainUuid): void
    {
        db($c)->transaction(function () use ($c, $domainUuid): void {
            $domain = TenantDomain::query($c)->where('uuid', $domainUuid)->first();
            if ($domain === null) {
                throw new \RuntimeException('Tenant domain was not found.');
            }
            $host = HostNormalizer::normalize($domain->host);
            $tenantUuid = (string) $domain->tenant_uuid;

            // Serialize with any concurrent claim/release of the same host.
            $this->cooldown->lockHost($c, $host);

            $deleted = db($c)->table('tenant_domains')->where('uuid', $domainUuid)->forceDelete();
            if ($deleted === 0) {
                // Lost the race to another release inside this same host lock — treat as not found.
                throw new \RuntimeException('Tenant domain was not found.');
            }

            $cooldownDays = (int) config($c, 'tenancy.domains.release_cooldown_days', 30);
            $retainedUntil = gmdate('Y-m-d H:i:s', time() + $cooldownDays * 86400);
            $this->cooldown->upsertTombstone($c, $host, $tenantUuid, $retainedUntil);

            db($c)->afterCommit(static function () use ($c, $host, $tenantUuid, $retainedUntil): void {
                app($c, EventService::class)->dispatch(new HostReleased($host, $tenantUuid, $retainedUntil));
            });
        });
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=HostCooldownTest`
Expected: PASS (all cooldown tests, including the new release test).

- [ ] **Step 6: Stage (HOLD)**

```bash
git add vendor/glueful/extension-contracts/src/Tenancy/TenantDomainAdministration.php \
        vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php \
        tests/Integration/Tenancy/HostCooldownTest.php
# HOLD.
```

---

### Task 7: Claim-time cooldown enforcement + tombstone consumption

**Files:**
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php`
- Test: `tests/Integration/Tenancy/HostCooldownTest.php` (append)

**Interfaces:**
- Produces: `addDomain()` and `addPreverifiedDomain()` now — under the per-host lock — refuse a host whose active tombstone belongs to a **different** tenant (throw `HostCooldownException` with `available_after`); the releasing tenant may reclaim immediately; every successful claim **consumes** (deletes) the tombstone in the same transaction.
- Consumes: `ReleasedHostRepository::lockHost/activeTombstone/consume` (Task 2), `HostCooldownException` (Task 2).

- [ ] **Step 1: Write the failing test**

Append to `HostCooldownTest.php`:

```php
    public function testClaimRefusedForDifferentTenantDuringCooldown(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration::class);
        $domains = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration::class);

        $owner = $admin->create($c, 'own-' . strtolower(\Glueful\Helpers\Utils::generateNanoID(6)), 'Own', \Glueful\Helpers\Utils::generateNanoID(12));
        $other = $admin->create($c, 'oth-' . strtolower(\Glueful\Helpers\Utils::generateNanoID(6)), 'Oth', \Glueful\Helpers\Utils::generateNanoID(12));
        $this->tenants[] = $owner;
        $this->tenants[] = $other;
        $host = strtolower(\Glueful\Helpers\Utils::generateNanoID(8)) . '.cooldown.test';

        $d = $domains->addPreverifiedDomain($c, $owner, $host);
        $domains->removeDomain($c, $d); // tombstone now reserves $host for $owner

        try {
            $domains->addPreverifiedDomain($c, $other, $host);
            self::fail('claim by a different tenant during cooldown must be refused');
        } catch (\Glueful\Extensions\Contracts\Tenancy\HostCooldownException $e) {
            self::assertNotSame('', $e->availableAfter());
        }
    }

    public function testOriginalOwnerReclaimsAndConsumesTombstone(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration::class);
        $domains = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration::class);

        $owner = $admin->create($c, 'rec-' . strtolower(\Glueful\Helpers\Utils::generateNanoID(6)), 'Rec', \Glueful\Helpers\Utils::generateNanoID(12));
        $this->tenants[] = $owner;
        $host = strtolower(\Glueful\Helpers\Utils::generateNanoID(8)) . '.cooldown.test';

        $d = $domains->addPreverifiedDomain($c, $owner, $host);
        $domains->removeDomain($c, $d);
        $domains->addPreverifiedDomain($c, $owner, $host); // reclaim allowed

        // Tombstone consumed.
        self::assertNull($this->repo()->activeTombstone($c, $host, gmdate('Y-m-d H:i:s')));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=HostCooldownTest`
Expected: FAIL — claim ignores cooldown; the reclaim leaves a stale tombstone.

- [ ] **Step 3: Add a shared cooldown-gate helper and call it from both claim paths**

In `ContractTenantDomainAdministration.php`, add a private helper:

```php
    /**
     * Enforce cooldown at claim time and consume the tombstone on success. Caller runs inside a
     * transaction; this takes the per-host lock. Refuses only when the active tombstone belongs to
     * a different tenant. On any allowed claim it deletes the tombstone so a later release records
     * the new owner instead of inheriting stale release identity.
     */
    private function guardCooldownAndConsume(ApplicationContext $c, string $host, string $claimantTenant): void
    {
        $host = HostNormalizer::normalize($host);
        $this->cooldown->lockHost($c, $host);
        $tombstone = $this->cooldown->activeTombstone($c, $host, gmdate('Y-m-d H:i:s'));
        if ($tombstone !== null && $tombstone['released_by_tenant'] !== $claimantTenant) {
            throw new HostCooldownException((string) $tombstone['retained_until']);
        }
        // Allowed (no tombstone, expired, or same-tenant reclaim) — consume any residual tombstone.
        $this->cooldown->consume($c, $host);
    }
```

Wrap `addDomain()` in a transaction and call the guard:

```php
    public function addDomain(ApplicationContext $c, string $tenantUuid, string $host): array
    {
        $host = $this->registeredHost($c, $host);

        return db($c)->transaction(function () use ($c, $tenantUuid, $host): array {
            $this->guardCooldownAndConsume($c, $host, $tenantUuid);
            $token = bin2hex(random_bytes(32));
            $domain = TenantDomain::create($c, [
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $tenantUuid,
                'host' => $host,
                'verification_token' => $token,
            ]);

            return ['uuid' => $domain->uuid, 'token' => $token];
        });
    }
```

In `addPreverifiedDomain()`, after the `HostNormalizer::normalize()` + `validateForRegistration()` calls and before the `$existing` lookup, wrap the remaining body in a transaction and insert the guard. The simplest correct edit: wrap the whole existing body (from the `$existing = …` lookup through the returns) in `db($c)->transaction(function () use (...) { $this->guardCooldownAndConsume($c, $host, $tenantUuid); … });`. When `$existing !== null && $existing->tenant_uuid === $tenantUuid` (same-tenant re-add), the guard's same-tenant branch consumes the tombstone and allows it.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=HostCooldownTest`
Expected: PASS.

- [ ] **Step 5: Stage (HOLD)**

```bash
git add vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php \
        tests/Integration/Tenancy/HostCooldownTest.php
# HOLD.
```

---

### Task 8: `purgeTenantRecord()` one-transaction final purge + atomic override claim

**Files:**
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php`
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php` (override entry)
- Test: `tests/Integration/Tenancy/PurgeTenantRecordTest.php`

**Interfaces:**
- Produces:
  - `purgeTenantRecord()` — requires `status=purging`; in one transaction under the tenant lock + all per-host locks: upsert a tombstone for every owned host, delete `tenant_domains` explicitly, delete `tenant_memberships`, hard-delete the `tenants` row (raw `DELETE`, NOT FK cascade); dispatches one `HostReleased` per host after commit.
  - `ContractTenantDomainAdministration::overrideCooldownAndClaim(..., tenantUuid, host): array`
    — consumes the tombstone and creates that tenant's pending domain in one host-locked
    transaction. No globally free race window; DNS verification remains mandatory.
- Consumes: `ReleasedHostRepository` (Task 2), `HostReleased` event (Task 3), `TenantLifecycleException` (Task 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Tenancy/PurgeTenantRecordTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Exceptions\TenantLifecycleException;
use Glueful\Helpers\Utils;

final class PurgeTenantRecordTest extends AppTestCase
{
    /** @var list<string> */
    private array $tenants = [];
    /** @var list<string> */
    private array $hosts = [];

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach ($this->hosts as $host) {
            $pdo->prepare('DELETE FROM released_hosts WHERE host = ?')->execute([$host]);
        }
        foreach ($this->tenants as $uuid) {
            $pdo->prepare('DELETE FROM tenant_domains WHERE tenant_uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM tenant_memberships WHERE tenant_uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM tenants WHERE uuid = ?')->execute([$uuid]);
        }
        $this->tenants = [];
        $this->hosts = [];
        parent::tearDown();
    }

    public function testPurgeRequiresPurgingStatus(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(TenantAdministration::class);
        $keep = $admin->create($c, 'k-' . strtolower(Utils::generateNanoID(6)), 'K', Utils::generateNanoID(12));
        $admin->markActive($c, $keep);
        $this->tenants[] = $keep;
        $t = $admin->create($c, 'p-' . strtolower(Utils::generateNanoID(6)), 'P', Utils::generateNanoID(12));
        $admin->markActive($c, $t);
        $this->tenants[] = $t;

        $this->expectException(TenantLifecycleException::class);
        $admin->purgeTenantRecord($c, $t); // still active — must refuse
    }

    public function testPurgeTombstonesHostsAndHardDeletesTenant(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(TenantAdministration::class);
        $domains = $this->container()->get(TenantDomainAdministration::class);
        $repo = $this->container()->get(ReleasedHostRepository::class);

        $keep = $admin->create(
            $c,
            'keep-' . strtolower(Utils::generateNanoID(6)),
            'Keep',
            Utils::generateNanoID(12),
        );
        $admin->markActive($c, $keep);
        $this->tenants[] = $keep;
        $t = $admin->create($c, 'gone-' . strtolower(Utils::generateNanoID(6)), 'Gone', Utils::generateNanoID(12));
        $admin->markActive($c, $t);
        $this->tenants[] = $t;
        $host = strtolower(Utils::generateNanoID(8)) . '.purge.test';
        $this->hosts[] = $host;
        $domains->addPreverifiedDomain($c, $t, $host);

        $admin->deleteTenant($c, $t);
        $admin->beginPurge($c, $t);
        $admin->purgeTenantRecord($c, $t);

        $pdo = $this->connection()->getPDO();
        self::assertFalse(
            $pdo->query('SELECT 1 FROM tenants WHERE uuid = ' . $pdo->quote($t))->fetchColumn(),
            'tenant row hard-deleted'
        );
        self::assertFalse(
            $pdo->query('SELECT 1 FROM tenant_domains WHERE tenant_uuid = ' . $pdo->quote($t))->fetchColumn(),
            'domains deleted'
        );
        self::assertNotNull($repo->activeTombstone($c, $host, gmdate('Y-m-d H:i:s')), 'host tombstoned');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=PurgeTenantRecordTest`
Expected: FAIL — `purgeTenantRecord` stub throws "not yet implemented".

- [ ] **Step 3: Implement `purgeTenantRecord()`**

Replace the `purgeTenantRecord()` stub in `ContractTenantAdministration.php`. Add a constructor-injected `ReleasedHostRepository`. Since the bridge currently has no constructor, add one:

```php
    public function __construct(
        private readonly ReleasedHostRepository $cooldown = new ReleasedHostRepository(),
    ) {
    }
```

Add imports `use Glueful\Extensions\Tenancy\Events\HostReleased;`. Implement:

```php
    public function purgeTenantRecord(ApplicationContext $c, string $tenantUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid): void {
            $status = $this->lockTenantStatus($c, $tenantUuid);
            if ($status !== 'purging') {
                throw new TenantLifecycleException('purgeTenantRecord requires a purging tenant.');
            }

            // Collect owned hosts, lock them deterministically alongside the tenant row.
            $stmt = db($c)->getPDO()->prepare('SELECT host FROM tenant_domains WHERE tenant_uuid = ?');
            $stmt->execute([$tenantUuid]);
            $hosts = array_map(
                static fn ($h): string => HostNormalizer::normalize((string) $h),
                $stmt->fetchAll(\PDO::FETCH_COLUMN)
            );
            $this->cooldown->lockHosts($c, $hosts);

            $cooldownDays = (int) config($c, 'tenancy.domains.release_cooldown_days', 30);
            $retainedUntil = gmdate('Y-m-d H:i:s', time() + $cooldownDays * 86400);
            foreach ($hosts as $host) {
                $this->cooldown->upsertTombstone($c, $host, $tenantUuid, $retainedUntil);
            }

            // Explicit deletes — NOT the tenant_domains → tenants FK cascade, which would drop the
            // rows without writing tombstones.
            db($c)->table('tenant_domains')->where('tenant_uuid', $tenantUuid)->forceDelete();
            db($c)->table('tenant_memberships')->where('tenant_uuid', $tenantUuid)->forceDelete();

            $deleted = db($c)->table('tenants')
                ->where('uuid', $tenantUuid)
                ->where('status', 'purging')
                ->forceDelete();
            if ($deleted === 0) {
                throw new TenantLifecycleException('purgeTenantRecord lost the status race.');
            }

            db($c)->afterCommit(static function () use ($c, $hosts, $tenantUuid, $retainedUntil): void {
                $events = app($c, EventService::class);
                foreach ($hosts as $host) {
                    $events->dispatch(new HostReleased($host, $tenantUuid, $retainedUntil));
                }
            });
        });
    }
```

`forceDelete()` is mandatory here. Glueful's ordinary builder `delete()` detects the
`tenants.deleted_at` column and would only soft-delete the row again.

- [ ] **Step 4: Add the atomic override-claim entry on the domain bridge**

In `ContractTenantDomainAdministration.php`, import `Models\Tenant` and add:

```php
    public function overrideCooldownAndClaim(
        ApplicationContext $c,
        string $tenantUuid,
        string $host
    ): array
    {
        return db($c)->transaction(function () use ($c, $tenantUuid, $host): array {
            $tenant = Tenant::query($c)
                ->where('uuid', $tenantUuid)
                ->where('status', 'active')
                ->first();
            if ($tenant === null) {
                throw new \InvalidArgumentException('Override target must be an active tenant.');
            }
            $normalized = $this->registeredHost($c, $host);
            $this->cooldown->lockHost($c, $normalized);
            $this->cooldown->consume($c, $normalized);
            $token = bin2hex(random_bytes(32));
            $domain = TenantDomain::create($c, [
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $tenantUuid,
                'host' => $normalized,
                'verification_token' => $token,
            ]);

            return ['uuid' => $domain->uuid, 'token' => $token];
        });
    }
```

This implements neutral `overrideCooldownAndClaim()` from Task 6. Thallo never resolves the
concrete bridge. Canonical-superuser authorization, reason capture, and audit live in Task 15b.
Add a concurrent claim test proving no third tenant can enter between tombstone consumption and
pending-domain insertion.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=PurgeTenantRecordTest`
Expected: PASS (3+ tests, including atomic override-claim concurrency).

- [ ] **Step 6: Run the whole tenancy suite to confirm no regressions**

Run: `vendor/bin/phpunit tests/Integration/Tenancy/`
Expected: PASS (all lifecycle + cooldown + purge tests).

- [ ] **Step 7: Stage (HOLD)**

```bash
git add vendor/glueful/tenancy/src/Bridge/ContractTenantAdministration.php \
        vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php \
        tests/Integration/Tenancy/PurgeTenantRecordTest.php
# HOLD.
```

---

### Task 9: Durable purge-run ledger (Thallo migration + repository)

**Files:**
- Create: `packages/thallo-tenancy/migrations/002_CreateTenantPurgeRunsTable.php`
- Create: `packages/thallo-tenancy/src/Purge/PurgeRunRepository.php`
- Modify: the pack ServiceProvider — register `PurgeRunRepository`.
- Test: `tests/Integration/Tenancy/PurgePipelineTest.php`

**Interfaces:**
- Produces:
  - Table `thallo_tenant_purge_runs`: `id` PK, `uuid` (12, unique), `tenant_uuid` (12,
    indexed — NO FK, survives tenant deletion), `requested_by_uuid` nullable (NO FK), `status`
    (`requested|queued|dispatch_failed|running|completed|failed`), `lease_expires_at` nullable,
    `lease_owner` nullable, `attempts`, `plan` (JSONB — handler IDs + phase checkpoints), `artifacts` (JSONB — captured
    media keys), failure fields, timestamps. A PostgreSQL partial unique index permits only one
    non-completed run per tenant.
  - `PurgeRunRepository::create(ApplicationContext $c, string $tenantUuid, ?string $actorUuid): string`
    (returns run uuid, status `requested`).
  - `::claimDispatch(...): bool` — CAS `requested|dispatch_failed|failed` or expired
    `queued|running → queued` before pushing.
  - `::claimRun(..., string $workerUuid): bool` — CAS `queued|failed` or expired
    `running → running`, sets `lease_owner`, bumps attempts, and establishes the 15-minute lease.
  - `::renewLease(..., string $workerUuid): void` — owner-checked extension after each checkpoint.
  - `::markDispatchFailed(...): void`; `::markCompleted(..., string $workerUuid): bool` and
    `::markFailed(..., string $workerUuid, string $handler, string $phase): bool` are owner-CASed
    so only the current lease owner emits terminal audit.
  - `::checkpoint(..., string $workerUuid, string $handlerId, string $phase): void` and
    `::putArtifacts(..., string $workerUuid, string $handlerId, array $artifacts): void` merge only
    while that worker still owns the locked running lease.
  - `::find(...)` / `::findByTenant(...)` / `::recoverable(...)` (requested, dispatch_failed,
    failed, or expired queued/running lease). Checkpoint/artifact updates lock the run row before
    read-modify-write so state cannot be lost.
  - Structural test: `thallo_tenant_purge_runs` is not tenant-owned/stamped; it must survive the
    tenant row and remain readable from system recovery.

Add a lease-theft regression: expire worker A's lease, claim with worker B, then prove A cannot
checkpoint, renew, fail, or complete the run while B can. This locks the crash-recovery ownership
model before the destructive job is written.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Tenancy/PurgePipelineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Purge\PurgeRunRepository;

final class PurgePipelineTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec("DELETE FROM thallo_tenant_purge_runs WHERE tenant_uuid LIKE 'ppt%'");
        parent::tearDown();
    }

    private function runs(): PurgeRunRepository
    {
        return $this->container()->get(PurgeRunRepository::class);
    }

    public function testCreateAndCheckpoint(): void
    {
        $c = $this->appContext();
        $runs = $this->runs();
        $run = $runs->create($c, 'pptAAAAAAAAA', 'actorAAAAAAA');

        $found = $runs->find($c, $run);
        self::assertSame('requested', $found['status']);

        self::assertTrue($runs->claimDispatch($c, $run));
        self::assertTrue($runs->claimRun($c, $run, 'workerCHECK1'));
        $runs->checkpoint($c, $run, 'workerCHECK1', 'thallo.tables', 'prepare');
        $runs->putArtifacts($c, $run, 'workerCHECK1', 'thallo.media', ['keys' => ['a/b.jpg']]);
        $found = $runs->find($c, $run);
        $plan = json_decode((string) $found['plan'], true);
        $artifacts = json_decode((string) $found['artifacts'], true);
        self::assertSame('prepare', $plan['thallo.tables'] ?? null);
        self::assertSame(['a/b.jpg'], $artifacts['thallo.media']['keys'] ?? null);
    }

    public function testRecoverableListsRequestedAndDispatchFailed(): void
    {
        $c = $this->appContext();
        $runs = $this->runs();
        $a = $runs->create($c, 'pptBBBBBBBBB', null);
        $b = $runs->create($c, 'pptCCCCCCCCC', null);
        $runs->markDispatchFailed($c, $b);
        self::assertTrue($runs->claimDispatch($c, $a));
        self::assertTrue($runs->claimRun($c, $a, 'workerAAAAAA'));
        self::assertTrue($runs->markCompleted($c, $a, 'workerAAAAAA'));

        $ids = array_column($runs->recoverable($c), 'uuid');
        self::assertContains($b, $ids);
        self::assertNotContains($a, $ids);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=PurgePipelineTest`
Expected: FAIL — table + repository absent.

- [ ] **Step 3: Create the migration**

Create `packages/thallo-tenancy/migrations/002_CreateTenantPurgeRunsTable.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * System-global purge-run ledger. Survives tenant-row deletion (tenant_uuid is a plain scalar,
 * no FK) so the final engine purge and post-hoc auditing can still reference the run. Never
 * tenant-scoped; never purged by its own handlers.
 */
final class CreateTenantPurgeRunsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('thallo_tenant_purge_runs')) {
            return;
        }

        $schema->createTable('thallo_tenant_purge_runs', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->string('requested_by_uuid', 12)->nullable();
            $table->string('status', 20)->default('requested');
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('lease_owner', 12)->nullable();
            $table->integer('attempts')->default(0);
            $table->json('plan')->nullable();
            $table->json('artifacts')->nullable();
            $table->string('failed_handler', 64)->nullable();
            $table->string('failed_phase', 16)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->unique('uuid');
            $table->index('tenant_uuid');
            $table->index('status');
        });
        $schema->getConnection()->getPDO()->exec(
            "CREATE UNIQUE INDEX uniq_active_tenant_purge_run "
            . "ON thallo_tenant_purge_runs (tenant_uuid) WHERE status <> 'completed'"
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_tenant_purge_runs');
    }

    public function getDescription(): string
    {
        return 'Creates the durable system-global tenant purge-run ledger.';
    }
}
```

The verified schema-builder API is `json()`; the PostgreSQL driver emits JSONB, matching the
existing content and form migrations.

- [ ] **Step 4: Create the repository**

Create `packages/thallo-tenancy/src/Purge/PurgeRunRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

/** Durable read/write access to the thallo_tenant_purge_runs ledger. */
final class PurgeRunRepository
{
    public function create(ApplicationContext $c, string $tenantUuid, ?string $actorUuid): string
    {
        $uuid = Utils::generateNanoID(12);
        $now = gmdate('Y-m-d H:i:s');
        db($c)->table('thallo_tenant_purge_runs')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenantUuid,
            'requested_by_uuid' => $actorUuid,
            'status' => 'requested',
            'plan' => json_encode([]),
            'artifacts' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /** @return array<string,mixed>|null */
    public function find(ApplicationContext $c, string $runUuid): ?array
    {
        $stmt = db($c)->getPDO()->prepare('SELECT * FROM thallo_tenant_purge_runs WHERE uuid = ?');
        $stmt->execute([$runUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByTenant(ApplicationContext $c, string $tenantUuid): ?array
    {
        $stmt = db($c)->getPDO()->prepare(
            "SELECT * FROM thallo_tenant_purge_runs WHERE tenant_uuid=? AND status <> 'completed' "
            . 'ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$tenantUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function recoverable(ApplicationContext $c): array
    {
        $stmt = db($c)->getPDO()->prepare(
            "SELECT * FROM thallo_tenant_purge_runs "
            . "WHERE status IN ('requested','dispatch_failed','failed') "
            . "OR (status IN ('queued','running') AND lease_expires_at < CURRENT_TIMESTAMP) "
            . 'ORDER BY created_at ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function markDispatchFailed(ApplicationContext $c, string $runUuid): void
    {
        db($c)->table('thallo_tenant_purge_runs')
            ->where('uuid', $runUuid)
            ->where('status', 'queued')
            ->update([
                'status' => 'dispatch_failed',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function claimDispatch(ApplicationContext $c, string $runUuid): bool
    {
        $stmt = db($c)->getPDO()->prepare(
            "UPDATE thallo_tenant_purge_runs SET status='queued', lease_owner=NULL, "
            . "lease_expires_at=CURRENT_TIMESTAMP + INTERVAL '5 minutes', "
            . "updated_at=CURRENT_TIMESTAMP WHERE uuid=? AND ("
            . "status IN ('requested','dispatch_failed','failed') OR "
            . "(status IN ('queued','running') AND lease_expires_at < CURRENT_TIMESTAMP)"
            . ') RETURNING uuid'
        );
        $stmt->execute([$runUuid]);

        return $stmt->fetchColumn() !== false;
    }

    public function claimRun(ApplicationContext $c, string $runUuid, string $workerUuid): bool
    {
        $stmt = db($c)->getPDO()->prepare(
            "UPDATE thallo_tenant_purge_runs SET status='running', attempts=attempts+1, "
            . "lease_owner=?, "
            . "lease_expires_at=CURRENT_TIMESTAMP + INTERVAL '15 minutes', updated_at=CURRENT_TIMESTAMP "
            . "WHERE uuid=? AND (status IN ('queued','failed') "
            . "OR (status='running' AND lease_expires_at < CURRENT_TIMESTAMP)) RETURNING uuid"
        );
        $stmt->execute([$workerUuid, $runUuid]);
        return $stmt->fetchColumn() !== false;
    }

    public function renewLease(ApplicationContext $c, string $runUuid, string $workerUuid): void
    {
        db($c)->table('thallo_tenant_purge_runs')
            ->where('uuid', $runUuid)
            ->where('status', 'running')
            ->where('lease_owner', $workerUuid)
            ->update([
                'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 900),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function markCompleted(ApplicationContext $c, string $runUuid, string $workerUuid): bool
    {
        return db($c)->table('thallo_tenant_purge_runs')->where('uuid', $runUuid)
            ->where('status', 'running')->where('lease_owner', $workerUuid)->update([
            'lease_owner' => null,
            'status' => 'completed',
            'lease_expires_at' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]) === 1;
    }

    public function markFailed(
        ApplicationContext $c,
        string $runUuid,
        string $workerUuid,
        string $handler,
        string $phase
    ): bool
    {
        return db($c)->table('thallo_tenant_purge_runs')->where('uuid', $runUuid)
            ->where('status', 'running')->where('lease_owner', $workerUuid)->update([
            'lease_owner' => null,
            'status' => 'failed',
            'failed_handler' => $handler,
            'failed_phase' => $phase,
            'lease_expires_at' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]) === 1;
    }

    /** Merge a handler's completed phase into the plan JSONB (checkpoint for idempotent resume). */
    public function checkpoint(
        ApplicationContext $c,
        string $runUuid,
        string $workerUuid,
        string $handlerId,
        string $phase
    ): void
    {
        db($c)->transaction(function () use ($c, $runUuid, $workerUuid, $handlerId, $phase): void {
            $run = $this->findForUpdate($c, $runUuid, $workerUuid);
            $plan = is_string($run['plan'] ?? null) ? (array) json_decode($run['plan'], true) : [];
            $plan[$handlerId] = $phase;
            db($c)->table('thallo_tenant_purge_runs')->where('uuid', $runUuid)->update([
                'plan' => json_encode($plan), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });
    }

    /** @param array<string,mixed> $artifacts */
    public function putArtifacts(
        ApplicationContext $c,
        string $runUuid,
        string $workerUuid,
        string $handlerId,
        array $artifacts
    ): void
    {
        db($c)->transaction(function () use ($c, $runUuid, $workerUuid, $handlerId, $artifacts): void {
            $run = $this->findForUpdate($c, $runUuid, $workerUuid);
            $all = is_string($run['artifacts'] ?? null) ? (array) json_decode($run['artifacts'], true) : [];
            $all[$handlerId] = $artifacts;
            db($c)->table('thallo_tenant_purge_runs')->where('uuid', $runUuid)->update([
                'artifacts' => json_encode($all), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function findForUpdate(ApplicationContext $c, string $runUuid, string $workerUuid): array
    {
        $stmt = db($c)->getPDO()->prepare(
            "SELECT * FROM thallo_tenant_purge_runs "
            . "WHERE uuid=? AND status='running' AND lease_owner=? FOR UPDATE"
        );
        $stmt->execute([$runUuid, $workerUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Purge run was not found.');
        }
        return $row;
    }
}
```

- [ ] **Step 5: Register the repository + migration**

In the pack ServiceProvider `services()` array, add:

```php
            \Thallo\Tenancy\Purge\PurgeRunRepository::class => [
                'class' => \Thallo\Tenancy\Purge\PurgeRunRepository::class,
                'shared' => true,
            ],
```

The pack's `loadMigrationsFrom(__DIR__ . '/../migrations', …)` already registers the whole `migrations/` directory, so `002_…` is picked up automatically.

- [ ] **Step 6: Migrate + run the test**

```bash
composer test:migrate
vendor/bin/phpunit --filter=PurgePipelineTest
```
Expected: PASS (2 tests).

- [ ] **Step 7: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/migrations/002_CreateTenantPurgeRunsTable.php \
        packages/thallo-tenancy/src/Purge/PurgeRunRepository.php \
        packages/thallo-tenancy/src/TenancyServiceProvider.php \
        tests/Integration/Tenancy/PurgePipelineTest.php
# HOLD.
```

---

### Task 10: `PurgeHandler` interface + `PurgeResourceRegistry` (topological order)

**Files:**
- Create: `packages/thallo-tenancy/src/Purge/PurgeHandler.php`
- Create: `packages/thallo-tenancy/src/Purge/PurgeResourceRegistry.php`
- Test: `tests/Unit/Tenancy/PurgeResourceRegistryTest.php`

**Interfaces:**
- Produces:
  - `interface PurgeHandler`: `id(): string`; `dependsOn(): array` (list of handler IDs whose rows must be gone first); `prepare(ApplicationContext $c, string $tenantUuid): array` (returns artifacts to persist); `purge(ApplicationContext $c, string $tenantUuid, array $artifacts): void`; `verify(ApplicationContext $c, string $tenantUuid): bool`.
  - `PurgeResourceRegistry::register(PurgeHandler $h): void`; `::ordered(): array` (topologically sorted handlers, dependencies first); `::all(): array`.
- Consumes: nothing external.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Tenancy/PurgeResourceRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\TestCase;
use Thallo\Tenancy\Purge\PurgeHandler;
use Thallo\Tenancy\Purge\PurgeResourceRegistry;

final class PurgeResourceRegistryTest extends TestCase
{
    public function testOrderedPlacesDependenciesFirst(): void
    {
        $registry = new PurgeResourceRegistry();
        // tables depends on media → media must come first.
        $registry->register($this->handler('thallo.tables', ['thallo.media']));
        $registry->register($this->handler('thallo.media', []));
        $registry->register($this->handler('thallo.cache', []));

        $ids = array_map(static fn (PurgeHandler $h): string => $h->id(), $registry->ordered());
        self::assertLessThan(array_search('thallo.tables', $ids, true), array_search('thallo.media', $ids, true));
    }

    public function testCycleThrows(): void
    {
        $registry = new PurgeResourceRegistry();
        $registry->register($this->handler('a', ['b']));
        $registry->register($this->handler('b', ['a']));

        $this->expectException(\RuntimeException::class);
        $registry->ordered();
    }

    /** @param list<string> $deps */
    private function handler(string $id, array $deps): PurgeHandler
    {
        return new class ($id, $deps) implements PurgeHandler {
            /** @param list<string> $deps */
            public function __construct(private string $id, private array $deps)
            {
            }
            public function id(): string
            {
                return $this->id;
            }
            /** @return list<string> */
            public function dependsOn(): array
            {
                return $this->deps;
            }
            public function prepare(ApplicationContext $c, string $tenantUuid): array
            {
                return [];
            }
            public function purge(ApplicationContext $c, string $tenantUuid, array $artifacts): void
            {
            }
            public function verify(ApplicationContext $c, string $tenantUuid): bool
            {
                return true;
            }
        };
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=PurgeResourceRegistryTest`
Expected: FAIL — interface + registry absent.

- [ ] **Step 3: Create the interface**

Create `packages/thallo-tenancy/src/Purge/PurgeHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

use Glueful\Bootstrap\ApplicationContext;

/**
 * One destructive owner of a product-data resource. Exactly one handler destroys any given table.
 * Phases run under the pipeline's global barrier: every handler prepares (and checkpoints) before
 * any handler purges.
 */
interface PurgeHandler
{
    /** Stable string id, e.g. 'thallo.media'. */
    public function id(): string;

    /**
     * IDs of handlers whose rows must be gone before this one purges (topological order).
     *
     * @return list<string>
     */
    public function dependsOn(): array;

    /**
     * Capture anything destruction needs (e.g. media object keys) BEFORE any purge runs. The
     * returned array is persisted to the run's artifacts and passed back to purge().
     *
     * @return array<string,mixed>
     */
    public function prepare(ApplicationContext $c, string $tenantUuid): array;

    /** @param array<string,mixed> $artifacts The artifacts prepare() returned. */
    public function purge(ApplicationContext $c, string $tenantUuid, array $artifacts): void;

    /** True once the resource is fully gone. Gates checkpointing and the final engine purge. */
    public function verify(ApplicationContext $c, string $tenantUuid): bool;
}
```

- [ ] **Step 4: Create the registry**

Create `packages/thallo-tenancy/src/Purge/PurgeResourceRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

/** Holds purge handlers and orders them so every dependency purges before its dependents. */
final class PurgeResourceRegistry
{
    /** @var array<string,PurgeHandler> */
    private array $handlers = [];

    public function register(PurgeHandler $handler): void
    {
        $this->handlers[$handler->id()] = $handler;
    }

    /** @return list<PurgeHandler> */
    public function all(): array
    {
        return array_values($this->handlers);
    }

    /**
     * Handlers in dependency order (dependencies first). Deterministic: ties break by id.
     *
     * @return list<PurgeHandler>
     */
    public function ordered(): array
    {
        $sorted = [];
        $state = []; // id => 'visiting'|'done'

        $visit = function (string $id) use (&$visit, &$sorted, &$state): void {
            if (($state[$id] ?? null) === 'done') {
                return;
            }
            if (($state[$id] ?? null) === 'visiting') {
                throw new \RuntimeException("Cyclic purge-handler dependency at '{$id}'.");
            }
            if (!isset($this->handlers[$id])) {
                throw new \RuntimeException("Unknown purge-handler dependency '{$id}'.");
            }
            $state[$id] = 'visiting';
            $deps = $this->handlers[$id]->dependsOn();
            sort($deps, SORT_STRING);
            foreach ($deps as $dep) {
                $visit($dep);
            }
            $state[$id] = 'done';
            $sorted[] = $this->handlers[$id];
        };

        $ids = array_keys($this->handlers);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $visit($id);
        }

        return $sorted;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=PurgeResourceRegistryTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Purge/PurgeHandler.php \
        packages/thallo-tenancy/src/Purge/PurgeResourceRegistry.php \
        tests/Unit/Tenancy/PurgeResourceRegistryTest.php
# HOLD.
```

---

### Task 11: `TablesPurgeHandler` — generic tenant-owned table deletion

**Files:**
- Create: `packages/thallo-tenancy/src/Purge/Handlers/TablesPurgeHandler.php`
- Test: `tests/Integration/Tenancy/PurgePipelineTest.php` (append)

**Interfaces:**
- Produces: `TablesPurgeHandler` — id `thallo.tables`, `dependsOn(): ['thallo.media']`, deletes `WHERE tenant_uuid = ?` across `ThalloTenantTables::tableNames()` **minus** the media-owned tables (`media_assets`, `media_meta`, `media_usage`); `verify()` true when no rows remain in the generic subset. Only deletes tables that exist (retrofit may skip absent pack tables).
- Consumes: `ThalloTenantTables::tableNames()` (existing).

- [ ] **Step 1: Write the failing test**

Append to `tests/Integration/Tenancy/PurgePipelineTest.php`:

```php
    public function testTablesHandlerDeletesTenantRowsExcludingMedia(): void
    {
        $c = $this->appContext();
        $pdo = $this->connection()->getPDO();
        $tenant = 'pptTABLES001';
        // Seed one fully-specified generic row and one valid media ownership row.
        $pdo->prepare(
            "INSERT INTO content_types "
            . "(uuid, tenant_uuid, slug, name, schema, status, created_at) "
            . "VALUES (?, ?, ?, 'Purge fixture', '{}'::jsonb, 'active', CURRENT_TIMESTAMP)"
        )->execute(['ctPURGE00001', $tenant, 'purge-fixture']);
        $pdo->prepare(
            "INSERT INTO blobs (uuid, name, mime_type, size, url, storage_type, created_by, created_at) "
            . "VALUES ('blobXXXXXXXX', 'x', 'text/plain', 1, 'purge/x', 'uploads', "
            . "'sysXXXXXXXXX', CURRENT_TIMESTAMP)"
        )->execute();
        $pdo->prepare("INSERT INTO media_assets (tenant_uuid, blob_uuid, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)")
            ->execute([$tenant, 'blobXXXXXXXX']);

        $handler = $this->container()->get(\Thallo\Tenancy\Purge\Handlers\TablesPurgeHandler::class);
        $artifacts = $handler->prepare($c, $tenant);
        $handler->purge($c, $tenant, $artifacts);

        self::assertTrue($handler->verify($c, $tenant));
        // Generic table cleared…
        self::assertFalse($pdo->query("SELECT 1 FROM content_types WHERE tenant_uuid = " . $pdo->quote($tenant))->fetchColumn());
        // …but media rows are NOT this handler's responsibility (owned by thallo.media).
        self::assertNotFalse($pdo->query("SELECT 1 FROM media_assets WHERE tenant_uuid = " . $pdo->quote($tenant))->fetchColumn());

        // Cleanup.
        $pdo->prepare('DELETE FROM media_assets WHERE tenant_uuid = ?')->execute([$tenant]);
        $pdo->exec("DELETE FROM blobs WHERE uuid = 'blobXXXXXXXX'");
    }
```

These fixtures use the verified `content_types`, `blobs`, and `media_assets` required columns; no
execution-time fixture discovery is left.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=testTablesHandlerDeletesTenantRowsExcludingMedia`
Expected: FAIL — handler absent.

- [ ] **Step 3: Create the handler**

Create `packages/thallo-tenancy/src/Purge/Handlers/TablesPurgeHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge\Handlers;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Purge\PurgeHandler;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Deletes tenant-owned rows across the generic subset of ThalloTenantTables — every table EXCEPT
 * those a specialized handler owns. Raw deletes must supply the tenant predicate explicitly (the
 * tenant scope does not auto-apply to raw db() writes).
 */
final class TablesPurgeHandler implements PurgeHandler
{
    /** Tables destroyed by thallo.media, excluded here so each table has one destructive owner. */
    private const MEDIA_OWNED = ['media_assets', 'media_meta', 'media_usage'];

    public function id(): string
    {
        return 'thallo.tables';
    }

    /** Media rows must be gone first (media GC needs its metadata before tables vanish). */
    public function dependsOn(): array
    {
        return ['thallo.media'];
    }

    public function prepare(ApplicationContext $c, string $tenantUuid): array
    {
        // Current tenant-owned tables have no FKs between generic targets. Prove that remains true
        // before destruction; a future FK must be represented by a specialized handler/order rather
        // than relying on registry insertion order.
        $targets = $this->targetTables();
        $marks = implode(', ', array_fill(0, count($targets), '?'));
        $stmt = db($c)->getPDO()->prepare(
            'SELECT child.relname AS child, parent.relname AS parent '
            . 'FROM pg_constraint c '
            . 'JOIN pg_class child ON child.oid = c.conrelid '
            . 'JOIN pg_class parent ON parent.oid = c.confrelid '
            . "WHERE c.contype = 'f' AND child.relname IN ({$marks}) "
            . "AND parent.relname IN ({$marks})"
        );
        $stmt->execute([...$targets, ...$targets]);
        $edges = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($edges !== []) {
            throw new \RuntimeException('Generic tenant-table purge has unresolved FK dependencies.');
        }
        return ['tables' => $targets];
    }

    public function purge(ApplicationContext $c, string $tenantUuid, array $artifacts): void
    {
        foreach ($this->targetTables() as $table) {
            if (!$this->tableExists($c, $table)) {
                continue; // Pack table not installed — retrofit skipped it.
            }
            db($c)->table($table)->where('tenant_uuid', $tenantUuid)->forceDelete();
        }
    }

    public function verify(ApplicationContext $c, string $tenantUuid): bool
    {
        foreach ($this->targetTables() as $table) {
            if (!$this->tableExists($c, $table)) {
                continue;
            }
            $stmt = db($c)->getPDO()->prepare("SELECT 1 FROM {$table} WHERE tenant_uuid = ? LIMIT 1");
            $stmt->execute([$tenantUuid]);
            if ($stmt->fetchColumn() !== false) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function targetTables(): array
    {
        return array_values(array_diff(ThalloTenantTables::tableNames(), self::MEDIA_OWNED));
    }

    private function tableExists(ApplicationContext $c, string $table): bool
    {
        $stmt = db($c)->getPDO()->prepare('SELECT to_regclass(?)');
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== null;
    }
}
```

Add a test asserting the real generic target set has no internal FK edges. Add a second test using
temporary child/parent tables (or a focused order helper unit test) proving an introduced dependency
fails in `prepare` before any tenant row is deleted. This makes the current no-FK fact executable and
forces future schema changes to declare a safe destructive owner/order.

- [ ] **Step 4: Register the handler**

Add to the pack ServiceProvider `services()`:

```php
            \Thallo\Tenancy\Purge\Handlers\TablesPurgeHandler::class => [
                'class' => \Thallo\Tenancy\Purge\Handlers\TablesPurgeHandler::class,
                'shared' => true,
            ],
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=testTablesHandlerDeletesTenantRowsExcludingMedia`
Expected: PASS.

- [ ] **Step 6: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Purge/Handlers/TablesPurgeHandler.php \
        packages/thallo-tenancy/src/TenancyServiceProvider.php \
        tests/Integration/Tenancy/PurgePipelineTest.php
# HOLD.
```

---

### Task 12: `MediaPurgeHandler` (+ `CachePurgeHandler`, `CollectionsPurgeHandler`)

**Files:**
- Create: `packages/thallo-tenancy/src/Purge/Handlers/MediaPurgeHandler.php`
- Create: `packages/thallo-tenancy/src/Purge/Handlers/CachePurgeHandler.php`
- Create: `packages/thallo-tenancy/src/Purge/Handlers/CollectionsPurgeHandler.php`
- Test: `tests/Integration/Tenancy/PurgePipelineTest.php` (append)

**Interfaces:**
- Produces:
  - `MediaPurgeHandler` — id `thallo.media`, `dependsOn(): []`. `prepare()` captures `{disk, path}` for every `media_assets`→`blobs` row (path = `blobs.url`, disk = `blobs.storage_type` or `uploads.disk`). `purge()` deletes storage objects via `StorageManager::disk($disk)->delete($path)`; **only after all required object deletions succeed** deletes `blobs` + `media_assets`/`media_meta`/`media_usage` rows. `verify()` true when the media rows are gone.
  - `CachePurgeHandler` — id `thallo.cache`, invalidate tenant cache segment (best-effort).
  - `CollectionsPurgeHandler` — id `thallo.collections`, registered no-op (guarded) until collections tenancy lands.
- Consumes: `\Glueful\Storage\StorageManager` (existing), `media_assets`/`blobs` schema (existing).

- [ ] **Step 1: Write the failing test**

Append to `PurgePipelineTest.php` (uses a fake disk path that `delete()` tolerates for a missing object — Flysystem `delete` is idempotent for absent files):

```php
    public function testMediaHandlerCapturesKeysThenDeletesRows(): void
    {
        $c = $this->appContext();
        $pdo = $this->connection()->getPDO();
        $tenant = 'pptMEDIA0001';
        $blob = 'blobMEDIA001';
        $pdo->prepare(
            "INSERT INTO blobs (uuid, name, mime_type, size, url, storage_type, created_by, created_at) " .
            "VALUES (?, 'x', 'image/jpeg', 1, ?, 'uploads', 'sysXXXXXXXXX', CURRENT_TIMESTAMP)"
        )->execute([$blob, 'tenants/' . $tenant . '/x.jpg']);
        $pdo->prepare("INSERT INTO media_assets (tenant_uuid, blob_uuid, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)")
            ->execute([$tenant, $blob]);

        $handler = $this->container()->get(\Thallo\Tenancy\Purge\Handlers\MediaPurgeHandler::class);
        $artifacts = $handler->prepare($c, $tenant);
        self::assertSame('tenants/' . $tenant . '/x.jpg', $artifacts['objects'][0]['path'] ?? null);

        $handler->purge($c, $tenant, $artifacts);
        self::assertTrue($handler->verify($c, $tenant));
        self::assertFalse($pdo->query("SELECT 1 FROM media_assets WHERE tenant_uuid = " . $pdo->quote($tenant))->fetchColumn());
        self::assertFalse($pdo->query("SELECT 1 FROM blobs WHERE uuid = " . $pdo->quote($blob))->fetchColumn());
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=testMediaHandlerCapturesKeysThenDeletesRows`
Expected: FAIL — handler absent.

- [ ] **Step 3: Create `MediaPurgeHandler`**

Create `packages/thallo-tenancy/src/Purge/Handlers/MediaPurgeHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge\Handlers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Storage\StorageManager;
use Thallo\Tenancy\Purge\PurgeHandler;

/**
 * Sole destructive owner of the media tables. prepare() captures every blob's storage {disk,path}
 * BEFORE deletion (so a failed object delete is retryable from the persisted artifacts). purge()
 * deletes storage objects first and only removes DB rows once every required object delete
 * succeeds — media rows survive a partial storage failure so the job can retry.
 */
final class MediaPurgeHandler implements PurgeHandler
{
    public function __construct(private readonly StorageManager $storage)
    {
    }

    public function id(): string
    {
        return 'thallo.media';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function prepare(ApplicationContext $c, string $tenantUuid): array
    {
        $default = (string) config($c, 'uploads.disk', 'uploads');
        $stmt = db($c)->getPDO()->prepare(
            'SELECT b.uuid AS blob_uuid, b.url AS path, b.storage_type AS disk '
            . 'FROM media_assets ma JOIN blobs b ON b.uuid = ma.blob_uuid '
            . 'WHERE ma.tenant_uuid = ?'
        );
        $stmt->execute([$tenantUuid]);
        $objects = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $objects[] = [
                'blob_uuid' => (string) $row['blob_uuid'],
                'path' => (string) $row['path'],
                'disk' => (string) $row['disk'] !== '' ? (string) $row['disk'] : $default,
            ];
        }

        return ['objects' => $objects];
    }

    public function purge(ApplicationContext $c, string $tenantUuid, array $artifacts): void
    {
        /** @var list<array{blob_uuid:string,path:string,disk:string}> $objects */
        $objects = $artifacts['objects'] ?? [];

        // Delete every storage object first. If any required delete throws, bail BEFORE removing
        // rows — the run keeps the artifacts and retries.
        foreach ($objects as $object) {
            if ($object['path'] === '') {
                continue;
            }
            $this->storage->disk($object['disk'])->delete($object['path']);
        }

        // All object deletes succeeded — remove DB rows in one transaction.
        db($c)->transaction(function () use ($c, $tenantUuid, $objects): void {
            $blobUuids = array_values(array_filter(array_map(
                static fn (array $o): string => $o['blob_uuid'],
                $objects
            )));
            db($c)->table('media_usage')->where('tenant_uuid', $tenantUuid)->forceDelete();
            db($c)->table('media_meta')->where('tenant_uuid', $tenantUuid)->forceDelete();
            db($c)->table('media_assets')->where('tenant_uuid', $tenantUuid)->forceDelete();
            foreach ($blobUuids as $blobUuid) {
                db($c)->table('blobs')->where('uuid', $blobUuid)->forceDelete();
            }
        });
    }

    public function verify(ApplicationContext $c, string $tenantUuid): bool
    {
        foreach (['media_assets', 'media_meta', 'media_usage'] as $table) {
            $stmt = db($c)->getPDO()->prepare(
                "SELECT 1 FROM {$table} WHERE tenant_uuid = ? LIMIT 1"
            );
            $stmt->execute([$tenantUuid]);
            if ($stmt->fetchColumn() !== false) {
                return false;
            }
        }

        return true;
    }
}
```

Migration 006 verifies all three sidecar tables carry `tenant_uuid`; `media_assets.blob_uuid` is the
ownership root and has the FK to framework `blobs.uuid`.

- [ ] **Step 4: Create `CachePurgeHandler` and `CollectionsPurgeHandler`**

Create `packages/thallo-tenancy/src/Purge/Handlers/CachePurgeHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge\Handlers;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Cache\TenantHostCachePurger;
use Thallo\Tenancy\Purge\PurgeHandler;

/** Invalidates the tenant's cache segment. Best-effort: cache is derived, not source of truth. */
final class CachePurgeHandler implements PurgeHandler
{
    public function __construct(private readonly TenantHostCachePurger $cache)
    {
    }

    public function id(): string
    {
        return 'thallo.cache';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function prepare(ApplicationContext $c, string $tenantUuid): array
    {
        return [];
    }

    public function purge(ApplicationContext $c, string $tenantUuid, array $artifacts): void
    {
        try {
            $this->cache->purgeForTenant($tenantUuid);
        } catch (\Throwable) {
            // Derived state only. The lifecycle audit records the purge completion separately.
        }
    }

    public function verify(ApplicationContext $c, string $tenantUuid): bool
    {
        return true; // Cache is best-effort; its absence is not a purge blocker.
    }
}
```

`TenantHostCachePurger::purgeForTenant()` is the verified existing API. Its segmented render and
SEO patterns are the same ones used on tenant-host transitions; no new cache abstraction is added.

Create `packages/thallo-tenancy/src/Purge/Handlers/CollectionsPurgeHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge\Handlers;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Purge\PurgeHandler;

/**
 * Placeholder owner for dynamic-collection tenancy (Bucket 2). Registered so the pipeline is
 * complete-by-construction; a no-op until per-tenant collections land. Guarded: it must never
 * touch the globally-physical collection tables while collections remain non-tenant-scoped.
 */
final class CollectionsPurgeHandler implements PurgeHandler
{
    public function id(): string
    {
        return 'thallo.collections';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function prepare(ApplicationContext $c, string $tenantUuid): array
    {
        return [];
    }

    public function purge(ApplicationContext $c, string $tenantUuid, array $artifacts): void
    {
        // No-op until collections become tenant-scoped (Bucket 2).
    }

    public function verify(ApplicationContext $c, string $tenantUuid): bool
    {
        return true;
    }
}
```

- [ ] **Step 5: Register all three handlers**

Add to the pack ServiceProvider `services()`:

```php
            \Thallo\Tenancy\Purge\Handlers\MediaPurgeHandler::class => [
                'class' => \Thallo\Tenancy\Purge\Handlers\MediaPurgeHandler::class,
                'shared' => true,
                'autowire' => true,
            ],
            \Thallo\Tenancy\Purge\Handlers\CachePurgeHandler::class => [
                'class' => \Thallo\Tenancy\Purge\Handlers\CachePurgeHandler::class,
                'shared' => true,
                'autowire' => true,
            ],
            \Thallo\Tenancy\Purge\Handlers\CollectionsPurgeHandler::class => [
                'class' => \Thallo\Tenancy\Purge\Handlers\CollectionsPurgeHandler::class,
                'shared' => true,
                'autowire' => true,
            ],
```

The verified provider convention is `class/shared/autowire`; no `@Class` argument syntax is used.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=testMediaHandlerCapturesKeysThenDeletesRows`
Expected: PASS.

- [ ] **Step 7: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Purge/Handlers/ \
        packages/thallo-tenancy/src/TenancyServiceProvider.php \
        tests/Integration/Tenancy/PurgePipelineTest.php
# HOLD.
```

---

### Task 13: `PurgeCoordinator` + `PurgeJob` (barrier, checkpoints, verify-gate)

**Files:**
- Create: `packages/thallo-tenancy/src/Purge/PurgeCoordinator.php`
- Create: `packages/thallo-tenancy/src/Purge/PurgeJob.php`
- Create: `packages/thallo-tenancy/src/Console/PurgeRecoveryCommand.php`
- Test: `tests/Integration/Tenancy/PurgePipelineTest.php` (append end-to-end)

**Interfaces:**
- Produces:
  - `PurgeCoordinator::request(ApplicationContext $c, string $tenantUuid, ?string $actorUuid): string`
    — in one transaction: create the run row (`requested`, actor retained) AND call `beginPurge()`;
    register dispatch through `Connection::afterCommit()` after the transaction body, guarded by
    `claimDispatch()`; nested callers therefore wait for the outermost commit, and an idempotent
    repeat request also repairs a dispatch-failed run.
  - `PurgeCoordinator::recover(ApplicationContext $c): int` — re-dispatch every `recoverable()` run; returns count.
  - `thallo:tenancy:purges:recover` invokes `recover()` and prints the dispatch count; safe to run
    repeatedly from operations cron.
  - `PurgeJob::handle()` — runs the global prepare barrier (all handlers `prepare` + checkpoint + persist artifacts), then `purge`+`verify` in dependency order (idempotent: skip a handler already checkpointed past a phase); on all-green calls engine `purgeTenantRecord()` and marks the run `completed`; on any failure marks `failed(handler, phase)` and leaves hosts reserved.
- Consumes: `PurgeResourceRegistry`, `PurgeRunRepository`,
  `TenantAdministration::beginPurge/purgeTenantRecord`, `TenantContextRunner`, `WriteBarrier`,
  `QueueManager`, `Job` base.

- [ ] **Step 1: Write the failing end-to-end test**

Append to `PurgePipelineTest.php` — drive the job directly (synchronously), bypassing the queue:

```php
    public function testPurgeJobRunsBarrierThenPurgesRecord(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration::class);
        // Keep the platform non-final.
        $keep = $admin->create($c, 'keepJ-' . strtolower(\Glueful\Helpers\Utils::generateNanoID(5)), 'K', \Glueful\Helpers\Utils::generateNanoID(12));
        $admin->markActive($c, $keep);
        $this->tenantsToClean[] = $keep;

        $t = $admin->create($c, 'goneJ-' . strtolower(\Glueful\Helpers\Utils::generateNanoID(5)), 'G', \Glueful\Helpers\Utils::generateNanoID(12));
        $admin->markActive($c, $t);
        $this->tenantsToClean[] = $t;

        // Seed a complete generic row.
        $this->connection()->getPDO()->prepare(
            "INSERT INTO content_types "
            . "(uuid, tenant_uuid, slug, name, schema, status, created_at) "
            . "VALUES (?, ?, ?, 'Job fixture', '{}'::jsonb, 'active', CURRENT_TIMESTAMP)"
        )->execute(['ctJOB0000001', $t, 'job-' . strtolower(substr($t, 0, 6))]);

        $admin->deleteTenant($c, $t);
        $runUuid = $this->container()->get(\Thallo\Tenancy\Purge\PurgeCoordinator::class)
            ->request($c, $t, 'actorAAAAAAA');

        // beginPurge happened in request().
        self::assertSame('purging', $this->tenantStatus($t));

        // Run the job synchronously.
        $job = new \Thallo\Tenancy\Purge\PurgeJob(['run' => $runUuid, 'tenant' => $t], $c);
        $job->handle();

        // Tenant record purged.
        self::assertNull($this->tenantStatus($t));
        $run = $this->container()->get(\Thallo\Tenancy\Purge\PurgeRunRepository::class)->find($c, $runUuid);
        self::assertSame('completed', $run['status']);
    }
```

Add helpers `tenantStatus(string $uuid): ?string` (raw `SELECT status FROM tenants WHERE uuid`) and a `$tenantsToClean` array with a `tearDown` that deletes tenants/entries/purge-runs. Merge with the class's existing teardown.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=testPurgeJobRunsBarrierThenPurgesRecord`
Expected: FAIL — coordinator + job absent.

- [ ] **Step 3: Create `PurgeCoordinator`**

Create `packages/thallo-tenancy/src/Purge/PurgeCoordinator.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Queue\QueueManager;

/**
 * Turns a purge request into durable work: creates the run row and claims beginPurge() atomically
 * on the same transaction, then dispatches the job after commit. A failed dispatch is recoverable
 * (the run stays requested/dispatch_failed and can be re-dispatched) so a workspace is never left
 * 'purging' without recoverable work.
 */
final class PurgeCoordinator
{
    public function __construct(
        private readonly TenantAdministration $tenants,
        private readonly PurgeRunRepository $runs,
        private readonly QueueManager $queue,
    ) {
    }

    public function request(ApplicationContext $c, string $tenantUuid, ?string $actorUuid): string
    {
        $runUuid = '';
        db($c)->transaction(function () use ($c, $tenantUuid, $actorUuid, &$runUuid): void {
            $lock = db($c)->getPDO()->prepare(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))'
            );
            $lock->execute(['thallo:tenant-purge:' . $tenantUuid]);
            $existing = $this->runs->findByTenant($c, $tenantUuid);
            if ($existing !== null) {
                $runUuid = (string) $existing['uuid'];
                return;
            }
            $runUuid = $this->runs->create($c, $tenantUuid, $actorUuid);
            $this->tenants->beginPurge($c, $tenantUuid); // deleted → purging, guarded

        });

        // Executes immediately when no outer transaction remains, or is promoted to the outermost
        // commit when request() participated in a caller transaction.
        db($c)->afterCommit(function () use ($c, $runUuid, $tenantUuid): void {
            $this->dispatch($c, $runUuid, $tenantUuid);
        });

        return $runUuid;
    }

    /** Re-dispatch every committed run still awaiting its job. Returns the count re-dispatched. */
    public function recover(ApplicationContext $c): int
    {
        $count = 0;
        foreach ($this->runs->recoverable($c) as $run) {
            $runUuid = (string) $run['uuid'];
            if (!$this->runs->claimDispatch($c, $runUuid)) {
                continue;
            }
            try {
                $this->queue->push(PurgeJob::class, ['run' => $runUuid, 'tenant' => $run['tenant_uuid']]);
                $count++;
            } catch (\Throwable) {
                $this->runs->markDispatchFailed($c, $runUuid);
            }
        }

        return $count;
    }

    private function dispatch(ApplicationContext $c, string $runUuid, string $tenantUuid): bool
    {
        if (!$this->runs->claimDispatch($c, $runUuid)) {
            return false;
        }
        try {
            $this->queue->push(PurgeJob::class, ['run' => $runUuid, 'tenant' => $tenantUuid]);
            return true;
        } catch (\Throwable) {
            $this->runs->markDispatchFailed($c, $runUuid);
            return false;
        }
    }
}
```

- [ ] **Step 4: Create `PurgeJob`**

Create `packages/thallo-tenancy/src/Purge/PurgeJob.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Queue\Job;

/**
 * Executes the product-data purge pipeline for one tenant, then calls the engine's final record
 * purge. Global prepare barrier: every handler prepares + persists artifacts before any purge.
 * Idempotent via per-handler phase checkpoints; retryable; verify-green gates purgeTenantRecord().
 */
final class PurgeJob extends Job
{
    public function handle(): void
    {
        $c = $this->context;
        if ($c === null) {
            throw new \RuntimeException('PurgeJob requires an application context.');
        }
        $data = $this->getData();
        $runUuid = (string) ($data['run'] ?? '');
        $tenantUuid = (string) ($data['tenant'] ?? '');
        $workerUuid = $this->getUuid();

        $registry = app($c, PurgeResourceRegistry::class);
        $runs = app($c, PurgeRunRepository::class);
        $tenants = app($c, TenantAdministration::class);
        $tenantContexts = app($c, \Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class);
        $writes = app($c, \Thallo\Contracts\Tenancy\WriteBarrier::class);

        if ($runUuid === '' || $tenantUuid === '') {
            throw new \InvalidArgumentException('PurgeJob requires run and tenant identifiers.');
        }
        $writes->runWritable(function () use (
            $c, $runUuid, $tenantUuid, $workerUuid, $registry, $runs, $tenants, $tenantContexts
        ): void {
            if (!$runs->claimRun($c, $runUuid, $workerUuid)) {
                return; // Another worker owns a live lease, or this run already completed.
            }
            $tenantContexts->runAsSystem(function () use (
                $c, $runUuid, $tenantUuid, $workerUuid, $registry, $runs, $tenants
            ): void {
                $this->runPipeline(
                    $c, $runUuid, $tenantUuid, $workerUuid, $registry, $runs, $tenants
                );
            });
        });
    }

    private function runPipeline(
        \Glueful\Bootstrap\ApplicationContext $c,
        string $runUuid,
        string $tenantUuid,
        string $workerUuid,
        PurgeResourceRegistry $registry,
        PurgeRunRepository $runs,
        TenantAdministration $tenants,
    ): void {
        $ordered = $registry->ordered();

        // Phase 1 — global prepare barrier: capture + persist artifacts for EVERY handler before
        // any destruction.
        foreach ($ordered as $handler) {
            $checkpoint = $this->phaseOf($runs, $c, $runUuid, $handler->id());
            if ($checkpoint !== null) {
                continue; // already prepared (idempotent resume)
            }
            try {
                $artifacts = $handler->prepare($c, $tenantUuid);
                $runs->putArtifacts($c, $runUuid, $workerUuid, $handler->id(), $artifacts);
                $runs->checkpoint($c, $runUuid, $workerUuid, $handler->id(), 'prepare');
                $runs->renewLease($c, $runUuid, $workerUuid);
            } catch (\Throwable $e) {
                $runs->markFailed($c, $runUuid, $workerUuid, $handler->id(), 'prepare');
                throw $e;
            }
        }

        // Phase 2 — purge + verify in dependency order.
        foreach ($ordered as $handler) {
            if ($this->phaseOf($runs, $c, $runUuid, $handler->id()) === 'verify') {
                continue; // already done
            }
            $artifacts = $this->artifactsOf($runs, $c, $runUuid, $handler->id());
            try {
                $handler->purge($c, $tenantUuid, $artifacts);
                $runs->checkpoint($c, $runUuid, $workerUuid, $handler->id(), 'purge');
                if (!$handler->verify($c, $tenantUuid)) {
                    throw new \RuntimeException("Purge verification failed for {$handler->id()}.");
                }
                $runs->checkpoint($c, $runUuid, $workerUuid, $handler->id(), 'verify');
                $runs->renewLease($c, $runUuid, $workerUuid);
            } catch (\Throwable $e) {
                $phase = str_contains($e->getMessage(), 'verification failed') ? 'verify' : 'purge';
                $runs->markFailed($c, $runUuid, $workerUuid, $handler->id(), $phase);
                throw $e;
            }
        }

        // The final engine purge and completion marker are separately durable. If a prior attempt
        // committed tenant deletion then crashed, absence is treated as an already-completed final
        // purge; any non-purging live status remains a hard failure.
        try {
            $lifecycle = $tenants->getTenantLifecycle($c, $tenantUuid);
            if ($lifecycle !== null) {
                if (($lifecycle['status'] ?? null) !== 'purging') {
                    throw new \RuntimeException('Final purge requires a purging workspace.');
                }
                $hostStmt = db($c)->getPDO()->prepare(
                    'SELECT host FROM tenant_domains WHERE tenant_uuid=? ORDER BY host'
                );
                $hostStmt->execute([$tenantUuid]);
                $runs->putArtifacts($c, $runUuid, $workerUuid, 'engine.tenant', [
                    'hosts' => array_values(array_map('strval', $hostStmt->fetchAll(\PDO::FETCH_COLUMN))),
                ]);
                $runs->checkpoint(
                    $c, $runUuid, $workerUuid, 'engine.tenant', 'final_prepare'
                );
                $tenants->purgeTenantRecord($c, $tenantUuid);
            }
            $runs->markCompleted($c, $runUuid, $workerUuid);
        } catch (\Throwable $e) {
            $runs->markFailed($c, $runUuid, $workerUuid, 'engine.tenant', 'final');
            throw $e;
        }
    }

    private function phaseOf(PurgeRunRepository $runs, $c, string $runUuid, string $handlerId): ?string
    {
        $run = $runs->find($c, $runUuid);
        $plan = $run !== null && is_string($run['plan']) ? (array) json_decode($run['plan'], true) : [];
        $phase = $plan[$handlerId] ?? null;

        return is_string($phase) ? $phase : null;
    }

    /** @return array<string,mixed> */
    private function artifactsOf(PurgeRunRepository $runs, $c, string $runUuid, string $handlerId): array
    {
        $run = $runs->find($c, $runUuid);
        $all = $run !== null && is_string($run['artifacts']) ? (array) json_decode($run['artifacts'], true) : [];

        return is_array($all[$handlerId] ?? null) ? $all[$handlerId] : [];
    }
}
```

`Job` exposes the application context as protected `$context` and payload through `getData()`; no
`getContext()` method exists. `runAsTenant()` is intentionally not used: its verified active-only
resolver rejects the already-purging workspace. Add `TenantAdministration::getTenantLifecycle()`
in Tasks 4–5 as the
raw-PDO, include-deleted lifecycle read used here and by the HTTP surface.

Add RED-first tests for: two calls to `request()` return the same active run; two workers cannot
claim one live lease; a queue push failure becomes `dispatch_failed` and `recover()` dispatches it;
a handler failure records `failed` **and rethrows**; and a crash after `purgeTenantRecord()` but
before `markCompleted()` resumes to `completed` without requiring the deleted tenant row. Add a
transaction-participation test that throws after `beginPurge()` and proves both the run insert and
the `deleted → purging` transition roll back on the same connection.
The end-to-end test seeds the same business keys for a second tenant and asserts every one remains
after the system-path purge, making explicit predicates an executable invariant.
The complete prepare/purge/final-record sequence runs inside `WriteBarrier::runWritable()`, which
holds the established shared PostgreSQL mutation lock across storage and database side effects;
retrofit's exclusive lock cannot start mid-purge.

Create `PurgeRecoveryCommand` with the same `BaseCommand`/`CommandTester` pattern as Task 14; it
calls `PurgeCoordinator::recover($this->getContext())`, reports the count, and exits success. The
pack's existing command discovery registers it without provider edits. Add it to the operations
cron immediately before the cooldown sweep.

- [ ] **Step 5: Register the coordinator + registry population**

Add to the pack ServiceProvider `services()` a factory that builds the registry with all handlers registered:

```php
            \Thallo\Tenancy\Purge\PurgeResourceRegistry::class => [
                'factory' => [self::class, 'makePurgeRegistry'],
                'shared' => true,
            ],
            \Thallo\Tenancy\Purge\PurgeCoordinator::class => [
                'class' => \Thallo\Tenancy\Purge\PurgeCoordinator::class,
                'shared' => true,
                'autowire' => true,
            ],
```

Add the provider factory beside its existing `make*` methods:

```php
    public static function makePurgeRegistry(ContainerInterface $container): PurgeResourceRegistry
    {
        $registry = new PurgeResourceRegistry();
        foreach ([MediaPurgeHandler::class, TablesPurgeHandler::class,
            CachePurgeHandler::class, CollectionsPurgeHandler::class] as $handler) {
            $registry->register($container->get($handler));
        }
        return $registry;
    }
```

`ApplicationContext`, `TenantAdministration`, repository, and `QueueManager` are all container
services, so the coordinator uses the provider's verified `autowire` convention.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=testPurgeJobRunsBarrierThenPurgesRecord`
Expected: PASS.

- [ ] **Step 7: Run the full pipeline + tenancy suites**

Run: `vendor/bin/phpunit tests/Integration/Tenancy/ tests/Unit/Tenancy/`
Expected: PASS.

- [ ] **Step 8: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Purge/PurgeCoordinator.php \
        packages/thallo-tenancy/src/Purge/PurgeJob.php \
        packages/thallo-tenancy/src/Console/PurgeRecoveryCommand.php \
        packages/thallo-tenancy/src/TenancyServiceProvider.php \
        tests/Integration/Tenancy/PurgePipelineTest.php
# HOLD.
```

---

### Task 14: Cooldown sweep job + cron-safe dispatch command

**Files:**
- Create: `packages/thallo-tenancy/src/Console/CooldownSweepCommand.php`
- Create: `packages/thallo-tenancy/src/Purge/CooldownSweepJob.php`
- Test: `tests/Integration/Tenancy/HostCooldownTest.php` (append)

**Interfaces:**
- Produces: `CooldownSweepJob` (performs the locked prune) and
  `thallo:tenancy:cooldowns:sweep` (queues one job). Deployment cron invokes the command daily.
  Claim correctness never depends on it.
- Consumes: `ReleasedHostRepository::pruneExpired()` (Task 2), `Job`, `QueueManager`, `BaseCommand`.

- [ ] **Step 1: Write the failing test**

Append to `HostCooldownTest.php`:

```php
    public function testSweepPrunesExpiredTombstones(): void
    {
        $c = $this->appContext();
        $repo = $this->repo();
        db($c)->transaction(function () use ($c, $repo): void {
            foreach ([
                'expired.cooldown.test' => '2000-01-01 00:00:00',
                'live.cooldown.test' => '2999-01-01 00:00:00',
            ] as $host => $until) {
                $repo->lockHost($c, $host);
                $repo->upsertTombstone($c, $host, 'tenantAAAAAA', $until);
            }
        });

        (new \Thallo\Tenancy\Purge\CooldownSweepJob([], $c))->handle();

        $pdo = $this->connection()->getPDO();
        self::assertFalse(
            $pdo->query("SELECT 1 FROM released_hosts WHERE host='expired.cooldown.test'")
                ->fetchColumn()
        );
        self::assertNotFalse(
            $pdo->query("SELECT 1 FROM released_hosts WHERE host='live.cooldown.test'")
                ->fetchColumn()
        );
    }
```

(Add both hosts to the `LIKE '%.cooldown.test'` teardown — already covered by the class teardown pattern.)
Add an independent-connection race test: pause the sweep after candidate discovery, extend/release
the same host under its advisory lock, then resume; the sweep's lock + recheck must preserve the
newer `retained_until`.
Add a `CommandTester` unit test with a recording `QueueManager` binding proving the command pushes
exactly one `CooldownSweepJob` and does not prune inline.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=testSweepPrunesExpiredTombstones`
Expected: FAIL — job absent.

- [ ] **Step 3: Create the sweep job and dispatch command**

Create `CooldownSweepJob` using protected `$context` (not a nonexistent job accessor); resolve
`ReleasedHostRepository`, call `pruneExpired(now)`, and throw when context is absent. Then create
`packages/thallo-tenancy/src/Console/CooldownSweepCommand.php` following the existing
`TenancyStatusCommand` service-lookup pattern:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Glueful\Queue\QueueManager;
use Thallo\Tenancy\Purge\CooldownSweepJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Dispatch daily host-cooldown housekeeping. */
#[AsCommand(
    name: 'thallo:tenancy:cooldowns:sweep',
    description: 'Remove expired workspace-host cooldown tombstones.',
)]
final class CooldownSweepCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->getService(QueueManager::class)->push(CooldownSweepJob::class);
        $this->success('Queued host cooldown sweep.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Verify discovery and document daily scheduling**

The pack already discovers console commands, and queue jobs are class-addressed, so no provider
registration is needed. Add the deployment note to the tenancy operations doc:

```cron
*/5 * * * * cd /path/to/thallo && php glueful thallo:tenancy:purges:recover
17 2 * * * cd /path/to/thallo && php glueful thallo:tenancy:cooldowns:sweep
```

Do not call `JobScheduler::registerInDatabase()` at boot: it would create duplicate persistent
rows on every process boot. A first-class declarative schedule registry can replace this cron entry
later without changing repository semantics.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=testSweepPrunesExpiredTombstones`
Expected: PASS.

- [ ] **Step 6: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Console/CooldownSweepCommand.php \
        packages/thallo-tenancy/src/Purge/CooldownSweepJob.php \
        docs/operations/tenancy.md \
        tests/Integration/Tenancy/HostCooldownTest.php
# HOLD.
```

---

### Task 15: Admin surface — delete/restore/purge controller + routes + guards

**Files:**
- Modify: `packages/thallo-tenancy/src/Http/Controllers/TenantManagementController.php`
- Modify: `packages/thallo-tenancy/src/Http/Controllers/TenantDomainController.php`
- Modify: `packages/thallo-tenancy/routes/enablement.php`
- Modify: the pack ServiceProvider `services()` — extend `TenantManagementController` construction with new deps.
- Test: `tests/Integration/Tenancy/TenantAdminRoutesTest.php`

**Interfaces:**
- Produces on `TenantManagementController`:
  - `destroy(Request $request, string $uuid): Response` → requires `{confirm:true}`, then
    `deleteTenant`; maps lifecycle gates to `422` with a clear message.
  - `restore(Request $request, string $uuid): Response` → `restoreTenant`; request supplies the
    audit actor and lifecycle refusals map to `422`.
  - `purge(Request $request, string $uuid): Response` → validate exact typed slug or name plus the
    selected-workspace guard, then `PurgeCoordinator::request()`; returns 202 with the run uuid.
- Routes (in `routes/enablement.php`, under the existing `content_permission:tenancy.manage` group): `DELETE /tenants/{uuid}`, `POST /tenants/{uuid}/restore`, `POST /tenants/{uuid}/purge`.
- Consumes: `TenantAdministration`, `PurgeCoordinator`, the resolved/selected tenant (Thallo tenant-context), `Response`.
- Domain create maps neutral `HostCooldownException` to HTTP `409` with details
  `{code:'HOST_COOLDOWN',available_after:<timestamp>}`; it never exposes `released_by_tenant`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Tenancy/TenantAdminRoutesTest.php`. Drive the controller directly (like `UserAdminContinuityTest`), asserting status codes for delete → restore → purge with a wrong slug and a correct slug:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Http\Controllers\TenantManagementController;

final class TenantAdminRoutesTest extends AppTestCase
{
    /** @var list<string> */
    private array $tenants = [];

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach ($this->tenants as $uuid) {
            $pdo->prepare('DELETE FROM tenant_memberships WHERE tenant_uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM tenants WHERE uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM thallo_tenant_purge_runs WHERE tenant_uuid = ?')->execute([$uuid]);
        }
        $this->tenants = [];
        parent::tearDown();
    }

    private function controller(): TenantManagementController
    {
        return $this->container()->get(TenantManagementController::class);
    }

    private function seed(string $slug): string
    {
        $c = $this->appContext();
        $admin = $this->container()->get(TenantAdministration::class);
        $uuid = $admin->create($c, $slug, ucfirst($slug), Utils::generateNanoID(12));
        $admin->markActive($c, $uuid);
        $this->tenants[] = $uuid;
        return $uuid;
    }

    public function testDeleteThenRestore(): void
    {
        $this->seed('ws-keep-' . strtolower(Utils::generateNanoID(4)));
        $slug = 'ws-del-' . strtolower(Utils::generateNanoID(4));
        $target = $this->seed($slug);

        $delete = Request::create('/', 'DELETE', [], [], [], [], json_encode(['confirm' => true]));
        self::assertSame(200, $this->controller()->destroy($delete, $target)->getStatusCode());
        self::assertSame(200, $this->controller()->restore(Request::create('/'), $target)->getStatusCode());
    }

    public function testPurgeRequiresCorrectSlug(): void
    {
        $this->seed('ws-keep2-' . strtolower(Utils::generateNanoID(4)));
        $slug = 'ws-purge-' . strtolower(Utils::generateNanoID(4));
        $target = $this->seed($slug);
        $delete = Request::create('/', 'DELETE', [], [], [], [], json_encode(['confirm' => true]));
        $this->controller()->destroy($delete, $target);

        $wrong = Request::create('/', 'POST', [], [], [], [], json_encode(['confirm' => 'not-the-slug']));
        self::assertSame(422, $this->controller()->purge($wrong, $target)->getStatusCode());

        $right = Request::create('/', 'POST', [], [], [], [], json_encode(['confirm' => $slug]));
        self::assertSame(202, $this->controller()->purge($right, $target)->getStatusCode());
    }
}
```

Add `testSelectedWorkspaceCannotBeDeletedOrPurged`: send the target UUID in `X-Tenant-Id` on both
requests and assert 422 before any transition. A sibling test with a different selected UUID
passes the guard. This drives the real `tenant_system` route behavior.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=TenantAdminRoutesTest`
Expected: FAIL — controller methods absent.

- [ ] **Step 3: Add the controller methods**

In `TenantManagementController.php`, add nullable `PurgeCoordinator` after the existing optional
seams. The pack continues to depend only on neutral tenancy contracts; it does not import concrete
engine lifecycle exceptions. Add only:

```php
use Thallo\Tenancy\Purge\PurgeCoordinator;
```

Extend the constructor:

```php
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly BootstrapTenantCreationGuard $creationGuard,
        private readonly ?TenantAdministration $tenants = null,
        private readonly ?TenantSeedActivator $seeder = null,
        private readonly ?TenantSeedRepair $seedRepair = null,
        private readonly ?PurgeCoordinator $purge = null,
    ) {
    }
```

Add methods:

```php
    public function destroy(Request $request, string $uuid): Response
    {
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        if (($this->body($request)['confirm'] ?? null) !== true) {
            return Response::validation(['confirm' => 'Confirm moving this workspace to trash.']);
        }
        if ($this->selectedTenantUuid($request) === $uuid) {
            return Response::validation([
                'tenant' => 'Switch away from this workspace before moving it to trash.',
            ]);
        }
        try {
            $this->tenants->deleteTenant($this->context, $uuid);
            return Response::success(['tenant' => ['uuid' => $uuid, 'status' => 'deleted']]);
        } catch (\DomainException | \RuntimeException $e) {
            return Response::validation(['tenant' => $e->getMessage()]);
        }
    }

    public function restore(Request $request, string $uuid): Response
    {
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        try {
            $this->tenants->restoreTenant($this->context, $uuid);
            return Response::success(['tenant' => ['uuid' => $uuid, 'status' => 'restored']]);
        } catch (\DomainException | \RuntimeException $e) {
            return Response::validation(['tenant' => $e->getMessage()]);
        }
    }

    public function purge(Request $request, string $uuid): Response
    {
        if ($this->tenants === null || $this->purge === null) {
            return $this->unavailable();
        }

        $tenant = $this->tenants->getTenantLifecycle($this->context, $uuid);
        if ($tenant === null) {
            return Response::notFound('Workspace not found.');
        }

        // Selected-workspace guard (request/client state, not an engine invariant): never purge the
        // workspace the operator is currently acting as.
        $selected = $this->selectedTenantUuid($request);
        if ($selected !== null && $selected === $uuid) {
            return Response::validation(['tenant' => 'Cannot purge the workspace you are currently acting as.']);
        }

        // Typed-confirmation guard: the operator must type the exact slug or name.
        $confirm = is_string($this->body($request)['confirm'] ?? null) ? trim($this->body($request)['confirm']) : '';
        if ($confirm !== $tenant['slug'] && $confirm !== $tenant['name']) {
            return Response::validation(['confirm' => 'Type the workspace slug or name to confirm purge.']);
        }

        try {
            $runUuid = $this->purge->request($this->context, $uuid, $this->actor($request));
            return Response::success(
                ['tenant' => ['uuid' => $uuid, 'status' => 'purging'], 'purge_run' => $runUuid],
                'Purge accepted.'
            )->setStatusCode(Response::HTTP_ACCEPTED);
        } catch (\DomainException | \RuntimeException $e) {
            return Response::validation(['tenant' => $e->getMessage()]);
        }
    }

    /** The workspace the SPA explicitly says it is currently acting as, if any. */
    private function selectedTenantUuid(Request $request): ?string
    {
        $selected = trim((string) $request->headers->get('X-Tenant-Id', ''));

        return preg_match('/\A[0-9A-Za-z]{12}\z/', $selected) === 1 ? $selected : null;
    }
```

`Response::success()` has no status-code argument, so the response explicitly calls
`setStatusCode(Response::HTTP_ACCEPTED)`. These routes are `tenant_system`, so tenant resolution is
intentionally not run; the selected-workspace UX guard reads the explicit `X-Tenant-Id` header
that `authFetch` already sends. This is a protective product guard, not an authorization boundary.

- [ ] **Step 4: Add the routes**

In `routes/enablement.php`, inside the existing `content_permission:tenancy.manage` group (the one with `suspend`/`reactivate`), add:

```php
            $router->delete('/tenants/{uuid}', [TenantManagementController::class, 'destroy']);
            $router->post('/tenants/{uuid}/restore', [TenantManagementController::class, 'restore']);
            $router->post('/tenants/{uuid}/purge', [TenantManagementController::class, 'purge']);
```

Extend `RouteCoverageTest`: all three lifecycle routes must carry exactly one `tenant_system`
marker and `content_permission:tenancy.manage`; none may be placed under `tenant_profile:admin` or
`tenant_bootstrap`, because they must remain operable after the selected tenant is trashed.

- [ ] **Step 5: Extend the controller DI registration**

In the pack ServiceProvider `services()`, extend the `TenantManagementController` binding to inject `PurgeCoordinator` as the new constructor argument (match the existing argument list + syntax).

Also update `TenantDomainController::create()` with a dedicated catch **before** its general
`DomainException` catch:

```php
        } catch (HostCooldownException $e) {
            return Response::error(
                'Host is temporarily reserved after release.',
                Response::HTTP_CONFLICT,
                ['code' => 'HOST_COOLDOWN', 'available_after' => $e->availableAfter()],
            );
```

Add an HTTP-level test asserting status 409, the exact `available_after`, and absence of the prior
tenant UUID from both message and details.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=TenantAdminRoutesTest`
Expected: PASS.

- [ ] **Step 7: phpcs + stage (HOLD)**

```bash
composer phpcs -- packages/thallo-tenancy/src/Http/Controllers/TenantManagementController.php
git add packages/thallo-tenancy/src/Http/Controllers/TenantManagementController.php \
        packages/thallo-tenancy/src/Http/Controllers/TenantDomainController.php \
        packages/thallo-tenancy/routes/enablement.php \
        packages/thallo-tenancy/src/TenancyServiceProvider.php \
        tests/Integration/Tenancy/TenantAdminRoutesTest.php
# HOLD.
```

---

### Task 15b: Lifecycle audit + canonical-superuser cooldown override

**Files:**
- Create: `packages/thallo-contracts/src/Tenancy/TenancyLifecycleAudit.php`
- Create: `app/Support/TenancyLifecycleAudit.php`
- Create: `app/Http/Controllers/TenantHostCooldownController.php`
- Modify: `app/Providers/ThalloServiceProvider.php`
- Modify: `packages/thallo-tenancy/src/{Purge/PurgeCoordinator.php,Purge/PurgeJob.php}`
- Modify: `packages/thallo-tenancy/src/Http/Controllers/{TenantManagementController,TenantDomainController}.php`
- Modify: `routes/admin.php`
- Test: `tests/Integration/Tenancy/TenantLifecycleAuditTest.php`

**Interfaces:**
- Neutral `Thallo\Contracts\Tenancy\TenancyLifecycleAudit::record(string $action,
  ?string $actorUuid, ?string $tenantUuid, array $context = []): void`.
- App implementation follows `AuthorityAudit`: optional `AuditRecorderInterface`, category
  `security`, target type `tenant`, and catches every recorder failure (best-effort).
- `POST /v1/admin/tenancy/hosts/cooldown/override` accepts `{tenant_uuid,host,reason}`, requires
  `auth + tenant_system + content_permission:tenancy.manage` middleware **and** verifies the actor
  holds the canonical `superuser` role through `RoleAuthority::isCanonicalSuperuser()`.

- [ ] **Step 1: Write failing audit and override tests**

Cover every required action: `tenant.deleted`, `tenant.restored`, `tenant.purge_requested`,
`tenant.purge_completed`, `tenant.purge_failed`, `host.released`, and
`host.cooldown_overridden`. Assert a throwing recorder never changes the lifecycle response/job
outcome. For override, assert administrator/workspace-manager actors receive 403, canonical
superuser succeeds, target/reason are required, the claimed domain is pending verification, and the
audit includes target/host/reason but no previous-owner disclosure in the HTTP envelope.

- [ ] **Step 2: Add and bind the neutral audit seam**

The pack consumes only `Thallo\Contracts\Tenancy\TenancyLifecycleAudit`; the app provider binds it
to the app implementation constructed with the optional audit recorder, exactly as
`AuthorityAudit` is bound. Do not import `App\*` from the pack.

- [ ] **Step 3: Emit lifecycle audit records at committed product boundaries**

- `TenantManagementController`: after successful delete/restore, with actor from the verified
  existing `actor(Request)` helper.
- `PurgeCoordinator`: register `tenant.purge_requested` through the same `afterCommit` boundary,
  using `requested_by_uuid`; queue-dispatch failure does not erase that accepted request. Emit it
  only when the run was newly created, not on idempotent repair requests.
- `PurgeJob`: record `tenant.purge_completed` only when owner-CAS `markCompleted()` returns true;
  record `tenant.purge_failed` only when owner-CAS `markFailed()` returns true, then rethrow. A
  stale lease holder emits neither terminal record. On completion, also emit one `host.released`
  audit per host from the durable `engine.tenant.hosts` artifact captured before final deletion;
  this remains available after a crash that already removed domain/tenant rows.
- `TenantDomainController::remove(Request $request, string $uuid)`: capture the domain projection
  before mutation; after successful `releaseDomain`, record `host.released` with actor, tenant, and
  normalized host. The router already injects `Request`; update the controller test call sites.
- `TenantHostCooldownController`: after successful `overrideCooldownAndClaim`, record
  `host.cooldown_overridden` against the target tenant.

Engine domain events remain domain integration signals; they do not substitute for actor-aware
Thallo audit entries.

- [ ] **Step 4: Add the override controller and route**

The app controller injects `ApplicationContext`, neutral `TenantDomainAdministration`,
`RoleAuthority`, and the audit seam. It resolves the actor using the established `UserIdentity`
shape, rejects non-superusers with 403, validates `{tenant_uuid,host,reason}`, and calls
`overrideCooldownAndClaim($context, $tenantUuid, $host)`. The engine atomically consumes cooldown
and creates a pending domain under the same lock. Return the pending domain plus TXT instructions;
verification remains mandatory.
Register the route in `routes/admin.php` with `auth`, `tenant_system`, and
`content_permission:tenancy.manage`. Keeping canonical-superuser enforcement in the controller is
mandatory; the route permission alone is insufficient.

- [ ] **Step 5: Verify**

```bash
vendor/bin/phpunit --filter=TenantLifecycleAuditTest
composer phpcs -- packages/thallo-contracts/src/Tenancy/TenancyLifecycleAudit.php \
  app/Support/TenancyLifecycleAudit.php app/Http/Controllers/TenantHostCooldownController.php
```

- [ ] **Step 6: Stage (HOLD)**

List only the files above in `git add`; do not execute while the commit hold is active.

---

### Task 16: Admin SPA — trash/restore/purge UX + cooldown-conflict surfacing

**Files:**
- Modify: `admin/src/queries/tenants.ts` — add delete/restore/purge mutations via `authFetch`.
- Modify: `admin/src/pages/workspaces/index.vue` — trash affordance + restore action +
  `purge_after` display.
- Create: `admin/src/components/tenancy/TenantPurgeModal.vue`.
- Modify: `admin/src/pages/workspaces/[uuid]/domains.vue` and
  `admin/src/components/tenancy/DomainAddForm.vue` — cooldown conflict display.
- Modify: domain-add flow — surface a cooldown conflict's `available_after`.
- Test: `admin/src/__tests__/workspaceDeletion.spec.ts`.

**Interfaces:**
- Consumes the Task 15 endpoints: `DELETE /v1/admin/tenancy/tenants/{uuid}` (body
  `{confirm:true}`), `POST …/restore`, `POST …/purge` (body `{confirm:<slug>}`), and the domain-add
  `409 HOST_COOLDOWN` details carrying `available_after`.
- Produces SPA store actions + a `data-testid`-hooked purge modal that requires typing the slug before enabling the confirm button.

- [ ] **Step 1: Write the failing vitest spec**

In `admin/src/__tests__/workspaceDeletion.spec.ts`, assert: (a) the purge modal's confirm button is
disabled until the typed value equals the workspace slug; (b) queries call the exact methods,
paths, and bodies; (c) selected workspace cannot expose purge; and (d) a 409
`HOST_COOLDOWN` renders the exact availability timestamp without any prior-owner data. Follow the
existing `tenancyAcceptance.spec.ts` stubbing pattern.

```ts
// admin/src/__tests__/workspaceDeletion.spec.ts
import { describe, it, expect } from 'vitest'
// ... mount the modal with workspace { slug: 'acme' }
// type 'wrong' → confirm button [data-testid="purge-confirm"] disabled
// type 'acme'  → confirm button enabled
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd admin && pnpm test workspaceDeletion` (do NOT pipe through tail).
Expected: FAIL — modal/composable absent.

- [ ] **Step 3: Add the composable actions**

In `admin/src/queries/tenants.ts` (which already owns create/suspend/reactivate), add:

Extend `TenantSummary` with nullable `deleted_at`, `deleted_from_status`, and `purge_after` before
adding the mutations; these fields come from the Task 5 lifecycle listing projection.

```ts
async function deleteWorkspace(uuid: string) {
  return authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${uuid}`, {
    method: 'DELETE', body: JSON.stringify({ confirm: true }),
  })
}
async function restoreWorkspace(uuid: string) {
  return authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${uuid}/restore`, {
    method: 'POST', body: '{}',
  })
}
async function purgeWorkspace(uuid: string, confirm: string) {
  return authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${uuid}/purge`, {
    method: 'POST', body: JSON.stringify({ confirm }),
  })
}
```

Expose these through `useTenantMutations()` and invalidate both `qkAllTenants()` and
`qkMyTenants()` on settle, matching the existing mutation pattern.

- [ ] **Step 4: Build the purge modal + list affordances**

Create the typed-confirmation modal: an input bound to a `typed` ref; the confirm button
`:disabled="typed !== workspace.slug"`, hooked `data-testid="purge-confirm"` and
`data-testid="purge-input"`. In the workspace list, show trash for active/suspended, restore plus
`purge_after` for deleted, and purge only for deleted workspaces that are not the currently selected
workspace. In the domains page, map `ApiError.status === 409` plus
`apiErrorDetails(error).code === 'HOST_COOLDOWN'` to the `DomainAddForm` error text
`Host in cooldown - available after {date}`.

- [ ] **Step 5: Run the spec to verify it passes**

Run: `cd admin && pnpm test workspaceDeletion`
Expected: PASS.

- [ ] **Step 6: Type-check + full SPA test run**

Run: `cd admin && pnpm type-check && pnpm test` (do NOT pipe tsc/vue-tsc through tail — it masks the exit code).
Expected: no type errors; specs green.

- [ ] **Step 7: Stage (HOLD)**

```bash
git add admin/src/queries/tenants.ts admin/src/pages/workspaces/index.vue \
        admin/src/pages/workspaces/[uuid]/domains.vue \
        admin/src/components/tenancy/DomainAddForm.vue \
        admin/src/components/tenancy/TenantPurgeModal.vue \
        admin/src/__tests__/workspaceDeletion.spec.ts
# HOLD.
```

---

### Task 17: Regression sweep, index update, docs

**Files:**
- Modify: `docs/superpowers/specs/multi-tenancy/LIFECYCLE-GAPS-README.md` — mark slice 2
  implemented (HELD), link the plan and verification record.
- Test: full backend + SPA suites.

**Interfaces:** none (verification + docs).

- [ ] **Step 1: Run the mandatory lifecycle acceptance journey**

Add/execute one PostgreSQL acceptance class covering the complete boundary with two workspaces:

1. delete active and suspended workspaces, then restore each to its exact prior status;
2. deleted workspace no longer resolves, retains hosts during trash, and cannot be selected;
3. final-workspace, required-host, selected-workspace, wrong-slug, and expired-restore gates;
4. remove a live domain and purge a workspace both create cooldown tombstones;
5. releasing workspace can reclaim immediately; a different workspace gets 409 with only
   `available_after`; expiry works before the sweeper; canonical-superuser override works/audits;
6. purge captures media keys, survives injected handler/queue failures, resumes once, and leaves
   the second workspace's identical business keys, media, hosts, and cache segment untouched;
7. duplicate purge requests/workers converge on one run; crash after final tenant deletion resumes
   to completed; every audit action is emitted and recorder failure remains non-blocking.

Run:

```bash
vendor/bin/phpunit --filter=TenantDeletionHostRetentionAcceptanceTest
```

- [ ] **Step 2: Run the full backend suite**

Run: `composer test` (or `vendor/bin/phpunit`).
Expected: PASS — new tenancy/purge tests green; existing suspend/reactivate, resolution, SP2/SP3, media-library, authority (slice 1) suites still green. Investigate any failure via systematic-debugging before proceeding.

- [ ] **Step 3: Run phpcs across new/changed PHP**

Run: `composer phpcs`
Expected: clean (120-char, no warnings). Fix with `composer phpcbf` where mechanical.

- [ ] **Step 4: Run the SPA suite + type-check**

Run: `cd admin && pnpm type-check && pnpm test`
Expected: green (no tail piping).

- [ ] **Step 5: Update the tracking index**

After implementation and all gates pass, change slice 2's Status to implemented (HELD), not shipped:

```markdown
| 2 | **Tenant deletion & host-retention** — … | implemented (HELD; uncommitted) | [spec](2026-07-11-tenant-deletion-host-retention-design.md) | [plan](../plans/multi-tenancy/2026-07-11-tenant-deletion-host-retention.md) |
```

- [ ] **Step 6: Stage (HOLD)**

```bash
git add docs/superpowers/specs/multi-tenancy/LIFECYCLE-GAPS-README.md
# HOLD — do not commit until the user gives the go-ahead.
```

- [ ] **Step 7: Porting note (do NOT release yet)**

Record for the eventual release (per Global Constraints, release only after the user says so):
- Port `vendor/glueful/extension-contracts` changes → the contracts source repo; publish; then `vendor/glueful/tenancy` → the tenancy source repo; publish; then pin both in Thallo's `composer.json` and re-`composer require`.
- Engine porting checklist: `TenantAdministration` (+4 mutations + lifecycle read),
  `TenantDomainAdministration` (+`releaseDomain` + `overrideCooldownAndClaim`) and neutral
  `HostCooldownException`; `ContractTenant*Administration` bodies, `Cooldown/*`, `Events/*`,
  `Exceptions/*`, migrations/001 fold + 004, config keys, and repository/bridge DI wiring.
- No framework release needed (storage deletion used the existing `StorageManager`).

---

## Self-Review

**1. Spec coverage:**
- §1 ownership boundary → engine Tasks 1–8, Thallo Tasks 9–15. ✅
- §2 lifecycle + schema (deleteTenant/restoreTenant/beginPurge/purgeTenantRecord, deleted_from_status/purge_after, include-deleted, hard delete) → Tasks 1, 4, 5, 8. ✅
- §3 cooldown ledger (released_hosts, per-host + multi-host locking, GREATEST upsert, claim consume, override, system-host reservation via existing guard, sweep) → Tasks 2, 6, 7, 8, 14. ✅
- §4 removeDomain delegation + one-transaction purgeTenantRecord (not FK cascade) → Tasks 6, 8. ✅
- §5 purge pipeline (registry, prepare→purge→verify, global barrier, single owner per table,
  media-owns-media, durable owner-leased ledger, PurgeJob, atomic run+beginPurge, post-commit
  dispatch, dispatch-failure/crash recovery, verify-gated final purge) → Tasks 9–13. ✅
- §6 events after commit + audit → events in Tasks 3/4/5/6/8; actor-aware best-effort audit in
  Task 15b. ✅
- §7 config single source → Task 1. ✅
- §8 gates (final-workspace locked-candidate-count, required-default-host, selected-workspace, typed-slug) → Tasks 4, 15. ✅
- §9 admin routes + SPA → Tasks 15, 16. ✅
- §10 release chain / no framework seam → confirmed; Task 17 porting note. ✅
- §11 failure modes → covered across Tasks 4–8, 13. ✅
- §12 testing → each task's tests. ✅
- §13 out of scope (collections no-op) → Task 12 `CollectionsPurgeHandler`. ✅

**2. Placeholder scan:** No "TBD"/"handle edge cases"/"similar to Task N" and no fabricated
queue-`Job::getContext`, `TenantCacheSegment::flushForTenant`, scheduler interface, app/test path,
response signature, request-attribute, or service-provider placeholders remain. (`BaseCommand`'s
verified protected `getContext()` is used by the two console commands.)

**3. Type consistency:** lifecycle mutations plus `getTenantLifecycle` match contract/bridge;
`HostCooldownException` is neutral; `PurgeRunRepository` uses
`create/find/findByTenant/recoverable/claimDispatch/claimRun/renewLease/mark*` consistently;
`PurgeCoordinator::request` carries the actor; queue handlers use protected `$context` and
`TenantContextRunner`; controller and SPA use the same 409 cooldown envelope.
