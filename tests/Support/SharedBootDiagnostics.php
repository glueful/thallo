<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ServiceProvider;
use Glueful\Routing\Router;

/**
 * A one-screen description of the process-shared boot, for assertion messages about route
 * registration. Everything that decides which routes the shared router holds is process state
 * that a failing run cannot otherwise show: which providers booted (and whether they came from
 * the extension cache), which route files the framework's per-file latch has consumed, whether
 * the router was hydrated from a compiled route cache, and the cache files on disk at boot.
 * Attach it to a failure message; it costs nothing while the assertion passes.
 */
final class SharedBootDiagnostics
{
    public const CACHE_FILES = ['bootstrap/cache/extensions.php', 'storage/cache/routes_dev.php'];

    public static function describe(ApplicationContext $app): string
    {
        $root = dirname(__DIR__, 2);
        $container = $app->getContainer();
        $lines = [];

        $lines[] = 'shared boot at ' . (TestApplication::bootedAt() ?? 'n/a') . ', now ' . time();
        foreach (TestApplication::cacheStateAtBoot() as $relative => $mtime) {
            $lines[] = "  at boot {$relative}: " . ($mtime === null ? 'absent' : "mtime {$mtime}");
        }
        foreach (self::CACHE_FILES as $relative) {
            $path = $root . '/' . $relative;
            $lines[] = "  now {$relative}: " . (is_file($path) ? 'mtime ' . filemtime($path) : 'absent');
        }

        $manager = $container->get(ExtensionManager::class);
        $providers = (new \ReflectionProperty(ExtensionManager::class, 'providers'))->getValue($manager);
        $lines[] = 'extension cache used: ' . var_export($manager->getCacheUsed(), true);
        $lines[] = 'providers in boot order:';
        foreach (array_keys((array) $providers) as $i => $class) {
            $lines[] = sprintf('  %2d %s', $i, $class);
        }

        $latched = (new \ReflectionProperty(ServiceProvider::class, 'loadedRoutes'))->getValue(null);
        $lines[] = 'route files consumed by the provider latch:';
        foreach (array_keys((array) $latched) as $file) {
            $lines[] = '  ' . $file;
        }

        $vendorRoutes = $root . '/vendor/glueful/commerce/routes.php';
        $lines[] = 'vendor/glueful/commerce/routes.php: '
            . (is_file($vendorRoutes) ? 'present, realpath ' . realpath($vendorRoutes) : 'ABSENT');

        $router = $container->get(Router::class);
        $fromCache = (new \ReflectionProperty(Router::class, 'routesLoadedFromCache'))->getValue($router);
        $lines[] = 'router hydrated from route cache: ' . var_export($fromCache, true);

        $byPrefix = [];
        foreach ($router->getAllRoutes() as $route) {
            $segments = explode('/', ltrim((string) ($route['path'] ?? ''), '/'), 3);
            $prefix = '/' . $segments[0] . (isset($segments[1]) ? '/' . $segments[1] : '');
            $byPrefix[$prefix] = ($byPrefix[$prefix] ?? 0) + 1;
        }
        ksort($byPrefix);
        $lines[] = 'routes by two-segment prefix:';
        foreach ($byPrefix as $prefix => $count) {
            $lines[] = sprintf('  %4d %s', $count, $prefix);
        }

        return implode("\n", $lines);
    }
}
