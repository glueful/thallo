# Starter Block Library Expansion

**Date:** 2026-07-04
**Status:** Draft — awaiting review
**Reference:** `docs/NUXT_UI_PAGE_COMPONENTS.md` (structure/config/styling extracted
from the installed @nuxt/ui 4.9.0) — anatomy and variant vocabulary inform the
new blocks; nothing Vue transfers.

## 0. Goals & non-duplication

Grow the seeded library from 10 to a set covering what every modern marketing
site uses, with **no two blocks sharing the same feature set**. Existing 10
(8 unchanged; `hero` and `cta` are UPGRADED to the Nuxt UI shapes — §2b):
`section` (titled band, preset background, child blocks),
`columns` (2–3 block columns), `divider`, `spacer`, `hero`, `rich_text`,
`quote` (single pull quote + attribution), `cta`, `image`, `gallery`.

Dedup rulings against the requested list and the Nuxt UI reference:
- **Image** — already exists; not re-added.
- **Container vs section**: `section` stays the *semantic titled band* with
  theme-preset backgrounds; `container` is the *free-form styled wrapper*
  (custom color/image background, overlay, width, padding — no title). No
  preset-background overlap: container has no `none|subtle|emphasis` enum.
- **Testimonials vs quote**: `quote` stays the single typographic pull quote;
  `testimonials` is a laid-out set of attributed cards (avatar, role) — the
  social-proof section (Nuxt UI PageCard/PageColumns anatomy).
- **Button vs cta**: `cta` stays the full band (title + description + links
  row); `button` is the standalone action element — used bare inside
  container/columns AND as the child block of `links` fields (hero, cta).
- **NavigationMenu / FooterColumns / Page(+Body/Aside/Anchors/Links)** from
  the reference are *site chrome and page scaffolding* — owned by the theme
  shell, the navigation feature, and `_presentation`. Explicitly NOT blocks.
- **BlogPost/BlogPosts** — a "latest entries" block needs a dynamic delivery
  query inside block rendering (a new data seam). Out of scope v1 (§7).

## 1. Repeating items = child blocks (no new field type)

The field vocabulary has no repeater. Rather than build one, repeating
structured items reuse the existing nested-`blocks` machinery: each
collection block (`features`, `faq`, `tabs`, `testimonials`, `steps`) has an
`items` field of type `blocks` with a `block_types` allowlist (picker-only,
per the existing convention) pointing at a single-purpose **item block**
(`feature`, `faq_item`, `tab`, `step`, `testimonial`).

- Item blocks get a new free-form category **`Items`** so the picker groups
  them away from page-level blocks (category is presentation-only).
- Validation, editor, duplication, canvas mirroring, depth handling — all
  existing machinery; zero new infrastructure.
- **Depth cost (documented):** `BlockDepth::MAX = 3`. `container → testimonials
  → testimonial` fits exactly; a collection block inside a container inside a
  section exceeds the max and is rejected by existing validation. This is the
  accepted trade-off of reusing blocks as repeaters.

## 2. New blocks

Class convention unchanged: root `lemma-block lemma-block-{slug}`, enum values
as `lemma-block-{slug}--{value}` modifiers, inner elements as
`lemma-block-{slug}__{el}`, wide blocks carry their own `__inner` container.
All styling lands in the standalone `blocks.css` (both the full-width flow
rules and the dormant `.layout--centered` rules, mirroring the existing file).
Editable text goes through `editable_text`; rich fields through `safe_html`.
**Every user-supplied URL field renders through the existing `safe_url`
filter before landing in an href** (autoescape does NOT neutralize
`javascript:` in href; `safe_url` scheme-allowlists and nulls everything
else, so templates fall back to plain-text labels). Applies to `feature.url`,
`button.url`, and any future link field — global rule, tested per block.

### Layout

**`container`** — the composition wrapper (user-requested).
- Schema: `background_color` (string — `#rgb`/`#rrggbb`, validated),
  `background_image` (asset), `bg_size` (enum `cover|contain|auto`),
  `bg_repeat` (enum `no-repeat|repeat|repeat-x|repeat-y`),
  `bg_position` (enum `center|top|bottom|left|right`),
  `overlay_color` (string hex), `overlay_opacity` (number 0–100),
  `width` (enum `full|contained|narrow`), `padding` (enum `none|small|medium|large`),
  `min_height` (enum `auto|half|full` — viewport-relative),
  `content` (blocks).
