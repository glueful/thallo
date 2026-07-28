<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Starter\DefaultStarterBlockTypeRegistry;
use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\CommerceIntegrationServiceProvider;
use Thallo\Commerce\Shop\ManualProductListNormalizer;
use Thallo\Commerce\Shop\ShopAssetMap;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Contracts\Delivery\StorefrontWishlistResolver;
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 11 (storefront-rendering spec §5.2/§10) + storefront-v1 Task 8 (spec §5): the 5 shop
 * block types (`wishlist-link` joined the four originals) + their templates + fingerprinted
 * asset serving, plus the structural parity gate between `_product_card.twig` and shop.js's
 * `buildProductCard()`. {@see \App\Tests\Integration\Commerce\ShopJsRuntimeTest} covers
 * `shop.js`'s executable JS contract; {@see \App\Tests\Integration\Commerce\ShopBlockTypeProvisioningTest}
 * covers the DEV_LINK-gated fresh-tenant provisioning + `thallo:tenant:sync` adoption (mirrors
 * the ProductStoryStarterTest/ProductStoryStarterTenancyTest split for the identical reason: none
 * of the coverage here needs a real multi-tenant retrofit harness).
 */
final class ShopBlocksTest extends AppTestCase
{
    private const TENANT_A = 'blockstestte'; // exactly 12 chars — tenant_uuid columns are varchar(12)

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateCommerceCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
        // Mirrors ProductStoryStarterTest's identical idiom: seedLinkedEntry() below inserts
        // directly into `entries`, and EngineEntryExistenceReader checks the row's OWN
        // tenant_uuid whenever the column is present — defensively ensured here regardless of
        // migration state.
        $this->connection()->getPDO()->exec(
            "ALTER TABLE entries ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }

    protected function tearDown(): void
    {
        $this->truncateCommerceCatalog();
        $this->connection()->getPDO()->exec('ALTER TABLE entries DROP COLUMN IF EXISTS tenant_uuid');
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function truncateCommerceCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM commerce_product_addons');
        $pdo->exec('DELETE FROM commerce_product_categories');
        $pdo->exec('DELETE FROM commerce_product_tags');
        $pdo->exec('DELETE FROM commerce_categories');
        $pdo->exec('DELETE FROM commerce_tags');
        $pdo->exec('DELETE FROM commerce_variants');
        $pdo->exec('DELETE FROM commerce_products');
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    // ==================================================================
    // A. Contributor definitions (pure)
    // ==================================================================

    public function testContributorReturnsTheFiveDefinitionsWithStableSourceIdsAndSlugs(): void
    {
        $definitions = (new ShopBlockTypesContributor())->blockTypeDefinitions();
        self::assertCount(5, $definitions);

        $bySlug = [];
        foreach ($definitions as $definition) {
            $bySlug[$definition->slug] = $definition;
            self::assertSame('thallo-commerce:' . $definition->slug, $definition->sourceId);
        }

        self::assertArrayHasKey('product-grid', $bySlug);
        self::assertArrayHasKey('featured-product', $bySlug);
        self::assertArrayHasKey('add-to-cart', $bySlug);
        self::assertArrayHasKey('mini-cart', $bySlug);
        self::assertArrayHasKey('wishlist-link', $bySlug);
        self::assertSame('Commerce', $bySlug['product-grid']->category);
        self::assertSame('Commerce', $bySlug['wishlist-link']->category);
        self::assertSame(ShopBlockTypesContributor::SLUG_WISHLIST_LINK, $bySlug['wishlist-link']->slug);
    }

    public function testWishlistLinkSchemaIsASingleOptionalLabelString(): void
    {
        $definitions = (new ShopBlockTypesContributor())->blockTypeDefinitions();
        $wishlist = self::findBySlug($definitions, 'wishlist-link');

        self::assertCount(1, $wishlist->schema);
        self::assertSame('label', $wishlist->schema[0]['name']);
        self::assertSame('string', $wishlist->schema[0]['type']);
        self::assertFalse((bool) ($wishlist->schema[0]['required'] ?? false));
    }

    public function testContributorSchemasPassBlockSchemaValidation(): void
    {
        $blocks = $this->container()->get(BlockTypeRepository::class);
        foreach ((new ShopBlockTypesContributor())->blockTypeDefinitions() as $definition) {
            $blocks->assertBlockSchema($definition->schema); // must not throw
        }
        self::assertTrue(true);
    }

    public function testProductGridSchemaHasTheDocumentedFieldsAndEnums(): void
    {
        $definitions = (new ShopBlockTypesContributor())->blockTypeDefinitions();
        $grid = self::findBySlug($definitions, 'product-grid');
        $byName = [];
        foreach ($grid->schema as $field) {
            $byName[$field['name']] = $field;
        }

        self::assertSame('enum', $byName['source']['type']);
        self::assertSame(['category', 'tag', 'manual', 'newest'], $byName['source']['enum']);
        self::assertSame('string', $byName['category_slug']['type']);
        self::assertSame('string', $byName['tag_slug']['type']);
        self::assertSame('text', $byName['products']['type']);
        self::assertSame('enum', $byName['page_size']['type']);
        self::assertSame(['small', 'medium', 'large'], $byName['page_size']['enum']);
    }

    public function testAddToCartProductSlugFieldIsNotRequired(): void
    {
        $definitions = (new ShopBlockTypesContributor())->blockTypeDefinitions();
        $addToCart = self::findBySlug($definitions, 'add-to-cart');
        self::assertFalse((bool) ($addToCart->schema[0]['required'] ?? false));
    }

    public function testMiniCartHasNoFields(): void
    {
        $definitions = (new ShopBlockTypesContributor())->blockTypeDefinitions();
        self::assertSame([], self::findBySlug($definitions, 'mini-cart')->schema);
    }

    // ==================================================================
    // B. Capability gate + registration
    // ==================================================================

    public function testCapabilityDisabledMeansNoShopBlockTypesAreContributed(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        $registry = $disabledApp->getContainer()->get(StarterBlockTypeRegistry::class);
        self::assertCount(0, array_filter(
            $registry->all(),
            static fn (object $c): bool => $c instanceof ShopBlockTypesContributor,
        ));

        self::resetSharedRepositoryConnection();
    }

    public function testProviderBootPerformsZeroTenantDataWritesForBlockTypes(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        self::assertSame(0, (int) $this->connection()->table('block_types')->count());

        $freshBoot = self::bootAppWithConfigOverride('thallo', []);

        self::assertNotEmpty(array_filter(
            $freshBoot->getContainer()->get(StarterBlockTypeRegistry::class)->all(),
            static fn (object $c): bool => $c instanceof ShopBlockTypesContributor,
        ));
        self::assertSame(
            0,
            (int) $this->connection()->table('block_types')->count(),
            'registering the contributor must never itself write a block_types row',
        );

        self::resetSharedRepositoryConnection();
    }

    public function testRegisterShopBlockTypeContributorIsIdempotent(): void
    {
        $provider = new CommerceIntegrationServiceProvider($this->container());
        $registry = new DefaultStarterBlockTypeRegistry();

        $provider->registerShopBlockTypeContributor($this->appContext(), $registry);
        $provider->registerShopBlockTypeContributor($this->appContext(), $registry);

        self::assertCount(1, $registry->all());
    }

    // ==================================================================
    // C. Manual product-list normalizer (pure)
    // ==================================================================

    public function testNormalizerTrimsAndDropsBlankLines(): void
    {
        self::assertSame(
            ['a', 'b'],
            ManualProductListNormalizer::normalize("  a  \n\n \t \nb\n\n"),
        );
    }

    public function testNormalizerDedupesInStableFirstOccurrenceOrder(): void
    {
        self::assertSame(
            ['a', 'b', 'c'],
            ManualProductListNormalizer::normalize("a\nb\na\nc\nb"),
        );
    }

    public function testNormalizerAcceptsExactlyFifty(): void
    {
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = 'slug-' . $i;
        }
        $result = ManualProductListNormalizer::normalize(implode("\n", $lines));

        self::assertCount(50, $result);
        self::assertSame('slug-0', $result[0]);
        self::assertSame('slug-49', $result[49]);
    }

    public function testNormalizerRejectsOverflowRatherThanTruncating(): void
    {
        $lines = [];
        for ($i = 0; $i < 51; $i++) {
            $lines[] = 'slug-' . $i;
        }

        $this->expectException(\InvalidArgumentException::class);
        ManualProductListNormalizer::normalize(implode("\n", $lines));
    }

    public function testNormalizerRejectsCommaDelimitedInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ManualProductListNormalizer::normalize("a\nb,c\nd");
    }

