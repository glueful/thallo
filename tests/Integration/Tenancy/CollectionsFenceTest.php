<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Tenancy\Runtime\CollectionsDisabledWhenTenantMiddleware;
use Thallo\Tenancy\System\SystemFlags;

final class CollectionsFenceTest extends AppTestCase
{
    public function testCollectionsPassWhileTenancyIsOff(): void
    {
        $middleware = new CollectionsDisabledWhenTenantMiddleware(
            $this->container()->get(SystemFlags::class),
        );

        $response = $middleware->handle(
            Request::create('/v1/collections/products'),
            static fn (): Response => new Response('passed'),
        );

        self::assertSame('passed', $response->getContent());
    }

    public function testCollectionsFailClosedWhileTenancyIsEnabled(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.enabled', '1');
        $middleware = new CollectionsDisabledWhenTenantMiddleware($flags);

        $response = $middleware->handle(
            Request::create('/v1/collections/products'),
            static fn (): Response => new Response('unsafe'),
        );

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }
}
