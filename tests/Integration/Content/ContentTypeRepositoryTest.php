<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Tests\Support\AppTestCase;

final class ContentTypeRepositoryTest extends AppTestCase
{
    private function repo(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->connection());
    }

    public function testCreateThenFindBySlug(): void
    {
        $uuid = $this->repo()->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
            'created_by' => 'user00000001',
        ]);
        $row = $this->repo()->findBySlug('post');
        self::assertSame($uuid, $row['uuid']);
        self::assertSame(1, $row['schema_version']);
        self::assertSame('title', $row['schema'][0]['name']);
    }

    public function testUpdateSchemaBumpsSchemaVersion(): void
    {
        $uuid = $this->repo()->create(['slug' => 'post', 'name' => 'Post', 'schema' => []]);
        $this->repo()->updateSchema($uuid, [['name' => 'body', 'type' => 'text']]);
        self::assertSame(2, $this->repo()->findByUuid($uuid)['schema_version']);
    }

    public function testARemovedFieldIsRefusedWithTheWayToRemoveIt(): void
    {
        // The refusal promised that migrating content was "planned for a later release";
        // delete and rename migrations have shipped. The message names that route, and
        // says plainly that a retype has none.
        $uuid = $this->repo()->create(['slug' => 'post', 'name' => 'Post', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'text'],
        ]]);
        try {
            $this->repo()->updateSchema($uuid, [['name' => 'title', 'type' => 'string']]);
            self::fail('expected the removal to be refused');
        } catch (\Thallo\Core\Content\Schema\SchemaParseException $e) {
            self::assertStringContainsString('body', $e->getMessage());
            self::assertStringContainsString('/content-types/{slug}/migrations', $e->getMessage());
            self::assertStringContainsString('cannot be retyped', $e->getMessage());
            self::assertStringNotContainsString('planned', $e->getMessage());
        }
    }
}
