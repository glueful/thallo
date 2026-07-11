<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofitHarnessTestCase;
use PDO;
use PDOException;
use RuntimeException;
use Thallo\Tenancy\Retrofit\DefaultTenant;
use Thallo\Tenancy\Retrofit\SchemaIntrospector;
use Thallo\Tenancy\Retrofit\TableRebuilder;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Exercises the staged, recoverable copy-rebuild against the narrow throwaway DB (tenancy bound,
 * scoping off). The three rebuild tables (regions/settings/entry_redirects) start narrow — a single-
 * column PK or an inline unique — and must come out widened with `tenant_uuid`, every business row
 * preserved and stamped, and (for entry_redirects) all inline uniques + the three CHECK constraints
 * intact. A real mid-swap crash is simulated via a failpoint and the fresh rebuilder recovers it.
 */
final class TableRebuilderTest extends RetrofitHarnessTestCase
{
    private function rebuilder(): TableRebuilder
    {
        return $this->container()->get(TableRebuilder::class);
    }

    private function introspector(): SchemaIntrospector
    {
        return $this->container()->get(SchemaIntrospector::class);
    }

    private function defaultTenant(): DefaultTenant
    {
        return $this->container()->get(DefaultTenant::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->flags()->forget('tenancy.provisioning_tenant_uuid');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        $this->flags()->forget('tenancy.retrofit_progress');
        $this->restoreNarrow();
    }

    /**
     * Rebuild the three narrow source tables from scratch (dropping any swap leftovers), so each test
     * starts from the exact pre-tenant shape the migrations produce. No inbound FKs reference them.
     */
    private function restoreNarrow(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach (['regions', 'settings', 'entry_redirects'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}_new");
            $pdo->exec("DROP TABLE IF EXISTS {$table}_backup");
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }

        $pdo->exec(
            'CREATE TABLE regions (
                slug varchar(64) PRIMARY KEY,
                blocks jsonb,
                settings jsonb,
                updated_at timestamp,
                updated_by varchar(12)
            )'
        );
        $pdo->exec(
            'CREATE TABLE settings (
                key varchar(120) PRIMARY KEY,
                value text,
                updated_at timestamp DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            "CREATE TABLE entry_redirects (
                id BIGSERIAL PRIMARY KEY,
                uuid varchar(12),
                content_type_uuid varchar(12),
                locale varchar(16),
                source_slug varchar(200),
                target_content_type_uuid varchar(12),
                target_locale varchar(16),
                target_entry_uuid varchar(12),
                target_url varchar(2048),
                status integer DEFAULT 301,
                origin varchar(16) DEFAULT 'manual',
                created_by varchar(12),
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp,
                CONSTRAINT entry_redirects_uuid_unique UNIQUE (uuid),
                CONSTRAINT uniq_redirect_type_locale_source UNIQUE (content_type_uuid, locale, source_slug),
                CONSTRAINT chk_entry_redirect_status CHECK (status IN (301, 302, 308)),
                CONSTRAINT chk_entry_redirect_origin CHECK (origin IN ('auto', 'manual')),
                CONSTRAINT chk_entry_redirect_exactly_one_target CHECK (
                    (target_entry_uuid IS NOT NULL AND target_content_type_uuid IS NOT NULL
                        AND target_locale IS NOT NULL AND target_url IS NULL)
                    OR
                    (target_entry_uuid IS NULL AND target_content_type_uuid IS NULL
                        AND target_locale IS NULL AND target_url IS NOT NULL)
                )
            )"
        );

        $pdo->exec('DELETE FROM tenant_memberships');
        $pdo->exec('DELETE FROM tenants');
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->connection()->getPDO()->prepare('SELECT to_regclass(:t)');
        $stmt->execute([':t' => $table]);
        $value = $stmt->fetchColumn();

