# Render Page Caching (V2 sub-project 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Full-page caching for the rendered site — per-path 200 hits serve stored HTML without touching the resolver or Twig, and warm fixed 404/410 keys skip the Twig render (the resolver still runs to classify the path); invalidation rides the existing surrogate-tag infrastructure; one operator CLI command clears the namespace.

**Architecture:** Two cooperating units, split by WHERE the cache check must happen. A `RenderPageCache` `RouteMiddleware` on both render routes stores per-path 200 `text/html` pages keyed `render:{theme}:{normalizedPath}` and applies the uniform HTTP validators (ETag/304/Cache-Control) to everything cacheable. A `RenderErrorCache` service, consulted by the CONTROLLER on the resolver's not_found/gone arms BEFORE rendering `404.twig`/`error.twig`, owns the fixed single-body keys (`render:{theme}:404` / `render:{theme}:410`) — a warm fixed key skips the Twig render entirely, which is what actually kills render amplification (a middleware-only design checks the per-path key before `$next()`, so Twig would already have run by the time it sees a 404). Both store via the SAME container `CacheStore::class` binding `InvalidateCacheTagsListener` invalidates, tagged with the `Cache-Tag` surrogate keys the controller emits plus `lemma:render:page`. Entry/type purges need ZERO new code; the pack adds ONE listener (`MenuUpdated` → broad purge) and ONE command (`render:cache:clear`). The `MenuUpdated` event moves from `lemma-navigation` into `lemma-contracts` first — cross-pack seams live in contracts.

**Tech Stack:** PHP 8.3, Glueful framework (`CacheStore`, `RouteMiddleware`, `BaseCommand`, `EventService`), lemma capability-pack conventions, PHPUnit integration tests (array cache driver — tag-capable).

**Spec:** `docs/superpowers/specs/2026-07-02-lemma-render-caching-design.md` (§2 carries the fixed-key-placement amendment this plan implements).

## Global Constraints

