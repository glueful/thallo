# Block Library Expansion Implementation Plan

> Spec: `docs/superpowers/specs/2026-07-04-block-library-expansion-design.md`
> Reference: `docs/NUXT_UI_PAGE_COMPONENTS.md`
> Execution: inline, task by task; STAGE at end; commit only on "commit all".

**Goal:** library 10 → 30 (15 new page-level + 5 item blocks), hero/cta
redefined to the Nuxt UI shapes, container/carousel/html/shortcode escape
hatches, site-logo setting, two new sandbox functions.

**Architecture:** everything rides existing machinery — seeder definitions,
block templates + standalone blocks.css, nested-blocks repeaters, the
sandbox allowlist. The only vocabulary addition is generic field validation
(`pattern`/`min`/`max`) so container hex/opacity and shortcode names are
schema-declared, not block-special-cased. One new theme JS asset
(blocks.js, carousel-only).

## Global constraints (from the spec)

- No duplicate feature sets; class convention `lemma-block-{slug}` +
  `--{value}` modifiers + `__{el}` elements; wide blocks own an `__inner`.
- Every user URL through `safe_url` before href.
- faq/tabs control groups scoped by `block.id`.
- Container: ONE style attribute, only the four CSS vars, typed fields only.
- `html` seeds inactive; `shortcode` = Twig templates only.
- CACHE_VERSION 4 → 5 in one policy change (`video_embed` + `site_logo`).
- blocks.js loaded once from layout.twig, deferred; carousel base is pure
  scroll-snap.
- phpcs 120 chars; hold commits.

---

### Task 1: Generic field validation — `pattern` / `min` / `max`

**Files:**
- Modify: `app/Content/Schema/FieldDefinition.php` — optional `pattern`
  (string fields; anchored regex, validated compilable at parse),
  `min`/`max` (number fields). Parse + `toArray()` round-trip.
- Modify: `app/Content/Validation/FieldValidator.php` — enforce on
  string/number values with the existing dot-path errors
  (`"does not match the required format"`, `"must be between X and Y"`).
- Test: `tests/Unit/Content/Schema/FieldDefinitionTest.php` (parse/rt,
  invalid regex rejected at schema save) +
  `tests/Unit/Content/Validation/FieldValidatorTest.php` (pattern miss,
  min/max clamp errors, dot-paths inside blocks).

Generic on purpose: content types get it for free; the seeder (Task 3)
declares container hex/opacity and shortcode-name rules with it.

**Gate:** unit suites green.

### Task 2: Settings + sandbox functions (site_logo, video_embed)

**Files:**
- Modify: `app/Settings/GeneralSettings.php` — DEFS `'site_logo' =>
  ['lemma.site_logo', 'string', '']` + accessor.
- Modify: `app/Http/Controllers/GeneralSettingsController.php` — accept the
  key (no special validation; empty clears via the existing forget path
  like homepage_entry? No — plain value, '' stores empty default → use the
  normal set path).
- Modify: `admin/src/pages/settings/general/index.vue` — "Site logo" card
  reusing `AssetField.vue`; regen not needed if payload passthrough
  (check the settings PUT schema — extend like homepage_entry did).
- Modify: `packages/lemma-render/src/RenderContextExtension.php` —
  - `site_logo(): ?string` — provider-injected GeneralSettings read
    (soft-bound like the sanitizer; null when unset/empty), resolved
    through `media()` so it returns a URL.
  - `video_embed(string $url): ?array{provider: string, id: string}` —
    strict URL parse: YouTube (`youtube.com/watch?v=`, `youtu.be/`,
    `youtube.com/shorts/`) and Vimeo (`vimeo.com/{digits}`); id shape
    validated (`[A-Za-z0-9_-]{6,20}` / digits); anything else → null.
- Modify: `packages/lemma-render/src/Templates/TemplatePolicy.php` —
  FUNCTIONS += `site_logo`, `video_embed`; `CACHE_VERSION = 5` (comment
  updated).
- Wire the GeneralSettings dependency in
  `packages/lemma-render/src/LemmaRenderServiceProvider.php` (soft/nullable
  — the render pack must keep booting without the app's settings service in
  minimal wiring).
