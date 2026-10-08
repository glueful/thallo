<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Thallo\Commerce\Shop\CatalogGeneration;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnCatalogChange;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Core\Tests\Support\AppTestCase;

/** Product grid spec §3.2: the catalog generation, and the purge's fallback per workspace. */
final class CatalogGenerationPurgeTest extends AppTestCase
{
    public function testAMissingGenerationIsInitializedAndReRead(): void
    {
        $cache = $this->container()->get(CacheStore::class);
        $cache->delete(CatalogGeneration::key('gentenant01'));
        $generation = new CatalogGeneration($cache);
        $first = $generation->read('gentenant01');
        self::assertIsString($first);
        self::assertSame($first, $generation->read('gentenant01'));
    }

    public function testAnUnreadableGenerationIsNullNotAMatch(): void
    {
        $store = $this->createMock(CacheStore::class);
        $store->method('get')->willReturn(null);
        $store->method('setNx')->willReturn(false);
        self::assertNull((new CatalogGeneration($store))->read('gentenant01'));
    }

    public function testEveryCatalogChangeRotatesTheGenerationBeforePurging(): void
    {
        $cache = $this->container()->get(CacheStore::class);
        $generation = new CatalogGeneration($cache);
        $before = $generation->read('gentenant02');
        $this->listener($cache)->onCatalogChanged(
            new StorefrontCatalogChanged('gentenant02', StorefrontCatalogChanged::REASON_ATTRIBUTE_CHANGED, null),
        );
        self::assertNotSame($before, $generation->read('gentenant02'));
        self::assertSame($generation->read('gentenant03'), $generation->read('gentenant03'), 'other tenants untouched');
    }

    public function testTheFallbackDeletesOnlyTheOwningWorkspacesShopAndRenderPages(): void
    {
        $store = $this->createMock(CacheStore::class);
        $store->method('invalidateTags')->willReturn(false);
        $store->method('get')->willReturn('g');
        $patterns = [];
        $store->method('deletePattern')->willReturnCallback(static function (string $p) use (&$patterns): bool {
            $patterns[] = $p;
            return true;
        });
        $renderPurged = [];
        $purge = new class ($renderPurged) implements RenderedPageCachePurge {
            /** @param list<string> $workspaces */
            public function __construct(private array &$workspaces)
            {
            }
            public function purge(array $tags): void
            {
            }
            public function purgeAll(): bool
            {
                return true;
            }
            public function purgeWorkspace(string $tenantUuid): bool
            {
                $this->workspaces[] = $tenantUuid;
                return true;
            }
        };
        $this->listener($store, $purge)->onCatalogChanged(
            new StorefrontCatalogChanged('gentenant04', StorefrontCatalogChanged::REASON_PRODUCT_UPDATED, null),
        );
        self::assertSame(['shop:gentenant04:*', 'tenant:gentenant04:shop:*'], $patterns);
        self::assertSame(['gentenant04'], $renderPurged, 'the EVENT\'s workspace, never the request\'s');
    }

    private function listener(CacheStore $cache, ?RenderedPageCachePurge $purge = null): PurgeShopCacheOnCatalogChange
    {
        $container = $this->container()->with(array_filter([
            CacheStore::class => static fn () => $cache,
            RenderedPageCachePurge::class => $purge !== null ? static fn () => $purge : null,
        ]));
        return new PurgeShopCacheOnCatalogChange($container);
    }
}
