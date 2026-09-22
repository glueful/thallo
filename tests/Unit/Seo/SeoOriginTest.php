<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Seo;

use PHPUnit\Framework\TestCase;
use Thallo\Seo\SeoServiceProvider;

/**
 * The sitemap and robots.txt need an absolute origin, and read only PUBLIC_URL_BASE — a key no
 * .env.example names — so a stock install answered 409 for both. PUBLIC_URL_BASE still wins; when
 * it is empty they use the site's canonical origin (BASE_URL, or a workspace's own). The localhost
 * default counts as no origin: a sitemap of localhost URLs is worse than none.
 */
final class SeoOriginTest extends TestCase
{
    public function testAnExplicitPublicUrlBaseWins(): void
    {
        $canonical = static fn (): string => 'https://example.com';
        $origin = SeoServiceProvider::resolveOrigin('https://www.example.com/', $canonical);
        self::assertSame('https://www.example.com', $origin);
    }

    public function testWithoutItTheCanonicalOriginIsUsed(): void
    {
        $site = static fn (): string => 'https://example.com';
        $workspace = static fn (): string => 'https://ws.example.net/';
        self::assertSame('https://example.com', SeoServiceProvider::resolveOrigin('', $site));
        self::assertSame('https://ws.example.net', SeoServiceProvider::resolveOrigin(' ', $workspace));
    }

    public function testALocalOrUnusableOriginIsNoOrigin(): void
    {
        $unusable = ['http://localhost', 'http://localhost:8000', 'http://127.0.0.1', 'http://[::1]', 'not a url', ''];
        foreach ($unusable as $origin) {
            self::assertSame('', SeoServiceProvider::resolveOrigin('', static fn (): string => $origin), $origin);
        }
        $failing = static fn (): string => throw new \RuntimeException('no tenant');
        self::assertSame('', SeoServiceProvider::resolveOrigin('', $failing));
        self::assertSame('', SeoServiceProvider::resolveOrigin('', null));
    }
}
