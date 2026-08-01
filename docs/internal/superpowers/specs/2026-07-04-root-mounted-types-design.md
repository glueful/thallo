# Root-Mounted Content Types (`/about`, not `/pages/about`)

**Date:** 2026-07-04
**Status:** Draft — awaiting review
**Depends on:** content-type metadata PATCH (same-day, staged), nav-entry-items target paths

## 0. Problem

The public URL grammar is fixed type-first: `/{locale?}/{type}/{slug}`. For a
marketing-site "pages" type that yields `/pages/about` — a CMS leaking its
internals. Only the homepage escapes the grammar (served at `/` via the
homepage setting). `LEMMA_SEO_ROUTE_TEMPLATE` changes what URLs we *print*,
not what the parser accepts, so config cannot flatten paths.

**Goal:** a per-type **`mount_at_root`** flag. Entries of a root-mounted type
resolve at `/{locale?}/{slug}` (`/about`, `/fr/a-propos`); every canonical
surface (nav targets, sitemap, SEO canonicals/hreflang, delivery hrefs,
redirect targets) follows. Blog-like types keep `/blog/hello` untouched.

## 1. Storage & admin surface

- Migration: `content_types.mount_at_root` boolean NOT NULL default `false`
  (new migration, mirrors `public_delivery`'s shape in 001).
- `ContentTypeRepository`: hydrate + `create()` + `updateMeta()` gain the key.
- `CreateContentTypeData` / `UpdateContentTypeData` DTOs gain
  `?bool $mount_at_root` (nullable = unchanged on PATCH).
- Admin: "Mount at root" `USwitch` on the create screen and the type editor
  (next to Public delivery; same PATCH + notify + revert-on-failure pattern).
- Orthogonal to `public_delivery` — a non-public root-mounted type 404s
  exactly like a non-public prefixed one.
- Flipping the flag (either direction) purges `render:*` — same rule as
  `public_delivery`.

## 2. Resolution grammar (the only parser change is the 1-segment case)

`EnginePublicRouteResolver::resolvePath`, after the existing normalization,
reserved-prefix guard (`v1`, `admin`, `extensions`, `theme-assets`, handled
before the resolver), and locale-wins shift:

- **1 segment `/{s}`** — precedence order:
  1. Exact content-type slug match → listing (existing behavior, including
     the `lemma_render.listing_types` allowlist gate). **Type wins**: types
     are few and explicitly named; this keeps every existing URL stable.
  2. Else → **root-mounted entry lookup**: resolve slug `s` through the
     locale chain against routes belonging to root-mounted, publicly
     deliverable types. Found → entry (same content shape: preview-session
     draft overlay, working-copy overlay, presentation extraction, cache
     tags — the existing entry tail is shared, not duplicated).
  3. Else → root **redirect lookup** (see §4), else 404.
- **2 segments `/{type}/{slug}`** where the type is root-mounted → the entry
  still resolves but **301s to the root canonical** `/{slug}` — the same
  canonical-collapse family as `page/1` and default-locale collapse.
- Everything else (3/5-segment listing, archive, `page`, `terms`) is
  untouched. Root-mounted entries have no paged/archive forms: `/about/x`
  parses as type `about` + slug `x` and 404s as today.

## 3. Collision rules (write-time, fail loud)

A root-mounted route slug must not equal, **per locale**:

| Colliding with | Why |
|---|---|
| any content-type slug | type-precedence would shadow the page silently |
| a reserved prefix (`v1`, `admin`, `extensions`, `theme-assets`, `_preview`) | the guard eats it before the resolver |
| an **active locale code** | the locale-wins shift eats it (`/en` can never be an entry) |
| `page`, `terms` | reserved grammar segments; banning at root keeps the vocabulary unambiguous |
| another root-mounted entry's slug (any root-mounted type, same locale) | root namespace is global — first-writer wins, second gets 409 |
| a **root redirect source** (any root-mounted type, same locale) | redirects are keyed per type+locale+source, so without this two root types could both own `/old`, and a new route could silently shadow a live redirect — the 1-segment resolver would have no deterministic honest choice |

**Self-reclaim exception:** assigning a slug that collides only with the SAME
entry's own redirect source (the one `RouteRepository::assign()` is about to
delete via `deleteBySource`) is allowed — an entry renaming back to a previous
slug must not 409 against its own history. Likewise, root redirect *creation*
(the automatic rename redirect) participates in the namespace: it may not
claim a source that collides with any root route or another root redirect.

Enforcement points (all return the conflict, never silently shadow):

1. **`assignRoute`** for an entry of a root-mounted type → 409
   (`ROOT_SLUG_TAKEN` with what it collides with) on any rule above, in
   addition to today's per-type check.
2. **Flipping `mount_at_root` ON** (PATCH) → validate ALL existing routes
   AND redirect sources of the type against the rules; any conflict → 409
   listing every colliding slug + locale. The flag never flips partially.
3. **Creating a content type** whose slug equals an existing root-mounted
   route slug → 422 (protects direction 2; renaming is impossible — type
   slugs are immutable).

Validation lives in one service (`RootMountGuard` or equivalent) shared by
all three call sites — one vocabulary, one error shape.

