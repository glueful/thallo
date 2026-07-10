<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Two-tenant "guard-as-oracle" base: boots a SECOND app with the glueful/tenancy framework
 * extension ENABLED (so its CurrentTenantResolver/TenantContextRunner bind and its query guard +
 * insert stamper arm), seeds two tenants, and exposes runAsTenant()/runAsSystem() so scoping tests
 * exercise the REAL enforcement path.
 *
 * OPT-IN + DETERMINISTIC: the whole class skips unless THALLO_TENANCY_DEV_LINK=1 AND glueful/tenancy
 * is symlinked into vendor/. It registers the extension's PSR-4 with a targeted autoloader HERE (a
 * test helper) rather than in tests/bootstrap.php, so the main suite's behavior never depends on a
 * developer's filesystem shape.
 *
 * SCHEMA STAND-IN: owned tables gain their tenant_uuid column from Phase C's retrofit, which lands
 * AFTER B2. applyMinimalTenantColumnsForOracle() is an explicit, minimal stand-in — it ADDS the
 * column (+ additive widened unique so scoped ON CONFLICT targets resolve) for ONLY the B2-exercised
 * additive tables; it NEVER drops the narrow unique, fakes rebuild tables, or reproduces Phase C's
 * backfill/idempotency. Tests prove ISOLATION with distinct per-tenant keys, not same-key
 * coexistence (a widened-unique property owned by Phase C).
 */
abstract class TenantOracleTestCase extends AppTestCase
{
    /** Additive owned tables B2 tests exercise (NOT the full owned set; NO rebuild tables). */
    protected const ORACLE_TABLES = [
        'seo_meta',
        'navigation_menus',
        'navigation_items',
        'analytics_facts',
        'analytics_daily',
        'analytics_active_actors',
        'workflow_review_states',
        'workflow_transitions',
        'block_type_migrations',
        'entry_schema_migrations',
        'entry_schedules',
        'entry_versions',
        'entry_publications',
        'entries',
    ];

    protected static ?ApplicationContext $tenantApp = null;
    protected static string $tenantAUuid = '';
    protected static string $tenantBUuid = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (getenv('THALLO_TENANCY_DEV_LINK') !== '1') {
            self::markTestSkipped(
                'Oracle harness is opt-in: set THALLO_TENANCY_DEV_LINK=1 with glueful/tenancy dev-linked.',
            );
        }

        self::registerTenancyAutoloader();
        if (!class_exists(\Glueful\Extensions\Tenancy\TenancyServiceProvider::class)) {
            self::markTestSkipped('glueful/tenancy is not symlinked into vendor/ — oracle harness skipped.');
        }

