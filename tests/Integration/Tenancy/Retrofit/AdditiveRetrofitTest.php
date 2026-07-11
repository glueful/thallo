<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Tenancy\Retrofit\AdditiveRetrofit;
use Thallo\Tenancy\Retrofit\DefaultTenant;
use Thallo\Tenancy\Retrofit\RetrofitProgress;
use Thallo\Tenancy\Retrofit\SchemaIntrospector;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Exercises the additive per-table retrofit path against the narrow throwaway DB (tenancy bound,
 * scoping off). `content_types` starts narrow: no `tenant_uuid` column, a single-column unique on
 * `slug`, and `id` the only NOT NULL column. After apply() the table is stamped with the default
 * tenant, `tenant_uuid` is NOT NULL, the narrow `slug` unique is gone, and the widened
 * `(tenant_uuid, slug)` unique exists.
 */
final class AdditiveRetrofitTest extends RetrofitHarnessTestCase
{
    private function additive(): AdditiveRetrofit
    {
        return $this->container()->get(AdditiveRetrofit::class);
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

    protected function setUp(): void
    {
        parent::setUp();
        // Reset per-operation + progress state and clear owned/registry rows left by a prior test.
        $this->flags()->forget('tenancy.provisioning_tenant_uuid');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        $this->flags()->forget('tenancy.retrofit_progress');
        $pdo = $this->connection()->getPDO();
        // content_types may have been widened by a prior test in this class — restore narrow shape.
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
        $pdo->exec('DELETE FROM tenant_memberships');
        $pdo->exec('DELETE FROM tenants');
    }

    private function seedNarrowRows(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec(
            "INSERT INTO content_types (uuid, slug, name, status, schema, schema_version)
             VALUES ('ctadd0000001', 'article', 'Article', 'active', '[]', 1),
                    ('ctadd0000002', 'page', 'Page', 'active', '[]', 1)"
        );
    }

    public function testApplyWidensContentTypesAdditively(): void
    {
        $this->seedNarrowRows();
        $tenantUuid = $this->container()->get(DefaultTenant::class)
            ->ensure('t1', 'T1', 'user00000001');

        // Precondition: narrow shape.
        self::assertFalse($this->introspector()->columnNotNull('content_types', 'tenant_uuid'));
        self::assertTrue($this->introspector()->uniqueExists('content_types', ['slug']));

        $this->additive()->apply('content_types');

        // Column exists + NOT NULL.
        self::assertTrue($this->introspector()->columnNotNull('content_types', 'tenant_uuid'));

        // Every seeded row was backfilled with the default tenant.
        $rows = $this->connection()->getPDO()
            ->query('SELECT tenant_uuid FROM content_types')
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotEmpty($rows);
        foreach ($rows as $value) {
            self::assertSame($tenantUuid, $value);
        }

        // Narrow unique gone, widened unique present.
        self::assertFalse($this->introspector()->uniqueExists('content_types', ['slug']));
        self::assertTrue($this->introspector()->uniqueExists('content_types', ['tenant_uuid', 'slug']));
        // The global uuid unique is untouched.
        self::assertTrue($this->introspector()->uniqueExists('content_types', ['uuid']));
    }

    public function testApplyIsIdempotent(): void
    {
        $this->seedNarrowRows();
        $this->container()->get(DefaultTenant::class)->ensure('t1', 'T1', 'user00000001');

        $this->additive()->apply('content_types');
        // Second apply must not error and must leave state unchanged.
        $this->additive()->apply('content_types');

        self::assertTrue($this->introspector()->columnNotNull('content_types', 'tenant_uuid'));
        self::assertFalse($this->introspector()->uniqueExists('content_types', ['slug']));
        self::assertTrue($this->introspector()->uniqueExists('content_types', ['tenant_uuid', 'slug']));
    }

    public function testApplyResumesFromColumnAddedWithColumnPresent(): void
    {
        $this->seedNarrowRows();
        $this->container()->get(DefaultTenant::class)->ensure('t1', 'T1', 'user00000001');

        // Simulate a crash after the column was added: the DDL is live but progress only records
        // COLUMN_ADDED. A fresh apply() must complete the remaining phases.
        $this->connection()->getPDO()->exec('ALTER TABLE content_types ADD COLUMN tenant_uuid VARCHAR(12)');
        $this->progress()->mark('content_types', RetrofitProgress::COLUMN_ADDED);

        $this->additive()->apply('content_types');

        self::assertTrue($this->introspector()->columnNotNull('content_types', 'tenant_uuid'));
        self::assertFalse($this->introspector()->uniqueExists('content_types', ['slug']));
        self::assertTrue($this->introspector()->uniqueExists('content_types', ['tenant_uuid', 'slug']));
        self::assertTrue(
            $this->progress()->reached('content_types', RetrofitProgress::WIDENED_UNIQUE_ADDED)
        );
    }
}
