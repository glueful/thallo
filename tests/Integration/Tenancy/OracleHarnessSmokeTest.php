<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\TenantOracleTestCase;

final class OracleHarnessSmokeTest extends TenantOracleTestCase
{
    public function testRunAsTenantEstablishesScope(): void
    {
        $a = $this->runAsTenant(self::$tenantAUuid, fn (): string => $this->currentTenantUuid());
        $b = $this->runAsTenant(self::$tenantBUuid, fn (): string => $this->currentTenantUuid());
        self::assertSame(self::$tenantAUuid, $a);
        self::assertSame(self::$tenantBUuid, $b);
    }

    public function testOwnedTableHasTenantColumnAfterHarnessRetrofit(): void
    {
        self::assertTrue(
            $this->connection()->getSchemaBuilder()->hasColumn('seo_meta', 'tenant_uuid'),
            'harness additive stand-in must add tenant_uuid to exercised owned tables',
        );
    }
}
