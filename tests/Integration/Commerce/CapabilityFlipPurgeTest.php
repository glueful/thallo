<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Thallo\Commerce\Shop\CapabilityFlipPurge;

/**
 * Capability-boundary pin: {@see CapabilityFlipPurge} purges the rendered-page cache (+ edge)
 * exactly when the `thallo.commerce` enabled state CHANGED since the last boot — never on a
 * steady state, and never when the marker is absent (a flushed store holds nothing stale).
 * Runs against the suite's REAL CacheStore so tag semantics (`thallo:render:page` gone,
 * untagged neighbors untouched — including the marker itself) are proven, not faked.
 */
final class CapabilityFlipPurgeTest extends AppTestCase
{
    private const PAGE_KEY = 'flip-test:render-page-entry';
    private const UNTAGGED_KEY = 'flip-test:untagged-neighbor';

    protected function tearDown(): void
    {
        $cache = $this->cacheStore();
        $cache->delete(CapabilityFlipPurge::MARKER_KEY);
        $cache->delete(self::PAGE_KEY);
        $cache->delete(self::UNTAGGED_KEY);
        parent::tearDown();
    }

    private function cacheStore(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    /** A recording fake edge cache; read `$fake->purgeAllCalls` for the purge count. */
    private function fakeEdge(bool $enabled): EdgeCacheInterface
    {
        return new class ($enabled) implements EdgeCacheInterface {
            public int $purgeAllCalls = 0;

            public function __construct(private readonly bool $enabled)
            {
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function getProvider(): ?string
            {
                return 'fake';
            }

            public function generateCacheHeaders(string $route, ?string $contentType = null): array
            {
                return [];
            }

            public function purgeUrl(string $url): bool
            {
                return true;
            }

            public function purgeByTag(string $tag): bool
            {
                return true;
            }

            public function purgeAll(): bool
            {
                $this->purgeAllCalls++;
                return true;
            }
        };
    }

    private function seedRenderPageEntry(): void
    {
        $cache = $this->cacheStore();
        $cache->set(self::PAGE_KEY, 'cached page body');
        $cache->addTags(self::PAGE_KEY, ['thallo:render:page']);
        $cache->set(self::UNTAGGED_KEY, 'untouched');
    }

    public function testAbsentMarkerRecordsStateWithoutPurging(): void
    {
        $cache = $this->cacheStore();
        $cache->delete(CapabilityFlipPurge::MARKER_KEY);
        $this->seedRenderPageEntry();
        $edge = $this->fakeEdge(true);

        (new CapabilityFlipPurge($cache, $edge))->reconcile(false);

        self::assertSame('cached page body', $cache->get(self::PAGE_KEY), 'no marker → nothing to purge');
        self::assertSame('off', $cache->get(CapabilityFlipPurge::MARKER_KEY));
        self::assertSame(0, $edge->purgeAllCalls, 'no marker → no edge purge');
    }

    public function testSteadyStateIsANoOp(): void
    {
        $cache = $this->cacheStore();
        $cache->set(CapabilityFlipPurge::MARKER_KEY, 'on');
        $this->seedRenderPageEntry();
        $edge = $this->fakeEdge(true);

        (new CapabilityFlipPurge($cache, $edge))->reconcile(true);

        self::assertSame('cached page body', $cache->get(self::PAGE_KEY), 'same state → no purge');
        self::assertSame('on', $cache->get(CapabilityFlipPurge::MARKER_KEY));
        self::assertSame(0, $edge->purgeAllCalls);
    }

    public function testFlipPurgesTaggedPagesAndEdgeButNeverUntaggedNeighbors(): void
    {
        $cache = $this->cacheStore();
        $cache->set(CapabilityFlipPurge::MARKER_KEY, 'on');
        $this->seedRenderPageEntry();
        $edge = $this->fakeEdge(true);

        (new CapabilityFlipPurge($cache, $edge))->reconcile(false);

        self::assertNull($cache->get(self::PAGE_KEY), 'on→off flip purges thallo:render:page entries');
        self::assertSame('untouched', $cache->get(self::UNTAGGED_KEY), 'untagged keys survive the tag purge');
        self::assertSame('off', $cache->get(CapabilityFlipPurge::MARKER_KEY), 'marker advances after the purge');
        self::assertSame(1, $edge->purgeAllCalls, 'enabled edge purges exactly once per flip');
    }

    public function testReEnableFlipPurgesToo(): void
    {
        // off→on must purge as well: pages cached while disabled carry the
        // missing-template fallback instead of shop shells.
        $cache = $this->cacheStore();
        $cache->set(CapabilityFlipPurge::MARKER_KEY, 'off');
        $this->seedRenderPageEntry();

        (new CapabilityFlipPurge($cache, null))->reconcile(true);

        self::assertNull($cache->get(self::PAGE_KEY));
        self::assertSame('on', $cache->get(CapabilityFlipPurge::MARKER_KEY));
    }

    public function testDisabledEdgeIsSkippedWithLocalPurgeIntact(): void
    {
        $cache = $this->cacheStore();
        $cache->set(CapabilityFlipPurge::MARKER_KEY, 'on');
        $this->seedRenderPageEntry();
        $edge = $this->fakeEdge(false);

        (new CapabilityFlipPurge($cache, $edge))->reconcile(false);

        self::assertNull($cache->get(self::PAGE_KEY), 'local purge unaffected by edge state');
        self::assertSame(0, $edge->purgeAllCalls, 'NullEdgeCache discipline: disabled edge is never purged');
    }
}
