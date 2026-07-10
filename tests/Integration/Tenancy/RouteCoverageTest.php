<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Routing\Route;
use Glueful\Routing\Router;

final class RouteCoverageTest extends AppTestCase
{
    private const MARKERS = [
        'tenant_bootstrap',
        'tenant_system',
        'collections_disabled_when_tenant',
    ];

    public function testEveryThalloRouteCarriesExactlyOneTenancyMarker(): void
    {
        $router = $this->container()->get(Router::class);
        $routes = array_values($router->getStaticRoutes());
        foreach ($router->getDynamicRoutes() as $methodRoutes) {
            foreach ($methodRoutes as $route) {
                $routes[] = $route;
            }
        }

        self::assertNotEmpty($routes);
        $checked = 0;
        foreach ($routes as $route) {
            if (!$route instanceof Route || !self::isThalloOwned(self::handlerClass($route))) {
                continue;
            }

            ++$checked;
            $middleware = $route->getMiddleware();
            $present = array_values(array_intersect(self::MARKERS, $middleware));
            $path = $route->getPath();

            self::assertCount(
                1,
                $present,
                sprintf('%s must carry exactly one tenancy marker; found %s', $path, json_encode($present)),
            );

            if (($present[0] ?? null) === 'tenant_bootstrap') {
                $index = array_search('tenant_bootstrap', $middleware, true);
                self::assertIsInt($index);
                $prefix = array_slice($middleware, 0, $index);
                self::assertContains(
                    $prefix,
                    [[], ['tenant_profile:public'], ['auth', 'tenant_profile:admin']],
                    sprintf(
                        '%s: tenant_bootstrap must directly follow its resolver; prefix was [%s]',
                        $path,
                        implode(',', $prefix),
                    ),
                );
            }

            if (str_starts_with($path, '/v1/collections')) {
                self::assertSame('collections_disabled_when_tenant', $present[0] ?? null, $path . ': must be fenced');
            }
        }

        self::assertGreaterThan(40, $checked);
    }

    private static function handlerClass(Route $route): ?string
    {
        $handler = $route->getHandler();
        if (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
            return $handler[0];
        }
        if (is_string($handler) && str_contains($handler, '::')) {
            return explode('::', $handler, 2)[0];
        }
        return null;
    }

    private static function isThalloOwned(?string $class): bool
    {
        return $class !== null
            && (str_starts_with($class, 'App\\') || str_starts_with($class, 'Thallo\\'));
    }
}
