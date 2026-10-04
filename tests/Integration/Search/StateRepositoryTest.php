<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\FixedClock;
use Thallo\Core\Tests\Support\Search\RowLockHolder;
use Thallo\Search\Lifecycle\DatabaseClock;
use Thallo\Search\Lifecycle\Fence;
use Thallo\Search\Lifecycle\StaleFence;
use Thallo\Search\Lifecycle\StateRepository;

/**
 * The only writer of the search index's per-kind state (search block spec §3.5.1–§3.5.5). Every
 * lease decision takes the row lock first, then reads the database's current time and the row, then
 * decides — so neither a long transaction nor a wait for the lock lets an expired holder through.
 */
final class StateRepositoryTest extends AppTestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock();
    }

    public function testOneClaimAtATimeAndAnExpiredLeaseCanBeTakenWithANewGeneration(): void
    {
        $repo = $this->repo();
        $a = $repo->claimBuild('entries', 60, 5, 1);
        self::assertNotNull($a);
        self::assertNull($repo->claimBuild('entries', 60, 5, 1));

        $this->clock->advance(61);
        $b = $repo->claimBuild('entries', 60, 6, 1);
        self::assertNotNull($b);
        self::assertNotSame($a->generation, $b->generation);
        self::assertNotSame($a->token, $b->token);
        self::assertSame(6, $b->versionAtStart);
    }

    public function testAStaleBuilderIsFencedOut(): void
    {
        $repo = $this->repo();
        $a = $repo->claimBuild('entries', 60, 0, 1);
        $this->clock->advance(61);
        $repo->claimBuild('entries', 60, 0, 1);

        $ran = false;
        try {
            $repo->fenced(
                Fence::builder('entries', $a->token, $a->generation),
                static function () use (&$ran): void {
                    $ran = true;
                },
            );
            self::fail('a stale builder must be fenced out');
        } catch (StaleFence) {
        }
        self::assertFalse($ran);
    }

    public function testThePromotedFenceCompletesAndGoesStaleAfterANewerPromotion(): void
    {
        $repo = $this->repo();
        $repo->ensure('entries');
        $this->setRow('entries', ['generation' => 1]);
        $repo->satisfy(Fence::promoted('entries', 1), 3, 7, 2);
        $row = $repo->row('entries');
        self::assertSame(
            [3, 7, 2],
            [(int) $row['satisfied_seq'], (int) $row['reconciled_version'], (int) $row['schema_version']],
        );

        $this->setRow('entries', ['generation' => 2]);
        $this->expectException(StaleFence::class);
        $repo->satisfy(Fence::promoted('entries', 1), 4, 8, 2);
    }

    public function testAppendsAreSerialisedBehindTheRowLock(): void
    {
        $repo = $this->repo(new DatabaseClock($this->connection()));
        $repo->ensure('entries');
        $holder = RowLockHolder::hold('entries', 1.0);
        $started = microtime(true);
        $seq = $repo->appendChange('entries', 'abc');
        $waited = microtime(true) - $started;
        $holder->release();

        self::assertSame(1, $seq);
        self::assertGreaterThan(0.6, $waited, 'the append waited for the row lock');
        self::assertSame(2, $repo->appendChange('entries', 'def'));
    }

    public function testDemandAndAcknowledgements(): void
    {
        $repo = $this->repo();
        self::assertSame(1, $repo->addDemand('entries', 'manual'));
        self::assertSame(2, $repo->addDemand('entries', 'taxonomy'));
        self::assertSame(2, $repo->maxDemandSeq('entries'));

        $token = (string) $repo->claimDrainer('entries', 60, 30);
        $drainer = Fence::drainer('entries', $token);
        $repo->recordAck($drainer, 1, 'pg:g1', [], 'succeeded');
        $repo->recordAck($drainer, 2, 'pg:g1', [], 'failed');
        self::assertSame([1], $repo->ackedFor('entries', 'pg:g1', [1, 2]));
        $repo->recordAck($drainer, 2, 'pg:g1', [], 'succeeded');
        self::assertSame([1, 2], $repo->ackedFor('entries', 'pg:g1', [1, 2]));

        $this->clock->advance(200);
        $repo->claimDrainer('entries', 60, 30);
        $this->expectException(StaleFence::class);
        $repo->recordAck($drainer, 3, 'pg:g1', [], 'succeeded');
    }

    public function testAPendingAckKeepsItsTasksUntilTheyFinish(): void
    {
        $repo = $this->repo();
        $drainer = Fence::drainer('entries', (string) $repo->claimDrainer('entries', 60, 30));
        $repo->recordAck($drainer, 1, 'idx_g1', [11, 12], 'pending');
        try {
            $repo->recordAck($drainer, 1, 'idx_g1', [13], 'pending');
            self::fail('a retry may not replace pending task ids');
        } catch (\LogicException) {
        }
        $repo->recordAck($drainer, 1, 'idx_g1', [11, 12], 'failed');
        $repo->recordAck($drainer, 1, 'idx_g1', [13], 'pending');
        self::assertSame([13], $repo->ack('entries', 1, 'idx_g1')['task_uids']);
    }

    public function testUnresolvedIncludesOldFailures(): void
    {
        $repo = $this->repo();
        foreach (['a', 'b', 'c'] as $id) {
            $repo->appendChange('entries', $id);
        }
        $this->connection()->table('search_index_changes')->where('seq', '=', 2)->update(['resolved' => 1]);
        self::assertSame([1, 3], array_map(static fn ($e): int => $e->seq, $repo->unresolvedEntries('entries')));
        self::assertSame([1, 3], array_map(static fn ($e): int => $e->seq, $repo->unresolvedEntries('entries', 2)));
        self::assertSame([1, 2, 3], array_map(static fn ($e): int => $e->seq, $repo->unresolvedEntries('entries', 1)));
    }

    public function testResolveIfCompleteChecksTargetsUnderTheLock(): void
    {
        $repo = $this->repo();
        $repo->ensure('entries');
        $this->setRow('entries', [
            'generation' => 1, 'active_target' => 'pg', 'building_generation' => 2, 'building_target' => 'pg',
        ]);
        $seq = $repo->appendChange('entries', 'a');
        $drainer = Fence::drainer('entries', (string) $repo->claimDrainer('entries', 60, 30));
        $repo->recordAck($drainer, $seq, 'pg:g1', [], 'succeeded');
        self::assertFalse($repo->resolveIfComplete($drainer, $seq), 'the build target has no ack yet');
        $repo->recordAck($drainer, $seq, 'pg:g2', [], 'succeeded');
        self::assertTrue($repo->resolveIfComplete($drainer, $seq));
    }

    public function testDrainerTakeoverWaitsForQuiescence(): void
    {
        $repo = $this->repo();
        self::assertNotNull($repo->claimDrainer('entries', 60, 30));
        $this->clock->advance(61);
        self::assertNull($repo->claimDrainer('entries', 60, 30), 'expired, but not yet quiet');
        $this->clock->advance(30);
        self::assertNotNull($repo->claimDrainer('entries', 60, 30));
    }

    public function testLeasesUseDatabaseTime(): void
    {
        $repo = $this->repo(new DatabaseClock($this->connection()));
        $claim = $repo->claimBuild('entries', 60, 0, 1);
        self::assertNotNull($claim);
        $db = (string) $this->connection()->query()->executeRawFirst(
            "SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS now",
        )['now'];
        $lease = strtotime($repo->row('entries')['lease_until'] . ' UTC');
        self::assertEqualsWithDelta(strtotime($db . ' UTC') + 60, $lease, 2);
    }

    public function testAnExpiredHolderInsideALongOuterTransactionIsRefused(): void
    {
        $repo = $this->repo(new DatabaseClock($this->connection()));
        $claim = $repo->claimBuild('entries', 1, 0, 1);
        $ran = false;
        try {
            $this->connection()->transaction(function () use ($repo, $claim, &$ran): void {
                usleep(2_100_000); // the outer transaction outlives the lease
                $repo->fenced(
                    Fence::builder('entries', $claim->token, $claim->generation),
                    static function () use (&$ran): void {
                        $ran = true;
                    },
                );
            });
            self::fail('the lease expired inside the outer transaction');
        } catch (StaleFence) {
        }
        self::assertFalse($ran, 'the check reads clock_timestamp(), not the transaction start');
    }

    public function testALockWaitThatCrossesExpiryIsRefused(): void
    {
        $repo = $this->repo(new DatabaseClock($this->connection()));
        $claim = $repo->claimBuild('entries', 1, 0, 1);
        $holder = RowLockHolder::hold('entries', 2.1);
        $ran = false;
        try {
            $repo->fenced(
                Fence::builder('entries', $claim->token, $claim->generation),
                static function () use (&$ran): void {
                    $ran = true;
                },
            );
            self::fail('the lease expired while waiting for the lock');
        } catch (StaleFence) {
        } finally {
            $holder->release();
        }
        self::assertFalse($ran);

        $holder = RowLockHolder::hold('entries', 2.1);
        try {
            $repo->renewBuild(Fence::builder('entries', $claim->token, $claim->generation), 60);
            self::fail('a renewal after the wait must be refused too');
        } catch (StaleFence) {
        } finally {
            $holder->release();
        }
    }

    private function repo(?\Thallo\Search\Lifecycle\Clock $clock = null): StateRepository
    {
        return new StateRepository($this->connection(), $clock ?? $this->clock);
    }

    /** @param array<string, mixed> $values */
    private function setRow(string $kind, array $values): void
    {
        $this->connection()->table('search_index_state')->where('kind', '=', $kind)->update($values);
    }
}
