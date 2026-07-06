<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\AppTestCase;

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
}
