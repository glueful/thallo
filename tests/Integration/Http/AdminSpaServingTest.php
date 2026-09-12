<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Thallo\Core\Tests\Support\AppTestCase;

final class AdminSpaServingTest extends AppTestCase
{
    public function testAdminBundleIsMountedAtAdmin(): void
    {
        // serveFrontend() registers the SPA root route only when bundle_path exists and holds
        // index.html. phpunit.xml points ADMIN_BUNDLE_PATH at tests/fixtures/admin (Step 1b),
        // which holds a committed index.html, so the mount is wired during the process-global boot.
        $route = $this->findRoute('GET', '/admin');
        self::assertNotNull($route, '/admin must be mounted by serveFrontend()');
    }

    public function testTheBundleShipsUnderCoreAndPublicAdminIsDerivedOutput(): void
    {
        // phpunit.xml pins ADMIN_BUNDLE_PATH at a fixture, so the DEFAULT is asserted from the
        // config file itself: the bundle lives in core/resources/admin (baked into the release
        // tag), and public/admin is what thallo:provision publishes — never tracked, never shipped.
        $root = dirname(__DIR__, 3);
        $default = (string) file_get_contents("$root/core/config/thallo.php");
        self::assertStringContainsString("dirname(__DIR__) . '/resources/admin'", $default);
        exec('git -C ' . escapeshellarg($root) . ' ls-files public/admin', $tracked);
        self::assertSame([], $tracked, 'public/admin is derived output and must not be tracked');
    }

    public function testConfigRouteIsNotShadowedBySpaCatchAll(): void
    {
        // The /admin/config route must still resolve as itself, never as the SPA fallback — the
        // router's static-first lookup guarantees this.
        $route = $this->findRoute('GET', '/admin/config');
        self::assertNotNull($route, '/admin/config must remain its own static route');
    }
}
