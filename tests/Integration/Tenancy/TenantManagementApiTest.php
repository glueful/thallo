<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Http\Controllers\TenantDirectoryController;
use Thallo\Tenancy\Http\Controllers\TenantManagementController;

final class TenantManagementApiTest extends AppTestCase
{
    public function testDirectoryDegradesToEmptyBeforeTenancyImplementationIsActive(): void
    {
        $response = $this->container()->get(TenantDirectoryController::class)->mine(Request::create('/'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['data']['tenants']);
    }

    public function testManagementListDegradesToEmptyBeforeControlPlaneIsActive(): void
    {
        $response = $this->container()->get(TenantManagementController::class)->index(Request::create('/'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['data']['tenants']);
    }

    public function testSeedDependentOperationsAreUnavailableBeforeControlPlaneIsActive(): void
    {
        $controller = $this->container()->get(TenantManagementController::class);
        $create = $controller->create(Request::create('/', 'POST', content: json_encode([
            'slug' => 'acme',
            'name' => 'Acme',
        ], JSON_THROW_ON_ERROR)));
        $repair = $controller->seed('tenant000001');

        self::assertSame(503, $create->getStatusCode());
        self::assertSame(503, $repair->getStatusCode());
        self::assertStringContainsString('unavailable', (string) $create->getContent());
        self::assertStringContainsString('unavailable', (string) $repair->getContent());
    }
}
