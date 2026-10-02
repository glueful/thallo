<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\BlockInsert;
use Thallo\Core\Capabilities\Activation\CapabilityBlockSeeder;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ChildProcesses;

/**
 * A capability's blocks are seeded from its explicit contributions, even while it is off; a block
 * insert that loses a race is skipped inside a savepoint, so the enclosing transaction can still
 * commit; and a workspace seed takes its activation share locks before any write, so it and a
 * finalization can't deadlock (on the single store here; per workspace in the tenancy suite).
 */
final class CapabilityBlockSeedingTest extends AppTestCase
{
    use ChildProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropCommerceBlocks();
    }

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        $this->dropCommerceBlocks();
        $pdo->exec("DELETE FROM block_types WHERE slug IN ('test-uuid-clash', 'test-uuid-owner')");
        $pdo->exec("DELETE FROM capability_activation_events WHERE capability = 'thallo.commerce'");
        $pdo->exec(
            "UPDATE capability_activations SET generation = 0, status = 'idle', steps_done = '[]',
               owner_token = NULL, lease_expires_at = NULL, workspaces = '{}', result = '{}'
             WHERE capability = 'thallo.commerce'"
        );
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key = 'capability.thallo.commerce.enabled'");
        parent::tearDown();
    }

    private function seeder(): CapabilityBlockSeeder
    {
        return $this->container()->get(CapabilityBlockSeeder::class);
    }

    private function states(): CapabilityStateStore
    {
        return $this->container()->get(CapabilityStateStore::class);
    }

    public function testPreparationSeedsTheStoreWhileTheCapabilityIsOff(): void
    {
        $this->states()->put('thallo.commerce', false);
        $created = $this->seeder()->seedAll('thallo.commerce');
        $slugs = array_merge(...array_values($created));
        self::assertContains('product-grid', $slugs);
        self::assertSame(1, $this->countSlug('product-grid'));
    }

    public function testARacingInsertInsideATransactionLeavesItCommittable(): void
    {
        $this->connection()->transaction(function (): void {
            $this->insertRawBlock('product-grid');              // the other runner's row
            $inserted = BlockInsert::ifAbsent($this->connection(), fn () => $this->insertRawBlock('product-grid'));
            self::assertFalse($inserted, 'the existing slug is skipped');
            $this->connection()->getPDO()->exec('SELECT 1');    // not aborted
            $this->states()->put('thallo.commerce', true);      // a later write in the same transaction
        });
        self::assertSame(1, $this->countSlug('product-grid'));
        self::assertTrue($this->states()->fresh('thallo.commerce'), 'the transaction committed');
    }

    public function testAnotherUniqueViolationIsNotTakenForAnExistingSlug(): void
    {
        $uuid = substr(bin2hex(random_bytes(8)), 0, 12);
        $this->connection()->getPDO()->prepare(
            "INSERT INTO block_types (uuid, slug, label, schema, active) VALUES (?, 'test-uuid-owner', 'x', '[]', true)"
        )->execute([$uuid]);
        $this->expectException(\PDOException::class);
        $clash = function () use ($uuid): void {
            $this->connection()->getPDO()->prepare(
                'INSERT INTO block_types (uuid, slug, label, schema, active)'
                . " VALUES (?, 'test-uuid-clash', 'x', '[]', true)"
            )->execute([$uuid]);
        };
        $this->connection()->transaction(fn () => BlockInsert::ifAbsent($this->connection(), $clash));
    }

    public function testAWorkspaceSeedAndAFinalizationInTwoProcessesDoNotDeadlock(): void
    {
        // The workspace seed holds its share lock and has already written a block row when the
        // finalization asks for the row lock: the finalization waits, the seed commits, then the
        // finalization completes, and the block exists once.
        $this->container()->get(ActivationStore::class)->startOrJoin('thallo.commerce', 'test');
        $seed = $this->startChild('workspace_seed_child.php');
        $seed->waitFor('kinds-written');
        $finalizer = $this->startChild('activation_finalize_child.php', ['thallo.commerce']);
        $finalizer->waitFor('waiting-for-row');
        usleep(800_000);
        self::assertFalse($finalizer->isFinished(), 'the finalization waits for the workspace seed');
        $seed->signal('resume');
        $seedOut = $seed->finish();
        $finalOut = $finalizer->finish(30);
        self::assertStringContainsString('committed', $seedOut);
        self::assertStringContainsString('succeeded', $finalOut);
        // Connection::transaction() retries a deadlock victim, so a deadlock would still finish:
        // each side must have run its transaction exactly once.
        self::assertStringContainsString('attempts=1', $seedOut, 'the workspace seed was not a deadlock victim');
        self::assertStringContainsString('attempts=1', $finalOut, 'the finalization was not a deadlock victim');
        self::assertSame(1, $this->countSlug('product-grid'));
        self::assertTrue($this->states()->fresh('thallo.commerce'));
    }

    private function insertRawBlock(string $slug): void
    {
        $this->connection()->getPDO()->prepare(
            "INSERT INTO block_types (uuid, slug, label, schema, active) VALUES (?, ?, 'x', '[]', true)"
        )->execute([substr(bin2hex(random_bytes(8)), 0, 12), $slug]);
    }

    private function countSlug(string $slug): int
    {
        $stmt = $this->connection()->getPDO()->prepare('SELECT count(*) FROM block_types WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int) $stmt->fetchColumn();
    }

    private function dropCommerceBlocks(): void
    {
        $slugs = array_map(
            static fn ($d): string => $d->definitionKey,
            $this->container()->get(BlockTypeKind::class)->contributionsFor('thallo.commerce'),
        );
        if ($slugs === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($slugs), '?'));
        $this->connection()->getPDO()->prepare("DELETE FROM block_types WHERE slug IN ({$in})")->execute($slugs);
    }
}
