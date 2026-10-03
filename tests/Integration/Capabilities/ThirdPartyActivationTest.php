<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EnabledProviders;
use Glueful\Http\Response;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Capabilities\Activation\ActivationStatus;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\EngineActivation;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Core\Http\Controllers\CapabilityActivationController;
use Thallo\Core\Http\DTOs\ActivationGenerationData;
use Thallo\Core\Http\DTOs\UpdateCapabilityStateData;
use Thallo\Core\Setup\InstallRoleGrants;
use Thallo\Core\Tests\Support\ActivationRunners;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\CapabilityBaseline;
use Thallo\Core\Tests\Support\RestoresPermissionRows;
use Thallo\Core\Tests\Support\TestableCapabilityAdminController;

/**
 * A third-party extension activates end to end (feature activation spec §7): acme/bookings, a real
 * composer package installed from tests/fixtures/packages/acme-bookings, declares `acme.bookings` in
 * its composer.json and knows Thallo only through thallo-contracts. Its engine starts disabled; the
 * turn-on creates its activation row, prepares the engine (its migration, its place in the enabled
 * list — a temporary list here), and the continue, in an application booted with the engine, seeds
 * its block and grants its permission. Turning off hides it and keeps the engine and its data.
 */
final class ThirdPartyActivationTest extends AppTestCase
{
    use ActivationRunners;
    use RestoresPermissionRows;

    private const ID = 'acme.bookings';
    private const PACKAGE = 'acme/bookings';
    private const PROVIDER = 'Acme\\Bookings\\BookingsServiceProvider';
    private const BLOCK = 'booking_form';
    private const PERMISSION = 'bookings.manage';

