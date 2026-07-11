<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Http\Controllers\TenantDirectoryController;

final class TenantManagementApiTest extends AppTestCase
{
    public function testDirectoryDegradesToEmptyBeforeTenancyImplementationIsActive(): void
    {
        $response = $this->container()->get(TenantDirectoryController::class)->mine(Request::create('/'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['data']['tenants']);
    }
}
