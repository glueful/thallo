# Writing Thallo's documentation

This guide is for whoever writes a page of Thallo's docs — a person, or a model such as Claude
working in this repository. Read all of it before writing. It is short on purpose.

The docs are Markdown files under `docs/`. They are published to thallo.dev by Thallo's own
Markdown import (`docs/documentation-sites.md`), so a file has to satisfy that import as well as
read well on GitHub. [PAGES.md](PAGES.md) lists every page to write, what it must cover, and
which files in this repository hold the facts.

## 1. The one rule that matters most

**Every statement in a page must be true of the code in this repository, today.**

A reader follows these pages with a terminal open. An invented flag, a guessed config key or a
menu that is not there costs them an hour and costs Thallo their trust.

- Before writing a page, open the **sources** PAGES.md lists for it, and read them.
- A command, flag, config key, environment variable, route, permission, template name, function
  name, field name, menu label or default value may appear in a page **only if you found it in a
  source file**. Copy it exactly.
- If you cannot find something, leave it out. Do not fill a gap with what another CMS does.
- Do not describe a feature from its name. `thallo:resync` may not do what "resync" suggests:
  read the command.
- Prefer the code to the prose. A README, an existing docs page, a plan under `docs/internal/`, a
  CHANGELOG entry, a code comment or a docblock can be out of date; the code that runs and the
  tests are not. When they disagree, the code wins, and you say so in your report (§8).
- **That includes PAGES.md.** Its "must cover" lists were written partly from the same prose. The
  first pilot was asked to document `QUEUE_CONNECTION=sync` and mail sent by the queue; neither
  exists, and the writer who read the driver code found out. If the code contradicts your row,
  the code wins: write what is true and report the row.
- A config file listing an option does not make the option work. `config/queue.php` lists a
  `sync` connection that no driver implements. Find the code that reads the key.
- Never write a version number into a page ("since beta.50"). `CHANGELOG.md` holds history. The
  docs describe the product as it is.
- Thallo is a **Developer Preview**. Do not promise stability, support or a roadmap. What is not
  built is in `docs/limitations.md`; link to it rather than restating it.

Ways to check a fact, from the repository root:

```bash
php glueful list                      # every console command that exists
php glueful <command> --help          # its real arguments and options
git grep -n "some_config_key"         # where a key is read, and its default
```

`php glueful` needs a database. In this repository's test setup, prefix it with:

```bash
DB_PGSQL_DATABASE=app_test APP_ENV=testing CACHE_DRIVER=array CACHE_TAGS=false QUERY_CACHE_ENABLED=false QUERY_CACHE_STORE=array
```

## 2. Where a page goes

```
docs/
  getting-started/   first contact: install, first page, first content type
  concepts/          how Thallo thinks: the ideas behind the screens
  guides/            how to do one job, start to finish
  reference/         things you look up: commands, settings, functions, blocks
  operations/        running a site: deploys, cron, queues, backups, upgrades
  internal/          not documentation — never imported, never shipped
```

Four pages already exist at the top of `docs/` and **must stay there**, because code, tests and
other files link to them by path: `production.md`, `upgrading.md`, `limitations.md`,
`documentation-sites.md`. Their front matter puts them in a section. Every new page goes in its
section's folder.

The five folder names are the docs type's sections (`DocsSetup::DEFAULT_SECTIONS`). Do not add a
folder: a file in an unknown folder gets no section and falls out of the sidebar.

## 3. A page's file

```markdown
---
title: "Install Thallo"
slug: install
section: getting-started
order: 2
summary: "Create a project, set it up, and sign in to the admin."
---

Opening paragraph: what this page gets you, in two or three sentences.

## First heading
```

**Front matter is flat `key: value` lines, nothing else.** The importer's parser
(`packages/thallo-importers/src/Markdown/FrontMatter.php`) reads no lists, no nesting and no
multi-line values. Put `title` and `summary` in double quotes, always: a colon inside an unquoted
value breaks the preview on GitHub.

