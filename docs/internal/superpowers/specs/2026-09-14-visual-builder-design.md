# Thallo visual builder — design

Status: design approved in discussion (2026-09-14), consolidated here for review. Owner: Michael
Tawiah Sowah. Supersedes the styling parts of `2026-07-03-visual-canvas-design.md` §9 ("not an
Elementor-style freeform builder") by replacing freeform styling with a closed, typed style
contract; every other canvas spec remains in force and this design builds on it. Charter:
`docs/internal/DISTRIBUTION.md`. Website plan: `docs/internal/plans/2026-09-11-website-and-docs.md`.

## 0. Summary and invariants

Thallo pages store semantic, typed design settings. Themes implement a platform vocabulary. The
renderer compiles settings into presentation classes. Content stays portable across themes
because pages describe design intent, not CSS.

The builder is the next versions of the existing Design view, built in core, the render pack and
the admin SPA. It is not a package: packs are always-loaded library modules with no admin-UI
extension point, and editing pages is the product, not a capability.

Invariants, each named once and used verbatim throughout:

1. **The block tree is the source of truth.** Every editor action is a tree operation; the DOM
   supplies geometry only.
2. **Twig is the only renderer.** The canvas shows real theme output in an iframe.
3. **Closed vocabulary.** Every managed style value is typed and validated; there is no raw CSS in
   the managed model. Custom CSS stays the unlayered escape hatch.
4. **Thallo owns token names; themes own values.**
5. **Breakpoint-first cascade** (§1.6): exact-breakpoint declarations beat inherited ones across
   layers; layer precedence breaks ties; then fall back to the nearest smaller breakpoint.
6. **Implicit theme defaults**: the compiler emits a managed class only where the resolved managed
   cascade establishes a value; absence at every breakpoint means the theme's own rules.
7. **Named style targets**: every capability lands on a target the template declares.
8. **One effective value per property and breakpoint**, emitted so HTML class order never decides.
9. **Three revisions**: local document, accepted working copy, displayed stage.
10. **Resolver-driven detach** preserves every effective value at every breakpoint.
11. **Whole-candidate-tree legality** before any structural commit.
12. **Envelope dependency resolution**: nothing crosses a site boundary by name alone.

### 0.1 Versions and generations

| counter | scope | changes when | used for |
|---|---|---|---|
| `settings_schema_version` | stored settings | the settings representation changes | conversion, import |
| block schema version | one block type | its data schema changes | block migration (implicit today in `block_type_migrations` rows; no column) |
| `format_version` | envelope | the envelope protocol changes | paste, import, export |
| vocabulary schema version | platform | the baseline contract changes | theme validation, artifact hash |
| compiler version | renderer | CSS emission changes | artifact hash |
| class `version` | one style class | that class is saved | optimistic concurrency |
| site style generation | site | any style class is saved | cache and editor invalidation |
| document revision (local, accepted, displayed) | editing session | a working document is accepted or shown | apply protocol |
| converter version | migration tool | conversion semantics change | decisions-file validity |

What exists today and is reused: the iframe stage at `/_preview/{token}?canvas=1`, the
`thallo-preview-block` wrappers, the in-iframe toolbar and bridge, `editable_text` inline editing,
the outline, the ephemeral apply with its working-copy stash (its request and response grow the
§3.5 revision fields; today they carry only a token, fields and a timestamp), the debounced
scheduler, and the regions editor. Block fields render inline in each block card today; there
is no per-block inspector, so §3.4's tabs are new block-level UI, not a widening of the page
tabs. What is missing and this design adds: a universal style layer, responsive values,
undo/redo, a fragment renderer, cross-container structure editing, global styles, and composition.

## 1. The style contract

### 1.1 Value kinds

A managed value is a typed object. Each setting definition declares the kinds it accepts.

| kind | shape | meaning |
|---|---|---|
| `token` | `{type:"token", value:"spacing.lg"}` | a platform vocabulary reference |
| `choice` | `{type:"choice", value:"hidden"}` | a closed enum |
| `identifier` | `{type:"identifier", value:"pricing"}` | a validated slug |
| `reset` | `{type:"reset"}` | ignore lower-precedence managed declarations for this property at the applicable scope and expose the theme's computed rules; for a responsive property the scope is the breakpoint, for a non-responsive property it is the property itself |
| `literal` | reserved | accepted by the type grammar, rejected by validation until a schema version admits it |

`reset` is accepted by every managed style property. Deleting an override (returning to the
class result) and resetting to the theme are different actions (§4.4).

### 1.2 Document shape

Input may omit `settings`. Normalised stored blocks always carry it:

```json
{ "id": "…", "type": "heading", "data": {…}, "settings": {} }
```

with `style`, `classes` and `advanced` present only when set. `settings.classes` is an **ordered
list** of reusable style class ids and is document state, never sorted. Later classes override
earlier ones for the properties they declare. `settings.advanced.css_classes` is the hook for
external CSS and takes no part in the managed cascade.

Blocks are reconstructed everywhere through one `Block` value object (`id, type, data, settings,
children`) so no path can forget `settings`. A behavioural completeness test drives every
reconstruction path (duplicate, move, restore, import, region save, preview apply, projections,
the API) with ordered class ids, sparse breakpoint maps, resets and nested blocks, and asserts
they survive.

### 1.3 Style groups and properties

Capabilities and targets name exact property paths; a group name is shorthand for all of its
properties.

| group | properties | kinds | responsive in v1 |
|---|---|---|---|
| `spacing` | `padding.{top,right,bottom,left}`, `margin.{top,bottom}` | token, reset | yes |
| `width` | `width` (`narrow, content, container, full`) | token, reset | yes |
| `alignment` | `alignment.text`, `alignment.content`, `alignment.self` | choice (`start, center, end`), reset | yes |
| `typography` | `size` (token), `weight` (choice `regular, medium, semibold, bold`) | as listed, reset | yes |
| `visibility` | `visibility` (`visible, hidden`) | choice, reset | yes |
| `shadow` | `shadow` | token, reset | yes |
| `radius` | `radius` | token, reset | no (scope, not meaning) |
| `colors` | `surface`, `text`, `border` | token, reset | no (scope, not meaning) |
| `border` | `width` (`none, thin, thick`), `style` (`solid, dashed`) | choice, reset | no |
| `marker` | `radius`, `shadow` | token, reset | as `radius` and `shadow`: no, yes |
| `tabs` | `bar_radius`, `tab_radius` | token, reset | as `radius`: no |
| `border` | + `sides` (`all, top, right, bottom, left`) | choice, reset | no |
| `backdrop` | `colors.surface_opacity` (`100`–`50`), `backdrop.blur` (`none, sm, md, lg`) | choice, reset | no |

**Amended 2026-09-19 — `marker`.** A block's marker — a feature's icon chip or number badge — has
corners and a shadow of its own, set in the Style tab under **Marker**. They are their own paths
(`marker.radius`, `marker.shadow`) because `radius` and `shadow` are the card's and one path holds
one value; they mirror those two in kind, token domain and responsiveness, compile to the same
declarations under their own class names (`t-mradius-*`, `t-mshadow-*`), and land on an optional
`marker` target. The schema moves to 4 and the compiler to 5. The marker's colours and size stay
the block's own Content fields for now: moving them is a data-to-settings move, and a separate one.

**Amended 2026-09-19 — `tabs`.** A tabs block's strip has corners of its own, set in the Style tab
under **Tabs**: the bar's (`tabs.bar_radius`, on a `bar` target — the list) and the tab's
(`tabs.tab_radius`, on an optional `tab` target — every label, since the active one is whichever
radio is checked, in CSS). Their own paths because the block's `radius` is the panels area's; both
mirror `radius` and compile to `border-radius` under their own class names (`t-barradius-*`,
`t-tabradius-*`). The schema moves to 5 and the compiler to 6. The strip's variant and colours stay
the block's own fields: the same data-to-settings move, and as separate.

**Amended 2026-09-19 — modifiers, and a region's style.** Three properties adjust what others
declare. `border.sides` joins the `border` group, so every block with a border has it;
`colors.surface_opacity` and `backdrop.blur` are the `backdrop` group, which a block or region opts
into (the container does). Sides and opacity are *modifiers*: later rules in the sheet that adjust
an earlier utility's declarations — one side is the width's four less three; the opacity repaints
the background as a `color-mix` of `--t-surface`, which the background utility now names as well as
painting (its own declared value is unchanged, so an opaque background computes as it always did).
With no colour chosen the mix falls back to `--t-surface-default`, which a theme sets on an element
it paints, else to nothing. Both variables are registered non-inheriting, so a child given only an
opacity never mixes its parent's colour. A modifier's reset rule is empty — reverting the
declarations it shares would undo the utility beside it — but is written, since every class the
emitter can write has a rule. The schema moves to 6 and the compiler to 7.

The chrome regions take the same style record in `settings.style`, validated against
`RegionStyle` (contracts): spacing, shadow, radius, colours, border, backdrop — not visibility (a
page's presentation hides chrome), layout or typography; no style classes, no Advanced fields. Two
targets: `root`, the bar, and `inner`, where a theme pads and so where padding lands. Templates
emit through `region_style_classes(slug, target, settings?)`; the third argument is for the admin's
chrome preview, which renders posted settings.

Alignment is typed by meaning. `alignment.text` is `text-align` on a text target.
`alignment.content` places a row target's children horizontally (`justify-content` on a
horizontal flex row only). `alignment.self` places a box target within its parent through auto
margins. `width` is sizing only: `narrow`, `content`, `container` set `max-width`; `full` sets
`max-width: none; width: 100%`; nothing centres; combine with `alignment.self`. Inside a flex or
grid track the max-width applies to the box. `visibility.hidden` removes the target from layout at
that breakpoint (`display: none`); `visible` at a larger breakpoint compiles to `display:
revert-layer`.

### 1.4 Advanced

`settings.advanced`: `anchor` (identifier, unique per rendered page), `css_classes` (validated
list), `attributes` (allowlisted `data-*` names with string values; the whole `data-thallo-*`
prefix is reserved and rejected), `accessibility.label`. `accessibility.role` is out of v1.

Anchor uniqueness is page-wide, including header and footer regions. Fresh block ids do not
make copied anchors unique, so every operation that copies blocks (duplicate, preset insertion,
saved-section insertion, paste) runs collision handling: a colliding anchor is renamed with a
numeric suffix, fragment links inside the copied subtree that pointed at the old anchor are
remapped to the new one, links outside the subtree are untouched, and the rename is shown to the
author as a diagnostic before commit.

### 1.5 Responsiveness

A responsive property holds a sparse map over `base`, `md`, `lg`; any subset is valid.
Thresholds are a platform contract, not a theme choice: `md` from 768px, `lg` from 1024px. The
editor previews at 390, 768 and 1280 so each range is exercised. The active breakpoint is editor
state (§3.4); the viewport width is presentation.

### 1.6 The breakpoint-first cascade

Managed layers in rising precedence are each reusable class in list order, then the instance.
The theme default is not a declaration in the resolver: it is the fallback outside the managed
declarations, exposed by the browser through `@layer theme` (and `revert-layer`) when no managed
declaration resolves or when reset terminates resolution. The resolver therefore returns either
a managed value or the sentinel `theme-default`, never a concrete theme value, because neither
runtime can know what variant-specific theme CSS computes. For target breakpoint B:

```
for bp in [B, …, base]:
    declarations = every managed layer's declaration made exactly at bp
    if declarations is not empty:
        take the highest-precedence layer's declaration
        if it is reset: effective = theme-default; stop
        effective = its value; stop
effective = theme-default
```

Consequence, stated as a product rule: a lower-breakpoint declaration in a higher-precedence
layer does not override an exact declaration at a larger breakpoint in a lower-precedence layer.
An instance `base` of `sm` under a class with `md: xl` yields `sm` on mobile and `xl` from `md`
up. "Small everywhere" sets `md` too; the editor offers "apply to all breakpoints". A reset
terminates resolution at that breakpoint (Class A `md: xl`, Class B `md: lg`, instance `md:
reset` gives the theme default at `md`) and a later breakpoint's explicit declaration still
applies. Pinned in tests (`resolver-fixtures/v1/*.json`, §3.3):

| reusable class | instance | base | md |
|---|---|---|---|
| base lg; md xl | base sm | sm | xl |
| base lg; md xl | md sm | lg | sm |
| base lg; md xl | base sm; md sm | sm | sm |
| base lg; md xl | md reset | lg | theme md default |

The compiler emits one managed class per property and breakpoint where the resolved managed
cascade establishes a value, a `revert-layer` class where it resolves to reset, and nothing where
no layer declares anything at that or any smaller breakpoint. External CSS (custom.css,
`css_classes`) sits outside this guarantee by design.

### 1.7 Capabilities and targets

Each block type declares `style_capabilities` (property paths or groups) and `style_targets`: the
named targets its template exposes, each with a layout kind (`text`, `row`, `stack`, `box`),
optional flag, and the capability-to-target mapping. Advanced capabilities are targetable the
same way and each has exactly one owner. Validation rejects a setting outside a block's
capabilities and a capability mapped to a target of the wrong kind (`alignment.text` needs a
text target; `alignment.content` a row target). A setting on an absent optional target is valid
and dormant.

Independently styled parts of one block (animated text's prefix, rotating text and suffix
colours; a carousel's transition speed) are block semantics, not settings: they stay in `data`
as fields of kind `token` or `choice` drawn from the same vocabulary, and the template applies
them through `token_class(property, value)`, which emits the same utilities the compiler uses.
They take no part in `settings.classes` or the cascade. Raw hex and numeric presentation fields
are retired under §7.2.

Proof blocks: heading (one text root: spacing, alignment.text, typography, colors.text,
visibility), button (root row: spacing, alignment.content, anchor, attributes; control: radius,
colors, typography, accessibility.label), columns with nested blocks (independent nested
styling, width inside tracks), hero with and without media (optional target), animated text
(per-part token fields in data).

### 1.8 Conversion of existing style fields

No compatibility layer (§7). Rule of thumb: a value that changes what a component *is* stays in
`data` (hero `background` treatments, button `variant` and `size`, columns ratio); a value that
changes how the selected component is *styled* moves to `settings`.

## 2. Vocabulary and delivery

### 2.1 Baseline vocabulary (platform-owned names)

- `spacing`: `none, xs, sm, md, lg, xl, 2xl, 3xl`
- `width`: `narrow, content, container, full`
- `radius`: `none, sm, md, lg, full`
- `color`: `background, surface, surface-2, text, muted, line, accent, accent-contrast, transparent`
- `shadow`: `none, xs, sm, md, lg, xl`
- `typography.size`: `xs, sm, md, lg, xl, 2xl, 3xl`

Scales are ordinal only (`xs < sm < … < 3xl`); Thallo promises no pixel values or ratios.
Extensions are out of v1: a document references baseline names only, so no theme can lack a
referenced token.

### 2.2 Theme mapping

`theme.json` gains `vocabulary`, mapping every baseline name to a CSS value, which may be a
`var()` reference to theme-private variables (this is how the corners and ground design settings
keep re-mapping live). The compiled `--t-*` custom properties are the contract; theme-private
variables are implementation details. A theme missing any baseline name fails validation at
load, in the shipped-theme test, on theme switch and in `thallo:doctor`.

`theme.json` also gains a `stylesheets` manifest. Thallo delivers all selector-bearing theme CSS
inside `@layer theme` by building a layered theme artifact from that manifest; theme source files
may not contain unmanaged `@import` or other stylesheet-level constructs the builder cannot place,
and validation and fingerprinting apply to the served output. `!important` is forbidden in theme
declarations on managed properties of styling targets.

### 2.3 Layers and order

A small external stylesheet loaded first declares `@layer theme, settings;`. Theme artifact:
`@layer theme`. Compiled style artifact: `@layer settings`. The theme colours block is variables
only (a test asserts it emits no selectors beyond `:root` and the dark root). Custom CSS is
unlayered and last; it takes precedence over normal managed declarations, which is its job.
Invariant: nothing after the compiled style artifact may emit selector rules except custom CSS.

### 2.4 The compiled style artifact

A pure function of the theme vocabulary, the vocabulary schema version and the compiler version,
hashed from those three. Contents: the `--t-*` block; one utility per property, token and
breakpoint; one `revert-layer` utility per property and breakpoint; source order base, then md,
then lg (later breakpoint rules win when several min-width ranges match; order within a
breakpoint is deterministic and carries no precedence). The compiler never emits `!important`
or inline styles. Compiled at provision and before a theme switch activates; a failed compile
blocks the switch.

Lifecycle: publish the artifact, activate, purge rendered pages. Previous artifacts are retained
(last three, minimum 24 hours) so HTML already in browsers can still fetch its stylesheet. The
artifact hash joins the render cache's appearance fingerprint, so an in-flight render cannot
repopulate the cache under the new key with old HTML. Recompiling the artifact and switching or updating a theme purge
the render page cache through the existing tag. A style-class save does **not** rebuild the
compiled style artifact: classes contain only baseline vocabulary the artifact already
represents. It increments the site style generation (§4.3), which is a separate invalidation
path: the artifact is unchanged, the page HTML's classes may change, and rendered-page caches are
purged.

### 2.5 Template helpers and lint

`style_classes(target)` returns the resolved utility classes for a target; `style_attrs(target)`
returns only the attributes that target owns, escaped centrally. The shipped-templates lint gate
checks: every declared target appears in the template; no undeclared target is used; every
capability maps to a valid target of the right kind; each advanced capability has one owner; no
template emits a `style=` attribute; theme sheets contain no unmanaged `@import`; no theme
declaration on a managed property of a styling target carries `!important` (the rule is
property-aware: `!important` elsewhere in a theme is not the lint's concern). The canvas
wrapper is unchanged (`display: contents`, outside the block root).

### 2.6 Browser floor and proofs

The public-site floor is derived from every required feature. `color-mix()` (already required by
the theme) sets it: Chrome 111, Firefox 113, Safari 16.2; cascade layers and `revert-layer` are
older than that. Computed-style proofs (the §1.6 table against the button's real theme CSS, the
secondary-variant reset, a responsive padding reset with base override, md reset and lg override)
run in Chromium, Firefox and WebKit through Playwright; the tested matrix is stated next to the
declared floor.

The managed settings system never emits inline styles. Every style source in the current
checkout has a disposition in the same release:

| source | today | disposition |
|---|---|---|
| heading `color` | `style="color:…"` from a hex field | field retired; `colors.text` setting |
| animated text per-part colours | `style="color:…"` from hex fields | `token` fields in data via `token_class()` (§1.7) |
| image | inline sizing style | converted to classes from typed fields |
| container background colour, overlay colour and opacity, max width, padding, margin, radius, border, shadow | inline `--container-*` variables and freeform values | settings properties; overlay becomes a choice (`none, light, dark`) plus an opacity step |
| container background image | inline `background-image: url()` | rendered as a positioned `<img>` layer with srcset and lazy loading, like the existing video layer |
| carousel `transition_duration` | inline `--carousel-duration` from a number | `choice` (`slow, normal, fast`) mapped to classes |
| style block scope | inline `<style>` of variables from `theme_style_scope()` | permitted: variables only, listed |
| theme colours and design tokens | inline `<style>` of variables | permitted: variables only, listed |
| `font_faces_style()` | inline `<style>` of `@font-face` | permitted: no selectors, listed |
| storefront stylesheet (`shop_styles_url()`) and any package stylesheet | separate `<link>` | delivered inside `@layer theme` through the render contribution registry |

The lint gate forbids `style=` attributes and selector-bearing inline style elements; the three
permitted variable-only or `@font-face` elements are the enumerated exceptions.

## 3. Editing architecture

### 3.1 Operations

History records intent. Each operation carries `op_id`, `transaction_id`, timestamp, session id,
`from` and `to`, and is fully reversible on its own: removed and duplicated subtrees travel with
the op, ids are allocated once and reused on redo, absent is distinct from null, moves record
`{parent, slot, index}` for both ends, advanced settings have their own path. Set: `SetField`,
`SetSetting`, `SetAdvanced`, `ApplyStyleClass`, `RemoveStyleClass`, `ReorderStyleClasses`,
`DetachStyleClass`, `InsertBlock`, `InsertBlocks`, `RemoveBlock`, `MoveBlock`, `DuplicateBlock`,
`SetPageSettings` (the persisted page fields; editor state never enters history). One pure
applier per operation; the existing pure list operations become appliers. `InsertBlocks` is
first-class because a composition is intrinsically one insertion intent; multi-selection remains
a transaction of primitives.

### 3.2 Transactions and history

A committed transaction is normalised to the minimal semantic delta (one `SetSetting` sm→xl,
one `SetField` "Hello"→"Hello world"). Boundaries: a slider drag is one transaction from pointer
down to up; typing and inline editing commit after 500 ms idle or blur; IME composition never
commits mid-composition; save and publish flush pending edits first; undo settles the active
transaction; a cancelled interaction restores its start with no entry; new edits clear redo,
replay does not. History is bounded by count (200) and bytes. Saved position is tracked apart
from current position, so undoing past a save makes the document dirty again.

### 3.3 One resolver, two runtimes

The breakpoint-first resolver is a pure function in PHP and TypeScript with one versioned
fixture set (`resolver-fixtures/v1/*.json`) that both must reproduce byte-equivalently; drift is
a compatibility bug. The compiler (class emission) is server-only; the client never learns class
names.

### 3.4 Generated tabs

Content: the data fields. Style: controls from `style_capabilities`, grouped as spacing, size,
typography, colours, effects (radius, shadow, border), visibility; a token picks from an ordinal
scale shown as a segmented control with the theme's value previewed; a choice is a segmented
control; an identifier is validated text. Each responsive property shows a breakpoint indicator
bound to the **active breakpoint** (editor state, synced to but not inferred from the viewport),
and per breakpoint the effective value, its source, and a state of `explicit`, `inherited`,
`theme-default` or `reset`, with reset and "apply to all breakpoints" one click each. Advanced:
anchor, the ordered **Style classes** list (drag to reorder), **CSS classes**, attributes, label.
The two class lists are never both called "Classes".

**Amended 2026-09-19 — the Content tab is the block's whole form.** "The data fields" means all of
them, edited as the main Content tab edits them. As first built the Block tab was a lesser form: a
blocks-typed field — a container's content, a hero's links, an accordion's items — was one line
("links: 2 blocks") with an Add button, and a prose body was absent, left to the stage alone. An
author who selected a block could not finish editing it there.

- **A blocks-typed field is its list.** The same list and cards, in the root blocks field's own
  context: that field stays the tree's single writer, so a child edited, reordered, duplicated or
  removed from the panel is the operation it always was, with the same legality, history and undo.
  The root field hands its context out and the panel provides it again — the components are the
  field's own, never a second implementation. The list's Add arms the Blocks tab at that position,
  as the inspector's Add did. Until the owning field has registered (it loads asynchronously) the
  tab falls back to the summary.
- **A prose body has its editor here too**, the same chromeless one with its `/` menu. The stage
  stays the primary way in; the panel is for what is awkward in place — a block hidden at the
  breakpoint being viewed, a narrow column, a long body.
- **One text has one owner at a time.** While the block is being edited on the stage the panel's
  editor is read-only and says so ("Editing on the stage — press Esc there to finish"), and shows
  what is typed there as it arrives; it is writable again when the session ends. Nothing is handed
  across in the other direction: the panel's editor writes every change as it is made, so there is
  nothing to flush, and the double-click that starts a stage session has already moved the
  browser's focus out of the panel. A session on another block locks nothing.

### 3.5 Apply, revisions and fragments

The apply endpoint keeps writing the session working copy. Three revisions are distinguished:
local document, accepted working copy, displayed stage. Requests carry the base revision;
responses name the revision rendered, the stage baseline the patch expects, and the site style
generation (§4.3). Stale responses are dropped; a baseline mismatch refreshes from accepted
state; a style-generation change forces re-resolution before inherited values are trusted.
Apply validation failure is never presented as accepted; fragment render failure after a
successful apply recovers by refresh.

The server is authoritative for render roots. An operation yields affected blocks and the
render-scope resolver yields minimal roots:

| operation | root |
|---|---|
| `SetField`, `SetSetting`, `SetAdvanced`, class operations | self, lifted to the parent when the parent renders child data inline |
| `InsertBlock`, `InsertBlocks`, `RemoveBlock`, `DuplicateBlock` | parent |
| `MoveBlock` | old parent and new parent |

Ancestors absorb descendants so swaps never overlap. A block type whose template renders its
children's data outside the children's wrappers declares `renders_children_inline` (today:
accordion, tabs, stepper, gallery, pricing table, carousel), which lifts a child's root to that
parent. Blocks that call `claim_priority_image()` (hero, image, blog posts) depend on page
order. After ancestor lifting, every proposed render root and its descendants are inspected; if
rendering any of them invokes page-order-dependent behaviour, the whole-page path is used, and
lifting repeats until the roots are safe. Tested with a container padding change that re-renders
an image inside it while an earlier priority image exists elsewhere on the page. The whole-page path
is also forced by: a root-level structural change, an entry field outside block wrappers, any
block on the page declaring a page dependency, a fragment needing an asset not yet loaded, or a
template not verified for fragments. In v1 only the default theme's entry template is verified,
by a test that renders every fixture block-by-block and whole-page and diffs them, including
nested tabs, a pricing table and a page with several image-bearing blocks; the verification
record carries the participating templates' hashes and is invalidated when any changes. The
bridge keeps its protections during typing and dragging, validates targets before swapping,
restores selection and handles runtime teardown after.

Debounce defaults: 150 ms for settings and structure, 800 ms for text, with a maximum wait.
The fragment system ships complete but disabled behind a flag. Activation requires, measured on
the thallo.dev homepage at equal debounce for both paths, input-to-paint and request-to-paint
median under 300 ms, p95 under 600 ms, median at most half the whole-page median, with fallback
frequency recorded. Failure of this gate does not block the builder's functional release; the
disabled subsystem still has implementation and maintenance cost.

State transitions of the three revisions (L local, A accepted, D displayed):

| event | L | A | D |
|---|---|---|---|
| edit committed | advances | unchanged | unchanged |
| apply accepted (revision r) | unchanged | r | unchanged until patched |
| patch applied for r on baseline D | unchanged | unchanged | r |
| apply validation failure | unchanged (edit stays local, reported) | unchanged | unchanged |
| stale response (r < A) | unchanged | unchanged | unchanged, response dropped |
| patch failure or baseline mismatch | unchanged | unchanged | refresh to A |
| save succeeds for submitted revision r | unchanged; saved position = r (not the current L) | unchanged | unchanged |
| save fails | unchanged; saved position unchanged | unchanged | unchanged |
| token renewal | unchanged | unchanged | unchanged, next apply uses the new token |

Invariant: class mutations and style-generation increments commit atomically. A render obtains a
consistent dependency snapshot, or verifies that the generation is unchanged across its
dependency reads and retries on change; response metadata and cache identity describe that same
snapshot. The plan chooses the mechanism. Tests cover undo during an in-flight apply, stale
responses, root insertion, cross-container moves, validation failure, a save of revision 10
completing after an edit to revision 11 (11 stays dirty), and a concurrent class update during a
render.

## 4. Global styles

### 4.1 Style classes

A site-owned (tenant or workspace scoped), theme-independent record `{id, version, name,
description, style}` where `style` uses the §1 schema, sparse breakpoints and resets included. A
class declares no capabilities or targets; applied to a block, each declaration lands only where
the block has the capability and the rest is dormant, shown as such. Ids are stable; names are
site-unique case-insensitively and freely renameable. Stored in `style_classes`, edited on its own
admin page behind its own permission, exposed at `/v1/admin/style-classes`. Reference validation
checks ownership, not mere existence.

### 4.2 Precedence and resets

As §1.6. No class-extends-class in v1; composition is the ordered list. A reset in a class
suppresses lower-precedence managed declarations at that property and breakpoint and may be
overridden by a later class or the instance; an instance reset bypasses all classes.

### 4.3 Versions and generations

Class saves use optimistic concurrency on `version`. A site style generation increments on every
class save, rides in apply and fragment responses, joins the render cache fingerprint, and forces
open editors to re-resolve. Saving a class changes published pages immediately; the editor says
so and shows usage (active versus dormant per reference, counting drafts, published content,
regions and retained revisions) before save.

### 4.4 Override, clear, reset, detach

Override: set an instance value (`explicit`). Clear: remove it (`inherited`). Reset: the §1.1
value (`reset`). Detach: remove the reference while preserving every managed effective value at
every breakpoint: resolve, remove, resolve again, write the previous result only where it changed,
normalise. The UI states that materialised values stop following remaining classes; "Detach all
style classes" is the simple freeze. All three of interactive, bulk and migration detach use the
same pure detach transformation.

### 4.5 Create, edit, delete

"Save as style class" lifts explicit declarations only, never inherited or resolved ones; the
record is created first, the reference is inserted last, a resolver comparison confirms the lift
preserves appearance, and undo restores the block but never deletes the shared class. Deletion
archives the definition so old revisions still restore; "detach everywhere" and "remove
everywhere" (documented as not appearance-preserving) run as idempotent jobs pinned to a class
version on the block-migration machinery, with the class locked against edits and new references
until completion. Out of v1: class inheritance, theme-shipped classes (presets carry those),
per-block-type classes.

## 5. Structural editing

### 5.1 One coordinator, three surfaces

One drag coordinator owns drag state and operation generation, with adapters for the stage
(pointer handling, hit testing, scrolling and geometry through the bridge, iframe-to-parent
coordinate conversion, a drag session id, cancellation on iframe reload), the outline, and the
inspector list (retained; its library is replaced only when parity is proven). Sources: palette,
outline, stage. Every drop resolves to one operation or one transaction.

### 5.2 Legality

Hard structural invariants are always enforced everywhere: target slot exists, no cycles, depth,
valid tree shape. The slot allow-list is authoring policy: the builder always enforces it;
`enforce_block_types` remains the server-side switch that may relax it for API writes, so the
builder is deliberately stricter than the API. Legality validates one complete candidate tree:
destination depth plus one plus subtree height at most five; destination slot constraints and the
resulting source slot; a multi-selection normalised to document order with destination indices
interpreted against the tree with the moving set removed; a group commits whole or not at all.
The rules are shared with the server validator through fixtures. Illegal drops show the reason.

Depth rises from three to five (section, columns, card, button, icon), an explicit product change
with the counting convention stated once per runtime and carried through validation, insertion,
duplication, import, restore and rendering, justified by composition fixtures.

### 5.3 Ephemeral drag and rejection

The tree is untouched until drop: movement produces a proposal, drop creates the operation, cancel
discards the session. A rejected apply never becomes an inverse history entry; locally valid
later edits stay, the failure is reported, and automatic rollback happens only when the rejected
transaction is still the unchanged history tip and the response matches its revision.

### 5.4 Geometry

Derived from real slot elements and rendered children, never the `display: contents` wrappers.
The bridge reports `{parent, slot, index, rect, layout}` with `layout` in v1 one of
`linear-vertical`, `linear-horizontal`; wrapped, grid, reversed and right-to-left slots fall back
to outline placement with a visible hint. Empty slots render a labelled placeholder in canvas mode
only, as geometry with no id and no document presence.

### 5.5 Outline, selection, insertion, multi-select

The outline reorders and reparents with the same rules. Selection synchronises across surfaces;
keyboard focus stays with the active surface. Keyboard reparenting is an explicit "Move to…"
action naming destination slot and position. Insertion is `InsertBlock(type, starter)` built by
the server's block factory from schema defaults plus starter content (kept separate); root-level
insertion takes the whole-page path. Multi-select is limited to siblings in one slot; group move,
duplicate and remove are one transaction; style edits apply to the intersection of capabilities,
one `SetSetting` per block in one transaction, with mixed values shown as mixed.

### 5.6 Proofs

Coordinator unit tests for op generation across every source and surface pair; bridge DOM tests
for zone derivation on vertical, horizontal, wrapped and empty slots; browser proofs for a
cross-container stage drop, an outline reparent, a rejected depth drop, a subtree whose root fits
at depth five but whose deepest child would not, adjacent siblings moved downward in place,
siblings moved across containers with index shift after removal, and cancellation (byte-identical
tree, unchanged history, unchanged accepted revision).

## 6. Composition

A preset answers how a new block starts; a saved section answers which composition of blocks to
insert; a style class answers how things look; a synchronised global component (later) answers
which content stays the same everywhere.

### 6.1 The envelope

Every serialised composition (section, preset, clipboard, export) uses one self-describing
envelope: `$thallo: "blocks"`, `format_version`, `settings_schema_version`, block schema versions,
source-site identity, the blocks, and a dependency manifest: style classes (id, name, version,
definition, fingerprint), assets (id, kind, name; no bytes), block types. Import, paste and
insertion run one preflight: parse, validate, resolve dependencies by ownership and type,
diagnostics, author confirmation, candidate tree, whole-candidate-tree legality, commit. Required
unresolved references block; optional ones commit a defined transformation; nothing is silently
dropped. Exported ids are references: a full site restore preserves ids; importing into another
site allocates destination ids through a mapping table and rewrites references.

Style classes across sites: an equal fingerprint maps automatically; otherwise the author decides
per class (use destination, import as new, materialise, drop) with the destination appearance
previewed. Names only suggest.

### 6.2 Saved sections

A site-owned, named, ordered list of sibling subtrees. Insertion is `InsertBlocks`: the server
resolves the section into concrete blocks with allocated ids, validates them against the current
document, and the operation records exactly those blocks; redo replays the payload. Copies carry
no provenance and no link; rename, replace contents (worded as affecting future insertions only)
and archive never touch pages. Saving from a selection: record first, then the operation; undo
never deletes the record. Sections participate in block migrations and dependency scans.

### 6.3 Presets

A starter for one block type: starter content plus settings plus optional children, with
origin-qualified identity `{origin: theme|site, id}`. Theme presets are JSON in the theme,
validated at load and at insertion, read-only, and may carry managed settings but never site
style classes or media-library assets. Site presets are authored from a selected block. Insertion
is `InsertBlock(type, starter)` resolved by the server with the same validation and legality as
sections.

### 6.4 Copy and paste, export

Copy writes the envelope to the system clipboard under a Thallo media type with a plain-text
fallback whose root is the same `$thallo` JSON, so ordinary text or JSON is never mistaken for a
composition. Paste is `InsertBlocks` after preflight. Saved sections and site presets are part of
site export and import under the envelope.

### 6.5 Previews

Rendered by the fragment renderer against the active theme in a non-interactive sandbox with
scripts off, under an explicit context contract: a block depending on an entry, route or region
needs a chosen sample context or shows an unavailable state; nothing is fabricated.

## 7. Migration and rollout

### 7.1 A declared breaking change

A Developer Preview release with no compatibility layer: block-level style fields are removed and
converted; themes must declare a vocabulary and a stylesheet manifest; the conversion runs under
the cutover contract below. Stated in the changelog and release notes.

### 7.2 The conversion table

Produced from the schema diff before Phase A ships and reviewed with its plan. Each removed field
maps to one of: a settings property with an explicit translation (button `shape` → radius token;
heading `align` → `alignment.text`; container and style padding and margin presets → spacing
tokens by a stated table), block semantics that stay in `data`, or unmappable, which includes
every raw hex and pixel value the container and style blocks accept today. There is no automatic
"nearest step" for arbitrary values: an unmappable value requires an author-selected token, a
documented transformation, or an explicit discard; if the tool proposes an approximation it shows
the result and requires acceptance and counts it as lossy.

### 7.3 The conversion command

`thallo:blocks:convert-settings` on the block-migration machinery (the admin's in-progress gate
holds while it runs). Scope invariant: every persisted block-bearing resource registered with the
block-migration system participates in conversion. That registry is built in Phase A: today the
backfill runner hard-codes drafts and current publications and never visits regions or
non-current versions, so Phase A introduces one `BlockDocumentSources` registry (drafts,
publications and every retained version, regions) that both the backfill runner and the
converter iterate; later resources (saved sections, presets) join by registering, never by a
hard-coded list. Documents carry no schema stamp today; the converter stamps them through the
reserved `_schema` key in `fields` (next to `_presentation`), for example `{"settings": 1}`. Historical versions stay restorable because the
converter also stamps and converts them. Dry run writes the diagnostics report: entry, locale, block id,
field, old value, status, reason, plus the source document revision hash and converter version.
Decisions (choose a token, transform, discard) are recorded in a durable decisions file keyed by
that hash; the live run consumes the file, refuses to complete while any diagnostic is unresolved,
and invalidates any decision whose document changed since review. Idempotent, stamping converted
documents with the settings schema version.

### 7.4 Cutover contract

1. Stage the candidate release and verify a restorable backup.
2. Preflight content and themes with the candidate converter.
3. Resolve every diagnostic; record decisions in the decisions file.
4. Enter maintenance or write protection; verify the preflight is still current.
5. Convert, build artifacts, verify, activate, reopen writes.

Recovery after a partial conversion is restore-from-backup; idempotence helps retries but does
not replace rollback. Provision may run the conversion automatically when the preflight is clean
and must not bypass unresolved decisions or activate incompatible code after a partial run. A
fresh install is the trivial case of this contract; thallo.dev takes that path.

### 7.5 Theme migration

The default theme gains `vocabulary`, the stylesheet manifest, targets in every block template,
the four inline styles removed, and no `!important` on managed properties. A theme without a
vocabulary fails to load with a named error; `thallo:doctor` reports it before activation. The
theming guide documents the vocabulary, target and layer contracts.

### 7.6 Gates and slices

Every phase: full PHP suite, admin lint, type-check, format and vitest, the cross-engine proof
suite, phpcs by exit code, boundaries, distribution smoke, skeleton smoke, clean-machine install
from Packagist, and a thallo.dev upgrade or install. Because thallo.dev takes the fresh-install
path, a separate mandatory gate rehearses the upgrade: a populated beta.28 fixture (drafts,
published content, regions, retained revisions, unmappable values) is upgraded under the §7.4
contract in CI, exercising stale decisions, an interrupted conversion with retry, and restoration
from backup. A phase does not start until the previous one
has been dogfooded on thallo.dev with its gap list filed.

Each published beta is internally complete: the beta that removes a field also ships its
validators, templates, artifacts, editor support and reconstruction-path changes. The Phase A plan
maps these indivisible slices before any beta number is assigned.

Phases: A, §1–§3 (fragments shipped disabled); B, §4 and §5; C, §6; D, header and footer regions
edited in the same stage.

### 7.7 Recorded risks

PHP and TypeScript resolver drift, held by the fixture contract. The fragment gate not being met,
which does not block the functional release. Third-party themes (none yet) needing the manifest.
The depth increase exposing untested nesting in existing templates, covered by the composition
fixtures. Cross-engine differences in `revert-layer`, covered by the three-engine proofs.
