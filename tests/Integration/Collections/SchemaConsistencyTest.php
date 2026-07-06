<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Repositories\CollectionDefinitionRepository;
use Thallo\Collections\Schema\CollectionDefinition;

/**
 * Definition ↔ physical-table consistency: every manager mutation commits the definition
 * write and its DDL in ONE transaction (DDL is transactional on PostgreSQL), and definition
 * updates are guarded by schema_version so a concurrent change can never be silently lost.
 */
final class SchemaConsistencyTest extends AppTestCase
{
    private const COL = 'consistency_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testFailedCreateLeavesNoResidueAndNameStaysCreatable(): void
    {
        // Duplicate field names pass create() validation today but make the CREATE TABLE
        // fail — a mid-create DDL failure. The transaction must roll back the definition
        // row with it: no orphan table, no dangling definition, and the name immediately
        // reusable. (If validation later rejects this payload before DDL, every assertion
        // below still holds.)
        try {
            $this->manager()->create([
                'name' => self::COL,
                'fields' => [
                    ['name' => 'title', 'type' => 'collections.string'],
                    ['name' => 'title', 'type' => 'collections.string'],
                ],
            ], 'admin', 'u1');
            self::fail('create() with duplicate field names should throw');
        } catch (\Throwable) {
            // expected
        }

        $table = CollectionManager::tableNameFor(self::COL);
        self::assertFalse($this->schema()->hasTable($table), 'no orphan table survives the rollback');
        self::assertNull($this->definitions()->findByName(self::COL), 'no dangling definition row survives');

        // The name is immediately creatable again.
        $def = $this->manager()->create([
            'name' => self::COL,
            'fields' => [['name' => 'title', 'type' => 'collections.string']],
        ], 'admin', 'u1');
        self::assertSame(self::COL, $def->name);
        self::assertTrue($this->schema()->hasTable($table));
    }

    public function testVersionGuardedUpdateWritesNothingOnStaleVersion(): void
    {
        $def = $this->manager()->create([
            'name' => self::COL,
            'fields' => [['name' => 'title', 'type' => 'collections.string']],
        ], 'admin', 'u1');

        $stale = new CollectionDefinition(
            uuid: $def->uuid,
            name: $def->name,
            label: 'Should Never Persist',
            tableName: $def->tableName,
            storageMode: $def->storageMode,
            fields: $def->fields,
            schemaVersion: $def->schemaVersion + 1,
            status: $def->status,
            accessPolicy: $def->accessPolicy,
            fieldOrder: $def->fieldOrder,
        );

        // Guard mismatch (the row is at $def->schemaVersion, we claim it was at +1) → 0 rows.
        self::assertSame(0, $this->definitions()->update($stale, expectedSchemaVersion: $def->schemaVersion + 1));
        $reloaded = $this->definitions()->findByName(self::COL);
        self::assertNotNull($reloaded);
        self::assertSame($def->label, $reloaded->label, 'a lost guard writes nothing');

        // Correct expected version → 1 row.
        self::assertSame(1, $this->definitions()->update($stale, expectedSchemaVersion: $def->schemaVersion));
        $reloaded = $this->definitions()->findByName(self::COL);
        self::assertNotNull($reloaded);
        self::assertSame($def->schemaVersion + 1, $reloaded->schemaVersion);
    }

    public function testAlterBumpsVersionAndKeepsTableInSync(): void
    {
        $this->manager()->create([
            'name' => self::COL,
            'fields' => [['name' => 'title', 'type' => 'collections.string']],
        ], 'admin', 'u1');

        $next = $this->manager()->addField(self::COL, [
            'name' => 'summary',
            'type' => 'collections.text',
        ], 'admin', 'u1');

        self::assertSame(2, $next->schemaVersion);
        self::assertTrue(
            $this->schema()->hasColumn(CollectionManager::tableNameFor(self::COL), 'summary'),
            'the column exists exactly when the definition says it does',
        );
    }

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function definitions(): CollectionDefinitionRepository
    {
        return $this->container()->get(CollectionDefinitionRepository::class);
    }

    private function schema(): SchemaBuilderInterface
    {
        return $this->container()->get(SchemaBuilderInterface::class);
    }

    private function cleanup(): void
    {
        $schema = $this->schema();
        $table = CollectionManager::tableNameFor(self::COL);
        if ($schema->hasTable($table)) {
            $schema->dropTableIfExists($table);
        }
        $this->connection()->table('collection_definitions')->where('name', self::COL)->delete();
    }
}
