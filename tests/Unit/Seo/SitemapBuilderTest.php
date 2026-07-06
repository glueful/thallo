<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use Thallo\Contracts\Delivery\ContentDeliveryReader;
use Thallo\Seo\Cache\SitemapCache;
use Thallo\Seo\Sitemap\SitemapBuilder;
use PHPUnit\Framework\TestCase;

final class SitemapBuilderTest extends TestCase
{
    private function cache(): SitemapCache
    {
        return new class implements SitemapCache {
            /** @var array<string,string> */
            public array $store = [];
            public function remember(string $key, callable $producer): string
            {
                return $this->store[$key] ??= $producer();
            }
            public function forgetAll(): void
            {
                $this->store = [];
            }
        };
    }

    /** @param list<array{href:string,lastmod:?string,alternates:list<array{locale:string,href:string}>}> $items */
    private function reader(array $items, int $total): ContentDeliveryReader
    {
        return new class ($items, $total) implements ContentDeliveryReader {
            public function __construct(private array $items, private int $total)
            {
            }
            public function listPublished(string $t, string $l, int $limit = 20): array
            {
                return [];
            }
            public function findPublished(string $t, string $l, string $s): ?array
            {
                return null;
            }
            public function enumeratePublishedForSitemap(int $limit, int $offset = 0): array
            {
                $slice = array_slice($this->items, $offset, $limit);
                return ['items' => $slice, 'total' => $this->total, 'limit' => $limit, 'offset' => $offset];
            }
        };
    }

    public function testSinglePageRendersUrlsetWithHreflang(): void
    {
        $items = [[
            'href' => 'https://site.test/en/blog/hello',
            'lastmod' => '2025-06-10T00:00:00+00:00',
            'alternates' => [
                ['locale' => 'en', 'href' => 'https://site.test/en/blog/hello'],
                ['locale' => 'fr', 'href' => 'https://site.test/fr/blog/bonjour'],
            ],
        ]];
        $b = new SitemapBuilder($this->reader($items, 1), $this->cache(), 'https://site.test');
        $xml = $b->rootXml();

        self::assertStringContainsString('<urlset', $xml);
        self::assertStringContainsString('<loc>https://site.test/en/blog/hello</loc>', $xml);
        self::assertStringContainsString('<lastmod>2025-06-10T00:00:00+00:00</lastmod>', $xml);
        self::assertStringContainsString('hreflang="fr"', $xml);
        self::assertStringContainsString('href="https://site.test/fr/blog/bonjour"', $xml);
    }

    public function testEmptySiteRendersValidEmptyUrlset(): void
    {
        $b = new SitemapBuilder($this->reader([], 0), $this->cache(), 'https://site.test');
        $xml = $b->rootXml();
        self::assertStringContainsString('<urlset', $xml);
        self::assertStringNotContainsString('<url>', $xml);
    }

    public function testOverCapRendersSitemapIndex(): void
    {
        // total 50001 → index with 2 page files.
        $b = new SitemapBuilder($this->reader([], 50001), $this->cache(), 'https://site.test');
        $xml = $b->rootXml();
        self::assertStringContainsString('<sitemapindex', $xml);
        self::assertStringContainsString('<loc>https://site.test/sitemap/1.xml</loc>', $xml);
        self::assertStringContainsString('<loc>https://site.test/sitemap/2.xml</loc>', $xml);
    }

    public function testHasOriginFalseWhenEmpty(): void
    {
        $b = new SitemapBuilder($this->reader([], 0), $this->cache(), '');
        self::assertFalse($b->hasOrigin());
    }
}
