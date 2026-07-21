<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use PDO;
use Thallo\Commerce\CommerceIntegrationServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * Task 6: pack skeleton — capability, config, migration, tenant-table registration.
 * Exercises the shared process boot (see AppTestCase::setUpBeforeClass), which already
 * includes CommerceServiceProvider and CommerceIntegrationServiceProvider — reaching any
 * assertion here at all is itself proof the full app boots with the pack present.
 */
final class PackSkeletonTest extends AppTestCase
{
    public function testMigrationCreatesLinkTableWithBothUniquesAndTheIndex(): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        self::assertTrue($schema->hasTable('thallo_commerce_product_links'));

        $pdo = $this->connection()->getPDO();

        $constraints = $pdo->query(
            "select conname from pg_constraint where conrelid = 'thallo_commerce_product_links'::regclass"
        );
        self::assertNotFalse($constraints);
        $constraintNames = array_map(
            static fn (array $row): string => (string) $row['conname'],
            $constraints->fetchAll(PDO::FETCH_ASSOC)
        );
        self::assertContains('uniq_commerce_product_link_tenant_product', $constraintNames);
        self::assertContains('uniq_commerce_product_link_tenant_entry', $constraintNames);

        $indexes = $pdo->query(
            "select indexname from pg_indexes where tablename = 'thallo_commerce_product_links'"
        );
        self::assertNotFalse($indexes);
        $indexNames = array_map(
            static fn (array $row): string => (string) $row['indexname'],
            $indexes->fetchAll(PDO::FETCH_ASSOC)
        );
        self::assertContains('idx_commerce_product_link_tenant_product', $indexNames);
    }

    public function testCapabilityIsRegisteredAndEnabledByDefault(): void
    {
        $caps = $this->container()->get(CapabilityRegistry::class);

        $ids = array_map(static fn (Capability $c): string => $c->id, $caps->all());
        self::assertContains('thallo.commerce', $ids);
        self::assertTrue($caps->isEnabled('thallo.commerce'));
    }

    public function testRegisterProductLinkTableIsANoOpWithoutARegistryBound(): void
    {
        // Default test boot: THALLO_TENANCY_DEV_LINK is unset, so config/testing/extensions.php
        // strips the tenancy enforcement provider that would bind this contract (see its header).
        self::assertFalse($this->container()->has(TenantTableRegistryContract::class));

        $provider = new CommerceIntegrationServiceProvider($this->container());
        self::assertFalse($provider->registerProductLinkTable($this->appContext()));
    }

    public function testRegisterProductLinkTableRegistersExactlyOnceAcrossDoubleBoot(): void
    {
        $fake = $this->capturingRegistry();
        $provider = new CommerceIntegrationServiceProvider($this->container());

        self::assertTrue($provider->registerProductLinkTable($this->appContext(), $fake));
        self::assertTrue($provider->registerProductLinkTable($this->appContext(), $fake));

        self::assertSame(['thallo_commerce_product_links'], array_keys($fake->registered));
    }

    public function testCommerceConfigIsMergedAndMarketplaceIsDisabledByDefault(): void
    {
        $context = $this->appContext();

        // Proves CommerceServiceProvider::register() actually merged config/commerce.php: these
        // keys only exist if the merge happened (no code path elsewhere sets them).
        self::assertSame(30, (int) config($context, 'commerce.cart.ttl_days'));
        self::assertFalse((bool) config($context, 'commerce.marketplace.enabled'));

        // Proves CommerceIntegrationServiceProvider::register() merged its own config tree.
        self::assertSame(500, (int) config($context, 'thallo-commerce.reconcile.batch_size'));
    }

    public function testThalloBootsWithTheCommercePackPresent(): void
    {
        self::assertTrue(class_exists(CommerceServiceProvider::class));
        self::assertTrue(class_exists(CommerceIntegrationServiceProvider::class));
        self::assertTrue($this->container()->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'));
        self::assertNotNull(config($this->appContext(), 'commerce.currency'));
    }

    /** A capturing fake bound to the contract so we can assert what got registered. */
    private function capturingRegistry(): TenantTableRegistryContract
    {
        return new class implements TenantTableRegistryContract {
            /** @var array<string,true> */
            public array $registered = [];

            public function register(array $tables): void
            {
                // Set semantics, matching the interface's documented "re-registering a table
                // must be a no-op" (the same guarantee the production registry provides).
                foreach ($tables as $table) {
                    $this->registered[$table] = true;
                }
            }
        };
    }
}
