<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\FieldDefinition;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;

/** Product grid spec §5.3: an option-source string field may hold several values, at most max_items. */
final class MultipleOptionSourceFieldTest extends TestCase
{
    /** @param array<string,mixed> $extra */
    private function raw(array $extra = []): array
    {
        return ['name' => 'categories', 'type' => 'string', 'multiple' => true, 'max_items' => 20,
            'options_source' => 'thallo-commerce.categories'] + $extra;
    }

    private function field(array $extra = []): FieldDefinition
    {
        return FieldDefinition::fromArray($this->raw($extra));
    }

    public function testMultipleIsKeptOnAnOptionSourceStringAndDroppedOnAPlainOne(): void
    {
        self::assertTrue($this->field()->multiple);
        $plain = FieldDefinition::fromArray(['name' => 'x', 'type' => 'string', 'multiple' => true]);
        self::assertFalse($plain->multiple);
    }

    public function testAListOfStringsIsValidAndDeduplicated(): void
    {
        [$clean, $errors] = $this->validate(['men', 'women', 'men']);
        self::assertSame([], $errors);
        self::assertSame(['men', 'women'], $clean['categories']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'a string' => ['men'];
        yield 'a number in the list' => [['men', 3]];
        yield 'a map' => [['a' => 'men']];
        yield 'an empty item' => [['men', '']];
    }

    #[DataProvider('invalid')]
    public function testAnythingElseIsRefused(mixed $value): void
    {
        [, $errors] = $this->validate($value);
        self::assertArrayHasKey('categories', $errors);
    }

    public function testMoreThanMaxItemsIsRefusedAndTheLimitIsKept(): void
    {
        self::assertSame(20, $this->field()->maxItems);
        [, $errors] = $this->validate(array_map(static fn (int $i): string => 'c' . $i, range(1, 21)));
        self::assertArrayHasKey('categories', $errors);
        [$clean, $ok] = $this->validate(array_map(static fn (int $i): string => 'c' . $i, range(1, 20)));
        self::assertSame([], $ok);
        self::assertCount(20, $clean['categories']);
    }

    public function testEachItemMeetsTheStringFieldsConstraints(): void
    {
        // A pattern on the field applies to every item, as it would to a single value.
        [, $errors] = $this->validate(['ok-slug', 'Not A Slug!'], ['pattern' => '[a-z0-9-]+']);
        self::assertArrayHasKey('categories', $errors);
    }

    public function testABoundedPatternBoundsEachItemsLength(): void
    {
        // Length is bounded through a supported constraint — the pattern — not an invented one.
        $bounded = ['pattern' => '[a-z0-9-]{1,64}'];
        [, $ok] = $this->validate([str_repeat('a', 64)], $bounded);
        self::assertSame([], $ok);
        [, $errors] = $this->validate([str_repeat('a', 65)], $bounded);
        self::assertArrayHasKey('categories', $errors);
    }

    /**
     * @param array<string,mixed> $extra extra field schema keys
     * @return array{array<string,mixed>, array<string,string>}
     */
    private function validate(mixed $value, array $extra = []): array
    {
        try {
            $clean = (new FieldValidator())->validate(
                ContentTypeSchema::fromArray([$this->raw($extra)]),
                ['categories' => $value],
            );
            return [$clean, []];
        } catch (ValidationException $e) {
            return [[], $e->errors()];
        }
    }
}
