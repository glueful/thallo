<?php

declare(strict_types=1);

namespace Thallo\Seo\Listeners;

use Thallo\Contracts\Events\ContentLifecycleEvent;
use Thallo\Seo\Cache\SitemapCache;

/**
 * Any published-content change (create/publish/unpublish/update/delete) can alter the
 * published-URL set or a lastmod, so the whole sitemap cache is dropped. Cheap: the next
 * request rebuilds lazily. Type-hints the pure contract interface only — no App bridge.
 */
final class SitemapCacheInvalidator
{
    public function __construct(private readonly SitemapCache $cache)
    {
    }

    public function onContentChanged(ContentLifecycleEvent $event): void
    {
        $this->cache->forgetAll();
    }
}
