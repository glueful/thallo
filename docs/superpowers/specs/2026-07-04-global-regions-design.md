# Global Regions (editable header & footer) — Design

**Date:** 2026-07-04
**Status:** Draft for review

## Goal

Header and footer stop being hardcoded template chrome and become **structured
block regions**: ordered `{id, type, data}` block lists, edited with the
existing block editor, validated against per-region palettes, rendered through
the real Twig pipeline — while navigation and the logo remain **blocks powered
by structured sources** (a menu slug, the site-logo setting), never hand-built
content.

## Pinned contract

1. **Two regions in v1: `header`, `footer`.** The model is slug-keyed so
   `announcement_bar` (or others) can join later without schema change.
2. **A region stores an ordered blocks list** — the same `{id, type, data}`
   model as entry block fields, validated by the same `FieldValidator`
   recursion (block schemas, depth cap, entry-wide id uniqueness per region).
3. **Global (unlocalized) in v1.** One header/footer per site. The navigation
   block delegates locale to `menu()` (menus are per-locale already), so nav
   labels localize for free; literal region copy is single-language until a
   later per-locale pass. Storage reserves the door: the table has no locale
   column, and adding one later is an additive migration.
4. **Server-validated palettes.** Each region has a fixed block-type
   allowlist; saves containing out-of-palette blocks 422 with dot-path errors.
   This is a **deliberate divergence** from the `block_types` convention
   (picker-only): regions are site chrome — the "structured region" promise is
   a hard guarantee, not a picker hint.
   - `header` palette: `logo`, `navigation`, `button`, `social_links`,
     `container`, `columns`, `rich_text`.
   - `footer` palette: everything in the header palette plus `divider`,
     `spacer`, `icon`, `image`, `shortcode`, and `html` (html remains gated on
     its block type being activated — palette membership does not bypass the
     active flag; free text in either region is `rich_text`).
5. **Structured sources.** The `navigation` block selects a **menu slug**
   (renders via `menu()`); the existing `logo` block reads the site-logo
   setting. Editors get layout freedom; the content model stays intact.
6. **Region settings, fixed vocabulary** (like `_presentation` — a system
   contract, not a free bag). v1 keys, header only:
   - `sticky`: bool (default false) → `lemma-region-header--sticky`
   - `width`: `'contained' | 'full'` (default contained) →
     `--contained|--full`
   Unknown keys fail loudly. `footer` accepts `width` only. Background /
   transparency / nav alignment / mobile behavior are later vocabulary
   additions (transparent header is page-level and deferred with variants).
7. **`_presentation` gains `header` and `footer`: `'default' | 'hidden'`.**
   Per-page chrome suppression (landing pages). Composed through the existing
   presentation context; templates never read `_presentation` directly.
   `variant:{slug}` values are future vocabulary — v1 rejects them.
8. **v1 editing surface: a form block-editor admin page** (Design → Header &
   footer) reusing the existing blocks editor components — add / reorder /
   edit exactly like entry block fields, palette-filtered picker, plus the
   region-settings controls. Saves apply immediately (regions have no
   draft/publish lifecycle in v1). Canvas in-place region editing is a later
   pass.
9. **Fallback chrome.** A missing/empty region renders the current hardcoded
   header/footer markup, unchanged. Fresh installs seed default regions that
   reproduce today's look (header: logo + navigation(main); footer: rich_text
   with the site name), so new sites are editable from minute one and
   existing installs change nothing until they save a region.
10. **`region_blocks(slug)` and `region_settings(slug)` join the sandbox** —
    `TemplatePolicy::FUNCTIONS` += both, with the **next policy version after
    the icon library's** (icon shipped 6, so 6 → 7 as of this writing; if
    ordering ever changes, the rule is "one bump per allowlist change", not
    the literal numbers). **`region_blocks` is the ONLY region render path
    (review pin):** it resolves the region, suppresses canvas annotation and
    edit-in-place marks for its render subtree internally, composes through
    the real `blocks()` machinery, and returns Markup — or null on every
    unavailable state (contract §12). Annotation suppression is
    render-context state, NEVER a template decision: `blocks()` keeps its
    public signature unchanged, and no boolean toggle leaks canvas internals
    into the template surface.
