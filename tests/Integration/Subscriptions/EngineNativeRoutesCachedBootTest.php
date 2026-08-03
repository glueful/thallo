<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\EngineRouteGuardServiceProvider;
use Thallo\Subscriptions\Http\EngineNativeRoutesDenied;
use Thallo\Subscriptions\SubscriptionsIntegrationServiceProvider;

/**
 * The CACHED-PROVIDER boot mode -- the one production is REQUIRED to run in, and the one every other
 * test in this suite misses.
 *
 * `ExtensionManager::discover()` has two mutually exclusive branches. On a cache hit
 * (`loadFromCache()` returns non-null) it assigns `$this->providers`, sets `cacheUsed`, and RETURNS
 * -- `registerProviders()` is never reached, so NO provider's `register()` runs on that boot. Only
 * when there is no usable cache does it fall through to live discovery (and, in production, it
 * instead THROWS "Extension cache missing in production. Run: php glueful extensions:cache").
 * `ExtensionManager::boot()` meanwhile runs for every provider in BOTH branches.
 *
 * Production therefore always takes the cache branch (`EXTENSIONS_CACHE_TTL_PROD` defaults to
 * `PHP_INT_MAX`), which is exactly why the engine-native route denial may not live in any
 * `register()`: it would be dead code precisely where it matters most, while the engine's own
 * `boot()` still mounted `/subscriptions/plans*` behind bare `['auth', 'subscriptions_plans_manage']`.
 * {@see EngineRouteGuardServiceProvider} does the pre-emption from `boot()` instead, ordered ahead of
 * the engine by a negative `loadPriority()`.
 *
 * The branch is environment-INDEPENDENT: `isProduction()` only picks the cache TTL and decides
 * whether a cache MISS is fatal. Given a fresh `bootstrap/cache/extensions.php` this test drives the
 * identical cache-hit code path under `APP_ENV=testing`, with `EXTENSIONS_CACHE_TTL_DEV` pinned so
 * the hit cannot depend on boot timing.
 */
final class EngineNativeRoutesCachedBootTest extends AppTestCase
{
    /** @var list<array{0:string,1:string}> the engine's own native mounts (vendor routes.php) */
    private const ENGINE_NATIVE_ROUTES = [
        ['GET', '/subscriptions/plans'],
        ['POST', '/subscriptions/plans'],
        ['POST', '/subscriptions/plans/import-config'],
        ['GET', '/subscriptions/plans/{key}'],
        ['PATCH', '/subscriptions/plans/{key}'],
        ['POST', '/subscriptions/plans/{key}/archive'],
    ];

