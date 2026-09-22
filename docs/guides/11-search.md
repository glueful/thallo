---
title: "Add search to the site"
slug: search
section: guides
order: 11
summary: "Turn on content search, build the index, and choose between PostgreSQL and Meilisearch."
---

At the end of this page your published content is searchable: `GET /v1/search` answers queries
over every entry a caller is allowed to read, and a theme can put a search box in front of it.
Nothing new has to be installed — the index lives in the site's own PostgreSQL database — and
Meilisearch is there if you would rather run one.

You need published content, an admin account with the `content.manage` permission, and a shell on
the server for one command.

## Switch content search on

Search ships off.

1. Go to **Settings › General**.
2. In **Feature toggles**, turn on **Content search**.
3. Press **Save**.

The same switch is the **Search** row in **Extensions › Capabilities**; both write the capability
`thallo.search`. See [capabilities and packs](../concepts/06-capabilities.md) for the deploy-time
default in `config/thallo.php`.

Until it is on, `/v1/search` is not registered and answers the router's 404, `search:reindex` and
`search:status` do not exist, and every publish leaves the index alone.

## Build the index

Switching search on indexes nothing. Backfill it once, from a shell in the project directory:

```bash
$ php glueful search:reindex
```

It reports how many documents it indexed. Two options narrow the run: `--type=<slug>` for one
content type, `--locale=<code>` for one locale. From then on the index keeps itself in step —
publishing, updating, unpublishing and deleting an entry each reindex it. A search-backend
failure during a publish is logged and never breaks the publish, so run `search:reindex` again
if you suspect a gap.

Check what answers:

```bash
$ php glueful search:status
Engine: Postgres full-text search
Backend: reachable, index present.
```

It exits non-zero when the backend is unreachable, and prints a line for every per-type
configuration problem it finds.

## What is indexed

One document per published entry per locale, for every content type. An entry is indexed when
its content type has not been deleted, the entry is active, and it has a route for that locale.

- **Fields.** Every `string` and `text` field of the type.
- **Title.** The field named `title`; failing that the entry's slug; failing that the first
  indexed string field.
- **Body.** The remaining indexed fields, as the words a reader sees. A `rich` text field loses
  its tags; a `plain` text field loses its Markdown syntax; a field whose whole value is one URL
  or one file path is left out, because it is where a page came from rather than what it says.

Left out: drafts, scheduled entries that have not gone live yet, and every field that is not
`string` or `text` — `number`, `boolean`, `datetime`, `enum`, `reference`, `asset`, `json`,
`token`, and `blocks`. A page whose body is blocks built in the Design view therefore has no body
in the index; its title and its own string fields are still found.

Visibility is not baked into a document. It is resolved from the live content types on every
request, so turning a type's **Public delivery** off drops it out of anonymous results at once,
with no reindex.

## Pick which fields are indexed

By default the builder picks the fields itself. Override that per content type in
`config/search.php` — create the file in your project and set only the keys you are changing:

```php
<?php

return [
    'types' => [
        'blog' => [
            'title_field' => 'headline',
            'body_fields' => ['summary', 'body'],
            'exclude_fields' => ['seo_description'],
            'weights' => ['headline' => 5, 'summary' => 2, 'body' => 1],
        ],
    ],
];
```

`weights` order the fields concatenated into the searchable body, highest first. A configured
field that does not exist, or is not a string or text field, is skipped at runtime and named by
`php glueful search:status`. Run `php glueful search:reindex` after changing any of this.

## Query the search API

```text
GET /v1/search?q=<terms>&locale=<code>[&type=<slug>][&limit=<n>][&offset=<n>]
```

`q` and `locale` are required. `type` narrows results to one content type. `limit` defaults to 20
and is clamped to 1–50; `offset` is 0 or more. The route is rate limited to 120 requests a minute
per caller.

An API key is optional. Without one the caller sees only content types whose **Public delivery**
is on; with one, the `read:content` and `read:content:{type}` scopes widen that, exactly as they
do for the delivery API. Visibility is applied inside the query, so `total` and paging stay
correct. See [the content API](../concepts/07-api.md) for keys and scopes.

