<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Capabilities\DefaultCapabilityRegistry;
use App\Http\Controllers\CapabilityAdminController;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\Capability;

final class CapabilityAdminApiTest extends AppTestCase
{
    public function testReturnsOnlyEnabledCapabilities(): void
    {
        // Hand-build a registry with one ENABLED and one DISABLED fake. This pins the
        // endpoint to enabled(), NOT all(): an index() that returned all() would wrongly
        // include test.disabled and fail this test.
        $registry = new DefaultCapabilityRegistry(['test.disabled' => false]);
        $registry->register(new Capability('test.fake', ['test.dep'], 'Fake', 'A fake capability'));
        $registry->register(new Capability('test.disabled', label: 'Disabled'));

        $controller = new CapabilityAdminController($registry);
        $resp = $controller->index();

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('capabilities', $body['data']);

        $ids = array_map(fn (array $c) => $c['id'], $body['data']['capabilities']);
        self::assertContains('test.fake', $ids);
        self::assertNotContains('test.disabled', $ids); // disabled capability must be excluded

        $fake = null;
        foreach ($body['data']['capabilities'] as $c) {
            if ($c['id'] === 'test.fake') {
                $fake = $c;
            }
        }
        self::assertNotNull($fake);
        self::assertSame('Fake', $fake['label']);
        self::assertSame('A fake capability', $fake['description']);
        self::assertSame(['test.dep'], $fake['requires']);
    }

    public function testRouteIsAuthenticatedDiscoveryWithoutPermissionGate(): void
    {
        $route = $this->findRoute('GET', '/v1/admin/capabilities');
        self::assertNotNull($route, '/v1/admin/capabilities must be registered');
        $middleware = (array) ($route['middleware'] ?? []);

        // Discovery is deliberately auth-only: a workspace owner with a pack permission
        // (e.g. navigation.manage) but WITHOUT system.access must still discover which
        // modules exist — the previous system.access gate 403'd those users into a
        // permanently empty capability set (hidden nav; reload never fixed it). Feature
        // endpoints keep their own content_permission gates.
        self::assertContains('auth', $middleware, 'capabilities endpoint must stay authenticated');
        foreach ($middleware as $entry) {
            self::assertStringNotContainsString(
                'content_permission:',
                (string) $entry,
                'capabilities endpoint is discovery — it must not carry a permission gate',
            );
        }
    }
}
