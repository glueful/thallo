# Custom palette — design

Status: draft for review (2026-10-10), revision 5. Implements the amendments agreed in
conversation; revision 2 applies the spec review (historical vs blocking usage, a replace job
contract, publication history, the unavailable-colour contract and theme precedence, replacement
destinations, palette read permissions); revision 3 adds a per-workspace palette fence for saves, job
cancellation and destination reservations, uses the actual render paths (the style cascade and
`token_class()`), and corrects the picker response and the byte-identical claim; revision 4 fences on the original
payload and re-normalises from it, makes restoration's trusted basis explicit, and requires a lock
order and writer inventory in the plan; revision 5 replaces the three fixed brand slots with a list
the author adds to, up to a deployment limit (`theme.brand_colors.max`), each colour keeping a
permanent id, and moves the brand utilities into a per-workspace colours stylesheet (§2.3, §3.4).

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
- **Brand colours** — a list the author adds to with **Add colour**, up to the deployment's limit
  (default 3). Each has a permanent id (`brand-4`), an author-given name, a hex, and a matching
  readable-text colour.
- **Contrast checks** of specific colour pairs, from the effective palette, in both modes.
- **Truthful pickers** — swatches, author names, and an explicit state for a colour that is no
  longer configured.

Nothing changes for a site until its author chooses Custom or configures a brand colour.

**The limit is deployment configuration, not code and not a site setting.** `theme.brand_colors.max`
(`THALLO_BRAND_COLORS_MAX`, default 3, clamped to 0–12) caps how many brand colours a workspace can
have at once. The operator who runs the install sets it; editors add colours up to it. It is not a
general setting because it is a budget, not a preference: each colour adds two choices to every
colour picker and about 3 KB of utilities to the workspace's colours stylesheet (§3.4), and an
author who could raise their own limit would have none. The ceiling of 12 bounds both. `0` turns
brand colours off: the Appearance section and the pickers' brand group are hidden, and stored
references render as unavailable (§3.2).

## 2. Settings

All keys are general settings: per workspace when tenancy is on (`settings` is a tenant table),
read DB → config → default like the existing theme keys, saved through the existing General
settings endpoint and validated in `GeneralSettingsController::validate()`.

| Key | Value | Default |
|---|---|---|
| `theme_neutral` | a family, or `custom` | `slate` (unchanged) |
| `theme_neutral_custom` | JSON `{"bg","surface","surface_2","ink","muted","line"}`, each `#rrggbb` | unset |
| `theme_dark_base` | a neutral family | unset (see §2.2) |
| `theme_brand_colors` | JSON `{"colors": [{"id": int ≥ 1, "name": string ≤ 32, "hex": "#rrggbb"}, …], "removed": [{"id": int, "name": string}, …]}` — `colors` in display order (job and reservation state lives in `palette_state`, §4.3) | unset (no colours) |

The limit is configuration, read from `config/theme.php` (`theme.brand_colors.max`), not a setting
(§1).

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

A workspace has a list of brand colours, in the author's order. Each has a **permanent id** `N`
and is referenced as `color.brand-N` (its text colour `color.brand-N-contrast`). In §3–§8, "slot
N" means the brand colour with id N; a slot is **configured** (in `colors`), **removed** (in
`removed`, keeping its name) or **never issued**.

- **Add.** Appearance's **Add colour** appends a row. On save the server gives each new colour the
  id one above the highest id the workspace has ever issued — configured or removed — inside the
  palette state transaction (§4.3). Ids are therefore never reused: a restored version naming a
  removed colour shows it as unavailable, never as a different colour that took its number.
- **Limit.** A save that would leave more configured colours than `theme.brand_colors.max` is a 422
  ("This site allows 3 brand colours"). Lowering the limit below the current count removes
  nothing: existing colours keep rendering and stay editable, and Add is refused until the count is
  under the limit. Raising it makes Add available at once.
