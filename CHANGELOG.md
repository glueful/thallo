# Changelog

All notable changes to Thallo are documented here. Format:
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/); versioning:
[SemVer](https://semver.org/spec/v2.0.0.html). Release tags are immutable — corrections ship
as the next release, never a mutated tag.

## [Unreleased]

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md). `thallo:provision` brings the Feature
  block's new Marker settings to an existing install. The style schema and the compiler each move
  by one version, so the compiled stylesheet is rebuilt under a new hash on the first request
  after PHP-FPM is reloaded. A theme that overrides `feature.twig` keeps working; to make the
  marker styleable, add `{{ style_classes('marker') }}` to the marker's class attribute
  (THEMING.md §12.3).
- A theme of your own should define `--radius-sm` and `--radius-md` beside `--radius` and
  `--radius-lg` if it reuses the default theme's block styles.

### Added
- **A feature's marker has its own corners and shadow**, in the Style tab under **Marker** — for
  its icon chip or its number badge. They are separate from the block's own Corners and Shadow
  under Effects, which stay the card's: a round, lifted badge on a square card, or the reverse.

### Fixed
- The default theme's badges and tabs had square corners where its stylesheet says rounded: the
  feature block's number badge and icon chip, the tabs' pill strip and its tabs, and the boxed
  tabs. They read `--radius-md` and `--radius-sm`, which the theme never defined, and a declaration
  that reads an undefined variable silently does nothing. Both are defined now — 6px, and the
  theme's base radius — matching the Style tab's own `sm` and `md`. The code block's text colour
  read an undefined variable too, and now names the theme's text colour.

## [1.0.0-beta.43] - 2026-09-19 — Developer Preview

The Block tab edits a block completely, a shortcode can be styled, and a shell snippet reads as a
terminal.

### Upgrade Notes
- No migrations, no new permissions, no dependency changes.
- The documented sequence applies (docs/upgrading.md). `thallo:provision` brings the Shortcode
  block's new style settings to an existing install. A theme that overrides
  `shortcodes/thallo-version.twig` or `shortcodes/copyright.twig`, or ships shortcodes of its
  own, keeps working unchanged; to make one styleable, add `{{ style.classes|default('') }}`
  inside its element's class attribute (THEMING.md, "shortcode").

### Added
- A Shortcode block can be styled from the Design view: **background, text and border colour,
  border, radius and shadow**. They land on what the shortcode renders — the version pill, the
  copyright line — and not on the full-width wrapper around it, where a background would have
  painted a bar across the page. Spacing, visibility and the item settings stay on the wrapper.
- **A shell snippet reads as a terminal.** In a Code block set to `bash`, a line you start with
  `$ ` shows its prompt in the accent colour and a line starting with `#` is muted. The prompt is
  drawn, not written: the Copy button — and a selection made by hand — takes the command without
  it, and the copied text no longer ends in a newline, which pasted into a terminal would have run
  the last command. A long command still wraps rather than scrolling, and now wraps under the
  command instead of under the prompt. Other languages are untouched.
- The version pill's dot follows the text colour, so recolouring the text brings it along. Two
  entries in the shortcode's params adjust it: `"dot": false` hides it, and `"dot_color"` takes
  one of the theme's colour names (`accent`, `text`, `muted`, `accent-contrast`, `background`).

### Changed
- **In the Design view, the Block tab's Content is the block's whole form**, as the main Content tab
  has it. A field that holds other blocks — a container's content, a hero's links, an accordion's
  items, a tab set's tabs — now shows those blocks as cards you can open, edit, reorder, duplicate
  and remove, where it used to be one line ("links: 2 blocks") and an Add button; you no longer
  leave the block to work on what is inside it. A rich text body has its editor there too, where
  the tab used to say only "Edit the text directly on the stage": useful for a block hidden at the
  breakpoint you are viewing, a narrow column, or a long text. While that text is being edited on
  the stage the panel's editor is read-only and says so, so the two never hold it at once.
- The default theme draws the Code block as a window: a tinted title bar with three lights over a
  light body, and a filled Copy button. A theme that overrides `blocks/code.twig` keeps its own
  markup; to get the prompt and comment treatment, copy the `bash` branch of the shipped template.

## [1.0.0-beta.42] - 2026-09-18 — Developer Preview

The Design view's side panel fits its content: no sideways scroll, and nothing under the scrollbar.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM. No migrations, no new permissions, no
  contract or theme changes — this release changes the admin only.

### Fixed
- The Design view's side panel scrolled sideways and its scrollbar sat on top of the content —
  over the state badges, the breakpoint chips, the link toggle, the last column of track swatches,
  and the right-hand tiles of the Blocks tab. The panel's content ran flush to the edge it scrolls
  at, which is where macOS draws its scrollbar, and a marker that overhangs the last breakpoint
  chip by two pixels was enough to make the whole panel scroll — whenever a setting was made at
  the desktop breakpoint, the one the page opens on. The panel now keeps a gutter clear of the
  scrollbar on every tab, and its content keeps the width it had.
- In the Outline, the row for an empty slot was wider than the panel by exactly its indent, so a
  nested empty container made the panel scroll sideways, further with every level.

## [1.0.0-beta.41] - 2026-09-18 — Developer Preview

A container is Flex or Grid and nothing else; a grid can be seen and filled on the stage; and a
style class can edit the layout it carries.

### Upgrade Notes
- **Breaking, with no content migration.** A container's layout is now Flex or Grid; the stacked
  ("block") mode that beta.40 offered is gone. A container that was never given a mode needs
  nothing: the default is a flex column that spaces its children as the stack did. A container or
  a style class that *stored* the stacked mode is invalid — saving it is refused, naming the
  field — and the Layout tab shows it as such with the way out (below).
- The documented sequence applies (docs/upgrading.md), and nothing more is needed for the
  stylesheet: the style schema and the compiler each move by one version, both are part of the
  compiled stylesheet's hash, and it is compiled under the new hash on the first request after
  PHP-FPM is reloaded. No migrations, no new permissions.
- A theme that ships its own `blocks.css` must carry the container's new defaults
  (THEMING.md §12.3a): the content area is a flex column with a `--space-5` gap, and no child of a
  container has a default vertical margin in any mode.

### Added
- A grid is drawn on the stage. The Design view outlines a grid container's tracks — while it is
  empty, while it or one of its children is selected, and while a block is dragged over it — so
  choosing Grid and a track count shows something. The outline follows the breakpoint being
  edited, takes no clicks, and exists only in the editor: nothing is stored and nothing reaches
  the public page. An empty grid's "Drag a block here" now sits in the first cell rather than
  across the whole row.
- **Fill empty cells.** A grid whose last row has room offers to complete it with column
  containers, each a place to build on its own — from the Layout tab under the Grid controls, and
  from the placeholder of an empty grid on the stage. The whole fill is one change, so undo takes
  every cell back together. When it cannot run the button stays, disabled, and says why: the last
  row is full, or the grid sits too deep for a cell to hold a block.
- A layout the contract no longer offers is shown as invalid rather than hidden. The Layout tab
  names the value and the breakpoint it sits at and offers **Replace with Flex** and **Remove**;
  when a style class supplies it, the tab names the class and opens it, and the class editor lists
  it under "Needs attention" with the same two actions.

- **A Layout tab in the style class editor.** A class can carry width, placement, content
  alignment and every layout setting — mode, direction, wrap, tracks, alignment, gaps, content
  width, gutter, minimum height, overflow, and the item settings — and now has somewhere to edit
  them, beside Style. A class is applied to many blocks in many places, so its tab hides nothing
  on the strength of a mode or a parent it does not have: every setting is always there, under a
  label saying where it takes effect — Applies in Flex, Applies in Grid, Applies in a Grid parent,
  Applies in a Flex parent. Where a class sets one mode and also holds the other's settings, the
  tab says they are retained and where they apply, and predicts nothing: a block or another class
  may set the mode differently and still take this class's direction. Both tabs carry a standing
  note that a declaration applies only to blocks that support that property.

### Changed
- A container arranges its children as **Flex or Grid** — the separate stacked mode is removed,
  since a flex column is a stack. An untouched container is a flex column whose gaps default to
  the theme's block spacing (`spacing.xl`), so a stack keeps the distances it had. One rule covers
  both modes and both axes, with a recorded consequence: a flex row or a grid whose gaps were never
  set had none, and now gains `spacing.xl` between its items. Set the gap to None to have them
  touch again.
- The Layout tab opens on **Container**, then Box, then As an item. The mode is set once, under
  the label Layout, as Flex or Grid, and the controls that mode uses — direction and wrap, or
  tracks, then alignment and gaps — sit directly beneath it; the separate Children section is
  gone. Controls that are unset show the default in force (dashed) instead of reading as empty.
- An authored **Width** now asks for the width as well as limiting it: "fill the available space, up
  to this maximum". Inside a container's default column a placed block therefore fills up to its
  width instead of shrinking to its text, and in a flex row an authored width is the block's
  starting size — which can change how a row's items are sized and where they wrap. A theme that
  does not use `box-sizing: border-box` must account for a padded block with an authored width.

- **In the style class editor, a setting says what the class declares.** An untouched setting
  read "theme", which is true of a block and not of a class: what a block ends up with is decided
  by its other classes, its own settings and the theme. It now reads **Not set in this class** —
  only when nothing reaches that breakpoint from an earlier one — and otherwise **Inherited from
  base** (or md), naming the breakpoint that declares it, **Theme default, set here**, or **Theme
  default, from base**. A setting that does not vary by screen size — overflow, radius, the
  colours, the border — says **Applies at all sizes**. The two actions are named for what they do:
  **Remove** deletes the declaration at the breakpoint being edited, which may bring an earlier
  one back into view, and **Use theme default** sets the theme's value from that breakpoint up.
  The block inspector's wording is unchanged.

### Fixed
- Since beta.40 a style class could *hold* width, placement, content alignment and layout settings
  — Save as style class lifts them, and they take effect on the page — and could not show or edit
  any of them: those properties had moved to the block inspector's Layout tab, and the class
  editor had only Style. See the new Layout tab, above.
- In the style class editor, linked sides — padding, margin, and now gap — saved only one of the
  sides they were meant to set: one click writes every side, and each write was built on the value
  from before the click. All of them are kept now.
- A style class that could not be saved said only that: the confirm dialog stayed open over the
  form and nothing named the problem. The refusal now closes the dialog, lists each refused field
  above the editor — the setting, its breakpoint and the reason — and leaves everything you typed
  in place. A class that still holds a layout value the contract no longer offers is refused
  until that value is repaired, whatever else you were editing; it is listed under Needs
  attention on the same page, and once repaired the same draft saves.
- In the Design view, ⌘Z and ⇧⌘Z did nothing after a click on the stage — selecting, moving,
  duplicating or deleting a block there — because the keystroke stayed in the preview and never
  reached the editor; only the toolbar's buttons worked. The stage now passes undo and redo on.
  While you are typing in a block, ⌘Z is still the undo of your typing.
- The Design view of a page that had never held a block — created with a title and nothing else —
  showed no "Drag a block here" and no +, and a block dragged onto the empty page did nothing;
  only clicking a block in the Blocks tab worked, after which both appeared. The stage now has its
  empty body to drop into from the start.

## [1.0.0-beta.40] - 2026-09-18 — Developer Preview