11. **Region saves broad-purge the render page cache (review pin).** Chrome
    appears on every page, so a `RegionUpdated` event dispatched from the
    save path is consumed by a `PurgeRenderCacheOnRegionUpdate` listener that
    `invalidateTags(['lemma:render:page'])` — the exact posture of
    `PurgeRenderCacheOnMenuUpdate` / `...OnTemplateUpdate`, and the tag the
    `RenderErrorCache` shares, so cached 404/410 bodies (which render the
    chrome too) purge with it. Broad purge over cleverness; without this,
    pages keep stale chrome until entry-level invalidation happens to touch
    them.
12. **Empty means fallback; hidden is explicit (review pin).** `region_blocks()`
    returns `null` for ALL of: reader unbound, store unavailable, region row
    absent, and a saved-but-empty blocks list — templates cannot (and must
    not) distinguish these; every null renders the hardcoded fallback chrome.
    Editors who want NO header/footer express that through
    `_presentation.header/footer = 'hidden'` (per page) — an intentionally
    empty region is not a way to blank the site chrome. An empty list is
    still a legal save (round-trips in the admin), it just renders as
    fallback. Site-wide chrome removal is a theme decision (edit the layout
    template), not a region state.

## Architecture

### Storage — `lemma_regions`

New app migration `019_CreateLemmaRegionsTable.php`:

| column | type | notes |
| --- | --- | --- |
| `slug` | varchar PK | `header`, `footer` (future: `announcement_bar`, …) |
| `blocks` | JSON | ordered `{id,type,data}` list |
| `settings` | JSON | fixed per-region vocabulary (contract §6) |
| `updated_at` / `updated_by` | | audit |

No draft column, no locale column (contract §3, §8). Pre-launch discipline:
this is a NEW table, so no fold-in concerns.

### Cache invalidation (contract §11)

- `packages/lemma-contracts/src/Content/RegionUpdated.php` — a small event
  (`slug`), the `MenuUpdated` shape (which also lives in contracts and is
  dispatched by its admin controller).
- `RegionAdminController::update` dispatches it after a successful save.
- `packages/lemma-render/src/Listeners/PurgeRenderCacheOnRegionUpdate.php`
  mirrors `PurgeRenderCacheOnMenuUpdate` verbatim:
  `CacheStore::invalidateTags(['lemma:render:page'])` — one tag, every cached
  page and error body. Listener registration follows the two existing render
  listeners' wiring.
- Test: a cached render + region save + re-render observes the new chrome
  (and the listener is registered, not just defined — the wiring is the bug
  surface, per the MediaUrlResolver incident).

### Contracts & wiring (the MenuReader pattern)

- `packages/lemma-contracts/src/Content/RegionReader.php`:
  `blocks(string $slug): ?array` (null = region absent/empty) and
  `settings(string $slug): array`.
- App implementation `App\Content\Regions\EngineRegionReader` over a
  `RegionRepository` (Connection-backed, per-process memo — same staleness
  discipline as everything else).
- Soft-bound into `RenderContextExtension` exactly like `MenuReader` /
  `SiteLogoProvider`: null → `region_blocks()` returns `null` → templates
  fall back to hardcoded chrome. Registered in
  `LemmaServiceProvider::services()` (WITH its `use` import — see the
  MediaUrlResolver incident).

### Rendering

`RenderContextExtension` gains:

```php
public function regionBlocks(Environment $env, array $context, string $slug): ?\Twig\Markup
public function regionSettings(string $slug): array
```

