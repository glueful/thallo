# A documentation section on your site

Thallo can publish a folder of Markdown as a documentation section: a sidebar of sections, the
page, an "On this page" outline, previous and next links, and an "Edit this page" link. The
Markdown stays in git as the source of truth; a deploy imports it. thallo.dev's own docs are
published this way, and nothing about it is specific to Thallo — a product, an agency handbook
or an open-source project can use it as it stands.

## In three commands

```bash
# 1. Once: make the content type a docs section needs, and let the site list it.
php glueful thallo:docs:setup

# 2. See what an import would do. Nothing is written.
php glueful thallo:import:markdown docs --type=docs --exclude=internal --dry-run

# 3. Import and publish. Run this on every deploy.
php glueful thallo:import:markdown docs --type=docs --exclude=internal --publish \
    --edit-base=https://github.com/you/your-repo/edit/main/docs
```

Your pages are at `/docs/{page}` and `/docs` is their index. The importers capability has to be
on (Settings › Capabilities).

## The content type

`thallo:docs:setup` makes an ordinary content type; you can open it under Settings › Content
types like any other.

| Field | What it holds |
|---|---|
| `title` | The page's title. |
| `summary` | One line, shown under the title and on the index. |
| `section` | Which sidebar group the page is in. The list of sections, in sidebar order, is this field's options. |
| `order` | The page's place within its section. |
| `body` | The Markdown, **as written**. A plain text field: the theme renders it. |
| `source_path` | The file the page came from. How a later import finds the page again. |
| `edit_url` | Where a reader can propose a change. |

The type's **slug is the URL**. `--type=handbook` gives you `/handbook`. Choose your own
sections with `--sections=start,guides,reference`. Running the command again changes nothing,
and it never rewrites a type that already exists.

## How a file becomes a page

Front matter is optional. A folder written for GitHub imports as it stands.

```markdown
---
title: Installing
slug: install
section: getting-started
order: 1
summary: Get it running in a few minutes.
---
```

| | From the front matter | Otherwise |
|---|---|---|
| URL | `slug` | The file's name, without an `NN-` prefix. A `README.md` or `index.md` is its folder. |
| Title | `title` | The first `# heading`, which is then taken out of the body. Failing that, the name. |
| Section | `section` | The top folder, when it is one of the type's sections. |
| Order | `order` | The `NN-` prefix of the file's name. |
| Summary | `summary` or `description` | None. |

`draft: true` or `publish: false` keeps a file out of the import. `--exclude=<folder>` keeps a
folder out, and can be repeated. Hidden files and folders are never read.

**Links between files become links between pages.** `[Upgrading](../upgrading.md#steps)` is
rewritten to `/docs/upgrading#steps`. A link to a file that is not in the import is left as
written and reported. Code, fenced or indented, is never rewritten.

## Run it again and again

The import is built for a deploy script.

- A file lands on the page it made last time, found by its URL or, failing that, by its
  `source_path`. So a page whose `slug` changes keeps its entry, and **its old URL redirects**.
- Only pages that changed are written. An unchanged page is left alone, so deploys do not pile
  up versions.
- **Nothing is ever deleted.** A page whose file is gone is reported as `gone`; remove it in the
  admin when you mean to.
- One file's failure is that file's. The rest still import, and the command exits non-zero so
  a deploy stops.
- If your site has the review workflow on, a plain publish is gated. The page is saved as a
  draft and the report says so. Pass `--actor=<user uuid>` for a user allowed to bypass review.

## What Markdown can do

GitHub-flavoured Markdown: tables, task lists, strikethrough, autolinks, fenced code. Every
heading gets a stable id and a copyable link, and the `h2`/`h3` outline becomes "On this page".
A code fence is rendered by the theme's own code block, with its language label and copy
button; a `bash` fence draws the `$` prompt without it being copied.

**Raw HTML in a source file is stripped**, and `javascript:` and `data:` links are refused. A
docs page can never carry markup or script of its own, whoever wrote the file.

Not yet: images that travel with the import (link to an uploaded image or an absolute URL for
now), and syntax colouring inside code listings.

## Search

Turn on **Settings › General › Content search** and run `php glueful search:reindex` once. The
sidebar and the index then carry a search box: results as you type, scoped to your docs, walked
with the arrow keys, opened with Enter, focused with `/`. It uses the site's own PostgreSQL
database — there is nothing to install — or Meilisearch if you have configured one
(`packages/thallo-search/README.md`). Each import keeps the index in step. A visitor without
JavaScript sees no search box rather than one that does nothing.

## Making it yours

The pages are rendered by two templates in the default theme, `entry/docs.twig` and
`listing/docs.twig`, and styled by `assets/docs.css`. Neither template names the type, so for a
type called `handbook` copy them to `entry/handbook.twig` and `listing/handbook.twig`. Three
template functions do the work and are yours to use anywhere (THEMING.md §4.2):
`markdown(text)`, `markdown_toc(text)` and `entry_tree(type)`. The search box is the partial
`_docs_search.twig`, shown where `search_enabled()`.