Layout is a setting: one Container arranges its children as a stack, a flex row or a grid, and
Columns, Grid and Section are compositions of it.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. No migrations, no new permissions; framework 1.85.8 remains the requirement.
- **Breaking, with no content migration.** The Columns, Grid and Section blocks are removed, and
  the Container's layout moved from data fields to style settings. Stored content is not
  converted: a Columns, Grid or Section block already in an entry renders nothing on the public
  page (an HTML comment, and a logged miss), and a Container keeps its children but loses the
  width, minimum height and alignment it was given. Rebuild those parts from a Container — the
  structure picker's presets produce each of the old arrangements.
- A theme that overrides `container.twig` must follow the new contract (THEMING.md §12.3a): the
  root tag comes from `data.element`, and `style_classes('inner')` goes on the inner element.
  Overrides of `columns.twig`, `grid.twig` and `section.twig` are no longer rendered.
- Column layouts now begin at 768px rather than 641px.

### Added
- A Layout tab in the block inspector, beside Content: Box (width, placement, minimum height,
  overflow), Container (how children are arranged, the content width and its gutter) and Children
  (the controls the mode in force actually uses — tracks for a grid, direction and wrap for a flex
  row). A block sitting inside a container also gets As an item: span against a grid parent, basis,
  grow and shrink against a flex one. Tab membership is per property, so a control appears wherever
  it belongs rather than wherever its capability group does.
- Layout is part of the style contract: display, direction, wrap, alignment, track counts, both
  gaps, content width, gutter, minimum height and overflow, each responsive and resettable per
  breakpoint, each written as a class the compiled stylesheet carries.
- A structure picker: a container you have just inserted offers Stack, Row, the column splits, a
  grid and the two Section compositions in its empty slot. Choosing one writes the whole
  arrangement as a single change, so undo takes it back in one step.
- Switching a container between stacked, flex and grid keeps the settings the other mode used, and
  the tab says which ones are being kept and ignored.

### Changed
- The Container carries its layout as settings rather than data fields, and gains an element
  choice: div, section, article, aside, header or footer.
- Heading and Rich text gained Placement and width, so a block can size and place itself inside a
  container.
- A block that clamps itself to the page measure has that clamp released inside a container, so it
  fills its cell instead of carrying a second gutter into it. An authored width, placement or
  padding still wins.
- Spacing inside a container comes from the container: the gaps space the children in flex and grid
  modes, and the container's own padding governs its edges. A single-paragraph rich text
  contributes no margin of its own.
- A Section composition's title and description take the theme's heading and text scales, so they
  are smaller on narrow screens and larger on wide ones than the fixed sizes they replace. On an
  inverted band the description reads at full contrast.
- A reversed Section composition places the content before the header in the reading order, which
  now follows the visual order.
- Column layouts sit side by side from 768px rather than 641px, and stack below that; a Grid's
  two-column step likewise begins at 768px. The contract's breakpoints are 768px and 1024px.

### Fixed
- Clicking a block on the stage while the editor was still loading could leave the Block inspector
  on "Select a block on the stage or in the outline" for a block the stage showed as selected,
  until it was selected again. The inspector now picks the block up as soon as the editor is ready.
- A block clicked on the stage before the page's schema had loaded was not selected at all, though
  the stage ringed it. The click is now kept and the block selected once the schema arrives; a
  later click or a deselect replaces it.

### Removed
- The Columns, Grid and Section blocks, and the masonry flow. A Container composition replaces
  each: columns and grids are a container with track settings, and the two Section presets build
  the band, its header group, its content area and its links row.

## [1.0.0-beta.39] - 2026-09-17 — Developer Preview

A page styles itself: padding, margin and background from the Page tab.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. No migrations, no new permissions; framework 1.85.8 remains the requirement.
- A theme that overrides `layout.twig` should add `presentation.style_classes` to its `<main>`
  class list for the Page tab's Styles to reach its pages.

### Added
- The Page tab has a Styles section: the page's own padding and margin (box rows, per
  breakpoint) and background (a theme colour token), painted on the page's main element with
  the same utility classes blocks use, saved and published with the page under
  `_presentation.style`. Unset keeps the theme's.

## [1.0.0-beta.38] - 2026-09-17 — Developer Preview

The Style tab's spacing as box rows, and the empty-block stub no longer claiming separators.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. No migrations, no new permissions; framework 1.85.8 remains the requirement.
- The preview bridge changed (the empty-block rule). Provision publishes it with the admin
  bundle; a stage still holding the old bridge reloads on the next apply.

### Changed
- The Style tab's four-sided properties (padding, margin) present as one box row each: a cell
  per side showing its token and state, a link toggle that writes every side at once, and the
  token pills opening under the cell you click. The breakpoint chips sit once on each group's
  header instead of on every row. Every write, breakpoint and reset path is unchanged.

### Fixed
- The stage's empty-block stub (beta.37) also claimed a separator, whose line is drawn by CSS
  alone. A block now counts as empty only when it holds nothing AND paints no box.

## [1.0.0-beta.37] - 2026-09-16 — Developer Preview

Blocks that build the landing page: styleable tabs that switch on the stage, a sized feature
marker, an aligned call-to-action row with a description that takes its colour, a white token,
and an empty block that can no longer hide from the canvas or the publish error.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. Three migrations (030, 031, 032) append optional fields to the `tabs`, `feature` and
  `cta` block types; each keeps the row's label and description and runs once. Migration 030
  also adopts the tabs starter's new style declaration on a row that still carries the old one.
  No new permissions; framework 1.85.8 remains the requirement.
- The colour vocabulary gains `white`. A theme copied before this release loads unchanged: the
  token carries a literal default when a manifest omits it. A theme that wants its own value
  maps `color.white` in theme.json.
- The preview bridge and stylesheet changed (empty-block stub, tab switching on the stage).
  Provision publishes them with the admin bundle; a stage still holding the old bridge reloads
  on the next apply.
- The tabs, feature and call-to-action templates' markup changed; a theme that overrides them
  keeps its own markup, one that only styles them should check the new modifiers in blocks.css.

### Added
- The tabs block is styleable: a Tabs group in its Block tab sets the strip's variant (pill,
  underline, boxed), alignment, and the strip, tab, active-tab background and text colours from
  the theme's colour tokens; a Panel group sets the padding around the shown panel; and the
  Style tab's colours, radius, border and shadow land on the one panels area, whichever tab is
  shown, rather than on each tab. Migration 030 adds the fields to an existing install and, on a
  tabs row still carrying the starter's old style declaration, adopts the new one.

- The feature block's marker takes a size (small, medium, large, extra large) that scales the
  icon and the number badge alike, beside its existing colour. Migration 031 adds the field.

- `white` joins the theme colour tokens: a literal `#ffffff` in every scheme, for text on an
  accent or inverted band. A theme copied before this still loads; it may map the token itself.
- The call-to-action block aligns its buttons row (start, center, end) from a Links group in its
  Block tab; unset keeps the orientation's default. Migration 032 adds the field.

### Fixed
- The call-to-action's description ignored the panel's text colour from the Style tab, since the
  theme pinned it to the muted token; it now softens whatever text colour the panel has.
- A page-level separator spanned the viewport instead of the page's width; it now carries the
  same width clamp as the other page-level blocks, released by the full-width layout.
- Tabs could not be switched on the stage: every in-block click is inert there, so a tab label
  never reached its radio. A label click now switches the tab and selects that tab's block, and
  selecting a block inside a hidden panel (from the outline) brings its panel forward.
- A block that paints nothing (a feature with no title, marker or description) was invisible on
  the stage yet still in the document, so a publish could fail on a block nobody could see.
  The stage now shows such a block as a labelled stub ("Empty feature — select it to add
  content, or delete it"), a refused publish selects the block it names and the toast says
  which block and field ("Feature: title is required"), and a block card's summary in the
  block list reads the block's title before an icon name, never an enum choice.

## [1.0.0-beta.36] - 2026-09-16 — Developer Preview

The feature block builds the landing page's cards, the stage placeholder fills its row, and the
site's custom stylesheet reaches the page on a web server that serves `.css` from disk.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. One migration (029) appends six optional fields to the `feature` block type; it keeps
  the row's label and description and runs once. No new permissions; framework 1.85.8 remains
  the requirement.
- The site's custom stylesheet moved from `/custom.css` to `/_thallo/custom.css`. An install
  whose web server already routes `/_thallo/*` to PHP (docs/production.md) needs nothing; a
  rule added by hand for `/custom.css` can go. Purge `/custom.css` from any CDN cache.
- The feature template's markup changed (title and description now sit in one body element);
  a theme that overrides `blocks/feature.twig` keeps its own markup, one that only styles it
  should check `.thallo-block-feature__body`.

### Added
- The feature block builds a card: a marker choice (the icon, a number badge such as "01", or
  none) with the badge's background and colour picked from the theme's colour tokens, a variant
  (plain, outline, soft, subtle — the card block's names) and an orientation (the marker beside
  the text or above it). Title and description now stack in one body whatever the layout.
  Migration 029 adds the six fields to an existing install's feature block type.

### Fixed
- The stage placeholder inside a grid or a flex row took one cell or one item's width; it now
  spans the slot's full row.
- The site's custom stylesheet was served at `/custom.css`, outside the documented PHP-served
  prefixes, so a web server with a static-file rule for `.css` answered it 404 and the rules
  never reached the page. It is now `/_thallo/custom.css`, which the documented nginx block
  already hands to PHP.

## [1.0.0-beta.35] - 2026-09-16 — Developer Preview

A one-fix release: the stage placeholder belongs at the end of the page, not after every block
inside every block.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. No migrations, no new permissions; framework 1.85.8 remains the requirement.
- The preview bridge changed (where the slot placeholder mounts). Provision publishes it with
  the admin bundle; a stage still holding the old bridge reloads on the next apply.

### Fixed
- The stage placeholder trailed every block inside every block; it now ends the page's own
  slots only, and fills a block's slot while that slot is empty.

## [1.0.0-beta.34] - 2026-09-16 — Developer Preview

The section block's headline, title and description align separately; every stage slot ends in
the placeholder that says where the next block goes; the Style tab's groups fold and stay
folded; an optional choice in the block editor can go back to its default.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. One migration (028) appends three optional fields to the `section` block type; it
  keeps the row's label and description and runs once. No new permissions; framework 1.85.8
  remains the requirement.
- The preview bridge and its stylesheet changed (the slot placeholder follows the last block of
  every slot). Provision publishes both with the admin bundle; a stage still holding the old
  bridge reloads on the next apply.

### Added
- The section block aligns its headline, title and description separately (start, center or
  end; unset keeps the orientation's default) from an Alignment group in the Block tab.
  Migration 028 adds the three fields to an existing install's section block type.
- An optional enum field in the block editor offers "Default" first, which clears the value, so
  a chosen alignment, background or orientation can go back to the theme's default.
- The Style tab's groups (Spacing, Size, Typography, Colours, Effects, Visibility) fold and
  unfold from their headers; a fold holds across blocks and sessions in that browser, and a
  folded group says how many of its properties the block sets.

### Changed
- The stage placeholder sits after the last block in every slot, not only in empty ones, so the
  next block's place is always in view; its + arms the Blocks tab at the end of that slot.

## [1.0.0-beta.33] - 2026-09-16 — Developer Preview

A day of building with the Design page: the Blocks tab grouped as cards, an empty slot on the
stage as a real target with its own +, the Block tab opening on what was just inserted, the
sidebar out of the way, and the columns picker, the blank inspector and the phantom third column
fixed.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. No migrations, no new permissions; framework 1.85.8 remains the requirement.
- The preview bridge and its stylesheet changed (an empty slot mounts a placeholder whose +
  posts `thallo:slot-add`). Provision publishes both with the admin bundle; a stage still
  holding the old bridge reloads on the next apply.

### Added
- The Blocks tab groups its tiles by category in the block-types page's order (Layout, Content,
  Media, Items, then the rest, Other last), two to a row, as bordered cards with hover and focus
  states and a grab cursor.
- A block inserted from the Blocks tab (click or drop) opens the Block tab on it, so the next
  step is configuring what just landed.
- The Block tab's slot rows carry an Add button that arms the Blocks tab into that slot of the
  selected block.
- An empty slot on the stage is a placeholder: a dashed frame, one + that arms the Blocks tab
  into that slot (`thallo:slot-add` from the bridge), and the hint "Drag a block here". The old
  placeholder was a line of text with nothing to click.
- The sidebar collapses on entering the Design page and comes back as it was on leaving; a
  sidebar the user reopens by hand while designing stays open.

### Fixed
- A block inserted from the Blocks tab below the fold was invisible: the stage now scrolls to
  the inserted block and rings it once the apply has painted it.
- The columns layout picker's three-column choice stayed at two: its two back-to-back writes
  (layout, widths) each started from the tree before the other. Writes within one tick now stage
  their result, so the second reads what the first produced.
- Deleting the selected block from the stage or the outline left the inspector blank: the Block
  tab left the strip with its selection but stayed chosen. The pane falls back to Content.
- The outline showed an empty `col_3` slot under a two-column columns block.

## [1.0.0-beta.32] - 2026-09-15 — Developer Preview

Visual builder Phase C.1: the Blocks tab is the Design page's one palette — every "add here"
surface arms it, a click inserts at the armed target, and a new block drags from the tab onto
the stage through the same coordinator and proposals as a move.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. No migrations, no new permissions; framework 1.85.8 remains the requirement.
- The preview bridge changed (`thallo:block-add-after` carries the id alone; a session leaving
  every slot posts a null proposal; `thallo:drag-drop` answers with the zone under the released
  pointer). Provision publishes the new bridge with the admin bundle; a stage still holding the
  old one reloads on the next apply.

### Added
- The Blocks tab: the Design page's one palette. Every active block type as a tile in the
  picker's order (typing a block's name offers that block first); a click inserts at an armed
  target — the stage `+` arms "after this block", the block list's gaps, its Add block button and
  the card header's `/` arm a position, the outline's empty slots arm "into that slot" — or,
  with nothing armed, after the selected block or at the end of the first blocks field. A target
  is an intent resolved when it is used: "after Hero" follows Hero, "into Columns › col_2" keeps
  landing at the slot's end, a gap dies with the next structural change and says so. A tile the
  target's allow-list refuses says why and is not clickable, but stays draggable.