| Key | Rule |
|---|---|
| `title` | The page's name, as PAGES.md gives it. Sentence case. No "Thallo" unless it is the subject. |
| `slug` | The page's URL: `/docs/{slug}`. As PAGES.md gives it. Lower-case, hyphens. |
| `section` | The folder's name. |
| `order` | The page's place in its section, as PAGES.md gives it. |
| `summary` | One sentence, shown under the title and on the index. It says what the reader gets. No "This page…". |
| `draft: true` | Keeps an unfinished page out of the import. Remove it when the page is done. |

**URLs are flat.** There is no `/docs/concepts/blocks`: it is `/docs/blocks`, whatever folder the
file is in. So **a slug is unique across all of `docs/`**, and two files that claim the same slug
fail the import. PAGES.md has already chosen slugs that do not collide. Do not change one.

**The body has no `# H1`.** The title comes from the front matter and the theme prints it. Start
the body with a paragraph, and use `##` for the first heading.

Name the file after its slug, with the order in front so the folder reads in order on GitHub:
`getting-started/02-install.md`. The importer drops the `NN-` prefix.

## 4. What the Markdown can do

The page is rendered by `packages/thallo-render/src/Markdown/MarkdownRenderer.php`: CommonMark
with GitHub's extensions.

**Use:** paragraphs, `##`/`###`/`####` headings, lists, task lists, tables, block quotes, inline
code, fenced code, links, bold and italic, strikethrough.

**Do not use:**

- **Raw HTML.** It is stripped. No `<details>`, `<br>`, `<img>`, `<kbd>`, no HTML comments as
  notes to yourself.
- **Images.** They do not travel with the import yet. Describe the screen in words. If a page
  truly cannot work without a picture, say so in your report instead of adding one.
- **Admonition syntax** (`> [!NOTE]`, `:::tip`). It is not rendered. Write the sentence:
  "Do this before you upgrade:" is a better warning than a coloured box.
- **Footnotes, emoji, badges, tabs.** None render.

**Headings.** `##` and `###` build the "On this page" outline, so they are the page's map: make
each one say what the section holds ("Create the database", not "Step 2" or "Overview"). Every
heading gets a link built from its words, and other pages link to it, so do not word two
headings the same on one page.

**Code.**

- Always name the language on a fence: `bash`, `php`, `twig`, `json`, `ini`, `nginx`, `css`,
  `js`, `text`. It is shown as the block's label.
- In a `bash` block, start each command the reader types with `$ ` and leave output lines
  without it. The theme draws the prompt and the copy button copies only the commands.
- More than a line or two of output goes in its own `text` block after the command, not in the
  `bash` block. A crontab line is `text` too: it is neither typed at a prompt nor output. A
  systemd unit is `ini`.
- Output you quote is exact, except a secret: replace a token, key or password with its name in
  capitals (`SETUP_TOKEN`), and say that you did.
- One block, one purpose. Do not put three alternatives in one block for the reader to pick
  apart.
- Use real values the reader can recognise as examples: `my-site`, `example.com`,
  `/path/to/site`. Never a real secret, never a key that looks real.
- Every command has been run, or read from the source, by you (§1).

**Links.**

- To another page: a **relative link to its `.md` file**, exactly as on GitHub —
  `[upgrading](../upgrading.md)`, `[blocks](../concepts/blocks.md#overriding-a-template)`. The
  import turns it into the page's URL. Never write `/docs/...` by hand.
- To a page that does not exist yet: still link to the file PAGES.md names. The import reports
  it as a broken link until that page is written, which is how the gap stays visible.
- To code in the repository: do not link. Name the path in inline code (`config/queue.php`). A
  reader's install has different paths from this repository; see §6.
- To elsewhere: a full `https://` URL.

## 5. How a page is shaped

Each section holds one kind of page. Keep them apart: a guide that stops to explain a concept
loses the reader who came to get a job done, and a concept page full of steps explains nothing.

**Getting started — a lesson.** The reader has never used Thallo. One path, no choices, every
step shown with its command and what they should see afterwards. It ends with something working
and one link onward.

**Concepts — an explanation.** The reader wants to understand. Say what the thing is, why it is
that way, how it relates to its neighbours, and where its edges are. Few commands. Link to the
guides that use the concept.

