---
title: "The content API"
slug: api
section: concepts
order: 7
summary: "Read your content as JSON: the delivery API, API keys, and the reference every install serves."
---

Every entry Thallo publishes is also a JSON resource. The same pinned version the theme renders
at `/{type}/{slug}` is served at `/v1/content/{type}/{slug}`, with no export step and no second
copy. This page describes that API: the routes it has, how it lists and filters, how references
come back, who is allowed to read what, and where the reference for your own install lives.

## Two APIs, one content spine

Thallo serves two JSON APIs, and they are not variants of each other.

The **delivery API** is at `/v1/content`. It is read-only and it serves published content only.
Every one of its queries joins the publication spine, so a draft, an unpublished locale or a
deleted entry cannot appear in a response whatever the request asks for.

The **admin API** is at `/v1/admin`. It is what the admin itself calls: content types, entries,
drafts, publishing, media, users, settings, API keys. Every route needs an authenticated
principal and each one also enforces a permission — `content.view`, `content.publish`,
`users.edit`, `styles.manage` and the rest. See
[users, roles and permissions](../guides/15-users-and-roles.md).

There is a third, narrower door: `GET /v1/preview/{token}` returns a draft. It carries no
authentication because the signed, short-lived token in the path is the permission. See
[drafts, preview and publishing](05-publishing.md).

## The delivery routes

| Route | Returns |
|---|---|
| `GET /v1/content/{type}` | A page of published entries of the content type. |
| `GET /v1/content/{type}/{slugOrUuid}` | One published entry, by route slug or by its 12-character uuid. |
| `GET /v1/content/{type}/facets` | Term counts for filterable reference fields. |
| `GET /v1/content/{type}/archive/{field}/{term}` | A term, plus the published entries that point at it. |

`facets` is a reserved word on this surface: an entry whose slug is literally `facets` is
shadowed by the facets route. An unknown content type is a 404. Each route is rate limited at
120 requests a minute, and answers 429 above it.

## What one entry looks like

Responses use the platform envelope — `success`, `message`, `data` — and a single entry is this
object:

| Key | Value |
|---|---|
| `uuid` | The entry's uuid, stable across locales and versions. |
| `locale` | The locale this body was read in. |
| `version` | The published version number. |
| `published_at` | When that version was pinned, as ISO-8601 (`2026-02-11T09:30:00+00:00`). |
| `fields` | The entry's fields, named as the content type names them. |
| `seo` | On the single-entry route only: `canonical`, `alternates` and `x_default`, each an object of `locale`, `href`, `content_type` and `slug`. |

`fields` holds only what the content type declares. Values written under a key the schema does
not know are dropped when the entry is saved, and keys beginning with `_` — the editor's own
presentation state — are stripped before the response is built.

Two things can come back instead of an entry. If the entry's slug has changed, or a redirect you
wrote points away from the requested slug, `data` holds a `redirect` object carrying `to`,
`status`, `external`, `target_state` and the redirect's own `uuid`. If the redirect's target is
no longer published, the response is a 404 instead.

## Listing, filtering, sorting and paging

A list request pages by cursor by default. `data` holds `items` and `next_cursor`; pass that
cursor back as `?cursor=` for the next page, and stop when it is `null`.

Supplying `?page` or `?perPage` switches the response to the offset envelope instead: the items
move to a top-level `data` array beside `current_page`, `per_page`, `total`, `total_pages`,
`has_next_page` and `has_previous_page`. Page size comes from **Settings › General › Content
delivery**, where **Default items per page** and **Max items per page** set the default and the
hard cap a client can ask for.

Filters use bracket syntax, one operator per clause, and multiple clauses are combined with AND:

```text
?filter[status][eq]=live&filter[price][lt]=50
```

Only a field marked **Filterable** in the content type can be filtered or sorted on; the field's
filter type fixes which operators it takes.

