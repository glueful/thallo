<?php

declare(strict_types=1);

namespace Thallo\Analytics\Facts;

use Glueful\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single write chokepoint for analytics. Synchronous + best-effort: it never throws into the
 * caller (a failed analytics write must not break the request that triggered the event).
 *
 * Postgres-only by design (the app's database). The count increment and distinct insert use atomic
 * `INSERT … ON CONFLICT` via raw SQL — the query builder's upsert() sets values, not increments.
 */
final class AnalyticsRecorder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ActorHasher $hasher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(AnalyticsFact $fact): void
    {
        try {
            $day = gmdate('Y-m-d', (int) $fact->occurredAt);
            $occurredAt = gmdate('Y-m-d H:i:s', (int) $fact->occurredAt);

            $this->connection->table('analytics_facts')->insert([
                'occurred_at' => $occurredAt,
                'event' => $fact->event,
                'category' => $fact->category,
                'subject_type' => $fact->subjectType,
                'subject_id' => $fact->subjectId,
                'actor_type' => $fact->actorType,
                'actor_id' => $fact->actorId,
                // THROW_ON_ERROR: a silent json_encode() false would insert `false`, not fail
                // into the best-effort catch below like every other write error.
                'metadata' => $fact->metadata === []
                    ? null
                    : json_encode($fact->metadata, \JSON_THROW_ON_ERROR),
            ]);

            $this->bumpDaily($day, $fact->event, '__total__');
            if ($fact->hasBreakdownSubject()) {
                $this->bumpDaily($day, $fact->event, (string) $fact->subjectId);
            }

            if ($fact->isHumanActor()) {
                $this->touchActiveUser($day, $this->hasher->hash((string) $fact->actorId));
            }
        } catch (Throwable $e) {
            // Best-effort: analytics never breaks the request that triggered the event.
            $this->logger->warning('analytics record failed', [
                'event' => $fact->event,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function bumpDaily(string $day, string $event, string $subject): void
    {
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'INSERT INTO analytics_daily (day, event, subject, count) VALUES (?, ?, ?, 1)'
            . ' ON CONFLICT (day, event, subject) DO UPDATE SET count = analytics_daily.count + 1'
        );
        $stmt->execute([$day, $event, $subject]);
    }

    private function touchActiveUser(string $day, string $hash): void
    {
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'INSERT INTO analytics_active_actors (day, metric, actor_type, actor_id_hash)'
            . " VALUES (?, 'active_users', 'user', ?)"
            . ' ON CONFLICT (day, metric, actor_type, actor_id_hash) DO NOTHING'
        );
        $stmt->execute([$day, $hash]);
    }
}
