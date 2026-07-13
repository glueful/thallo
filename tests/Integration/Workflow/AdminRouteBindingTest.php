<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin workflow routes read/write the registered tenant tables `workflow_review_states`/
 * `workflow_transitions` via auto-scoped reads, so they MUST bind the operator's selected workspace
 * under full resolution. Dropping admin_tenant_binding silently leaks rows across workspaces — guard
 * the wiring structurally.
 */
final class AdminRouteBindingTest extends AppTestCase
{
    public function testAdminWorkflowRouteBindsSelectedWorkspace(): void
    {
        $match = $this->router()->match(Request::create('/v1/admin/workflow/queue', 'GET'));
        self::assertNotNull($match, 'GET /v1/admin/workflow/queue must resolve to a route');
        self::assertNotNull($match['route'], 'workflow queue route must exist');
        self::assertContains(
            'admin_tenant_binding',
            $match['route']->getMiddleware(),
            'admin workflow route must bind the selected workspace via admin_tenant_binding',
        );
    }
}
