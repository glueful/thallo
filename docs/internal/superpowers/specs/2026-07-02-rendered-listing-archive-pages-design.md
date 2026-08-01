# Rendered Listing / Archive Pages — Design

**Date:** 2026-07-02
**Status:** Approved design, pre-implementation
**Parent:** the first follow-up track in `docs/V2_DESIGN.md` §6 ("listing/archive pages"),
unblocked by `docs/superpowers/specs/2026-07-02-term-archives-facets-design.md` (the
`published_entry_references` projection + archive semantics this reuses).

Type listing pages (`/blog`) and term-archive pages (`/blog/category/php`) served
through the `lemma-render` Twig pipeline. Data and URL classification are
**resolver-owned**: only resolver-provided data lets the controller emit correct
`Cache-Tag` headers, so publishes purge listing pages structurally rather than
template-dependently.

## 1. URL grammar

Extends `EnginePublicRouteResolver`'s parsing. Normalization-first 301s are unchanged
and still run before any parsing. With an optional ACTIVE-locale first segment (the
established "locale wins" rule, now applied at every segment count):

| Path | Meaning |
|---|---|
| `/{type}` | listing, page 1 |
| `/{type}/page/{n}` | listing, page n (n ≥ 2) |
| `/{type}/{field}/{term}` | term archive, page 1 |
| `/{type}/{field}/{term}/page/{n}` | archive, page n (n ≥ 2) |
| `/{type}/{slug}` | entry page — existing, unchanged |

**Pagination is path-based by structural necessity (pinned):** the render page cache is
keyed `render:{theme}:{normalizedPath}` — path only — so query-string paging would serve
cached page 1 forever. Distinct paths give distinct cache entries for free.

**Canonical page-number handling (pinned):**
- `/blog/page/1` → **301** `/blog`; `/blog/category/php/page/1` → **301**
  `/blog/category/php` (one canonical URL per page of results).
- `/blog/page/0`, negative, or non-numeric `{n}` → **not_found** (themed 404).
- `{n}` beyond `total_pages` → **not_found**, where (pinned)
  **`total_pages = max(1, ceil(total / per_page))`** — the `max(1, …)` is load-bearing:
  a naive `ceil(0 / per_page) = 0` would make page 1 of an empty listing "beyond range".
  **Page 1 is always valid** (an empty listing renders 200 with empty `items`).

**Disambiguation (deterministic, documented as the cost of clean URLs):**
- At 3 segments, `page` + all-digits third segment parses as listing pagination BEFORE
  field lookup — **`page` is a reserved word as a `{field}` segment**. A reference field
  literally named `page` cannot have rendered archives (its API archive endpoint still
  works); a characterization test pins the collision behavior.
- A 3-segment path parses as `/{locale}/{type}/{slug}` only when segment 0 is an active
  locale — exactly today's rule; type slugs colliding with locale codes lose, as they
  already do at 2 segments... which now also applies at the NEW 2-segment reading:
  `/fr/blog` is a locale listing of `blog`, never an entry `blog` of a type `fr`.

## 2. Resolver contract (the one seam, extended)

`PublicRouteResolver`'s result gains kinds **`listing`** and **`archive`** and payload
keys (a breaking docblock change to the contract — accepted, monorepo precedent):

```
kind: 'content'|'listing'|'archive'|'redirect'|'gone'|'not_found'
locale: ?string, type: ?string, content: ?array, redirect: ?array,
listing: ?{items: list<array>, page: int, per_page: int, total: int, total_pages: int},
                   // total_pages = max(1, ceil(total / per_page)) — never 0 (§1 pin)
term: ?array,      // archive kind: the shaped term entry (show shape, seo included)
term_type: ?string, // archive kind: the term's content-type slug (drives its cache tag)
field: ?string     // archive kind: the source field name
```

`EnginePublicRouteResolver` implements both kinds with machinery that already exists:

- **Members:** `DeliveryRepository::paginatePublished` (default order,
  `published_at DESC`); archives add the **`published_entry_references` membership
  predicate — the same single source the API archive endpoint uses (pinned: the two
  surfaces cannot diverge)**.
- **Term resolution:** uuid-first then `referenceSlugField` via `ReferenceTargetResolver`
  (published-in-locale, ambiguity → not_found on this anonymous surface); the term body
  is shaped via `shapePublic` (it is a real page subject — seo included).
- **Gates mirror the API:** type publicly deliverable (anonymous rules), `{field}` must
  be a `filterable: true` `reference` field, unknown/unpublished term → not_found.

