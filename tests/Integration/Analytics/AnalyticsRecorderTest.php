<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\AppTestCase;
use Thallo\Analytics\Facts\AnalyticsFact;
use Thallo\Analytics\Facts\AnalyticsRecorder;

final class AnalyticsRecorderTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clean analytics tables between test methods. analytics_active_actors has no `id`
        // column so cannot use the parent's where('id', '>', 0) pattern — raw DELETE instead.
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM analytics_facts');
        $pdo->exec('DELETE FROM analytics_daily');
        $pdo->exec('DELETE FROM analytics_active_actors');
    }

    private function recorder(): AnalyticsRecorder
    {
        return $this->container()->get(AnalyticsRecorder::class);
    }

    private function fact(array $o = []): AnalyticsFact
    {
        return new AnalyticsFact(
            event: $o['event'] ?? 'collections.row.created',
            category: $o['category'] ?? 'collections',
            subjectType: $o['subjectType'] ?? 'collection',
            subjectId: $o['subjectId'] ?? 'posts',
            actorType: $o['actorType'] ?? 'admin',
            actorId: $o['actorId'] ?? 'u-1',
            occurredAt: $o['occurredAt'] ?? 1751299200.0, // 2025-06-30 16:00:00 UTC, fixed
            metadata: $o['metadata'] ?? [],
        );
    }

    public function testRecordWritesFactAndIncrementsDailyTotalAndSubject(): void
    {
        $this->recorder()->record($this->fact());
        $this->recorder()->record($this->fact());

        self::assertSame(2, (int) $this->connection()->table('analytics_facts')
            ->where('event', 'collections.row.created')->count());

        $total = $this->connection()->table('analytics_daily')
            ->where('event', 'collections.row.created')->where('subject', '__total__')->first();
        self::assertSame(2, (int) $total['count']);

        $subject = $this->connection()->table('analytics_daily')
            ->where('event', 'collections.row.created')->where('subject', 'posts')->first();
        self::assertSame(2, (int) $subject['count']);
    }

    public function testActiveUsersIsDistinctHumanNormalizingAdminToUser(): void
    {
        // Same uuid as admin then as user → one active row (normalized to 'user').
        $this->recorder()->record($this->fact(['actorType' => 'admin', 'actorId' => 'u-9']));
        $this->recorder()->record($this->fact(['actorType' => 'user', 'actorId' => 'u-9']));
        // api_key + system actors are excluded.
        $this->recorder()->record($this->fact(['actorType' => 'api_key', 'actorId' => 'k-1']));
        $this->recorder()->record($this->fact(['actorType' => 'system', 'actorId' => null]));

        $rows = $this->connection()->table('analytics_active_actors')
            ->where('metric', 'active_users')->get();
        self::assertCount(1, $rows);
        self::assertSame('user', $rows[0]['actor_type']);
        self::assertNotSame('u-9', $rows[0]['actor_id_hash']); // hashed, not raw
    }
}
