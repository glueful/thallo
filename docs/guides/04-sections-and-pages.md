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
**Sections** and **Templates**. **Blocks** offers one block type at a time. **Sections** and
**Templates** are the library; in the Design view, **Templates** holds whole starter pages. The switch appears only when the library has something to offer.

The box at the top filters the view you are in — `Filter sections…`, `Filter templates…` — and matches
a pattern's name, its category and its description.

The library is part of the Design view, and of the header and footer editor (**Site › Header &
footer**). Each offers only what belongs there: the Design view never shows the header's and
footer's sections, and the header and footer editor never shows a page body's. There,
**Templates** holds whole headers and footers instead of pages, and both views show only the region
you are editing — header sections and templates while **Header** is on, footer ones on **Footer**.
See [headers and footers](#headers-and-footers).

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

Open **Templates** and click a card. A page has no drag: clicking is the only way in.

Its sections are inserted one after another at the same place, as one transaction. One undo takes
the whole page back out.

Every starter page opens with a section that carries the page's heading, so inserting one also
sets **Show page title** on the **Page** tab to **Hide**; otherwise the theme would print the
entry's title above it as a second heading. Thallo says so when it does. Set it back to **Show**
if you want both.

A page goes in whole or not at all. If the sequence would not fit — a slot that refuses
containers, or the five-level nesting cap — Thallo inserts nothing and says `That template does not
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

## Headers and footers

The header and footer have a library of their own. Their sections are one block each, built to
fill the region's row, and insert like any other section. A template is a whole header or footer:
it **replaces** the region's blocks. If the region already has blocks, Thallo asks first —
`Replace the whole header with Classic header? Undo brings it back.` — and changes nothing until
you press **Replace**. The replace is one step: a single **Undo** puts the old header back. Nothing
is saved until you press **Save**, as with any other change there.

| Region | Sections |
|---|---|
| Header | Logo, menu and button · Announcement bar · Centred logo and menu |
| Footer | Link columns · Copyright and social links · Tagline and social links · Copyright line |

| Template | Sections |
|---|---|
| Classic header | Announcement bar · Logo, menu and button |
| Simple header | Logo, menu and button |
| Centred header | Centred logo and menu |
| Four-column footer | Link columns · Copyright and social links |
| Simple footer | Tagline and social links · Copyright line |

The menu in them is **Main**, the one a new site starts with; pick another on the Navigation
block's **Block** tab. The footer's links and social profiles point at `#` and the networks' home
pages. Replace them with your own. The copyright line prints this year and the site's name, and
keeps itself current.

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

## Save your own sections

Any block you have built — a card, a pricing box, a container holding a whole band of blocks — can
join the library:

1. Select it on the stage (or in the Outline), so its **Block** tab opens.
2. Press the bookmark button beside the block's name, **Save as section**.
3. Give it a **Name**, and optionally a **Category** (it goes under **Saved** otherwise) and a
   **Description**, then press **Save section**.

It appears under **Sections**, in its category, with your other sections, and is inserted the
same way: click it, or drag it onto the stage. What you insert is a copy — change it freely on the
page; the saved section stays as it was, and pages that used it keep their copies when you later
rename or delete it. A saved section has no picture on its card, only its name and description.

A saved section is one block and everything inside it. To save several blocks as one section, put
them in a **Container** first and save the container. Saved sections belong to the site, so
everyone who edits content sees them; saving, renaming and deleting one needs the right to manage
content. The pencil and bin on a saved section's card rename and delete it.

A section saved in the header and footer editor belongs to the region you saved it from. It is
offered there again, under that region's **Sections**, and never in the Design view. A section
saved in the Design view is never offered in the header or footer. Only a block the region takes
at its top level can be saved from there. A container always qualifies. A heading does not, since
neither region takes a bare heading.

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
