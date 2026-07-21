<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 7 (storefront-rendering spec §3/§6): ShopUrlGenerator + routes + catalog pages — shop
 * index, product detail (commerce-only and enrichment-linked), category archive.
 *
 * Tenant is driven via mode (b) (widened schema + persisted default tenant, {@see SystemFlags}),
 * mirroring ProductLinkServiceTest/ProductPageStarterTest's identical convention in this same
 * directory. Unlike those classes, this suite does NOT alter `entries` to add a transient
 * `tenant_uuid` column: {@see \App\Content\Authoring\EngineEntryExistenceReader::exists()} only
 * enforces the tenant check when that column is present on the row, so enrichment entries
 * created through the real authoring pipeline (no `tenant_uuid` column) resolve regardless of
 * which product tenant links to them — this suite never needs cross-tenant ENTRY isolation
 * (slice-1's ProductLinkServiceTest already covers that), only cross-tenant PRODUCT isolation.
 */
final class ShopCatalogTest extends AppTestCase
{
    private const TENANT_A = 'shoptesttena';
    private const TENANT_B = 'shoptesttenb';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateCommerceCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
    }

    protected function tearDown(): void
    {
        $this->truncateCommerceCatalog();
        // Never leave 'widened' persisted past this class (ProductPageStarterTest's identical
        // discipline): a later PHPUnit PROCESS's very first (process-shared) boot reads
        // thallo_system_flags before any test's setUp() truncates it.
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function truncateCommerceCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM commerce_product_categories');
        $pdo->exec('DELETE FROM commerce_product_media');
        $pdo->exec('DELETE FROM commerce_variants');
        $pdo->exec('DELETE FROM commerce_categories');
        $pdo->exec('DELETE FROM commerce_products');
    }

    // ------------------------------------------------------------------
    // capability gate + reserved-path shadow prevention
    // ------------------------------------------------------------------

    public function testShopIndexResolvesWhenCapabilityIsEnabled(): void
    {
        $this->seedProduct(self::TENANT_A, 'idx-on', 1999);

        $response = $this->handle(Request::create('/shop', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('idx-on', (string) $response->getContent());
    }

    public function testShopRoutes404WhenCapabilityIsDisabled(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);
        $hit = static fn (string $path): int => (new Application($disabledApp))
            ->handle(Request::create($path, 'GET'))
            ->getStatusCode();

        self::assertSame(404, $hit('/shop'));
        self::assertSame(404, $hit('/shop/products/whatever'));
        self::assertSame(404, $hit('/shop/categories/whatever'));

        self::resetSharedRepositoryConnection();
    }

    public function testBuilderPageAtShopPrefixCannotShadowTheCatalogIndexWhenCapabilityEnabled(): void
    {
        $this->seedBuilderPageAtRoute('shop', 'BUILDER-PAGE-MARKER');

        $response = $this->handle(Request::create('/shop', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('BUILDER-PAGE-MARKER', (string) $response->getContent());
        self::assertStringContainsString('shop-index', (string) $response->getContent());
    }

    public function testBuilderPageAtShopPrefixStaysReservedWhenCapabilityDisabled(): void
    {
        $this->seedBuilderPageAtRoute('shop', 'BUILDER-PAGE-MARKER');
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);
        $response = (new Application($disabledApp))->handle(Request::create('/shop', 'GET'));

        // The reserved-path contribution is registered OUTSIDE the capability gate — the
        // catch-all must never render the builder page even while thallo.commerce is off.
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('BUILDER-PAGE-MARKER', (string) $response->getContent());

        self::resetSharedRepositoryConnection();
    }

    // ------------------------------------------------------------------
    // prefix misconfiguration -> boot error
    // ------------------------------------------------------------------

    public function testEmptyShopPrefixThrowsAtBoot(): void
    {
        $this->assertPrefixThrowsAtBoot('');
    }

    public function testMultiSegmentShopPrefixThrowsAtBoot(): void
    {
        $this->assertPrefixThrowsAtBoot('a/b');
    }

    private function assertPrefixThrowsAtBoot(string $badPrefix): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        try {
            self::bootAppWithConfigOverride('thallo-commerce', ['shop_prefix' => $badPrefix]);
            self::fail('expected boot to throw for shop_prefix = ' . var_export($badPrefix, true));
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('shop_prefix', $e->getMessage());
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ------------------------------------------------------------------
    // product detail: unlinked vs enrichment-linked
    // ------------------------------------------------------------------

    public function testProductPageRendersCommerceDataAloneWhenUnlinked(): void
    {
        $this->seedProduct(self::TENANT_A, 'unlinked-prod', 2500, 'A plain description');

        $response = $this->handle(Request::create('/shop/products/unlinked-prod', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Unlinked prod', $html); // product name (slug, hyphens -> spaces, title-cased)
        self::assertStringContainsString('25.00', $html);
        self::assertStringNotContainsString('shop-product__enrichment', $html);
    }

    public function testProductPageRendersEnrichmentBlocksWhenLinked(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'linked-prod', 3000);
        $typeUuid = $this->createEnrichmentType();
        $entryUuid = $this->seedEnrichmentEntry($typeUuid, 'en', 'linked-prod-entry', 'ENRICHMENT-BLOCK-MARKER');

        $this->container()->get(ProductLinkService::class)->link($this->appContext(), $productUuid, $entryUuid);

        $response = $this->handle(Request::create('/shop/products/linked-prod', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('shop-product__enrichment', $html);
        self::assertStringContainsString('ENRICHMENT-BLOCK-MARKER', $html);
    }

    /**
     * Fix B (Commerce-Slice-2 review): the starter "Product page" content type is route-less
     * in normal editorial use — its canonical URL is the SHOP product page
     * (`/shop/products/{slug}`), so an editor has no reason to ever assign it a route of its
     * own via {@see \App\Content\Repositories\RouteRepository::assign()}. Before Fix B,
     * `ShopCatalogController::resolveEnrichmentEntry()` resolved the link through
     * `PublicRouteResolver::resolveEntry()`, which requires a live `entry_routes` row and
     * returns `not_found` otherwise — a route-less linked entry's enrichment silently never
     * rendered. This entry carries ZERO `entry_routes` rows (unlike
     * `seedEnrichmentEntry()`/`testProductPageRendersEnrichmentBlocksWhenLinked` above, which
     * assigns one) — proving the fix is route-independent, not incidentally passing because a
     * route happens to exist. Also pins the two invariants the fix must never violate: the
     * canonical URL and JSON-LD `url` stay `/shop/products/{slug}` (never the entry's own
     * path — it doesn't have one), and the shop product-detail template renders (never the
     * entry's own `entry/{type}.twig`, which this suite never even defines).
     */
    public function testProductPageRendersEnrichmentBlocksWhenLinkedEntryIsRouteLess(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'routeless-prod', 3000);
        $typeUuid = $this->createEnrichmentType();
        $entryUuid = $this->seedRouteLessEnrichmentEntry($typeUuid, 'en', 'ROUTELESS-BLOCK-MARKER');
        self::assertSame(
            [],
            $this->connection()->table('entry_routes')->where('entry_uuid', '=', $entryUuid)->get(),
            'precondition: the linked entry must carry zero route rows',
        );

        $this->container()->get(ProductLinkService::class)->link($this->appContext(), $productUuid, $entryUuid);

        $response = $this->handle(Request::create('/shop/products/routeless-prod', 'GET'));
        $html = (string) $response->getContent();
        $urls = $this->container()->get(ShopUrlGenerator::class);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('shop-product__enrichment', $html);
        self::assertStringContainsString('ROUTELESS-BLOCK-MARKER', $html);
        // Canonical URL stays the shop path — the entry never becomes routing authority.
        self::assertStringContainsString('rel="canonical" href="' . $urls->product('routeless-prod') . '"', $html);
        // The shop product-detail template rendered — not the entry's own page template
        // (this suite defines no entry/{type}.twig for the enrichment type at all).
        self::assertStringContainsString('class="shop-product"', $html);
    }

    /**
     * Fix B fail-closed proof: a linked entry that exists (right tenant, live) but has never
     * been PUBLISHED — draft only — must render commerce-only, never an error. Also
     * route-less, since draft-only entries never get a route either in normal use.
     */
    public function testProductPageEnrichmentIsCommerceOnlyWhenLinkedEntryIsDraftOnly(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'draft-linked-prod', 3000);
        $typeUuid = $this->createEnrichmentType();
        $entryUuid = $this->seedDraftOnlyEnrichmentEntry($typeUuid, 'en');

        $this->container()->get(ProductLinkService::class)->link($this->appContext(), $productUuid, $entryUuid);

        $response = $this->handle(Request::create('/shop/products/draft-linked-prod', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('shop-product__enrichment', $html);
    }

    /**
     * Fix B fail-closed proof: a linked entry that gets soft-deleted AFTER linking must render
     * commerce-only, never an error — mirrors {@see ProductLinkService}'s own
     * `liveOrNull()` guard, now ALSO independently enforced by
     * {@see \Thallo\Render\EntryBlocksRenderer}'s own read path (defense in depth: the
     * enrichment seam never trusts the link row alone).
     */
    public function testProductPageEnrichmentIsCommerceOnlyWhenLinkedEntryIsDeleted(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'deleted-linked-prod', 3000);
        $typeUuid = $this->createEnrichmentType();
        $entryUuid = $this->seedRouteLessEnrichmentEntry($typeUuid, 'en', 'SHOULD-NEVER-APPEAR');
        $this->container()->get(ProductLinkService::class)->link($this->appContext(), $productUuid, $entryUuid);
        $this->connection()->table('entries')->where('uuid', '=', $entryUuid)->update(['status' => 'deleted']);

        $response = $this->handle(Request::create('/shop/products/deleted-linked-prod', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('shop-product__enrichment', $html);
        self::assertStringNotContainsString('SHOULD-NEVER-APPEAR', $html);
    }

    public function testProductPageTombstonedProductIsNonRevealing404(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'tombstoned-prod', 1500);
        $this->container()->get(CatalogService::class)->deleteProduct($this->appContext(), $productUuid);

        $response = $this->handle(Request::create('/shop/products/tombstoned-prod', 'GET'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testProductPageCrossTenantProductIsNonRevealing404(): void
    {
        $this->seedProduct(self::TENANT_B, 'cross-tenant-prod', 1500);
        // Current default resolves as TENANT_A (set in setUp) — a slug that only exists under
        // TENANT_B must be indistinguishable from an unknown slug.
        $response = $this->handle(Request::create('/shop/products/cross-tenant-prod', 'GET'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testProductPageUnknownSlugIsNonRevealing404(): void
    {
        $response = $this->handle(Request::create('/shop/products/does-not-exist', 'GET'));

        self::assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // category archive
    // ------------------------------------------------------------------

    public function testCategoryArchiveListsLiveProductsOnly(): void
    {
        $categoryUuid = $this->seedCategory(self::TENANT_A, 'gadgets', 'Gadgets');
        $liveUuid = $this->seedProduct(self::TENANT_A, 'live-gadget', 999, status: 'active');
        $draftUuid = $this->seedProduct(self::TENANT_A, 'draft-gadget', 999, status: 'draft');
        (new CategoryRepository())->attachProduct($this->appContext(), $liveUuid, $categoryUuid);
        (new CategoryRepository())->attachProduct($this->appContext(), $draftUuid, $categoryUuid);

        $response = $this->handle(Request::create('/shop/categories/gadgets', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('live-gadget', $html);
        self::assertStringNotContainsString('draft-gadget', $html);
    }

    public function testCategoryArchiveUnknownSlugIsNonRevealing404(): void
    {
        $response = $this->handle(Request::create('/shop/categories/does-not-exist', 'GET'));

        self::assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // every URL in rendered markup comes from ShopUrlGenerator
    // ------------------------------------------------------------------

    public function testEveryProductAndCanonicalUrlInRenderedShopIndexComesFromShopUrlGenerator(): void
    {
        $this->seedProduct(self::TENANT_A, 'url-check-prod', 1234);
        $urls = $this->container()->get(ShopUrlGenerator::class);

        $response = $this->handle(Request::create('/shop', 'GET'));
        $html = (string) $response->getContent();

        self::assertStringContainsString('href="' . $urls->shopIndex() . '"', $html);
        self::assertStringContainsString('href="' . $urls->product('url-check-prod') . '"', $html);
    }

    public function testProductPageCanonicalAndJsonLdUseShopUrlGenerator(): void
    {
        $this->seedProduct(self::TENANT_A, 'ld-check-prod', 4321);
        $urls = $this->container()->get(ShopUrlGenerator::class);
        $expected = $urls->product('ld-check-prod');

        $response = $this->handle(Request::create('/shop/products/ld-check-prod', 'GET'));
        $html = (string) $response->getContent();

        self::assertStringContainsString('rel="canonical" href="' . $expected . '"', $html);
        self::assertStringContainsString('"url":"' . $expected . '"', $html);
    }

    // ------------------------------------------------------------------
    // closed view models: internal columns never leak
    // ------------------------------------------------------------------

    public function testProductViewModelNeverLeaksInternalColumns(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'poison-check-prod', 1000);
        // Poison the row's internal-only columns with a distinctive marker AFTER creation —
        // never allowlisted onto ProductViewModel.
        $this->connection()->table('commerce_products')
            ->where('uuid', '=', $productUuid)
            ->update(['metadata' => json_encode(['poison' => 'POISON-MARKER-XYZ'])]);

        $response = $this->handle(Request::create('/shop/products/poison-check-prod', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('POISON-MARKER-XYZ', $html);
        self::assertStringNotContainsString(self::TENANT_A, $html, 'tenant_uuid must never leak into markup');
        self::assertStringNotContainsString($productUuid, $html, 'the internal uuid must never leak into markup');
        self::assertStringNotContainsString('catalog_revision', $html);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function seedProduct(
        string $tenant,
        string $slug,
        int $priceCents,
        ?string $description = null,
        string $status = 'active',
    ): string {
        $previous = $this->flags()->get('tenancy.default_tenant_uuid') ?? self::TENANT_A;
        $this->flags()->put('tenancy.default_tenant_uuid', $tenant);

        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'description' => $description,
            'status' => $status,
            'type' => 'physical',
            'variants' => [[
                'sku' => 'sku-' . $slug . '-' . (++self::$seq),
                'price' => $priceCents,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);

        $this->flags()->put('tenancy.default_tenant_uuid', $previous);

        return (string) $product['uuid'];
    }

    private function seedCategory(string $tenant, string $slug, string $name): string
    {
        self::$seq++;
        $uuid = 'shpcat' . str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT);
        (new CategoryRepository())->insert($this->appContext(), [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
            'position' => 0,
        ]);

        return $uuid;
    }

    /** Ad-hoc content type with a `body` blocks field — the enrichment entry's target type. */
    private function createEnrichmentType(): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'shop_enrichment_test_' . (++self::$seq),
            'name' => 'Shop Enrichment Test',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    /**
     * A real published + routed entry whose `body` field carries one `heading` block (seeded
     * into the global block-type registry below) — the marker text this suite asserts appears
     * in the rendered product page when a link exists.
     */
    private function seedEnrichmentEntry(
        string $typeUuid,
        string $locale,
        string $routeSlug,
        string $markerText,
    ): string {
        $this->ensureHeadingBlockTypeSeeded();

        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, $locale, 1, 'user00000001');
        $entries->saveDraft($entry, $locale, [
            'title' => 'Enrichment entry',
            'body' => [['id' => 'shpblkmarker', 'type' => 'heading', 'data' => ['text' => $markerText]]],
        ], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $typeUuid, $locale, $routeSlug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, $locale, 'user00000001');

        return $entry;
    }

    /**
     * Fix B fixture: a real published entry with a `heading` block — deliberately WITHOUT
     * calling {@see RouteRepository::assign()} at all, mirroring the starter "Product page"
     * type's normal editorial use (linked purely for enrichment; the canonical URL is the
     * shop path, not this entry's own).
     */
    private function seedRouteLessEnrichmentEntry(string $typeUuid, string $locale, string $markerText): string
    {
        $this->ensureHeadingBlockTypeSeeded();

        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, $locale, 1, 'user00000001');
        $entries->saveDraft($entry, $locale, [
            'title' => 'Route-less enrichment entry',
            'body' => [['id' => 'shpblkrless', 'type' => 'heading', 'data' => ['text' => $markerText]]],
        ], 1, 0, 'user00000001');
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, $locale, 'user00000001');

        return $entry;
    }

    /** Fix B fixture: draft only — created, saved, never published, never routed. */
    private function seedDraftOnlyEnrichmentEntry(string $typeUuid, string $locale): string
    {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, $locale, 1, 'user00000001');
        $entries->saveDraft($entry, $locale, ['title' => 'Never published enrichment'], 1, 0, 'user00000001');

        return $entry;
    }

    private function ensureHeadingBlockTypeSeeded(): void
    {
        $blockTypes = $this->container()->get(BlockTypeRepository::class);
        if ($blockTypes->findBySlug('heading') !== null) {
            return;
        }
        $blockTypes->create([
            'slug' => 'heading',
            'label' => 'Heading',
            'schema' => [['name' => 'text', 'type' => 'string']],
        ]);
    }

    /** A "builder" (route-catch-all-served) page entry at the given route slug. */
    private function seedBuilderPageAtRoute(string $routeSlug, string $markerText): string
    {
        $typeUuid = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'shop_builder_page_test_' . (++self::$seq),
            'name' => 'Shop Builder Page Test',
            'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => $markerText], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $typeUuid, 'en', $routeSlug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');

        return $entry;
    }
}