**Guides — a recipe.** The reader has a job. Title it by the job ("Add a contact form"). Open
with what they will have at the end and what they need first. Then numbered steps in the order
they are done, each starting with a verb. End with how to check it worked. No theory: link to
the concept.

**Reference — a lookup.** The reader knows what they want. Tables and short entries in a
predictable order, complete rather than friendly. Every item the source has, none it has not.
Reference is where completeness is the whole point: a CLI page that lists half the commands is
worse than none.

**Operations — a runbook.** The reader runs a live site, perhaps during an outage. Exact
commands, what each changes, how to confirm it, how to undo it. Say plainly what is destructive.

Every page, whatever its kind:

- Opens with what the reader gets, not with background.
- Says what the reader needs before they start, if anything.
- Names things as the admin names them, in bold, with the path through the menu:
  **Settings › Import / Export**. Use `›` between levels. The menu's labels are in
  `admin/src/registry/*Module.ts`. Where the sidebar and a page's own heading differ in case
  ("Block Types" against "Block types"), the sidebar wins: it is the path the reader follows.
- Shows what success looks like.
- Is as long as its job needs. Most pages are 300 to 900 words; a page whose row asks a lot runs
  to 1,200. A reference page is as long as its source. Never drop a verified fact to reach a
  number: cut words, not facts.

## 6. The reader's site is not this repository

This repository is Thallo's source. A reader has an **install**: a project made by
`composer create-project`, where Thallo lives in `vendor/` and their own code lives in `app/`,
`routes/`, `config/`, `themes/` and `database/migrations/`.

- Give paths as the reader sees them: `config/uploads.php`, `.env`, `storage/logs/`.
- Never tell a reader to edit a file under `vendor/`. An upgrade overwrites it. Say how the
  thing is overridden from their own project; if you cannot find how, that is a finding for
  your report, not something to paper over.
- Paths in this repository that a reader never sees (`core/src/…`, `packages/…/src/…`, `admin/`,
  `tests/`, `scripts/`) do not belong in a page, except in a page written for people who
  contribute to Thallo itself.
- `skeleton/` in this repository is the project a reader gets. Read it to learn what their
  install looks like.

## 7. Voice

Write the way the CHANGELOG and the existing four pages are written: plain, exact, a little dry.

- Second person, present tense, active voice. "Run the command. Thallo writes `.env`."
- Short sentences. One idea each. Cut every word that carries nothing.
- British spelling: colour, behaviour, organise, licence (noun).
- Say what happens, then why it matters, in that order.
- No marketing: not "powerful", "seamless", "simply", "just", "easy", "robust", "blazing".
  If something takes one command, show the one command; the reader will see that it is easy.
