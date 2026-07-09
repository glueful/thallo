# Blog Posts Block — Design Spec

**Date:** 2026-07-08
**Status:** Draft for review
**Scope:** Subsystem 2 of 2 — the dynamic blog listing. One droppable block
(`blogPosts`) plus a reusable dynamic content-listing seam. A hand-picked
`featured_post` block (single referenced entry) is explicitly **deferred** and
out of scope.

## Goal

Add a `blogPosts` block to the default theme that dynamically lists published
`post` content-type entries (modeled on the Nuxt UI Pro blogPosts/blogPost theme
maps), rendering each as an inline card. Introduce a general, reusable
content-listing seam so blocks can query published entries at render time.

## Architecture

`blogPosts` is a **leaf block** — it fetches entries dynamically and holds no
child blocks, so it has no `BlockDepth` concern and nests anywhere. There is **no
`blogPost` block or partial**: the item card is markup inline in `blogPosts.twig`
(a same-file Twig `{% macro %}` for loop readability). When a future
`featured_post` block adds a second consumer, the card is extracted to a shared
partial then — not now (YAGNI).

The dynamic listing is a new seam modeled **exactly** on the existing `facets()`
path (`RenderContextExtension` → injected `?FacetCountsReader` contract → app
`EngineFacetCountsReader` → `{items, cache_tags}` → `collectTags()` → drained into
the response `Cache-Tag` header by `RenderController`):

- **Contract** `Thallo\Contracts\Delivery\EntryListReader`:
  `list(string $type, array $opts, string $locale): array` → `{items, cache_tags}`.
- **App impl** `App\Content\Delivery\EngineEntryListReader` — reuses the delivery
  repository + `DeliveryItemShaper` + canonical path logic (the reusable core of
  `EnginePublicRouteResolver::listItems()`, extracted so both share one path).
- **Twig** `entries(type, opts)` on `RenderContextExtension` (injected
  `?EntryListReader`, **null-safe** like `facetReader` — the pack renders without
  the app): calls the reader, `collectTags(result['cache_tags'])`, returns items.

## Tech Stack

PHP 8.3 (contract, Engine reader, block definition, `SetupService` seed), Twig
(`blocks/blog_posts.twig`), plain CSS (`blocks.css`), PHPUnit. Reuses
`DeliveryRepository::paginatePublished`, `SortCompiler`, `DeliveryItemShaper`,
`ReferenceFilterResolver`/`membershipPredicate`, `CanonicalPath`.

## Global Constraints

- Repo `/Users/michaeltawiahsowah/Sites/glueful/thallo`; work on `dev`; hold all
  commits until explicit go-ahead; no AI/Anthropic attribution.
- **One new block type**: `blog_posts` (snake_case slug). Seed count 42 → 43.
- **Server-side limit clamp**: the reader clamps `limit` to `1..12` regardless of
  input — the block schema's bounds are editor ergonomics only; the seam protects
  itself.
- **No `json` field type**; no new admin widgets (existing types only).
- **Pre-launch DB sync**: add `cover` to the `post` seed in `SetupService`; sync
  the current dev DB's `post` content type manually (not a migration). Seed the
  `blog_posts` block type via `thallo:blocks:seed`.

## The dynamic listing seam

### Contract `Thallo\Contracts\Delivery\EntryListReader`

```
list(string $type, array $opts, string $locale): array
// $opts: ['limit' => int, 'order' => 'newest'|'oldest', 'category' => ?string]
// returns ['items' => list<array>, 'cache_tags' => list<string>]
```

### `EngineEntryListReader::list()` behavior (in order)

1. **Clamp** `limit = max(1, min(12, (int) $opts['limit']))`. Default 3 if absent.
2. **Resolve + gate type**: `types->findBySlug($type)`; if missing or **not
   publicly deliverable** → return `{items: [], cache_tags: []}` (fail-safe).
3. **Order**: `newest` → published/created **desc**; `oldest` → **asc**, built via
   `SortCompiler` (default is newest).
4. **Category filter** (only when `$opts['category']` is a non-empty string):
   find the **first `type: reference` field with `filterable: true` in schema
   declaration order** (the taxonomy/term-reference kind — deterministic, not the
   first reference of any kind). If none, or the term slug does not resolve to a
   published term of the field's target type → return empty. Otherwise apply
   `projection->membershipPredicate($typeUuid, $field, $termUuid)` as the filter.
5. **Query**: `delivery->paginatePublished($typeUuid, $locale, page: 1,
   perPage: $limit, filter, order)`.
6. **Shape**: shape rows via `DeliveryItemShaper` + attach canonical `href`
   (shared with `listItems()`), collecting an `ExpandedTargets`.
7. **Cache tags**: return the **broad listing dependency** `thallo:type:{slug}`
   (the correctness mechanism — a newly published post or a changed category
   assignment that alters the top-N must purge the page) **plus** each returned
   item's entry tag (`thallo:entry:{uuid}`), plus the expansion tags, plus — when a
   category filter applied — the term's entry tag and the term type's tag
   (mirroring `RenderController::tagCollection` for archives).
   **Tag identity**: the broad tag uses the **resolved type row's slug**
   (`types->findBySlug($type)['slug']`), not the raw submitted string — the
   identity comes from the resolved row. This matches the system-wide convention:
   entries are tagged by UUID, **types by slug** (every type-tag emitter and purge
   event uses `thallo:type:{slug}`). A `thallo:type:{uuid}` tag is deliberately
   **not** added — nothing else emits or purges by type-UUID, so it would be a dead
   tag. If type slugs become editable later, the fix is a system-wide move of type
   tags to UUID (all emitters + purge events), not a one-off divergence here.

