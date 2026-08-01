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
use App\Tests\Support\CountingPdoStatement;
use Glueful\Application;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\StorefrontWishlistResolver;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 7 (storefront-rendering spec §3/§6): ShopUrlGenerator + routes + catalog pages — shop
 * index, product detail (commerce-only and enrichment-linked), category archive.
 *
 * Tenant is driven via mode (b) (widened schema + persisted default tenant, {@see SystemFlags}),
 * mirroring ProductLinkServiceTest/ProductStoryStarterTest's identical convention in this same
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
        // The query-budget test installs the counting statement class on the SHARED suite
        // PDO — restore the default so no other test measures through it.
        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        $this->truncateCommerceCatalog();
        // Never leave 'widened' persisted past this class (ProductStoryStarterTest's identical
        // discipline): a later PHPUnit PROCESS's very first (process-shared) boot reads
        // thallo_system_flags before any test's setUp() truncates it.
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function truncateCommerceCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM commerce_product_addons');
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
        $this->assertPrefixIsRejected('');
    }

    public function testMultiSegmentShopPrefixThrowsAtBoot(): void
    {
        $this->assertPrefixIsRejected('a/b');
    }

    private function assertPrefixIsRejected(string $badPrefix): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        // The framework swallows extension-boot throwables (it logs "Extensions boot failed" and
        // continues), so a misconfigured prefix does NOT abort boot(). The pack resolves
        // ShopUrlGenerator eagerly in its boot() to validate the prefix; the RuntimeException that
        // validation raises therefore surfaces the moment ShopUrlGenerator is resolved (during that
        // eager pass, and on every shop route). Assert on THAT resolution — and keep the assertions
        // OUTSIDE the catch: `self::fail()`/`assert*()` raise an AssertionFailedError, which extends
        // RuntimeException, so a `catch (\RuntimeException)` around them would swallow the failure and
        // let the test pass vacuously (the bug this rewrite fixes).
        $caught = null;
        try {
            $context = self::bootAppWithConfigOverride('thallo-commerce', ['shop_prefix' => $badPrefix]);
            try {
                $context->getContainer()->get(ShopUrlGenerator::class);
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
        } finally {
            self::resetSharedRepositoryConnection();
        }

        self::assertInstanceOf(
            \RuntimeException::class,
            $caught,
            'shop_prefix = ' . var_export($badPrefix, true) . ' must be rejected when ShopUrlGenerator resolves',
        );
        self::assertStringContainsString('shop_prefix', $caught->getMessage());
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
        // JSON-LD is emitted via the json_script() Twig function into a raw <script> body —
        // pins that the structured-data script tag actually opens with real JSON, not an
        // escaped/empty payload.
        self::assertStringContainsString('<script type="application/ld+json">{', $html);
        // The enrichment container's `heading` block must render as an ACTUAL <h2> tag, not
        // HTML-escaped text — this is the regression net for the EntryBlocksRenderer
        // enrichment boundary (renderPublishedBlocks(): ?string -> ?Twig\Markup). If that
        // boundary regressed to double-escaping, the raw '<h2' tag would disappear and
        // '&lt;h2' would appear instead.
        self::assertStringContainsString('<h2', $html);
        self::assertStringNotContainsString('&lt;h2', $html);
    }

    /**
     * Fix B (Commerce-Slice-2 review): the starter "Product story" content type is route-less
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
        // The product uuid IS deliberately in the markup since storefront-v1 Task 6 — but ONLY
        // as the detail heart's wishlist wiring (spec §5: the store is UUID-keyed, same contract
        // as the grid cards). Exactly one occurrence, and it is that attribute.
        self::assertSame(
            1,
            substr_count($html, $productUuid),
            'the product uuid must appear ONLY as the detail heart data-product-uuid wiring',
        );
        self::assertStringContainsString('data-product-uuid="' . $productUuid . '"', $html);
        self::assertStringNotContainsString('catalog_revision', $html);
    }

    // ------------------------------------------------------------------
    // product page: gallery fallback + customer-facing money display
    // ------------------------------------------------------------------

    public function testProductPageRendersGalleryImagesAndDisplayPricing(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'gallery-money-prod', 8900);
        $variant = $this->connection()->table('commerce_variants')
            ->where('product_uuid', '=', $productUuid)
            ->first();
        self::assertNotNull($variant);
        $this->connection()->table('commerce_variants')
            ->where('uuid', '=', $variant['uuid'])
            ->update(['compare_at_price' => 12900]);

        // Admin-default media: role 'gallery' ONLY — no cover row. The storefront previously
        // rendered cover-role rows exclusively, so every admin-managed product (the picker
        // attaches with role 'gallery') shipped an imageless store page.
        $blobUuid = \Glueful\Helpers\Utils::generateNanoID();
        $this->connection()->table('blobs')->insert([
            'uuid' => $blobUuid,
            'name' => 'gallery-money.png',
            'mime_type' => 'image/png',
            'size' => 123,
            'url' => 'uploads/gallery-money.png',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(\Glueful\Extensions\Commerce\Catalog\ProductMediaService::class)
            ->attach($this->appContext(), $productUuid, [
                'blob_uuid' => $blobUuid,
                'role' => 'gallery',
            ]);

        $response = $this->handle(Request::create('/shop/products/gallery-money-prod', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        // The first gallery image leads even without a cover-role row, via the resolved
        // (API-prefixed, visibility-checked) media URL — never a hand-built '/blobs/…' path.
        self::assertStringContainsString('/blobs/' . $blobUuid, $html);
        self::assertStringContainsString('shop-product__cover', $html);
        // Customer-facing money display: the symbol form, not "89.00 USD"; compare-at struck.
        self::assertStringContainsString('$89.00', $html);
        self::assertStringContainsString('$129.00', $html);
        self::assertStringContainsString('shop-product__price-compare', $html);
    }

    public function testProductDescriptionRendersSanitizedRichHtml(): void
    {
        // Descriptions are rich HTML from the admin's RichText editor — rendered through the
        // render pack's fail-closed safe_html sanitizer: honest markup kept, scripts dropped.
        $this->seedProduct(
            self::TENANT_A,
            'rich-desc-prod',
            1000,
            '<p>Great <strong>lamp</strong></p><script>alert(1)</script>',
        );

        $response = $this->handle(Request::create('/shop/products/rich-desc-prod', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<strong>lamp</strong>', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('alert(1)', $html);
    }

    // ------------------------------------------------------------------
    // Concept A cards (storefront-v1 Task 5): honest cart modes, category
    // rail, tile tags, wishlist hearts, constant query budget
    // ------------------------------------------------------------------

    public function testGridCardRendersDirectPrgCartFormForSingleVariantNoRequiredAddonProduct(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'card-direct-prod', 1999);
        $variant = $this->connection()->table('commerce_variants')
            ->where('product_uuid', '=', $productUuid)
            ->first();
        self::assertNotNull($variant);

        $html = (string) $this->handle(Request::create('/shop', 'GET'))->getContent();

        // A REAL PRG form posting to the SAME endpoint shop.js's shop-form module already
        // intercepts — a working no-JS add, never a JS-only shell.
        self::assertStringContainsString('method="post" action="/_shop/cart/add"', $html);
        self::assertStringContainsString('name="variant_uuid" value="' . $variant['uuid'] . '"', $html);
        self::assertStringNotContainsString('shop-grid__action--options', $html);
    }

    public function testGridCardRendersOptionsLinkNotAFormWhenAnActiveRequiredAddonExists(): void
    {
        // The SAME single-active-variant fixture as the direct test above — the required
        // add-on ALONE must flip the card to options (AddToCartViewModel's link decision).
        $productUuid = $this->seedProduct(self::TENANT_A, 'card-addon-prod', 1999);
        $this->container()->get(AddonService::class)->create($this->appContext(), $productUuid, [
            'name' => 'Engraving',
            'field_type' => 'checkbox',
            'price_delta' => 500,
            'required' => true,
        ]);
        $urls = $this->container()->get(ShopUrlGenerator::class);

        $html = (string) $this->handle(Request::create('/shop', 'GET'))->getContent();

        self::assertStringNotContainsString('action="/_shop/cart/add"', $html);
        self::assertStringContainsString('shop-grid__action--options', $html);
        self::assertStringContainsString('href="' . $urls->product('card-addon-prod') . '"', $html);
    }

    public function testGridCardRendersOptionsLinkForAMultiVariantProduct(): void
    {
        $this->seedProduct(self::TENANT_A, 'card-multi-prod', 1999, variantCount: 2);
        $urls = $this->container()->get(ShopUrlGenerator::class);

        $html = (string) $this->handle(Request::create('/shop', 'GET'))->getContent();

        self::assertStringNotContainsString('action="/_shop/cart/add"', $html);
        self::assertStringContainsString('shop-grid__action--options', $html);
        self::assertStringContainsString('href="' . $urls->product('card-multi-prod') . '"', $html);
    }

    public function testCategoryRailRendersEveryChipAndMarksTheCategoryPageChipActive(): void
    {
        $gadgets = $this->seedCategory(self::TENANT_A, 'gadgets', 'Gadgets');
        $this->seedCategory(self::TENANT_A, 'lamps', 'Lamps');
        $productUuid = $this->seedProduct(self::TENANT_A, 'rail-prod', 999);
        (new CategoryRepository())->attachProduct($this->appContext(), $productUuid, $gadgets);
        $urls = $this->container()->get(ShopUrlGenerator::class);

        $index = (string) $this->handle(Request::create('/shop', 'GET'))->getContent();
        self::assertStringContainsString('href="' . $urls->category('gadgets') . '"', $index);
        self::assertStringContainsString('href="' . $urls->category('lamps') . '"', $index);
        // "All" is the active chip on the index.
        self::assertStringContainsString(
            'shop-rail__chip--active" aria-current="page" href="' . $urls->shopIndex() . '"',
            $index,
        );

        $archive = (string) $this->handle(Request::create('/shop/categories/gadgets', 'GET'))->getContent();
        self::assertStringContainsString(
            'shop-rail__chip--active" aria-current="page" href="' . $urls->category('gadgets') . '"',
            $archive,
        );
        self::assertStringContainsString('href="' . $urls->category('lamps') . '"', $archive);
        self::assertStringNotContainsString(
            'shop-rail__chip--active" aria-current="page" href="' . $urls->shopIndex() . '"',
            $archive,
        );
    }

    public function testTileTagShowsTheDeterministicFirstCategoryAndIsAbsentWithoutCategories(): void
    {
        // Category `position ASC` beats `name ASC`: 'Zeta First' (position 0) must win over
        // 'Alpha Late' (position 1) even though 'Alpha…' sorts first alphabetically.
        $first = $this->seedCategory(self::TENANT_A, 'zeta-first', 'Zeta First');
        $late = $this->seedCategory(self::TENANT_A, 'alpha-late', 'Alpha Late', position: 1);
        $tagged = $this->seedProduct(self::TENANT_A, 'tagged-prod', 999);
        $this->seedProduct(self::TENANT_A, 'untagged-prod', 999);
        (new CategoryRepository())->attachProduct($this->appContext(), $tagged, $first);
        (new CategoryRepository())->attachProduct($this->appContext(), $tagged, $late);

        $html = (string) $this->handle(Request::create('/shop', 'GET'))->getContent();

        self::assertStringContainsString('shop-grid__tag">Zeta First<', $html);
        // The untagged product's card carries NO tag — exactly one tag on the whole page.
        self::assertSame(1, substr_count($html, 'class="shop-grid__tag"'));
    }

    public function testGridCardHeartsRenderHiddenWithToggleWiringAndThePageRootCarriesTheScope(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'heart-prod', 999);
        $scope = $this->container()->get(StorefrontWishlistResolver::class)->storageScope();
        self::assertNotNull($scope, 'precondition: the wishlist seam must answer a scope in this suite');

        $html = (string) $this->handle(Request::create('/shop', 'GET'))->getContent();

        // Hearts ship `hidden` (spec §5): visible ONLY after the wishlist store initializes.
        self::assertStringContainsString(
            'hidden data-shop-wishlist-toggle data-product-uuid="' . $productUuid . '" aria-pressed="false"',
            $html,
        );
        self::assertStringContainsString('aria-label="Save Heart prod to wishlist"', $html);
        self::assertStringContainsString('data-shop-scope="' . $scope . '"', $html);
    }

    public function testShopIndexQueryCountIsConstantInProductCount(): void
    {
        // Ground-truth statement counting on the shared suite PDO (Task 3's helper): the
        // counter is cumulative, so warm up first, SNAPSHOT, then assert on the DELTA.
        // Installed BEFORE any traffic so every later prepare() yields a counting statement.
        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);

        for ($i = 1; $i <= 2; $i++) {
            $this->seedGridProductWithGalleryMedia('budget-prod-' . $i);
        }

        // Warm-up render: Twig compilation, settings/flags memos, route dispatch.
        self::assertSame(200, $this->handle(Request::create('/shop', 'GET'))->getStatusCode());

        $this->purgeShopCache();
        $before = CountingPdoStatement::$count;
        self::assertSame(200, $this->handle(Request::create('/shop', 'GET'))->getStatusCode());
        $twoProductQueries = CountingPdoStatement::$count - $before;

        for ($i = 3; $i <= 6; $i++) {
            $this->seedGridProductWithGalleryMedia('budget-prod-' . $i);
        }

        $this->purgeShopCache();
        $before = CountingPdoStatement::$count;
        self::assertSame(200, $this->handle(Request::create('/shop', 'GET'))->getStatusCode());
        $sixProductQueries = CountingPdoStatement::$count - $before;

        self::assertSame(
            $twoProductQueries,
            $sixProductQueries,
            sprintf(
                'shop index query count must be constant in product count — a per-card loop '
                . 'crept back in (2 products: %d queries, 6 products: %d queries)',
                $twoProductQueries,
                $sixProductQueries,
            ),
        );
    }

    // ------------------------------------------------------------------
    // Concept A product page (storefront-v1 Task 6): buy-area price data
    // attributes, quantity stepper, detail heart, breadcrumb, root scope
    // ------------------------------------------------------------------

    public function testProductPageEmitsPriceDataStepperDetailHeartAndRootScope(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'buy-area-prod', 8900);
        $scope = $this->container()->get(StorefrontWishlistResolver::class)->storageScope();
        self::assertNotNull($scope, 'precondition: the wishlist seam must answer a scope in this suite');

        $html = (string) $this->handle(Request::create('/shop/products/buy-area-prod', 'GET'))->getContent();

        // The pinned buy-area data attributes (spec amendment): data-currency +
        // data-currency-exponent ONCE on the form; direct mode carries the single variant's
        // minor price on the form (select mode moves it onto each <option> instead).
        self::assertStringContainsString(
            'data-currency="USD" data-currency-exponent="2" data-price-minor="8900">',
            $html,
        );
        // The stepper restyles the EXISTING quantity input — still the real form input, 1–99.
        self::assertStringContainsString('data-shop-qty-minus', $html);
        self::assertStringContainsString('data-shop-qty-plus', $html);
        self::assertStringContainsString(
            '<input type="number" name="quantity" min="1" max="99" step="1" value="1" inputmode="numeric">',
            $html,
        );
        // The server keeps rendering the unit price in the button text (the no-JS truth the
        // JS recompute later replaces).
        self::assertStringContainsString('data-shop-buy-price', $html);
        self::assertStringContainsString('$89.00', $html);
        // The detail heart: the EXACT attribute contract the grid cards ship (Task 5) —
        // hidden until the wishlist store is ready, product-specific label.
        self::assertStringContainsString(
            'hidden data-shop-wishlist-toggle data-product-uuid="' . $productUuid . '" aria-pressed="false"',
            $html,
        );
        self::assertStringContainsString('aria-label="Save Buy area prod to wishlist"', $html);
        // Spec §5: EVERY shop page root emits the opaque scope (same omit-when-null rule as
        // the index page's own root).
        self::assertStringContainsString('class="shop-product" data-shop-scope="' . $scope . '"', $html);
    }

    public function testProductPageSelectModeCarriesPriceMinorPerOption(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'buy-select-prod', 1999, variantCount: 2);
        $variants = $this->connection()->table('commerce_variants')
            ->where('product_uuid', '=', $productUuid)
            ->get();
        self::assertCount(2, $variants);

        $html = (string) $this->handle(Request::create('/shop/products/buy-select-prod', 'GET'))->getContent();

        foreach ($variants as $variant) {
            self::assertStringContainsString(
                '<option value="' . $variant['uuid'] . '" data-price-minor="' . $variant['price'] . '">',
                $html,
            );
        }
        // Currency + exponent stay ONCE on the form; the form-level price attribute is
        // direct-mode-only — in select mode the selected option is the price authority.
        self::assertStringContainsString('data-currency="USD" data-currency-exponent="2">', $html);
        self::assertDoesNotMatchRegularExpression('/<form[^>]*data-price-minor/', $html);
    }

    public function testProductPageBreadcrumbLinksThroughTheFirstCategory(): void
    {
        // Same deterministic "first" as the grid tags: position ASC beats name ASC.
        $first = $this->seedCategory(self::TENANT_A, 'zeta-crumb', 'Zeta Crumb');
        $late = $this->seedCategory(self::TENANT_A, 'alpha-crumb', 'Alpha Crumb', position: 1);
        $productUuid = $this->seedProduct(self::TENANT_A, 'crumb-prod', 999);
        $this->seedProduct(self::TENANT_A, 'crumbless-prod', 999);
        (new CategoryRepository())->attachProduct($this->appContext(), $productUuid, $first);
        (new CategoryRepository())->attachProduct($this->appContext(), $productUuid, $late);
        $urls = $this->container()->get(ShopUrlGenerator::class);

        $html = (string) $this->handle(Request::create('/shop/products/crumb-prod', 'GET'))->getContent();
        self::assertStringContainsString(
            '<a href="' . $urls->category('zeta-crumb') . '">Zeta Crumb</a>',
            $html,
        );
        self::assertStringNotContainsString('Alpha Crumb', $html);

        // No category assignment → no category crumb at all (never an empty link).
        $bare = (string) $this->handle(Request::create('/shop/products/crumbless-prod', 'GET'))->getContent();
        self::assertStringNotContainsString($urls->category('zeta-crumb'), $bare);
        self::assertStringNotContainsString($urls->category('alpha-crumb'), $bare);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function purgeShopCache(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('shop:*');
    }

    /**
     * A grid product with admin-default media: ONE role-'gallery' row (no cover) — the shape
     * that forced the old per-missing-cover fallback loop, so the query-budget test actually
     * exercises the media pipeline per card.
     */
    private function seedGridProductWithGalleryMedia(string $slug): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, $slug, 1999);
        $blobUuid = Utils::generateNanoID();
        $this->connection()->table('blobs')->insert([
            'uuid' => $blobUuid,
            'name' => $slug . '.png',
            'mime_type' => 'image/png',
            'size' => 123,
            'url' => 'uploads/' . $slug . '.png',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(ProductMediaService::class)->attach($this->appContext(), $productUuid, [
            'blob_uuid' => $blobUuid,
            'role' => 'gallery',
        ]);
    }

    private function seedProduct(
        string $tenant,
        string $slug,
        int $priceCents,
        ?string $description = null,
        string $status = 'active',
        int $variantCount = 1,
    ): string {
        $previous = $this->flags()->get('tenancy.default_tenant_uuid') ?? self::TENANT_A;
        $this->flags()->put('tenancy.default_tenant_uuid', $tenant);

        $variants = [];
        for ($i = 0; $i < $variantCount; $i++) {
            $variants[] = [
                'sku' => 'sku-' . $slug . '-' . (++self::$seq),
                'price' => $priceCents + $i,
                'currency' => 'USD',
                'option_values' => [],
            ];
        }

        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'description' => $description,
            'status' => $status,
            'type' => 'physical',
            'variants' => $variants,
        ]);

        $this->flags()->put('tenancy.default_tenant_uuid', $previous);

        return (string) $product['uuid'];
    }

    private function seedCategory(string $tenant, string $slug, string $name, int $position = 0): string
    {
        self::$seq++;
        $uuid = 'shpcat' . str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT);
        (new CategoryRepository())->insert($this->appContext(), [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
            'position' => $position,
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
     * calling {@see RouteRepository::assign()} at all, mirroring the starter "Product story"
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
