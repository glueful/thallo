<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Thallo\Account\CapabilityFlipPurge;

/**
 * The capability-flip reconciler, unit-tested directly against the suite's real tagged cache — that
 * is where the flip logic lives. Toggling a capability inside a booted application proves nothing:
 * routes and contributors are registered at boot, so flipping the registry afterwards changes
 * neither. Mirrors the commerce suite's own {@see \App\Tests\Integration\Commerce\CapabilityFlipPurgeTest}.
 */
final class AccountCapabilityFlipPurgeTest extends AppTestCase
{
    private const PAGE_KEY = 'account-flip-test:page:home';
    private const SHOP_KEY = 'account-flip-test:page:shop';

    protected function tearDown(): void
    {
        $cache = $this->cacheStore();
        $cache->delete(self::PAGE_KEY);
        $cache->delete(self::SHOP_KEY);
        $cache->delete(CapabilityFlipPurge::MARKER_KEY);
        parent::tearDown();
    }

    private function cacheStore(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function reconciler(?EdgeCacheInterface $edge = null): CapabilityFlipPurge
    {
        return new CapabilityFlipPurge($this->cacheStore(), $edge);
    }

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

            /** @return array<string,string> */
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

    /** Seeds one entry under each tag the reconciler must clear (two-step: set() then addTags()). */
    private function seedTaggedPages(): void
    {
        $cache = $this->cacheStore();
        $cache->set(self::PAGE_KEY, '<shell/>');
        $cache->addTags(self::PAGE_KEY, ['thallo:render:page']);
        $cache->set(self::SHOP_KEY, '<shell/>');
        $cache->addTags(self::SHOP_KEY, ['thallo:shop:catalog']);
    }

    public function testAnAbsentMarkerRecordsStateWithoutPurging(): void
    {
        // First boot ever, or a flushed store: nothing stale can be cached, so a purge is pure cost.
        $this->cacheStore()->delete(CapabilityFlipPurge::MARKER_KEY);
        $this->seedTaggedPages();

        $this->reconciler()->reconcile(enabled: true);

        self::assertNotNull($this->cacheStore()->get(self::PAGE_KEY));
        self::assertSame('on', $this->cacheStore()->get(CapabilityFlipPurge::MARKER_KEY));
    }

    public function testAnUnchangedStatePurgesNothing(): void
    {
        // Every boot calls reconcile(); only a CHANGE may purge, or each deploy flushes for nothing.
        $this->reconciler()->reconcile(enabled: true);
        $this->seedTaggedPages();

        $this->reconciler()->reconcile(enabled: true);

        self::assertNotNull($this->cacheStore()->get(self::PAGE_KEY));
        self::assertNotNull($this->cacheStore()->get(self::SHOP_KEY));
    }

    public function testAFlipPurgesBothLocalTags(): void
    {
        $this->reconciler()->reconcile(enabled: true);
        $this->seedTaggedPages();

        $this->reconciler()->reconcile(enabled: false);

        // Both: the header renders on shop pages too, and those live in the catalog cache.
        self::assertNull($this->cacheStore()->get(self::PAGE_KEY));
        self::assertNull($this->cacheStore()->get(self::SHOP_KEY));
        self::assertSame('off', $this->cacheStore()->get(CapabilityFlipPurge::MARKER_KEY));
    }

    public function testAFlipBackAlsoPurges(): void
    {
        $this->reconciler()->reconcile(enabled: false);
        $this->seedTaggedPages();

        $this->reconciler()->reconcile(enabled: true);

        self::assertNull($this->cacheStore()->get(self::PAGE_KEY));
        self::assertNull($this->cacheStore()->get(self::SHOP_KEY));
    }

    public function testTheMarkerSurvivesItsOwnPurge(): void
    {
        // Untagged precisely so a purge cannot delete its own bookkeeping — else every boot would
        // look like a first boot and never purge again.
        $this->reconciler()->reconcile(enabled: true);
        $this->reconciler()->reconcile(enabled: false);

        self::assertSame('off', $this->cacheStore()->get(CapabilityFlipPurge::MARKER_KEY));
    }

    public function testTheEdgeIsPurgedOnAFlipAndOnlyWhenEnabled(): void
    {
        $this->reconciler()->reconcile(enabled: true);

        $disabled = $this->fakeEdge(false);
        $this->reconciler($disabled)->reconcile(enabled: false);
        self::assertSame(0, $disabled->purgeAllCalls);

        $enabled = $this->fakeEdge(true);
        $this->reconciler($enabled)->reconcile(enabled: true);
        self::assertSame(1, $enabled->purgeAllCalls);
    }

    public function testTheMarkerKeyIsAccountOwned(): void
    {
        // Sharing Commerce's marker would mean whichever pack booted second sees no flip.
        self::assertNotSame(
            \Thallo\Commerce\Shop\CapabilityFlipPurge::MARKER_KEY,
            CapabilityFlipPurge::MARKER_KEY,
        );
    }
}
