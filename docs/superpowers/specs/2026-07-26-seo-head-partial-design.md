# SEO Head Partial — Design

**Date:** 2026-07-26
**Status:** Draft for review (theme-runtime spec §11.2 follow-up)
**Packages:** `packages/thallo-contracts` (one new contract), Thallo app (engine provider +
binding + cache purge), `packages/thallo-render` (head partial + policy), `packages/thallo-seo`
(admin save purge only)

## §0 Context

Rendered pages ship **zero** head metadata today: no description, canonical, hreflang,
Open Graph, or Twitter tags — the last "starter-level" finding from the external theme
review, deferred by the theme-runtime spec (§11.2) with the rule that the fix must go
through thallo-seo's own machinery ("no SEO storage duplication").

Everything needed already exists; nothing new is stored:

- `Thallo\Seo\Meta\SeoMetaResolver` computes title/description/OG/twitter-card/robots per
  entry with the full precedence chain (per-entry `seo_meta` override → per-type fallback
  field → site default, `title_template` applied to field-derived titles).
- `App\Content\Seo\CanonicalProjector` computes the canonical path, per-locale hreflang
  alternates, and x-default — per-pin mount-aware (the same object the headless delivery
  JSON already exposes as `seo`).
- `Thallo\Contracts\Delivery\CanonicalPublicOriginResolver` is the pinned "ONE
  trusted-origin algorithm" (host-spoof-safe, tenant-aware) for composing absolute URLs.

What is missing is (a) a seam the render pack may legally consume (pack boundary: render
must never import `Thallo\Seo\*` or `App\*`), (b) the head partial itself, and (c) cache
correctness: `AdminSeoMetaController` dispatches **nothing** on save today, so an SEO
override edit would leave stale cached heads once the layout consumes this data.

## §1 Goals and non-goals

**Goals**

- Entry pages (including the entry-backed homepage) render a complete head: description,
  canonical, hreflang alternates + x-default, Open Graph, conditional twitter/robots —
  composed exclusively from existing thallo-seo data.
- Preview responses are explicitly non-indexable and never leak canonical/OG for drafts.
- Every SEO override update (including an empty-values clear) purges the affected
  entry's cached rendered pages, locally and at the edge.

**Non-goals (follow-ups, §7)**

- Canonicals/pagination-rel for listing, term, and archive pages (their title blocks stay
  as they are).
- Shop pages — the storefront already emits its own canonical + JSON-LD via
  `ShopUrlGenerator`; untouched.
- Entry JSON-LD, sitemap changes, selective loading. No new SEO storage of any kind.

## §2 The seam: `SeoHeadResolver`

New contract in `packages/thallo-contracts/src/Delivery/SeoHeadResolver.php`:

```php
interface SeoHeadResolver
{
    /**
     * The composed head data for one published entry variant, or null when the entry
     * is not published in this locale (callers then emit nothing).
     *
     * Takes ONLY the identity every render site reliably holds — entry uuid + locale
     * (the same pair the page cache tags with). The engine side derives type and slug
     * itself from its own repositories; requiring them here would push lookups render
     * cannot always perform (homepage and root-mounted renders don't carry a slug).
     *
     * @return array{
     *   title: string,                                       // the FINAL effective title (§2 precedence)
     *   description: ?string,
     *   canonical: ?string,                                  // absolute, or null (origin unknown)
     *   alternates: list<array{locale: string, href: string}>, // absolute hrefs
     *   x_default: ?string,
     *   og: array{title: string, description: ?string, image: ?string, url: ?string, type: string},
     *   twitter_card: ?string,                               // only when explicitly overridden
     *   robots: string,                                      // 'index' | override value
     * }|null
     */
    public function headFor(string $entryUuid, string $locale): ?array;
}
```

**Implementation lives in the app** — `App\Content\Seo\EngineSeoHeadProvider` — following
the established Engine* pattern (the app composes pack + app services; render consumes the
contract only). It composes:

- `SeoMetaResolver::resolve(...)` for title/description/og/twitter/robots;
- `CanonicalProjector::project(...)` for the canonical path + alternates + x-default;
- `CanonicalPublicOriginResolver::currentOrigin(...)` to absolutize every URL-bearing
  value (canonical, alternate hrefs, og:url, and a relative og:image).

