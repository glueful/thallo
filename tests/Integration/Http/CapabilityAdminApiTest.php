<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Capabilities\CapabilityStateStore;
use App\Capabilities\DefaultCapabilityRegistry;
use App\Http\Controllers\CapabilityAdminController;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingSystemChannel;
use App\Tests\Support\TestableCapabilityAdminController;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityAvailability;
use Thallo\Contracts\Capability\CapabilityAvailabilityResolver;

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

        $controller = $this->controller($registry);
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

    // ── management surface (schema program Task 7) ────────────────────────────────

    private RecordingSystemChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new RecordingSystemChannel();
    }

    private function controller(DefaultCapabilityRegistry $registry): TestableCapabilityAdminController
    {
        return new TestableCapabilityAdminController(
            $registry,
            new CapabilityStateStore($this->appContext(), $this->channel),
            $this->appContext(),
        );
    }

    /** A resolver whose verdict is scripted per capability id. @param array<string, CapabilityAvailability> $map */
    private function scriptedResolver(array $map): CapabilityAvailabilityResolver
    {
        return new class ($map) implements CapabilityAvailabilityResolver {
            /** @param array<string, CapabilityAvailability> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function resolve(Capability $capability): CapabilityAvailability
            {
                return $this->map[$capability->id] ?? CapabilityAvailability::available();
            }
        };
    }

    /** @param array<string,mixed> $body */
    private function putRequest(array $body): Request
    {
        return Request::create(
            '/v1/admin/capabilities/test.fake',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    public function testManagementRoutesAreOperatorOnly(): void
    {
        foreach ([['GET', '/v1/admin/capabilities/manage'], ['PUT', '/v1/admin/capabilities/{id}']] as [$m, $p]) {
            $route = $this->findRoute($m, $p);
            self::assertNotNull($route, "{$m} {$p} must be registered");
            $middleware = array_map('strval', (array) ($route['middleware'] ?? []));
            self::assertContains('auth', $middleware, "{$m} {$p}");
            self::assertContains('tenant_system', $middleware, "{$m} {$p}");
            self::assertContains('content_permission:system.access', $middleware, "{$m} {$p}");
        }
    }

    public function testManageListsEveryRegisteredCapabilityWithItsStateTriple(): void
    {
        $registry = new DefaultCapabilityRegistry([], $this->scriptedResolver([
            'test.owned' => CapabilityAvailability::unavailable(
                'acme/engine is installed but not enabled.',
                'php glueful extensions:enable acme/engine'
            ),
        ]));
        $registry->register(new Capability('test.fake', label: 'Fake'));
        $registry->register(new Capability('test.owned', label: 'Owned', owningPackage: 'acme/engine'));

        $resp = $this->controller($registry)->manage();

        self::assertSame(200, $resp->getStatusCode());
        $rows = json_decode((string) $resp->getContent(), true)['data']['capabilities'];
        $byId = array_column($rows, null, 'id');
        self::assertCount(2, $rows, 'manage() lists ALL registered capabilities');

        self::assertTrue($byId['test.fake']['requested']);
        self::assertTrue($byId['test.fake']['available']);
        self::assertTrue($byId['test.fake']['effective']);

        self::assertTrue($byId['test.owned']['requested'], 'requested stays on — only availability fails');
        self::assertFalse($byId['test.owned']['available']);
        self::assertFalse($byId['test.owned']['effective']);
        self::assertSame('acme/engine', $byId['test.owned']['owning_package']);
        self::assertStringContainsString('not enabled', (string) $byId['test.owned']['reason']);
        self::assertSame('php glueful extensions:enable acme/engine', $byId['test.owned']['remedy']);
    }

    public function testUpdateRefusesAnUnknownIdWith404AndNeverWritesAKey(): void
    {
        $registry = new DefaultCapabilityRegistry();
        $registry->register(new Capability('test.fake'));

        $resp = $this->controller($registry)->update(
            'test.not-registered',
            new \App\Http\DTOs\UpdateCapabilityStateData(enabled: true)
        );

        self::assertSame(404, $resp->getStatusCode());
        self::assertSame([], $this->channel->puts, 'request text must never become a system key');
    }

    public function testEnableRefuses409WhileTheOwningEngineIsUnavailable(): void
    {
        $registry = new DefaultCapabilityRegistry([], $this->scriptedResolver([
            'test.owned' => CapabilityAvailability::unavailable('engine down', 'fix the engine'),
        ]));
        $registry->register(new Capability('test.owned', owningPackage: 'acme/engine'));
        $controller = $this->controller($registry);

        $resp = $controller->update('test.owned', new \App\Http\DTOs\UpdateCapabilityStateData(enabled: true));

        self::assertSame(409, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        self::assertSame('engine down', $body['error']['details']['reason'] ?? null);
        self::assertSame('fix the engine', $body['error']['details']['remedy'] ?? null);
        self::assertSame([], $this->channel->puts, 'a refused enable persists nothing');
        self::assertSame(0, $controller->routeStatePurges);
    }

    public function testDisableIsAlwaysAllowedEvenWhileUnavailable(): void
    {
        $registry = new DefaultCapabilityRegistry([], $this->scriptedResolver([
            'test.owned' => CapabilityAvailability::unavailable('engine down'),
        ]));
        $registry->register(new Capability('test.owned', owningPackage: 'acme/engine'));
        $controller = $this->controller($registry);

        $resp = $controller->update('test.owned', new \App\Http\DTOs\UpdateCapabilityStateData(enabled: false));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('false', $this->channel->puts['capability.test.owned.enabled'] ?? null);
        // Effectively off before (unavailable) and off after: no route-state purge needed.
        self::assertSame(0, $controller->routeStatePurges);
    }

    public function testAnEffectiveFlipPersistsAndClearsCompiledRouteState(): void
    {
        $registry = new DefaultCapabilityRegistry(
            [],
            $this->scriptedResolver([]),
            static fn (string $id): bool => false, // requested OFF this boot
        );
        $registry->register(new Capability('test.fake'));
        $controller = $this->controller($registry);

        $resp = $controller->update('test.fake', new \App\Http\DTOs\UpdateCapabilityStateData(enabled: true));

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertTrue($data['requested']);
        self::assertTrue($data['effective'], 'available + newly requested = effective on the next boot');
        self::assertSame('true', $this->channel->puts['capability.test.fake.enabled'] ?? null);
        self::assertSame(1, $controller->routeStatePurges, 'an effective flip clears the compiled route state');
    }
}