    public function testNormalizerRejectsACommaAnywhereEvenPastTheCap(): void
    {
        $lines = [];
        for ($i = 0; $i < 60; $i++) {
            $lines[] = 'slug-' . $i;
        }
        $lines[] = 'x,y'; // beyond the 50-cap boundary — must still be rejected, not ignored.

        $this->expectException(\InvalidArgumentException::class);
        ManualProductListNormalizer::normalize(implode("\n", $lines));
    }

    // ==================================================================
    // D. ShopAssetMap / ShopAssetController / ShopUrlGenerator::assets()
    // ==================================================================

    private function assetsDir(): string
    {
        return $this->appContext()->getBasePath() . '/packages/thallo-commerce/assets';
    }

    public function testAssetMapResolvesOnlyExactAllowlistedNames(): void
    {
        $map = new ShopAssetMap($this->assetsDir());
        $fingerprinted = $map->fingerprintedName('shop.js');

        self::assertNotNull($fingerprinted);
        self::assertMatchesRegularExpression('/\Ashop-[0-9a-f]{12}\.js\z/', $fingerprinted);
        self::assertNotNull($map->resolve($fingerprinted));

        // Never concatenates the given name into a filesystem path — an exact-lookup miss,
        // not a resolved (and therefore possibly escaping) path.
        self::assertNull($map->resolve('../../../../etc/passwd'));
        self::assertNull($map->resolve('..'));
        self::assertNull($map->resolve('shop.js')); // the LOGICAL name is not itself a file key
        self::assertNull($map->resolve('unknown-file.js'));
    }

