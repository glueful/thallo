# Hover state — design

**Status:** for review (revision 2: forced preview includes the theme's hover look, theme hover
gated for touch, server-expanded capabilities, repeated parts and stage reloads). **Date:** 2026-10-07.

## 1. Purpose

An author can say what a link or button looks like under the pointer and under keyboard focus: its
text colour, background, border colour and opacity on hover, set in the Style tab beside the look
it has at rest. Today every hover look is the theme's, written per block in CSS (the button's tint,
the Social link's ink, the Links block's ink), and nothing an author picks reaches it — a Social
link given a white icon has no way to say "accent under the pointer".

Hover is one **state** of the style system, not a feature of particular blocks: a block opts a
target or part into it, and the same settings, utilities, inspector switch and cascade serve every
block that does.

**Success:** an author gives a row of Social links a muted icon that turns accent on hover, then
makes one of them turn white instead; a Button's background darkens on hover and the same change
shows when a keyboard user tabs to it; on a phone a tap leaves no hover look behind; the stage shows
the hover look — the theme's and the author's together — without moving the mouse, identical to what
the pointer produces; a site that sets no hover values renders as before, except for the one
deliberate touch correction (§4.3).

**Out of scope for this release:**

- **Geometry on hover** — radius, size, padding and border width. Size, padding and border width
  move the layout when the pointer arrives; radius does not, but is rarely wanted and would put a
  control in every hover panel. The model admits them later (§2.2) if they are asked for.
- **Other states** — active (pressed), current page, visited. The state axis is named so they can
  follow (§2.1) without another schema change of this shape.
- **Navigation** — its hover look is already a choice (`variant`, `highlight`); it is not moved
  onto this mechanism now.
- **Links inside rich text** — styled by the theme's typography and the Appearance settings, not by
  a block target; a site-level link hover colour is a separate decision.
- **Regions** (header and footer region styles).
- **Per-breakpoint hover values** (§2.3).

## 2. The model

### 2.1 Hover paths

Hover values are **managed properties of their own**, under a `hover.` prefix that mirrors the path
they change:

| Hover path | Changes | Kind |
|---|---|---|
| `hover.colors.text` | `colors.text` | colour token |
| `hover.colors.surface` | `colors.surface` | colour token |
| `hover.colors.border` | `colors.border` | colour token |
| `hover.opacity` | `opacity` (§2.4) | choice |

Each is a `PropertyDefinition` in `StyleSchema` with group `hover`, the same value kinds and token
domain or choices as the path it mirrors, plus `reset`. They are ordinary paths in every respect
the style system already has — validation, the cascade resolver, style classes, parts, the
emitter, the TS mirror — so nothing downstream learns a new shape. Stored, they nest like any path:

```json
"settings": {
  "style": { "colors": { "surface": {"type":"token","value":"color.accent"} },
             "hover": { "colors": { "surface": {"type":"token","value":"color.ink"} } } },
  "parts": { "icon": { "colors": { "text": {"type":"token","value":"color.muted"} },
                       "hover": { "colors": { "text": {"type":"token","value":"color.accent"} } } } }
}
```

`hover` is the first state; a later `active.` (or other) prefix would follow the same rule.

### 2.2 Which hover paths a target has

A target or part opts in by declaring the **`hover` capability**. It then has the hover version of
each hoverable property **it already has at rest**, and no other:

- `hover.colors.text` if it has `colors.text`; likewise `surface` and `border`;
- `hover.opacity` if it has `opacity`.

So the Links block's Link part, which has a text colour only, gets a hover text colour only; a
Button's control, with all three colours, gets all three. A hover value with no resting
counterpart (a hover background on a target that cannot have a background) is not offered and is
refused on save, as any undeclared path is.

**The rule is per target, not per block.** On a block with several targets, a hover path exists only
where its resting path and `hover` land on the **same** target. A block that maps `colors.text` to
its `title` and `colors.surface` to its `root`, with `hover` mapped to `root`, has
`hover.colors.surface` and not `hover.colors.text`. Concretely:

- mapping the **group** `hover` to a target gives that target the hover paths whose resting paths it
  owns, silently omitting the rest;
- mapping an **individual** hover path (`'hover.colors.text' => 'title'`) to a target that does not
  own its resting path is a **declaration error** (`StyleTargets::validateAgainst`), because the
  author asked for something that cannot exist;
- a part's capability list is expanded the same way against that part's own paths;
- the result does **not depend on declaration order**: hover expansion runs after every other entry
  of the list or map has been expanded, so `['hover', 'colors']` and `['colors', 'hover']` are equal.

Adding a geometry property later means adding its `hover.` definition and its utility; the rule
above extends to it unchanged.

