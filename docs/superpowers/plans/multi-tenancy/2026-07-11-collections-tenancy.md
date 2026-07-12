# Collections Tenancy Implementation Plan

**Revision:** 2 — review findings integrated; HELD and uncommitted.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make dynamic collections tenant-scoped (per-workspace definitions + per-tenant physical tables) by unfencing the deliberate `collections.*` fence, so every workspace owns its collection model and data.

**Architecture:** Model A — per-tenant physical tables (`tc_<tenant-token>_<collection-token>`, opaque tokens, isolation is structural) plus tenant-scoped metadata (`collection_definitions`/`collection_schema_changes` gain `tenant_uuid`, self-scoped by every repository op). The already-installed Glueful tenancy provider becomes bundled-active as the identity/provisioning plane while `tenancy.enabled` remains the enforcement switch. A `SingleStoreTenant` service supplies one deterministic tenant owner in every mode and becomes the canonical provisioner that `DefaultTenant` delegates to. A tenant-bound API-key binding secures the shared public/headless route surface. The real `CollectionsPurgeHandler` lives in the collections pack and is discovered by the tenancy registry through an optional alias, preserving one-way package dependencies.

**Tech Stack:** PHP 8.3+, PostgreSQL (advisory locks, transactional DDL, SQLSTATE 23505 handling, RFC 4648 base32), Glueful framework, `glueful/tenancy ^1.3.0` (`TenantProvisioner`, `TenantContextRunner`), the `thallo-collections` + `thallo-tenancy` packs, PHPUnit integration tests against real PostgreSQL.

## Global Constraints

- **Thallo-only.** No framework/contract/engine change; `glueful/tenancy ^1.3.0` + `TenantProvisioner` provide everything. Do not edit `vendor/`.
- **HOLD ALL COMMITS.** Stage only; never commit until the user gives an explicit go-ahead. Work on `dev`.
- **No AI/Anthropic attribution.** Never stage/commit `CLAUDE.md`; every task lists explicit `git add` paths. No tags. No Packagist.
- **PHP style:** `declare(strict_types=1)`, `final` classes, constructor DI, `use`-imports, `composer phpcs` clean (120-char, warnings fail).
- **PostgreSQL-only.** Advisory locks, transactional DDL, `DROP TABLE IF EXISTS`, SQLSTATE 23505 are used deliberately.
- **One code path, one naming scheme, every mode.** No dual-mode collections.
- **Identity plane always available; enforcement still gated.** `Glueful\Extensions\Tenancy\TenancyServiceProvider` is bundled-active before first setup so `TenantProvisioner`, `TenantContextRunner`, and tenant migrations exist. `tenancy.enabled` plus Thallo's boot-gated table registration still decide whether row scoping is armed.
- **Physical name:** `tc_<tenant-token>_<collection-token>` — `tenant-token` = lowercase RFC 4648 base32 (no padding) of raw `sha256(tenant_uuid)` bytes, first 10 chars; `collection-token` = 12 random `[a-z0-9]`. `table_name` ≤ 63 chars, stored as source of truth; renames never touch it.
- **Explicit self-scoping in all modes** — repos resolve `tenant_uuid` via `SingleStoreTenant` and constrain every metadata read/write themselves (clean-off has no active stamper/hooks). `collection_schema_changes` always constrains **both** `tenant_uuid` **and** `collection_uuid`.
- **Resolved-definition-only** — every physical-table consumer takes a resolved `CollectionDefinition` (carrying `tenantUuid` + stored `table_name`); never a caller-supplied table string.
- **Fail closed** — enabled mode with no resolved request tenant never falls back to the default pointer; missing tenant identity is a clear infrastructure error.
- **Deferred/out of scope:** JWT/claim central selection; finer member-level collection permissions; runtime `coll_*` conversion (dev-adoption procedure only); schema-per-tenant; non-`table` storage modes.

---

## File Structure

**thallo-collections (`packages/thallo-collections/src/`):**
- `composer.json` — MODIFY: declare the one-way dependency on `glueful/thallo-tenancy`.
- `Schema/CollectionPhysicalName.php` — CREATE: token derivation + table/index-name generation + validation.
- `Schema/CollectionDefinition.php` — MODIFY: add `tenantUuid`.
- `Repositories/CollectionDefinitionRepository.php` — MODIFY: tenant-scope every op.
- `CollectionManager.php` — MODIFY: resolve tenant, physical-name via helper, one-txn-per-attempt retry, tenant-scope.
- `Relations/RelationResolver.php` — MODIFY: global scan → tenant-scoped.
- `Data/RowRepository.php`, `Schema/SchemaMaterializer.php`, `Schema/DdlPlanner.php`, `Schema/ColumnMapper.php`, `Http/Controllers/*`, `Http/CollectionAccessResolver.php` — MODIFY: resolved-definition-only + `tenantUuid` on events.
- `migrations/001_CreateCollectionDefinitionsTable.php`, `migrations/002_CreateCollectionSchemaChangesTable.php` — MODIFY: fold `tenant_uuid` + named constraints/index.
- `routes/collections.php` — MODIFY: fence removal, binding middleware, route order.
- `routes/admin-routes.php` — MODIFY: fence removal + admin tenant middleware order.
- `Purge/CollectionsPurgeHandler.php` — CREATE: real collections purge owner + optional service alias.

**thallo-tenancy (`packages/thallo-tenancy/src/`):**
- `Tenant/SingleStoreTenant.php` — CREATE: canonical resolver + provisioner.
- `Retrofit/DefaultTenant.php` — MODIFY: delegate to `SingleStoreTenant`.
- `ApiKeyBinding/TenantApiKeyBindingRepository.php` — CREATE.
- `Http/Middleware/CollectionsTenantBindingMiddleware.php` — CREATE.
- `Runtime/CollectionsDisabledWhenTenantMiddleware.php` — DELETE (fence removal).
- `Purge/Handlers/CollectionsPurgeHandler.php` — DELETE: remove tenancy→collections coupling.
- `Purge/PurgeHandler.php`, `Purge/PurgeJob.php`, existing handlers — MODIFY: verification receives durable artifacts.
- `ThalloTenantTables.php` — MODIFY: add the two metadata tables.
- `migrations/003_CreateTenantApiKeyBindingsTable.php` — CREATE.
- pack ServiceProvider(s) — MODIFY: register services/middleware/repo.

**thallo (app):**
- `config/extensions.php` — MODIFY: activate the installed Glueful tenancy provider before Thallo's pack.
- `app/Setup/SetupService.php` — MODIFY: eager `ensure()` after admin.
- the workspace `RoleMatrix` — MODIFY: add `collections.*` to owner+admin.
- `tests/Unit/Tenancy/RawPdoScopingLintTest.php` — MODIFY: `tc_*` PER_TENANT_PHYSICAL.
- API-key DTO/controller/routes + `admin/src/pages/developers/api-keys/**` — MODIFY: explicit tenant bind/unbind UX and lifecycle.

**Tests:** `tests/Integration/Collections/*` (new tenant-scoped suites) + migrated existing collections tests.

---

### Task 0: Make the tenancy identity plane available from clean install

**Files:**
- Modify: `config/extensions.php`
- Modify: `tests/Unit/Tenancy/Enablement/TenancyPackageDiscoverableTest.php`
- Modify: `tests/Unit/Enablement/TenancyReleaseDistributionTest.php`
- Modify: `tests/Integration/Tenancy/EnableFullMachineAcceptanceTest.php`
- Test: `tests/Integration/Tenancy/CleanInstallIdentityPlaneTest.php`

**Interfaces:**
- Produces: `TenantProvisioner`, `TenantContextRunner`, `CurrentTenantResolver`, and the extension migrations are available before `SetupService::install()`; enforcement remains off until `tenancy.enabled=1` and Thallo registers owned tables.
- Consumes: the root's existing hard requirement on `glueful/tenancy ^1.3.0`.

- [ ] **Step 1: Write the failing clean-install test**

Create `CleanInstallIdentityPlaneTest`: boot with `tenancy.enabled` absent/`0`; assert the container has `TenantProvisioner`, `TenantContextRunner`, and `CurrentTenantResolver`; assert the `tenants` table exists after migrations; assert `TenantTableRegistry` contains no Thallo owned tables and `Connection::applyInsertHooks('collection_definitions', ['name' => 'probe'])` does not add `tenant_uuid`. This pins identity availability without issuing an invalid metadata insert or silently enabling enforcement.

- [ ] **Step 2: Activate the installed provider in the application allow-list**

Add `Glueful\Extensions\Tenancy\TenancyServiceProvider` to `config/extensions.php` before `Thallo\Tenancy\TenancyServiceProvider`. Do not set `tenancy.enabled=true` and do not preload Thallo tables into the extension's static config list.

- [ ] **Step 3: Reconcile the SP1 distribution/enablement tests**

Update the three named tests to the bundled-active identity-plane model: install/activate are clean skips on a fresh build; the legacy `INSTALLING`/`ENABLING_EXTENSION`/`AWAITING_PROVIDER_BOOT` transitions remain supported for upgraded hosts where the provider is absent. Keep the existing two-boot enforcement finalization tests unchanged.

- [ ] **Step 4: Verify**

