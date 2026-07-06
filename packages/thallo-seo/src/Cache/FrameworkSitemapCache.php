<?php

declare(strict_types=1);

namespace Thallo\Seo\Cache;

use Glueful\Cache\CacheStore;

/** SitemapCache backed by the framework cache. No TTL — entries live until invalidated. */
final class FrameworkSitemapCache implements SitemapCache
{
    private const PATTERN = 'lemma_seo:sitemap:*';

    public function __construct(private readonly CacheStore $cache)
    {
    }

    public function remember(string $key, callable $producer): string
    {
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            return $cached;
        }
        $value = $producer();
        $this->cache->set($key, $value);
        return $value;
    }

    public function forgetAll(): void
    {
        $this->cache->deletePattern(self::PATTERN);
    }
}
