<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\Retrofit\RetrofitProgress;
use Thallo\Tenancy\Retrofit\SchemaIntrospector;
use Thallo\Tenancy\Retrofit\SchemaRetrofit;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Drives the full enable-time schema retrofit through {@see SchemaRetrofit::run()} against the narrow
 * throwaway PostgreSQL DB (tenancy bound, scoping off). Because run() is idempotent + resumable, each
 * test is order-independent: setUp only resets the per-operation state (provisioning pointer + tenants
 * registry) and lowers a barrier a prior successful run left UP.
 */
final class SchemaRetrofitTest extends RetrofitHarnessTestCase
{
    private function schemaRetrofit(): SchemaRetrofit
    {
        return $this->container()->get(SchemaRetrofit::class);
    }

    private function introspector(): SchemaIntrospector
    {
        return $this->container()->get(SchemaIntrospector::class);
    }

    private function progress(): RetrofitProgress
    {
        return $this->container()->get(RetrofitProgress::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function guard(): RetrofitMaintenanceGuard
    {
        return $this->container()->get(RetrofitMaintenanceGuard::class);
    }

    protected function setUp(): void
    {
        // A prior successful run() leaves the barrier UP; lower it BEFORE parent::setUp(), whose cleanup
        // issues owned-table builder deletes that the interceptor would otherwise refuse.
        if (self::$engineApp !== null) {
            $this->guard()->end();
        }
        parent::setUp();
        // Reset per-operation identity so each run() provisions a fresh default tenant.
        $this->flags()->forget('tenancy.provisioning_tenant_uuid');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM tenant_memberships');
        $pdo->exec('DELETE FROM tenants');
    }

    /** A narrow (pre-retrofit) content_types, mirroring the shape run() must widen. */
    private function narrowContentTypes(): void
    {
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
        $pdo->exec(
            "INSERT INTO content_types (uuid, slug, name, status, schema, schema_version)
             VALUES ('ctrun0000001', 'article', 'Article', 'active', '[]', 1),
                    ('ctrun0000002', 'page', 'Page', 'active', '[]', 1)"
        );
    }

    public function testRunWidensSampledAdditiveAndRebuildTablesAndLeavesBarrierUp(): void
    {
        $report = $this->schemaRetrofit()->run('t1', 'T1', 'user00000001');

        self::assertNotSame('', $report->defaultTenantUuid());

        // Sampled ADDITIVE table: tenant_uuid NOT NULL + widened (tenant_uuid, slug) unique.
        self::assertTrue($this->introspector()->columnNotNull('content_types', 'tenant_uuid'));
        self::assertTrue($this->introspector()->uniqueExists('content_types', ['tenant_uuid', 'slug']));

        // Sampled REBUILD table: tenant_uuid NOT NULL + widened (tenant_uuid, slug) PK.
        self::assertTrue($this->introspector()->columnNotNull('regions', 'tenant_uuid'));
        self::assertTrue($this->introspector()->uniqueExists('regions', ['tenant_uuid', 'slug']));

        // Schema state recorded, and the report lists the widened tables.
        self::assertSame('widened', $this->flags()->schemaState());
        self::assertContains('content_types', $report->widenedTables());
        self::assertContains('regions', $report->widenedTables());
        self::assertSame($report->widenedTableCount(), count($report->widenedTables()));

        // Barrier stays UP on success — Phase E lowers it atomically with the transition to `on`.
        self::assertTrue($this->guard()->active());
    }

    public function testRetrofitMovesLegacySystemKeyWhileBarrierUp(): void
    {
        // Ensure the system channel does not already hold the key (channel-wins would skip the move),
        // then seed a legacy `installed` row directly into `settings` — matching its current shape.
        $this->flags()->forget('installed');
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM settings WHERE key = 'installed'");
        if ($this->introspector()->columnExists('settings', 'tenant_uuid')) {
            $pdo->exec("INSERT INTO settings (key, value, tenant_uuid) VALUES ('installed', '1', 'seedtenant01')");
        } else {
            $pdo->exec("INSERT INTO settings (key, value) VALUES ('installed', '1')");
        }

        $report = $this->schemaRetrofit()->run('t1', 'T1', 'user00000001');

        // run() completed — the reconciler's DELETE from the owned settings table was NOT rejected.
        self::assertContains('installed', $report->movedSettingsKeys());
        // The key now lives in the system channel (thallo_system_flags), and the settings row is gone.
        self::assertSame('1', $this->flags()->get('installed'));
        $remaining = $pdo->query("SELECT COUNT(*) FROM settings WHERE key = 'installed'")->fetchColumn();
        self::assertSame(0, (int) $remaining);
    }

    public function testRunIsIdempotent(): void
    {
        $first = $this->schemaRetrofit()->run('t1', 'T1', 'user00000001');
        // A second run after a completed retrofit must be a no-op-ish success — no error, state unchanged.
        $second = $this->schemaRetrofit()->run('t1', 'T1', 'user00000001');

        self::assertSame($first->defaultTenantUuid(), $second->defaultTenantUuid());
        self::assertSame('widened', $this->flags()->schemaState());
        self::assertTrue($this->introspector()->columnNotNull('content_types', 'tenant_uuid'));
        self::assertTrue($this->introspector()->uniqueExists('content_types', ['tenant_uuid', 'slug']));
        self::assertTrue($this->guard()->active());
    }

    public function testRunResumesAfterInterruptedTable(): void
    {
        // Complete a full retrofit so every OTHER owned table is already widened.
        $this->schemaRetrofit()->run('t1', 'T1', 'user00000001');

        // Simulate an interrupt that left one table un-retrofitted: narrow content_types and forget its
        // progress, as if the process crashed before this table's phase completed.
        $this->narrowContentTypes();
        $this->progress()->reset('content_types');
        self::assertFalse($this->introspector()->columnExists('content_types', 'tenant_uuid'));

        // A fresh run must pick up and finish the outstanding table without error.
        $report = $this->schemaRetrofit()->run('t1', 'T1', 'user00000001');

        self::assertTrue($this->introspector()->columnNotNull('content_types', 'tenant_uuid'));
        self::assertTrue($this->introspector()->uniqueExists('content_types', ['tenant_uuid', 'slug']));
        self::assertSame('widened', $this->flags()->schemaState());
        self::assertContains('content_types', $report->widenedTables());
    }
}
