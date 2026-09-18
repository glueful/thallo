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
  z-index and positioning, per-item order, masonry (§3.9).

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
- **Nested clamps (theme defaults only).** Heading, rich text, button and other leaves carry a
  default page-level containment in the theme (max width, auto margins, inline padding). When the
  block's parent is a container, only that **default** containment is released. Declarations from
  the instance or a style class — `width`, `alignment.self`, padding, `layout.basis` — still win.
  Reset returns a property to the default appropriate to the block's current nesting context.
- **Proof:** moving a block into a container and back out preserves every authored setting, and
  its unauthored properties follow the context's default, on both the public and annotated
  renders. Section's constrained description (§7.3) is the authored case.

### 3.7 Span clamping

- `layout.span` resolves against the parent's effective track count at the same breakpoint,
  inherited values included. A span larger than the available tracks fills the row instead of
  creating implicit tracks.
- The mechanism stays open until these pass: mobile stacking, asymmetric presets, inherited spans,
  nested grids.

### 3.8 Spacing inside a container

Theme defaults only; authored margins and paddings always win.

- Every block carries the theme's default vertical margin (`.thallo-block { margin-block:
  var(--space-5) }`).
- **Flex and grid modes:** the default vertical margins of the container's direct children are
  released; spacing between items comes only from `layout.gap.column` and `layout.gap.row`.
- **Block mode:** children keep their default margins, except the first child's top margin and
  the last child's bottom margin, which are released so the container's own padding governs its
  edges.
- **Rich text:** the first child element's top margin and the last child element's bottom margin
  inside a rich-text block are released by default, so a single-paragraph rich text contributes no
  paragraph margin of its own. Spacing between its paragraphs is unchanged.
- **Proof:** a container with default-margin children in each mode, and a single-paragraph and a
  multi-paragraph rich text, compared against the declared result.

### 3.9 Exclusions

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

- **Parity.** Every container case is measured against the rendering frozen before the cutover, at
  each width and in both renderings. Two closed tables account for the differences a correct
  cutover still produces: §7.10 for a leaf's page containment released inside a container, and
  §7.11 for where a container's spacing comes from. A difference neither table lists fails, and is
  fixed rather than dispositioned.

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
  not make every item property available. **As an item** controls are unavailable for a selection
  spanning different parents.
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
- **An offer ends when content arrives, at any point in its life.** Any insertion or move into the
  container — while the offer is merely pending or while a choice is preparing — consumes the
  offer. Undoing that insertion does not reopen it. Skip and deletion of the container also end
  it.

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

- **Consumption.** Every successful preset consumes the offer, Row and Stack included. An offer
  already ended by §6.1 (content arriving, Skip, deletion) rejects any later choose, including a
  delayed one; a late factory answer cannot resurrect it.
- **Undo** restores the exact previous document, settings included, and does not reopen the
  picker. **Redo** replays the same operations with the same ids.
- **Factory failure** leaves document and history unchanged; the offer stays pending.

### 6.4 Presets as scoped patches

- Each preset declares the paths it **owns**. For every owned property it writes the complete
  responsive result at all three breakpoints, so an existing `lg` override cannot defeat the
  arrangement.
- **"Cleared" follows the cascade.** Deleting a declaration exposes any style-class declaration
  beneath it, so deletion never guarantees a preset's result. For every owned property the preset
  writes, at every breakpoint, one of:
  - **a value**, where the preset requires a particular result;
  - **a reset**, where the preset requires the theme default;
  - **deletion**, only where the preset deliberately restores inheritance from a class or a lower
    breakpoint — listed explicitly in the preset.
- **Proof:** a container carrying a style class with conflicting `lg` values for owned properties;
  choosing a preset resolves to the preset's result at every breakpoint, and undo restores the
  exact prior instance declarations. Unowned values (width, gutter, spacing, backgrounds, style-class references) are
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
| Two columns 50/50, 33/67, 67/33, 25/75, 75/25 | `layout.display` = grid, `layout.columns` = 1 / 2, 1-2, 2-1, 1-3, 3-1, `layout.gap.column` = lg, `layout.gap.row` = lg | two column containers |
| Three columns, 25/50/25, 50/25/25, 25/25/50 | `layout.display` = grid, `layout.columns` = 1 / 3, 1-2-1, 2-1-1, 1-1-2, both gaps = lg | three column containers |
| Four columns | `layout.display` = grid, `layout.columns` = 1 / 4, both gaps = lg | four column containers |
| Grid 2×2 | `layout.display` = grid, `layout.columns` = 1 / 2, both gaps = lg | four column containers |
| Section, Section split | §7 | §7 |

A **column container** created by a preset is: `layout.display` flex, `layout.direction` column,
`layout.gap.row` = md. This reproduces today's Columns rhythm: `--space-3` between blocks, none at
the column's edges (§3.8).

## 7. Compositions for the retired types

Each retired type is deleted only after its matrix passes: Section §7.6–§7.7, Columns §7.8, Grid §7.9,
with §7.10 for leaf blocks inside any of them and §7.11 for the spacing differences every
composition meets. The container cutover (§4) is measured against the same frozen references and
dispositioned by the same two tables.

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
`layout.columns` in the vertical Section) are written as a reset at every breakpoint (§6.4). All
are written in the one transaction; undo restores each previous value exactly.

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

Per-item order is excluded (§3.9), so the reversed composition places **content before the header
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
  `color.accent-contrast`, description at full strength per §7.7).
- Headline, title and description alignment set independently (start, center, end each).
- Absent header fields: no headline; no title; no description; all three absent.
- Empty content; content with blocks.
- No links; one link; several links wrapping.

Checked at `base`, `md` and `lg` widths: same elements, heading level, accessible names and reading order
(except §7.4), and computed-style equivalence for spacing, width, alignment, colour and type size,
**except the differences in §7.7**. Composition introduces different wrapper elements; parity is
semantic and visual, not DOM-identical.

**Spacing normalization.** The header, content and links containers are flex or block containers
whose default margins §3.8 releases; the headline and description are single-paragraph rich texts
whose paragraph margins §3.8 releases. Their spacing is therefore exactly the authored margins in
§7.3 and the inner row gap. The matrix proves that and admits no spacing difference **for the
composed header, content and links containers**. Author-placed leaf blocks inside the content and
links areas follow §7.10.

### 7.7 Disposition of known differences

This table is closed. A difference the matrix finds that is not listed here fails the retirement
gate; it is fixed, or this table is amended by explicit decision before Section is deleted.

| Element | Today | New | Disposition |
|---|---|---|---|
| Title size | fixed `2rem`, line height 1.15, letter spacing −0.02em, bold | theme `h2`: `clamp(1.5rem, 1.2rem + 1.2vw, 2rem)`, same line height, letter spacing and weight | **Intentionally changed.** Equal from about 1067px; smaller below, down to 1.5rem. |
| Description size | fixed `1.125rem` | `typography.size.lg`: `clamp(1.125rem, 1rem + 0.5vw, 1.35rem)` | **Intentionally changed.** Equal at the narrowest widths; up to 1.35rem at wide ones. |
| Inverted description colour | `--accent-ink` at 72% | `color.accent-contrast` at full strength | **Intentionally changed.** The closed colour vocabulary has no reduced-strength token; the description reads at full contrast on the inverted band. |
| Reversed reading order | header, content, links | content, header, links | **Intentionally changed** (§7.4). |

### 7.8 Columns retirement

**Today:** a grid with `gap: var(--space-4)`, contained with `--space-4` inline padding; ratios
50/50, 33/67, 67/33, 25/75, 75/25, equal thirds, 25/50/25, 50/25/25, 25/25/50; vertical alignment
stretch, top, center, bottom; **stacks at 40rem (640px) and below** (`max-width: 40rem`), side
by side from 641px; blocks inside a column spaced `--space-3`, none at the column's edges.

**Composition:** the matching column preset (§6.5) inside a container with `layout.content_width`
`width.container`; vertical alignment maps to `layout.align_items` (stretch, start, center, end).

**Matrix:** every ratio; each vertical alignment; one and several blocks per column (heading,
rich text and button fixtures, §7.10); empty columns; reading order column by column. Checked at
widths 375px, **700px**, 800px and 1280px — 700px sits inside the recorded change.

**Recorded difference:** columns sit side by side from 768px (`md`) instead of 641px. From 641px to
767px they now stack. The contract's breakpoints are 768px and 1024px, and a 640px
breakpoint is not being added.

### 7.9 Grid retirement

**Today:** one column at base; **two columns from 40rem (640px, inclusive)** for counts 2, 3 and 4;
three or four columns from 64rem (1024px); count 1 is one column at every width; gaps small, medium, large = `--space-3`, `--space-4`, `--space-5`; items with no
vertical margin; contained with `--space-4` inline padding.

**Composition:** a container with `layout.content_width` `width.container`, `layout.display` grid,
`layout.columns` per this table, all three breakpoints written; gaps small, medium, large →
`spacing.md`, `spacing.lg`, `spacing.xl` for both gaps; items' default margins released by §3.8.

| Old count | `base` | `md` | `lg` |
|---|---|---|---|
| 1 | 1 | 1 | 1 |
| 2 | 1 | 2 | 2 |
| 3 | 1 | 2 | 3 |
| 4 | 1 | 2 | 4 |

**Matrix:** counts 1, 2, 3 and 4; each gap; item counts that do and don't fill the last row;
heading, rich text and button items (§7.10); reading order row by row. Checked at widths 375px,
**700px**, 800px and 1280px — 700px sits inside the recorded change.

**Recorded difference:** for counts 2, 3 and 4 the two-column step starts at 768px (`md`) instead
of 640px; from 640px to 767px they now show one column. Count 1 is unchanged. Masonry is retired separately (§3.9) and is not part of this matrix.

### 7.10 Leaf blocks placed inside a container

Today a heading, rich text or button placed inside a Section's content or links, a Columns
column, a Grid, **or a container** keeps its page-level containment. In the compositions its
parent is a container, so §3.6 releases that default. Preserving the old appearance through
authored settings would restore the doubled gutter inside every cell, so the difference is taken
as a change. This table is closed and applies to all four matrices — the three retired types and
the container cutover (§4), whose own compositions meet the same rule because the rule is about
the parent a leaf ends up in, not about which type it came from.

| Leaf | Today, inside a retired type or a container | New, inside a container | Disposition |
|---|---|---|---|
| Heading | `max-width: var(--content)`, auto margins, `padding-inline: var(--space-4)` | fills its layout cell; no inline padding | **Intentionally changed.** Text aligns with the cell's edge instead of indenting by `--space-4`, and in cells wider than `--content` it spans the cell. |
| Rich text | `max-width: var(--content)`, auto margins, `padding-inline: var(--space-4)` | fills its layout cell; no inline padding | **Intentionally changed**, as for Heading. |
| Button | `max-width: var(--container)`, auto margins, `padding-inline: var(--space-4)` | fills its layout cell; no inline padding | **Intentionally changed.** The control aligns with the cell's edge; in Section's links row adjacent buttons are separated by the row's gaps only. |

**Fixtures:** each leaf alone and several together, inside a Section content area, a Section links
row, a Columns column, a Grid cell and a container's content area; each fixture also with an
authored `width`, `alignment.self` and padding, which must render exactly as authored (§3.6).

### 7.11 Spacing differences in every composition

§3.8 changes where a container's spacing comes from, so every matrix meets these three
differences wherever the composition puts blocks inside a container. This table is closed on the
same terms as §7.10.

| Difference | Today | New | Disposition |
|---|---|---|---|
| First and last child, block mode | the child's own `margin-block: var(--space-5)` at both edges | the first child's top margin and the last child's bottom margin released | **Intentionally changed.** The container's own padding governs its boundary, so a band's padding is what it says it is instead of adding to a child's margin. |
| Every child, flex and grid modes | the child's own `margin-block` alongside the container's gaps | released; spacing comes from `layout.gap.column` and `layout.gap.row` | **Intentionally changed.** One source of spacing between items, so a gap of `none` means none. |
| A rich text's outer paragraphs | the first paragraph's top margin and the last paragraph's bottom margin | released | **Intentionally changed.** A single-paragraph rich text contributes no margin of its own; spacing between its own paragraphs is unchanged. |

A container that centres its content is a flex column in the composition (§3.5), so the flex row
of this table applies to it: today's centred band spaced its children by their own margins, and
the composition spaces them by its gaps.

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
  half-screen band with root padding (§3.5); spacing normalization (§3.8).
- **Render:** container parity for every old width, min height and content alignment; `layout.item`
  participation on the public page and the annotated stage; nested-clamp release with authored
  settings preserved into and out of a container (§3.6); Section parity matrix with its closed
  disposition table (§7.6, §7.7); Columns matrix (§7.8); Grid matrix (§7.9); leaf-block fixtures
  with their closed disposition table (§7.10) and the spacing differences (§7.11).
- **Admin:** per-property tab membership; the Layout tab's four sections across block, flex and
  grid parents; Button and Navigation keeping `alignment.content`; dormant notices both ways,
  including class-supplied values; mixed sibling selection.
- **Picker:** bridge DOM specs for offer, choose and skip; qualification exclusions (preset
  children, duplicate, paste, restore, redo); duplicate choose messages; Skip while the factory is
  loading; factory failure; emptiness lost between offer and commit; offer → drop content → undo →
  delayed choose records no picker transaction; legality refusal refreshing reasons; Stack no-op;
  a preset over a style class with conflicting `lg` values (§6.4); undo restoring exact settings;
  redo reusing ids.
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
4. **Retirement** — Columns, Grid and Section removed after their matrices (§7.6–§7.10) pass, with
   shipped content, presets, allowlists, fixtures and docs updated in the same phase.
