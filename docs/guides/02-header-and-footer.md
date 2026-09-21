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

Go to **Site › Header & footer**. On the left is the editor, with a **Header** tab and a
**Footer** tab; each holds a **Content** tab, a **Style** tab and its own **Save**. On the right
is a preview of both bars around a placeholder page body. Nothing reaches the site until you
press **Save**, and each region saves on its own.

## Add blocks to a region

1. Choose the **Header** or **Footer** tab, then **Content**.
2. Press **Add block** under the list, or the `+` that appears in the gap between two blocks.
   A tile grid opens with a **Filter blocks…** box; pick a block type.
3. Fill in the block's fields on its card.
4. Reorder with the drag handle or **Move up** and **Move down**, copy with **Duplicate**, remove
   with **Delete**, which asks to confirm.

A region takes a fixed list of block types, and the server enforces it: a type that is not on the
list is refused at the save, not merely hidden in the picker.

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

Above the block list, **Width** is either **Contained** — the bar's content is held to the
theme's page measure — or **Full width**, which lets it run edge to edge.

The header has one more control, at the top of its tab: **Sticky**. Switched on, the header stays
at the top of the window as the visitor scrolls. The footer has no **Sticky**.

## Style the bar

The **Style** tab styles the bar itself, not the blocks in it. It offers three groups:

- **Spacing** — **Padding** on all four sides, **Margin** top and bottom.
- **Colours** — **Background**, **Text colour**, **Border colour**, **Background opacity** and
  **Backdrop blur**.
- **Effects** — **Corners**, **Shadow**, **Border width**, **Border style** and **Border sides**.

A region is not a block: there is no Layout tab, no Advanced tab and no
[style classes](05-style-classes.md) to apply. Padding lands on the bar's inner element, where
the theme pads; everything else lands on the bar.

Padding, margin and shadow are responsive, so their value is written at the breakpoint the
preview is showing, by the rule in [the Design view](../concepts/03-design-view.md). The other
settings apply at every width.

To style one block rather than the bar, press **Block settings** on its card. That swaps the
region's tabs for the block's **Layout**, **Style** and **Advanced** — the same tabs the Design
view's inspector shows. **Back to the header** returns.

## Watch the preview

The right-hand pane renders both bars through the real theme, with the site's colours, fonts and
custom CSS, around a **Page content** placeholder. It refreshes shortly after you stop typing;
**Refresh** forces it. It validates the edit exactly as a save would, so a refusal appears here
before anything goes live: the pane then keeps the last good render, marks it **Preview not
updated**, and prints the reason above the frame.

The three buttons in the toolbar set the preview's width: desktop, tablet at 768px and mobile at
390px. The one you choose is also the breakpoint a responsive style setting is written at.

Two things the preview will not do. Scripts are off inside its frame, so a dropdown menu or the
**Color mode** switch does not operate there. And it needs the **Rendered delivery** capability
(**Extensions › Capabilities**); without it the pane reports that the render pack is not active.

## Save, and hide a region where you do not want it

Press **Save** on the region you changed — a dot on the button marks unsaved changes. Thallo
confirms that the change is live on the site immediately: a region has no draft and no publish
step, and no per-locale variant. One header and one footer serve the whole site.

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
