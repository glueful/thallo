---
title: "Command line reference"
slug: cli
section: reference
order: 1
summary: "Every command Thallo adds to the console, with its arguments and options."
---

Every command Thallo adds to the `php glueful` console, grouped by what it is for. For each one:
what it does, its arguments and options as `--help` prints them, whether it writes anything, and
an example. The framework's own commands a site owner reaches for are in a table at the end.

## How to read this page

Run every command from the project root, the directory holding `composer.json` and `glueful`. One
command prints what this install has registered:

```bash
$ php glueful list
```

That list is not a fixed set: a command that belongs to a capability appears only while that
capability is on (**Extensions › Capabilities**), and the same is true of the Glueful extensions
beside them. See [capabilities and packs](../concepts/06-capabilities.md).

Any command prints its own arguments and options:

```bash
$ php glueful thallo:provision --help
```

Every command takes these as well, from Symfony's console:

| Option | What it does |
|---|---|
| `-h`, `--help` | Display help for the given command |
| `--silent` | Do not output any message |
| `-q`, `--quiet` | Only errors are displayed. All other output is suppressed |
| `-V`, `--version` | Display this application version |
| `--ansi`, `--no-ansi` | Force (or disable `--no-ansi`) ANSI output |
| `-n`, `--no-interaction` | Do not ask any interactive question |
| `-v`, `-vv`, `-vvv`, `--verbose` | Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug |

The option tables below leave those out and list only what the command adds. A command marked
**Writes** changes the database, `.env` or a file on disk; one marked **Reads only** does not.

## Setting up the install

### thallo:provision

Configure the database and the security keys and run the migrations. This is the first of the two
setup layers: it creates no people. **Writes** `.env`, applies pending migrations, publishes the
admin bundle, and rebuilds the extension, route and render caches.

| Option | What it does |
|---|---|
| `--force` | Regenerate keys / rewrite `.env` / re-run pending migrations |
| `--db-host=DB-HOST` | Override db-host |
| `--db-port=DB-PORT` | Override db-port |
| `--db-name=DB-NAME` | Override db-name |
| `--db-user=DB-USER` | Override db-user |
| `--db-password=DB-PASSWORD` | Override db-password |
| `--db-schema=DB-SCHEMA` | Override db-schema |
| `--db-sslmode=DB-SSLMODE` | Override db-sslmode |

Without options it asks for each connection field in turn. With all of them and `-n` it asks
nothing, which is what a deploy script wants.

```bash
$ php glueful thallo:provision --db-host=localhost --db-port=5432 --db-name=my_site --db-user=my_site -n
```

[Install Thallo](../getting-started/02-install.md) walks through a first run.

### thallo:create-admin

Create the first admin account and the site settings. Run it after `thallo:provision`. **Writes.**

| Option | What it does |
|---|---|
| `--site-name=SITE-NAME` | Site name [default: `"Thallo"`] |
| `--admin-email=ADMIN-EMAIL` | First admin email |
| `--admin-password=ADMIN-PASSWORD` | First admin password |
| `--locale=LOCALE` | Default locale [default: `"en"`] |

It asks for anything you leave out. With `-n`, the email and the password are required.

```bash
$ php glueful thallo:create-admin --admin-email=you@example.com
```

### thallo:doctor

Check that this host can run a Thallo instance: PHP, extensions, paths, database. **Reads only.**

| Option | What it does |
|---|---|
| `--strict` | Treat warnings (e.g. absent security keys) as failures |

One row per check, each OK, WARN or FAIL. Any FAIL fails the command.

```bash
$ php glueful thallo:doctor --strict
```

### thallo:update:check

Ask Packagist whether a newer `glueful/thallo-core` is published, and show what this install runs.
**Writes** the result of the check — the newest published version and the time it was asked — and
nothing else. It never upgrades anything.

| Option | What it does |
|---|---|
| `-f`, `--force` | Ask now, even if the daily check already ran |

A check runs at most once in twenty hours unless forced, and is skipped entirely on a development
checkout or when `UPDATE_CHECK_ENABLED` is false.

```bash
$ php glueful thallo:update:check --force
```

### thallo:superuser:grant

Grant hidden-root superuser authority to an active user. **Writes**, and records the grant in the
audit log.

| Argument | What it is |
|---|---|
| `user-uuid` | The user UUID to promote |

