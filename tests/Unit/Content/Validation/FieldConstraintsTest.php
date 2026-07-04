<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Validation;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\SchemaParseException;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Schema-declared value constraints (block-library spec §5): `pattern` on
 * string/text, `min`/`max` on number — parsed, ROUND-TRIPPED (the review P1:
 * no surface may silently drop them), and enforced with dot-path errors.
 */
final class FieldConstraintsTest extends TestCase
{
    private const HEX = '#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?';

    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'color', 'type' => 'string', 'pattern' => self::HEX],
            ['name' => 'opacity', 'type' => 'number', 'min' => 0, 'max' => 100],
        ]);
    }

    public function testConstraintsRoundTripThroughToArray(): void
    {
        $raw = $this->schema()->toArray();
        self::assertSame(self::HEX, $raw[0]['pattern']);
        self::assertSame(0, $raw[1]['min']);
        self::assertSame(100, $raw[1]['max']);

        // And back: a full parse of the serialized form keeps enforcing.
        $reparsed = ContentTypeSchema::fromArray($raw);
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($reparsed, ['color' => 'not-a-color']);
    }

    public function testPatternAcceptsAndRejects(): void
    {
        $validator = new FieldValidator();
        $clean = $validator->validate($this->schema(), ['color' => '#a1B2c3']);
        self::assertSame('#a1B2c3', $clean['color']);

        try {
            $validator->validate($this->schema(), ['color' => 'javascript:alert(1)']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('does not match the required format', $e->errors()['color'] ?? null);
        }
    }

    public function testMinMaxBoundsInclusive(): void
    {
        $validator = new FieldValidator();
        $clean = $validator->validate($this->schema(), ['opacity' => 0]);
        self::assertSame(0, $clean['opacity']);
        $clean = $validator->validate($this->schema(), ['opacity' => 100]);
        self::assertSame(100, $clean['opacity']);

        try {
            $validator->validate($this->schema(), ['opacity' => 101]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('must be at most 100', $e->errors()['opacity'] ?? null);
        }
        try {
            $validator->validate($this->schema(), ['opacity' => -1]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('must be at least 0', $e->errors()['opacity'] ?? null);
        }
    }

    public function testInvalidRegexFailsAtSchemaParseNotContentSave(): void
    {
        $this->expectException(SchemaParseException::class);
        ContentTypeSchema::fromArray([
            ['name' => 'broken', 'type' => 'string', 'pattern' => '[unclosed'],
        ]);
    }

    public function testMinAboveMaxRejectedAtParse(): void
    {
        $this->expectException(SchemaParseException::class);
        ContentTypeSchema::fromArray([
            ['name' => 'n', 'type' => 'number', 'min' => 10, 'max' => 1],
        ]);
    }

    public function testConstraintsIgnoredOnOtherTypes(): void
    {
        // A pattern on a boolean / bounds on a string parse as unconstrained.
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'flag', 'type' => 'boolean', 'pattern' => self::HEX],
            ['name' => 'label', 'type' => 'string', 'min' => 5, 'max' => 6],
        ]);
        $clean = (new FieldValidator())->validate($schema, ['flag' => true, 'label' => 'anything at all']);
        self::assertSame(['flag' => true, 'label' => 'anything at all'], $clean);
    }

    public function testEmptyStringSkipsPatternPresenceIsRequiredsJob(): void
    {
        // '' passes pattern (presence is `required`'s concern, mirroring the
        // framework's custom-rule convention).
        $clean = (new FieldValidator())->validate($this->schema(), ['color' => '']);
        self::assertSame('', $clean['color']);
    }
}