## 4. Redirects

- **Slug renames** on root-mounted entries keep the existing auto-redirect
  machinery (`entry_redirects` keyed type+locale+slug). The root 1-segment
  branch consults redirects **scoped to root-mounted types** after a route
  miss, so `/old-about` 301s to `/about` exactly like `/pages/old-about` did.
  The lookup is deterministic by construction: §3 puts root redirect sources
  in the same global namespace as root routes, so at most one root-mounted
  type can own a given source slug per locale.
- **Redirect targets** (`RouteResolver`'s `to`) render root-aware — a
  redirect landing on a root-mounted entry points at `/{slug}`.
- **Flag OFF after ON**: root URLs stop resolving (404); the prefixed
  canonical `/{type}/{slug}` takes back over. No synthetic redirects are
  created for the flip in v1 — documented cost, pre-launch posture.

## 5. Canonical rendering — one decision point

`PathRenderer` gains root variants (`renderRoot(locale, slug)` /
`renderRootDefaultLocale(slug)`). **The root grammar is FIXED** —
`/{locale?}/{slug}` — defined by the resolver, not derived from
`LEMMA_SEO_ROUTE_TEMPLATE`: the root variants honor only `public_url_base`
and the default-locale collapse, never the template (dropping `{type}` from
a custom template like `/content/{type}/{slug}` would print `/content/about`
while the parser accepts `/about` — parser and renderer must stay exact
inverses). `LEMMA_SEO_ROUTE_TEMPLATE` remains prefixed-route customization
only; root mounting is the per-type boolean and nothing else. If a
configurable URL grammar is ever needed, it is a separate URL-rules system
with explicit parser support — not a PathRenderer option.

A single **`CanonicalPathBuilder`** (wrapping PathRenderer + the type's
flag) becomes the one place that chooses prefixed vs root, and every
consumer goes through it:

- `EnginePublicRouteResolver::href` (canonical 301 targets, listing links)
- `EngineEntryTargetResolver` (nav/menu target paths)
- `CanonicalProjector` (SEO canonical + hreflang alternates)
- `EngineIndexableContentReader` (**search index hrefs** — it adapts the
  search `IndexableContentReader` contract; its sitemap enumeration shares
  the same row→href mapper via the delivery reader)
- `EngineContentDeliveryReader` (delivery `href` +
  `enumeratePublishedForSitemap` — the **sitemap** surface)
- `EngineLemmaContext`
- `RouteResolver` (redirect `to`)

All these call sites already hold the type row or slug; none re-query.
Search and sitemap are separate canonical surfaces: if only one is
root-collapsed, search results keep serving `/pages/about` while the site
canonicalizes `/about` — both get pinned tests.

## 6. Interactions

- **Homepage**: unchanged — still served at `/`. Its canonical alias becomes
  `/home` instead of `/pages/home` once the type is root-mounted.
- **Preview sessions & working-copy overlay**: work by construction — the
  root branch reuses the shared entry tail where both overlays live.
- **Render page cache**: keys are paths; flag flips purge `render:*` (§1).
  Cache tags (`lemma:entry:{uuid}`) are path-independent — publish
  invalidation is unaffected.
- **Admin type dropdowns** (nav Add page, homepage picker): unaffected —
  they filter on `public_delivery` only.

## 7. Out of scope (v1)

- Nested/hierarchical slugs (`/about/team`) — slugs stay single-segment.
- Per-entry custom full paths.
- Synthetic redirects when the flag flips OFF.
- Root-mounted *listings* (a root type never gets `/` as a listing — that is
  the homepage setting's job).

## 8. Load-bearing tests

**Grammar** (`RenderPipelineTest` / resolver integration):
- `/about` resolves the root-mounted entry; `/fr/a-propos` with prefix;
  default-locale collapse holds.
- Type precedence: a type slugged `about` beats a root entry `about`
  (listing/404 as today, never the entry).
- `/pages/about` 301s to `/about` when pages is root-mounted.
- Root slug rename: `/old` 301s to `/new` via the redirect table.
- Non-public root-mounted type → 404 at root.
- Flag OFF → root path 404s, prefixed path resolves again.
- Preview session draft overlay applies at a root path.

**Collisions** (route/type API integration):
- assignRoute 409: vs type slug, vs locale code, vs reserved prefix, vs
  `page`/`terms`, vs another root type's slug, vs another root type's
  redirect source (and: same slug in a *different* locale is fine).
- Self-reclaim: renaming an entry back to its own previous slug (its own
  redirect source) succeeds, and the stale redirect is gone.
- Flip-ON with a colliding existing route → 409 listing the conflicts, flag
  unchanged. Flip-ON with a colliding *redirect source* → same.
- Type creation with a slug matching a root-mounted route → 422.

**Canonical surfaces**: menu target path, **sitemap href**
(`enumeratePublishedForSitemap`), **search index href**
(`IndexableContentReader`), SEO canonical + hreflang, delivery href,
redirect target — all root-collapsed for the flagged type, all unchanged
for a prefixed type in the same run.

**Admin** (vitest): toggle on the type editor PATCHes `mount_at_root`;
409 conflict list surfaces in the error toast; create screen sends the flag.
