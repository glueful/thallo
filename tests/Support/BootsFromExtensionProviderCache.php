<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;

/**
 * Boots a second app through `ExtensionManager::discover()`'s CACHE-HIT branch — the boot mode
 * production is REQUIRED to use, and the one the rest of the suite never exercises.
 *
 * `discover()` has two mutually exclusive branches. On a cache hit (`loadFromCache()` returns
 * non-null) it assigns `$this->providers`, sets `cacheUsed`, and RETURNS — `registerProviders()` is
 * never reached, so NO provider's `register()` runs on that boot. Only when there is no usable cache
 * does it fall through to live discovery (and in production it instead THROWS "Extension cache
 * missing in production. Run: php glueful extensions:cache"). `ExtensionManager::boot()` meanwhile
 * runs for every provider in BOTH branches — which is why every mechanism this pack relies on in
 * production lives in `boot()`, never in `register()`.
 *
 * The branch is environment-INDEPENDENT: `isProduction()` only picks the cache TTL and decides
 * whether a cache MISS is fatal. Given a fresh `bootstrap/cache/extensions.php` these helpers drive
 * the identical cache-hit code path under `APP_ENV=testing`, with `EXTENSIONS_CACHE_TTL_DEV` pinned
 * so the hit is a property of the file rather than of how long the boot took.
 *
 * The cache file is written from the REAL resolver output ({@see ExtensionManager::resolveProviderClasses()},
 * i.e. `ProviderClassResolver::resolve()` — app providers plus enabled extensions, already run
 * through `ProviderOrderer::order()`), so it is byte-for-byte what `php glueful extensions:cache`
 * would write. Both the env var and any pre-existing cache file are restored in a `finally`.
 *
 * Consumed by {@see \App\Tests\Integration\Subscriptions\EngineNativeRoutesCachedBootTest} and
 * {@see \App\Tests\Integration\Subscriptions\SubjectResolverCachedBootTest}.
 */
trait BootsFromExtensionProviderCache
{
    /**
     * @param list<class-string> $mustResolve provider classes whose presence in the resolved list is
     *        asserted before booting — a cached-boot proof about a provider that is not even in the
     *        cache would prove nothing.
     */
    protected function bootFromExtensionProviderCache(array $mustResolve = []): ApplicationContext
    {
        $root = dirname(__DIR__, 2);
        $cacheFile = $root . '/bootstrap/cache/extensions.php';
        $cacheDir = dirname($cacheFile);

        $manager = $this->container()->get(ExtensionManager::class);
        self::assertInstanceOf(ExtensionManager::class, $manager);
        $classes = $manager->resolveProviderClasses();
        foreach ($mustResolve as $class) {
            self::assertContains($class, $classes, "sanity: {$class} must resolve into the provider list");
        }

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $previousCache = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : null;
        // Restored alongside the CONTENT below. `loadFromCache()` decides cache hit/miss from
        // filemtime vs EXTENSIONS_CACHE_TTL_DEV (5s by default), so rewriting the file with a
        // fresh mtime would silently turn EVERY secondary boot in the next few seconds of this
        // process into a cached-provider boot — where no provider's register() runs and every
        // extension's mergeConfig() defaults are therefore absent. That leaked into unrelated
        // config-dependent tests as order-dependent failures; put the timestamp back too.
        $previousMtime = $previousCache !== null ? (int) filemtime($cacheFile) : null;
        $previousTtl = $_ENV['EXTENSIONS_CACHE_TTL_DEV'] ?? null;

        file_put_contents(
            $cacheFile,
            "<?php\n\n// Written by " . self::class . "\n\nreturn " . var_export($classes, true) . ";\n",
        );
        $_ENV['EXTENSIONS_CACHE_TTL_DEV'] = (string) PHP_INT_MAX;

        try {
            // Any config override works here — this helper is only used for the boot machinery it
            // wraps (RouteManifest / loaded-route / route-cache resets). The extensions list is
            // written back UNCHANGED so this is the ordinary, fully-enabled boot.
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
                if ($previousMtime !== null) {
                    @touch($cacheFile, $previousMtime);
                }
            } else {
                @unlink($cacheFile);
            }
        }
    }

    /** Asserts the boot really took the cache-hit branch (so `registerProviders()` did not run). */
    protected function assertBootUsedTheProviderCache(ApplicationContext $app): ExtensionManager
    {
        $manager = $app->getContainer()->get(ExtensionManager::class);
        self::assertInstanceOf(ExtensionManager::class, $manager);
        self::assertTrue(
            $manager->getCacheUsed(),
            'this boot must take the cached-provider path (the one production is required to use)',
        );

        return $manager;
    }
}
