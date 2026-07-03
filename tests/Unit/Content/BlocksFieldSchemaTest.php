<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Http\DTOs\FieldDefinitionData;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\SchemaParseException;
use PHPUnit\Framework\TestCase;

final class BlocksFieldSchemaTest extends TestCase
{
    public function testBlocksFieldParsesAndRoundTripsThroughToArray(): void
    {
        $schema = ContentTypeSchema::fromArray([[
            'name' => 'body',
            'type' => 'blocks',
            'localized' => true,
            'block_types' => ['hero', 'quote'],
        ]]);
        $field = $schema->field('body');
        self::assertSame('blocks', $field->type);
        self::assertSame(['hero', 'quote'], $field->blockTypes);
        self::assertTrue($field->localized);

        // ContentTypeSchema::toArray PRESERVES block_types (spec §1 round-trip pin).
        $out = $schema->toArray()[0];
        self::assertSame(['hero', 'quote'], $out['block_types']);
    }

    public function testBlocksFieldRejectsFilterable(): void
    {
        $this->expectException(SchemaParseException::class);
        ContentTypeSchema::fromArray([[
            'name' => 'body', 'type' => 'blocks', 'filterable' => true, 'filter_type' => 'string',
        ]]);
    }

    public function testEmptyBlockTypesMeansAllAndIsOmittedFromToArray(): void
    {
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        self::assertSame([], $schema->field('body')->blockTypes);
        self::assertArrayNotHasKey('block_types', $schema->toArray()[0]);
    }

    public function testRequestDtoCarriesBlockTypesThrough(): void
    {
        $dto = new FieldDefinitionData(name: 'body', type: 'blocks', block_types: ['hero']);
        self::assertSame(['hero'], $dto->toArray()['block_types']);
    }
}