- Template anatomy (Nuxt UI PageCTA band shape):
  ```
  div.lemma-block-container.--{width}.--pad-{padding}.--h-{min_height} [style: CSS vars]
  ├─ div.lemma-block-container__overlay   (only when overlay_color set)
  └─ div.lemma-block-container__inner → blocks(content)
  ```
- **Styling pin (per-instance backgrounds):** the root emits ONE `style`
  attribute containing only CSS custom properties built server-side from
  typed, validated fields — `--container-bg` (validated hex),
  `--container-bg-image: url(...)` (URL from `media()` only),
  `--container-overlay` (hex), `--container-overlay-opacity` (int clamped
  0–100 → 0–1). Enums stay classes. `blocks.css` consumes the vars. This
  narrowly amends the classes-only styling convention for per-instance
  media/color values; free-form CSS remains impossible by construction
  (every var is derived from a typed field, never from raw text).

**`grid`** — responsive wrapping grid of blocks (Nuxt UI PageGrid /
PageColumns).
- Schema: `columns` (enum `2|3|4`), `flow` (enum `grid|masonry`),
  `gap` (enum `small|medium|large`), `items` (blocks — ANY block type; one
  flat list, cells wrap into rows).
- `--grid` = CSS grid (`grid-template-columns`, equal-height rows,
  1 column on mobile); `--masonry` = CSS multi-columns
  (`columns: N` + `break-inside: avoid`, the PageColumns recipe).
- Dedup: `columns` (existing) stays the *fixed* 2–3 column layout split
  with independently authored stacks; `grid` is one flowing list that
  wraps — N cards without hand-balancing columns. `gallery` stays
  images-only.
- Depth note: `grid → testimonial` / `grid → image` fit; `section → grid →
  card-ish block` also fits (3 levels).

### Content

**`features`** + item **`feature`** — the feature grid (Nuxt UI PageSection
features / PageFeature / PageGrid).
- `features`: `title` (string), `intro` (text), `columns` (enum `2|3|4`),
  `items` (blocks, allowlist `feature`).
- `feature`: `icon` (string — emoji or short glyph; the live site has no icon
  font, so v1 icons are emoji/text — documented), `title` (string, required),
  `description` (text), `url` (string).
- Anatomy: `section > __inner > __header (title, intro) > __grid (ul) > li`
  per feature: `__icon`, `__title`, `__description`, stretched link when url.

**`testimonials`** + item **`testimonial`** (user-requested).
- `testimonials`: `title` (string), `layout` (enum `grid|single`),
  `items` (blocks, allowlist `testimonial`).
- `testimonial`: `quote` (text, required), `author` (string), `role` (string),
  `avatar` (asset).
- Anatomy (PageCard subtle-variant recipe): card with quote body, footer row
  of avatar + author/role. `--single` renders one large centered card.

**`faq`** + item **`faq_item`** — accordion (Nuxt UI Accordion).
- `faq`: `title` (string), `multiple` (boolean — allow several open),
  `items` (blocks, allowlist `faq_item`).
