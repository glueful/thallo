# Storefront Performance & Listing Polish — Design

**Date:** 2026-07-27
**Status:** Draft for review (theme-runtime spec §11.1 + §11.5 follow-up)
**Packages:** `packages/thallo-render` (theme templates, site.css, RenderContextExtension),
`packages/thallo-contracts` (one optional delivery seam), app (`app/Content/Media` binding),
`tests/`

## §0 Context

The theme-runtime track put ALL rendered-page behavior on `ThalloRuntime` (5 theme modules +
6 shop modules). What remains from its §11 list is the performance/polish follow-up (§11.1)
and the archive/listing template improvements (§11.5, folded in here; theme presets stay a
separate future conversation).

Current state, from recon: the runtime is ONE fingerprinted immutable file (~30KB raw,
~9KB gzipped) loaded from every page's layout head; images render as bare `<img src>` with no
dimensions, priority, laziness, or `srcset` (though `GET /blobs/{uuid}` already accepts
`?width=` — served as a real resize only when an image processor is installed); the default
theme is a pure system-font stack with no webfonts; and `archive.twig`/`listing.twig`/
`terms.twig` are bare `<ul>` title lists while the starter post type already carries
`excerpt` and `cover` fields.

## §1 Goals and non-goals

**Goals**

- Pin the single-runtime asset posture with receipts and a CI size budget.
- Real responsive images where an image processor exists; honest plain images where not.
- At most one priority (LCP-candidate) image per page — the first eligible image claims
  it; pages with no eligible image correctly have none. Everything else lazy.
- Restrained below-the-fold rendering relief and a subtle cross-document crossfade.
- Editorial listing rows for archives/listings/terms (the §11.5 half).

**Non-goals**

- Per-block module manifests / runtime splitting (§2 records the rejection).
- Speculation-rules prefetch, element-level view-transition morphs (future opt-in preset).
- Commerce template adoption of the image discipline (pack-owned; can follow later).
- Homepage listing plumbing — `index.twig` renders an entry or a welcome card, has no
  listing context, and stays OUT of the listing-template scope.
- Any image-processing work itself (`glueful/media` owns that).

## §2 Asset posture: one runtime file, with receipts

**Decision: the parent spec's "per-block module manifests" idea is REJECTED.** Recon
receipts, recorded so the idea is not re-litigated casually:

- Every page's header chrome contains `navigation` and `color-mode` — every page needs the
  runtime regardless of body content, so per-page selection can never skip the file.
- ~9KB gzipped, fingerprinted, immutable, cached once site-wide beats per-page fragments:
  splitting adds requests and cache fragmentation for zero realistic payload savings.
- Selector-scanning modules already no-op when their components are absent.
- Genuinely optional behavior is already package-owned and per-block: shop.js/shop.css load
  only where shop blocks render — that pattern, not a central manifest, is the model.

**Pins:**

- One fingerprinted, immutable runtime asset; delivery unchanged.
- CI size budget: a PHPUnit test asserts `strlen(gzencode($runtimeSource, 9)) <= 12_288`
  (12KB; current ~9KB leaves visible headroom). The assertion message explains the rule:
  growth is fine until optional modules materially dominate the payload — reconsider
  splitting THEN, not at an arbitrary byte count.
- Future heavy features (maps, video players) ship as independently-owned assets first — a
  generalized per-block asset-contribution seam is introduced only after a SECOND real
  consumer proves the abstraction (shop.js is the first).
- Fonts: the default theme's system stack is the pin — zero font-loading cost, nothing to
  preload. A theme that adds webfonts owns `font-display: swap` and preloading; recorded
  here as guidance, no code.

## §3 Responsive media: an optional variant seam, never a pretend one

`Thallo\Contracts\Delivery\MediaUrlResolver` guarantees only a public BASE URL. Emitting
`?width=` candidates unconditionally would, on installs without an image processor, serve
the original bytes under multiple cache keys — cache pollution with zero payload win. So
variant URLs exist only behind an explicit capability seam:

