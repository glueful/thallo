<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Glueful\Cache\CacheStore;
use Glueful\Routing\RouteCache;
use Thallo\Core\Setup\UpgradeCaches;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * `composer update && php glueful thallo:provision` is the whole upgrade, so provision must drop
 * what outlives a release: the compiled route table (its signature does not cover the routes
 * shipped in vendor/, so a stale one keeps serving the previous release's routes) and the
 * rendered page cache.
 */
final class UpgradeCachesTest extends AppTestCase
{
    public function testTheCompiledRouteTableAndRenderedPagesAreDropped(): void
    {
        $routeCache = $this->container()->get(RouteCache::class);
        $file = $routeCache->getCacheFilePath();
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, "<?php\nreturn ['signature' => 'stale'];\n");
        $store = $this->container()->get(CacheStore::class);
        $store->set('render:probe', 'html', 300);

        $cleared = (new UpgradeCaches($routeCache, $store))->clear();

        self::assertFileDoesNotExist($file);
        self::assertNull($store->get('render:probe'));
        self::assertSame(['route table', 'rendered pages'], $cleared);
    }
}