- Drag a new block from the Blocks tab onto the stage. The tile keeps the pointer, the stage
  answers each hover with a zone and its legality, and the drop is the zone under the released
  pointer — never a remembered one — judged against the current document before it commits.
  Escape, a release outside the stage, or a lost pointer cancel with nothing changed.

### Removed
- The stage's add-after popover and its anchoring; the `+` arms the Blocks tab instead.

## [1.0.0-beta.31] - 2026-09-15 — Developer Preview

Visual builder Phase B: style classes as site-owned records with a per-site generation, a
lifecycle and bulk jobs; blocks five levels deep; one drag coordinator behind the stage, the
outline and the block list, with real slot geometry, sibling multi-selection, a server block
factory and browser proofs for every structural scenario.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. Provisioning runs four migrations: `style_classes`, `style_generations`,
  `style_class_jobs`, and a `lock_version` column on regions and retained entry versions. Every
  existing document stays valid.
- A new `styles.manage` permission (Experience group) gates the Style classes settings page and
  its API; the owner and admin roles receive it on provision.
- Block templates a theme overrides must name their slots: every `blocks` field is wrapped by an
  element carrying `slot_attrs('<field>')` (types that render their children inline are
  exempt). The template lint refuses an override without it; the shipped templates all carry it.
- Framework 1.85.8 is required (repinned): the five scheduled framework jobs resolved their
  logger from the container unguarded, and a skeleton install, which binds none, failed every
  tenth-minute scheduler tick. 1.85.8 guards the lookup.

### Added
- Style classes exist as site-owned records (`style_classes`) and resolve through the cascade as
  layers below a block's own settings, in the order of its `settings.classes`; nothing applies one
  yet. A reference is validated for ownership, not mere existence: an archived class the site owns
  is a valid reference (old revisions restore), an unknown or foreign id and a repeated id are
  rejected.
- The site style generation is the version of the site's style-class definitions: one row per
  site, incremented atomically inside every class write's transaction and by nothing else. Every
  render works from one generation-named snapshot of the classes; the page-cache key, the apply
  response, the canvas page (`data-thallo-style-generation`) and the stage's refresh
  acknowledgement all name that generation, and a class write purges the rendered pages.
- Style classes are managed on their own Settings page behind the new `styles.manage`
  permission (Experience group; owner and admin roles), through `/v1/admin/style-classes`: the
  list names its generation, a save carries the version it loaded and conflicts when the record
  moved on, delete archives so old revisions still restore, and before every save the page shows
  where the class is used — one reference per occurrence in a stored document across drafts,
  published entries, retained revisions and regions (the published revision counted once), each
  declared property active or dormant per block type — and says that published pages change
  immediately.
- A block composes style classes from its Advanced tab: the Style classes list (kept apart from
  CSS classes) applies a class from a picker of the site's classes, removes one, reorders by drag
  and detaches one or all — a detach writes what the class contributed into the block at every
  breakpoint, so the block keeps its look and stops following the class. The Style tab names the
  class a value comes from, marks an applied class the site no longer holds as missing, and
  offers Save as style class, which lifts the block's own declarations into a new class applied
  last, once the resolver confirms the page looks the same. Every carrier of the style
  generation — an apply, a stage refresh, a fragment swap — re-resolves inherited values when the
  site's classes changed, and a detach or a lift refetches the classes first.
- A style class has a lifecycle: archiving keeps the definition so old revisions still restore;
  "Detach everywhere" writes what the class contributed into every block that carries it and
  removes the reference, "Remove everywhere" removes the reference only and is labelled as
  changing how pages look. Both run as idempotent, pass-based queue jobs pinned to the class
  version they were queued against, holding the class locked until completion — no edit and no
  newly authored reference meanwhile, checked inside every document write — with a CLI
  counterpart (`thallo:style-classes:run-job`). Regions and retained versions now carry a lock
  version and every source persists through a conditional write, so a concurrent change is a
  refused write, never a lost one.
- Blocks nest five levels deep (section → columns → card → container → heading) on every
  surface: the validator, the renderer, the fragments and the editor agree, and a composition
  fixture proves the depth-five block's setting in Chromium, Firefox and WebKit. A drop or an
  insert is judged on the whole candidate tree — the moving blocks removed, then placed —
  against one set of rules shared with the server through fixtures: the slot exists, nothing
  moves into its own subtree, the depth cap holds for the whole subtree, the slot's allow-list
  admits every moved type (the builder always enforces it; `enforce_block_types` stays the
  server's switch), and the tabs cap holds. A refused drop says why.
- One drag coordinator generates every structural change for every surface — the palette, the
  outline, the stage and the inspector list. Movement is a proposal judged on the candidate
  tree; a drop is one operation or one transaction, so a group move replays exactly, and a
  cancel discards the session with the tree untouched.
- Block templates name their slots: `slot_attrs('field')` on the element that wraps a
  `blocks()` call renders `data-thallo-slot` on the canvas, the template lint requires one per
  `blocks` field (types that render their children inline are exempt), and an empty slot shows
  a dashed placeholder so there is always somewhere to drop. The stage drag works on that real
  geometry: the insertion line is placed inside the slot under the pointer — split left/right in
  a row, top/bottom in a column, the end of a grid with a hint to use the outline — the
  coordinator answers each proposal's legality (a refused zone turns red and says why), and a
  drop across containers applies as one transaction. The same-parent live reorder is gone.
- The Design page's outline drags and reparents blocks — into a slot, between siblings, at the
  end of a list — and its context menu offers Move to…, a dialog that names a parent, a slot and
  a position and judges the move the same way.
- New blocks come from the server block factory: `POST /block-types/{slug}/instance` returns a
  type's canonical fresh block (every blocks field an empty list, every enum field its first
  option, no id) with its starter content alongside; the editor merges the starter, mints the
  ids and inserts one block. Eight everyday types ship starter content (heading, rich text,
  button, call to action, hero, section, columns, card).
- Sibling multi-selection: shift-click extends to a range within a slot and cmd/ctrl-click
  toggles a sibling, on the stage, in the outline and in the block list. A group moves,
  duplicates, removes and styles as one transaction; the inspector shows only Style for several
  blocks, rendering the capability intersection and marking a property the blocks resolve
  differently as mixed.
- A rejected apply never becomes history: when the server refuses one transaction that is still
  the unchanged tip against the pair the request named, it is rolled back with no redo and the
  stage keeps the displayed truth; otherwise the edits stay, the toast says to undo, and the next
  apply retries from the current document.
- Browser proofs for structural editing (`admin/e2e`, Chromium): the real Design page against
  responses captured from the real controllers, proving a cross-container stage drag, Move to…
  from the outline, a rejected depth drop, a subtree whose deepest child does not fit, two
  siblings down in place, two siblings across with the index shift, and cancel — each through
  the tree, history, the accepted pair and the sent operations.

### Fixed
- The Design page handed the stage a reactive array in its highlight message, which the browser
  refused to post; every bridge message is plain data now. A stage drag started from an outline
  selection left keyboard focus in the parent, so Escape never reached the stage; the grip takes
  focus, and the parent ends the session on Escape regardless.

## [1.0.0-beta.30] - 2026-09-15

### Added
- The hero's horizontal split is a choice: equal columns, a wider copy column or a wider media
  column (three fifths to two).

### Fixed
- A publish is visible on the live site on the next request whatever the cache driver. The
  default `file` driver cannot invalidate cache tags, so rendered pages, error bodies and the
  shop's pages stayed as they were for up to the cache TTL after a publish, a menu, region,
  template or theme change; on such a driver every rendered page is dropped instead
  (`RenderedPageCachePurge`, bound by the render pack and used by core and the packs).
- Corners and shadow land where the theme paints them. The code block's framed figure, the
  cta's inner box (colours and border too) and the video's frame are their blocks' `panel` and
  `frame` style targets, and the hero's media box carries its own corners and shadow, so
  "Corners: none" and a shadow choice take effect on every one of them. The code block's
  spacing and width stay on its root. The snippet wraps long lines instead of scrolling sideways, and the Copy
  button shows in the design canvas as it does on the page.
- The stage patches in place again after an apply. Since the revision pair joined `<main>`,
  every post-apply refresh compared page shells that differed only by that pair, answered
  "shell drift" and reloaded the iframe; the comparison ignores the pair and a successful patch
  advances it, and a patched wrapper is handed to the theme runtime to enhance.
- Every admin page's panel takes the height of the layout's rounded shell instead of the
  viewport: Nuxt UI's `min-h-svh` default overflowed the shell by its margins, and the
  overflow-hidden shell scrolled on focus, clipping the page title and its Save button.