| Option | What it does |
|---|---|
| `--force` | Proceed without confirmation |

The account is given both the `superuser` and the `administrator` role. It must be active. The
command asks to confirm, and refuses to run outside a terminal without `--force`.

```bash
$ php glueful thallo:superuser:grant <user-uuid>
```

### thallo:superuser:transfer

Move superuser authority between two active users, in one transaction. **Writes**, and records the
transfer in the audit log.

| Argument | What it is |
|---|---|
| `from-user-uuid` | Current superuser UUID |
| `to-user-uuid` | Destination user UUID |

| Option | What it does |
|---|---|
| `--force` | Proceed without confirmation |

```bash
$ php glueful thallo:superuser:transfer <from-user-uuid> <to-user-uuid>
```

[Users and roles](../guides/15-users-and-roles.md) explains what superuser reaches.

### thallo:policy:manifest

Export or validate workspace role policy manifests. **Reads only:** it prints JSON on standard
output and writes no file.

| Option | What it does |
|---|---|
| `--export` | Export the deployed policy manifest |
| `--validate=VALIDATE` | Validate a policy manifest file |
| `--compare=COMPARE` | Compare two policy manifest files: `--compare old.json --compare new.json` (multiple values allowed) |

Choose exactly one: `--export`, `--validate` with a file, or `--compare` twice. Anything else is an
error.

```bash
$ php glueful thallo:policy:manifest --export > policy.json
```

## Content

### thallo:resync

Re-drive the publishing pipeline for published content: the published-reference projection, the
cache tags, and the search index. **Writes.** Use it after a crash dropped the in-process effects
of a publish, leaving an entry published in the database but stale everywhere downstream.

| Option | What it does |
|---|---|
| `--entry=ENTRY` | Resync a single published entry by uuid |
| `--type=TYPE` | Resync every published entry of a type slug |
| `--webhooks` | Also re-dispatch webhooks (opt-in; off default) |

Pass one of `--entry` or `--type`, or neither to walk every published entry of every content type.
Drafts and archived entries are never touched. Webhooks stay silent unless you ask for them,
because re-firing them delivers duplicates to every subscriber. Every effect is idempotent, so the
command is safe to run again.

```bash
$ php glueful thallo:resync --type=post
```

[Publishing](../concepts/05-publishing.md) describes the pipeline this re-drives.

### thallo:schedules:run

Fire due scheduled publish and unpublish actions through the normal publish path. **Writes.**

| Option | What it does |
|---|---|
| `--limit=LIMIT` | Max schedules to fire this run [default: `"100"`] |

The scheduler's cron line already runs this work; the command is the manual path. See
[the scheduler and the queue](../operations/03-scheduler-and-queues.md).

```bash
$ php glueful thallo:schedules:run --limit=500
```

### thallo:versions:prune

Delete out-of-policy, non-pinned version history. **Writes**, and the deletion is permanent:
export first.

| Option | What it does |
|---|---|
| `--dry-run` | Report what would be deleted and delete nothing |
| `--keep=KEEP` | Override: keep the N newest versions per lineage |
| `--max-age-days=MAX-AGE-DAYS` | Override: delete versions older than D days |

It prunes per entry and locale. The pinned version of a lineage always survives. With no policy
configured — `VERSION_KEEP` and `VERSION_MAX_AGE_DAYS` — and no override on the command line, it
warns and prunes nothing.

```bash
$ php glueful thallo:versions:prune --dry-run --keep=10
```

### thallo:schema:backfill

Run or resume the backfill for a destructive content-type schema migration. **Writes.**

| Argument | What it is |
|---|---|
| `migration` | The migration uuid to run or resume |

```bash
$ php glueful thallo:schema:backfill <migration-uuid>
```

[The content model](../concepts/01-content-model.md) says when one of these is waiting.

### thallo:style-classes:run-job

Run or resume a detach-everywhere or remove-everywhere style class job. **Writes.**

| Argument | What it is |
|---|---|
| `job` | The style class job id to run or resume |

It prints the job's status, the number of passes, and how many documents it finished and failed.
A job that does not reach `completed` exits with a failure; fix the documents it names and queue
it again.

```bash
$ php glueful thallo:style-classes:run-job <job-id>
```

[Style classes](../guides/05-style-classes.md) is where these jobs come from.

