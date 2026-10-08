<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Shop\ShopPageCache;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Cache\RenderCacheHints;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Product grid spec §3.2, the shop family: a shop page's render guards are stored in the entry and
 * re-read before every serve — a stale entry is refused on read, whoever stored it and whenever.
 */
final class ShopCacheGuardTest extends AppTestCase
{
    private const TENANT = 'shopguardten';
    private const GEN = 'thallo:cataloggen:shop:shopguardten';

    protected function setUp(): void
    {
        parent::setUp();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT);
    }

    protected function tearDown(): void
    {
        $this->cache()->deletePattern('shop:*');
        $this->cache()->delete(self::GEN);
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function middleware(?CacheStore $cache = null): ShopPageCache
    {
        return new ShopPageCache(
            $cache ?? $this->cache(),
            $this->container()->get(CommerceTenantResolution::class),
            'default',
            static fn (): string => 'fp',
            true,
            3600,
            $this->appContext(),
        );
    }

    private function key(): string
    {
        $ref = new \ReflectionMethod($this->middleware(), 'key');
        $ref->setAccessible(true);
        return (string) $ref->invoke($this->middleware(), self::TENANT, 'en', '/shop', 1);
    }

    /** A render that observed `$gen` (or is uncacheable) and says `$body`. */
    private function next(string $body, ?string $gen, bool $uncacheable = false, ?\Closure $during = null): \Closure
    {
        return function (Request $request) use ($body, $gen, $uncacheable, $during): Response {
            $during?->__invoke();
            $request->attributes->set(RenderCacheHints::ATTRIBUTE, new RenderCacheHints(
                ['thallo:shop:catalog:' . self::TENANT],
                $gen === null ? [] : [self::GEN => $gen],
                $uncacheable,
            ));
            return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        };
    }

    /** @param array<string,string> $headers */
    private function get(array $headers = []): Request
    {
        $request = Request::create('/shop', 'GET');
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
        $this->cache()->set(self::GEN, 'B', 3600);

        $reader = $this->middleware()->handle(
            $this->get(['If-None-Match' => (string) $stale->headers->get('ETag')]),
            $this->next('fresh', 'B'),
        );
        self::assertSame(200, $reader->getStatusCode(), 'no 304 for the stale ETag');
        self::assertSame('fresh', $reader->getContent());
    }

    public function testAWorkerThatStopsRightAfterStoringLeavesNothingServable(): void
    {
        $this->cache()->set(self::GEN, 'B', 3600);
        $this->middleware()->handle($this->get(), $this->next('fresh', 'B'));
        $entry = $this->cache()->get($this->key());
        self::assertIsArray($entry);
        $entry['body'] = 'stale';
        $entry['guards'] = [self::GEN => 'A'];
        $this->cache()->set($this->key(), $entry, 3600);

        $reader = $this->middleware()->handle($this->get(), $this->next('fresh again', 'B'));
        self::assertSame('fresh again', $reader->getContent());
    }

    public function testAChangeBeforeThePreStoreCheckLeavesThePageUnstored(): void
    {
        $this->cache()->set(self::GEN, 'A', 3600);
        $this->middleware()->handle(
            $this->get(),
            $this->next('old', 'A', false, fn () => $this->cache()->set(self::GEN, 'B', 3600)),
        );
        self::assertNull($this->cache()->get($this->key()));
    }

    public function testAnUncacheableRenderIsServedButNotStored(): void
    {
        $response = $this->middleware()->handle($this->get(), $this->next('two grids disagreed', null, true));
        self::assertSame('two grids disagreed', $response->getContent());
        self::assertNull($this->cache()->get($this->key()));
    }

    public function testAPageWithoutGuardsServesWithNoExtraRead(): void
    {
        $plain = static fn (Request $r): Response => new Response('plain', 200, ['Content-Type' => 'text/html']);
        $this->middleware()->handle($this->get(), $plain);
        $reads = [];
        $inner = $this->cache();
        $spy = $this->createMock(CacheStore::class);
        $spy->method('get')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($inner, &$reads): mixed {
                $reads[] = $key;
                return $inner->get($key, $default);
            },
        );
        $mustHit = static fn () => throw new \LogicException('must be a hit');
        $hit = $this->middleware($spy)->handle($this->get(), $mustHit);
        self::assertSame('plain', $hit->getContent());
        self::assertSame([$this->key()], $reads, 'the entry read only — no guard reads for a page without a grid');
    }
}
