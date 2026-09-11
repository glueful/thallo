# Thallo website and documentation, built on Thallo

Status: design approved (2026-09-11); phase 1 not started.
Owner: Michael Tawiah Sowah. Charter: `docs/internal/DISTRIBUTION.md` (decision 8 and the
website-from-tag gate bind this plan).

## Goal

thallo.dev becomes the product's own website: a landing page and the developer documentation,
both delivered by a Thallo install running a published tag. This closes the charter's
website-from-tag gate and is half of the Developer Preview → Beta promotion condition (the other
half is outsiders installing from the public docs alone). Every gap the site exposes is fixed in
Thallo or the framework, never worked around in the site.

## Decisions

1. **One Thallo install serves both surfaces.** The landing page is the homepage entry; the docs
   live under `/docs/...` on the same install. No second site, no Nuxt.
2. **The landing page is authored in the admin** with the block library. Marketing copy moves on
   its own rhythm and is what the blocks exist for.
3. **Docs are markdown in git, published through Thallo.** Source of truth is the repo's `docs/`
   folder: reviewed in PRs, shipped inside the tag, always matching the release. On deploy the
   Markdown importer loads them into a `doc` content type and Thallo delivers, routes, searches
   and indexes them. The admin is not where reference material is written.
4. **Deploys come from the tag** (charter). A deploy script checks out `vX.Y.Z-beta.N`, installs,
   provisions, imports the docs and warms caches. Nothing is ever deployed from the dev checkout.
5. **Latest docs only** for the Developer Preview. Per-version docs are a post-Beta question;
   if they come, they fit under the same path (`/docs/1.0/...`).
6. **Docs live at `/docs` on thallo.dev, not a subdomain** (decided 2026-09-11). One install,
   one route prefix, one sitemap, one search index, shared header/footer regions. A subdomain
   would mean a second install kept in step with the tag, or the first production use of
   workspaces on the site that gates the Beta promotion; workspaces get dogfooded on a
   lower-stakes site. Routes are a prefix, so the subdomain door stays open.
7. **The landing page shows the running version through a shortcode, nothing else live**
   (decided 2026-09-11). A `thallo:version` shortcode rendered from the install's own version
   is correct by construction on every deploy — a page that says beta.18 the week beta.19
   ships undermines "deployed from the tag". Block counts, pack counts and similar trivia stay
   out; all other copy is hand-written and changed deliberately per release.

Alternatives rejected: docs authored in the admin (loses git review and tag alignment; makes
"from the tag" meaningless for docs); docs on Nuxt Content like the Glueful docs (fastest, proves
nothing about Thallo, and the gate is about the site running on Thallo).

## What exists today (beta.19)

- thallo.dev runs beta.19 on the default theme with the 46-block starter library (hero, features,
  pricing, tabs, accordion, stepper, CTA, blog posts, forms, layout primitives).
- Content types Pages and Posts; packs for navigation, SEO (meta, sitemap, redirects), search
  (Meilisearch-backed, behind a `SearchBackend` port), analytics, workflow, importers (CSV,
  Markdown/MDX with YAML front matter, WordPress).
- Template hierarchy per type: `entry/{type-slug}.twig` falls back to `entry.twig`.
- Public docs corpus: README, `docs/production.md`, `docs/upgrading.md`, `docs/limitations.md`,
  and the pack READMEs. Everything else is internal.
- The Markdown importer creates new entries on every run (no upsert), assigns no routes, and has
  no CLI entry point; it is driven from the admin.

## Architecture

```
repo docs/**/*.md ──(deploy: thallo:import markdown)──▶ entries of type `doc`
                                                          │ routes /docs/{section}/{slug}
                                                          ▼
                       Thallo delivery: entry/doc.twig ── sidebar (content tree)
                                                       ── TOC (headings), prev/next
                                                       ── "Edit on GitHub" (front matter → repo path)
                                                       ── search (search pack), sitemap (SEO pack)

admin-authored homepage entry (Pages) ──▶ entry/page.twig with the block library
```

The `doc` content type: `title` (string), `body` (text, rich), `section` (enum: getting-started,
concepts, guides, packs, reference, operations), `order` (number), `summary` (string, for search
and listing cards), `source_path` (string, repo path for the edit link). Front matter carries
`slug`, `section`, `order`, `summary`; the file path is the fallback for `slug` and `section`.

## Phases

### Phase 1 — Landing page (admin-authored)

