<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Http\Shop\CartCookie;
use Thallo\Commerce\Http\Shop\GuestOrderCookie;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Commerce-Slice-2 Task 12 (storefront-rendering spec §11 "distributed" + this task's own
 * brief): the FULL storefront walk. One comprehensive narrative under mode (b) (widened schema +
 * a persisted default tenant, the convention every other suite in this directory already uses):
 * seed a catalog product + a linked enrichment entry, browse the three cached catalog routes
 * twice each (second read is a cache HIT), mutate price/stock/media/taxonomy/add-on through
 * their real Commerce service surfaces (stock via BOTH {@see InventoryService::adjust()} and a
 * real checkout placement), proving after EVERY mutation that the shop cache was purged (the
 * NEXT catalog request is fresh), then closes with the real customer path: `putLine` cart add via
 * `/_shop/cart/add`, `/cart`, a manual-collector `/checkout` placement, and
 * `/checkout/confirmation` showing `pending_payment`.
 *
 * Two lighter companion tests close the "across the three tenancy modes" requirement on the
 * product-detail + cart + checkout hot paths: mode (a) sentinel (unconditional) and mode (c)
 * enforcement (opt-in `THALLO_TENANCY_DEV_LINK=1`, self-skips otherwise — mirrors
 * `TenantResolutionModesTest::testEndToEndCatalogReaderReadLandsInModeCEnforcedTenant`'s
 * established per-test gate rather than the heavier `RetrofitHarnessTestCase` two-boot dance,
 * since this walk needs only an already-enforced tenant to resolve requests against, not a live
 * retrofit). Mode (b) itself is fully covered by the comprehensive walk above, so it is not
 * duplicated here.
 */
