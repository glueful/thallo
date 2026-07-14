<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Symfony\Component\HttpFoundation\Request;

/**
 * With the capability enabled, the admin schema routes are registered and sit behind `auth`:
 * an unauthenticated request gets 401 (not 404), proving the route exists behind the gate.
 * (The disabled → 404 case lives in RemovabilityTest, against a disabled-capability boot.)
 */
final class AdminRoutesGatedTest extends CollectionsTestCase
{
    public function testAdminCollectionsRouteIsRegisteredAndRequiresAuth(): void
    {
        $response = $this->handle(Request::create('/v1/admin/collections', 'GET', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ]));

        self::assertSame(
            401,
            $response->getStatusCode(),
            'Enabled-boot GET /v1/admin/collections must be 401 (route exists, auth rejects anonymous), '
                . 'got: ' . $response->getStatusCode() . ' body: ' . $response->getContent(),
        );
    }

    public function testAdminCollectionsRouteBindsSelectedWorkspace(): void
    {
        // The admin collections routes read/write registered tenant tables (collection_definitions and
        // the per-tenant tc_* data tables) via auto-scoped reads, so they MUST bind the operator's
        // selected workspace under full resolution. Dropping admin_tenant_binding silently leaks rows
        // across workspaces — guard the wiring structurally.
        $match = $this->router()->match(Request::create('/v1/admin/collections', 'GET'));
        self::assertNotNull($match, 'GET /v1/admin/collections must resolve to a route');
        self::assertNotNull($match['route'], 'collections index route must exist');
        self::assertContains(
            'admin_tenant_binding',
            $match['route']->getMiddleware(),
            'admin collections route must bind the selected workspace via admin_tenant_binding',
        );
    }
}
