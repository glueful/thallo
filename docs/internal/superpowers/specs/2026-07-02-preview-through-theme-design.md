# Preview-Through-Theme (+ `facets()` in Twig, OpenAPI HTML-route exclusion) — Design

**Date:** 2026-07-02
**Status:** Approved design, pre-implementation
**Parent:** `docs/V2_DESIGN.md` §6 follow-up tracks ("preview-through-theme"); closes the
cache-bypass deferral in
`docs/superpowers/specs/2026-07-02-lemma-render-caching-design.md` §8 ("the bypass
concept returns with preview-through-theme's token mechanism") and the `facets()`
deferral in `docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md`
§6.

Editors preview drafts rendered through the real Twig theme via the EXISTING preview
tokens (`app/Content/Preview` — HMAC-signed, bound to `{entry, locale, ?version}`,
TTL'd, fail-closed reader). Plus two small riders in the same subsystem: the deferred
`facets()` Twig function with its tagging story, and excluding the render pack's HTML
routes from OpenAPI generation.

## 1. The preview route (structural cache bypass)

**`GET /_preview/{token}`**, registered by the render pack inside the `lemma.render`
capability gate, **WITHOUT the `RenderPageCache` middleware** — bypass is structural:
a preview response can never enter or read the shared page cache; there is no bypass
logic to get wrong. The static first segment beats the `*`-bucket catch-all
automatically, so no reserved-path changes. Mirrors the JSON door
(`GET /v1/preview/{token}`), including its authorization semantics: **the signed token
IS the authorization** (minted by an authenticated editor; `public_delivery` gating
does not apply — same as the JSON door).

## 2. Resolver seam (pinned: a content render, not a new kind)

`PublicRouteResolver` gains:

```php
public function resolvePreview(string $token): array; // same result shape
```

**PINNED:** success returns **`kind: 'content'`** with a new result-shape flag
**`preview: true`** — NOT a new kind. Preview is a content render with different
headers and context; the render controller's template hierarchy stays a single path.
The result-shape gains `preview: bool` (false on every non-preview return; the
contract docblock updates — breaking, monorepo precedent).

Core implements it over the fail-closed `PreviewReader`:

- Verified token → the draft (or pinned version) shaped like an entry item —
  references expanded against the **published** spine (referenced entries appear as the
  public sees them), `type` slug included for the hierarchy, `locale` from the token.
- **No `seo` object on preview content** (a draft may be routeless; canonical
  projection doesn't apply; the page is noindex anyway). Documented for theme authors;
  the default theme doesn't read `entry.seo`.
- **Every failure is `not_found`** — malformed, bad signature, expired
  (`PreviewTokenException`), missing draft/version (`PreviewNotFoundException`) —
  rendered as the themed 404. The failure reason is logged (info) for debuggability.
  Tokens are short-lived (`lemma.preview.ttl_seconds`, default 600); the SPA re-mints.

## 3. Rendering + headers

The controller's preview arm renders the entry hierarchy (`entry/{type-slug}.twig` →
`entry.twig`) with `entry` context plus **`preview: true`**; the default theme's layout
shows a small banner when `preview` is set (escape-by-default like the rest). Response
headers: **`Cache-Control: no-store`** and **`X-Robots-Tag: noindex`**. The fixed-key
`RenderErrorCache` is NOT consulted for preview failures (a preview 404 renders fresh —
it must never serve or fill the shared fixed body with preview-flagged context).

## 4. SPA action (minimal) + `theme_url`

`PreviewController::mint`'s response gains **`theme_url`** — pinned disabled behavior:

- `"/_preview/{token}"` when the `lemma.render` capability is enabled and the route is
  available;
- **`null` when `lemma.render` is disabled or the route is unavailable** — the normal
  JSON preview URL is unaffected either way. The SERVER decides; the SPA never builds
  theme URLs and never consults capability state for this (no SPA capability drift).

The entry editor's existing preview affordance gains a "Preview in theme" action with
**pinned executable behavior** (the SPA cannot know `theme_url` before minting, and it
must not consult capability state): the action is ALWAYS shown; clicking it mints
(the existing mutation), then opens `theme_url` in a new tab when non-null, or shows
the existing notify/warning pattern ("theme preview unavailable — rendered delivery is
disabled") when `theme_url === null`. The server stays the single authority.

## 5. `facets()` in Twig with the tag collector

New contracts seam — **the result carries its own cache tags** (the render pack cannot
derive `termTypeSlug` from a bare counts list without depending on core schema
internals, and it must distinguish "valid facet, zero counts" from "gate failed"):

```php
namespace Glueful\Lemma\Contracts\Delivery;
interface FacetCountsReader
{
    /**
     * @return array{items: list<array{uuid: string, slug: ?string, count: int}>,
     *               cache_tags: list<string>}
     */
    public function counts(string $typeSlug, string $field, string $locale, int $limit = 100): array;
}
```

Core implements it over `PublishedReferenceRepository::facetCounts` with the SAME gates
as the facets endpoint (source type + target type anonymously visible, field a
`filterable: true` reference; limit clamped 1..500) and computes BOTH keys:

- **gate failure** → `{items: [], cache_tags: []}` — never throws into a template;
- **valid facet, even with zero counts** →
  `{items: […], cache_tags: ['lemma:type:{sourceSlug}', 'lemma:type:{termTypeSlug}']}`
  — a valid EMPTY facet still tags the page, so it purges when the first matching
  entry publishes.

Render adds the `facets(type, field, limit = 100)` Twig function to
`RenderContextExtension`: it returns `items` to the template and records `cache_tags`
in the **tag collector**;
- **PINNED scoping:** the collector is scoped to the render request, NOT a singleton
  bag that survives between renders — the extension instance is process-shared, so the
  controller **resets the collector before every render and drains it in a
  finally-style path**, ensuring a Twig exception cannot leak collected tags into the
  next response;
- after a successful render, the controller merges drained tags into the response's
  `Cache-Tag` (entry, listing, archive, homepage alike — `RenderPageCache` stores
  whatever `Cache-Tag` it sees, so facet sidebars purge event-driven with zero new
  invalidation code). Preview renders drain and DISCARD (the response is `no-store`).

## 6. OpenAPI HTML-route exclusion

The regenerated `openapi.json` currently documents the render catch-all (`GET /`,
`GET /{path}`) as API operations. Exclude the render pack's HTML routes (`/`,
`/{path}`, `/_preview/{token}`, `/theme-assets/*`) from OpenAPI generation via the
generator's existing exclusion mechanism (config path-exclusion or route/controller
attribute — the plan verifies which exists and uses it; if none exists, propose the
smallest framework/config addition rather than hand-editing the JSON), then regenerate
`openapi.json` deliberately.

## 7. Testing

- Kernel preview flow: mint via `PreviewMinter` → `GET /_preview/{token}` → 200 draft
  HTML with the banner, `Cache-Control: no-store`, `X-Robots-Tag: noindex`, and **no
  `render:*` cache key created**; a second request re-renders (never cached).
- Failure paths: garbage token, expired token (mint with a past expiry via
  `PreviewToken::mint` directly), draft-deleted-after-mint → themed 404; the JSON door
  is untouched.
- Version-pinned token renders the PINNED fields, not the newer draft.
- Non-public type's draft previews fine (token-is-authorization semantics).
- `theme_url`: present in the mint response when render is enabled; `null` under a
  config-override boot with the capability disabled (controller-direct where the route
  latch bites).
- `facets()`: counts flow through with the endpoint's gates (gate failure →
  `{items: [], cache_tags: []}`); a VALID facet with zero counts still returns both
  type tags (and the page purges when the first matching entry publishes — tested);
  collector resets per render and survives-nothing on a Twig exception (render an
  intentionally-failing template; next render's tags are clean); a page rendering
  `facets()` carries `lemma:type:{termType}` in `Cache-Tag` and purges through the
  REAL listener when a source-type entry publishes.
- OpenAPI: regenerated spec contains the taxonomy endpoints but NO `GET /` /
  `GET /{path}` / `_preview` operations.

## 8. Out of scope

Full-site preview navigation (links on a preview page lead to published pages),
preview of listing/archive pages, per-preview theme switching, DB-edited templates,
preview banners beyond the default theme's minimal one.
