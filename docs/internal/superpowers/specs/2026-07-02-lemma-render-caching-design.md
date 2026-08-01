# Render Page Caching (`lemma-render` sub-project 3) — Design

**Date:** 2026-07-02
**Status:** Approved design, pre-implementation
**Parent:** `docs/V2_DESIGN.md` §4 (rendered-delivery sub-project 3)

Full-page caching for the rendered site: per-path 200 hits serve stored HTML without
touching the resolver or Twig; warm fixed 404/410 keys skip the Twig render (the
resolver still runs to classify the path — see the §2 placement amendment);
invalidation rides the surrogate-tag infrastructure the delivery cache already uses. Lives inside the existing `glueful/lemma-render` pack — caching is a
render concern, not a new pack.

## 1. Config (`lemma_render.*` additions)

| Key (env) | Default | Meaning |
|---|---|---|
| `cache_enabled` (`RENDER_CACHE_ENABLED`) | `true` | the page cache; `false` = exactly today's uncached behavior (set in dev `.env` while theming) |
| `cache_ttl` (`RENDER_CACHE_TTL`) | `3600` | safety-net TTL per entry (V2 §4); tags do the real invalidation |

## 2. The middleware and the key

**`RenderPageCache`** is a `RouteMiddleware` on BOTH render routes (`GET /` and the
catch-all). Hit → stored status/headers/body (+ETag handling) with no resolver/Twig
work; miss → pipeline runs, qualifying responses stored.

**Key = `render:{theme}:{normalizedPath}`** (pinned):

