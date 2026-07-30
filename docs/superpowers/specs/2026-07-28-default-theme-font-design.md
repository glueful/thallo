# Default Theme Font (Figtree) — Design

**Date:** 2026-07-28
**Status:** Draft for review
**Packages:** `packages/thallo-render` (default theme assets + layout + RenderContextExtension
helper), `packages/thallo-commerce` (shop.css font-family removal), `tests/`

## §0 Context

The default theme renders in whatever the OS ships (SF Pro / Segoe / Roboto) — three
personalities, none chosen. Typography is part of Thallo's product identity, so the default
theme ships its own face. Grounding facts (verified in code, correcting the performance
spec's framing where needed):

- Theme assets are NOT content-addressed/immutable: `asset()` appends `?t={theme}&v={mtime}`
  and responses cache for 24h (`RenderContextExtension::asset()`, theme-asset serving).
  Adequate for fonts (they change ~never); this spec makes no immutability claim.
- `woff2` is already in the theme-asset server's strict MIME map — no serving change.
- `ThemeCloner` copies the complete asset tree — clones inherit the fonts with zero work.
- `asset()` is NOT existence-aware: a missing file still yields a URL (just no `&v=`).
  Custom themes may inherit the default `layout.twig` while using their OWN asset
  directory, so an unconditional preload would 404. §3 adds the existence-aware helper.
- Preview sessions construct a request-local `ThemeLocator` and pass ONLY an alternate
  asset-base URL (`RenderController::themedEnv()` returns `[env, assetBase]`) while the
  extension's `themeAssetsDir` is a boot-pinned constructor value — so any existence
  check must NOT consult the boot dir during a themed preview. §3 replaces the
  base-only override with a render-scoped asset CONTEXT (base + assets dir), reset at
  every render boundary.
- Twig autoescaping breaks naive URL identity inside `<style>`: `{{ url }}` emits
  `&amp;v=…`, and a style element is RAW TEXT — the browser never entity-decodes it, so
  the CSS URL would differ from the (attribute-decoded) preload URL. §3's helper emits
  the whole block from PHP as trusted markup with CSS-escaped raw URLs.
- Shop catalog/cart/checkout containers (`shop.css`) declare their own `font-family` —
  left alone they would silently OVERRIDE the theme face on every shop page (§5).

## §1 Decision (pinned)

- **Figtree** (SIL OFL 1.1) — more identity than Inter, less assertive than Manrope, fits
  Thallo's mixed editorial/business/commerce surfaces; its variable weight axis covers the
  theme's intermediate weights (e.g. 650).
- ONE family across headings and body. No second/display family (that is the future
  "theme presets" track).
- Variable **roman + variable italic**; only roman is preloaded.
- `font-display: swap` on both faces.
- Latin subset initially.
- Custom themes are untouched unless they clone the default or explicitly adopt the font.
- Validation gate before the binary is locked: representative screenshots (§7).

## §2 Files and provenance (reproducibility pinned)

New directory `packages/thallo-render/themes/default/assets/fonts/`:

- `figtree-roman-latin.woff2` — variable weight axis, latin subset.
- `figtree-italic-latin.woff2` — same, italic.
- `OFL.txt` — the license file, copied verbatim from the upstream release.
- `PROVENANCE.md` — commits, for EACH binary: the exact upstream release tag + source URL
  (**github.com/erikdkennedy/figtree** — the upstream repository; use a tagged release,
  v2.0.3 at spec time; the variable sources are `Figtree[wght].ttf` and
  `Figtree-Italic[wght].ttf`), the sha256 of the upstream file AND of the shipped
  subset, and the exact reproducible subsetting command — filenames SHELL-QUOTED because
  `[…]` is a glob (zsh errors on it unquoted):

  ```
  pyftsubset 'Figtree[wght].ttf' \
    --unicodes="U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD" \
    --layout-features='*' --flavor=woff2 \
    --output-file=figtree-roman-latin.woff2
  ```

  (the standard Google Fonts latin range; same command with `'Figtree-Italic[wght].ttf'`
  for the italic). PROVENANCE.md records the fonttools/pyftsubset version AND the Brotli
  package + version used for the woff2 flavor — both determine the output bytes.

## §3 Loading mechanics: one helper owns identity, existence, and escaping

The preload and the `@font-face` `src` MUST reference **byte-identical URLs** — including
the `?t=…&v=…` busters. Twig templating cannot deliver that (§0: autoescape entity-mangles
the raw-text `<style>` path), so ONE PHP-side helper composes and emits everything:

- **Render-scoped asset context** (fixes the preview hazard, §0): the extension's
  base-only `setAssetBase(?string)` override becomes an asset CONTEXT of base + assets
  dir — `setAssetContext(?string $base, ?string $assetsDir)` — reset at every render
  boundary (the same combined-reset family §4 of the performance spec established). The
  preview pipeline (`RenderController::themedEnv()` and its callers) passes the preview
  theme's OWN assets dir alongside the alternate base — `themedEnv()` returns
  `[environment, assetBase, assetsDir]` — so existence checks always consult the
  directory the emitted URLs will actually serve from. `asset()`'s buster logic reads
  the same context.

  **Boundary ordering is pinned** — `RenderController::render()` currently ASSIGNS the
  asset base and THEN calls `resetPerRenderState()`; once the context joins that reset,
  this order would clear the just-set value. Every boundary follows the sequence:

  1. `resetPerRenderState()` (which now also clears the asset context), THEN
  2. `setAssetContext(base, assetsDir)` (and the other site-specific setters).

  Tests prove NO leakage in either direction: a preview render followed by a live render
  (live must not see the preview dir/base) and a live render followed by a preview render
  (preview must not see the boot dir).
- **New Twig function** `font_faces_style(family, romanRel, italicRel = null)` on
  `RenderContextExtension`, returning trusted `Twig\Markup` (is_safe html). It:
  - validates the rel paths with `asset()`'s exact safety rules;
  - resolves each against the ACTIVE asset context; a missing roman → emits NOTHING
    (empty Markup) — no preload, no `@font-face` — so a custom theme inheriting the
    layout with a fontless asset dir falls straight through to the system stack; a
    missing italic just omits the italic face;
  - emits, from one URL composition: the roman `<link rel="preload" as="font"
    type="font/woff2" … crossorigin>` (crossorigin required even same-origin; roman ONLY
    — the italic loads on demand) and a `<style>` block with the roman + italic
    `@font-face` rules (`font-weight: 300 900; font-display: swap;`), the URLs
    **CSS-escaped raw** (never HTML-entity-escaped) so the style URL and the
    attribute-decoded preload URL are the same bytes on the wire.
  - **Complete output-escaping contract** — the function is DB-template-callable (policy
    member) and returns raw HTML, so EVERY dynamic value is escaped for its exact sink,
    not just the URLs-for-CSS case:
    - `family` is validated (or CSS-string-escaped) — it must not be able to terminate
      the CSS string or the style element;
    - the preload `href` is HTML-attribute-escaped with `ENT_QUOTES | ENT_SUBSTITUTE`;
    - CSS `url(…)` values are CSS-string-escaped independently of the attribute path;
    - `<` is escaped in every CSS-sink value so no dynamic input can form `</style>`.
    Tests include HOSTILE inputs (family/path/base containing quotes, `</style>`,
    backslashes, control characters), not only happy-path identity assertions.
- **`layout.twig` head**, BEFORE the `site.css` link:

  ```twig
  {{ font_faces_style('Figtree', 'fonts/figtree-roman-latin.woff2', 'fonts/figtree-italic-latin.woff2') }}
  ```

  The theme names its font files (theme-owned data); the helper owns composition.
- **Template policy**: `font_faces_style` joins `TemplatePolicy::FUNCTIONS`,
  `CACHE_VERSION` bumps (13 → 14) with the conventional bump comment, and the policy test
  gains the function — DB-managed layouts must be able to call it.
- **CSP posture, documented**: the inline `<style>` relies on Thallo's existing
  `style-src 'unsafe-inline'` posture. If stricter CSP ever becomes a goal, font-face
  delivery moves to an externally generated stylesheet — recorded here so that change is
  planned, not discovered.

## §4 Measured fallback face

`"Figtree Fallback"` is Arial metric-adjusted to Figtree so the swap does not reflow:
`size-adjust`, `ascent-override`, `descent-override`, `line-gap-override` values are
GENERATED, not hand-tuned. **The one pinned tool**: `@capsizecss/core`'s
`createFontStack` with `@capsizecss/metrics` — exact versions of both packages recorded
in the comment beside the committed literals (reproducibility requires one tool, not a
choice of two). Acceptance: the values in the shipped `@font-face` match that tool's
output for the exact shipped subset.

The fallback face contains NO URLs, so it needs no buster identity — it lives as static
CSS in `site.css`, not in the helper's inline block. It is harmless on themes without the
font files (an unreferenced `local("Arial")` face).

## §5 Adoption in the stylesheets

- `site.css` body font becomes:
  `font-family: "Figtree", "Figtree Fallback", system-ui, -apple-system, "Segoe UI", sans-serif;`
  (keep the existing size/line-height). Nothing else in the theme names a family, so one
  declaration covers headings and body (pinned: one family).
- **`shop.css` page containers** (`.shop-product`, `.shop-index`, `.shop-category`,
  `.shop-cart`, `.shop-checkout`, `.shop-confirmation`) DROP their `font-family`
  declarations — every shop page extends the theme `layout.twig`, so inheriting the theme
  face is correct and the current declarations would override Figtree with the system
  stack. (The declarations were always defensive; the layout is the single font
  authority.)
- The admin SPA is out of scope (its own design system).

## §6 Budget (separate from the runtime's)

New `FontPayloadBudgetTest`: the summed byte size of
`themes/default/assets/fonts/*.woff2` is ≤ **131,072 bytes (128KB)** — raw file size, no
gzip (woff2 is already Brotli-compressed internally). Separate budget, separate test,
same philosophy as the runtime's: growth is a conscious decision; the assertion message
says so. Expected actuals: roman+italic latin subsets ≈ 90–110KB combined — headroom
without room for a stealth second family.

## §7 Validation gate (before the binary is locked)

After implementation, BEFORE the track is called done:

- **Screenshot pass** (visual approval): an article page, a listing page, a product page,
  and a form page at desktop (~1440px) and mobile (~390px) widths on the dev site,
  reviewed by the operator — weights render correctly across the 400/600/650/700 usages,
  price/tabular figures unharmed. Figtree is the working decision; this gate is where it
  is confirmed or swapped before anything is released.
- **Cold-load behavioral run** (warm loads prove nothing — the font is already cached),
  made DETERMINISTIC rather than throttle-dependent: the run intercepts the roman font
  request and HOLDS the response until first paint, then releases it — throttling alone
  can still let a preloaded font win the race and mask the fallback path. The
  layout-shift observer is installed BEFORE navigation so early shifts are captured.
  Asserts: the fallback renders first and the font swaps in after release; the swap's
  CLS contribution stays < 0.02; and — the §3 identity check that markup string
  comparison cannot prove — the network log shows **exactly ONE request for the roman
  font file** (a second request means the preload and CSS URLs diverged).

## §8 Testing

- `font_faces_style()`: emits preload + both faces for the default theme with the
  preload href and CSS `src` URL as the SAME BYTES (assert on the raw Markup — no
  `&amp;` anywhere in the style block); `crossorigin` present; roman preloaded, italic
  not; empty Markup when the roman is missing; italic-only-missing omits just the
  italic face; `asset()`'s path-safety exception for unsafe rel input.
- **Asset context**: `setAssetContext()` resets at every render boundary with the §3
  ordering (reset FIRST, then set); with a preview-style context (alternate base +
  alternate dir), existence is checked against the ALTERNATE dir — a font present only
  in the boot theme emits nothing, one present in the preview theme emits preview-base
  URLs. Sequential-render leak tests in BOTH directions (preview→live and live→preview,
  per §3).
- **Hostile-input escaping** (§3's contract): family/path values containing quotes,
  `</style>`, backslashes, and control characters neither break out of the style
  element nor corrupt the attribute; the path-safety exception still fires for unsafe
  rel input.
- **Template policy**: `font_faces_style` is in `FUNCTIONS`, `CACHE_VERSION` is 14, and
  the representative-template policy test covers the function.
- Layout render with a fontless asset dir: NO preload, NO inline font style.
- Shop page render: no `font-family` declaration remains on the shop.css page
  containers (the theme face inherits through).
- `FontPayloadBudgetTest` (§6). Full suite + phpcs gates. The §7 cold-load browser run
  is the end-to-end authority for URL identity (exactly one roman request) — the Markup
  string test is the fast regression guard, not the proof.

## §9 Out of scope → later

- Latin-ext / additional subsets (add when a real locale needs them; same provenance
  discipline).
- A second display family, per-theme font pickers, operator font upload — the "theme
  presets with stronger character" track.
- Content-addressed theme-asset delivery (a general theme-assets follow-up, not fonts').