- `faq_item`: `question` (string, required), `answer` (text, format rich).
- **No JS**: rendered as native `<details>/<summary>` (chevron rotate +
  border-b styling from the Accordion theme recipe). `multiple: false` uses
  the `name` attribute on `<details>` (native exclusive accordions), and the
  name is **scoped per block instance** — `faq-{{ block.id }}` (block.id is
  already in every block template's context) — so two FAQ blocks on one page
  never share a control group.

**`tabs`** + item **`tab`** (Nuxt UI Tabs, pill variant).
- `tabs`: `items` (blocks, allowlist `tab`).
- `tab`: `label` (string, required), `content` (blocks).
- **No JS**: CSS-only — hidden radio inputs + labels as the pill list,
  `:checked`-driven panel visibility (first tab checked by default). Radio
  `name` and input/label `id`s are **scoped per block instance** with
  `block.id` (`tabs-{{ block.id }}`, `tabs-{{ block.id }}-{{ loop.index }}`)
  so multiple tabs blocks on one page stay independent. Depth note:
  `tabs → tab → rich_text` fits the max.

**`steps`** + item **`step`** — "how it works" (Nuxt UI Stepper, static).
- `steps`: `title` (string), `orientation` (enum `horizontal|vertical`),
  `items` (blocks, allowlist `step`).
- `step`: `title` (string, required), `description` (text).
- Anatomy: numbered circular badges (CSS counters), connecting separator
  line per the Stepper theme recipe. Purely presentational — no active state
  on a marketing page; all steps render.

**`button`** — standalone action (Nuxt UI Button vocabulary).
- Schema: `label` (string, required), `url` (string, required),
  `variant` (enum `solid|outline|soft|ghost`), `size` (enum `sm|md|lg`),
  `align` (enum `left|center|right`).
- Anatomy: `div.lemma-block-button.--{align} > a.--{variant}.--{size}`.
  The `solid|outline|soft|ghost` recipes port Button's neutral+primary rows.

**`carousel`** — swipeable slider of blocks (Nuxt UI Carousel).
- Schema: `slides` (blocks — each direct child block is one slide: image,
  testimonial, card, anything), `slides_per_view` (enum `1|2|3`),
  `arrows` (boolean), `dots` (boolean), `autoplay` (boolean).
- **Nuxt UI's carousel is a JS engine (Embla).** Our port: the base is a
  CSS **scroll-snap** container — `__viewport` (overflow-x auto, snap-x
  mandatory, styled thin scrollbar) with each slide `snap-start` and a flex
  basis from `slides_per_view` — fully functional with native touch swipe
  and no JS. Arrows, dots, and autoplay are **progressive enhancement** via
  a new, tiny `blocks.js` theme asset (the FIRST and only block JS): it
  activates only when a `.lemma-block-carousel` exists, injects/binds the
  controls markup, and honors `prefers-reduced-motion` (autoplay never runs
  under reduced motion; it stops on any interaction). Without JS the
  arrows/dots simply never appear — the block loses nothing essential.
- This amends §4's "no new JS" to "no JS except the optional
  `blocks.js` progressive enhancement, currently used only by carousel" —
  the asset ships with the theme (copyable alongside blocks.css), and is
  NOT required for any other block.
- **Delivery pin:** the default theme's `layout.twig` loads it ONCE —
  `<script defer src="{{ asset('blocks.js') }}"></script>` next to the
  existing `asset('site.css')`/`asset('blocks.css')` links — never from
  per-block templates. Custom themes adopting starter blocks copy
  blocks.css + blocks.js together.
- Dedup: `gallery` stays the static image grid; `carousel` is sequential
  presentation of arbitrary blocks.
- Depth note: `carousel → image` fits; `carousel → testimonial` gives a
  testimonial slider for free.

### Media

**`logo`** — the site logo (user-requested).
- New **`site_logo` setting** (`lemma.site_logo`, string = asset uuid, set in
  Settings → General via the asset picker; empty default). One source of
  truth — the theme shell header can adopt it later.
- Block schema: `size` (enum `small|medium|large`), `link_home` (boolean,
  default true). Renders the setting's asset via `media()`; when the setting
  is empty it falls back to the `site_name` text — never a broken image.
- Seam: block templates don't receive the page context (blocks() values are
  provider-injected, never read from Twig context — existing rule), and the
  `site` context block is config-sourced. So the logo template reads a new
  sandbox-allowlisted `site_logo()` Twig function (provider-injected
  GeneralSettings read, cached per request). Joins `video_embed` in the same
  allowlist addition / CACHE_VERSION bump.

**`logo_cloud`** — "trusted by" strip (Nuxt UI PageLogos).
- Schema: `title` (string), `images` (asset, multiple), `grayscale`
  (boolean), `scroll` (boolean — marquee).
- No child blocks needed (flat asset list, like gallery).
- Marquee is pure CSS (`@keyframes` translate loop, duplicated track,
  `prefers-reduced-motion` disables it).

**`video`** (user-requested).
- Schema: `source` (enum `upload|embed`), `video` (asset), `url` (string),
  `poster` (asset), `caption` (string), `width` (enum `normal|wide|full`).
- `upload` → native `<video controls>` with poster.
- `embed` → **server-parsed provider embed**: a new sandbox-allowlisted Twig
  function `video_embed(url)` returns `{provider, id}` for YouTube/Vimeo URL
  shapes or `null`; the template builds the iframe itself from a fixed
  pattern (`https://www.youtube-nocookie.com/embed/{id}`,
  `https://player.vimeo.com/video/{id}`). Raw user iframes are never
  emitted; unparseable URL → nothing in live, notice in preview.
- Sandbox allowlist gains `video_embed` → CACHE_VERSION bump (see §5a).

