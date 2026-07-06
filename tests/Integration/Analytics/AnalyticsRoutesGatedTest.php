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
}
