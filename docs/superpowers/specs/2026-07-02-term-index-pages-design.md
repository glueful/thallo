# Taxonomy Term Index Pages — Design

**Date:** 2026-07-02
**Status:** Approved design, pre-implementation
**Parent:** `docs/V2_DESIGN.md` §6 follow-up tracks ("taxonomy term INDEX pages").
Builds directly on the shipped `FacetCountsReader`
(`docs/superpowers/specs/2026-07-02-preview-through-theme-design.md` §5) and the
listing/archive grammar
(`docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md`).

A rendered page enumerating all terms of a filterable reference field with counts —
`/post/terms/category` → "PHP (12), Laravel (4), …", each linking to its archive page.

## 1. URL grammar

**`/{type}/terms/{field}`** (+ optional active-locale prefix: `/fr/post/terms/category`).

- At 3 segments, **`terms` parses BEFORE archive lookup, exactly like `page`** —
  `terms` joins `page` as a static reserved word. Costs (documented + characterization-
  tested, same precedent): an entry slugged `terms` is shadowed; an archive FIELD named
  `terms` cannot have rendered archives. Rejected alternatives: `/{type}/{field}`
  field-wins (schema edits silently shadow published entry URLs) and entry-wins
  (data-dependent URLs — publishing an entry slugged like a field kills the index).
- Gated by the same **`lemma_render.listing_types` allowlist** — a term index is a
  listing variant; unlisted types stay `not_found` (grammar dormant when the list is
  empty).
- **No pagination in v1**: the reader caps at 500 terms, one page;
  `/{type}/terms/{field}/page/n` stays `not_found` (4-segment paths with `terms` do not
  parse).

## 2. Resolution split (thin kind; the reader's invariant does the gating)

The resolver returns a THIN result — **`kind: 'terms'`** with `type`, `field`, `locale`
and no payload (no new payload keys in the result shape). The render controller fetches
the data itself via the `FacetCountsReader` contract it already knows
(`counts(type, field, locale, 500)`), and branches on the reader's invariant:

**PINNED CONTRACT INVARIANT (the controller's 404-vs-200 split depends on it):**
`FacetCountsReader::counts()` MUST return **non-empty `cache_tags` for every VALID
facet — even with zero items** — and **empty `cache_tags` ONLY on gate failure**
(unknown type, non-filterable/non-reference field, non-visible type on either side).
This is already the shipped behavior; this spec promotes it from behavior to contract:
the interface docblock states it as an invariant consumers may dispatch on.

- `cache_tags === []` → gate failed → **themed 404** (the ordinary `RenderErrorCache`
  path — nothing preview-like here);
- `cache_tags !== []` → **200**, even with zero terms (a valid empty index renders).

Cache-tagging stays structural: the controller merges the reader's `cache_tags` into
`Cache-Tag` via the existing `mergeCacheTags` — publishes purge index pages with zero
new invalidation code (both type tags are the broad-purge mechanism, exactly as for
facet sidebars).

## 3. Rendering

- Template family: **`terms/{type-slug}.twig` → `terms.twig`** (default theme ships
  `terms.twig`, escape-by-default, reusing the listing look).
- Context: `terms` — the reader's items, **each given a ready `href` BY THE RENDER
  CONTROLLER** (pinned: the reader stays counts + tags, shared with `facets()`; render
  URL grammar does not leak into it). `href` = the term's archive path
  `/{type}/{field}/{slug}` with the same default-locale collapse the listing/archive
  hrefs use (locale-prefixed for non-default locales); a `null`-slugged term renders
  unlinked (`href: null`). Plus `type` and `field`.
- Ordering is the reader's: `count DESC, slug ASC` — one rule shared with `facets()`
  and the API facets endpoint.

## 4. Testing

- Grammar: `terms` beats archive parsing at 3 segments (a filterable field named
  `terms` — its index still works, its ARCHIVE path is shadowed: characterized); locale
  variants; unlisted type → not_found; `/{type}/terms/{field}/page/2` → not_found;
  entry slugged `terms` shadowed (characterized).
- Gate-vs-empty split: non-filterable/unknown field → themed 404; valid field with zero
  members → 200 rendering the template's empty-state branch; non-visible target type →
  themed 404.
- Kernel: index renders term names + counts with archive hrefs (default-locale collapse
  asserted; null-slug term unlinked); Cache-Tag carries BOTH type tags; a publish
  through the REAL listener purges a cached index page; `/blog…`-style listing/archive/
  entry routes unaffected.
- Template fallback: `terms/{type}.twig` absent → `terms.twig`.
- Contract invariant: reader tests already assert valid-empty ⇒ tags and gate ⇒ no
  tags (FacetsTwigTest); the docblock promotion is verified by those existing tests.

## 5. Out of scope

Pagination of term indexes; per-field ordering options; term descriptions/bodies on the
index (the archive page is the term's page); a headless term-index API endpoint (the
facets endpoint already serves that need); term hierarchies.
