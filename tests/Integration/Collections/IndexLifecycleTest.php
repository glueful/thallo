<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Data\Actor;
use Thallo\Collections\Data\RowRepository;
use Thallo\Collections\Schema\CollectionPhysicalName;

/**
 * Physical index lifecycle against real PostgreSQL. The index KIND (unique vs plain) is
 * fixed on the SchemaChange at plan time — these tests pin the transitions that used to
 * break when the materializer re-derived the kind from the field's post-change settings:
 * dropping a unique constraint was impossible, and removing a plain index from a
 * unique+indexed field silently dropped the unique constraint instead.
 */
final class IndexLifecycleTest extends CollectionsTestCase
{
    private const COL = 'idx_lifecycle';

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

    public function testUniqueConstraintCanBeDropped(): void
    {
        $manager = $this->manager();
        $def = $manager->create([
            'name' => self::COL,
            'fields' => [['name' => 'sku', 'type' => 'collections.string', 'settings' => ['unique' => true]]],
        ], 'admin', 'u1');

        self::assertContains($this->uniqueIndexName('sku'), $this->physicalIndexes());

        $manager->removeIndex(self::COL, 'sku', 'admin', 'u1');

        self::assertNotContains($this->uniqueIndexName('sku'), $this->physicalIndexes());

        // The constraint is really gone: duplicate values now insert cleanly.
        $rows = $this->container()->get(RowRepository::class);
        $freshDef = $this->reload();
        $actor = new Actor('admin', 'u1');
        $rows->create($freshDef, ['sku' => 'same'], $actor);
        $rows->create($freshDef, ['sku' => 'same'], $actor);
        self::assertSame(2, (int) $this->connection()->table($freshDef->tableName)->count());
    }

    public function testRemovingIndexesFromUniqueAndIndexedFieldDropsOnlyWhatExists(): void
    {
        // unique+index on the same column: only the unique index exists physically
        // (a unique constraint serves lookups; the plain index is never created).
        $manager = $this->manager();
        $manager->create([
            'name' => self::COL,
            'fields' => [[
                'name' => 'code',
                'type' => 'collections.string',
                'settings' => ['unique' => true, 'index' => true],
            ]],
        ], 'admin', 'u1');

        $indexes = $this->physicalIndexes();
        self::assertContains($this->uniqueIndexName('code'), $indexes);
        self::assertNotContains($this->plainIndexName('code'), $indexes, 'no redundant plain index');

        // removeIndex strips both settings; it must drop exactly the unique index
        // (previously it tried to drop a nonexistent plain index and errored).
        $manager->removeIndex(self::COL, 'code', 'admin', 'u1');

        $indexes = $this->physicalIndexes();
        self::assertNotContains($this->uniqueIndexName('code'), $indexes);
        self::assertNotContains($this->plainIndexName('code'), $indexes);
    }

    public function testPlainIndexOnNewFieldIsMaterialized(): void
    {
        // settings.index on a create payload used to be silently ignored.
        $this->manager()->create([
            'name' => self::COL,
            'fields' => [['name' => 'status', 'type' => 'collections.string', 'settings' => ['index' => true]]],
        ], 'admin', 'u1');

        self::assertContains($this->plainIndexName('status'), $this->physicalIndexes());
    }

    public function testPlainIndexAddAndDropViaAlter(): void
    {
        $manager = $this->manager();
        $manager->create([
            'name' => self::COL,
            'fields' => [['name' => 'status', 'type' => 'collections.string']],
        ], 'admin', 'u1');

        $manager->addIndex(self::COL, 'status', ['index' => true], 'admin', 'u1');
        self::assertContains($this->plainIndexName('status'), $this->physicalIndexes());

        $manager->removeIndex(self::COL, 'status', 'admin', 'u1');
        self::assertNotContains($this->plainIndexName('status'), $this->physicalIndexes());
    }

    public function testPromotingPlainIndexToUniqueSwapsThePhysicalIndex(): void
    {
        $manager = $this->manager();
        $manager->create([
            'name' => self::COL,
            'fields' => [['name' => 'slug', 'type' => 'collections.string', 'settings' => ['index' => true]]],
        ], 'admin', 'u1');
        self::assertContains($this->plainIndexName('slug'), $this->physicalIndexes());

        // Adding unique drops the now-redundant plain index and creates the unique one.
        $manager->addIndex(self::COL, 'slug', ['unique' => true], 'admin', 'u1');

        $indexes = $this->physicalIndexes();
        self::assertContains($this->uniqueIndexName('slug'), $indexes);
        self::assertNotContains($this->plainIndexName('slug'), $indexes);
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<string> */
    private function physicalIndexes(): array
    {
        $table = $this->physicalTable(self::COL);
        $stmt = $this->connection()->getPDO()->prepare(
            'SELECT indexname FROM pg_indexes WHERE tablename = :t',
        );
        $stmt->execute(['t' => $table]);

        /** @var list<string> */
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function uniqueIndexName(string $column): string
    {
        return CollectionPhysicalName::indexName($this->physicalTable(self::COL), $column, 'unique');
    }

    private function plainIndexName(string $column): string
    {
        return CollectionPhysicalName::indexName($this->physicalTable(self::COL), $column, 'plain');
    }

    private function reload(): \Thallo\Collections\Schema\CollectionDefinition
    {
        $def = $this->container()
            ->get(\Thallo\Collections\Repositories\CollectionDefinitionRepository::class)
            ->findByName(self::COL);
        self::assertNotNull($def);

        return $def;
    }

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function cleanup(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        $table = $this->physicalTable(self::COL);
        if ($schema->hasTable($table)) {
            $schema->dropTableIfExists($table);
        }
        $this->connection()->table('collection_definitions')->where('name', self::COL)->delete();
    }
}
