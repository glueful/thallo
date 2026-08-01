# Thallo Multi-Tenancy SP1 — Phase B1: Pack Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Stand up the `thallo-tenancy` capability pack — its scaffold + capability, the unscoped system-global channel, the single `ThalloTenantTables` registry, and table registration gated behind the compound boot gate — so nothing scopes until tenancy is turned on.

**Architecture:** A standard Thallo path-package pack (`Thallo\Tenancy`, `type: glueful-extension`). Its dependency posture is precise: it **hard-depends** on the *contract* package `glueful/extension-contracts` (compile-time seams `TenantTableRegistry`/`CurrentTenantResolver`/`TenantContextRunner`) and on `glueful/thallo-contracts` (`Capability`) and `glueful/framework` — but it **soft-depends** on the tenancy *implementation* `glueful/tenancy`, which it NEVER requires in composer and only ever reaches through a runtime container-binding check. A new unscoped `thallo_system_flags` table (readable before any tenant resolves) holds the runtime `tenancy.enabled` + `schema_state` + default-tenant pointer. At boot the pack registers Thallo's owned tables via the contract **only when** the contract binding is present **and** `thallo_system_flags` says tenancy is enabled.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5, Glueful pack conventions (`ServiceProvider`, `CapabilityRegistry`, `MigrationInterface`), `glueful/extension-contracts`.

**Spec:** [../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md](../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md) §5.1, §6, §7.3.

## Global Constraints