- **New optional contract** `Thallo\Contracts\Delivery\MediaVariantUrlResolver`, batch by
  design so ONE blob/access/MIME lookup produces every candidate (per-width calls plus a
  separate `media()` call would be N+1 reads per image, contradicting the whole goal):

  ```php
  /** @param list<int> $widths  @return ?array{src: string, srcset: ?string} */
  public function variants(string $uuid, array $widths): ?array;
  ```

  One lookup, three pinned outcomes:
  - Valid image with surviving candidates: `{src, srcset: string}`.
  - Valid image with NO surviving candidates (every width fell to the clamp):
    `{src, srcset: null}` — the base URL is still authoritative; only the variant list is
    empty. This case is representable ONLY because `srcset` is nullable in the contract —
    a null RESULT must remain unambiguous.
  - Not anonymously servable (same fail-closed set as `MediaUrlResolver::url()`), missing,
    or not `image/*` (variants of a PDF are nonsense): NULL.

  The contract remains OPTIONAL at the generic `thallo-render` boundary. Implementations
  MUST never emit candidate URLs when real resizing is unavailable; silently emitting
  multiple `?width=` cache keys that all serve the original is a contract violation.
- **The Thallo app always binds its MIME-aware implementation.** Compiled service
  registration cannot depend on runtime configuration, and the implementation remains
  useful while resizing is unavailable because it can still distinguish a valid image
  (`{src, srcset: null}`) from missing/private/deleted/non-image media (`null`). Its
  candidate-generation capability mirrors `UploadController::serveBlob` exactly: a media
  processor is bound AND `uploads.image_processing.enabled` is true. Candidate widths
  MUST stay within `uploads.image_processing.max_width`: a descriptor the server would
  clamp down lies to the browser's selection algorithm, so out-of-clamp widths are
  dropped. An incapable implementation or clamp exhaustion returns
  `{src, srcset: null}`; only a capable implementation may return `?width=` candidates.
- **Twig surface** (`RenderContextExtension`): ONE unified helper is the template entry
  point for image slots — a bare "variants null → fall back to `media()`" pattern would
  conflate capability absence with invalid media: a bound resolver rejecting a non-image
  blob would fall back to `media()`'s public URL and emit `<img src="…pdf">` (asset fields
  do not enforce image MIME).

  `media_image(uuid, widths): ?{src: string, srcset: ?string}`
  - Variant resolver ABSENT: `{src: media(uuid), srcset: null}` — today's behavior,
    byte-identical plain `<img>` (no MIME knowledge without the resolver).
  - Resolver present, valid image: `{src, srcset}` from the one batch lookup; if every
    candidate width fell to the clamp, `{src, srcset: null}`.
  - Missing, private, deleted, or non-image blob (resolver present): NULL — the template
    omits the image element entirely.

  Templates resolve once into a var: null → no element; otherwise emit `src`, plus
  `srcset`/`sizes` only when `srcset` is non-null.
- `sizes` values are template-owned literals matched to each slot's CSS (e.g. listing thumb
  `(max-width: 48rem) 96px, 160px`).

## §4 Priority image discipline: at most one per page, first eligible wins

Unconditionally marking heroes `fetchpriority="high"` lets a second or below-fold hero
compete with the real LCP image — and a hero-only claim leaves a page that OPENS with an
image block, blog cover, or listing thumbnail marking its actual LCP candidate lazy. Pin:
**at most one priority image per rendered page, claimed by the first ELIGIBLE image in
render order**:

- `RenderContextExtension` gains a per-render claim — `claim_priority_image(): bool`
  returns true exactly once per render.
- **Eligible claimants** are the §5 content slots: hero media, the image block,
  blog_posts covers, and listing thumbnails. **Brand imagery never claims** — the site
  logo renders FIRST on every page and would otherwise always steal the slot. Its loading
  is POSITIONAL (the `logo` block is palette-legal in header AND footer, and available in
  bodies): the layout's hardcoded `site_logo()` imgs are `loading="eager"` with NO
  `fetchpriority`; a `logo` block rendering with `region_slug == 'header'` is likewise
  eager; a `logo` block in the body or footer is `loading="lazy" decoding="async"`. The
  logos block never claims either.