- **Rename, re-colour, reorder** through the same save. Order is the pickers' order and nothing else.
- **Remove only through Clear** (§4). A save that omits a configured id is a 422 ("Remove a brand
  colour with Clear"), so no settings write can strand references without the usage check. Clearing
  moves the colour from `colors` to `removed`, keeping its id and name.
- **Imports** apply the same rules: imported colours are added or updated by id (the source's ids
  are kept, and the next id stays above every id either side has issued), colours the import does
  not name are kept, and the limit applies.

Saving brand colours through the General settings endpoint is a palette mutation (§4.3): it runs
in the palette state transaction and is refused (409) where a running job forbids it. The **name is
a label only**: stored style values always reference the id token (`color.brand-1`), so renaming
changes what the pickers show and nothing else.

Per slot and per mode the render derives:

- **Light:** the hex as entered.
- **Dark:** as the accent does today (`ThemeColors::accentVars`): mixed toward white in 5% steps
  until it reaches 4.5:1 against the effective dark Background, at most 20 steps.
- **Contrast colour** (`brand-N-contrast`), per mode: black or white, whichever contrasts more
  with that mode's brand fill — what text and icons on a Brand 1 button or card use.

## 3. The style vocabulary

### 3.1 New tokens

The colour domain gains a **family of names** rather than a fixed list: `brand-N` and
`brand-N-contrast` for any id `N` from 1 to 9999 (`/\Abrand-[1-9][0-9]{0,3}(-contrast)?\z/`).
`Vocabulary` exposes the pattern (`Vocabulary::isBrandColor()`), and the static
`DOMAINS['color']` list holds no brand names. The style schema's vocabulary lists the workspace's
configured brand colours, in order, after the theme's colour names (§5.2).

Their values are **site-controlled, not theme-controlled**: each always resolves to
`var(--brand-N)` / `var(--brand-N-ink)`, the variables §3.2 emits from the site's settings. A
theme's `theme.json` need not list them (cloned and third-party themes keep loading), and may not
remap them: a theme entry for any brand name is ignored by the loader and reported by the
Doctor as a warning ("brand colours are set in Appearance"). A theme mapping would bypass the
site's hex and contradict the pickers' swatches and the contrast checks (§5.2, §6).

The brand utilities are **not** in the shared per-theme artifact: they are generated per workspace
for its configured ids (§3.4). `StyleCompiler::VERSION` goes to 25, removing them from the
per-theme artifact. `Vocabulary::VERSION` stays 1: the change only adds names.

Stored values are validated as today: `SettingsValidator` accepts any name matching the pattern as
a baseline token, whether or not the slot is configured — a stored reference to a removed or
never-issued slot is valid data.

### 3.2 Rendering a slot

`themeColorsStyle()` emits, for each configured slot, `--brand-N` and `--brand-N-ink` in both the
light `:root` block and the `html[data-theme="dark"]` block. A **removed** or **never-issued** slot
emits nothing, and nor does any slot while the limit is 0; all of these are "unconfigured" below.

**An unavailable reference contributes no colour override.** CSS cannot express "skip this
declaration": a utility whose variable is undefined is *invalid at computed-value time* and takes
the property's inherited or initial value — it does not fall back to an earlier declaration. So
an unconfigured slot's utility must never reach the page. A hover text colour naming an
unconfigured slot would otherwise replace a button's normal text colour with its parent's.

The render therefore decides availability, not CSS, on both paths that turn a stored colour token
into a class:

- **Style settings** — `BlockStyleEmitter::classesFor()`, which every block, target, part, region
  and layout render reaches through `style_classes()`. Before a path's cascade is resolved
  (`resolver->resolve($path, $classDefinitions, $instance, …)`), a colour value naming an
  unconfigured slot — or its contrast token — is **removed from each layer that holds it**: the
  instance's values and each applied style class's definition, per breakpoint and per state. The
  cascade then resolves as if that layer had never set the property, so a lower layer shows
  through: a style class that supplies Accent under an instance value of unavailable Brand 1
  resolves to Accent; a missing hover colour leaves the normal colour in place; with no other
  layer the property takes its theme default. Style classes have no separate compilation step —
  their definitions feed this same render-time cascade — so nothing is recompiled.
- **Token-typed content fields** — `token_class()` (`RenderContextExtension::tokenClass`), which
  block templates call for colour values kept in `data` (Animated text's prefix and rotating
  colours). It already returns `''` for an unknown value; it also returns `''` for a value naming
  an unconfigured slot or its contrast token.

The value stays stored unchanged. Normal, hover, keyboard focus and the editor's forced-state
preview all follow from the resolved cascade. The set of configured slots is part of the
appearance fingerprint (§5.3), so a cached render never outlives a slot change.

This is also what restoring an old version whose brand slot has since been cleared produces.

### 3.3 Custom neutral rendering

Under Custom, `ThemeColors::css` emits the six custom values in light `:root` and the dark base
family's six in `html[data-theme="dark"]`, with the accent computed against the *effective* dark
Background (§2.2) as today. A family neutral renders exactly as today.

### 3.4 The colours stylesheet

Modelled on the workspace fonts stylesheet (`FontsArtifact` / `FontsArtifacts`): for each configured
brand colour, the utilities the style compiler emits for a colour name — background, text, border,
hover, marker, footer divider and the rest — for `brand-N` and `brand-N-contrast`, in
`@layer settings`, generated from **the same property table** `StyleCompiler` uses, so the two
cannot drift. Values stay in variables (`var(--t-color-brand-N)`, resolved from §3.2's
`--brand-N`), so the stylesheet depends only on **which ids are configured**:

- Its hash is over the sorted configured ids and the compiler version. Renaming or re-colouring
  changes nothing here (the variables change); adding or clearing a colour makes a new stylesheet.
- It is written as `colors-{hash}.css` under the style cache, publish-then-serve, with the
  compiled artifacts' retention (`CompiledStyleArtifacts::RETAIN_NEWEST` / `RETAIN_SECONDS`).
- Pages, stage heads and the Appearance preview link it after the compiled per-theme artifact.
  Its hash enters the render-cache fingerprint and `appearanceFingerprint()` (§5.3).
- A workspace with no configured colours links nothing.

Under tenancy every workspace gets its own stylesheet, while the per-theme artifact stays shared.

## 4. Clearing and replacing a brand colour

Clearing moves a colour to `removed` (§2.3); it is the only way a brand colour leaves the list. Its
endpoints take the colour's id (`/v1/admin/appearance/palette/brand/{id}`, `{id}` matching
`[1-9][0-9]{0,3}`). A configured slot with **blocking** references cannot be cleared. Clearing (in Appearance) first
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
- any unconfigured brand slot, and any slot currently being replaced (§4.4);
- contrast tokens in general (`accent-contrast`, `brand-M-contrast`) — they are paired, not chosen.

