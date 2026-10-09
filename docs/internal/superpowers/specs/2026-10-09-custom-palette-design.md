# Custom palette — design

Status: draft for review (2026-10-09), revision 2. Implements the amendments agreed in
conversation; revision 2 applies the spec review (historical vs blocking usage, a replace job
contract, publication history, the unavailable-colour contract and theme precedence, replacement
destinations, palette read permissions).

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
| `theme_brand_1` … `theme_brand_3` | JSON `{"name": string ≤ 32, "hex": "#rrggbb"}`, plus a server-set `replacing` marker during a replace (§4.3) | unset |

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

Their values are **site-controlled, not theme-controlled**: each always resolves to
`var(--brand-N)` / `var(--brand-N-ink)`, the variables §3.2 emits from the site's settings. A
theme's `theme.json` need not list them (cloned and third-party themes keep loading), and may not
remap them: a theme entry for any of the six names is ignored by the loader and reported by the
Doctor as a warning ("brand colours are set in Appearance"). A theme mapping would bypass the
site's hex and contradict the pickers' swatches and the contrast checks (§5.2, §6).

`StyleCompiler::VERSION` goes to 24, so every colour utility for the new names (background, text,
border, hover, marker, footer divider…) is compiled once into the shared per-theme artifact.
`Vocabulary::VERSION` stays 1: the change only adds names.

Stored values are validated as today: `SettingsValidator` accepts the new names as baseline
tokens, whether or not the slot is configured — a stored reference to an unset slot is valid data.

### 3.2 Rendering a slot

`themeColorsStyle()` emits, for each configured slot, `--brand-N` and `--brand-N-ink` in both the
light `:root` block and the `html[data-theme="dark"]` block. An **unset** slot emits nothing.

**An unavailable reference contributes no colour override.** CSS cannot express "skip this
declaration": a utility whose variable is undefined is *invalid at computed-value time* and takes
the property's inherited or initial value — it does not fall back to an earlier declaration. So
an unset slot's utility must never reach the page. A hover text colour naming an unset slot would
otherwise replace a button's normal text colour with its parent's.

The render therefore decides availability, not CSS: when `style_classes()` (and the region and layout
renders that share it) builds a part's classes, a colour value naming an
unconfigured slot — or its contrast token — emits **no class** for that property and state. The
value stays stored unchanged; the property behaves exactly as if it had never been set, so normal,
hover, keyboard focus and the editor's forced-state preview all keep their other declarations
(a missing hover colour leaves the normal colour in place). The set of configured slots is part of
the appearance fingerprint (§5.3), so a cached render never outlives a slot change. Style classes,
which compile their own declarations, apply the same rule at compile time and take the configured
set as a compile input, so a slot change recompiles the classes that name it.

This is also what restoring an old version whose brand slot has since been cleared produces.

### 3.3 Custom neutral rendering

Under Custom, `ThemeColors::css` emits the six custom values in light `:root` and the dark base
family's six in `html[data-theme="dark"]`, with the accent computed against the *effective* dark
Background (§2.2) as today. A family neutral renders exactly as today.

## 4. Clearing and replacing a brand colour

A configured slot with **blocking** references cannot be cleared. Clearing (in Appearance) first
asks the server for its usage (§4.1); with no blocking references, it clears; with some, the
dialog lists them and offers **Replace with…** (§4.2) or **Cancel**. There is no "clear anyway".

### 4.1 Usage

A `BrandColorUsage` scan — modelled on `FontUsage` — over the block-bearing document sources
(`BlockDocumentSources`) and style classes, at every style path whose value is the slot token or
its contrast token: each block's `settings.style`, each target, each part
(`settings.parts.<name>`), hover values, and token-typed content fields (`data`) whose token domain
is `color`. Nested blocks are walked.

The result has two groups:

- **Blocking** — the documents a site renders now: entry drafts, each entry's current
  publication, the header and footer, layouts, saved sections and style classes. These are what
  Replace rewrites, and while any remain the slot cannot clear.
- **Historical** — retained versions that are neither a draft nor the current publication. Shown
  in the dialog for information ("12 older versions also use Gold dark; restoring one shows it as
  an unavailable colour"), never blocking, never rewritten. A restore after the slot is cleared
  renders those references as unavailable (§3.2).

Each group lists entries, regions, layouts, saved sections and style classes, with counts.

### 4.2 Replacement destinations

The **Replace with…** picker offers configured colour tokens except:

- the slot being cleared and its own contrast token;
- any unconfigured brand slot, and any slot currently being replaced (§4.3);
- contrast tokens in general (`accent-contrast`, `brand-M-contrast`) — they are paired, not chosen.

References to the slot map to the chosen destination. References to **its contrast token** map to
the destination's contrast pair where one exists (`accent` → `accent-contrast`, `brand-M` →
`brand-M-contrast`). Where the destination has none (a neutral such as `text` or `surface`) and the
usage contains contrast references, the dialog shows a second, required picker — "Text on Gold dark
becomes…" — with the chosen pair's contrast ratio in both modes and a warning below 4.5:1. Nothing
is substituted silently.