| Filter type | Operators |
|---|---|
| `number`, `datetime` | `gt`, `gte`, `lt`, `lte` |
| `string`, `boolean`, `enum` | `eq`, `neq`, `in` |
| A filterable `reference` or `asset` field | `eq`, `in` — membership, up to 50 values |

`in` takes a comma-separated list. A reference value may be the target's uuid or the value of its
**Slug filter field**. Sorting is `?sort=field:asc` or `?sort=field:desc`, again on a filterable
field only, and the default is newest first by `published_at`. A field that is not filterable, an
operator its type does not take, or a direction that is not `asc` or `desc`, all answer 422 with
the reason in the body.

## Facets and archives

`/{type}/facets` takes `?fields=` with a comma-separated list of filterable reference fields and
returns, for each one, a list of `uuid`, `slug` and `count` — the number of published entries
pointing at that term. It returns at most 100 terms per field, or `?limit=` up to 500.

`/{type}/archive/{field}/{term}` is the list of entries that point at one term, with the term
itself in the response under `term`. It takes the same filter, sort and paging parameters as the
plain list route, so an archive can be paged and narrowed like any other listing. Counts and
archive membership are read from the same projection, so the number a facet reports is the number
of entries the archive serves.

## Choosing fields and expanding references

A `reference` field is stored as the target entry's uuid, and the delivery API resolves it as it
reads: the response carries the target's published version in place of the uuid, as an object of
`entry_uuid`, `version_uuid`, `version` and `fields`. A target that is unpublished in the
requested locale resolves to `null` — never to its draft. Expansion goes two levels deep, covers
references inside a `blocks` field, and batch-loads, so a list of fifty entries costs one extra
query per level rather than fifty. `asset` fields are never expanded: they stay as media uuids at
every level.

`?fields=` takes a comma-separated list of field names and narrows the `fields` object to them.
It does two things at once: it projects the response, and it decides which references are
expanded — a reference field you did not name is left as a raw uuid. `?expand=` alone expands the
reference fields it names and keeps every other field; combined with `?fields=`, it expands within
the fields you asked for. The envelope keys are not
projectable; `uuid`, `locale`, `version` and `published_at` are always there.

## Reading a locale

`?locale=` chooses the locale to read; without it, the API reads the install's default locale.
The single-entry route walks that locale's fallback chain before giving up, so a request for a
locale an entry was never translated into can still return the fallback. List, facets and archive
routes do not fall back: they read exactly the locale asked for. See
[publish in more than one language](../guides/09-languages.md).

## API keys and their scopes

Anonymous reads are allowed for a content type whose **Public delivery** switch is on. Everything
else needs a key.

Make one under **Developers › API Keys**, with **New API key**. A key has a **Name**, an optional
list of **Scopes**, optional **Allowed IPs** (addresses or CIDR ranges) and an optional expiry
date. On an install with [workspaces](08-workspaces.md) on, it can also be bound to one
workspace. The full key is shown once, when it is created; afterwards the admin shows only its
prefix. Store it when you see it.

Send it as an `X-API-Key` header, or as `Authorization: ApiKey <key>`. Two scopes matter to the
delivery API:

| Scope | Reads |
|---|---|
| `read:content` | Every content type. |
| `read:content:{type}` | That one content type. |

Scopes are matched as wildcards, so `read:content:*` and `read:*` both read every type.

A key created with no scopes at all has full access. A key that does not satisfy either scope for
the type it asks for gets 403 — unless the type has **Public delivery** on, which anyone may
read. A key that is wrong, revoked or expired is 401, and it never falls through to the public
path: sending a bad key to a public type fails.

The detail pane holds the rest of a key's life. **Edit** beside **Scopes** changes what the key
may read, at once and without a new key; emptying the list gives it full access, and the pane says
so before you save. **Rotate key** issues a fresh key and keeps the old one working for a grace
period you choose, so a deploy can overlap. **Revoke key** stops it at once.

