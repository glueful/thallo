# Per-block typeface — design

**Status:** for review. **Date:** 2026-10-05.

## 1. Purpose

An author can choose the typeface of any text a block shows — a Heading, a Rich text, a card's title,
a button's label — from the site's own font library, in the block's Style tab or in a style class.
Typeface becomes part of the typography contract, beside Size, Weight and Line height, so blocks,
individual text targets and style classes all use the same setting. The site's uploaded fonts live
in one place, the font library, which also feeds the site-wide Text and Headings choice.

**Success:** an author sets the Scent Noir hero line in an uploaded script face and the body copy
beside it in Serif; renaming the script family changes nothing on the page; deleting it is explained
before it happens and leaves the page readable; the stage and the public page always agree.

**Out of scope for this release:** a per-breakpoint typeface (the contract keeps it possible, §4.1);
fonts loaded from third-party services; preloading block-level faces.

## 2. The font library

One library per workspace, managed in a **Typefaces** card under **Site › Appearance** (`content.manage`,
§4.8). Every
lookup, usage scan and resolution is scoped to the current workspace.

### 2.1 Identity

A reference is a **font ID**, never a display name or CSS. Two kinds exist, and validation accepts
exactly these:

- **Reserved built-in IDs:** `theme`, `serif`, `humanist`, `geometric`, `slab`, `mono`, `system`.
- **Uploaded-family IDs:** the family record's 12-character ID (`[A-Za-z0-9]{12}`), generated once
  and never changed.

`inherit` and `reset` are reserved and never IDs. A reserved name and an uploaded ID cannot collide
(no reserved name is 12 characters). Renaming a family changes only its display name.

### 2.2 Built-in families

Built-ins resolve to their real stacks, never wrapped in a generated family name:

- `serif`, `humanist`, `geometric`, `slab`, `mono`, `system` — the named stacks `ThemeDesign`
  already defines (for example `serif` is `"Iowan Old Style","Palatino Linotype","Book Antiqua",
  Georgia,serif`).
- `theme` — the theme's own face, from optional `theme.json` metadata (§2.2.1; the default theme:
  Figtree, then the system stack).

The Theme face's `@font-face` rules are **always declared**; whether to **preload** it stays a
separate decision (§3.6). Today `font_faces_style()` suppresses the declarations when the site's
text uses another face (`RenderContextExtension.php:2090`); that suppression moves to the preload
only, so a block set to Theme renders in the theme's face on a site whose body is Serif.

#### 2.2.1 Themes without the face metadata

The `theme.json` face declaration is **optional**. Existing themes keep working unchanged:

- **Ordinary rendering is untouched.** The metadata is read only to resolve an explicit **Theme**
  choice (the `t-font-theme` utility) and the inspector's faces line. A theme's own CSS, and every
  target with no Typeface setting, render exactly as before.
