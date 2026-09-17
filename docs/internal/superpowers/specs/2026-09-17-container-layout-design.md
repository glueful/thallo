# Container Layout — Design

**Status:** approved in brainstorming, 2026-09-17. Delivered as one release (one beta cut).
**Supersedes:** the `columns`, `grid` and `section` block types.

## 1. Goal and scope

One layout block, **Container**, replaces Columns, Grid and Section. Layout becomes part of the
style contract, edited from a new **Layout** tab in the block inspector, and a new container
offers an optional **structure picker** on the stage.

- **Fresh-install scope.** No stored content is rewritten. The parity tests in §7 and §9 prove
  visual and semantic equivalence between the old blocks and their Container compositions; they
  are not a claim of upgrade support for existing installations.
- **Kept blocks.** Hero, Call to action, Card and Feature keep their identities and content
  semantics. They may gain item-layout capabilities (§3.6) so they behave correctly as flex or
  grid children.
- **Out of scope:** hover states, gradients, entrance or scroll animation, free numeric units,
  z-index and positioning, per-item order, masonry (§3.8).

## 2. Principles

1. **Presentation lives in `settings.style`; structure lives in `data`.** Direction, wrapping,
   gap, alignment, grid tracks, min height and overflow are presentation. Child lists, the
   semantic element and background asset references are data.
2. **Why the style contract.** Layout could have been made responsive as data fields. It goes
   into `settings.style` because that shares responsive overrides, resets, style classes,
   history and validation with the existing system, and avoids building a second cascade and
   reuse mechanism.
3. **Grid tracks are not structural columns.** A container stores one `content` list. A "column"
   is a child container in that list. Changing the displayed track count is responsive styling;
   adding or removing a child is a structural edit with its own history entry.
4. **Layout settings never remove children or implicitly change their visibility.** The
   `visibility` property can still hide a block on purpose.
5. **Reuse, don't compete.** Existing properties and vocabularies are used where their meaning
   matches; a new name is introduced only where it does not.

## 3. Contract

### 3.1 Targets

- **Container `root`:** the band. Background layers, overlay, box width, min height, overflow,
  padding, colours, radius, border, shadow, visibility, and the container's own item properties.
- **Container `inner`:** the content area. Content width, gutter, and every flex and grid parent
  property. Its children are the layout participants.

### 3.2 Properties

"Responsive" means a value per breakpoint (`base`, `md` from 768px, `lg` from 1024px) with the
existing inheritance.

**Container properties on `inner`:**

| Path | Values | Responsive | Notes |
|---|---|---|---|
| `layout.display` | block, flex, grid | yes | block: children stack |
| `layout.direction` | row, column, row-reverse, column-reverse | yes | flex only |
| `layout.wrap` | nowrap, wrap | yes | flex only |
| `alignment.content` | start, center, end, between, around, evenly | yes | existing property; choices extended |
| `layout.align_items` | start, center, end, stretch, baseline | yes | flex and grid |
| `layout.columns` | 1, 2, 3, 4, 6, 12, 1-2, 2-1, 1-3, 3-1, 1-2-1, 1-1-2, 2-1-1 | yes | grid only |
| `layout.gap.column` | spacing tokens | yes | flex and grid |
| `layout.gap.row` | spacing tokens | yes | flex and grid |
| `layout.content_width` | width tokens | yes | constrains and centres `inner` |
| `layout.gutter` | spacing tokens | yes | inline padding of `inner` (§3.4) |

**Container properties on `root`:**

| Path | Values | Responsive |
|---|---|---|
| `layout.min_height` | auto, half, screen | yes |
| `layout.overflow` | visible, hidden, auto | **no** — applies at all sizes |

**Item properties on the `root` of a block declaring `layout.item`:**

| Path | Values | Responsive | Parent mode |
|---|---|---|---|
| `layout.span` | 1–12, full | yes | grid |
| `layout.basis` | auto, 1/4, 1/3, 1/2, 2/3, 3/4, full | yes | flex |
| `layout.grow` | 0, 1 | yes | flex |
| `layout.shrink` | 0, 1 | yes | flex |
| `layout.align_self` | start, center, end, stretch | yes | flex and grid (cross axis) |

