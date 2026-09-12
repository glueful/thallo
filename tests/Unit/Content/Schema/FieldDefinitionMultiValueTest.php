<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Schema;

use Thallo\Core\Content\Schema\FieldDefinition;
use Thallo\Core\Content\Schema\SchemaParseException;
use PHPUnit\Framework\TestCase;

final class FieldDefinitionMultiValueTest extends TestCase
{
    public function testReferenceParsesMultipleMaxItemsAndSlugField(): void
    {
        $f = FieldDefinition::fromArray([
            'name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
            'multiple' => true, 'max_items' => 5, 'reference_slug_field' => 'slug',
        ]);
        self::assertTrue($f->multiple);
        self::assertSame(5, $f->maxItems);
        self::assertSame('slug', $f->referenceSlugField);
    }

    public function testReferenceSlugFieldDefaultsToSlug(): void
    {
        $f = FieldDefinition::fromArray(['name' => 'tag', 'type' => 'reference', 'reference_type' => 'tag']);
        self::assertSame('slug', $f->referenceSlugField);
        self::assertFalse($f->multiple);
        self::assertNull($f->maxItems);
    }

    public function testAssetMayBeMultiple(): void
    {
        $f = FieldDefinition::fromArray(['name' => 'gallery', 'type' => 'asset', 'multiple' => true, 'max_items' => 3]);
        self::assertTrue($f->multiple);
        self::assertSame(3, $f->maxItems);
        self::assertNull($f->referenceSlugField); // slug field is reference-only
    }

    public function testReferenceAssetMayBeFilterableWithoutFilterType(): void
    {
        $f = FieldDefinition::fromArray([
            'name' => 'category', 'type' => 'reference',
            'reference_type' => 'category', 'filterable' => true,
        ]);
        self::assertTrue($f->filterable);
        self::assertNull($f->filterType);
    }

    public function testMaxItemsMustBePositive(): void
    {
        $this->expectException(SchemaParseException::class);
        FieldDefinition::fromArray(['name' => 'category', 'type' => 'reference', 'multiple' => true, 'max_items' => 0]);
    }

    public function testInvalidSlugFieldNameRejected(): void
    {
        $this->expectException(SchemaParseException::class);
        FieldDefinition::fromArray(['name' => 'category', 'type' => 'reference', 'reference_slug_field' => 'Bad-Name']);
    }

    public function testScalarFilterableStillRequiresFilterType(): void
    {
        $this->expectException(SchemaParseException::class);
        FieldDefinition::fromArray(['name' => 'price', 'type' => 'number', 'filterable' => true]);
    }
}
