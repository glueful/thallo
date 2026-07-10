<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;

/**
 * Guards the raw-PDO surface. Raw SQL bypasses the tenancy guard/hook AND the retrofit write-barrier
 * interceptor (which only sees builder mutations at QueryExecutor). So every getPDO() site over an
 * owned table must (a) carry tenant_uuid scoping and (b), if it WRITES, call the WriteBarrier's
 * assertWritable() before mutating. A NEW getPDO() file forces a conscious read-vs-write classification
 * here.
 */
final class RawPdoScopingLintTest extends TestCase
{
    /**
     * Owned-table raw WRITERS on the request path. MUST reference tenant_uuid (smoke) AND gate every
     * raw mutation behind the WriteBarrier (assertWritable).
     */
    private const SCOPED = [
        'packages/thallo-seo/src/Meta/SeoMetaRepository.php',
        'packages/thallo-navigation/src/MenuRepository.php',
        'packages/thallo-analytics/src/Facts/AnalyticsRecorder.php',
        'packages/thallo-workflow/src/WorkflowStateRepository.php',
        'app/Content/Blocks/Migration/BlockMigrationRepository.php',
        'app/Content/Repositories/MigrationRepository.php',
        'app/Content/Media/TenantBlobPolicy.php',
    ];

    /**
     * Raw READERS (or transaction-control / advisory-lock / non-owned writes): reviewed, no owned-row
     * mutation, so no barrier gate is required.
     *  - AnalyticsQuery / VersionRepository: read-only + pg_advisory_xact_lock.
     *  - TemplateRepository: getPDO() is transaction control only; the render_templates writes go
     *    through the BUILDER (covered by the interceptor).
     *  - RowRepository: TRUNCATE targets a dynamic collection table (collections are NOT owned).
     *  - SchemaIntrospector / UniquenessPreflight: retrofit engine introspection/preflight READS.
     */
    private const SYSTEM_READERS = [
        'packages/thallo-analytics/src/Query/AnalyticsQuery.php',
        'app/Content/Repositories/VersionRepository.php',
        'packages/thallo-render/src/Templates/TemplateRepository.php',
        'packages/thallo-collections/src/Data/RowRepository.php',
        'packages/thallo-tenancy/src/Retrofit/SchemaIntrospector.php',
        'packages/thallo-tenancy/src/Retrofit/UniquenessPreflight.php',
        'packages/thallo-tenancy/src/Enablement/EnablementLock.php',
        'packages/thallo-tenancy/src/Retrofit/MutationBoundaryLock.php',
    ];

    /**
     * Named SYSTEM-PATH raw WRITERS over owned data (cross-tenant drains, keyed on global surrogates —
     * no per-row tenant predicate by design). MUST gate every raw mutation behind assertWritable().
     */
    private const SYSTEM_WRITERS = [
        'app/Content/Repositories/ScheduleRepository.php',
        'app/Content/Retention/VersionPruner.php',
    ];

    /**
     * DELIBERATELY-GLOBAL infrastructure raw writers: touch the un-owned `filter_indexes` registry and
     * the shared `entry_versions` expression-index DDL. Kept out of the owned set with a documented
     * three-part proof in ThalloTenantTables; still MUST gate every raw mutation behind assertWritable().
     */
    private const GLOBAL_BY_PROOF = [
        'app/Content/Indexing/EnsureFilterIndexesJob.php',
    ];

    /**
     * The retrofit ENGINE's own raw DDL/DML (AdditiveRetrofit / TableRebuilder) and the provider's
     * driver-detection getPDO() (TenancyServiceProvider's RetrofitDdl factory). These deliberately
     * bypass the WriteBarrier — they ARE the retrofit, executing WHILE the barrier is up via raw PDO
     * outside QueryExecutor (a single synchronous, operation-invoked pass). Gating them behind
     * assertWritable() would self-block the retrofit, so they are barrier-EXEMPT by design. They never
     * run on the request/queue path; the barrier + runner gates keep every OTHER writer out during the
     * window, and the engine's own writes target the enable-time schema it is transforming.
     */
    private const RETROFIT_ENGINE = [
        'packages/thallo-tenancy/src/Retrofit/AdditiveRetrofit.php',
        'packages/thallo-tenancy/src/Retrofit/TableRebuilder.php',
        'packages/thallo-tenancy/src/TenancyServiceProvider.php',
        'packages/thallo-tenancy/src/Retrofit/MediaOwnershipBackfill.php',
    ];

