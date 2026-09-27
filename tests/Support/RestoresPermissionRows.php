<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

/**
 * For a test that runs the permission catalog sync (install, `thallo:provision`, the role grants):
 * the sync rewrites existing permission rows to what the catalog declares — `commerce.view`'s
 * category becomes the catalog's `Commerce` where its seed migration wrote `commerce` — and the test
 * database outlives the test. Snapshot the rows before, write back what the sync changed after, so
 * a later test reads the rows the migrations left.
 */
trait RestoresPermissionRows
{
    private const RESTORED_PERMISSION_COLUMNS = [
        'name', 'description', 'category', 'resource_type', 'is_system', 'managed_by', 'metadata',
    ];

    /** @var array<string, array<string, mixed>> slug => the restored columns */
    private array $permissionRowsBefore = [];

    protected function snapshotPermissionRows(): void
    {
        $columns = implode(', ', self::RESTORED_PERMISSION_COLUMNS);
        $this->permissionRowsBefore = [];
        foreach ($this->connection()->getPDO()->query("SELECT slug, {$columns} FROM permissions") as $row) {
            $slug = (string) $row['slug'];
            unset($row['slug']);
            $this->permissionRowsBefore[$slug] = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY);
        }
    }

    protected function restorePermissionRows(): void
    {
        if ($this->permissionRowsBefore === []) {
            return;
        }
        $pdo = $this->connection()->getPDO();
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", self::RESTORED_PERMISSION_COLUMNS));
        $update = $pdo->prepare("UPDATE permissions SET {$set} WHERE slug = ?");
        $columns = implode(', ', self::RESTORED_PERMISSION_COLUMNS);
        foreach ($pdo->query("SELECT slug, {$columns} FROM permissions") as $row) {
            $slug = (string) $row['slug'];
            $before = $this->permissionRowsBefore[$slug] ?? null;
            unset($row['slug']);
            $now = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($before === null || $before == $now) {
                continue;
            }
            $values = array_map(
                static fn (string $c): mixed => is_bool($before[$c]) ? ($before[$c] ? 'true' : 'false') : $before[$c],
                self::RESTORED_PERMISSION_COLUMNS,
            );
            $update->execute([...$values, $slug]);
        }
    }
}
