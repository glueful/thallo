# Theme Color Config — Design

**Date:** 2026-07-07
**Status:** Approved design, pre-implementation
**Parent:** the theming initiative's Feature B, sibling to
`docs/superpowers/specs/2026-07-07-color-mode-design.md` (Feature A, color mode).
Template presets (swapping templates/layouts) remain a separate, later feature.

## Goal

Let an operator re-skin the default theme by choosing a **brand accent** and a
**neutral tone**, both from closed enums, applied live and previewable — with
zero developer or deploy. It re-skins the design **tokens only**; it never swaps
a template. It works in both light and dark mode and sits alongside the color-mode
system Feature A shipped.

## Non-goals (v1)

- Free-hex / arbitrary colors (accent is a named Tailwind family).
- Per-mode independent family choice (one accent + one neutral drive both modes).
- Custom per-token stop overrides.
- Template/layout presets (separate feature class).
- A linked stylesheet delivery route — deliberately deferred; see §10. The storage
  and token model here are chosen so that route can replace the delivery mechanism
  later without a data migration.

## Contract

### 1. What's configurable

- **Accent** — one Tailwind hue family: `red, orange, amber, yellow, lime, green,
  emerald, teal, cyan, sky, blue, indigo, violet, purple, fuchsia, pink, rose`.
- **Neutral** — one Tailwind neutral family: `slate, gray, zinc, neutral, stone`.
- **Defaults `blue` / `slate`** — reproduce today's look exactly (§3).

Both are closed enums. There is no free-text input anywhere in the stack.

### 2. Storage (GeneralSettings, the `theme`-key posture)

Two keys join `GeneralSettings::DEFS` (same `[config-path, type, default]` shape
as `theme`, `site_logo`):

```php
'theme_accent'  => ['thallo.theme.accent',  'string', 'blue'],
'theme_neutral' => ['thallo.theme.neutral', 'string', 'slate'],
```

Accessors `themeAccent(): string` / `themeNeutral(): string`, threaded through
`UpdateGeneralSettingsData` and the `GeneralSettingsController` save map exactly
like `theme`. DB row → config default → hard fallback.

**Write-time validation:** the save **422s** unless both values are members of
their enums (the `PreviewThemeValidator` posture — an invalid appearance can never
be stored). An empty string is NOT legal here (unlike `custom.css`); the enum
guard rejects it. To "reset", the operator picks `blue`/`slate` explicitly.

### 3. The family → token table (a curated constant) + the frozen default

A static map (shipped in the render pack) turns each family into concrete token
values, per mode. It is the single source of truth for non-default emission.

**Accent**, per family:
- `--accent` = `{family}-600` (light) / `{family}-500` (dark).
- `--accent-ink` = `#ffffff` uniformly. The table pins a darker accent stop for any
  hue whose 600 fails white-text contrast (WCAG AA on the button-fill use), so ink
  stays white across every family — no per-family ink value.

**Neutral**, per family, at fixed stop positions in each mode:
`--bg`, `--surface`, `--surface-2`, `--ink`, `--muted`, `--line`. The **slate row
equals the current shipped values verbatim** (below); the other four families
follow the same stop positions in their own ramps.

**Frozen default (P2b pin).** The `blue` × `slate` output MUST equal the values
`site.css` ships today, byte-for-byte, and a test asserts them so a Tailwind-table
refresh can never silently drift the default theme:

```
Light (:root)                     Dark (html[data-theme="dark"])
  --bg:        #ffffff              --bg:        #0b1120
  --surface:   #f6f7f9             --surface:   #111a2e
  --surface-2: #eef0f4             --surface-2: #16213a
  --ink:       #0f172a             --ink:       #e2e8f0
  --muted:     #64748b             --muted:     #94a3b8
  --line:      #e2e8f0             --line:      #1e293b
  --accent:    #2563eb            --accent:    #3b82f6
  --accent-ink:#ffffff             --accent-ink:#ffffff
```

