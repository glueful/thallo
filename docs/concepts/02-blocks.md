---
title: "Blocks and block types"
slug: blocks
section: concepts
order: 2
summary: "What a block is, how a page's body is stored, and how a block becomes HTML."
---

A page in Thallo is not a document of HTML. It is an ordered list of blocks, each one a small
record of data. That shape is what lets a theme change a site's look without touching a word of
its content, and what lets the content API hand a page's structure to something that is not a
browser.

## A block is data, not markup

One block is four things:

- `id` — unique across the whole entry, at every nesting level. Thallo generates it when it is
  missing.
- `type` — the slug of a block type, such as `hero` or `container`.
- `data` — the block's own field values, validated against that block type's schema.
- `settings` — the choices made in the Style tab and the Layout tab, stored as names from the
  theme's vocabulary rather than as CSS. See [the Design view](03-design-view.md).

Nothing in that record is HTML: a block stores what it says, not how it looks.

## A page's body is a blocks field

Blocks live in a field whose type is `blocks`, exactly as a heading lives in a `string` field.
The starter **Pages** and **Posts** content types both have one, named `body`; you can add a
`blocks` field to any content type of your own. See
[content types, entries and fields](01-content-model.md).

A `blocks` field can carry **Allowed block types**. Leave it empty and the Blocks tab offers
every active block type; list some and it offers only those.

## Blocks inside blocks

A block type can itself declare a `blocks` field, so a block holds other blocks. Sixteen of the
starters do. The Container is the one whose job is to arrange its children, as flex or grid;
Card, Accordion, Tabs, Carousel, Hero and Footer are among the rest.

Nesting stops at **five levels**. The entry's own `blocks` field is level one, its children are
level two, and so on: container, container, card, container, heading. A deeper list is refused
when the entry is saved, and renders nothing if one ever reaches a template. The cap is the same
number in the validator, the renderer and the admin.

## A block type defines a block's fields

A block type is a reusable mini-schema. Its fields are declared the same way a content type's
are, with two rules of their own: a block field is never localised — localisation belongs to the
outer `blocks` field — and never filterable.

Thallo ships **44 block types**, grouped by the category each one declares:

| Category | Block types |
|---|---|
| Layout | 6 |
| Content | 21 |
| Media | 8 |
| Items | 7 |
| Advanced | 2 |

The Blocks tab and **Settings › Block Types** both group by category in that order. Every type is
listed in [the block library](../reference/04-block-library.md). A
[capability](06-capabilities.md) can contribute block types of its own: turning one on makes its
blocks appear.

A block type's slug is fixed at creation, because it names the template. Fields can be added at
any time, but removing or renaming one needs a declared migration: the schema changes at once,
a background backfill rewrites every current draft and publication, and entries holding that
block cannot be saved or published until it finishes. A failed backfill is resumed with
`php glueful thallo:blocks:migration:backfill <uuid>`.

The starter definitions live in Thallo's code, so an upgrade can add fields to them.
`php glueful thallo:provision` syncs those additions onto existing rows once the site is
installed. To see what a sync would change without writing anything:

```bash
$ php glueful thallo:blocks:sync --dry-run
```

`php glueful thallo:blocks:seed` creates any starter that is missing and skips every slug that
already exists. To make a block type of your own, see
[make your own block type](../guides/14-make-a-block-type.md).

## One template per block type

A block type's slug is the name of its Twig template: `templates/blocks/<slug>.twig`. The
template receives the block's fields as `data` and emits the markup.

A [theme](04-themes.md) overrides a block by shipping its own copy at
`themes/<name>/templates/blocks/<slug>.twig`. Fallback is per file, so a theme only ships the
templates it changes; every other block keeps rendering through the default theme's copy. You
never edit anything under `vendor/`.

## Switching a block type off

Removing a block type is deactivation. Open **Settings › Block Types** and use the switch on the
type's card, or open the type and press **Deactivate**. The type disappears from the Blocks tab's
picker, and pages that already use it keep validating and keep rendering. Activating it again
puts it back in the picker. The HTML block ships deactivated for this reason: raw output is an
explicit choice.

Each type's page has a **Usage & lifecycle** panel showing how many current drafts and
publications hold that block. **Delete block type** is offered only when that count is zero and
no migration is running; it is permanent, and versions that referenced the type can no longer be
restored. Deactivating is the reversible alternative.

## Why structure instead of markup

Two things follow from storing a page as data.

The [content API](07-api.md) returns the body as that same list of blocks, so a mobile app or a
static build reads the page's structure directly instead of parsing HTML out of a string.

And a change of look is a change of templates. Restyling a block, or switching the site to a
different theme, rewrites no content: the blocks are untouched and the new templates render them.

Next: [the Design view](03-design-view.md), where blocks are assembled, and
[the block library](../reference/04-block-library.md), which lists every block that ships.