**Origin decision (pinned):** absolute URLs come from `CanonicalPublicOriginResolver` —
NOT from `thallo.seo.public_url_base` (the sitemap's older convention). The origin
resolver is the pinned single authority and is tenant-aware; the sitemap's migration to it
is a named follow-up, not this spec. If origin resolution fails or returns an empty/blank
origin, every URL-bearing tag (canonical, hreflang, og:url) is **omitted** — a relative
canonical is worse than none. Non-URL tags (description, og:title, robots) still render.
The un-overridden `http://localhost` config default counts as unconfigured — it is
treated exactly like a blank origin (observed emitting localhost canonicals on a real
install); an EXPLICITLY configured localhost base (e.g. with a port) is a deliberate
choice and keeps its URLs. Projector hrefs that arrive ALREADY absolute (a deployment with
`thallo.seo.public_url_base` set) pass through untouched — double-prefixing would
corrupt them; unifying the two base authorities is follow-up §7.3.

**Effective-title precedence (pinned):** override title → type-mapped `title_field`
(template applied) → **the entry's `title` field** (template applied — the theme
convention every entry template already displays) → site name. The mechanism is a
one-line change IN THE PACK RESOLVER itself — `SeoMetaResolver` reads
`$map['title_field'] ?? 'title'` instead of `?? null`, so an unmapped type derives from
the conventional `title` field before falling to the bare site name. This deliberately
fixes the pack's OWN surface too: the headless `/v1/seo/meta` endpoint has the same
unmapped-type regression today and gets the identical correction (a type that maps
`title_field` to something else is unaffected; a type whose entries have no `title`
field falls to the site name exactly as before). The provider does no augmentation —
`title` on the wire is always the final effective value. `og.title` preserves the
resolver's existing override semantics: an explicit `og_title` override wins (an editor
may deliberately title the social card differently), otherwise it equals the effective
title.

**Homepage canonical authority (pinned):** the configured homepage entry is reachable at
BOTH `/` and its own entry path — two live URLs for one document. `/` is the canonical.
The provider learns the homepage identity from the SAME authority the render pipeline
uses — the existing `Thallo\Contracts\Delivery\HomepageEntryProvider` contract (engine
implementation already reads the settings-row-then-config chain) — never from its own
config read. Whenever the resolved entry IS that provider's entry, the head provider
returns
`canonical = origin + '/'` and `og.url` to match, empties `alternates`/`x_default` for
v1 (localized homepage roots are a named follow-up), and sets `og.type = 'website'`.
This applies at BOTH render sites — the homepage render and the entry's own path — so
the entry path correctly declares `/` as its canonical instead of competing with it.
All other entries get `og.type = 'article'` and the `CanonicalProjector` output.

Binding: `ThalloServiceProvider::services()` binds
`SeoHeadResolver::class => EngineSeoHeadProvider` (factory, shared). No capability gate —
thallo-seo is an always-loaded module with no capability, and the contract is always
present; render still soft-binds (§3) so a host without the app wiring degrades cleanly.

## §3 Render integration

- `RenderController` holds `entryUuid` + `locale` at every entry render site (the same
  pair it tags the page cache with). For ENTRY pages (and the entry-backed homepage) it
  resolves the soft-bound `SeoHeadResolver` (`$container->has(...)` — absent ⇒ null) and
  threads the resulting array into the render context as the template variable **`seo`**
  (null when absent, unpublished, or a non-entry page).
- A new Twig function **`seo_head()`** on `RenderContextExtension`, registered with
  `['is_safe' => ['html'], 'needs_context' => true]` — it reads the **`seo` context
  variable** (ONE source of truth; no parallel per-render state channel) plus the
  existing preview state, and emits the tag block. Added to `TemplatePolicy::FUNCTIONS`
  with the mandatory `CACHE_VERSION` bump (12 → 13). Every value is escaped
  (`htmlspecialchars` with ENT_QUOTES); URL attributes additionally pass the same
  safe-URL discipline the templates' `safe_url` filter enforces.
- `layout.twig` head gains `{{ seo_head() }}` directly after the `<title>` element, and
  the title line becomes `<title>{% block title %}{{ seo.title|default(site.name) }}{%
  endblock %}</title>`. `entry.twig`'s title block becomes
  `{{ seo.title|default(entry.fields.title|default(site.name)) }}` — with the §2
  effective-title guarantee, `seo.title` is always the final value whenever `seo`
  exists; the chained fallbacks fire only when the resolver is absent entirely (soft
  binding missing) and preserve today's behavior byte-for-byte in that case. Every other
  template's title block is unchanged.

**Emitted tags** (each line only when its value exists):

```html
<meta name="description" content="…">
<link rel="canonical" href="…">
<link rel="alternate" hreflang="{locale}" href="…">   <!-- one per alternate -->
<link rel="alternate" hreflang="x-default" href="…">
<meta property="og:type" content="article|website">
<meta property="og:title" content="…">
<meta property="og:description" content="…">
<meta property="og:image" content="…">
<meta property="og:url" content="…">
<meta property="og:site_name" content="{site.name}">
<meta name="twitter:card" content="…">                <!-- ONLY when explicitly overridden -->
<meta name="robots" content="…">                      <!-- ONLY when not the plain default 'index' -->
```

Pinned minimalism: no twitter:* fabrication (crawlers fall back to og:*), and no
`robots: index` noise — the tag renders only for a real directive (`noindex`, etc.).

