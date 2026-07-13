<?php

declare(strict_types=1);

namespace Thallo\Analytics\Query;

use DateTimeImmutable;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;

/**
 * Read service over the rollups. Reads analytics_daily for counts and analytics_active_actors for
 * distinct active users. Series are zero-filled so callers get a contiguous daily axis.
 */
final class AnalyticsQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
    ) {
    }

    /**
     * Daily count series for one metric, zero-filled across [from, to].
     *
     * `active_users` is special: it is not an event in analytics_daily but a distinct-actor count
     * over analytics_active_actors, so callers get one uniform series shape for every chart.
     *
     * @return list<array{day: string, count: int}>
     */
    public function series(string $event, string $from, string $to, ?string $subject = null): array
    {
        $byDay = $event === 'active_users'
            ? $this->activeUsersByDay($from, $to)
            : $this->countsByDay($event, $subject, $from, $to);

        $out = [];
        $cursor = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $out[] = ['day' => $day, 'count' => $byDay[$day] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    /**
     * event/subject daily counts from analytics_daily.
     *
     * @return array<string, int> day (Y-m-d) => count
     */
    private function countsByDay(string $event, ?string $subject, string $from, string $to): array
    {
        $rows = $this->connection->table('analytics_daily')
            ->select(['day', 'count'])
            ->where('event', $event)
            ->where('subject', $subject ?? '__total__')
            ->where('day', '>=', $from)
            ->where('day', '<=', $to)
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[substr((string) $row['day'], 0, 10)] = (int) $row['count'];
        }
        return $byDay;
    }

    /**
     * Daily distinct active users from analytics_active_actors (raw SQL: the builder's count() is
     * COUNT(*), and we need COUNT(DISTINCT actor_id_hash) per day).
     *
     * @return array<string, int> day (Y-m-d) => distinct count
     */
    private function activeUsersByDay(string $from, string $to): array
    {
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'SELECT day, COUNT(DISTINCT actor_id_hash) AS count FROM analytics_active_actors'
            . " WHERE metric = 'active_users' AND day >= ? AND day <= ?" . $scope . ' GROUP BY day'
        );
        $stmt->execute($tenant === null ? [$from, $to] : [$from, $to, $tenant]);

        $byDay = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byDay[substr((string) $row['day'], 0, 10)] = (int) $row['count'];
        }
        return $byDay;
    }

    /**
     * Top subjects for one event over [from, to], ordered by total count desc. The '__total__'
     * sentinel row is excluded so only real subjects (collection names, content-type slugs) rank.
     *
     * @return list<array{subject: string, count: int}>
     */
    public function breakdown(string $event, string $from, string $to, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'SELECT subject, SUM(count) AS count FROM analytics_daily'
            . " WHERE event = ? AND subject <> '__total__' AND day >= ? AND day <= ?" . $scope
            . ' GROUP BY subject ORDER BY count DESC, subject ASC LIMIT ' . $limit
        );
        $stmt->execute($tenant === null ? [$event, $from, $to] : [$event, $from, $to, $tenant]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = ['subject' => (string) $row['subject'], 'count' => (int) $row['count']];
        }
        return $out;
    }

    /**
     * `scoped` reports whether a tenant was bound for this read: true means the counts reflect a
     * single workspace (platform-level auth rollups, which carry no tenant, are excluded); false
     * means an unscoped/global read. The admin UI uses it to drop auth panels in a workspace view.
     *
     * @return array{from: string, to: string, totals: array<string, int>, active_users: int,
     *     scoped: bool}
     */
    public function summary(string $from, string $to): array
    {
        $totals = [];
        $rows = $this->connection->table('analytics_daily')
            ->select(['event', 'count'])
            ->where('subject', '__total__')
            ->where('day', '>=', $from)
            ->where('day', '<=', $to)
            ->get();
        foreach ($rows as $row) {
            $event = (string) $row['event'];
            $totals[$event] = ($totals[$event] ?? 0) + (int) $row['count'];
        }

        // DISTINCT over the range — a user active on N days counts ONCE, not N (the per-(day,actor)
        // rows would otherwise be user-days). Raw SQL: the builder's count() is COUNT(*).
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT actor_id_hash) FROM analytics_active_actors'
            . " WHERE metric = 'active_users' AND day >= ? AND day <= ?" . $scope
        );
        $stmt->execute($tenant === null ? [$from, $to] : [$from, $to, $tenant]);
        $activeUsers = (int) $stmt->fetchColumn();

        return [
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'active_users' => $activeUsers,
            'scoped' => $tenant !== null,
        ];
    }
}
