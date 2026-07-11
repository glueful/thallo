<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofitHarnessTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Thallo\Tenancy\Cache\CacheTransition;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\ExtensionActivationContract;
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

    public function testThreeBootMachineReachesOn(): void
    {
        $activation = $this->activationFake();
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        self::assertFalse($boot1->getContainer()->has(TenantProvisioner::class));
        $service1 = $this->service($boot1, $activation);

        self::assertSame(EnablementStep::ENABLING_EXTENSION, $service1->begin()->step);
        self::assertSame(EnablementStep::AWAITING_PROVIDER_BOOT, $service1->begin()->step);
        self::assertSame(EnablementStep::AWAITING_PROVIDER_BOOT, $service1->begin()->step);

        $boot2 = $this->bootWithTenancyExtension();
        self::assertTrue($boot2->getContainer()->has(TenantProvisioner::class));
        $service2 = $this->service($boot2, $activation);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $service2->begin()->step);
        self::assertSame(
            EnablementStep::RELOADING,
            $service2->confirm('acme', 'Acme', 'user00000001')->step,
        );

        $boot3 = $this->bootWithTenancyExtension();
        $service3 = $this->service($boot3, $activation);
        self::assertSame(EnablementStep::ON, $service3->finalize()->step);
        $guard = $boot3->getContainer()->get(RetrofitMaintenanceGuard::class);
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

    private function service(ApplicationContext $context, ExtensionActivationContract $activation): TenancyEnablement
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

    private function activationFake(): ExtensionActivationContract
    {
        return new class implements ExtensionActivationContract {
            private bool $activated = false;

            public function isInstalled(): bool
            {
                return true;
            }

            public function isActivated(): bool
            {
                return $this->activated;
            }

            public function install(): array
            {
                return [
                    'status' => 'installed',
                    'blocked' => false,
                    'reason' => null,
                    'cli' => null,
                    'output' => '',
                ];
            }

            public function activate(): void
            {
                $this->activated = true;
            }

            public function migrate(): array
            {
                return ['applied' => [], 'failed' => []];
            }
        };
    }
}