## Blocks

### thallo:blocks:seed

Seed the starter block types, skipping any slug that already exists. **Writes.** Aliased as
`blocks:seed`.

| Option | What it does |
|---|---|
| `--tenant=TENANT` | Tenant uuid |
| `--all` | Seed all active tenants |

A slug that is already there is skipped whatever state it is in, so a rerun never overwrites an
edit. There is no `--force`. The two options matter only once workspaces are on, and then exactly
one of them is required.

```bash
$ php glueful thallo:blocks:seed
```

### thallo:blocks:sync

Additively add new starter block-type fields to existing rows; it never removes one. **Writes**
unless `--dry-run`. Aliased as `blocks:sync`.

| Option | What it does |
|---|---|
| `--dry-run` | Report what would change without writing |
| `--tenant=TENANT` | Tenant uuid |
| `--all` | Sync all active tenants |

`thallo:provision` runs the same sync; this command is the manual and multi-workspace path. A row
it cannot find is reported as `missing`, with the instruction to run `thallo:blocks:seed`.

```bash
$ php glueful thallo:blocks:sync --dry-run
```

### thallo:blocks:convert-settings

Convert legacy block presentation fields into typed settings. **Writes:** the dry run writes the
diagnostics report, and a live run converts every pending document.

| Option | What it does |
|---|---|
| `--dry-run` | Evaluate and write the diagnostics report only |
| `--report=REPORT` | Where to write the report (JSON lines) |
| `--decisions=DECISIONS` | The decisions file to consume |

The report defaults to `storage/conversion/report-` plus a UTC timestamp and `.jsonl`; the
decisions file defaults to `storage/conversion/decisions.json`. A live run refuses to start while
any diagnostic in the report is unresolved, and refuses altogether while a block type migration is
in progress. [Running Thallo in production](../production.md) has the cutover contract this
belongs to.

```bash
$ php glueful thallo:blocks:convert-settings --dry-run --report=storage/conversion/report.jsonl
```

### thallo:blocks:migration:backfill

Run or resume the backfill for a block-type schema migration. **Writes.**

| Argument | What it is |
|---|---|
| `migration` | The block migration uuid to run or resume |

A failed migration stays active, and the write gate on that block type stays closed, until this
converges it.

```bash
$ php glueful thallo:blocks:migration:backfill <migration-uuid>
```

## Documentation and Markdown imports

### thallo:docs:setup

Create the content type a documentation section needs, and let the site list it. **Writes**, once:
an existing type is never rewritten.

| Option | What it does |
|---|---|
| `--type=TYPE` | The content type slug, which is the URL [default: `"docs"`] |
| `--sections=SECTIONS` | Comma-separated sections, in sidebar order [default: `"getting-started,concepts,guides,reference,operations"`] |

The type's slug is the URL: `--type=handbook` puts the section at `/handbook`. If a type with that
slug already exists without the fields the section needs, the command names the missing fields and
stops.

```bash
$ php glueful thallo:docs:setup --type=handbook --sections=start,guides
```

### thallo:import:markdown

Import a folder of Markdown as the pages of a content type. **Writes** unless `--dry-run`.

| Argument | What it is |
|---|---|
| `folder` | The folder of Markdown files |

| Option | What it does |
|---|---|
| `--type=TYPE` | The content type to import into [default: `"docs"`] |
| `--locale=LOCALE` | The locale the pages are in [default: `"en"`] |
| `--publish` | Publish each page (otherwise: drafts) |
| `--dry-run` | Report what would change; write nothing |
| `--exclude=EXCLUDE` | A folder to leave out, by name or path (repeatable) (multiple values allowed) |
| `--edit-base=EDIT-BASE` | URL prefix for each page's "edit this page" link |
| `--actor=ACTOR` | User uuid the writes are attributed to |

It is meant to run on every deploy: a file lands on the page it made last time, only changed pages
are written, a changed slug leaves a redirect, and nothing is deleted.

```bash
$ php glueful thallo:import:markdown docs --type=docs --exclude=internal --publish
```

[A documentation section on your site](../documentation-sites.md) covers the front matter it reads
and the links it rewrites; [import content](../guides/12-import-content.md) covers the other
formats.

## Search

Both commands exist only while the **Search** capability is on.

### search:reindex

