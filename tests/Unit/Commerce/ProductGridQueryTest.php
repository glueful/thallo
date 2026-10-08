<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thallo\Commerce\Shop\ProductGridQuery;

final class ProductGridQueryTest extends TestCase
{
    public function testDefaults(): void
    {
        $q = ProductGridQuery::fromData([]);
        self::assertSame(['all', [], [], '', false, 'newest', 12, 7], [
            $q->source, $q->categories, $q->tags, $q->products, $q->excludeOutOfStock, $q->orderBy, $q->limit,
            $q->newBadgeDays,
        ]);
    }

    public function testValidValues(): void
    {
        $q = ProductGridQuery::fromData([
            'source' => 'on_sale', 'categories' => ['men', ' women ', 'men', ''], 'tags' => ['summer'],
            'exclude_out_of_stock' => true, 'order_by' => 'price_desc', 'limit' => 4, 'new_badge_days' => 30,
        ]);
        self::assertSame('on_sale', $q->source);
        self::assertSame(['men', 'women'], $q->categories, 'trimmed, deduplicated, blanks dropped');
        self::assertSame(['summer'], $q->tags);
        self::assertTrue($q->excludeOutOfStock);
        self::assertSame('price_desc', $q->orderBy);
        self::assertSame(4, $q->limit);
        self::assertSame(30, $q->newBadgeDays);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function invalid(): iterable
    {
        yield 'removed sources' => [['source' => 'category']];
        yield 'removed newest source' => [['source' => 'newest']];
        yield 'unknown order' => [['order_by' => 'random']];
        yield 'limit low' => [['limit' => 0]];
        yield 'limit high' => [['limit' => 200]];
        yield 'limit text' => [['limit' => 'many']];
        yield 'categories not a list' => [['categories' => 'men']];
        yield 'days high' => [['new_badge_days' => 1000]];
    }

    #[DataProvider('invalid')]
    public function testInvalidValuesReadAsDefaultsOrClamped(array $data): void
    {
        $q = ProductGridQuery::fromData($data);
        self::assertSame('all', $q->source);
        self::assertSame('newest', $q->orderBy);
        self::assertContains($q->limit, [1, 12, 48]);
        self::assertSame([], $q->categories);
        self::assertContains($q->newBadgeDays, [7, 365]);
    }

    public function testListsAreCappedAtTwenty(): void
    {
        $slugs = array_map(static fn (int $i): string => 'c' . $i, range(1, 30));
        self::assertCount(20, ProductGridQuery::fromData(['categories' => $slugs])->categories);
    }
}
