<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thallo\Search\Store\PostgresTextSearch;

/** The Postgres engine reads a query as words only, and a language by its subtag. */
final class PostgresTextSearchTest extends TestCase
{
    public function testAQueryIsWordsAndNothingElse(): void
    {
        self::assertSame(['rose', 'garden'], PostgresTextSearch::words('Rose & garden | !rose:*'));
        self::assertSame(['café', '2024'], PostgresTextSearch::words('  Café — 2024 '));
        self::assertSame([], PostgresTextSearch::words('"><&|!'));
    }

    public function testALongQueryKeepsItsFirstTwelveDistinctWords(): void
    {
        $words = PostgresTextSearch::words(implode(' ', array_map(fn (int $i): string => "w{$i}", range(1, 20))));
        self::assertCount(12, $words);
        self::assertSame('w12', $words[11]);
    }

    public function testALanguageMapsByItsSubtagOrFallsBackToSimple(): void
    {
        self::assertSame('french', PostgresTextSearch::configFor('fr-CA'));
        self::assertSame('english', PostgresTextSearch::configFor('en'));
        self::assertSame('simple', PostgresTextSearch::configFor('tlh'));
        self::assertSame('simple', PostgresTextSearch::configFor('simple'));
    }
}