- A block's Background setting owns the whole background: it compiles to the `background`
  shorthand, so a theme gradient (the hero's band) yields to a managed colour and `transparent`
  clears it, where before only `background-color` changed and the gradient stayed. Compiler
  version 2 (the settings artifact recompiles at provision); proven in all three engines.
- Framework 1.85.7 is required (repinned): the scheduled framework jobs keep the application
  context they are handed, so `queue:scheduler run` no longer fails every tenth minute on a
  fresh install with `NotificationRetryTask requires an ApplicationContext`.

## [1.0.0-beta.29] - 2026-09-14

### Added
- **Typed block settings** (visual builder, slice A1). Every stored block carries `settings`
  (schema v1) next to `data`: managed style as typed values (`token`, `choice`, `reset`; `literal`
  reserved) over sparse `base`/`md`/`lg` breakpoint maps, an ordered list of style class ids, and
  `advanced` (anchor, CSS classes, `data-*` attributes, accessibility label). Block types declare
  `style_capabilities`, named `style_targets`, `flags` (rendering hints) and `starter_content`;
  undeclared means none. The breakpoint-first cascade resolver ships in PHP and TypeScript
  against one fixture contract.
- **Layered style delivery** (visual builder, slice A2). A theme maps the platform style
  vocabulary in `theme.json` (`vocabulary`) and lists its CSS (`stylesheets`); the layout links
  three stylesheets — the layer order sheet, the theme artifact (`@layer theme`, every manifest
  and package-contributed sheet, served by content hash) and the compiled settings artifact
  (`@layer settings`: `--t-*` custom properties, one utility per managed property, value and
  breakpoint, `revert-layer` resets), compiled from the vocabulary and published before
  anything links it. Every shipped block type declares its style capabilities and named
  targets, every block template styles them through `style_classes()`, `style_attrs()` and
  `token_class()`, and the template lint holds a block template to its declaration. Computed
  styles are proven in Chromium, Firefox and WebKit; the public-site browser floor is Chrome
  111, Firefox 113 and Safari 16.2.
- **Editor history and the revision protocol** (visual builder, slice A3). The canvas records
  intent: every change to the tree becomes a reversible operation (fields, settings, advanced
  paths, style classes, inserts, removals, moves, duplicates, page settings) in a
  sequence-numbered history with undo and redo (toolbar, ⌘Z / ⇧⌘Z); a slider drag or a typing
  burst commits as one step, structure at once, and the saved position is tracked apart from
  the current one. The preview working copy is a revisioned record accepted by compare-and-set:
  an apply names the epoch and revision it last accepted and is refused (409
  `PREVIEW_REVISION_STALE`, carrying the current pair) when the copy moved on; a save clears
  the copy only at the revision it was submitted from; the mint and the rendered canvas page
  carry the accepted pair; every apply response names the site style generation.

- **Canvas fragments, disabled** (visual builder, slice A6). An accepted apply can answer the
  affected roots' markup instead of a whole-page refresh: the server derives the affected blocks
  from the operations (validated against the accepted-before and validated-after documents),
  the render-scope resolver lifts to parents that render their children inline, absorbs
  descendants and escalates to the whole page for anything page-order or page dependent (a
  reachable priority-image claim, a block reading its list index, `entries()`, the request
  path, a block type new to the page that loads runtime assets), and only templates recorded
  as verified — every fixture rendered block-by-block equals the whole page — take part.
  The stage swaps fragments only after every guard holds — its displayed pair is the patch's
  baseline, the epoch matches, the revision is newer, every target exists, every fragment is
  exactly its own wrapper, no target nests in another — re-enhances what came in, re-anchors the
  selection and advances the displayed pair; a refused patch falls back to the whole-page
  refresh. Every apply carries input, request, response and paint performance marks, and a
  development-only overlay on the canvas shows the medians, p95s and fallback count per path.
  Ships behind `render.fragments.enabled` (`RENDER_FRAGMENTS_ENABLED`, default off); the apply
  answers `fragments: null` and the stage refreshes as before.

### Changed
- **Breaking (Developer Preview): block presentation fields are settings now.** Heading
  `align` and `color`, button `align` and `shape`, animated text's hex colours, image `size`,
  `width` and `height`, and the carousel's `transition_duration` are gone in favour of typed
  settings and `token`/`choice` fields. No content written before this release is carried
  over: reinstall. The converter (`thallo:blocks:convert-settings`: stages, a decisions file,
  a provision preflight, the cutover contract in `docs/production.md`) ships with no stage,
  ready for the first future retirement. Themes must map the vocabulary and list their
  stylesheets (`theme.json`).
- **Breaking (Developer Preview): the container and style blocks are styled through settings.**
  The container's background colour, overlay colour, padding preset and boxes, margin, radius,
  border, shadow, pixel width, height and gap and the style block's padding, margin, shadow,
  shadow colour and opacity and class hook are retired: colours, spacing, corners, border and
  shadow are settings on the block's root target, the overlay is a choice (`none|light|dark`) with
  an opacity step (`25|50|75`), the flex gap is a spacing token, a background image is a
  positioned image layer, and a class hook is the Advanced tab's CSS classes. The `hex_color`
  and `style_hook` filters and the `thallo-shadow-*` utilities are gone; template policy cache
  version 23.
- **Breaking (Developer Preview): templates emit no inline styles.** The template lint refuses a
  `style=` attribute and a `<style>` element, at save and before render, so an operator template
  carrying either no longer renders until it styles through settings or the theme stylesheet;
  `theme_colors_style()`, `theme_style_scope()` and `font_faces_style()` are the only inline
  style emitters. The pricing plans' column count is a `--count-{n}` modifier and the admin's
  chrome preview styles through the theme sheet.
- Every conversion report line names its stage, and a region stamped by one conversion stage
  can be written by the next (the write checked a fingerprint without the stamp the read
  included).
- The transitional `legacy_presentation` block flag is gone: every block type is styled through
  settings, the validator no longer withholds managed style, and the inspector's Style tab shows
  the block's controls or "declares no styling"; `flags` carries rendering hints only.
- `thallo:provision` syncs the evolved starter block-type definitions onto the existing rows
  (new fields, and the style declaration every render relies on) — an upgraded instance no
  longer needs `thallo:blocks:sync` by hand — and `thallo:blocks:sync`
  refreshes a starter's style declaration that differs from the definition, not only one that
  is missing.
- `thallo:provision` compiles the active theme's settings artifact before clearing caches and
  fails when it cannot; a theme switch compiles the incoming theme first and answers 422 on
  failure; `thallo:doctor` reports the theme vocabulary and whether the artifact is published.
- A theme's stylesheets are no longer linked one by one, `shop_styles_url()` is gone (the
  storefront sheet rides inside the theme artifact), and a theme stylesheet may not use
  `@import` or `!important` on a managed property of a block selector. Template policy cache
  version 22.

## [1.0.0-beta.28] - 2026-09-14

### Added
- **Design without code** (website plan, phase 1b): building the thallo.dev homepage from
  the blueprint needed custom CSS for four things; each is a choice in the admin now.
  - The **hero** takes any blocks beside its copy (`aside`: a code snippet, a card) instead
    of only an image, and its **background** is a choice — gradient (unchanged default),
    none, muted, inverted.
  - The **button** has a **shape**: pill, rounded (the theme radius) or square; unset follows
    the site's radius setting.
  - **Settings → General → Design**: corner radius (round, soft, sharp), typeface pairing
    (sans, editorial with serif headings, serif) and page ground (plain, tinted), next to the
    theme colours. Closed enums; the defaults are today's look and emit nothing. The theme
    reads `--font-body`, `--font-display` and `--radius-btn` as tokens.
  - Every appearance choice is in the render and shop cache fingerprint, so a change
    re-keys cached pages.

### Changed
- **The `thallo-version` shortcode is a status pill by default.** It rendered as bare text; the
  default theme now dresses it as a pill with a dot, driven by three tokens (`--version-fg`,
  `--version-bg`, `--version-dot`) so a site's custom CSS only has to recolour it.

### Fixed
- **The admin's header/footer preview looked unlike the live page**: it linked only the theme
  sheets. It loads the theme colours, the design tokens and the site's custom CSS now.

## [1.0.0-beta.27] - 2026-09-13

### Fixed
- Framework 1.85.6 is required (repinned): a login whose token generation fails (an empty JWT
  key) no longer stores a session with an empty refresh token, whose constant hash made every
  later login answer 409; the cause is logged, and unique-constraint violations are reported
  (1.85.5); an SVG served with a width hint is the original, not a 422 (1.85.6).
- **Uploaded media answered 401 on a fresh install.** The framework's upload access default is
  `private` (auth for retrieval too), so every image on the site and every preview in the admin
  was unauthorized until `UPLOADS_ACCESS` was set by hand. Thallo's default is `upload_only`
  now: uploading and deleting need the admin session, retrieval is public per blob (the media
  library uploads site media as public; private blobs still need auth or a signed URL).
- **Saving the site's custom CSS answered 405 from nginx.** `/v1/admin/render/templates/custom.css`
  ends like a file, so the common static-file location took it. The production guide's location
  rule covers `/v1/` and `/api-docs/` now, and `thallo:doctor` probes an API path that ends like
  a file (`api-routing`) next to the theme-asset probe.
- **SVG thumbnails in the media library answered 422.** The list asked for a 160px variant of
  every `image/*` blob, and the framework's resizer refuses vector images (its raster validator
  knows JPEG, PNG, GIF and WebP only). The thumbnail URL is the original for anything but those
  four formats now; framework 1.85.6 also serves an SVG's original when a width is requested.

### Added
- **Code block** (website plan, phase 1): a snippet with a language label and a Copy button,
  for the install command on a landing page. The snippet is text (never markup), the
  language rides as `data-language` and a `language-*` class for a later highlighter, the
  caption is optional, and Copy can be switched off. Without JavaScript the block is a plain
  `<pre><code>`; `block-code.js` adds the button (same-origin, the `block_script()` catalog).
- **`thallo-version` shortcode** (website plan decision 7): renders the running install's
  version from `site.version`, now available to every template, with an optional prefix
  (`params.prefix`); a development checkout says so. `site.version` comes from the new
  `SiteVersionProvider` contract, bound to Composer's installed-version registry.

## [1.0.0-beta.26] - 2026-09-13

Findings from the first real upgrade on thallo.dev: the version is visible to every admin user,
and the guide stops asking for a PHP-FPM reload nobody needs by default. Framework 1.85.4
required.

