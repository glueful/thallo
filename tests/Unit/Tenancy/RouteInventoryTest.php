<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Content\Authorization\RoleMatrix;
use App\Tests\Support\AppTestCase;
use Glueful\Routing\Route;
use Glueful\Routing\Router;

final class RouteInventoryTest extends AppTestCase
{
    public function testTenantDataPermissionSlugsAreDeliberatelyClassified(): void
    {
        $matrix = new RoleMatrix($this->appContext());
        foreach ($this->routes() as $route) {
            if (!$this->isThalloOwned($route)) {
                continue;
            }
            $middleware = $route->getMiddleware();
            $requirement = $this->permission($middleware);
            if ($requirement === null) {
                continue;
            }

            // `content_permission:a,b` is an any-of requirement (RequirePermission comma-splits
            // it into per-candidate alternatives, see App\Content\Http\RequirePermission) — each
            // candidate is classified individually in tenancy.role_matrix (which only ever holds
            // real, single CapabilityCatalog slugs, never a composite comma-joined string).
            $candidates = array_values(array_filter(array_map('trim', explode(',', $requirement))));

            if (in_array('tenant_bootstrap', $middleware, true)) {
                foreach ($candidates as $permission) {
                    self::assertTrue(
                        $matrix->isTenantCapability($permission),
                        $route->getPath() . ": new tenant-data permission slug '{$permission}'"
                            . ' - add it to tenancy.role_matrix deliberately',
                    );
                }
            }

            foreach ($candidates as $permission) {
                if (
                    str_starts_with($permission, 'users.')
                    || str_starts_with($permission, 'tenancy.')
                    || $permission === 'system.access'
                ) {
                    self::assertContains(
                        'tenant_system',
                        $middleware,
                        $route->getPath() . ": global permission '{$permission}' must use tenant_system",
                    );
                }
            }
        }
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

    /** @param list<string> $middleware */
    private function permission(array $middleware): ?string
    {
        foreach ($middleware as $item) {
            if (str_starts_with($item, 'content_permission:')) {
                return substr($item, strlen('content_permission:'));
            }
        }
        return null;
    }

    private function isThalloOwned(Route $route): bool
    {
        $handler = $route->getHandler();
        $class = is_array($handler) && is_string($handler[0] ?? null) ? $handler[0] : null;
        return $class !== null && (str_starts_with($class, 'App\\') || str_starts_with($class, 'Thallo\\'));
    }
}
