<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantPurge;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use Glueful\Helpers\Utils;
use Thallo\Commerce\Adoption\CommerceAdoptionContributor;
use Thallo\Commerce\CommerceIntegrationServiceProvider;
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
    /**
     * Every table this pack OWNS and therefore must purge/adopt itself. Cleanup-train Task 10
     * closed the two that predate the payment-links cycle (`_product_slugs`, `_checkout_attempts`):
     * both are tenant-keyed pack tables, so a purge that left them behind kept a deleted
     * workspace's slug history and its checkout-attempt ledger (including that ledger's encrypted
     * guest credentials) on disk, and an adoption that skipped them left `tenant_uuid = ''` rows
     * that a post-enablement request can no longer see — turning a slug reservation or an
     * idempotency key into a silent collision.
     *
     * @var list<string>
     */
    private const PACK_TENANT_TABLES = [
        'thallo_commerce_product_links',
        'thallo_commerce_payment_link_deliveries',
        'thallo_commerce_product_slugs',
        'thallo_commerce_checkout_attempts',
    ];

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
        $connection->table('thallo_commerce_product_slugs')->insert([
            'tenant_uuid' => $tenantUuid,
            'slug' => 'pack-only-' . strtolower(Utils::generateNanoID(8)),
            'product_uuid' => Utils::generateNanoID(),
        ]);
        $connection->table('thallo_commerce_checkout_attempts')->insert([
            'tenant_uuid' => $tenantUuid,
            'idempotency_key' => 'pack-only-' . Utils::generateNanoID(),
            'request_fingerprint' => str_repeat('a', 64),
            'status' => 'completed',
        ]);

        // None of these three may throw: there is no Commerce schema for them to leave behind.
        $artifacts = $handler->prepare($this->appContext(), $tenantUuid);
        $handler->purge($this->appContext(), $tenantUuid, $artifacts);
        self::assertTrue($handler->verify($this->appContext(), $tenantUuid, $artifacts));

        foreach (self::PACK_TENANT_TABLES as $table) {
            self::assertSame(
                0,
                (int) $connection->table($table)->where('tenant_uuid', $tenantUuid)->count(),
                "{$table} must be emptied for the purged tenant",
            );
        }
    }

    public function testHandlerPurgesLinkAndCommerceRowsForTheTargetTenantOnlyWhenPurgeServiceIsBound(): void
    {
        $handler = new CommercePurgeHandler($this->connection(), new CommerceTenantPurge());
        $tenantA = Utils::generateNanoID();
        $tenantB = Utils::generateNanoID();

        foreach ([$tenantA, $tenantB] as $tenant) {
            $this->seedPackTenantRows($tenant);
            $this->connection()->table('commerce_products')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => $tenant,
                'slug' => 'purge-handler-' . $tenant,
                'name' => 'Purge Handler Product',
            ]);
        }

        $artifacts = $handler->prepare($this->appContext(), $tenantA);
        self::assertSame(1, $artifacts['link_count']);
        self::assertSame(1, $artifacts['delivery_count']);
        self::assertSame(1, $artifacts['slug_count']);
        self::assertSame(1, $artifacts['attempt_count']);
        self::assertSame(1, $artifacts['commerce_counts']['commerce_products']);

        $handler->purge($this->appContext(), $tenantA, $artifacts);
        self::assertTrue($handler->verify($this->appContext(), $tenantA, $artifacts));

        // PHYSICAL deletion, table by table — verify() answering true is the handler's own
        // opinion; these are the rows.
        foreach (self::PACK_TENANT_TABLES as $table) {
            self::assertSame(
                0,
                (int) $this->connection()->table($table)->where('tenant_uuid', $tenantA)->count(),
                "{$table} must have no rows left for the purged tenant",
            );
        }
        self::assertSame(
            0,
            (int) $this->connection()->table('commerce_products')->where('tenant_uuid', $tenantA)->count()
        );

        // Tenant B is untouched.
        foreach (self::PACK_TENANT_TABLES as $table) {
            self::assertSame(
                1,
                (int) $this->connection()->table($table)->where('tenant_uuid', $tenantB)->count(),
                "{$table} must keep the OTHER tenant's row",
            );
        }
        self::assertSame(
            1,
            (int) $this->connection()->table('commerce_products')->where('tenant_uuid', $tenantB)->count()
        );
    }

    /**
     * A `verify()` that only ever consulted the link table would answer true while a purged
     * tenant's slug/attempt rows were still on disk — the exact shape of the gap this task
     * closed. Each pack table is re-seeded ALONE after a completed purge, so a single missing
     * `verify()` check is caught by name rather than hidden behind the others.
     */
    public function testVerifyRefusesWhileAnyPackOwnedTableStillHoldsTheTenantSRows(): void
    {
        $handler = new CommercePurgeHandler($this->connection(), new CommerceTenantPurge());
        $tenantUuid = Utils::generateNanoID();

        $artifacts = $handler->prepare($this->appContext(), $tenantUuid);
        $handler->purge($this->appContext(), $tenantUuid, $artifacts);
        self::assertTrue($handler->verify($this->appContext(), $tenantUuid, $artifacts));

        foreach (self::PACK_TENANT_TABLES as $table) {
            $this->seedPackTenantRows($tenantUuid, only: $table);
            self::assertFalse(
                $handler->verify($this->appContext(), $tenantUuid, $artifacts),
                "verify() must refuse while {$table} still holds the tenant's rows",
            );
            $this->connection()->table($table)->where('tenant_uuid', $tenantUuid)->forceDelete();
        }
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
            [
                'thallo_commerce_product_links',
                // Payment links Task 12 (payment-links spec §2.4): the delivery ledger is
                // tenant-owned and named by that spec as adoption-covered.
                'thallo_commerce_payment_link_deliveries',
                // Cleanup-train Task 10: the two pack-owned tables that predate the payment-links
                // cycle and were never registered here.
                'thallo_commerce_product_slugs',
                'thallo_commerce_checkout_attempts',
                ...DiagnosticsReport::tenantTables(),
            ],
            $tables,
        );
    }

    /**
     * `tables()` is what `FinalizationProbe` checks against the tenant-table REGISTRY before
     * enforcement may report ON, so the pack's provider must register exactly the pack-owned
     * tables this contributor claims — a claim the registry does not carry would fail
     * finalization, and a registered table this contributor does not adopt would be enforced
     * against while still holding sentinel rows.
     */
    public function testEveryPackOwnedTableThisContributorClaimsIsAlsoRegisteredAsATenantTable(): void
    {
        $contributor = new CommerceAdoptionContributor($this->connection(), new TenantAdopter());

        $claimed = array_values(array_filter(
            $contributor->tables(),
            static fn (string $table): bool => str_starts_with($table, 'thallo_commerce_'),
        ));

        self::assertSame(self::PACK_TENANT_TABLES, $claimed);

        $registry = new class implements TenantTableRegistryContract {
            /** @var array<string, true> */
            public array $registered = [];

            public function register(array $tables): void
            {
                foreach ($tables as $table) {
                    $this->registered[$table] = true;
                }
            }
        };

        $provider = new CommerceIntegrationServiceProvider($this->container());
        self::assertTrue($provider->registerProductLinkTable($this->appContext(), $registry));
        self::assertSame(self::PACK_TENANT_TABLES, array_keys($registry->registered));
    }

    public function testAdoptRekeysSentinelLinkAndCommerceRowsIntoTheTenant(): void
    {
        $contributor = new CommerceAdoptionContributor($this->connection(), new TenantAdopter());
        $tenantUuid = Utils::generateNanoID();

        $this->seedPackTenantRows('');
        $this->connection()->table('commerce_products')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'slug' => 'adopt-sentinel',
            'name' => 'Adopt Sentinel Product',
        ]);

        $contributor->adopt($this->appContext(), $tenantUuid);

        foreach (self::PACK_TENANT_TABLES as $table) {
            self::assertSame(
                0,
                (int) $this->connection()->table($table)->where('tenant_uuid', '')->count(),
                "{$table} must have no sentinel rows left after adoption",
            );
            self::assertSame(
                1,
                (int) $this->connection()->table($table)->where('tenant_uuid', $tenantUuid)->count(),
                "{$table}'s sentinel row must be rekeyed into the adopting tenant",
            );
        }
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
     * One row in each pack-owned tenant table (or in just `$only`), carrying `$tenant` — which is
     * the sentinel `''` for the adoption tests and a real uuid for the purge ones. Keys are
     * randomized per call because three of the four tables carry a `(tenant_uuid, …)` unique.
     */
    private function seedPackTenantRows(string $tenant, ?string $only = null): void
    {
        $nonce = strtolower(Utils::generateNanoID(10));

        $rows = [
            'thallo_commerce_product_links' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => $tenant,
                'product_uuid' => Utils::generateNanoID(),
                'entry_uuid' => Utils::generateNanoID(),
            ],
            'thallo_commerce_payment_link_deliveries' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => $tenant,
                'idempotency_key' => 'purge-adoption-' . $nonce,
                'fingerprint' => str_repeat('b', 64),
                'order_uuid' => Utils::generateNanoID(),
                'recipient_hash' => str_repeat('c', 64),
                'mode' => 'current',
                'status' => 'sent',
            ],
            'thallo_commerce_product_slugs' => [
                'tenant_uuid' => $tenant,
                'slug' => 'purge-adoption-' . $nonce,
                'product_uuid' => Utils::generateNanoID(),
            ],
            'thallo_commerce_checkout_attempts' => [
                'tenant_uuid' => $tenant,
                'idempotency_key' => 'purge-adoption-' . $nonce,
                'request_fingerprint' => str_repeat('d', 64),
                'status' => 'completed',
            ],
        ];

        foreach ($rows as $table => $row) {
            if ($only !== null && $only !== $table) {
                continue;
            }
            $this->connection()->table($table)->insert($row);
        }
    }

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
        // Payment links Task 12: this fixture stands in for "the pack's own schema, with no
        // Commerce schema anywhere on the search_path", so it must carry EVERY pack-owned table
        // the purge handler touches — otherwise the handler's delivery-ledger cleanup would fail
        // for a missing table rather than for the absent-Commerce condition under test.
        $connection->getPDO()->exec(
            'CREATE TABLE thallo_commerce_payment_link_deliveries ('
            . 'id BIGSERIAL PRIMARY KEY, uuid VARCHAR(12), tenant_uuid VARCHAR(12) DEFAULT \'\', '
            . 'idempotency_key VARCHAR(191), fingerprint VARCHAR(64), order_uuid VARCHAR(12), '
            . 'link_uuid VARCHAR(12), recipient_hash VARCHAR(64), mode VARCHAR(16), '
            . 'status VARCHAR(16), error_code VARCHAR(64), provider_message_id VARCHAR(191), '
            . 'created_at TIMESTAMP, updated_at TIMESTAMP)'
        );
        // Cleanup-train Task 10, same reasoning: the fixture must carry EVERY pack-owned table
        // the purge handler touches, or the absent-Commerce case under test would fail for a
        // missing table instead.
        $connection->getPDO()->exec(
            'CREATE TABLE thallo_commerce_product_slugs ('
            . 'id BIGSERIAL PRIMARY KEY, tenant_uuid VARCHAR(12) DEFAULT \'\', '
            . 'slug VARCHAR(191), product_uuid VARCHAR(12), created_at TIMESTAMP)'
        );
        $connection->getPDO()->exec(
            'CREATE TABLE thallo_commerce_checkout_attempts ('
            . 'id BIGSERIAL PRIMARY KEY, tenant_uuid VARCHAR(12) DEFAULT \'\', '
            . 'idempotency_key VARCHAR(191), request_fingerprint VARCHAR(64), '
            . 'status VARCHAR(16), order_uuid VARCHAR(12), order_ref VARCHAR(191), '
            . 'guest_credential_ciphertext TEXT, created_at TIMESTAMP, updated_at TIMESTAMP)'
        );

        return $connection;
    }
}