- **Region-rendered blocks never claim.** The footer palette DOES permit the image block
  (and regions render via the same templates), so the rule is positional, not palette-based:
  the claim is only available while rendering body content (`region_slug` is null in the
  block context); region renders skip it. Twig macros that invoke
  `claim_priority_image()` MUST receive and retain `region_slug` explicitly because macro
  context is isolated; nested image-bearing blocks must not erase their region ancestry.
  A hero naturally wins when it comes first.
- **Claim only after the media resolves** (`media_image()` non-null): an
  invalid or private first image must not consume the slot and leave the real LCP image
  lazy.
- The winner gets `loading="eager" fetchpriority="high"`; every other in-scope image gets
  `loading="lazy" decoding="async"`.
- **Reset rule (grep-enumerated):** `RenderContextExtension` is process-shared, and the
  per-render reset family is invoked at SIX render boundaries today —
  `RenderController` (~:801), `EntryBlocksRenderer` (~:68), `ShopCartController` (~:283),
  `ShopCatalogController` (~:365), `ShopCheckoutController` (~:375), and
  `RegionAdminController` preview (~:151). Every `resetBlockDepth()` call site also resets
  the priority claim — the plan re-greps and updates ALL of them, and SHOULD fold the
  duplicated reset sequence into one combined per-render reset method so future per-render
  state has a single list. Tests cover consecutive full renders (each gets its own claim)
  and fragment renders (`EntryBlocksRenderer`) not leaking claim state into the next
  render.

## §5 Per-template image scope and reservation policy

Only templates that actually render `<img>` from `media()` are in scope: **hero, image,
blog_posts, logos, the layout's `site_logo()`, container (background), and the new listing
rows**. (audio/file/video use `media()` for non-image sources — untouched.) Reservation is
per-template — no single aspect ratio is forced across image types. In the Loading column,
"eligible" means the image participates in §4's first-eligible-wins claim; non-winners are
`loading="lazy" decoding="async"`:

| Template | Ratio/reservation | Loading | srcset |
|---|---|---|---|
| site logo (`site_logo()`: layout hardcoded + logo block) | natural ratio at fixed height (existing) | positional (§4): layout + header-region block eager, NO fetchpriority; body/footer block lazy+async; never claims | no — brand asset |
| hero media | fixed design crop: CSS `aspect-ratio` on the media wrapper, `object-fit: cover` | claims §4 slot when first eligible; else lazy | yes (§3) |
| image block | authored `width`/`height` preserved; natural ratio; NO invented crop | eligible | yes; `sizes` from the normal/wide/full modifier — except when the author set an explicit `width`, which wins as `sizes="{width}px"` |
| blog_posts cover | fixed card crop (existing layout), `object-fit: cover` | eligible | yes |
| logos | natural ratio at fixed height (existing) | lazy, never claims | no — logos are tiny; srcset noise |
| container background | `background-image` via existing inline style; no `<img>` attrs apply | n/a | no (CSS backgrounds are out of `srcset` reach; recorded, not worked around) |
| listing thumb | fixed 160×110 desktop / 96×72 mobile crop, `object-fit: cover` | eligible | yes |

## §6 Below-the-fold rendering relief

`content-visibility: auto` on exactly TWO surfaces, nothing else (anchors and find-in-page
stay predictable):

- Listing rows: `content-visibility: auto; contain-intrinsic-size: auto 110px;` — the
  `auto` keyword lets the browser retain learned sizes across renders.
- The footer region wrapper: `content-visibility: auto; contain-intrinsic-size: auto 300px;`
  (300px is a placeholder scale — the plan measures the default footer and pins the value).

## §7 Cross-document view transitions: root crossfade only

- `@view-transition { navigation: auto; }` in the default theme's CSS with a ~150ms root
  crossfade. CSS only — no JavaScript dependency, no navigation interception.
- Completely disabled under `prefers-reduced-motion: reduce` (the `@view-transition` opt-in
  itself is wrapped so reduced-motion users get instant navigation, not a shortened fade).
- NO dynamic `view-transition-name` values; NO element morphs for titles, covers, headers,
  or commerce components. Element-level morphing arrives later as an opt-in theme preset
  once listing/detail templates have settled.
