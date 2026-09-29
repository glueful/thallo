---
title: "Design a layout"
slug: layouts
section: guides
order: 20
summary: "Design every post of a content type at once: where the title, date, cover and content go, and what surrounds them."
---

A layout designs every page of one kind at once. Build it once for your posts — the categories
above the title, the date and a lead under it, the cover, then the post's own content, then
related posts — and every post, old and new, shows that way. Each post keeps its own content;
the layout decides where that content sits and what surrounds it.

You need the **Manage templates** permission (`templates.manage`) to edit a layout, and
rendered delivery turned on: layouts are edited on your site's real theme output.

## What a layout is

A layout is a list of blocks, like a page's. Two kinds of block go in it:

- **The blocks you already use** — headings, containers, buttons, images, forms — with their
  settings and style classes. They are the same on every post.
- **Fields blocks**, which show the current post's own data: its title, its date, its cover, a
  field's value. They hold settings (which field, what format), never the data. On a post they
  show that post's values.

One **Entry content** block places the post's own content — the blocks of its body — and every
layout has one where the type has a blocks body. The post's content is still edited on the post.

Every content type the site publishes can have a layout for its single pages, and — where the site
lists the type — for its listing pages and its archive pages: see [Design listing and archive
pages](#design-listing-and-archive-pages). A page kind without a layout shows through the theme's
template, as before. With Commerce on, the shop's product page can have one too: see [Design the
product page](#design-the-product-page).

## Open a layout

1. Open **Site › Layouts**. Each row is a page kind, such as **Posts — single post**, marked
   **Theme template** or **Custom layout**.
2. Choose **Edit** on the row.

The editor opens on a stage: the layout, drawn around one of the type's published posts. A type
with no layout yet opens on a starter built from its fields — for posts, the categories, the
title, the date, the excerpt, the cover, the content and related posts; for a type with only a
title and a body, those two.

## Place the post's fields

The **Blocks** tab lists the **Fields** first. Drag one onto the stage, or select a block and
click a tile to add it after:

| Block | Shows | Settings |
|---|---|---|
| **Entry title** | the title | **level** (h1 to h4), **Link to the entry** |
| **Entry date** | when it was published | **format** (long, short, relative), **prefix** |
| **Entry cover** | an image field, such as the cover | **Image field**, **aspect** (natural, 16:9, 4:3, 1:1), **Link to the entry** |
| **Entry excerpt** | a short text field, as the lead | **Text field**, **Lines at most** |
| **Entry terms** | a reference field's terms, such as categories | **Reference field**, **style** (text or badges), **Link to their archives** |
| **Entry field** | any other field | **Field**, **format** (text, rich, number, date) |
| **Entry content** | the post's own content | **Blocks field** (the body when left empty) |
| **Previous and next** | the posts either side of this one, by date | **previous label**, **next label** |
| **Related entries** | the newest other posts of the type | **count** (one to six), **style** (list or cards) |

A field block names a field of the type. A field that is empty on a post shows nothing on the
site; on the stage it says so, so an empty cover is still something you can select and move.
Terms link to their archive pages only where the site lists the type; otherwise they show as
text, so no link ever leads to a missing page.

Select any block on the stage to open its **Block** tab — its settings, its style and its
classes — as on any page. The post's own content inside **Entry content** is not part of the
layout: it does not select, and it changes with the sample.

## The page around the layout

The **Frame** tab sets, for every post of the type, the page's **Width** (the theme default,
contained or full width), **Show the header** and **Show the footer**. A post's own page settings win
where it sets them: a post that hides its footer hides it under any layout.

## Preview against another post

The picker in the top bar lists the type's published posts, newest first; the stage shows the
layout around the one you choose. Your unsaved changes come with you. With nothing published
yet, the stage shows a placeholder post — "Sample post", today's date, no cover — and says so;
nothing is written. If the post you are previewing is unpublished while you work, the stage
falls back to the placeholder and your changes stay.

## Save, and what it changes

**Save** applies the layout to every post of the type at once, and says so beside the button
("Applies to every post"). Pages are refreshed as soon as it is saved.

A layout must keep its **Entry content** block for the body: deleting it is refused with the
reason, and Save stays off while it is missing. Move it instead.