final class StorefrontWalkTest extends AppTestCase
{
    private const TENANT_B = 'swalktentb01'; // exactly 12 chars — tenant_uuid columns are varchar(12)
    private const TENANT_A_POISON = 'swalktenap01';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateCommerceState();
    }

    protected function tearDown(): void
    {
        $this->truncateCommerceState();
        $this->cache()->deletePattern('shop:*');
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function truncateCommerceState(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach (
            [
                'thallo_commerce_checkout_attempts',
                'commerce_order_events',
                'commerce_order_lines',
                'commerce_orders',
                'commerce_sequences',
                'commerce_cart_lines',
                'commerce_carts',
                'commerce_discount_redemptions',
                'commerce_discounts',
                'commerce_product_addons',
                'commerce_product_media',
                'commerce_product_categories',
                'commerce_categories',
                'commerce_stock_movements',
                'commerce_stock',
                'commerce_variants',
                'commerce_products',
                'commerce_marketplace_settings',
            ] as $table
        ) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    // ==================================================================
    // The full walk (mode b: widened schema + persisted default tenant)
    // ==================================================================

    public function testFullStorefrontWalkBrowseMutateFreshCartAndCheckoutUnderModeBWidenedDefault(): void
    {
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_B);

        // A poisoned second tenant so cache-purge assertions can never pass by accident (mirrors
        // ShopCacheTest's primeBothTenantsShopIndexCache convention). Requests resolve their
        // tenant from the ambient SystemFlags default, so this poison priming temporarily flips
        // the default tenant, primes the cache, then restores it before the walk itself begins.
        $this->seedProduct(self::TENANT_A_POISON, 'poison-product', 1500, 'digital');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A_POISON);
        $poisonPrime = $this->handle(Request::create('/shop', 'GET'));
        self::assertSame(200, $poisonPrime->getStatusCode());
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_B);

        // -------------------------------------------------------------------------------
        // 1. Seed a catalog product + a linked enrichment entry.
        // -------------------------------------------------------------------------------
        $product = $this->seedProduct(self::TENANT_B, 'walk-product', 1999);
        $productUuid = $product['uuid'];
        $variantUuid = $product['variantUuid'];

        $primaryCategory = $this->seedCategory(self::TENANT_B, 'walk-category-primary', 'Walk Category Primary');
        $this->container()->get(CategoryService::class)
            ->setProductCategories($this->appContext(), $productUuid, [$primaryCategory]);

        $typeUuid = $this->createEnrichmentType();
        $entryUuid = $this->seedEnrichmentEntry($typeUuid, 'walk-product-entry', 'WALK-ENRICHMENT-MARKER');
        $this->container()->get(ProductLinkService::class)->link($this->appContext(), $productUuid, $entryUuid);

        // -------------------------------------------------------------------------------
        // 2. Browse index / category / product — SECOND read of each is a cache HIT.
        // -------------------------------------------------------------------------------
        $this->assertSecondReadIsACacheHit('/shop');
        $this->assertSecondReadIsACacheHit('/shop/categories/walk-category-primary');
        $productHtmlFirst = $this->assertSecondReadIsACacheHit('/shop/products/walk-product');
        self::assertStringContainsString('19.99', $productHtmlFirst);
        self::assertStringContainsString('WALK-ENRICHMENT-MARKER', $productHtmlFirst);

        // -------------------------------------------------------------------------------
        // 3. Mutate price, stock (InventoryService::adjust), media, taxonomy, add-on — each
        //    followed by proof that the shop cache was purged (the NEXT request is fresh).
        // -------------------------------------------------------------------------------

        // -- price --
        $this->container()->get(CatalogService::class)
            ->setVariantPrice($this->appContext(), $variantUuid, 2500);
        $this->assertProductDetailCacheWasPurged('walk-product');
        $afterPrice = $this->getProductDetail('walk-product');
        self::assertStringContainsString('25.00', $afterPrice);
        self::assertStringNotContainsString('19.99', $afterPrice);

        // -- stock, via InventoryService::adjust --
        $this->container()->get(InventoryService::class)
            ->adjust($this->appContext(), $variantUuid, 50, 'walk-restock');
        $this->assertProductDetailCacheWasPurged('walk-product');
        self::assertSame(200, $this->getProductDetailResponse('walk-product')->getStatusCode());

        // -- media --
        $blobUuid = $this->seedBlob();
        $this->container()->get(ProductMediaService::class)->attach($this->appContext(), $productUuid, [
            'blob_uuid' => $blobUuid,
            'role' => 'cover',
        ]);
        $this->assertProductDetailCacheWasPurged('walk-product');
        $afterMedia = $this->getProductDetail('walk-product');
        self::assertStringContainsString('/blobs/' . $blobUuid, $afterMedia);

        // -- taxonomy assignment --
        $secondaryCategory = $this->seedCategory(self::TENANT_B, 'walk-category-secondary', 'Walk Category Secondary');
        $this->container()->get(CategoryService::class)
            ->setProductCategories($this->appContext(), $productUuid, [$primaryCategory, $secondaryCategory]);
        $this->assertProductDetailCacheWasPurged('walk-product');
        $this->getProductDetail('walk-product'); // re-prime
        $secondaryArchive = (string) $this->handleAsTenant(
            self::TENANT_B,
            Request::create('/shop/categories/walk-category-secondary', 'GET'),
        )->getContent();
        self::assertStringContainsString('walk-product', $secondaryArchive);

        // -- add-on --
        $this->container()->get(AddonService::class)->create($this->appContext(), $productUuid, [
            'name' => 'Gift wrap',
            'field_type' => 'checkbox',
            'price_delta' => 500,
        ]);
        $this->assertProductDetailCacheWasPurged('walk-product');
        $this->getProductDetail('walk-product'); // re-prime before the cart/checkout leg

        // -------------------------------------------------------------------------------
        // 4. Cart add (putLine) -> /cart -> checkout place (manual collector) -> confirmation.
        // -------------------------------------------------------------------------------
        $add = $this->handleAsTenant(self::TENANT_B, Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variantUuid, 'quantity' => 1],
            [],
            [],
            $this->jsonServer(self::TENANT_B),
        ));
        self::assertSame(200, $add->getStatusCode(), (string) $add->getContent());
        $cartToken = $this->cookieValueFrom($add, CartCookie::NAME);
        self::assertNotNull($cartToken, 'putLine add must mint a cart cookie');

        $cartPage = $this->handleAsTenant(
            self::TENANT_B,
            Request::create('/cart', 'GET', [], [CartCookie::NAME => $cartToken]),
        );
        self::assertSame(200, $cartPage->getStatusCode());
        self::assertStringContainsString('Walk product', (string) $cartPage->getContent());

        $place = $this->handleAsTenant(self::TENANT_B, Request::create(
            '/_shop/checkout/place',
            'POST',
            [
                'idempotency_key' => 'walk-checkout-key-1',
                'email' => 'walk-checkout@example.test',
                'addresses' => ['shipping' => ['country' => 'US']],
            ],
            [CartCookie::NAME => $cartToken],
            [],
            $this->csrfServer(self::TENANT_B),
        ));
        self::assertSame(200, $place->getStatusCode(), (string) $place->getContent());
        self::assertStringContainsString('Payment pending', (string) $place->getContent());

        $order = $this->connection()->table('commerce_orders')
            ->where('email', '=', 'walk-checkout@example.test')->first();
        self::assertNotNull($order);
        self::assertSame('pending_payment', $order['status']);

        // Stock via checkout: the placement above decrements stock, which must ALSO purge the
        // shop catalog cache — the NEXT catalog request is fresh yet again.
        $this->assertProductDetailCacheWasPurged('walk-product');
        self::assertSame(200, $this->getProductDetailResponse('walk-product')->getStatusCode());

        $guestCookie = $this->cookieValueFrom($place, GuestOrderCookie::NAME);
        self::assertNotNull($guestCookie);

        $confirm = $this->handleAsTenant(self::TENANT_B, Request::create(
            '/checkout/confirmation/' . $order['order_number'],
            'GET',
            [],
            [GuestOrderCookie::NAME => $guestCookie],
        ));
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('Payment pending', (string) $confirm->getContent());

        // The poisoned tenant's cache must never have been touched by any of the above.
        self::assertIsArray(
            $this->cache()->get('shop:' . self::TENANT_A_POISON . ':en:default:blue-slate:1:%2Fshop'),
            'a wholly separate tenant\'s cache must be untouched by this walk',
        );
    }

    // ==================================================================
    // Tenancy modes: product detail + cart + checkout hot paths
    // ==================================================================

    public function testProductDetailCartAndCheckoutHotPathsResolveUnderModeASentinel(): void
    {
        // Mode (a): no widened flag, no default tenant — the resolved tenant is the '' sentinel.
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        // 'digital' (untracked stock): this hot-path proof is about tenancy-mode resolution
        // across the three routes, not stock arithmetic — the full walk above already exercises
        // physical/tracked stock decrement via checkout in depth.
        $product = $this->seedProduct('', 'sentinel-walk-product', 1200, 'digital');

        $this->runHotPathWalk('', $product['variantUuid'], 'sentinel-walk-product', 'sentinel-hotpath-key');
    }

    public function testProductDetailCartAndCheckoutHotPathsResolveUnderModeCEnforcement(): void
    {
        if (!$this->container()->has(CurrentTenantResolver::class)) {
            self::markTestSkipped(
                'Enforcement provider not bound in this test env (default suite strips '
                . 'glueful/tenancy — see config/testing/extensions.php). Re-run with '
                . 'THALLO_TENANCY_DEV_LINK=1 to exercise the real request-resolved delegation path.'
            );
        }

        $tenantUuid = $this->seedRealTenant('walk-mode-c');
        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');

        // The product is seeded INSIDE runAsTenant() too: CatalogService resolves its tenant via
        // the SAME CommerceTenantResolution seam the storefront routes use, and mode (c) only
        // resolves correctly once the ambient TenantContext runAsTenant() establishes is active
        // (mirrors TenantResolutionModesTest's mode (c) end-to-end convention).
        $runner = $this->container()->get(TenantContextRunner::class);
        $runner->runAsTenant($tenantUuid, function () use ($tenantUuid): void {
            $product = $this->seedProduct($tenantUuid, 'enforced-walk-product', 1300, 'digital');
            $this->runHotPathWalk(
                $tenantUuid,
                $product['variantUuid'],
                'enforced-walk-product',
                'enforced-hotpath-key',
            );
        });

        $this->connection()->table('tenants')->where('uuid', '=', $tenantUuid)->forceDelete();
    }

    /** Shared product-detail -> cart-add -> checkout-place -> confirmation sequence. */
    private function runHotPathWalk(string $tenant, string $variantUuid, string $slug, string $idempotencyKey): void
    {
        $productResponse = $this->handleAsTenant($tenant, Request::create('/shop/products/' . $slug, 'GET'));
        self::assertSame(200, $productResponse->getStatusCode());

        $add = $this->handleAsTenant($tenant, Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variantUuid, 'quantity' => 1],
            [],
            [],
            $this->jsonServer($tenant),
        ));
        self::assertSame(200, $add->getStatusCode(), (string) $add->getContent());
        $cartToken = $this->cookieValueFrom($add, CartCookie::NAME);
        self::assertNotNull($cartToken);

        $cartPage = $this->handleAsTenant(
            $tenant,
            Request::create('/cart', 'GET', [], [CartCookie::NAME => $cartToken]),
        );
        self::assertSame(200, $cartPage->getStatusCode());

        $email = $idempotencyKey . '@example.test';
        $place = $this->handleAsTenant($tenant, Request::create(
            '/_shop/checkout/place',
            'POST',
            [
                'idempotency_key' => $idempotencyKey,
                'email' => $email,
                'addresses' => ['shipping' => ['country' => 'US']],
            ],
            [CartCookie::NAME => $cartToken],
            [],
            $this->csrfServer($tenant),
        ));
        self::assertSame(200, $place->getStatusCode(), (string) $place->getContent());
        self::assertStringContainsString('Payment pending', (string) $place->getContent());

        $order = $this->connection()->table('commerce_orders')->where('email', '=', $email)->first();
        self::assertNotNull($order);
        self::assertSame('pending_payment', $order['status']);
        self::assertSame($tenant, (string) $order['tenant_uuid']);

        $guestCookie = $this->cookieValueFrom($place, GuestOrderCookie::NAME);
        self::assertNotNull($guestCookie);

        $confirm = $this->handleAsTenant($tenant, Request::create(
            '/checkout/confirmation/' . $order['order_number'],
            'GET',
            [],
            [GuestOrderCookie::NAME => $guestCookie],
        ));
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('Payment pending', (string) $confirm->getContent());
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /**
     * Requests are driven directly through the kernel; the '' sentinel and mode (b)'s
     * flag-driven resolution both work with a plain `handle()` call — this indirection exists
     * only so the mode (c) test can reuse the exact same call sites from inside its
     * `runAsTenant()` closure (the ambient tenant context `Application::handle()` reads is
     * already established by the closure, so this is deliberately a passthrough there).
     */
    private function handleAsTenant(string $tenant, Request $request): Response
    {
        return $this->handle($request);
    }

    private function expectedOrigin(string $tenant): string
    {
        return $this->container()->get(CanonicalPublicOriginResolver::class)->currentOrigin($this->appContext());
    }

    /** @return array<string,string> */
    private function jsonServer(string $tenant): array
    {
        return ['HTTP_ORIGIN' => $this->expectedOrigin($tenant), 'HTTP_ACCEPT' => 'application/json'];
    }

    /** @return array<string,string> */
    private function csrfServer(string $tenant): array
    {
        return ['HTTP_ORIGIN' => $this->expectedOrigin($tenant)];
    }

    private function cookieValueFrom(Response $response, string $name): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return (string) $cookie->getValue();
            }
        }

        return null;
    }

    /**
     * Requests the given path twice; the SECOND response must be served straight from the shop
     * cache (mirrors ShopCacheTest's sentinel-swap technique). Returns the FIRST (real) response
     * body for content assertions.
     */
    private function assertSecondReadIsACacheHit(string $path): string
    {
        $first = $this->handleAsTenant(self::TENANT_B, Request::create($path, 'GET'));
        self::assertSame(200, $first->getStatusCode(), "priming request to {$path} must succeed");
        $firstBody = (string) $first->getContent();

        $key = $this->cacheKey(self::TENANT_B, $path);
        $entry = $this->cache()->get($key);
        self::assertIsArray($entry, "expected a cached entry for {$path} at key {$key}");
        $entry['body'] = 'SENTINEL-CACHE-HIT-' . $path;
        $this->cache()->set($key, $entry, 3600);

        $second = $this->handleAsTenant(self::TENANT_B, Request::create($path, 'GET'));
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(
            'SENTINEL-CACHE-HIT-' . $path,
            (string) $second->getContent(),
            "second read of {$path} must be served from the shop cache",
        );

        return $firstBody;
    }

    /** Primes, then purges via a caller-performed mutation, then asserts the key is gone. */
    private function assertProductDetailCacheWasPurged(string $slug): void
    {
        $key = $this->cacheKey(self::TENANT_B, '/shop/products/' . $slug);
        self::assertNull(
            $this->cache()->get($key),
            "expected the product-detail cache entry for {$slug} to have been purged by the mutation",
        );
    }

    private function getProductDetail(string $slug): string
    {
        return (string) $this->getProductDetailResponse($slug)->getContent();
    }

    private function getProductDetailResponse(string $slug): Response
    {
        return $this->handleAsTenant(self::TENANT_B, Request::create('/shop/products/' . $slug, 'GET'));
    }

    private function cacheKey(string $tenant, string $path): string
    {
        return 'shop:' . $tenant . ':en:default:blue-slate:1:' . rawurlencode($path);
    }

    /** @return array{uuid: string, variantUuid: string} */
    private function seedProduct(string $tenant, string $slug, int $priceCents, string $type = 'physical'): array
    {
        $previous = (string) ($this->flags()->get('tenancy.default_tenant_uuid') ?? $tenant);
        $this->flags()->put('tenancy.default_tenant_uuid', $tenant);

        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'status' => 'active',
            'type' => $type,
            'variants' => [[
                'sku' => 'walk-sku-' . $slug . '-' . (++self::$seq),
                'price' => $priceCents,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);

        $this->flags()->put('tenancy.default_tenant_uuid', $previous);

        return ['uuid' => (string) $product['uuid'], 'variantUuid' => (string) $product['variants'][0]['uuid']];
    }

    private function seedCategory(string $tenant, string $slug, string $name): string
    {
        self::$seq++;
        $uuid = 'swcat' . str_pad((string) self::$seq, 7, '0', STR_PAD_LEFT);
        (new CategoryRepository())->insert($this->appContext(), [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
            'position' => 0,
        ]);

        return $uuid;
    }

    /** Insert a blobs row directly (framework table; mirrors RenderPipelineTest::seedBlob()). */
    private function seedBlob(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'walk-cover.png',
            'mime_type' => 'image/png',
            'size' => 123,
            'url' => 'uploads/walk-cover.png',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    private function seedRealTenant(string $slugSuffix): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'swalk-' . $slugSuffix . '-' . substr($uuid, 0, 6),
            'name' => 'Storefront walk ' . $slugSuffix,
            'status' => 'active',
        ]);

        return $uuid;
    }

    /** Ad-hoc content type with a `body` blocks field — the enrichment entry's target type. */
    private function createEnrichmentType(): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'storefront_walk_enrichment_' . (++self::$seq),
            'name' => 'Storefront Walk Enrichment',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    private function seedEnrichmentEntry(string $typeUuid, string $routeSlug, string $markerText): string
    {
        $this->ensureHeadingBlockTypeSeeded();

        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', [
            'title' => 'Enrichment entry',
            'body' => [['id' => 'swblkmarker', 'type' => 'heading', 'data' => ['text' => $markerText]]],
        ], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $typeUuid, 'en', $routeSlug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');

        return $entry;
    }

    private function ensureHeadingBlockTypeSeeded(): void
    {
        $blockTypes = $this->container()->get(\App\Content\Blocks\BlockTypeRepository::class);
        if ($blockTypes->findBySlug('heading') !== null) {
            return;
        }
        $blockTypes->create([
            'slug' => 'heading',
            'label' => 'Heading',
            'schema' => [['name' => 'text', 'type' => 'string']],
        ]);
    }
}
