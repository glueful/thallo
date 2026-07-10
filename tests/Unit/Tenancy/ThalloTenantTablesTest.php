<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Thallo\Tenancy\ThalloTenantTables;

final class ThalloTenantTablesTest extends TestCase
{
    public function testCoreOwnedTablesArePresent(): void
    {
        $names = ThalloTenantTables::tableNames();
        $core = ['content_types', 'entries', 'entry_routes', 'block_types', 'regions', 'settings', 'form_submissions'];
        foreach ($core as $t) {
            self::assertContains($t, $names, "$t must be tenant-owned");
        }
    }

    public function testCollectionsAreExcluded(): void
    {
        $names = ThalloTenantTables::tableNames();
        self::assertNotContains('collection_definitions', $names);
        self::assertNotContains('collection_schema_changes', $names);
    }

    public function testSystemChannelTableIsNotOwned(): void
    {
        // thallo_system_flags MUST stay unscoped — it is read/written before tenant resolution.
        self::assertNotContains('thallo_system_flags', ThalloTenantTables::tableNames());
    }

    public function testSettingsIsInstanceNotDefinition(): void
    {
        // Site settings are per-tenant DATA/config, not schema definition (affects divergence checks).
        self::assertSame('instance', ThalloTenantTables::all()['settings']['kind']);
    }

    public function testEveryEntryCarriesRequiredMetadata(): void
    {
        foreach (ThalloTenantTables::all() as $table => $meta) {
            self::assertSame('tenant_uuid', $meta['tenant_column'], "$table tenant_column");
            self::assertContains($meta['kind'], ['definition', 'instance'], "$table kind");
            self::assertIsArray($meta['widened_uniques'], "$table widened_uniques");
            self::assertIsArray($meta['indexes'], "$table indexes");
        }
    }

    public function testRebuildTablesAreMarked(): void
    {
        $all = ThalloTenantTables::all();
        foreach (['regions', 'settings', 'entry_redirects'] as $t) {
            self::assertSame('rebuild', $all[$t]['special_backfill'] ?? null, "$t needs a rebuild backfill");
        }
    }

    public function testUpsertTablesHaveWidenedUniques(): void
    {
        $all = ThalloTenantTables::all();
        self::assertSame(
            [['uniq_workflow_state_entry_locale', ['tenant_uuid', 'entry_uuid', 'locale']]],
            $all['workflow_review_states']['widened_uniques'],
        );
        self::assertSame(
            [[null, ['tenant_uuid', 'day', 'event', 'subject']]],
            $all['analytics_daily']['widened_uniques'],
        );
        self::assertSame(
            [[null, ['tenant_uuid', 'day', 'metric', 'actor_type', 'actor_id_hash']]],
            $all['analytics_active_actors']['widened_uniques'],
        );
    }
}
