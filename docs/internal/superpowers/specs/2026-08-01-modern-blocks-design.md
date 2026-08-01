# Modern Blocks: Hero Slider, Animated Text, Gallery — Design

**Date:** 2026-08-01. **Status:** approved design, pre-implementation.

## Context

The starter block library (40+ types seeded by `app/Content/Blocks/StarterBlockTypes.php`,
templates in the default theme, behavior in the theme runtime) lacks three staples of
modern sites. Exploration resolved them as: a **hero presentation preset on the existing
carousel** (mechanics already exist — child blocks as slides, arrows/dots/autoplay,
no-JS scroll-snap floor), a new **`animated_text`** block, and a new **`gallery`**
block. The two new behaviors ship as **lazily-loaded, per-block fingerprinted assets**
— NOT in the universal `runtime.js` (global gzip budget stays at 14,336, untouched).
`RuntimeAssetMap` already fingerprints every `.js`/`.css` in the pack `runtime/` dir
and serves it immutable via `RuntimeAssetController`, so delivery needs no new
infrastructure — only an emission function and the assets themselves.

## Pinned rules

1. **Hero slider is carousel styling, not a new block.** Only the
   `thallo-block-carousel--hero` presentation contract + a documented recipe. All
   carousel mechanics (arrows, dots, autoplay, swipe, scroll-snap floor,
   canvas posture) inherit unchanged.
2. **Block behaviors live in per-block assets** (`runtime/block-animated-text.js`,
   `runtime/block-gallery.js`), loaded only when the block renders, registering with
   `ThalloRuntime` exactly once regardless of how many blocks appear.
3. **No-JS / reduced-motion / canvas / failed-load all degrade to correct static
   output** — static text for animated_text; the plain grid (with real full-size
   links) for gallery.
4. **Teardown contract**: both modules' `enhance()` returns a complete cleanup
   (timers, observers, listeners, generated DOM) per the runtime's established
   contract; `false` for structural no-ops.
5. **One `TemplatePolicy` change**: FUNCTIONS += `block_script`; `CACHE_VERSION`
   17 → 18. `raw`/`constant`/range/`matches` posture unchanged.
6. **Both new templates round-trip the save policy** (they enter the shipped-template
   ratchet gate automatically and must lint clean).

## Design

### 1. `block_script(name)` — lazy asset emission (the one new mechanism)

New Twig function on `RenderContextExtension`, allowlisted (pin 5):

- **Closed catalog (P1):** accepts exactly `'animated-text'` and `'gallery'`; any
  other value returns an empty string. `block_script` is DB-template vocabulary —
  the catalog is a hardcoded const, not derived from the filesystem, so a DB
  template can never point the emitter at an arbitrary asset name.
- Emits `<script defer src="/_thallo/runtime/block-{name}.js"></script>` **once per
  render per name** via a render-scoped emitted-set cleared in
  `resetPerRenderState()`.
- **Per-render dedupe is a bandwidth optimization, NOT the correctness guard (P1):**
  fragment render boundaries reset per-render state independently
  (`EntryBlocksRenderer.php:64` resets extension state for enrichment fragments), so
  a page can legitimately carry the same tag twice. Each asset therefore opens with
  an **exactly-once IIFE guard** (`if (window.__thalloBlockGallery) return;
  window.__thalloBlockGallery = true;` — same pattern per asset) so double
  execution never double-registers (double `ThalloRuntime.register` throws by
  design).
- Ordering is structural: the layout's `runtime_script()` and all `block_script()`
  tags are `defer`, and defer executes in document order — the registry always
  exists before a block asset registers; all defer scripts run before the boot scan.
- Documented limitation: HTML fragments inserted post-load don't execute script
  tags; dynamically injected blocks need the asset already present (canvas
  unaffected — behaviors are canvas-skipped).

### 2. Hero slider preset

- **Carousel schema** gains `style` enum `['default', 'hero']` → existing
  enum→modifier convention emits `thallo-block-carousel--hero`.
- **Hero block semantic fix (P2):** `hero.twig` currently always emits `<h1>`
  (`hero.twig:18`), so a carousel of heroes would emit several H1s. The hero block
  schema gains `heading_level` enum `['h1', 'h2', 'h3']` **defaulting to `h1`**
  (backward compatible); the template renders the chosen tag. The recipe pins the
  guidance: slides use `h2` unless the slider is the page's sole hero, in which case
  the first slide may be `h1`.
- **Explicit CSS contract (P2)** for `--hero` (all in `blocks.css`, class path and
  documented for theme authors):
  1. root containment removal (full-bleed: the hero carousel escapes the content
     column the way band blocks do under the theme's layout rules);
  2. slide flex-basis 100% declared AFTER the `--per-2`/`--per-3` rules so one
     slide per view wins regardless of `slides_per_view`;
  3. each slide's hero child switches to a stacked media/text grid (media layer
     fills the slide, text overlays);
  4. scrim layering: a bottom gradient scrim between media and text;
  5. contrast tokens: overlay text uses the theme's inverse/on-media text tokens,
     not the ambient ink color;
  6. no-image fallback: a hero slide without media keeps the standard hero
     background so text never sits on nothing.
