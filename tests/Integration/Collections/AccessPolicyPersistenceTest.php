<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Repositories\CollectionDefinitionRepository;

/**
 * The per-collection access policy survives a create → load round-trip through the
 * collection_definitions.access_policy column.
 */
final class AccessPolicyPersistenceTest extends AppTestCase
{
    private const NAMES = ['articles', 'secrets'];

    protected function setUp(): void
    {
        parent::setUp();

        // Collection creation materializes real tables (DDL is not rolled back with the test
        // transaction), so drop any leftovers from a prior run before recreating.
        foreach (self::NAMES as $name) {
            $table = CollectionManager::tableNameFor($name);
            if ($this->schema()->hasTable($table)) {
                $this->schema()->dropTableIfExists($table);
            }
            $this->connection()->table('collection_definitions')->where('name', $name)->delete();
        }
    }

    public function testCreateWithAccessPolicyRoundTripsThroughTheDatabase(): void
    {
        $this->manager()->create([
            'name' => 'articles',
            'label' => 'Articles',
            'fields' => [['name' => 'title', 'type' => 'collections.string', 'settings' => []]],
            'access' => ['read' => 'public', 'write' => 'scoped', 'delete' => 'scoped'],
        ], 'admin', 'u-1');

        $loaded = $this->repo()->findByName('articles');

        self::assertNotNull($loaded);
        self::assertSame('public', $loaded->accessPolicy->read);
        self::assertSame('scoped', $loaded->accessPolicy->write);
        self::assertSame('scoped', $loaded->accessPolicy->delete);
    }

    public function testCreateWithoutAccessDefaultsToAllScoped(): void
    {
        $this->manager()->create([
            'name' => 'secrets',
            'label' => 'Secrets',
            'fields' => [['name' => 'value', 'type' => 'collections.string', 'settings' => []]],
        ], 'admin', 'u-1');

        $loaded = $this->repo()->findByName('secrets');

        self::assertNotNull($loaded);
        self::assertSame('scoped', $loaded->accessPolicy->read);
        self::assertSame('scoped', $loaded->accessPolicy->write);
        self::assertSame('scoped', $loaded->accessPolicy->delete);
    }

    private function schema(): SchemaBuilderInterface
    {
        return $this->container()->get(SchemaBuilderInterface::class);
    }

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function repo(): CollectionDefinitionRepository
    {
        return $this->container()->get(CollectionDefinitionRepository::class);
    }
}
