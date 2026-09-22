---
title: "Build the site's menus"
slug: navigation
section: guides
order: 3
summary: "Create a menu, order its links, and show it in the header."
---

At the end of this page the site has a menu you control: links to your own pages and to
addresses elsewhere, nested where you want them, rendered in the header by the Navigation block.

You need an install you can sign in to, an account with the `navigation.manage` permission, and
the **Navigation** capability switched on — it is on by default, and
[capabilities](../concepts/06-capabilities.md) says where the switch is. A new install already
has one menu, **Main**, holding a single link, **Home**, to `/`, and its header already shows it.

## Create a menu

1. Open **Site › Navigation**. If the page says "Navigation isn't enabled.", the capability is
   off and nothing below will work.
2. Press **New menu**.
3. Fill in **Slug** and **Name**. The slug is the name a block and the API use for this menu:
   lower-case letters, digits and hyphens, up to 64 characters. The name is what you read in the
   admin, up to 120 characters.
4. Press **Create**.

The menu joins the list on the left, which shows each menu's name, its slug and how many items it
holds. Drag a row by its handle to reorder the list. The row's overflow button offers **Rename**,
**Move up**, **Move down** and **Delete**. Deleting removes the menu and all of its items, and
cannot be undone.

## Add a link to a page

1. Select the menu.
2. Press **Add page**.
3. Choose a **Content type**. A type that is not publicly delivered is listed with
   "— not publicly delivered" and cannot be chosen: its entries have no address on the site.
4. Pick the entry.

The item arrives with an empty label. An empty label follows the page's title in the language
you are editing, and keeps following it when the title changes. Type one only to override it.

Beside a page item Thallo shows a badge and the path it resolves to: **published**,
**unpublished**, **needs a route**, **deleted** or **missing**. Only **published** items reach
the site.

## Add a link to an address

Press **Add link**. The item arrives with `/` in its URL field. Replace it with a path on this
site (`/pricing`) or a full address (`https://example.com`); anything else is refused when you
save, as is a URL over 1024 characters. Give the item a label too — a URL item has no page title
to inherit one from.

## Fill in a row

Every row carries the same three extras, whichever kind it is:

- The **label** for the language you are editing, up to 200 characters.
- A **description**, up to 500 characters: a supporting line shown under the label inside a
  submenu panel.
- An icon. The button reads **Icon** until you choose one; it opens the icon picker, and the
  icon is drawn before the label.

## Order and nest the items

Drag a row by its handle, or use the up and down arrows. The indent button nests a row under the
row above it; the outdent button, which appears only on a nested row, lifts it back out. Dragging
works between levels as well, except into a row's own children.

A menu holds at most 500 items and nests at most six deep; the indent button is off, and a drag
refused, where a row would go deeper. The default theme renders three of those levels: a top-level
item, its children and their children. A **dropdown** submenu flattens children and grandchildren
into one panel; a **columns** submenu gives each child a column of its own children. Items deeper
than that are stored but not drawn, and the editor marks each one with its level.

## Save

Press **Save**. The whole tree is written in one go and every cached page is discarded, so the
site shows the new menu at once.

If somebody else saved the same menu while you had it open, your write is refused and the editor
says so, with your changes still on screen. **Load the latest** drops them and shows their
version; **Save mine over it** writes yours in its place.

## Show the menu in the header

1. Open **Site › Header & footer**.
2. On the **Header** tab, open **Content**.
3. Press **Add block** and choose **Navigation**.
4. Click the block's card to open it, and pick your menu in the **menu** field.
5. Press **Save**.

The pane beside the editor renders the header as you work. [Edit the header and
footer](02-header-and-footer.md) covers the rest of that screen.

The same block goes in the footer, and on a page: in the Design view its card is in the
**Blocks** tab under **Layout**.

Its other fields decide how the menu looks — **orientation**, **align**, **size**, **variant**,
**color**, **highlight** for the current page, **submenu layout**, **submenu icon** and
**submenu trigger**, which opens submenus on hover or on click. **Navigation label (assistive)**
names the menu for a screen reader; it is "Navigation" until you change it. Below 48rem the
default theme folds the whole list behind a button reading **Menu**.

## Give the labels another language

A menu is one tree however many languages the site has. What varies by language is the text:
labels and descriptions are stored per language.

The row of language codes above the tree chooses which language you are editing, and the label
and description fields follow it. A label left empty in the language a visitor asks for falls
back to the site's default language, then to any language that has one; a page item with no label
anywhere falls back to the page's own title. Switching the row also re-resolves the badges, so a
page published in one language and not another shows **unpublished** on that language's tab.
Languages are added under **Settings › Languages**.

## Check it worked

Load the site. The header shows the menu, the current page's link is marked, and a parent opens
its submenu.

An item you cannot find is one whose target is not published. Thallo drops a page item whose entry
is not published in the language being rendered, and everything nested under it, so a menu can
never render a dead link. Publish the entry, or give it a route if the editor said **needs a
route**, and the item comes back. A menu with no surviving items renders nothing at all.
