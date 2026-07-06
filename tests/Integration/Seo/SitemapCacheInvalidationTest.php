<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Content\Events\EntryPublished;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;
use Thallo\Seo\Cache\SitemapCache;

final class SitemapCacheInvalidationTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(SitemapCache::class)->forgetAll();
    }

    public function testLifecycleEventClearsSitemapCache(): void
    {
        $cache = $this->container()->get(SitemapCache::class);
        // Prime a sitemap cache entry.
        $cache->remember('thallo:seo:sitemap:root', static fn (): string => '<urlset/>');

        // Dispatch a real content-lifecycle event (EntryPublished implements
        // ContentLifecycleEvent via BaseContentEvent) through the booted EventService.
        $this->container()->get(EventService::class)->dispatch(
            new EntryPublished('e-1', 't-1', 'en', 1, 'user00000001'),
        );

        // The primed entry must be gone → remember() reproduces from the new producer.
        $reproduced = $cache->remember('thallo:seo:sitemap:root', static fn (): string => 'REBUILT');
        self::assertSame('REBUILT', $reproduced);
    }
}
