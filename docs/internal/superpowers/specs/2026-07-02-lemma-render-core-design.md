# lemma-render Core (`glueful/lemma-render`) — Design

**Date:** 2026-07-02
**Status:** Approved design, pre-implementation
**Parent:** `docs/V2_DESIGN.md` §5–§7 (sub-project 2 of the rendered-delivery sequence)

The first render milestone, verbatim from V2: **"Lemma can serve real HTML pages from
published content using a filesystem theme."** Uncached SSR; the render page cache is
sub-project 3. Everything V2 §6 defers stays deferred.

## 1. Boundary

Pack `glueful/lemma-render`, namespace `Glueful\Lemma\Render\`, capability **`lemma.render`**
(enabled by default; switchboard disable). Dependencies: `lemma-contracts` + framework +
**`twig/twig ^3`** (pack-owned dependency, the html-sanitizer precedent). Standard pack
invariants (no engine-namespace references, `composer boundaries`, triple gating where
applicable). Consumes contracts only: `PublicRouteResolver` (new, §3), `MenuReader`
(optional — `menu()` yields `[]` when unbound/disabled), `EntryTargetResolver` (`path()`),
lifecycle events (unused in core; the cache sub-project subscribes).

Config (`lemma_render.*`; NO `enabled` key — the switchboard is the gate):

| Key | Default | Meaning |
|---|---|---|
| `theme` (`RENDER_THEME`) | `default` | active theme name |
| `homepage_entry` (`RENDER_HOMEPAGE_ENTRY`) | `''` | entry uuid rendered at `/`; empty = standalone `index.twig` |
| `site_name` (`RENDER_SITE_NAME`) | `Lemma` | `site.name` in template context |
| `reserved_prefixes` | `['v1', 'admin', 'extensions', 'theme-assets']` | first-**path-segment** prefixes the catch-all must not render |
| `reserved_exact` | `['sitemap.xml', 'robots.txt']` | exact paths the catch-all must not render |

## 2. Route→render: catch-all into resolver (no framework change)

Verified against the router source: static routes always win; dynamic routes bucket by
literal first segment and the `*` bucket (param-first routes) is tried LAST; `where()`
constraints accept slash-spanning regexes; HEAD is mapped to GET in dispatch. So the pack
registers, only when the capability is enabled:

- `GET /` (static — homepage), and
- `GET /{path}` with `->where('path', '.+')` — a true lowest-priority catch-all.

**Reserved fallback (pinned):** the `*` bucket also sees unmatched requests under literal
prefixes (`/v1/nonexistent`), so the handler first checks the path against
`reserved_prefixes` (**path-segment** semantics: `v1` reserves `/v1` and `/v1/...`, NOT
`/v1abc`) and `reserved_exact` (exact-match paths — `sitemap.xml` does not reserve
`/sitemap-history`). Reserved hits return **byte-compatible standard JSON 404s** — same
shape and content type the router itself returns with render absent
(`ApiResponse::error('Not Found', 404)`); a regression test asserts equality against a
disabled-capability boot. Everything else goes to `PublicRouteResolver::resolvePath()`:

| Result kind | Response |
|---|---|
| `redirect` | real 30x with `Location` (status from the descriptor; normalization redirects are 301) |
| `gone` | `error.twig`, HTTP 410 |
| `content` | template pipeline (§4) |
| `not_found` | `404.twig`, HTTP 404 |

## 3. `PublicRouteResolver` — the required core seam

`Delivery\PublicRouteResolver` in `lemma-contracts`:

```php
interface PublicRouteResolver
{
    /**
     * @return array{kind: 'content'|'redirect'|'gone'|'not_found', locale: ?string,
     *   type: ?string, content: ?array, redirect: ?array{location: string, status: int}}
     *   `type` = the content-type slug (content kind only) — the template hierarchy
     *   (entry/{type-slug}.twig) selects on it; neither the delivery envelope nor the
     *   seo object carries the slug, and the resolver knows it during resolution.
     */
    public function resolvePath(string $path): array;

    /** Same result shape, for a known entry (homepage, previews later). */
    public function resolveEntry(string $entryUuid, ?string $locale = null): array;
}
```

**`content` contract (pinned):** the public delivery shape for ONE published entry —
`seo` object included — already visibility-filtered and route-resolved by core; byte-
identical to what the headless delivery API serves. The pack treats it as **read-only
template context**: no mutation, no re-normalization.

Core implements it (`App\Content\Delivery\EnginePublicRouteResolver`) wrapping the
existing `RouteResolver`/`PathRenderer`/redirect/canonical logic. The one new core piece
is the **path parser** — the inverse of `PathRenderer`'s configured route template.

**Normalization rules (pinned):**

- Ignore the query string entirely; operate on the decoded path only.
- Split into segments FIRST, then `rawurldecode` each segment — never decode the whole
  path blindly.
- **Canonical redirects are core's decision AND come first:** the normalization check is
  the FIRST step of `resolvePath()`, before any parsing or content lookup — if the raw
  path differs from its normalized form (trailing slash, duplicate `//`), return
  `redirect { location: normalizedPath, status: 301 }` immediately. `/blog//hello` can
  therefore never reach the route parser or fall through oddly; content lookup only ever
  sees canonical paths. The pack never normalizes.