If someone else saved the layout while you worked, Save shows **Changed by someone else** with
**Reload**. Reload discards your unsaved changes and opens theirs; there is no overwrite.

The menu beside Save has **Reset to starter**, which puts the starter back as one change Undo
takes back.

## Remove a layout

**Remove layout**, in the same menu, returns every post of the type to the theme's template. It
asks first: your unsaved changes are discarded. Afterwards the editor goes back to **Site ›
Layouts**, and the row reads **Theme template**. Editing it again opens the starter.

## Let one post use the theme's template

A single post can opt out: on its **Design** view, open the **Page** tab and choose **Theme
template** under **Design**. That post renders through the theme's template as if its type had
no layout. **Type layout** puts it back. The site's front page, at `/`, never uses a layout; the
same entry opened at its own address does.

## What a post's Design view shows

A post of a type with a layout is shown inside it on its own **Design** view. A strip above the
stage names the layout, with **Edit layout** to open it. The post's blocks are edited as always;
the layout's blocks are not selectable there. **Show page title** does not apply under a layout —
the layout places the title — and the **Page** tab says so.

## Design listing and archive pages

A type the site lists — chosen under **Settings › General › Public listings**, in **Listing
types** — has two more kinds of page: its listing (`/post`, then `/post/page/2` and on) and, for each reference field that files it,
such as its categories, an archive per term (`/post/categories/pottery`). **Site › Layouts** has a
row for each: **Posts — listing pages**, and **Posts — Categories archive**. One layout designs every
page of a listing, or every term's archive of one field.

A type that is not listed shows its rows turned off, with the reason — "Listing pages are off for
Pages." — and **Turn on listing pages**, which opens **Settings › General**. Taking a type off the
list keeps its layouts; listing it again serves them again. A row turned off because its pages are
off the site, and that keeps a custom layout, has **Remove** instead of **Edit**, for a layout you no
longer need. (A row turned off only because its blocks are not installed yet is still live: it has
neither until they are.)

**Edit** opens the stage on the listing's first page, or on one term's archive — the picker lists
the terms that have published posts. The layout opens on a starter that follows today's page: the
title, the list of posts — each card today's row, the cover beside the title, the date and the
excerpt — and the page navigation. An archive's starter has no term description, as today's archive
page has none; add the **Term description** block from the palette to show it.

### The Entry list and its card

The **Entry list** shows every post on the page. You design one post's **card** — once — and the
list repeats it for each post, newest first. On the stage the first card is the one you edit: its
blocks select, move and take settings, and whatever you drop into it shows in every card. The other
cards show the same design for the page's other posts; nothing in them selects, and nothing drops
there. With nothing published yet, the stage shows one sample card and says "No published posts yet —
showing a placeholder"; nothing is written.

A post's own fields go inside the card: **Entry title**, **Entry date**, **Entry cover**, **Entry
excerpt**, **Entry terms** and **Entry field**, with the settings they have on a single post. They
cannot be placed outside it — the editor says so, and so does the server. The page's own blocks
cannot go inside a card:

| Block | Shows | Settings |
|---|---|---|
| **Entry list** | every post on the page, each as its card | **When there are no entries** (the text an empty page shows) |
| **Listing title** | the type's name; on an archive, the term's title | **level** (h1 to h4) |
| **Term description** | on an archive, the term's description | — |
| **Page navigation** | the newer and older links and "Page X of Y"; nothing on a single page | **Newer label**, **Older label**, **Show "Page X of Y"** |

Your other blocks — headings, text, images, containers — go anywhere, the card included.

Every listing and archive layout keeps exactly one **Entry list**: deleting it is refused with the
reason ("Every page of the post listing shows its Entry list here…"), and Save stays off without it.
It has no **Visibility** setting, a container holding it cannot be hidden, and neither the Entry
list nor a block holding it takes **CSS classes** — use style classes to style them.

Select the Entry list and open its **Layout** tab to **Arrange the cards**: a column of cards (the
default), a wrapping row, or a grid of two, three or four columns, with the gap between them. The
arrangement places whole cards; what is inside a card stays in the card's own flow, so a block
directly in a card has no item controls. To arrange blocks inside the card — the cover beside the
text, say — put them in a container in the card and give the container its layout.

