# Live Theme Setting — Design

**Date:** 2026-07-05
**Status:** Draft for review

## Goal

Make the live site's theme an ADMIN SETTING instead of deploy configuration:
a "Theme" picker in Settings → General whose save takes effect on the next
request — no `.env` edit, no restart. `RENDER_THEME` stays as the deploy
default underneath (the `homepage_entry` precedence model, exactly).

## Why it's cheap now (and what the one real pin is)

In classic PHP every container singleton is per-request, so ThemeLocator, the
Twig loader paths, and the page cache can all follow a per-request theme read.
The ONLY true boot freeze is the `/theme-assets` static mount: `serveFrontend`
bakes the active theme's assets DIRECTORY into the compiled route manifest.
The fix is the pattern `previewAsset` already uses — a dynamic route that
resolves the directory per request.

## Contract

### 1. The setting (GeneralSettings-backed, homepage_entry posture)

- `theme` joins `GeneralSettings::DEFS`: `['lemma_render.theme', 'string', 'default']`
  — DB row → `RENDER_THEME` env → `'default'`. Accessor `theme(): string`;
  threads through `UpdateGeneralSettingsData` + the controller save map.
- **Write-time validation:** the save 422s unless
  `PreviewThemeValidator::isValidTheme($value)` — you cannot store a theme
  that doesn't exist or has a broken `theme.json`.
- **Read-time posture (the homepage_entry split, P1 pin — matched to the
  ACTUAL ladder):** the settings read revalidates the stored name via
  `isValidTheme()` BEFORE ThemeLocator construction — a DB row that has
  become invalid since the save (directory deleted OR theme.json broken)
  logs and falls back to the env/default; a stale row can never reach
  ThemeLocator's throwing path. The env ladder is UNCHANGED from today's
  pinned behavior: a MISSING `RENDER_THEME` directory keeps the existing
  silent fallback to the pack default; a PRESENT env theme directory with a
  broken `theme.json` stays the existing loud `ThemeConfigError` 500 (deploy
  config error — operator must notice).

### 2. Per-request resolution

- **The settings seam is an explicit lemma-contracts contract (review pin):**
  `Glueful\Lemma\Contracts\Settings\ThemeSettingProvider` —
  `themeOverride(): ?string` (null = no stored override; the
  SiteLogoProvider/SiteFaviconProvider pattern verbatim). The app binds
  `EngineThemeSettingProvider` over `GeneralSettings`; the render pack's new
  `ActiveThemeSource` soft-binds it (`container->has()` probed; unbound →
  env/config only, so the pack keeps working without the app's settings
  engine) and memoizes the resolved name per request. Resolution:
  provider override (revalidated per the posture above) → `RENDER_THEME`
  env → `'default'`.
