<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\Migration\BlockMigrationRepository;
use App\Tests\Support\TenantOracleTestCase;

final class MigrationTenantScopeTest extends TenantOracleTestCase
{
    public function testIncrementDoneIsScopedToTenant(): void
    {
        $pdo = $this->connection()->getPDO();
        // Seed a migration row owned by tenant A (raw insert carrying tenant_uuid).
        $pdo->prepare(
            'INSERT INTO block_type_migrations'
            . ' (uuid, block_type_uuid, ops, status, failure_report, tenant_uuid, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(['bmig00000001', 'bt0000000001', '[]', 'running', '[]', self::$tenantAUuid, gmdate('Y-m-d H:i:s')]);

        $repo = $this->container()->get(BlockMigrationRepository::class);
        $done = static fn (): int => (int) $pdo
            ->query("SELECT work_items_done FROM block_type_migrations WHERE uuid = 'bmig00000001'")
            ->fetchColumn();

        // Wrong tenant: the tenant_uuid predicate scopes the UPDATE out — no increment.
        $this->runAsTenant(self::$tenantBUuid, fn () => $repo->incrementDone('bmig00000001'));
        self::assertSame(0, $done());

        // Owning tenant: increments.
        $this->runAsTenant(self::$tenantAUuid, fn () => $repo->incrementDone('bmig00000001'));
        self::assertSame(1, $done());
    }
}