- Parse against the route template: 3 segments where the first is an ACTIVE locale code →
  locale variant (`/{locale}/{type}/{slug}`); 2 segments → default-locale variant
  (`/{type}/{slug}` — `/blog/hello` is always type `blog` in the default locale, never
  locale `blog`); anything else (1 segment, 4+, or a locale code with the wrong arity) →
  `not_found`. Active locale codes come from the i18n locale registry.
- Within a parse, delegate to `RouteResolver` with the same locale chain the delivery API
  uses; redirects and gone flow through as their own kinds.
- **Visibility (pinned):** render is an anonymous surface — the resolver enforces the
  SAME public-only rule as anonymous delivery (non-public-delivery content types resolve
  `not_found` even when a route exists). A route existing is never sufficient to render.

## 4. Themes and the render pipeline

**Theme resolution (pinned fallback ladder):**

1. Active app theme `themes/{name}/` missing entirely → fall back to the pack-embedded
   default theme (log a warning).
2. Active app theme present but invalid `theme.json` (unparseable, missing name) →
   **500 config error** at request time (operator error must be loud, not silently themed).
3. Pack default theme missing/invalid (a broken install) → **500 hard failure**.
4. Missing individual template in the app theme → per-template fallback to the pack
   default (the Twig loader carries BOTH paths: active theme first, pack default second).
5. Missing in both → render `error.twig`; if that itself fails → plain-text 500. Never a
   render loop.

A theme is `themes/{name}/` with `theme.json` (`name`, `version`, `menus`: declared region
slugs), `templates/`, `assets/`. Twig environment: autoescape `html`, compile cache
`storage/cache/twig/{theme}`, `auto_reload` on. Template hierarchy (V2 §3):
`entry/{type-slug}.twig` → `entry.twig`; `index.twig` (homepage); `404.twig`;
`error.twig`; `layout.twig` as the shared base.

**Context:** `entry` (the resolver's delivery-shaped row), `site`
(`name`, `locale`, `locales`), and functions:

- `menu(slug)` → `MenuReader::menu(slug, currentLocale)` ?? `[]` — **no hard dependency
  on lemma-navigation** (unbound reader or disabled capability both yield `[]`).
- `path(entryUuid)` → `EntryTargetResolver` path (null unless published — templates can
  never emit a dead link).
- `asset(rel)` → **path-safe** (pinned): reject absolute URLs, `..`, leading `/`, and
  backslashes (422-of-the-template-world: a Twig runtime error naming the offending
  value); emit `/theme-assets/{normalized-rel}`.

**Asset serving:** `serveFrontend('/theme-assets', <activeTheme>/assets)` registered at
boot when enabled — it exposes ONLY the active theme's `assets/` directory, never
`templates/` or `theme.json`. **The active theme is resolved at BOOT (pinned v1
limitation):** changing `lemma_render.theme` requires an app restart / extension-cache
rebuild to take effect. If a later sub-project adds runtime/admin theme switching, the
static mount must become a dynamic asset controller — noted so that follow-up knows the
cost.

**Homepage (pinned):** `GET /` always renders **`index.twig`**. With `homepage_entry`
set, `resolveEntry()` supplies `entry` in the context; when it resolves to anything but
`content` (missing/unpublished/routeless/deleted entry), that is a **500 config error** —
operator error, deliberately loud, never a themed 404. The error is raised as a dedicated
exception whose message names the key and the resolution outcome (e.g.
*"lemma_render.homepage_entry resolves to an unpublished entry"*) — **always written to
the log; surfaced in the response body only under the framework's debug mode**, so
production responses stay generic and leak nothing. Empty `homepage_entry` renders
`index.twig` with no `entry`.

Responses are raw HTML `Symfony\Component\HttpFoundation\Response` (the SitemapController
precedent), not the JSON envelope.

## 5. Testing

Pack-convention integration tests (`tests/Integration/Render/`):

- Path parser: locale + default-locale variants, per-segment decoding, query-string
  ignored, trailing-slash and duplicate-slash → 301 to normalized, locale-code arity
  edge (`/en/blog` → not_found), 1/4+ segments → not_found.
- Resolver: published → content with the delivery shape (assert `seo` present and equal
  to the delivery API's payload for the same entry), redirect status passthrough, gone →
  410, `resolveEntry` for homepage, non-public-delivery type with a live route →
  `not_found` (anonymous visibility rule).
- Pipeline: published entry → 200 HTML containing rendered field values; type template
  beats generic `entry.twig`; app-theme template missing → default-theme fallback used;
  themed 404; reserved prefix and reserved exact → byte-compatible JSON 404 (regression
  vs disabled boot); homepage with and without `homepage_entry`; bad `homepage_entry` →
  500; `menu()` renders real lemma-navigation data AND `[]` with navigation disabled;
  `asset()` rejects `..`/absolute/leading-slash; HEAD returns GET headers.
- Theme ladder: missing app theme → default + warning; invalid `theme.json` → 500.
- Removability: capability disabled → catch-all absent (unmatched paths behave exactly
  as pre-render: JSON 404), routes absent, boundary sweep; `composer boundaries` at
  **9 packages**.

## 6. Out of scope (deferred per V2 §6)

Render page caching (sub-project 3 — `MenuUpdated`/lifecycle purge seams already exist),
listing/archive pages, taxonomy term pages, DB-edited templates, page/block builder,
preview-through-theme, admin theme/homepage switching UI (config-only in v1; the settings
contract arrives with the caching/settings follow-up).
