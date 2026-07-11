<?php

declare(strict_types=1);

namespace App\Content\Starter;

use Thallo\Tenancy\Contracts\StaticWriteAudit;

/** Source-tree audit for raw PDO sites that bypass builder tenancy enforcement. */
final class RawPdoWriteAudit implements StaticWriteAudit
{
    private const SCOPED = [
        'packages/thallo-seo/src/Meta/SeoMetaRepository.php',
        'packages/thallo-navigation/src/MenuRepository.php',
        'packages/thallo-analytics/src/Facts/AnalyticsRecorder.php',
        'packages/thallo-workflow/src/WorkflowStateRepository.php',
        'app/Content/Blocks/Migration/BlockMigrationRepository.php',
        'app/Content/Repositories/MigrationRepository.php',
        'app/Content/Media/TenantBlobPolicy.php',
    ];

    private const SYSTEM_READERS = [
        'packages/thallo-analytics/src/Query/AnalyticsQuery.php',
        'app/Content/Repositories/VersionRepository.php',
        'packages/thallo-render/src/Templates/TemplateRepository.php',
        'packages/thallo-collections/src/Data/RowRepository.php',
        'packages/thallo-tenancy/src/Retrofit/SchemaIntrospector.php',
        'packages/thallo-tenancy/src/Retrofit/UniquenessPreflight.php',
        'packages/thallo-tenancy/src/Enablement/EnablementLock.php',
        'packages/thallo-tenancy/src/Retrofit/MutationBoundaryLock.php',
        'app/Support/AuthorityContinuityGuard.php',
        'app/Support/RoleAuthority.php',
        'packages/thallo-tenancy/src/Purge/Handlers/MediaPurgeHandler.php',
        'packages/thallo-tenancy/src/Purge/Handlers/TablesPurgeHandler.php',
        'packages/thallo-tenancy/src/Purge/PurgeCoordinator.php',
        'packages/thallo-tenancy/src/Purge/PurgeJob.php',
        'packages/thallo-tenancy/src/Purge/PurgeRunRepository.php',
        'packages/thallo-tenancy/src/Enablement/TenancyDiagnostics.php',
        'packages/thallo-tenancy/src/Reverification/DomainReverificationSweep.php',
    ];

    private const SYSTEM_WRITERS = [
        'app/Content/Repositories/ScheduleRepository.php',
        'app/Content/Retention/VersionPruner.php',
    ];

    private const GLOBAL_BY_PROOF = ['app/Content/Indexing/EnsureFilterIndexesJob.php'];

    private const RETROFIT_ENGINE = [
        'packages/thallo-tenancy/src/Retrofit/AdditiveRetrofit.php',
        'packages/thallo-tenancy/src/Retrofit/TableRebuilder.php',
        'packages/thallo-tenancy/src/TenancyServiceProvider.php',
        'packages/thallo-tenancy/src/Retrofit/MediaOwnershipBackfill.php',
        'packages/thallo-tenancy/migrations/002_CreateTenantPurgeRunsTable.php',
    ];

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

    public function __construct(private readonly string $basePath)
    {
    }

    public function available(): bool
    {
        return is_dir($this->basePath . '/app') && is_dir($this->basePath . '/packages');
    }

    public function run(): array
    {
        if (!$this->available()) {
            return ['available' => false, 'unclassified' => [], 'bucketViolations' => [], 'wrapperMismatches' => []];
        }

        $known = array_merge(
            self::SCOPED,
            self::SYSTEM_READERS,
            self::SYSTEM_WRITERS,
            self::GLOBAL_BY_PROOF,
            self::RETROFIT_ENGINE,
        );
        $found = [];
        foreach (['app', 'packages'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->basePath . '/' . $dir),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if ($file->getPathname() === __FILE__) {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), 'getPDO()')) {
                    $found[] = str_replace($this->basePath . '/', '', $file->getPathname());
                }
            }
        }

        $bucketViolations = [];
        foreach (self::SCOPED as $relative) {
            if (!str_contains($this->body($relative), 'tenant_uuid')) {
                $bucketViolations[] = $relative . ': missing tenant_uuid';
            }
        }

        $wrapperMismatches = [];
        foreach (self::RUNWRITABLE_SITES as $relative => $expected) {
            $actual = substr_count($this->body($relative), 'runWritable(');
            if ($actual !== $expected) {
                $wrapperMismatches[] = "{$relative}: expected {$expected}, found {$actual}";
            }
        }

        sort($found);
        return [
            'available' => true,
            'unclassified' => array_values(array_diff($found, $known)),
            'bucketViolations' => $bucketViolations,
            'wrapperMismatches' => $wrapperMismatches,
        ];
    }

    private function body(string $relative): string
    {
        return (string) file_get_contents($this->basePath . '/' . $relative);
    }
}