    /**
     * Targeted: file => list of required source fragments proving the ACTUAL scoping construct
     * (not just a comment). Fragments are the exact strings introduced by the B2a fixes.
     * @var array<string, list<string>>
     */
    private const REQUIRED_FRAGMENTS = [
        'packages/thallo-seo/src/Meta/SeoMetaRepository.php' => [
            "array_unshift(\$conflict, 'tenant_uuid')",
            "\$insert['tenant_uuid'] = \$tenant",
        ],
        'packages/thallo-workflow/src/WorkflowStateRepository.php' => [
            "array_unshift(\$conflict, 'tenant_uuid')",
            ' AND tenant_uuid = ?',
        ],
        'packages/thallo-analytics/src/Facts/AnalyticsRecorder.php' => [
            "array_unshift(\$conflict, 'tenant_uuid')",
        ],
        'packages/thallo-analytics/src/Query/AnalyticsQuery.php' => [
            ' AND tenant_uuid = ?',
        ],
        'packages/thallo-navigation/src/MenuRepository.php' => [
            'slug = ? AND tenant_uuid = ?',   // reorderMenus (critical)
            'i.tenant_uuid = m.tenant_uuid',  // listMenus join scoping
        ],
        'app/Content/Blocks/Migration/BlockMigrationRepository.php' => [
            ' AND tenant_uuid = :tenant',
        ],
        'app/Content/Repositories/MigrationRepository.php' => [
            ' AND tenant_uuid = :tenant',
        ],
        'app/Content/Media/TenantBlobPolicy.php' => [
            'runWritable(',
            'ON CONFLICT (blob_uuid) DO NOTHING',
        ],
    ];

    /** @var array<string,int> file => one runWritable wrapper per mutation boundary */
    private const RUNWRITABLE_SITES = [
        'packages/thallo-seo/src/Meta/SeoMetaRepository.php' => 1,
        'packages/thallo-navigation/src/MenuRepository.php' => 3,
        'packages/thallo-analytics/src/Facts/AnalyticsRecorder.php' => 2,
        'packages/thallo-workflow/src/WorkflowStateRepository.php' => 1,
        'app/Content/Blocks/Migration/BlockMigrationRepository.php' => 1,
        'app/Content/Repositories/MigrationRepository.php' => 1,
        'app/Content/Repositories/ScheduleRepository.php' => 3,
        'app/Content/Retention/VersionPruner.php' => 1,
        'app/Content/Indexing/EnsureFilterIndexesJob.php' => 5,
        'app/Content/Media/TenantBlobPolicy.php' => 1,
    ];

    public function testEveryScopedRawSiteReferencesTenantUuid(): void
    {
        foreach (self::SCOPED as $rel) {
            $body = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            self::assertStringContainsString('tenant_uuid', $body, "$rel: no tenant_uuid scoping");
        }
    }

    public function testEveryOwnedRawWriterGatesTheWriteBarrier(): void
    {
        $writers = array_merge(self::SCOPED, self::SYSTEM_WRITERS, self::GLOBAL_BY_PROOF);
        foreach ($writers as $rel) {
            $body = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            self::assertStringContainsString(
                'runWritable(',
                $body,
                "$rel: raw owned-data writer must hold the WriteBarrier mutation boundary.",
            );
        }
    }

    public function testEveryRawMutationSiteIsWrappedInRunWritable(): void
    {
        foreach (self::RUNWRITABLE_SITES as $rel => $expected) {
            $body = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            self::assertSame(
                $expected,
                substr_count($body, 'runWritable('),
                "$rel: expected one runWritable() wrapper per mutation boundary.",
            );
            self::assertStringNotContainsString("?->assertWritable();\n", $body);
        }
    }

    public function testFilterIndexesIsDocumentedDeliberatelyGlobal(): void
    {
        $tables = (string) file_get_contents(
            dirname(__DIR__, 3) . '/packages/thallo-tenancy/src/ThalloTenantTables.php'
        );
        // The proof must be documented in the single owned-table registry...
        self::assertStringContainsString('DELIBERATELY-GLOBAL', $tables);
        self::assertStringContainsString('filter_indexes', $tables);
        // ...and filter_indexes must NOT appear as an owned table key.
        self::assertStringNotContainsString("'filter_indexes' =>", $tables);
    }

    public function testCriticalScopingConstructsArePresent(): void
    {
        foreach (self::REQUIRED_FRAGMENTS as $rel => $fragments) {
            $body = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            foreach ($fragments as $fragment) {
                self::assertStringContainsString(
                    $fragment,
                    $body,
                    "$rel lost its critical scoping construct: {$fragment}",
                );
            }
        }
    }

    public function testNoUnclassifiedGetPdoSites(): void
    {
        $known = array_merge(
            self::SCOPED,
            self::SYSTEM_READERS,
            self::SYSTEM_WRITERS,
            self::GLOBAL_BY_PROOF,
            self::RETROFIT_ENGINE,
        );
        $root = dirname(__DIR__, 3);
        $found = [];
        foreach (['app', 'packages'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), 'getPDO()')) {
                    $found[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }
        sort($found);
        self::assertSame(
            [],
            array_values(array_diff($found, $known)),
            'New getPDO() site(s) must be classified in this lint (read-vs-write).',
        );
    }
}
