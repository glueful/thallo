# Custom palette — design

Status: draft for review (2026-10-09). Implements the amendments agreed in conversation.

## 1. Purpose

A site's colours are set in **Appearance → Theme colors**: an accent (a family or any hex) and a
neutral family (slate, gray, zinc, neutral, stone) that supplies Background, Surface, Surface 2,
Text, Muted and Line. Blocks pick colours by **name** (`color.surface`, `color.accent`…), never by
value, so one change recolours the site, dark mode works, and every page shares one compiled
stylesheet.

The gap: a brand whose ground is not grey (Scent Noir's cream, `#F8F4EC`) or that needs a second
brand colour (a deep gold for labels) has nowhere to say so, and authors fall back to overriding
the theme's variables in `custom.css`.

This design lets the author set those values in Appearance, keeping the names model:

- **Custom neutral** — six light-mode hex values, with a **dark-mode base family**.
- **Three brand colours** — stable slots `brand-1`…`brand-3`, each with an author-given name, a
  hex, and a matching readable-text colour.
- **Contrast checks** of specific colour pairs, from the effective palette, in both modes.
- **Truthful pickers** — swatches, author names, and an explicit state for a colour that is no
  longer configured.

Nothing changes for a site until its author chooses Custom or configures a brand colour.

**Three slots is a product decision, not a technical limit.** The compiled style artifact is per
theme; site-specific names could instead come from a separately generated colour artifact per
workspace. Three fixed slots keep the token names stable and the scope small; a later release can
raise the count or move to named colours without breaking stored references (§3.1).

## 2. Settings

All keys are general settings: per workspace when tenancy is on (`settings` is a tenant table),
read DB → config → default like the existing theme keys, saved through the existing General
settings endpoint and validated in `GeneralSettingsController::validate()`.

| Key | Value | Default |
|---|---|---|
| `theme_neutral` | a family, or `custom` | `slate` (unchanged) |
| `theme_neutral_custom` | JSON `{"bg","surface","surface_2","ink","muted","line"}`, each `#rrggbb` | unset |
| `theme_dark_base` | a neutral family | unset (see §2.2) |
| `theme_brand_1` … `theme_brand_3` | JSON `{"name": string ≤ 32, "hex": "#rrggbb"}` | unset |

Hex input accepts `#abc` and `#aabbcc` in any case and is stored lower-case, six digits (as the
accent is today, `ThemeColors::normalizeSiteAccent`). A malformed value is a 422 naming the field;
a stored value that no longer parses (hand-edited, imported) reads as unset.

### 2.1 Custom neutral lifecycle

- Choosing **Custom** for the first time — `theme_neutral_custom` unset — pre-fills the six fields
  from the family the site had, and the dark base from that family too.
- `theme_neutral_custom` and `theme_dark_base` are kept when the neutral is switched back to a
  family: switching to Stone and back to Custom restores the author's values, not a fresh copy.
- Saving Custom requires all six values.
- Clearing Custom's values is an explicit **Reset to <family>** action in the form, not a
  side-effect of switching.

### 2.2 Dark-mode base

Six light values cannot produce a coherent dark palette by formula, so dark mode under Custom uses
the six dark values of the **dark base family** (`ThemeColors` `NEUTRAL_DARK`). If unset, the
base is the family the custom values were first copied from, else `slate`.

When colour mode is off for the deployment (`theme.color_mode.enabled = false`) the dark-base
control is hidden; its stored value is kept and returns when colour mode is turned on.

Plain/Tinted ground keeps working under Custom: Tinted swaps the custom Background and Surface in
light mode, exactly as `ThemeDesign::css` swaps a family's.

### 2.3 Brand colours

Each slot is configured (a name and a hex) or unset. The **name is a label only**: stored style
values always reference the slot token (`color.brand-1`), so renaming changes what the pickers
show and nothing else.

Per slot and per mode the render derives:

- **Light:** the hex as entered.
- **Dark:** as the accent does today (`ThemeColors::accentVars`): mixed toward white in 5% steps
  until it reaches 4.5:1 against the effective dark Background, at most 20 steps.
- **Contrast colour** (`brand-N-contrast`), per mode: black or white, whichever contrasts more
  with that mode's brand fill — what text and icons on a Brand 1 button or card use.

## 3. The style vocabulary

### 3.1 New tokens

The colour domain (`Vocabulary::DOMAINS['color']`) gains six names:

`brand-1`, `brand-1-contrast`, `brand-2`, `brand-2-contrast`, `brand-3`, `brand-3-contrast`

They join `LITERAL_DEFAULTS`' mechanism: a theme's `theme.json` need not list them (cloned and
third-party themes keep loading); the default value of each is `var(--brand-N)` /
`var(--brand-N-ink)`. A theme may still map them explicitly. The default theme lists them.

`StyleCompiler::VERSION` goes to 24, so every colour utility for the new names (background, text,
border, hover, marker, footer divider…) is compiled once into the shared per-theme artifact.
`Vocabulary::VERSION` stays 1: the change only adds names.

Stored values are validated as today: `SettingsValidator` accepts the new names as baseline
tokens, whether or not the slot is configured — a stored reference to an unset slot is valid data.

### 3.2 Rendering a slot

`themeColorsStyle()` emits, for each configured slot, `--brand-N` and `--brand-N-ink` in both the
light `:root` block and the `html[data-theme="dark"]` block. An **unset** slot emits nothing.

An unset slot is therefore *not applied*: its utilities resolve `var(--t-color-brand-N)` →
`var(--brand-N)` to an undefined variable, which CSS treats as the property being unset — text
inherits its colour, a background is transparent, a border takes the text colour. A heading,
label or link that names an unset slot stays readable in its context's colour; nothing renders
transparent text. This is the same outcome as restoring an old version whose brand slot has since
been cleared.

### 3.3 Custom neutral rendering

Under Custom, `ThemeColors::css` emits the six custom values in light `:root` and the dark base
family's six in `html[data-theme="dark"]`, with the accent computed against the *effective* dark
Background (§2.2) as today. A family neutral renders exactly as today.

## 4. Clearing and replacing a brand colour

A configured slot that is referenced anywhere cannot be cleared. Clearing (in Appearance) first
asks the server for its usage (§4.1); with none, it clears; with some, the dialog lists them and
offers **Replace with…** another colour name (any colour token, including another brand slot) or
**Cancel**. There is no "clear anyway".

### 4.1 Usage

A `BrandColorUsage` scan — modelled on `FontUsage` — over every block-bearing document source
(`BlockDocumentSources`: entry drafts, published entries, retained versions, the header and
footer, layouts, saved sections) and style classes, at every style path whose value is the slot
token or its contrast token: each block's `settings.style`, each target, each part
(`settings.parts.<name>`), hover values, and token-typed content fields (`data`) whose token domain
is `color`. Nested blocks are walked. The result lists entries (draft / published / versions),
regions, layouts, saved sections and style classes, with counts.

### 4.2 Replace

Replacing rewrites each reference of `color.brand-N` to the chosen token, and of
`color.brand-N-contrast` to that token's contrast pair where it has one (`accent` →
`accent-contrast`, `brand-M` → `brand-M-contrast`) or else to `text`. It rewrites:

