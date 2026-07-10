<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Tenancy\Runtime\BootstrapDefaultTenantMiddleware;
use Thallo\Tenancy\System\SystemFlags;

final class BootstrapResolutionTest extends AppTestCase
{
    public function testOffModePassesWithoutExtensionContracts(): void
    {
        $middleware = new BootstrapDefaultTenantMiddleware(
            $this->appContext(),
            $this->container()->get(SystemFlags::class),
            $this->readiness(TenantRuntimeReadiness::MODE_NONE),
        );

        $response = $middleware->handle(
            Request::create('/'),
            static fn (): Response => new Response('passed'),
        );

        self::assertSame('passed', $response->getContent());
    }

    public function testBootstrapWrapsRequestInDefaultTenant(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.enabled', '1');
        $flags->put('tenancy.default_tenant_uuid', 'tenant000001');
        $runner = $this->runner();

        $middleware = new BootstrapDefaultTenantMiddleware(
            $this->appContext(),
            $flags,
            $this->readiness(TenantRuntimeReadiness::MODE_BOOTSTRAP_DEFAULT),
            null,
            $runner,
        );

        $response = $middleware->handle(
            Request::create('/'),
            static fn (): Response => new Response('scoped'),
        );

        self::assertSame('scoped', $response->getContent());
        self::assertSame('tenant000001', $runner->tenantUuid);
    }

    public function testEnabledWithoutSafeResolutionFailsClosed(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.enabled', '1');

        $middleware = new BootstrapDefaultTenantMiddleware(
            $this->appContext(),
            $flags,
            $this->readiness(TenantRuntimeReadiness::MODE_NONE),
        );

        $response = $middleware->handle(
            Request::create('/'),
            static fn (): Response => new Response('unsafe'),
        );

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    private function readiness(string $mode): TenantRuntimeReadiness
    {
        return new class ($mode) implements TenantRuntimeReadiness {
            public function __construct(private readonly string $mode)
            {
            }

            public function isReady(ApplicationContext $context): bool
            {
                return $this->mode !== self::MODE_NONE;
            }

            public function mode(ApplicationContext $context): string
            {
                return $this->mode;
            }
        };
    }

    private function runner(): TenantContextRunner
    {
        return new class implements TenantContextRunner {
            public ?string $tenantUuid = null;

            public function runAsTenant(string $tenantUuid, callable $fn): mixed
            {
                $this->tenantUuid = $tenantUuid;
                return $fn();
            }

            public function runAsSystem(callable $fn): mixed
            {
                return $fn();
            }

            public function forEachTenant(callable $fn): void
            {
            }
        };
    }
}