        return $value !== null && $value !== false;
    }

    public function testRebuildRegionsPreservesRowsAndWidensPk(): void
    {
        $tenant = $this->defaultTenant()->ensure('t1', 'T1', 'user00000001');
        $pdo = $this->connection()->getPDO();
        $pdo->exec(
            "INSERT INTO regions (slug, blocks, settings, updated_by)
             VALUES ('header', '[]', '{}', 'user00000001'), ('footer', '[]', '{}', 'user00000001')"
        );

        $this->rebuilder()->rebuild('regions');

        // Every row preserved and stamped with the default tenant.
        $rows = $pdo->query('SELECT slug, tenant_uuid FROM regions ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($tenant, $row['tenant_uuid']);
        }

        // tenant_uuid is NOT NULL; the PK widened to (tenant_uuid, slug); the narrow slug-only PK is gone.
        self::assertTrue($this->introspector()->columnNotNull('regions', 'tenant_uuid'));
        self::assertTrue($this->introspector()->uniqueExists('regions', ['tenant_uuid', 'slug']));
        self::assertFalse($this->introspector()->uniqueExists('regions', ['slug']));

        // Coexistence: the SAME slug under a second tenant uuid succeeds (no PK collision).
        $insert = $pdo->prepare(
            "INSERT INTO regions (slug, blocks, settings, tenant_uuid) VALUES ('header', '[]', '{}', :t)"
        );
        $insert->execute([':t' => 'tenant000002']);
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM regions')->fetchColumn());

        // But the SAME (tenant_uuid, slug) still collides — the widened PK is real.
        $this->expectException(PDOException::class);
        $dup = $pdo->prepare(
            "INSERT INTO regions (slug, blocks, settings, tenant_uuid) VALUES ('header', '[]', '{}', :t)"
        );
        $dup->execute([':t' => $tenant]);
    }

    public function testRebuildEntryRedirectsPreservesRowsAndConstraints(): void
    {
        $tenant = $this->defaultTenant()->ensure('t1', 'T1', 'user00000001');
        $pdo = $this->connection()->getPDO();
        // A COMPLETE VALID redirect: literal target_url set, all three target_* NULL, status/origin valid.
        $pdo->exec(
            "INSERT INTO entry_redirects (uuid, content_type_uuid, locale, source_slug, target_url, status, origin)
             VALUES ('red000000001', 'ct0000000001', 'en', 'old-path', 'https://example.test/new', 302, 'auto')"
        );

        $this->rebuilder()->rebuild('entry_redirects');

        // Row preserved + stamped.
        $rows = $pdo->query('SELECT uuid, tenant_uuid, status FROM entry_redirects')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('red000000001', $rows[0]['uuid']);
        self::assertSame($tenant, $rows[0]['tenant_uuid']);
        self::assertSame(302, (int) $rows[0]['status']);

        // Inline uuid unique preserved; business unique widened; the narrow business unique is gone.
        self::assertTrue($this->introspector()->uniqueExists('entry_redirects', ['uuid']));
        self::assertTrue($this->introspector()->uniqueExists(
            'entry_redirects',
            ['tenant_uuid', 'content_type_uuid', 'locale', 'source_slug']
        ));
        self::assertFalse($this->introspector()->uniqueExists(
            'entry_redirects',
            ['content_type_uuid', 'locale', 'source_slug']
        ));
        self::assertTrue($this->introspector()->columnNotNull('entry_redirects', 'tenant_uuid'));

        // The status CHECK still rejects an out-of-range status (307).
        $this->assertInsertRejected(
            $pdo,
            "INSERT INTO entry_redirects
                 (uuid, content_type_uuid, locale, source_slug, target_url, status, origin, tenant_uuid)
             VALUES ('red000000002', 'ct0000000001', 'en', 'p2', 'https://example.test/x', 307, 'auto', '{$tenant}')"
        );

        // The exactly-one-target CHECK still rejects a two-target row (entry target AND url both set).
        $this->assertInsertRejected(
            $pdo,
            "INSERT INTO entry_redirects
                 (uuid, content_type_uuid, locale, source_slug, target_entry_uuid,
                  target_content_type_uuid, target_locale, target_url, status, origin, tenant_uuid)
             VALUES ('red000000003', 'ct0000000001', 'en', 'p3', 'e00000000001',
                  'ct0000000001', 'en', 'https://example.test/y', 301, 'auto', '{$tenant}')"
        );
    }

    public function testRebuildRecoversFromRealMidSwapCrash(): void
    {
        $tenant = $this->defaultTenant()->ensure('t1', 'T1', 'user00000001');
        $pdo = $this->connection()->getPDO();
        $pdo->exec(
            "INSERT INTO entry_redirects (uuid, content_type_uuid, locale, source_slug, target_url, status, origin)
             VALUES ('rec000000001', 'ct0000000001', 'en', 'crash-path', 'https://example.test/z', 301, 'manual')"
        );

        $failpoint = static function (): void {
            throw new RuntimeException('simulated mid-swap crash');
        };

        // The failpoint fires right after original -> _backup and before _new -> canonical.
        try {
            $this->rebuilder()->rebuild('entry_redirects', $failpoint);
            self::fail('Expected the failpoint to interrupt the swap.');
        } catch (RuntimeException $e) {
            self::assertSame('simulated mid-swap crash', $e->getMessage());
        }

        // Crash state: canonical MISSING, backup PRESENT — the swap is provably mid-flight.
        self::assertFalse($this->tableExists('entry_redirects'));
        self::assertTrue($this->tableExists('entry_redirects_backup'));

        // A fresh rebuilder recovers without corruption or a recopy.
        $this->rebuilder()->rebuild('entry_redirects');

        self::assertTrue($this->tableExists('entry_redirects'));
        self::assertFalse($this->tableExists('entry_redirects_backup'));
        self::assertFalse($this->tableExists('entry_redirects_new'));
        self::assertTrue($this->introspector()->columnExists('entry_redirects', 'tenant_uuid'));

        // Rows intact and stamped after recovery.
        $rows = $pdo->query('SELECT uuid, tenant_uuid FROM entry_redirects')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('rec000000001', $rows[0]['uuid']);
        self::assertSame($tenant, $rows[0]['tenant_uuid']);
    }

    private function assertInsertRejected(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
            self::fail('Expected the insert to be rejected by a CHECK constraint.');
        } catch (PDOException) {
            // expected — the CHECK constraint survived the rebuild.
        }
    }
}
