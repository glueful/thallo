<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\AppTestCase;

final class FieldValidatorMediaDiskTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteBlobFixtures();
    }

    protected function tearDown(): void
    {
        $this->deleteBlobFixtures();
        $this->setConfig('lemma.media_disk', 'local');
        parent::tearDown();
    }

    public function testAssetFieldRequiresActiveBlobOnConfiguredMediaDisk(): void
    {
        $this->setConfig('lemma.media_disk', 'media');
        $this->insertBlob('assetmedia01', 'media');
        $this->insertBlob('assetlocal01', 'local');

        $validator = $this->container()->get(FieldValidator::class);
        self::assertInstanceOf(FieldValidator::class, $validator);

        $clean = $validator->validate($this->assetSchema(), ['hero' => 'assetmedia01']);
        self::assertSame(['hero' => 'assetmedia01'], $clean);

        try {
            $validator->validate($this->assetSchema(), ['hero' => 'assetlocal01']);
            self::fail('Expected asset on the wrong media disk to fail validation.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['hero' => 'must reference an active blob on the configured media disk'],
                $e->errors()
            );
        }
    }

    public function testStrictReferenceValidationRejectsDanglingTargetButAcceptsExistingEntry(): void
    {
        // A real entry to point at, and a soft-deleted one that must not satisfy the reference.
        $types = new \App\Content\Repositories\ContentTypeRepository($this->connection());
        $type = $types->create([
            'slug' => 'refs-target',
            'name' => 'Refs Target',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = new \App\Content\Repositories\EntryRepository($this->connection(), $this->appContext(), $types);
        $live = $entries->createEntry($type, 'en', 1, 'user00000001');
        $deleted = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->softDelete($deleted);

        $schema = ContentTypeSchema::fromArray([
            ['name' => 'author', 'type' => 'reference', 'reference_type' => 'refs-target'],
        ]);
        $validator = $this->container()->get(FieldValidator::class);
        self::assertInstanceOf(FieldValidator::class, $validator);

        // Draft (permissive): any non-empty string passes — existence is a publish-time rule.
        self::assertSame(['author' => 'ghostuuid001'], $validator->validate($schema, ['author' => 'ghostuuid001']));

        // Publish (strict): an existing live entry passes; a nonexistent or deleted target is rejected.
        self::assertSame(['author' => $live], $validator->validate($schema, ['author' => $live], true));

        foreach (['ghostuuid001', $deleted] as $bad) {
            try {
                $validator->validate($schema, ['author' => $bad], true);
                self::fail('expected a dangling reference to fail strict validation');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('author', $e->errors());
            }
        }
    }

    private function assetSchema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'hero', 'type' => 'asset', 'required' => true],
        ]);
    }

    private function insertBlob(string $uuid, string $disk): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => $uuid . '.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => '/uploads/' . $uuid . '.jpg',
            'storage_type' => $disk,
            'visibility' => 'private',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function deleteBlobFixtures(): void
    {
        $stmt = $this->connection()->getPDO()->prepare(
            "DELETE FROM blobs WHERE uuid IN ('assetmedia01', 'assetlocal01')"
        );
        $stmt->execute();
    }

    private function setConfig(string $key, mixed $value): void
    {
        $prop = (new \ReflectionClass($this->appContext()))->getProperty('configCache');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $cache */
        $cache = $prop->getValue($this->appContext());
        $cache[$key] = $value;
        $prop->setValue($this->appContext(), $cache);
    }
}