**`audio`** (user-requested).
- Schema: `audio` (asset, required), `title` (string).
- Native `<audio controls>` + optional title line. No embeds in v1.

### Advanced

**`html`** (user-requested) — raw HTML escape hatch.
- Schema: `code` (text). Rendered **verbatim** (`|raw`) — this is the block's
  entire purpose (embeds, widgets); sanitizing it would make it `rich_text`.
- **Safety posture:** seeded **deactivated** (`active = false` — the registry
  already supports deactivate-over-delete; existing content keeps validating
  per the registry rule). An admin explicitly activates it in Settings →
  Block types to opt the site into trusted-HTML editing. The canvas stage
  renders it like the live site (same-origin iframe) — documented.
- Editor: plain code textarea (monospace), no canvas inline editing
  (`editable_text` never applies to raw fields).

**`shortcode`** (user-requested) — template-registry escape hatch.
- Schema: `name` (string, slug shape `[a-z][a-z0-9_-]*`), `params` (json).
- Renders `shortcodes/{name}.twig` through the EXISTING template hierarchy
  (theme dir + DB template overrides + sandbox), with `params` in context.
  Missing template → empty string in live render, dashed placeholder box in
  canvas/preview. No PHP callback registry in v1 — shortcodes are Twig
  templates a theme or extension ships (extensions can also seed DB
  templates), which keeps the sandbox as the only execution surface.

## 2b. Existing block upgrades: `hero` and `cta` → the Nuxt UI shapes

Both blocks are REDEFINED in the seeder to mirror PageHero/PageCTA
(user-requested). **No content migration** — there is no existing content to
carry (pre-launch); the seeder definitions change in place, and an already-
seeded install re-seeds after removing the two rows (`lemma:blocks:seed`
never overwrites — one documented CLI step; any previously authored
hero/cta instances are re-authored).

**`hero`** (PageHero):
| field | type | notes |
|---|---|---|
| `headline` | string | eyebrow above the title (primary-colored, per recipe) |
| `title` | string, required | the `<h1>` (was `heading`) |
| `description` | text | supporting paragraph (was `subheading`) |
| `links` | blocks, allowlist `button` | buttons row (was `cta_label`/`cta_url`) |
| `image` | asset | the media column/band (kept) |
| `orientation` | enum `vertical\|horizontal`, default `vertical` | replaces `alignment`: vertical = centered stack (headline/title/description centered, links centered, image full-width below), horizontal = two columns (text + image) |
| `reverse` | boolean, default false | horizontal only: media column first |

Anatomy (PageHero recipe): `root > __inner > __wrapper (header: __headline,
__title h1, __description; footer: __links) + __media (img)`. Vertical
centers the wrapper and balances text; horizontal is `lg:grid-cols-2
items-center`; `--reverse` order-swaps the wrapper. Type scale ports the
recipe (title `text-5xl sm:text-7xl` equivalent in theme tokens).

**`cta`** (PageCTA):
| field | type | notes |
|---|---|---|
| `title` | string, required | the `<h2>` (was `heading`) |
| `description` | text | supporting text (was `body`) |
| `variant` | enum `solid\|outline\|soft\|subtle\|naked`, default `outline` | replaces `primary\|secondary`; recipes port PageCTA's variant classes (inverted band / ring / tinted / tinted+ring / bare) |
| `orientation` | enum `vertical\|horizontal`, default `vertical` | vertical = centered band, horizontal = 2-col |
| `reverse` | boolean, default false | horizontal only |
| `links` | blocks, allowlist `button` | buttons row (was `button_label`/`button_url`) |

Anatomy (PageCTA recipe): rounded band (`rounded-xl overflow-hidden`
equivalent) with the same wrapper/header/footer skeleton.

**Buttons inside `links` rows (both blocks + any future links field):**
- The `button` block's `align` wrapper collapses inside a links row via CSS
  (`.lemma-block-hero__links .lemma-block-button { display: contents }`)
  so buttons sit in one flex row — no schema special-casing.
- Size is CONTEXT-FORCED per the Nuxt UI behavior: hero links render `xl`,
  cta links render `lg`, regardless of the button's own `size` enum
  (CSS context selectors override) — matching PageHero/PageCTA forcing
  `size` on their links.
- Depth: `hero → button` = 2 levels; `section → hero → button` = 3 (fits);
  `container → section → hero → button` = 4 (rejected — documented).

## 2c. Sandbox policy change

