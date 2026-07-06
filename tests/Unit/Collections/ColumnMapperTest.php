<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collections;

use Thallo\Collections\Schema\CollectionField;
use Thallo\Collections\Schema\ColumnMapper;
use PHPUnit\Framework\TestCase;

final class ColumnMapperTest extends TestCase
{
    private ColumnMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ColumnMapper();
    }

    /** Spec §4.3 canonical example from the brief. */
    public function testMapsDecimalAndTextAndMultiRelation(): void
    {
        $m = $this->mapper;

        $price = $m->column(CollectionField::fromArray([
            'name' => 'price',
            'type' => 'collections.decimal',
            'settings' => ['precision' => 12, 'scale' => 2],
        ]));
        self::assertSame(['decimal', [12, 2]], [$price->type, $price->params]);

        $title = $m->column(CollectionField::fromArray([
            'name' => 'title',
            'type' => 'collections.string',
            'settings' => ['length' => 120, 'nullable' => false],
        ]));
        self::assertSame(['string', [120]], [$title->type, $title->params]);
        self::assertFalse($title->nullable);

        $tags = $m->column(CollectionField::fromArray([
            'name' => 'tags',
            'type' => 'collections.relation',
            'settings' => ['multi' => true, 'target' => 'collection:tags'],
        ]));
        self::assertSame('text', $tags->type); // JSON array column
    }

    public function testStringDefaultLength(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'title', 'type' => 'collections.string', 'settings' => []])
        );
        self::assertSame(['string', [255]], [$col->type, $col->params]);
    }

    public function testTextMapsToTextColumn(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'body', 'type' => 'collections.text', 'settings' => []])
        );
        self::assertSame('text', $col->type);
        self::assertSame([], $col->params);
    }

    public function testIntegerWithBigint(): void
    {
        $col = $this->mapper->column(CollectionField::fromArray([
            'name' => 'big_id',
            'type' => 'collections.integer',
            'settings' => ['bigint' => true],
        ]));
        self::assertSame('bigInteger', $col->type);
    }

    public function testIntegerWithoutBigint(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'count', 'type' => 'collections.integer', 'settings' => []])
        );
        self::assertSame('integer', $col->type);
    }

    public function testBooleanMapping(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'active', 'type' => 'collections.boolean', 'settings' => []])
        );
        self::assertSame('boolean', $col->type);
    }

    public function testDateMapping(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'birthday', 'type' => 'collections.date', 'settings' => []])
        );
        self::assertSame('date', $col->type);
    }

    public function testDatetimeMapsToTimestamp(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'created_at', 'type' => 'collections.datetime', 'settings' => []])
        );
        self::assertSame('timestamp', $col->type);
    }

    public function testJsonMapsToText(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'meta', 'type' => 'collections.json', 'settings' => []])
        );
        self::assertSame('text', $col->type);
    }

    public function testEmailMapsToString255(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'email', 'type' => 'collections.email', 'settings' => []])
        );
        self::assertSame(['string', [255]], [$col->type, $col->params]);
    }

    public function testUrlMapsToString2048(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'website', 'type' => 'collections.url', 'settings' => []])
        );
        self::assertSame(['string', [2048]], [$col->type, $col->params]);
    }

    public function testEnumMapsToString255(): void
    {
        $col = $this->mapper->column(CollectionField::fromArray([
            'name' => 'status',
            'type' => 'collections.enum',
            'settings' => ['values' => ['a', 'b', 'c']],
        ]));
        self::assertSame(['string', [255]], [$col->type, $col->params]);
    }

    public function testRelationSingleMapsToString36(): void
    {
        $col = $this->mapper->column(CollectionField::fromArray([
            'name' => 'author',
            'type' => 'collections.relation',
            'settings' => ['target' => 'collection:authors'],
        ]));
        self::assertSame(['string', [36]], [$col->type, $col->params]);
    }

    public function testAssetSingleMapsToString36(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'image', 'type' => 'collections.asset', 'settings' => []])
        );
        self::assertSame(['string', [36]], [$col->type, $col->params]);
    }

    public function testAssetMultiMapsToText(): void
    {
        $col = $this->mapper->column(CollectionField::fromArray([
            'name' => 'gallery',
            'type' => 'collections.asset',
            'settings' => ['multi' => true],
        ]));
        self::assertSame('text', $col->type);
    }

    public function testNullableDefaultsToTrue(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'x', 'type' => 'collections.string', 'settings' => []])
        );
        self::assertTrue($col->nullable);
    }

    public function testUniqueDefaultsToFalse(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'x', 'type' => 'collections.string', 'settings' => []])
        );
        self::assertFalse($col->unique);
    }

    public function testUniqueFromSettings(): void
    {
        $col = $this->mapper->column(CollectionField::fromArray([
            'name' => 'slug',
            'type' => 'collections.string',
            'settings' => ['unique' => true],
        ]));
        self::assertTrue($col->unique);
    }

    public function testSupportedTypesContainsAllTypes(): void
    {
        $types = $this->mapper->supportedTypes();
        self::assertContains('collections.string', $types);
        self::assertContains('collections.text', $types);
        self::assertContains('collections.integer', $types);
        self::assertContains('collections.decimal', $types);
        self::assertContains('collections.boolean', $types);
        self::assertContains('collections.date', $types);
        self::assertContains('collections.datetime', $types);
        self::assertContains('collections.json', $types);
        self::assertContains('collections.email', $types);
        self::assertContains('collections.url', $types);
        self::assertContains('collections.enum', $types);
        self::assertContains('collections.relation', $types);
        self::assertContains('collections.asset', $types);
    }

    public function testColumnNamePassedThrough(): void
    {
        $col = $this->mapper->column(
            CollectionField::fromArray(['name' => 'my_column', 'type' => 'collections.string', 'settings' => []])
        );
        self::assertSame('my_column', $col->name);
    }

    public function testUnsupportedTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->column(
            CollectionField::fromArray(['name' => 'x', 'type' => 'content.text', 'settings' => []])
        );
    }
}