**Listing item shape (pinned):** `shapePublic()` is the wrong primitive for list items —
it stamps per-entry SEO and behaves like a show response. Listing/archive `items` use
the delivery **list** shape (reference-expanded, field-projected, no per-item seo
object) **plus a ready `href` per item** (the entry's relative canonical path in the
listing's locale, batch-rendered from the entries' routes via the core path machinery —
one query for the page, not N). Themes must never call `path()` in a loop; an item
without a route renders with `href: null` (themes decide whether to link).

## 3. Opt-in + config (render-owned, read softly by core)

| Key (env) | Default | Meaning |
|---|---|---|
| `lemma_render.listing_types` (`RENDER_LISTING_TYPES`, comma-separated slugs) | `''` → `[]` | types with rendered listings AND archives; empty = the entire grammar is dormant |
| `lemma_render.listing_per_page` (`RENDER_LISTING_PER_PAGE`) | `10` | items per rendered page |

**Placement (pinned wording):** the keys are RENDER config, not core config. Core's
`EnginePublicRouteResolver` may read `lemma_render.listing_types` as a **soft, optional
config namespace** because this grammar exists only for rendered delivery — that is not
a class/package dependency, and with the pack absent or the config empty the grammar is
dormant (every such path stays `not_found`). Moving the keys to core config would make a
render-only concern look like a headless delivery feature. Archives are gated by the
same allowlist — an archive is a listing variant.

## 4. Render pack: templates, context, caching

`RenderController::page()` gains two match arms. Template hierarchy mirrors entries:

```
listing/{type-slug}.twig → listing.twig
archive/{type-slug}.twig → archive.twig
```

The default theme ships `listing.twig`, `archive.twig`, and a `_pagination.twig`
partial (escape-by-default, like the rest of the reference theme).

**Template context:** `items` (list shape + `href`), `pagination`
`{page, per_page, total, total_pages, prev_path, next_path}` — `prev_path`/`next_path`
are ready-made canonical paths (`null` at the edges; page 2's `prev_path` is the BARE
listing path, honoring the §1 canonical), `type` (slug), and for archives `term`
(shaped entry) + `field`. `site`/`menu()`/`asset()`/`path()` behave as on other pages.

**Caching (pinned):** the controller emits `Cache-Tag` on listing/archive 200s:
- every listing AND archive page carries **`lemma:type:{type}`** — the broad type tag is
  the correctness mechanism, not an optimization: page 2's contents change when ONE new
  entry publishes, so per-entry tags alone are insufficient. The existing
  `InvalidateCacheTagsListener` drops the type tag on every entry lifecycle event —
  zero new invalidation code.
- per-item entry tags additionally (single-entry edits purge precisely);
- archives add `lemma:entry:{termUuid}` and `lemma:type:{termTypeSlug}`.

`RenderPageCache` needs **no changes**: per-path storage keys each pagination page
separately, and its tag parsing already stores whatever `Cache-Tag` the controller
emits.

## 5. Testing

- Resolver grammar table-tests: every segment count × locale variants; `page`
  reserved-word collision (field named `page` → pagination wins, characterized);
  `/page/1` → 301 (listing AND archive); `/page/0`/non-numeric → not_found; beyond
  `total_pages` → not_found; empty listing page 1 → 200/empty items AND `total_pages`
  reports 1, not 0 (the `max(1, …)` pin); `/blog/page/2` on an empty listing →
  not_found; allowlist
  empty/missing → not_found; non-filterable or non-reference `{field}` → not_found;
  unknown term → not_found.
- Membership parity: archive members come from `published_entry_references` (seed a
  JSONB/projection divergence; the projection wins — mirroring the API test).
- Item shape: list shape + `href` present and locale-correct; routeless item →
  `href: null`; NO per-item seo object.
- Kernel pipeline: `/blog` renders items through the catch-all; `/blog/category/php`
  renders term + members; template fallback ladder (`listing/blog.twig` absent →
  `listing.twig`).
- Caching: `/blog` and `/blog/page/2` are distinct cache entries; **publish of a new
  entry through the REAL listener purges a cached listing page** (the type-tag pin,
  proven); archive purges on term entry events.
- `prev_path`/`next_path` canonical correctness (page 2 → bare path).

## 6. Out of scope (explicit follow-ups)

`facets()` Twig function for tag clouds/sidebars (deferred by decision — needs its own
contracts seam + tagging story); listing pages in the sitemap (seo-pack follow-up);
RSS/Atom feeds; custom sort/filter params on rendered listings; taxonomy term INDEX
pages (`/blog/category` enumerating all terms); per-type `per_page` overrides.
