---
title: "Content types, entries and fields"
slug: content-model
section: concepts
order: 1
summary: "How content is modelled: types, fields, entries, references, locales and versions."
---

Everything a Thallo site holds is an entry, and every entry follows a content type. The content
type is the schema: the fields an entry has, what each stores, and which of them the site and the
API may use. That one relationship explains the URLs a page gets, what translating means, and why
some schema changes are harder than others.

## A content type is a schema

You make one under **Settings › Content Types**. It carries a **Name**, a slug derived from the
name, an optional **Description**, an optional **Cache TTL (seconds)**, two switches and a list of
fields. The slug is fixed at creation; everything else can change.

**Public delivery** decides whether the outside world sees the type at all. With it off, entries
render nowhere and the [content API](07-api.md) never returns them, however published they are.
**Mount at root** serves entries at `/{slug}` instead of `/{type}/{slug}`.

A new install ships three types, each with its own section under **Content**:

| Type | Slug | Fields |
|---|---|---|
| Pages | `pages` | `title`, `body` — mounted at root |
| Posts | `post` | `title`, `excerpt`, `cover`, `body`, `categories` |
| Categories | `category` | `title`, `slug` |

**Danger zone › Delete** hides a type and its entries from listing and delivery. The rows stay in
storage.

## The field types

A field has a name matching `[a-z][a-z0-9_]*`, a type, and the options its type allows.

| Type | What it stores |
|---|---|
| `string` | One line of text. |
| `text` | A longer passage. **Editor** picks **Plain textarea** or **Rich text**; rich HTML is sanitised as it is saved. |
| `number` | An integer or a decimal. |
| `boolean` | True or false. |
| `datetime` | A moment, normalised to ISO 8601 UTC on save. |
| `enum` | One of the **Allowed values**. |
| `reference` | The uuid of another entry, or an ordered list of them. |
| `asset` | The uuid of a file on the media disk, or a list of them. |
| `json` | Any object or array, unvalidated. |
| `blocks` | An ordered list of [blocks](02-blocks.md). |
| `token` | One named value from a style vocabulary. |
| `box` | Four numeric sides (top, right, bottom, left), in pixels. |

Three switches sit on every field. **Required** holds on an entry's own fields at every save; a
field that is present but empty is rejected only at publish. **Localized** is explained below.
**Filterable** opens the field to the content API's filters and queues a job that builds a
Postgres index for it; a scalar field must also declare which of `string`, `number`, `boolean`,
`datetime` or `enum` it filters as.

Values for keys the schema does not declare are dropped when an entry is saved.

## References between entries

A `reference` field names its target under **References**. **Multiple** turns it into an ordered
list, deduplicated, capped by **Max items**. **Slug filter field** is the field on the target
whose value stands in for the target in an API filter or a URL; it defaults to `slug`.

Saving a draft accepts a reference to an entry that no longer exists, so incomplete work is never
blocked. Publishing does not: a dangling reference fails the publish, and deleting the entry that
was pointed at is enough to make one.

The starter **Posts** type shows what references buy. Its `categories` field is a multiple,
filterable reference to **Categories**, which is what makes an archive URL such as
`/post/categories/news` resolve.

## An entry's slug and its URL

An entry's slug is not one of its fields. It lives in the **Slug** box of the Publishing panel,
seeded from the title until you type in it, and it is held per locale: one entry can be
`/post/hello` in English and `/fr/post/bonjour` in French. Where a site has more than one
language, the **Routes by locale** dialog on the entry's toolbar shows them together.

From a slug, the site builds:

| URL | Page |
|---|---|
| `/{type}/{slug}` | The entry. |
| `/{slug}` | The entry, when its type is mounted at root. |
| `/{type}` | The type's listing, first page. |
| `/{type}/page/{n}` | The listing, page n. |
| `/{type}/{field}/{term}` | An archive: entries whose `{field}` points at `{term}`. |
| `/{type}/terms/{field}` | The index of terms for `{field}`. |
| `/{locale}/…` | Any of the above, in a non-default locale. |

Only the types whose **Listing page** is on (the switch on the content type, or **Settings ›
General › Listing types**) get the listing and archive URLs. Everything else serves entry pages alone.

Change an entry's slug and Thallo writes a 301 from the old one to the entry, so the old URL keeps
working. Claiming a slug clears any redirect that pointed away from it. These automatic redirects
sit among the ones you write yourself under **Settings › Redirects**; see
[titles, descriptions, sitemaps and redirects](../guides/10-seo.md).

## One entry, many locales

An entry is one thing with a working copy per locale. Its draft, its slug, its published version
and its version history are all held per locale, and each is published on its own.

**Localized** decides what a new locale starts with. Starting one from an existing locale copies
the fields that are not localised — a cover image, a price, a category — and leaves the localised
ones empty for a translator. The copy happens once; the two drafts are independent afterwards.

A request for a locale falls back through that locale's fallback chain before giving up. See
[publish in more than one language](../guides/09-languages.md).

## Draft, published version and history

Saving writes the draft and nothing else; the live site does not move. Drafts are saved under an
optimistic lock, so two people editing the same entry and locale do not overwrite each other. The
second save is refused, and the admin says the draft changed elsewhere and asks you to reload.

Publishing takes a snapshot. The draft is validated strictly, appended to the entry's history as
a numbered, immutable version, and that version is pinned as the published one. The draft stays
editable; the site serves the pinned version until the next publish. Restoring an older version
re-pins it. [Drafts, preview and publishing](05-publishing.md) follows the whole life of an entry.

History is unlimited until you set a retention policy, in `VERSION_KEEP` or
`VERSION_MAX_AGE_DAYS`, or as `--keep` and `--max-age-days` on the command that applies it. See
what a policy would remove first:

```bash
$ php glueful thallo:versions:prune --dry-run
```

The pinned version always survives. Deletion is permanent, and nothing schedules it: history is
pruned only when you run the command.

## Changing a type's schema

Adding a field is free. Existing entries simply have no value for it, and the type's schema
version goes up by one.

Removing or renaming one is a migration, because content written under the old name has to move.
Thallo takes a list of rename and delete operations, flips the schema to its new shape at once,
and queues a backfill that rewrites every draft and every published version. Until the backfill
reaches a row, reads project that row forward, so nothing disappears in the meantime. An editor
holding a draft from before the flip is refused on their next save and has to reload. One
migration per content type runs at a time.

A backfill that fails part-way is resumed by uuid:

```bash
$ php glueful thallo:schema:backfill <migration-uuid>
```

The admin does not run migrations yet; renames and deletes go through the admin API. The field
editor does show **Remove field** and lets you change a field's type, but saving either is
refused with a message that points at the migration route. A field cannot be retyped at all:
add a new field and leave the old one.

## Collections hold data, not pages

A collection is the other way to hold structured data. Where a content type is a schema over
entries — drafts, versions, locales, routes, a page on the site — a collection is a real database
table with a generated CRUD API, no publishing and no URLs. Reach for one when you need rows that
are queried rather than read. Collections are a [capability](06-capabilities.md), under
**Collections** in the sidebar.

Next: [blocks and block types](02-blocks.md), which is what a `blocks` field holds, or
[model your own content](../getting-started/04-first-content-type.md) to build a type of your own.
