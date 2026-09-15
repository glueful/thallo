<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use Glueful\Cache\CacheStore;
use PHPUnit\Framework\TestCase;
use Thallo\Render\Http\Middleware\RenderCachePurge;

/**
 * Rendered pages are purged by surrogate tag where the driver can, and dropped whole where it
 * cannot (the default file driver answers every tag call with false) — a publish is visible on
 * the next request either way, never after the TTL.
 */
final class RenderCachePurgeTest extends TestCase
{
    public function testATagPurgeIsEnoughWhenTheDriverInvalidatesTags(): void
    {
        $cache = $this->createMock(CacheStore::class);
        $cache->expects(self::once())->method('invalidateTags')->with(['thallo:entry:e1'])->willReturn(true);
        $cache->expects(self::never())->method('deletePattern');
        (new RenderCachePurge($cache))->purge(['thallo:entry:e1']);
    }

    public function testADriverWithoutTagInvalidationDropsEveryRenderedPage(): void
    {
        $cache = $this->createMock(CacheStore::class);
        $cache->method('invalidateTags')->willReturn(false);
        $patterns = [];
        $cache->expects(self::exactly(2))->method('deletePattern')
            ->willReturnCallback(static function (string $pattern) use (&$patterns): bool {
                $patterns[] = $pattern;
                return true;
            });
        (new RenderCachePurge($cache))->purge(['thallo:entry:e1']);
        self::assertSame(['render:*', 'tenant:*:render:*'], $patterns);
    }

    public function testNoTagsMeansNothingToPurge(): void
    {
        $cache = $this->createMock(CacheStore::class);
        $cache->expects(self::never())->method('invalidateTags');
        $cache->expects(self::never())->method('deletePattern');
        (new RenderCachePurge($cache))->purge([]);
    }
}