- **No declaration:** an explicit Theme choice resolves to the documented system stack
  (`ThemeDesign`'s `system` stack: `system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue",
  Arial, sans-serif`), and the inspector says "This theme declares no face; Theme uses the system
  stack."
- **Declared face files missing:** a face whose file is absent is not declared (the same existence
  check `font_faces_style()` uses today); the stack's next family renders. Nothing errors, and the
  inspector shows "Supplied by the theme" only for faces that exist.
- **A theme switch while an editor is open:** `t-font-theme` lives in the theme's compiled settings
  artifact, so a switch re-keys it with the appearance fingerprint. The open stage refreshes from one
  new snapshot (theme and library), as in §4.5, keeping the document and undo history; the inspector
  re-reads the Theme face line. Stored values never change — `theme` stays `theme`.

### 2.3 Uploaded families

A family has: its ID, a display name, a **fallback** (one of `sans-serif`, `serif`, `monospace`,
`cursive`, `system-ui`, chosen when the family is added; default `sans-serif`), and one or more
**faces**. A face is a `.woff2` file in the media library with its weight and style.

**Faces are read from the file.** On upload, the server reads the face's weight class and italic
flag, and for a variable font its weight axis range; those become the `@font-face` descriptors.
Labels are never trusted. The reader must handle the WOFF2 table directory, the Brotli-compressed
table data and the relevant OpenType tables (`OS/2`, `head`, `fvar`), and reject an unsupported file
with a clear reason ("Not a WOFF2 font", "Couldn't read this font's weight table"). **The dependency
that provides this is chosen and proven before implementation** (plan task 1): a reader that passes
the fixture set, including variable, italic and single-weight faces, and rejects malformed files.

The same face uploaded twice to one family is flagged.

**Stack:** the generated family, then the fallback's named stack, ending with its generic —
`"thallo-font-<id>", "Iowan Old Style", …, serif`. Families are tried in list order.

**Synthesis policy:** an uploaded family never has bold faked (`font-synthesis: style`); the browser
may slant text when there is no italic face. Built-ins keep the browser's default (`weight style`).
Because `font-synthesis` inherits, every resolved family sets it explicitly (§3.3).

### 2.4 Fallback cases

Three distinct cases:

1. **A requested weight the family doesn't supply.** The browser's font matching selects another face
   within the family (for 700, heavier first, then lighter). The target's stored Weight is never
   rewritten.
2. **Missing glyphs.** Characters the face lacks fall through, character by character, to the next
   family in the stack.
3. **Failed or pending load.** With `font-display: swap`, the fallback stack shows while the file
   loads, and stays if it fails.

### 2.5 Unreadable files

A file the reader cannot parse still becomes a face, so nothing disappears in the upgrade (§2.7).
This is **compatibility behaviour, not metadata**: it keeps the old declaration (`font-weight:
100 900`, normal style). Its faces are labelled **Unknown faces**, weight predictions are suppressed
for it (§4.2), and **Read again** is offered with the warning that success may change how the font
renders. A newly added file that cannot be read is refused instead (§4.6).

### 2.6 Lifecycle

- **Add:** name, fallback, at least one readable file.
- **Edit:** rename, change the fallback, add or remove files. An uploaded family keeps at least one.
- **Delete (soft):** first, a dialog lists every usage, grouped with links: blocks in entry drafts
  and published entries, the header and footer, layouts, saved sections, style classes, and the
  Appearance Text and Headings assignments. The family then leaves the pickers; its `@font-face`
  rules and utilities are no longer emitted; block references resolve to `inherit` (§3.3); an
  Appearance assignment falls back by the Text/Headings rules (§2.8). Its media files stay
  protected ("Used in: Font library (removed)"), so **Restore** brings it back whole.
- **Delete permanently:** only from the removed list, after the same usage check. It releases the
  media; remaining references become unknown.
- **Unknown references** (an ID that is not in the library, such as content imported from another
  site) resolve exactly as removed ones.
- A font file a family uses (current or removed) shows as used in "Font library" and cannot be
  deleted from the media library.

### 2.7 Upgrade from today's Custom faces

Custom folds into the library. `thallo:provision` runs a migration step that:

- creates one family per distinct uploaded file (Text and Headings referencing the same file share a
  family), reading its faces (§2.3; an unreadable file per §2.5);
- records that it has run, and migrates only **untouched** legacy assignments: an Appearance choice
  someone changed after the step is never restored by a later run; matching media IDs alone is not
  enough;
- preserves Text-only, Headings-only and both-upload configurations through the Appearance
  assignments, including headings following a Text-only upload;
- turns uploads saved while Custom is not selected into families without activating them.

**Deliberate rendering change:** today each upload is advertised as covering `100–900` with default
synthesis; after the upgrade it is declared with the faces actually in the file and `font-synthesis:
style`. Text whose requested weight the file doesn't supply is then drawn from the face the browser
selects rather than from the advertised file. The rendering proofs record the before/after
declarations — sources, families, weights, styles **and synthesis** — and the Upgrade Notes describe
the visible effect.

### 2.8 Appearance assignments

The site-wide **Typefaces** choice keeps its pairings (Sans, Editorial, Serif, Humanist, Geometric,
Slab, Mono, System). **Custom** now selects a **Text** family and a **Headings** family from the
library. The fallback rules are today's: no Text family (or a removed one) → the theme's face; no
Headings family (or a removed one) → Headings follow Text.

## 3. The setting and its rendering

### 3.1 The setting

**Typeface** (`typography.family`) joins the `typography` group, so every block target that offers
Typography gains it, and style classes carry it. Value kind: `{"type": "font", "value": "<id>"}`.
Validation checks the ID's **shape** only (§2.1), never existence — content stays valid after a
deletion or an import; existence affects rendering only.

**Non-responsive in this release**, stored as a single value like Radius. The contract keeps
widening open: a later responsive version reads a plain value as `base`, with no data migration.

### 3.2 Absent, reset, clear

- **Absent:** no declaration; style classes, the site-wide assignment and the theme apply. No target
  gets a default typeface.
- **Use theme default** is the existing **Reset to theme**: `{"type": "reset"}`, stopping resolution
  so lower style-class declarations are suppressed too, and emitting `t-font-reset` (`font-family:
  revert-layer; font-synthesis: revert-layer`). This returns the target to its **contextual default**:
  a heading may take Appearance's Headings face; an element without its own theme declaration may
  inherit an ancestor's authored typeface. **Theme** is the explicit way to ask for the theme's
  original stack.
- **Clear** removes the declaration, as for every other setting.

### 3.3 What a resolved value emits

The cascade resolves per property and per **typography target**: a block's text target and its parts
(a card's title, its text) each get their own utility, so they can use different families. Block
style classes do not reach a part's settings, as today (`BlockStyleEmitter.php:35`).

| Resolved value | `font-family` | `font-synthesis` |
|---|---|---|
| `serif`, `humanist`, `geometric`, `slab`, `mono`, `system` | the named stack, ending with its generic | `weight style` |
| `theme` | the theme's face stack from `theme.json` | `weight style` |
| an uploaded family | `"thallo-font-<id>"`, the fallback's named stack, its generic | `style` |
| a removed or unknown ID | `inherit` (utility `t-font-inherit`) | `inherit` |

The utility is `t-font-<id>`. The removed/unknown substitution happens in the renderer while it
resolves, so it applies whether the winning value came from the block or from a style class; the
stored ID is kept.

### 3.4 Where the rules live, and precedence

- **Built-in utilities** (including `theme`, whose stack the theme declares) go in the theme's
  compiled settings stylesheet, in `@layer settings`.
- **Uploaded families** are per workspace: a content-hashed `fonts-{hash}.css` holds every
  non-removed family's `@font-face` rules and its utilities in `@layer settings`, linked from the
  layout immediately after the settings stylesheet. The stage uses the same layout and link.
- **Site-wide assignments** keep writing `--font-body` and `--font-display`, consumed by the theme in
  `@layer theme`. Appearance also writes `--font-synthesis-body` and `--font-synthesis-display` for
  the assigned family's policy; the default theme applies them (`font-synthesis:
  var(--font-synthesis-body, weight style)`, and the display equivalent). A theme that ignores them
  keeps the browser default; the theme documentation says so.
- **Precedence:** a target's Typeface (in `@layer settings`) beats the theme's managed styling and the
  site-wide assignment whatever the selectors. The site's custom CSS is unlayered and keeps its
  current precedence over both.

### 3.5 The fonts artifact is immutable

- One **library snapshot** produces the utility decisions (including removed/unknown substitution),
  the stylesheet, its link and the cache fingerprint segment; they never mix snapshots.
- A hash names one version forever: a request for an old hash serves that version, never current
  CSS regenerated under the old URL.
- Publish-then-serve: the artifact is written before any HTML links it, so concurrent edits cannot
  produce a page referencing a stylesheet that doesn't exist. Old artifacts are retained like the
  compiled style artifacts (the newest three, and anything younger than a day).
- The hash joins the appearance fingerprint, so a library change re-keys cached pages.

### 3.6 Loading

Every face is declared; a visitor downloads only the faces the page renders. Preloading stays as
today: the Theme face only, and only when the site-wide Text uses it. Block-level faces are never
preloaded.

## 4. The inspector

### 4.1 The Typeface control

In the Style tab's **Typography** group, on each typography target (the target picker already chooses
the part). Two groups: **Built-in** (Theme, Serif, Humanist, Geometric, Slab, Mono, System) and **Your
fonts** (uploaded families by name), each option set in its own face. **Reset to theme** ("Returns
this target to its contextual default") and **Clear** behave as for every setting; **Theme** is
offered as the explicit original stack.

### 4.2 Faces and weight

Under the control, the chosen family's faces: "Faces: 400, 700, 400 italic", "300–900 variable",
**Unknown faces** (§2.5), "Provided by the visitor's device" for device built-ins, and "Supplied by
the theme" for Theme. Choosing a family never changes Weight; the Weight control marks weights the
family doesn't supply ("Bold — not in this family") and they stay selectable. No predictions for a
family with unknown faces.

**Computed typography (new bridge work).** The stage reports the selected block-and-target's
computed `font-weight` and `font-style` to the inspector — new messages in the preview bridge, keyed
to the selected block ID and target, with stale responses (an older selection or render) ignored.
Computed weight is the **requested** weight, not proof of the rendered face, so the notice reads:
"700 isn't supplied by Brand script; the browser will select an available face." For italic with no
italic face: "There's no italic face; the browser may slant the text."

### 4.3 The style-class editor

The same control, with **context-free notices**: it shows the family's faces but never claims which
face renders (there is no stage target). It keeps its existing **Use theme default**, **Remove** and
"Not set in this class" states.

### 4.4 Removed and unknown values

The stored value shows first, disabled: **Removed typeface: Brand script** or **Unknown typeface**
(with its ID), with a line that it renders inheriting the enclosing font, and **Choose another**,
**Clear**, and — for someone who can manage Appearance — a link to restore it. The value is kept
until someone changes it.

### 4.5 Library changes while editing

A removal or restoration changes markup as well as CSS (`t-font-<id>` ↔ `t-font-inherit`), so the
stage refreshes from **one new library snapshot** — markup and stylesheet together — keeping the
document and the undo history. Proven with the affected family selected, for both removal and
restoration.

### 4.6 The Typefaces card

- **Built-in** families first, read-only, with a specimen line each.
- **Your fonts:** name, specimen, faces, fallback, usage count.
- **Add family:** name, fallback, then one or more `.woff2` files; each shows the faces read from it
  as it uploads; an unreadable file is refused with its reason; a duplicate face is flagged.
- **Edit**, **Delete** (the usage dialog, distinguishing block references, which inherit from their
  parent, from Appearance assignments, which follow the Text/Headings fallback rules), and a
  collapsed **Removed** list with **Restore** and **Delete permanently**.
- **Read again** on a family with unknown faces, warning that success may change rendering.

### 4.7 Appearance Text and Headings

Under **Custom**, **Text** and **Headings** pickers choose from the library; **Add a font…** inside
either opens the Add family dialog and selects the new family on save.

### 4.8 Access

Every editor that shows Typeface can read the library for its picker. The **picker read** (names,
faces, specimen file URLs, the Theme face line) is allowed with **any** of `content.edit`,
`content.manage`, `templates.manage` (layout editing) or `styles.manage` (style-class editing); none of
these implies another, so a style-class editor without `content.edit` can still pick a typeface.

**Usage details** (which entries, layouts, sections, classes and assignments use a family) are a
separate response, never part of the picker read, and need `content.manage`. Adding, editing,
deleting, restoring and Read again also need `content.manage`, the permission that guards
Site › Appearance's settings today.

## 5. Testing

- **Reader:** the chosen dependency against fixtures — variable, italic, single-weight, malformed,
  non-WOFF2.
- **Validation:** reserved IDs and 12-character IDs accepted; anything else refused; existence not
  required.
- **Cascade and rendering:** two independently styled text targets in one block; a built-in child
  inside an uploaded-font parent (its own stack and `weight style`); switching uploaded → built-in,
  uploaded → removed (inherits family and synthesis); a style class's removed family emits `inherit`
  over an older class's family; a reset on a heading takes Appearance's Headings face; a reset on an
  element with no theme declaration inherits its ancestor's authored typeface; on a Serif-bodied site
  a block set to Theme renders the theme face; a block's typeface beats the site-wide Headings face;
  custom CSS still wins.
- **Artifact:** one snapshot drives link, CSS and fingerprint; an old hash serves its own version;
  publish-before-link under concurrent edits; retention.
- **Stage parity:** the stage and the public page carry the same artifact; removal and restoration
  while the family is selected refresh markup and stylesheet together, keeping undo.
- **Upgrade:** repeatable; untouched-only; shared file → one family; Text-only, Headings-only, both,
  unselected uploads; unreadable file → compatibility face; before/after declarations recorded,
  including synthesis.
- **Lifecycle:** usage scan across every listed kind, workspace-scoped; soft delete protects media;
  restore; permanent delete releases media and turns references unknown.
- **Access:** the picker read succeeds for a user holding only `content.edit`, only `content.manage`,
  only `templates.manage`, and only `styles.manage` (each without `content.edit` where applicable),
  and fails with none of them; the usage response and every mutation fail without `content.manage`;
  the picker response carries no usage details.
- **Themes without the metadata:** a custom theme with no face declaration renders public pages and
  the stage exactly as before for unset targets, and an explicit Theme choice resolves to the system
  stack on both; a declared face whose file is missing is not declared; a theme switch while the
  stage is open refreshes markup and stylesheet from one snapshot, keeping undo.
- **Inspector:** grouping and specimens; faces line per kind; Weight marks; computed-typography
  messages keyed and stale-safe; notice wording; context-free notices in the style-class editor;
  removed/unknown states.

## 6. Documentation and changelog

The style settings reference (Typeface row, the `font` value kind, `t-font-*`, reset semantics), the
appearance guide (the Typefaces card, Custom from the library), the theme guide (`theme.json` face
stack, the synthesis tokens), the configuration and upgrade notes (the deliberate rendering change of
§2.7), and a changelog entry with each change.
