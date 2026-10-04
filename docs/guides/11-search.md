---
title: "Add search to the site"
slug: search
section: guides
order: 11
summary: "Turn on search, put a Search block in the header, and choose between PostgreSQL and Meilisearch."
---

At the end of this page your site is searchable. Visitors get a Search block, in the header or on
any page, and a `/search` results page; with Commerce on, products are found alongside pages.
`GET /v1/search` answers the same queries for your own code. Nothing new has to be installed — the
index lives in the site's own PostgreSQL database — and Meilisearch is there if you would rather
run one.

You need published content and an admin account with the `content.manage` permission. Indexing
runs in the background, so the site's queue worker and scheduler must be running; see
[the scheduler and the queue](../operations/03-scheduler-and-queues.md).

## Switch search on

Search ships off.

1. Go to **Settings › General**.
2. In **Feature toggles**, turn on **Content search**.
3. Press **Save**.

The same switch is the **Search** row in **Extensions › Capabilities**; both write the capability
`thallo.search`. See [capabilities and packs](../concepts/06-capabilities.md) for the deploy-time
default in `config/thallo.php`.

Until it is on, `/v1/search` is not registered and answers the router's 404, `/search` renders the
theme's 404 page, the `search:*` commands do not exist, and saving content leaves the index alone.

## Indexing runs on its own

Switching search on asks for the index to be built, and the scheduler starts building it within a
minute. From then on the index keeps itself in step: publishing, updating, unpublishing and
deleting an entry each reach the index shortly after the save. A search-engine failure during a
save is recorded and never breaks the save.

Go to **Settings › Search** to watch it. The page lists the engine, then one row per kind of
result: **Pages & posts**, and **Products** when Commerce is on. Each row shows its status —
**Ready**, **Building**, **Pending**, **Out of date** or **Failed** — with the number of
documents, the last success and the last error. A kind whose feature is off says why instead.
The page refreshes itself every five seconds while something is building, and every minute
otherwise.

**Rebuild** on a row asks for that kind to be rebuilt; **Rebuild all** asks for every kind. The
current index keeps answering while the new one is built, and the new one replaces it only once it
is complete. If a request has waited more than ten minutes unclaimed, the page says
background processing hasn't picked it up: start the queue worker and the scheduler, or run the
rebuild yourself.

The same request from a shell:

```bash
$ php glueful search:reindex
Rebuild requested for entries, products.
```

That only records the request. Add `--wait` to run the rebuild in the foreground and see the
result, and `--kind` to rebuild one kind:

```bash
$ php glueful search:reindex --kind=entries --wait
entries: rebuilt
```