### 2.2.1 One expansion, published to the admin

The expansion is implemented **once, in PHP** (`StyleCapabilities` / `StyleTargets`), and the admin
**does not expand groups itself** for block types. Today `StyleTab`'s `pathsOf()` expands a type's
`style_capabilities` against the schema's groups with no knowledge of targets; given `hover` it would
offer every hover path wherever the shorthand reaches. Instead:

- the block-type payload the admin reads gains a derived, read-only **`style_paths`** field:
  `{ block: [...], parts: { <name>: [...] } }` — the block's effective style paths (every target's,
  after the target-aware hover rule) and each part's, in schema order. It is computed from the stored
  declaration on read and never stored, so a custom block type gets it too;
- `pathsOf()` reads `style_paths.block`; `BlockInspector`'s part tabs read `style_paths.parts[name]`
  instead of the part's raw `capabilities`;
- multi-select keeps its rule — a row shows only when every selected block has the path — applied to
  the **expanded** sets, so a Button selected with a Links block shows no hover rows (Links' hover is
  on its part, not the block), and two Buttons show all of them;
- the style-class editor is not target-bound and keeps offering every path (`StyleCapabilities::all()`),
  hover included; at render a class's hover value applies only on targets that have that path, as
  every class value already does.

**Shared cases.** A fixture set (`packages/thallo-contracts/style-capability-fixtures/v1/*.json`, the
shape of `resolver-fixtures/v1`) pins the expansion: each case is a declaration (capability list,
targets, map, parts) and the expected `style_paths`. PHP runs every case through the expansion and
through the block-type payload; the admin runs every case's expected `style_paths` through `StyleTab`
(single selection, part tab, and multi-select of the case's sibling declarations) and asserts the
rows it renders. Required cases:

1. **Text-only Links** — the `link` part with `colors.text` and `hover` gets `hover.colors.text` only.
2. **Colours on different targets** — `colors.text` on `title`, `colors.surface` on `root`, `hover`
   on `root` → `hover.colors.surface` only; the same with `hover` on `title` → `hover.colors.text` only.
3. **Individual hover path on the wrong target** — a declaration error, not a silent drop.
4. **Declaration order** — `hover` before and after `colors`, in the capability list and in the map,
   give identical results.
5. **Sibling intersection** — two sibling parts (one with `hover` and text, one with surface and no
   `hover`) each get only their own; and multi-selected sibling blocks (Button + Button, Button +
   Links, Button + Social link) intersect to the expected rows.
6. **No `hover`** — a target with all colours and no `hover` gets no hover path.

### 2.3 One value for every width

Hover paths are **not responsive**: one value for every width. Hover is a pointer state, and a
per-width hover colour has no use case worth a fourth dimension in the inspector. As with the
typeface, a later responsive version can read a plain value as `base`.

### 2.4 Opacity

`opacity` is a **new resting property** (group `opacity`, choices `100`, `90`, `80`, `70`, `60`,
`50`, not responsive, `reset`), so that "faint until hovered" and "dims on hover" are both
expressible: hover opacity mirrors it. It sets the element's `opacity`, so it fades the whole
element, text and icon included — unlike `colors.surface_opacity`, which fades the background only
and is the backdrop group's.

### 2.5 Settings version

`StyleSchema::VERSION` 14 → **15** (`hover.*`, `opacity`). No stored document changes: a document
without hover values means what it meant.

## 3. Cascade

### 3.1 Within the style system

Hover paths resolve through `CascadeResolver` exactly as other non-responsive paths do: the block's
own value beats its style classes', a later class beats an earlier one, `reset` clears what the
classes set. A hover value and the resting value of the same property are **independent**: setting
a hover background does not need a resting one, and resetting the resting one leaves the hover one.

### 3.2 Parent and child (Social links)

The Social links row's Icon part reaches each Social link through `parent_style_classes('icon',
'icon')`, filtered by `withoutValuesSetIn()` so a link's own values win leaf by leaf. Hover paths are
leaves under `hover`, so the existing recursion already gives the intended result: the row's hover
colour applies to every link that does not set its own, and a link's own hover colour wins for that
link. No change to `parentStyleClasses()` beyond tests pinning it.

### 3.3 Against the theme

The compiled utilities live in `@layer settings`, which follows `@layer theme`, so a managed value
beats a theme rule regardless of specificity. Consequences, all intended:

- A hover colour the author sets **replaces** the theme's hover colour for that property (the
  ghost/soft/subtle button tint, the File link's surface, the Links block's ink, the Social link's
  ink). It does not stack with it.
- Theme hover effects the author cannot set **remain**: the button's 1px lift, the link variant's
  thicker underline, transitions.
- Unchanged and already true today: a **resting** colour the author sets also beats the theme's
  hover colour for that property (a ghost button given a background keeps it on hover). The hover
  setting is now the way to say what it becomes.

### 3.4 Against the resting utility

A hover utility's selector carries one more pseudo-class than a resting one (§4.1), so within the
settings layer it beats the resting utility of the same property at every breakpoint, and beats the
surface-opacity modifier. A hover background therefore paints at full strength even on a target that
also has a surface opacity; none of the targets in scope (§5) has the backdrop group, and the
reference documents this for themes that combine them.

## 4. Compilation

### 4.1 Utilities

Class names come from `ClassNames`, stems `hover-fg`, `hover-bg`, `hover-bc`, `hover-opacity` and
`opacity`: `t-hover-bg-accent`, `t-hover-opacity-70`, `t-opacity-80`. Each hover utility compiles to
a pointer branch gated on `(hover: hover)` and an ungated branch for keyboard focus and the stage's
forced preview:

```css
@media (hover: hover) {
  .t-hover-bg-accent:hover { --t-surface: var(--t-color-accent); background: var(--t-color-accent); }
}
.t-hover-bg-accent:focus-visible,
.t-hover-bg-accent[data-thallo-hover] { --t-surface: var(--t-color-accent); background: var(--t-color-accent); }
```

- `:focus-visible` makes the authored hover look the keyboard focus look as well. Every target in
  scope is a link; on a non-focusable target the selector simply never matches.
- `(hover: hover)` describes the **primary** input. On a device whose primary input cannot hover (a
  phone, a tablet) the pointer branch never applies, so a tap leaves nothing behind. On a hybrid
  device whose primary input can hover (a touch-screen laptop) a tap with the finger may still leave
  the hover look until the next tap elsewhere; that is accepted and documented, not prevented.
- `[data-thallo-hover]` is the stage's forced hover (§6.3). It never appears in public markup.
- The declarations are the resting property's own (`colors.surface` names `--t-surface` too).
- **Reset** for a hover path is an **empty rule**, as for the modifiers: it means "no hover value
  from this layer", never `revert-layer` (which would also undo the resting utility while hovered).
- Hover rules are emitted once (they are not responsive), after the base utilities.

`StyleCompiler::VERSION` 19 → **20**.

### 4.2 Transitions

The compiled artifact sets **no `transition`**: a transition is the theme's choice, and a utility
that set one would replace the theme's (the button's includes `transform`). The default theme gives
each element in scope a transition on every property a hover value can change there — colour,
background, border colour and opacity, as the element offers them — adding them to the Button's and
the File link's existing transitions (amended after the implementation review: a hover opacity or
text colour must not snap while the background fades), and turns them all off under
`prefers-reduced-motion: reduce`.

### 4.3 The theme's hover rules

The forced preview (§6.3) and the touch behaviour (§4.1) both need the theme's hover look to follow
the same shape as the utilities, or the stage would preview an incomplete combination (an untouched
ghost Button keeping its resting background; a Button with only an authored hover text colour
previewed without its tint and lift) and a phone would keep the theme's hover after a tap.

So every default-theme hover rule for an element in scope (§5) is rewritten into the same three
branches:

```css
/* before */
.thallo-block-file__link:hover,
.thallo-block-file__link:focus-visible { background: var(--surface); }

