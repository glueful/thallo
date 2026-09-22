---
title: "The Design view"
slug: design-view
section: concepts
order: 3
summary: "How the visual builder works: the stage, the Container, breakpoints, and settings that are saved as data."
---

The Design view is where a page's blocks are arranged and styled. Open an entry under
**Content** and press **Design**. It is not a drawing program: every edit changes the entry's
data, and what you see is your own theme rendering that data. The button appears only on entries
of a type with a **blocks** field, since those are what the Design view arranges.

## The stage is the page

The middle of the screen is the stage, and it is a real page: the admin mints a
[preview](05-publishing.md) token, the stage loads the page at that token's URL, and the theme
renders it as it renders the public site — same templates, same CSS, same fonts.

An edit changes the working copy, not the entry. **Apply** sends the current fields to the
server, which renders the changed blocks and patches them into the stage; nothing is persisted.
**Auto** does it for you shortly after you stop. **Save draft** writes the draft; publishing is
a separate act, described in [drafts, preview and publishing](05-publishing.md).

You work on the stage directly. Selecting a block raises a toolbar over it — reorder,
duplicate, delete, add a block after — and double-clicking text puts the caret in it.

The stage needs the **Rendered delivery** capability (**Extensions › Capabilities**). With it
off, the Design view says so and sends you to the form editor.

## Every edit is an operation

Thallo does not snapshot the page after each change. It records what you meant: insert this
block here, set this property at this breakpoint, move this block from there to here. One
interaction — a drag, a run of keystrokes in a field — collects into one transaction, folded to
its smallest form, so dragging a slider leaves one entry in the history rather than forty.

Every operation is reversible on its own: a deleted subtree travels with the operation that
removed it. So **Undo** (⌘Z) and **Redo** (⇧⌘Z) work on structure as well as on styling. The
history holds the last 200 transactions, or 2 MB of them, whichever comes first.

## The panel and the inspector

The left panel is tabbed: **Blocks** to insert, **Outline** for the block tree, **Content** for
the ordinary form editor, **Page** for the page as a whole, and **Versions** for its history.

Select a block and a **Block** tab joins them: the inspector. **Content** edits the block's
fields, **Layout** and **Style** hold its settings, and **Advanced** carries the anchor, the
block's style classes, your own CSS class names, `data-*` attributes and an accessibility label.

## The Container arranges the page

Most blocks decide their own insides. The Container arranges other blocks: reach for it when a
page needs columns, a row or a band of colour. It is two elements — the band, which takes the
background, the border and the block's own spacing, and the content area inside it, which lays
the children out as **Flex** or **Grid**. There is no third mode. The arrangement is settings,
not data, so switching mode discards nothing: the tracks a flex container is not using are there
when you switch back.

Insert an empty Container and the stage offers **Choose a structure**: tiles for Stack, Row,
two, three and four columns, the asymmetric splits, Grid 2 × 2, Section and Section split. A
tile writes the whole arrangement at every breakpoint and creates the children.

While a Container is a grid, the editor draws its tracks over the stage. The outline moves
nothing and never reaches a public page. **Fill empty cells** completes a part-filled last row
with column containers, as one change.

## Three breakpoints, and the rule that catches everyone

A setting can differ at three widths: **base**, **md** from 768px up, and **lg** from 1024px up.
The stage's three presets choose which one you are editing — Mobile (390px) is base, Tablet
(768px) is md, Desktop is lg — and the editor decides from the preset, never from the frame's
measured width.

Here is the rule. **A setting is written at the breakpoint the stage is showing, and applies from
that width up.** To find a value at lg, Thallo looks at lg, then md, then base, and takes the
first declaration it finds. A padding set on Desktop does nothing on a phone; a padding set on
Mobile reaches every width unless something above overrides it. Set the base first, then
override upwards.

Each setting says where its value came from — set here, inherited from a narrower breakpoint,
from a style class, or the theme's default — and a dot marks each breakpoint carrying a
declaration of its own. **Apply to all breakpoints** writes one value at all three. **Clear**
removes the declaration and lets whatever is underneath show through. **Reset to theme** writes
an explicit reset, which stops resolution there and hands the property back to the theme.

## The Layout tab and the Style tab

The split is per property, not per group. The Layout tab has three parts: **Container**, how the
block arranges its children (**Layout**, **Content width**, **Gutter**, **Direction**, **Wrap**,
**Distribute**, **Align**, **Columns**, **Gap**); **Box**, its own **Width**, **Placement**,
**Minimum height** and **Overflow**; and **As an item**, how it sits in its parent — **Span** in
a grid, **Basis**, **Grow** and **Shrink** in a flex row. The tab offers only what the parent's
mode uses.

The Style tab holds the rest: Spacing, Text, Typography, Colours, Effects, Motion and
Visibility. A block type declares which settings it offers, so no block shows a control it
cannot honour. Every setting, its choices and the CSS it becomes are in
[the style settings reference](../reference/05-style-settings.md).

## Settings are the theme's vocabulary, not CSS

You never type a value. Spacing is `none`, `xs`, `sm`, `md`, `lg`, `xl`, `2xl`, `3xl`; widths
are `narrow`, `content`, `container`, `full`; colours are `background`, `surface`, `text`,
`muted`, `accent` and their neighbours. The [theme](04-themes.md) decides what each name is
worth, so a site changes its scale, its corners or its typefaces in one place and every page
follows: the page says "large padding", not "48 pixels".

The names are a contract. A theme that omits one fails validation when it loads, on a theme
switch, and in `php glueful thallo:doctor`.

## Style classes, patterns and motion

A [style class](../guides/05-style-classes.md) is a named set of the same settings, kept by the
site rather than by a theme. A block lists the classes it composes; they resolve as layers below
the block's own settings, later classes over earlier ones, so a block's own choice wins, per
property and per breakpoint. **Save as style class** in the Style tab lifts a block's
declarations into a new one.

The Blocks tab has three views: **Blocks** lists the block types you can insert, **Sections**
offers ready-made patterns, and **Pages** offers whole starter pages made of those sections. A
pattern is ordinary blocks with ordinary settings; once inserted there is nothing special about
it. See [use the section and page library](../guides/04-sections-and-pages.md).

Motion is a group of settings like any other: an entrance, its duration and delay, stagger for a
Container's children, and a slow drift for a picture. Animations are held still while you edit;
**Play** replays the selected block's once on the stage. See
[animate blocks as they scroll into view](../guides/06-animation.md).

## What the Design view does not do

There is no CSS field and no pixel input. You cannot give a block a colour outside the theme's
palette, a spacing off its scale, or a rule of your own. A block template may not emit a `style`
attribute or a `<style>` element either: the template lint refuses both, at save and before
render.

The escape hatch is named, not free-form: the Advanced tab takes your own class names, which
your theme's CSS defines. Anything beyond that is a change to the theme. That is the trade — the
settings a page carries stay a small, checkable vocabulary, so they survive a theme change and
travel through [the content API](07-api.md).

Next: [themes](04-themes.md), which decide what the vocabulary is worth, or
[build your first page](../getting-started/03-first-page.md).
