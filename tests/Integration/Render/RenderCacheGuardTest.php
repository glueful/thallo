<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Cache\RenderCacheHints;
use Thallo\Render\Http\Middleware\RenderPageCache;

/**
 * Product grid spec §3.2: a render's guards are stored in the entry and re-read before every
 * serve — a stale entry is refused on read, whoever stored it and whenever.
 */
final class RenderCacheGuardTest extends AppTestCase
{
    private const GEN = 'thallo:cataloggen:shop:guardtest';

    protected function tearDown(): void
    {
        $this->cache()->deletePattern('render:*');
        $this->cache()->delete(self::GEN);
        parent::tearDown();
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function middleware(): RenderPageCache
    {
        return new RenderPageCache($this->cache(), 'default', static fn (): string => 'fp', true, 3600);
    }

    /** A render that observed `$gen` (or is uncacheable) and says `$body`. */
    private function next(string $body, ?string $gen, bool $uncacheable = false, ?\Closure $during = null): \Closure
    {
        return function (Request $request) use ($body, $gen, $uncacheable, $during): Response {
            $during?->__invoke();
            $request->attributes->set(RenderCacheHints::ATTRIBUTE, new RenderCacheHints(
                ['thallo:shop:catalog:guardtest'],
                $gen === null ? [] : [self::GEN => $gen],
                $uncacheable,
            ));
            return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        };
    }

    private function get(string $path = '/grid-page', array $headers = []): Request
    {
        $request = Request::create($path, 'GET');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        return $request;
    }

    public function testAGuardedPageIsStoredWithItsGuardsAndServedWhileTheyHold(): void
    {
        $this->cache()->set(self::GEN, 'A', 3600);
        $first = $this->middleware()->handle($this->get(), $this->next('old', 'A'));
        $second = $this->middleware()->handle($this->get(), $this->next('fresh', 'A'));
        self::assertSame('old', $second->getContent(), 'served from cache while the guard holds');
        self::assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    public function testAChangeAfterTheStoreIsRefusedOnTheNextReadAndNoStale304(): void
    {
        $this->cache()->set(self::GEN, 'A', 3600);
        $stale = $this->middleware()->handle($this->get(), $this->next('old', 'A'));
        $this->cache()->set(self::GEN, 'B', 3600); // a catalog change lands; nothing deletes the entry

        $reader = $this->middleware()->handle(
            $this->get('/grid-page', ['If-None-Match' => (string) $stale->headers->get('ETag')]),
            $this->next('fresh', 'B'),
        );
        self::assertSame(200, $reader->getStatusCode(), 'no 304 for the stale ETag');
        self::assertSame('fresh', $reader->getContent());
    }

    public function testAWorkerThatStopsRightAfterStoringLeavesNothingServable(): void
    {
        // Simulated: an entry written with the old guard, then nothing else ever runs.
        $this->cache()->set(self::GEN, 'B', 3600);
        $this->middleware()->handle($this->get(), $this->next('fresh', 'B'));
        $key = $this->onlyRenderKey();
        $entry = $this->cache()->get($key);
        $entry['body'] = 'stale';
        $entry['guards'] = [self::GEN => 'A'];
        $this->cache()->set($key, $entry, 3600);

        $reader = $this->middleware()->handle($this->get(), $this->next('fresh again', 'B'));
        self::assertSame('fresh again', $reader->getContent());
    }

    public function testAChangeBeforeThePreStoreCheckLeavesThePageUnstored(): void
    {
        $this->cache()->set(self::GEN, 'A', 3600);
        $change = fn () => $this->cache()->set(self::GEN, 'B', 3600);
        $this->middleware()->handle($this->get(), $this->next('old', 'A', false, $change));
        self::assertSame([], $this->renderKeys());
    }

    public function testAnUncacheableRenderIsServedButNotStored(): void
    {
        $response = $this->middleware()->handle($this->get(), $this->next('two grids disagreed', null, true));
        self::assertSame('two grids disagreed', $response->getContent());
        self::assertSame([], $this->renderKeys());
    }

    public function testThePrivateStorageTagJoinsTheEntryButNotTheHeader(): void
    {
        $this->cache()->set(self::GEN, 'A', 3600);
        $response = $this->middleware()->handle($this->get(), $this->next('old', 'A'));
        self::assertStringNotContainsString('guardtest', (string) $response->headers->get('Cache-Tag'));
        $this->cache()->invalidateTags(['thallo:shop:catalog:guardtest']);
        self::assertSame([], $this->renderKeys(), 'the entry was tagged with the storage tag');
    }

    public function testAPageWithoutGuardsServesWithNoExtraRead(): void
    {
        $plain = static fn (Request $r): Response => new Response('plain', 200, ['Content-Type' => 'text/html']);
        $this->middleware()->handle($this->get('/plain'), $plain); // stored
        $reads = [];
        $spy = $this->readCounting($this->cache(), $reads);
        $mw = new RenderPageCache($spy, 'default', static fn (): string => 'fp', true, 3600);
        $hit = $mw->handle($this->get('/plain'), static fn () => throw new \LogicException('must be a hit'));
        self::assertSame('plain', $hit->getContent());
        self::assertCount(1, $reads, 'the entry read only — no guard reads for a page without a grid');
    }

    /**
     * A CacheStore mock that records every get() and answers it from `$inner`. The hit path only
     * calls get(), so no other method needs forwarding.
     *
     * @param list<string> $reads
     */
    private function readCounting(CacheStore $inner, array &$reads): CacheStore
    {
        $spy = $this->createMock(CacheStore::class);
        $spy->method('get')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($inner, &$reads): mixed {
                $reads[] = $key;
                return $inner->get($key, $default);
            },
        );
        return $spy;
    }

    /** @return list<string> */
    private function renderKeys(): array
    {
        return array_values(array_filter(
            $this->cache()->getKeys('render:*'),
            static fn (string $k): bool => !str_ends_with($k, ':404') && !str_ends_with($k, ':410'),
        ));
    }

    private function onlyRenderKey(): string
    {
        $keys = $this->renderKeys();
        self::assertCount(1, $keys);
        return $keys[0];
    }
}