These live canonically in `site.css`'s `:root` / `html[data-theme="dark"]` blocks
(so a copied theme renders standalone with no settings engine). The table's
`blue`/`slate` row is defined to match them and is tested against them.

### 4. Resolution + the contract seam

New contract `Thallo\Contracts\Settings\ThemeAppearanceProvider` in the
`thallo-contracts` package (mirrors `ThemeSettingProvider`):

```php
namespace Thallo\Contracts\Settings;

interface ThemeAppearanceProvider
{
    public function accent(): string;   // resolved family name
    public function neutral(): string;  // resolved family name
}
```

The provider yields the **saved-or-default** pair only. The app binds an
implementation over `GeneralSettings`; the render pack **soft-binds** it
(`container->has()` probe): unbound → the pack uses `blue`/`slate`, so render keeps
working without the app's settings engine.

**Resolution ladder, applied by the render controller per render** (the preview step
sits ABOVE the provider — the provider is never asked during a themed-appearance
preview):
1. A **verified preview session** carrying a signed appearance override → that pair
   (request-local; §6). Else
2. `ThemeAppearanceProvider::accent()/neutral()` — the **saved GeneralSettings** pair.
   Else (provider unbound)
3. `blue` / `slate`.

**Fallback + fingerprint source (P1b pin).** Each resolved value is validated against
its enum *after* reading. An out-of-enum value (e.g. a hand-edited DB row
`theme_accent=banana`) falls back to the default (`blue`) **and logs a warning** —
render never emits broken CSS. The **cache fingerprint (§7) and the emitted CSS both
use the validated-resolved pair after fallback**, not the raw stored value: a
`banana`/`slate` row renders and caches as `blue`/`slate`.

### 5. Injection — `theme_colors_style()` (pinned)

One Twig function, registered by the render pack (like `color_mode_script()`):

- It emits **only generated CSS from the validated enum values** — never arbitrary
  input. Output is `:root { … }` (light tokens) plus `html[data-theme="dark"] { … }`
  (dark tokens), values pulled from the §3 table for the resolved pair.
- **It emits nothing when the resolved pair is the default (`blue`/`slate`)** — the
  frozen `site.css` base already carries those exact values, so default sites keep
  byte-identical, override-free HTML. It emits the override block only for a
  non-default pair.
- **Output order (pin):** rendered in `<head>` **after `site.css` / `blocks.css`,
  before `custom.css`**, so the token override shadows the theme defaults but
  `custom.css` remains the final escape hatch.
- **Live vs preview (pin):** live values come from `GeneralSettings`; preview values
  come from the signed preview-session override — the **same function on the same
  path**, differing only in which resolved pair it reads.
- This is also what **replaces the hard-coded blue** in the dark block: for a
  non-default accent, dark `--accent` comes from the chosen family's dark stop.

Marked `['is_safe' => ['html']]` — safe because the content is generated from two
closed enums, not user input (the trust profile that separates it from `custom.css`).

### 6. Preview integration (token/session-only)

Mirrors per-preview theme (`preview-sessions` spec §5):

- The preview-mint endpoint accepts optional `accent` / `neutral`, **validated
  against the enums and signed into the token payload** (tamper-proof, expires with
  the token, **additive** — old tokens with no appearance fields still verify and
  use the saved/default pair).
- `PreviewSession` gains `?string $accent`, `?string $neutral`. The render controller
  reads them and applies the pair **request-locally**; boot singletons are untouched,
  and preview renders bypass the page cache structurally (so no cache concern).
- **Pin (P1a): a preview override is token/session-only. It NEVER writes
  `GeneralSettings`. `Save` in the admin is the only write that changes the live
  site.** Exiting/expiring the session reverts to the saved pair with no residue.

### 7. Cache (both pins)