**Existing properties kept as they are:** `width` (the box's own width), `alignment.self`
(horizontal placement through auto margins, labelled "Placement"), `alignment.text`.
`alignment.self` is not CSS `align-self`; cross-axis self-alignment is the new
`layout.align_self`.

### 3.3 Mode dormancy

- Flex-only and grid-only values stay stored when the effective display is another mode, and
  paint nothing. Switching back restores them.
- Dormancy is decided against the **effective display at each breakpoint**, including inherited
  values — never against the presence of a mode class in the HTML (a base flex class stays in the
  markup when `md` selects grid).
- Item properties resolve against the **immediate layout parent's** effective mode at the same
  breakpoint — not the child's own mode and not an ancestor's.
- The compiled mechanism is chosen in the plan and must pass: base flex with grid at `md`; grid
  inherited from base; flex inside grid inside flex.

### 3.4 Gutter

- The gutter default sits **outside the managed cascade**. An absent `layout.gutter` resolves to
  the default for the effective content width at that breakpoint and is never written as a
  declaration; the inspector shows it as the theme's value.
- Defaults: a boxed content width (`width.content`, `width.container`, `width.narrow`) defaults
  the gutter to `spacing.lg`; `width.full` defaults it to none.
- Pinned cases: boxed → full with no authored gutter gives zero; boxed → full with an authored
  gutter keeps it; Reset returns to the content-width default; explicit `spacing.none` gives
  zero at any width.
- The container's own padding stays on `root` (space around the band) and never competes with
  the gutter on `inner`.

### 3.5 Min height reaching the content area

- A non-managed base rule makes the container `root` a column flex box whenever the effective
  `layout.min_height` is not auto, and `inner` flexes to fill the remaining height inside
  root's padding. Background and overlay layers stay absolutely positioned against `root`.
- Root sizing gives `inner` space but does not change `inner`'s display mode. Centring content
  in a tall band is an explicit `inner` configuration.
- Equivalence fixture for today's half-screen centred band: root `layout.min_height: half` with
  padding; inner `layout.display: flex`, `layout.direction: column`, `alignment.content: center`.

### 3.6 `layout.item` and nested clamps

- A block type opts in with the `layout.item` capability. The item properties land on the block's
  `root` target, which must be the element that participates in the parent's layout.
- A block whose styled root is not its participating element cannot declare `layout.item` until
  its targets are corrected; the template linter checks this.
- On the stage every block root sits inside a `display: contents` annotation wrapper. Parent and
  item selectors must match through it. Participation is proven on both the public page and the
  annotated stage.
- **Nested clamps.** Heading, rich text, button and other leaves carry a page-level clamp (max
  width, auto margins, gutter). When the block's parent is a container, the leaf releases that
  clamp and fills its layout cell; at page level the clamp is unchanged.

### 3.7 Span clamping

- `layout.span` resolves against the parent's effective track count at the same breakpoint,
  inherited values included. A span larger than the available tracks fills the row instead of
  creating implicit tracks.
- The mechanism stays open until these pass: mobile stacking, asymmetric presets, inherited spans,
  nested grids.

### 3.8 Exclusions

- **Per-item order.** Reverse directions are kept: whole-list reversal answers a bounded need
  (image first on mobile, text first on desktop). Per-item order is excluded because it lets any
  single child move anywhere, multiplying the ways focus order diverges from visual order. Reverse
  also diverges from reading order; authors should use it for presentation swaps, not to reorder
  meaning.
- **Masonry** is a deliberate scope reduction. Gallery covers image walls; arbitrary
  mixed-content masonry is retired, not replaced by an equivalent.
- **Position, z-index, hover states** are not in this contract.

## 4. Container cutover

- **Data after cutover:** `content` (blocks); `element` (div, section, article, aside, header,
  footer; default div — structure, allowlisted); the Background group (image, video, video URL,
  size, position, overlay, overlay opacity).
- **Deleted with their template modifiers and CSS:** the data fields `width`, `min_height`,
  `content_align`, `layout`, `flex_direction`, `justify`, `align_items`, `gap`, `flex_wrap`. API
  validation rejects them on a container.
- **Capabilities.** `root`: spacing, `width`, `alignment.self`, visibility, colours, radius,
  border, shadow, `layout.min_height`, `layout.overflow`, `layout.item`. `inner`: `layout.display`,
  `layout.direction`, `layout.wrap`, `alignment.content`, `layout.align_items`,
  `layout.columns`, `layout.gap.column`, `layout.gap.row`, `layout.content_width`,
  `layout.gutter`.
- **Factory-created defaults.** A container created by the block factory resolves to block
  display, full content width, no gutter and auto height, with nothing written. (The old
  template's fallback for a missing `data.width` was contained; factory-created containers never
  hit it.)
- **Parity mapping** for today's container widths:

| Old `data.width` | `layout.content_width` | Default gutter |
|---|---|---|
| contained | `width.container` | `spacing.lg` |
| narrow | `width.content` | `spacing.lg` |
| full | `width.full` | none |

`narrow` maps to `width.content` because today's CSS uses `var(--content)`, not `width.narrow`.

## 5. Inspector

- **Tabs:** Content, Layout, Style, Advanced.
- **Tab membership is per property**, from one central path-to-tab map, independent of
  capability groups and capability expansion. The alignment group splits across tabs:
  `alignment.text` → Style; `alignment.self` and `alignment.content` → Layout.
- **Style tab:** spacing, colours, radius, border, shadow, typography, visibility,
  `alignment.text`.
- **Layout tab**, capability-driven for every block, four sections:
  1. **Box** — `width`, `alignment.self` ("Placement"), `layout.min_height`, `layout.overflow`,
     wherever declared.
  2. **Container** — `layout.display`, `layout.content_width` with `layout.gutter`.
  3. **Children** — the container's mode-dependent controls at the active breakpoint.
     Flex: direction, wrap, `alignment.content`, `layout.align_items`, gaps (a two-cell linked
     row, Column and Row). Grid: track presets as proportional swatches, `alignment.content`,
     `layout.align_items`, gaps. Block: a line saying children stack, with the display switch.
  4. **As an item** — for a block declaring `layout.item` whose immediate parent is a container,
     resolved against that parent's effective display at the active breakpoint. Grid: span,
     `layout.align_self`. Flex: basis, grow, shrink, `layout.align_self`. Block: a line saying the
     parent stacks its children, with a link selecting the parent.
- **Non-container blocks** that declare `alignment.content` (Button, Navigation) keep that
  control against their existing target without becoming containers.
- **One active breakpoint** drives every section header. Overflow is labelled as applying at all
  sizes.
- **Multi-selection** uses the capability intersection and mixed values. Sharing a parent does
  not make every item property available.
- **Dormant notices in both directions:** a container switched from grid to flex discloses its
  retained grid-parent settings; a child discloses retained item settings for the other mode.
  Values supplied by style classes count as well as local declarations.
- **Canvas:** no new protocol for the Layout tab — an apply re-renders as today. A rendering proof
  shows children updating when their parent's mode changes.

## 6. Structure picker

### 6.1 Qualification

- Offered only for a Container the author explicitly inserted, fresh and empty (a Blocks tab
  tile click or drop, a stage "+" placeholder).
- Never offered for containers created by a preset, a duplicate, a paste, a version restore or a
  redo.
- Pending state lives in editor session state, never in the document.

### 6.2 Protocol

- Parent → bridge: `thallo:structure-offer { offers: [{ id, presets: [{ key, enabled, reason? }] }] }`.
  The bridge renders the tiles inside that container's empty-slot placeholder, with a Skip link.
- Bridge → parent: `thallo:structure-choose { id, preset }` and `thallo:structure-skip { id }`.
- A choose is accepted only for an active offer; repeat requests are ignored while a choice is
  preparing.

### 6.3 Commit sequence

1. Mark the offer as preparing.
2. Fetch factory instances for the preset's children (the server factory supplies structure and
   defaults) and allocate their ids in the editor.