- Work on `dev` directly. No AI/Anthropic attribution. **Hold all commits until explicit go-ahead.**
- `declare(strict_types=1)`, `final class`, constructor DI, `use`-imports (no inline FQCNs).
- `composer phpcs` clean before a task is done.
- Pack namespace `Thallo\Tenancy\…`; provider `Thallo\Tenancy\TenancyServiceProvider` (distinct from the framework extension's `Glueful\Extensions\Tenancy\TenancyServiceProvider`).
- Pack config must **not** merge under the `tenancy` config key (owned by the `glueful/tenancy` extension). Runtime scoping state lives in `thallo_system_flags`, not `config()`.
- **Dev linkage (execution prerequisite):** Thallo's `vendor/` must expose the local `glueful/framework` (1.66.3 + A2), `glueful/extension-contracts` (with `TenantContextRunner`), and `glueful/tenancy` (A3/A4). Set up the same reversible symlink way as Phase A (back up originals under gitignored `vendor/`). `glueful/extension-contracts` is a NEW Thallo dependency — add it to the pack's `require` and make it resolvable (path repo or symlink) at execution time.

---

### Task B1.1: Pack scaffold + capability + activation

**Files:**
- Create: `packages/thallo-tenancy/composer.json`
- Create: `packages/thallo-tenancy/src/TenancyServiceProvider.php`
- Create: `packages/thallo-tenancy/config/tenancy.php` (pack config, merged under a NON-colliding key)
- Modify: `composer.json` (root — add path repo + require)
- Modify: `config/extensions.php` (add provider FQCN to `enabled`)
- Test: `tests/Unit/Tenancy/TenancyPackScaffoldTest.php`

**Interfaces:**
- Produces: `Thallo\Tenancy\TenancyServiceProvider` (registers capability `thallo.tenancy`, loads pack migrations). Later tasks add `services()` entries + the boot gate.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Tenancy/TenancyPackScaffoldTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Thallo\Tenancy\TenancyServiceProvider;

final class TenancyPackScaffoldTest extends TestCase
{
    public function testProviderExposesAServicesMap(): void
    {
        // services() must be a static array map (the DSL loader rejects anything else).
        self::assertIsArray(TenancyServiceProvider::services());
    }

    public function testProviderIsRegisteredForActivation(): void
    {
        $enabled = require dirname(__DIR__, 3) . '/config/extensions.php';
        self::assertContains(
            'Thallo\\Tenancy\\TenancyServiceProvider',
            $enabled['enabled'] ?? [],
            'the tenancy pack provider must be in config/extensions.php enabled[]',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && vendor/bin/phpunit tests/Unit/Tenancy/TenancyPackScaffoldTest.php`
Expected: FAIL — `Class "Thallo\Tenancy\TenancyServiceProvider" not found`.

- [ ] **Step 3: Create `packages/thallo-tenancy/composer.json`**

Hard requires: `glueful/thallo-contracts` (Capability), `glueful/extension-contracts` (the tenancy *contract seams*), `glueful/framework`. It deliberately does **not** require `glueful/tenancy` (the implementation) — that stays a runtime soft dependency, guarded by a container-binding check.

```json
{
  "name": "glueful/thallo-tenancy",
  "description": "Multi-tenancy foundation for Thallo: tenant-owned schema, scoping, seed/sync and enablement as a capability pack.",
  "type": "glueful-extension",
  "license": "MIT",
  "authors": [
    { "name": "Michael Tawiah Sowah", "email": "michael@glueful.dev" }
  ],
  "version": "0.1.0",
  "require": {
    "php": "^8.3",
    "glueful/thallo-contracts": "*",
    "glueful/extension-contracts": "*",
    "glueful/framework": "^1.66.3"
  },
  "autoload": {
    "psr-4": { "Thallo\\Tenancy\\": "src/" }
  },
  "extra": {
    "glueful": {
      "provider": "Thallo\\Tenancy\\TenancyServiceProvider"
    }
  },
  "minimum-stability": "stable"
}
```

- [ ] **Step 4: Create the pack config `packages/thallo-tenancy/config/tenancy.php`**

Merged under the `thallo_tenancy` key (NOT `tenancy` — that belongs to the extension). Minimal for now:
```php
<?php

declare(strict_types=1);

// Merged under config('thallo_tenancy.*'). Runtime scoping state is NOT here — it lives in the
// thallo_system_flags table (readable before tenant resolution); this file holds only static
// pack config.
return [
    // Reserved for future static config (e.g. default seed options).
];
```

- [ ] **Step 5: Create `packages/thallo-tenancy/src/TenancyServiceProvider.php`**

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;

final class TenancyServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        // System-channel store + owned-table registry are registered here in later tasks.
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge under a non-colliding key.
        $this->mergeConfig('thallo_tenancy', require __DIR__ . '/../config/tenancy.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'thallo.tenancy',
            label: 'Multi-tenancy',
            description: 'Tenant-owned content model + data, scoping, seed/sync and enablement.',
        ));

        // Migrations load unconditionally (outside any gate) so the system-channel table exists
        // for every install — the retrofit that adds tenant_uuid is NOT here (it is an
        // enable-time operation, spec §7.4).
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'thallo-tenancy',
        );

        // Table registration behind the compound gate is added in Task B1.4.
    }
}
```

- [ ] **Step 6: Wire the pack into the root `composer.json`**

Add to `repositories` (with the other path repos):
```json
    { "type": "path", "url": "packages/thallo-tenancy" },
```
Add to `require` (with the other `glueful/thallo-*`):
```json
    "glueful/thallo-tenancy": "*",
```

- [ ] **Step 7: Activate the pack in `config/extensions.php`**

Add the provider FQCN string to the `enabled` array (plain string, no `::class`):
```php
    'Thallo\\Tenancy\\TenancyServiceProvider',
```

**Route-gating policy (pinned now; B1 registers NO routes — this governs SP3/Phase E).** Do not let route exposure ride on the capability's "absent means on" default. The pack will register two distinct route groups under two independent gates:
- **Enablement / status routes** (turn tenancy on, read enable progress): **always registered**, guarded only by a system/super-admin permission — they MUST be reachable while tenancy is off (that's how you turn it on) and while the capability default may change.
- **Tenant-management routes** (tenant CRUD, membership, switcher): registered **only when** `CapabilityRegistry::isEnabled('thallo.tenancy')` AND `SystemFlags::tenancyEnabled()` — they only make sense once tenancy is actually live.

So activating the pack here exposes nothing sensitive: B1 adds no routes, and the future route wiring keys off explicit gates, not the capability default. (Scoping itself remains gated solely by `thallo_system_flags`, off by default.)

- [ ] **Step 8: Refresh autoload + run the test**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && composer dump-autoload -q && vendor/bin/phpunit tests/Unit/Tenancy/TenancyPackScaffoldTest.php`
Expected: PASS (2 tests). If the class isn't found, ensure the path repo + `require` are present and `composer update glueful/thallo-tenancy --no-interaction` (or a symlink) has made it resolvable.

- [ ] **Step 9: phpcs**

Run: `composer phpcs 2>&1 | tail` (and the pack's own if it has one). Expected: clean.

- [ ] **Step 10: Commit** (HOLD)

```bash
git add packages/thallo-tenancy composer.json config/extensions.php tests/Unit/Tenancy/TenancyPackScaffoldTest.php
git commit -m "Scaffold thallo-tenancy capability pack"
```

---

### Task B1.2: System-global channel (`thallo_system_flags` + `SystemFlags`)

**Files:**
- Create: `packages/thallo-tenancy/migrations/001_CreateSystemFlagsTable.php`
- Create: `packages/thallo-tenancy/src/System/SystemFlags.php`
- Test: `tests/Integration/Tenancy/SystemFlagsTest.php`

**Interfaces:**
- Produces: `Thallo\Tenancy\System\SystemFlags` with `get(string): ?string`, `put(string,string): void`, `forget(string): void`, `tenancyEnabled(): bool`, `schemaState(): string` (`none|widened`), `defaultTenantUuid(): ?string`. Backed by an **unscoped** `thallo_system_flags` (key/value) table that is NEVER in `ThalloTenantTables`. Reads tolerate a missing table (fresh install) → treated as "off".

**Context:** models the existing `App\Settings\SettingsStore` K/V pattern, but for system-global (non-tenant) state that must be readable before tenant resolution.

- [ ] **Step 1: Write the failing test**

`tests/Integration/Tenancy/SystemFlagsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\System\SystemFlags;

final class SystemFlagsTest extends AppTestCase
{
    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    public function testDefaultsWhenNothingSet(): void
    {
        self::assertFalse($this->flags()->tenancyEnabled());
        self::assertSame('none', $this->flags()->schemaState());
        self::assertNull($this->flags()->defaultTenantUuid());
    }

    public function testPutGetForgetRoundTrip(): void
    {
        $flags = $this->flags();
        $flags->put('tenancy.enabled', '1');
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', 'ten000000001');

        // Re-read through a fresh instance to prove it persisted (not just memoized).
        $fresh = $this->container()->get(SystemFlags::class);
        self::assertTrue($fresh->tenancyEnabled());
        self::assertSame('widened', $fresh->schemaState());
        self::assertSame('ten000000001', $fresh->defaultTenantUuid());

        $fresh->forget('tenancy.enabled');
        self::assertFalse($this->container()->get(SystemFlags::class)->tenancyEnabled());
    }
}
```

> If `SystemFlags` is `shared` in the container, `get()` returns the same instance — construct a second directly (`new SystemFlags($context)`) instead of via the container for the "fresh instance" assertions, or add a `clearCache()` like `SettingsStore` and call it. Adjust the test to whichever the implementation uses; the intent is "persisted, not just memoized."

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && vendor/bin/phpunit tests/Integration/Tenancy/SystemFlagsTest.php`
Expected: FAIL — class/table not found.

- [ ] **Step 3: Create the migration**

`packages/thallo-tenancy/migrations/001_CreateSystemFlagsTable.php`:
```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Unscoped system-global key/value store. Holds runtime tenancy state that MUST be readable
 * before any tenant resolves (tenancy.enabled, tenancy.schema_state, the default-tenant pointer,
 * enable-job progress). It is NEVER registered in ThalloTenantTables — it has no tenant_uuid.
 */
final class CreateSystemFlagsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('thallo_system_flags')) {
            return;
        }

        $schema->createTable('thallo_system_flags', function ($table): void {
            $table->string('key', 120)->primary();
            $table->text('value')->nullable();
            $table->string('updated_at', 32)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_system_flags');
    }

    public function getDescription(): string
    {
        return 'Create thallo_system_flags: unscoped system-global key/value store for tenancy runtime state.';
    }
}
```

> Verify the exact `SchemaBuilderInterface` method names against a sibling migration (e.g. `packages/thallo-seo/migrations/001_CreateSeoMetaTable.php`) — `createTable`/`hasTable`/`dropTableIfExists` and the column builders (`string`/`text`/`nullable`/`primary`) — and match them. Also confirm whether `MigrationInterface` requires `getDescription()` (SEO's migration implements it).

- [ ] **Step 4: Create `SystemFlags`**

`packages/thallo-tenancy/src/System/SystemFlags.php`:
```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\System;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Thin key/value store over the unscoped `thallo_system_flags` table — the runtime tenancy state
 * that must be readable before tenant resolution. Modeled on App\Settings\SettingsStore, but
 * system-global (never tenant-scoped). Missing table (fresh install) reads as "off".
 */
final class SystemFlags
{
    private const KEY_ENABLED = 'tenancy.enabled';
    private const KEY_SCHEMA_STATE = 'tenancy.schema_state';
    private const KEY_DEFAULT_TENANT = 'tenancy.default_tenant_uuid';

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    public function put(string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = db($this->context)->table('thallo_system_flags')->where(['key' => $key])->first();
        if ($existing === null) {
            db($this->context)->table('thallo_system_flags')
                ->insert(['key' => $key, 'value' => $value, 'updated_at' => $now]);
        } else {
            db($this->context)->table('thallo_system_flags')->where(['key' => $key])
                ->update(['value' => $value, 'updated_at' => $now]);
        }
        $this->cache = null;
    }

    public function forget(string $key): void
    {
        db($this->context)->table('thallo_system_flags')->where(['key' => $key])->delete();
        $this->cache = null;
    }

    public function tenancyEnabled(): bool
    {
        return $this->get(self::KEY_ENABLED) === '1';
    }

    /** @return 'none'|'widened' */
    public function schemaState(): string
    {
        return $this->get(self::KEY_SCHEMA_STATE) === 'widened' ? 'widened' : 'none';
    }

    public function defaultTenantUuid(): ?string
    {
        $uuid = $this->get(self::KEY_DEFAULT_TENANT);
        return ($uuid === null || $uuid === '') ? null : $uuid;
    }

    public function clearCache(): void
    {
        $this->cache = null;
    }

    /** @return array<string,string> */
    private function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        // Fresh install: the table may not exist yet — treat ONLY that case as empty (=> off).
        // We check existence explicitly rather than catching every throwable, so a real DB
        // outage on the read below propagates instead of masquerading as "tenancy off".
        if (!db($this->context)->getSchemaBuilder()->hasTable('thallo_system_flags')) {
            return $this->cache = [];
        }

        $out = [];
        foreach (db($this->context)->table('thallo_system_flags')->get() as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '') {
                $out[$key] = (string) ($row['value'] ?? '');
            }
        }
        return $this->cache = $out;
    }
}
```

> Confirm `db($context)->getSchemaBuilder()->hasTable(...)` is the right accessor (it's used in `TenancyTestCase` as `$connection->getSchemaBuilder()` and in migrations as `$schema->hasTable(...)`). Write paths (`put`/`forget`) are intentionally NOT guarded — they run only after enablement has created the table, so a failure there should surface loudly (fail closed), not be swallowed.

- [ ] **Step 5: Register `SystemFlags` in the pack `services()`**

In `TenancyServiceProvider::services()`:
```php
        return [
            \Thallo\Tenancy\System\SystemFlags::class => [
                'class' => \Thallo\Tenancy\System\SystemFlags::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
```
(Use a `use` import for `SystemFlags` and the short name in the map, per the no-inline-FQCN rule.)

- [ ] **Step 6: Run the migration locally + the test**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && php glueful migrate:run 2>&1 | tail -5 && vendor/bin/phpunit tests/Integration/Tenancy/SystemFlagsTest.php`
Expected: migration creates `thallo_system_flags`; test PASS. (Migration run is a LOCAL step — never committed.)

- [ ] **Step 7: Pin the regression — system-channel writes stay usable + unstamped under active scoping**

The whole point of the system channel is that it remains readable/writable before tenant resolution and is never tenant-stamped. Add this test method to `SystemFlagsTest` (guarded — it needs the framework A2 seam + tenancy stamper, present once Thallo's `vendor/` is dev-linked):

```php
    public function testWritesAreNotStampedWhileTheTenantInsertHookIsActive(): void
    {
        if (!method_exists(\Glueful\Database\Connection::class, 'addInsertHook')) {
            self::markTestSkipped('Framework lacks the A2 insert-hook seam (pinned at release).');
        }

        // Arm the REAL tenant stamper and establish a request context (deliberately with NO tenant):
        // for a tenant-OWNED table this setup would fail closed, but thallo_system_flags is not
        // owned, so the stamper returns early — proving the channel is excluded from scoping.
        \Glueful\Extensions\Tenancy\Context\CurrentContext::set($this->context());
        \Glueful\Database\Connection::addInsertHook(
            \Glueful\Extensions\Tenancy\Query\TenantInsertStamper::hook()
        );

        try {
            $this->container()->get(SystemFlags::class)->put('tenancy.enabled', '1');

            $row = db($this->context())->table('thallo_system_flags')->where(['key' => 'tenancy.enabled'])->first();
            self::assertSame('1', $row['value'], 'system-channel write still works under active hooks');
            self::assertArrayNotHasKey('tenant_uuid', $row, 'system-channel row was never tenant-stamped');
        } finally {
            \Glueful\Database\Connection::clearInsertHooks();
            \Glueful\Extensions\Tenancy\Context\CurrentContext::clear();
        }
    }
```
(FQCNs inline here only because they're test-local one-offs; if the pack's test style prefers imports, add `use` statements. Run: `vendor/bin/phpunit tests/Integration/Tenancy/SystemFlagsTest.php` → all pass, this one skips only on a pre-A2 framework.)

- [ ] **Step 8: phpcs + commit** (HOLD)

Run: `composer phpcs 2>&1 | tail`. Then:
```bash
git add packages/thallo-tenancy/migrations packages/thallo-tenancy/src/System packages/thallo-tenancy/src/TenancyServiceProvider.php tests/Integration/Tenancy/SystemFlagsTest.php
git commit -m "Add unscoped system-global channel (thallo_system_flags + SystemFlags)"
```

---

### Task B1.3: `ThalloTenantTables` registry

**Files:**
- Create: `packages/thallo-tenancy/src/ThalloTenantTables.php`
- Test: `tests/Unit/Tenancy/ThalloTenantTablesTest.php`

**Interfaces:**
- Produces: `Thallo\Tenancy\ThalloTenantTables` with `::all(): array<string,array{...}>` (per-table metadata) and `::tableNames(): list<string>`. Metadata per spec §6: `tenant_column`, `kind` (`definition|instance`), `widened_uniques`, `indexes`, `special_backfill`. This is the SINGLE source consumed by registration (B1.4), the retrofit (Phase C), diagnostics (Phase F), and tests.

**Content:** the owned-table set + widenings verified against migrations (spec §7.2). Collections (`collection_definitions`, `collection_schema_changes`, dynamic collection tables) are **excluded** (spec §6). `uuid` nano-id uniques stay global. `regions`/`settings`/`entry_redirects` carry a `special_backfill: 'rebuild'` marker (PK/inline-unique reconstruction).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Tenancy/ThalloTenantTablesTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Thallo\Tenancy\ThalloTenantTables;

final class ThalloTenantTablesTest extends TestCase
{
    public function testCoreOwnedTablesArePresent(): void
    {
        $names = ThalloTenantTables::tableNames();
        foreach (['content_types', 'entries', 'entry_routes', 'block_types', 'regions', 'settings', 'form_submissions'] as $t) {
            self::assertContains($t, $names, "$t must be tenant-owned");
        }
    }

    public function testCollectionsAreExcluded(): void
    {
        $names = ThalloTenantTables::tableNames();
        self::assertNotContains('collection_definitions', $names);
        self::assertNotContains('collection_schema_changes', $names);
    }

    public function testSystemChannelTableIsNotOwned(): void
    {
        // thallo_system_flags MUST stay unscoped — it is read/written before tenant resolution.
        self::assertNotContains('thallo_system_flags', ThalloTenantTables::tableNames());
    }

    public function testSettingsIsInstanceNotDefinition(): void
    {
        // Site settings are per-tenant DATA/config, not schema definition (affects divergence checks).
        self::assertSame('instance', ThalloTenantTables::all()['settings']['kind']);
    }

    public function testEveryEntryCarriesRequiredMetadata(): void
    {
        foreach (ThalloTenantTables::all() as $table => $meta) {
            self::assertSame('tenant_uuid', $meta['tenant_column'], "$table tenant_column");
            self::assertContains($meta['kind'], ['definition', 'instance'], "$table kind");
            self::assertIsArray($meta['widened_uniques'], "$table widened_uniques");
            self::assertIsArray($meta['indexes'], "$table indexes");
        }
    }

    public function testRebuildTablesAreMarked(): void
    {
        $all = ThalloTenantTables::all();
        foreach (['regions', 'settings', 'entry_redirects'] as $t) {
            self::assertSame('rebuild', $all[$t]['special_backfill'] ?? null, "$t needs a rebuild backfill");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && vendor/bin/phpunit tests/Unit/Tenancy/ThalloTenantTablesTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `ThalloTenantTables`**

`packages/thallo-tenancy/src/ThalloTenantTables.php`:
```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy;

/**
 * The single source of truth for Thallo's tenant-owned tables + their retrofit metadata.
 *
 * Consumed by table registration (boot gate), the schema retrofit (Phase C), diagnostics
 * (Phase F), and tests — NO table list is hand-maintained anywhere else.
 *
 * Excludes collections (collection_definitions/collection_schema_changes + dynamic collection
 * tables): their table_name names a PHYSICAL table and is globally unique, so per-tenant
 * collections are a dedicated follow-up (SP4), not folded into the foundation (spec §6).
 *
 * `widened_uniques`: each entry is [name|null, columns[]] where columns is the NEW composite
 * (tenant_uuid first). uuid nano-id uniques stay GLOBAL and are not listed. `special_backfill`
 * = 'rebuild' marks tables needing PK/inline-unique reconstruction (no surrogate id, or an
 * inline ->unique()); everything else is an additive column add.
 */
final class ThalloTenantTables
{
    /**
     * @return array<string, array{
     *   tenant_column: string,
     *   kind: 'definition'|'instance',
     *   widened_uniques: list<array{0: string|null, 1: list<string>}>,
     *   indexes: list<string>,
     *   special_backfill: string|null
     * }>
     */
    public static function all(): array
    {
        $def = 'definition';
        $inst = 'instance';

        return [
            // --- core definitions ---
            'content_types' => self::row($def, [[null, ['tenant_uuid', 'slug']]]),
            'block_types' => self::row($def, [['uniq_block_type_slug', ['tenant_uuid', 'slug']]]),
            'block_type_migrations' => self::row($def),
            'regions' => self::row($def, [], 'rebuild'), // PK is `slug` => (tenant_uuid, slug)

            // --- core instance data ---
            'entries' => self::row($inst),
            'entry_drafts' => self::row($inst),
            'entry_versions' => self::row($inst),
            'entry_publications' => self::row($inst),
            'entry_routes' => self::row(
                $inst,
                [['uniq_route_type_locale_slug', ['tenant_uuid', 'content_type_uuid', 'locale', 'slug']]],
            ),
            'entry_references' => self::row($inst),
            'published_entry_references' => self::row($inst),
            'entry_redirects' => self::row(
                $inst,
                [['uniq_redirect_type_locale_source', ['tenant_uuid', 'content_type_uuid', 'locale', 'source_slug']]],
                'rebuild', // inline ->unique() on uuid forces a table rebuild
            ),
            'entry_schema_migrations' => self::row($inst),
            'entry_schedules' => self::row($inst),
            'form_submissions' => self::row($inst),
            // settings: the site subset is tenant-owned (system keys move to the channel). INSTANCE
            // (per-tenant site data/config), NOT a schema definition — matters for divergence
            // checks + diagnostics. PK is `key` => (tenant_uuid, key), so needs a rebuild backfill.
            'settings' => self::row($inst, [], 'rebuild'),

            // --- pack tables (present only when the pack is installed; retrofit skips absent tables) ---
            'render_templates' => self::row(
                $def,
                [['uniq_render_template_theme_path', ['tenant_uuid', 'theme', 'path']]],
            ),
            'render_template_versions' => self::row($def),
            'navigation_menus' => self::row($def, [['uniq_navigation_menu_slug', ['tenant_uuid', 'slug']]]),
            'navigation_items' => self::row($inst),
            'seo_meta' => self::row($inst, [[null, ['tenant_uuid', 'entry_uuid', 'locale']]]),
            'analytics_facts' => self::row($inst),
            'analytics_daily' => self::row($inst),
            'analytics_active_actors' => self::row($inst),
            'workflow_review_states' => self::row($inst),
            'workflow_transitions' => self::row($inst),

            // --- added by this pack ---
            'starter_provenance' => self::row($inst),
        ];
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_keys(self::all());
    }

    /**
     * @param 'definition'|'instance' $kind
     * @param list<array{0: string|null, 1: list<string>}> $widenedUniques
     * @return array{tenant_column: string, kind: 'definition'|'instance', widened_uniques: list<array{0: string|null, 1: list<string>}>, indexes: list<string>, special_backfill: string|null}
     */
    private static function row(string $kind, array $widenedUniques = [], ?string $specialBackfill = null): array
    {
        return [
            'tenant_column' => 'tenant_uuid',
            'kind' => $kind,
            'widened_uniques' => $widenedUniques,
            'indexes' => ['tenant_uuid'],
            'special_backfill' => $specialBackfill,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && vendor/bin/phpunit tests/Unit/Tenancy/ThalloTenantTablesTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: phpcs + commit** (HOLD)

```bash
git add packages/thallo-tenancy/src/ThalloTenantTables.php tests/Unit/Tenancy/ThalloTenantTablesTest.php
git commit -m "Add ThalloTenantTables owned-table registry with retrofit metadata"
```

---

### Task B1.4: Table registration + compound boot gate

**Files:**
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php` (add a testable `registerTenantTables()` + call it from `boot()`)
- Test: `tests/Integration/Tenancy/TableRegistrationGateTest.php`

**Interfaces:**
- Consumes: `Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry` (contract binding — present only when `glueful/tenancy` is active); `Thallo\Tenancy\System\SystemFlags`; `Thallo\Tenancy\ThalloTenantTables`.
- Produces: `TenancyServiceProvider::registerTenantTables(ApplicationContext): bool` — registers `ThalloTenantTables::tableNames()` via the contract **iff** (contract binding present) AND (`SystemFlags::tenancyEnabled()`); returns whether it registered.

- [ ] **Step 1: Write the failing test**

`tests/Integration/Tenancy/TableRegistrationGateTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\TenancyServiceProvider;
use Thallo\Tenancy\ThalloTenantTables;

final class TableRegistrationGateTest extends AppTestCase
{
    protected function tearDown(): void
    {
        // Order-independence: the enabled flag persists in the DB, and the REAL boot path (full
        // suite) registers into the tenancy extension's PROCESS-STATIC TenantTableRegistry. These
        // tests use a capturing fake (so they don't touch the static registry), but reset both so
        // no state leaks to a later test. Clear the static registry only if the class is loaded.
        $this->container()->get(SystemFlags::class)->forget('tenancy.enabled');
        if (class_exists(\Glueful\Extensions\Tenancy\Query\TenantTableRegistry::class)) {
            \Glueful\Extensions\Tenancy\Query\TenantTableRegistry::clear();
        }
        parent::tearDown();
    }

    /** A capturing fake bound to the contract so we can assert what got registered. */
    private function bindCapturingRegistry(): object
    {
        $fake = new class implements TenantTableRegistryContract {
            /** @var list<string> */
            public array $registered = [];
            public function register(array $tables): void
            {
                $this->registered = array_merge($this->registered, $tables);
            }
        };
        $this->container()->set(TenantTableRegistryContract::class, $fake); // see note on container API
        return $fake;
    }

    private function provider(): TenancyServiceProvider
    {
        return new TenancyServiceProvider();
    }

    public function testNoRegistryBindingIsANoOp(): void
    {
        // No contract bound (tenancy extension absent) => must not register, must not error.
        self::assertFalse($this->provider()->registerTenantTables($this->context()));
    }

    public function testDisabledFlagIsANoOp(): void
    {
        $fake = $this->bindCapturingRegistry();
        // tenancy.enabled unset => off
        self::assertFalse($this->provider()->registerTenantTables($this->context()));
        self::assertSame([], $fake->registered);
    }

    public function testEnabledWithBindingRegistersAllOwnedTables(): void
    {
        $fake = $this->bindCapturingRegistry();
        $this->container()->get(SystemFlags::class)->put('tenancy.enabled', '1');

        self::assertTrue($this->provider()->registerTenantTables($this->context()));
        self::assertSame(ThalloTenantTables::tableNames(), $fake->registered);
    }
}
```

> **Container API note:** confirm how the test container binds an instance (`set()`/`bind()`/`instance()`) and how `context()` exposes it — match `AppTestCase`. If the container can't be mutated mid-test, resolve the gate through a small seam: pass the registry + flags into `registerTenantTables()` as optional args defaulting to container lookups, and inject the fakes directly in the test.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && vendor/bin/phpunit tests/Integration/Tenancy/TableRegistrationGateTest.php`
Expected: FAIL — `registerTenantTables()` undefined.

- [ ] **Step 3: Implement the gate in the provider**

Add to `TenancyServiceProvider` (imports: `SystemFlags`, `ThalloTenantTables`, `TenantTableRegistry as TenantTableRegistryContract`):
```php
    /**
     * Register Thallo's tenant-owned tables into the tenancy backstop — but ONLY when both:
     *   (a) the TenantTableRegistry contract is bound (the glueful/tenancy extension is active), and
     *   (b) SystemFlags says tenancy scoping is enabled.
     * A merely-installed-but-disabled extension never silently scopes a single-tenant site.
     */
    public function registerTenantTables(ApplicationContext $context): bool
    {
        $container = $context->getContainer();
        if (!$container->has(TenantTableRegistryContract::class)) {
            return false;
        }

        if (!app($context, SystemFlags::class)->tenancyEnabled()) {
            return false;
        }

        /** @var TenantTableRegistryContract $registry */
        $registry = $container->get(TenantTableRegistryContract::class);
        $registry->register(ThalloTenantTables::tableNames());

        return true;
    }
```
Then call it at the end of `boot()`:
```php
        // Register owned tables behind the compound gate (contract bound + scoping enabled).
        $this->registerTenantTables($context);
```

> Confirm `ApplicationContext::getContainer()` + `has()`/`get()` exist (they're used across the app). If the app's container interface differs, use the same accessor the pack's `services()` factories use (`$container->get(...)`).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && vendor/bin/phpunit tests/Integration/Tenancy/TableRegistrationGateTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Full Thallo suite regression + phpcs**

Run: `composer test 2>&1 | tail -15 && composer phpcs 2>&1 | tail`
Expected: no regressions; clean. (The pack boots for the whole suite now — confirm capability registration + the disabled gate don't perturb existing tests.)

- [ ] **Step 6: Commit** (HOLD)

```bash
git add packages/thallo-tenancy/src/TenancyServiceProvider.php tests/Integration/Tenancy/TableRegistrationGateTest.php
git commit -m "Register tenant tables behind the compound boot gate (binding + enabled flag)"
```

---

## Phase B1 self-review checklist (run before handing off)

- **Spec coverage:** B1.1 = pack scaffold + capability (§5.1); B1.2 = system-global channel (§7.3); B1.3 = `ThalloTenantTables` single source + collections exclusion (§6); B1.4 = compound boot gate (§5.1). ✅
- **Placeholder scan:** none — every step has concrete code. The `> Verify…` notes are execution checks against named reference files, not gaps.
- **Type consistency:** `ThalloTenantTables::tableNames()` used identically in B1.3 (def) and B1.4 (registration); `SystemFlags::tenancyEnabled()` used in B1.4; the contract `TenantTableRegistry::register(list<string>)` matches the A-phase contract signature.
- **Soft-dependency preserved:** the pack's *code* references only `Glueful\Extensions\Contracts\Tenancy\*` (never the `Glueful\Extensions\Tenancy\*` implementation); registration is guarded by `$container->has(contract)`. Composer hard-requires the contract package + thallo-contracts + framework, but never `glueful/tenancy`. (Test files may reference tenancy classes for the regression pins — those are guarded by `method_exists`/`class_exists` so they skip on a pre-A2 / no-tenancy setup.)
- **Review fixes folded in:** precise soft/hard dep wording; `SystemFlags::all()` uses `hasTable` (no swallowed errors) and write paths fail loud; `settings` reclassified `instance`; system-channel-not-owned + settings-kind assertions added; a regression pins that `thallo_system_flags` writes stay usable + unstamped under an active insert hook; route-gating policy pinned (enablement routes always-on/permissioned, tenant-management routes capability-gated); B1.4 test resets the enabled flag + static registry in tearDown for order-independence.
- **Execution prerequisites flagged:** dev linkage of framework + extension-contracts + tenancy into Thallo `vendor/`; `migrate:run` + any seed are LOCAL-only (never committed); container-mutation API in B1.4's test to confirm against `AppTestCase`.

## Deferred to B2 (scoping correctness)

- Raw-PDO `tenant_uuid` fixes for `seo_meta` (`SeoMetaRepository` upsert), `entry_versions` (`VersionPruner` — window-function CTE + `DELETE … NOT EXISTS`), `entry_schedules` (`ScheduleRepository` — `FOR UPDATE SKIP LOCKED` + `RETURNING`). These are Postgres-specific and can't convert to the builder — they need explicit `tenant_uuid` predicates from `CurrentTenantResolver`, plus a design decision on their SQLite-test behavior.
- The raw-PDO regression lint (grep for `getPDO()` reads/writes against owned tables).
- The guard-as-oracle harness: boot tenancy with **≥2 tenants**, run Thallo's existing suite with the guard in throw mode, assert cross-tenant isolation for builder paths.
