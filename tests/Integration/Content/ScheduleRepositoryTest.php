<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Enums\ScheduleAction;
use App\Content\Repositories\ScheduleRepository;
use App\Tests\Support\AppTestCase;

final class ScheduleRepositoryTest extends AppTestCase
{
    public function testEntrySchedulesTableShape(): void
    {
        $pdo = $this->connection()->getPDO();
        $insert = static fn (string $action): bool => (bool) $pdo->prepare(
            "INSERT INTO entry_schedules (uuid, entry_uuid, locale, action, run_at, status, created_at)
             VALUES (?, 'e1abcdefghij', 'en', ?, now() + interval '1 hour', 'pending', now())"
        )->execute([substr(md5($action . microtime()), 0, 12), $action]);

        self::assertTrue($insert('publish'));
        self::assertTrue($insert('unpublish'));

        $this->expectException(\PDOException::class);

        $insert('publish');
    }

    public function testScheduleCreatesPendingThenReplacePreservesTerminalHistory(): void
    {
        $repo = $this->repo();

        $first = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Publish, '2026-07-01T07:00:00Z', 'user00000001');
        self::assertFalse($first['replaced']);
        self::assertSame('pending', $first['status']);

        $this->connection()->table('entry_schedules')
            ->where('uuid', '=', $first['uuid'])
            ->update(['status' => 'done']);

        $second = $repo->schedule(
            'e1abcdefghij',
            'en',
            ScheduleAction::Publish,
            '2026-07-02T07:00:00Z',
            'user00000001',
        );
        self::assertFalse($second['replaced']);

        $third = $repo->schedule(
            'e1abcdefghij',
            'en',
            ScheduleAction::Publish,
            '2026-07-03T07:00:00Z',
            'user00000001',
        );
        self::assertTrue($third['replaced']);

