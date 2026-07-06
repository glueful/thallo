<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Schema\ContentSchemaReader;
use Thallo\Contracts\Schema\ContentTypeReader;

final class ContentTypeReaderTest extends AppTestCase
{
    public function testResolvesBySlugAndReadsSchema(): void
    {
        $this->connection()->table('content_types')->insert([
            'uuid' => 'type00000001',
            'slug' => 'post',
            'name' => 'Post',
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
            ]),
            'schema_version' => 1,
        ]);

        $reader = $this->container()->get(ContentTypeReader::class);
        self::assertInstanceOf(ContentTypeReader::class, $reader);

        self::assertSame('type00000001', $reader->findUuidBySlug('post'));
        self::assertNull($reader->findUuidBySlug('nope'));

        $schema = $reader->schemaFor('type00000001');
        self::assertInstanceOf(ContentSchemaReader::class, $schema);
        self::assertNotNull($schema->field('title'));
        // FieldDescriptor::format() is exposed for the importers' raw-vs-HTML body decision.
        self::assertSame('rich', $schema->field('body')?->format());
        self::assertNull($schema->field('title')?->format()); // non-text field has no format
        self::assertNull($reader->schemaFor('missing'));
    }

    public function testDeliveryTypesListsNonDeletedTypesWithVisibility(): void
    {
        $this->connection()->table('content_types')->insert([
            'uuid' => 'type0000pub1',
            'slug' => 'pages',
            'name' => 'Pages',
            'public_delivery' => true,
            'schema' => json_encode([['name' => 'title', 'type' => 'string']]),
            'schema_version' => 1,
        ]);
        $this->connection()->table('content_types')->insert([
            'uuid' => 'type0000priv',
            'slug' => 'internal',
            'name' => 'Internal',
            'public_delivery' => false,
            'schema' => json_encode([['name' => 'title', 'type' => 'string']]),
            'schema_version' => 1,
        ]);

        $types = $this->container()->get(ContentTypeReader::class)->deliveryTypes();

        self::assertSame(['slug' => 'pages', 'public_delivery' => true], $types['type0000pub1']);
        self::assertSame(['slug' => 'internal', 'public_delivery' => false], $types['type0000priv']);
    }
}
