<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\AppTestCase;
use Thallo\Analytics\Facts\AnalyticsFact;
use Thallo\Analytics\Facts\AnalyticsRecorder;
use Thallo\Analytics\Query\AnalyticsQuery;

final class AnalyticsQueryTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM analytics_facts');
        $pdo->exec('DELETE FROM analytics_daily');
        $pdo->exec('DELETE FROM analytics_active_actors');
    }

    private function record(string $event, float $ts, string $actorId): void
    {
        $this->container()->get(AnalyticsRecorder::class)->record(new AnalyticsFact(
            event: $event,
            category: 'collections',
            subjectType: 'collection',
            subjectId: 'posts',
            actorType: 'user',
            actorId: $actorId,
            occurredAt: $ts,
        ));
    }

    public function testSeriesIsZeroFilledAcrossRange(): void
    {
        // 2025-06-10 and 2025-06-12, query 2025-06-10..2025-06-12 → [1,0,1].
        $this->record('collections.row.created', 1749556800.0, 'u-1'); // 2025-06-10
        $this->record('collections.row.created', 1749729600.0, 'u-2'); // 2025-06-12

        $q = $this->container()->get(AnalyticsQuery::class);
        $series = $q->series('collections.row.created', '2025-06-10', '2025-06-12');

        self::assertSame(
            [
                ['day' => '2025-06-10', 'count' => 1],
                ['day' => '2025-06-11', 'count' => 0],
                ['day' => '2025-06-12', 'count' => 1],
            ],
            $series,
        );
    }

    public function testSummaryTotalsAndActiveUsersAreDistinctOverRange(): void
    {
        // u-1 active on 2025-06-10 AND 2025-06-11; u-2 active on 2025-06-10.
        $this->record('collections.row.created', 1749556800.0, 'u-1'); // 2025-06-10
        $this->record('collections.row.created', 1749556800.0, 'u-2'); // 2025-06-10
        $this->record('collections.row.created', 1749643200.0, 'u-1'); // 2025-06-11

        $q = $this->container()->get(AnalyticsQuery::class);
        $summary = $q->summary('2025-06-10', '2025-06-11');

        self::assertSame(3, $summary['totals']['collections.row.created']);
        // Distinct users over the range — u-1's two active days count ONCE (not user-days).
        self::assertSame(2, $summary['active_users']);
    }

    public function testActiveUsersSeriesIsDailyDistinctAndZeroFilled(): void
    {
        // u-1 and u-2 active on 2025-06-10; u-1 again on 2025-06-12. Day 06-11 has nobody.
        $this->record('collections.row.created', 1749556800.0, 'u-1'); // 2025-06-10
        $this->record('collections.row.created', 1749556800.0, 'u-2'); // 2025-06-10
        $this->record('collections.row.created', 1749729600.0, 'u-1'); // 2025-06-12

        $q = $this->container()->get(AnalyticsQuery::class);
        $series = $q->series('active_users', '2025-06-10', '2025-06-12');

        self::assertSame(
            [
                ['day' => '2025-06-10', 'count' => 2], // u-1, u-2 distinct
                ['day' => '2025-06-11', 'count' => 0], // zero-filled
                ['day' => '2025-06-12', 'count' => 1], // u-1
            ],
            $series,
        );
    }

    private function recordSubject(string $event, float $ts, string $actorId, string $subject): void
    {
        $this->container()->get(AnalyticsRecorder::class)->record(new AnalyticsFact(
            event: $event,
            category: 'collections',
            subjectType: 'collection',
            subjectId: $subject,
            actorType: 'user',
            actorId: $actorId,
            occurredAt: $ts,
        ));
    }

    public function testBreakdownRanksSubjectsDescendingAndExcludesTotalSentinel(): void
    {
        // 'posts' gets 2 row-creates, 'authors' gets 1, on 2025-06-10.
        $this->recordSubject('collections.row.created', 1749556800.0, 'u-1', 'posts');
        $this->recordSubject('collections.row.created', 1749556800.0, 'u-2', 'posts');
        $this->recordSubject('collections.row.created', 1749556800.0, 'u-1', 'authors');

        $q = $this->container()->get(AnalyticsQuery::class);
        $breakdown = $q->breakdown('collections.row.created', '2025-06-10', '2025-06-10');

        self::assertSame(
            [
                ['subject' => 'posts', 'count' => 2],
                ['subject' => 'authors', 'count' => 1],
            ],
            $breakdown,
        );
    }

    public function testBreakdownClampsLimitToFiftyAndMinimumOne(): void
    {
        // Seed 60 distinct subjects so an unclamped limit would return >50.
        for ($i = 0; $i < 60; $i++) {
            $this->recordSubject('collections.row.created', 1749556800.0, "u-$i", "subj-$i");
        }
        $q = $this->container()->get(AnalyticsQuery::class);

        // limit above the ceiling is clamped to 50.
        $clamped = $q->breakdown('collections.row.created', '2025-06-10', '2025-06-10', 9999);
        self::assertCount(50, $clamped, 'limit must clamp to a max of 50');

        // limit below 1 is clamped up to 1 (never 0/negative rows).
        $floored = $q->breakdown('collections.row.created', '2025-06-10', '2025-06-10', 0);
        self::assertCount(1, $floored, 'limit must clamp to a min of 1 when data exists');
    }
}
