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

    public function testTheCompiledTemplatesAreDroppedToo(): void
    {
        // Twig reuses a compiled template while the template file is not NEWER than it — and a
        // release archive stamps every file with its commit's time, which is in the past. So a
        // template compiled on the old install after that moment kept serving the old markup
        // (thallo.dev, 1.0.0-beta.56: code.twig and image.twig ignored their new fields). The
        // compiled directory goes, and every template recompiles from the new files.
        $dir = sys_get_temp_dir() . '/thallo-twig-' . bin2hex(random_bytes(4));
        mkdir($dir . '/default/ab', 0755, true);
        file_put_contents($dir . '/default/ab/stale.php', '<?php // compiled before the upgrade');

        $cleared = (new UpgradeCaches(
            $this->container()->get(RouteCache::class),
            $this->container()->get(CacheStore::class),
            $dir,
        ))->clear();

        self::assertFileDoesNotExist($dir . '/default/ab/stale.php');
        self::assertDirectoryExists($dir, 'the directory itself stays, for the next compile');
        self::assertSame(['route table', 'rendered pages', 'compiled templates'], $cleared);
        @rmdir($dir);
    }
}
