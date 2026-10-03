<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Core\Tests\Support\CapabilityBaseline;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Capabilities\Activation\ActivationRowMissing;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\CapabilityBlockSeeder;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\Console\CapabilitiesStatusCommand;
use Thallo\Core\Http\Controllers\CapabilityActivationController;
use Thallo\Core\Setup\CapabilityProvisioning;
use Thallo\Core\Tests\Support\ActivationRunners;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ChildProcesses;
use Thallo\Core\Tests\Support\ResetsCommerceActivation;
use Thallo\Core\Tests\Support\TestableCapabilityAdminController;

/**
 * Activation rows exist before a capability is actionable (spec §7.5): one initializer creates them,
 * in its own committed transaction, after any workspace seed in flight; locking a missing row never
 * inserts one; and turning off a capability with no row holds the same lock, so no runner can start
 * in between. Reads never write rows.
 */
final class ActivationRowInitializationTest extends AppTestCase
{
    use ActivationRunners;
    use ChildProcesses;
    use ResetsCommerceActivation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetCommerceActivation();
    }

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM capability_activation_events WHERE capability LIKE 'test.%'");
        $pdo->exec("DELETE FROM capability_activations WHERE capability LIKE 'test.%'");
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key LIKE 'capability.test.%'");
        foreach (['thallo.commerce', 'thallo.subscriptions'] as $id) {
            $this->store()->initializeRow($id);
        }
        $this->resetCommerceActivation();
        $this->removeActivationTempFiles();
        CapabilityBaseline::restore($this->connection()->getPDO());
        parent::tearDown();
    }

    private function store(): ActivationStore
    {
        return $this->container()->get(ActivationStore::class);
    }

    private function rowExists(string $capability): bool
    {
        $stmt = $this->connection()->getPDO()
            ->prepare('SELECT count(*) FROM capability_activations WHERE capability = ?');
        $stmt->execute([$capability]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function dropRow(string $capability): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->prepare('DELETE FROM capability_activation_events WHERE capability = ?')->execute([$capability]);
        $pdo->prepare('DELETE FROM capability_activations WHERE capability = ?')->execute([$capability]);
    }

    private function anAdvisoryLockWaiterAppears(int $timeoutSeconds = 15): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $waiting = (int) $this->connection()->getPDO()
                ->query("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted")
                ->fetchColumn();
            if ($waiting > 0) {
                return true;
            }
            usleep(50_000);
        }
        return false;
    }

    public function testAWorkspaceSeedInFlightFinishesBeforeTheRowIsCreated(): void
    {
        $seed = $this->startChild('workspace_seed_child.php');
        $seed->waitFor('kinds-written');                        // holds the shared workspace-seed lock
        $init = $this->startChild('row_init_child.php', ['test.newcap']);
        self::assertTrue($this->anAdvisoryLockWaiterAppears(), 'the initializer waits for the seed in flight');
        self::assertFalse($init->isFinished());
        self::assertFalse($this->rowExists('test.newcap'));
        $seed->signal('resume');
        self::assertStringContainsString('attempts=1', $seed->finish());
        self::assertStringContainsString('initialized', $init->finish());
        self::assertTrue($this->rowExists('test.newcap'));
    }

    public function testASeedStartedAfterTheRowExistsSeedsAPreparingCapability(): void
    {
        $this->store()->startOrJoin('thallo.commerce', 'test');     // its row exists, now preparing
        $seeder = $this->container()->get(CapabilityBlockSeeder::class);
        $noKinds = static function (): void {
        };
        $this->connection()->transaction(static fn () => $seeder->withinWorkspaceSeed('single', $noKinds));
        $count = (int) $this->connection()->getPDO()
            ->query("SELECT count(*) FROM block_types WHERE slug = 'product-grid'")->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testTheFirstTurnOnCommitsItsRowThenStarts(): void
    {
        $this->dropRow('thallo.commerce');
        $controller = new CapabilityActivationController(
            $this->appContext(),
            $this->store(),
            $this->runner(),
        );
        $request = $this->jsonRequest('POST', '/v1/admin/capabilities/thallo.commerce/activation');
        $response = $controller->start($request, 'thallo.commerce');
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, $this->store()->find('thallo.commerce')->generation);
    }

    public function testReadsWriteNoRow(): void
    {
        $this->dropRow('thallo.commerce');
        $admin = new TestableCapabilityAdminController(
            $this->container()->get(\Thallo\Contracts\Capability\CapabilityRegistry::class),
            $this->container()->get(CapabilityStateStore::class),
            $this->appContext(),
        );
        $rows = json_decode((string) $admin->manage()->getContent(), true)['data']['capabilities'];
        self::assertNull(array_column($rows, null, 'id')['thallo.commerce']['activation']);
        (new CommandTester($this->container()->get(CapabilitiesStatusCommand::class)))->execute([]);
        self::assertFalse($this->rowExists('thallo.commerce'));
    }

    public function testProvisionSyncsRowsForEveryDeclaredActivationCapability(): void
    {
        $this->dropRow('thallo.commerce');
        $this->dropRow('thallo.subscriptions');
        $synced = $this->container()->get(CapabilityProvisioning::class)->syncRows();
        self::assertSame(['thallo.commerce', 'thallo.subscriptions'], $synced);
        self::assertTrue($this->rowExists('thallo.commerce'));
        self::assertTrue($this->rowExists('thallo.subscriptions'));
    }

    public function testLockingAMissingRowThrowsInsteadOfInserting(): void
    {
        try {
            $this->store()->startOrJoin('test.none', 'test');
            self::fail('a missing row was locked');
        } catch (ActivationRowMissing) {
        }
        self::assertFalse($this->rowExists('test.none'));
    }

    public function testInitializeRowRefusesAnOpenTransaction(): void
    {
        try {
            $this->connection()->transaction(fn () => $this->store()->initializeRow('test.inside'));
            self::fail('a row was initialized inside a transaction');
        } catch (\LogicException) {
        }
        self::assertFalse($this->rowExists('test.inside'));
    }

    public function testSupersedingACapabilityWithNoRowPublishesOff(): void
    {
        $states = $this->container()->get(CapabilityStateStore::class);
        $states->put('test.norow', true);
        self::assertSame(0, $this->store()->supersede('test.norow', 'test'));
        self::assertFalse($states->fresh('test.norow'));
        self::assertFalse($this->rowExists('test.norow'));
    }

    public function testAFirstStartWaitsForATurnOffInFlight(): void
    {
        $off = $this->startChild('turn_off_child.php', ['test.firststart']);
        $off->waitFor('absence-checked');                       // holds the workspace-seed lock, shared
        $start = $this->startChild('row_init_child.php', ['test.firststart', '--start']);
        self::assertTrue($this->anAdvisoryLockWaiterAppears(), 'the first start waits for the turn-off');
        self::assertFalse($start->isFinished());
        self::assertFalse($this->rowExists('test.firststart'), 'no row, so no runner, before the off write');
        $off->signal('resume');
        self::assertStringContainsString('superseded=0', $off->finish());
        $out = $start->finish();
        self::assertStringContainsString('generation=1', $out, 'the activation began after the off decision');
        self::assertSame('preparing', $this->store()->find('test.firststart')->status);
        self::assertFalse($this->container()->get(CapabilityStateStore::class)->fresh('test.firststart'));
    }
}
