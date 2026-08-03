<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\BootsFromExtensionProviderCache;
use Glueful\Application;
use Glueful\Extensions\ExtensionManager;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\EnginePreemptionServiceProvider;
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
 * {@see EnginePreemptionServiceProvider} does the pre-emption from `boot()` instead, ordered ahead of
 * the engine by a negative `loadPriority()`.
 *
 * The branch is environment-INDEPENDENT: `isProduction()` only picks the cache TTL and decides
 * whether a cache MISS is fatal. Given a fresh `bootstrap/cache/extensions.php` this test drives the
 * identical cache-hit code path under `APP_ENV=testing`, with `EXTENSIONS_CACHE_TTL_DEV` pinned so
 * the hit cannot depend on boot timing.
 */
final class EngineNativeRoutesCachedBootTest extends AppTestCase
{
    use BootsFromExtensionProviderCache;

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
        $cachedApp = $this->bootFromExtensionProviderCache([
            EnginePreemptionServiceProvider::class,
            \Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class,
        ]);

        try {
            $container = $cachedApp->getContainer();

            // The whole point: this boot took `discover()`'s cache-hit early return, so
            // `registerProviders()` never ran and NO provider's register() executed.
            $manager = $this->assertBootUsedTheProviderCache($cachedApp);

            // Both providers really are live on this boot -- otherwise "the routes are absent"
            // would prove nothing at all.
            $providers = $manager->getProviders();
            self::assertArrayHasKey(EnginePreemptionServiceProvider::class, $providers);
            self::assertArrayHasKey(\Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class, $providers);

            // The guard provider must BOOT BEFORE the engine: ExtensionManager::boot() walks
            // $providers in order, and the pre-emption only works ahead of the engine's own
            // loadRoutesFrom(). Pinned on the cached order itself, not on a live re-sort.
            $order = array_keys($providers);
            self::assertLessThan(
                array_search(\Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class, $order, true),
                array_search(EnginePreemptionServiceProvider::class, $order, true),
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
}
