<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use Thallo\Contracts\Events\ContentLifecycleEvent;
use Thallo\Seo\Cache\SitemapCache;
use Thallo\Seo\Listeners\SitemapCacheInvalidator;
use PHPUnit\Framework\TestCase;

final class SitemapCacheInvalidatorTest extends TestCase
{
    public function testOnContentChangedClearsSitemapCache(): void
    {
        $cache = new class implements SitemapCache {
            public int $forgotten = 0;
            public function remember(string $key, callable $producer): string
            {
                return $producer();
            }
            public function forgetAll(): void
            {
                $this->forgotten++;
            }
        };

        $event = new class implements ContentLifecycleEvent {
            public function name(): string
            {
                return 'entry.published';
            }
            public function payload(): array
            {
                return [];
            }
        };

        (new SitemapCacheInvalidator($cache))->onContentChanged($event);
        self::assertSame(1, $cache->forgotten);
    }
}