        if (self::$tenantApp === null) {
            // Turn Thallo tenancy ON in the shared app_test DB BEFORE the enabled boot, so the pack's
            // registerTenantTables() passes its SystemFlags gate during that boot and registers
            // Thallo's owned tables into the (process-global) guard registry.
            self::$app->getContainer()->get(SystemFlags::class)->put('tenancy.enabled', '1');

            // glueful/tenancy is symlinked, NOT composer-discovered, so listing it in
            // extensions.enabled is inert (that list only GATES composer candidates). Load it as an
            // APP provider instead: AppProviderLoader reads config('serviceproviders.enabled')
            // VERBATIM (no discovery), and our targeted autoloader resolves the class. Its services()
            // bind CurrentTenantResolver/TenantContextRunner and its boot() arms guard + stamper.
            /** @var array{enabled: list<string>} $base */
            $base = require dirname(__DIR__, 2) . '/config/serviceproviders.php';
            $providers = $base['enabled'];
            $providers[] = 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider';

            self::$tenantApp = self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers]);
            self::seedTenants();
        }

        // Add the stand-in columns for THIS class only; tearDownAfterClass drops them so the shared
        // app_test schema other (non-oracle) classes see is never mutated — the tenant_uuid columns
        // exist strictly while an oracle class runs.
        self::applyMinimalTenantColumnsForOracle();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tenantApp !== null) {
            self::dropMinimalTenantColumnsForOracle();
        }
        parent::tearDownAfterClass();
    }

    /** Targeted opt-in autoloader for the dev-linked tenancy extension (test helper, not bootstrap). */
    private static function registerTenancyAutoloader(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $srcRoot = dirname(__DIR__, 2) . '/vendor/glueful/tenancy/src/';
        spl_autoload_register(static function (string $class) use ($srcRoot): void {
            $prefix = 'Glueful\\Extensions\\Tenancy\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = $srcRoot . $rel . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    /** Minimal Phase-C stand-in: ADD tenant_uuid column + additive widened unique. No drops. */
    private static function applyMinimalTenantColumnsForOracle(): void
    {
        $pdo = self::$tenantApp->getContainer()->get(Connection::class)->getPDO();
        $meta = ThalloTenantTables::all();
        foreach (self::ORACLE_TABLES as $i => $table) {
            $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(191)', $table));
            foreach ($meta[$table]['widened_uniques'] ?? [] as $n => $entry) {
                $cols = implode(', ', $entry[1]);
                // Synthetic name (never the narrow's name) — the narrow unique deliberately stays.
                $name = sprintf('oracle_w_%s_%d', $table, $n);
                $pdo->exec(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s)', $name, $table, $cols));
            }
        }
    }

    /** Drop the per-class stand-in columns + widened indexes so app_test schema is left pristine. */
    private static function dropMinimalTenantColumnsForOracle(): void
    {
        $pdo = self::$tenantApp->getContainer()->get(Connection::class)->getPDO();
        foreach (self::ORACLE_TABLES as $table) {
            // CASCADE drops the oracle_w_* widened indexes that depend on the column.
            $pdo->exec(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS tenant_uuid CASCADE', $table));
        }
    }

    private static function seedTenants(): void
    {
        // tenants is not truncated between tests (it is not tenant-owned), and persists across
        // separate phpunit runs — clear this harness's fixed-slug rows first so re-seeding with a
        // fresh uuid is idempotent.
        self::$tenantApp->getContainer()->get(Connection::class)->getPDO()
            ->exec("DELETE FROM tenants WHERE slug IN ('oracle-tenant-a', 'oracle-tenant-b')");

        self::$tenantAUuid = \Glueful\Helpers\Utils::generateNanoID();
        self::$tenantBUuid = \Glueful\Helpers\Utils::generateNanoID();
        \Glueful\Extensions\Tenancy\Models\Tenant::create(
            self::$tenantApp,
            ['uuid' => self::$tenantAUuid, 'slug' => 'oracle-tenant-a', 'name' => 'Oracle Tenant A'],
        );
        \Glueful\Extensions\Tenancy\Models\Tenant::create(
            self::$tenantApp,
            ['uuid' => self::$tenantBUuid, 'slug' => 'oracle-tenant-b', 'name' => 'Oracle Tenant B'],
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clean the oracle allowlist (some aren't in AppTestCase::TABLES, e.g. seo_meta/analytics_*;
        // analytics_active_actors has no surrogate `id`). Raw DELETE — bypasses the id assumption and
        // the builder guard entirely (test cleanup runs with no tenant context anyway).
        $pdo = $this->connection()->getPDO();
        foreach (self::ORACLE_TABLES as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    }

    /** Bind repo resolution to the tenancy-ENABLED boot (where CurrentTenantResolver is bound). */
    protected function container(): ContainerInterface
    {
        return self::$tenantApp?->getContainer() ?? parent::container();
    }

    protected function appContext(): ApplicationContext
    {
        return self::$tenantApp ?? parent::appContext();
    }

    /** Run $fn scoped to a tenant (owned-table queries get tenant_uuid injected; guard satisfied). */
    protected function runAsTenant(string $tenantUuid, callable $fn): mixed
    {
        return $this->container()
            ->get(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class)
            ->runAsTenant($tenantUuid, $fn);
    }

    /** Run $fn with enforcement suspended (bypass = system). */
    protected function runAsSystem(callable $fn): mixed
    {
        return $this->container()
            ->get(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class)
            ->runAsSystem($fn);
    }

    protected function currentTenantUuid(): string
    {
        return $this->container()
            ->get(\Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver::class)
            ->tenantUuid($this->appContext());
    }
}
