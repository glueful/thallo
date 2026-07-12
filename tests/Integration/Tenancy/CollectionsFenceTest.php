<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Routing\Route;
use Glueful\Routing\Router;

final class CollectionsFenceTest extends AppTestCase
{
    public function testLegacyFenceIsRemoved(): void
    {
        self::assertFalse(class_exists('Thallo\\Tenancy\\Runtime\\CollectionsDisabledWhenTenantMiddleware'));
    }

    public function testCollectionsUseTenantResolutionAndBindingInsteadOfAFence(): void
    {
        $routes = $this->container()->get(Router::class)->getDynamicRoutes();
        $found = false;
        foreach ($routes as $methodRoutes) {
            foreach ($methodRoutes as $route) {
                if (!$route instanceof Route || !str_starts_with($route->getPath(), '/v1/collections')) {
                    continue;
                }
                $found = true;
                self::assertContains('collections_tenant_binding', $route->getMiddleware());
                self::assertContains('tenant_bootstrap', $route->getMiddleware());
                self::assertNotContains('collections_disabled_when_tenant', $route->getMiddleware());
            }
        }
        self::assertTrue($found);
    }
}
