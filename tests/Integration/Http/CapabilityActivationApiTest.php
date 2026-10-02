<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Core\Capabilities\Activation\ActivationRunner;
use Thallo\Core\Capabilities\Activation\ActivationStatus;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\EngineActivation;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\CapabilityStateVersion;
use Thallo\Core\Http\Controllers\CapabilityActivationController;
use Thallo\Core\Http\DTOs\ActivationGenerationData;
use Thallo\Core\Http\DTOs\UpdateCapabilityStateData;
use Thallo\Core\Tests\Support\ActivationRunners;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ResetsCommerceActivation;
use Thallo\Core\Tests\Support\RestoresPermissionRows;
use Thallo\Core\Tests\Support\TestableCapabilityAdminController;

/**
 * The activation API (feature activation spec §3.2–3.3): start prepares and asks for a continue
 * when the next step needs a fresh boot; continue resumes in that fresh request; cancel and turning
 * off supersede the operation and publish the capability off in one write; and the switchboard's
 * own update can't turn an activation capability on directly.
 */
final class CapabilityActivationApiTest extends AppTestCase
{
    use ActivationRunners;
    use ResetsCommerceActivation;
    use RestoresPermissionRows;

    private const ID = 'thallo.commerce';

    private static ?ContainerInterface $fresh = null;
    private static ?ContainerInterface $withoutCommerce = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPermissionRows();
        $this->resetCommerceActivation();
    }

    protected function tearDown(): void
    {
        $this->resetCommerceActivation();
        $this->restorePermissionRows();
        $this->removeActivationTempFiles();
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private function store(): ActivationStore
    {
        return $this->container()->get(ActivationStore::class);
    }

    private function states(): CapabilityStateStore
    {
        return $this->container()->get(CapabilityStateStore::class);
    }

    /** A request handled by an application booted now, after the engine step (as the admin's continue is). */
    private static function freshContainer(): ContainerInterface
    {
        if (self::$fresh === null) {
            /** @var array{enabled: list<string>} $testing */
            $testing = require dirname(__DIR__, 3) . '/config/testing/extensions.php';
            self::$fresh = self::bootAppWithConfigOverride('extensions', ['enabled' => $testing['enabled']])
                ->getContainer();
        }
        return self::$fresh;
    }

    /** An application booted without the Commerce provider: the engine disabled outside Thallo. */
    private static function containerWithoutCommerce(): ContainerInterface
    {
        if (self::$withoutCommerce === null) {
            /** @var array{enabled: list<string>} $testing */
            $testing = require dirname(__DIR__, 3) . '/config/testing/extensions.php';
            $enabled = array_values(array_diff($testing['enabled'], [self::COMMERCE_PROVIDER]));
            self::$withoutCommerce = self::bootAppWithConfigOverride('extensions', ['enabled' => $enabled])
                ->getContainer();
        }
        return self::$withoutCommerce;
    }

    private function controller(
        ?EngineActivation $engine = null,
        ?ContainerInterface $container = null,
    ): CapabilityActivationController {
        $container ??= $this->container();
        return new CapabilityActivationController(
            $container->get(ApplicationContext::class),
            $container->get(ActivationStore::class),
            $this->runner($engine ?? $this->engine(container: $container), $container),
        );
    }

    private function capabilityAdmin(?ContainerInterface $container = null): TestableCapabilityAdminController
    {
        $container ??= $this->container();
        return new TestableCapabilityAdminController(
            $container->get(CapabilityRegistry::class),
            $container->get(CapabilityStateStore::class),
            $container->get(ApplicationContext::class),
        );
    }

    private function request(string $method, ?array $body = null): Request
    {
        return $this->jsonRequest($method, '/v1/admin/capabilities/' . self::ID . '/activation', $body);
    }

    /** @return array<string, mixed> success `data`, or an error's `error.details` */
    private function payload(Response $response): array
    {
        $json = json_decode((string) $response->getContent(), true);
        if (is_array($json['data'] ?? null)) {
            return $json['data'];
        }
        return is_array($json['error']['details'] ?? null) ? $json['error']['details'] : [];
    }

    private function start(?CapabilityActivationController $controller = null): Response
    {
        return ($controller ?? $this->controller())->start($this->request('POST'), self::ID);
    }

    private function continueAt(int $generation, ?CapabilityActivationController $controller = null): Response
    {
        $request = $this->request('POST', ['generation' => $generation]);
        return ($controller ?? $this->controller(container: self::freshContainer()))
            ->continue(new ActivationGenerationData($generation), $request, self::ID);
    }

    // ── start and continue ───────────────────────────────────────────────────────

    public function testStartAsksForAContinue(): void
    {
        $response = $this->start();
        self::assertSame(202, $response->getStatusCode());
        $data = $this->payload($response);
        self::assertTrue($data['continue']);
        self::assertSame(1, $data['activation']['generation']);
        self::assertSame(ActivationStatus::PREPARING, $data['activation']['status']);
        self::assertArrayNotHasKey('owner_token', $data['activation']);
    }

    public function testContinueFinishesInAFreshRequest(): void
    {
        $gen = $this->payload($this->start())['activation']['generation'];
        $response = $this->continueAt($gen);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->payload($response);
        self::assertFalse($data['continue']);
        self::assertSame(ActivationStatus::SUCCEEDED, $data['activation']['status']);
        self::assertTrue($this->states()->fresh(self::ID));
    }

    public function testContinueAfterARetriedEngineStepAsksForAnotherContinue(): void
    {
        $failed = $this->payload($this->start($this->controller($this->engine([], writable: false))));
        self::assertSame(ActivationStatus::FAILED, $failed['activation']['status']);
        self::assertFalse($failed['continue']);

        $retry = $this->controller($this->engine([]), self::freshContainer());
        $data = $this->payload($this->continueAt($failed['activation']['generation'], $retry));
        self::assertTrue($data['continue'], 'the retried engine step needs another fresh boot');
        self::assertFalse($this->states()->fresh(self::ID));
    }

    public function testAContinueForASupersededGenerationIsRefused(): void
    {
        $gen = $this->payload($this->start())['activation']['generation'];
        $this->store()->supersede(self::ID, 'other-operator');
        $response = $this->continueAt($gen);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('superseded', $this->payload($response)['reason']);
        self::assertFalse($this->states()->fresh(self::ID));
    }

    public function testAContinueWhileAnotherRunnerHoldsTheLeaseIsInProgress(): void
    {
        $gen = $this->payload($this->start())['activation']['generation'];
        self::assertNotNull($this->store()->acquire(self::ID, $gen));
        $response = $this->continueAt($gen);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('in_progress', $this->payload($response)['reason']);
    }

    public function testTwoStartsReturnTheSameGeneration(): void
    {
        $a = $this->payload($this->start())['activation']['generation'];
        $b = $this->payload($this->start())['activation']['generation'];
        self::assertSame($a, $b);
    }

    // ── cancel and turning off ─────────────────────────────────────────────────────

    public function testADelayedCancelOfAnOldGenerationLeavesTheCurrentOneOn(): void
    {
        $gen1 = $this->payload($this->start())['activation']['generation'];
        $this->store()->supersede(self::ID, 'off');
        $gen3 = $this->payload($this->start())['activation']['generation'];
        $this->continueAt($gen3);
        self::assertTrue($this->states()->fresh(self::ID));

        $response = $this->controller()->cancel(
            new ActivationGenerationData($gen1),
            $this->request('DELETE', ['generation' => $gen1]),
            self::ID,
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('superseded', $this->payload($response)['reason']);
        self::assertTrue($this->states()->fresh(self::ID), 'still on');
        self::assertSame($gen3, $this->store()->find(self::ID)->generation);
    }

    public function testCancellingTheOpenGenerationTurnsItOff(): void
    {
        $gen = $this->payload($this->start())['activation']['generation'];
        $response = $this->controller()->cancel(
            new ActivationGenerationData($gen),
            $this->request('DELETE', ['generation' => $gen]),
            self::ID,
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ActivationStatus::SUPERSEDED, $this->payload($response)['activation']['status']);
        self::assertFalse($this->states()->fresh(self::ID));
    }

    public function testTheUpdateEndpointCannotTurnAnActivationCapabilityOnDirectly(): void
    {
        $response = $this->capabilityAdmin()->update(self::ID, new UpdateCapabilityStateData(true));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('use_activation', $this->payload($response)['reason']);
        self::assertNull($this->states()->fresh(self::ID), 'nothing written');
    }

    public function testTurningOffPublishesOffAtomicallyAndAdvancesTheVersion(): void
    {
        $gen = $this->payload($this->start())['activation']['generation'];
        $this->continueAt($gen);
        self::assertTrue($this->states()->fresh(self::ID));
        $version = (int) $this->container()->get(CapabilityStateVersion::class)->current();

        $admin = $this->capabilityAdmin();
        $response = $admin->update(self::ID, new UpdateCapabilityStateData(false));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->states()->fresh(self::ID));
        self::assertSame($version + 1, (int) $this->container()->get(CapabilityStateVersion::class)->current());
        $record = $this->store()->find(self::ID);
        self::assertSame(ActivationStatus::SUPERSEDED, $record->status);
        self::assertSame($gen + 1, $record->generation);
        self::assertSame(1, $admin->routeStatePurges);
    }

    // ── the management view ──────────────────────────────────────────────────────

    public function testManageReportsHowEachCapabilityIsSwitched(): void
    {
        $rows = array_column($this->payload($this->capabilityAdmin()->manage())['capabilities'], null, 'id');
        self::assertSame('activation', $rows[self::ID]['management']);
        self::assertNull($rows[self::ID]['activation']);
        self::assertTrue($rows[self::ID]['engine_enabled']);
        self::assertIsBool($rows[self::ID]['application_files_writable']);
        self::assertSame('simple', $rows['thallo.search']['management']);
        self::assertNull($rows['thallo.search']['engine_enabled']);

        $gen = $this->payload($this->start())['activation']['generation'];
        $rows = array_column($this->payload($this->capabilityAdmin()->manage())['capabilities'], null, 'id');
        self::assertSame($gen, $rows[self::ID]['activation']['generation']);
    }

    public function testAnEngineDisabledOutsideThalloShowsUnavailable(): void
    {
        $rows = array_column(
            $this->payload($this->capabilityAdmin(self::containerWithoutCommerce())->manage())['capabilities'],
            null,
            'id',
        );
        self::assertFalse($rows[self::ID]['available']);
        self::assertFalse($rows[self::ID]['engine_enabled']);
        self::assertSame('activation', $rows[self::ID]['management'], 'turning it on runs a normal activation');
        self::assertNotNull($rows[self::ID]['reason']);
    }

    public function testTheActivationRoutesAreOperatorOnly(): void
    {
        $routes = [
            ['POST', '/v1/admin/capabilities/{id}/activation'],
            ['POST', '/v1/admin/capabilities/{id}/activation/continue'],
            ['DELETE', '/v1/admin/capabilities/{id}/activation'],
        ];
        foreach ($routes as [$method, $path]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path}");
            self::assertContains('content_permission:system.access', (array) ($route['middleware'] ?? []), $path);
        }
    }
}
