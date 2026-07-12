<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Data\Actor;
use Thallo\Collections\Data\RowRepository;
use Thallo\Collections\Schema\CollectionDefinition;

/**
 * RowRepository::truncate() empties the collection table and resets the auto-increment id (a real
 * TRUNCATE), keeping the schema.
 */
final class RowTruncateTest extends CollectionsTestCase
{
    private const COL = 'trunc_test';

    private CollectionDefinition $def;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->def = $this->container()->get(CollectionManager::class)->create([
            'name' => self::COL,
            'label' => 'Trunc',
            'fields' => [
                ['name' => 'title', 'type' => 'collections.string', 'settings' => ['nullable' => false]],
            ],
        ], 'admin', 'u1');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testTruncateClearsAllRowsAndResetsId(): void
    {
        $repo = $this->container()->get(RowRepository::class);
        $actor = new Actor('admin', 'u1');

        $repo->create($this->def, ['title' => 'a'], $actor);
        $repo->create($this->def, ['title' => 'b'], $actor);

        $cleared = $repo->truncate($this->def, $actor);
        self::assertSame(2, $cleared);

        $remaining = (int) $this->connection()->table($this->def->tableName)->count();
        self::assertSame(0, $remaining, 'all rows are removed');

        // A real TRUNCATE resets the sequence — the next row starts again at id 1.
        $row = $repo->create($this->def, ['title' => 'c'], $actor);
        self::assertSame(1, (int) $row['id'], 'the auto-increment id is reset');
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
