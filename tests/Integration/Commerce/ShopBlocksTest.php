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
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 11 (storefront-rendering spec §5.2/§10): the 4 shop block types + their templates +
 * fingerprinted asset serving. {@see \App\Tests\Integration\Commerce\ShopJsRuntimeTest} covers
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

    public function testContributorReturnsTheFourDefinitionsWithStableSourceIdsAndSlugs(): void
    {
        $definitions = (new ShopBlockTypesContributor())->blockTypeDefinitions();
        self::assertCount(4, $definitions);

        $bySlug = [];
        foreach ($definitions as $definition) {
            $bySlug[$definition->slug] = $definition;
            self::assertSame('thallo-commerce:' . $definition->slug, $definition->sourceId);
        }

        self::assertArrayHasKey('product-grid', $bySlug);
        self::assertArrayHasKey('featured-product', $bySlug);
        self::assertArrayHasKey('add-to-cart', $bySlug);
        self::assertArrayHasKey('mini-cart', $bySlug);
        self::assertSame('Commerce', $bySlug['product-grid']->category);
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
        $extension->resetBlockDepth();
        $extension->resetBlockFrames();
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