- `makeThemeLocator` consults `ActiveThemeSource` instead of raw config.
  Everything downstream (TwigFactory loader paths, `DatabaseTemplateLoader`
  keying, `RenderPageCache`'s `render:{theme}:{path}` keys, the templates
  admin's default theme, `custom_css()`/`CustomCssUrl`) follows automatically
  — they all already take the name from `ThemeLocator`.
- Page cache safety is structural: the theme is IN the cache key, so two
  themes never share entries. On theme change a `ThemeChanged` event purges
  via **the same tag mechanism the region/menu/template listeners use —
  `invalidateTags(['lemma:render:page'])` (P2 pin)** — which also covers
  `RenderErrorCache`'s themed 404/410 bodies; NOT a `deletePattern('render:*')`
  (hygiene — orphaned old-theme keys would otherwise linger until TTL, and
  the error bodies would keep the OLD theme's chrome).

### 3. Dynamic theme assets (the one real change)

- Replace the boot-time `serveFrontend('/theme-assets', …)` mount with a
  public route `GET /theme-assets/{path}` (`where('path', '.+')`, registered
  with the other literal statics) served by `RenderController::themeAsset()`:
  resolve the ACTIVE theme's assets dir per request, traversal-guarded
  segment grammar (the `previewAsset` implementation is the model — reuse
  its path validation and MIME map; the framework's static-asset MIME fix in
  1.65.3 applies), `Cache-Control: public, max-age=86400`.
- **Asset URLs carry a theme cache-buster (P1 pin):** browser caches don't
  care about our page-cache purge — with unchanged URLs and `max-age=86400`,
  a browser that cached theme A's `/theme-assets/site.css` would keep using
  it for up to a day after the switch. `asset()` therefore appends the
  ACTIVE theme name: `/theme-assets/{rel}?t={theme}`. A theme switch purges
  the page cache, regenerated HTML emits new `?t=` values, and every asset
  re-fetches immediately — while same-theme repeat views keep the full
  day-long cache benefit. (The query string is ignored by the serving route,
  exactly like `/custom.css?v=`.) The preview pipeline's `setAssetBase`
  override composes unchanged — the buster applies to the live base only.

### 4. Admin

- **Settings → General** gains a "Theme" select (in the right column, its own
  small card): items from a new `GET /v1/admin/render/themes` endpoint
  (render pack; auth + the templates permission gate) returning the SAME
  validator-accepted list the templates page switcher uses — `['default',
  ...appThemes]`. The templates-page listing keeps its embedded `themes`
  array; the endpoint is the shared source for pages that aren't
  template-scoped.
- Saving shows the standard settings toast; copy notes "applies on the next
  page view — preview a theme first via a preview session."
- The templates page dropdown remains "which theme am I EDITING" — unchanged
  and orthogonal.

### 5. Events & invalidation

- `ThemeChanged` (BaseEvent, app-level like the settings save path) dispatched
  when the saved `theme` value actually changes → render-pack listener calls
  `invalidateTags(['lemma:render:page'])` — the SAME tag mechanism the
  region/menu/template purge listeners use (§2), covering pages and the
  themed error bodies alike.
- **Raw-override pin:** `EngineThemeSettingProvider::themeOverride()` reads
  the RAW stored row via a new `GeneralSettings::themeOverride(): ?string`
  (the `homepageEntryOverride()` mirror) — never the resolved effective
  value, or the env fallback would masquerade as a stored override and the
  revalidation/fallback ladder in §1 would misfire.

## Out of scope

- Theme cloning/scaffolding (separate follow-up; see the feasibility note in
  the review thread — CLI-first `lemma:theme:clone`).
- Per-page or per-entry themes; scheduled theme switches.
- Long-running runtimes (Swoole/RoadRunner) — the per-request memo is
  request-scoped there too via context reset, but validating that runtime is
  not v1 work.

## Testing

- Settings API: PUT `theme` round-trips; invalid name 422s; the effective
  value wins over env (seed row ≠ env, render uses row).
- Read-time fallback (amended to the real ladder): stored theme directory
  removed OR its theme.json broken → render logs + falls back with a 200 (a
  stale ROW never 500s); env theme dir MISSING → existing silent default
  fallback (unchanged pin); env theme dir PRESENT with broken theme.json →
  still the loud `ThemeConfigError` 500.
- Dynamic assets: `GET /theme-assets/site.css` serves the ACTIVE theme's file
  with the right MIME; switching the setting switches which file serves (two
  themes with different site.css bodies); traversal-shaped paths 404.
- Asset buster: rendered HTML's `asset()` URLs carry `?t={theme}` and CHANGE
  across a theme switch (regex on two renders); the serving route ignores
  the query string.
- Cache: theme switch dispatches `ThemeChanged` → listener calls
  `invalidateTags(['lemma:render:page'])` (pages AND themed error bodies);
  `render:{theme}:` keys diverge per theme.
- Admin (vitest): the Theme card renders options from the themes endpoint;
  save payload carries `theme`.

## Files touched

`GeneralSettings` (+`theme` DEF/accessor), `UpdateGeneralSettingsData`,
`GeneralSettingsController` (+write-time validator via soft-bound
`PreviewThemeValidator`), new `lemma-contracts`
`Settings/ThemeSettingProvider` + app `EngineThemeSettingProvider`, render-pack
`ActiveThemeSource`, `makeThemeLocator`, `RenderContextExtension::asset()`
(theme buster), provider boot (drop the static mount),
`RenderController::themeAsset` + public route, `ThemeChanged` event + purge
listener (tag-based), `GET /admin/render/themes` endpoint, settings page Theme
card, OpenAPI regen, tests, CHANGELOG.
