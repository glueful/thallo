<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use PDO;

final class CollectionsTenancySchemaTest extends CollectionsTestCase
{
    public function testMetadataTablesHaveTenantColumns(): void
    {
        self::assertContains('tenant_uuid', $this->columns('collection_definitions'));
        self::assertContains('tenant_uuid', $this->columns('collection_schema_changes'));
    }

    public function testDefinitionsUniquesAreNamedAndScoped(): void
    {
        $indexes = $this->indexDefinitions('collection_definitions');
        self::assertArrayHasKey('uniq_collection_def_tenant_name', $indexes);
        self::assertStringContainsString('(tenant_uuid, name)', $indexes['uniq_collection_def_tenant_name']);
        self::assertArrayHasKey('uniq_collection_def_table_name', $indexes);
        self::assertStringContainsString('(table_name)', $indexes['uniq_collection_def_table_name']);
    }

    public function testSchemaChangesHasTenantCollectionIndex(): void
    {
        $indexes = $this->indexDefinitions('collection_schema_changes');
        self::assertArrayHasKey('idx_collection_changes_tenant_collection', $indexes);
        self::assertStringContainsString(
            '(tenant_uuid, collection_uuid)',
            $indexes['idx_collection_changes_tenant_collection'],
        );
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $statement = $this->connection()->getPDO()->prepare(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position',
        );
        $statement->execute([$table]);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,string> */
    private function indexDefinitions(string $table): array
    {
        $statement = $this->connection()->getPDO()->prepare(
            'SELECT indexname, indexdef FROM pg_indexes WHERE tablename = ?',
        );
        $statement->execute([$table]);
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexes[(string) $row['indexname']] = (string) $row['indexdef'];
        }

        return $indexes;
    }
}