    public function testCachedProviderBootStillDeniesTheEngineNativePlanRoutes(): void
    {
        $cachedApp = $this->bootFromExtensionProviderCache();

        try {
            $container = $cachedApp->getContainer();
            $manager = $container->get(ExtensionManager::class);
            self::assertInstanceOf(ExtensionManager::class, $manager);

            // The whole point: this boot took `discover()`'s cache-hit early return, so
            // `registerProviders()` never ran and NO provider's register() executed.
            self::assertTrue(
                $manager->getCacheUsed(),
                'this boot must take the cached-provider path (the one production is required to use)',
            );

            // Both providers really are live on this boot -- otherwise "the routes are absent"
            // would prove nothing at all.
            $providers = $manager->getProviders();
            self::assertArrayHasKey(EngineRouteGuardServiceProvider::class, $providers);
            self::assertArrayHasKey(\Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class, $providers);

            // The guard provider must BOOT BEFORE the engine: ExtensionManager::boot() walks
            // $providers in order, and the pre-emption only works ahead of the engine's own
            // loadRoutesFrom(). Pinned on the cached order itself, not on a live re-sort.
            $order = array_keys($providers);
            self::assertLessThan(
                array_search(\Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class, $order, true),
                array_search(EngineRouteGuardServiceProvider::class, $order, true),
                'the route guard must boot before glueful/subscriptions on the cached boot too',
            );
            // ...and the pack's MAIN provider necessarily boots after it (loadAfter + priority 100),
            // which is exactly why the denial cannot live there.
            self::assertGreaterThan(
                array_search(\Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class, $order, true),
                array_search(SubscriptionsIntegrationServiceProvider::class, $order, true),
                'sanity: the pack main provider still loads AFTER the engine',
            );

            // Structural: each native mount registered exactly once, guard first, engine's own
            // `auth` still behind it (i.e. this really is the engine's file, pre-empted).
            $routes = $container->get(Router::class)->getAllRoutes();
            foreach (self::ENGINE_NATIVE_ROUTES as [$method, $path]) {
                $matches = array_values(array_filter(
                    $routes,
                    static fn (array $route): bool => strtoupper((string) $route['method']) === $method
                        && (string) $route['path'] === $path,
                ));
                self::assertCount(1, $matches, "{$method} {$path} must be registered exactly once");
                $middleware = array_values((array) $matches[0]['middleware']);
                self::assertSame(
                    EngineNativeRoutesDenied::ALIAS,
                    $middleware[0] ?? null,
                    "{$method} {$path} must run the deny guard first on a cached boot (got: "
                    . implode(',', array_map('strval', $middleware)) . ')',
                );
                self::assertContains('auth', $middleware);
            }

            // Behavioural: 404 for every method, with and without credentials -- the framework's own
            // unmatched-route status, so the mounts are indistinguishable from absent.
            foreach (self::ENGINE_NATIVE_ROUTES as [$method, $template]) {
                $path = str_replace('{key}', 'irrelevant', $template);
                self::assertSame(
                    404,
                    (new Application($cachedApp))->handle(Request::create($path, $method, [], [], [], [
                        'CONTENT_TYPE' => 'application/json',
                        'HTTP_ACCEPT' => 'application/json',
                    ]))->getStatusCode(),
                    "{$method} {$path} must be absent (404) on a cached-provider boot",
                );
            }
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }

    /**
     * Boots a second app whose `ExtensionManager` takes the cache-hit branch.
     *
     * The cache file is written from the REAL resolver output
     * ({@see ExtensionManager::resolveProviderClasses()}, which is
     * `ProviderClassResolver::resolve()` -- app providers + enabled extensions, already run through
     * `ProviderOrderer::order()`), i.e. byte-for-byte what `php glueful extensions:cache` would
     * write. `EXTENSIONS_CACHE_TTL_DEV` is pinned for the duration so the hit is a property of the
     * file, not of how long the boot took. Both the env var and any pre-existing cache file are
     * restored in a finally.
     */
    private function bootFromExtensionProviderCache(): ApplicationContext
    {
        $root = dirname(__DIR__, 3);
        $cacheFile = $root . '/bootstrap/cache/extensions.php';
        $cacheDir = dirname($cacheFile);

        $manager = $this->container()->get(ExtensionManager::class);
        self::assertInstanceOf(ExtensionManager::class, $manager);
        $classes = $manager->resolveProviderClasses();
        self::assertContains(EngineRouteGuardServiceProvider::class, $classes, 'sanity: the guard resolves');
        self::assertContains(
            \Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class,
            $classes,
            'sanity: the engine resolves',
        );

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $previousCache = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : null;
        $previousTtl = $_ENV['EXTENSIONS_CACHE_TTL_DEV'] ?? null;

        file_put_contents(
            $cacheFile,
            "<?php\n\n// Written by " . self::class . "\n\nreturn " . var_export($classes, true) . ";\n",
        );
        $_ENV['EXTENSIONS_CACHE_TTL_DEV'] = (string) PHP_INT_MAX;

        try {
            // Any config override works here -- this helper is only used for the boot machinery it
            // wraps (RouteManifest/loaded-route/route-cache resets). The extensions list is written
            // back UNCHANGED so this boot is the ordinary, fully-enabled one.
            $base = (array) require $root . '/config/extensions.php';

            return self::bootAppWithConfigOverride('extensions', ['enabled' => (array) $base['enabled']]);
        } finally {
            if ($previousTtl === null) {
                unset($_ENV['EXTENSIONS_CACHE_TTL_DEV']);
            } else {
                $_ENV['EXTENSIONS_CACHE_TTL_DEV'] = $previousTtl;
            }
            if ($previousCache !== null) {
                file_put_contents($cacheFile, $previousCache);
            } else {
                @unlink($cacheFile);
            }
        }
    }
}
