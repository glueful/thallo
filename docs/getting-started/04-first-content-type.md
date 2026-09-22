---
title: "Model your own content"
slug: first-content-type
section: getting-started
order: 4
summary: "Make a content type, add entries, and list them on the site."
---

A fresh install ships three content types: **Pages**, **Posts** and **Categories**. This page
adds a fourth of your own — Events — puts two published entries in it, and serves them at
`/events`, at `/events/<slug>` and from the content API. You need an install with the admin open;
[Install Thallo](02-install.md) gets you there.

## Create the content type

Go to **Settings › Content Types** and press **New content type**. The **Details** rail asks for:

- **Name** — type `Events`. The **Slug** fills itself in as `events` as you type. The slug is the
  first segment of every URL this type serves, so leave it alone.
- **Description** — a note for whoever works in the admin.
- **Cache TTL (seconds)** — leave it empty.
- **Public delivery** — turn it on. With it off, the site and the API answer 404 for every entry
  of the type unless the caller holds an API key.
- **Mount at root** — leave it off. On, entries serve at `/<slug>` instead of `/events/<slug>`;
  the starter **Pages** type is the one that has it on.

## Add the fields

The **Fields** card starts empty. Press **Add field** four times and fill the rows in:

| Field name | Type | Then |
|---|---|---|
| `title` | `string` | turn **Required** on |
| `excerpt` | `text` | leave **Editor** on Plain textarea |
| `starts_at` | `datetime` | — |
| `body` | `blocks` | — |

A field name starts with a lower-case letter and carries only lower-case letters, digits and
underscores. It is also the field's label in the entry form, so `starts_at` is what an author
sees. Two fields of one type cannot share a name.

Every field has three switches. **Required** refuses a save with the field missing, and a publish
with it empty. **Localized** gives the field a separate value per locale instead of one shared
across all of them. **Filterable** lets the API filter and sort on the field; a `blocks` field
never can.

These are the types the **Type** list offers:

| Type | What it holds |
|---|---|
| `string` | One line of text. |
| `text` | Longer text, edited as a plain textarea or as rich text. |
| `number` | A number. |
| `boolean` | True or false, edited as a switch. |
| `datetime` | A moment, stored as ISO-8601 UTC. |
| `enum` | One value out of the **Allowed values** you list. |
| `reference` | One entry of another content type, or an ordered list of them. |
| `asset` | One file from the media library, or an ordered list of them. |
| `json` | An object or an array, edited as JSON. |
| `blocks` | A page body: an ordered list of blocks. |

Two more types serve design settings. `token` holds one named value from the style vocabulary;
choose which set in **Token domain** (`color`, `spacing` and so on). `box` holds four numeric
sides, such as a padding. Neither can be filterable.

The eye button above the fields, **Preview the entry form**, shows the form authors will get.
Nothing typed into it is kept.

Press **Create content type**. **Events** now appears in the sidebar under **Content**.

## Add two entries

Open **Content › Events** and press **New Events**. Thallo creates an empty draft and opens the
entry editor on it.

Fill in `title`, `excerpt` and `starts_at`. Leave `body` for now: it is the page body, and the
**Design** button lays it out visually — that is [Build your first page](03-first-page.md).

The **Publishing** panel on the right holds the entry's **Slug**, suggested from the title. It is
the second half of the URL, `/events/<slug>`; edit it and press **Save route**, or leave the
suggestion as it is.

Press **Publish**. Publishing saves the draft and the slug first, so one press is enough; **Save
draft**, the disk button beside it, keeps the entry as a draft instead. An empty required field
stops the publish and marks the field `is required`.

Go back with the arrow and add a second entry the same way. The list shows both, each badged
**published**.

## Turn the listing on

An entry page works the moment the entry is published. A type's index page does not: it is off
until you name the type. Go to **Settings › General**, find **Public listings**, add **Events** to
**Listing types**, and press **Save**.

`/events` now serves the type's published entries, ten to a page, with page two at
`/events/page/2`. Without the setting, `/events` is a 404 while `/events/<slug>` still works.

The same setting turns on term archives at `/events/<field>/<term>`, for any `reference` field
that is **Filterable**. The starter **Posts** type has one: list **Posts** too and
`/post/categories/<slug>` serves everything filed under that category.

## See it on the site

Open `http://localhost:8000/events`. The default theme's listing prints the type's slug as the
heading and one row per entry: the title as a link, the date it was published, the excerpt, and a
thumbnail when the entry has a `cover` asset. Follow a row through to `/events/<slug>` and the
entry page prints the title and the body.

`starts_at` is on neither page. A theme decides what a page shows, and the default theme's
`entry.twig` renders the title and the `body` field, while its listing rows read `title`,
`excerpt`, `cover` and the publication date. To print the rest, a theme adds
`templates/entry/events.twig` and `templates/listing/events.twig`: per-type templates named after
the type's slug, which the renderer prefers over the generic pair. See
[themes](../concepts/04-themes.md) and [make your own theme](../guides/13-make-a-theme.md).

Give a body field the `blocks` type, as the starter types do. The default theme renders a `blocks`
body through the block templates; a body that is a `text` field is escaped, so rich text stored in
one shows up as its own markup.

## The same entries in the API

Publishing an entry publishes it to the content API as well. **Public delivery** is what makes
these two calls work without a key:

```bash
$ curl http://localhost:8000/v1/content/events
$ curl http://localhost:8000/v1/content/events/my-first-event
```

The list returns `items` — each one a `uuid`, `locale`, `version`, `published_at` and the entry's
`fields` — and a `next_cursor` for the page after it. The single-entry call takes a slug or a
UUID, and answers 404 for anything not published. With **Public delivery** off, both need an API
key scoped `read:content` or `read:content:events`. See
[the content API](../concepts/07-api.md).

## Next

[Content types, entries and fields](../concepts/01-content-model.md): what changing a schema does
to entries that already exist, how references and locales work, and where version history goes.
