<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\SchemaParseException;
use PHPUnit\Framework\TestCase;

final class ContentTypeSchemaTest extends TestCase
{
    public function testParsesFields(): void
    {
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);

        self::assertSame(['title', 'price'], array_map(fn($f) => $f->name, $schema->fields()));
        self::assertTrue($schema->field('title')->required);
        self::assertTrue($schema->field('price')->filterable);
        self::assertSame('number', $schema->field('price')->filterType);
        self::assertNull($schema->field('missing'));
    }

    public function testFilterableFieldMustDeclareFilterType(): void
    {
        $this->expectException(SchemaParseException::class);
        ContentTypeSchema::fromArray([
            ['name' => 'price', 'type' => 'number', 'filterable' => true],
        ]);
    }

    public function testRejectsDuplicateFieldNames(): void
    {
        $this->expectException(SchemaParseException::class);
        ContentTypeSchema::fromArray([
            ['name' => 'a', 'type' => 'string'],
            ['name' => 'a', 'type' => 'number'],
        ]);
    }

    public function testTextFieldDefaultsToPlainFormat(): void
    {
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'text'],
        ]);

        self::assertSame('plain', $schema->field('body')->format);
        self::assertSame('plain', $schema->toArray()[0]['format']);
    }

    public function testTextFieldAcceptsRichFormat(): void
    {
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
        ]);

        self::assertSame('rich', $schema->field('body')->format);
    }

    public function testRejectsInvalidTextFormat(): void
    {
        $this->expectException(SchemaParseException::class);
        ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'text', 'format' => 'markdown'],
        ]);
    }

    public function testStringFieldAcceptsIconFormats(): void
    {
        // Editor hints (icon-picker spec §2): presentation metadata only —
        // validation stays with pattern/enum.
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'icon', 'type' => 'string', 'format' => 'icon'],
            ['name' => 'brand', 'type' => 'string', 'format' => 'brand-icon'],
            ['name' => 'title', 'type' => 'string'],
        ]);

        self::assertSame('icon', $schema->field('icon')->format);
        self::assertSame('brand-icon', $schema->field('brand')->format);
        self::assertNull($schema->field('title')->format); // plain strings carry none
    }

    public function testFormatsAreTypeScoped(): void
    {
        // rich is TEXT-only…
        try {
            ContentTypeSchema::fromArray([['name' => 'x', 'type' => 'string', 'format' => 'rich']]);
            self::fail('expected SchemaParseException');
        } catch (SchemaParseException) {
            $this->addToAssertionCount(1);
        }
        // …and icon is STRING-only.
        try {
            ContentTypeSchema::fromArray([['name' => 'x', 'type' => 'text', 'format' => 'icon']]);
            self::fail('expected SchemaParseException');
        } catch (SchemaParseException) {
            $this->addToAssertionCount(1);
        }
        // Other types keep ignoring format (no vocabulary for them yet).
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'n', 'type' => 'number', 'format' => 'rich'],
        ]);
        self::assertNull($schema->field('n')->format);
    }
}
