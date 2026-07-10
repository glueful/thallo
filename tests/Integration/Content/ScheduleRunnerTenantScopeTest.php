<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ScheduleRepository;
use App\Content\Scheduling\ScheduleRunner;
use App\Tests\Support\TenantOracleTestCase;

final class ScheduleRunnerTenantScopeTest extends TenantOracleTestCase
{
    private function seedDue(string $uuid, string $entryUuid, ?string $tenantUuid): void
    {
        $this->connection()->getPDO()->prepare(
            'INSERT INTO entry_schedules (uuid, entry_uuid, locale, action, run_at, status, tenant_uuid)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uuid, $entryUuid, 'en', 'publish', '2020-01-01 00:00:00', 'pending', $tenantUuid]);
    }

    public function testClaimCarriesTenantUuid(): void
    {
        // The system-path claim (no tenant context) must surface each row's tenant via RETURNING *.
        $this->seedDue('sch000000001', 'ent000000001', self::$tenantAUuid);

        $rows = $this->container()->get(ScheduleRepository::class)->claimDuePending(10, 'tok-1');

        self::assertCount(1, $rows);
        self::assertArrayHasKey('tenant_uuid', $rows[0]);
        self::assertSame(self::$tenantAUuid, (string) $rows[0]['tenant_uuid']);
    }

    public function testRunFailsClosedWhenRowHasNoTenant(): void
    {
        // Tenancy active but the claimed row carries no tenant → the runner must FAIL it, never
        // publish unscoped into the '' partition.
        $this->seedDue('sch000000002', 'ent000000002', null);

        $fired = $this->container()->get(ScheduleRunner::class)->run();
        self::assertSame(1, $fired);

        $row = $this->connection()->getPDO()
            ->query("SELECT status, failure_reason FROM entry_schedules WHERE uuid = 'sch000000002'")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('tenant_uuid', (string) $row['failure_reason']);
    }
}
