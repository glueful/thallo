<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Routing\Route;
use Glueful\Routing\Router;

final class ManagementRekeyTest extends AppTestCase
{
    public function testLifecycleAndSelfServiceRoutesUseTheirDedicatedPermissions(): void
    {
        $expected = [
            '/v1/admin/tenancy/status' => ['tenant_system', 'content_permission:tenancy.manage'],
            '/v1/admin/tenancy/tenants' => ['tenant_system', 'content_permission:tenancy.manage'],
            '/v1/admin/tenancy/tenants/{uuid}/members' => [
                'tenant_bootstrap',
                'content_permission:tenant.members.manage',
            ],
            '/v1/admin/tenancy/tenants/{uuid}/domains' => [
                'tenant_bootstrap',
                'content_permission:tenant.domains.manage',
            ],
        ];
        $seen = [];

        foreach ($this->routes() as $route) {
            $path = $route->getPath();
            if (!isset($expected[$path])) {
                continue;
            }
            $seen[$path] = true;
            foreach ($expected[$path] as $middleware) {
                self::assertContains($middleware, $route->getMiddleware(), $path);
            }
            self::assertNotContains('content_permission:system.access', $route->getMiddleware(), $path);
        }

        $expectedPaths = array_keys($expected);
        $seenPaths = array_keys($seen);
        sort($expectedPaths);
        sort($seenPaths);
        self::assertSame($expectedPaths, $seenPaths);
    }

    /** @return list<Route> */
    private function routes(): array
    {
        $router = $this->container()->get(Router::class);
        $routes = array_values($router->getStaticRoutes());
        foreach ($router->getDynamicRoutes() as $methodRoutes) {
            foreach ($methodRoutes as $route) {
                if ($route instanceof Route) {
                    $routes[] = $route;
                }
            }
        }
        return array_values(array_filter($routes, static fn(mixed $route): bool => $route instanceof Route));
    }
}
