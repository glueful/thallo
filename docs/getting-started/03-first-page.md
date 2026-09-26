---
title: "Build your first page"
slug: first-page
section: getting-started
order: 3
summary: "Open the Design view, lay out a page from the library, and publish it."
---

By the end of this page your site has a real front page: an entry of the **Pages** content type,
laid out from Thallo's own library of sections, edited on the page itself, published, and
rendered by the theme at `/`.

You need an install you can sign in to, from [install Thallo](02-install.md).

## Create the page

Pages are entries. The install seeds a **Pages** content type, so open **Content › Pages** in the
sidebar and press **New Pages**. Thallo creates an empty entry and opens the form editor.

The form editor lists the content type's own fields. The starter Pages type has two: **title**, a
line of text, and **body**, which holds the blocks. Type a title — `Home` will do.

On the right, under **Publishing**, the **Slug** field follows the title as you type it. This is
the entry's URL on the site: the page cannot be the homepage without one, and the Design view
will not publish it without one. Press **Save route** beside it, then press the save icon in the
top bar (**Save draft**).

Save before you go on. The Design view loads the draft from the server, not from this screen.

## Open the Design view

Press **Design** in the top bar. The Design view is Thallo's visual builder, and it opens on this
entry in the default language.

Three things are on the screen.

- **The top bar.** Three screen-size buttons, undo and redo, the preview controls
  (**Refresh preview**, **Auto**, **Apply**), an eye that opens the preview in a new tab, the save
  icon, and **Publish**.
- **The side panel**, down the left, with its tabs: **Content**, **Blocks**, **Outline**,
  **Page**, **SEO** and **Versions**. A **Block** tab joins them whenever a block is selected —
  that is the inspector, with its own **Content**, **Layout**, **Style** and **Advanced** tabs.
- **The stage**, filling the rest. It is not a mock-up: it is the page, rendered by your theme in
  a preview session, the same templates the public site uses.

## Insert a starter page

Open the **Blocks** tab. Above the tiles are three views: **Blocks**, **Sections** and **Templates**.
The first offers one block at a time. The other two are the pattern library: whole pieces, built
out of ordinary blocks, that arrive ready to edit.

Open **Templates**. Five patterns are offered, each shown as a picture of what it inserts:
**Landing page**, **About**, **Pricing**, **Contact** and **Services**. Click **Landing page**.

Its seven sections — a hero, a feature grid, how it works, testimonials, pricing, an FAQ and a
closing call to action — land at the end of the body as one change. One press of undo in the top
bar takes the whole page back out.

Nothing in a pattern is a special kind of thing. Every part of it is an ordinary block, with
placeholder text that is obviously yours to replace, and no image to go missing.

The hero now carries the page's heading, so the theme's own title above it is a repeat. Open the
**Page** tab and set **Show page title** to **Hide**.

## Change the text on the stage

Click a block on the stage to select it. A small toolbar appears on it: **Drag to reorder**,
**Move up**, **Move down**, **Duplicate**, **Delete** and **Add block after**.

Double-click the hero's headline. The text becomes editable where it stands; type over it. On a
rich text field a formatting bar follows the selection, with **Bold**, **Italic**, **Underline**,
**Strikethrough**, **Add link** and **Remove link**.

Press Escape, or click elsewhere, to end the edit. What you typed is kept — undo is how you take
it back, not Escape.

## Add a section

Go back to the **Blocks** tab and open **Sections**. Seventeen sections are grouped by what they
are for: Hero, Features, Social proof, Pricing, FAQ, Call to action, Content and Contact.

With nothing selected, clicking a card puts that section at the end of the body; with a block
selected, it goes straight after that block. To drop it exactly where you want instead, drag the
card onto the stage. Either way a section arrives as one block, which is also how it moves and
how it is deleted.

To aim before you insert, press **Add block after** on a block's toolbar. The Blocks tab then
shows the place it is holding — `Inserting after Hero` — with **Cancel** beside it.

## Check the narrow widths

The three buttons at the left of the top bar set the stage's width: **Desktop viewport** (the
full panel), **Tablet viewport** (768px) and **Mobile viewport** (390px). The page reflows as a
visitor's would.

They do one more thing, and it catches everyone. The button also chooses which breakpoint your
style settings are written at — Desktop writes `lg`, Tablet writes `md`, Mobile writes `base` —
and a setting applies from its own width upwards until a wider one overrides it. So set the look
you want on Mobile first, then correct it on Tablet and Desktop. Text you type on the stage is
not affected: a word is a word at every width.

## Save, preview and publish

**Auto** is on, so the stage catches up with your edits a moment after you stop making them.
**Apply** pushes them to the stage at once.

The eye opens the same preview in a new tab, where you can walk the rest of the site around your
draft. Nothing there is public: the preview is a signed session, and it never enters the page
cache.

The save icon saves the draft and says `Draft saved`. **Publish** saves anything outstanding
first, then makes the draft the live version; afterwards the button reads **Update**.

## Make it the homepage

Open **Settings › General** and find the **Homepage** card. Choose **Content type** — Pages —
then pick the entry, and press **Save**. Thallo accepts only a published entry of a publicly
delivered type that has a slug, which is why you saved the route before you started.

There is a second way to the same setting: the house icon in the form editor's **Publishing**
panel, which reads **Set as homepage** and is disabled until the entry is published and has a
slug.

## See it

Open `http://localhost:8000/`. You get the page you just built, rendered by the theme, with
nothing of the admin around it. A Pages entry also keeps its own address, which is `/` plus its
slug: `http://localhost:8000/home`.

## Next

[Model your own content](04-first-content-type.md) makes a content type of your own. For what the
Design view is doing underneath, read [the Design view](../concepts/03-design-view.md) and
[content types, entries and fields](../concepts/01-content-model.md).
