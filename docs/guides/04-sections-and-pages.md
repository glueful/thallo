---
title: "Use the section and page library"
slug: sections-and-pages
section: guides
order: 4
summary: "Start a page from ready-made sections and pages instead of an empty stage."
---

Thallo ships seventeen sections and five starter pages. Insert one and you get ordinary blocks
with placeholder copy, ready to edit. By the end of this page you will have put a whole starter
page on an entry, added a single section to it, and aimed an insert at an exact place.

You need an entry with a `blocks` field open in [the Design view](../concepts/03-design-view.md).
[Build your first page](../getting-started/03-first-page.md) gets you there.

## Open the library

Open the **Blocks** tab in the side panel. Above the tiles are three views: **Blocks**,
**Sections** and **Pages**. **Blocks** offers one block type at a time. **Sections** and **Pages**
are the library. The switch appears only when the library has something to offer.

The box at the top filters the view you are in — `Filter sections…`, `Filter pages…` — and matches
a pattern's name, its category and its description.

The library is part of the Design view. The header and footer editor (**Site › Header & footer**)
has no **Sections** or **Pages** view.

## Insert a section

Open **Sections**. The cards are grouped by category, each showing a picture of what it inserts.

Where a section lands depends on what you did before clicking:

- Nothing selected: the end of the entry's first `blocks` field.
- A block selected: straight after that block.
- A place armed: that place. Press **Add block after** on a block's toolbar on the stage, or
  **Add** on a slot row in the **Block** tab. The Blocks tab opens and says which place it is
  holding — `Inserting after Hero` — with **Cancel** beside it. Escape in the filter box clears it
  too.

Then insert:

1. Click a card. The section arrives as one block, becomes the selection, and the stage scrolls to
   it with the inspector on the **Block** tab.
2. Or press and drag the card onto the stage. A ghost carries the section's name and the stage
   shows where it would go. Release outside the stage, or press Escape, and nothing is inserted.
3. Or type in the filter box and press Enter, which inserts the first card that is offered.

Each of the four **Hero** sections is a single Hero block; every other section is a single
Container. So a section moves, duplicates and deletes as one thing, and one undo removes it.

## Insert a whole page

Open **Pages** and click a card. A page has no drag: clicking is the only way in.

Its sections are inserted one after another at the same place, as one transaction. One undo takes
the whole page back out.

Every starter page opens with a section that carries the page's heading, so inserting one also
sets **Show page title** on the **Page** tab to **Hide**; otherwise the theme would print the
entry's title above it as a second heading. Thallo says so when it does. Set it back to **Show**
if you want both.

A page goes in whole or not at all. If the sequence would not fit — a slot that refuses
containers, or the five-level nesting cap — Thallo inserts nothing and says `That page does not
fit here`.

## What ships

| Category | Sections |
|---|---|
| Hero | Centred hero · Hero with highlights · Dark hero · Page header |
| Features | Feature grid · Six features · Features beside text · How it works |
| Social proof | Numbers · Testimonials |
| Pricing | Pricing plans |
| FAQ | FAQ |
| Call to action | Call to action · Call to action, split |
| Content | Our story · Latest posts |
| Contact | Contact form |

| Page | Sections |
|---|---|
| Landing page | Centred hero · Feature grid · How it works · Testimonials · Pricing plans · FAQ · Call to action |
| About | Page header · Our story · Numbers · Testimonials · Call to action, split |
| Pricing | Page header · Pricing plans · FAQ · Call to action |
| Contact | Page header · Contact form · FAQ |
| Services | Hero with highlights · Six features · How it works · Call to action, split |

## Edit what arrived

Nothing in a pattern is a special kind of thing. Every part of it is a block you already have, and
after the insert the library has no hold on it: editing a section changes that page and nothing
else.

Two sections carry more than copy.

- **Latest posts** holds a Blog posts block set to the three newest entries of the `post` content
  type. With no published posts, the stage shows `No posts found.` and the public page renders
  nothing there.
- **Contact form** holds a form named `Contact`, set to store the submission and email it. See
  [add a form and receive submissions](07-forms.md) for where submissions arrive and what mail
  settings it needs.

No pattern names an image, so nothing arrives broken on a site with an empty media library.

## Why a section or page is missing

A pattern is offered only if this site can use every block type in it. Switch a block type off
under **Settings › Block Types** and every pattern built on it goes: switching off Pricing plans
removes the **Pricing plans** section and also the **Pricing** page, which is made of it. Switch
the type back on and both return. See
[switching a block type off](../concepts/02-blocks.md#switching-a-block-type-off).

A card that is present but dimmed is one the armed place refuses. Its tooltip says why —
`Would nest deeper than 5 levels`, for a place too far down the tree to hold the whole section.
Clicking it does nothing; the same card is fine somewhere shallower.

If the block you armed the insert against has since gone, the tab says `That place is gone — pick
a block for the default place.` and falls back to the end of the field.

## Check it worked

Open the **Outline** tab. A section shows as one block at the level you dropped it, with its parts
beneath. Press undo once: the whole section, or the whole page, disappears. Press redo, then
**Save draft**.

Next: [reuse styling with style classes](05-style-classes.md), which turns the settings a pattern
arrived with into something you can apply elsewhere.