## §4 Preview and non-entry pages

- **Preview** (any previewed page, entry or not): `seo_head()` emits exactly
  `<meta name="robots" content="noindex, nofollow">` and NOTHING else — no canonical, no
  hreflang, no OG. Draft titles/descriptions must never be socially scrapeable, and a
  preview URL must never declare itself canonical. The existing `is_preview()` per-render
  state is the gate.
- **Non-entry pages** (listing, terms, archive, 404, error, non-entry home): `seo` is
  null and `seo_head()` emits nothing (empty string) on live renders; their `<title>`
  blocks behave exactly as today via the `default()` fallbacks.

## §5 Cache correctness

Rendered entry pages are cached tagged `thallo:entry:{uuid}` (RenderPageCache) AND may
sit on a CDN edge purged by surrogate tags (`PurgeCdnListener`). A direct internal-cache
purge alone would leave the edge serving the stale head — so the invalidation goes
through the SAME listener pipeline content events use:

- New contracts event `Thallo\Contracts\Seo\SeoMetaChanged` (`entryUuid`, `locale`) in
  `packages/thallo-contracts` (packs may not import App events; contracts is the shared
  event home, the `MenuUpdated` precedent).
- `AdminSeoMetaController` (thallo-seo) dispatches it after every successful `update` —
  the controller's ONLY mutation surface (there is no delete endpoint; an override is
  cleared by upserting empty values, which is still an `update` and still dispatches).
- The existing content-event listeners read content-event shapes and would silently
  ignore this event — so the app gains ONE dedicated listener,
  `App\Content\Pipeline\Listeners\SeoMetaChangedListener`, registered for it in
  `ThalloServiceProvider::registerEventListeners()`. It performs both halves itself via
  the same services the content listeners use: drops the internal cache tag
  `thallo:entry:{entryUuid}`, and purges the same surrogate tag through
  `EdgeCacheInterface` with `PurgeCdnListener`'s exact disabled-skip discipline
  (NullEdgeCache reports disabled → clean no-op). Type-level tags are NOT purged — a
  meta edit changes one entry's pages only.

The sitemap cache is NOT touched (meta edits don't change sitemap URLs;
`SitemapCacheInvalidator` already handles content lifecycle).

Page-cache keying needs no change: the head varies only by entry + locale + tenant +
theme, all already in the key.

## §6 Testing

- **SeoMetaResolver** (pack): the one-line default gains its own cases — unmapped type
  derives from the `title` field (template applied); a type mapping `title_field`
  elsewhere is unaffected; entries with no `title` field still fall to the site name.
  The `/v1/seo/meta` endpoint test asserts the corrected title for an unmapped type.
- **EngineSeoHeadProvider** (integration, app): the FULL effective-title precedence —
  override → mapped-field → entry-title → site-name each proven on the wire shape;
  absolute canonical/alternates/og:url composed
  from the origin resolver; blank origin ⇒ URL-bearing keys null/empty but
  description/title intact; relative `default_og_image` absolutized; unpublished
  variant ⇒ null; homepage entry ⇒ `canonical = origin + '/'` with empty alternates and
  `og.type = website` — asserted for BOTH the homepage render identity and the entry's
  own path.
- **Render integration**: entry page HTML contains description/canonical/hreflang/og
  (values escaped — a title with `"` and `<` renders entity-escaped); title precedence
  (override title beats field title beats site name); non-entry pages contain NO
  seo-emitted tags; preview response contains the noindex robots tag and NO
  canonical/og; a page for an entry with no seo_meta row still gets the field-fallback
  head.
- **Policy**: `seo_head` allowlisted + `CACHE_VERSION === 13` pinned.
- **Cache purge**: every successful `update` (including an empty-values clear)
  dispatches `SeoMetaChanged`; `SeoMetaChangedListener` drops the internal
  `thallo:entry:{uuid}` tag (cached rendered page re-renders with the new description)
  AND purges the same surrogate tag through the edge cache (asserted with the recording
  edge-cache substitute; the Null edge cache self-skips cleanly in the lean default,
  per the existing CapabilityGatingTest pattern). No type-level tags are purged.
- **Pack boundary**: the existing no-App-references check for render stays green (render
  consumes only `Thallo\Contracts\Delivery\SeoHeadResolver`).
- Full suite + phpcs gates.

## §7 Out of scope → follow-ups

1. Listing/term/archive canonicals + `rel=prev/next` pagination semantics.
2. Entry JSON-LD (Article/BlogPosting structured data).
3. Sitemap origin migration from `thallo.seo.public_url_base` to
   `CanonicalPublicOriginResolver` (one authority everywhere).
4. Social-image derivation from entry media when no override/default exists.
5. Localized homepage roots: per-locale `/` hreflang alternates for the homepage entry
   (v1 empties the homepage's alternates; see §2's homepage canonical pin).