The same four things can be done from a terminal, which suits a deploy script. A key belongs to
a user, named by uuid:

```bash
$ php glueful apikey:create --user=<user-uuid> --name="Site build" --scopes=read:content
$ php glueful apikey:list --user=<user-uuid>
$ php glueful apikey:rotate <key-uuid> --grace=24   # hours the old key keeps working
$ php glueful apikey:revoke <key-uuid>
```

## What the delivery API never returns

- Drafts, scheduled-but-unpublished entries, and any locale that has never been published.
- Entries of a content type whose **Public delivery** is off, to a caller without the scope for
  it. This holds through references as well: a reference to a type the caller may not read
  resolves to `null` rather than leaking its fields. Facets and archives go further and answer
  404 when the referenced type is not visible, because a term list is itself a disclosure.
- Entries whose type or entry has been deleted in the admin.
- Fields the schema does not declare, and `_`-prefixed editor state.

## Caching and conditional requests

Every delivery response carries an `ETag`. Send it back as `If-None-Match` and an unchanged entry
answers 304 with no body. The tag folds in the published version, the requested shape and the
caller's scopes, so a changed field, a different `?fields=` or a differently scoped key all
produce a different tag.

`Cache-Control` carries the **Cache TTL (seconds)** from **Settings › General**, or the content
type's own **Cache TTL** where it sets one. A response read anonymously is `public`; one that
depended on a key's scopes is `private` and varies on `X-API-Key`, so a shared cache cannot serve
a scoped body to an anonymous reader. A `Cache-Tag` header lists `thallo:entry:{uuid}` for every
entry in the response and `thallo:type:{slug}` for the type, which is what a CDN purges on
publish.

## The reference every install serves

Thallo does not ship one API document, because no two installs serve the same API: enabled
[capabilities](06-capabilities.md) and your own routes change it. The reference is generated from
the live routes of the install it runs on, at `/api-docs`, with the OpenAPI document beside it at
`/api-docs/openapi.json`. **Developers › API Reference** in the admin opens it.

`php glueful thallo:provision` writes both files, into `docs/openapi.json` and `docs/index.html`
in the project, and refreshes them on every run. Regenerate them after adding routes of your own:

```bash
$ php glueful generate:openapi -f --ui
```

Set `API_DOCS_ENABLED=false` to stop serving the reference.

## One complete request

Fetching a post, with three of its fields. The key is read from a shell variable rather than
written into the command:

```bash
$ curl -H "X-API-Key: $THALLO_API_KEY" \
    "https://example.com/v1/content/post/a-second-look?fields=title,excerpt,categories"
```

The body:

```json
{
  "success": true,
  "message": "Content retrieved.",
  "data": {
    "uuid": "0f3c9d2a1b7e",
    "locale": "en",
    "version": 4,
    "published_at": "2026-02-11T09:30:00+00:00",
    "fields": {
      "title": "A second look at type",
      "excerpt": "Why the body face changed.",
      "categories": [
        {
          "entry_uuid": "7a1d4e88c260",
          "version_uuid": "b2c5f0913ad7",
          "version": 2,
          "fields": { "title": "Design", "slug": "design" }
        }
      ]
    },
    "seo": {
      "canonical": {
        "locale": "en",
        "href": "/post/a-second-look",
        "content_type": "post",
        "slug": "a-second-look"
      },
      "alternates": [
        {
          "locale": "en",
          "href": "/post/a-second-look",
          "content_type": "post",
          "slug": "a-second-look"
        }
      ],
      "x_default": {
        "locale": "en",
        "href": "/post/a-second-look",
        "content_type": "post",
        "slug": "a-second-look"
      }
    }
  }
}
```

Next: [workspaces](08-workspaces.md), if one install has to serve several sites, or
[notify other systems with webhooks](../guides/16-webhooks.md) to push changes out instead of
polling for them.
