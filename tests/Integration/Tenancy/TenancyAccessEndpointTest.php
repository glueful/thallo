<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Http\Controllers\TenancyAccessController;
use App\Tests\Support\AppTestCase;
use Glueful\Routing\Route;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

final class TenancyAccessEndpointTest extends AppTestCase
{
    public function testRouteUsesSoftAdminResolutionWithoutPermissionGate(): void
    {
        $route = $this->route('GET:/v1/admin/tenancy/access');

        self::assertSame(
            ['auth', 'tenant_profile:admin,soft', 'tenant_bootstrap:optional'],
            $route->getMiddleware(),
        );
        self::assertNotContains('content_permission:tenancy.manage', $route->getMiddleware());
    }

    public function testMissingPrincipalReturnsFailClosedAccessShape(): void
    {
        $controller = $this->container()->get(TenancyAccessController::class);
        self::assertInstanceOf(TenancyAccessController::class, $controller);

        $response = $controller->access(new Request());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'manage_platform' => false,
            'access_any' => false,
            'manage_members' => false,
            'manage_domains' => false,
        ], $body['data']['access']);
    }

    private function route(string $key): Route
    {
        $route = $this->container()->get(Router::class)->getStaticRoutes()[$key] ?? null;
        self::assertInstanceOf(Route::class, $route);
        return $route;
    }
}
