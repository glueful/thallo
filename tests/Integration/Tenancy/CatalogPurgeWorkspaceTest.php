<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Tenancy;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnCatalogChange;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;
use Thallo\Render\Http\Middleware\RenderCachePurge;
use Thallo\Tenancy\Cache\TenantCacheSegment;

/**
 * Product grid spec §3.2: on a cache driver without tag invalidation, a catalog change drops the
 * rendered pages of the workspace the EVENT names — never the request's, which background or
 * cross-workspace work may not share.
 */
final class CatalogPurgeWorkspaceTest extends RetrofittedTenantTestCase
{
    /** @var list<string> */
    private array $patterns = [];

    private function listener(): PurgeShopCacheOnCatalogChange
    {
        $patterns = &$this->patterns;
        $store = $this->createMock(CacheStore::class);
        $store->method('invalidateTags')->willReturn(false);
        $store->method('deletePattern')->willReturnCallback(static function (string $p) use (&$patterns): bool {
            $patterns[] = $p;
            return true;
        });
        $purge = new RenderCachePurge($store, $this->container()->get(TenantCacheSegment::class));
        return new PurgeShopCacheOnCatalogChange($this->container()->with([
            CacheStore::class => static fn () => $store,
            RenderedPageCachePurge::class => static fn () => $purge,
        ]));
    }

    public function testAnEventForAWhileTheRequestIsInBPurgesAOnly(): void
    {
        $a = self::$tenantAUuid;
        $b = self::$tenantBUuid;
        $listener = $this->listener();
        $this->runAsTenant($b, static function () use ($listener, $a): void {
            $listener->onCatalogChanged(
                new StorefrontCatalogChanged($a, StorefrontCatalogChanged::REASON_TAG_CHANGED, null),
            );
        });
        self::assertContains('tenant:' . $a . ':render:*', $this->patterns);
        self::assertContains('shop:' . $a . ':*', $this->patterns);
        foreach ($this->patterns as $pattern) {
            self::assertStringNotContainsString($b, $pattern, 'nothing of the request\'s workspace');
        }
    }

    public function testAnEventWithNoRequestWorkspaceStillPurgesItsOwn(): void
    {
        $a = self::$tenantAUuid;
        // Outside runAsTenant(): no request workspace, as in a queue worker.
        $this->listener()->onCatalogChanged(
            new StorefrontCatalogChanged($a, StorefrontCatalogChanged::REASON_PRODUCT_UPDATED, null),
        );
        self::assertContains('tenant:' . $a . ':render:*', $this->patterns);
        self::assertNotContains('render:*', $this->patterns, 'never every workspace when one is named');
    }
}
