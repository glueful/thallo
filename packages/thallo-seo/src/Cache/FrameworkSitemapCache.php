<?php

declare(strict_types=1);

namespace Thallo\Seo\Cache;

use Glueful\Cache\CacheStore;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Cache\TenantCacheSegment;

/** SitemapCache backed by the framework cache. No TTL — entries live until invalidated. */
final class FrameworkSitemapCache implements SitemapCache
{
    private const PATTERN = 'thallo:seo:sitemap:*';

    public function __construct(
        private readonly CacheStore $cache,
        private readonly ?TenantCacheSegment $tenantCache = null,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    public function remember(string $key, callable $producer): string
    {
        $key = $this->prefix() . $key;
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
        $this->cache->deletePattern($this->prefix() . self::PATTERN);
    }

    private function prefix(): string
    {
        return $this->tenantCache !== null && $this->context !== null
            ? $this->tenantCache->segment($this->context, 'seo')
            : '';
    }
}