- No filler openers ("In this guide, we will…", "Let's dive in"), no sign-offs ("Happy
  building!"), no rhetorical questions, no exclamation marks, no emoji.
- Do not apologise for the product or hedge ("should", "might", "usually") where the code is
  definite. Where it is not definite, say exactly what it depends on.
- One name per thing, every time. The glossary below is the list. Do not vary for elegance.

**Glossary — use these words, spelled this way.**

| Say | For | Not |
|---|---|---|
| content type | the model an entry follows (Settings › Content Types) | model, schema, post type |
| entry | one piece of content of a content type | post, item, record, document |
| field | one value of an entry | attribute, property |
| block | one piece of a page's body | component, widget, module |
| block type | the definition a block follows (Settings › Block Types) | block schema |
| Container | the one layout block: flex or grid | section, row, column block |
| the Design view | the visual page builder | page builder, editor, designer |
| the stage | the live page inside the Design view | canvas, preview (the preview is a separate thing) |
| the Blocks tab | the side panel's tab that offers blocks, sections and pages to insert | block picker, palette, library |
| Style tab, Layout tab | the inspector's tabs | styling panel |
| style class | a named, reusable set of style settings | CSS class, preset |
| pattern | a ready-made section or page in the Blocks tab's library | template, snippet |
| theme | the Twig templates and CSS that render the site | skin, template pack |
| region | the header or the footer (Site › Header & footer) | global block, partial |
| capability | a feature that can be switched on (Extensions › Capabilities) | plugin, add-on, module |
| pack | the Composer package a capability ships in (`glueful/thallo-*`) | plugin |
| workspace | a tenant: one site among several on an install | tenant (in prose), account |
| the admin | the app at `/admin` | dashboard, backend, CMS panel |
| preview | the draft, rendered by the theme, in a signed session | staging |
| publish | make an entry's draft the live version | deploy, release |
| provision | `php glueful thallo:provision` | install script, setup (setup is the web screen) |

If a term you need is not here, find what the admin's own screens call it (`admin/src/`) and use
that.

## 8. How to work, and what to hand back

Write **one page per task** unless you are told otherwise. For each page:

1. Find the page in [PAGES.md](PAGES.md). Read its row: path, front matter, what it must cover.
2. Read every source the row lists. Follow them where they lead. Run the commands you will
   show, where you can.
3. Read the pages yours will link to, if they exist, so the two agree and do not repeat each
   other.
4. Write the file at the path given, with the front matter given.
5. Check it against the list below.
6. Run the docs lint. It reads your page the way the import does and checks what a machine can
   check: the front matter against PAGES.md, one URL per page, no raw HTML, image or admonition,
   a language on every fence, and every link to another page leading to a file that exists or
   is planned.

   ```bash
   vendor/bin/phpunit tests/Unit/Docs
   ```

7. Rehearse the import. With `--dry-run` it writes nothing:

   ```bash
   php glueful thallo:import:markdown docs --type=docs --exclude=internal --dry-run
   ```

   It needs the environment prefix from §1, and it needs the `docs` content type to exist on
   that database. `php glueful thallo:docs:setup` makes it, once, and **it writes**: if you were
   told the database is prepared, or you share it with other writers, do not run it.

   Your file must be listed as `created` or `updated`, at the URL you expect. A broken link to a
   page that PAGES.md plans but nobody has written yet is fine. Any other broken link is yours.

Then **report**, separately from the page, in a few lines:

- **Left out:** anything the row asked for that you could not verify, and where you looked.
- **Disagreements:** anywhere a README, a plan or the CHANGELOG said one thing and the code
  another.
- **Product gaps:** anything a reader would need that Thallo cannot do, or can do only by
  editing `vendor/`.
- **Pictures:** any place the page suffers for having no image.

These findings are as valuable as the page. Do not hide them in the page as caveats, and do not
"fix" them by guessing.

**Before you call a page done**

- [ ] Every command, flag, key, path, label and default in it was found in a source file.
- [ ] The front matter matches PAGES.md exactly, with `title` and `summary` in quotes.
- [ ] No `# H1`, no raw HTML, no image, no admonition syntax, no emoji.
- [ ] Every code fence names its language; `bash` commands start with `$ `.
- [ ] Every link to another page is a relative `.md` link, and the file is one PAGES.md names.
- [ ] No path under `vendor/` is edited, and no repository-only path is shown to a site owner.
- [ ] No version numbers, no promises about the future.
- [ ] The opening says what the reader gets. The end says how to tell it worked.
- [ ] The words are the glossary's.
- [ ] `draft: true` is gone, the docs lint passes, and the dry run lists the page.

## 9. A prompt to start a model on a page

Paste this, with the page's slug filled in, into a session opened at the repository root:

```text
Write one page of Thallo's documentation: the page whose slug is "<slug>".

1. Read docs/internal/docs-writing/GUIDE.md in full. It is the contract for this task.
2. Find the page's row in docs/internal/docs-writing/PAGES.md.
3. Read every source that row lists before writing anything. Verify each command, flag,
   config key, path and menu label against the code. If you cannot verify something, leave it
   out and tell me.
4. Write the page at the path the row gives, with the front matter the row gives.
5. Run the docs lint and the import's dry run as the guide describes, and fix what they report.
6. Set the page's Status to done in PAGES.md.
7. Reply with the report the guide asks for in section 8. Do not commit.
```