### 4.3 The replace job

Replace is a **resumable job**, workspace scoped, modelled on the style-class jobs
(`StyleClassController::queueJob` / `showJob`), and needs `content.manage`. It is not promised to
finish within one request.

1. **Start.** The slot's setting gains a `replacing` marker `{job, to, contrast_to}`. While it is
   present the slot stays configured and keeps rendering, is hidden from new choices in every
   picker, and cannot be renamed, re-coloured or cleared by another request (409).
2. **No new references.** While the marker is present, the style-value normalisation every save
   passes through (the path `SettingsValidator` validates) maps the slot's tokens to `to` /
   `contrast_to` on write. An editor saving a stale draft that still names the slot therefore
   writes the destination, rather than being refused or adding a reference the job already passed.
3. **Rewrite.** The job walks the blocking sources. Each document is written through its source's
   conditional `persist()` (`BlockDocumentSource`: atomic, only while the document is at the
   revision it was read at). A `false` — an editor saved, a publication landed — re-reads that
   document and re-applies the mapping, up to three attempts, then defers it to the next pass.
   Style classes are written through their repository with the same revision check.
   - **Drafts, regions, layouts, saved sections, style classes:** rewritten in place, through
     their repositories' change events (which purge the pages that use them after commit).
   - **Current publications:** written through `PublishedEntriesSource`, which appends a new
     version and repins the publication to it — the existing append-and-repin model. The version
     is authored as the palette operation (actor = the user who started it, note "Replaced Gold
     dark with Accent"); no editorial approval is requested. The previous version stays as it was.
4. **Verify and clear.** When a pass rewrites nothing, the job runs a final blocking-usage scan.
   Zero → in one transaction it clears the slot's setting (name, hex and marker) and records the
   audit entry (slot, name, destination, contrast destination, counts per source, historical count).
   Non-zero → another pass, at most five; after that the job stops as failed.
5. **Cache effects after commit.** `ThemeAppearanceChanged` fires only after the clearing
   transaction commits, purging rendered pages and refreshing open stages (§5.3).

**Failure.** A job that fails or is interrupted leaves the marker in place: the slot remains
configured and rendering, the documents already rewritten keep their (valid) destination tokens,
and Appearance shows "Replacing Gold dark with Accent — 140 of 300 done" with **Resume** and
**Cancel**. Resume continues from a fresh scan. Cancel removes the marker and returns the slot to
normal use; it does not revert rewritten documents. A failure never leaves the slot cleared while
blocking references remain.

## 5. The admin

### 5.1 Appearance → Theme colors

- **Neutral** select adds **Custom**. Choosing it shows six labelled hex fields with swatches
  (Background, Surface, Surface 2, Text, Muted, Line), the **Dark mode base** select (hidden while
  colour mode is off), and **Reset to <family>**.
- **Brand colours**: three rows — name, hex with swatch (the accent's `BrandColorField` picker,
  minus the families), and **Clear** (§4). A slot under replacement shows the job's progress with
  **Resume** / **Cancel** instead of its controls (§4.3).
- **Contrast checks** (§6) beneath.
- The live preview (`AppearancePreview`) carries the whole unsaved look — custom values, dark base
  and brand slots — in its signed preview token, as it carries accent and neutral today. Unsaved
  colours never reach any other page or stage.

### 5.2 Colour pickers

Every place that offers colour tokens — block Style tabs (targets, parts, hover), the style-class
editor, the header and footer editor, and token-typed content fields with the colour domain —
reads the vocabulary from the style schema endpoint (`GET /render/style-schema`), which adds the
workspace's palette:

```json
"palette": {
  "brand-1": {"name": "Gold dark", "hex": "#8a6a2a", "configured": true},
  "brand-2": {"configured": false}, "brand-3": {"configured": false}
}
```

**Who can read it.** Today the endpoint requires `content.manage`, so a content, layout or
style-class editor without it gets 403. It takes the Typeface picker's read rule (`GET /fonts`):
any of `content.edit`, `content.manage`, `templates.manage`, `styles.manage`. The palette carries
only names, hexes and configured flags. Usage details (§4.1), the replace job (§4.3) and every
palette change stay under `content.manage`.

- Each colour button shows a **swatch** of the site value and its label; brand slots show the
  author's name ("Gold dark"), contrast tokens "Gold dark — text".
- Unconfigured slots are **hidden from new choices**.
- A stored reference to an unconfigured slot shows first, disabled: **Unavailable colour: Brand 2**
  with "no colour applied" and **Choose another** / **Clear** — kept until someone changes it. A
  slot under replacement is likewise hidden from new choices; a stored reference to it shows its
  swatch with "being replaced by Accent".
- Raw-hex `ColorField` content fields are unchanged: they store a literal value and are outside the
  token system.
- **Scoped palettes.** A block that re-skins its own subtree (a scoped accent or neutral family,
  `ThemeColors::scopedCss`) changes what `accent`, `surface`… resolve to inside it. Its pickers'
  swatches resolve from that scope when the editor knows it; otherwise the swatch is labelled
  **site default**. Brand slots are not affected by a scoped re-skin.

### 5.3 Freshness

Any change to the new keys — including which slots are configured, which decides the classes
§3.2 emits — fires `ThemeAppearanceChanged` (purging rendered pages, as accent and
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
  site's **appearance CSS** (`themeColorsStyle()` output) and the **rendered HTML** of its pages
  are byte-identical to today's (pinned by tests). The shared compiled per-theme stylesheet does
  change — it gains the brand utilities (§3.1) — but nothing on an existing page uses them.
- Themes without the new tokens in `theme.json` load (§3.1); the Doctor accepts them. A theme
  that maps them has the mapping ignored, with a Doctor warning.
- A site that overrode variables in `custom.css` keeps working (custom CSS loads last); the guide
  points to Custom instead.
- Import/export: the new keys travel with the other general settings; brand references in content
  travel as tokens.

## 8. Testing

- **Settings:** each key's validation (hex forms, JSON shape, name length, family names), 422s,
  normalisation; Custom lifecycle (first-entry prefill, values kept across a family switch, reset).
- **Rendering:** appearance CSS and rendered HTML for a family site unchanged (byte-for-byte);
  Custom light values; dark base; Tinted under Custom; brand light/dark/ink derivation; unset slot
  emits no variables; a value naming an unset slot (or its contrast token) emits no class, for
  normal and hover, in blocks, targets, parts, regions, layouts and style classes; preview override
  confined to the preview render.
- **Vocabulary:** domain list (tests pinning it updated); theme without brand tokens loads; a theme
  mapping a brand token is ignored and the Doctor warns; compiler emits brand utilities; validator
  accepts brand tokens.
- **Usage:** references found in every source and path kind (style, target, part, hover, content
  field, nested, style class); blocking vs historical grouping (a retained older version is
  historical, the current publication and draft are blocking); clearing refused only with
  blocking usage, allowed with historical usage alone.
- **Replace destinations:** the slot itself, its contrast token, unconfigured slots, slots under
  replacement and contrast tokens are refused (422); contrast references map to the destination's
  pair; a destination without a pair requires an explicit contrast destination when contrast
  references exist (422 without it).
- **Replace job:** rewrites drafts, regions, layouts, sections and classes in place; each current
  publication gets a new version, repinned, with the previous version unchanged; audit entry;
  clears only after a zero blocking scan. **Concurrent save:** an editor saving a document between
  the job's read and write makes `persist()` return false, and the job re-reads and rewrites it; an
  editor saving a stale draft naming the slot while the marker is present stores the destination;
  a publication landing mid-job is rewritten on retry. **Partial failure:** a job forced to fail
  midway leaves the slot configured with its marker, already-rewritten documents valid, no
  `ThemeAppearanceChanged`; Resume completes it; Cancel removes the marker without reverting.
  Rename / re-colour / clear of a slot under replacement is 409. `ThemeAppearanceChanged` fires
  after the clearing commit, not before. Workspace scoping.
- **History:** replace a slot, then restore an older version that names it — the restored page
  renders with no colour override for those properties and the picker shows the unavailable state.
- **Permissions:** the style schema with only `content.edit`, only `content.manage`, only
  `templates.manage`, only `styles.manage` — each 200 with the palette; with none, 403. Usage
  and the replace job with each of the first, third and fourth alone — 403.
- **Admin:** Appearance form (Custom, dark base visibility, brand rows, clear dialog with blocking
  and historical groups, destination picker exclusions, contrast destination picker, job progress
  with Resume/Cancel, contrast rows), picker swatches and author names, unconfigured and replacing
  slots hidden, unavailable state, scoped "site default" label.
- **Browser:** a block using Brand 1 paints the hex in light mode, the derived value in dark mode,
  and its contrast colour on it; a Custom palette paints the cream ground and Surface 2 band. A
  button whose **hover** text names an unset slot keeps its normal text colour on hover, on
  keyboard focus and in the editor's forced hover preview; a heading whose text names an unset slot
  keeps the colour it had without that setting.

## 9. Documentation and changelog

Appearance guide (Custom neutral, brand colours, contrast checks), style settings reference (the
colour names, brand slots and the unavailable state), theming guide (`brand-*` tokens and their
defaults), the block library where colour lists appear, and an `[Unreleased]` changelog entry.
