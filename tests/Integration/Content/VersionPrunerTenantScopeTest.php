<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\VersionRepository;
use App\Content\Retention\RetentionPolicy;
use App\Content\Retention\VersionPruner;
use App\Tests\Support\TenantOracleTestCase;

final class VersionPrunerTenantScopeTest extends TenantOracleTestCase
{
    private function versions(): VersionRepository
    {
        return $this->container()->get(VersionRepository::class);
    }

    private function buildLineage(string $entry, int $count): void
    {
        for ($version = 1; $version <= $count; $version++) {
            $this->versions()->appendVersion($entry, 'en', $version, ['title' => "v{$version}"], 1, 'user00000001');
        }
    }

    public function testSystemPathPrunesEachTenantLineageIndependently(): void
    {
        // Two tenants, each with a 5-version lineage on a DISTINCT global entry uuid. The version rows
        // are stamped with each tenant's uuid on insert (builder path inside runAsTenant).
        $this->runAsTenant(self::$tenantAUuid, fn () => $this->buildLineage('verentaaaaaa', 5));
        $this->runAsTenant(self::$tenantBUuid, fn () => $this->buildLineage('verentbbbbbb', 5));

        // The pruner is the background SYSTEM PATH: raw PDO, no tenant predicate, no tenant context.
        // It scans both tenants' lineages in one pass, keyed on the global entry uuid.
        $report = $this->runAsSystem(
            fn () => (new VersionPruner($this->connection()))->prune(new RetentionPolicy(2, null)),
        );

        // Global sweep deleted 3 from EACH lineage (5 → keep 2), never across the boundary.
        self::assertSame(6, $report->toArray()['versions_deleted']);

        // versionsFor() is a scoped builder read: each tenant sees exactly its own retained 2.
        $survivorsA = $this->runAsTenant(
            self::$tenantAUuid,
            fn () => $this->versions()->versionsFor('verentaaaaaa', 'en'),
        );
        $survivorsB = $this->runAsTenant(
            self::$tenantBUuid,
            fn () => $this->versions()->versionsFor('verentbbbbbb', 'en'),
        );

        self::assertCount(2, $survivorsA);
        self::assertCount(2, $survivorsB);
    }
}