/* after */
@media (hover: hover) {
  .thallo-block-file__link:hover { background: var(--surface); }
}
.thallo-block-file__link:focus-visible,
.thallo-block-file__link[data-thallo-hover] { background: var(--surface); }
```

- **Pointer** branch: gated on `(hover: hover)`.
- **Forced preview** branch: `[data-thallo-hover]`, always, so the stage shows exactly what the
  pointer would.
- **Keyboard focus** stays separate: a rule that already had `:focus-visible` keeps it, ungated; a
  rule that had none (the Button's lift and tint, the Social link's ink) does not gain one — the theme's
  focus look stays its focus ring.

The rules this covers (`packages/thallo-render/themes/default/assets/blocks.css`):

| Rule | Line today |
|---|---|
| `.thallo-block-button__link:hover` (lift) | 643 |
| `--ghost:hover`, `--soft:hover`, `--subtle:hover` (tint) | 666–668 |
| `--link:hover` (no lift, thicker underline) | 679 |
| `.thallo-block-social_link:has(> __link:hover)` (ink) — forced form `:has(> __link[data-thallo-hover])` | 969 |
| `.thallo-block-file__link:hover, :focus-visible` | 1081–1082 |
| `.thallo-block-links__link:hover, :focus-visible` | 1354–1355 |

Hover rules for elements outside this release's scope (cards, navigation, the search block, the
commerce blocks) are unchanged.

**A deliberate touch correction.** With no hover values set, these elements now behave differently in
one case: on a device whose primary input cannot hover, a tap no longer leaves the theme's hover look
(lift, tint, ink) on the element. This is the only exception to "a site that sets no hover values
renders as before", and it is recorded in the changelog as a behaviour change.

**Custom themes.** A theme's hover rules for these elements keep working on a real pointer without
change. For the stage's forced preview to show them, and for them not to stick on phones, a theme
writes each such rule in the same three branches. The theme reference documents the pattern and the
attribute; a theme that does not adopt it previews only the authored hover values on the stage.

## 5. Blocks in scope

| Block | Target or part | Gains |
|---|---|---|
| Button | `control` target | `opacity`, `hover` (text, background, border, opacity) |
| Social link | `icon` part | `opacity`, `hover` (text, background, border, opacity) |
| Social links | `icon` part (drawn by each link) | `opacity`, `hover` |
| Links | `link` part | `hover` (text only — the part's one colour) |
| File | new `link` part | colours (text, background, border), radius, typography size, padding, `opacity`, `hover` |

File has no colour settings at all today, so it gains a Link part with the resting look as well;
its template adds `{{ style_classes('link') }}` to `thallo-block-file__link`. Provisioning updates
existing workspaces' block types (`thallo:provision`), as for the Social link's Icon section.

Default theme CSS changes: the hover rules in §4.3 take the three-branch form; the Social link's
link and the Links block's link gain a colour transition (§4.2).

## 6. The inspector

### 6.1 What does not change

Because hover values are ordinary non-responsive paths, the admin's path handling needs nothing new:
`settingSegments()` (`editor/ops/apply.ts`) already stores `hover.colors.text` bare under `style` or
`parts.<part>`; the `SetSetting` op, the resolver, lift/detach, multi-select and `useStyleRecord`
already handle a non-responsive path. The style-schema endpoint carries the new rows, and the static
mirror `style/schema.ts` gains them. What a block or part **offers** comes from the server-expanded
`style_paths` (§2.2.1), not from a local group expansion.

### 6.2 The Normal / Hover switch

`StyleTab.vue` shows a **Normal / Hover** segmented switch in the header of each section that has a
hoverable row on this target or part — Colours (text, background, border) and Effects (opacity) —
beside where the breakpoint chips sit. In Hover, the section's hoverable rows are replaced by their
hover counterparts (same control, same labels, the state named in the section header); rows with no
hover counterpart (gradient, shadow, radius, border width and style) are hidden while Hover is on,
not disabled, so the panel shows only what hover can change. Hover rows are not responsive, so no
breakpoint chips show for them.

- **One state per Style tab**, shared by its sections: switching Colours to Hover switches Effects
  too. It returns to Normal when the selection changes. It is not the breakpoint (`editor/breakpoint.ts`
  stays as it is): hover and breakpoint are independent.
- **A dot** on Hover when any hover value is declared on this target or part, as the breakpoint chips
  mark a declared breakpoint.
- **Clearing** a hover row removes the hover value only.
- **Hover groups do not get a section of their own**: the `hover` group is never listed in
  `GROUPS`; its rows only appear through the switch.

The switch appears wherever `StyleTab` renders a target that has hover paths: a block's targets, a
block's parts (`context="part"`), and the style-class editor (`context="class"`), where a class can
carry hover values for any block target that accepts them. Region styles declare no hover, so the
region editor never shows it.

### 6.3 The stage shows the hover look

While a section is on Hover, the stage shows the selected target's hover look — the theme's hover
rules and the authored values together (§4.3) — without the pointer.

**The message.** The admin posts `thallo:force-hover {id, target, part}` through `useCanvasBridge`
(one of `target` / `part` set). `{id: null}` clears it. The admin clears when the switch returns to
Normal, when the selection changes, when the panel holding the switch closes (the Style tab is left
for another tab, the part's section is collapsed, the inspector closes), and when the page is left.

**Which elements.** Unlike the typography lookup, which deliberately stops at the first match, the
force reaches **every** element the block owns for that target or part:

- a target or own part: every element inside the block's wrapper carrying
  `.thallo-stage-target--<target>` / `.thallo-stage-part--<part>` **whose nearest block wrapper
  (`wrapperFor`) is that block** — every link of a Links block, never a link of a block nested inside
  it;
- a part drawn by children (the Social links' Icon): every element carrying
  `.thallo-stage-part--<part>` whose nearest block wrapper is a **direct child block** of this one.
  `parent_style_classes()` writes no stage marker, but every Social link draws its own Icon part with
  `style_classes('icon')`, which does. A grandchild's element is not reached.

Each element gets `data-thallo-hover`; the bridge removes it from every element it set when the force
clears or changes. The name is distinct from `thallo-canvas-hover`, the stage's selection-outline hover.

**Surviving paints and reloads.** Two cases, handled in different places:

- **Fragment swap or in-place stage refresh** — the bridge's state survives but the DOM is replaced,
  so the bridge remembers the active force and re-applies it after every paint.
- **Full iframe reload** (the stage reloads on a theme, appearance or fonts change, or a refresh falls
  back to a reload) — the bridge's memory is gone. The **admin** owns the state: it keeps the active
  force and re-sends it whenever the stage announces it is ready (`thallo:stage-state`, posted on
  every activation), so a reload ends with the same forced look.

The style-class editor has no stage; its switch only edits.

## 7. Documentation

- `docs/reference/05-style-settings.md`: a Hover section — the paths, the opt-in rule (§2.2), what
  beats what (§3.3–3.4), touch and keyboard behaviour (§4.1), and the opacity property.
- `docs/reference/04-block-library.md`: the rows for Button, Social link, Social links, Links and
  File.
- Theme authoring notes: transitions are the theme's (§4.2); a theme hover rule yields to a set
  hover value; the three-branch pattern and `[data-thallo-hover]` for forced preview and touch (§4.3).
- Changelog under [Unreleased]: the feature; the touch correction as a behaviour change (§4.3); and
  Upgrade Notes — run `thallo:provision` for the new sections; a theme override of `blocks/file.twig`
  must add `style_classes('link')` to the link to get the File's Link section; a custom theme adopts
  the three-branch pattern for its hover rules to be previewed and not stick on phones.

## 8. Testing

- **Contracts:** `StyleSchemaTest` — the hover definitions mirror their resting paths' kinds and
  domains and are not responsive. The capability fixtures (§2.2.1, all six required cases) run through
  `StyleCapabilities` / `StyleTargets` and through the block-type payload's `style_paths`.
- **Compiler:** each hover utility's two rules, the empty reset, `opacity`; the artifact hash moves.
- **Emitter / render:** a Button's control, a Links link, a File link and a Social link carry the
  hover classes; the row's hover value reaches each Social link; a link's own hover value beats the
  row's leaf by leaf (extends `FooterAndSocialStyleTest`).
- **Reset:** (a) a style class sets a hover background and the block resets it — the element carries
  `t-hover-bg-reset` and not the class's hover utility; (b) a Social links row sets a hover icon
  colour and one link resets it — that link carries the reset and not the row's hover class, its
  sibling keeps the row's. In the browser, both elements, hovered and forced, keep their **resting**
  utility's colour: the empty reset rule does not undo it.
- **Validation:** a hover path a target lacks is refused; a document without hover values
  round-trips unchanged.
- **Browser (runtime-browser):** on hover the colour applies and beats the resting utility and the
  theme's hover rule; the same look on `:focus-visible`.
- **Pointer and forced preview match:** for each element in §4.3 (every Button variant, Social link,
  Links link, File link), the computed `color`, `background-color`, `border-color`, `opacity`,
  `transform` and `text-decoration-thickness` under a real `hover()` equal those with
  `data-thallo-hover` set — once with **no declarations** (the theme's look alone) and once with **one
  authored hover property** overriding one theme property (the rest still the theme's). Run with
  reduced motion so transitions do not leave values mid-flight.
- **Touch:** in a touch-primary context (`isMobile`, `hasTouch`, where `(hover: none)` matches), a tap
  leaves neither the theme's hover look nor an authored one on any §4.3 element.
- **Admin:** the resolver fixture contract (`resolver-fixtures/v1`) gains hover and opacity cases; a
  new parity test diffs `style/schema.ts` against `StyleSchema::properties()` (none exists today, and
  this change adds five rows to keep in step); StyleTab specs for the switch — which sections show
  it, the row swap, the dot, the shared state, reset on selection change — and that it writes
  `hover.*` for a block, a part and a class.
- **Stage (e2e):** switching to Hover forces the look on the selected Button; on **every** link of a
  Links block with several links, and on none of a Links block nested elsewhere in the page; on every
  link of a Social links row. The force survives a fragment paint and a **full stage reload** (the
  admin re-sends it on `stage-state`). It clears on Normal, on a selection change, and when the
  panel holding the switch closes (leaving the Style tab, collapsing the part's section).
- **Fragments:** `fragments-verified.json` re-recorded for the File template.