### `entries()` Twig function

```twig
{% set posts = entries(type, { limit: limit, order: order, category: category }) %}
```
Returns the shaped `items` (a list). Registers the reader's `cache_tags` via the
extension's existing `collectTags()`; `RenderController` drains them into
`Cache-Tag`. Null-safe: returns `[]` if no `EntryListReader` is bound.

### Preview flag

Expose the extension's existing preview state (`annotateBlocks`) to Twig as
`is_preview()` so the block shows an empty-state placeholder **only** in
preview/canvas; public renders emit nothing when the list is empty.

## `blog_posts` block

### Fields

| Field | Type | Notes |
|---|---|---|
| `type` | string | content-type slug; default `post`; any publicly-deliverable type |
| `limit` | number (min 1, max 12) | top-N; default 3 (reader re-clamps) |
| `order` | enum `newest`\|`oldest` | default `newest` |
| `category` | string | optional term slug; filters via the type's first filterable reference field |
| `columns` | enum `1`\|`2`\|`3`\|`4` | grid columns; default `3` |
| `variant` | enum `outline`\|`soft`\|`subtle`\|`ghost`\|`naked` | card style; default `outline` |
| `orientation` | enum `vertical`\|`horizontal` | card internal layout; default `vertical` |

### Render (`blocks/blog_posts.twig`)

1. `entries(data.type|default('post'), {limit, order, category})`.
2. Root `thallo-block thallo-block-blog_posts` + `--columns-{n}`,
   `--variant-{v}`, `--orientation-{o}` modifiers.
3. If items non-empty → a `__grid` of inline **card** markup (same-file macro),
   one per shaped item. If empty → `is_preview()` ? a `__empty` placeholder
   ("No posts found") : nothing.

### Inline card (macro in `blog_posts.twig`)

Binds to a shaped `post` item:
- `cover` (asset uuid) → `media()` in a `__image`; **omitted gracefully** when
  unset (Nuxt `image:false`).
- `title` → `__title` (linked to `href`).
- `excerpt` → `__description`.
- entry **date** (the shaped item's published/created timestamp) → `__date`.
- `categories` (expanded reference) → `__badge`(s).
- `href` (canonical path) wraps the card / title.

**Author is out of scope for v1** (needs user-record resolution). Card
`variant`/`orientation` cascade from the block root via descendant CSS (like the
pricing blocks). Styling translated from the Nuxt blogPost map to theme
tokens/BEM (`__card __image __title __description __meta __date __badge`), rounded
cards (`--radius-lg`).

## `post` content-type change

Add a `cover` asset field to the `post` type in `SetupService`:
```php
['name' => 'cover', 'type' => 'asset'],
```
Fresh installs get it; the current dev DB's `post` type is synced manually
(pre-launch). The card treats `cover` as optional.

## Wiring

1. `Thallo\Contracts\Delivery\EntryListReader` (render pack contract).
2. `App\Content\Delivery\EngineEntryListReader` implementing it; extract the
   shared shape+href core from `EnginePublicRouteResolver::listItems()` so both
   use one path.
3. Bind the contract in the app service provider; inject `?EntryListReader` into
   `RenderContextExtension`; add `entries()` + `is_preview()` Twig functions.
4. Add `blog_posts` to `StarterBlockTypes::definitions()` (seed 42→43); add
   `cover` to the `post` seed in `SetupService`.
5. `blocks/blog_posts.twig` + CSS appended to `blocks.css`.

## Testing

- **EngineEntryListReader**: lists published posts (respects clamped limit,
  order newest/oldest); non-deliverable/unknown type → empty; category filter
  restricts to members; unknown category / type-without-filterable-reference →
  empty; **cache_tags include the broad `thallo:type:post`** plus per-item entry
  tags (assert a post outside the returned top-N is still covered by the broad
  tag); limit clamp: `limit: 50` returns ≤ 12.
- **entries() Twig fn**: returns items; registers tags (drainTags contains the
  broad type tag); null reader → `[]`.
- **blog_posts render**: N cards for N items; `--columns-{n}` modifier; a card
  with no `cover` omits `__image`; category/date/title/href present; empty result
  → `__empty` only when `is_preview()`, nothing on public.

## Out of Scope

- `featured_post` (hand-picked single entry; needs a reference picker +
  fetch-by-uuid seam + stale-selection handling) — a separate future spec.
- Author display on the card. `created_by` is not in the shaped delivery item,
  so a byline would need a second new seam resolving a user UUID → display name +
  avatar. That is deferred deliberately: it should land later as a proper **author
  identity** seam (UUID → name/avatar via `UserProviderInterface`) that also
  backs byline blocks, author pages, and author archives — not a one-off
  resolution wired only into `blogPosts`. v1 card omits author entirely.
- Interactive pagination / offset in the block (the `/post` listing page owns
  real pagination).
- Any new field type or admin widget.
