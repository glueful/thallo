<?php

declare(strict_types=1);

namespace Thallo\Seo\Sitemap;

use Thallo\Contracts\Delivery\ContentDeliveryReader;
use Thallo\Seo\Cache\SitemapCache;

/**
 * Serializes published content into sitemap XML. Adaptive: one <urlset> at or below the
 * page cap, a <sitemapindex> above it. Output is cached; the caller guards on hasOrigin().
 */
final class SitemapBuilder
{
    public const PAGE_SIZE = 50000;

    private const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const XMLNS_XHTML = 'http://www.w3.org/1999/xhtml';

    public function __construct(
        private readonly ContentDeliveryReader $reader,
        private readonly SitemapCache $cache,
        private readonly string $origin,
    ) {
    }

    public function hasOrigin(): bool
    {
        return trim($this->origin) !== '';
    }

    /**
     * Number of page files (≥ 1) derived from the published total. Cached under the sitemap
     * prefix (same invalidation) so the controller can bound {n} without an enumeration per
     * request — unbounded n would otherwise mint a permanent cache entry and a deep-OFFSET
     * query per distinct value.
     */
    public function pageCount(): int
    {
        $raw = $this->cache->remember('lemma_seo:sitemap:pages', function (): string {
            $first = $this->reader->enumeratePublishedForSitemap(1, 0);
            return (string) max(1, intdiv((int) $first['total'] + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        });
        return (int) $raw;
    }

    public function rootXml(): string
    {
        return $this->cache->remember('lemma_seo:sitemap:root', function (): string {
            $first = $this->reader->enumeratePublishedForSitemap(self::PAGE_SIZE, 0);
            if ((int) $first['total'] <= self::PAGE_SIZE) {
                return $this->urlset($first['items']);
            }
            $pages = intdiv((int) $first['total'] + self::PAGE_SIZE - 1, self::PAGE_SIZE);
            return $this->sitemapIndex($pages);
        });
    }

    public function pageXml(int $n): string
    {
        $n = max(1, $n);
        return $this->cache->remember('lemma_seo:sitemap:page:' . $n, function () use ($n): string {
            $page = $this->reader->enumeratePublishedForSitemap(self::PAGE_SIZE, ($n - 1) * self::PAGE_SIZE);
            return $this->urlset($page['items']);
        });
    }

    /** @param list<array{href:string,lastmod:?string,alternates:list<array{locale:string,href:string}>}> $items */
    private function urlset(array $items): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="' . self::XMLNS . '" xmlns:xhtml="' . self::XMLNS_XHTML . '">' . "\n";
        foreach ($items as $item) {
            $out .= '  <url>' . "\n";
            $out .= '    <loc>' . $this->esc($item['href']) . '</loc>' . "\n";
            if (($item['lastmod'] ?? null) !== null && $item['lastmod'] !== '') {
                $out .= '    <lastmod>' . $this->esc((string) $item['lastmod']) . '</lastmod>' . "\n";
            }
            foreach ($item['alternates'] as $alt) {
                $out .= '    <xhtml:link rel="alternate" hreflang="' . $this->esc($alt['locale'])
                    . '" href="' . $this->esc($alt['href']) . '"/>' . "\n";
            }
            $out .= '  </url>' . "\n";
        }
        $out .= '</urlset>' . "\n";
        return $out;
    }

    private function sitemapIndex(int $pages): string
    {
        $base = rtrim($this->origin, '/');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<sitemapindex xmlns="' . self::XMLNS . '">' . "\n";
        for ($n = 1; $n <= $pages; $n++) {
            $out .= '  <sitemap><loc>' . $this->esc($base . '/sitemap/' . $n . '.xml') . '</loc></sitemap>' . "\n";
        }
        $out .= '</sitemapindex>' . "\n";
        return $out;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
