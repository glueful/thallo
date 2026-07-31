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

    public function testEnforceBlockTypesParsesAndRoundTripsThroughToArray(): void
    {
        $schema = ContentTypeSchema::fromArray([[
            'name' => 'body',
            'type' => 'blocks',
            'block_types' => ['hero', 'quote'],
            'enforce_block_types' => true,
        ]]);
        $field = $schema->field('body');
        self::assertTrue($field->enforceBlockTypes);

        $out = $schema->toArray()[0];
        self::assertTrue($out['enforce_block_types']);
    }

    public function testEnforceBlockTypesAbsentOrFalseIsOmittedFromToArray(): void
    {
        // Absent key: defaults false, and the falsy default never appears in toArray()
        // (mirrors block_types' own absent-when-empty rule).
        $absent = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        self::assertFalse($absent->field('body')->enforceBlockTypes);
        self::assertArrayNotHasKey('enforce_block_types', $absent->toArray()[0]);

        // Explicit false: same outcome as absent.
        $explicitFalse = ContentTypeSchema::fromArray([[
            'name' => 'body', 'type' => 'blocks', 'enforce_block_types' => false,
        ]]);
        self::assertFalse($explicitFalse->field('body')->enforceBlockTypes);
        self::assertArrayNotHasKey('enforce_block_types', $explicitFalse->toArray()[0]);
    }

    public function testRequestDtoCarriesEnforceBlockTypesThrough(): void
    {
        $dto = new FieldDefinitionData(
            name: 'body',
            type: 'blocks',
            block_types: ['hero'],
            enforce_block_types: true,
        );
        self::assertTrue($dto->toArray()['enforce_block_types']);
    }
}