- Test: policy version test updated; extension unit tests for the
  video_embed parse matrix (good/bad URLs) and site_logo null/URL.

**Gate:** render pack + settings suites green.

### Task 3: Seeder — 20 new types + hero/cta redefinition

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php`:
  - Redefine `hero` and `cta` per spec §2b (headline/title/description/
    links/image/orientation/reverse; title/description/variant(5)/
    orientation/reverse/links).
  - Add all 20: `container` (with `pattern` on the two hex fields,
    `min: 0, max: 100` on overlay_opacity), `grid`, `features`+`feature`,
    `testimonials`+`testimonial`, `faq`+`faq_item`, `tabs`+`tab`,
    `steps`+`step`, `button`, `carousel`, `logo`, `logo_cloud`, `video`,
    `audio`, `html`, `shortcode` (with `pattern` on name).
  - `links`/`items`/`slides`/`content` blocks-fields carry their
    `block_types` allowlists; item blocks category `Items`; `html` seeds
    with `active => false` (extend the seed insert to honor a per-type
    `active` key — default true).
- Test: `tests/Integration/Blocks/StarterBlockTypesTest.php` (or the
  existing seeder test file) — 30 types after seed, idempotent re-run,
  `html` inactive, hero/cta field sets match §2b, container pattern rules
  present.

**Gate:** seeder tests green.

### Task 4: Templates — hero/cta rewrites + 20 new + layout script

**Files (all under `packages/lemma-render/themes/default/templates/`):**
- Modify: `layout.twig` — `<script defer src="{{ asset('blocks.js') }}"></script>`
  after the css links.
- Rewrite: `blocks/hero.twig`, `blocks/cta.twig` per §2b anatomy
  (`__wrapper` header/footer skeleton, `--{orientation}`, `--reverse`,
  cta `--{variant}`; links via `blocks(data.links)`).
- Create: `blocks/container.twig` (style attr = the four CSS vars from
  validated fields; overlay div only when overlay_color), `blocks/grid.twig`,
  `blocks/features.twig` + `blocks/feature.twig` (safe_url on url),
  `blocks/testimonials.twig` + `blocks/testimonial.twig`,
  `blocks/faq.twig` + `blocks/faq_item.twig` (details name=`faq-{{ block.id }}`
  when NOT multiple), `blocks/tabs.twig` + `blocks/tab.twig` (radios named
  `tabs-{{ block.id }}`), `blocks/steps.twig` + `blocks/step.twig` (CSS
  counters), `blocks/button.twig` (safe_url; no-href fallback renders a
  span), `blocks/carousel.twig` (scroll-snap viewport; slides via
  `blocks(data.slides)`; data-attrs `data-arrows/data-dots/data-autoplay`
  for blocks.js to read — no controls markup server-side),
  `blocks/logo.twig` (site_logo() → img, else site.name text),
  `blocks/logo_cloud.twig` (duplicated track when scroll),
  `blocks/video.twig` (upload → <video controls>; embed → iframe built from
  video_embed() provider/id; null → nothing live), `blocks/audio.twig`,
  `blocks/html.twig` (`{{ data.code|raw }}`), `blocks/shortcode.twig`
  (include `shortcodes/{name}.twig` via a safe computed name if the loader
  finds it; else empty — `{% include ... ignore missing %}` with the
  sanitized name; preview placeholder handled by annotation CSS).
- Create: `shortcodes/` directory with a README stub template convention
  (e.g. `shortcodes/.gitkeep`).

Escaping notes carried from spec: every text through `editable_text` where
inline-editable; `answer` rich via `safe_html`; container style attr built
with `|e('html_attr')` on the assembled var string (values already
shape-validated at save).

**Gate:** existing render suite still green (hero/cta template changes may
touch annotation tests — adjust if selectors changed).

### Task 5: blocks.css expansion + blocks.js

**Files:**
- Modify: `packages/lemma-render/themes/default/assets/blocks.css` — recipes
  for all new blocks + rewritten hero/cta (orientation grids, cta variants,
  container width/padding/min-height + var-driven background/overlay, grid/
  masonry, feature grid, testimonial cards, details-accordion, radio-tabs,
  steps counters/separators, button variants/sizes + links-row context
  forcing (`__links .lemma-block-button { display: contents }`; size
  override), scroll-snap carousel + scrollbar styling, logo sizes,
  logo-cloud strip + CSS marquee (`prefers-reduced-motion` off), video/audio
  frames, dormant `.layout--centered` rules for the new wide blocks).
- Create: `packages/lemma-render/themes/default/assets/blocks.js` —
  IIFE, no deps: for each `.lemma-block-carousel`, read data-attrs, inject
  prev/next buttons + dots, wire scrollTo/scroll events (snap-aligned via
  scrollLeft math), optional autoplay honoring
  `matchMedia('(prefers-reduced-motion: reduce)')`, stop on any interaction.
  ~100 lines, vanilla.

**Gate:** visual sanity via render tests (markup assertions), phpcs
untouched (css/js not linted by phpcs).

### Task 6: Render integration tests (the spec §8 list)

**File:** `tests/Integration/Render/BlockLibraryRenderTest.php` (new; seeds
types via the seeder + a page entry with blocks, renders via the pipeline
helpers used by RenderPipelineTest):
- container: style attribute contains EXACTLY the four vars (asserted
  verbatim), absent when fields empty; invalid hex rejected at save (422
  via pattern).
- video: YouTube/Vimeo URLs → iframe with built src; junk URL → no iframe.
- html: verbatim when type activated; block skipped while inactive?
  (existing rule: deactivated types keep validating — RENDER behavior:
  template still renders; assert current machinery behavior and document).
- shortcode: theme template renders with params; missing → empty output.
- logo: setting set → img with media URL; empty → site name text.
- safe_url matrix on button/feature.
- faq/tabs group identity: two instances → disjoint name/id sets.
- carousel: no controls markup, no inline JS; layout has ONE deferred
  blocks.js tag.
- hero/cta: orientation/reverse/variant modifiers present.

**Gate:** full phpunit green.

### Task 7: Canvas/admin touches

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview.css` (or the
  annotation styles location) — shortcode placeholder (dashed box + name)
  in PREVIEW only; html block region non-editable (no editable_text —
  nothing to do beyond not marking regions).
