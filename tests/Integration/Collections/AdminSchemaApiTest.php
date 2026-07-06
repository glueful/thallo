<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Data\Actor;
use Thallo\Collections\Data\RowRepository;
use Thallo\Collections\Http\Controllers\CollectionAdminSchemaController;
use Thallo\Collections\Http\DTOs\AddIndexData;
use Thallo\Collections\Http\DTOs\CreateCollectionData;
use Thallo\Collections\Http\DTOs\FieldData;
use Thallo\Collections\Http\DTOs\UpdateAccessData;
use Thallo\Collections\Repositories\CollectionDefinitionRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives CollectionAdminSchemaController through the container (routes arrive in a later task),
 * asserting the Response and the persisted definition — including the access-policy control.
 */
final class AdminSchemaApiTest extends AppTestCase
{
    private const NAME = 'gadgets';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropCollection(self::NAME);
    }

    protected function tearDown(): void
    {
        $this->dropCollection(self::NAME);
        parent::tearDown();
    }

    public function testStoreCreatesCollectionWithAccessPolicyAndIndexLists(): void
    {
        $dto = new CreateCollectionData(
            name: self::NAME,
            label: 'Gadgets',
            fields: [new FieldData('title', 'collections.string', ['nullable' => false])],
            access: ['read' => 'public', 'write' => 'scoped', 'delete' => 'scoped'],
        );

        $created = $this->controller()->store($dto, $this->request());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $listed = $this->controller()->index($this->request());
        self::assertSame(200, $listed->getStatusCode());

        $definition = $this->defRepo()->findByName(self::NAME);
        self::assertNotNull($definition);
        self::assertSame('public', $definition->accessPolicy->read);
        self::assertSame('scoped', $definition->accessPolicy->write);
    }

    public function testUnsupportedFieldTypeReturns422(): void
    {
        $dto = new CreateCollectionData(self::NAME, 'Gadgets', [new FieldData('x', 'not-a-type', [])]);

        $response = $this->controller()->store($dto, $this->request());

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testAddFieldAndAddIndexSucceed(): void
    {
        $this->seed();

        $addField = $this->controller()->addField(
            new FieldData('qty', 'collections.integer', ['nullable' => true]),
            $this->request(),
            self::NAME,
        );
        self::assertSame(200, $addField->getStatusCode(), (string) $addField->getContent());

        $addIndex = $this->controller()->addIndex(new AddIndexData('qty', true), $this->request(), self::NAME);
        self::assertSame(200, $addIndex->getStatusCode(), (string) $addIndex->getContent());
    }

    public function testUpdateAccessReplacesThePolicy(): void
    {
        $this->seed(); // defaults to all-scoped

        $response = $this->controller()->updateAccess(
            new UpdateAccessData(read: 'public'),
            $this->request(),
            self::NAME,
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $definition = $this->defRepo()->findByName(self::NAME);
        self::assertNotNull($definition);
        self::assertSame('public', $definition->accessPolicy->read);
        self::assertSame('scoped', $definition->accessPolicy->write);
    }

    public function testDropFieldWithoutConfirmOnPopulatedTableReturns409(): void
    {
        $this->seed();
        $definition = $this->defRepo()->findByName(self::NAME);
        self::assertNotNull($definition);
        $this->container()->get(RowRepository::class)
            ->create($definition, ['title' => 'A row'], new Actor('admin', 'test'));

        $response = $this->controller()->dropField($this->request(), self::NAME, 'title');

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testDestroyWithConfirmReturns200(): void
    {
        $this->seed();

        $response = $this->controller()->destroy($this->request(['confirm' => self::NAME]), self::NAME);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertNull($this->defRepo()->findByName(self::NAME));
    }

    private function seed(): void
    {
        $this->controller()->store(
            new CreateCollectionData(
                self::NAME,
                'Gadgets',
                [new FieldData('title', 'collections.string', ['nullable' => false])],
            ),
            $this->request(),
        );
    }

    private function controller(): CollectionAdminSchemaController
    {
        return $this->container()->get(CollectionAdminSchemaController::class);
    }

    private function defRepo(): CollectionDefinitionRepository
    {
        return $this->container()->get(CollectionDefinitionRepository::class);
    }

    /** @param array<string, mixed>|null $body */
    private function request(?array $body = null): Request
    {
        return Request::create('/', 'POST', [], [], [], [], $body === null ? null : (string) json_encode($body));
    }

    private function dropCollection(string $name): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        $table  = CollectionManager::tableNameFor($name);
        if ($schema->hasTable($table)) {
            $schema->dropTableIfExists($table);
        }
        $this->connection()->table('collection_definitions')->where('name', $name)->delete();
    }
}
