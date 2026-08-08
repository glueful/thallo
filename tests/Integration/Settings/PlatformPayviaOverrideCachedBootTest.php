<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Providers\ThalloServiceProvider;
use App\Settings\PlatformPayviaSettingsOverride;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\BootsFromExtensionProviderCache;
use Glueful\Extensions\Payvia\Support\PayviaSettingsOverride;

/**
 * Task 4 (platform-payments-settings spec §2 "Binding") — the binding mechanism, pinned in BOTH
 * boot modes.
 *
 * The empirical constraint, re-verified for this task rather than assumed:
 *
 *  - `ExtensionManager::discover()` returns EARLY on an extensions-cache hit, so
 *    `registerProviders()` — the ONLY caller of any provider's `register()` — never runs on the
 *    boot mode production is required to use. That is true of APP-level providers too: the
 *    framework's `ProviderClassResolver` folds `config/serviceproviders.php` entries into the same
 *    provider list as composer extensions, and the cache file is written from that same resolved
 *    list. A binding contributed from {@see ThalloServiceProvider::register()} would therefore be
 *    dead code exactly where it matters most.
 *  - `ContainerFactory::loadExtensionDefinitions()`, by contrast, does NOT consult the extensions
 *    cache at all: it re-resolves the provider list through `ProviderClassResolver` while the
 *    container is being built and reads each provider's STATIC `services()`. That path runs
 *    identically in both boot modes.
 *
 * So the binding lives in `ThalloServiceProvider::services()`, and this test is what stops it from
 * silently migrating into `register()`/`boot()` later: it asserts the container really resolves
 * {@see PayviaSettingsOverride} to the app-owned {@see PlatformPayviaSettingsOverride} on the
 * ordinary boot AND on a boot proven (via `getCacheUsed()`) to have taken the cache-hit branch.
 */
final class PlatformPayviaOverrideCachedBootTest extends AppTestCase
{
    use BootsFromExtensionProviderCache;

    public function testTheAppOwnedOverrideIsBoundOnBothBootModes(): void
    {
        // 1. The ordinary (live-discovery) boot the rest of the suite runs on.
        self::assertInstanceOf(
            PlatformPayviaSettingsOverride::class,
            $this->container()->get(PayviaSettingsOverride::class),
            'the app-owned override must be bound on a live-discovery boot',
        );

        // 2. The cached-provider boot — the one production is required to use.
        $cachedApp = $this->bootFromExtensionProviderCache([ThalloServiceProvider::class]);

        try {
            $manager = $this->assertBootUsedTheProviderCache($cachedApp);
            self::assertArrayHasKey(
                ThalloServiceProvider::class,
                $manager->getProviders(),
                'sanity: the app provider must really be live on this cached boot',
            );

            $container = $cachedApp->getContainer();
            self::assertTrue(
                $container->has(PayviaSettingsOverride::class),
                'the payvia settings seam must be bound on a cached-provider boot',
            );
            self::assertInstanceOf(
                PlatformPayviaSettingsOverride::class,
                $container->get(PayviaSettingsOverride::class),
                'a cached-provider boot must resolve the APP-owned override — no register()-only wiring',
            );
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }
}
