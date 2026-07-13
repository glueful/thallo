<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin navigation routes read/write the registered tenant tables `navigation_menus`/
 * `navigation_items` via auto-scoped reads, so they MUST bind the operator's selected workspace under
 * full resolution. Dropping admin_tenant_binding silently leaks rows across workspaces — guard the
 * wiring structurally.
 */
final class AdminRouteBindingTest extends AppTestCase
{
    public function testAdminNavigationRouteBindsSelectedWorkspace(): void
    {
        $match = $this->router()->match(Request::create('/v1/admin/navigation/menus', 'GET'));
        self::assertNotNull($match, 'GET /v1/admin/navigation/menus must resolve to a route');
        self::assertNotNull($match['route'], 'navigation menus route must exist');
        self::assertContains(
            'admin_tenant_binding',
            $match['route']->getMiddleware(),
            'admin navigation route must bind the selected workspace via admin_tenant_binding',
        );
    }
}