    private ?string $ledgerBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPermissionRows();
        $this->ledgerBefore = $this->channel()->get(InstallRoleGrants::LEDGER_KEY);
        $this->resetBookings();
    }

    protected function tearDown(): void
    {
        $this->resetBookings();
        $this->restorePermissionRows();
        $flags = $this->channel();
        $this->ledgerBefore === null
            ? $flags->forget(InstallRoleGrants::LEDGER_KEY)
            : $flags->put(InstallRoleGrants::LEDGER_KEY, $this->ledgerBefore);
        $this->removeActivationTempFiles();
        CapabilityBaseline::restore($this->connection()->getPDO());
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    /** No row, no switch, no block, no permission, no table and no migration receipts. */
    private function resetBookings(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->prepare('DELETE FROM block_types WHERE slug = ?')->execute([self::BLOCK]);
        $pdo->prepare('DELETE FROM capability_activation_events WHERE capability = ?')->execute([self::ID]);
        $pdo->prepare('DELETE FROM capability_activations WHERE capability = ?')->execute([self::ID]);
        $pdo->prepare('DELETE FROM thallo_system_flags WHERE key = ?')
            ->execute([CapabilityStateStore::PREFIX . self::ID . '.enabled']);
        $pdo->prepare(
            'DELETE FROM role_permissions WHERE permission_uuid IN (SELECT uuid FROM permissions WHERE slug = ?)'
        )->execute([self::PERMISSION]);
        $pdo->prepare('DELETE FROM permissions WHERE slug = ?')->execute([self::PERMISSION]);
        $pdo->exec('DROP TABLE IF EXISTS acme_bookings');
        $pdo->prepare('DELETE FROM migrations WHERE source = ?')->execute([self::PACKAGE]);
    }

    /**
     * A request handled by an application booted now with the Bookings engine enabled, as the
     * continue's fresh request is (a capability's state is read once per request).
     */
    private static function withEngine(): ContainerInterface
    {
        /** @var array{enabled: list<string>} $testing */
        $testing = require dirname(__DIR__, 3) . '/config/testing/extensions.php';
        return self::bootAppWithConfigOverride(
            'extensions',
            ['enabled' => [...$testing['enabled'], self::PROVIDER]],
        )->getContainer();
    }

    private function channel(): SystemChannel
    {
        return $this->container()->get(SystemChannel::class);
    }

    private function store(): ActivationStore
    {
        return $this->container()->get(ActivationStore::class);
    }

    private function states(): CapabilityStateStore
    {
        return $this->container()->get(CapabilityStateStore::class);
    }

    private function controller(
        EngineActivation $engine,
        ?ContainerInterface $container = null,
    ): CapabilityActivationController {
        $container ??= $this->container();
        return new CapabilityActivationController(
            $container->get(ApplicationContext::class),
            $container->get(ActivationStore::class),
            $this->runner($engine, $container),
        );
    }

    private function request(?array $body = null): Request
    {
        return $this->jsonRequest('POST', '/v1/admin/capabilities/' . self::ID . '/activation', $body);
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $json = json_decode((string) $response->getContent(), true);
        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /** @return array<string, mixed> the capability as the Extensions page reads it */
    private function managed(): array
    {
        $admin = new TestableCapabilityAdminController(
            $this->container()->get(CapabilityRegistry::class),
            $this->container()->get(CapabilityStateStore::class),
            $this->container()->get(ApplicationContext::class),
        );
        foreach ($this->payload($admin->manage())['capabilities'] as $capability) {
            if ($capability['id'] === self::ID) {
                return $capability;
            }
        }
        self::fail('acme.bookings is not on the Extensions page');
    }

    /** Turns it on: start in this process, continue in an application booted with the engine. */
    private function turnOn(): void
    {
        $engine = $this->engine([], container: $this->container());
        $started = $this->payload($this->controller($engine)->start($this->request(), self::ID));
        $generation = (int) $started['activation']['generation'];

        $request = self::withEngine();
        $listed = $this->engine([self::PROVIDER], container: $request);
        $continued = $this->controller($listed, $request)->continue(
            new ActivationGenerationData($generation),
            $this->request(['generation' => $generation]),
            self::ID,
        );
        self::assertSame(ActivationStatus::SUCCEEDED, $this->payload($continued)['activation']['status']);
    }

    private function blockExists(): bool
    {
        $stmt = $this->connection()->getPDO()->prepare('SELECT 1 FROM block_types WHERE slug = ?');
        $stmt->execute([self::BLOCK]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<string> the roles holding the Bookings permission */
    private function rolesGranted(): array
    {
        $stmt = $this->connection()->getPDO()->prepare(
            'SELECT r.slug FROM role_permissions rp JOIN roles r ON r.uuid = rp.role_uuid
               JOIN permissions p ON p.uuid = rp.permission_uuid WHERE p.slug = ? ORDER BY r.slug'
        );
        $stmt->execute([self::PERMISSION]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function tableExists(): bool
    {
        return $this->connection()->getPDO()->query("SELECT to_regclass('acme_bookings')")->fetchColumn() !== null;
    }

    // ── the proof ───────────────────────────────────────────────────────────────

    public function testItAppearsAndIsActionableWhileItsEngineIsDisabled(): void
    {
        $capability = $this->managed();
        self::assertSame('activation', $capability['management']);
        self::assertSame('Bookings', $capability['label']);
        self::assertSame(self::PACKAGE, $capability['owning_package']);
        self::assertFalse($capability['engine_enabled']);
        self::assertFalse($capability['available'], 'off until its activation');
        self::assertSame('Block types', $capability['copy']['links'][0]['label']);
        self::assertNull($this->store()->find(self::ID), 'no row until the first turn-on');
    }

    public function testTheFirstTurnOnSyncsItsRowThenPreparesTheEngine(): void
    {
        $engine = $this->engine([]);
        $response = $this->controller($engine)->start($this->request(), self::ID);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue($this->payload($response)['continue'], 'the next step needs a boot with the engine');

        self::assertNotNull($this->store()->find(self::ID), 'the turn-on created the row');
        self::assertTrue($engine->isListed(self::PROVIDER), 'the engine is in the enabled list');
        self::assertTrue($this->tableExists(), 'its migration ran');
        self::assertFalse((bool) $this->states()->fresh(self::ID), 'still off while preparing');
    }

    public function testTheActivationSeedsTheEnginesBlocksAndGrantsItsPermission(): void
    {
        $this->turnOn();
        self::assertTrue($this->states()->fresh(self::ID));
        self::assertTrue($this->blockExists(), 'its block was seeded');
        self::assertSame(['administrator', 'superuser'], $this->rolesGranted());
        self::assertTrue(self::withEngine()->get(CapabilityRegistry::class)->isEnabled(self::ID));
    }

    public function testAnEngineAlreadyEnabledStillReadsOffUntilItsActivation(): void
    {
        $request = self::withEngine();
        self::assertFalse(
            $request->get(CapabilityRegistry::class)->isEnabled(self::ID),
            'enabling the engine elsewhere does not turn it on',
        );
        self::assertContains(self::BLOCK, $request->get(BlockTypeKind::class)->hiddenSlugs(), 'its block is hidden');
    }

    public function testProvisionWithholdsItsPermissionUntilItsActivation(): void
    {
        // Provision's role grants run in an application where the engine is enabled but the
        // capability was never turned on: the permission is synced, not granted, and not recorded
        // as offered — so the activation still grants it.
        self::withEngine()->get(InstallRoleGrants::class)->apply();
        self::assertSame([], $this->rolesGranted(), 'nothing granted before its activation');

        $this->turnOn();
        self::assertSame(['administrator', 'superuser'], $this->rolesGranted());
    }

    public function testTurningOffHidesItAndKeepsTheEngineAndItsData(): void
    {
        $this->turnOn();
        $this->connection()->getPDO()->exec("INSERT INTO acme_bookings (name) VALUES ('Ama, Tuesday 10:00')");

        $request = self::withEngine();
        $admin = new TestableCapabilityAdminController(
            $request->get(CapabilityRegistry::class),
            $request->get(CapabilityStateStore::class),
            $request->get(ApplicationContext::class),
        );
        self::assertSame(200, $admin->update(self::ID, new UpdateCapabilityStateData(false))->getStatusCode());

        self::assertFalse($this->states()->fresh(self::ID));
        self::assertTrue($this->blockExists(), 'the block row is kept');
        $after = self::withEngine();
        self::assertContains(self::BLOCK, $after->get(BlockTypeKind::class)->hiddenSlugs(), 'its block is hidden');
        self::assertSame(
            1,
            (int) $this->connection()->getPDO()->query('SELECT count(*) FROM acme_bookings')->fetchColumn(),
            'its data is kept',
        );
        $enabled = EnabledProviders::from($after->get(ApplicationContext::class));
        self::assertContains(self::PROVIDER, $enabled, 'the engine stays enabled');
    }
}
