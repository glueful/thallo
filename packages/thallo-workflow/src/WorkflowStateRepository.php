<?php

declare(strict_types=1);

namespace Thallo\Workflow;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Thallo\Contracts\Tenancy\WriteBarrier;

/**
 * Reads/writes the review-state row (one per entry+locale; absent ≡ draft) and the
 * append-only transition history. setState() is an atomic ON CONFLICT upsert (Postgres,
 * the app's database — the thallo-seo/analytics pattern).
 */
final class WorkflowStateRepository
{
    private const ATTRS = ['submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at'];

    public function __construct(
        private readonly Connection $db,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(string $entryUuid, string $locale): ?array
    {
        $row = $this->db->table('workflow_review_states')
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->first();
        return $row === null ? null : (array) $row;
    }

    public function stateOf(string $entryUuid, string $locale): string
    {
        return (string) ($this->find($entryUuid, $locale)['state'] ?? 'draft');
    }

    /** @param array<string, string|null> $attrs subset of submitted_by/at, reviewed_by/at */
    public function setState(string $entryUuid, string $locale, string $state, array $attrs = []): void
    {
        $this->barrier?->assertWritable();
        $payload = ['state' => $state];
        foreach (self::ATTRS as $attr) {
            if (array_key_exists($attr, $attrs)) {
                $payload[$attr] = $attrs[$attr];
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        $insert = $payload + ['entry_uuid' => $entryUuid, 'locale' => $locale, 'updated_at' => $now];

        $sets = ['updated_at = excluded.updated_at'];
        foreach (array_keys($payload) as $col) {
            $sets[] = $col . ' = excluded.' . $col;
        }

        // Raw upsert bypasses the tenancy stamper — scope the row + widen the conflict target.
        $conflict = ['entry_uuid', 'locale'];
        $tenant = TenantScope::current($this->tenants, $this->context);
        if ($tenant !== null) {
            $insert['tenant_uuid'] = $tenant;
            array_unshift($conflict, 'tenant_uuid');
        }

        $cols = array_keys($insert);
        $sql = 'INSERT INTO workflow_review_states (' . implode(', ', $cols) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            . ' ON CONFLICT (' . implode(', ', $conflict) . ') DO UPDATE SET ' . implode(', ', $sets);
        $this->db->getPDO()->prepare($sql)->execute(array_values($insert));
    }

    public function record(
        string $entryUuid,
        string $locale,
        string $from,
        string $to,
        string $action,
        ?string $actor,
        ?string $note = null,
    ): void {
        $this->db->table('workflow_transitions')->insert([
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'from_state' => $from,
            'to_state' => $to,
            'action' => $action,
            'actor_uuid' => $actor,
            'note' => $note,
            'metadata' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function queuePage(string $state, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $pdo = $this->db->getPDO();

        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = ?';

        $count = $pdo->prepare('SELECT COUNT(*) FROM workflow_review_states WHERE state = ?' . $scope);
        $count->execute($tenant === null ? [$state] : [$state, $tenant]);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT entry_uuid, locale, state, submitted_by, submitted_at FROM workflow_review_states'
            . ' WHERE state = ?' . $scope . ' ORDER BY submitted_at ASC NULLS LAST, id ASC LIMIT ? OFFSET ?'
        );
        $stmt->execute(
            $tenant === null
                ? [$state, $perPage, ($page - 1) * $perPage]
                : [$state, $tenant, $perPage, ($page - 1) * $perPage],
        );

        return ['items' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }

    /** @return list<array<string,mixed>> newest first */
    public function history(string $entryUuid, string $locale, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $stmt = $this->db->getPDO()->prepare(
            'SELECT from_state, to_state, action, actor_uuid, note, created_at'
            . ' FROM workflow_transitions WHERE entry_uuid = ? AND locale = ?' . $scope
            . ' ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute($tenant === null ? [$entryUuid, $locale] : [$entryUuid, $locale, $tenant]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
