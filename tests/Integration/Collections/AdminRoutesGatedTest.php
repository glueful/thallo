<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * With the capability enabled, the admin schema routes are registered and sit behind `auth`:
 * an unauthenticated request gets 401 (not 404), proving the route exists behind the gate.
 * (The disabled → 404 case lives in RemovabilityTest, against a disabled-capability boot.)
 */
final class AdminRoutesGatedTest extends AppTestCase
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
}