3. After every await, re-check: the offer is still pending and not cancelled, the container still
   exists, and it is still empty. If any check fails, cancel with a notice and change nothing.
4. Build the complete candidate tree and validate it: nesting depth and slot rules for every
   child. A refusal leaves the offer pending with refreshed reasons.
5. Commit synchronously as **one transaction**: the owned settings writes, owned data writes, and
   one insert per child in order.
6. Consume the offer.

- **Consumption.** Every successful preset consumes the offer, Row and Stack included. Skip,
  deletion of the container, or a structural edit into it during preparation cancels the choice;
  a late answer cannot resurrect it.
- **Undo** restores the exact previous document, settings included, and does not reopen the
  picker. **Redo** replays the same operations with the same ids.
- **Factory failure** leaves document and history unchanged; the offer stays pending.

### 6.4 Presets as scoped patches

- Each preset declares the paths it **owns**. For every owned property it writes the complete
  responsive result at all three breakpoints, so an existing `lg` override cannot defeat the
  arrangement. An owned path the preset's composition does not use is cleared at every
  breakpoint. Unowned values (width, gutter, spacing, backgrounds, style-class references) are
  left alone unless listed.
- Undo restores the exact previous value of every owned path.
- **Stack** records a transaction only if it changes a value; dismissing an already-stacked empty
  container records nothing.

