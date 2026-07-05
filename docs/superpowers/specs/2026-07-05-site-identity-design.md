# Site Identity: favicon, dark-mode logo, cleaner previews — Design

**Date:** 2026-07-05
**Status:** Draft for review

## Goal

The Settings → General identity card grows up: a favicon upload with a
browser-tab preview, a dark-mode logo variant (the default theme HAS a
`prefers-color-scheme: dark` palette), and asset previews that show the image
instead of a uuid string.

## Contract

### 1. Settings (GeneralSettings-backed, like `site_logo`)

- `site_favicon` → `['lemma.site_favicon', 'string', '']` — blob uuid.
- `site_logo_dark` → `['lemma.site_logo_dark', 'string', '']` — blob uuid.
- Both thread through `UpdateGeneralSettingsData`, the controller save map,
  and accessors. Uploads via the existing AssetField flow (public blobs).

### 2. Render seams

- **`SiteLogoProvider` gains the variant** (pre-launch interface change; one
  impl): `siteLogoUuid(string $variant = 'light'): ?string` — `'dark'` reads
  `site_logo_dark`; anything else reads `site_logo`. Dark UNSET → null (the
  template falls back to the light logo everywhere; a dark variant is an
  override, never a requirement).
- **New one-method contract `SiteFaviconProvider`** (`faviconUuid(): ?string`)
  + `EngineSiteFaviconProvider` over GeneralSettings — the SiteLogoProvider
  pattern verbatim (registered WITH its import; soft-bound in the render
  provider).
- **Twig**: `site_logo(variant = 'light')` — the EXISTING function gains an
  optional arg (no policy change). **Variant is validated (P2 review pin):**
  only `null|'light'|'dark'` are accepted; any other value returns null at
  the extension boundary — a DB template can never turn the argument into an
  unbounded settings lookup.
  `site_favicon()` — NEW function → `TemplatePolicy::FUNCTIONS` +=
  `site_favicon`, **CACHE_VERSION 7 → 8**.
  **Favicon URLs obey the media() predicate (P1 review pin):** the uuid
  resolves through the SAME `MediaUrlResolver` path as `site_logo()`/`media()`
  — uploads disabled, non-anonymous access mode, or a non-public/inactive
  blob all yield null, so the layout emits NO link tag rather than one that
  401s. Favicon requests are anonymous browser fetches; a broken link here
  is worse than a missing one.

### 3. Theme (default layout + logo block)

- `<head>` gains `{% if site_favicon() %}<link rel="icon" href="{{ site_favicon() }}">{% endif %}`.
- The header logo and the logo BLOCK render a light/dark pair when the dark
  variant exists:

  ```twig
  {% set logoDark = site_logo('dark') %}
  <img class="site-logo site-logo--light" src="{{ logo }}" alt="{{ site.name }}">
  {% if logoDark %}<img class="site-logo site-logo--dark" src="{{ logoDark }}" alt="{{ site.name }}">{% endif %}
  ```

  CSS: `--dark` hidden by default; under `prefers-color-scheme: dark`, the
  pair swaps **only when a dark image is present** (no dark upload = light
  logo everywhere, unchanged). Same rule pair in `site.css` (header) and
  `blocks.css` (logo block).

### 4. Admin — identity card

- **AssetField single-mode preview loses the uuid text** (global change, all
  callers): the current `img h-10 + uuid` row becomes a larger bare preview
  (`max-h-20`, natural aspect, object-contain) with the Remove button.
  **Identity ownership is pinned (P2 review pin):** the MEDIA PICKER owns
  rich identity — its tiles already show the filename (hover overlay +
  `title`), which is where editors distinguish two similar logos. The field
  preview stays minimal but keeps a machine-identity affordance: the `<img>`
  carries `title`/`alt` with the blob uuid, so hover/tooltip and assistive
  tech can still tell assets apart without visual noise.
- **Site logo** splits into two AssetFields: "Site logo" and "Site logo
  (dark)" — help: "Shown when visitors use a dark color scheme; themes
  without a dark scheme ignore it. Falls back to the main logo." The admin
  always offers it (it can't know theme capabilities; unset = no-op).
- **Favicon**: AssetField + a browser-tab PREVIEW mock (the WP site-icon
  pattern): a rounded app-icon tile and a fake browser tab (favicon + site
  name truncated + ×) rendered from the uploaded blob via `blobDisplayUrl`.
  Help: "PNG or SVG, square, ≥ 512×512 recommended." Preview shows only when
  a favicon is set. Small page-local component (`FaviconPreview`), pure
  presentation.

## Out of scope

- Multi-size favicon generation (apple-touch-icon, .ico conversion, manifest
  icons) — one `<link rel="icon">` with the uploaded image; browsers scale.
- Per-theme capability detection for the dark-logo field.
- Admin dark/light preview switching.

## Testing

- Render: favicon link present when set + absent when unset; dark pair
  renders only with `site_logo_dark` set (header + logo block); light-only
  markup unchanged (regression); policy lint `site_favicon` +
  `CACHE_VERSION === 8`.
- Settings: round-trip both keys through GET/PUT `/settings/general`.
- Admin (vitest): favicon preview renders from the set uuid and hides when
  cleared; AssetField single-mode shows image without uuid text (adjust
  existing assetField specs).

## Files touched

`GeneralSettings`, `UpdateGeneralSettingsData`, `GeneralSettingsController`,
`SiteLogoProvider` + `EngineSiteLogoProvider`, `SiteFaviconProvider` (new) +
`EngineSiteFaviconProvider` (new), `LemmaServiceProvider`,
`RenderContextExtension`, `LemmaRenderServiceProvider`, `TemplatePolicy` (v8),
`layout.twig`, `blocks/logo.twig`, `site.css`, `blocks.css`,
`AssetField.vue`, `settings/general/index.vue` (+ `FaviconPreview`),
tests (render, policy pin update, settings API, vitest), OpenAPI regen,
CHANGELOG.
