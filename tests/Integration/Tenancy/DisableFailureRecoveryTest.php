<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RecordingExtensionActivation;
use App\Tests\Support\RetrofittedTenantTestCase;
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

final class DisableFailureRecoveryTest extends RetrofittedTenantTestCase
{
    protected static function seedAdditionalTenants(): bool
    {
        return false;
    }

    protected function tearDown(): void
    {
        $guard = $this->container()->get(RetrofitMaintenanceGuard::class);
        $guard->refresh();
        if ($guard->active()) {
            $guard->end();
        }
        parent::tearDown();
    }

    public function testDeactivationFailureIsRetryableWithBarrierRaised(): void
    {
        $container = $this->container();
        $container->get(TenantSeedRepair::class)->repair(self::$defaultTenantUuid);
        $container->get(SystemFlags::class)->put('tenancy.schema_state', 'widened');
        $container->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', self::$defaultTenantUuid);
        $container->get(SystemFlags::class)->put('tenancy.enabled', '1');
        $container->get(EnablementStore::class)->setStep(EnablementStep::ON);

        $activation = new RecordingExtensionActivation(
            activated: true,
            failNextDeactivation: true,
        );
        $service = $this->service($activation);

        self::assertSame(EnablementStep::FAILED, $service->disable()->step);
        self::assertSame(EnablementStep::DISABLING, $container->get(EnablementStore::class)->failedFrom());
        self::assertSame('1', $container->get(SystemFlags::class)->get('tenancy.retrofit_active'));

        self::assertSame(EnablementStep::DISABLING, $service->retry()->step);
        self::assertSame(EnablementStep::DISABLED_WIDENED, $service->disable()->step);
        self::assertSame(2, $activation->deactivateCalls);
    }

    private function service(RecordingExtensionActivation $activation): TenancyEnablement
    {
        $container = $this->container();

        return new TenancyEnablement(
            $this->appContext(),
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
}
