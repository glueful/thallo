<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Data\Actor;
use Thallo\Collections\Data\RowRepository;
use Thallo\Collections\Data\RowValidator;
use Thallo\Collections\Exceptions\RowNotFoundException;
use Thallo\Collections\Exceptions\RowValidationException;
use Thallo\Collections\Schema\CollectionDefinition;
use Thallo\Collections\Schema\CollectionField;

final class RowCrudTest extends AppTestCase
{
    private const COLLECTION_NAME = 'rowcrudtest';

    private CollectionDefinition $def;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->reset();
        $this->cleanupBlobs();
        $this->cleanupCollection();

        // Build a collection with various field types to cover all validation scenarios.
        $this->def = $this->manager()->create([
            'name'   => self::COLLECTION_NAME,
            'label'  => 'Row Crud Test',
            'fields' => [
                // Non-nullable text (required).
                [
                    'name'     => 'title',
                    'type'     => 'collections.string',
                    'settings' => ['nullable' => false],
                ],
                // Nullable longtext (optional).
                [
                    'name'     => 'body',
                    'type'     => 'collections.text',
                    'settings' => ['nullable' => true],
                ],
                // Nullable integer (for type-coercion test).
                [
                    'name'     => 'score',
                    'type'     => 'collections.integer',
                    'settings' => ['nullable' => true],
                ],
                // Nullable enum (for enum rejection test).
                [
                    'name'     => 'status',
                    'type'     => 'collections.enum',
                    'settings' => ['nullable' => true, 'values' => ['draft', 'published']],
                ],
                // Nullable email (for format rejection test).
                [
                    'name'     => 'contact_email',
                    'type'     => 'collections.email',
                    'settings' => ['nullable' => true],
                ],
                // Nullable unique text (for unique-constraint tests).
                [
                    'name'     => 'slug',
                    'type'     => 'collections.string',
                    'settings' => ['nullable' => true, 'unique' => true],
                ],
                // Nullable single asset (for asset-existence tests).
                [
                    'name'     => 'cover',
                    'type'     => 'collections.asset',
                    'settings' => ['nullable' => true, 'multi' => false],
                ],
                // Nullable multi asset (for multi-asset-existence tests).
                [
                    'name'     => 'gallery',
                    'type'     => 'collections.asset',
                    'settings' => ['nullable' => true, 'multi' => true],
                ],
            ],
        ], 'admin', 'u1');
    }

    protected function tearDown(): void
    {
        $this->schema()->reset();
        $this->cleanupBlobs();
        $this->cleanupCollection();

        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers

    private function schema(): SchemaBuilderInterface
    {
        return $this->container()->get(SchemaBuilderInterface::class);
    }

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function repo(): RowRepository
    {
        return $this->container()->get(RowRepository::class);
    }

    private function adminActor(): Actor
    {
        return new Actor('admin', 'user-001');
    }

    private function tableNameFor(string $name): string
    {
        return CollectionManager::tableNameFor($name);
    }

    private function cleanupCollection(): void
    {
        $table = $this->tableNameFor(self::COLLECTION_NAME);
        if ($this->schema()->hasTable($table)) {
            $this->schema()->dropTableIfExists($table);
        }
        $this->connection()->table('collection_definitions')
            ->where('name', self::COLLECTION_NAME)
            ->delete();
        $this->connection()->table('collection_schema_changes')
            ->where('id', '>', 0)
            ->delete();
    }

    private function cleanupBlobs(): void
    {
        $this->connection()->getPDO()->exec("DELETE FROM blobs WHERE uuid LIKE 'tstblob%'");
    }

    /**
     * Seed a blob with a 12-character UUID (blobs.uuid is varchar(12)).
     */
    private function seedBlob(string $uuid): string
    {
        $this->connection()->table('blobs')->insert([
            'uuid'         => $uuid,
            'name'         => $uuid . '.jpg',
            'mime_type'    => 'image/jpeg',
            'size'         => 1024,
            'url'          => '/uploads/' . $uuid . '.jpg',
            'storage_type' => 'uploads',
            'visibility'   => 'private',
            'status'       => 'active',
            'created_by'   => 'tstblob_usr',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    // ----------------------------------------------------------------- tests

    /**
     * create() stamps uuid, timestamps, and actor columns; find() retrieves the same row.
     */
    public function testCreateStampsActorUuidTimestampsAndRoundTrips(): void
    {
        $actor = $this->adminActor();
        $row   = $this->repo()->create($this->def, ['title' => 'Hello World'], $actor);

        self::assertNotEmpty($row['uuid']);
        self::assertNotEmpty($row['created_at']);
        self::assertNotEmpty($row['updated_at']);
        self::assertSame('admin', (string) $row['created_by_type']);
        self::assertSame('user-001', (string) $row['created_by_id']);
        self::assertSame('admin', (string) $row['updated_by_type']);
        self::assertSame('user-001', (string) $row['updated_by_id']);
        self::assertSame('Hello World', (string) $row['title']);

        // find() must return the same row.
        $found = $this->repo()->find($this->def, (string) $row['uuid']);
        self::assertSame((string) $row['uuid'], (string) $found['uuid']);
        self::assertSame('Hello World', (string) $found['title']);
    }

    /**
     * A non-nullable field absent from input throws RowValidationException with a per-field error.
     */
    public function testRequiredFieldMissingThrowsRowValidationException(): void
    {
        $caught = null;
        try {
            // 'title' is non-nullable but absent from this input.
            $this->repo()->create($this->def, ['score' => 42], $this->adminActor());
        } catch (RowValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'RowValidationException must be thrown when a required field is absent');
        self::assertArrayHasKey('title', $caught->errors());
    }

    /**
     * update() is partial: it only mutates provided fields and stamps updated_by_*.
     */
    public function testPartialUpdateTouchesOnlyGivenFieldsAndStampsUpdatedBy(): void
    {
        $repo    = $this->repo();
        $creator = new Actor('admin', 'u1');
        $updater = new Actor('api_key', 'key-abc');

        $created = $repo->create(
            $this->def,
            ['title' => 'Original Title', 'body' => 'Original Body'],
            $creator,
        );
        $uuid = (string) $created['uuid'];

        $updated = $repo->update($this->def, $uuid, ['title' => 'Updated Title'], $updater);

        self::assertSame('Updated Title', (string) $updated['title']);
        self::assertSame('Original Body', (string) $updated['body']);
        self::assertSame('api_key', (string) $updated['updated_by_type']);
        self::assertSame('key-abc', (string) $updated['updated_by_id']);
        // created_by_* columns must not change on update.
        self::assertSame('admin', (string) $updated['created_by_type']);
        self::assertSame('u1', (string) $updated['created_by_id']);
    }

    /**
     * find() with an unknown uuid throws RowNotFoundException.
     */
    public function testFindWithUnknownUuidThrowsRowNotFoundException(): void
    {
        $this->expectException(RowNotFoundException::class);
        $this->repo()->find($this->def, 'non-existent-uuid-0000');
    }

    /**
     * delete() with an unknown uuid throws RowNotFoundException.
     */
    public function testDeleteWithUnknownUuidThrowsRowNotFoundException(): void
    {
        $this->expectException(RowNotFoundException::class);
        $this->repo()->delete($this->def, 'non-existent-uuid-0000', $this->adminActor());
    }

    /**
     * An enum field with a value outside settings['values'] throws RowValidationException.
     */
    public function testEnumRejectsOutOfSetValue(): void
    {
        $caught = null;
        try {
            $this->repo()->create(
                $this->def,
                ['title' => 'Title', 'status' => 'invalid_status'],
                $this->adminActor(),
            );
        } catch (RowValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'RowValidationException must be thrown for an out-of-set enum value');
        self::assertArrayHasKey('status', $caught->errors());
    }

    /**
     * An email field with a malformed value throws RowValidationException.
     */
    public function testEmailRejectsMalformedFormat(): void
    {
        $caught = null;
        try {
            $this->repo()->create(
                $this->def,
                ['title' => 'Title', 'contact_email' => 'not-an-email'],
                $this->adminActor(),
            );
        } catch (RowValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'RowValidationException must be thrown for a malformed email');
        self::assertArrayHasKey('contact_email', $caught->errors());
    }

    /**
     * A single asset field rejects a blob UUID not present in the blobs table.
     */
    public function testSingleAssetRejectsMissingBlobUuid(): void
    {
        $caught = null;
        try {
            $this->repo()->create(
                $this->def,
                ['title' => 'Title', 'cover' => 'test-blob-nonexistent-0001'],
                $this->adminActor(),
            );
        } catch (RowValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'RowValidationException must be thrown for a non-existent blob UUID');
        self::assertArrayHasKey('cover', $caught->errors());
    }

    /**
     * A single asset field accepts a blob UUID that resolves in the blobs table.
     */
    public function testSingleAssetAcceptsRealBlobUuid(): void
    {
        // UUID must be exactly 12 chars (blobs.uuid is varchar(12)).
        $blobUuid = $this->seedBlob('tstblob00001');

        $row = $this->repo()->create(
            $this->def,
            ['title' => 'Title', 'cover' => $blobUuid],
            $this->adminActor(),
        );

        self::assertSame($blobUuid, (string) $row['cover']);
    }

    /**
     * A multi-asset field rejects input containing a blob UUID absent from the blobs table.
     */
    public function testMultiAssetRejectsMissingBlobUuidInArray(): void
    {
        // UUID must be exactly 12 chars (blobs.uuid is varchar(12)).
        $realBlobUuid = $this->seedBlob('tstblob00002');

        $caught = null;
        try {
            $this->repo()->create(
                $this->def,
                ['title' => 'Title', 'gallery' => [$realBlobUuid, 'test-blob-fake-uuid']],
                $this->adminActor(),
            );
        } catch (RowValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull(
            $caught,
            'RowValidationException must be thrown when a multi-asset contains a missing blob UUID',
        );
        self::assertArrayHasKey('gallery', $caught->errors());
    }

    /**
     * A multi-asset field accepts an array where every blob UUID resolves in the blobs table.
     */
    public function testMultiAssetAcceptsArrayOfRealBlobUuids(): void
    {
        // UUIDs must be exactly 12 chars (blobs.uuid is varchar(12)).
        $uuid1 = $this->seedBlob('tstblob00003');
        $uuid2 = $this->seedBlob('tstblob00004');

        $row = $this->repo()->create(
            $this->def,
            ['title' => 'Title', 'gallery' => [$uuid1, $uuid2]],
            $this->adminActor(),
        );

        // gallery is serialised as a JSON array in the database.
        $decoded = json_decode((string) $row['gallery'], true);
        self::assertSame([$uuid1, $uuid2], $decoded);
    }

    /**
     * A unique field rejects a duplicate value on create.
     */
    public function testUniqueFieldRejectsDuplicateOnCreate(): void
    {
        $repo = $this->repo();
        $repo->create(
            $this->def,
            ['title' => 'First', 'slug' => 'my-slug'],
            $this->adminActor(),
        );

        $caught = null;
        try {
            $repo->create(
                $this->def,
                ['title' => 'Second', 'slug' => 'my-slug'],
                $this->adminActor(),
            );
        } catch (RowValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'RowValidationException must be thrown for a duplicate unique field value');
        self::assertArrayHasKey('slug', $caught->errors());
    }

    /**
     * A unique field allows updating the same row with its own existing value (self-update).
     */
    public function testUniqueFieldAllowsUpdateOfSameRow(): void
    {
        $repo = $this->repo();
        $row  = $repo->create(
            $this->def,
            ['title' => 'First', 'slug' => 'my-slug'],
            $this->adminActor(),
        );

        // Updating the same row with the same slug must NOT throw.
        $updated = $repo->update(
            $this->def,
            (string) $row['uuid'],
            ['slug' => 'my-slug'],
            $this->adminActor(),
        );

        self::assertSame('my-slug', (string) $updated['slug']);
    }

    public function testValidateThrowsOnUnmappedFieldType(): void
    {
        // A registered field type with no coercion rule is a pack misconfiguration — fail loudly,
        // never silently store it as a string.
        $def = new CollectionDefinition('clx_bogus', 'bogustypes', 'Bogus', 'collection_bogus', 'table', [
            CollectionField::fromArray(['name' => 'weird', 'type' => 'collections.nonsense', 'settings' => []]),
        ], 1, 'active');

        $this->expectException(\LogicException::class);
        $this->container()->get(RowValidator::class)->validate($def, ['weird' => 'x'], false);
    }

    public function testActorRejectsUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Actor('superuser', 'u1');
    }
}
