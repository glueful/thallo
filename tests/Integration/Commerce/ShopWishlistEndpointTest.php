<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\CountingPdoStatement;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\StorefrontWishlistResolver;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Storefront-v1 Task 7: `GET /_shop/wishlist/items` (the bounded, ordered wishlist resolution
 * endpoint) + `GET /{prefix}/wishlist` (the JS-hydrated wishlist page shell).
 *
 * Endpoint contract (spec §5): validation FIRST — a non-list `uuids` shape, more than 100 RAW
 * values, or ANY value failing the pinned `/\A[A-Za-z0-9]{12}\z/` product-uuid pattern is a 422
 * BEFORE any query runs (proven here with the ground-truth {@see CountingPdoStatement} delta);
 * duplicates dedupe by first occurrence; empty input answers `{"items":[]}` without querying;
 * servable items come back as EXACTLY {@see \Thallo\Commerce\Shop\ViewModels\ProductCardViewModel}
 * projections in REQUEST order (absent/unservable uuids simply omitted — the response IS the
 * client's reconciliation authority); always `private, no-store`.
 *
 * Tenant is driven via mode (b) (widened schema + persisted default tenant), mirroring
 * {@see ShopCatalogTest}'s identical convention in this directory.
 */
final class ShopWishlistEndpointTest extends AppTestCase
{
    private const TENANT_A = 'wshtesttenaa';
    private const TENANT_B = 'wshtesttenbb';

    /** The pinned card projection allowlist — key order included (storefront-v1 spec §3/§5). */
    private const CARD_ALLOWLIST = [
        'uuid',
        'name',
        'url',
        'cover_url',
        'rating',
        'price_formatted',
        'compare_at_formatted',
        'category_name',
        'cart_mode',
        'direct_variant_uuid',
    ];

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
        // The 422 no-query test installs the counting statement class on the SHARED suite
        // PDO — restore the default so no other test measures through it.
        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        $this->truncateCommerceCatalog();
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
    // validation: 422 BEFORE any query
    // ------------------------------------------------------------------

    public function testInvalidInputIsRejectedWithNoQueryAtAll(): void
    {
        // Ground-truth statement counting on the shared suite PDO (ShopCatalogTest's budget-test
        // convention): the counter is cumulative, so warm up first, SNAPSHOT, then assert DELTA.
        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);

        $servable = $this->seedProduct(self::TENANT_A, 'warmup-prod', 1000);

        // Warm-up traffic through the SAME route: one servable resolution (route dispatch,
        // tenancy-flag memos, settings memos) and one of each 422 shape so every lazily
        // initialized code path is already warm before anything is measured.
        self::assertSame(200, $this->handle($this->itemsRequest([$servable]))->getStatusCode());
        self::assertSame(422, $this->handle($this->itemsRequest(array_fill(0, 101, $servable)))->getStatusCode());
        self::assertSame(
            422,
            $this->handle(Request::create('/_shop/wishlist/items?uuids=abc', 'GET'))->getStatusCode(),
        );

        $matrix = [
            '101 raw values (before dedupe)' => $this->itemsRequest(array_fill(0, 101, $servable)),
            'malformed uuid among valid ones' => $this->itemsRequest([$servable, 'not-a-uuid!']),
            'too-short uuid' => $this->itemsRequest(['abc']),
            'non-list scalar shape (uuids=abc)' => Request::create('/_shop/wishlist/items?uuids=abc', 'GET'),
            'non-list map shape (uuids[k]=...)' => Request::create(
                '/_shop/wishlist/items?uuids[k]=' . $servable,
                'GET',
            ),
        ];

        foreach ($matrix as $label => $request) {
            $before = CountingPdoStatement::$count;
            $response = $this->handle($request);
            $delta = CountingPdoStatement::$count - $before;

            self::assertSame(422, $response->getStatusCode(), $label);
            self::assertSame(0, $delta, "{$label}: a 422 must run NO query at all (ran {$delta})");
            $this->assertPrivateNoStore($response, $label);
        }
    }

    public function testEmptyOrAbsentUuidsAnswersEmptyItemsWithoutQuerying(): void
    {
        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);

        // Warm-up (route dispatch + memoized flags/settings), then measure.
        self::assertSame(
            200,
            $this->handle(Request::create('/_shop/wishlist/items', 'GET'))->getStatusCode(),
        );

        $before = CountingPdoStatement::$count;
        $response = $this->handle(Request::create('/_shop/wishlist/items', 'GET'));
        $delta = CountingPdoStatement::$count - $before;

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['items' => []], $this->json($response));
        self::assertSame(0, $delta, "empty input must issue NO query (ran {$delta})");
        $this->assertPrivateNoStore($response, 'empty input');
    }

    // ------------------------------------------------------------------
    // resolution: request order, first-occurrence dedupe, omission
    // ------------------------------------------------------------------

    public function testItemsPreserveTheRequestOrderNeverDatabaseOrder(): void
    {
        $a = $this->seedProduct(self::TENANT_A, 'order-prod-a', 1100);
        $b = $this->seedProduct(self::TENANT_A, 'order-prod-b', 1200);
        $c = $this->seedProduct(self::TENANT_A, 'order-prod-c', 1300);

        $response = $this->handle($this->itemsRequest([$c, $a, $b]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [$c, $a, $b],
            array_column($this->json($response)['items'], 'uuid'),
            'items must come back in REQUEST order — not creation/database order',
        );
    }

    public function testDuplicateUuidsResolveOnceByFirstOccurrence(): void
    {
        $a = $this->seedProduct(self::TENANT_A, 'dupe-prod-a', 1100);
        $b = $this->seedProduct(self::TENANT_A, 'dupe-prod-b', 1200);

        $response = $this->handle($this->itemsRequest([$a, $a, $b]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$a, $b], array_column($this->json($response)['items'], 'uuid'));
    }

    public function testUnservableUuidsAreOmittedNotErrored(): void
    {
        $active = $this->seedProduct(self::TENANT_A, 'omit-active-prod', 1000);
        $draft = $this->seedProduct(self::TENANT_A, 'omit-draft-prod', 1000, status: 'draft');
        $deleted = $this->seedProduct(self::TENANT_A, 'omit-deleted-prod', 1000);
        $this->container()->get(CatalogService::class)->deleteProduct($this->appContext(), $deleted);
        $crossTenant = $this->seedProduct(self::TENANT_B, 'omit-cross-tenant-prod', 1000);
        $unknown = 'zzzzzzzzzzzz'; // valid shape, no row

        $response = $this->handle($this->itemsRequest([$draft, $active, $deleted, $crossTenant, $unknown]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [$active],
            array_column($this->json($response)['items'], 'uuid'),
            'inactive/tombstoned/cross-tenant/unknown uuids must be silently omitted',
        );
    }

    public function testSellerUnavailableUuidsAreOmittedUnderTheMarketplaceMaster(): void
    {
        $sellerless = $this->seedProduct(self::TENANT_A, 'seller-ok-prod', 1000);
        $orphaned = $this->seedProduct(self::TENANT_A, 'seller-gone-prod', 1000);
        // A seller_uuid with NO active commerce_sellers row — buyer-unavailable the moment the
        // marketplace install master is on (the same predicate every buyer surface applies).
        $this->connection()->table('commerce_products')
            ->where('uuid', '=', $orphaned)
            ->update(['seller_uuid' => 'nosellerhere']);

        $store = $this->container()->get(\App\Settings\SettingsStore::class);
        $store->putMany(['commerce.marketplace.enabled' => '1']);
        $store->clearCache();

        try {
            $response = $this->handle($this->itemsRequest([$orphaned, $sellerless]));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(
                [$sellerless],
                array_column($this->json($response)['items'], 'uuid'),
                'a product whose seller is not active must be omitted while the marketplace master is on',
            );
        } finally {
            $store->forget('commerce.marketplace.enabled');
            $store->clearCache();
        }
    }

    // ------------------------------------------------------------------
    // projection shape + headers
    // ------------------------------------------------------------------

    public function testEachItemCarriesExactlyTheCardAllowlist(): void
    {
        $uuid = $this->seedProduct(self::TENANT_A, 'shape-prod', 1999);
        $urls = $this->container()->get(ShopUrlGenerator::class);

        $response = $this->handle($this->itemsRequest([$uuid]));

        self::assertSame(200, $response->getStatusCode());
        $items = $this->json($response)['items'];
        self::assertCount(1, $items);
        self::assertSame(self::CARD_ALLOWLIST, array_keys($items[0]));
        self::assertSame($uuid, $items[0]['uuid']);
        self::assertSame($urls->product('shape-prod'), $items[0]['url']);
        self::assertSame('$19.99', $items[0]['price_formatted']);
        // Single active variant, no required add-on — AddToCartViewModel's direct decision.
        self::assertSame('direct', $items[0]['cart_mode']);
        self::assertNotNull($items[0]['direct_variant_uuid']);
        $this->assertPrivateNoStore($response, 'servable resolution');
    }

    // ------------------------------------------------------------------
    // the wishlist page shell
    // ------------------------------------------------------------------

    public function testWishlistPageRendersTheHydrationShellThroughTheShopPageCache(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('shop:*');
        $scope = $this->container()->get(StorefrontWishlistResolver::class)->storageScope();
        self::assertNotNull($scope, 'precondition: the wishlist seam must answer a scope in this suite');
        $urls = $this->container()->get(ShopUrlGenerator::class);

        $response = $this->handle(Request::create('/shop/wishlist', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        // The pinned hydration hooks (spec §5): root marker + opaque scope + busy-until-settled.
        self::assertStringContainsString('data-shop-wishlist-page', $html);
        self::assertStringContainsString('data-shop-scope="' . $scope . '"', $html);
        self::assertStringContainsString('aria-busy="true"', $html);
        self::assertStringContainsString('data-shop-wishlist-status', $html);
        // Empty state and grid both ship HIDDEN — never a false empty flash before the store
        // and reconciliation settle.
        self::assertStringContainsString('data-shop-wishlist-empty hidden', $html);
        self::assertStringContainsString('data-shop-wishlist-grid hidden', $html);
        // Honestly JS-dependent: the server cannot read browser storage.
        self::assertStringContainsString('<noscript>', $html);
        self::assertStringContainsString('JavaScript', $html);
        // Continue-shopping link through ShopUrlGenerator — never a hand-built path.
        self::assertStringContainsString('href="' . $urls->shopIndex() . '"', $html);

        // The page participates in ShopPageCache exactly as the catalog pages do: the cache
        // middleware is the ONLY thing that stamps the ETag + public revalidate pair
        // (Symfony normalizes directive order, so assert per directive).
        self::assertNotSame('', (string) $response->headers->get('ETag'), 'the shell must be shop-cached');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
        self::assertStringContainsString('must-revalidate', $cacheControl);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    /** @param list<string> $uuids repeated `uuids[]` query values, request order preserved */
    private function itemsRequest(array $uuids): Request
    {
        $pairs = array_map(static fn (string $uuid): string => 'uuids[]=' . rawurlencode($uuid), $uuids);
        $query = $pairs === [] ? '' : '?' . implode('&', $pairs);

        return Request::create('/_shop/wishlist/items' . $query, 'GET');
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function assertPrivateNoStore(Response $response, string $label): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl, $label);
        self::assertStringContainsString('no-store', $cacheControl, $label);
    }

    private function seedProduct(
        string $tenant,
        string $slug,
        int $priceCents,
        string $status = 'active',
    ): string {
        $previous = $this->flags()->get('tenancy.default_tenant_uuid') ?? self::TENANT_A;
        $this->flags()->put('tenancy.default_tenant_uuid', $tenant);

        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
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
}
