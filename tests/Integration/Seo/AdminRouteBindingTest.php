<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin SEO routes read/write the registered tenant table `seo_meta` via auto-scoped reads, so
 * they MUST bind the operator's selected workspace under full resolution. Dropping
 * admin_tenant_binding silently leaks rows across workspaces — guard the wiring structurally.
 */
final class AdminRouteBindingTest extends AppTestCase
{
    public function testAdminSeoRouteBindsSelectedWorkspace(): void
    {
        $match = $this->router()->match(Request::create('/v1/admin/seo/meta/entry00000001', 'GET'));
        self::assertNotNull($match, 'GET /v1/admin/seo/meta/{entryUuid} must resolve to a route');
        self::assertNotNull($match['route'], 'seo meta route must exist');
        self::assertContains(
            'admin_tenant_binding',
            $match['route']->getMiddleware(),
            'admin seo route must bind the selected workspace via admin_tenant_binding',
        );
    }
}
