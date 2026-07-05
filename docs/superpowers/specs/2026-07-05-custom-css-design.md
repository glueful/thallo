# Site Custom CSS — Design

**Date:** 2026-07-05
**Status:** Draft for review

## Goal

The styling escape hatch: a site-wide, DB-backed `custom.css` editable from the
admin, loaded AFTER the theme stylesheets, so editors can style blocks (via the
stable `lemma-block-*` class conventions) and override preset styles without a
developer + deploy. Deliberately ADDITIVE — shipped `site.css`/`blocks.css`
never get shadowed, so upstream theme improvements keep flowing.

## Contract

### 1. Storage — the existing template store, one well-known path

- `custom.css` is a row in the EXISTING template repository, keyed
  `(theme, 'custom.css')` — per-theme like every template row. This buys
  versioning, history, restore, delete, and the `TemplateUpdated` purge
  pipeline for free.
- There is NO filesystem counterpart: it is a DB-only path (the catalog's
  DB-only listing path already supports this).

### 2. Save validation — type-aware, CSS can never 500 the site

- The save/show/delete path grammar gains ONE special case: the exact path
  `custom.css` is allowed alongside the `.twig` grammar. Every other non-twig
  path still 422s (this spec adds no general asset editing).
- For `custom.css` the Twig policy linter is SKIPPED. Instead: valid UTF-8 and
  a size cap — `lemma_render.custom_css.max_bytes`, default 262144 (256 KB) —
  over-cap or non-UTF-8 → 422. No CSS syntax validation: a broken rule loses
  in the browser; it cannot take the site down (unlike theme.json — that stays
  out of scope for exactly that reason).
- Saving an EMPTY string is legal and means "disabled": the row + history
  remain, but no link renders (§4). Delete-override removes the row entirely
  (existing endpoint).

### 3. Serving — a public versioned stylesheet route

- New public route `GET /custom.css`, registered as a STATIC route (static
  routes win over the `*` page catch-all by router bucketing). Served by the
  render pack from the ACTIVE theme's current row: `Content-Type: text/css`,
  `Cache-Control: public, max-age=31536000, immutable`. 404 when no row or
  the source is empty. Anonymous by design — it is a stylesheet.
- The URL always carries the cache-buster: `/custom.css?v={version_uuid}`.
  Browsers cache immutably; a save produces a new version uuid → new URL →
  fresh fetch. The query string is ignored by the handler (buster only).

### 4. Render seam — `custom_css()` Twig function

- New pack-internal Twig function `custom_css(): ?string` — returns
  `/custom.css?v={version_uuid}` when the active theme has a non-empty
  (after trim) current row, else null. Pack-internal wiring (the repository
  lives in the same pack — the IconSet posture, no app-side contract).
- `TemplatePolicy::FUNCTIONS` += `custom_css`, **CACHE_VERSION 8 → 9**,
  `BlocksRenderingTest` pin updated + lint `{{ custom_css() }}`.
- `layout.twig` emits it after the theme stylesheets (cascade order is the
  point):

  ```twig
  {% set customCss = custom_css() %}
  {% if customCss %}<link rel="stylesheet" href="{{ customCss }}">{% endif %}
  ```

- Cache invalidation: the save dispatches the EXISTING
  `TemplateUpdated(theme, 'custom.css')` → `PurgeRenderCacheOnTemplateUpdate`
  broad-purges rendered pages → regenerated HTML carries the new `?v=`.
  Nothing new to build.

### 5. Admin — a pinned entry in the templates page

- The templates page pins a virtual **Site** group at the top of the file
  tree with one entry, `custom.css` (paintbrush icon, badge `db` when a row
  exists / `empty` otherwise) — always visible, even before the first save,
  so the feature is discoverable. Deduped against the API listing once a row
  exists.
- Opening it: when no row exists the editor opens EMPTY with the helper note
  "Loaded after the theme stylesheets on every page — target blocks via
  their lemma-block-* classes." Save creates the row (server-side the normal
  save flow, minus twig lint per §2).
- `TemplateEditor` gains a language mode: CSS highlighting for `custom.css`,
  the existing Twig mode for everything else (verify the CodeMirror CSS
  language package is already a dependency; add it if not).
- History/restore/delete reuse the existing panel and endpoints unchanged.
- Permissions unchanged: the same triple gate as templates
  (capability → auth → `lemma_permission:templates.manage`).
- **Trust model (review pin):** this is "template-manage power can inject
  global CSS" — the same trust tier as editing templates themselves (which
  can already emit arbitrary markup within the sandbox). README/admin copy
  frames it as TRUSTED-SITE STYLING, not content editing: the permission is
  for site operators/designers, never granted to content-editor roles. The
  editor's helper note and the docs both say so explicitly.

### 6. Preview

Live preview renders through the same layout, so saved custom CSS shows in
preview automatically. DRAFT preview of unsaved CSS is out of scope v1 — the
edit-save-look loop matches how template editing already works.

## Out of scope

- Per-block `css_class` field (agreed follow-up, separate spec).
- Whole-file overrides of `site.css`/`blocks.css`/JS (the shadowing problem;
  revisit only on real demand).
- `theme.json` editing (render-time loud-500 config; the design page owns
  presentation settings).
- Draft/preview-only CSS, per-page CSS, CSS minification.

## Testing

- Policy: `CACHE_VERSION === 9` pin + `custom_css` in FUNCTIONS + lint
  `{{ custom_css() }}`.
- API: save `custom.css` skips the twig linter (a `{% raw %}`-free CSS body
  with braces saves clean); over-cap body 422s; a non-twig path OTHER than
  `custom.css` still 422s (the special case is exact); empty save → row kept,
  `custom_css()` null; delete removes.
- Serving: `GET /custom.css` returns the row with text/css + immutable
  cache headers; 404 when absent/empty; version uuid appears in the URL the
  layout emits and CHANGES after a save.
- Render: layout emits the link only when non-empty custom CSS exists;
  light-touch regression — no link in a fresh install's markup.
- Purge: saving custom.css dispatches `TemplateUpdated` (listener pattern
  already covered by existing purge tests; assert the dispatch).
- Admin (vitest): pinned Site entry always visible; opening the empty state
  shows the helper note; save flow round-trips; history button appears once
  the row exists.

## Files touched

`TemplatesAdminController` (path special-case, type-aware validation),
`TemplateCatalog`/listing (dedupe note), new serving action + static route,
`RenderContextExtension` (+`custom_css()`), `TemplatePolicy` (v9),
`layout.twig`, `config` (`lemma_render.custom_css.max_bytes`),
`templates/index.vue` (pinned Site group), `TemplateEditor.vue` (CSS mode),
tests (policy pin, API matrix, serving, render, vitest), CHANGELOG.