```bash
$ curl "https://example.com/v1/search?q=climate+crisis&locale=en&limit=2"
```

```json
{
  "success": true,
  "message": "Success",
  "data": {
    "hits": [
      {
        "uuid": "e1f0a72c4b98",
        "type": "blog",
        "locale": "en",
        "href": "/blog/climate",
        "title": "The climate crisis",
        "snippet": "…the <mark>climate</mark> <mark>crisis</mark> began…",
        "score": 0.98
      }
    ],
    "total": 42,
    "limit": 2,
    "offset": 0
  }
}
```

The only markup in `snippet` is `<mark>`; everything else in it is escaped, so it is safe to put
straight into the page. `title` carries no highlighting.

The failures: empty `q` or missing `locale` is 422, an unknown `type` is 404, a `type` the caller
may not read is 403, and a backend that cannot answer is 503.

## Choose the engine

`SEARCH_ENGINE` in `.env` picks it.

| `SEARCH_ENGINE` | Engine |
|---|---|
| `auto` (the default) | Meilisearch when `MEILISEARCH_HOST` is set, otherwise PostgreSQL. |
| `postgres` | PostgreSQL full-text search. The site's database must be PostgreSQL. |
| `meilisearch` | Meilisearch. Needs the `glueful/meilisearch` extension enabled and a reachable server. |

PostgreSQL is the engine that needs nothing: the index is the `search_documents` table, created
by `php glueful thallo:provision`, and Postgres maintains its search vector itself. A query is
cut into words and nothing else — each word must match, by its stem in the page's language or as
a prefix of a word as written, so more words narrow a search and no operator a visitor types
reaches the parser. A locale maps to a text-search configuration by its language, so `fr-CA` is
indexed as French; a language Postgres has no configuration for uses `simple`, which matches
whole words and prefixes without stemming. Only the first twelve distinct words of a query are
used.

Meilisearch adds typo tolerance, at the cost of a server to run. Enable the extension:

```bash
$ php glueful extensions:enable glueful/meilisearch
```

Then add `MEILISEARCH_HOST` to `.env`, pointing at the server, and `MEILISEARCH_KEY` if the
server needs a key. Neither is in the shipped `.env.example`; write them in yourself.

A choice that cannot be honoured is never swapped for the other engine behind your back. Search
goes unavailable instead: the endpoint answers 503, publishing carries on untouched, and
`php glueful search:status` prints the reason. Run `php glueful search:reindex` after changing
engines.

Two more settings, in `.env` or `config/search.php`: `SEARCH_INDEX` names the Meilisearch index
(`content`), and `SEARCH_SNIPPET_LENGTH` sets the snippet crop length in words (`40`).

## Put a search box in a theme

Templates get `search_enabled()`, which is true only while the capability is on. Offer a box
inside it, so a visitor never gets one that cannot answer.

The default theme ships the box as `_docs_search.twig`, used by `entry/docs.twig` and
`listing/docs.twig`. Nothing in it is tied to the docs type, so include it from any entry or
listing template — both already carry the `type` and `site` it reads:

```twig
{% include '_docs_search.twig' %}
```

A theme of your own that omits the file falls back to the default theme's copy. To write your
own instead, reuse the behaviour that ships with it: give the form `data-docs-search`,
`data-type` (a content type's slug, or empty for every type) and `data-locale`, mark it `hidden`,
put an `input`, an element with `role="listbox"` and an element with `data-docs-search-status`
inside it, and call `{{ block_script('docs-search') }}`. The script unhides the form, queries
after two characters, and lists eight hits: arrows move, Enter opens, Escape closes, `/` focuses.
A visitor without JavaScript sees no box rather than a dead one. See
[make your own theme](13-make-a-theme.md).

For a documentation section the box is already wired up — see
[a documentation section on your site](../documentation-sites.md).

## Check it worked

Run `php glueful search:status` and read the engine line. Then query the endpoint for a word you
know is in a published page:

```bash
$ curl "https://example.com/v1/search?q=climate&locale=en"
```

A `total` above zero, with an `href` you can open, means the index is live. A 404 means the
capability is still off; a 503 means the engine cannot answer, and `search:status` says why.
