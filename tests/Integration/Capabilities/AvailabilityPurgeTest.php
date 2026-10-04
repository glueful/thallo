<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Glueful\Cache\CacheStore;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Database\Connection;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Capability\AvailabilityFingerprint;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Capabilities\AvailabilityPurge;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\FakeEdgeCache as FakeEdge;

/**
 * Core purges cached pages when the features in use change (search block spec §3.6): the local
 * page tag and the edge first, then the marker — in one transaction with a retry obligation named
 * by the new fingerprint, so a later edge purge catches responses that reached the CDN late, and
 * an older completion can never clear a newer obligation.
 */
final class AvailabilityPurgeTest extends AppTestCase
{
    private const PAGE_KEY = 'availability-test:render-page-entry';
    private const UNTAGGED_KEY = 'availability-test:untagged-neighbor';

    private ?string $savedMarker = null;
    private ?string $savedDue = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedMarker = $this->system()->get(AvailabilityPurge::MARKER);
        $this->savedDue = $this->system()->get(AvailabilityPurge::EDGE_DUE);
    }

    protected function tearDown(): void
    {
        $cache = $this->container()->get(CacheStore::class);
        $cache->delete(self::PAGE_KEY);
        $cache->delete(self::UNTAGGED_KEY);
        $this->restore(AvailabilityPurge::MARKER, $this->savedMarker);
        $this->restore(AvailabilityPurge::EDGE_DUE, $this->savedDue);
        parent::tearDown();
    }

    public function testFirstSightRecordsWithoutPurging(): void
    {
        $this->system()->forget(AvailabilityPurge::MARKER);
        $this->seedPage();
        $edge = new FakeEdge();

        $this->purge($edge)->reconcile();

        self::assertSame($this->fingerprint(), $this->system()->get(AvailabilityPurge::MARKER));
        self::assertSame('cached page body', $this->cache()->get(self::PAGE_KEY));
        self::assertSame(0, $edge->purgeAllCalls);
    }

    public function testAChangePurgesPagesAndEdgeAndSchedulesTheRetry(): void
    {
        $this->system()->put(AvailabilityPurge::MARKER, 'old');
        $this->system()->forget(AvailabilityPurge::EDGE_DUE);
        $this->seedPage();
        $edge = new FakeEdge();

        $before = time();
        $this->purge($edge)->reconcile();

        self::assertNull($this->cache()->get(self::PAGE_KEY), 'the page tag is purged');
        self::assertSame('untouched', $this->cache()->get(self::UNTAGGED_KEY));
        self::assertSame(1, $edge->purgeAllCalls);
        self::assertSame($this->fingerprint(), $this->system()->get(AvailabilityPurge::MARKER));
        [$named, $due] = explode('|', (string) $this->system()->get(AvailabilityPurge::EDGE_DUE));
        self::assertSame($this->fingerprint(), $named, 'the obligation is named by the new state');
        self::assertGreaterThanOrEqual($before + 300, strtotime($due . ' UTC'));
    }

    public function testAFailedEdgePurgeLeavesTheMarkerUnadvanced(): void
    {
        $this->system()->put(AvailabilityPurge::MARKER, 'old');
        $edge = new FakeEdge(failPurges: 1);

        $this->purge($edge)->reconcile();
        self::assertSame('old', $this->system()->get(AvailabilityPurge::MARKER));

        $this->purge($edge)->reconcile();
        self::assertSame(2, $edge->purgeAllCalls, 'the next reconcile repeats the purge');
        self::assertSame($this->fingerprint(), $this->system()->get(AvailabilityPurge::MARKER));
    }

    public function testTheMarkerAdvancesOnlyAfterTheRequiredPurges(): void
    {
        $this->system()->put(AvailabilityPurge::MARKER, 'old');
        $this->system()->forget(AvailabilityPurge::EDGE_DUE);

        $failingCache = $this->createMock(CacheStore::class);
        $failingCache->method('invalidateTags')->willThrowException(new \RuntimeException('cache down'));
        $purge = new AvailabilityPurge(
            $this->container()->get(AvailabilityFingerprint::class),
            $this->system(),
            $this->container()->get(Connection::class),
            $failingCache,
            300,
            null,
            new FakeEdge(),
        );
        try {
            $purge->reconcile();
            self::fail('the local purge failure must surface');
        } catch (\RuntimeException) {
        }
        self::assertSame('old', $this->system()->get(AvailabilityPurge::MARKER));
        self::assertNull($this->system()->get(AvailabilityPurge::EDGE_DUE));
    }

    public function testTheDelayedPurgeSurvivesARestartAndClearsOnSuccess(): void
    {
        $this->system()->put(AvailabilityPurge::EDGE_DUE, 'f1|2000-01-01 00:00:00');
        $edge = new FakeEdge(failPurges: 1);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // A fresh instance stands for a restart: the obligation lives in the database.
        $this->purge($edge)->completeDue($now);
        self::assertSame('f1|2000-01-01 00:00:00', $this->system()->get(AvailabilityPurge::EDGE_DUE));

        $this->purge($edge)->completeDue($now);
        self::assertNull($this->system()->get(AvailabilityPurge::EDGE_DUE));
        self::assertSame(2, $edge->purgeAllCalls);
    }

    public function testANotYetDueObligationWaits(): void
    {
        $this->system()->put(AvailabilityPurge::EDGE_DUE, 'f1|2999-01-01 00:00:00');
        $edge = new FakeEdge();
        $this->purge($edge)->completeDue(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        self::assertSame(0, $edge->purgeAllCalls);
        self::assertNotNull($this->system()->get(AvailabilityPurge::EDGE_DUE));
    }

    public function testAnOldCompletionCannotEraseANewerObligation(): void
    {
        $this->system()->put(AvailabilityPurge::EDGE_DUE, 'f1|2000-01-01 00:00:00');
        $system = $this->system();
        // While the old obligation's purge is in flight, a new flip writes its own obligation.
        $edge = new FakeEdge(onPurge: static function () use ($system): void {
            $system->put(AvailabilityPurge::EDGE_DUE, 'f2|2999-01-01 00:00:00');
        });

        $this->purge($edge)->completeDue(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        self::assertSame('f2|2999-01-01 00:00:00', $this->system()->get(AvailabilityPurge::EDGE_DUE));
    }

    public function testAConfigOnlyCommerceFlipPurges(): void
    {
        // The default boot has recorded its state; a boot that turns Commerce off in configuration
        // only (no switch is written) must purge on its first request. Tests run the array cache
        // driver, one store per boot, so the page is seeded in the boot that must purge it.
        $this->container()->get(AvailabilityPurge::class)->reconcile();

        $off = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
        $offCache = $off->getContainer()->get(CacheStore::class);
        $offCache->set(self::PAGE_KEY, 'cached page body');
        $offCache->addTags(self::PAGE_KEY, ['thallo:render:page']);
        (new \Glueful\Application($off))->handle(Request::create('/', 'GET'));

        $offFingerprint = $off->getContainer()->get(AvailabilityFingerprint::class)->current();
        self::assertNotSame($this->fingerprint(), $offFingerprint);
        $this->system()->clearCache();
        self::assertSame($offFingerprint, $this->system()->get(AvailabilityPurge::MARKER));
        self::assertNull($offCache->get(self::PAGE_KEY), 'cached pages from the old state are gone');
    }

    public function testTurningSearchOffPurges(): void
    {
        $on = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => true]]);
        (new \Glueful\Application($on))->handle(Request::create('/', 'GET'));
        $this->seedPage();

        // Back to the default state (search off): the default context reconciles. Its flags are
        // memoised per container, so read them afresh as a new process would.
        $this->system()->clearCache();
        self::assertNotSame($this->fingerprint(), $this->system()->get(AvailabilityPurge::MARKER));
        $this->container()->get(AvailabilityPurge::class)->reconcile();

        $this->system()->clearCache();
        self::assertSame($this->fingerprint(), $this->system()->get(AvailabilityPurge::MARKER));
        self::assertNull($this->cache()->get(self::PAGE_KEY));
    }

    private function purge(EdgeCacheInterface $edge): AvailabilityPurge
    {
        return new AvailabilityPurge(
            $this->container()->get(AvailabilityFingerprint::class),
            $this->system(),
            $this->container()->get(Connection::class),
            $this->cache(),
            300,
            null,
            $edge,
        );
    }

    private function seedPage(): void
    {
        $this->cache()->set(self::PAGE_KEY, 'cached page body');
        $this->cache()->addTags(self::PAGE_KEY, ['thallo:render:page']);
        $this->cache()->set(self::UNTAGGED_KEY, 'untouched');
    }

    private function restore(string $key, ?string $value): void
    {
        $value === null ? $this->system()->forget($key) : $this->system()->put($key, $value);
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function system(): \Thallo\Tenancy\System\SystemFlags
    {
        $flags = $this->container()->get(SystemChannel::class);
        \assert($flags instanceof \Thallo\Tenancy\System\SystemFlags);
        return $flags;
    }

    private function fingerprint(): string
    {
        return $this->container()->get(AvailabilityFingerprint::class)->current();
    }
}
