---
title: "Import content from CSV, WordPress or Markdown"
slug: import-content
section: guides
order: 12
summary: "Bring existing content in: map a CSV, import a WordPress export, or move a Thallo site."
---

Thallo turns a spreadsheet, a WordPress export, a Markdown file or its own content bundle into
entries of a content type you choose. Every import runs twice: once as a dry run that writes
nothing, and once for real. This page covers each format, what it maps, and what it leaves behind.

## Before you start

- The **Content importers** capability on (**Extensions › Capabilities**). With it off, the format
  adapters are missing from the **Adapter** list and the API refuses them; see
  [capabilities and packs](../concepts/06-capabilities.md).
- The content type that will hold the entries, with the fields you mean to map to. See
  [your first content type](../getting-started/04-first-content-type.md).
- A queue worker taking the `import-export` queue, or nothing you start will run. See
  [the scheduler and the queue](../operations/03-scheduler-and-queues.md).

## Where the importers are

**Settings › Import / Export** holds an **Export** card, an **Import** card, and **Recent jobs**
under them. Everything here happens on that page, except the users CSV, which has its own screen.

The **Import** card's first control is **Adapter**. Pick the one that matches your file:

| Adapter | File | One record is |
|---|---|---|
| **CSV** | `.csv` with a header row | one row |
| **Markdown / MDX** | `.md`, `.mdx`, `.markdown` | the whole file |
| **Markdown folder (.zip)** | `.zip` | each Markdown file in the archive |
| **WordPress (WXR)** | `.xml`, `.wxr` | each post or page in the export |
| **Thallo Content Bundle** | `.ndjson`, `.jsonl`, `.json` | one line |

An upload is refused above 50 MB. `max_file_size` in a `config/import_export.php` you create changes that, and
`batch_size` (500) sets how many records one queued batch handles.

**Markdown folder (.zip)** works differently from the rest: it finds pages again on a later run and
writes only what changed. It has its own page —
[a documentation section on your site](../documentation-sites.md).

## Import a CSV

Each row becomes one entry.

```text
title,summary,published_year
"The Hills","A short line about it",2019
"The Coast","Another short line",2021
```

1. Choose **CSV** under **Adapter**.
2. Choose the **Content type**. Each row becomes an entry of this type.
3. Choose the file. Thallo reads its header row to learn the column names.
4. Under **Map fields to columns**, point each field of the type at a column, or at **— skip —**.
   A field whose name matches a column is mapped for you. Fields marked `*` are required and have
   to be mapped.
5. Set **Publish imported entries** if the entries should go live rather than stay drafts.
6. Leave **Mode** on **Dry run (preview, no writes)** and press **Run dry run**.
7. Read the job below, then set **Mode** to **Commit (write to the database)** and press
   **Import**.

An empty cell leaves the field unset. A `number` field takes a number, a `boolean` field reads
`true`, `1`, `yes` and `on` as true and anything else as false, and a `json` field takes parsed
JSON. Everything else is stored as text and validated against the field, so a value the field will
not accept is reported as a failed row rather than dropped. HTML in a cell that lands in a text
field whose **Editor** is **Rich text** is sanitised as it is saved.

A mapping to a field the type does not have, or to a column the file does not have, refuses the
whole import before any row runs.

**The CSV import only creates.** It never matches a row against an entry that exists already, so
importing the same file twice gives you two sets of entries.

## Import a WordPress export

A WordPress export is a WXR file — XML named `.xml` or `.wxr`. Thallo reads the items whose post
type is `post` or `page` and passes over everything else in the file. The steps are the CSV's, with
three differences.

The sources you map to are fixed, not read from the file: `title`, `excerpt`, `slug`, `date`,
`status` and `author`. Mapping a field to anything else refuses the import.

**Body field** takes the post's body. Pick a text field: a **Rich text** field gets the HTML
cleaned — headings, lists, links and images survive, scripts, frames, event handlers and
`javascript:` URLs do not — and a **Plain textarea** field gets the tags stripped.

**Publish imported entries** is narrower here: only items whose WordPress status is `publish` are
published. The rest stay drafts.

Not brought across: media and attachments, categories and tags, custom post types, post meta, and
authors as users. The author's name is a value you can map to a field; it creates no account.

## Import one Markdown file

The **Markdown / MDX** adapter turns one file into one entry.

Choose the file first: Thallo reads the keys out of its `---` front-matter block and offers them as
the sources to map. The body is separate, and goes to the field you pick under **Body field** — a
**Rich text** field gets the Markdown converted to HTML, a **Plain textarea** field gets the
Markdown stored as written, for a theme to render. You need either a body field or one mapped
front-matter key; neither is an error.

Raw HTML in the file is stripped and `javascript:` and `data:` links are refused, whoever wrote the
file. In an `.mdx` file, JSX is not interpreted: it passes through as text.

## Import users from a CSV

Users have their own screen. On **Users & Access › Users**, the upload button beside **+** in the
list header opens **Bulk import users**: the same upload, map, dry run, commit, over the fixed
fields **Username**, **Email**, **Password**, **Status**, **First name**, **Last name** and
**Roles (slugs, comma-separated)**. Username and email are required, and have to be new both in the
database and within the file. A row with no password gets a generated one the user has to reset.

Imported accounts are stamped as having a verified email address, on the understanding that you
vouch for them. Do not import an address list you did not collect yourself.

The modal only queues the job. It appears in **Recent jobs** like any other.

## Watch the job

A job's row names its adapter by key (`csv.content`, `wordpress.content`), its type, its status,
`dry run` where that is what it was, how many of the total records it has processed, how many
failed, and when it started. A job is `queued`, then `running`, then `completed` — or `cancelled`,
or `failed`. One failed record is enough to finish it as `failed`. The list refreshes itself every
few seconds while anything is moving; **Refresh** at the top right forces it. A job that stays
`queued` has no worker on the `import-export` queue.

- **Errors** opens the failed records: a severity, a code, the record's number in the file, and the
  message. The number is the row of the CSV or the item of the WXR file, counting from one.
- **Report** replaces **Errors** for a Markdown folder import, and lists what each page became.
- **Cancel** stops a job that is still running. **Retry** re-queues a failed one.

A job stores at most 1,000 records of each severity — `error_cap_per_severity` in
`config/import_export.php` — and counts the rest.

With the approval workflow on, an entry the importer may not publish is saved as a draft and
reported as a warning, "Saved as a draft, not published", not as a failed record. Send the drafts
through the review queue. See [drafts, preview and publishing](../concepts/05-publishing.md).

## Move a site with a content bundle

The **Thallo Content Bundle** is Thallo's own format, and the way to carry content between
installs. It belongs to the core, so it works whether or not **Content importers** is on.

On the site you are leaving, set **What to export** to **Thallo Content Bundle** and press **Start
export**. When the job reads `completed`, **Download** gives you one NDJSON file: the content
types, the entries, their drafts, versions, publications and routes, the references between them,
and a manifest of the assets those entries point at.

On the site you are moving to, choose the same adapter and the same file, dry run, then commit.
Each line is matched by its own key and updated if it is there already, so importing the same
bundle a second time changes nothing.

**The uploaded files are not in the bundle.** The manifest records each asset's row, including
where its file was, but the file itself stays where it is. Copy `storage/` across yourself before
you import, or the entries arrive pointing at images that are not there.

## Check it worked

The job reads `completed` with no failed records, and its processed count matches the number of
rows, items or lines you expected. Open **Content**, pick the content type, and the entries are
there — as drafts, or live if you asked for them to be published.