Backfill the search index from published content. **Writes** to the index.

| Option | What it does |
|---|---|
| `--type=TYPE` | Limit to a content-type slug |
| `--locale=LOCALE` | Limit to a locale |

It makes sure the index exists, pages through published records and upserts them, then prints how
many documents it indexed. If the engine is unreachable it stops and points at `search:status`.

```bash
$ php glueful search:reindex --type=post
```

### search:status

Report search backend health and configuration warnings. **Reads only.**

It prints the engine that answers, whether the backend is reachable, and one warning line per
configured type whose fields do not line up with its content type. An unreachable backend fails
the command.

```bash
$ php glueful search:status
```

[Add search to the site](../guides/11-search.md) explains the two engines.

## Workspaces

These commands drive multi-tenancy. The admin has the same steps under **Settings › Workspaces**
and **Workspaces**; [turn on workspaces](../operations/07-multi-site.md) is the runbook, and
[workspaces](../concepts/08-workspaces.md) explains what the stages change. The ones that report
state or manage a workspace, a member or a domain answer with JSON.

### thallo:tenancy:status

Show multi-tenancy enablement status. **Reads only.** It warns when a step is waiting for a fresh
process, or when an interrupted enablement still has to be finished.

```bash
$ php glueful thallo:tenancy:status
```

### thallo:tenancy:enable

Advance the multi-tenancy enablement flow. **Writes.**

| Option | What it does |
|---|---|
| `--slug=SLUG` | First tenant slug |
| `--name=NAME` | First tenant name |
| `--owner=OWNER` | Owner user UUID |

The flow is staged: each run advances it by one step and prints where it stopped. When it reaches
the confirmation step, run it again with all three options to name the first workspace and adopt
the existing content into it. Two of the steps need a fresh process, and the command says so.

```bash
$ php glueful thallo:tenancy:enable --slug=acme --name="Acme" --owner=<user-uuid>
```

### thallo:tenancy:disable

Disable tenant scoping without narrowing the schema. **Writes.** The widened schema stays, so
nothing is lost and enabling again is quick.

```bash
$ php glueful thallo:tenancy:disable
```

### thallo:tenancy:diagnose

Run read-only tenancy coherence checks — registered tables, schema drift, membership integrity.
**Reads only.** It prints one line per section with its status, and fails if any section is not
OK.

```bash
$ php glueful thallo:tenancy:diagnose
```

### thallo:tenancy:tenant

Manage workspaces. **Writes**, except for `list`.

| Argument | What it is |
|---|---|
| `action` | `create`, `list`, `suspend`, `reactivate` |

| Option | What it does |
|---|---|
| `--uuid=UUID` | Tenant uuid |
| `--slug=SLUG` | Tenant slug |
| `--name=NAME` | Tenant name |
| `--owner=OWNER` | Owner user uuid |
| `--status=STATUS` | Filter status |

`create` needs `--slug`, `--name` and `--owner`, and seeds the new workspace's starter surface
before activating it. `suspend` and `reactivate` need `--uuid`. `list` takes an optional
`--status`.

```bash
$ php glueful thallo:tenancy:tenant create --slug=acme --name="Acme" --owner=<user-uuid>
```

### thallo:tenancy:member

Manage workspace memberships. **Writes**, except for `list`.

| Argument | What it is |
|---|---|
| `action` | `add`, `set-role`, `remove`, `list` |

| Option | What it does |
|---|---|
| `--tenant=TENANT` | Tenant uuid |
| `--user=USER` | User uuid |
| `--role=ROLE` | Membership role |

Every action needs `--tenant`. `add` and `set-role` need `--user` and `--role`; `remove` needs
`--user`.

```bash
$ php glueful thallo:tenancy:member add --tenant=<tenant-uuid> --user=<user-uuid> --role=admin
```

### thallo:tenancy:domain

Manage workspace domains. **Writes**, except for `list`. Every action that changes a domain also
purges the host cache for its workspace.

| Argument | What it is |
|---|---|
| `action` | `add`, `verify`, `list`, `enable`, `disable`, `remove` |

| Option | What it does |
|---|---|
| `--tenant=TENANT` | Tenant uuid |
| `--domain=DOMAIN` | Domain uuid |
| `--host=HOST` | Domain host |

