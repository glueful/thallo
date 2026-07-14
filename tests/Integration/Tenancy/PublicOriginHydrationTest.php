<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\PublicOrigin\PublicOriginService;
use Thallo\Tenancy\PublicOrigin\PublicOriginStore;
use Thallo\Tenancy\System\SystemFlags;

/**
 * End-to-end proof that TenancyServiceProvider::boot() hydrates the persisted public origin over
 * config (Pin 4). A persisted base domain is written, then a fresh app is booted with a DIFFERENT
 * config-file value: the pack provider's boot() must overrideConfig() so the persisted value wins,
 * while an unset value (default_hosts) leaves the config file untouched. The fresh boot exercises the
 * real provider registration + hydrate call — the same path production boot runs before markBooted().
 */
final class PublicOriginHydrationTest extends AppTestCase
{
    private static ?ApplicationContext $overrideApp = null;

    public static function tearDownAfterClass(): void
    {
        // Hand process-global shared state back to the primary app after the secondary boot.
        self::resetSharedRepositoryConnection();
        self::restoreSharedPermissionProvider();
        self::$overrideApp = null;
        parent::tearDownAfterClass();
    }

    /** Boot (once) a fresh app whose config file differs from the persisted flag. */
    private function overrideApp(): ApplicationContext
    {
        if (self::$overrideApp === null) {
            // Persist an admin-set base domain that must win over the config file at boot.
            $this->appContext()->getContainer()->get(SystemFlags::class)
                ->put('tenancy.public_origin.base_domain', 'apex.example');
            self::resetSharedRepositoryConnection();
            self::$overrideApp = self::bootAppWithConfigOverride('tenancy', [
                'public_origin' => ['base_domain' => 'file.example'],
            ]);
        }

        return self::$overrideApp;
    }

    public function testHydrationMakesPersistedOriginWinOverConfigFile(): void
    {
        $ctx = $this->overrideApp();
        self::assertSame('apex.example', $ctx->getConfig('tenancy.public_origin.base_domain'));
    }

    public function testUnsetOriginLeavesConfigFileUntouched(): void
    {
        $ctx = $this->overrideApp();
        // No persisted default_hosts flag -> hydrate() leaves it exactly as the un-hydrated config
        // (the primary app booted with no public-origin flags resolves the pure file/env value).
        self::assertSame(
            $this->appContext()->getConfig('tenancy.public_origin.default_hosts'),
            $ctx->getConfig('tenancy.public_origin.default_hosts')
        );
    }

    public function testContainerRegistersPublicOriginServices(): void
    {
        self::assertInstanceOf(
            PublicOriginStore::class,
            $this->container()->get(PublicOriginStore::class)
        );
        self::assertInstanceOf(
            PublicOriginService::class,
            $this->container()->get(PublicOriginService::class)
        );
    }
}
