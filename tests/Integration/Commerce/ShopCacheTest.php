<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Services\PublishService;
use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Events\ProductSlugChanged;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Events\ProductLinkChanged;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Shop\ShopPageCache;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Contracts\Settings\ThemeChanged;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Commerce-Slice-2 Task 8: {@see ShopPageCache} — dimension-complete keying
 * `(tenant, resolvedLocale, activeTheme, appearanceFingerprint, path, page)` and its five purge
 * listeners (storefront-rendering spec §9). Drives the REAL kernel (shop routes +
 * `CommerceIntegrationServiceProvider`'s listener wiring) so the middleware, route ordering,
 * and event wiring are all under test — mirrors `RenderPageCacheTest`'s conventions in this
 * same suite (sentinel-body cache-hit proof, `getKeys()`/`deletePattern()` inspection).
 *
 * Tenant is mode (b) (widened schema + a persisted default tenant), mirroring
 * `ShopCatalogTest`/`SlugLifecycleRaceTest`'s identical convention in this same directory. The
 * cache store is process-shared — tearDown purges `shop:*` keys so later tests never serve
 * stale seeds.
 */
final class ShopCacheTest extends AppTestCase
{
    private const TENANT_A = 'shopcachtena';
    private const TENANT_B = 'shopcachtenb';

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
        $this->cache()->deletePattern('shop:*');
        $this->truncateCommerceCatalog();
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function truncateCommerceCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM commerce_stock_movements');
        $pdo->exec('DELETE FROM commerce_stock');
        $pdo->exec('DELETE FROM commerce_product_categories');
        $pdo->exec('DELETE FROM commerce_variants');
        $pdo->exec('DELETE FROM commerce_categories');
        $pdo->exec('DELETE FROM commerce_products');
    }

    // ==================================================================
    // key dimension-completeness
    // ==================================================================

    public function testKeyIsDimensionComplete(): void
    {
        $tenants = $this->container()->get(CommerceTenantResolution::class);
        $mw = new ShopPageCache($this->cache(), $tenants, 'default', 'blue-slate', true, 60, $this->appContext());
        $ref = new \ReflectionMethod($mw, 'key');
        $ref->setAccessible(true);

        $base = $ref->invoke($mw, 'tenantA', 'en', '/shop', 1);
        self::assertSame('shop:tenantA:en:default:blue-slate:1:%2Fshop', $base);

        self::assertNotSame($base, $ref->invoke($mw, 'tenantB', 'en', '/shop', 1), 'tenant must vary the key');
        self::assertNotSame($base, $ref->invoke($mw, 'tenantA', 'fr', '/shop', 1), 'locale must vary the key');
        self::assertNotSame($base, $ref->invoke($mw, 'tenantA', 'en', '/shop', 2), 'page must vary the key');
        self::assertNotSame($base, $ref->invoke($mw, 'tenantA', 'en', '/shop/x', 1), 'path must vary the key');

        $themed = new ShopPageCache(
            $this->cache(),
            $tenants,
            'other-theme',
            'blue-slate',
            true,
            60,
            $this->appContext(),
        );
        $refThemed = new \ReflectionMethod($themed, 'key');
        $refThemed->setAccessible(true);
        self::assertNotSame(
            $base,
            $refThemed->invoke($themed, 'tenantA', 'en', '/shop', 1),
            'theme must vary the key',
        );

        $skinned = new ShopPageCache($this->cache(), $tenants, 'default', 'rose-zinc', true, 60, $this->appContext());
        $refSkinned = new \ReflectionMethod($skinned, 'key');
        $refSkinned->setAccessible(true);
        self::assertNotSame(
            $base,
            $refSkinned->invoke($skinned, 'tenantA', 'en', '/shop', 1),
            'appearance must vary the key',
        );
    }

    // ==================================================================
    // page allowlist: 1..1000, foreign params bypass
    // ==================================================================

    public function testShopIndexIsCachedAndSecondRequestServesFromCache(): void
    {
        $this->seedProduct(self::TENANT_A, 'cache-hit-prod', 1999);

        $first = $this->handle(Request::create('/shop', 'GET'));
        self::assertSame(200, $first->getStatusCode());

        $key = 'shop:' . self::TENANT_A . ':en:default:blue-slate:1:%2Fshop';
        $entry = $this->cache()->get($key);
        self::assertIsArray($entry);
        $entry['body'] = 'SENTINEL-FROM-SHOP-CACHE';
        $this->cache()->set($key, $entry, 3600);

        $second = $this->handle(Request::create('/shop', 'GET'));
        self::assertSame(200, $second->getStatusCode());
        self::assertSame('SENTINEL-FROM-SHOP-CACHE', (string) $second->getContent());
    }

    public function testPageTwoIsCachedSeparatelyFromPageOne(): void
    {
        $this->seedProduct(self::TENANT_A, 'page-sep-prod', 1000);

        $this->handle(Request::create('/shop', 'GET'));
        $this->handle(Request::create('/shop?page=2', 'GET'));

        $keys = $this->cache()->getKeys('shop:*');
        self::assertCount(2, $keys);
        self::assertContains('shop:' . self::TENANT_A . ':en:default:blue-slate:1:%2Fshop', $keys);
        self::assertContains('shop:' . self::TENANT_A . ':en:default:blue-slate:2:%2Fshop', $keys);
    }

    /** @return iterable<string,array{0:string}> */
    public static function invalidPageProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'over max' => ['1001'];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['1.5'];
        yield 'non numeric' => ['abc'];
        yield 'empty' => [''];
    }

    /** @dataProvider invalidPageProvider */
    public function testInvalidOrOutOfRangePageReturns404WithNoCacheWrite(string $page): void
    {
        $response = $this->handle(Request::create('/shop?page=' . urlencode($page), 'GET'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->cache()->getKeys('shop:*'));
    }

    public function testForeignQueryParameterBypassesTheCacheEntirely(): void
    {
        $this->seedProduct(self::TENANT_A, 'bypass-prod', 1500);

        $this->handle(Request::create('/shop?utm_source=newsletter', 'GET'));

        self::assertSame([], $this->cache()->getKeys('shop:*'));
    }

    public function testForeignQueryParameterAlongsideAValidPageStillBypasses(): void
    {
        $this->seedProduct(self::TENANT_A, 'bypass-page-prod', 1500);

        $this->handle(Request::create('/shop?page=2&utm_source=newsletter', 'GET'));

        self::assertSame([], $this->cache()->getKeys('shop:*'));
    }

    public function testDisabledMiddlewareIsAPurePassthrough(): void
    {
        $tenants = $this->container()->get(CommerceTenantResolution::class);
        $middleware = new ShopPageCache(
            $this->cache(),
            $tenants,
            'default',
            'blue-slate',
            false,
            3600,
            $this->appContext(),
        );
        $downstream = new \Symfony\Component\HttpFoundation\Response(
            '<html>fresh</html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
        $res = $middleware->handle(Request::create('/shop', 'GET'), static fn () => $downstream);

        self::assertSame($downstream, $res);
        self::assertSame([], $this->cache()->getKeys('shop:*'));
    }

    // ==================================================================
    // global theme/appearance purge (tenantless events)
    // ==================================================================

    public function testThemeChangedPurgesTheGlobalTag(): void
    {
        $this->seedProduct(self::TENANT_A, 'theme-purge-prod', 1500);
        $this->handle(Request::create('/shop', 'GET'));
        self::assertCount(1, $this->cache()->getKeys('shop:*'));

        $this->events()->dispatch(new ThemeChanged('other-theme'));

        self::assertSame([], $this->cache()->getKeys('shop:*'));
    }

    public function testAppearanceChangedPurgesTheGlobalTag(): void
    {
        $this->seedProduct(self::TENANT_A, 'appearance-purge-prod', 1500);
        $this->handle(Request::create('/shop', 'GET'));
        self::assertCount(1, $this->cache()->getKeys('shop:*'));

        $this->events()->dispatch(new ThemeAppearanceChanged('emerald', 'zinc'));

        self::assertSame([], $this->cache()->getKeys('shop:*'));
    }

    // ==================================================================
    // tenant-scoped purges: every StorefrontCatalogChanged reason + slug + link
    // ==================================================================

    public function testAllElevenStorefrontCatalogChangeReasonsPurgeOnlyTheMutatingTenantsCache(): void
    {
        $reasons = [
            StorefrontCatalogChanged::REASON_PRODUCT_CREATED,
            StorefrontCatalogChanged::REASON_PRODUCT_UPDATED,
            StorefrontCatalogChanged::REASON_PRODUCT_STATUS_CHANGED,
            StorefrontCatalogChanged::REASON_PRODUCT_DELETED,
            StorefrontCatalogChanged::REASON_VARIANT_CHANGED,
            StorefrontCatalogChanged::REASON_STOCK_CHANGED,
            StorefrontCatalogChanged::REASON_MEDIA_CHANGED,
            StorefrontCatalogChanged::REASON_CATEGORY_CHANGED,
            StorefrontCatalogChanged::REASON_TAG_CHANGED,
            StorefrontCatalogChanged::REASON_ATTRIBUTE_CHANGED,
            StorefrontCatalogChanged::REASON_ADDON_CHANGED,
        ];
        self::assertCount(11, $reasons, 'the closed reason vocabulary must stay exactly 11');

        foreach ($reasons as $reason) {
            $this->primeBothTenantsShopIndexCache();

            $this->events()->dispatch(new StorefrontCatalogChanged(self::TENANT_A, $reason, null));

            self::assertNull(
                $this->cache()->get($this->shopIndexKey(self::TENANT_A)),
                "reason '{$reason}' must purge tenant A's cache",
            );
            self::assertIsArray(
                $this->cache()->get($this->shopIndexKey(self::TENANT_B)),
                "reason '{$reason}' must NOT purge tenant B's cache (poison check)",
            );

            $this->cache()->deletePattern('shop:*');
        }
    }

    public function testProductSlugChangedPurgesTheTenantCache(): void
    {
        $this->seedProduct(self::TENANT_A, 'slug-purge-old', 1000);
        $productUuid = $this->productUuidBySlug(self::TENANT_A, 'slug-purge-old');
        $this->primeBothTenantsShopIndexCache();

        $this->catalog()->updateProduct($this->appContext(), $productUuid, ['slug' => 'slug-purge-new']);

        self::assertNull($this->cache()->get($this->shopIndexKey(self::TENANT_A)));
        self::assertIsArray($this->cache()->get($this->shopIndexKey(self::TENANT_B)));
    }

    public function testProductLinkChangedPurgesTheTenantCache(): void
    {
        $this->seedProduct(self::TENANT_A, 'link-purge-prod', 1000);
        $productUuid = $this->productUuidBySlug(self::TENANT_A, 'link-purge-prod');
        $entryUuid = $this->seedRawEntry(self::TENANT_A);
        $this->primeBothTenantsShopIndexCache();

        $this->container()->get(ProductLinkService::class)->link($this->appContext(), $productUuid, $entryUuid);

        self::assertNull($this->cache()->get($this->shopIndexKey(self::TENANT_A)));
        self::assertIsArray($this->cache()->get($this->shopIndexKey(self::TENANT_B)));
    }

    // ==================================================================
    // Commerce-Slice-2 Fix B: the product-detail cache's entry-uuid tag fold — publishing or
    // changing a route-less linked entry purges the ALREADY-CACHED product-detail page, via the
    // SAME `thallo:entry:{uuid}` string App\Content\Pipeline\Listeners\InvalidateCacheTagsListener
    // already invalidates on EntryPublished/EntryUpdated/EntryDeleted. Zero new listener code —
    // ShopCatalogController stamps the Cache-Tag response header, ShopPageCache folds it into its
    // own tag set on write (mirrors RenderPageCache's identical fold for RenderController).
    // ==================================================================

    public function testPublishingTheLinkedEntryPurgesTheCachedProductDetailPage(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'entry-publish-purge-prod', 1000);
        $typeUuid = $this->createEnrichmentType();
        $entryUuid = $this->seedRouteLessEnrichmentEntry($typeUuid, 'en', 'INITIAL-CACHE-BLOCK');
        $this->container()->get(ProductLinkService::class)->link($this->appContext(), $productUuid, $entryUuid);

        $key = $this->productDetailKey(self::TENANT_A, 'entry-publish-purge-prod');
        $first = $this->handle(Request::create('/shop/products/entry-publish-purge-prod', 'GET'));
        self::assertSame(200, $first->getStatusCode());
        self::assertStringContainsString('INITIAL-CACHE-BLOCK', (string) $first->getContent());
        self::assertIsArray($this->cache()->get($key), 'precondition: the product-detail page is cached');

        // Real republish (a real EntryPublished event, not a synthetic dispatch) with EDITED
        // block content — proves both the purge AND that the next request re-renders fresh.
        $this->republishEnrichmentEntry($entryUuid, 'en', 'UPDATED-CACHE-BLOCK');

        self::assertNull($this->cache()->get($key), 'publishing the linked entry must purge the cached page');

        $second = $this->handle(Request::create('/shop/products/entry-publish-purge-prod', 'GET'));
        self::assertStringContainsString('UPDATED-CACHE-BLOCK', (string) $second->getContent());
        self::assertStringNotContainsString('INITIAL-CACHE-BLOCK', (string) $second->getContent());
    }

    /**
     * Belt-and-suspenders proof: deleting the linked entry purges the cached page too — via
     * TWO independent, overlapping mechanisms (either alone is sufficient, so this test does
     * not isolate one): this fix's `thallo:entry:{uuid}` tag fold (EntryDeleted is also
     * invalidated by InvalidateCacheTagsListener, exactly like EntryPublished/EntryUpdated),
     * AND the pre-existing `Thallo\Commerce\Listeners\EntryDeletedListener` cascade (an entry
     * delete auto-unlinks the product, dispatching `ProductLinkChanged`, which
     * `PurgeShopCacheOnLinkChange` already purges tenant-wide). Unlike
     * testPublishingTheLinkedEntryPurgesTheCachedProductDetailPage, this one still passes with
     * Fix B's tag fold alone reverted (the pre-existing cascade covers it) — it is a system-
     * level regression guard, not a targeted proof of the new mechanism.
     */
    public function testDeletingTheLinkedEntryPurgesTheCachedProductDetailPage(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'entry-delete-purge-prod', 1000);
        $typeUuid = $this->createEnrichmentType();
        $entryUuid = $this->seedRouteLessEnrichmentEntry($typeUuid, 'en', 'DOOMED-BLOCK');
        $this->container()->get(ProductLinkService::class)->link($this->appContext(), $productUuid, $entryUuid);

        $key = $this->productDetailKey(self::TENANT_A, 'entry-delete-purge-prod');
        $this->handle(Request::create('/shop/products/entry-delete-purge-prod', 'GET'));
        self::assertIsArray($this->cache()->get($key), 'precondition: the product-detail page is cached');

        $this->connection()->table('entries')->where('uuid', '=', $entryUuid)->update(['status' => 'deleted']);
        $this->events()->dispatch(new \App\Content\Events\EntryDeleted($entryUuid, $typeUuid));

        self::assertNull($this->cache()->get($key), 'deleting the linked entry must purge the cached page');
    }

    public function testUnrelatedEntryPublishNeverPurgesAProductDetailPageItIsNotTaggedWith(): void
    {
        $productUuid = $this->seedProduct(self::TENANT_A, 'entry-poison-prod', 1000);
        $typeUuid = $this->createEnrichmentType();
        $linkedEntryUuid = $this->seedRouteLessEnrichmentEntry($typeUuid, 'en', 'LINKED-BLOCK');
        $unrelatedEntryUuid = $this->seedRouteLessEnrichmentEntry($typeUuid, 'en', 'UNRELATED-BLOCK');
        $this->container()->get(ProductLinkService::class)->link($this->appContext(), $productUuid, $linkedEntryUuid);

        $key = $this->productDetailKey(self::TENANT_A, 'entry-poison-prod');
        $this->handle(Request::create('/shop/products/entry-poison-prod', 'GET'));
        self::assertIsArray($this->cache()->get($key), 'precondition: the product-detail page is cached');

        $this->republishEnrichmentEntry($unrelatedEntryUuid, 'en', 'UNRELATED-BLOCK-V2');

        self::assertIsArray(
            $this->cache()->get($key),
            'publishing an entry NOT linked to this product must never purge its cached page',
        );
    }

    // ------------------------------------------------------------------
    // real end-to-end wiring (not just a synthetic direct dispatch)
    // ------------------------------------------------------------------

    public function testRealProductUpdateThroughCatalogServicePurgesTheTenantCache(): void
    {
        $this->seedProduct(self::TENANT_A, 'real-update-prod', 1000);
        $productUuid = $this->productUuidBySlug(self::TENANT_A, 'real-update-prod');
        $this->primeBothTenantsShopIndexCache();

        $this->catalog()->updateProduct($this->appContext(), $productUuid, ['name' => 'Renamed']);

        self::assertNull($this->cache()->get($this->shopIndexKey(self::TENANT_A)));
        self::assertIsArray($this->cache()->get($this->shopIndexKey(self::TENANT_B)));
    }

    public function testRealCategoryCreateThroughCategoryServicePurgesTheTenantCache(): void
    {
        $this->primeBothTenantsShopIndexCache();

        $this->container()->get(CategoryService::class)->create($this->appContext(), [
            'slug' => 'real-cat-' . (++self::$seq),
            'name' => 'Real category',
        ]);

        self::assertNull($this->cache()->get($this->shopIndexKey(self::TENANT_A)));
        self::assertIsArray($this->cache()->get($this->shopIndexKey(self::TENANT_B)));
    }

    public function testRealStockAdjustmentThroughInventoryServicePurgesTheTenantCache(): void
    {
        $this->seedProduct(self::TENANT_A, 'real-stock-prod', 1000);
        $productUuid = $this->productUuidBySlug(self::TENANT_A, 'real-stock-prod');
        $variants = $this->container()->get(VariantRepository::class)
            ->forProduct($this->appContext(), self::TENANT_A, $productUuid);
        self::assertNotSame([], $variants);
        $variantUuid = (string) $variants[0]['uuid'];
        $this->primeBothTenantsShopIndexCache();

        $this->container()->get(InventoryService::class)->adjust($this->appContext(), $variantUuid, 5);

        self::assertNull($this->cache()->get($this->shopIndexKey(self::TENANT_A)));
        self::assertIsArray($this->cache()->get($this->shopIndexKey(self::TENANT_B)));
    }

    // ==================================================================
    // wiring: the middleware never wraps anything but the 3 catalog GET routes
    // ==================================================================

    public function testShopPageCacheIsWiredOnlyOnTheThreeCatalogRoutes(): void
    {
        foreach (
            [
                ['GET', '/shop'],
                ['GET', '/shop/products/{slug}'],
                ['GET', '/shop/categories/{slug}'],
            ] as [$method, $path]
        ) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} must be registered");
            self::assertContains(
                ShopPageCache::class,
                (array) ($route['middleware'] ?? []),
                "{$method} {$path} must carry ShopPageCache",
            );
        }

        // No admin route (a representative sample) ever carries the shop cache middleware.
        foreach ($this->router()->getAllRoutes() as $route) {
            if (str_starts_with((string) $route['path'], '/shop')) {
                continue;
            }
            self::assertNotContains(
                ShopPageCache::class,
                (array) ($route['middleware'] ?? []),
                "{$route['method']} {$route['path']} must never carry ShopPageCache",
            );
        }
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function events(): EventService
    {
        return $this->container()->get(EventService::class);
    }

    private function catalog(): CatalogService
    {
        return $this->container()->get(CatalogService::class);
    }

    private function shopIndexKey(string $tenant): string
    {
        return 'shop:' . $tenant . ':en:default:blue-slate:1:%2Fshop';
    }

    private function primeBothTenantsShopIndexCache(): void
    {
        $this->cache()->deletePattern('shop:*');
        $this->seedProduct(self::TENANT_A, 'poison-check-a-' . (++self::$seq), 1000);
        $this->seedProduct(self::TENANT_B, 'poison-check-b-' . (++self::$seq), 1000);

        $previous = (string) ($this->flags()->get('tenancy.default_tenant_uuid') ?? self::TENANT_A);

        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
        $this->handle(Request::create('/shop', 'GET'));
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_B);
        $this->handle(Request::create('/shop', 'GET'));

        $this->flags()->put('tenancy.default_tenant_uuid', $previous);

        self::assertIsArray($this->cache()->get($this->shopIndexKey(self::TENANT_A)), 'precondition: tenant A primed');
        self::assertIsArray($this->cache()->get($this->shopIndexKey(self::TENANT_B)), 'precondition: tenant B primed');
    }

    private function seedProduct(string $tenant, string $slug, int $priceCents): string
    {
        $previous = (string) ($this->flags()->get('tenancy.default_tenant_uuid') ?? self::TENANT_A);
        $this->flags()->put('tenancy.default_tenant_uuid', $tenant);

        $product = $this->catalog()->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'status' => 'active',
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

    private function productUuidBySlug(string $tenant, string $slug): string
    {
        $row = $this->connection()->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)->where('slug', '=', $slug)->first();
        self::assertNotNull($row);

        return (string) $row['uuid'];
    }

    /**
     * Raw-seeds `entries` directly (mirrors ShopCatalogTest's identical helper docblock): no
     * `tenant_uuid` column here (unlike ProductLinkRaceTest's transient ALTER) — this suite
     * never needs cross-tenant ENTRY isolation, only cross-tenant PRODUCT isolation.
     */
    private function seedRawEntry(string $tenant): string
    {
        self::$seq++;
        $uuid = 'shcache' . str_pad((string) self::$seq, 5, '0', STR_PAD_LEFT);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => 'shcachetype1',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /** shop:{tenant}:en:default:blue-slate:1:%2Fshop%2Fproducts%2F{slug} — mirrors shopIndexKey(). */
    private function productDetailKey(string $tenant, string $slug): string
    {
        return 'shop:' . $tenant . ':en:default:blue-slate:1:' . rawurlencode('/shop/products/' . $slug);
    }

    /** Ad-hoc content type with a `body` blocks field — mirrors ShopCatalogTest's identical helper. */
    private function createEnrichmentType(): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'shop_cache_enrichment_test_' . (++self::$seq),
            'name' => 'Shop Cache Enrichment Test',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    /**
     * A real published entry with ONE `heading` block, carrying NO route (Fix B: mirrors the
     * route-less starter "Product story" shape ShopCatalogTest's identical helper exercises).
     * Uses the CONTAINER-bound {@see EntryRepository}/{@see PublishService} (not hand-built
     * ones) — those carry a real event emitter, so publishing here dispatches a REAL
     * `EntryPublished` (the event this whole fix's purge mechanism depends on).
     */
    private function seedRouteLessEnrichmentEntry(string $typeUuid, string $locale, string $markerText): string
    {
        $this->ensureHeadingBlockTypeSeeded();
        $entries = $this->container()->get(EntryRepository::class);
        $entry = $entries->createEntry($typeUuid, $locale, 1, 'user00000001');
        $entries->saveDraft($entry, $locale, [
            'title' => 'Cache-fix entry',
            'body' => [['id' => 'shcacheblk', 'type' => 'heading', 'data' => ['text' => $markerText]]],
        ], 1, 0, 'user00000001');
        $this->container()->get(PublishService::class)->publish($entry, $locale, 'user00000001');

        return $entry;
    }

    /** Edits the draft with new block text and republishes — a real second EntryPublished. */
    private function republishEnrichmentEntry(string $entryUuid, string $locale, string $markerText): void
    {
        $entries = $this->container()->get(EntryRepository::class);
        $entries->saveDraft($entryUuid, $locale, [
            'title' => 'Cache-fix entry',
            'body' => [['id' => 'shcacheblk', 'type' => 'heading', 'data' => ['text' => $markerText]]],
        ], 1, 1, 'user00000001');
        $this->container()->get(PublishService::class)->publish($entryUuid, $locale, 'user00000001');
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
}