`add` needs `--tenant` and `--host`, and answers with the new domain's uuid and its verification
token. `list` needs `--tenant`. `verify`, `enable`, `disable` and `remove` need `--domain`;
`verify` prints the resulting status.

```bash
$ php glueful thallo:tenancy:domain add --tenant=<tenant-uuid> --host=www.example.com
```

### thallo:tenancy:resolution:status

Show full-resolution status: whether a public request is matched to a workspace by its host.
**Reads only.**

```bash
$ php glueful thallo:tenancy:resolution:status
```

### thallo:tenancy:resolution:activate

Advance full tenant resolution. **Writes.** Like enablement it is staged, and one step needs a
fresh process before the next run finishes it.

```bash
$ php glueful thallo:tenancy:resolution:activate
```

### thallo:tenancy:resolution:deactivate

Return to bootstrap tenant resolution, where the install serves one workspace. **Writes.**

```bash
$ php glueful thallo:tenancy:resolution:deactivate
```

### thallo:tenancy:hosts:sweep

Queue an expired host-cooldown sweep, which releases hosts whose cooldown has run out. **Writes:**
it pushes one job onto the `tenancy-maintenance` queue and prints the job's uuid. A worker has to
be running for anything to happen.

```bash
$ php glueful thallo:tenancy:hosts:sweep
```

### thallo:tenancy:purge:recover

Redispatch recoverable workspace purge runs — purges that were interrupted before they finished.
**Writes.** It prints how many it dispatched.

```bash
$ php glueful thallo:tenancy:purge:recover
```

### thallo:tenancy:single-store:repair

Establish or repair the workspace identity a single-store installation uses. **Writes.**

| Option | What it does |
|---|---|
| `--owner=OWNER` | Owner user uuid |
| `--slug=SLUG` | Tenant slug [default: `"default"`] |
| `--name=NAME` | Tenant name [default: `"Default"`] |

`--owner` is required and must name a user that exists.

```bash
$ php glueful thallo:tenancy:single-store:repair --owner=<user-uuid>
```

### thallo:tenant:seed

Repair and activate a workspace still stuck in provisioning, by completing its starter surface.
**Writes.**

| Argument | What it is |
|---|---|
| `uuid` | Tenant uuid |

```bash
$ php glueful thallo:tenant:seed <tenant-uuid>
```

### thallo:tenant:sync

Synchronize starter definitions for workspaces. **Writes.**

| Argument | What it is |
|---|---|
| `uuid` | Tenant uuid |

| Option | What it does |
|---|---|
| `--all` | Synchronize all active tenants |
| `--kind=KIND` | Limit synchronization to one kind |

Supply exactly one of the `uuid` argument or `--all`. The kinds are `content_type`, `block_type`,
`region`, `navigation_menu`, `setting` and `entry`. The JSON it prints names each definition's
kind, its source id and what happened to it — `added`, `updated`, `renamed`, `unchanged`, or a
`skipped_` reason such as the workspace having customised it.

```bash
$ php glueful thallo:tenant:sync --all --kind=content_type
```

### thallo:tenant:blocks:sync

Synchronize starter block definitions for workspaces: `thallo:tenant:sync` with the kind fixed to
`block_type`. **Writes.**

| Argument | What it is |
|---|---|
| `uuid` | Tenant uuid |

| Option | What it does |
|---|---|
| `--all` | Synchronize all active tenants |

```bash
$ php glueful thallo:tenant:blocks:sync --all
```

## Commerce

### thallo:commerce:diagnose

Run read-only Commerce integration diagnostics. **Reads only.** One line per section with its
status and detail; the command fails if any section is not OK. It checks Thallo's integration
layer, not the Commerce extension itself — that is `commerce:diagnose`.

```bash
$ php glueful thallo:commerce:diagnose
```

### thallo:commerce:links:reconcile

Remove product-to-entry links whose product is tombstoned or absent, or whose entry is gone.
**Writes.** Healthy links are untouched.

| Option | What it does |
|---|---|
| `--tenant=TENANT` | Limit the sweep to a single tenant uuid |

One run is capped by `thallo-commerce.reconcile.batch_size` across every workspace it processes,
so run it again to continue past the cap. With Commerce inactive it warns and does nothing.

```bash
$ php glueful thallo:commerce:links:reconcile
```

### thallo:commerce:checkout:purge-attempts