### Added
- The user menu shows the Thallo version this admin runs ("Thallo 1.0.0-beta.25"; "development
  checkout" in the development repository) and, when a newer one is published, an "Update
  available" entry that leads to the Home card. `GET /v1/admin/update-status` is readable by
  any signed-in admin user now, operator-only before; it stays off the anonymous `/admin/config`.

### Changed
- The upgrade guide, the template README and the update card ask for a PHP-FPM reload only when
  OPcache runs with `opcache.validate_timestamps=0`; with PHP's default, changed files are
  picked up without one.
- `pnpm gen:api` formats the generated schema files, so regenerating the typed client no
  longer fails the admin's format check.

### Upgrade Notes
- `composer update && php glueful thallo:provision`. No PHP-FPM reload unless OPcache runs with
  `opcache.validate_timestamps=0`.

## [1.0.0-beta.25] - 2026-09-13

The first upgrade release: provision now finishes an upgrade completely, and the website's
separate deploy path is gone. Framework 1.85.4 required.

### Fixed
- `thallo:provision` drops the compiled route table and the rendered page cache, so
  `composer update && php glueful thallo:provision` is the whole upgrade. The route table's
  signature does not cover the routes shipped in `vendor/`, so after an update a stale table
  kept serving the previous release's routes until `route:cache:clear` was run by hand — a step
  the upgrade guide listed but the upgrade command did not do.

### Upgrade Notes
- `composer update && php glueful thallo:provision`, then reload PHP-FPM. Provision now clears
  the route table and the rendered pages itself; no manual cache clear is needed.

### Removed
- `scripts/deploy-site`, the website's deploy-from-a-tag flow written before the package split.
  It was never used: thallo.dev is an ordinary template install now, upgraded like every site
  with `composer update && php glueful thallo:provision`. The runbook's website gate says so.

## [1.0.0-beta.24] - 2026-09-13

Housekeeping for the published repositories: they are read-only mirrors now, and they say so.
No application code changed since beta.23; framework 1.85.4 required.

### Changed
- `scripts/mirror-protect` makes the 15 mirrors read-only for everyone but the release pusher
  through the GitHub API: branch and tag rulesets (no creation, update or deletion; the
  repository admin bypasses) and issues, wiki, projects and discussions switched off. Applied
  once; re-run after adding a mirror.
- Every published repository's README carries a Contributing note: the mirrors are read-only,
  overwritten on each release, and issues and pull requests belong in glueful/thallo. The three
  packs without a README (account, subscriptions, tenancy) have one now, and the archive check
  requires it.

## [1.0.0-beta.23] - 2026-09-13

A small release after beta.22's install gate: the documented install command works as written,
the admin tells you when nothing is ticking the scheduler, and the admin code base is formatted
and gated. Framework 1.85.4 required.

### Added
- Health reports a **Scheduler** check: the scheduled-publishing runner leaves a heartbeat in
  the system flags every tick, and the check is ok while it is recent, a warning naming the
  cron line (`* * * * * php /path/to/site/glueful queue:scheduler run`) when it is stale or
  has never happened. A missing cron entry is now visible in the admin instead of showing up
  as publishing that never fires.

### Changed
- The documented install command carries `--stability=beta` (`create-project` defaults to
  stable, and Thallo is beta-only), and `./thallo update-check` maps to `thallo:update:check`.
- The admin is formatted with oxfmt in one whitespace-only commit, and CI's admin job lints,
  format-checks and tests before it builds.

### Upgrade Notes
- `composer update && php glueful thallo:provision`, then check Utilities → Health: the new
  Scheduler check should be green within a minute if your cron entry is in place.

## [1.0.0-beta.22] - 2026-09-12

The first release installable from Packagist as the split, and the first that tells you when
the next one exists. Beta.21's content ships under this number: the `glueful/thallo` Packagist
entry had crawled the development repository's `v1.0.0-beta.21` tag before it was repointed at
the install template, and a published version's reference is immutable there. Every artifact
carries beta.22. Framework 1.85.4 required.

### Added
- **The update notice** (charter decision 11). Once a day the install asks Packagist's public
  metadata for the newest published `glueful/thallo-core` it may move to — a plain GET, no
  install identifier — and keeps the answer in the system flags. Administrators with
  `system.access` see a dismissible card on Home and an **Update** badge on Utilities → Health,
  both with the release notes link and `composer update && php glueful thallo:provision`; the
  Health page shows the installed and newest versions; `GET /v1/admin/update-status` serves the
  same to the API. A pre-release install is offered newer pre-releases and stable, a stable
  install only stable; the notice clears the moment the upgrade has run. `UPDATE_CHECK_ENABLED=false`
  turns it off; `php glueful thallo:update:check [--force]` shows it on the command line. Never an
  updater: Composer runs as the deploy user, not under the web worker.
- `thallo:provision` generates the API reference: `docs/openapi.json` and the `/api-docs` UI,
  from the install's live routes, refreshed on every provision (what `php glueful
  generate:openapi -f --ui` writes). An install from the template answered 404 at `/api-docs`
  before: the docs route serves those two files, and only the development repository had them.
- The install template ships the `thallo` launcher beside `glueful`: `./thallo setup`,
  `./thallo doctor`, `./thallo provision`, `./thallo create-admin`, and every other command
  passed through to the console.

### Changed
- `scripts/release-split` is idempotent and resumable: a local split tag that already names
  the split head is kept (an annotated tag is a new object each time it is written, which a
  mirror that holds it rejects), a mirror that already publishes the tag receives only `main`,
  a mirror publishing it at another commit is refused, and every mirror is pushed before the
  summary names what did not land. The runbook pushes the mirrors before the development
  repository's own tag and never registers the development repository with Packagist.

### Upgrade Notes
- Framework 1.85.4 is required (repinned): jobs declared in `config/schedule.php` actually run
  under `queue:scheduler run`; before it, the tick logged them as executed and ran nothing.
- **One scheduler cron entry is required:** `* * * * * php /path/to/site/glueful queue:scheduler run`.
  It evaluates every job in `config/schedule.php` — scheduled publishing, the update check, the
  signup and domain-reverification sweeps. Earlier guides listed only `thallo:schedules:run`,
  which fires scheduled publishing alone, and called the sweeps automatic; they were not running
  on an install without this tick. Queue workers do not tick the scheduler. See
  [production.md](docs/production.md), "Running the scheduler and the queue".
- Installs on beta.21 upgrade as usual: `composer update && php glueful thallo:provision`.

## [1.0.0-beta.21] - 2026-09-12 — Developer Preview

Thallo becomes a Composer package. `composer create-project glueful/thallo` installs a thin
template whose `vendor/` holds `glueful/thallo-core` and the thirteen capability packs, and
`composer update && php glueful thallo:provision` is every upgrade from here on. Existing
installs move once; their databases need nothing. Framework 1.85.3 required.

### Upgrade Notes
- **Installs created before this release move once to the template.** `create-project` beside
  the old site, carry `.env`, `storage/`, theme overrides and any code of your own across,
  provision, switch the document root. The database needs nothing: Thallo's migrations were
  recorded under `app` / `app:dependent` and are adopted under the core package's lanes
  (framework 1.85 `previous_sources`) — nothing re-runs, nothing looks pending. Exact steps in
  `docs/upgrading.md`.
- Customisations made inside Thallo's own files under a previous release's `app/`, `routes/` or
  `database/migrations/` are not carried by an upgrade; those directories are now yours and
  start empty, so re-apply such changes as overrides in `config/` and your own files there.
- Framework 1.85.3 is required (repinned): `previous_sources` on migration descriptors, its
  `migrate:run` adoption fix, `env()` reading the real process environment (a CI job or
  container that exports `DB_*` no longer sends a fresh install's first connections to sqlite),
  and the boot environment read the same way, with the extension cache stamped for the
  environment it was compiled under.
- The bootstrap passes `env('APP_ENV', 'development')` to the framework instead of the `$_ENV`
  array alone, so an `APP_ENV` the process exports is honoured under PHP's default
  `variables_order`.

### Changed
- **Thallo is a Composer package.** The application — `core/` in the development repository,
  namespace `Thallo\Core` — is published as `glueful/thallo-core`, a library with a Glueful
  manifest declaring its provider and its two migration lanes; the packs are published at the
  same version and pinned to it. The template (`skeleton/`) ships only the operator's tree:
  entry points, config overrides, `app/`, `routes/`, `database/migrations/`, `themes/`,
  `storage/`. Thallo loads its routes, migrations, config defaults and the admin bundle from
  `vendor/glueful/thallo-core`; `thallo:provision` publishes the bundle into `public/admin` so
  the web server keeps serving it from disk.
- **The release is fifteen artifacts.** `scripts/release-split` subtree-splits the core, the
  template and the packs to read-only mirror repositories and tags them together;
  `scripts/verify-dist-archive` checks every artifact from the release commit;
  `scripts/skeleton-smoke` installs the template against the local packages (also in CI).

### Added
- **`scripts/deploy-site`** — the website's deploy-from-tag: checks out this repository at a
  release tag (a complete, lock-pinned install) into a `releases/` + `shared/` + `current`
  layout with instant rollback; refuses branches and commits; `--dry-run` prints every step.

## [1.0.0-beta.20] - 2026-09-11 — Developer Preview

The framework's API reference moves to `/api-docs`, freeing `/docs` for the site's own
documentation, and the admin's API Reference link works again. No schema changes; beta.19
installs upgrade in place.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes.
- Framework 1.84.0 is required (repinned). **The framework's API reference moved from `/docs`
  to `/api-docs`** (`API_DOCS_PATH`), so `/docs` now belongs to the site — Thallo will deliver
  its own documentation there. The regenerated reference page ships in this release; links to
  `/docs/` for the API need updating, or set `API_DOCS_PATH=/docs`.

### Fixed
- **The admin's "API Reference" link works.** It pointed at a hardcoded (and misspelled) host;
  it now opens the running site's API reference at the configured path, delivered through
  `/admin/config` as `apiDocsPath`.
- The render pack reserves `/api-docs` from page slugs and no longer needs `/docs`.

## [1.0.0-beta.19] - 2026-09-11 — Developer Preview

The Site › Regions preview renders again in production, publishing confirms with one toast,
and the review divider no longer dangles for direct publishers. No schema changes; beta.18
installs upgrade in place.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes.
- Framework 1.83.4 is required (repinned): the regions preview iframe was blocked by the
  admin document's Content Security Policy (no `frame-src`, so a `blob:` preview document was
  refused); 1.83.4 allows a mounted SPA to frame itself and its own blobs.

### Fixed
- **Site › Regions preview renders again.** The header/footer preview showed nothing in
  production — see the framework note above; no Thallo code changed.
- **One toast per publish.** Publish/Update in the editor and the design canvas now reports a
  single "Published" (or "Updated") toast; the draft and route saves it performs stay silent,
  while their failures still report. Save draft on its own still confirms.
- **No stray divider under Unpublish.** The line between the publishing controls and the
  Review section now belongs to the Review section, so it disappears with it (a direct
  publisher on a bare draft saw an empty rule).

## [1.0.0-beta.18] - 2026-09-11 — Developer Preview

Block-type icons render again, a pack's starter blocks arrive the moment its capability is
switched on and leave the listing when it is switched off, publishing saves the page's route,
direct publishers no longer see a review prompt, and Settings › Block types is searchable. No
schema changes; beta.17 installs upgrade in place.

### Upgrade Notes
- The documented sequence still applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. Provision heals any starter block type the instance lacks; nothing else is required.
- Switching Commerce or Accounts on now seeds that pack's block types on the next request;
  switching it off hides them (rows kept). No command either way on a single-store install.
- Pack authors: `StarterBlockTypeDefinition` gained an optional `requiresCapability` argument.
  A contribution that sets it is seeded only while that capability is on and hidden while it
  is off; contributors should now be registered unconditionally and rely on that field.

### Added
- **Search on Settings › Block types.** A search box filters the cards by label, slug or
  description as you type; an empty result says what was searched for.

### Fixed
- **The admin ships the whole lucide icon set.** The block picker, block cards and Settings ›
  Block types showed blank icons for 20 starter block types (accordion, blog posts, call to
  action, …): icon names on block types are data — seeded from PHP or chosen in the icon
  picker — and reach the admin through the API, so the build's source scan could never see
  them and the browser fell back to api.iconify.design, which the CSP blocks. The build now
  embeds every lucide icon (~90KB gzipped, cached with the bundle), and the release gate
  requires it.
- **Publish saves the route.** The editor's Publish/Update button now saves the slug shown in
  the Publishing panel (the title suggestion on a new page, or an edit) before publishing, so
  a page never goes live without a URL; a failed route save stops the publish.
- **No "Submit for review" for direct publishers.** The review overview reports whether the
  requesting user holds `workflow.bypass` (`can_bypass`); the Review section hides for them
  while nothing is in review, and never offers Submit — reviewer actions on a submission are
  unchanged.

### Changed
- **A pack's starter blocks seed themselves when its capability turns on.** The first request
  after Commerce or Accounts is switched on creates that pack's missing block types — no
  `thallo:provision` or `thallo:blocks:seed` run. Existing rows are never touched. A system flag
  records which capabilities were seeded, so it happens once per switch-on. Single-store only:
  with workspaces on, `thallo:blocks:seed --all` / `thallo:tenant:sync --kind=block_type` remain
  the per-workspace path.
- **A disabled pack's block types leave the listing, not the table.** Settings › Block types and
  the block picker omit Commerce (and Accounts) block types while the capability is off; their
  rows and any content using them are kept, and they reappear when it is on again. Starter
  block-type definitions carry the capability that gates them
  (`StarterBlockTypeDefinition::$requiresCapability`, new optional field), and packs now declare
  their contributions unconditionally — the app applies the switch, seeding a gated definition
  only while its capability is on.

## [1.0.0-beta.17] - 2026-09-09 — Developer Preview

A fresh install gets the whole starter block library, a refused homepage says why, the
production container is compiled once instead of on every request, and the docs carry the
web-server block that makes PHP-served assets work. No schema changes; beta.16 installs upgrade
in place.

### Upgrade Notes
- **Run `php glueful thallo:provision` once after updating, then reload PHP-FPM.** Provision
  seeds the starter block types a beta.16 install is missing (30 of 46) and grants the install
  roles any new permission; the FPM reload drops the previous release's OPcache copies. This is
  now the documented upgrade sequence (docs/upgrading.md).
- Framework 1.83.3 is required (repinned).

### Changed
- **Framework 1.83.3.** The production container is compiled once, atomically, under a name
  signed by its definitions — no more per-request rewrites of `CompiledContainer.runtime.php`,
  half-written files falling back to the runtime container, or stale OPcache copies surviving a
  deploy. The old runtime artifact is pruned on the first boot.

### Fixed
- **A fresh install gets the whole starter block library.** Setup seeded content types, settings
  and regions but not block types, so an instance had only the 16 slugs migration 021 (re)seeded
  — no rich text, hero, image, heading, CTA, gallery, video, pricing, HTML … — until someone ran
  `thallo:blocks:seed`, which nothing mentioned. Setup now seeds the full library (fixed set plus
  pack contributions), and `thallo:provision` seeds any starter block type an installed
  single-store instance lacks, never touching existing rows — so beta.16 installs are completed
  by the next provision run, and a starter added in a later release lands on upgrade.
- **"Set as homepage" says why it refused, and no longer offers what it would refuse.** The
  homepage check needs a published locale AND a saved route (slug); the Pages list shows only
  the first, so a page reading "published" could still be turned down with "must be a published
  entry of a publicly delivered content type" and nothing else. The 422 now names the failing
  condition ("published in locale "en" but has no route yet — save a slug in the Publishing
  panel", "not published in locale "en"", "content type "category" is not publicly delivered"),
  and the editor's house button stays disabled with a matching tooltip until both hold.

## [1.0.0-beta.16] - 2026-09-09 — Developer Preview

The first admin can publish: a refused publish explains itself, empty required fields are marked
where they are, a bypass holder is never trapped by their own submission, and the designer's
support assets live under the one proxied prefix pair. No schema changes; beta.15 installs
upgrade in place.

### Upgrade Notes
- **Web server:** every PHP-served asset now sits under `/theme-assets/*` or `/_thallo/*`. If
  your vhost serves `.css`/`.js`/`.woff2` from disk, the location rule for those two prefixes
  must sit above that rule (docs/production.md); `php glueful thallo:provision` now warns
  (`asset-routing`) when it does not.
- Seeded Pages/Posts on existing installs keep a required `body`; make it optional on the
  content type in the admin if you want the fresh-install behaviour.

### Fixed
- **Canvas preview assets are served under `/_thallo/`.** The preview injected `/_preview.css`
  and `/_preview-bridge.js` at the site root; a web-server rule that serves every `.css`/`.js`
  URL from disk answered 404 even on a host that had proxied the documented prefixes, so the
  designer loaded unstyled and without its bridge. Every PHP-served asset now lives under the
  documented `/theme-assets/*` + `/_thallo/*` pair.
- **`thallo:provision` and `thallo:doctor` warn when the web server eats PHP-served assets.**
  A new `asset-routing` check probes one theme asset on a public `BASE_URL`; a 404 names the
  misconfiguration and the docs row that fixes it, instead of an unstyled site being the first sign.
- **A bypass holder may approve their own submission.** The self-review rule protects nothing
  against someone who can publish directly; applying it to them only trapped an admin who had
  submitted their own page.
- **A refused publish says why.** A review-gated publish now reads "Needs a review before
  publishing" with the next step, and a forbidden one names `content.publish`, instead of a
  bare "Couldn't publish".
- **Required-field misses are marked inline.** A 422 on save now highlights each failing field
  under the editor ("body is required") instead of a toast that read as a failed save.
- **No commerce request on installs without Commerce.** The entry editor's commerce panel gate
  fetched `/v1/admin/commerce/meta` on every entry page regardless of the capability (gate
  hooks run before the capability filter); the query now stays idle while `thallo.commerce` is off.

### Changed
- **Seeded Pages and Posts no longer require a body.** A page must be publishable with a title
  alone (a landing page composed in the designer, a placeholder); the first-run Publish must
  not 422. Existing installs keep the schema they were seeded with — make `body` optional on the
  content type in the admin if you want the same.
- **glueful/audit 1.4.1.** The audit log lists newest first even for rows that share a second
  (a login, a first-run setup burst): the insertion id now breaks `occurred_at` ties.

## [1.0.0-beta.15] - 2026-09-08 — Developer Preview

A fresh install works end to end: `thallo:provision` from the sample `.env` with real credentials
typed at the prompt migrates, grants the install roles the whole catalog, and hands off to the
setup link; the first admin can administer everything. Proven by provisioning a clean database
from the archive. No schema changes; beta.14 installs upgrade in place.

### Upgrade Notes
- **beta.14 installs: run `php glueful thallo:provision` once after updating.** beta.14's grant
  step skipped itself on the server; this one applies the grants.
- Framework 1.83.2 is required (repinned).

### Changed
- **Framework 1.83.2.** The Installer publishes freshly written database credentials to the
  provisioning process, so third-party migrations that open their own connection (Aegis's role
  seed) see the real database on a fresh install.

### Fixed
- **Pack permission seeds migrate the database they are handed.** Seven seed migrations opened
  their own `new Connection()`, which reads the live environment — on a fresh `create-project`
  that is the sample's placeholder user, and provision failed at migrate with "role
  your_database_user does not exist" whenever the real credentials were typed at the prompt.
  They now use `$schema->getConnection()`, and a unit test refuses any migration that opens its
  own connection. Pairs with framework 1.83.2, which also publishes the written credentials to
  the provisioning process for third-party migrations (Aegis's role seed).
- **`thallo:provision` grants the install roles on a fresh install.** Aegis decides at boot
  whether to activate its permission provider (the RBAC tables must already exist) and provision
  runs the migrations that create them in the same process, so the grant step found no active
  provider and printed "Install role grants skipped (No persistent RBAC provider …)". The grantor
  now activates the provider itself, the way the extension's boot would once the tables exist.

## [1.0.0-beta.14] - 2026-09-08 — Developer Preview

The first admin can actually administer: the install roles now hold the whole permission
catalog, every admin icon ships inside the bundle, the health report names its findings, and a
fresh install's switchboard and sample `.env` describe what is really on. No schema changes;
beta.13 installs upgrade in place.

### Upgrade Notes
- **Existing installs: run `php glueful thallo:provision` once after updating.** It grants the
  install roles the full catalog (the 403s on form submissions, the audit log and analytics for
  the first admin) and rebuilds the caches. `thallo:create-admin` and the web setup do the same
  for new installs.
- **`.env` copied from an earlier sample:** set `API_USE_PREFIX=false` (Thallo mounts everything
  under `/v1`; the old sample's `/api` prefix left the login route unreachable), then
  `php glueful route:cache:clear`.
- Framework 1.83.1 is required (repinned).

### Fixed
- **Every admin icon is embedded; none is fetched from api.iconify.design.** beta.13 bundled
  the icons named in `.vue` files but the scan's default globs skip `.ts`, so the 28 icons
  named only in the module registries (`src/registry/*.ts`: analytics, code-xml, settings,
  wrench …) were still requested from the Iconify API at runtime and blocked by the admin's
  `connect-src 'self'` policy — blank icons in production. The scan now covers `.ts`, and
  `scripts/verify-dist-archive` refuses a release whose baked bundle lacks any referenced icon.
- **The first admin really has full access.** Aegis seeds the install roles with its own 15
  permissions only; Thallo's packs seed theirs by migration and Thallo's core catalog
  (`content.manage`, `content.publish`, `content.routes`, `tenant.*.manage`, `billing.manage`)
  was never persisted at all — so the superuser could not manage content models, triage form
  submissions, read the audit log or see analytics (403 on every one of them). The provider now
  declares the catalog to the framework's permission registry, and web setup, `thallo:create-admin`
  and `thallo:provision` run `InstallRoleGrants`: persist the catalog, then grant `superuser`
  every permission and `administrator` everything but `system.config`. Additive and idempotent;
  re-running provision on an existing install heals it.
- **The dashboard's first-run card asks for a page, not a "categorie".** The picker looked for
  slug `page` (the seed is `pages`) and fell through to the first type alphabetically; the
  singular was made by chopping a trailing "s". It now prefers Pages, then Posts, then any
  non-taxonomy type, and singularizes properly (Categories → Category).

### Changed
- **Sample `.env`.** `API_USE_PREFIX=false` so framework routes (login, blobs) sit under `/v1`
  like everything else; `CSP_HEADER` ships as a permissive policy in report-only mode
  (`CSP_REPORT_ONLY=true`), so nothing is blocked and the production recommendation is quiet;
  the users lookup/list endpoints are on.
- **Framework 1.83.1.** Production recommendations are logged once per boot cache instead of
  on every request, and a recommendation no longer degrades the config health check — so a
  thallo.dev-style host with an empty `CSP_HEADER` stops filling the error log and reports
  `ok` health.
- **The admin health report says what is wrong.** `GET /v1/admin/health` flattened every
  framework check to name/status/message, so "Configuration warnings detected" reached the
  operator with no way to learn which setting. Each check now carries its `issues`, `warnings`
  and `recommendations` lists when the framework provides them, and the Health page lists them
  under the check. Pairs with framework 1.83.2, where a recommendation no longer degrades the
  check's status.
- **An untouched capability switch follows its engine.** The switchboard defaulted every
  capability to requested, so a fresh install showed Commerce and Multi-tenancy switched on
  with a "Requested · engine unavailable" warning — on-looking rows for features that are not
  active. With no explicit answer (no stored row, no `thallo.capabilities` config entry) a
  capability is now requested only while its owning engine is available: tier-2 packs read
  plainly Off until the extension is enabled from the extensions browser, and the tenancy
  switch reads Off until the Workspaces flow enables enforcement. An explicit switchboard
  choice still outranks the engine. Existing installs that never touched a switch see the same
  rows as a fresh install.
- **"Storefront accounts" is now "Accounts"** in the capabilities switchboard, described as the
  site's visitor accounts — it is not a commerce feature.

## [1.0.0-beta.13] - 2026-09-08 — Developer Preview

Admin icons ship inside the bundle, and the browser first-run works on a production host:
provision prints a one-time setup link. No schema or API changes beyond the setup gate's
messages; beta.12 installs upgrade in place.

### Changed
- **The browser first-run is a link.** Provision prints `<BASE_URL>/admin/setup?st=<SETUP_TOKEN>`;
  the setup page reads the token once, drops it from the address bar, and sends it back as the
  `X-Setup-Token` header the production gate requires. A completed setup blanks `SETUP_TOKEN` in
  `.env`, so the link is single-use on top of the endpoint's own 409 lock. Re-running provision
  prints the link again; the production 403 says so. Local zero-config setup (no token, not
  production) is unchanged.

### Fixed
- **Admin icons are embedded in the build instead of fetched from the Iconify API.** The Vite
  plugin's `icon.clientBundle.scan` only embeds icons from an INSTALLED collection, and the admin
  had none, so every icon was resolved at runtime from `api.iconify.design` — which the admin's
  document Content-Security-Policy (`connect-src 'self'`, framework 1.82.2) now blocks, leaving
  icons blank. `@iconify-json/lucide` is a dev dependency; the scan bundles the lucide icons the
  admin uses and the runtime fetch is no longer attempted.

### Removed
- **`CSP_HEADER` is gone from `.env.example`.** Framework 1.83.0 (repinned here) makes the
  variable real: a non-empty value is sent verbatim as `Content-Security-Policy` on every
  response that does not set its own. Thallo's rendered site is not written for a blanket policy
  (inline colour-mode resolver, theme assets, headless media), so the sample no longer suggests
  one. Operators who want a CSP can still set `CSP_HEADER` — the admin's own document policy
  keeps precedence — and audit it first with `CSP_REPORT_ONLY=true`.

## [1.0.0-beta.12] - 2026-09-08 — Developer Preview

First-run and admin housekeeping on beta.11: provision mints `SETUP_TOKEN`, and the admin moves
to Nuxt UI 4.11. No schema or API changes; beta.11 installs upgrade in place.

### Changed
- **Admin: Nuxt UI 4.11.1.** The Vite plugin's `icon` option is typed correctly upstream, so the
  local cast is gone. The switch component's render tree changed; the admin tests that drive
  switches now resolve them through the rendered `<button role="switch">`.
- **`SETUP_TOKEN` is minted by provision and listed in `.env.example`.** The unauthenticated
  first-run `POST /admin/setup` is gated by it in production (sent as the `X-Setup-Token`
  header); until now nothing generated or documented it, so a production host answered
  "First-run setup is disabled" with no hint where the value came from. Provision now mints it
  exactly like `APP_KEY`/`JWT_KEY`/`TOKEN_SALT` (only when empty, never overwritten) and prints
  it at the end.

## [1.0.0-beta.11] - 2026-09-07 — Developer Preview

A lock-only release on beta.10: framework 1.82.3 serves the admin's HTML document with a
Content-Security-Policy a built front-end can run under, and the `php -S` quickstart serves
admin deep links. No Thallo code, schema, API, or admin changes; beta.10 installs upgrade in
place.

### Changed
- `glueful/framework` 1.82.3 in the lock:
  - (1.82.3) **The `php -S … router.php` quickstart serves admin deep links.** With the admin
    bundle at `public/admin/index.html`, PHP's built-in server resolved `/admin/setup` to that
    directory index and Symfony stripped `/admin` as a base path, so every admin deep link or
    reload 404'd locally (nginx/Apache were unaffected). The router script now presents the
    front controller the way a real web server does.
  - (1.82.2) The SPA mount controller applied the static-asset
  header set — `style-src 'self'`, no inline allowance — to `index.html` too, so the admin's
  runtime-injected styles were blocked in every environment where PHP serves the bundle: the
  primary button on the setup screen rendered with no background. `index.html` now carries a
  document policy (inline styles allowed, `data:`/`blob:` images, scripts still self-only);
  assets keep the strict policy. Surfaced on thallo.dev's `/admin/setup`.

## [1.0.0-beta.10] - 2026-09-07 — Developer Preview

A small follow-up to beta.9 from the first thallo.dev walkthrough: provision hands off to the
browser setup screen, and the production checklist covers the web-server rule that otherwise
leaves the rendered site unstyled. No schema, API, or admin changes; beta.9 installs upgrade
in place.

### Changed
- **Provision ends by naming both ways to create the first admin**, browser first:
  `<BASE_URL>/admin/setup` (recommended) and `php glueful thallo:create-admin`. The README
  quickstart says the same. Surfaced by dogfooding: the old one-liner only mentioned the CLI.
- **Production checklist: PHP-served asset paths must reach PHP.** `/theme-assets/*` and
  `/_thallo/runtime/*` are served by Thallo, not from disk; a web-server rule that answers every
  `.css`/`.js`/`.woff2` URL straight from the document root (CloudPanel's template does) turns
  them into 404s and every rendered page loads unstyled. `docs/production.md` now carries the
  required row with the nginx location to add above the static-file rule.

## [1.0.0-beta.9] - 2026-09-07 — Developer Preview

Framework 1.82.1 makes the compiled container real and lets a never-installed production
checkout boot quietly; Thallo's two boot-time container re-pins now guard on the framework's new
`RebindableContainer` interface so they reach that compiled container. No schema, API, or admin
changes; beta.8 installs upgrade in place.

### Changed
- **Boot-time re-pins reach the compiled container.** The subscriptions pre-engine seam and the
  commerce payment-link seams re-bind services on the built container from `boot()`; both
  guarded on the concrete runtime `Container` class, which production's compiled container is
  not. With compilation now succeeding they would have silently no-op'd — exactly what
  `SubjectResolverCompiledContainerGateTest` was written to catch, and it did. The guards target
  `Glueful\Container\RebindableContainer` (framework ≥ 1.82.1) and the gate test now asserts
  the production contract directly: build the compiled container, run the re-pin, resolve.
- `glueful/framework` 1.82.1 in the lock:
  - **The compiled container actually engages in production.** Every production boot used to
    log `[Container][WARNING] container compilation failed` and run the runtime container;
    static factories, closure factories and the live `ApplicationContext` now all compile or
    hydrate, and the artifact lives in `storage/cache/container/`.
  - **A fresh production checkout is quiet before provision.** Until the security keys exist,
    the framework skips its boot-time security validation and resolves extensions live once,
    writing the cache — so the `composer create-project` hook and the first `php glueful`
    call print no warnings and no "Extension cache missing" failure. The remaining
    pre-provision line, Aegis' "RBAC tables not found", is handled by Aegis 1.16.0 below.
  - The "FORCE_HTTPS not enabled" recommendation no longer fires on production hosts that
    leave it unset (unset = enabled).
  - Compiled autowiring mirrors the runtime autowirer for optional dependencies and for
    object defaults built in the initializer (1.82.1), and compiled containers accept
    boot-time `load()` re-pins through `RebindableContainer`.
- **A fresh production checkout prints nothing before provision.** Two Thallo lines the
  quiet framework boot exposed are gone: the commerce pack no longer declares webhook
  settlement "DEAD" on installs where Payvia is not active (tier 2 is off by default and
  payments degrade to manual collection by design — it now checks for Payvia's own services,
  not merely its classes), and `thallo:payments:migrate-platform-credentials` no longer
  resolves its encryption-backed collaborators at construction, so the console can register it
  before `APP_KEY` exists.
- `glueful/aegis` 1.16.0 in the lock: the boot-time "RBAC tables not found" warning is silent
  before first run (no security keys yet) and unchanged once installed. With it, a fresh
  production checkout prints nothing at all before `thallo:provision`.

### Upgrade Notes
- **Production now runs the compiled container.** If anything behaves differently only in
  production, set `APP_DEBUG=true` to compare against the runtime container and report it.
  Delete `storage/cache/container/` to force a fresh compile.

## [1.0.0-beta.8] - 2026-09-07 — Developer Preview

A lock-only release on beta.7: framework 1.81.2 stops a stale, host-shared command manifest
from breaking every production boot. No Thallo code, schema, API, or admin changes; beta.7
installs upgrade in place.

### Changed
- `glueful/framework` 1.81.2 in the lock: the production console command manifest now lives
  in the app's `storage/cache` and is re-validated on load. Before, every host shared one
  `/tmp/glueful_commands_manifest.php` and trusted it verbatim, so a manifest left by an older
  framework on the same VPS fed a phantom command class into every production boot — container
  compilation failed and the console threw a 500 before `thallo:provision` could run. Surfaced
  on thallo.dev's server, which once ran a pre-1.41 framework.

### Upgrade Notes
- **If a host ever showed `Cannot compile autowire definition for unknown class` at boot**:
  after `composer update`, run `php glueful commands:clear` once to delete the old shared
  temp manifest, or simply delete `/tmp/glueful_commands_manifest.php` as root.

## [1.0.0-beta.7] - 2026-09-07 — Developer Preview

Hotfix on beta.6: the production mode beta.6 made the default could not provision a fresh
install. Three defects in Thallo and one in the framework, all surfaced by the first
production-mode deploy of thallo.dev and each pinned by a test; a fresh install from the dist
archive now provisions in production mode end to end. No schema, API, or admin changes.

### Fixed
- **A fresh install boots in production mode** — three defects the first production-mode
  provision on thallo.dev surfaced, all fixed and pinned by tests:
  - The app provider used two closure factories. The compiled container refuses closures and
    skips the WHOLE provider, so the capability registry vanished, every pack failed to boot,
    and no `thallo:*` command existed. Both are static factories now, and an architecture test
    forbids closure factories in every Thallo provider.
  - Production boot needs the compiled extension cache, which a fresh checkout lacks.
    `composer create-project` now builds it right after copying `.env`, and `thallo:provision`
    rebuilds it after migrating.
  - `thallo:doctor`, `thallo:provision` and `thallo:create-admin` register in the provider's
    register() phase, not boot(): boot needs a reachable database, and a production boot failure
    is logged and skipped, which silently removed the very commands that diagnose it. The boot
    also no longer dies when the tenancy flag cannot be read pre-provision.
- `glueful/framework` 1.81.1 in the lock: providers loaded from the extension cache now get
  `register()` called. Without it the first-run commands above never existed in production,
  because production boots from that cache.


### Upgrade Notes
- **beta.6 installs that never completed first run**: update to beta.7 and run
  `php glueful thallo:provision` again — it now builds the extension cache itself.
- **Installs running in production already**: `composer update` then
  `php glueful extensions:cache`, because the framework 1.81.1 fix changes what the cached
  boot registers.

## [1.0.0-beta.6] - 2026-09-06 — Developer Preview

A first-run polish release on beta.5: `thallo:provision` recognises a hand-filled `.env` and
asks for one confirmation instead of seven answers, and `.env.example` ships in production
mode. No schema, API, or admin changes; beta.5 installs upgrade in place.

> **Known issue — fixed in beta.7.** A FRESH beta.6 install cannot complete its first run in
> the new default production mode (`thallo:provision` reports no `thallo` commands). Install
> beta.7, or set `APP_ENV=development` in `.env` for the first run. Existing installs upgraded
> in place are unaffected.

### Changed
- **`thallo:provision` confirms a pre-filled `.env` instead of re-asking**: when `.env` already
  holds real `DB_PGSQL_*` values (non-empty database and user, none of them the `.env.example`
  placeholders), the interactive run shows the settings — password masked — and asks one
  question. "No" walks the usual prompts with those values prefilled, and an empty password
  answer keeps the stored one. A placeholder or empty `.env` gets the plain prompts as before;
  `-n` is unchanged. Surfaced by dogfooding: with credentials written by hand, seven prompts
  after three boot warnings read like the command had stopped.
- **`.env.example` ships in production mode.** Thallo is installed to be deployed, so the
  template now defaults to `APP_ENV=production`, `APP_DEBUG=false`, API docs off, HTTPS
  enforcement on, production logging, and no CORS origins (the admin is same-origin). The
  commented block at the end of the file is the local-development baseline, and the README
  quickstart says to apply it before starting the built-in server. `thallo:doctor` now warns
  when a public `BASE_URL` runs in development mode.
### Upgrade Notes
- **Existing `.env` files are untouched** — this only changes what a fresh copy of
  `.env.example` contains. Installs that copied the previous template and never changed
  `APP_ENV` are running in development mode on their public host; set `APP_ENV=production`
  and `APP_DEBUG=false` (or run `php glueful system:production`), then clear the compiled
  container and run `php glueful extensions:cache` — production boot refuses to start
  without that cache.

## [1.0.0-beta.5] - 2026-09-06 — Developer Preview

A maintenance release on beta.4: framework 1.81.0, whose boot profiler no longer aborts boot
on hosts where `/tmp/boot_profile.log` belongs to another OS user — the defect that stopped
`thallo:provision` on the first thallo.dev deploy. No schema, API, or admin changes; beta.4
installs upgrade in place.

### Changed
- `glueful/framework` 1.81.0 in the lock: the framework's boot profiler no longer writes a
  hard-coded `/tmp/boot_profile.log` on every boot. On a host where another OS user had
  created that file first (a second site, or a root CLI run followed by the site user), the
  denied write became a fatal `ErrorException` and no command — `thallo:provision`
  included — could boot. The dump is now opt-in via `BOOT_PROFILE_LOG` and best-effort.
  Surfaced by dogfooding thallo.dev on CloudPanel.
- `.env.example` leads with PostgreSQL: the database block named SQLite as the no-setup
  default and listed PostgreSQL as an alternative, while the effective values were already
  PostgreSQL. SQLite and MySQL are now commented blocks marked unsupported, matching
  `docs/limitations.md`.

## [1.0.0-beta.4] - 2026-09-06 — Developer Preview

A maintenance release on beta.3: the framework lock moves to 1.80.2 so `migrate:verify`
never misclassifies an untouched migration source on a healthy install. No schema, API, or
admin changes; beta.3 installs upgrade in place.

### Changed
- `glueful/framework` 1.80.2 in the lock: an untouched migration source (a disabled engine's
  schema on a fresh install) classifies `pending`, never `divergent`, so `migrate:verify`
  exits 0 on healthy installs. Beta.3 artifacts lock 1.80.1 but never hit the defect — the
  first-run sequence doesn't run verify, and the upgrade chain's `composer update` pulls the
  fix before verify executes.

## [1.0.0-beta.3] - 2026-08-18 — Developer Preview

The schema-on-enable release: schema exists exactly when the feature that owns it is
provisioned or enabled — never as a side effect of boot — and every migration operation is
locked, truthful, and recorded.

### Changed — the schema-on-enable program

- **BREAKING — pre-beta.3 installs are not upgradable in place.** Developer Preview builds up
  to `1.0.0-beta.2` recorded pack migration receipts under pre-manifest ledger names
  (`thallo-*`, render's bare `migrations`); beta.3's ledger is canonical from provision and
  ships no migration path for those receipts. Re-provision, or rewrite the ledger `source`
  values by hand before upgrading (see [docs/upgrading.md](docs/upgrading.md)).
- **Fresh provision is ONE locked, failure-aware complete pass**: `thallo:provision` applies
  the app schema, every core pack descriptor (the eight schema-owning packs and the tenancy
  platform tier), and every shipped-enabled engine together under an all-source migration
  lock, and a failed migration fails provision naming the file — never a quiet success.
  Disabled engines (Commerce, Payvia) get their schema later through the executor's
  migrate-first enable, which is the point of the program. The create-admin catch-up pass now
  applies only the app's dependent-grants lane; its first-pass-ordering retry is obsolete
  (render's permission seed moved to the dependent tier with the other packs).
- **Extension toggling works in production, truthfully**: the admin SPA and CLI both drive the
  shared schema executor — migrate-first, lock-serialized, with a persisted operation record
  (id, terminal status, failed migration, error) surfaced through the API and UI. A stale
  provider cache is a warning on success; failures and manual-repair states are 409s carrying
  the record. The extensions list shows each package's schema state (ready/pending/divergent/
  none/undeclared) with reasons and the CLI equivalent; a divergent schema blocks the toggle.
- **Capabilities know their owning engine**: each engine-backed capability declares the
  Composer package whose activation defines it (accounts→glueful/users, commerce→
  glueful/commerce, importers→glueful/import-export, search→glueful/meilisearch,
  subscriptions→glueful/subscriptions, tenancy→glueful/tenancy). Effective capability state is
  now *requested AND available* — an engine that is missing, disabled, or schema-unready turns
  its capability off everywhere at once, with the reason and remedy named, instead of leaving
  a half-alive surface.
- **One system-scoped capability switchboard**: requested state lives in
  `capability.<id>.enabled` system rows with an operator-only management surface
  (`GET /v1/admin/capabilities/manage`, `PUT /v1/admin/capabilities/{id}`, and a Capabilities
  tab on the extensions page). Disable is always allowed; enable refuses while the owning
  engine cannot back it; the Settings › General search toggle now reads and writes through
  the same authority (its legacy `search_enabled` row is retired on first write).
- Dependency stack: `glueful/framework` `^1.80` (1.80.1 in the lock — complete provision,
  the protected migration lane, unconditional manifest enforcement) and the adopted extension
  minors (aegis ^1.15, audit ^1.4, commerce ^1.13, email-notification ^1.13, i18n ^1.2,
  import-export ^1.2, media ^1.2, meilisearch ^1.7, payvia ^2.8, subscriptions ^2.3,
  tenancy ^2.1, users ^2.4). Tenancy's enablement flow migrates through the executor's
  protected lane (`protected_migrate` operations) while keeping sole custody of the provider
  state write.

### Changed
- `glueful/framework` requirement raised to `^1.78.4`: application boot performs no schema
  work at all — migration discovery and registration are database-free, and only an actual
  `migrate` operation creates the migrations ledger. (Beta.2's framework fix covered the
  migrate commands; this closes the remaining boot path through extension providers.)

### Fixed
- **Provision accepts passwordless (trust/peer-auth) PostgreSQL**: `thallo:provision -n`
  refused any empty password, so the common local trust-auth setup could not pass validation
  at all. Password *presence* is now tracked separately from its value — `--db-password=""`
  or a present-but-empty `DB_PGSQL_PASSWORD=` line means "none" and validates; a fully absent
  password still refuses. The host now defaults to `localhost` only when absent (an
  explicitly empty host still fails), and the preflight connection test remains the real
  arbiter of the credentials.

## [1.0.0-beta.2] - 2026-08-16 — Developer Preview

Corrections from the beta.1 clean-machine artifact gate (tags are immutable — beta.1 stands
as published; install from beta.2).

### Fixed
- **Fresh installs could not run any console command**: the framework console connected to the
  `.env` database on boot, and the shipped `.env.example` pointed at a database name no
  quickstart ever created. Fixed on both sides: `.env.example` now names the quickstart
  database (`thallo`) and documents the credentials requirement, and `glueful/framework`
  1.78.3 resolves migration services lazily so the console works before the database does.
- **PostgreSQL table detection was privilege-blind**: a table owned by another role (e.g.
  created during a mis-credentialed first boot) surfaced as an inexplicable "Duplicate
  table" error. `glueful/framework` 1.78.3 reads `pg_catalog` instead of the
  privilege-filtered information schema.

### Changed
- `glueful/framework` requirement raised to `^1.78.3` (carries both fixes above).
- **Dependency advisories**: `league/commonmark` updated past its published advisories
  (2.8.3 → 2.10.0). The one remaining `composer audit` finding is a dev-only tool
  (`php_codesniffer`) that never ships in `--no-dev` installs.

## [1.0.0-beta.1] - 2026-08-15 — Developer Preview

The initial public release: a self-hosted, composable CMS and commerce platform for
developers, on the Glueful PHP framework with a Vue 3 admin.

### The platform

- **Content & rendering** — block-based pages and entries with revisioning, themeable
  server rendering with caching (+ edge purge), scheduled publish/unpublish, previews
  through the theme, navigation, SEO (canonical/OG heads, sitemaps), collections and term
  index pages, forms with spam guarding, media, i18n, import/export.
- **Commerce** (installed-but-disabled tier: enable from the admin) — catalog with variants
  and stock, carts and storefront checkout, walk-in draft orders finalized through a single
  atomic authority, printable invoices/receipts (A4 + thermal), refunds, marketplace
  seller machinery, and **payment links**: hash-custodied bearer URLs with a zero-third-party
  landing page, provider webhook settlement, and a session-exposure guard that blocks
  automatic cancellation while a live checkout session could still collect money.
- **Payments** (Payvia; installed-but-disabled) — Stripe + Paystack behind one fail-closed
  collector: ensure-live hosted sessions, reference-addressable attempts with durable
  idempotency, verify-first Paystack recovery, amount-revalidated session reuse,
  attribution-bound manual confirmation. Keyless installs degrade to manual collection.
- **Subscriptions** (bundled billing engine, enabled) — provider-agnostic hosted checkout
  with its own origination ledger and reconciliation; workspace SaaS billing.
- **Multi-workspace tenancy** — full lifecycle (enable → widen → confirm → finalize) managed
  in Settings → Workspaces; tenant purge/adoption with coherence probes.
- **Admin** — Vue 3 SPA (shipped prebuilt in release tags), capability-gated areas,
  extensions browser, audit log, analytics.

### Operational contract

The production obligations (cron entries, log redaction, key generation, gateway settings)
are documented per capability in `docs/production.md`; deliberate boundaries in
`docs/limitations.md`; the upgrade sequence — including the required compiled-state clear —
in `docs/upgrading.md`.

### Pre-release development

Thallo was built May–August 2026 through successive reviewed programs: the render/content
core and collections; forms; multi-tenancy (through `glueful/tenancy` 2.0.0); the commerce
slices (catalog → checkout → invoices/receipts → walk-in draft orders); payment links
(payvia 2.6.0 / commerce 1.11.0 / framework 1.78.0); a cross-repo hardening train
(payvia 2.7.0 / commerce 1.12.0 / framework 1.78.1 — attribution binding, settlement
idempotency, draft-artifact lifecycle); and the distribution posture split behind this
release. The complete engineering record is the git history and the extension changelogs
(`vendor/glueful/*/CHANGELOG.md`).