Build the homepage on thallo.dev from existing blocks: hero, feature grid, stepper ("install in
three steps"), capability tabs, CTA, footer. Log every block or template need as a product gap and
fix it in the library. Expected first gap: a **code block** with language label and copy button
for the `composer create-project` line (rich text has no code highlighting; `html` is the only
escape hatch and ships deactivated).

Second expected gap: the **`thallo:version` shortcode** (decision 7) for the "install beta.N"
line, registered by the app and rendered through the existing shortcode block.

Done when: the homepage is published from the admin with no `html` block and no custom template,
and every gap found is either fixed or filed in `docs/internal/OUTSTANDING.md`.

### Phase 2 — Documentation

Four workstreams; 2a and 2b can run in parallel, 2c depends on both, 2d on 2c.

**2a. Corpus (markdown in `docs/`).** Write the developer documentation the Beta gate needs an
outsider to succeed with, in this order:

1. Getting started: requirements, `create-project`, provision, first admin, first page live.
2. Concepts: content types and fields, entries and locales, blocks and the block library,
   regions (header/footer), themes and templates, routes and delivery, publishing and workflow.
3. Capabilities and packs: the switchboard, then one page per pack (commerce, accounts,
   collections, navigation, SEO, search, analytics, importers, subscriptions, tenancy,
   workflow), each grown from its README.
4. Operations: production (exists), upgrading (exists), limitations (exists), backups, health,
   the web-server block.
5. Reference: admin API overview (from `docs/openapi.json`), CLI commands, configuration keys,
   environment variables.

Front matter on every file: `slug`, `section`, `order`, `summary`. Existing files move under the
section folders and gain front matter.

**2b. Importer becomes deploy-grade (product work).**

- Upsert: an entry is keyed by `(content_type, slug)`; a re-import updates the existing draft
  and publishes it rather than creating a duplicate. Removed files: reported, not deleted
  (deletion is an explicit flag).
- Routes: the importer assigns the route `/{prefix}/{section}/{slug}` from front matter, with
  the prefix an option; a renamed slug creates a redirect through the SEO pack.
- CLI: `php glueful thallo:import markdown <dir> --type=doc --prefix=docs --publish`, runnable
  from a deploy script, with `--dry-run` and a per-file report.
- Markdown fidelity: fenced code blocks keep their language; headings keep stable ids for the
  TOC and deep links; relative links between files resolve to routes; images resolve to media
  or the theme's assets.

**2c. Docs delivery (theme work).**

- `entry/doc.twig`: three-column layout — section sidebar built from the published `doc`
  entries (section, order), the body, and an on-page TOC from `h2`/`h3`.
- Previous/next by section order; "Edit on GitHub" from `source_path`; last-updated from the
  entry.
- Code block rendering with syntax highlighting and a copy button (shared with the phase 1
  block), theme-provided, no third-party runtime fetches (the site's CSP is `'self'`).
- A `/docs` index page listing sections and their first pages.
- Search: docs entries indexed by the search pack; a search box in the docs header. If the
  server does not run Meilisearch, this is where the Postgres FTS backend the pack anticipates
  gets built.

**2d. Verification.** An outsider walks Getting started on a clean machine using only the
public site and completes an install and a first published page. That walk is the Beta gate's
second half and is recorded in `DISTRIBUTION.md`.

Done when: every page in the corpus renders at its route from an import run against a tag,
search returns docs pages, the sitemap lists them, and the outsider walk passes.

### Phase 3 — Deploy from the tag

`scripts/deploy-site` (or the panel equivalent) on the server: fetch the tag, `composer install
--no-dev`, `php glueful thallo:provision`, `php glueful thallo:import markdown docs --type=doc
--prefix=docs --publish`, reload PHP-FPM, warm the page cache, run `thallo:doctor`. The script
refuses a non-tag ref. First run against beta.N with the phase 1 and 2 work closes the
website-from-tag gate; `DISTRIBUTION.md`'s checklist is ticked in the same commit.

## Expected product gaps (fix in Thallo/framework, never in the site)

| Area | Gap | Where it lands |
|---|---|---|
| Blocks | Code block with language + copy | starter block library + theme template |
| Shortcodes | `thallo:version` renders the running install's version | app shortcode registry |
| Importer | Upsert by slug, routes, redirects on rename, CLI, dry run | thallo-importers |
| Render | `entry/doc.twig` layout: sidebar from content tree, TOC, prev/next | default theme |
| Render | Heading ids and code fences preserved from markdown | render pipeline |
| Search | Postgres FTS backend if Meilisearch is not on the host | thallo-search |
| SEO | Sitemap includes docs; redirects created by the importer | thallo-seo |
| Ops | Deploy-from-tag script; doctor check that the running version is a tag | scripts, Doctor |

## Open questions

None at present. The domain layout and live-data questions were decided on 2026-09-11 (decisions
6 and 7).
