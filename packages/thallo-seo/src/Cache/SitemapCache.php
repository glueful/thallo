<?php

declare(strict_types=1);

namespace Thallo\Seo\Cache;

/** Narrow cache seam for sitemap XML — keeps the builder testable without the full CacheStore. */
interface SitemapCache
{
    /** Return the cached string for $key, or produce+store it. */
    public function remember(string $key, callable $producer): string;

    /** Drop every sitemap cache entry (thallo:seo:sitemap:*). */
    public function forgetAll(): void;
}
