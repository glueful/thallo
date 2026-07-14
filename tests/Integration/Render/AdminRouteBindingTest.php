<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin render routes read/write the registered tenant tables `render_templates`/
 * `render_template_versions` via auto-scoped reads, so they MUST bind the operator's selected
 * workspace under full resolution. Dropping admin_tenant_binding silently leaks rows across
 * workspaces — guard the wiring structurally.
 */
final class AdminRouteBindingTest extends AppTestCase
{
    public function testAdminRenderRouteBindsSelectedWorkspace(): void
    {
        $match = $this->router()->match(Request::create('/v1/admin/render/templates', 'GET'));
        self::assertNotNull($match, 'GET /v1/admin/render/templates must resolve to a route');
        self::assertNotNull($match['route'], 'render templates route must exist');
        self::assertContains(
            'admin_tenant_binding',
            $match['route']->getMiddleware(),
            'admin render route must bind the selected workspace via admin_tenant_binding',
        );
    }
}