- entry drafts, the header and footer, layouts, saved sections and style classes, through their
  repositories (so their own change events fire and caches purge);
- each entry's **current publication** in place, as a site-wide palette operation (no new version,
  no workflow), recorded in the audit log with the slot, the replacement and the count;
- **not** retained older versions: restoring one later renders any remaining reference as unset
  (§3.2).

When nothing references the slot any more, it clears. The operation is one request, workspace
scoped, and needs the Appearance permission.

## 5. The admin

### 5.1 Appearance → Theme colors

- **Neutral** select adds **Custom**. Choosing it shows six labelled hex fields with swatches
  (Background, Surface, Surface 2, Text, Muted, Line), the **Dark mode base** select (hidden while
  colour mode is off), and **Reset to <family>**.
- **Brand colours**: three rows — name, hex with swatch (the accent's `BrandColorField` picker,
  minus the families), and **Clear** (§4).
- **Contrast checks** (§6) beneath.
- The live preview (`AppearancePreview`) carries the whole unsaved look — custom values, dark base
  and brand slots — in its signed preview token, as it carries accent and neutral today. Unsaved
  colours never reach any other page or stage.

### 5.2 Colour pickers

Every place that offers colour tokens — block Style tabs (targets, parts, hover), the style-class
editor, the header and footer editor, and token-typed content fields with the colour domain —
reads the vocabulary from the style schema endpoint, which adds the workspace's palette:

