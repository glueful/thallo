<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantPurge;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Helpers\Utils;
use Thallo\Commerce\Adoption\CommerceAdoptionContributor;
use Thallo\Commerce\Purge\CommercePurgeHandler;

/**
 * Commerce-Slice-1 Task 10: {@see CommercePurgeHandler} + {@see CommerceAdoptionContributor}
 * (design spec §8). Ungated (plain {@see AppTestCase}, mirroring `TenantResolutionModesTest`'s /
 * `ProductLinkServiceTest`'s established "mode (b)" convention): every test here exercises the
 * two classes' OWN logic directly against real Postgres tables, without needing the
 * glueful/tenancy enforcement extension (stripped from the default test boot by
 * `config/testing/extensions.php`).
 *
 * Two companion files cover what this file structurally cannot: `CommercePurgePipelineTest`
 * (real `PurgeCoordinator`/`PurgeJob`, needs the enforcement extension + two provisioned
 * tenants — `RetrofittedTenantTestCase`) and `CommerceAdoptionEnablementTest` (real
 * `TenancyEnablement::confirm()` with the retrofit write-barrier UP — `RetrofitHarnessTestCase`,
 * mirroring `AdoptionContributorTest`). Both require `THALLO_TENANCY_DEV_LINK=1` and self-skip
 * without it, exactly like every other retrofit-harness suite in this codebase.
 */
final class PurgeAdoptionTest extends AppTestCase
{
    /** @var list<string> Postgres schemas created by {@see self::schemaLessLinkOnlyConnection()}. */
    private array $createdSchemas = [];

    protected function setUp(): void
    {
        parent::setUp();
        // AppTestCase::setUp() already truncates thallo_commerce_product_links; no Commerce
        // table is in its managed list (see TenantResolutionModesTest's identical note). This
        // class's adopt() assertions assume a genuinely clean slate across EVERY table
        // TenantAdopter's "mixed data" refusal inspects (DiagnosticsReport::tenantTables()) --
        // not just commerce_products -- because a leftover non-sentinel row in ANY of those
        // tables (left by an unrelated test elsewhere in the suite that creates a product via
        // CatalogService under a real tenant, e.g. commerce_marketplace_settings via
        // MarketplaceWorkspaceLock::claim()) makes a freshly-generated tenant uuid here look
        // "mixed" and spuriously fails testAdoptRekeysSentinelLinkAndCommerceRowsIntoTheTenant/
        // testSecondAdoptRunForTheSameTenantIsACleanNoOp.
        $this->truncateCommerceTenantTables();
    }

    protected function tearDown(): void
    {
        $this->truncateCommerceTenantTables();
        foreach ($this->createdSchemas as $schema) {
            $this->connection()->getPDO()->exec('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
        }
        $this->createdSchemas = [];
        parent::tearDown();
    }

    // =================================================================================
    // CommercePurgeHandler
    // =================================================================================

    public function testDependsOnDeclaresNoOrderingConstraints(): void
    {
        // The link table carries no DB foreign key into Commerce or into any core Thallo table
        // (design spec §5.1), and nothing else in the registry depends on 'thallo.commerce' —
        // see the handler's own dependsOn() docblock for the full justification.
        $handler = new CommercePurgeHandler($this->connection(), null);
        self::assertSame([], $handler->dependsOn());
    }

    public function testFailClosedThrowsOnEveryMethodWhenCommerceSchemaPresentButPurgeServiceUnavailable(): void
    {
        // commerce_products (the fail-closed schema marker) is real and present in this shared
        // suite DB — Commerce is a hard composer dependency of thallo-commerce (design spec §3),
        // so its migrations always ran here. Constructing the handler with a null
        // CommerceTenantPurge simulates "Commerce's provider is inactive" without needing a
        // second app boot.
        $handler = new CommercePurgeHandler($this->connection(), null);
        $tenantUuid = Utils::generateNanoID();

        foreach (['prepare', 'purge', 'verify'] as $method) {
            $threw = false;
            try {
                match ($method) {
                    'prepare' => $handler->prepare($this->appContext(), $tenantUuid),
                    'purge' => $handler->purge($this->appContext(), $tenantUuid, []),
                    'verify' => $handler->verify($this->appContext(), $tenantUuid, []),
                };
            } catch (\RuntimeException) {
                $threw = true;
            }
            self::assertTrue($threw, "{$method}() must fail closed when Commerce schema is present but unbound");
        }
    }

    public function testLinkOnlyCleanupCompletesWhenCommerceSchemaIsAbsentAndPurgeServiceIsUnavailable(): void
    {
        $connection = $this->schemaLessLinkOnlyConnection();
        $handler = new CommercePurgeHandler($connection, null);
        $tenantUuid = Utils::generateNanoID();

        $connection->table('thallo_commerce_product_links')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenantUuid,
            'product_uuid' => Utils::generateNanoID(),
            'entry_uuid' => Utils::generateNanoID(),
        ]);

