<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\FinalizationProbe;
use Thallo\Tenancy\Enablement\TenancyEnablement;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\Runtime\BootstrapDefaultTenantMiddleware;
use Thallo\Tenancy\System\SystemFlags;

final class EnableToOnAcceptanceTest extends RetrofittedTenantTestCase
{
    protected static function seedAdditionalTenants(): bool
    {
        return false;
    }

    public function testFreshBootFinalizesOneTenantAndRefusesAmbiguousBootstrap(): void
    {
        $container = $this->container();
        $flags = $container->get(SystemFlags::class);
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', self::$defaultTenantUuid);
        $flags->put('tenancy.enabled', '1');
        $container->get(EnablementStore::class)->setStep(EnablementStep::RELOADING);
        $container->get(RetrofitMaintenanceGuard::class)->begin();

        $probe = $container->get(FinalizationProbe::class)->report($this->appContext());
        self::assertTrue($probe['blobPolicy']);
        self::assertTrue($probe['enforcement']);
        self::assertTrue($probe['ready']);

        $status = $container->get(TenancyEnablement::class)->finalize();
        self::assertSame(EnablementStep::ON, $status->step);
        $container->get(RetrofitMaintenanceGuard::class)->refresh();
        self::assertFalse($container->get(RetrofitMaintenanceGuard::class)->active());

        $seenTenant = '';
        $resolver = $container->get(CurrentTenantResolver::class);
        $response = $container->get(BootstrapDefaultTenantMiddleware::class)->handle(
            Request::create('/'),
            function () use (&$seenTenant, $resolver): Response {
                $seenTenant = $resolver->tenantUuid($this->appContext());
                return new Response('ok');
            },
        );
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::$defaultTenantUuid, $seenTenant);

        $secondTenant = Utils::generateNanoID(12);
        $container->get(TenantProvisioner::class)->provisionDefault(
            $this->appContext(),
            $secondTenant,
            'tenant-two',
            'Tenant Two',
            'user00000001',
        );
        $container->get(EnablementStore::class)->setStep(EnablementStep::RELOADING);
        $container->get(RetrofitMaintenanceGuard::class)->begin();

        $refused = $container->get(TenancyEnablement::class)->finalize();
        self::assertSame(EnablementStep::RELOADING, $refused->step);
        $container->get(RetrofitMaintenanceGuard::class)->refresh();
        self::assertTrue($container->get(RetrofitMaintenanceGuard::class)->active());

        $segments = $container->get(TenantCacheSegment::class);
        $runner = $container->get(TenantContextRunner::class);
        $first = $runner->runAsTenant(
            self::$defaultTenantUuid,
            fn (): string => $segments->segment($this->appContext(), 'render'),
        );
        $second = $runner->runAsTenant(
            $secondTenant,
            fn (): string => $segments->segment($this->appContext(), 'render'),
        );
        self::assertNotSame('', $first);
        self::assertNotSame($first, $second);
    }
}