**`region_blocks()` is the one region render path (review pin).** It resolves
the region via the reader (null on every unavailable state, contract §12) and
composes the list through the SAME internal `blocks()` machinery — safe_url,
editable_text, icon(), depth caps all apply — but with canvas annotation and
edit-in-place marking suppressed for its render subtree (save/restore around
the internal call, so nested `blocks()` inside region blocks stays suppressed
too). Region chrome block ids are not entry blocks; annotated wrappers would
corrupt the canvas DOM↔id bridge. Suppression is render-context state inside
the helper — `blocks()`'s public signature is unchanged and templates never
see an annotation toggle.

```twig
{% set headerHtml = presentation.header|default('default') == 'hidden' ? null : region_blocks('header') %}
{% if headerHtml %}
  {% set hs = region_settings('header') %}
  <header class="site-header lemma-region-header lemma-region-header--{{ hs.width|default('contained') }}{% if hs.sticky|default(false) %} lemma-region-header--sticky{% endif %}">
    {{ headerHtml }}
  </header>
{% elseif presentation.header|default('default') != 'hidden' %}
  … current hardcoded header, verbatim …
{% endif %}
```

(Same shape for footer. Two policy functions — `region_blocks`,
`region_settings` — both join FUNCTIONS in the same CACHE_VERSION 7 bump.)

### New starter blocks (count 31 → 33)

- **`navigation`** (`Layout` category):
  `menu` (string, required, pattern `[a-z0-9-]+` — a menu SLUG, not links),
  `orientation` (`enum horizontal|vertical`, default horizontal). Template
  renders `menu(data.menu)` as a `<nav>` with the existing item shape; empty
  menu renders nothing. Structured source by construction.
- **`social_links`** (`Content`): `items` — a repeater of child block
  `social_link` (`Items` category): `icon` (string, required, pattern
  `brand:[a-z0-9-]+` — **brand namespace only**, per the icon-library
  contract), `url` (string, required, through `safe_url`), `label` (string —
  accessible name, falls back to the brand name). Renders an inline row of
  `icon()` glyphs wrapped in links; unknown icons degrade to escaped label
  text. This is the social block the icon-library spec deferred.

Both get templates, `blocks.css` rules, StarterTemplatesTest fixtures, and
join the unsafe-URL matrix (`social_link.url`).

### Validation

`RegionValidator` (app, `App\Content\Regions`): wraps the blocks payload in a
synthetic one-field schema (`[['name' => 'blocks', 'type' => 'blocks']]`) and
runs the existing `FieldValidator` (block schemas, depth, id uniqueness),
then enforces:
- **palette**: every block's `type` (top level only — nested `blocks`-type
  fields inside an allowed block are governed by that block's own schema,
  same as entries) must be in the region's palette; violations are dot-path
  422s (`blocks.2.type`).
- **settings vocabulary**: fixed keys per region (contract §6), loud failure
  on unknown keys — mirrors `validatePresentation`.

Palettes are code constants (`App\Content\Regions\RegionDefinitions`), not
DB state: chrome policy is a product decision, versioned with the code.

### `_presentation` extension

`FieldValidator::validatePresentation` accepts two new keys: `header`,
`footer` ∈ `'default' | 'hidden'` (anything else: loud 422, including
`variant:*` for now). `RenderController::presentationContext` composes them
with the existing theme.json/per-type/built-in chain; built-in default is
`'default'`. The layout consumes `presentation.header` / `presentation.footer`
as above.

### Admin

- **API**: `RegionAdminController` (registered in
  `LemmaServiceProvider::services()` with `use` import):
  - `GET /admin/regions` → both regions (slug, blocks, settings, palette —
    the palette ships in the response so the SPA picker filters without
    hardcoding).
  - `PUT /admin/regions/{slug}` → validate (RegionValidator) + save; 404 on
    unknown slug; 422 on palette/settings/schema violations.
  - Permission: same gate as templates/settings admin surfaces.