- Key = `render:{theme}:{normalizedPath}` — theme is the RESOLVED name from `ThemeLocator::activePaths()['name']`; path is normalized (collapse `//`, trim trailing `/`, root stays `/`). Normalized paths always start with `/`, so per-path keys can never collide with the fixed `render:{theme}:404` / `render:{theme}:410` keys.
- Store rule (spec §2, pinned): per-path entries store ONLY 200 responses with `Content-Type` containing `text/html`; themed 404/410 bodies store ONLY under fixed keys (`RenderErrorCache`, before Twig), never per-path; reserved-path JSON 404s, redirects, 500s, and any non-HTML response pass through unstored.
- Storage MUST go through the container `CacheStore::class` binding (spec §3 pin) — the identical binding `InvalidateCacheTagsListener` resolves. Every cached 200 gets `addTags($key, ['lemma:entry:{uuid}', 'lemma:type:{slug}', 'lemma:render:page'])`; the fixed 404/410 keys get `['lemma:render:page']` only, and their responses emit `Cache-Tag: lemma:render:page` so CDN purges compose for them too. Non-tag drivers: `addTags` no-ops → TTL-only degradation (documented, nothing breaks).
- Config: `lemma_render.cache_enabled` (`RENDER_CACHE_ENABLED`, default `true`), `lemma_render.cache_ttl` (`RENDER_CACHE_TTL`, default `3600`). Every stored entry carries the TTL — no no-TTL entries (test-harness gotcha: no-TTL entries in the process-shared store poison other suites). Both the middleware AND `RenderErrorCache` respect `cache_enabled=false` as a pure passthrough (byte-for-byte today's behavior).
- HTTP semantics (spec §5): ETag = `'"' . sha1($body) . '"'`; `If-None-Match` match → 304 empty body; all cacheable responses (hit AND miss, 200/404/410 `text/html`) carry `Cache-Control: public, max-age=0, must-revalidate`; no user/preview bypass.
- Pack boundaries: `packages/lemma-render` and `packages/lemma-contracts` code must NEVER reference `App\` (the boundary script greps for it, even in comments). Tests (namespace `App\Tests`) MAY use `App\` classes.
- Provider convention (recurring review nit): `use` imports / short class names in `services()` and factory bodies — no inline FQCNs.
- Commits: NO Claude/Anthropic attribution or Co-Authored-By trailers. Batch commits at the logical groupings marked below (not after every task).
- PHPCS: PSR-12, max line length 120.

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `packages/lemma-contracts/src/Navigation/MenuUpdated.php` | Create | Cross-pack menu-changed event (moved from lemma-navigation) |
| `packages/lemma-navigation/src/Events/MenuUpdated.php` | Delete | superseded by the contracts class |
| `packages/lemma-navigation/src/Http/Controllers/NavigationAdminController.php` | Modify | import the contracts event |
| `packages/lemma-render/config/lemma-render.php` | Modify | `cache_enabled` / `cache_ttl` keys |
| `packages/lemma-render/src/Http/Controllers/RenderController.php` | Modify | emit `Cache-Tag` on entry 200s; consult `RenderErrorCache` on not_found/gone |
| `packages/lemma-render/src/Http/Middleware/RenderPageCache.php` | Create | per-path 200 store/serve, key normalization, ETag/304/Cache-Control |
| `packages/lemma-render/src/RenderErrorCache.php` | Create | fixed 404/410 bodies, checked BEFORE Twig (the render-amplification fix) |
| `packages/lemma-render/src/Listeners/PurgeRenderCacheOnMenuUpdate.php` | Create | `MenuUpdated` → `invalidateTags(['lemma:render:page'])` |
| `packages/lemma-render/src/Console/ClearRenderCacheCommand.php` | Create | `render:cache:clear` (`deletePattern('render:*')`) |
| `packages/lemma-render/src/LemmaRenderServiceProvider.php` | Modify | service defs, factories, listener + command wiring |
| `packages/lemma-render/routes/public-routes.php` | Modify | attach the middleware to both routes |
| `packages/lemma-render/README.md` | Modify | caching section incl. the non-tag TTL-only fallback (spec pin) |
| `tests/Integration/Render/RenderPageCacheTest.php` | Create | all caching tests |
| `tests/Integration/Render/RenderPipelineTest.php` | Modify | tearDown render-cache purge (process-shared store hygiene) |
| `CHANGELOG.md` | Modify | `[Unreleased]` entries |
| `docs/NEXT.md`, `docs/V2_DESIGN.md` | Modify | flip sub-project 3 to shipped |

Test-harness facts the implementer needs (from `tests/Support/LemmaTestCase` and prior sessions):
- `composer test:reset-db && composer test:migrate` once, then `vendor/bin/phpunit <file>` per run. `CACHE_DRIVER=array` in `phpunit.xml` — the array driver fully supports tags AND `deletePattern`/`getKeys`.
- `$this->handle(Request::create(...))` drives the REAL kernel; `$this->container()` is the shared app container.
- The cache store is process-shared across tests in a run — any test that fills `render:*` keys MUST purge them in `tearDown()` or later tests serve stale seeds.
- The `SeedsPublishedContent` trait (`tests/Integration/Seo/Concerns/`) seeds a public `blog` type with one entry published at `/blog/hello` (en) and returns the entry uuid.

---

### Task 1: Move the `MenuUpdated` seam into `lemma-contracts`

`lemma-render` must subscribe to menu changes, but packs may only depend on `lemma-contracts` + framework. `MenuUpdated` currently lives in the navigation pack (its docblock already calls it the "Render-cache purge seam") — move it to contracts, where the other cross-pack seams (`ContentLifecycleEvent`, `MenuReader`) live.

**This is an ACCEPTED BREAKING FQCN change** — anything subscribed to `Glueful\Lemma\Navigation\Events\MenuUpdated` stops firing. Decision (explicit, not accidental): no deprecated alias class, no dual-dispatch transition. The packs are unreleased monorepo code with no external consumers; a repo-wide grep (done during planning) shows exactly ONE producer (`NavigationAdminController`, four dispatch sites) and ZERO subscribers today — the render listener added in Task 5 is the first. The CHANGELOG entry in Task 7 records it as breaking. If you find another subscriber the grep missed, update its import in this task rather than adding an alias.

**Files:**
- Create: `packages/lemma-contracts/src/Navigation/MenuUpdated.php`
- Delete: `packages/lemma-navigation/src/Events/MenuUpdated.php`
- Modify: `packages/lemma-navigation/src/Http/Controllers/NavigationAdminController.php:11` (import only)

**Interfaces:**
- Produces: `Glueful\Lemma\Contracts\Navigation\MenuUpdated` — `final class ... extends BaseEvent`, ctor `__construct(public readonly string $menuSlug)`. Task 5's listener and tests subscribe/dispatch this exact class.

- [ ] **Step 1: Create the contracts event**

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Navigation;

use Glueful\Events\Contracts\BaseEvent;

/**
 * A menu (or its tree) was created/renamed/replaced/deleted. Cross-pack seam:
 * lemma-navigation dispatches it; lemma-render purges its page cache on it.
 */
final class MenuUpdated extends BaseEvent
{
    public function __construct(public readonly string $menuSlug)
    {
        parent::__construct();
    }
}
```

- [ ] **Step 2: Delete the navigation-pack copy and update the one consumer**

```bash
rm packages/lemma-navigation/src/Events/MenuUpdated.php
rmdir packages/lemma-navigation/src/Events 2>/dev/null || true
```

In `packages/lemma-navigation/src/Http/Controllers/NavigationAdminController.php` change line 11:

```php
// old
use Glueful\Lemma\Navigation\Events\MenuUpdated;
// new
use Glueful\Lemma\Contracts\Navigation\MenuUpdated;
```

The four `$this->events->dispatch(new MenuUpdated(...))` call sites are unchanged (they use the short name).

- [ ] **Step 3: Verify — boundaries + navigation suite**

```bash
composer boundaries
vendor/bin/phpunit tests/Integration/Navigation/
```

Expected: `Pack boundaries OK`, navigation tests PASS. (If the DB isn't migrated yet: `composer test:reset-db && composer test:migrate` first.)

- [ ] **Step 4: Commit** *(logical grouping 1 — the seam move stands alone)*

```bash
git add packages/lemma-contracts/src/Navigation/MenuUpdated.php \
        packages/lemma-navigation/src/Events \
        packages/lemma-navigation/src/Http/Controllers/NavigationAdminController.php
git commit -m "refactor(contracts): move MenuUpdated seam from lemma-navigation to lemma-contracts"
```

(`git add` on the deleted `Events/` path stages the removal.)

---

### Task 2: Cache config keys + `Cache-Tag` surrogate header on rendered entry 200s

The middleware (Task 3) learns the entry/type tags from the response, not the resolver — the controller emits the same `Cache-Tag` header the delivery API already emits (`DeliveryEtag::cacheTag` format: `lemma:entry:{uuid}, lemma:type:{slug}`). Bonus: CDN purging via `PurgeCdnListener` now composes with rendered pages for free.

**Files:**
- Modify: `packages/lemma-render/config/lemma-render.php`
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php`
- Test: `tests/Integration/Render/RenderPageCacheTest.php` (created here)

**Interfaces:**
- Consumes: resolver results carry `'type' => ?string` (content-type slug) and `'content'['uuid']` (entry uuid) — both already exist.
- Produces: rendered content 200s carry `Cache-Tag: lemma:entry:{uuid}, lemma:type:{slug}` (comma+space separated — the exact strings `InvalidateCacheTagsListener` invalidates). Config keys `lemma_render.cache_enabled` (bool, default true) and `lemma_render.cache_ttl` (int, default 3600) — the Task 3/4 factories read these.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/RenderPageCacheTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;

/**
 * Render page caching (V2 sub-project 3). Drives the REAL kernel so the middleware,
 * router bucket order, and listener wiring are all under test. The cache store is
 * process-shared — tearDown purges render:* keys so later tests never serve stale seeds.
 */
final class RenderPageCacheTest extends LemmaTestCase
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
        self::assertStringContainsString('lemma:entry:' . $entry, $cacheTag);
        self::assertStringContainsString('lemma:type:blog', $cacheTag);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Integration/Render/RenderPageCacheTest.php
```

Expected: FAIL — `Cache-Tag` header is empty (controller doesn't emit it yet).

- [ ] **Step 3: Add the config keys**

Append to the returned array in `packages/lemma-render/config/lemma-render.php` (after `'reserved_exact'`):

```php
    // Full-page render cache (spec sub-project 3). false = exactly the uncached
    // behavior (set in dev while theming).
    'cache_enabled' => env('RENDER_CACHE_ENABLED', true),

    // Safety-net TTL per cached page (seconds); surrogate tags do the real
    // invalidation. On non-tag cache drivers this TTL is the ONLY freshness bound.
    'cache_ttl' => (int) env('RENDER_CACHE_TTL', 3600),
```

- [ ] **Step 4: Emit the Cache-Tag header from RenderController**

In `packages/lemma-render/src/Http/Controllers/RenderController.php`:

Change `home()`:

```php
    public function home(Request $request): Response
    {
        $homepageEntry = (string) config($this->context, 'lemma_render.homepage_entry', '');
        $locale = (string) config($this->context, 'i18n.default_locale', 'en');
        $entry = null;
        $typeSlug = '';

        if ($homepageEntry !== '') {
            $result = $this->resolver->resolveEntry($homepageEntry);
            if ($result['kind'] !== 'content') {
                return $this->homepageConfigFailure($homepageEntry);
            }
            $entry = $result['content'];
            $locale = (string) $result['locale'];
            $typeSlug = (string) ($result['type'] ?? '');
        }

        // Homepage ALWAYS renders index.twig (spec §4) — the entry, when configured,
        // arrives as context; routed pages use the entry hierarchy instead.
        $response = $this->render('index.twig', $locale, $entry, 200);
        if ($entry !== null) {
            $this->tagResponse($response, $entry, $typeSlug);
        }
        return $response;
    }
```

Change `renderEntry()`:

```php
    /** @param array{locale: ?string, type: ?string, content: ?array} $result */
    private function renderEntry(array $result): Response
    {
        $entry = $result['content'];
        $locale = (string) $result['locale'];
        // Template hierarchy: entry/{type-slug}.twig → entry.twig (the resolver's `type`
        // field carries the content-type slug for exactly this selection).
        $typeSlug = (string) ($result['type'] ?? '');
        $candidate = $typeSlug !== '' ? "entry/{$typeSlug}.twig" : '';

        $template = $candidate !== '' && $this->twig()->getLoader()->exists($candidate)
            ? $candidate
            : 'entry.twig';
        $response = $this->render($template, $locale, $entry, 200);
        $this->tagResponse($response, $entry ?? [], $typeSlug);
        return $response;
    }
```

Add the private helper (below `renderEntry()`):

```php
    /**
     * Stamp the surrogate Cache-Tag header (same strings the delivery API emits and
     * InvalidateCacheTagsListener invalidates) so the page cache and the CDN can both
     * purge this page on entry/type events.
     *
     * @param array<string,mixed> $entry
     */
    private function tagResponse(Response $response, array $entry, string $typeSlug): void
    {
        $uuid = is_string($entry['uuid'] ?? null) ? $entry['uuid'] : '';
        if ($uuid === '' || $typeSlug === '') {
            return;
        }
        $response->headers->set('Cache-Tag', "lemma:entry:{$uuid}, lemma:type:{$typeSlug}");
    }
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
vendor/bin/phpunit tests/Integration/Render/RenderPageCacheTest.php
vendor/bin/phpunit tests/Integration/Render/
```

Expected: PASS (all — the existing render suite must stay green). No commit yet — grouped with Tasks 3–5.

---

### Task 3: The `RenderPageCache` middleware — per-path 200s + HTTP validators

Key building, hit serving, the pinned eligibility rule for per-path entries, ETag/304/Cache-Control on everything cacheable, tags on store, disabled passthrough — wired onto both render routes. The fixed 404/410 keys are deliberately NOT this middleware's job (Task 4): a middleware checks the per-path key before `$next()`, so by the time a 404 reaches it Twig has already rendered — storing fixed bodies here would cap storage but not the render cost.

**Files:**
- Create: `packages/lemma-render/src/Http/Middleware/RenderPageCache.php`
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php` (service def + factory)
- Modify: `packages/lemma-render/routes/public-routes.php` (attach middleware)
- Modify: `tests/Integration/Render/RenderPipelineTest.php` (tearDown hygiene)
- Test: `tests/Integration/Render/RenderPageCacheTest.php`

**Interfaces:**
- Consumes: `Cache-Tag` header from Task 2; config keys `lemma_render.cache_enabled` / `lemma_render.cache_ttl`; `ThemeLocator::activePaths()['name']` (resolved theme name); container `CacheStore::class`.
- Produces: `Glueful\Lemma\Render\Http\Middleware\RenderPageCache` — `__construct(CacheStore $cache, string $theme, bool $enabled, int $ttl)`, `handle(Request $request, callable $next, ...$params): mixed`. Per-path cache keys `render:{theme}:{normalizedPath}` with stored entry shape `array{body: string, status: int, contentType: string, cacheTag: string, etag: string}`. Tags per Global Constraints. Tasks 5–6 rely on these keys and tags.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Integration/Render/RenderPageCacheTest.php`:

```php
    public function testSecondRequestServesFromCacheWithEtagAndCacheControl(): void
    {
        $this->seedBilingualPublishedEntry();
        $first = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(
            'public, max-age=0, must-revalidate',
            $first->headers->get('Cache-Control'),
        );
        $etag = (string) $first->headers->get('ETag');
        self::assertNotSame('', $etag);

        // Overwrite the stored body: if the second request serves the sentinel, it came
        // from the cache — the resolver/Twig pipeline provably did not run.
        $key = 'render:default:/blog/hello';
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
        self::assertSame(['render:default:/blog/hello'], $keys);
        foreach ($keys as $key) {
            self::assertStringNotContainsString('//', $key);
        }
    }

    public function testHomepageIsCachedUnderRootKey(): void
    {
        $this->handle(Request::create('/', 'GET'));
        self::assertIsArray($this->cache()->get('render:default:/'));
    }

    public function testDisabledMiddlewareIsAPurePassthrough(): void
    {
        // cache_enabled=false must be byte-for-byte today's behavior. Config-override
        // boots lose extension routes (loadRoutesFrom latch), so exercise the middleware
        // directly with enabled=false.
        $middleware = new \Glueful\Lemma\Render\Http\Middleware\RenderPageCache(
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
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/RenderPageCacheTest.php
```

Expected: FAIL — class `RenderPageCache` not found; no `render:*` keys ever appear.

- [ ] **Step 3: Implement the middleware**

Create `packages/lemma-render/src/Http/Middleware/RenderPageCache.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Http\Middleware;

use Glueful\Cache\CacheStore;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full-page cache for the rendered site (render caching spec §2–§3, §5).
 *
 * PER-PATH entries store ONLY 200 responses whose Content-Type is text/html (a
 * content render), keyed render:{theme}:{normalizedPath}. The themed 404/410 bodies
 * are NOT stored here — RenderErrorCache holds them under fixed keys and is consulted
 * by the controller BEFORE Twig renders (spec §2 amendment: a middleware sees a 404
 * only after the render already happened). This middleware still applies the uniform
 * HTTP validators (ETag / If-None-Match 304 / Cache-Control) to 404/410 text/html
 * responses so hit and miss carry identical semantics. Reserved-path JSON 404s,
 * redirects, 500s, and any non-HTML response pass through untouched.
 *
 * Storage goes through the SAME CacheStore binding InvalidateCacheTagsListener
 * invalidates. Every cached 200 is tagged with the surrogate keys the controller
 * emits in Cache-Tag (lemma:entry:{uuid}, lemma:type:{slug}) plus lemma:render:page.
 * On a non-tag driver addTags() is a no-op and freshness degrades to the TTL window
 * (spec §3 fallback) — nothing breaks.
 */
final class RenderPageCache implements RouteMiddleware
{
    public function __construct(
        private readonly CacheStore $cache,
        private readonly string $theme,
        private readonly bool $enabled,
        private readonly int $ttl,
    ) {
    }

    public function handle(Request $request, callable $next, ...$params): mixed
    {
        if (!$this->enabled) {
            return $next($request);
        }

        $key = $this->key($request->getPathInfo());
        $hit = $this->cache->get($key);
        if (is_array($hit)) {
            return $this->respond($request, $hit);
        }

        $response = $next($request);
        if (!$response instanceof Response) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response; // eligibility pin: JSON reserved 404s / redirects / non-HTML.
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getContent();
        $cacheTag = (string) $response->headers->get('Cache-Tag', '');

        if ($status === 200) {
            $entry = $this->entry($body, 200, $contentType, $cacheTag);
            $this->cache->set($key, $entry, $this->ttl);
            $this->cache->addTags($key, [...$this->surrogateTags($cacheTag), 'lemma:render:page']);
            // Serve stored entries on the miss path too, so hit and miss responses
            // carry identical headers (ETag / Cache-Control).
            return $this->respond($request, $entry);
        }

        if ($status === 404 || $status === 410) {
            // Body already comes from RenderErrorCache's fixed key (or its first
            // render) — no storage here, just the uniform validators.
            return $this->respond($request, $this->entry($body, $status, $contentType, $cacheTag));
        }

        return $response; // 500s: never cached, untouched.
    }

    /**
     * render:{theme}:{normalizedPath} — duplicate slashes collapsed, trailing slash
     * trimmed (root stays '/'), mirroring the resolver's canonical rules. Normalized
     * paths always start with '/', so per-path keys can never collide with the fixed
     * render:{theme}:404 / render:{theme}:410 keys.
     */
    private function key(string $path): string
    {
        $collapsed = (string) preg_replace('#/{2,}#', '/', '/' . trim($path, " \t"));
        $trimmed = rtrim($collapsed, '/');
        $normalized = $trimmed === '' ? '/' : $trimmed;
        return "render:{$this->theme}:{$normalized}";
    }

    /** @param array{body: string, status: int, contentType: string, cacheTag: string, etag: string} $entry */
    private function respond(Request $request, array $entry): Response
    {
        $headers = [
            'Content-Type' => $entry['contentType'],
            'ETag' => $entry['etag'],
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ];
        if ($entry['cacheTag'] !== '') {
            $headers['Cache-Tag'] = $entry['cacheTag'];
        }
        if ($this->etagMatches($request, $entry['etag'])) {
            return new Response('', 304, $headers);
        }
        return new Response($entry['body'], $entry['status'], $headers);
    }

    /** @return array{body: string, status: int, contentType: string, cacheTag: string, etag: string} */
    private function entry(string $body, int $status, string $contentType, string $cacheTag): array
    {
        return [
            'body' => $body,
            'status' => $status,
            'contentType' => $contentType,
            'cacheTag' => $cacheTag,
            'etag' => '"' . sha1($body) . '"',
        ];
    }

    /** @return list<string> the surrogate keys from a Cache-Tag header value */
    private function surrogateTags(string $cacheTag): array
    {
        if ($cacheTag === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $cacheTag))));
    }

    private function etagMatches(Request $request, string $etag): bool
    {
        $ifNoneMatch = (string) $request->headers->get('If-None-Match', '');
        if ($ifNoneMatch === '') {
            return false;
        }
        foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
            if ($candidate === $etag || $candidate === 'W/' . $etag || $candidate === '*') {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 4: Register the service and attach the middleware**

In `packages/lemma-render/src/LemmaRenderServiceProvider.php`:

Add imports (short names — never inline FQCNs in services()/factories):

```php
use Glueful\Cache\CacheStore;
use Glueful\Lemma\Render\Http\Middleware\RenderPageCache;
```

Add to the `services()` array:

```php
            RenderPageCache::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderPageCache'],
            ],
```

Add the factory method (near the other factories):

```php
    public static function makeRenderPageCache(ContainerInterface $container): RenderPageCache
    {
        $context = $container->get(ApplicationContext::class);
        return new RenderPageCache(
            // The SAME binding InvalidateCacheTagsListener invalidates (spec §3 pin) —
            // this identity is what makes zero-new-purge-code true.
            $container->get(CacheStore::class),
            $container->get(ThemeLocator::class)->activePaths()['name'],
            (bool) config($context, 'lemma_render.cache_enabled', true),
            (int) config($context, 'lemma_render.cache_ttl', 3600),
        );
    }
```

In `packages/lemma-render/routes/public-routes.php`, attach the middleware to both routes:

```php
use Glueful\Lemma\Render\Http\Middleware\RenderPageCache;

// ... (existing docblock)

$router->get('/', [RenderController::class, 'home'])
    ->middleware([RenderPageCache::class]);
$router->get('/{path}', [RenderController::class, 'page'])
    ->where('path', '.+')
    ->middleware([RenderPageCache::class]);
```

- [ ] **Step 5: Add tearDown hygiene to RenderPipelineTest**

The pipeline suite now fills `render:*` keys through the kernel; the store is process-shared, so purge in `tearDown()`. In `tests/Integration/Render/RenderPipelineTest.php`:

```php
    protected function tearDown(): void
    {
        // Hygiene: the render page cache and any sitemap entry cached during a render
        // request must not leak into later tests (the store is process-shared; sitemap
        // entries carry no TTL, and cached pages would serve earlier tests' seeds).
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Glueful\Lemma\Seo\Cache\SitemapCache::class)->forgetAll();
        parent::tearDown();
    }
```

- [ ] **Step 6: Run the render suite**

```bash
vendor/bin/phpunit tests/Integration/Render/
```

Expected: PASS — all new RenderPageCacheTest methods AND the pre-existing suite (RenderPipelineTest's own assertions are status/content-type/content only, so the added ETag/Cache-Control headers don't break them). No commit yet.

---

### Task 4: `RenderErrorCache` — fixed 404/410 bodies checked BEFORE Twig

The render-amplification fix. The controller consults this service on the resolver's not_found/gone arms: warm fixed key → cached body with `Cache-Tag: lemma:render:page`, NO Twig render; miss → render once, store, tag. The residual per-bogus-path cost drops to the resolver's indexed queries, exactly as the spec claims.

**Files:**
- Create: `packages/lemma-render/src/RenderErrorCache.php`
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` (constructor + `page()` arms)
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php` (service def + factory; `makeRenderController` gains the new dependency)
- Test: `tests/Integration/Render/RenderPageCacheTest.php`

**Interfaces:**
- Consumes: fixed-key naming from Global Constraints (`render:{theme}:404` / `render:{theme}:410`); container `CacheStore::class`; `ThemeLocator::activePaths()['name']`; config keys from Task 2.
- Produces: `Glueful\Lemma\Render\RenderErrorCache` — `__construct(CacheStore $cache, string $theme, bool $enabled, int $ttl)`, `themed404(callable $render): Response`, `themed410(callable $render): Response` (the callable returns the freshly rendered `Response`; theme is constructor-injected rather than a per-call param — it is boot-frozen, and the controller shouldn't know theme names). Fixed-key stored shape: `array{body: string, contentType: string}`. Task 5's MenuUpdated purge and Task 6's `deletePattern` cover these keys.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Integration/Render/RenderPageCacheTest.php` (add imports: `use Glueful\Lemma\Render\RenderErrorCache;` and `use Symfony\Component\HttpFoundation\Response;`):

```php
    public function testFixed404BodyIsStoredOnceAndReusedAcrossBogusPaths(): void
    {
        $first = $this->handle(Request::create('/no/such-page', 'GET'));
        self::assertSame(404, $first->getStatusCode());
        // The fixed body's Cache-Tag reaches the client/CDN too, so edge purges on
        // lemma:render:page compose for themed 404s.
        self::assertSame('lemma:render:page', $first->headers->get('Cache-Tag'));
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
        self::assertSame('lemma:render:page', $second->headers->get('Cache-Tag'));
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
        self::assertSame('lemma:render:page', $res->headers->get('Cache-Tag'));
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
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/RenderPageCacheTest.php
```

Expected: FAIL — class `RenderErrorCache` not found; `render:default:404` never appears.

- [ ] **Step 3: Implement the service**

Create `packages/lemma-render/src/RenderErrorCache.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fixed single-body 404/410 cache (spec §2 amendment). Consulted by the controller
 * BEFORE rendering 404.twig / error.twig: a warm key serves the stored body without
 * touching Twig — this, not per-path storage, is what kills render amplification for
 * bogus URLs (the per-path middleware only sees a 404 after the render already ran).
 *
 * ONE body per theme per status (render:{theme}:404 / render:{theme}:410), tagged
 * lemma:render:page and emitted as a Cache-Tag header so server AND CDN purges compose.
 * Only responses that match the expected status and are text/html are stored — a
 * failed error render (plain-text 500 fallback) is never cached. Same CacheStore
 * binding as the rest of the render cache (spec §3 pin).
 */
final class RenderErrorCache
{
    public function __construct(
        private readonly CacheStore $cache,
        private readonly string $theme,
        private readonly bool $enabled,
        private readonly int $ttl,
    ) {
    }

    /** @param callable(): Response $render renders 404.twig — invoked only on a cold key */
    public function themed404(callable $render): Response
    {
        return $this->fixedError(404, $render);
    }

    /** @param callable(): Response $render renders error.twig at 410 — invoked only on a cold key */
    public function themed410(callable $render): Response
    {
        return $this->fixedError(410, $render);
    }

    /** @param callable(): Response $render */
    private function fixedError(int $status, callable $render): Response
    {
        if (!$this->enabled) {
            return $render();
        }

        $key = "render:{$this->theme}:{$status}";
        $stored = $this->cache->get($key);
        if (is_array($stored)) {
            return new Response((string) $stored['body'], $status, [
                'Content-Type' => (string) $stored['contentType'],
                'Cache-Tag' => 'lemma:render:page',
            ]);
        }

        $response = $render();
        $contentType = (string) $response->headers->get('Content-Type');
        if ($response->getStatusCode() !== $status || !str_contains($contentType, 'text/html')) {
            return $response; // e.g. the error template itself failed → 500: never store.
        }

        $this->cache->set(
            $key,
            ['body' => (string) $response->getContent(), 'contentType' => $contentType],
            $this->ttl,
        );
        $this->cache->addTags($key, ['lemma:render:page']);
        $response->headers->set('Cache-Tag', 'lemma:render:page');
        return $response;
    }
}
```

- [ ] **Step 4: Wire it into the controller and provider**

In `packages/lemma-render/src/Http/Controllers/RenderController.php`:

Add the constructor dependency (after `$reserved`):

```php
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PublicRouteResolver $resolver,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
        private readonly ReservedPaths $reserved,
        private readonly RenderErrorCache $errors,
        private readonly LoggerInterface $logger,
    ) {
    }
```

Add the import:

```php
use Glueful\Lemma\Render\RenderErrorCache;
```

Change the `page()` match arms so not_found/gone go through the service (redirect/content arms unchanged):

```php
        return match ($result['kind']) {
            'redirect' => new Response('', $result['redirect']['status'], [
                'Location' => $result['redirect']['location'],
            ]),
            'gone' => $this->errors->themed410(
                fn (): Response => $this->render('error.twig', $this->defaultLocale(), null, 410),
            ),
            'content' => $this->renderEntry($result),
            default => $this->errors->themed404(
                fn (): Response => $this->render('404.twig', $this->defaultLocale(), null, 404),
            ),
        };
```

In `packages/lemma-render/src/LemmaRenderServiceProvider.php`:

Add the import:

```php
use Glueful\Lemma\Render\RenderErrorCache;
```

(Note: the provider is already in namespace `Glueful\Lemma\Render`, so the import is redundant for PHP but harmless — match the file's existing style, which imports `RenderController` explicitly. If phpcs flags it, drop the import and use the short name directly.)

Add to `services()`:

```php
            RenderErrorCache::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderErrorCache'],
            ],
```

Add the factory:

```php
    public static function makeRenderErrorCache(ContainerInterface $container): RenderErrorCache
    {
        $context = $container->get(ApplicationContext::class);
        return new RenderErrorCache(
            $container->get(CacheStore::class),
            $container->get(ThemeLocator::class)->activePaths()['name'],
            (bool) config($context, 'lemma_render.cache_enabled', true),
            (int) config($context, 'lemma_render.cache_ttl', 3600),
        );
    }
```

Update `makeRenderController` to pass the new dependency (insert before the logger, matching the constructor order):

```php
    public static function makeRenderController(ContainerInterface $container): RenderController
    {
        return new RenderController(
            $container->get(ApplicationContext::class),
            $container->get(\Glueful\Lemma\Contracts\Delivery\PublicRouteResolver::class),
            $container->get(TwigFactory::class),
            $container->get(RenderContextExtension::class),
            $container->get(ReservedPaths::class),
            $container->get(RenderErrorCache::class),
            $container->get(\Psr\Log\LoggerInterface::class),
        );
    }
```

- [ ] **Step 5: Run the render suite**

```bash
vendor/bin/phpunit tests/Integration/Render/
```

Expected: PASS — including the pre-existing `RenderPipelineTest::testThemed404` and `testGoneRendersErrorTemplateWith410` (their bodies now come from the fixed keys on repeat runs; tearDown purges keep them isolated). No commit yet.

---

### Task 5: Purge integration — the same-store proof + the `MenuUpdated` listener

Entry/type purges are ZERO new code — the test proves a publish through the REAL `InvalidateCacheTagsListener` drops a cached page (the spec §3 headline test). The pack's ONE new listener handles `MenuUpdated`.

**Files:**
- Create: `packages/lemma-render/src/Listeners/PurgeRenderCacheOnMenuUpdate.php`
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php` (service def + boot wiring)
- Test: `tests/Integration/Render/RenderPageCacheTest.php`

**Interfaces:**
- Consumes: `Glueful\Lemma\Contracts\Navigation\MenuUpdated` (Task 1); keys/tags from Tasks 3–4; the app-side `InvalidateCacheTagsListener` wiring for `EntryPublished` (already exists — nothing to add).
- Produces: `Glueful\Lemma\Render\Listeners\PurgeRenderCacheOnMenuUpdate` — `__construct(ContainerInterface $container)`, `onMenuUpdated(object $event): void`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Integration/Render/RenderPageCacheTest.php` (imports to add: `use App\Content\Events\EntryPublished;`, `use Glueful\Events\EventService;`, `use Glueful\Lemma\Contracts\Navigation\MenuUpdated;`):

```php
    public function testPublishPurgesCachedPageThroughTheRealListener(): void
    {
        // The spec §3 pin made concrete: the middleware stores via the same CacheStore
        // binding InvalidateCacheTagsListener invalidates, with the exact surrogate
        // strings — so a publish event purges page A while page B (the homepage,
        // which carries no entry tags) still hits.
        $entry = $this->seedBilingualPublishedEntry();
        $this->handle(Request::create('/blog/hello', 'GET'));
        $this->handle(Request::create('/', 'GET'));
        self::assertIsArray($this->cache()->get('render:default:/blog/hello'));
        $root = $this->cache()->get('render:default:/');
        self::assertIsArray($root);
        // Precondition, asserted rather than assumed: the test env runs the STANDALONE
        // homepage (lemma_render.homepage_entry unset), so the root entry carries no
        // entry/type surrogate tags — only lemma:render:page. If the homepage were
        // configured to entry A, publishing A SHOULD purge it too and this test's
        // "B still hit" assertion would be wrong by setup.
        self::assertSame('', $root['cacheTag']);

        $this->container()->get(EventService::class)
            ->dispatch(new EntryPublished($entry, $this->typeUuid()));

        self::assertNull($this->cache()->get('render:default:/blog/hello')); // A purged
        self::assertIsArray($this->cache()->get('render:default:/'));        // B still hit
    }

    public function testRenderPageTagInvalidationDropsEverything(): void
    {
        // Every stored entry — per-path pages AND the fixed 404 body — carries
        // lemma:render:page, so a broad invalidation empties the namespace.
        $this->seedBilingualPublishedEntry();
        $this->handle(Request::create('/blog/hello', 'GET'));
        $this->handle(Request::create('/no/such-page', 'GET'));
        self::assertCount(2, $this->cache()->getKeys('render:*'));

        $this->cache()->invalidateTags(['lemma:render:page']);
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
```

- [ ] **Step 2: Run to verify the split**

```bash
vendor/bin/phpunit tests/Integration/Render/RenderPageCacheTest.php
```

Expected: `testPublishPurgesCachedPageThroughTheRealListener` and `testRenderPageTagInvalidationDropsEverything` PASS already (that is the point — zero new code; if either fails, the tag strings or store binding are wrong and MUST be fixed, not worked around). `testMenuUpdatedPurgesPagesAndFixed404Body` FAILS (no listener yet).

- [ ] **Step 3: Implement the MenuUpdated listener**

Create `packages/lemma-render/src/Listeners/PurgeRenderCacheOnMenuUpdate.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;

/**
 * MenuUpdated → invalidateTags(['lemma:render:page']) (spec §4): menus can appear on
 * any rendered page, so menu mutations purge every cached page including the fixed
 * 404/410 bodies (they render the nav too). The CacheStore is resolved per-invocation,
 * not captured at construction — same rationale as the engine's
 * InvalidateCacheTagsListener (long-lived singleton, current cache.store binding).
 */
final class PurgeRenderCacheOnMenuUpdate
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onMenuUpdated(object $event): void
    {
        $this->container->get(CacheStore::class)->invalidateTags(['lemma:render:page']);
    }
}
```

- [ ] **Step 4: Wire it in the provider**

In `packages/lemma-render/src/LemmaRenderServiceProvider.php`:

Add imports:

```php
use Glueful\Events\EventService;
use Glueful\Lemma\Contracts\Navigation\MenuUpdated;
use Glueful\Lemma\Render\Listeners\PurgeRenderCacheOnMenuUpdate;
```

Add to `services()`:

```php
            PurgeRenderCacheOnMenuUpdate::class => [
                'shared' => true,
                'factory' => [self::class, 'makePurgeRenderCacheOnMenuUpdate'],
            ],
```

Add the factory:

```php
    public static function makePurgeRenderCacheOnMenuUpdate(
        ContainerInterface $container,
    ): PurgeRenderCacheOnMenuUpdate {
        return new PurgeRenderCacheOnMenuUpdate($container);
    }
```

In `boot()`, inside the `if ($registry->isEnabled('lemma.render'))` block (after the `serveFrontend` wiring):

```php
            // The pack's ONE purge listener (spec §4): menu changes purge broadly.
            // Entry/type purges need no render code — InvalidateCacheTagsListener
            // already invalidates the tags the middleware stores under.
            $events = app($context, EventService::class);
            $events->addListener(
                MenuUpdated::class,
                [app($context, PurgeRenderCacheOnMenuUpdate::class), 'onMenuUpdated'],
            );
```

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit tests/Integration/Render/
```

Expected: PASS (whole render suite).

- [ ] **Step 6: Commit** *(logical grouping 2 — the caching core, Tasks 2–5)*

```bash
composer phpcs && composer boundaries
git add packages/lemma-render tests/Integration/Render/RenderPageCacheTest.php \
        tests/Integration/Render/RenderPipelineTest.php
git commit -m "feat(lemma-render): full-page render cache with surrogate-tag invalidation

Per-path 200 text/html pages keyed render:{theme}:{normalizedPath} (middleware);
single fixed 404/410 body per theme checked BEFORE Twig (RenderErrorCache — kills
render amplification, not just cache fill); ETag/304; MenuUpdated broad purge;
entry/type purges ride the existing InvalidateCacheTagsListener (same CacheStore
binding + tags)."
```

---

### Task 6: `php glueful render:cache:clear`

The operator purge story (spec §6): render-scoped, works WITHOUT tag support — the answer to "edited the theme, still seeing old HTML" (Redis survives restarts, so restart ≠ cold cache). `deletePattern('render:*')` covers the per-path AND fixed keys.

**Files:**
- Create: `packages/lemma-render/src/Console/ClearRenderCacheCommand.php`
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php` (service def + `commands()`)
- Test: `tests/Integration/Render/RenderPageCacheTest.php`

**Interfaces:**
- Consumes: `render:*` keys from Tasks 3–4; `Glueful\Console\BaseCommand` (pack CLI convention — NOT raw Symfony Command); `CacheStore::deletePattern(string): bool`.
- Produces: `Glueful\Lemma\Render\Console\ClearRenderCacheCommand` — `#[AsCommand(name: 'render:cache:clear')]`, public `clear(): bool` (the testable unit, mirroring `PruneAnalyticsCommand::prune()`).

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/Render/RenderPageCacheTest.php`:

```php
    public function testRenderCacheClearCommandEmptiesTheNamespace(): void
    {
        // deletePattern works with or without tag support — the non-tag-driver
        // escape hatch (spec §6). Covers per-path AND fixed keys.
        $this->seedBilingualPublishedEntry();
        $this->handle(Request::create('/blog/hello', 'GET'));
        $this->handle(Request::create('/no/such-page', 'GET'));
        self::assertCount(2, $this->cache()->getKeys('render:*'));

        $command = $this->container()
            ->get(\Glueful\Lemma\Render\Console\ClearRenderCacheCommand::class);
        $command->clear();

        self::assertSame([], $this->cache()->getKeys('render:*'));
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit tests/Integration/Render/RenderPageCacheTest.php \
  --filter testRenderCacheClearCommandEmptiesTheNamespace
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement the command**

Create `packages/lemma-render/src/Console/ClearRenderCacheCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Console;

use Glueful\Cache\CacheStore;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clears the whole rendered-page cache (render:* keys — per-path pages AND the fixed
 * 404/410 bodies) via deletePattern — no tag support required, so it works on every
 * cache driver. The documented answer to "I edited the theme and still see old HTML":
 * a Redis-backed cache survives app restarts, so a restart does NOT imply a cold
 * cache (spec §6).
 */
#[AsCommand(
    name: 'render:cache:clear',
    description: 'Clear the rendered page cache (all render:* keys).',
)]
final class ClearRenderCacheCommand extends BaseCommand
{
    public function __construct(private readonly CacheStore $cache)
    {
        parent::__construct();
    }

    /** The testable unit: drop every render:* key. */
    public function clear(): bool
    {
        return $this->cache->deletePattern('render:*');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->clear();
        $this->success('Rendered page cache cleared (render:* keys).');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register it in the provider**

In `packages/lemma-render/src/LemmaRenderServiceProvider.php`:

Add import:

```php
use Glueful\Lemma\Render\Console\ClearRenderCacheCommand;
```

Add to `services()`:

```php
            ClearRenderCacheCommand::class => [
                'shared' => true,
                'factory' => [self::class, 'makeClearRenderCacheCommand'],
            ],
```

Add the factory:

```php
    public static function makeClearRenderCacheCommand(
        ContainerInterface $container,
    ): ClearRenderCacheCommand {
        return new ClearRenderCacheCommand($container->get(CacheStore::class));
    }
```

At the END of `boot()` (OUTSIDE the `isEnabled` gate — the analytics-pack precedent, and an operator may need to clear stale pages right after disabling the capability):

```php
        $this->commands([ClearRenderCacheCommand::class]);
```

- [ ] **Step 5: Run tests + verify CLI discovery**

```bash
vendor/bin/phpunit tests/Integration/Render/
php glueful commands:cache && php glueful render:cache:clear
```

Expected: tests PASS; the CLI prints the success line. (`commands:cache` rebuilds the manifest — new commands aren't discovered from a stale `storage/cache/glueful_commands_manifest.php`.) No commit yet — grouped with Task 7.

---

### Task 7: Docs, changelog, full verification

**Files:**
- Modify: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md` (`[Unreleased]`)
- Modify: `docs/NEXT.md`, `docs/V2_DESIGN.md` (flip sub-project 3 to shipped)

**Interfaces:**
- Consumes: everything shipped in Tasks 1–6 (names/keys/commands must be quoted exactly).

- [ ] **Step 1: README caching section**

Append to `packages/lemma-render/README.md`:

```markdown
## Page caching

Rendered pages are cached full-page in the framework `CacheStore`, keyed
`render:{theme}:{normalizedPath}`. Only `200` responses with `Content-Type:
text/html` are cached per path. The themed 404/410 bodies are stored once per
theme (`render:{theme}:404` / `render:{theme}:410`) and checked BEFORE the
template renders, so unique bogus URLs cost only the resolver's indexed queries —
they can neither fill the cache nor re-render `404.twig`. Redirects, JSON
responses, and errors are never cached.

| Env | Default | Meaning |
|---|---|---|
| `RENDER_CACHE_ENABLED` | `true` | `false` = uncached SSR (set in dev while theming) |
| `RENDER_CACHE_TTL` | `3600` | safety-net TTL per entry; tags do the real invalidation |

**Invalidation.** Every cached page is tagged with the same surrogate keys the
delivery API uses (`lemma:entry:{uuid}`, `lemma:type:{slug}`) plus
`lemma:render:page`. Publishing, unpublishing, or deleting content purges the
affected pages through the engine's existing cache listener — no render-specific
wiring. Menu changes (`MenuUpdated`) purge every cached page. Theme FILE edits
are not event-visible: run `php glueful render:cache:clear` (or wait out the
TTL). A theme NAME switch needs no purge — the theme is part of the key.

**Non-tag cache drivers.** If the configured cache driver does not support tags
(e.g. the file driver), targeted purges become no-ops and the page cache
degrades to TTL-only freshness: entries still store and expire by
`RENDER_CACHE_TTL`, and `php glueful render:cache:clear` remains the manual
escape hatch. A tag-capable driver (Redis) is recommended for production render
caching.
```

- [ ] **Step 2: CHANGELOG `[Unreleased]`**

Add under `[Unreleased]` in `CHANGELOG.md` (create the section heading if absent, matching the file's existing format — cover ALL touched changes):

```markdown
### Added
- **lemma-render: full-page render cache** (V2 sub-project 3) — `RenderPageCache`
  middleware keyed `render:{theme}:{normalizedPath}`; only `200 text/html` content
  renders cached per path; single fixed 404/410 body per theme served via
  `RenderErrorCache` BEFORE the template renders (bogus URLs cost resolver queries
  only); ETag/304 with `Cache-Control: public, max-age=0, must-revalidate`;
  entry/type purges ride the existing surrogate-tag listener; `MenuUpdated` purges
  broadly; TTL-only fallback on non-tag cache drivers. Config:
  `RENDER_CACHE_ENABLED` / `RENDER_CACHE_TTL`.
- **`php glueful render:cache:clear`** — operator purge for the rendered-page cache
  (theme file edits are not event-visible).
- Rendered entry pages (and cached 404/410 bodies) now emit `Cache-Tag` surrogate
  headers, so CDN purging composes with rendered pages via the existing
  `PurgeCdnListener`.

### Changed
- **lemma-contracts (BREAKING):** `MenuUpdated` moved from
  `Glueful\Lemma\Navigation\Events\MenuUpdated` to
  `Glueful\Lemma\Contracts\Navigation\MenuUpdated` (cross-pack seams live in
  contracts; lemma-render subscribes without depending on lemma-navigation). No
  deprecated alias — subscribers must re-import the contracts FQCN (none existed
  in-repo before this change).
```

- [ ] **Step 3: Flip the trackers**

`docs/NEXT.md` — replace the "Next step: render caching…" sentence (in "Recommended sequencing" item 1) with:

```markdown
   ✅ sub‑project 3 (render caching) **shipped** (2026‑07‑02): full‑page cache keyed
   `render:{theme}:{normalizedPath}`, surrogate‑tag invalidation through the existing
   lifecycle/`MenuUpdated` seams, ETag/304, `php glueful render:cache:clear`. Spec:
   `docs/superpowers/specs/2026-07-02-lemma-render-caching-design.md`.
```

`docs/V2_DESIGN.md` §4 — change the opening "**Decision:** render core ships **uncached SSR first**; caching is the next sub-project, not a blocker." to note shipped status:

```markdown
**Decision:** render core shipped **uncached SSR first**; caching followed as
sub-project 3 (**shipped 2026-07-02**).
```

(Leave the rest of §4 as-is — it already matches the shipped design.)

- [ ] **Step 4: Full verification**

```bash
composer phpcs
composer boundaries
vendor/bin/phpunit --testsuite Integration
```

Expected: phpcs clean, boundaries OK, Integration suite green (the render cache's process-shared-store hygiene is exactly what the tearDown purges are for — if an unrelated suite fails, check for stale `render:*` keys first).

- [ ] **Step 5: Commit** *(logical grouping 3 — command + docs)*

```bash
git add packages/lemma-render CHANGELOG.md docs/NEXT.md docs/V2_DESIGN.md
git commit -m "feat(lemma-render): render:cache:clear command + caching docs

Operator purge for theme-file edits (deletePattern, no tag support needed);
README documents the non-tag TTL-only fallback; trackers flipped to shipped."
```

(Never stage `CLAUDE.md`.)

---

## Self-Review Notes (already applied)

- Spec §2 store rules + placement amendment → Task 3 (per-path 200s only, validators for 404/410) + Task 4 (`RenderErrorCache` before Twig, with `testErrorRenderCallbackRunsOnlyOnceOnWarmKey` proving the render-amplification claim directly); §3 same-store/tags → Task 3 factory comment + Task 5 real-listener test; §3 non-tag fallback → inherent in `addTags` best-effort + README (Task 7); §4 invalidation → Task 5; §5 HTTP semantics → Task 3 `respond()` + ETag/304/HEAD tests; §6 CLI → Task 6; §7 test list → all mapped (per-page TTL assertion deliberately omitted: the `set(..., $this->ttl)` calls are visible in review, and clock-based expiry tests are timing-fragile).
- The one §7 deviation: "cache_enabled=false → byte-for-byte today's behavior (config-override boot)" is implemented as direct construction of BOTH units with `enabled=false` — the middleware asserts the exact downstream response object passes through unmodified, the error cache asserts the render callback runs every time and no headers are added. Override boots lose extension routes entirely (harness latch), so the kernel path can't reach either unit in an override boot at all; identity passthrough is the strongest available proof.
- Type consistency: middleware per-path entry shape `{body, status, contentType, cacheTag, etag}` matches its sentinel tests; `RenderErrorCache` fixed-key shape `{body, contentType}` matches `testFixed404BodyIsStoredOnceAndReusedAcrossBogusPaths` (which only mutates `body`); `render:default:...` key literals match the `key()`/fixed-key builders; `MenuUpdated` FQCN in Task 1 matches Task 5's imports; `RenderController` constructor order matches `makeRenderController` in Task 4.
- Known seam: `RenderErrorCache` lives in namespace `Glueful\Lemma\Render` (same as the provider), so its "import" in the provider is stylistic — Task 4 flags this so phpcs `UnusedUse`-style sniffs don't surprise the implementer.
