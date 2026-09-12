<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class FieldValidatorTest extends TestCase
{
    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'price', 'type' => 'number'],
            ['name' => 'status', 'type' => 'enum', 'enum' => ['draft', 'live']],
            ['name' => 'active', 'type' => 'boolean'],
            ['name' => 'published_at', 'type' => 'datetime'],
        ]);
    }

    public function testAcceptsValidPayloadAndDropsUnknownKeys(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), [
            'title' => 'Hi', 'price' => 9.5, 'status' => 'live', 'active' => true,
            'sneaky' => 'x', // unknown -> dropped, not an error
        ]);
        self::assertSame(['title' => 'Hi', 'price' => 9.5, 'status' => 'live', 'active' => true], $clean);
    }

    public function testRejectsMissingRequired(): void
    {
        try {
            (new FieldValidator())->validate($this->schema(), ['price' => 1]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('title', $e->errors());
        }
    }

    public function testRejectsWrongType(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['title' => 'ok', 'price' => 'not-a-number']);
    }

    public function testRejectsEnumOutsideSet(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['title' => 'ok', 'status' => 'archived']);
    }

    public function testNormalizesSpaceSeparatedDatetimeToCanonicalUtc(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), [
            'title' => 'ok',
            'published_at' => '2026-06-14 09:30:00',
        ]);
        // Local '2026-06-14 09:30:00' is interpreted in the default TZ and emitted as UTC `...Z`.
        self::assertSame(
            FieldValidator::normalizeDatetime('2026-06-14 09:30:00'),
            $clean['published_at']
        );
        self::assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/',
            $clean['published_at']
        );
    }

    public function testNormalizesOffsetDatetimeToUtc(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), [
            'title' => 'ok',
            'published_at' => '2026-06-14T09:30:00+02:00',
        ]);
        // +02:00 09:30 == 07:30 UTC
        self::assertSame('2026-06-14T07:30:00Z', $clean['published_at']);
    }

    public function testRejectsUnparseableDatetime(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), [
            'title' => 'ok',
            'published_at' => 'not-a-date',
        ]);
    }

    public function testTimezonelessDatetimeIsParsedAsUtcNotServerLocal(): void
    {
        // Regression: a bare (offset-less) datetime must be read as UTC, not the server's zone, so
        // the stored wall-clock is unchanged. This assertion is timezone-independent.
        self::assertSame('2020-01-13T09:00:00Z', FieldValidator::normalizeDatetime('2020-01-13T09:00:00'));
        self::assertSame('2020-01-13T09:00:00Z', FieldValidator::normalizeDatetime('2020-01-13 09:00:00'));
    }

    public function testPermissiveModeAllowsEmptyRequiredString(): void
    {
        // Draft-save (permissive) behaviour is unchanged: a present-but-empty required field saves.
        $clean = (new FieldValidator())->validate($this->schema(), ['title' => '']);
        self::assertSame(['title' => ''], $clean);
    }

    public function testStrictModeRejectsEmptyRequiredString(): void
    {
        try {
            (new FieldValidator())->validate($this->schema(), ['title' => ''], true);
            self::fail('expected ValidationException in strict mode');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('title', $e->errors());
        }
    }

    public function testStrictModeRejectsEmptyArrayForRequiredJson(): void
    {
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'meta', 'type' => 'json', 'required' => true],
        ]);

        // Permissive: [] passes (current behaviour).
        self::assertSame(['meta' => []], (new FieldValidator())->validate($schema, ['meta' => []]));

        // Strict (publish): an empty required json/array is rejected.
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($schema, ['meta' => []], true);
    }

    public function testStrictModeWithoutDbDoesNotBlockReferences(): void
    {
        // referenceExists fails open when no DB is wired, so a reference still validates in strict
        // mode from a unit context (existence is enforced only where the container injects a DB).
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'author', 'type' => 'reference', 'reference_type' => 'people'],
        ]);
        $clean = (new FieldValidator())->validate($schema, ['author' => 'entry0000001'], true);
        self::assertSame(['author' => 'entry0000001'], $clean);
    }
}
