<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofittedTenantTestCase;
use App\Tests\Support\RecordingExtensionActivation;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Thallo\Tenancy\Cache\CacheTransition;
use Thallo\Tenancy\Contracts\TenantSeedRepair;
use Thallo\Tenancy\Enablement\DisableGates;
use Thallo\Tenancy\Enablement\DisableProbe;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\FinalizationProbe;
use Thallo\Tenancy\Enablement\TenancyEnablement;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\System\SystemFlags;

final class DisableRoundTripAcceptanceTest extends RetrofittedTenantTestCase
{
    protected function tearDown(): void
    {
        $guard = $this->container()->get(RetrofitMaintenanceGuard::class);
        $guard->refresh();
        if ($guard->active()) {
            $guard->end();
        }
        parent::tearDown();
    }

    protected static function seedAdditionalTenants(): bool
    {
        return false;
    }

    public function testDisableSettlesOnFreshBootAndReenableReachesReloading(): void
    {
        $container = $this->container();
        $this->prepareOn($container);
        $activation = new RecordingExtensionActivation(activated: true);

        $disabled = $this->service($this->appContext(), $activation)->disable();
        self::assertSame(EnablementStep::DISABLED_WIDENED, $disabled->step);
        self::assertTrue($disabled->reloading);
        self::assertSame(1, $activation->deactivateCalls);

        $fresh = $this->freshBoot();
        $freshService = $this->service($fresh, $activation);
        $settled = $freshService->disable();
        self::assertSame(EnablementStep::DISABLED_WIDENED, $settled->step);
        self::assertFalse($settled->reloading);
        $fresh->getContainer()->get(RetrofitMaintenanceGuard::class)->refresh();
        self::assertFalse($fresh->getContainer()->get(RetrofitMaintenanceGuard::class)->active());

        $reenabling = $freshService->begin();
        self::assertSame(EnablementStep::RELOADING, $reenabling->step);
        self::assertTrue($reenabling->enabled);
        self::assertSame(1, $activation->activateCalls);
    }

    private function prepareOn(\Psr\Container\ContainerInterface $container): void
    {
        $container->get(TenantSeedRepair::class)->repair(self::$defaultTenantUuid);
        $container->get(SystemFlags::class)->put('tenancy.schema_state', 'widened');
        $container->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', self::$defaultTenantUuid);
        $container->get(SystemFlags::class)->put('tenancy.enabled', '1');
        $container->get(EnablementStore::class)->setStep(EnablementStep::ON);
    }

    private function service(
        ApplicationContext $context,
        RecordingExtensionActivation $activation,
    ): TenancyEnablement {
        $container = $context->getContainer();

        return new TenancyEnablement(
            $context,
            $container->get(EnablementStore::class),
            $container->get(EnablementLock::class),
            $container->get(SystemFlags::class),
            $activation,
            $container->get(FinalizationProbe::class),
            $container->get(TenantRuntimeReadiness::class),
            $container->get(RetrofitMaintenanceGuard::class),
            $container->get(CacheTransition::class),
            $container->get(Connection::class),
            $container->get(DisableGates::class),
            $container->get(DisableProbe::class),
            $container->get(CacheStore::class),
        );
    }

    private function freshBoot(): ApplicationContext
    {
        self::resetTenancyGlobals();
        self::resetSharedRepositoryConnection();
        /** @var array{enabled:list<string>} $base */
        $base = require dirname(__DIR__, 3) . '/config/serviceproviders.php';
        $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'];

        self::$onApp = self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers]);
        return self::$onApp;
    }
}