- Recipe documented in the render README: hero slider = `carousel(style=hero)` with
  `hero` children. Existing installs receive the new fields via the block-type sync
  path (`SyncBlockTypesCommand`); seeding stays idempotent.

### 3. `animated_text` block (Content)

**Schema (P1 — the rotating word has an explicit position):**
`prefix` (string), `rotate_words` (`text`, **one alternative per line** — newline
separation so alternatives may be phrases), `suffix` (string), `effect` enum
`['fade', 'slide-up', 'blur']`, `tag` enum `['h1', 'h2', 'h3', 'p']`.
A block with empty `rotate_words` renders `prefix suffix` with the reveal effect
only — rotation machinery absent.

**Width reservation (P1 — no Twig string measuring):** the template renders EVERY
alternative stacked in the same CSS grid cell (`grid-area: 1 / 1`); inactive
alternatives are `visibility: hidden`; the module toggles which one is visible. The
browser therefore reserves the true maximum *rendered* width — no layout shift, no
character-count heuristics.

**Accessibility:** the heading carries a stable accessible phrase built from prefix +
first alternative + suffix (`aria-label` on the root); the visual rotating span
stack is `aria-hidden="true"`. No `aria-live` announcements for rotation.

**Motion bound (P2):** rotation is finite — ONE full cycle through the
alternatives, then settle on the final alternative. The complete cycle must finish
within **5 seconds**: the template uses at most the first 5 alternatives (documented
cap) and the module rotates at 1000ms intervals (≤4 transitions ⇒ ≤4s < 5s), so no
pause control is owed under the moving-content rule.

**`block-animated-text.js`:** exactly-once guard; registers `animated-text`
(selector `.thallo-block-animated_text`, canvas `skip`): IntersectionObserver adds
the in-view class once (CSS keyframes perform the reveal); rotation starts on first
intersection, pauses while offscreen or `document.hidden`, resumes to complete its
single cycle; `prefers-reduced-motion` → module no-ops entirely (static floor: first
alternative visible). `enhance()` returns cleanup (IO, timers, listeners);
missing structural pieces → `false`.

### 4. `gallery` block (Media)

**Schema (P1 — enforced child contract):**

```php
['name' => 'items', 'type' => 'blocks',
 'block_types' => ['image'], 'enforce_block_types' => true],
['name' => 'columns', 'type' => 'enum', 'enum' => ['2', '3', '4']],
['name' => 'aspect', 'type' => 'enum', 'enum' => ['natural', 'square', 'landscape']],
['name' => 'lightbox', 'type' => 'boolean'],
```

(`enforce_block_types` per `FieldDefinition.php:196` — picker-only restriction is
not enough for data written around the UI.)

**Template:** iterate `data.items`; for each item resolve the image asset FIRST —
unresolved or non-image assets are omitted entirely (no dead anchors); each rendered
item is `blocks([item])` wrapped in a real `<a href="{{ full-size blob }}">` with an
accessible label (the image's alt, falling back to "Image N of M"). Lightbox opt-out
reads **`data.lightbox ?? true`** — never `|default(true)`, which would flip an
authored `false` back to `true`. The anchor grid IS the no-JS floor.

**`block-gallery.js`:** exactly-once guard; registers `gallery` (selector
`.thallo-block-gallery`, canvas `skip`): intercepts thumb clicks only when
`data-lightbox` is on; lazily creates ONE native `<dialog>` per gallery on first
open — full image, prev/next controls, an "n of m" position status, a labeled close
icon; native `<dialog>` supplies Esc, modality, and focus containment; close
**explicitly restores focus to the originating thumbnail**; reduced motion disables
transitions only (the lightbox stays functional); galleries on one page are
independently scoped; `enhance()` cleanup removes listeners and any generated
dialog.

### 5. Budgets, gates, tests

- Global `runtime.js`: untouched at 14,336. New per-asset budget test pins each
  block asset at **3,072 bytes gzip** (same conscious-growth posture; raising one is
  its own reviewed decision).
- Both templates enter the shipped-template ratchet lint gate; `block_script` joins
  the linter allowlist (pin 5) so they round-trip the DB editor.
- Node harness tests per module (eval `runtime.js` + the asset bytes together, same
  stub pattern): exactly-once guard under double execution; reveal-once; finite
  cycle + settle + 5s bound; pause gates (offscreen/hidden); reduced-motion no-op;
  gallery dialog lifecycle, position status, focus restore, `data-lightbox` off,
  cleanup accounting for both modules.
- **Playwright gate extension** (`tools/runtime-browser` — the existing
  infrastructure is the correct path): one fixture page with an animated_text block
  and a two-gallery page exercising the real `<dialog>` (modality, Esc, focus
  restore) and the real IO reveal in chromium.
- Seeder/sync: two new `StarterBlockTypes` entries + carousel `style` + hero
  `heading_level`; `blocks:seed` idempotent; `SyncBlockTypesCommand` delivers the
  field additions to existing installs; `StarterTemplatesTest` extended.

## Out of scope (deliberate)

- Custom elements for these blocks (class-based only in v1; the element API can
  adopt them later).
- Masonry layout; typing/character effects; perpetual rotation; video slides in
  gallery; a lightbox for the carousel; serving block assets from the universal
  runtime.
