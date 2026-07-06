<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\Controllers\AdminConfigController;
use App\Setup\SetupService;
use App\Tests\Support\AppTestCase;

final class AdminConfigApiTest extends AppTestCase
{
    public function testReturnsRuntimeConfigKeys(): void
    {
        $controller = new AdminConfigController(
            $this->appContext(),
            $this->container()->get(SetupService::class),
        );
        $resp = $controller->config();

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        self::assertArrayHasKey('apiBase', $body);
        self::assertArrayHasKey('sitePreviewUrl', $body);
        self::assertArrayHasKey('defaultLocale', $body);
        self::assertArrayHasKey('installed', $body);
        self::assertSame('/v1/admin', $body['apiBase']);
        self::assertIsBool($body['installed']);
    }

    public function testConfigRouteIsRegisteredUnauthenticated(): void
    {
        // The SPA needs apiBase BEFORE it can log in, so this route must NOT be in the
        // /v1/admin auth group. Assert it is registered and carries no `auth` middleware.
        $route = $this->findRoute('GET', '/admin/config');
        self::assertNotNull($route, '/admin/config must be registered');
        $middleware = (array) ($route['middleware'] ?? []);
        self::assertNotContains('auth', $middleware, '/admin/config must be unauthenticated');
    }
}
