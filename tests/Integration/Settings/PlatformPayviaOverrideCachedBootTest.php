<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Providers\ThalloServiceProvider;
use App\Settings\PlatformPaymentSettingsStore;
use App\Settings\PlatformPayviaSettingsOverride;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\BootsFromExtensionProviderCache;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Extensions\Payvia\Support\PayviaSettingsOverride;
use Thallo\Tenancy\System\SystemFlags;

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
 * silently migrating into `register()`/`boot()` later.
 *
 * A BOUND override is not the same as a WORKING one, so `instanceof` alone is not the pin. The
 * same cached-boot rule that kills `register()` also kills every extension's
 * `mergeConfig()` — including payvia's own `config/payvia.php` defaults — which left
 * `config('payvia.gateways')` EMPTY, and the override's config-gated whitelist therefore refused
 * every `payvia.gateways.{id}.*` key while still resolving as the right class. The remediation is
 * the app-published `config/payvia.php` (read by `ConfigurationLoader` in BOTH boot modes); the
 * proof that it works is below: this test seeds a real platform credential through
 * {@see PlatformPaymentSettingsStore} and asserts the cached boot SERVES it, both straight off the
 * seam and through {@see PayviaSettings} — the read path drivers and signature verification use.
 */
final class PlatformPayviaOverrideCachedBootTest extends AppTestCase
{
    use BootsFromExtensionProviderCache;

    public function testTheAppOwnedOverrideResolvesCredentialsOnBothBootModes(): void
    {
        // Seeded through the ordinary boot's store; the cached boot below is a SECOND app over the
        // SAME database, so it reads this exact row (system-channel rows are truncated per test).
        $key = 'payvia.gateways.paystack.secret_key';
        $secret = 'sk_cached_boot_must_resolve_this';
        (new PlatformPaymentSettingsStore(
            $this->container()->get(SystemFlags::class),
            $this->container()->get(EncryptionService::class),
        ))->putMany([$key => $secret, 'payvia.default_gateway' => 'stripe']);

        // 1. The ordinary (live-discovery) boot the rest of the suite runs on.
        self::assertInstanceOf(
            PlatformPayviaSettingsOverride::class,
            $this->container()->get(PayviaSettingsOverride::class),
            'the app-owned override must be bound on a live-discovery boot',
        );
        self::assertSame(
            $secret,
            $this->container()->get(PayviaSettingsOverride::class)->value($this->appContext(), $key),
            'sanity: the live-discovery boot resolves the seeded platform secret',
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

            // The gateway map must be READABLE on this boot — this is what payvia's own
            // register()-time mergeConfig() cannot supply here, and what the app-published
            // config/payvia.php exists to guarantee.
            self::assertSame(
                ['paystack', 'stripe'],
                array_keys((array) config($cachedApp, 'payvia.gateways', [])),
                'payvia gateway config must be present on a cached-provider boot',
            );

            // …and the seam must actually SERVE a platform credential, not merely be the right
            // class. A gateway-scoped key is deliberately used: it is the one the whitelist gates
            // on the config map above, so it is precisely the assertion an empty map would break.
            /** @var PayviaSettingsOverride $override */
            $override = $container->get(PayviaSettingsOverride::class);
            self::assertSame(
                $secret,
                $override->value($cachedApp, $key),
                'a cached-provider boot must RESOLVE the stored platform secret through the seam',
            );
            self::assertSame(
                'stripe',
                $override->value($cachedApp, 'payvia.default_gateway'),
                'the non-secret platform value must resolve on a cached-provider boot too',
            );

            // End to end through payvia's own reader — what GatewayManager, the drivers, and
            // WebhookService's signature verification actually call.
            $gatewayConfig = PayviaSettings::gatewayConfig($cachedApp, 'paystack');
            self::assertSame($secret, $gatewayConfig['secret_key'] ?? null);
            self::assertSame('stripe', PayviaSettings::defaultGateway($cachedApp));
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }
}