- The key path is NORMALIZED (duplicate `//` collapsed, trailing slash trimmed — the
  same collapse rules the resolver's canonical step uses), so `/blog//hello` and
  `/blog/hello` can never occupy two entries. Structurally this is belt-and-suspenders:
  only `content` 200s are cached and non-canonical requests 301 before rendering
  (redirects are never cached), so writes are canonical by construction — the explicit
  normalization makes reads correct too and removes the dependence on that inference.
- `{locale}` from V2 §4's original key sketch is deliberately ABSENT: locale is a pure
  function of the path (`/fr/blog/x` vs `/blog/x`), so it is provably redundant — V2 §4
  carries this amendment. Theme stays in the key so a theme switch can never serve
  stale markup.

**What is cached (pinned):** the middleware's store rules are unambiguous —
**per-path entries store ONLY 200 responses whose `Content-Type` is `text/html`** (a
content render); the themed 404/410 bodies (also `text/html`, non-200) are stored ONLY
under their FIXED keys below, never per-path. Everything else passes through untouched:
the reserved-path **JSON 404s must never enter the render cache** (they flow through
this same middleware), nor redirects, 500s, or any non-HTML response.

| Response | Cached? |
|---|---|
| `content` 200 (`text/html`) | per normalized path |
| themed 404 | ONE body per theme (`render:{theme}:404`) — path-independent, served for every not_found; kills render-amplification with O(1) storage (the residual per-bogus-path cost is a few indexed resolver queries, so rate limiting stays deferred) |
| 410 (gone) | ONE body per theme (`render:{theme}:410`), same reasoning |
| redirects (30x) | never (two cheap queries; caching risks stale Locations) |
| 500s / config errors | never |

**Placement of the fixed 404/410 lookup (amended):** the middleware checks the
PER-PATH key before `$next()`, so by the time a not_found response reaches it, Twig has
already rendered — a middleware-only design would cap storage at O(1) but still pay the
404 render for every unique bogus URL. The fixed-key check therefore lives in the
CONTROLLER, via a small `RenderErrorCache` service consulted on the resolver's
not_found/gone arms BEFORE rendering `404.twig`/`error.twig`: warm key → cached body,
no Twig; miss → render once, store, tag. The middleware keeps per-path 200 storage and
the uniform HTTP validators (§5) for everything cacheable.

## 3. Storage and tags — the mechanism is pinned, not implied

- `RenderPageCache` stores via **the same framework `CacheStore` instance that
  `InvalidateCacheTagsListener` invalidates** (container-resolved `CacheStore::class` —
  the identical binding the listener's `cache()` helper resolves). This identity is what
  makes "zero new entry/type purge code" TRUE; it is a requirement, not an assumption,
  and a test proves a publish through the real listener purges a cached page.
- Every cached 200 page: `addTags($key, ['lemma:entry:{uuid}', 'lemma:type:{slug}',
  'lemma:render:page'])` — the exact surrogate keys `DeliveryEtag::cacheTag` emits. The
  404/410 bodies get `['lemma:render:page']` only.
- **Non-tag-capable driver fallback (pinned):** when the configured cache driver does
  not support tags (`addTags` returns false / tag ops unsupported), the page cache
  degrades to **TTL-only**: entries still store and expire by `cache_ttl`, targeted
  purges become no-ops, and `render:cache:clear` (§6) remains the manual escape hatch.
  This mode is DOCUMENTED in the pack README (tag-capable Redis/db cache recommended for
  production render caching); nothing breaks, freshness just degrades to the TTL window.

## 4. Invalidation

- **Entry/type events: zero new code.** Publish/unpublish/delete already flow through
  `InvalidateCacheTagsListener` → `invalidateTags(['lemma:entry:{uuid}',
  'lemma:type:{slug}'])`, which now also removes the tagged render pages.
  `PurgeCdnListener` forwards the same tags to the edge, so CDN purging composes with no
  render-specific code.
- **`MenuUpdated` → `invalidateTags(['lemma:render:page'])`** — the pack's ONE new
  listener (menus can appear on any page, so menu mutations purge broadly, including the
  cached 404/410 bodies which render the nav too).
- **Theme file edits / site_name changes:** not event-visible. Twig's `auto_reload`
  recompiles templates, but cached FULL PAGES would serve stale HTML until TTL — and a
  Redis-backed cache survives restarts, so "restart implies cold cache" is not
  guaranteed. The operator purge story is §6's CLI command.
- Theme NAME switches need no purge (theme is in the key).

## 5. HTTP semantics

Cached entries persist body + status + `Content-Type` + an **ETag** (sha1 of body).
`If-None-Match` match → 304 with no body, served from the cache layer. All cacheable
responses (hit or miss) carry `Cache-Control: public, max-age=0, must-revalidate` —
browsers revalidate cheaply via ETag; server/CDN layers do the heavy lifting through
surrogate tags. `HEAD` serves from the same entries with the body stripped. **No
user-based bypass in v1** (V2 §4 amended): purges are event-driven and immediate, and
the bypass concept returns with preview-through-theme's token mechanism.

## 6. Operator purge: `php glueful render:cache:clear`

A render-pack CLI command (extends `Glueful\Console\BaseCommand`, discovered via the
pack's `#[AsCommand]` convention): clears the whole render page cache **without
requiring tag support** — `deletePattern('render:*')` (the mechanism the seo sitemap
cache already uses), falling back through the store's pattern-delete. Output reports the
namespace cleared. This is the documented answer to "I edited
`themes/my-site/templates/entry.twig` and still see old HTML": run `render:cache:clear`
(or wait out `cache_ttl`). Preferred over a generic `cache:clear --tag=...` because it
is discoverable, render-scoped, and works on non-tag drivers.

## 7. Testing

`tests/Integration/Render/` additions (harness notes: all entries carry TTLs —
deliberately none of the no-TTL-shared-store fragility; hit/miss assertions via a spy or
query-count, not timing):

- Hit/miss round trip: second GET serves without invoking the resolver; body/status/
  Content-Type identical.
- **Publish purges through the REAL listener**: cache page A and page B, publish A's
  entry → A misses (fresh render), B still hits — proving the same-store/same-tag
  identity of §3.
- Type-tag purge; `MenuUpdated` broad purge (both pages AND the 404 body).
- 404/410 single-body reuse across distinct bogus paths + assert per-path 404 keys do
  NOT accumulate in the store; the error-render callback runs ONCE (warm fixed key
  skips Twig — the render-amplification claim, proven directly).
- Key normalization: `/blog//hello` request 301s (uncached) and no `//` key ever exists;
  `/blog/hello` cached once.
- ETag/304; HEAD from cache; redirects never cached; **reserved-path JSON 404s are
  never stored** (request `/v1/nonexistent` twice: both hit the guard, no cache key
  appears) — the 200-text/html eligibility rule enforced.
- `cache_enabled=false` → byte-for-byte today's behavior (config-override boot,
  controller-direct where the route latch bites — see lemma-test-harness gotchas).
- Theme-in-key isolation (two locator instances / override boot).
- `render:cache:clear` empties the namespace (works with the array/test driver).

## 8. Out of scope

Per-page TTL overrides, stale-while-revalidate, request-collapsing/dogpile protection,
user/preview bypass (returns with preview-through-theme), rate limiting (the cached-404
posture removes the amplification; revisit only with evidence), and admin UI for cache
state.
