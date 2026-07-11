<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofitHarnessTestCase;

/**
 * Proves the harness boots against the DEDICATED throwaway DB (not the shared suite DB) and that the
 * schema is NARROW — no owned table has gained its tenant_uuid column, so scoping is genuinely off.
 */
final class HarnessSmokeTest extends RetrofitHarnessTestCase
{
    public function testBootIsBoundToTheThrowawayDatabase(): void
    {
        $current = $this->connection()->getPDO()->query('SELECT current_database()')->fetchColumn();
        self::assertSame(self::$throwawayDb, $current);
    }

    public function testNarrowOwnedTableHasNoTenantUuidColumnYet(): void
    {
        $stmt = $this->connection()->getPDO()->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'content_types' AND column_name = 'tenant_uuid'"
        );
        $stmt->execute();
        self::assertFalse($stmt->fetchColumn(), 'content_types must be narrow (no tenant_uuid) before retrofit.');
    }
}