References to the slot map to the chosen destination. References to **its contrast token** map to
the destination's contrast pair where one exists (`accent` → `accent-contrast`, `brand-M` →
`brand-M-contrast`). Where the destination has none (a neutral such as `text` or `surface`) and the
usage contains contrast references, the dialog shows a second, required picker — "Text on Gold dark
becomes…" — with the chosen pair's contrast ratio in both modes and a warning below 4.5:1. Nothing
is substituted silently.

### 4.3 Palette state and the write fence

Brand slot changes and replace jobs coordinate through one **palette state row per workspace**, in
a new tenant table `palette_state`: a `generation` integer, the active replace jobs (each: job id,
source slot, `to`, `contrast_to`) and the **reservations** they hold. Every palette mutation —
configuring, renaming, re-colouring or clearing a slot, starting, finishing or cancelling a job —
runs in a transaction that updates this row, bumping `generation`. The row lock serialises those
mutations with each other and with fenced saves.

**Fenced saves.** Every save of a block-bearing document or style class (drafts, regions, layouts,
saved sections, style classes, and the job's own writes) normalises colour tokens (§4.5) against
the palette state it read, noting its `generation`. The save is **fenced** when either the
**original submitted payload** or the normalised payload names any brand token, and every write the
replace job makes is fenced regardless. A fenced save's write transaction first runs a conditional
update on the palette state row — `WHERE workspace = ? AND generation = ?` — and only then writes
the document. A palette mutation that committed in between makes the conditional update match
nothing: the save re-reads the palette state, **re-normalises from the original submitted payload**
(never from its earlier normalised result, which may encode a decision the mutation made obsolete)
and retries (three attempts, then 409 "the palette changed, try again"). The conditional update
also holds the row lock until the save commits, so a palette mutation started after it waits for
the save to land and then sees it.

Fencing on the original payload matters because normalisation can remove every brand token: under
a Brand 1 → Accent job a submitted Brand 1 normalises to Accent. If that job is cancelled and a
Brand 1 → Brand 2 job starts before the save commits, the generation check catches it and the
retry maps Brand 1 to Brand 2. Only a save whose submitted and normalised payloads both name no
brand token skips the fence — it can neither add a reference nor carry a stale mapping.

Both orderings are therefore safe:

- **Save first.** A save that read "no job" commits before the job starts (or before an ordinary
  clear's transaction takes the row): the job's scan, or the clear's scan, sees the saved
  reference.
- **Mutation first.** The job start or clear commits first: the save's conditional update fails,
  and it re-normalises against the new state (mapping to the destination during a job; refusing a
  new reference to a cleared slot, §4.5).

An **ordinary clear** (no blocking usage) runs its blocking-usage scan inside the transaction that
holds the row, after bumping `generation`; a reference saved before it is seen and refuses the
clear, and one racing after it is refused by the fence.

### 4.4 The replace job

Replace is a **resumable job**, workspace scoped, modelled on the style-class jobs
(`StyleClassController::queueJob` / `showJob`), and needs `content.manage`. It is not promised to
finish within one request.

1. **Start (atomic).** In one transaction on the palette state row the server checks, then
   records: the source slot is configured and neither replacing nor reserved; the destination,
   and a separately chosen contrast destination, are configured and not themselves being
   replaced. It records the job and **reserves** every brand slot the job may write — the
   destination slot and, when a brand slot, the separately chosen contrast destination. Any check
   failing is a 409 naming the conflict; nothing is recorded.
2. **While running.** The source slot stays configured and keeps rendering, is hidden from new
   choices, and cannot be renamed, re-coloured, cleared or replaced (409). A **reserved** slot
   stays fully usable and may be renamed or re-coloured, but cannot be cleared or become the source
   of another replace (409) until every job reserving it completes or is cancelled. So Brand 1 →
   Brand 2 blocks Brand 2 → Accent until it ends; the reverse order is refused at start because
   Brand 2 is then a source being replaced.
3. **Rewrite.** The job walks the blocking sources. Each document is written through its source's
   conditional `persist()` (`BlockDocumentSource`: atomic, only while the document is at the
   revision it was read at), inside a fenced transaction (§4.3) that also asserts the job is
   still recorded under its id. A `false` from `persist()` — an editor saved, a publication landed —
   re-reads that document and re-applies the mapping, up to three attempts, then defers it to the
   next pass. Style classes are written through their repository with the same checks.
   - **Drafts, regions, layouts, saved sections, style classes:** rewritten in place, through
     their repositories' change events (which purge the pages that use them after commit).
   - **Current publications:** written through `PublishedEntriesSource`, which appends a new
     version and repins the publication to it — the existing append-and-repin model. The version
     is authored as the palette operation (actor = the user who started it, note "Replaced Gold
     dark with Accent"); no editorial approval is requested. The previous version stays as it was.
4. **Verify and clear.** When a pass rewrites nothing, the job takes the palette state row, asserts
   it is still recorded, bumps `generation`, runs the final blocking-usage scan, and — zero — in
   that same transaction clears the source slot's setting, removes the job and its reservations,
   and records the audit entry (slot, name, destination, contrast destination, counts per source,
   historical count). Non-zero → the transaction ends without clearing and another pass runs, at
   most five; after that the job stops as failed.
5. **Cache effects after commit.** `ThemeAppearanceChanged` fires only after the clearing
   transaction commits, purging rendered pages and refreshing open stages (§5.3).

**Failure.** A job that fails or is interrupted stays recorded: the slot remains configured and
rendering, its reservations hold, documents already rewritten keep their (valid) destination
tokens, and Appearance shows "Replacing Gold dark with Accent — 140 of 300 done" with **Resume**
and **Cancel**. Resume continues the same job from a fresh scan. A failure never leaves the slot
cleared while blocking references remain.

**Cancellation fences the worker.** Cancel removes the job and its reservations in a transaction
on the palette state row. Every write the worker makes (step 3) and its clear (step 4) first
asserts, under that row, that its job id is still recorded; after Cancel commits, that assertion
fails, so a worker already running — or resumed from a queue later — writes nothing more and
cannot clear the slot, even if the slot has since been edited or a new job started for it (a new
job has a new id). Cancel does not revert rewritten documents.

### 4.5 Normalisation on save

The fenced save path (§4.3) normalises **every** colour-token location a document holds: style
settings (instance, targets, parts, hover — the values `SettingsValidator` validates) **and**
token-typed content fields in block `data` whose token domain is `color` (found through the block
type's field definitions; these never pass through `SettingsValidator`). For each value naming a
brand token:

- **Source of an active job:** mapped to the job's `to` / `contrast_to`. A stale draft that still
  names the slot writes the destination.
- **Unconfigured slot:** accepted only if a **trusted basis** already holds that same value at
  that location; otherwise 422 "Gold dark isn't in the palette". A save racing a clear can
  therefore never add a fresh reference to a slot that was just cleared. The trusted basis is
  always loaded by the server, never taken from the request:
  - **Ordinary saves:** the document's current stored revision the save is based on.
  - **Restoration:** additionally, the historical version being restored, loaded server-side by
    its id from the entry's own retained versions. A restore may therefore bring back the
    unavailable references that version holds even when the current draft no longer does. The
    client names only which version to restore; it cannot supply old content to widen the basis.
  Once restored, the draft is the stored revision, so saving it again preserves those references
  under the ordinary rule.
- **Configured slot:** unchanged.

Restoration follows the same rules as any save: during an active replacement, a restored
reference to the job's source slot is mapped to its destination like any other write — only
references to unconfigured slots are preserved as unavailable.

The replace job applies the same mapping to the same locations, so Animated text's colours and
every other token field are rewritten along with style settings.

### 4.6 Implementation requirements

The implementation plan must include, before any fence code:

- **One lock order** covering the palette state row and the existing locks writers already take
  (entry drafts' `lock_version`, version-number reservation and publication repin, content-type,
  region, layout, saved-section and style-class rows). The palette state row is taken **first**,
  before any document, type or region lock, in every path that takes it — fenced saves, palette
  mutations, the job's writes and its clear — so no path can hold a document lock while waiting
  for the palette row in the opposite order.
- **An inventory of every writer** of block-bearing documents and style classes, each marked
  fenced/normalised or justified as unable to write a colour token: editor saves, publish,
  scheduled publish, restore, imports (site import, content import, seeders run against a live
  workspace), the authoring engine (`EngineContentWriter` / `EngineContentUpserter`), background
  rewrites (block-type migrations through `BackfillRunner`, the settings converter, style-class
  jobs), region and layout savers, saved sections, and the replace job itself. Each fenced writer
  gets a test that a palette mutation committed between its normalisation and its write is caught.

## 5. The admin

### 5.1 Appearance → Theme colors

- **Neutral** select adds **Custom**. Choosing it shows six labelled hex fields with swatches
  (Background, Surface, Surface 2, Text, Muted, Line), the **Dark mode base** select (hidden while
  colour mode is off), and **Reset to <family>**.
- **Brand colours**: one row per configured colour, in order — name, hex with swatch (the accent's
  `BrandColorField` picker, minus the families), a drag handle to reorder, and **Clear** (§4).
  **Add colour** below the rows appends an empty row; a row added but not yet saved has a plain
  remove button instead of Clear, since nothing references it. The section header shows the count
  against the limit ("2 of 3"); at the limit Add is disabled and says "This site allows 3 brand
  colours". A slot under replacement shows the job's progress with **Resume** / **Cancel** instead
  of its controls (§4.4); a reserved slot shows "reserved by the Gold dark replacement" and no
  Clear. With the limit at 0 the section is hidden.
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
  "limit": 3,
  "order": ["brand-2", "brand-1"],
  "slots": {
    "brand-1": {"name": "Gold dark", "hex": "#8a6a2a", "state": "replacing",
                "replacing": {"to": "accent", "to_label": "Accent",
                              "contrast_to": "accent-contrast", "contrast_to_label": "Accent — text"}},
    "brand-2": {"name": "Rose", "hex": "#c98a8a", "state": "configured", "reserved": false},
    "brand-3": {"name": "Teal", "state": "removed"}
  }
}
```

`state` is `configured`, `replacing` or `removed`; a never-issued id is absent. `reserved` marks a
configured slot a running job may write. `order` lists the configured and replacing slots in the
author's order. Labels are resolved server-side (author names for brand slots, the vocabulary's labels
otherwise) so a picker can say "being replaced by Accent" without a second lookup.

**Who can read it.** Today the endpoint requires `content.manage`, so a content, layout or
style-class editor without it gets 403. It takes the Typeface picker's read rule (`GET /fonts`):
any of `content.edit`, `content.manage`, `templates.manage`, `styles.manage`. The palette carries
only names, hexes, states and destination labels. Usage details (§4.1), the replace job (§4.4) and every
palette change stay under `content.manage`.

- Each colour button shows a **swatch** of the site value and its label; brand slots show the
  author's name ("Gold dark"), contrast tokens "Gold dark — text".
- **Brand colours are their own group**, headed "Brand colours", after the theme's colours, in
  `order`. Their text colours (`-contrast`) sit behind a **Text colours** disclosure inside the
  group, opened automatically when the stored value is one of them, so each brand colour adds one
  visible choice, not two. For a user with `content.manage`, the group header carries a **Manage
  brand colours** link to Appearance's Colours tab (`/appearance?tab=colours`, Appearance tabs
  spec §2); with no brand colours configured, the group shows only that link (or nothing, without
  `content.manage`).
- Slots whose `state` is `removed` or `replacing`, and never-issued ids, are **hidden from new
  choices**; reserved slots stay choosable. The group is hidden when the limit is 0.
- A stored reference to an unconfigured slot shows first, disabled: **Unavailable colour: Teal
  (removed)** — or **Brand 7** for an id never issued here (imported content) — with "no colour
  applied" and **Choose another** / **Clear**, kept until someone changes it. A
  slot under replacement is likewise hidden from new choices; a stored reference to it shows its
  swatch with "being replaced by Accent" (from `replacing.to_label`).
- Raw-hex `ColorField` content fields are unchanged: they store a literal value and are outside the
  token system.
- **Scoped palettes.** A block that re-skins its own subtree (a scoped accent or neutral family,
  `ThemeColors::scopedCss`) changes what `accent`, `surface`… resolve to inside it. Its pickers'
  swatches resolve from that scope when the editor knows it; otherwise the swatch is labelled
  **site default**. Brand slots are not affected by a scoped re-skin.

### 5.3 Freshness

Any change to the new keys — including which slots are configured, which decides the classes
§3.2 emits and the colours stylesheet (§3.4) — fires `ThemeAppearanceChanged` (purging rendered pages, as accent and
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

- Existing sites: no new key is set, the neutral is a family, no brand colour is configured — the
  site's **appearance CSS** (`themeColorsStyle()` output) is byte-identical to today's, no colours
  stylesheet is linked (§3.4), and the **rendered HTML** of its pages is byte-identical once the
  compiled-stylesheet URL is normalised (pinned by tests). That URL changes with
  `StyleCompiler::VERSION`, but nothing on an existing page uses what changed.
- The three fixed keys of the unreleased revision-4 build (`theme_brand_1` … `theme_brand_3`)
  exist only on development installs. A migration converts any it finds into `theme_brand_colors`,
  keeping ids 1–3, and deletes them.
- Themes without the new tokens in `theme.json` load (§3.1); the Doctor accepts them. A theme
  that maps them has the mapping ignored, with a Doctor warning.
- A site that overrode variables in `custom.css` keeps working (custom CSS loads last); the guide
  points to Custom instead.
- Import/export: the new keys travel with the other general settings; brand references in content
  travel as tokens.

## 8. Testing

- **Settings:** each key's validation (hex forms, JSON shape, name length, family names), 422s,
  normalisation; Custom lifecycle (first-entry prefill, values kept across a family switch, reset).
- **Brand colour list:** a new colour gets the next id above every id ever issued, so clearing
  `brand-3` and adding gives `brand-4`. Two concurrent adds get distinct ids. A save over the limit
  is 422. A save omitting a configured id is 422. Lowering the limit keeps existing colours
  rendering and refuses Add, and raising it allows Add. Reorder changes only `order`. A limit of 0
  hides the brand group and renders references as unavailable, and values outside 0–12 are
  clamped. An import adds and updates by id and keeps unnamed colours. The revision-4 key migration
  keeps ids 1–3.
- **Colours stylesheet:** emits the compiler's colour utilities for each configured id and none for
  removed ones, and its property table matches `StyleCompiler`'s. Renaming or re-colouring keeps
  the hash, and adding or clearing changes it. Nothing is linked with no colours. Under tenancy two
  workspaces get different stylesheets, with one per-theme artifact.
- **Rendering:** appearance CSS for a family site byte-identical; rendered HTML byte-identical
  with the compiled-stylesheet URL normalised;
  Custom light values; dark base; Tinted under Custom; brand light/dark/ink derivation; an
  unconfigured slot emits no variables; a value naming an unconfigured slot (or its contrast token) emits no class, for
  normal and hover, in blocks, targets, parts, regions and layouts; **cascade:** a style class
  supplying Accent with an instance value of unavailable Brand 1 resolves to Accent, an unavailable
  value in the class with an instance Accent stays Accent, and an unavailable value alone falls to
  the theme default; `token_class()` returns `''` for an unavailable brand token (Animated text's
  prefix and rotating colours as the fixture); preview override confined to the preview render.
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
- **Replace job:** rewrites drafts, regions, layouts, sections, classes and token-typed content
  fields (Animated text) in place; each current publication gets a new version, repinned, with the
  previous version unchanged; audit entry; clears only after a zero blocking scan.
  `ThemeAppearanceChanged` fires after the clearing commit, not before. Workspace scoping.
- **Fence, both orderings** (driven deterministically by pausing a save between normalise and
  write): *save first* — a save that read "no job" commits a Brand 1 reference, then the job starts
  and its scan rewrites it; *job first* — the job starts while the save is paused, the save's
  conditional update fails, it re-normalises and stores the destination. The same pair for an
  ordinary clear: save first refuses the clear (blocking usage); clear first makes the save
  re-normalise and get 422 for the fresh reference, while a save that only carries a reference the
  document already stored succeeds. And at final clear: a save paused across the job's clearing
  transaction cannot commit the old token afterwards. A save naming no brand token takes no fence.
  Three consecutive fence failures return 409. **Stale mapping:** a save submitting Brand 1 is
  paused after normalisation under a Brand 1 → Accent job (its normalised payload names no brand
  token); the job is cancelled and a Brand 1 → Brand 2 job started; the save resumes, its fence
  fails, and it re-normalises from the submitted payload and stores Brand 2, not Accent. Job writes
  whose payloads name no brand token are still fenced.
- **Concurrent document save:** an editor saving between the job's read and write makes
  `persist()` return false; the job re-reads and rewrites it; a publication landing mid-job is
  rewritten on retry.
- **Partial failure and cancellation:** a job forced to fail midway leaves the slot configured,
  reservations held, rewritten documents valid, no `ThemeAppearanceChanged`; Resume completes it.
  Cancel removes the job and reservations without reverting; a worker that resumes after Cancel
  writes nothing and does not clear — including after the slot was re-coloured, and after a new job
  for the same slot started (the old worker's id no longer matches). Rename / re-colour / clear /
  replace of a slot under replacement is 409.
- **Overlapping replacements:** with Brand 1 → Brand 2 running, starting Brand 2 → Accent, or
  clearing Brand 2, is 409; renaming Brand 2 is allowed; after the first job completes or is
  cancelled the second can start. With Brand 2 → Accent running, Brand 1 → Brand 2 is refused at
  start. A separately chosen brand contrast destination is reserved the same way. Two starts racing
  for conflicting slots: exactly one succeeds.
- **History and restoration:** replace a slot, then restore an older version that names it — the
  restore succeeds although the current draft no longer holds the reference; the restored page
  renders with no colour override for those properties and the picker shows the unavailable state;
  saving the restored draft afterwards (with and without unrelated edits) succeeds and keeps the
  reference. A save request that submits the same unavailable reference without a server-loaded
  basis (a plain draft save naming a version id it is not restoring, or a client-supplied old
  payload) gets 422. Restoring during an active replacement of that slot maps the reference to the
  destination.
- **Writer inventory:** for each fenced writer in §4.6, a mutation committed between normalisation
  and write is caught.
- **Permissions:** the style schema with only `content.edit`, only `content.manage`, only
  `templates.manage`, only `styles.manage` — each 200 with the palette; with none, 403. Usage
  and the replace job with each of the first, third and fourth alone — 403.
- **Admin:** Add colour (appends a row; disabled at the limit with its message; "2 of 3"), reorder,
  an unsaved row removed without Clear, the brand group in the pickers with its Text colours
  disclosure (opened when the stored value is a text colour), the **Manage brand colours** link
  (shown only with `content.manage`, targeting `/appearance?tab=colours`), "Unavailable colour: Teal
  (removed)" and "Brand 7"; Appearance form (Custom, dark base visibility, brand rows, clear dialog with blocking
  and historical groups, destination picker exclusions, contrast destination picker, job progress
  with Resume/Cancel, reserved-slot state, contrast rows), picker swatches and author names, removed
  and replacing slots hidden, reserved slots shown, "being replaced by <label>" from the response,
  unavailable state, scoped "site default" label.
- **Browser:** a block using Brand 1 paints the hex in light mode, the derived value in dark mode,
  and its contrast colour on it; a Custom palette paints the cream ground and Surface 2 band. A
  button whose **hover** text names an unconfigured slot keeps its normal text colour on hover, on
  keyboard focus and in the editor's forced hover preview; a heading whose text names an
  unconfigured slot keeps the colour it had without that setting.

## 9. Documentation and changelog

Appearance guide (Custom neutral, brand colours — adding, the limit, Clear — contrast checks), style
settings reference (the colour names, brand colours and the unavailable state), theming guide
(`brand-*` tokens and their defaults), the configuration reference (`THALLO_BRAND_COLORS_MAX`), the block library where colour lists appear, and an `[Unreleased]` changelog entry.
