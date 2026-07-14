<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsRoutesGatedTest extends AppTestCase
{
    public function testAnalyticsAdminRouteIsRegisteredAndRequiresAuth(): void
    {
        $response = $this->handle(Request::create('/v1/admin/analytics/summary', 'GET', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ]));

        self::assertSame(
            401,
            $response->getStatusCode(),
            'Enabled-boot GET /v1/admin/analytics/summary must be 401 (route exists, auth rejects '
            . 'anonymous), got: ' . $response->getStatusCode() . ' body: ' . $response->getContent()
        );
    }

    public function testBreakdownRouteIsRegisteredAndRequiresAuth(): void
    {
        $response = $this->handle(Request::create('/v1/admin/analytics/breakdown', 'GET', [
            'event' => 'collections.row.created', 'from' => '2025-06-10', 'to' => '2025-06-10',
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ]));

        self::assertSame(
            401,
            $response->getStatusCode(),
            'Enabled-boot GET /v1/admin/analytics/breakdown must be 401 (route exists, auth rejects '
            . 'anonymous), got: ' . $response->getStatusCode() . ' body: ' . $response->getContent()
        );
    }

    public function testAnalyticsRouteBindsSelectedWorkspace(): void
    {
        // The analytics read route MUST carry admin_tenant_binding so that under full resolution the
        // operator's selected workspace is bound and the tenant-scoped rollup reads return that
        // workspace's data. Dropping it silently reintroduces the cross-workspace read gap, so guard
        // the wiring structurally (the query-level scoping itself is covered by AnalyticsTenantScopeTest).
        $match = $this->router()->match(Request::create('/v1/admin/analytics/summary', 'GET'));
        self::assertNotNull($match, 'GET /v1/admin/analytics/summary must resolve to a route');
        self::assertNotNull($match['route'], 'analytics summary route must exist');
        self::assertContains(
            'admin_tenant_binding',
            $match['route']->getMiddleware(),
            'analytics summary route must bind the selected workspace via admin_tenant_binding',
        );
    }
}