Run the four named test classes plus the tenancy-off suite. Expected: provider contracts resolve, migration tables exist, and no table hook/stamper scopes Thallo tables while the persisted flag is off.

- [ ] **Step 5: Stage (HOLD)**

```bash
git add config/extensions.php \
        tests/Unit/Tenancy/Enablement/TenancyPackageDiscoverableTest.php \
        tests/Unit/Enablement/TenancyReleaseDistributionTest.php \
        tests/Integration/Tenancy/EnableFullMachineAcceptanceTest.php \
        tests/Integration/Tenancy/CleanInstallIdentityPlaneTest.php
# HOLD.
```

---

### Task 1: Fold `tenant_uuid` into collection metadata migrations + registry + lint

**Files:**
- Modify: `packages/thallo-collections/migrations/001_CreateCollectionDefinitionsTable.php`
- Modify: `packages/thallo-collections/migrations/002_CreateCollectionSchemaChangesTable.php`
- Modify: `packages/thallo-tenancy/src/ThalloTenantTables.php`
- Modify: `packages/thallo-tenancy/src/Retrofit/RetrofitDiagnostics.php`
- Modify: `tests/Unit/Tenancy/RawPdoScopingLintTest.php`
- Test: `tests/Integration/Collections/CollectionsTenancySchemaTest.php`
- Test: `tests/Integration/Tenancy/Retrofit/RetrofitDiagnosticsTest.php`

**Interfaces:**
- Produces: `collection_definitions.tenant_uuid` (string12), `unique(tenant_uuid,name)` named `uniq_collection_def_tenant_name`, `table_name` unique named `uniq_collection_def_table_name`, `uuid` global unique; `collection_schema_changes.tenant_uuid` + index `(tenant_uuid,collection_uuid)`. `ThalloTenantTables::all()` includes both tables (metadata only), diagnostics checks the folded composite index, and `tc_*` is declared PER_TENANT_PHYSICAL in the lint.

- [ ] **Step 1: Write the failing schema test**

Create `tests/Integration/Collections/CollectionsTenancySchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;

final class CollectionsTenancySchemaTest extends AppTestCase
{
    public function testMetadataTablesHaveTenantColumns(): void
    {
        $defCols = $this->columns('collection_definitions');
        self::assertContains('tenant_uuid', $defCols);
        $scCols = $this->columns('collection_schema_changes');
        self::assertContains('tenant_uuid', $scCols);
    }

    public function testDefinitionsUniquesAreNamedAndScoped(): void
    {
        $idx = $this->connection()->getPDO()->query(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'collection_definitions'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('uniq_collection_def_tenant_name', $idx);
        self::assertContains('uniq_collection_def_table_name', $idx);
    }

    public function testSchemaChangesHasTenantCollectionIndex(): void
    {
        $defs = $this->connection()->getPDO()->query(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'collection_schema_changes'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertTrue((bool) array_filter(
            $defs,
            static fn (string $def): bool => str_contains($def, '(tenant_uuid, collection_uuid)'),
        ));
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->connection()->getPDO()
            ->query("SELECT column_name FROM information_schema.columns WHERE table_name = '{$table}'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=CollectionsTenancySchemaTest`
Expected: FAIL — no `tenant_uuid`, no named uniques.

- [ ] **Step 3: Fold columns into `001`**

In `001_CreateCollectionDefinitionsTable.php`, add `tenant_uuid` after `uuid`, replace `unique('name')` with a named composite, and name the `table_name` unique:

```php
            $table->string('uuid', 24);
            $table->string('tenant_uuid', 12);
            $table->string('name', 64);
            // ... existing columns ...
            $table->unique('uuid');
            $table->unique(['tenant_uuid', 'name'], 'uniq_collection_def_tenant_name');
            $table->unique('table_name', 'uniq_collection_def_table_name');
            $table->index('tenant_uuid');
```