- Modify: `admin/src/pages/settings/general/index.vue` vitest — site-logo
  field saves (extend the existing general-settings spec).
- Check: block picker groups the `Items` category (existing category
  grouping — assert in an existing picker test if cheap).

**Gate:** admin vitest/type-check/lint green.

### Task 8: CHANGELOG + gates + stage

- CHANGELOG `[Unreleased]`: library expansion entry (counts, hero/cta
  reshape, container/style-var pin, html inactive-by-default, shortcode
  templates, blocks.js, CACHE_VERSION 5, site_logo setting,
  pattern/min/max field validation).
- Dev DB note for the user: delete the old `hero`/`cta` rows from
  `lemma_block_types` and re-run `php glueful lemma:blocks:seed` to pick up
  the new shapes (seeder never overwrites).
- Full gates: phpunit, phpcs, admin vitest/type-check/lint.
- `git add -A`; hold for "commit all".

## Self-review notes

- Task 2's GeneralSettings dependency in the render pack must stay soft
  (nullable) — the pack boots in harnesses without the app container.
- Task 4 hero/cta rewrites change annotation targets (editable_text field
  names changed: heading→title etc.) — canvas edit-region tests referencing
  hero.heading need updating in the same task.
- Task 3 seeder `active` support is additive (default true) — existing
  callers unaffected.
- blocks.js and the canvas MUST NOT fight: injected controls inside
  wrappers would (a) diverge live wrapper HTML from fetched HTML and force
  the v10 patch gate into 'reload' mode, and (b) vanish after a wrapper
  swap with no re-init. Resolution: **blocks.js no-ops entirely in the
  canvas stage** — first line bails when `.lemma-preview-block` exists
  (annotation wrappers are preview-only by construction). The canvas shows
  the scroll-snap base; arrows/dots/autoplay are live-site-only. Test:
  annotated render + blocks.js → no controls injected.