- **Fingerprint in the key.** `RenderPageCache` (and the fixed error keys) key on
  `render:{theme}:{accent}-{neutral}:{path}`, where `{accent}-{neutral}` is the
  **validated-resolved pair (§4)**. A color change moves every page to a new key;
  entries under the old key lapse by TTL.
- **Purge on save.** Saving either setting dispatches `ThemeAppearanceChanged`; a
  `PurgeRenderCacheOnAppearanceChange` listener calls
  `invalidateTags(['thallo:render:page'])` — the same purge class as
  theme/menu/template changes. The fingerprint is defense-in-depth against purge
  races and multi-node cache lag.

### 8. Admin UI

A "Theme colors" card in **Settings → General**, near the "Live theme" card:

- Two selects — **Accent** and **Neutral** — each option rendered with its color
  swatch. Enum-bounded; no free input.
- **"Preview on site"** mints a preview session carrying the pending (unsaved) pair
  and opens the live-rendered site in preview chrome (the real-site fidelity path,
  not a synthetic in-admin swatch board).
- **Save** persists both keys to `GeneralSettings` (the only live write).

### 9. Validation summary

- **Write:** save 422s unless both values are in their enums.
- **Read:** an out-of-enum stored value logs and falls back to the default rather
  than emitting broken CSS (§4). Render can never 500 on a bad appearance row.

### 10. CSP (the one accepted downside for v1)

The inline `<style>` varies by settings, so a static hash won't cover it, and a
per-request nonce is incompatible with page caching. **Strict-CSP operators must
allow `style-src 'unsafe-inline'`.** This is acceptable for v1 because:

- CSP is operator opt-in and already custom (Glueful ships none by default).
- The inline style is generated from **two closed enums**, not free CSS — a much
  narrower trust surface than `custom.css`.
- If strict-CSP perfection is later required, **Approach 2** — a linked
  `GET /theme-colors.css` route served from the resolved pair — is a **delivery-only
  swap**: it changes where the CSS is emitted, not the storage keys, the family
  table, the resolution ladder, or the token model. Documented as a future option,
  not built now.

## Architecture units

| Unit | Responsibility | Depends on |
|------|----------------|------------|
| `GeneralSettings` (+2 keys) | store/validate `theme_accent`, `theme_neutral` | `SettingsStore` |
| `ThemeAppearanceProvider` (contract) | expose resolved accent/neutral to render | — |
| App binding over `GeneralSettings` | implement the provider | `GeneralSettings` |
| Family → token table (render pack) | map family → concrete token values (light+dark) | — |
| `theme_colors_style()` Twig fn | emit override CSS for a non-default resolved pair | table, provider, preview session |
| `PreviewSession` (+2 fields) + mint | sign/carry the previewed pair | preview verifier |
| Cache key fingerprint + `ThemeAppearanceChanged` + purge listener | keep cached HTML fresh | `RenderPageCache`, events |
| Admin "Theme colors" card | select accent/neutral, preview, save | GeneralSettings API |

## Testing

- **Table + frozen default:** `blue`×`slate` emits the §3 hex verbatim, light and
  dark; a non-default family emits its own stops; `--accent-ink` is white for every
  accent family.
- **Resolution ladder:** preview override beats saved beats default; a
  `banana`/`slate` row resolves, emits, AND fingerprints as `blue`/`slate` with a
  logged warning.
- **`theme_colors_style()`:** emits nothing for the default pair; emits an
  enum-derived override for a non-default pair; lands after `site.css`/`blocks.css`
  and before `custom.css` in the layout.
- **Preview:** a signed appearance override renders request-locally and never writes
  `GeneralSettings`; an old token with no appearance fields still verifies.
- **Cache:** the key varies by resolved pair; `ThemeAppearanceChanged` purges
  `thallo:render:page`.
- **Validation:** save 422s on an out-of-enum value.
- **End-to-end:** a non-default pair renders correctly in both light and dark.
