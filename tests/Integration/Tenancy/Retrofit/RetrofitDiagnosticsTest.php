<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Tenancy\Retrofit\RetrofitDiagnostics;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Exercises the retrofit diagnostics against the narrow throwaway DB (tenancy bound, scoping off),
 * before the orchestrator exists. On the narrow schema `checkTables()` reports the present owned
 * tables as NOT coherent (no `tenant_uuid` yet) while `checkAgreement()` is ok (schema_state `none`
 * agrees with zero widened tables). Manually widening one table — with schema_state still `none` —
 * flips agreement to not-ok (reality has a widened table the flag denies).
 */
final class RetrofitDiagnosticsTest extends RetrofitHarnessTestCase
{
    private function diagnostics(): RetrofitDiagnostics
    {
        return $this->container()->get(RetrofitDiagnostics::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // schema_state defaults to 'none'; forget any value a prior test left behind.
        $this->flags()->forget('tenancy.schema_state');
        // Restore `content_types` to its narrow (pre-tenant) shape — a prior test may have widened it.
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DROP TABLE IF EXISTS content_types');
        $pdo->exec(
            'CREATE TABLE content_types (
                id BIGSERIAL PRIMARY KEY,
                uuid VARCHAR(12) NOT NULL,
                slug VARCHAR(160),
                name VARCHAR(200),
                status VARCHAR(20) DEFAULT \'active\',
                schema JSONB,
                schema_version INT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT content_types_uuid_unique UNIQUE (uuid),
                CONSTRAINT content_types_slug_unique UNIQUE (slug)
            )'
        );
    }

    public function testNarrowStateAgreesAndTablesNotYetCoherent(): void
    {
        $d = $this->diagnostics();

        $tables = $d->checkTables();
        self::assertArrayHasKey('content_types', $tables);
        self::assertFalse($tables['content_types']['ok']); // narrow → not coherent (no tenant_uuid)

        self::assertTrue($d->checkAgreement()['ok']); // schema_state none + no widened tables → agree
    }

    public function testBornWidenedCollectionMetadataDoesNotConflictWithNarrowGlobalState(): void
    {
        $tables = $this->diagnostics()->checkTables();
        self::assertTrue($tables['collection_definitions']['ok']);
        self::assertTrue($tables['collection_schema_changes']['ok']);
        self::assertTrue($this->diagnostics()->checkAgreement()['ok']);
    }

    public function testCollectionSchemaChangesRequiresCompositeOwnershipIndex(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DROP INDEX IF EXISTS idx_collection_changes_tenant_collection');
        try {
            $result = $this->diagnostics()->checkTables()['collection_schema_changes'];
            self::assertFalse($result['ok']);
            self::assertStringContainsString('tenant collection index', $result['detail']);
        } finally {
            $pdo->exec(
                'CREATE INDEX idx_collection_changes_tenant_collection '
                . 'ON collection_schema_changes (tenant_uuid, collection_uuid)',
            );
        }
    }

    public function testManualWidenFlipsAgreementWhileFlagStillNone(): void
    {
        // Manually widen ONE owned table (raw PDO): add tenant_uuid, backfill, NOT NULL, widened unique.
        $pdo = $this->connection()->getPDO();
        $pdo->exec('ALTER TABLE content_types ADD COLUMN tenant_uuid VARCHAR(12)');
        $pdo->exec("UPDATE content_types SET tenant_uuid = 'tenant000001' WHERE tenant_uuid IS NULL");
        $pdo->exec('ALTER TABLE content_types ALTER COLUMN tenant_uuid SET NOT NULL');
        $pdo->exec('CREATE UNIQUE INDEX content_types_tenant_uuid_slug_unique ON content_types (tenant_uuid, slug)');

        $d = $this->diagnostics();

        // schema_state is still 'none', but reality now has a widened table → disagreement.
        $agreement = $d->checkAgreement();
        self::assertFalse($agreement['ok']);
        self::assertContains('content_types', $agreement['detail']['widened_tables']);

        // And the widened table now reports coherent (tenant_uuid present/NOT NULL + widened unique).
        self::assertTrue($d->checkTables()['content_types']['ok']);
    }
}