    public function testAssetMapFingerprintIsStableAcrossInstances(): void
    {
        $first = (new ShopAssetMap($this->assetsDir()))->fingerprintedName('shop.js');
        $second = (new ShopAssetMap($this->assetsDir()))->fingerprintedName('shop.js');

        self::assertSame($first, $second);
    }

    public function testShopUrlGeneratorAssetsReturnsTheFingerprintedUrlAndIsStable(): void
    {
        $urls = $this->container()->get(ShopUrlGenerator::class);

        $first = $urls->assets();
        $second = $urls->assets();

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('#\A/_shop/assets/shop-[0-9a-f]{12}\.js\z#', $first);
    }

    public function testAssetAliasRedirectsToTheFingerprintedUrl(): void
    {
        $response = $this->handle(Request::create('/_shop/assets/shop.js', 'GET'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            $this->container()->get(ShopUrlGenerator::class)->assets(),
            $response->headers->get('Location'),
        );
    }

    public function testFingerprintedAssetServesImmutableJavaScript(): void
    {
        $url = $this->container()->get(ShopUrlGenerator::class)->assets();
        $response = $this->handle(Request::create($url, 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('javascript', (string) $response->headers->get('Content-Type'));
        self::assertSame(
            (string) file_get_contents($this->assetsDir() . '/shop.js'),
            (string) $response->getContent(),
        );
    }

    public function testUnknownAndTraversalAssetNamesReturn404(): void
    {
        foreach (['unknown-file.js', '..', 'shop-deadbeefdead.js'] as $name) {
            $response = $this->handle(Request::create('/_shop/assets/' . $name, 'GET'));
            self::assertSame(404, $response->getStatusCode(), "expected 404 for '{$name}'");
        }
    }

    public function testAssetRouteAbsentWhenCapabilityDisabled(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        $response = (new Application($disabledApp))->handle(Request::create('/_shop/assets/shop.js', 'GET'));
        self::assertSame(404, $response->getStatusCode());

        self::resetSharedRepositoryConnection();
    }

    // ==================================================================
    // E. Block-data JSON endpoints
    // ==================================================================

    public function testProductGridNewestSourceReturnsSeededProductsAndShopIndexAsViewAll(): void
    {
        $this->seedSimpleProduct('grid-newest-1', 'Newest one');
        $this->seedSimpleProduct('grid-newest-2', 'Newest two');

        $data = $this->jsonBody($this->handle(Request::create('/_shop/blocks/product-grid?source=newest', 'GET')));

        self::assertCount(2, $data['items']);
        self::assertSame($this->urls()->shopIndex(), $data['view_all_url']);
        self::assertStringNotContainsString('page=', $data['view_all_url']);
    }

    public function testProductGridCategorySourceFiltersAndViewAllIsTheCategoryArchive(): void
    {
        $matching = $this->seedSimpleProduct('grid-cat-match', 'In category');
        $other = $this->seedSimpleProduct('grid-cat-other', 'Not in category');
        $categoryUuid = $this->seedCategory('grid-cat-slug');
        $this->attachCategory($matching, $categoryUuid);

        $data = $this->jsonBody($this->handle(Request::create(
            '/_shop/blocks/product-grid?source=category&category_slug=grid-cat-slug',
            'GET',
        )));

        self::assertCount(1, $data['items']);
        self::assertSame('In category', $data['items'][0]['name']);
        self::assertSame($this->urls()->category('grid-cat-slug'), $data['view_all_url']);
        self::assertStringNotContainsString('page=', $data['view_all_url']);
        unset($other);
    }

    public function testProductGridTagSourceFiltersAndViewAllIsShopIndex(): void
    {
        $matching = $this->seedSimpleProduct('grid-tag-match', 'Has tag');
        $this->seedSimpleProduct('grid-tag-other', 'No tag');
        $tagUuid = $this->seedTag('grid-tag-slug');
        $this->attachTag($matching, $tagUuid);

        $data = $this->jsonBody($this->handle(Request::create(
            '/_shop/blocks/product-grid?source=tag&tag_slug=grid-tag-slug',
            'GET',
        )));

        self::assertCount(1, $data['items']);
        self::assertSame('Has tag', $data['items'][0]['name']);
        self::assertSame($this->urls()->shopIndex(), $data['view_all_url']);
    }

    public function testProductGridManualSourceResolvesInGivenOrderSkippingMissing(): void
    {
        $this->seedSimpleProduct('grid-manual-a', 'Manual A');
        $this->seedSimpleProduct('grid-manual-b', 'Manual B');

        $products = "grid-manual-b\nno-such-slug\ngrid-manual-a";
        $data = $this->jsonBody($this->handle(Request::create(
            '/_shop/blocks/product-grid?source=manual&products=' . urlencode($products),
            'GET',
        )));

        self::assertSame(['Manual B', 'Manual A'], array_column($data['items'], 'name'));
        self::assertSame($this->urls()->shopIndex(), $data['view_all_url']);
    }

    public function testProductGridManualSourceRejectsCommaDelimitedInput(): void
    {
        $response = $this->handle(Request::create(
            '/_shop/blocks/product-grid?source=manual&products=' . urlencode('a,b,c'),
            'GET',
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame([], $data['items']);
    }

    public function testProductGridPageSizeMapsSmallMediumLargeToTwelveTwentyFourFortyEight(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->seedSimpleProduct('grid-size-' . $i, 'Size product ' . $i);
        }

        $small = $this->getBlockJson('/_shop/blocks/product-grid?source=newest&page_size=12');
        self::assertCount(12, $small['items']);

        $large = $this->getBlockJson('/_shop/blocks/product-grid?source=newest&page_size=48');
        self::assertCount(30, $large['items']); // only 30 exist — never more than available
    }

    public function testFeaturedProductResolvesByExplicitSlug(): void
    {
        $this->seedSimpleProduct('featured-explicit', 'Featured explicit');

        $data = $this->jsonBody($this->handle(Request::create(
            '/_shop/blocks/featured-product?product_slug=featured-explicit',
            'GET',
        )));

        self::assertNotNull($data['product']);
        self::assertSame('Featured explicit', $data['product']['name']);
    }

    public function testFeaturedProductWithNoResolvableContextReturnsNull(): void
    {
        $data = $this->jsonBody($this->handle(Request::create('/_shop/blocks/featured-product', 'GET')));
        self::assertNull($data['product']);
    }

    public function testFeaturedProductFallsBackToTheLinkedEntrysProduct(): void
    {
        $productUuid = $this->seedSimpleProduct('featured-linked', 'Featured linked');
        $entryUuid = $this->seedLinkedEntry($productUuid);

        $data = $this->jsonBody($this->handle(Request::create(
            '/_shop/blocks/featured-product?entry_uuid=' . $entryUuid,
            'GET',
        )));

        self::assertNotNull($data['product']);
        self::assertSame('Featured linked', $data['product']['name']);
    }

    public function testAddToCartSimpleProductIsDirectMode(): void
    {
        $this->seedSimpleProduct('atc-direct', 'Direct product');

        $data = $this->getBlockJson('/_shop/blocks/add-to-cart?product_slug=atc-direct');

        self::assertTrue($data['available']);
        self::assertSame('direct', $data['mode']);
        self::assertNotNull($data['variant_uuid']);
        self::assertSame([], $data['variants']);
    }

    public function testAddToCartMultiVariantProductIsSelectModeWithRequiredControls(): void
    {
        $this->seedMultiVariantProduct('atc-select', 'Select product');

        $data = $this->getBlockJson('/_shop/blocks/add-to-cart?product_slug=atc-select');

        self::assertTrue($data['available']);
        self::assertSame('select', $data['mode']);
        self::assertNull($data['variant_uuid']);
        self::assertCount(2, $data['variants']);
    }

    public function testAddToCartRequiredAddonForcesLinkModeNeverAnInvalidLine(): void
    {
        $productUuid = $this->seedSimpleProduct('atc-addon', 'Addon product');
        $this->seedRequiredAddon($productUuid);

        $data = $this->getBlockJson('/_shop/blocks/add-to-cart?product_slug=atc-addon');

        self::assertTrue($data['available']);
        self::assertSame('link', $data['mode']);
        self::assertNull($data['variant_uuid']);
        self::assertSame([], $data['variants']);
        self::assertNotNull($data['product_url']);
    }

    public function testAddToCartUnresolvableProductIsUnavailable(): void
    {
        $data = $this->getBlockJson('/_shop/blocks/add-to-cart?product_slug=no-such-product');

        self::assertFalse($data['available']);
        self::assertSame('unavailable', $data['mode']);
    }

    // ==================================================================
    // F. Block template rendering (with/without enrichment)
    // ==================================================================

    private function renderBlock(string $type, array $data, ?array $entry = null): string
    {
        $env = $this->container()->get(TwigFactory::class)->environment();
        /** @var RenderContextExtension $extension */
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setBlockAnnotations(false);
        $extension->setLocale('en');

        return $extension->blocks($env, ['entry' => $entry, 'site' => []], [
            ['id' => 'test-block', 'type' => $type, 'data' => $data],
        ]);
    }

    public function testMiniCartTemplateRendersStableShellAndPlainCartLink(): void
    {
        $html = $this->renderBlock('mini-cart', []);

        self::assertStringContainsString('data-shop-mini-cart', $html);
        self::assertStringContainsString('data-shop-cart-count', $html);
        self::assertStringContainsString('href="/cart"', $html);
        self::assertStringContainsString('/_shop/assets/shop.js', $html);
    }

    public function testProductGridTemplateReflectsConfigurationInDataAttributes(): void
    {
        $html = $this->renderBlock('product-grid', [
            'source' => 'category',
            'category_slug' => 'shoes',
            'tag_slug' => '',
            'products' => '',
            'page_size' => 'large',
        ]);

        self::assertStringContainsString('data-source="category"', $html);
        self::assertStringContainsString('data-category-slug="shoes"', $html);
        self::assertStringContainsString('data-page-size="48"', $html);
    }

    public function testProductGridTemplateFallsBackToNewestForAnUnknownSource(): void
    {
        $html = $this->renderBlock('product-grid', ['source' => 'bogus']);
        self::assertStringContainsString('data-source="newest"', $html);
    }

    public function testFeaturedProductTemplateCarriesTheEntryUuidOnlyWhenEnrichmentIsPresent(): void
    {
        $withoutEnrichment = $this->renderBlock('featured-product', ['product_slug' => 'widget']);
        self::assertStringContainsString('data-product-slug="widget"', $withoutEnrichment);
        self::assertStringContainsString('data-entry-uuid=""', $withoutEnrichment);

        $withEnrichment = $this->renderBlock('featured-product', [], ['uuid' => 'entryuuid001']);
        self::assertStringContainsString('data-entry-uuid="entryuuid001"', $withEnrichment);
    }

    public function testAddToCartTemplateNeverExposesASubmittableFormBeforeHydration(): void
    {
        foreach ([[], ['product_slug' => 'widget']] as $data) {
            $html = $this->renderBlock('add-to-cart', $data);
            self::assertMatchesRegularExpression(
                '/<form[^>]*data-shop-add-to-cart-form[^>]*\bhidden\b/',
                $html,
                'the add-to-cart form must always be hidden pre-hydration — never an invalid line',
            );
        }
    }

    public function testAddToCartTemplateFallsBackToTheEnrichedProductWhenBlank(): void
    {
        $html = $this->renderBlock('add-to-cart', [], ['uuid' => 'entryuuid002']);
        self::assertStringContainsString('data-product-slug=""', $html);
        self::assertStringContainsString('data-entry-uuid="entryuuid002"', $html);
    }

    // ==================================================================
    // F2. wishlist-link block (storefront-v1 Task 8, spec §5)
    // ==================================================================

    public function testWishlistLinkTemplateRendersAPlainLinkWithAHiddenCountBadge(): void
    {
        $url = $this->container()->get(StorefrontWishlistResolver::class)->wishlistUrl();
        self::assertNotNull($url, 'precondition: the wishlist seam must answer a URL in this suite');

        $html = $this->renderBlock('wishlist-link', []);

        // A plain <a> to the GENERATOR-owned wishlist URL — never a hand-built path, never a
        // disclosure widget: no-JS gets an ordinary working link.
        self::assertStringContainsString('href="' . $url . '"', $html);
        self::assertStringNotContainsString('aria-expanded', $html);
        // The badge ships hidden (a zero count is noise) — shop.js reveals it at n > 0.
        self::assertMatchesRegularExpression(
            '/<span[^>]*data-shop-wishlist-count[^>]*\bhidden\b/',
            $html,
            'the wishlist count badge must ship hidden, exactly like the cart badge',
        );
        self::assertStringContainsString('/_shop/assets/shop.js', $html);
    }

    public function testWishlistLinkTemplateRendersTheConfiguredLabel(): void
    {
        $html = $this->renderBlock('wishlist-link', ['label' => 'Saved items']);
        self::assertStringContainsString('Saved items', $html);
    }

    public function testEveryShopBlockRootCarriesTheWishlistScope(): void
    {
        // Spec §5 root emission: hydrated hearts inside a BUILDER-page block find the storage
        // scope from the nearest root — no metadata fetch, no per-block seam call in shop.js.
        $scope = $this->container()->get(StorefrontWishlistResolver::class)->storageScope();
        self::assertNotNull($scope, 'precondition: the wishlist seam must answer a scope in this suite');

        foreach (
            [
                'mini-cart' => [],
                'product-grid' => ['source' => 'newest'],
                'featured-product' => ['product_slug' => 'widget'],
                'add-to-cart' => ['product_slug' => 'widget'],
                'wishlist-link' => [],
            ] as $type => $data
        ) {
            self::assertStringContainsString(
                'data-shop-scope="' . $scope . '"',
                $this->renderBlock($type, $data),
                "the {$type} block root must carry the wishlist storage scope",
            );
        }
    }

    // ==================================================================
    // G. buildProductCard() ↔ _product_card.twig structural parity
    // ==================================================================

    public function testBuildProductCardMatchesTheServerCardsClassDataAndAriaHooks(): void
    {
        $direct = $this->seedSimpleProduct('parity-direct', 'Parity direct');
        $categoryUuid = $this->seedCategory('parity-category');
        $this->attachCategory($direct, $categoryUuid);
        $options = $this->seedMultiVariantProduct('parity-options', 'Parity options');

        // The SAME card projection both renderers consume: the endpoint's JSON is exactly
        // ProductCardViewModel::toArray(), which `_product_card.twig` also renders from.
        $json = $this->getBlockJson(
            '/_shop/wishlist/items?uuids[]=' . $direct . '&uuids[]=' . $options,
        );
        self::assertCount(2, $json['items'], 'precondition: both fixtures resolve as cards');
        self::assertSame('direct', $json['items'][0]['cart_mode']);
        self::assertSame('options', $json['items'][1]['cart_mode']);

        $serverHtml = (string) $this->handle(Request::create($this->urls()->shopIndex(), 'GET'))->getContent();
        self::assertStringContainsString('shop-grid__item', $serverHtml, 'precondition: the server grid rendered');

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js buildProductCard()');
        }

        $hooks = $this->builtCardHooks($node, $json['items']);

        // The pinned hook set (spec §5): exact markup equality is NOT the contract — the
        // class/data/ARIA hooks are, so a redesign of either renderer cannot silently drift.
        foreach (
            [
                'class:shop-grid__item', 'class:shop-grid__tile', 'class:shop-grid__media',
                'class:shop-grid__image', 'class:shop-grid__image--empty', 'class:shop-grid__tag',
                'class:shop-grid__actions', 'class:shop-grid__action', 'class:shop-grid__cart-form',
                'class:shop-grid__action--cart', 'class:shop-grid__action--options',
                'class:shop-grid__action--wishlist', 'class:shop-grid__body', 'class:shop-grid__name',
                'class:shop-grid__price', 'class:shop-grid__price-current',
                'attr:data-shop-wishlist-toggle', 'attr:data-product-uuid', 'attr:aria-pressed',
                'attr:aria-label', 'attr:hidden', 'attr:href',
            ] as $hook
        ) {
            self::assertContains($hook, $hooks, "buildProductCard() must emit the {$hook} hook");
        }

        // …and every hook the client builds must exist in the server-rendered card too.
        foreach ($hooks as $hook) {
            self::assertStringContainsString(
                substr($hook, (int) strpos($hook, ':') + 1),
                $serverHtml,
                "hook '{$hook}' is client-only — the two card renderers have drifted",
            );
        }
    }

    private function findNode(): ?string
    {
        $env = getenv('THALLO_NODE_BIN');
        if (is_string($env) && $env !== '' && is_executable($env)) {
            return $env;
        }
        $which = trim((string) shell_exec('command -v node 2>/dev/null'));

        return $which !== '' ? $which : null;
    }

    /**
     * Runs shop.js's OWN `buildProductCard()` (the served bytes — there is no build step)
     * against the given card JSON under node, and returns the structural hooks of the elements
     * it builds: `class:<token>`, `attr:<name>` (including a reflected `hidden`).
     *
     * @param list<array<string,mixed>> $cards
     * @return list<string>
     */
    private function builtCardHooks(string $node, array $cards): array
    {
        $src = json_encode(
            (string) file_get_contents($this->assetsDir() . '/shop.js'),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $data = json_encode($cards, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $harness = <<<JS
        'use strict';

        function Element(tagName) {
          this.tagName = tagName;
          this.attrs = {};
          this.children = [];
          this.parentNode = null;
          this._text = '';
          this.className = '';
          this.hidden = false;
        }
        Object.defineProperty(Element.prototype, 'textContent', {
          get: function () { return this._text; },
          set: function (v) { this._text = String(v); this.children = []; },
        });
        Element.prototype.getAttribute = function (n) {
          return Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null;
        };
        Element.prototype.setAttribute = function (n, v) { this.attrs[n] = String(v); };
        Element.prototype.appendChild = function (c) { this.children.push(c); c.parentNode = this; return c; };
        Element.prototype.addEventListener = function () {};

        var doc = {
          readyState: 'loading', // nothing self-drives: only buildProductCard() is exercised
          documentElement: new Element('html'),
          body: new Element('body'),
          createElement: function (tag) { return new Element(tag); },
          createElementNS: function (ns, tag) { return new Element(tag); },
          addEventListener: function () {},
          querySelector: function () { return null; },
          querySelectorAll: function () { return []; },
          getElementById: function () { return null; },
        };
        var win = { document: doc, location: { href: '' } };
        new Function('window', 'document', $src)(win, doc);

        if (!win.thalloShop || typeof win.thalloShop.buildProductCard !== 'function') {
          console.error('FAIL: shop.js exposes no buildProductCard()');
          process.exit(1);
        }

        var hooks = [];
        function walk(node) {
          String(node.className || '').split(/\s+/).forEach(function (token) {
            if (token) { hooks.push('class:' + token); }
          });
          Object.keys(node.attrs).forEach(function (name) { hooks.push('attr:' + name); });
          if (node.hidden === true) { hooks.push('attr:hidden'); }
          node.children.forEach(walk);
        }
        $data.forEach(function (card) { walk(win.thalloShop.buildProductCard(card)); });
        console.log(JSON.stringify(hooks));
        JS;

        $file = sys_get_temp_dir() . '/thallo_shop_card_parity_' . getmypid() . '.mjs';
        file_put_contents($file, $harness);
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            $output = implode("\n", $out);
            self::assertSame(0, $code, "card-parity harness failed:\n" . $output);
            $hooks = json_decode(trim($output), true);
            self::assertIsArray($hooks, "card-parity harness printed no hook list:\n" . $output);

            /** @var list<string> $unique */
            $unique = array_values(array_unique(array_map(static fn ($h): string => (string) $h, $hooks)));

            return $unique;
        } finally {
            @unlink($file);
        }
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private static function findBySlug(array $definitions, string $slug): object
    {
        foreach ($definitions as $definition) {
            if ($definition->slug === $slug) {
                return $definition;
            }
        }
        self::fail("no contributed definition with slug '{$slug}'");
    }

    private function urls(): ShopUrlGenerator
    {
        return $this->container()->get(ShopUrlGenerator::class);
    }

    /** @return array<string,mixed> */
    private function jsonBody(Response $response): array
    {
        self::assertContains($response->getStatusCode(), [200, 422]);
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string,mixed> */
    private function getBlockJson(string $path): array
    {
        return $this->jsonBody($this->handle(Request::create($path, 'GET')));
    }

    private function seedSimpleProduct(string $slug, string $name): string
    {
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug . '-' . (++self::$seq),
            'name' => $name,
            'status' => 'active',
            'type' => 'digital',
            'variants' => [[
                'sku' => 'sku-' . self::$seq,
                'price' => 1000,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);
        // Use the REAL slug (no seq suffix confusion for callers) by renaming to the caller's
        // literal slug via the DB — createProduct() requires a unique slug across the whole
        // suite run, hence the seq suffix; tests need the exact literal slug for readability.
        $this->connection()->table('commerce_products')
            ->where('uuid', '=', (string) $product['uuid'])
            ->update(['slug' => $slug]);

        return (string) $product['uuid'];
    }

    private function seedMultiVariantProduct(string $slug, string $name): string
    {
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug . '-' . (++self::$seq),
            'name' => $name,
            'status' => 'active',
            'type' => 'digital',
            'variants' => [
                ['sku' => 'sku-a-' . self::$seq, 'price' => 1000, 'currency' => 'USD', 'option_values' => []],
                ['sku' => 'sku-b-' . self::$seq, 'price' => 2000, 'currency' => 'USD', 'option_values' => []],
            ],
        ]);
        $this->connection()->table('commerce_products')
            ->where('uuid', '=', (string) $product['uuid'])
            ->update(['slug' => $slug]);

        return (string) $product['uuid'];
    }

    private function seedCategory(string $slug): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_categories')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT_A,
            'slug' => $slug,
            'name' => ucfirst($slug),
        ]);

        return $uuid;
    }

    private function seedTag(string $slug): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_tags')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT_A,
            'slug' => $slug,
            'name' => ucfirst($slug),
        ]);