        $rows = $repo->forEntry('e1abcdefghij');
        self::assertCount(2, $rows);
        self::assertSame(['pending', 'done'], array_column($rows, 'status'));
    }

    public function testCancelPendingMarksCanceledAndTerminalCannotCancel(): void
    {
        $repo = $this->repo();
        $row = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Unpublish, '2026-07-01T07:00:00Z', 'user00000001');

        self::assertTrue($repo->cancel('e1abcdefghij', $row['uuid'], 'user00000002'));
        $stored = $repo->find($row['uuid']);
        self::assertSame('canceled', $stored['status']);
        self::assertSame('user00000002', $stored['canceled_by']);
        self::assertNotNull($stored['canceled_at']);

        self::assertFalse($repo->cancel('e1abcdefghij', $row['uuid'], 'user00000002'));
    }

    public function testCancelIsEntryScoped(): void
    {
        $repo = $this->repo();
        $row = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Publish, '2026-07-01T07:00:00Z', null);

        self::assertFalse($repo->cancel('e9wrongentry0', $row['uuid'], null));
        self::assertSame('pending', $repo->find($row['uuid'])['status']);
    }

    public function testNormalizeRunAtConvertsOffsetToUtcAndRejectsNaive(): void
    {
        $repo = $this->repo();

        self::assertSame('2026-07-01T07:00:00Z', $repo->normalizeRunAt('2026-07-01T09:00:00+02:00'));
        self::assertSame('2026-07-01T07:00:00Z', $repo->normalizeRunAt('2026-07-01T07:00:00Z'));

        $this->expectException(\InvalidArgumentException::class);

        $repo->normalizeRunAt('2026-07-01T09:00:00');
    }

    public function testClaimDueFlipsPastPendingToProcessingNotFuture(): void
    {
        $repo = $this->repo();
        $due = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Publish, '2020-01-01T00:00:00Z', null);
        $repo->schedule('e2abcdefghij', 'en', ScheduleAction::Publish, '2999-01-01T00:00:00Z', null);

        $claimed = $repo->claimDuePending(10, 'tok-a');

        self::assertSame([$due['uuid']], array_column($claimed, 'uuid'));
        self::assertSame('processing', $repo->find($due['uuid'])['status']);
        self::assertSame([], $repo->claimDuePending(10, 'tok-b'));
    }

    public function testConcurrentClaimSkipsLockedRow(): void
    {
        $repo = $this->repo();
        $row = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Publish, '2020-01-01T00:00:00Z', null);

        $pdo = $this->newPdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id FROM entry_schedules WHERE uuid = ? FOR UPDATE');
        $stmt->execute([$row['uuid']]);

        try {
            self::assertSame([], $repo->claimDuePending(10, 'tok-a'));
        } finally {
            $pdo->commit();
        }
    }

    public function testReclaimStaleResetsProcessingToPending(): void
    {
        $repo = $this->repo();
        $row = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Publish, '2020-01-01T00:00:00Z', null);
        $this->connection()->getPDO()->prepare(
            "UPDATE entry_schedules
             SET status = 'processing', updated_at = now() - interval '10 minutes'
             WHERE uuid = ?"
        )->execute([$row['uuid']]);

        self::assertSame(1, $repo->reclaimStale(300));
        self::assertSame('pending', $repo->find($row['uuid'])['status']);
    }

    public function testMarkOutcomeMakesProcessingScheduleTerminalAndIncrementsAttempts(): void
    {
        $repo = $this->repo();
        $row = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Publish, '2020-01-01T00:00:00Z', null);
        $claimed = $repo->claimDuePending(10, 'tok-a')[0];

        $repo->markOutcome((int) $claimed['id'], \App\Content\Enums\ScheduleStatus::Failed, 'invalid draft', 'tok-a');

        $stored = $repo->find($row['uuid']);
        self::assertSame('failed', $stored['status']);
        self::assertSame('invalid draft', $stored['failure_reason']);
        self::assertSame(1, (int) $stored['attempts']);
    }

    public function testMarkOutcomeFromNonOwningRunNoOps(): void
    {
        $repo = $this->repo();
        $row = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Publish, '2020-01-01T00:00:00Z', null);
        $claimed = $repo->claimDuePending(10, 'owner-run')[0];

        // A different run (e.g. after a stale-lease reclaim) must not be able to finalise this row.
        $repo->markOutcome((int) $claimed['id'], \App\Content\Enums\ScheduleStatus::Done, null, 'other-run');

        self::assertSame('processing', $repo->find($row['uuid'])['status'], 'wrong-token outcome must no-op');

        // The owning run still can.
        $repo->markOutcome((int) $claimed['id'], \App\Content\Enums\ScheduleStatus::Done, null, 'owner-run');
        self::assertSame('done', $repo->find($row['uuid'])['status']);
    }

    public function testReclaimClearsLockOwnerSoOriginalRunCannotFinalise(): void
    {
        $repo = $this->repo();
        $row = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Publish, '2020-01-01T00:00:00Z', null);
        $claimed = $repo->claimDuePending(10, 'owner-run')[0];

        // Age the claim past the lease and reclaim it (clears locked_by, back to pending).
        $this->connection()->getPDO()->prepare(
            "UPDATE entry_schedules SET updated_at = now() - interval '10 minutes' WHERE id = ?"
        )->execute([(int) $claimed['id']]);
        self::assertSame(1, $repo->reclaimStale(300));

        // The original run's outcome write finds no matching processing row → no-op; the row stays
        // pending (re-claimable), so it can't be double-finalised.
        $repo->markOutcome((int) $claimed['id'], \App\Content\Enums\ScheduleStatus::Done, null, 'owner-run');
        self::assertSame('pending', $repo->find($row['uuid'])['status']);
    }

    public function testClaimedRowsAreReturnedInChronologicalOrder(): void
    {
        $repo = $this->repo();
        // Insert the later action first so a naive RETURNING order would surface it before the
        // earlier one; the repository must re-sort by (run_at, id).
        $later = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Unpublish, '2020-01-02T00:00:00Z', null);
        $earlier = $repo->schedule('e1abcdefghij', 'en', ScheduleAction::Publish, '2020-01-01T00:00:00Z', null);

        $claimed = $repo->claimDuePending(10, 'tok-a');

        self::assertSame(
            [$earlier['uuid'], $later['uuid']],
            array_column($claimed, 'uuid'),
            'due rows must run oldest run_at first',
        );
    }

    private function repo(): ScheduleRepository
    {
        return new ScheduleRepository($this->connection());
    }

    private function newPdo(): \PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $_ENV['DB_PGSQL_HOST'] ?? getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
            $_ENV['DB_PGSQL_PORT'] ?? getenv('DB_PGSQL_PORT') ?: '5432',
            $_ENV['DB_PGSQL_DATABASE'] ?? getenv('DB_PGSQL_DATABASE') ?: 'app_test',
        );

        return new \PDO(
            $dsn,
            (string) ($_ENV['DB_PGSQL_USERNAME'] ?? getenv('DB_PGSQL_USERNAME') ?: 'postgres'),
            (string) ($_ENV['DB_PGSQL_PASSWORD'] ?? getenv('DB_PGSQL_PASSWORD') ?: ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }
}
