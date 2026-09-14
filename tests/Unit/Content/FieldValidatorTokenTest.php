<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\FieldDefinition;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;

/** The `token` field type (visual builder spec §1.7): a typed token whose domain the field declares. */
final class FieldValidatorTokenTest extends TestCase
{
    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'prefix_color', 'type' => 'token', 'domain' => 'color'],
        ]);
    }

    public function testATokenFieldDeclaresAKnownDomain(): void
    {
        $field = FieldDefinition::fromArray(['name' => 'c', 'type' => 'token', 'domain' => 'spacing']);
        self::assertSame('token', $field->type);
        self::assertSame('spacing', $field->domain);

        $this->expectException(SchemaParseException::class);
        FieldDefinition::fromArray(['name' => 'c', 'type' => 'token', 'domain' => 'nope']);
    }

    public function testATokenFieldRequiresADomain(): void
    {
        $this->expectException(SchemaParseException::class);
        FieldDefinition::fromArray(['name' => 'c', 'type' => 'token']);
    }

    public function testAValueIsATypedTokenOfTheFieldsDomain(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), [
            'title' => 'x',
            'prefix_color' => ['type' => 'token', 'value' => 'color.accent'],
        ]);
        self::assertSame(['type' => 'token', 'value' => 'color.accent'], $clean['prefix_color']);
    }

    /** @dataProvider badValues */
    public function testAnythingElseIsRejected(mixed $value): void
    {
        try {
            (new FieldValidator())->validate($this->schema(), ['title' => 'x', 'prefix_color' => $value]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('prefix_color', $e->errors());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function badValues(): iterable
    {
        yield 'a raw hex' => ['#ff0000'];
        yield 'a bare name' => ['accent'];
        yield 'another domain' => [['type' => 'token', 'value' => 'spacing.lg']];
        yield 'an unknown name' => [['type' => 'token', 'value' => 'color.nope']];
        yield 'a choice' => [['type' => 'choice', 'value' => 'color.accent']];
        yield 'a reset' => [['type' => 'reset']];
    }

    public function testTokenFieldsAreNeverFilterable(): void
    {
        $this->expectException(SchemaParseException::class);
        FieldDefinition::fromArray([
            'name' => 'c', 'type' => 'token', 'domain' => 'color', 'filterable' => true, 'filter_type' => 'string',
        ]);
    }
}