```json
"palette": {
  "brand-1": {"name": "Gold dark", "hex": "#8a6a2a", "configured": true},
  "brand-2": {"configured": false}, "brand-3": {"configured": false}
}
```

- Each colour button shows a **swatch** of the site value and its label; brand slots show the
  author's name ("Gold dark"), contrast tokens "Gold dark — text".
- Unconfigured slots are **hidden from new choices**.
- A stored reference to an unconfigured slot shows first, disabled: **Unavailable colour: Brand 2**
  with "renders as not set" and **Choose another** / **Clear** — kept until someone changes it.
- Raw-hex `ColorField` content fields are unchanged: they store a literal value and are outside the
  token system.
- **Scoped palettes.** A block that re-skins its own subtree (a scoped accent or neutral family,
  `ThemeColors::scopedCss`) changes what `accent`, `surface`… resolve to inside it. Its pickers'
  swatches resolve from that scope when the editor knows it; otherwise the swatch is labelled
  **site default**. Brand slots are not affected by a scoped re-skin.

### 5.3 Freshness

Any change to the new keys fires `ThemeAppearanceChanged` (purging rendered pages, as accent and
neutral changes do), enters `ThemeAppearanceSource::fingerprint()` (render cache) and
`appearanceFingerprint()` (open stages refresh their head), and invalidates the style schema
query in the admin so pickers show new names and swatches.

## 6. Contrast checks

The Appearance form reports **specific pairs** from the **effective palette** — the values the
site would render after Plain/Tinted swap and dark-base resolution — for **both modes**:

| Foreground | On |
|---|---|
| Text, Muted | Background, Surface, Surface 2 |
| Accent | Background |
| Accent contrast | Accent |
| Each configured brand colour | Background |
| Each brand contrast colour | its brand colour |

Each row shows the ratio and passes at 4.5:1 (normal text); below it, a warning names the pair and
mode. The checks are advisory — they never block a save — and are described as checks of these
pairs, not a guarantee that every combination a block can make is readable. Ratios use the same
WCAG formula as `ThemeColors::contrast` (admin mirror: `style/contrast.ts`). The server computes the
effective palette; the form previews it from the same rules for unsaved values.

## 7. Upgrade and compatibility

- Existing sites: no new key is set, the neutral is a family, no brand slot is configured — the
  emitted CSS is byte-identical to today's (pinned by a test).
- Themes without the new tokens in `theme.json` load with the defaults (§3.1); the Doctor accepts
  them.
- A site that overrode variables in `custom.css` keeps working (custom CSS loads last); the guide
  points to Custom instead.
- Import/export: the new keys travel with the other general settings; brand references in content
  travel as tokens.

## 8. Testing

- **Settings:** each key's validation (hex forms, JSON shape, name length, family names), 422s,
  normalisation; Custom lifecycle (first-entry prefill, values kept across a family switch, reset).
- **Rendering:** CSS for a family site unchanged (byte-for-byte); Custom light values; dark base;
  Tinted under Custom; brand light/dark/ink derivation; unset slot emits nothing; preview override
  confined to the preview render.
- **Vocabulary:** domain list (tests pinning it updated); theme without brand tokens loads;
  compiler emits brand utilities; validator accepts brand tokens.
- **Usage and replace:** references found in every source and path kind (style, target, part,
  hover, content field, nested, style class, version); clearing refused with usage; replace
  rewrites drafts, regions, layouts, sections, classes and current publications, maps contrast
  tokens, leaves retained versions, audits, then clears; workspace scoping.
- **Admin:** Appearance form (Custom, dark base visibility, brand rows, clear dialog, contrast
  rows), picker swatches and author names, unconfigured slots hidden, unavailable state, scoped
  "site default" label.
- **Browser:** a block using Brand 1 paints the hex in light mode, the derived value in dark mode,
  and its contrast colour on it; a heading naming an unset slot inherits its colour; a Custom
  palette paints the cream ground and Surface 2 band.

## 9. Documentation and changelog

Appearance guide (Custom neutral, brand colours, contrast checks), style settings reference (the
colour names, brand slots and the unavailable state), theming guide (`brand-*` tokens and their
defaults), the block library where colour lists appear, and an `[Unreleased]` changelog entry.
