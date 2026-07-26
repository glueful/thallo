<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Starter\DefaultStarterContributorRegistry;
use App\Content\Starter\Kinds\ContentTypeKind;
use App\Content\Starter\StarterDefinition;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Starter\ProductStoryContributor;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Starter\StarterContributorRegistry;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 11: the starter "Product story" content-type contribution (design spec §9) — the parts
 * that need no real multi-tenant retrofit harness. Mirrors ProductLinkServiceTest/
 * ProductLinkApiTest's mode (b) convention (widened schema + a persisted default tenant via
 * {@see SystemFlags}) for the end-to-end linkage case, and ProductLinkApiTest's
 * `bootAppWithConfigOverride` convention for the disabled-capability and no-boot-writes cases.
 *
 * The genuinely tenancy-shaped coverage — fresh-tenant provisioning creates `product-story`, and
 * `thallo:tenant:sync --all --kind=content_type` adopts it into a pre-existing tenant
 * idempotently — needs `TenantSeeder`/`TenantSyncCommand` against a REAL widened schema and
 * therefore lives in {@see \App\Tests\Integration\Commerce\ProductStoryStarterTenancyTest}
 * (opt-in Postgres retrofit machinery, THALLO_TENANCY_DEV_LINK=1), mirroring exactly how Task 5
 * split {@see \App\Tests\Integration\Content\Starter\StarterContributorTest} (this file's
 * counterpart) from
 * {@see \App\Tests\Integration\Content\Starter\StarterContributorTenancyTest}.
 */
final class ProductStoryStarterTest extends AppTestCase
{
    private const TENANT = 'ppstesttenan';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT);
        $this->connection()->getPDO()->exec(
            "ALTER TABLE entries ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $this->connection()->getPDO()->exec('ALTER TABLE entries DROP COLUMN IF EXISTS tenant_uuid');
        // Never leave 'widened' persisted past this class: a later PHPUnit PROCESS's very first
        // (process-shared) boot reads thallo_system_flags before any test's setUp() truncates it
        // — a leftover 'widened' row would make that boot latch TenancyServiceProvider's compat
        // insert-hook permanently for the rest of that run.
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // capability gate
    // ------------------------------------------------------------------

    public function testCapabilityDisabledMeansProductStoryIsNotContributed(): void
    {
        // See ProductLinkApiTest::testRoutesAbsentWhenCapabilityDisabled's identical warning:
        // a second full boot with 'tenancy.schema_state' still 'widened' would make
        // TenancyServiceProvider::boot() latch a process-global compat write hook that outlives
        // this test. Reset before booting.
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        $registry = $disabledApp->getContainer()->get(StarterContributorRegistry::class);
        foreach ($registry->all() as $contributor) {
            self::assertNotInstanceOf(
                ProductStoryContributor::class,
                $contributor,
                'disabling thallo.commerce must keep ProductStoryContributor out of the registry',
            );
        }

        /** @var ContentTypeKind $kind */
        $kind = $disabledApp->getContainer()->get(ContentTypeKind::class);
        $slugs = array_map(
            static fn (StarterDefinition $d): string => $d->definitionKey,
            $kind->definitions(),
        );
        self::assertNotContains(ProductStoryContributor::SLUG, $slugs);

        self::resetSharedRepositoryConnection();
    }

    // ------------------------------------------------------------------
    // boot() write-safety
    // ------------------------------------------------------------------

    public function testProviderBootPerformsZeroTenantDataWrites(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        self::assertSame(
            0,
            (int) $this->connection()->table('content_types')->count(),
            'sanity: AppTestCase truncates content_types before every test',
        );

        // A REAL fresh Framework boot (thallo.commerce left at its default: enabled) runs
        // CommerceIntegrationServiceProvider::boot() for real, including the new
        // registerStarterContributor() call inside the capability-enabled branch.
        $freshBoot = self::bootAppWithConfigOverride('thallo', []);

        self::assertTrue(
            $freshBoot->getContainer()->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'),
            'sanity: the fresh boot must have the capability ON so registerStarterContributor() ran',
        );
        self::assertNotEmpty(
            array_filter(
                $freshBoot->getContainer()->get(StarterContributorRegistry::class)->all(),
                static fn (object $c): bool => $c instanceof ProductStoryContributor,
            ),
            'sanity: the contribution really was registered by this boot',
        );

        // The whole point: registering the contributor is a pure in-memory registry mutation —
        // no `content_types` row is ever created just by booting. Only fresh-tenant provisioning
        // or the explicit `thallo:tenant:sync` step may write that row (design spec §9).
        self::assertSame(
            0,
            (int) $this->connection()->table('content_types')->count(),
            'CommerceIntegrationServiceProvider::boot() must never write tenant data',
        );

        self::resetSharedRepositoryConnection();
    }

    // ------------------------------------------------------------------
    // end-to-end: a product-story entry links to a commerce product
    // ------------------------------------------------------------------

    public function testProductStoryEntryLinksToACommerceProductEndToEndAndResolvesBothWays(): void
    {
        $typeUuid = $this->createProductStoryType();
        $product = $this->seedProduct('product-page-e2e');
        $entry = $this->seedEntry($typeUuid);

        $service = $this->container()->get(ProductLinkService::class);

        self::assertNull($service->resolveByProduct($this->appContext(), $product));
        self::assertNull($service->resolveByEntry($this->appContext(), $entry));

        $row = $service->link($this->appContext(), $product, $entry);
        self::assertSame($product, $row['product_uuid']);
        self::assertSame($entry, $row['entry_uuid']);

        $byProduct = $service->resolveByProduct($this->appContext(), $product);
        self::assertNotNull($byProduct);
        self::assertSame($entry, $byProduct['entry_uuid']);

        $byEntry = $service->resolveByEntry($this->appContext(), $entry);
        self::assertNotNull($byEntry);
        self::assertSame($product, $byEntry['product_uuid']);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    /**
     * Persists a REAL `product-story` content-type row using the exact same
     * definition/conversion pipeline TenantSeeder/StarterSync use — a throwaway registry +
     * ContentTypeKind instance, so nothing here registers into the shared process boot's
     * container (mirrors StarterContributorTest's identical discipline).
     */
    private function createProductStoryType(): string
    {
        $repository = $this->container()->get(ContentTypeRepository::class);
        $registry = new DefaultStarterContributorRegistry();
        $registry->register(new ProductStoryContributor());
        $kind = new ContentTypeKind($repository, $this->connection(), $registry);

        foreach ($kind->definitions() as $definition) {
            if ($definition->definitionKey === ProductStoryContributor::SLUG) {
                return $repository->create($definition->payload);
            }
        }

        throw new \RuntimeException('product-story definition not found');
    }

    private function seedProduct(string $slug): string
    {
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug . '-' . (++self::$seq),
            'name' => ucfirst($slug),
            'status' => 'active',
            'type' => 'external',
            'metadata' => ['external_url' => 'https://example.test/' . $slug],
        ]);

        return (string) $product['uuid'];
    }

    /** Raw-seeds `entries` directly under the given content type (T8's established idiom). */
    private function seedEntry(string $contentTypeUuid): string
    {
        self::$seq++;
        $uuid = 'ppsent' . str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => $contentTypeUuid,
            'status' => 'active',
            'tenant_uuid' => self::TENANT,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }
}
