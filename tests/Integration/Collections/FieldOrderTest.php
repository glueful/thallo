<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;

/**
 * field_order (display order of all columns, system + custom) is persisted on create and survives a
 * round-trip through the DB — addField reloads the definition via fromRow and preserves the order.
 */
final class FieldOrderTest extends AppTestCase
{
    private const COL = 'widgets';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropCollection();
    }

    protected function tearDown(): void
    {
        $this->dropCollection();
        parent::tearDown();
    }

    public function testFieldOrderIsStoredOnCreateAndSurvivesReload(): void
    {
        $manager = $this->container()->get(CollectionManager::class);

        $order = ['title', 'id', 'uuid', 'created_at', 'updated_at'];
        $def = $manager->create([
            'name' => self::COL,
            'label' => 'Widgets',
            'fields' => [['name' => 'title', 'type' => 'collections.string', 'settings' => []]],
            'field_order' => $order,
        ], 'admin', 'setup');

        self::assertSame($order, $def->fieldOrder);

        // addField reloads the definition from the DB (fromRow) and rebuilds it; the order must persist.
        $reloaded = $manager->addField(
            self::COL,
            ['name' => 'body', 'type' => 'collections.string', 'settings' => []],
            'admin',
            'setup',
        );

        self::assertSame($order, $reloaded->fieldOrder);
    }

    public function testSetFieldOrderReplacesTheStoredOrder(): void
    {
        $manager = $this->container()->get(CollectionManager::class);
        $manager->create([
            'name' => self::COL,
            'label' => 'Widgets',
            'fields' => [['name' => 'title', 'type' => 'collections.string', 'settings' => []]],
            'field_order' => ['id', 'title'],
        ], 'admin', 'setup');

        $next = ['title', 'id', 'uuid', 'created_at', 'updated_at'];
        $updated = $manager->setFieldOrder(self::COL, $next);
        self::assertSame($next, $updated->fieldOrder);

        // Survives a reload from the DB.
        $reloaded = $manager->addField(
            self::COL,
            ['name' => 'extra', 'type' => 'collections.string', 'settings' => []],
            'admin',
            'setup',
        );
        self::assertSame($next, $reloaded->fieldOrder);
    }

    private function dropCollection(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        $table = CollectionManager::tableNameFor(self::COL);
        if ($schema->hasTable($table)) {
            $schema->dropTableIfExists($table);
        }
        $this->connection()->table('collection_definitions')->where('name', self::COL)->delete();
    }
}
