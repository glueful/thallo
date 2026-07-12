<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofitHarnessTestCase;
use App\Tests\Support\RecordingExtensionActivation;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Thallo\Tenancy\Cache\CacheTransition;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\FinalizationProbe;
use Thallo\Tenancy\Enablement\TenancyEnablement;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\System\SystemFlags;

final class EnableFullMachineAcceptanceTest extends RetrofitHarnessTestCase
{
    protected static function includeTenancyExtensionOnEngineBoot(): bool
    {
        return false;
    }

    public function testTwoBootMachineReachesOn(): void
    {
        $activation = new RecordingExtensionActivation();
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        self::assertTrue($boot1->getContainer()->has(TenantProvisioner::class));
        $service1 = $this->service($boot1, $activation);

        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $service1->begin()->step);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $service1->begin()->step);
        self::assertSame(
            EnablementStep::RELOADING,
            $service1->confirm('acme', 'Acme', 'user00000001')->step,
        );
        self::assertSame(1, $activation->activateCalls);

        $boot2 = $this->bootWithTenancyExtension();
        $service2 = $this->service($boot2, $activation);
        self::assertSame(EnablementStep::ON, $service2->finalize()->step);
        $guard = $boot2->getContainer()->get(RetrofitMaintenanceGuard::class);
        $guard->refresh();
        self::assertFalse($guard->active());
    }

    private function bootWithTenancyExtension(): ApplicationContext
    {
        self::resetTenancyGlobals();
        self::resetSharedRepositoryConnection();
        /** @var array{enabled:list<string>} $base */
        $base = require dirname(__DIR__, 3) . '/config/serviceproviders.php';
        $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'];

        return self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers]);
    }

    private function service(ApplicationContext $context, RecordingExtensionActivation $activation): TenancyEnablement
    {
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
        );
    }
}
