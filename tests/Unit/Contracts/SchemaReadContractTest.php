<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;
use Thallo\Contracts\Schema\ContentSchemaReader;
use Thallo\Contracts\Schema\FieldDescriptor;
use PHPUnit\Framework\TestCase;

final class SchemaReadContractTest extends TestCase
{
    public function testFieldDefinitionImplementsDescriptor(): void
    {
        $f = FieldDefinition::fromArray([
            'name' => 'tags', 'type' => 'reference', 'multiple' => true,
            'reference_type' => 'tag', 'reference_slug_field' => 'slug',
        ]);
        self::assertInstanceOf(FieldDescriptor::class, $f);
        self::assertSame('tags', $f->name());
        self::assertSame('reference', $f->type());
        self::assertTrue($f->isMultiple());
        self::assertSame('tag', $f->referenceType());
        self::assertSame('slug', $f->referenceSlugField());
    }

    public function testContentTypeSchemaImplementsReader(): void
    {
        $s = ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true],
        ]);
        self::assertInstanceOf(ContentSchemaReader::class, $s);
        self::assertInstanceOf(FieldDescriptor::class, $s->field('title'));
        self::assertNull($s->field('nope'));
        self::assertContainsOnlyInstancesOf(FieldDescriptor::class, $s->fields());
    }
}