Inside a card the blocks sit as today's list shows them: the cover is a thumbnail, 160px wide
(96px on phones), and the title, date and excerpt take the list's text sizes; the blocks carry no
page spacing of their own. A value you set on a block wins — a card that shows its cover full width
sets the cover's **Width**.

### Save, and what the pages keep

**Save** applies the layout to every page of the listing ("Applies to every page of the post
listing"), or to every term's archive ("Applies to every category page of Posts"). While a layout
exists it is used instead of the theme's listing template for that type, whichever the theme ships;
**Remove layout** brings the theme's page back.

A layout never changes which pages exist: a page past the last, an unknown term or a type that is not
listed is still not found, and `/post/page/1` still leads to `/post`.

On a site upgraded to this release, add the new blocks once:

```bash
php glueful thallo:provision
# with workspaces on, also bring every existing workspace up to date:
php glueful thallo:tenant:sync --all --kind=block_type
```

## Design the product page

With [Commerce](18-commerce.md) switched on, **Site › Layouts** has a **Products — product page**
row. Its layout designs every product's page at once: **Edit** opens it on the stage around one of
your active products (the picker lists them, newest first), or around a **Sample product** while the
shop has none. **Save** says **Applies to every product**.

The **Blocks** tab leads with the product's fields:

| Block | Shows | Settings |
|---|---|---|
| **Product breadcrumb** | Shop, the product's category and its name | **Hide the category** |
| **Product gallery** | the cover, with thumbnails that swap it when there are more images | **Hide the thumbnails**, **aspect** (4:3, 1:1, natural) |
| **Product category** | the product's category, above its name | **Link to the category** |
| **Product name** | the name | **level** (h1 to h4), **Link to the product** |
| **Product rating** | the stars, the average and the review count | **Hide until it has reviews** |
| **Product price** | the price, and the struck "was" price when there is one | **Hide the "was" price** |
| **Product description** | the description | — |
| **Product buy box** | the options, the quantity, the **Add to cart** button, the wishlist heart and "In stock" | **Hide the wishlist heart**, **Hide "In stock"** |
| **Product story** | the content of the product's [linked story](18-commerce.md#add-a-product) | — |

Every product layout keeps exactly one **Product buy box**: deleting it is refused with the reason,
and Save stays off without it. Move it instead. It has no **Visibility** setting, and a container
holding it cannot be hidden either — by its own **Visibility** or by a style class, including a
later edit of that class, which is refused and names the layout — so no screen size loses it.
Neither the buy box nor a block holding it takes **CSS classes** (the **Advanced** tab): a class
name can be hidden by any stylesheet the site loads, so style them with style classes instead. It
works as the product page's always has — a product with options offers a list to choose from, a
product that needs an add-on says it cannot be bought online, and the button adds to the cart even
where JavaScript is off.

However you design it, the page keeps what it must have: its canonical address in the shop, the
product's structured data for search engines, and the shop's script. A value you set on a block —
its size, colour or spacing — wins over the shop's own styling; remove it and the default returns.

The layout opens on a starter that follows today's product page: the breadcrumb, the gallery beside
the product's details, then the story. Two things differ: the two columns are equal (today's gallery
is a touch wider), and the space between them is 2.5rem (today's is 2rem). The container that holds
the two columns has its top and bottom margins set to none, so it sits where today's grid does;
anything you add beside it — a heading, text, an image — keeps the theme's spacing, as on any page.

Turning Commerce off hides the row and every product page; the layout is kept, and is used again
when Commerce comes back.

On a site that had Commerce on before this release, add the product blocks once:

```bash
php glueful thallo:provision
# with workspaces on, also bring every existing workspace up to date:
php glueful thallo:tenant:sync --all --kind=block_type
```

Until a site — or a workspace — has them, the **Products — product page** row has no **Edit** and
says which commands to run.

## Design the shop home and category pages

With [Commerce](18-commerce.md) switched on, **Site › Layouts** also has **Products — shop home**
and **Products — shop categories**. One layout designs every page of the shop home (`/shop`, then
`/shop?page=2` and on); the other designs every category's page (`/shop/categories/mugs`) at once.
**Edit** opens the shop home on its first page, and the categories on one category — the picker lists
the categories that have products. While the shop has no products, the stage shows one sample card
and says "No published products yet — showing a placeholder"; nothing is written. **Save** says
**Applies to every page of the shop home**, or **Applies to every shop category**.

The layout opens on a starter that is today's page: the heading and the product count, the category
chips, the products — each card today's card, the picture with its category and quick buttons above
the name, and the rating beside the price — and the page navigation. The one visible change is the
product's name in each card: it is now a heading holding the link, so the cards are the page's
sections; it looks as it did.

### The Product list and its card

The **Product list** shows every product on the page. You design one product's **card** — once — and
the list repeats it for each product, newest first. On the stage the first card is the one you edit:
its blocks select, move and take settings, and whatever you drop into it shows in every card. The
other cards show the same design for the page's other products; nothing in them selects, and nothing
drops there.

These go inside the card, and only there — the editor says so, and so does the server:

| Block | Shows in a card | Settings |
|---|---|---|
| **Product tile** | the product's picture, its category, and the quick **Add to cart** and wishlist buttons | **Hide the category**, **Hide the quick buttons** |
| **Product name** | the name, as a heading | **level** (h1 to h4), **Link to the product** |
| **Product rating** | one star, the average and the review count | **Hide until it has reviews** |
| **Product price** | the price, and the struck "was" price when there is one | **Hide the "was" price** |

The quick **Add to cart** works as the shop's grid always has: a product with one variant and no
required add-on is added straight away, even where JavaScript is off; any other product links to its
page to choose. The page's own blocks go outside the card:

| Block | Shows | Settings |
|---|---|---|
| **Product list** | every product on the page, each as its card | **When there are no products** (the text an empty page shows; by default "No products yet.", or "No products in this category yet.") |
| **Shop title** | "Shop", or the category's name, with the number of products beside it | **level** (h1 to h4), **Hide the product count** |
| **Category chips** | "All" and a chip for every category, the page's own marked | **"All" label** |
| **Page navigation** | the newer and older links and "Page X of Y"; nothing on a single page | **Newer label**, **Older label**, **Show "Page X of Y"** |

Your other blocks — headings, text, images, containers — go anywhere, the card included. The card's
starter puts the name and the rating and price in two containers, as today's card has them: to place
something between the name and that row, select the name and click the block in the **Blocks** tab.

Every shop layout keeps exactly one **Product list**: deleting it is refused with the reason ("Every
page of the shop home shows its Product list here…"), and Save stays off without it. It has no
**Visibility** setting, a container holding it cannot be hidden, and neither the Product list nor a
block holding it takes **CSS classes** — use style classes to style them.

Select the Product list and open its **Layout** tab to **Arrange the cards**. Until you do, the cards
are the shop's own grid — as many columns as fit, each at least 15rem wide — and the tab says so:
**Theme default: Grid**, and **Theme default: Adaptive — as many 15rem columns as fit**. Choose a
number of columns, a wrapping row, or other gaps, and the cards follow; reset them and the shop's
grid returns, even over a style class that set columns. What is inside a card stays in the card's own
flow: a block directly in a card has no item controls, and a block in a container in the card is
that container's item, however the cards are arranged.

However you design them, the pages keep their canonical address in the shop, and the wishlist
buttons still find the visitor's saved products. A value you set on a block — its size, colour or
spacing — wins over the shop's own styling; remove it and the default returns.

A layout never changes which pages exist: an unknown category is still not found, and a page number
the shop does not have still answers as it does today.

Turning Commerce off hides both rows and the shop's pages; the layouts are kept, and are used again
when Commerce comes back. On a site that had Commerce on before this release, add the new blocks
once:

```bash
php glueful thallo:provision
# with workspaces on, also bring every existing workspace up to date:
php glueful thallo:tenant:sync --all --kind=block_type
```

## When the content type changes

Renaming a field moves the layouts that show it — an archive's layout included. Deleting a field a
layout shows, or one an archive layout is for, is refused, and the refusal names the layout: change
or remove the layout first. A field that stops filing the type (no longer filterable) takes its
archive pages off the site and keeps their layout: the row stays on **Site › Layouts**, turned off,
with **Remove** — as does a listing's row while its type is not listed. Deleting the content type
removes its layouts.

## Check it worked

Open a published post of the type on the site. It shows the layout: the blocks you placed, with
that post's title, date and content in them. Open **Site › Layouts**: the row reads **Custom
layout**, with when it was saved.