- Unsupported browsers navigate normally; custom themes override or remove it in CSS.

## §8 Listing templates (the §11.5 half)

`archive.twig`, `listing.twig`, and `terms.twig` (NOT `index.twig` — §1). Pinned row
design, all degradation server-side in Twig:

- Unframed rows with subtle separators — not floating cards. Cards remain the right shape
  for product catalogs and curated visual collections, not generic archives.
- Row anatomy: cover thumbnail (when present) beside title-first content; quiet date/meta
  when the listing item exposes a published timestamp (field availability verified at plan
  time); excerpt clamped to 2–3 lines (`-webkit-line-clamp`) and omitted entirely when
  absent — never empty space.
- Thumbnail: ~160×110 desktop, ~96×72 mobile, fixed aspect, `object-fit: cover`, srcset
  per §3, `alt=""` — the adjacent title link supplies the accessible identity, so the
  thumbnail is decorative by construction. **The media column keys on the RESOLVED media
  URL, not the cover uuid's presence**: `media(cover)` nulling (private/deleted blob)
  removes the column exactly like an absent cover — never a placeholder, never an empty
  box; text expands.
- Whole-row hover/focus treatment via the stretched-link pattern — the title stays a real
  semantic link; the row gets a visible focus state. **Routeless items** (no `href` — the
  existing template branch) render as plain rows with NO stretched-link, hover, or focus
  affordance: nothing must look clickable that isn't.
- Mobile keeps the thumbnail beside the title; only very narrow screens may stack it above.
- Pagination (`_pagination.twig`) stays visually separate below the list.
- `terms.twig` has no covers/excerpts — it gets the matching typographic row treatment
  (separators, hover/focus, count meta if available) without the media column.

## §9 Testing

- **Size budget:** gzip-level-9 runtime budget test (§2), message documents the rule.
- **`media_image()`:** no resolver bound (generic render host) →
  `{src, srcset: null}` and null when `media()` itself nulls; the Thallo app's always-bound
  resolver + valid image + resizing unavailable/disabled → `{src, srcset: null}`;
  resizing-capable + valid image → `{src, srcset}` with correct `w` descriptors from ONE
  lookup; bound resolver + non-image blob → NULL (element omitted — never a `media()`
  fallback to a non-image URL); widths above the `max_width` clamp are dropped (all
  dropped → `{src, srcset: null}`); missing/private/deleted blob → null.
- **Priority claim:** a page with two heroes renders exactly ONE `fetchpriority="high"`
  image; a page OPENING with an image block claims via the image block (hero-less LCP);
  the site logo never claims despite rendering first; a footer-region image block never
  claims; nested `blog_posts` inside a header-region container retain `region_slug` across
  the macro boundary and never claim; a body/footer `logo` block renders lazy while the
  header-region one is eager; an unresolvable first image does not consume the claim;
  consecutive full renders each get a fresh claim; a fragment render
  (`EntryBlocksRenderer`) does not leak claim state into the next render.
- **Listing rows:** template render tests for the degradation states — cover+excerpt,
  cover only, excerpt only, neither, and cover-uuid-present-but-unresolvable (media column
  removed, no empty space) — plus stretched-link/title-link semantics, the routeless-row
  no-affordance branch, `alt=""` on thumbnails, and two resolvable covers proving exactly
  one listing thumbnail claims `fetchpriority="high"` while the other is lazy.
- **Plain-image parity:** with no variant resolver bound (generic host), or with Thallo's
  resolver bound but resizing incapable, hero/image templates emit no `srcset` attribute
  at all (not an empty one).
- Existing suites (runtime delivery, coexistence, ShopJs, render) stay green; rendered-page
  cache purge on deploy is a dev/ops step, pre-launch.

## §10 Out of scope → later

- Element-level view-transition morphs as an opt-in theme preset.
- Speculation-rules prefetch.
- Commerce templates adopting the §5 discipline (pack-owned follow-up).
- Theme presets with stronger character (separate design conversation).
- A generalized per-block asset-contribution seam (waits for a second real consumer).