- **SPA**: `admin/src/pages/design/regions.vue` (nav label "Header & footer",
  under the existing design/settings cluster): two sections (Header, Footer),
  each an existing blocks editor (`BlocksField`-family components) fed the
  palette as its picker allowlist, plus the header settings controls
  (sticky switch, width select). One Save per region (or one page-level Save
  covering both — plan decides by component fit). Dirty chip per the
  general-settings pattern.
- **OpenAPI + `pnpm gen:api`** regeneration for the new endpoints.

### Seeding

`SetupService::install` seeds both regions (contract §9):
- `header`: `[logo (link_home: true), navigation (menu: 'main')]`,
  settings `{sticky: false, width: 'contained'}`.
- `footer`: `[rich_text with the site name]`, settings `{width: 'contained'}`.
Existing installs get nothing seeded — the layout fallback keeps them
rendering exactly as today. (Optionally: the admin page offers "Start from
the default layout" when a region is empty — plan decides if it's v1.)

## Out of scope (explicit)

- Region **variants** and per-page `variant:{slug}` selection; transparent
  header per page.
- **Per-locale** region content.
- **Canvas in-place** region editing (canvas keeps rendering regions as
  static chrome around the entry; no region patch/outline/pickers).
- Draft/publish lifecycle for regions (saves apply immediately).
- `announcement_bar` (model supports it; not shipped).
- Headless delivery of regions (`GET /content/regions`) — nothing blocks it,
  but no consumer exists yet.
- Region-level custom CSS/background controls beyond the fixed vocabulary.

## Testing

- **RegionValidator**: palette rejection (dot-path), nested-blocks pass-through,
  settings vocabulary (unknown key 422, wrong type 422), id-uniqueness reuse.
- **Render**: seeded header/footer render through `blocks()` (logo + menu
  present); ALL null paths (unbound reader, absent row, saved-empty list)
  fall back to the hardcoded chrome byte-alike;
  `presentation.header = 'hidden'` suppresses both the region AND the fallback;
  settings classes (`--sticky`, `--full`) land on the elements.
- **Cache**: a cached page render + region save (event dispatched through the
  real wiring) + re-render observes the new chrome — proves listener
  registration, not just the listener class.
- **Policy**: `region_blocks`/`region_settings` lint clean; `CACHE_VERSION === 7`.
- **Blocks**: navigation block renders a real menu and nothing for an unknown
  slug; social_link enforces `brand:` pattern at save; unsafe URLs render no
  anchor; brand icons render via `icon()` with label fallback.
- **API**: PUT round-trip; palette 422; unknown-slug 404; GET exposes palettes.
- **Seeder/Setup**: fresh install seeds both regions; `SeedBlockTypesTest`
  count 31 → 33.
- **Admin SPA**: page renders both editors; picker filtered to the palette;
  save PUTs and clears dirty (vitest, data-test hooks per house rules).

## Files touched (summary)

| Area | Files |
| --- | --- |
| Storage | `database/migrations/019_CreateLemmaRegionsTable.php` |
| Contracts | `packages/lemma-contracts/src/Content/RegionReader.php` |
| App engine | `App\Content\Regions\{RegionRepository, EngineRegionReader, RegionValidator, RegionDefinitions}` |
| Render pack | `RenderContextExtension` (+`region_blocks`/`region_settings`), `TemplatePolicy` (FUNCTIONS + v7), `layout.twig`, `blocks.css` |
| Blocks | `StarterBlockTypes` (+navigation, social_links, social_link), `blocks/navigation.twig`, `blocks/social_links.twig` |
| Validation | `FieldValidator::validatePresentation` (+header/footer) |
| Admin API | `RegionAdminController`, routes, `LemmaServiceProvider::services()` |
| Admin SPA | `pages/design/regions.vue`, queries, generated API types |
| Setup | `SetupService` region seeding |
| Tests | validator, render, policy, blocks, API, setup, SPA |
