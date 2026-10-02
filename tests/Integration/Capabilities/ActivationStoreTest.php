<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Core\Capabilities\Activation\ActivationStatus;
use Thallo\Core\Capabilities\Activation\ActivationStep;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\ActivationSuperseded;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ChildProcesses;

/**
 * The activation store: one row per capability is the coordination point. Starting a generation,
 * superseding it, and every runner write happen in a transaction holding the row lock; a runner's
 * writes are fenced on generation and lease-owner token, so a stale runner can neither advance the
 * operation nor change the capability.
 */
final class ActivationStoreTest extends AppTestCase
{
    use ChildProcesses;

    private function store(): ActivationStore
    {
        return $this->container()->get(ActivationStore::class);
    }

    private function states(): CapabilityStateStore
    {
        return $this->container()->get(CapabilityStateStore::class);
    }

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM capability_activation_events WHERE capability LIKE 'test.%'");
        $pdo->exec("DELETE FROM capability_activations WHERE capability LIKE 'test.%'");
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key LIKE 'capability.test.%'");
        parent::tearDown();
    }

    public function testStartingPublishesTheCapabilityOffInTheSameTransaction(): void
    {
        $this->states()->put('test.shop', true);
        $record = $this->store()->startOrJoin('test.shop', 'op');
        self::assertSame(1, $record->generation);
        self::assertSame(ActivationStatus::PREPARING, $record->status);
        self::assertContains(ActivationStep::MARK_PREPARING, $record->stepsDone);
        self::assertSame(ActivationStep::ENABLE_ENGINE, $record->nextStep());
        self::assertFalse($this->states()->fresh('test.shop'));
    }

    public function testASecondStartJoins(): void
    {
        $a = $this->store()->startOrJoin('test.shop', 'a');
        self::assertSame($a->generation, $this->store()->startOrJoin('test.shop', 'b')->generation);
    }

    public function testSupersedingPublishesOffAndRefusesTheOldRunner(): void
    {
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen);
        self::assertNotNull($lease);
        self::assertSame($gen + 1, $this->store()->supersede('test.shop', 'off'));
        self::assertFalse($this->states()->fresh('test.shop'));
        self::assertSame(ActivationStatus::SUPERSEDED, $this->store()->find('test.shop')->status);
        $this->expectException(ActivationSuperseded::class);
        $this->store()->withinFenced($lease, fn () => $this->states()->put('test.shop', true));
    }

    public function testACancelForAStaleGenerationChangesNothing(): void
    {
        $gen1 = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $this->store()->supersede('test.shop', 'off');
        $gen3 = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen3);
        $this->store()->withinFenced($lease, fn () => $this->states()->put('test.shop', true));
        try {
            $this->store()->supersede('test.shop', 'late-cancel', expectedGeneration: $gen1);
            self::fail('a stale cancel superseded the current generation');
        } catch (ActivationSuperseded) {
        }
        self::assertSame($gen3, $this->store()->find('test.shop')->generation);
        self::assertTrue($this->states()->fresh('test.shop'));
    }

    public function testAPausedOldRunnerCannotDisableANewerSuccessfulActivation(): void
    {
        // generation 1's runner pauses; it is superseded; generation 3 finishes on; runner 1 resumes
        $gen1 = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $old = $this->store()->acquire('test.shop', $gen1);
        $this->store()->supersede('test.shop', 'off');
        $gen3 = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $new = $this->store()->acquire('test.shop', $gen3);
        $this->store()->withinFenced($new, fn () => $this->states()->put('test.shop', true));
        try {
            $this->store()->withinFenced($old, fn () => $this->states()->put('test.shop', false));
            self::fail('the old runner wrote');
        } catch (ActivationSuperseded) {
        }
        self::assertTrue($this->states()->fresh('test.shop'), 'still on');
    }

    public function testAnExpiredLeaseIsTakenOverAndTheOldOwnerIsFenced(): void
    {
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $a = $this->store()->acquire('test.shop', $gen);
        self::assertNotNull($a);
        self::assertNull($this->store()->acquire('test.shop', $gen), 'a live lease is exclusive');
        $this->expireLease('test.shop');
        $b = $this->store()->acquire('test.shop', $gen);
        self::assertNotNull($b);
        $this->store()->completeStep($b, ActivationStep::ENABLE_ENGINE);
        $this->expectException(ActivationSuperseded::class);
        $this->store()->completeStep($a, ActivationStep::ENABLE_ENGINE);
    }

    public function testASlowStepWhoseLeaseLapsedWithoutATakeoverStillRecords(): void
    {
        // A step that outlives its lease (a long migration) is not superseded: nobody took the
        // operation over, so its owner token still holds and its record lands.
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen);
        self::assertNotNull($lease);
        $this->expireLease('test.shop');
        $record = $this->store()->completeStep($lease, ActivationStep::ENABLE_ENGINE);
        self::assertContains(ActivationStep::ENABLE_ENGINE, $record->stepsDone);
        self::assertNull($this->store()->acquire('test.shop', $gen), 'the write renewed the lease');
    }

    public function testAFailedStepIsRecordedAndTheOperationStaysOpen(): void
    {
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen);
        $record = $this->store()->failStep($lease, ActivationStep::ENABLE_ENGINE, 'boom', 'php glueful x');
        self::assertSame(ActivationStatus::FAILED, $record->status);
        self::assertSame(ActivationStep::ENABLE_ENGINE, $record->failedStep);
        self::assertSame('php glueful x', $record->remedy);
        self::assertTrue($record->isOpen());
        self::assertSame($gen, $this->store()->startOrJoin('test.shop', 'retry')->generation, 'a retry joins');
    }

    public function testALostResponseIsHarmless(): void
    {
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen);
        $this->store()->completeStep($lease, ActivationStep::ENABLE_ENGINE);
        $this->store()->release($lease);
        self::assertSame(ActivationStep::VERIFY_BOOT, $this->store()->find('test.shop')->nextStep());
        self::assertNotNull($this->store()->acquire('test.shop', $gen), 'released: the next runner takes it');
    }

    public function testWorkspacesAreRecordedAndDropped(): void
    {
        $gen = $this->store()->startOrJoin('test.shop', 'op')->generation;
        $lease = $this->store()->acquire('test.shop', $gen);
        $this->store()->markWorkspace($lease, 'tenant000001', 'ready');
        $this->store()->markWorkspace($lease, 'tenant000002', 'failed');
        $this->store()->markWorkspace($lease, 'tenant000002', null);
        self::assertSame(['tenant000001' => 'ready'], $this->store()->find('test.shop')->workspaces);
    }

    public function testAPausedRunnerProcessResumingAfterTakeoverIsRefused(): void
    {
        $this->store()->startOrJoin('test.shop', 'op');
        $child = $this->startChild('activation_paused_runner_child.php', ['test.shop']);
        $child->waitFor('acquired');
        $this->expireLease('test.shop');
        $b = $this->store()->acquire('test.shop', $this->store()->find('test.shop')->generation);
        self::assertNotNull($b);
        $this->store()->completeStep($b, ActivationStep::ENABLE_ENGINE);
        $child->signal('resume');
        self::assertStringContainsString('superseded', $child->finish());
    }

    public function testSimultaneousStartsInTwoProcessesMakeOneOperation(): void
    {
        self::assertSame(
            ['1', '1'],
            $this->runChildren('activation_start_race_child.php', [['test.race'], ['test.race']]),
        );
    }

    public function testShareAllLocksEveryActivationRowInOrderAndReadsFreshState(): void
    {
        $states = $this->connection()->transaction(fn (): array => $this->store()->shareAll());
        self::assertSame(['thallo.commerce', 'thallo.subscriptions'], array_keys($states));
        self::assertArrayHasKey('on', $states['thallo.commerce']);
        self::assertArrayHasKey('preparing', $states['thallo.commerce']);
    }

    private function expireLease(string $capability): void
    {
        $this->connection()->getPDO()->prepare(
            "UPDATE capability_activations SET lease_expires_at = NOW() - INTERVAL '1 minute' WHERE capability = ?"
        )->execute([$capability]);
    }
}
