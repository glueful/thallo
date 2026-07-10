<?php

declare(strict_types=1);

namespace Thallo\Seo\Meta;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Contracts\Tenancy\TenantWriteScope;

/**
 * Reads/writes the seo_meta override table, keyed by (entry_uuid, locale).
 */
final class SeoMetaRepository
{
    private const COLUMNS = [
        'title', 'description', 'og_title', 'og_description', 'og_image', 'twitter_card', 'robots',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
        private readonly ?TenantWriteScope $writeScope = null,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(string $entryUuid, string $locale): ?array
    {
        $row = $this->db->table('seo_meta')
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->first();
        return $row === null ? null : (array) $row;
    }

    /**
     * Atomic `INSERT … ON CONFLICT (entry_uuid, locale) DO UPDATE` (Postgres, the app's
     * database — same pattern as thallo-analytics): find-then-insert raced concurrent PUTs
     * into a unique violation. Only the provided columns are updated; created_at is
     * insert-only. Column names come from the fixed COLUMNS allow-list.
     *
     * @param array<string,mixed> $data
     */
    public function upsert(string $entryUuid, string $locale, array $data): void
    {
        $payload = [];
        foreach (self::COLUMNS as $col) {
            if (array_key_exists($col, $data)) {
                $payload[$col] = $data[$col];
            }
        }
        // robots is NOT NULL default 'index' — an explicit null means "reset to default".
        if (array_key_exists('robots', $payload) && $payload['robots'] === null) {
            $payload['robots'] = 'index';
        }

        $now = gmdate('Y-m-d H:i:s');
        $insert = $payload + [
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $sets = ['updated_at = excluded.updated_at'];
        foreach (array_keys($payload) as $col) {
            $sets[] = $col . ' = excluded.' . $col;
        }

        // Raw PDO bypasses the tenancy stamper/guard — scope explicitly. tenant_uuid is not in
        // $payload, so it never enters DO UPDATE SET (the row's tenant is immutable).
        $conflict = ['entry_uuid', 'locale'];
        $tenant = TenantScope::current($this->tenants, $this->context);
        if ($this->writeScope?->mode() === 'compat') {
            $tenant = $this->writeScope->tenantUuidForWrite();
        }
        if ($tenant !== null) {
            $insert['tenant_uuid'] = $tenant;
            array_unshift($conflict, 'tenant_uuid');
        }

        $cols = array_keys($insert);
        $sql = 'INSERT INTO seo_meta (' . implode(', ', $cols) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            . ' ON CONFLICT (' . implode(', ', $conflict) . ') DO UPDATE SET ' . implode(', ', $sets);
        $write = fn (): bool => $this->db->getPDO()->prepare($sql)->execute(array_values($insert));
        $this->barrier !== null ? $this->barrier->runWritable($write) : $write();
    }
}