Delete checkout-attempt ledger rows older than the guest-confirmation retention window.
**Writes.**

| Option | What it does |
|---|---|
| `--tenant=TENANT` | Limit the purge to a single tenant uuid |
| `--days=DAYS` | Override the retention window in days (default: `thallo-commerce.guest_confirmation_days`, clamped 1-90) |

```bash
$ php glueful thallo:commerce:checkout:purge-attempts --days=30
```

[Sell something](../guides/18-commerce.md) covers the storefront these serve.

## Rendering, themes and housekeeping

### render:cache:clear

Clear the rendered page cache — every `render:*` key. **Writes.** It is available whether or not
the rendering capability is on, so stale pages can be cleared after switching it off.

```bash
$ php glueful render:cache:clear
```

### render:theme:clone

Clone a theme into a new `themes/{name}` directory. **Writes** files into `themes/`.

| Argument | What it is |
|---|---|
| `name` | The new theme name (lowercase, dashes/underscores) |

| Option | What it does |
|---|---|
| `--from=FROM` | Source theme to copy [default: `"default"`] |

```bash
$ php glueful render:theme:clone my-theme
```

[Make a theme](../guides/13-make-a-theme.md) starts here.

### analytics:prune

Delete raw analytics facts past the retention window. **Writes.** No scheduled job runs it, so put
it on cron yourself if you want the table kept down.

```bash
$ php glueful analytics:prune
```

## Framework commands a site owner uses

These come from the Glueful framework, not from Thallo. `php glueful <command> --help` prints the
options for each.

| Command | What it does |
|---|---|
| `migrate:create` | Create a new database migration file |
| `migrate:rollback` | Rollback database migrations |
| `migrate:run` | Run pending database migrations |
| `migrate:status` | Show the status of database migrations |
| `migrate:verify` | Classify descriptor schema state (ready/adoptable/divergent); `--adopt` writes verified receipts |
| `queue:scheduler` | Advanced job scheduling and management system |
| `queue:work` | Start a queue worker to process jobs |
| `queue:failed` | List failed queue jobs (`--queue=`, `--limit=`, `--json`) |
| `queue:retry` | Put failed jobs back on their queue (`<uuid>…` or `--all`, optional `--queue=`) |
| `queue:forget` | Delete one failed job |
| `queue:flush` | Delete all failed jobs, or one queue's with `--queue=` |
| `webhook:cleanup` | Delete webhook delivery records past their retention |
| `cache:clear` | Clear application cache (aliased `cache:flush`) |
| `cache:delete` | Delete cached entries by key or pattern |
| `cache:expire` | Set new TTL/expiration for cached items |
| `cache:get` | Get a cached value by key |
| `cache:inspect` | Inspect cache driver and extension status |
| `cache:maintenance` | Run or queue cache maintenance tasks |
| `cache:set` | Set a cache value with key and optional TTL |
| `cache:status` | Show cache system status and statistics |
| `cache:ttl` | Get TTL (Time To Live) for cached items |
| `extensions:cache` | Build extensions cache for production with verification |
| `extensions:clear` | Clear extensions cache with optional development reset |
| `extensions:diagnose` | Diagnose extension discovery and configuration issues |
| `extensions:disable` | Disable extension (schema and data are preserved) |
| `extensions:enable` | Enable extension (migrates its schema first) |
| `extensions:info` | Show detailed extension information |
| `extensions:list` | List discovered extensions with comprehensive status |
| `extensions:summary` | Show startup summary and diagnostics |
| `generate:client` | Generate an SDK client from `openapi.json` via an off-the-shelf OpenAPI generator |
| `generate:key` | Generate secure encryption keys for the framework |
| `generate:openapi` | Generate OpenAPI/Swagger documentation from database schema and route annotations |

`queue:work` and `queue:scheduler` are the two a live site depends on; see
[the scheduler and the queue](../operations/03-scheduler-and-queues.md) for how to run them.
`extensions:enable` and `extensions:disable` are covered by
[capabilities and packs](../concepts/06-capabilities.md), and `extensions:cache` is required in
production — [running Thallo in production](../production.md) says why.

## Check what your install has

The list above is what Thallo ships. What your install has registered is whatever
`php glueful list` prints, and that is the answer to trust: a command that is missing belongs to a
capability or an extension that is switched off.
