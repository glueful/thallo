<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Localization;

use Thallo\Core\Content\Localization\LocaleFieldSeeder;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use PHPUnit\Framework\TestCase;

final class LocaleFieldSeederTest extends TestCase
{
    /** @param list<array<string,mixed>> $fields */
    private function schema(array $fields): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray($fields);
    }

    public function testCopiesNonLocalizedAndOmitsLocalized(): void
    {
        $schema = $this->schema([
            ['name' => 'title', 'type' => 'string', 'localized' => true],
            ['name' => 'price', 'type' => 'number'],
        ]);

        $seed = (new LocaleFieldSeeder())->seed(['title' => 'Hello', 'price' => 42], $schema);

        self::assertSame(['price' => 42], $seed, 'non-localized copied, localized omitted');
        self::assertArrayNotHasKey('title', $seed);
    }

    public function testPreservesFalsyNonLocalizedValuesViaKeyPresence(): void
    {
        $schema = $this->schema([
            ['name' => 'flag', 'type' => 'boolean'],
            ['name' => 'count', 'type' => 'number'],
            ['name' => 'ratio', 'type' => 'number'],
            ['name' => 'note', 'type' => 'string'],
        ]);

        $source = ['flag' => false, 'count' => 0, 'ratio' => 0.0, 'note' => ''];
        $seed = (new LocaleFieldSeeder())->seed($source, $schema);

        self::assertArrayHasKey('flag', $seed);
        self::assertArrayHasKey('count', $seed);
        self::assertArrayHasKey('ratio', $seed);
        self::assertArrayHasKey('note', $seed);
        self::assertFalse($seed['flag']);
        self::assertSame(0, $seed['count']);
        self::assertSame(0.0, $seed['ratio']);
        self::assertSame('', $seed['note']);
    }

    public function testDropsSourceFieldThatIsNotInSchema(): void
    {
        $schema = $this->schema([
            ['name' => 'price', 'type' => 'number'],
        ]);

        $seed = (new LocaleFieldSeeder())->seed(['price' => 9, 'stale' => 'gone'], $schema);

        self::assertSame(['price' => 9], $seed, 'a stale key not in the schema is not carried over');
    }

    public function testDoesNotInventSchemaFieldAbsentFromSource(): void
    {
        $schema = $this->schema([
            ['name' => 'price', 'type' => 'number'],
            ['name' => 'weight', 'type' => 'number'],
        ]);

        $seed = (new LocaleFieldSeeder())->seed(['price' => 9], $schema);

        self::assertSame(['price' => 9], $seed, 'a schema field absent from source is not invented');
        self::assertArrayNotHasKey('weight', $seed);
    }

    public function testEmptySourceProducesEmptySeed(): void
    {
        $schema = $this->schema([
            ['name' => 'title', 'type' => 'string', 'localized' => true],
            ['name' => 'price', 'type' => 'number'],
        ]);

        self::assertSame([], (new LocaleFieldSeeder())->seed([], $schema));
    }

    public function testAllLocalizedSchemaCopiesNothing(): void
    {
        $schema = $this->schema([
            ['name' => 'title', 'type' => 'string', 'localized' => true],
            ['name' => 'body', 'type' => 'text', 'localized' => true],
        ]);

        self::assertSame([], (new LocaleFieldSeeder())->seed(['title' => 'Hi', 'body' => 'B'], $schema));
    }

    public function testAllSharedSchemaCopiesEverything(): void
    {
        $schema = $this->schema([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'price', 'type' => 'number'],
        ]);

        $source = ['title' => 'Hi', 'price' => 5];
        self::assertSame($source, (new LocaleFieldSeeder())->seed($source, $schema));
    }
}
