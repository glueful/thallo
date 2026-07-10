<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Contracts\TenantSeedRepair;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\TenancyEnablement;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\System\SystemFlags;

final class DisableRoundTripAcceptanceTest extends RetrofittedTenantTestCase
{
    protected static function seedAdditionalTenants(): bool
    {
        return false;
    }

    public function testDisableSettlesOnFreshBootAndReenableReachesReloading(): void
    {
        $container = $this->container();
        $container->get(TenantSeedRepair::class)->repair(self::$defaultTenantUuid);
        $container->get(SystemFlags::class)->put('tenancy.schema_state', 'widened');
        $container->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', self::$defaultTenantUuid);
        $container->get(SystemFlags::class)->put('tenancy.enabled', '1');
        $container->get(EnablementStore::class)->setStep(EnablementStep::ON);

        $disabled = $container->get(TenancyEnablement::class)->disable();
        self::assertSame(EnablementStep::DISABLED_WIDENED, $disabled->step);
        self::assertTrue($disabled->reloading);

        $fresh = $this->freshBoot();
        $settled = $fresh->getContainer()->get(TenancyEnablement::class)->disable();
        self::assertSame(EnablementStep::DISABLED_WIDENED, $settled->step);
        self::assertFalse($settled->reloading);
        $fresh->getContainer()->get(RetrofitMaintenanceGuard::class)->refresh();
        self::assertFalse($fresh->getContainer()->get(RetrofitMaintenanceGuard::class)->active());

        $reenabling = $fresh->getContainer()->get(TenancyEnablement::class)->begin();
        self::assertSame(EnablementStep::RELOADING, $reenabling->step);
        self::assertTrue($reenabling->enabled);
    }

    private function freshBoot(): ApplicationContext
    {
        self::resetTenancyGlobals();
        self::resetSharedRepositoryConnection();
        /** @var array{enabled:list<string>} $base */
        $base = require dirname(__DIR__, 3) . '/config/serviceproviders.php';
        $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'];

        return self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers]);
    }
}
