<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Http\Middleware\AdminTenantBindingMiddleware;
use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminTenantBindingMiddlewareTest extends AppTestCase
{
    public function testResolvesFromContainerAndIsInertWithoutFullResolution(): void
    {
        // Resolving via the alias proves the factory + every injected dependency wire up; a
        // misregistration would otherwise only surface at request time on the live console.
        $middleware = $this->container()->get('admin_tenant_binding');
        self::assertInstanceOf(AdminTenantBindingMiddleware::class, $middleware);

        // Bootstrap/single-store env (no full resolution) => inert passthrough, so existing
        // admin behaviour is unchanged until full resolution is armed.
        $result = $middleware->handle(
            Request::create('/v1/admin/content-types'),
            static fn (): Response => new Response('passed'),
        );
        self::assertSame('passed', $result->getContent());
    }
}