        // None of these three may throw: there is no Commerce schema for them to leave behind.
        $artifacts = $handler->prepare($this->appContext(), $tenantUuid);
        $handler->purge($this->appContext(), $tenantUuid, $artifacts);
        self::assertTrue($handler->verify($this->appContext(), $tenantUuid, $artifacts));

        self::assertSame(
            0,
            (int) $connection->table('thallo_commerce_product_links')
                ->where('tenant_uuid', $tenantUuid)->count()
        );
    }

    public function testHandlerPurgesLinkAndCommerceRowsForTheTargetTenantOnlyWhenPurgeServiceIsBound(): void
    {
        $handler = new CommercePurgeHandler($this->connection(), new CommerceTenantPurge());
        $tenantA = Utils::generateNanoID();
        $tenantB = Utils::generateNanoID();

        foreach ([$tenantA, $tenantB] as $tenant) {
            $this->connection()->table('thallo_commerce_product_links')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => $tenant,
                'product_uuid' => Utils::generateNanoID(),
                'entry_uuid' => Utils::generateNanoID(),
            ]);
            $this->connection()->table('commerce_products')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => $tenant,
                'slug' => 'purge-handler-' . $tenant,
                'name' => 'Purge Handler Product',
            ]);
        }

        $artifacts = $handler->prepare($this->appContext(), $tenantA);
        self::assertSame(1, $artifacts['link_count']);
        self::assertSame(1, $artifacts['commerce_counts']['commerce_products']);

        $handler->purge($this->appContext(), $tenantA, $artifacts);
        self::assertTrue($handler->verify($this->appContext(), $tenantA, $artifacts));

        self::assertSame(
            0,
            (int) $this->connection()->table('thallo_commerce_product_links')
                ->where('tenant_uuid', $tenantA)->count()
        );
        self::assertSame(
            0,
            (int) $this->connection()->table('commerce_products')->where('tenant_uuid', $tenantA)->count()
        );

        // Tenant B is untouched.
        self::assertSame(
            1,
            (int) $this->connection()->table('thallo_commerce_product_links')
                ->where('tenant_uuid', $tenantB)->count()
        );
        self::assertSame(
            1,
            (int) $this->connection()->table('commerce_products')->where('tenant_uuid', $tenantB)->count()
        );
    }

    // =================================================================================
    // CommerceAdoptionContributor
    // =================================================================================

    public function testTablesIncludesTheLinkTableAndEveryCommerceTenantTable(): void
    {
        $contributor = new CommerceAdoptionContributor($this->connection(), new TenantAdopter());
        $tables = $contributor->tables();

        self::assertContains('thallo_commerce_product_links', $tables);
        self::assertContains('commerce_products', $tables);
        self::assertContains('commerce_orders', $tables);
        self::assertSame(
            ['thallo_commerce_product_links', ...DiagnosticsReport::tenantTables()],
            $tables,
        );
    }

    public function testAdoptRekeysSentinelLinkAndCommerceRowsIntoTheTenant(): void
    {
        $contributor = new CommerceAdoptionContributor($this->connection(), new TenantAdopter());
        $tenantUuid = Utils::generateNanoID();

        $this->connection()->table('thallo_commerce_product_links')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'product_uuid' => Utils::generateNanoID(),
            'entry_uuid' => Utils::generateNanoID(),
        ]);
        $this->connection()->table('commerce_products')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'slug' => 'adopt-sentinel',
            'name' => 'Adopt Sentinel Product',
        ]);

        $contributor->adopt($this->appContext(), $tenantUuid);

        self::assertSame(
            0,
            (int) $this->connection()->table('thallo_commerce_product_links')
                ->where('tenant_uuid', '')->count()
        );
        self::assertSame(
            1,
            (int) $this->connection()->table('thallo_commerce_product_links')
                ->where('tenant_uuid', $tenantUuid)->count()
        );
        self::assertSame(
            0,
            (int) $this->connection()->table('commerce_products')->where('tenant_uuid', '')->count()
        );
        self::assertSame(
            1,
            (int) $this->connection()->table('commerce_products')->where('tenant_uuid', $tenantUuid)->count()
        );
    }

    /**
     * Proves the exact retry semantics carry-forward #2 asked for: a SECOND adopt() call for the
     * SAME tenant, with no sentinel rows left anywhere, must be a clean no-op — not a thrown
     * exception. TenantAdopter's own "mixed data" refusal only fires for rows belonging to some
     * OTHER tenant; rows already rekeyed to THIS tenant are neither sentinel nor mixed, so a
     * second call finds nothing to touch and returns normally. No extra guard is needed in
     * CommerceAdoptionContributor on top of that.
     */
    public function testSecondAdoptRunForTheSameTenantIsACleanNoOp(): void
    {
        $contributor = new CommerceAdoptionContributor($this->connection(), new TenantAdopter());
        $tenantUuid = Utils::generateNanoID();

        $this->connection()->table('thallo_commerce_product_links')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'product_uuid' => Utils::generateNanoID(),
            'entry_uuid' => Utils::generateNanoID(),
        ]);
        $this->connection()->table('commerce_products')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'slug' => 'adopt-retry',
            'name' => 'Adopt Retry Product',
        ]);

        $contributor->adopt($this->appContext(), $tenantUuid);

        $threw = false;
        try {
            $contributor->adopt($this->appContext(), $tenantUuid);
        } catch (\Throwable) {
            $threw = true;
        }
        self::assertFalse($threw, 'a second adopt() call for the same tenant must not throw');

        self::assertSame(
            1,
            (int) $this->connection()->table('thallo_commerce_product_links')
                ->where('tenant_uuid', $tenantUuid)->count()
        );
        self::assertSame(
            1,
            (int) $this->connection()->table('commerce_products')->where('tenant_uuid', $tenantUuid)->count()
        );
    }

    public function testAdoptSkipsTheCommerceCallButStillAdoptsLinkRowsWhenTenantAdopterIsUnavailable(): void
    {
        $contributor = new CommerceAdoptionContributor($this->connection(), null);
        $tenantUuid = Utils::generateNanoID();

        $this->connection()->table('thallo_commerce_product_links')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'product_uuid' => Utils::generateNanoID(),
            'entry_uuid' => Utils::generateNanoID(),
        ]);
        $this->connection()->table('commerce_products')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'slug' => 'adopt-unbound',
            'name' => 'Adopt Unbound Product',
        ]);

        $contributor->adopt($this->appContext(), $tenantUuid);

        self::assertSame(
            1,
            (int) $this->connection()->table('thallo_commerce_product_links')
                ->where('tenant_uuid', $tenantUuid)->count(),
            'the pack\'s own link rows must still be adopted even when Commerce is inactive',
        );
        self::assertSame(
            0,
            (int) $this->connection()->table('commerce_products')->where('tenant_uuid', $tenantUuid)->count(),
            'commerce_products must be left untouched — nothing adopted it',
        );
    }

    // =================================================================================
    // helpers
    // =================================================================================

    /**
     * Deletes every row from every table {@see DiagnosticsReport::tenantTables()} lists that
     * actually exists in this connection's schema — the full set {@see TenantAdopter}'s
     * "mixed data" refusal inspects, not just `commerce_products` (see setUp()'s docblock).
     */
    private function truncateCommerceTenantTables(): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        foreach (DiagnosticsReport::tenantTables() as $table) {
            if ($schema->hasTable($table)) {
                $this->connection()->table($table)->where('id', '>', 0)->forceDelete();
            }
        }
    }

    /**
     * A fresh Postgres schema containing ONLY a hand-built `thallo_commerce_product_links`
     * table — no `commerce_products` (or any other Commerce table) anywhere on its search_path.
     * `Connection`'s pgsql driver issues `SET search_path TO <schema>` with no `public` fallback
     * (see `Glueful\Database\Connection`), so `hasTable('commerce_products')` genuinely resolves
     * false here even though that table exists in the shared suite database's `public` schema.
     */
    private function schemaLessLinkOnlyConnection(): Connection
    {
        $schema = 'commerce_absent_' . strtolower(Utils::generateNanoID(10));
        $this->connection()->getPDO()->exec('CREATE SCHEMA "' . $schema . '"');
        $this->createdSchemas[] = $schema;

        $connection = new Connection([
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'app_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => $schema,
            ],
            'pooling' => ['enabled' => false],
        ]);
        $connection->getPDO()->exec(
            'CREATE TABLE thallo_commerce_product_links ('
            . 'id BIGSERIAL PRIMARY KEY, uuid VARCHAR(12), tenant_uuid VARCHAR(12) DEFAULT \'\', '
            . 'product_uuid VARCHAR(12), entry_uuid VARCHAR(12), '
            . 'created_at TIMESTAMP, updated_at TIMESTAMP)'
        );

        return $connection;
    }
}
