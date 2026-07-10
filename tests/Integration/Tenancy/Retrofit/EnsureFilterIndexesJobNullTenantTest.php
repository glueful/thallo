<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;

/**
 * The tenancy-off leg of the fail-closed handle(): an explicit `null` tenant_uuid reconciles DIRECTLY
 * (no tenant context) against the real engine. Proved here rather than in the pure-unit test because
 * the null branch actually invokes reconcile() → schemaFor() + the filter_indexes registry, which need
 * a real Connection + ContentTypeRepository. The two-tenant (scoped) path is Task 13.
 */
final class EnsureFilterIndexesJobNullTenantTest extends RetrofitHarnessTestCase
{
    protected function setUp(): void
    {
        // Lower any barrier a PRIOR test left up BEFORE parent::setUp() truncates owned tables.
        self::$engineApp?->getContainer()->get(RetrofitMaintenanceGuard::class)->end();
        parent::setUp();
        $this->container()->get(RetrofitMaintenanceGuard::class)->end();
        // A content type with an EMPTY schema → no desired filter indexes → reconcile does no DDL.
        $this->connection()->getPDO()->exec(
            "INSERT INTO content_types (uuid, slug, name, status, schema, schema_version, created_at)
             VALUES ('ctnull000001', 'n', 'N', 'active', '[]', 1, now()) ON CONFLICT (uuid) DO NOTHING"
        );
    }

    public function testExplicitNullTenantReconcilesDirectly(): void
    {
        $job = new EnsureFilterIndexesJob(
            ['content_type_uuid' => 'ctnull000001', 'tenant_uuid' => null],
            $this->appContext(),
        );

        // No exception: the explicit-null branch calls reconcile() directly (schemaFor read succeeded,
        // no tenant context required). A missing/invalid tenant_uuid would have thrown before reconcile.
        $job->handle();

        // Empty schema → no registry rows written.
        self::assertSame(
            [],
            $this->connection()->table('filter_indexes')
                ->where('content_type_uuid', '=', 'ctnull000001')->get(),
        );
    }
}
