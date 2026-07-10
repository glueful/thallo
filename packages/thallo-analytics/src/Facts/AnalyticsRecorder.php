<?php

declare(strict_types=1);

namespace Thallo\Analytics\Facts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Psr\Log\LoggerInterface;
use Thallo\Contracts\Tenancy\WriteBarrier;
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
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
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
        // Raw upsert bypasses the tenancy stamper — scope the row + widen the conflict target.
        $tenant = TenantScope::current($this->tenants, $this->context);
        $cols = ['day', 'event', 'subject', 'count'];
        $vals = [$day, $event, $subject, 1];
        $conflict = ['day', 'event', 'subject'];
        if ($tenant !== null) {
            array_unshift($cols, 'tenant_uuid');
            array_unshift($vals, $tenant);
            array_unshift($conflict, 'tenant_uuid');
        }
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO analytics_daily (' . implode(', ', $cols) . ") VALUES ({$ph})"
            . ' ON CONFLICT (' . implode(', ', $conflict) . ')'
            . ' DO UPDATE SET count = analytics_daily.count + 1';
        $write = fn (): bool => $this->connection->getPDO()->prepare($sql)->execute($vals);
        $this->barrier !== null ? $this->barrier->runWritable($write) : $write();
    }

    private function touchActiveUser(string $day, string $hash): void
    {
        $tenant = TenantScope::current($this->tenants, $this->context);
        $cols = ['day', 'metric', 'actor_type', 'actor_id_hash'];
        $vals = [$day, 'active_users', 'user', $hash];
        $conflict = ['day', 'metric', 'actor_type', 'actor_id_hash'];
        if ($tenant !== null) {
            array_unshift($cols, 'tenant_uuid');
            array_unshift($vals, $tenant);
            array_unshift($conflict, 'tenant_uuid');
        }
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO analytics_active_actors (' . implode(', ', $cols) . ") VALUES ({$ph})"
            . ' ON CONFLICT (' . implode(', ', $conflict) . ') DO NOTHING';
        $write = fn (): bool => $this->connection->getPDO()->prepare($sql)->execute($vals);
        $this->barrier !== null ? $this->barrier->runWritable($write) : $write();
    }
}