        return $uuid;
    }

    private function attachCategory(string $productUuid, string $categoryUuid): void
    {
        $this->connection()->table('commerce_product_categories')->insert([
            'product_uuid' => $productUuid,
            'category_uuid' => $categoryUuid,
        ]);
    }

    private function attachTag(string $productUuid, string $tagUuid): void
    {
        $this->connection()->table('commerce_product_tags')->insert([
            'product_uuid' => $productUuid,
            'tag_uuid' => $tagUuid,
        ]);
    }

    private function seedRequiredAddon(string $productUuid): void
    {
        $this->connection()->table('commerce_product_addons')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => self::TENANT_A,
            'product_uuid' => $productUuid,
            'name' => 'Gift wrap',
            'field_type' => 'checkbox',
            'required' => true,
            'price_delta' => 0,
            'position' => 0,
            'status' => 'active',
        ]);
    }

    /** Raw-seeds a minimal content type + entry linked to the product (T11 Slice-1 idiom). */
    private function seedLinkedEntry(string $productUuid): string
    {
        self::$seq++;
        $entryUuid = 'blkent' . str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT);
        $typeUuid = $this->seedContentType();
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('entries')->insert([
            'uuid' => $entryUuid,
            'content_type_uuid' => $typeUuid,
            'status' => 'active',
            'tenant_uuid' => self::TENANT_A,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->container()
            ->get(\Thallo\Commerce\Links\ProductLinkService::class)
            ->link($this->appContext(), $productUuid, $entryUuid);

        return $entryUuid;
    }

    /** AppTestCase truncates `content_types` before every test — seed a minimal one. */
    private function seedContentType(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('content_types')->insert([
            'uuid' => $uuid,
            'slug' => 'blktype' . (++self::$seq),
            'name' => 'Block test type',
            'schema' => json_encode([]),
        ]);

        return $uuid;
    }
}
