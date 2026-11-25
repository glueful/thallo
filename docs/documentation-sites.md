---
section: guides
order: 60
summary: "Publish a folder of Markdown as a documentation section, from a deploy or from the admin."
---
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
on (**Extensions › Capabilities**).

Step 1 makes five sections, in this sidebar order: `getting-started`, `concepts`, `guides`,
`reference`, `operations`. **Your folders are matched against these names**, so a folder called
`how-to` is in none of them and its pages arrive with no section at all. Name your folders after
the sections you want, choose your own with `--sections=start,guides,reference`, or change them
later under **Settings › Content types**.

## Without a shell: from the admin

Everything above can be done under **Settings › Import / Export**, for a site whose owner has
the admin and not a terminal.

1. Choose the adapter **Markdown folder (.zip)**. On a site with no docs section the page offers
   **Set up documentation**: one click does what `thallo:docs:setup` does, with the same five
   sections. To use your own, change them afterwards under **Settings › Content types**.
2. Zip your docs folder and choose the file. It does not matter whether the zip holds the
   folder or only what is inside it: a folder that wraps every page is set aside, so a page has
   the same path whichever way it was packed, and the same path the command would give it.
3. Name any **folders to leave out**, give the address for **“Edit this page” links** if you want
   them, and decide whether the pages are **published**.
4. Run it as a **dry run** first. The job's **Report** says what each file would become, which
   links lead nowhere, and which pages no longer have a file. Then run it as a **commit**.

It is the same import, with the same rules: upload the folder again whenever the files change,
only what changed is written, and nothing is deleted. Pages are written as the person who is
signed in, so on a site with the review workflow they are published only if that person may
publish without review; otherwise they are saved as drafts and the report says so.

An upload is unpacked with care: only Markdown files are read out of the archive, a name that
points outside the import refuses the whole archive, and an archive that unpacks to more than
50 MB or holds more than 2,000 pages is refused. It needs PHP's `zip` extension.

Imports are background jobs on the `import-export` queue. They run while a queue worker that
was given that queue is running, or when a cron line drains it
([the scheduler and the queue](operations/03-scheduler-and-queues.md)). Until then the job reads
"queued".

## From a GitHub repo

A site whose docs live in a public GitHub repository can follow it, so a new release of the docs
is one click away. Under **Settings › Import / Export**, **Documentation from GitHub**:

1. Give the **repository** (`owner/name`, e.g. `glueful/thallo`), the **folder** that holds the
   pages (`docs`), and what to **follow**: the **latest release tag** (pre-releases included or
   not) or a **branch**. Choose the content type, whether the pages are published, and folders to
   leave out. Saving checks the repository with GitHub: it must be public, and have a version tag
   or the branch.
2. Press **Preview** to see what a sync would change, page by page, with nothing written. Press
   **Sync now** to bring the pages to that version.
3. Each time you open the card it says whether the repository has moved since the last sync:
   "v1.0.0-beta.110 is out", the branch has new commits, or the tag you synced now points elsewhere.
   It only reads: nothing is imported until you press Sync now.

The latest release is the highest version **tag**, by semantic versioning, not GitHub's
"latest release" page, which a repository that only pushes tags never updates. Each sync downloads
GitHub's archive of exactly one commit, the one the tag or branch pointed at, and reads only the
chosen folder: Markdown elsewhere in the repository (its README, a package's CHANGELOG) never
becomes a page. A page is found again by its path, so pages you imported by hand before are
updated, not made twice. "Edit this page" links point at the repository's default branch, or the
branch you follow.

What a sync ends with:

- **Up to date**: every page written or unchanged.
- **Synced with errors**: some pages failed (each one's reason is under **pages**); the rest landed.
- **Refused**: nothing was written, because the folder holds no pages (its name must match the
  repository's, letter case included), the content type is gone, or GitHub refused the repository
  or tag.
- **Failed**: every page failed, or the archive could not be used.
- **Cancelled**: you pressed **Cancel**; the sync stopped after the page it was writing.
- **Interrupted**: the sync stopped reporting in (a worker died) and was released, or it did not
  finish in five attempts. Pages it wrote stay.

Pages whose file is gone from the folder are listed, never deleted.

It needs a queue worker taking the `import-export` queue (see
[the scheduler and the queue](operations/03-scheduler-and-queues.md)), and the scheduler running
from cron so that a sync whose worker died is picked up again on its own. `thallo:provision` adds
the two scheduled tasks that do this, `docs_sync_recover` (every five minutes) and `docs_sync_prune`
(daily), to the scheduler's database store; with that store off (`SCHEDULE_DATABASE_STORE=false`) it
says so, and a stuck sync is picked up only when the card is opened. Syncs are kept for 30 days
(`import_export.retention_days`), the last one that landed for as long as it is the last; their
downloaded archives are deleted a day after each finishes. GitHub allows a server 60 anonymous
requests an hour: the card checks at most once every five minutes, and says when the limit is
reached.

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
| `edit_url` | Where a reader can propose a change. A page made from a file named `CHANGELOG`, `LICENSE` or `LICENCE` (an `NN-` prefix aside) gets none: a changelog is written by releases and a licence is not open to edits. |

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
| Section | `section` | The top folder, when it is one of the type's sections — exactly, so `Guides` and `03-guides` are not `guides`. A folder that matches none leaves the page with no section, and the sidebar holds those pages in one unnamed group. |
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

Turn on **Settings › General › Content search**; the index builds itself in the background. The
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