`video_embed` and `site_logo` join `TemplatePolicy::FUNCTIONS` in ONE
change; **CACHE_VERSION goes 4 → 5** (concrete target — the existing
policy-version test asserts it).

## 3. Seeder & categories

`lemma:blocks:seed` grows to seed all of the above (idempotent, never
overwrites, unchanged). Categories: Layout (`container`, `grid`), Content
(`features`, `testimonials`, `faq`, `tabs`, `steps`, `button`, `carousel`),
Media
(`logo`, `logo_cloud`, `video`, `audio`), Advanced (`html` — inactive,
`shortcode`), Items (`feature`, `testimonial`, `faq_item`, `tab`, `step`).
Total: 20 new types — 15 page-level (`container`, `grid`, `features`,
`testimonials`, `faq`, `tabs`, `steps`, `button`, `carousel`, `logo`,
`logo_cloud`, `video`, `audio`, `html`, `shortcode`) + 5 item (`feature`,
`testimonial`, `faq_item`, `tab`, `step`). Library 10 → 30.

## 4. Theme & CSS

- New templates under `themes/default/templates/blocks/*.twig` (DB-overridable
  per theme, as today) + one `shortcodes/` directory convention.
- `blocks.css` gains the new recipes — still standalone/copyable; tokens only
  (`--accent`, `--surface`, `--ink`, spacing) mapped from the Nuxt UI
  semantic utilities per the reference doc's conventions table.
- No new JS except the optional `blocks.js` carousel enhancement (§2);
  FAQ = `<details>`; tabs = radio-input CSS; marquee = CSS animation. The bridge continues to annotate wrappers as today; container's
  style attribute is emitted by the TEMPLATE (typed fields), which the
  existing wrapper annotation tolerates (wrappers are display:contents divs
  around templates, unchanged).

## 5. Validation additions

- Hex color shape for `background_color`/`overlay_color` (`/\A#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?\z/`)
  and 0–100 clamp for `overlay_opacity` — enforced in per-block validation
  (existing dot-path error machinery). No new field types: colors are
  `string` fields with block-level validation, consistent with `cta_url`
  being a plain string today.
- `shortcode.name` slug shape at save.

## 6. Canvas behavior

Nothing new required: all blocks are ordinary templates inside annotated
wrappers; enum/string/text fields edit via the existing inspector; nested
`items` reuse the container-block editor. Two notes:
- `html` shows a static (non-editable) preview region.
- `shortcode` placeholder styling lives in preview.css (preview-only UI).

## 7. Out of scope (v1)

- Dynamic "latest entries"/collection block (needs a block-data seam into
  delivery queries — own spec).
- Icon system (icon fonts/SVG sprite) — feature icons are emoji/text v1.
- PHP shortcode callbacks; oEmbed discovery for arbitrary providers.
- Nav/footer/page-scaffold blocks (theme shell + navigation feature own them).
- A dedicated repeater field type (child-block items cover v1; revisit if
  depth limits bite in practice).

## 8. Load-bearing tests

- Seeder: idempotent re-run; all new types present; `html` seeded inactive;
  `hero`/`cta` definitions carry the new field sets.
- Render: hero orientation/reverse modifiers; cta variant classes; links-row
  buttons collapse the align wrapper and take the context-forced size.
- Validation: container hex/opacity bounds (dot-path 422s), shortcode name
  shape, depth rejection for `section → container → testimonials → testimonial`.
- Render: container emits ONLY the four CSS vars in its style attribute
  (attribute content asserted verbatim — the injection surface);
  video embed builds iframes only for parseable YouTube/Vimeo URLs (bad URL
  → no iframe in live output); html block renders verbatim when active;
  shortcode renders theme template with params / empty when missing;
  logo falls back to site_name when the setting is empty.
- Sandbox: `video_embed` + `site_logo` allowlisted + CACHE_VERSION bumped
  (existing policy test pattern).
- Render: carousel base markup is pure scroll-snap (no inline JS, no
  controls markup without blocks.js); layout emits ONE deferred blocks.js
  script tag.
- safe_url: `javascript:alert(1)`, `data:text/html,…`, and `//evil.com`
  values in `feature.url`/`button.url` render NO href (plain-text label);
  https/relative pass through.
- Group identity: two `faq` blocks and two `tabs` blocks on one page render
  disjoint `name`/`id` sets (block.id-scoped).
- Admin/vitest: settings site-logo picker saves; block picker shows the
  Items category grouped.
