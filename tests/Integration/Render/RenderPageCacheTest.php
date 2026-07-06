<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Events\EntryPublished;
use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Events\EventService;
use Thallo\Contracts\Navigation\MenuUpdated;
use Thallo\Render\RenderErrorCache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render page caching (V2 sub-project 3). Drives the REAL kernel so the middleware,
 * router bucket order, and listener wiring are all under test. The cache store is
 * process-shared — tearDown purges render:* keys so later tests never serve stale seeds.
 */
final class RenderPageCacheTest extends AppTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->cache()->deletePattern('render:*');
        parent::tearDown();
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function typeUuid(string $slug = 'blog'): string
    {
        $row = $this->container()->get(ContentTypeRepository::class)->findBySlug($slug);
        return (string) $row['uuid'];
    }

    public function testRenderedEntryCarriesCacheTagSurrogateHeader(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $res = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $cacheTag = (string) $res->headers->get('Cache-Tag');
        self::assertStringContainsString('thallo:entry:' . $entry, $cacheTag);
        self::assertStringContainsString('thallo:type:blog', $cacheTag);
    }

    public function testSecondRequestServesFromCacheWithEtagAndCacheControl(): void
    {
        $this->seedBilingualPublishedEntry();
        $first = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertSame(200, $first->getStatusCode());
        // Symfony's ResponseHeaderBag recomputes Cache-Control from its directives in
        // alphabetical order — same semantics as the spec's "public, max-age=0,
        // must-revalidate", canonicalized.
        self::assertSame(
            'max-age=0, must-revalidate, public',
            $first->headers->get('Cache-Control'),
        );
        $etag = (string) $first->headers->get('ETag');
        self::assertNotSame('', $etag);

        // Overwrite the stored body: if the second request serves the sentinel, it came
        // from the cache — the resolver/Twig pipeline provably did not run.
        $key = 'render:default:%2Fblog%2Fhello';
        $entry = $this->cache()->get($key);
        self::assertIsArray($entry);
        $entry['body'] = 'SENTINEL-FROM-CACHE';
        $this->cache()->set($key, $entry, 3600);

        $second = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertSame(200, $second->getStatusCode());
        self::assertSame('SENTINEL-FROM-CACHE', (string) $second->getContent());
    }

    public function testIfNoneMatchServes304WithEmptyBody(): void
    {
        $this->seedBilingualPublishedEntry();
        $first = $this->handle(Request::create('/blog/hello', 'GET'));
        $etag = (string) $first->headers->get('ETag');

        $conditional = Request::create('/blog/hello', 'GET');
        $conditional->headers->set('If-None-Match', $etag);
        $res = $this->handle($conditional);
        self::assertSame(304, $res->getStatusCode());
        self::assertSame('', (string) $res->getContent());
    }

    public function testHeadServesCachedGetHeaders(): void
    {
        $this->seedBilingualPublishedEntry();
        $this->handle(Request::create('/blog/hello', 'GET'));
        $res = $this->handle(Request::create('/blog/hello', 'HEAD'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', (string) $res->headers->get('Content-Type'));
    }

    public function testReservedJsonResponsesAreNeverStored(): void
    {
        // The 200-text/html eligibility rule (spec §2 pin): the reserved-path JSON 404
        // flows through this same middleware and must never enter the render cache.
        $this->handle(Request::create('/v1/nonexistent-endpoint', 'GET'));
        $this->handle(Request::create('/v1/nonexistent-endpoint', 'GET'));
        self::assertSame([], $this->cache()->getKeys('render:*'));
    }

    public function testRedirectsAreNeverCachedAndKeysAreNormalized(): void
    {
        $this->seedBilingualPublishedEntry();
        $res = $this->handle(Request::create('/blog//hello', 'GET'));
        self::assertSame(301, $res->getStatusCode());
        self::assertSame([], $this->cache()->getKeys('render:*'));

        $this->handle(Request::create('/blog/hello', 'GET'));
        $keys = $this->cache()->getKeys('render:*');
        self::assertSame(['render:default:%2Fblog%2Fhello'], $keys);
        foreach ($keys as $key) {
            self::assertStringNotContainsString('//', $key);
        }
    }

    public function testHomepageIsCachedUnderRootKey(): void
    {
        $this->handle(Request::create('/', 'GET'));
        self::assertIsArray($this->cache()->get('render:default:%2F'));
    }

    public function testKeysAreValidForEveryCacheDriver(): void
    {
        // The framework's Redis driver rejects PSR-16-reserved characters
        // ({}()/\@) in keys — a raw path in the key 500s EVERY live render on
        // Redis (the test harness driver doesn't validate, which is how the
        // literal-path keys slipped through). Pin the invariant here.
        $this->seedBilingualPublishedEntry();
        $this->handle(Request::create('/blog/hello', 'GET'));
        $this->handle(Request::create('/', 'GET'));
        $keys = $this->cache()->getKeys('render:*');
        self::assertNotSame([], $keys);
        foreach ($keys as $key) {
            self::assertFalse(strpbrk($key, '{}()/\\@'), "driver-invalid cache key: {$key}");
        }
    }

    public function testDisabledMiddlewareIsAPurePassthrough(): void
    {
        // cache_enabled=false must be byte-for-byte today's behavior. Config-override
        // boots lose extension routes (loadRoutesFrom latch), so exercise the middleware
        // directly with enabled=false.
        $middleware = new \Thallo\Render\Http\Middleware\RenderPageCache(
            $this->cache(),
            'default',
            false,
            3600,
        );
        $downstream = new \Symfony\Component\HttpFoundation\Response(
            '<html>fresh</html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
        $res = $middleware->handle(
            Request::create('/blog/hello', 'GET'),
            static fn () => $downstream,
        );
        self::assertSame($downstream, $res); // untouched — no ETag/Cache-Control added
        self::assertSame([], $this->cache()->getKeys('render:*'));
    }

    public function testFixed404BodyIsStoredOnceAndReusedAcrossBogusPaths(): void
    {
        $first = $this->handle(Request::create('/no/such-page', 'GET'));
        self::assertSame(404, $first->getStatusCode());
        // The fixed body's Cache-Tag reaches the client/CDN too, so edge purges on
        // thallo:render:page compose for themed 404s.
        self::assertSame('thallo:render:page', $first->headers->get('Cache-Tag'));
        self::assertIsArray($this->cache()->get('render:default:404'));

        // Overwrite the stored body: a DIFFERENT bogus path serving the sentinel proves
        // the 404 came from the fixed key — 404.twig was not rendered again.
        $entry = $this->cache()->get('render:default:404');
        $entry['body'] = 'SENTINEL-404';
        $this->cache()->set('render:default:404', $entry, 3600);

        $second = $this->handle(Request::create('/another/bogus/path', 'GET'));
        self::assertSame(404, $second->getStatusCode());
        self::assertSame('SENTINEL-404', (string) $second->getContent());

        // No per-path accumulation: the fixed key is the ONLY render:* entry.
        self::assertSame(['render:default:404'], $this->cache()->getKeys('render:*'));
    }

    public function testErrorRenderCallbackRunsOnlyOnceOnWarmKey(): void
    {
        // Direct proof of the render-amplification fix: on a warm fixed key the Twig
        // callback is never invoked.
        $calls = 0;
        $render = function () use (&$calls): Response {
            $calls++;
            return new Response('<html>404</html>', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        };
        $errors = new RenderErrorCache($this->cache(), 'default', true, 3600);
        $errors->themed404($render);
        $second = $errors->themed404($render);

        self::assertSame(1, $calls);
        self::assertSame(404, $second->getStatusCode());
        self::assertSame('<html>404</html>', (string) $second->getContent());
        self::assertSame('thallo:render:page', $second->headers->get('Cache-Tag'));
    }

    public function testFailedErrorRenderIsNeverStored(): void
    {
        // If 404.twig itself blows up, the controller falls back to a 500 — the service
        // must not cache it (500s are never cached) and must retry the render next time.
        $calls = 0;
        $render = function () use (&$calls): Response {
            $calls++;
            return new Response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        };
        $errors = new RenderErrorCache($this->cache(), 'default', true, 3600);
        $errors->themed404($render);
        $errors->themed404($render);
        self::assertSame(2, $calls);
        self::assertNull($this->cache()->get('render:default:404'));
    }

    public function testGoneStoresFixed410Body(): void
    {
        $this->seedBilingualPublishedEntry();
        $types = $this->container()->get(ContentTypeRepository::class);
        $entries = $this->container()->get(\App\Content\Repositories\EntryRepository::class);
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $draft = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');
        (new \App\Content\Seo\RedirectRepository($this->connection()))->create([
            'content_type_uuid' => $typeUuid,
            'locale' => 'en',
            'source_slug' => 'moved-away',
            'target_content_type_uuid' => $typeUuid,
            'target_locale' => 'en',
            'target_entry_uuid' => $draft,
            'status' => 301,
        ]);

        $res = $this->handle(Request::create('/blog/moved-away', 'GET'));
        self::assertSame(410, $res->getStatusCode());
        self::assertSame('thallo:render:page', $res->headers->get('Cache-Tag'));
        self::assertIsArray($this->cache()->get('render:default:410'));
    }

    public function testDisabledErrorCacheIsAPurePassthrough(): void
    {
        $calls = 0;
        $render = function () use (&$calls): Response {
            $calls++;
            return new Response('<html>404</html>', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        };
        $errors = new RenderErrorCache($this->cache(), 'default', false, 3600);
        $res = $errors->themed404($render);
        $errors->themed404($render);
        self::assertSame(2, $calls); // rendered every time — byte-for-byte today's behavior
        self::assertFalse($res->headers->has('Cache-Tag'));
        self::assertSame([], $this->cache()->getKeys('render:*'));
    }

    public function testPublishPurgesCachedPageThroughTheRealListener(): void
    {
        // The spec §3 pin made concrete: the middleware stores via the same CacheStore
        // binding InvalidateCacheTagsListener invalidates, with the exact surrogate
        // strings — so a publish event purges page A while page B (the homepage,
        // which carries no entry tags) still hits.
        $entry = $this->seedBilingualPublishedEntry();
        $this->handle(Request::create('/blog/hello', 'GET'));
        $this->handle(Request::create('/', 'GET'));
        self::assertIsArray($this->cache()->get('render:default:%2Fblog%2Fhello'));
        $root = $this->cache()->get('render:default:%2F');
        self::assertIsArray($root);
        // Precondition, asserted rather than assumed: the test env runs the STANDALONE
        // homepage (render.homepage_entry unset), so the root entry carries no
        // entry/type surrogate tags — only thallo:render:page. If the homepage were
        // configured to entry A, publishing A SHOULD purge it too and this test's
        // "B still hit" assertion would be wrong by setup.
        self::assertSame('', $root['cacheTag']);

        $this->container()->get(EventService::class)
            ->dispatch(new EntryPublished($entry, $this->typeUuid()));

        self::assertNull($this->cache()->get('render:default:%2Fblog%2Fhello')); // A purged
        self::assertIsArray($this->cache()->get('render:default:%2F'));        // B still hit
    }

    public function testRenderPageTagInvalidationDropsEverything(): void
    {
        // Every stored entry — per-path pages AND the fixed 404 body — carries
        // thallo:render:page, so a broad invalidation empties the namespace.
        $this->seedBilingualPublishedEntry();
        $this->handle(Request::create('/blog/hello', 'GET'));
        $this->handle(Request::create('/no/such-page', 'GET'));
        self::assertCount(2, $this->cache()->getKeys('render:*'));

        $this->cache()->invalidateTags(['thallo:render:page']);
        self::assertSame([], $this->cache()->getKeys('render:*'));
    }

    public function testMenuUpdatedPurgesPagesAndFixed404Body(): void
    {
        $this->seedBilingualPublishedEntry();
        $this->handle(Request::create('/blog/hello', 'GET'));
        $this->handle(Request::create('/no/such-page', 'GET'));
        self::assertCount(2, $this->cache()->getKeys('render:*'));

        $this->container()->get(EventService::class)
            ->dispatch(new MenuUpdated('main'));

        self::assertSame([], $this->cache()->getKeys('render:*'));
    }

    public function testRenderCacheClearCommandEmptiesTheNamespace(): void
    {
        // deletePattern works with or without tag support — the non-tag-driver
        // escape hatch (spec §6). Covers per-path AND fixed keys.
        $this->seedBilingualPublishedEntry();
        $this->handle(Request::create('/blog/hello', 'GET'));
        $this->handle(Request::create('/no/such-page', 'GET'));
        self::assertCount(2, $this->cache()->getKeys('render:*'));

        $command = $this->container()
            ->get(\Thallo\Render\Console\ClearRenderCacheCommand::class);
        $command->clear();

        self::assertSame([], $this->cache()->getKeys('render:*'));
    }
}
