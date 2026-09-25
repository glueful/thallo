---
title: "Edit the header and footer"
slug: header-and-footer
section: guides
order: 2
summary: "Change what is in the site's header and footer, and how they look."
---

The header and the footer are regions: chrome the theme draws around every page. By the end of
this page your own blocks are in them, each bar behaves and looks the way you want, and both are
live.

You need an account that can manage content. If you want a menu in the header, build it first
under **Site › Navigation** — see [build the site's menus](03-navigation.md).

## What a region starts as

A region is empty until you save something into it, and an empty region renders nothing: the
theme falls back to its own built-in chrome. The default theme's built-in header shows the site
logo and the menu whose slug is `main`; its built-in footer shows the site name. The moment a
region holds one block, that fallback is gone — the theme renders your blocks and nothing else.

## Open the editor

Go to **Site › Header & footer**. The page is a stage, like [the Design view](../concepts/03-design-view.md):
a real published page — the homepage unless you pick another in the toolbar — rendered by the
theme, with the header and footer live on it. The page body is there for context; clicking it does
nothing. On the left is the inspector. The toolbar holds a **Header | Footer** switch, the three
widths, the page picker, **Undo** and **Redo**, and one **Save** for both regions. Nothing reaches
the site until you press **Save**.

The **Header | Footer** switch picks the *current region*: the one the **Blocks**, **Region** and
**Outline** tabs work on. Selecting a block on the stage switches to its region.

## Add blocks to a region

1. Choose **Header** or **Footer** in the toolbar, then the **Blocks** tab.
2. Drag a tile onto the stage where the block should go, or click it to add it after the selected
   block (or at the end of the region). Tiles the region does not take are dimmed.
3. Click the new block on the stage: the **Block** tab opens with its **Content**, **Layout**,
   **Style** and **Advanced**. Text you can double-click on the stage and type in place.
4. Move a block with the arrows in its toolbar or by dragging its grip, copy it with
   **Duplicate**, remove it with **Delete**, which asks to confirm. The **Outline** tab shows the
   region's blocks as a tree and moves them precisely.

A region takes a fixed list of block types, and the server enforces it: a type that is not on the
list is refused, not merely hidden in the palette.

The header takes **Logo**, **Navigation**, **Button**, **Color mode**, **Social links**,
**Container** and **Rich text**.

The footer takes **Logo**, **Navigation**, **Button**, **Social links**, **Container**,
**Rich text**, **Separator**, **Spacer**, **Icon**, **Image**, **Shortcode**, **HTML**,
**Footer** and **Links**.

Both also take **Mini cart**, **Wishlist link** and **Account state**, each offered only while
the [capability](../concepts/06-capabilities.md) that defines it is on.

The list governs the top level only. A **Container** inside a region holds whatever its own field
allows, so nesting one is how a region ends up with a block the top level does not offer.

## Point a Navigation block at a menu

A **Navigation** block holds no links. It renders a menu: its **menu** field lists the menus that
exist, and you choose one. The links, their order and their nesting belong to the menu, edited
under **Site › Navigation**, so a change there reaches every place the menu is used. The built-in
header's `main` menu stops being automatic as soon as the region holds blocks: add a
**Navigation** block and pick the menu yourself.

## Set the bar's options

The **Region** tab holds the current region's own settings. **Width** is either **Contained** —
the bar's content is held to the theme's page measure — or **Full width**, which lets it run edge
to edge.

The header has one more control: **Sticky**. Switched on, the header stays at the top of the window
as the visitor scrolls. The footer has no **Sticky**.

## Style the bar

Below them on the **Region** tab, **Style** styles the bar itself, not the blocks in it. It offers
three groups:

- **Spacing** — **Padding** on all four sides, **Margin** top and bottom.
- **Colours** — **Background**, **Text colour**, **Border colour**, **Background opacity** and
  **Backdrop blur**.
- **Effects** — **Corners**, **Shadow**, **Border width**, **Border style** and **Border sides**.

A region is not a block: there is no Layout tab, no Advanced tab and no
[style classes](05-style-classes.md) to apply. Padding lands on the bar's inner element, where
the theme pads; everything else lands on the bar.

Padding, margin and shadow are responsive, so their value is written at the breakpoint the
stage is showing, by the rule in [the Design view](../concepts/03-design-view.md). The other
settings apply at every width.

To style one block rather than the bar, select it on the stage: its **Block** tab has the same
**Layout**, **Style** and **Advanced** the Design view's inspector shows.

## Watch the stage

The stage renders the page through the real theme, with the site's colours, fonts and custom CSS,
and your header and footer as they are now, saved or not. Each edit is checked exactly as a save
would check it and then shown; an edit the server refuses is named in a message, and the stage
stops updating until you press **Stage paused — resume** in the toolbar.

The three width buttons set the stage's width: desktop, tablet at 768px and mobile at 390px. The
one you choose is also the breakpoint a responsive style setting is written at.

Pick another page to see the bars around it; your unsaved edits come with you. If the page you
pick hides the header or footer in its own settings, the inspector says so. The stage needs the
**Rendered delivery** capability (**Extensions › Capabilities**); without it the page says so.

## Save, and hide a region where you do not want it

Press **Save** — a dot on the button marks unsaved changes. One save stores both regions, and the
change is live on the site immediately: a region has no draft and no publish step, and no
per-locale variant. One header and one footer serve the whole site. If someone else saved the
header or footer since you opened the page, the save is refused and the toolbar says **Changed by
someone else**; **Reload** discards your edits and loads theirs. Leaving the page with unsaved
edits asks first.

To drop the chrome from a single page, open that entry in the Design view, choose the **Page**
tab, and set **Header** or **Footer** to **Hide**. **Theme default** follows the theme and
**Show** overrides a theme that hides it; the choice saves and publishes with the page.

## Check it worked

Open the site. The header shows your blocks in the order you put them, at the width you chose,
and it follows the page down if **Sticky** is on. A page you set to **Hide** shows neither that
bar nor the theme's fallback. To undo the whole thing, delete every block from the region and
save: the theme's built-in header or footer comes back.

Next: [build the site's menus](03-navigation.md), or
[set your colours, fonts and logo](01-appearance.md).
