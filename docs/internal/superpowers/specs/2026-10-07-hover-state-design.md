# Hover state — design

**Status:** for review. **Date:** 2026-10-07.

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
shows when a keyboard user tabs to it; nothing sticks on a phone after a tap; the stage shows the
hover look without moving the mouse; a site that sets no hover values renders exactly as before.

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
refused on save, as any undeclared path is. The expansion happens where groups already become
paths (`StyleTargets` / `StyleCapabilities`), so `allows()` stays a flat lookup.

Adding a geometry property later means adding its `hover.` definition and its utility; the rule
above extends to it unchanged.

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
two rules, so a tap on a touch screen does not leave the hover look stuck:

```css
@media (hover: hover) {
  .t-hover-bg-accent:hover { --t-surface: var(--t-color-accent); background: var(--t-color-accent); }
}
.t-hover-bg-accent:focus-visible,
.t-hover-bg-accent[data-thallo-hover] { --t-surface: var(--t-color-accent); background: var(--t-color-accent); }
```

- `:focus-visible` makes the hover look the keyboard focus look as well. Every target in scope is
  a link; on a non-focusable target the selector simply never matches.
- `[data-thallo-hover]` is the stage's forced hover (§6.3). It never appears in public markup.
- The declarations are the resting property's own (`colors.surface` names `--t-surface` too).
- **Reset** for a hover path is an **empty rule**, as for the modifiers: it means "no hover value
  from this layer", never `revert-layer` (which would also undo the resting utility while hovered).
- Hover rules are emitted once (they are not responsive), after the base utilities.

`StyleCompiler::VERSION` 19 → **20**.

### 4.2 Transitions

The compiled artifact sets **no `transition`**: a transition is the theme's choice, and a utility
that set one would replace the theme's (the button's includes `transform`). The default theme gives
each element in scope a colour/opacity transition where it lacks one (Social link, Links), keeps the
ones it has (Button, File), and turns them off under `prefers-reduced-motion: reduce`.

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

Default theme CSS changes:

- the Social link's `:has(> __link:hover)` ink rule stays (it is the theme's hover look when no
  value is set), and the link gains a colour transition;
- the Links block's link gains a colour transition;
- no other hover rule changes.

## 6. The inspector

### 6.1 What does not change

Because hover values are ordinary non-responsive paths, the admin's path handling needs nothing new:
`settingSegments()` (`editor/ops/apply.ts`) already stores `hover.colors.text` bare under `style` or
`parts.<part>`; the `SetSetting` op, the resolver, lift/detach, multi-select and `useStyleRecord`
already handle a non-responsive path. The style-schema endpoint carries the new rows, and the static
mirror `style/schema.ts` gains them.

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

While a section is on Hover, the stage shows the selected target's hover look without the pointer:

- The admin posts `thallo:force-hover {id, target, part}` through `useCanvasBridge`; switching back
  to Normal, changing the selection or leaving the page posts `{id: null}`.
- `preview-bridge.js` finds the element as `thallo:typography-request` does
  (`.thallo-stage-target--<target>` / `.thallo-stage-part--<part>` inside the block's own wrapper; for
  a part drawn by children, the Social links' Icon, the marker of each direct child block instead:
  `parent_style_classes()` writes no stage marker, but every Social link draws its own Icon part
  with `style_classes('icon')`, which does) and sets
  `data-thallo-hover` on it. The compiled hover utilities match that attribute (§4.1). The name is
  distinct from `thallo-canvas-hover`, the stage's selection-outline hover.
- A fragment swap or stage refresh replaces the DOM, so the bridge re-applies the attribute after
  every paint while a force is active.
- The style-class editor has no stage; its switch only edits.

## 7. Documentation

- `docs/reference/05-style-settings.md`: a Hover section — the paths, the opt-in rule (§2.2), what
  beats what (§3.3–3.4), touch and keyboard behaviour (§4.1), and the opacity property.
- `docs/reference/04-block-library.md`: the rows for Button, Social link, Social links, Links and
  File.
- Theme authoring notes: transitions are the theme's (§4.2); a theme hover rule yields to a set
  hover value.
- Changelog under [Unreleased], with an Upgrade Note: run `thallo:provision` for the new sections;
  a theme override of `blocks/file.twig` must add `style_classes('link')` to the link to get the
  File's Link section.

## 8. Testing

- **Contracts:** `StyleSchemaTest` — the hover definitions mirror their resting paths' kinds and
  domains and are not responsive; capability expansion gives a target only the hover paths whose
  resting path it has, and none without `hover`.
- **Compiler:** each hover utility's two rules, the empty reset, `opacity`; the artifact hash moves.
- **Emitter / render:** a Button's control, a Links link, a File link and a Social link carry the
  hover classes; the row's hover value reaches each Social link; a link's own hover value beats the
  row's leaf by leaf (extends `FooterAndSocialStyleTest`).
- **Validation:** a hover path a target lacks is refused; a document without hover values
  round-trips unchanged.
- **Browser (runtime-browser):** on hover the colour applies and beats the resting utility and the
  theme's hover rule; the same look on `:focus-visible`; with `hover: none` emulated, a tap leaves
  no hover look; `[data-thallo-hover]` forces it.
- **Admin:** the resolver fixture contract (`resolver-fixtures/v1`) gains hover and opacity cases; a
  new parity test diffs `style/schema.ts` against `StyleSchema::properties()` (none exists today, and
  this change adds five rows to keep in step); StyleTab specs for the switch — which sections show
  it, the row swap, the dot, the shared state, reset on selection change — and that it writes
  `hover.*` for a block, a part and a class.
- **Stage:** e2e — switching to Hover forces the look on the selected Button and on every link of a
  Social links row, and it survives a paint; Normal clears it.
- **Fragments:** `fragments-verified.json` re-recorded for the File template.