(Confirm the schema builder's `unique(columns, name)` signature — grep an existing named-unique migration such as `render_templates`; if the builder names differently, match its API. Remove the old bare `unique('name')`.)

- [ ] **Step 4: Fold columns into `002`**

In `002_CreateCollectionSchemaChangesTable.php`, add `tenant_uuid` and the composite index:

```php
            $table->string('tenant_uuid', 12);
            // ... existing columns ...
            $table->index(['tenant_uuid', 'collection_uuid']);
```

- [ ] **Step 5: Register the two metadata tables (only) in `ThalloTenantTables`**

In `ThalloTenantTables::all()`, add (definitions carries the widened unique; schema_changes has no widened unique):

```php
            'collection_definitions' => self::row($def, [['uniq_collection_def_tenant_name', ['tenant_uuid', 'name']]]),
            'collection_schema_changes' => self::row($inst),
```

Do **not** add any `tc_*` entry — those are never registered.

- [ ] **Step 6: Declare `tc_*` PER_TENANT_PHYSICAL in the lint**

In `tests/Unit/Tenancy/RawPdoScopingLintTest.php`, add a `PER_TENANT_PHYSICAL` allow-rule (analogous to the existing `GLOBAL_BY_PROOF` list) so raw writes to a `tc_`-prefixed table are accepted with the structural-isolation proof. (Read the test's existing proof-list mechanism and mirror it; add a short proof comment: one physical table per tenant, manager-derived stored name, never caller-supplied, dropped as a unit on purge.)

Extend `RetrofitDiagnostics::checkTables()` with a collection-metadata assertion requiring the `(tenant_uuid, collection_uuid)` index on `collection_schema_changes`; the registry's current `indexes` field represents only single-column tenant indexes. Add a missing-composite-index failure case to `RetrofitDiagnosticsTest`.

- [ ] **Step 7: Reset + migrate the test DB, run the test**

```bash
composer test:migrate   # from-zero reset first (see Task 12 dev-adoption procedure)
vendor/bin/phpunit --filter=CollectionsTenancySchemaTest
vendor/bin/phpunit --filter=RawPdoScopingLintTest
vendor/bin/phpunit --filter=RetrofitDiagnosticsTest
```
Expected: PASS.

- [ ] **Step 8: Stage (HOLD)**

```bash
git add packages/thallo-collections/migrations/001_CreateCollectionDefinitionsTable.php \
        packages/thallo-collections/migrations/002_CreateCollectionSchemaChangesTable.php \
        packages/thallo-tenancy/src/ThalloTenantTables.php \
        packages/thallo-tenancy/src/Retrofit/RetrofitDiagnostics.php \
        tests/Unit/Tenancy/RawPdoScopingLintTest.php \
        tests/Integration/Tenancy/Retrofit/RetrofitDiagnosticsTest.php \
        tests/Integration/Collections/CollectionsTenancySchemaTest.php
# HOLD.
```

---

### Task 2: `CollectionPhysicalName` helper

**Files:**
- Create: `packages/thallo-collections/src/Schema/CollectionPhysicalName.php`
- Test: `tests/Unit/Collections/CollectionPhysicalNameTest.php`

**Interfaces:**
- Produces:
  - `CollectionPhysicalName::tenantToken(string $tenantUuid): string` — lowercase RFC 4648 base32 (no padding) of `hash('sha256', $tenantUuid, true)`, first 10 chars.
  - `CollectionPhysicalName::generate(string $tenantUuid): string` — `tc_<tenantToken>_<random12>` (random from `[a-z0-9]`), asserted ≤ 63 chars.
  - `CollectionPhysicalName::isValid(string $tableName): bool` — matches `^tc_[a-z2-7]{10}_[a-z0-9]{12}$`.
  - `CollectionPhysicalName::belongsToTenant(string $tableName, string $tenantUuid): bool` — valid AND prefix `tc_<tenantToken>_`.
  - `CollectionPhysicalName::indexName(string $tableName, string $fieldName, string $kind): string` — deterministic, ≤ 63 bytes: readable bounded prefix + short hash suffix over `{table_name,field_name,kind}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Collections/CollectionPhysicalNameTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collections;

use PHPUnit\Framework\TestCase;
use Thallo\Collections\Schema\CollectionPhysicalName;

final class CollectionPhysicalNameTest extends TestCase
{
    public function testTenantTokenIsDeterministicLowercaseBase32(): void
    {
        $t = CollectionPhysicalName::tenantToken('tenantAAAAAA');
        self::assertSame($t, CollectionPhysicalName::tenantToken('tenantAAAAAA'));
        self::assertSame(10, strlen($t));
        self::assertMatchesRegularExpression('/^[a-z2-7]{10}$/', $t);
    }

    public function testGenerateMatchesPatternAndBelongsToTenant(): void
    {
        $name = CollectionPhysicalName::generate('tenantAAAAAA');
        self::assertMatchesRegularExpression('/^tc_[a-z2-7]{10}_[a-z0-9]{12}$/', $name);
        self::assertLessThanOrEqual(63, strlen($name));
        self::assertTrue(CollectionPhysicalName::belongsToTenant($name, 'tenantAAAAAA'));
        self::assertFalse(CollectionPhysicalName::belongsToTenant($name, 'tenantBBBBBB'));
    }

    public function testIndexNameIsBoundedAndDeterministic(): void
    {
        $a = CollectionPhysicalName::indexName('tc_aaaaaaaaaa_bbbbbbbbbbbb', 'a_very_long_field_name', 'unique');
        self::assertSame($a, CollectionPhysicalName::indexName('tc_aaaaaaaaaa_bbbbbbbbbbbb', 'a_very_long_field_name', 'unique'));
        self::assertLessThanOrEqual(63, strlen($a));
        $b = CollectionPhysicalName::indexName('tc_aaaaaaaaaa_bbbbbbbbbbbb', 'another_long_field_name', 'unique');
        self::assertNotSame($a, $b);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=CollectionPhysicalNameTest`
Expected: FAIL — class absent.

- [ ] **Step 3: Create the helper**

Create `packages/thallo-collections/src/Schema/CollectionPhysicalName.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

/**
 * Owns every derivation of a collection's physical identifiers so create and purge agree exactly
 * and PostgreSQL's 63-byte limit is never breached. Physical names are OPAQUE — human names live
 * only in collection_definitions. A raw tenant nano-id must never appear in an identifier.
 */
final class CollectionPhysicalName
{
    private const TABLE_RE = '/^tc_[a-z2-7]{10}_[a-z0-9]{12}$/';
    private const RFC4648_BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    /** Deterministic, lowercase, identifier-safe 10-char tenant token from the tenant uuid. */
    public static function tenantToken(string $tenantUuid): string
    {
        $bytes = hash('sha256', $tenantUuid, true);

        return substr(self::base32(($bytes)), 0, 10);
    }

    /** A fresh physical table name for a collection owned by $tenantUuid. */
    public static function generate(string $tenantUuid): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $token = '';
        for ($i = 0; $i < 12; $i++) {
            $token .= $alphabet[random_int(0, 35)];
        }
        $name = 'tc_' . self::tenantToken($tenantUuid) . '_' . $token;
        if (strlen($name) > 63) {
            throw new \LogicException('Collection physical name exceeds 63 bytes.');
        }

        return $name;
    }

    public static function isValid(string $tableName): bool
    {
        return preg_match(self::TABLE_RE, $tableName) === 1;
    }

    public static function belongsToTenant(string $tableName, string $tenantUuid): bool
    {
        return self::isValid($tableName)
            && str_starts_with($tableName, 'tc_' . self::tenantToken($tenantUuid) . '_');
    }

    /** Deterministic ≤63-byte index name for a (table, field, kind) triple. */
    public static function indexName(string $tableName, string $fieldName, string $kind): string
    {
        $suffix = substr(hash('sha256', $tableName . '|' . $fieldName . '|' . $kind), 0, 12);
        $prefix = substr($tableName, 0, 40);

        return $prefix . '_' . $suffix . '_' . ($kind === 'unique' ? 'u' : 'i');
    }

    /** RFC 4648 base32 (lowercase, no padding) of raw bytes. */
    private static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= self::RFC4648_BASE32[bindec($chunk)];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=CollectionPhysicalNameTest`
Expected: PASS.

- [ ] **Step 5: Stage (HOLD)**

```bash
git add packages/thallo-collections/src/Schema/CollectionPhysicalName.php \
        tests/Unit/Collections/CollectionPhysicalNameTest.php
# HOLD.
```

---

### Task 3: `SingleStoreTenant` (canonical resolver + provisioner)

**Files:**
- Create: `packages/thallo-tenancy/src/Tenant/SingleStoreTenant.php`
- Modify: pack ServiceProvider — register it.
- Test: `tests/Integration/Tenancy/SingleStoreTenantTest.php`

**Interfaces:**
- Produces:
  - `SingleStoreTenant::resolve(): string` — enabled→current request tenant (fail closed if absent, never the pointer); compat→`CompatWriteScope` default; disabled→the ensured single-store tenant. Missing identity throws a clear infra error.
  - `SingleStoreTenant::ensure(string $slug, string $name, string $ownerUserUuid): string` — transactional; `pg_advisory_xact_lock(hashtextextended('thallo:single-store-tenant', 0))`; reload pointers after the lock; reuse persisted provisioning uuid or record a new one; `TenantProvisioner::provisionDefault()`; persist `tenancy.default_tenant_uuid`; all writes in one transaction (rollback leaves nothing partial). Adopts an existing tenant only when it equals the recorded pointer; unrelated tenants fail closed.
- Consumes: `SystemFlags`, `TenantProvisioner`, `CurrentTenantResolver`, `Connection` (all available from Task 0).

- [ ] **Step 1: Verify the mode + current-tenant seams**

Read `packages/thallo-tenancy/src/System/SystemFlags.php` and `vendor/glueful/tenancy/src/Bridge/ContractTenantResolver.php`. Pin the existing contract exactly: `CurrentTenantResolver::tenantUuid(ApplicationContext): string`, where `''` means no resolved tenant. `SystemFlags::defaultTenantUuid()` is the sole off/compat pointer accessor.

- [ ] **Step 2: Write the failing test**

Create `tests/Integration/Tenancy/SingleStoreTenantTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

final class SingleStoreTenantTest extends AppTestCase
{
    protected function tearDown(): void
    {
        // Clean provisioned single-store tenant + flags between tests.
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM tenant_memberships");
        $pdo->exec("DELETE FROM tenants");
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key LIKE 'tenancy.%'");
        parent::tearDown();
    }

    public function testEnsureIsIdempotentAndTransactional(): void
    {
        $svc = $this->container()->get(SingleStoreTenant::class);
        $admin = $this->createUser('single-store-admin@example.com');

        $a = $svc->ensure('default', 'Default', $admin);
        $b = $svc->ensure('default', 'Default', $admin);
        self::assertSame($a, $b, 'ensure() is idempotent by the recorded pointer');

        // Exactly one tenant + one owner membership.
        self::assertSame(1, (int) $this->connection()->getPDO()->query('SELECT count(*) FROM tenants')->fetchColumn());
    }

    public function testResolveReturnsEnsuredTenantWhenDisabled(): void
    {
        $svc = $this->container()->get(SingleStoreTenant::class);
        $ensured = $svc->ensure('default', 'Default', $this->createUser('resolve-admin@example.com'));
        self::assertSame($ensured, $svc->resolve());
    }

    public function testEnsureJoinsOuterTransactionAndRollsBackEverything(): void
    {
        $svc = $this->container()->get(SingleStoreTenant::class);
        $admin = $this->createUser('rollback-admin@example.com');
        $pdoId = spl_object_id($this->connection()->getPDO());
        try {
            $this->connection()->transaction(function () use ($svc, $admin, $pdoId): void {
                self::assertSame($pdoId, spl_object_id($this->connection()->getPDO()));
                $svc->ensure('default', 'Default', $admin);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        self::assertSame(0, (int) $this->connection()->getPDO()->query('SELECT count(*) FROM tenants')->fetchColumn());
        $this->container()->get(SystemFlags::class)->clearCache();
        self::assertNull($this->container()->get(SystemFlags::class)->defaultTenantUuid());
    }
}
```

Add a local `createUser(string $email): string` helper using the real user repository so the membership foreign key is exercised. Concurrent-ensure convergence is added in Task 12, where the harness drives two independent PostgreSQL sessions.

- [ ] **Step 3: Create the service**

Create `packages/thallo-tenancy/src/Tenant/SingleStoreTenant.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Tenant;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The single deterministic tenant owner for tenant-scoped subsystems (collections) across every
 * tenancy mode. Canonical provisioner: DefaultTenant delegates here so retrofit + enablement adopt
 * the same tenant established at install.
 */
final class SingleStoreTenant
{
    private const KEY_PROVISIONING = 'tenancy.provisioning_tenant_uuid';
    private const KEY_DEFAULT = 'tenancy.default_tenant_uuid';
    private const LOCK_KEY = 'thallo:single-store-tenant';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
        private readonly TenantProvisioner $provisioner,
        private readonly CurrentTenantResolver $current,
    ) {
    }

    /** The tenant that owns collections for this request. Fail-closed; never a silent fallback. */
    public function resolve(): string
    {
        if ($this->flags->tenancyEnabled()) {
            $tenant = $this->current->tenantUuid($this->context);
            if ($tenant === '') {
                throw new \RuntimeException('Tenancy is enabled but no tenant was resolved for this request.');
            }
            return $tenant; // NEVER fall back to the default pointer once enabled
        }

        $default = $this->flags->get(self::KEY_DEFAULT);
        if ($default === null || $default === '') {
            throw new \RuntimeException(
                'No single-store tenant is established. Run the single-store repair command.'
            );
        }

        return $default;
    }

    /** Provision (or resume) the single-store tenant, transactionally, under an advisory lock. */
    public function ensure(string $slug, string $name, string $ownerUserUuid): string
    {
        return db($this->context)->transaction(function () use ($slug, $name, $ownerUserUuid): string {
            db($this->context)->getPDO()
                ->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))')
                ->execute([self::LOCK_KEY]);

            // Reload AFTER the lock so a concurrent ensure() we waited on is observed.
            $this->flags->clearCache();
            $existingDefault = $this->flags->defaultTenantUuid();
            if ($existingDefault !== null && $existingDefault !== '') {
                $intended = $this->flags->get(self::KEY_PROVISIONING);
                if ($intended !== null && $intended !== '' && $intended !== $existingDefault) {
                    throw new \RuntimeException('Single-store tenant pointers disagree.');
                }
                // Repair-safe: re-assert the tenant + owner membership rather than trusting a pointer.
                return $this->provisioner->provisionDefault(
                    $this->context,
                    $existingDefault,
                    $slug,
                    $name,
                    $ownerUserUuid,
                );
            }

            $uuid = $this->flags->get(self::KEY_PROVISIONING);
            if ($uuid === null || $uuid === '') {
                if ($this->provisioner->hasAnyTenant($this->context)) {
                    throw new PreexistingTenantException();
                }
                $uuid = Utils::generateNanoID(12);
                $this->flags->put(self::KEY_PROVISIONING, $uuid);
            }

            $tenantUuid = $this->provisioner->provisionDefault(
                $this->context,
                $uuid,
                $slug,
                $name,
                $ownerUserUuid,
            );
            $this->flags->put(self::KEY_DEFAULT, $tenantUuid);

            return $tenantUuid;
        });
    }
}
```

- [ ] **Step 4: Register the service(s)**

Add `SingleStoreTenant` to `packages/thallo-tenancy/src/TenancyServiceProvider.php` as a shared autowired service. Task 0 guarantees `CurrentTenantResolver` and `TenantProvisioner` are bound.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=SingleStoreTenantTest`
Expected: PASS.

- [ ] **Step 6: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Tenant/ packages/thallo-tenancy/src/TenancyServiceProvider.php \
        tests/Integration/Tenancy/SingleStoreTenantTest.php
# HOLD.
```

---

### Task 4: `DefaultTenant` delegates + `SetupService` eager provisioning

**Files:**
- Modify: `packages/thallo-tenancy/src/Retrofit/DefaultTenant.php`
- Create: `packages/thallo-tenancy/src/Console/SingleStoreRepairCommand.php`
- Modify: `app/Setup/SetupService.php`
- Test: `tests/Integration/Tenancy/SingleStoreTenantTest.php` (append) + `tests/Integration/Setup/SetupServiceTest.php` (append) + `tests/Integration/Tenancy/SingleStoreRepairCommandTest.php`

**Interfaces:**
- Consumes: `SingleStoreTenant::ensure` (Task 3).
- Produces: `DefaultTenant::ensure()` returns the same tenant as `SingleStoreTenant`; `SetupService::install()` establishes the single-store tenant right after the admin, owner = the install admin.

- [ ] **Step 1: Write the failing tests**

Append to `SetupServiceTest.php` (which already truncates users/user_roles/settings — extend teardown to also clear tenants/memberships/flags):

```php
    public function testInstallEstablishesSingleStoreTenant(): void
    {
        $this->service()->install(
            siteName: 'S', adminEmail: 'admin@example.com',
            adminPassword: 'S3cur3P@ssw0rd!', locale: 'en',
        );
        $default = $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class)->defaultTenantUuid();
        self::assertNotNull($default, 'install must establish the single-store tenant');
        self::assertSame(
            1,
            (int) $this->connection()->getPDO()->query('SELECT count(*) FROM tenants')->fetchColumn(),
        );
    }
```

Append to `SingleStoreTenantTest.php`:

```php
    public function testDefaultTenantDelegatesToSingleStore(): void
    {
        $single = $this->container()->get(SingleStoreTenant::class);
        $admin = $this->createUser('delegate-admin@example.com');
        $established = $single->ensure('default', 'Default', $admin);

        $default = $this->container()->get(\Thallo\Tenancy\Retrofit\DefaultTenant::class);
        self::assertSame($established, $default->ensure('default', 'Default', $admin));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter=SetupServiceTest` and `--filter=SingleStoreTenantTest`
Expected: FAIL — install doesn't provision; `DefaultTenant` still standalone.

- [ ] **Step 3: Refactor `DefaultTenant::ensure()` to delegate**

Replace the provisioning body of `DefaultTenant::ensure()` with a delegation to `SingleStoreTenant` (inject it), preserving the persisted-pointer + pre-existing-block semantics now centralized in `SingleStoreTenant`:

```php
    public function __construct(
        private readonly \Thallo\Tenancy\Tenant\SingleStoreTenant $singleStore,
    ) {
    }

    public function ensure(string $slug, string $name, string $ownerUserUuid): string
    {
        return $this->singleStore->ensure($slug, $name, $ownerUserUuid);
    }

    public function uuid(): ?string
    {
        return $this->singleStore->defaultUuidOrNull();
    }
```

Add `SingleStoreTenant::defaultUuidOrNull(): ?string` (reads `tenancy.default_tenant_uuid`). Move the `PreexistingTenantException` refusal into `SingleStoreTenant::ensure` (adopt an existing tenant only when it equals the recorded pointer; otherwise throw it). Update the pack ServiceProvider's `DefaultTenant` registration to the new single-arg constructor.

- [ ] **Step 4: Add eager provisioning to `SetupService`**

In `app/Setup/SetupService.php`, after role assignment and before any starter definitions are applied (inside the existing install transaction), call `$tenantUuid = SingleStoreTenant::ensure('default', $siteName, $userUuid)`. Inject `SingleStoreTenant` and replace the existing `new SeedContext('', ...)` with `new SeedContext($tenantUuid, ...)`. This ordering establishes and explicitly carries tenant identity before every starter write; no off-mode hook is expected to fill it. Extend `SetupServiceTest` to assert seeded content types/settings/regions carry the established uuid and rollback together with the tenant if a later seed fails.

- [ ] **Step 5: Add the explicit repair command**

Create `thallo:tenancy:single-store:repair --owner=<user-uuid> [--slug=default] [--name=Default]`. It validates that the owner user exists, calls `SingleStoreTenant::ensure`, prints the established uuid, and exits non-zero on the pre-existing/coherence refusal. It is the only repair surface; `CollectionManager` never provisions as a side effect. Test success, missing owner, unrelated pre-existing tenant, and idempotent rerun.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter=SetupServiceTest`, `--filter=SingleStoreTenantTest`, and `--filter=SingleStoreRepairCommandTest`
Expected: PASS.

- [ ] **Step 7: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Retrofit/DefaultTenant.php \
        packages/thallo-tenancy/src/Tenant/SingleStoreTenant.php \
        packages/thallo-tenancy/src/Console/SingleStoreRepairCommand.php \
        packages/thallo-tenancy/src/TenancyServiceProvider.php \
        app/Setup/SetupService.php \
        tests/Integration/Tenancy/SingleStoreTenantTest.php \
        tests/Integration/Tenancy/SingleStoreRepairCommandTest.php \
        tests/Integration/Setup/SetupServiceTest.php
# HOLD.
```

---

### Task 5: Add `tenantUuid` to `CollectionDefinition` + tenant-scope the repository

**Files:**
- Modify: `packages/thallo-collections/src/Schema/CollectionDefinition.php`
- Modify: `packages/thallo-collections/src/Repositories/CollectionDefinitionRepository.php`
- Modify: `packages/thallo-collections/src/CollectionManager.php`
- Modify: `packages/thallo-collections/src/Http/CollectionScopeMiddleware.php`, `Http/CollectionAccessResolver.php`
- Modify: `packages/thallo-collections/src/Relations/RelationResolver.php`
- Modify: all tests constructing `CollectionDefinition` or calling the repository directly.
- Test: `tests/Integration/Collections/CollectionDefinitionRepositoryTenantTest.php`

**Interfaces:**
- Produces:
  - `CollectionDefinition` gains `public readonly string $tenantUuid` (first constructor param after `uuid`); `fromRow()` reads `tenant_uuid`.
  - Repository methods take an explicit `string $tenantUuid` and constrain it: `findByName(string $tenantUuid, string $name)`, `findByUuid(string $tenantUuid, string $uuid)`, `all(string $tenantUuid)`, `insert(CollectionDefinition)` (writes `tenant_uuid`), `update(...)` (WHERE includes `tenant_uuid`), `delete(string $tenantUuid, string $uuid)`.
- Consumes: nothing new.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Collections/CollectionDefinitionRepositoryTenantTest.php` — insert two definitions with the same `name` under different `tenant_uuid`, assert `findByName($tenantA,'posts')` and `findByName($tenantB,'posts')` return distinct rows and that `all($tenantA)` excludes tenant B's. (Insert via the repository; use two literal tenant uuids and real `table_name`s from `CollectionPhysicalName::generate`.)

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=CollectionDefinitionRepositoryTenantTest`
Expected: FAIL — repo has no tenant param; VO has no tenantUuid.

- [ ] **Step 3: Add `tenantUuid` to the VO**

In `CollectionDefinition.php`, add `public readonly string $tenantUuid` as the second constructor parameter (after `uuid`), and in `fromRow()` read `(string) ($row['tenant_uuid'] ?? '')` and pass it. Update every `new CollectionDefinition(...)` call site and every copy/rebuild path in the same task; no constructor is left temporarily broken.

- [ ] **Step 4: Tenant-scope the repository**

In `CollectionDefinitionRepository.php`, thread `tenant_uuid`:

```php
    public function insert(CollectionDefinition $def): void
    {
        $this->connection->table('collection_definitions')->insert([
            'uuid' => $def->uuid,
            'tenant_uuid' => $def->tenantUuid,
            // ... existing columns ...
        ]);
    }

    public function update(CollectionDefinition $def, ?int $expectedSchemaVersion = null): int
    {
        $query = $this->connection->table('collection_definitions')
            ->where('uuid', $def->uuid)
            ->where('tenant_uuid', $def->tenantUuid);
        if ($expectedSchemaVersion !== null) {
            $query->where('schema_version', $expectedSchemaVersion);
        }
        // ... existing update payload ...
    }

    public function delete(string $tenantUuid, string $uuid): void
    {
        $this->connection->table('collection_definitions')
            ->where('tenant_uuid', $tenantUuid)
            ->where('uuid', $uuid)
            ->delete();
    }

    public function findByName(string $tenantUuid, string $name): ?CollectionDefinition
    {
        $row = $this->connection->table('collection_definitions')
            ->where('tenant_uuid', $tenantUuid)
            ->where('name', $name)
            ->first();
        return $row === null ? null : CollectionDefinition::fromRow($row);
    }

    public function findByUuid(string $tenantUuid, string $uuid): ?CollectionDefinition
    {
        $row = $this->connection->table('collection_definitions')
            ->where('tenant_uuid', $tenantUuid)
            ->where('uuid', $uuid)
            ->first();
        return $row === null ? null : CollectionDefinition::fromRow($row);
    }

    /** @return list<CollectionDefinition> */
    public function all(string $tenantUuid): array
    {
        $rows = $this->connection->table('collection_definitions')
            ->where('tenant_uuid', $tenantUuid)->get();
        return array_map(static fn (array $r): CollectionDefinition => CollectionDefinition::fromRow($r), $rows);
    }
```

- [ ] **Step 5: Run the test to verify it passes**

- [ ] **Step 5: Migrate every repository caller before declaring green**

Inject `SingleStoreTenant` into the manager, `CollectionScopeMiddleware`, and `RelationResolver` where they load definitions. Each call resolves once and passes the explicit tenant UUID to `findByName`/`findByUuid`/`all`/`delete`. Update direct test callers. This is the mechanical scoping/signature migration only; Task 6 replaces physical-name creation/retry and Task 7 hardens every physical consumer. Run `rg 'findBy(Name|Uuid)\(|->all\(\)|->delete\(' packages/thallo-collections tests` and classify every hit; no old global repository signature may remain.

- [ ] **Step 6: Run focused and full collections tests**

Run: `vendor/bin/phpunit --filter=CollectionDefinitionRepositoryTenantTest` and the existing collections test directory. Expected: PASS; this task must not knowingly leave callers uncompilable.

- [ ] **Step 7: Stage (HOLD)**

```bash
git add packages/thallo-collections/src/Schema/CollectionDefinition.php \
        packages/thallo-collections/src/Repositories/CollectionDefinitionRepository.php \
        packages/thallo-collections/src/CollectionManager.php \
        packages/thallo-collections/src/Http/CollectionScopeMiddleware.php \
        packages/thallo-collections/src/Relations/RelationResolver.php \
        tests/Integration/Collections/CollectionDefinitionRepositoryTenantTest.php
# HOLD.
```

---

### Task 6: `CollectionManager` — tenant-resolved create/alter/drop + retry loop

**Files:**
- Modify: `packages/thallo-collections/src/CollectionManager.php`
- Test: `tests/Integration/Collections/CollectionManagerTenantTest.php`

**Interfaces:**
- Consumes: `SingleStoreTenant::resolve()`, `CollectionPhysicalName`, tenant-scoped repository (Task 5), `CollectionDefinition.tenantUuid`.
- Produces: `create()`/`addField()`/`addIndex()`/`removeIndex()`/`dropField()`/`dropCollection()`/`setAccessPolicy()`/`setFieldOrder()` all resolve the tenant first, generate physical names via the helper, retry `table_name` collisions in a fresh transaction bounded to 5 attempts (only SQLSTATE 23505 on `uniq_collection_def_table_name`), and pass `tenantUuid` on every definition.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Collections/CollectionManagerTenantTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Schema\CollectionPhysicalName;

final class CollectionManagerTenantTest extends AppTestCase
{
    // teardown drops any tc_* tables + clears collection metadata + tenants/flags

    public function testTwoTenantsMayShareACollectionName(): void
    {
        $runner = $this->container()->get(TenantContextRunner::class);
        $manager = $this->container()->get(CollectionManager::class);
        $a = $this->createTenant('tenantA00001');
        $b = $this->createTenant('tenantB00001');
        $defA = $runner->runAsTenant($a, fn () => $manager->create($this->postsPayload(), 'admin', 'userA000001'));
        $defB = $runner->runAsTenant($b, fn () => $manager->create($this->postsPayload(), 'admin', 'userB000001'));

        self::assertNotSame($defA->tableName, $defB->tableName);
        self::assertTrue(CollectionPhysicalName::belongsToTenant($defA->tableName, $a));
        self::assertTrue(CollectionPhysicalName::belongsToTenant($defB->tableName, $b));
        self::assertTrue($this->connection()->getSchemaBuilder()->hasTable($defA->tableName));
        self::assertTrue($this->connection()->getSchemaBuilder()->hasTable($defB->tableName));
    }
}
```

Use the exact neutral contract `Glueful\Extensions\Contracts\Tenancy\TenantContextRunner`; add concrete `createTenant()` and `postsPayload()` fixtures using the existing tenancy test helpers. Add a second test that forces the first generated token to collide, proves a fresh transaction retries, and proves a `uniq_collection_def_tenant_name` violation is not retried.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=CollectionManagerTenantTest`
Expected: FAIL — manager still global.

- [ ] **Step 3: Thread the tenant + retry loop through the manager**

In `CollectionManager`, inject `SingleStoreTenant`. Replace the static `tableNameFor()` derivation and the global `findByName` calls. Rewrite `create()`:

```php
    public function create(array $payload, string $actorType, ?string $actorId): CollectionDefinition
    {
        $this->validateCreate($payload, $tenantUuid = $this->singleStore->resolve());

        $name  = (string) $payload['name'];
        $label = isset($payload['label']) ? (string) $payload['label'] : $this->deriveLabel($name);
        $uuid  = PublicId::generate('col');
        $fields = /* unchanged field mapping */;
        $accessPolicy = /* unchanged */;
        $fieldOrder = /* unchanged */;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $tableName = CollectionPhysicalName::generate($tenantUuid);
            $def = new CollectionDefinition(
                uuid: $uuid, tenantUuid: $tenantUuid, name: $name, label: $label,
                tableName: $tableName, storageMode: 'table', fields: $fields,
                schemaVersion: 1, status: 'active', accessPolicy: $accessPolicy, fieldOrder: $fieldOrder,
            );
            try {
                $this->connection->transaction(function () use ($def, $actorType, $actorId): void {
                    $this->repo->insert($def);
                    $this->materializer->apply($def, $this->planner->planCreate($def), $actorType, $actorId);
                });
                $this->events->dispatch(new CollectionCreated($name, $tenantUuid, $actorType, $actorId));
                return $def;
            } catch (\PDOException $e) {
                if ($this->isTableNameCollision($e) && $attempt < 5) {
                    continue; // fresh transaction next iteration; the aborted one already rolled back
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Could not allocate a unique collection table name after 5 attempts.');
    }

    private function isTableNameCollision(\PDOException $e): bool
    {
        return ($e->getCode() === '23505')
            && str_contains((string) ($e->errorInfo[2] ?? ''), 'uniq_collection_def_table_name');
    }
```

`validateCreate()` gains a `$tenantUuid` arg and calls `$this->repo->findByName($tenantUuid, $name)` for the duplicate-name check. `loadOrFail()` becomes `loadOrFail($tenantUuid, $name)` calling the scoped `findByName`, and every mutator (`addField`/`addIndex`/`removeIndex`/`dropField`/`dropCollection`/`setAccessPolicy`/`setFieldOrder`) resolves `$tenantUuid = $this->singleStore->resolve()` and passes it to `loadOrFail`/`repo->delete`. `rebuildWith()`/`setAccessPolicy()`/`setFieldOrder()` preserve `tenantUuid`. `dropCollection()` additionally asserts `CollectionPhysicalName::belongsToTenant($current->tableName, $tenantUuid)` before the `DROP TABLE`. Delete the static `tableNameFor()` (no longer used) or repoint it — grep for external callers first (tests/tooling) and update them to read `def->tableName`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter=CollectionManagerTenantTest`
Expected: PASS.

- [ ] **Step 5: Stage (HOLD)**

```bash
git add packages/thallo-collections/src/CollectionManager.php \
        tests/Integration/Collections/CollectionManagerTenantTest.php
# HOLD.
```

---

### Task 7: Resolved-definition-only across data/relation/DDL consumers + `tenantUuid` on events

**Files:**
- Modify: `packages/thallo-collections/src/Relations/RelationResolver.php`
- Modify: `packages/thallo-collections/src/Data/RowRepository.php`
- Modify: `packages/thallo-collections/src/CollectionManager.php` (direct schema-change audit write)
- Modify: `packages/thallo-collections/src/Schema/SchemaMaterializer.php`, `Schema/DdlPlanner.php`, `Schema/ColumnMapper.php`
- Modify: `packages/thallo-collections/src/Http/Controllers/CollectionDataController.php`, `CollectionAdminSchemaController.php`, `Http/CollectionAccessResolver.php`
- Modify: the collection `Events/*` (add `tenantUuid`)
- Test: `tests/Integration/Collections/CollectionCrossTenantIsolationTest.php`

**Interfaces:**
- Consumes: `SingleStoreTenant::resolve()`, tenant-scoped repository, `CollectionDefinition.tenantUuid`, `CollectionPhysicalName::indexName`.
- Produces: every consumer resolves the tenant-scoped definition (never a caller table string); `RelationResolver` resolves relations only within the current tenant's definitions; index DDL uses `CollectionPhysicalName::indexName`; collection events carry `tenantUuid`.

- [ ] **Step 1: Verify the consumers**

Read the verified consumers: `RelationResolver`'s global definition scan, `RowRepository`'s stored-table use, `SchemaMaterializer`'s implicit add-index and derived drop-index names, `DdlPlanner`, both controllers, and `CollectionAccessResolver`. Record every entry point that accepts or derives a physical table name before editing; the resulting inventory is asserted by the resolved-definition-only regression test.

- [ ] **Step 2: Write the failing isolation test**

Create `tests/Integration/Collections/CollectionCrossTenantIsolationTest.php`: create `products` for tenant A (with a row) and `products` for tenant B; assert (a) a data read as tenant B cannot see tenant A's row; (b) `RelationResolver` expanding a reference resolves only tenant B's `products`; (c) a `CollectionCreated`/row event carries the acting `tenantUuid`; (d) every schema-change row carries matching tenant + collection ownership and a deliberately mismatched tenant cannot update its status; (e) long, similar field names create and drop distinct explicitly named indexes. Drive each block under `runAsTenant`.

- [ ] **Step 3: Retarget `RelationResolver`**

Change its global definition scan to resolve `$tenantUuid = $this->singleStore->resolve()` and call the tenant-scoped `repo->all($tenantUuid)` / `findByName($tenantUuid, …)`. It must never load a definition outside the current tenant.

- [ ] **Step 4: Thread resolved definitions through data + DDL**

`RowRepository`, `SchemaMaterializer`, `DdlPlanner`, and `ColumnMapper` operate on the passed `CollectionDefinition` (or its stored `tableName`) only — never re-derive from a human name and never accept a caller-supplied table string. Controllers (`CollectionDataController`, `CollectionAdminSchemaController`) resolve the tenant-scoped definition first and pass the object down; `CollectionAccessResolver` resolves within the current tenant.

Every `collection_schema_changes` insert writes `tenant_uuid` beside `collection_uuid`. Pending→failed and pending→applied updates constrain all three of `tenant_uuid`, `collection_uuid`, and audit `uuid`. `CollectionManager::setAccessPolicy()`'s direct audit insert carries the same ownership pair.

For index DDL, compute `$idxName = CollectionPhysicalName::indexName($def->tableName, $field->name, $op->indexKind)` once. Pass that explicit name to both `$t->unique($colName, $idxName)`/`$t->index($colName, $idxName)` and the matching `dropUnique($idxName)`/`dropIndex($idxName)`. The current add path has no explicit name, so changing only the drop-side string derivation is insufficient.

- [ ] **Step 5: Add `tenantUuid` to events**

Add a `string $tenantUuid` field to each collection event constructor (`CollectionCreated`, `CollectionUpdated`, `CollectionDropped`, `CollectionRowCreated`, `CollectionRowUpdated`, `CollectionRowDeleted`, `CollectionTruncated`) and pass the acting tenant at every dispatch site (the manager + row controllers). Update `Audit/CollectionAuditListener` if it reads a positional arg.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=CollectionCrossTenantIsolationTest`
Expected: PASS.

- [ ] **Step 7: Stage (HOLD)**

```bash
git add packages/thallo-collections/src/Relations/RelationResolver.php \
        packages/thallo-collections/src/Data/RowRepository.php \
        packages/thallo-collections/src/Schema/ \
        packages/thallo-collections/src/Http/ \
        packages/thallo-collections/src/Events/ \
        packages/thallo-collections/src/Audit/ \
        tests/Integration/Collections/CollectionCrossTenantIsolationTest.php
# HOLD.
```

---

### Task 8: `thallo_tenant_api_key_bindings` table + repository

**Files:**
- Create: `packages/thallo-tenancy/migrations/003_CreateTenantApiKeyBindingsTable.php`
- Modify: `scripts/run-test-migrations.php` only if the existing package-directory registration does not already discover the new migration.
- Create: `packages/thallo-tenancy/src/ApiKeyBinding/TenantApiKeyBindingRepository.php`
- Modify: pack ServiceProvider — register the repository.
- Test: `tests/Integration/Tenancy/TenantApiKeyBindingTest.php`

**Interfaces:**
- Produces:
  - Table `thallo_tenant_api_key_bindings`: `id` PK, `api_key_uuid` (unique), `tenant_uuid`, `created_at`, `updated_at`; index on `tenant_uuid`; FKs → `api_keys.uuid` and `tenants.uuid` cascade delete (backstop; explicit deletes still happen). **Not** in `ThalloTenantTables`.
  - `TenantApiKeyBindingRepository::bind(apiKeyUuid, tenantUuid)`, `unbind(apiKeyUuid)`, `tenantFor(apiKeyUuid): ?string`, `copyBinding(fromApiKeyUuid, toApiKeyUuid)`, `bindingsForTenant(tenantUuid): list<string>`.
- Consumes: `api_keys` table (verify its PK column is `uuid` — grep the api-keys migration).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Tenancy/TenantApiKeyBindingTest.php`: bind a key→tenant, assert `tenantFor` returns it; `copyBinding` to a successor key; `unbind` removes it. (Seed `api_keys`/`tenants` rows the FKs require, or disable FK checks if the harness lacks those rows — prefer seeding real rows.)

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=TenantApiKeyBindingTest`
Expected: FAIL — table + repo absent.

- [ ] **Step 3: Create the migration**

Create `packages/thallo-tenancy/migrations/003_CreateTenantApiKeyBindingsTable.php` (001 and 002 are the verified existing prefixes):

```php
<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * System-global tenant binding for API keys — the ONLY authority that lets a headless request
 * select a workspace via an explicit tenant id. Not tenant-owned; never in ThalloTenantTables.
 */
class CreateTenantApiKeyBindingsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('thallo_tenant_api_key_bindings')) {
            return;
        }
        $schema->createTable('thallo_tenant_api_key_bindings', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('api_key_uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->unique('api_key_uuid');
            $table->index('tenant_uuid');
            $table->foreign('api_key_uuid')->references('uuid')->on('api_keys')->cascadeOnDelete();
            $table->foreign('tenant_uuid')->references('uuid')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_tenant_api_key_bindings');
    }

    public function getDescription(): string
    {
        return 'Creates the tenant↔API-key binding table for headless collection tenant selection.';
    }
}
```

(Verify the `api_keys` PK column name is `uuid` and its char width — grep the api-keys migration; match the FK type. If api_keys uses a different key, adjust.)

- [ ] **Step 4: Create the repository**

Create `packages/thallo-tenancy/src/ApiKeyBinding/TenantApiKeyBindingRepository.php` with `bind`/`unbind`/`tenantFor`/`copyBinding`/`bindingsForTenant`, each a direct `db($context)->table('thallo_tenant_api_key_bindings')` op. `bind` is an explicit operator-authorized transfer and upserts `api_key_uuid`; `copyBinding` reads the source tenant and inserts the new key binding without changing the source. Constructor takes `ApplicationContext`.

- [ ] **Step 5: Register + migrate + run**

Register the repo in the pack ServiceProvider. `composer test:migrate`, then run the test. Expected: PASS.

- [ ] **Step 6: Stage (HOLD)** — the migration, repo, provider, test.

---

### Task 9: Collections tenant-binding middleware + binding lifecycle

**Files:**
- Create: `packages/thallo-tenancy/src/Http/Middleware/CollectionsTenantBindingMiddleware.php`
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php`
- Modify: `app/Http/Controllers/ApiKeyAdminController.php`, `app/Http/DTOs/CreateApiKeyData.php`
- Create: `app/Http/DTOs/UpdateApiKeyTenantData.php`
- Modify: `app/Http/DTOs/Responses/ApiKeyData.php`, `routes/admin.php`
- Modify: `admin/src/queries/apiKeys.ts`
- Regenerate: `docs/openapi.json`, `admin/src/api/schema.d.ts`
- Modify: `admin/src/pages/developers/api-keys/components/ApiKeyCreateModal.vue`, `ApiKeyDetailPane.vue`
- Test: `tests/Integration/Tenancy/CollectionsTenantBindingMiddlewareTest.php`
- Test: `tests/Integration/Http/ApiKeyTenantBindingLifecycleTest.php`
- Test: `admin/src/__tests__/apiKeyTenantBinding.spec.ts`

**Interfaces:**
- Consumes: `TenantApiKeyBindingRepository` (Task 8), the `optional_api_key` middleware's `api_key_uuid` request attribute, the host-resolved tenant, `TenantContextRunner::runAsTenant`.
- Produces: middleware `collections_tenant_binding` — anonymous requests reject `X-Tenant-Id` and continue to host/bootstrap resolution; a keyed request must have exactly one binding, every host/header candidate must equal it, and success wraps the remaining pipeline in `TenantContextRunner::runAsTenant($bound, ...)`. The API-key surface exposes explicit operator-only bind/unbind, carries the binding through rotation, removes it on revocation, and renders it in the existing SPA.

- [ ] **Step 1: Verify the seams**

Pin the verified seams: `OptionalApiKeyAuthMiddleware` sets `api_key_uuid`; `CurrentTenantResolver::tenantUuid($context)` returns the host/profile-resolved tenant or `''`; `TenantContextRunner::runAsTenant(string, callable): mixed`; `ApiKeyService::{create,rotate,revoke}` use the shared application connection/model context. Record whether their entity-event listeners participate in the outer transaction; the lifecycle test below must prove no binding or successor key survives rollback.

- [ ] **Step 2: Write the failing test**

Create `CollectionsTenantBindingMiddlewareTest`: assert keyed/matching passes in the bound context; key/header mismatch, key/host mismatch, and unbound key fail before definition lookup; anonymous + `X-Tenant-Id` fails; anonymous without a header continues so the following host/bootstrap middleware remains authoritative.

Create `ApiKeyTenantBindingLifecycleTest`: HTTP-create with a tenant writes one binding; explicit PATCH bind/unbind requires both `system.access` and `tenancy.manage`; rotation creates the successor and copies its binding in one outer transaction; forced binding failure rolls back the successor and old-key expiry; revocation and unbind commit together; scope edits preserve binding; response presentation includes `tenant_uuid`/`tenant_name` without leaking another field.

- [ ] **Step 3: Create the middleware**

Create `CollectionsTenantBindingMiddleware` implementing `RouteMiddleware`. If `api_key_uuid` is absent, reject any non-empty `X-Tenant-Id` and otherwise call `$next`; full-resolution host strictness is enforced by the surrounding `tenant_profile:public,soft` + `tenant_bootstrap` chain, while off/bootstrap-default modes retain their single-store behavior. If a verified key is present, require exactly one binding; compare it with `CurrentTenantResolver::tenantUuid($context)` when non-empty and with a supplied `X-Tenant-Id`; reject any mismatch before `$next`; then `return $runner->runAsTenant($bound, fn () => $next($request));`.

- [ ] **Step 4: Binding lifecycle on the API-key admin surface**

Add optional `tenant_uuid` to `CreateApiKeyData`, `tenant_uuid`/`tenant_name` to `ApiKeyData`, and `PATCH /v1/admin/api-keys/{uuid}/tenant` with `UpdateApiKeyTenantData {tenant_uuid:?string}`. Keep list/create/rotate/revoke under their existing `system.access` gate; any create-with-binding and the PATCH binding route additionally require `tenancy.manage` (controller enforcement for the conditional create case, two explicit middleware checks for PATCH). Wrap create+bind, rotate+copy, and revoke+unbind in `Connection::transaction()` on the same application connection. A binding failure propagates and rolls the key mutation back. Scope edits do not touch bindings.

Update `present()` to left-resolve the binding and tenant label without N+1 queries. In the SPA, add a workspace selector to `ApiKeyCreateModal`, display the current binding in `ApiKeyDetailPane`, and provide explicit bind/change/unbind controls only to callers whose tenancy access store reports `manage_platform`; server authorization remains authoritative. Extend `apiKeys.ts` types/mutations and add the named Vitest coverage.

Run `composer docs:openapi`, then `cd admin && pnpm gen:api` before implementing the typed query changes; stage both generated artifacts with the task.

- [ ] **Step 5: Register + alias the middleware**

Register `CollectionsTenantBindingMiddleware` in the pack ServiceProvider and alias it as `collections_tenant_binding` (match how the pack aliases its other route middleware).

- [ ] **Step 6: Run the test to verify it passes**

Run both PHP test classes, then `cd admin && pnpm test -- apiKeyTenantBinding.spec.ts && pnpm type-check`. Expected: PASS.

- [ ] **Step 7: Stage (HOLD).**

---

### Task 10: Remove the fence, wire routes, add role-matrix permissions

**Files:**
- Delete: `packages/thallo-tenancy/src/Runtime/CollectionsDisabledWhenTenantMiddleware.php`
- Modify: `packages/thallo-collections/routes/collections.php`
- Modify: `packages/thallo-collections/routes/admin-routes.php`
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php`
- Modify: `config/tenancy.php`
- Test: `tests/Integration/Collections/CollectionsRouteAuthTest.php`

**Interfaces:**
- Produces: the single public/headless route group runs `tenant_profile:public,soft → optional_api_key → collections_tenant_binding → tenant_bootstrap → collection_scope`; admin routes run `auth → tenant_profile:admin → tenant_bootstrap → permission`. `config/tenancy.php` grants the three seeded collection permissions to owner/admin only.

- [ ] **Step 1: Write the failing test**

Create `CollectionsRouteAuthTest`: enumerate both route files and assert the exact middleware order; tenancy-enabled anonymous public access on a verified host reaches a public-policy collection; anonymous `X-Tenant-Id` is rejected; an unbound key is rejected; a bound key works without a host and cannot override a conflicting host/header; admin routes no longer 503 and resolve through the admin tenant chain. Assert owner/admin receive all three collection capabilities and member/viewer receive none.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=CollectionsRouteAuthTest`
Expected: FAIL — fence still present / matrix lacks the grants.

- [ ] **Step 3: Remove the fence + its wiring**

Delete `CollectionsDisabledWhenTenantMiddleware.php`; remove its alias/registration from `packages/thallo-tenancy/src/TenancyServiceProvider.php`; remove `collections_disabled_when_tenant` from **both** `routes/collections.php` and `routes/admin-routes.php`.

- [ ] **Step 4: Wire the route groups**

Do not duplicate `/v1/collections` registrations. Its one group becomes `['tenant_profile:public,soft', 'optional_api_key', 'collections_tenant_binding', 'tenant_bootstrap']`; the existing per-route `collection_scope` and rate-limit middleware remain after the group chain. In `admin-routes.php`, replace the old group middleware with `['auth', 'tenant_profile:admin', 'tenant_bootstrap']`; retain each existing `content_permission:collections.*` annotation.

- [ ] **Step 5: Add the capabilities to the role matrix**

The permissions already exist in `packages/thallo-collections/migrations/003_SeedCollectionsPermissions.php`. Add `collections.manage`, `collections.schema.manage`, and `collections.data.manage` to the `owner` and `admin` arrays in `config/tenancy.php`; do not add a permission migration and do not grant them to member/viewer.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=CollectionsRouteAuthTest`
Expected: PASS.

- [ ] **Step 7: Stage (HOLD).**

---

### Task 11: `CollectionsPurgeHandler` made real

**Files:**
- Modify: `packages/thallo-collections/composer.json`
- Modify: root `composer.lock` (refresh path-package metadata; no unrelated updates)
- Create: `packages/thallo-collections/src/Purge/CollectionsPurgeHandler.php`
- Modify: `packages/thallo-collections/src/CollectionsServiceProvider.php`
- Delete: `packages/thallo-tenancy/src/Purge/Handlers/CollectionsPurgeHandler.php`
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php`
- Modify: `packages/thallo-tenancy/src/Purge/PurgeHandler.php`, `PurgeJob.php`
- Modify: `packages/thallo-tenancy/src/Purge/Handlers/TablesPurgeHandler.php`, `MediaPurgeHandler.php`, `CachePurgeHandler.php`
- Modify: `tests/Integration/Tenancy/PurgePipelineTest.php`, `tests/Unit/Tenancy/PurgeResourceRegistryTest.php`
- Test: `tests/Integration/Tenancy/CollectionsPurgeHandlerTest.php`

**Interfaces:**
- Consumes: tenant-scoped metadata, `TenantApiKeyBindingRepository`, `CollectionPhysicalName`, and the slice-2 durable artifact map.
- Produces: a one-way package dependency (`thallo-collections` → `thallo-tenancy`); an optional `thallo.collections.purge_handler` alias; `PurgeHandler::verify(..., array $artifacts)`; prepared-artifact-specific verification of tables, both metadata tables, and bindings.

- [ ] **Step 1: Correct the package boundary and write failing tests**

Add `glueful/thallo-tenancy: *` to `packages/thallo-collections/composer.json`. Move the implementation owner to `Thallo\Collections\Purge\CollectionsPurgeHandler`; delete the no-op tenancy handler. The collections provider publishes it with alias `thallo.collections.purge_handler`. The tenancy registry factory conditionally registers that alias when present and never imports a `Thallo\Collections` class.

Refresh only the locked path package metadata with `composer update glueful/thallo-collections --with-dependencies` and inspect the lock diff; abort if it changes unrelated third-party versions.

Create `CollectionsPurgeHandlerTest`: create collections and bindings for tenants A and B; persist A's prepared artifacts through the real purge-run repository; run prepare→purge→verify; assert only A's exact prepared tables, both metadata tables, and bindings disappear. Assert a corrupted artifact naming B's table is refused, an absent prepared table is an idempotent retry, an extra unrelated `tc_*` table does not make A's verification fail, B survives, and `TablesPurgeHandler::prepare()` excludes both collection metadata tables.

- [ ] **Step 2: Extend verification to receive durable artifacts**

Change the internal interface to:

```php
public function verify(
    ApplicationContext $context,
    string $tenantUuid,
    array $artifacts,
): bool;
```

Update `PurgeJob` to call `verify($context, $tenantUuid, $artifacts[$handlerId] ?? [])`. Update Tables, Media, and Cache handlers plus their tests to accept the third argument. Add `collection_definitions` and `collection_schema_changes` to `TablesPurgeHandler::SPECIALIZED` so the generic row-purge handler never prepares, purges, or verifies resources now solely owned by the collections handler. This is an internal Thallo-pack API, not a framework/extension-contract release.

- [ ] **Step 3: Implement prepare + purge with strict artifact validation**

`prepare()` returns `tenant_uuid`, a de-duplicated list of `{definition_uuid,table_name}` tuples ordered by definition uuid, and binding UUIDs. `purge()` first validates that artifact tenant equals the target and that each tuple is structurally complete and unique. For every table: require `CollectionPhysicalName::isValid`, `belongsToTenant($table,$tenantUuid)`, and tuple membership; when the table exists, require the live three-way metadata row before `DROP TABLE IF EXISTS`. Keep metadata until every drop succeeds, then delete `collection_schema_changes`, `collection_definitions`, and bindings for the target tenant.

- [ ] **Step 4: Implement artifact-specific verification**

```php
    public function verify(
        ApplicationContext $context,
        string $tenantUuid,
        array $artifacts,
    ): bool
    {
        $pdo = db($context)->getPDO();
        foreach ($this->validatedTables($tenantUuid, $artifacts) as $tuple) {
            $exists = $pdo->prepare('SELECT to_regclass(?)');
            $exists->execute([$tuple['table_name']]);
            if ($exists->fetchColumn() !== null) {
                return false;
            }
        }
        foreach (['collection_schema_changes', 'collection_definitions', 'thallo_tenant_api_key_bindings'] as $table) {
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE tenant_uuid = ? LIMIT 1");
            $stmt->execute([$tenantUuid]);
            if ($stmt->fetchColumn() !== false) {
                return false;
            }
        }
        return true;
    }
```

`validatedTables()` is shared by purge and verify, rejects duplicate/malformed tuples and an artifact tenant mismatch, and performs pattern + target-token validation before any identifier interpolation. Verification checks only prepared tables, never a wildcard token scan.

- [ ] **Step 5: Run purge regression**

Run `CollectionsPurgeHandlerTest`, `PurgePipelineTest`, and `PurgeResourceRegistryTest`. The pipeline test exercises all built-in handlers with the new artifact-bearing verify call. Expected: PASS.

- [ ] **Step 6: Stage (HOLD).**

```bash
git add composer.lock \
        packages/thallo-collections/composer.json \
        packages/thallo-collections/src/Purge/CollectionsPurgeHandler.php \
        packages/thallo-collections/src/CollectionsServiceProvider.php \
        packages/thallo-tenancy/src/Purge/Handlers/CollectionsPurgeHandler.php \
        packages/thallo-tenancy/src/Purge/PurgeHandler.php \
        packages/thallo-tenancy/src/Purge/PurgeJob.php \
        packages/thallo-tenancy/src/Purge/Handlers/TablesPurgeHandler.php \
        packages/thallo-tenancy/src/Purge/Handlers/MediaPurgeHandler.php \
        packages/thallo-tenancy/src/Purge/Handlers/CachePurgeHandler.php \
        packages/thallo-tenancy/src/TenancyServiceProvider.php \
        tests/Integration/Tenancy/CollectionsPurgeHandlerTest.php \
        tests/Integration/Tenancy/PurgePipelineTest.php \
        tests/Unit/Tenancy/PurgeResourceRegistryTest.php
# HOLD.
```

---

### Task 12: Migrate existing collections tests + dev-adoption, regression, index update

**Files:**
- Modify: existing `tests/**/Collections*` suites (run under a resolved tenant).
- Modify: `docs/superpowers/specs/multi-tenancy/LIFECYCLE-GAPS-README.md` (or the Bucket-2 tracker) + `docs/operations/` (dev-adoption note).
- Test: full backend + the new acceptance suite.

**Interfaces:** none (verification + docs).

- [ ] **Step 1: Migrate existing collections tests**

Task 5 already migrated repository signatures. Update remaining fixtures/setUp paths to establish the single-store tenant or act under an explicit tenant (`runAsTenant`), and remove assumptions about `coll_<name>` physical names. Run the full existing collections test directory until green.

- [ ] **Step 2: Add the acceptance suite**

Create `tests/Integration/Collections/CollectionsTenancyAcceptanceTest.php` covering the §11 items not yet asserted: concurrent `ensure()` convergence under two independent PostgreSQL sessions; identifier-safety (create and purge derive identical tenant tokens; long/similar field names get distinct ≤63-byte index names); one shared route surface serving verified-host anonymous public data and bound-key headless data without cross-selection; a corrupted purge artifact refused while another tenant survives. The same-connection rollback proof already lives in Task 3 and must remain green.

- [ ] **Step 3: Dev-adoption procedure (documented, local-only)**

Document in `docs/operations/` a one-time local procedure: drop legacy `coll_*` tables + their `collection_definitions`/`collection_schema_changes` rows, reset the dev DB from zero, and recreate collections under the tenant-scoped path. No shipped data migration. (The `lemma`/fresh DB has none; only a dev DB with pre-existing `coll_*` needs it.)

- [ ] **Step 4: Full regression**

Run: `composer test` (tenancy off) and the tenancy-on suite.
Expected: PASS — new collections-tenancy suites green; slice-1/2/3, resolution, and existing collections suites still green. Investigate any failure with systematic-debugging.

- [ ] **Step 5: phpcs + SPA + package manifests**

Run: `composer phpcs`, `composer boundaries`, `composer validate`, and from `admin/`: `pnpm type-check && pnpm test` (no tail piping). Assert `thallo-collections` declares `thallo-tenancy`, while `thallo-tenancy` has no collections dependency or `Thallo\Collections` import.

- [ ] **Step 6: Update the tracker + porting note**

Mark Bucket 2 item 2C implemented (HELD) in the tracking index. Record: **Thallo-only, no external release** (uses bundled-active `glueful/tenancy ^1.3.0` identity services; enforcement remains flag-controlled). Record the one-way collections→tenancy package dependency and replacement of the tenancy no-op purge handler by the optional collections-owned implementation.

- [ ] **Step 7: Stage (HOLD)** — do not commit until the user gives the go-ahead.

---

## Self-Review

**1. Spec coverage:**
- Bundled-active identity plane with flag-gated enforcement → Task 0. ✅
- §1 Model A (per-tenant tables + metadata scoping) → Tasks 1, 5, 6, 7. ✅
- §2 single-store tenant (cross-mode resolve, fail-closed enabled, transactional ensure + advisory lock + delegation + eager setup, explicit owner-identified operator repair) → Tasks 3, 4. ✅
- §3 opaque token naming + metadata schema (named uniques, folded columns) → Tasks 1, 2, 6. ✅
- §4 resolved-definition-only inventory + create retry (23505 on the table-name unique only) + self-scoped repos → Tasks 5, 6, 7. ✅
- §5 tenant-selection security (host / API-key binding middleware / admin chain) + binding lifecycle → Tasks 8, 9. ✅
- §6 fence removal + route order + role-matrix grants (owner/admin only) → Task 10. ✅
- §7 purge (pattern + target-token + artifact-tuple + live 3-way ownership + DROP IF EXISTS + tenant-specific verify + binding cleanup) vs single-collection drop → Tasks 11, 6. ✅
- §8 `ThalloTenantTables` (two metadata tables only) + `RawPdoScopingLintTest` PER_TENANT_PHYSICAL + retrofit-idempotence → Task 1. ✅
- §9 Thallo-only + dev adoption → Task 12. ✅
- §10/§11 failure modes + testing → each task's tests + Task 12 acceptance. ✅
- §12 out of scope (JWT, finer member perms, runtime coll_* conversion) → not implemented. ✅

**2. Placeholder scan:** No unresolved migration prefix, invented task reference, placeholder path, or incomplete test remains. Migration prefix `003`, provider paths, route files, neutral resolver/runner signatures, permission source, API-key DTO/controller/SPA files, and purge interface consumers are pinned explicitly.

**3. Type consistency:** `SingleStoreTenant::resolve()/ensure()/defaultUuidOrNull()` consistent across Tasks 3, 4, 6, 7. `CollectionDefinition` `tenantUuid` (2nd ctor param) consistent Tasks 5, 6, 7. Repository tenant-first signatures (`findByName($tenantUuid,$name)`, `findByUuid`, `all`, `delete($tenantUuid,$uuid)`) consistent Tasks 5, 6, 7, 11. `CollectionPhysicalName::tenantToken/generate/isValid/belongsToTenant/indexName` consistent Tasks 2, 6, 7, 11. `TenantApiKeyBindingRepository::bind/unbind/tenantFor/copyBinding/bindingsForTenant` consistent Tasks 8, 9, 11.

**4. Task integrity:** Every task finishes with compiling consumers and a green focused suite. Task 5 updates all repository callers atomically; Task 11 updates the purge interface, job, every handler, and tests together. No task intentionally leaves a broken signature for a later task. ✅