### 6.5 Preset list

Multi-column presets stack on mobile. In the table, `layout.columns` = `1 / X` means `base` 1, with
`md` and `lg` both written as X.

| Preset | Owned paths | Children |
|---|---|---|
| Stack | `layout.display` = block | none |
| Row | `layout.display` = flex, `layout.direction` = row | none |
| Two columns 50/50, 33/67, 67/33, 25/75, 75/25 | `layout.display` = grid, `layout.columns` = 1 / 2, 1-2, 2-1, 1-3, 3-1 | two containers |
| Three columns, 25/50/25 | `layout.display` = grid, `layout.columns` = 1 / 3, 1-2-1 | three containers |
| Four columns | `layout.display` = grid, `layout.columns` = 1 / 4 | four containers |
| Grid 2×2 | `layout.display` = grid, `layout.columns` = 1 / 2 | four containers |
| Section, Section split | §7 | §7 |

## 7. Section composition

Section is deleted only after these compositions pass their parity proofs.

### 7.1 Today's behaviour (the reference)

- Root `<section>`: `padding-block: var(--space-7)`, background variant.
- Inner: `max-width: var(--container)`, auto margins, `padding-inline: var(--space-4)`, flex
  column, `gap: var(--space-5)`.
- Children in DOM order: **header group** (headline `<p>`, title `<h2>`, description `<p>`) →
  **content** → **links** (flex, wrap, `gap: var(--space-3) var(--space-5)`).
- **Vertical** (default): headline, title and description centred; description auto-centred at
  `var(--content)`; links centred.
- **Reverse:** `order: -1` on content only — content moves visually ahead of the header; links
  stay last; DOM order unchanged.
- **Horizontal:** from `min-width: 64rem` the inner becomes a 2-column grid, `align-items:
  center`, `column-gap: var(--space-6)`; header, content and links flow into it.
- **Per-part alignment** (`headline_align`, `title_align`, `description_align`) overrides the
  orientation default.
- **Background:** none (transparent), muted (`--surface-2`), subtle (`--surface`), inverted
  (`--ink` ground, `--accent-ink` text, description at 72%).

### 7.2 Owned paths

The Section presets own: `data.element` = section; root `spacing.padding.top` and
`spacing.padding.bottom` = `spacing.3xl`; inner `layout.content_width` = `width.container`;
inner `layout.display`, `layout.direction`, `layout.gap.row`, `layout.columns`,
`layout.align_items`, `layout.gap.column`. Paths a variant does not use (for example
`layout.columns` in the vertical Section) are cleared at every breakpoint. All are written in the
one transaction; undo restores each previous value exactly.

### 7.3 Composition — Section (vertical)

- **Container** `element: section`; root padding block `spacing.3xl`; inner content width
  `width.container`, flex column, row gap `spacing.xl`.
  1. **Header group** — container; inner flex column, no gap.
     - **Headline** — rich text containing one paragraph; `colors.text` accent;
       `typography.weight` semibold; `spacing.margin.bottom` sm; `alignment.text` center.
     - **Title** — heading, level 2; `alignment.text` center.
     - **Description** — rich text containing one paragraph; `colors.text` muted;
       `typography.size` lg; `width` `width.content`; `alignment.self` center;
       `alignment.text` center; `spacing.margin.top` lg.
  2. **Content** — empty container.
  3. **Links** — container; inner flex row, wrap, column gap `spacing.xl`, row gap `spacing.md`,
     `alignment.content` center; one button as starter copy.

