<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thallo\Search\Query\InvalidSearchInput;
use Thallo\Search\Query\SearchInput;
use Thallo\Search\Query\Surface;

/**
 * One reading of a search query for every surface (search block spec §3.4): the block, the
 * suggestions, the results page and the API normalise `q`, the scope and the locale the same way.
 * The public surfaces read `scope` and forgive a bad locale; the API reads `kind`, keeps its
 * entries-only default, and answers 422 where it always did.
 */
final class SearchInputTest extends TestCase
{
    public function testQIsNormalisedOneWayEverywhere(): void
    {
        $in = $this->site(['q' => "  Cafe\u{0301}\t\x07 noir\u{3000} "]);
        self::assertSame("Caf\u{00E9} noir", $in->q);
        self::assertSame(200, mb_strlen($this->site(['q' => str_repeat('é', 250)])->q));
        self::assertSame('', $this->site(['q' => ['a']])->q);
    }

    public function testInvalidUtf8IsTreatedAsEmpty(): void
    {
        self::assertSame('', $this->site(['q' => "\xC3\x28"])->q);
    }

    public function testPublicScopeMapping(): void
    {
        self::assertTrue($this->site([])->scope->isAll());
        self::assertTrue($this->site(['scope' => ''])->scope->isAll());
        self::assertSame('products', $this->site(['scope' => 'Products'])->scope->kind);
        self::assertTrue($this->site(['scope' => 'reviews'])->scope->isUnavailable());
        self::assertTrue($this->site(['scope' => ['x']])->scope->isUnavailable());
        self::assertTrue($this->site(['scope' => 'a_b'])->scope->isUnavailable());
    }

    public function testScopeBindingsDifferForAllAKindAndAnUnavailableValue(): void
    {
        self::assertSame('', $this->site([])->scope->binding);
        self::assertSame('products', $this->site(['scope' => 'products'])->scope->binding);
        self::assertSame('!reviews', $this->site(['scope' => 'reviews'])->scope->binding);
    }

    public function testApiKindMapping(): void
    {
        self::assertSame('entries', $this->api([])->scope->kind);
        self::assertTrue($this->api(['kind' => 'all'])->scope->isAll());
        self::assertSame('products', $this->api(['kind' => 'products'])->scope->kind);
        $this->assertApiError(['kind' => 'reviews'], 422);
        $this->assertApiError(['kind' => ['x']], 422);
        $this->assertApiError(['type' => 'post', 'kind' => 'products'], 422);
        self::assertSame('entries', $this->api(['type' => 'post'])->scope->kind);
        self::assertSame('post', $this->api(['type' => 'post'])->type);
        $this->assertApiError(['type' => 'Not a slug!'], 422);
        $this->assertApiError(['offset' => '10', 'cursor' => 'abc'], 422);
        $this->assertApiError(['offset' => '-1'], 422);
        self::assertSame(10, $this->api(['offset' => '10'])->offset);
        self::assertSame('abc', $this->api(['cursor' => 'abc'])->rawCursor);
    }

    public function testTheApiRequiresAQueryAndALocale(): void
    {
        $this->assertApiError(['q' => ''], 422, ['q' => '']);
        $this->assertApiError(['q' => ['a']], 422, ['q' => ['a']]);
        $this->assertApiError(['locale' => null], 422, ['locale' => null]);
    }

    public function testLocaleIsCanonicalOrDefault(): void
    {
        self::assertSame('fr-CA', $this->site(['locale' => 'FR-ca'])->locale);
        self::assertSame('en', $this->site(['locale' => 'xx'])->locale);
        self::assertSame('en', $this->site(['locale' => ['en']])->locale);
        self::assertSame('en', $this->site([])->locale);
        $this->assertApiError(['locale' => 'xx'], 422);
    }

    /** @param array<string, mixed> $query */
    private function site(array $query): SearchInput
    {
        return SearchInput::from($query, Surface::Site, ['en', 'fr-CA'], 'en', ['entries', 'products']);
    }

    /** @param array<string, mixed> $query */
    private function api(array $query): SearchInput
    {
        return SearchInput::from(
            $query + ['q' => 'rose', 'locale' => 'en'],
            Surface::Api,
            ['en', 'fr-CA'],
            'en',
            ['entries', 'products'],
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $raw the whole query, when the defaults must not be merged
     */
    private function assertApiError(array $query, int $status, ?array $raw = null): void
    {
        try {
            if ($raw !== null) {
                $raw += array_diff_key(['q' => 'rose', 'locale' => 'en'], $raw);
                $raw = array_filter($raw, static fn ($v): bool => $v !== null);
                SearchInput::from($raw, Surface::Api, ['en', 'fr-CA'], 'en', ['entries', 'products']);
            } else {
                $this->api($query);
            }
            self::fail('accepted ' . json_encode($query));
        } catch (InvalidSearchInput $e) {
            self::assertSame($status, $e->status, json_encode($query) ?: '');
        }
    }
}
