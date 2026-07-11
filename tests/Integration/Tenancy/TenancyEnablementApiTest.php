<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Routing\Route;
use Glueful\Routing\Router;

final class TenancyEnablementApiTest extends AppTestCase
{
    public function testEnablementRoutesAreRegisteredAsSystemRoutes(): void
    {
        $expected = [
            'GET:/v1/admin/tenancy/status',
            'GET:/v1/admin/tenancy/diagnose',
            'POST:/v1/admin/tenancy/begin',
            'POST:/v1/admin/tenancy/confirm',
            'POST:/v1/admin/tenancy/retry',
            'POST:/v1/admin/tenancy/cancel',
            'POST:/v1/admin/tenancy/finalize',
            'POST:/v1/admin/tenancy/disable',
            'POST:/v1/admin/tenancy/resolution/activate',
        ];
        $found = [];

        foreach ($this->container()->get(Router::class)->getStaticRoutes() as $key => $route) {
            if (!$route instanceof Route || !in_array($key, $expected, true)) {
                continue;
            }
            $found[] = $key;
            self::assertContains('auth', $route->getMiddleware());
            self::assertContains('tenant_system', $route->getMiddleware());
            self::assertContains('content_permission:tenancy.manage', $route->getMiddleware());
        }

        sort($expected);
        sort($found);
        self::assertSame($expected, $found);
    }
}