The options `--type` and `--locale` are gone: search rebuilds whole kinds. `php glueful
search:status` prints the same table as **Settings › Search**, and exits non-zero when the engine
cannot answer. See [the CLI reference](../reference/01-cli.md#search) for every option.

## What is indexed

One document per published entry per language, for every content type. An entry is indexed when
its content type has not been deleted, the entry is active, and it has a route for that language.

- **Fields.** Every `string`, `text` and `blocks` field of the type.
- **Title.** The field named `title`; failing that the entry's slug; failing that the first
  indexed string field.
- **Body.** The remaining indexed fields, as the words a reader sees. A `rich` text field loses
  its tags; a `plain` text field loses its Markdown syntax; a field whose whole value is one URL
  or one file path is left out, because it is where a page came from rather than what it says.
  A `blocks` field (a page built in the Design view) contributes the text of every block in page
  order, nested blocks included: each block's string and text fields, read by that block type's
  schema, so a setting, a link or a colour is never indexed as a word.

Left out: drafts, scheduled entries that have not gone live yet, and every field that is not
`string`, `text` or `blocks` — `number`, `boolean`, `datetime`, `enum`, `reference`, `asset`,
`json` and `token`.

Visibility is not baked into a document. It is resolved from the live content types on every
request, so turning a type's **Public delivery** off drops it out of anonymous results at once,
with no rebuild.

## Products in search

With Commerce on as well, products are a second kind of result. A product is indexed while the
shop lists it — live, available and active — and matched on its name, its description and its
category and tag names. It is always shown with its current name, price and picture, so a renamed
or withdrawn product never appears as it was. Editing a product, a category or a tag reaches the
index on its own.

Turn Commerce off and products drop out of search at once; **Settings › Search** shows the row as
**Requires Commerce**. See [sell something](18-commerce.md).

## Pick which fields are indexed

By default the builder picks an entry's fields itself. Override that per content type in
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
`php glueful search:status`. Press **Rebuild** on **Pages & posts** after changing any of this.

## Put a Search block in the header

1. Go to **Site › Header & footer** and choose **Header** in the toolbar.
2. In the **Blocks** tab, drag **Search** onto the stage beside the navigation.
3. Click it. In **Content**, set **display** to `icon` for a magnifier that opens a field, or
   leave it at `field`. Set **placeholder** if `Search` is not the word you want.
4. Set **scope** to search everything, or one kind: **Pages & posts** or **Products**.
5. Press **Save**.

**live results** shows up to six suggestions while a visitor types; switch it off for a plain
field. Arrow keys move through the suggestions, Enter opens one, Escape closes the list.

The block works on a page too: it is in the Design view's **Blocks** tab under **Site**. A scope
that cannot be searched — Search is off, or Commerce is off for a products scope — hides the block
on the site, and the stage says why instead. Without JavaScript the field is a plain form and the
icon a plain link, both opening `/search`. See
[the block library](../reference/04-block-library.md#site) for its fields.

## The results page

`/search?q=<terms>` lists results ten to a page, with **More results** to continue. It takes
`scope` (a kind, or empty for every kind) and `locale`, and is what the Search block opens. It
works without JavaScript, and says so plainly when there are no results, when a scope is not
available, while the index is still being built, and when a visitor has searched too often (60
searches a minute per visitor). The page is never cached and asks search engines not to index it.

It renders through the theme template `search/results.twig`. `SEARCH_PAGE_SIZE` and
`SEARCH_PAGE_RATE_LIMIT` change the page size and the limit; see
[the configuration reference](../reference/02-configuration.md#search).

## Query the search API

```text
GET /v1/search?q=<terms>&locale=<code>[&kind=<kind>][&type=<slug>][&limit=<n>][&offset=<n>|&cursor=<c>]
```

`q` and `locale` are required. `kind` picks what is searched: left out it is entries only, `all`
searches every available kind, and `products` searches products. `type` narrows entries to one
content type, and cannot be combined with another `kind`. `limit` defaults to 20 and is clamped to
1–50. Page with `offset`, or with `cursor`: pass the `next` value of one response as the `cursor`
of the following request, and stop when `next` is `null`. A cursor fills each page even when some
matches are hidden from the caller; `offset` gives fixed windows. The route is rate limited to 120
requests a minute per caller.

An API key is optional. Without one the caller sees only content types whose **Public delivery**
is on; with one, the `read:content` and `read:content:{type}` scopes widen that, exactly as they
do for the delivery API. See [the content API](../concepts/07-api.md) for keys and scopes.

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
        "kind": "entries",
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
    "total_approximate": false,
    "limit": 2,
    "offset": 0,
    "next": "eyJ2Ijox…"
  }
}
```

A product hit carries `source_id` in place of `uuid` and `type`, and adds `image` and `price` when it
has them.
`total_approximate` is `true` when `total` is an estimate. The only markup in `snippet` is
`<mark>`; everything else in it is escaped, so it is safe to put straight into the page. `title`
carries no highlighting.

The failures: an empty `q`, a missing or unknown `locale`, an unknown `kind`, `offset` with
`cursor`, or a kind that is not available is 422; a `cursor` that does not belong to the query is
400; an unknown `type` is 404; a `type` the caller may not read is 403; and an engine that cannot
answer, or an index still being built for the first time, is 503.

## How fresh results are

Which items match is eventually consistent. A save reaches the index shortly after it commits;
if that is ever lost, the next save of the same item repairs it, and so does the daily full
rebuild (`search:reconcile --full`, at 03:30; `SEARCH_FULL_RECONCILE=false` switches it off). A
failed rebuild leaves the kind **Out of date** on **Settings › Search**.

What is shown is always current. Every result is checked against the live records before it is
displayed: its title, link, picture, price and snippet come from the item as it is now, and an
item that was unpublished, withdrawn or hidden since it was indexed is left out.

## Choose the engine

`SEARCH_ENGINE` in `.env` picks it.

| `SEARCH_ENGINE` | Engine |
|---|---|
| `auto` (the default) | Meilisearch when `MEILISEARCH_HOST` is set, otherwise PostgreSQL. |
| `postgres` | PostgreSQL full-text search. The site's database must be PostgreSQL. |
| `meilisearch` | Meilisearch. Needs the `glueful/meilisearch` extension enabled and a reachable server. |

PostgreSQL is the engine that needs nothing: the index is held in the site's database, created
by `php glueful thallo:provision`, and Postgres maintains its search vector itself. A query is
cut into words and nothing else — each word must match, by its stem in the page's language or as
a prefix of a word as written, so more words narrow a search and no operator a visitor types
reaches the parser. A language maps to a text-search configuration, so `fr-CA` is indexed as
French; a language Postgres has no configuration for uses `simple`, which matches whole words and
prefixes without stemming. Only the first twelve distinct words of a query are used.

Meilisearch adds typo tolerance, at the cost of a server to run. Enable the extension:

```bash
$ php glueful extensions:enable glueful/meilisearch
```

Then add `MEILISEARCH_HOST` to `.env`, pointing at the server, and `MEILISEARCH_KEY` if the
server needs a key. Neither is in the shipped `.env.example`; write them in yourself.

A choice that cannot be honoured is never swapped for the other engine behind your back. Search
goes unavailable instead: the endpoint answers 503, saving carries on untouched, and
**Settings › Search** and `php glueful search:status` print the reason. Rebuild every kind after
changing engines.

`SEARCH_SNIPPET_LENGTH` sets the snippet length in words (`40`).

## Meilisearch 1.10 and one index per workspace and kind

Searching across kinds needs Meilisearch 1.10 or newer. An older server leaves search
unavailable, and **Settings › Search** names the version it found.

Each kind gets its own Meilisearch index, and on an install with
[workspaces](../concepts/08-workspaces.md) each workspace gets its own as well, so one workspace's
documents never sit in another's index. The names start with `SEARCH_INDEX` (`content`), then
`_v2_`, the workspace, the kind and the build, such as `content_v2_entries_g3`. A rebuild writes a
new index and switches to it when complete; the old one is deleted two minutes later
(`SEARCH_RETIRE_GRACE`), once no query can still be reading it.

The single `content` index of an older install keeps answering until the new indexes are ready,
and is deleted once no workspace needs it. On an install with workspaces it is never read: each
workspace shows search as rebuilding until its own index is ready.

## Behind a CDN

Rendered pages are sent `public, max-age=0, must-revalidate` with an ETag, and the page cache is
keyed by which features are switched on. When a feature turns on or off — Search, or Commerce
under a products scope — the cached pages are purged, and the CDN purge is repeated five minutes
later (`RENDER_AVAILABILITY_EDGE_GRACE`) to catch a page that reached the edge late. A CDN that
honours those headers revalidates every request, so a stale Search block is replaced at once.

A CDN set to override the origin's headers with its own edge cache time falls outside this. There,
removing a stale page depends on the two purges and on that cache time, and until then the CDN may
serve the page as it was before the change. Keep its cache time short, or let it honour the
origin's headers.

## Put a search box in a theme

The Search block is the box most sites need. A theme can also offer its own: templates get
`search_enabled()`, which is true only while the capability is on, and `search_scope_state()`,
which says whether a scope can be searched and why not. Offer a box inside them, so a visitor
never gets one that cannot answer.

The default theme ships a box for documentation sections as `_docs_search.twig`, used by
`entry/docs.twig` and `listing/docs.twig`. Nothing in it is tied to the docs type, so include it
from any entry or listing template — both already carry the `type` and `site` it reads:

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
[make your own theme](13-make-a-theme.md) and
[the template functions](../reference/03-template-functions.md#search).

## Check it worked

Open **Settings › Search**. The engine line reads **Ready**, and **Pages & posts** reads
**Ready** with a document count above zero. Then search the site for a word you know is in a
published page:

```text
https://example.com/search?q=climate
```

A result you can open means search is live. **Building** means the first build is still running;
**Pending** for more than a few minutes means the queue worker or the scheduler is not running.
A 404 at `/search` means the capability is still off.