### 7.4 Composition — Section, reversed

Per-item order is excluded (§3.8), so the reversed composition places **content before the header
group in the DOM**, links last. **Recorded behaviour change:** reading order now follows visual
order (content, header, links) where today's reverse kept header first in the reading order.

### 7.5 Composition — Section split (horizontal)

- Same children as §7.3, left-aligned (`alignment.text` start on headline, title and
  description; `alignment.self` start on description; `alignment.content` start on links).
- Inner: `layout.display` flex column at `base` and `md`; **grid at `lg`**, `layout.columns` 2,
  `layout.align_items` center, `layout.gap.column` `spacing.2xl`.
- `lg` begins at 1024px = 64rem, matching today's threshold. The equal-two-column token is `2`.

### 7.6 Parity matrix

The retirement proof covers every Section configuration, independent of the preset's starter
copy (one starter button does not prove multi-link support):

- Orientation vertical and horizontal, each normal and reversed.
- Background none, muted, subtle, inverted (inverted: surface `color.text` ground, text
  `color.accent-contrast`, description at 72%).
- Headline, title and description alignment set independently (start, center, end each).
- Absent header fields: no headline; no title; no description; all three absent.
- Empty content; content with blocks.
- No links; one link; several links wrapping.

Checked at `base`, `md` and `lg`: same elements, heading level, accessible names and reading order
(except §7.4's recorded change), and computed-style equivalence for spacing, width, alignment,
colour and type size. Composition introduces different wrapper elements; parity is semantic and
visual, not DOM-identical. Any computed difference the matrix finds is either fixed or recorded
here as an accepted behaviour change before Section is deleted.

## 8. Retirement

In the same release, found by an inventory grep at the start of the plan:

- The `columns`, `grid` and `section` starters, templates and CSS.
- Admin columns special cases: `ColumnsLayoutField`, the Block tab's columns visibility rule in
  `BlockFields`, the outline's `col_3` rule.
- `columns` in the header and footer region allowlists (`container` stays).
- Migration 028 (section per-part alignment) and its test — it exists only for Section and acts
  on nothing in a fresh install.
- Every shipped reference rewritten to containers: block factory spec fixtures, the builder proof
  fixtures (the fixture script rebuilds its page from container compositions), `THEMING.md` and
  other docs, and any starter content in the repository.

## 9. Proofs

- **Contract:** validation of every property and value; capability and target mapping; dormancy
  per breakpoint (§3.3 cases); gutter cases (§3.4); span clamping (§3.7 cases); the centred
  half-screen band with root padding (§3.5).
- **Render:** container parity for every old width, min height and content alignment; `layout.item`
  participation on the public page and the annotated stage; nested-clamp release (§3.6); Section
  parity matrix (§7.6).
- **Admin:** per-property tab membership; the Layout tab's four sections across block, flex and
  grid parents; Button and Navigation keeping `alignment.content`; dormant notices both ways,
  including class-supplied values; mixed sibling selection.
- **Picker:** bridge DOM specs for offer, choose and skip; qualification exclusions (preset
  children, duplicate, paste, restore, redo); duplicate choose messages; Skip while the factory is
  loading; factory failure; emptiness lost between offer and commit; legality refusal refreshing
  reasons; Stack no-op; undo restoring exact settings; redo reusing ids.
- **Browser:** choose 33/67 → one history entry → undo to empty → redo with the same ids; a
  parent mode switch updating its children; the existing proofs rebuilt on container fixtures.
- **Gates:** full PHP suite, phpcs, boundaries, skeleton and distribution smoke, admin suite,
  type-check, lint, format, browser proofs.

## 10. Delivery

One release, one beta cut at the end. Build order on `dev`, each phase ending with its gates green:

1. **Contract and proofs** — properties, compiler support, target contracts, fixture proofs. The
   overlapping managed properties stay unavailable on the production container until its cutover,
   API validation included.
2. **Container cutover and Layout tab** — the container switches to the new settings; its
   superseded data fields and rendering paths are deleted; parent and item controls ship
   together.
3. **Structure picker** — the creation flow with atomic history and commit-time legality.
4. **Retirement** — Columns, Grid and Section removed after their compositions are proven, with
   shipped content, presets, allowlists, fixtures and docs updated in the same phase.
