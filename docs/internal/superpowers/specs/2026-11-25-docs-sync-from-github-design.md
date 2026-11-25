# Docs sync from GitHub — design

Status: revision 4 (2026-11-25). Core, importers pack, tenancy pack (table registration) and admin.
No framework change.

Revision 4 changes how a sync runs. Revision 3 handed the work to the import-export extension's jobs
and coordinated with them from outside; every review found another gap there (retries that rerun an
old archive, reservations the original request could outlive, report rows capped at 1,000, outcomes
lost to the 30-day cleanup), because that job system has its own retries, cancellation, retention and
reporting, none of which know a sync exists. Now **one dedicated sync job owns a sync from start to
finish** and reuses the Markdown importer's reading and writing rules through per-page hooks (§8).
Its rules:

- a run holds the site's single slot; each execution attempt holds a lease with its own token (§6);
- every page is written in one transaction that first proves the lease, so a page can never land
  after the slot was taken over or released (§6.3);
- reserving and queueing are one transaction on the database queue, recovered otherwise; duplicate
  deliveries cannot both write (§6.1, §6.2);
- each page's result and the run's exact counts are written with that page, so progress survives a
  crash (§6.3, §7);
- cancelling stops after the current page and has its own outcome; so does an interrupted run (§7).

Unchanged from revision 3: the GitHub side and commit pinning (§5), source revisions (§4), the
empty-folder refusal and `within` (§8), the screen apart from its history (§9).

Syncs no longer appear in Settings › Import / Export's job list. The sync card keeps their history
(§9). The owner accepted this trade-off.

## 1. Why

A site with a documentation section keeps its pages in git as Markdown. Today a new release of
those docs reaches the site only by hand: clone the repo on the server, then run
`thallo:import:markdown` or zip the folder and upload it under Settings › Import / Export.
thallo.dev is the first case: its docs are the Thallo repo's `docs/`, which no Packagist package
ships, so a site update never brings them, and they fell several releases behind.

The owner sets the source up once (a public GitHub repo, a folder, what to follow) and from then
on one click, **Sync now**, brings the site's docs to the repo's. It is general-purpose: any
Thallo site whose docs live in a public GitHub repo, not only thallo.dev.

## 2. Goals, non-goals, anti-goals

Goals:

- A docs source saved per site: repo, folder, follow the latest release tag or a branch, and the
  import options the Markdown import already has (content type, publish, folders left out).
- **Sync now** brings the pages to the followed version; **Preview** reports what it would change
  and writes nothing. The card shows what each did, page by page.
- On opening the screen, a note says whether the repo has moved since the last sync ("v1.0.0-beta.110
  is out; you last synced v1.0.0-beta.109"). Reading only: nothing imports until Sync now.
- "Edit this page" links filled in from the source, with nothing to type.
- An empty or wrong source is refused before anything is written (§8).

Non-goals (this version):

- Automatic or scheduled syncs, and webhooks. Both would later queue the same sync job (§6).
- Private repos, tokens, and hosts other than GitHub. The source is a public GitHub repo; this is a
  requirement, not a limitation to work around.
- Deleting or unpublishing pages whose file is gone. They are reported, as today.
- Several sources per site. One source per site (per workspace with workspaces on).

Anti-goals (a failure even if it works):

- A sync that marks every page "gone", or writes anything, because the folder, the tag or the
  download was wrong. The run on thallo.dev that reported 53 pages gone from an empty folder must
  not be repeatable through this feature.
- Markdown from outside the chosen folder becoming pages (the repo's READMEs, a package's
  CHANGELOG).
- A sync that duplicates pages already imported by hand: a page must be found again by the same
  path the folder import gave it.
- Two executions writing pages for one site at once, by any route: two presses, a duplicate queue
  delivery, a stalled worker that wakes, a retry.
- A sync recorded as landed when it did not, or counts that differ from what was written.
- The site fetching from anywhere but GitHub, or anything but the two GitHub hosts named in §5.

## 3. Approaches

How the site gets the docs (revision 1, owner's choice):

- **A. GitHub repo + folder (chosen).** The site asks GitHub's API which commit the latest release
  tag or the branch tip is, downloads that commit's zip archive, keeps the folder and imports it.
  A few small API requests per check; one archive per sync. Follows releases. GitHub only.
- **B. Any archive URL.** Host-agnostic, but cannot follow "the latest release" by itself, cannot
  tell whether anything changed without downloading the archive, and cannot fill in edit links.
- Ruled out: fetching each file through GitHub's contents API (one request per page; the anonymous
  limit is 60 an hour per server), and `git clone` on the server (many hosts have no git).

Manual sync only: nothing runs unattended in this version (owner's choice).

How a sync executes (revision 4, owner's choice):

- **A dedicated sync job (chosen).** One queued job owns the run: download, read, write each page,
  record the outcome. Reuses the importer through per-page hooks. Its own history on the card.
- **Import-export jobs with fences around them (revisions 2–3).** Reuses the job list and its
  report, but its retries, cancellation, retention and report storage act without knowing about
  the sync, and each needed its own fence.

## 4. State

Three tables, after the palette's state-row-and-jobs pattern (`palette_state` / `palette_jobs`,
migration 043). All are registered in `ThalloTenantTables` as definition tables, so with workspaces
on each workspace has its own source and runs, like its content; the state row's site unique widens
to `(tenant_uuid, site)` as `palette_state`'s does.

**`docs_sync_state`**, one row per site (the constant `site` under a unique index):

| Column | Meaning |
|---|---|
| `source` | JSON: the source (below), or null when none is saved. |
| `revision` | Integer, bumped on every save or removal of the source. |
| `active_run` | The run holding the site's single slot, or null. |
| `lease_token` | The token of the execution attempt working on `active_run`, or null (§6.2). |
| `lease_at` | When that attempt last proved its lease. |
| `synced_run` | The last run that landed under the current revision, or null. |
| `updated_at` | |

The source:

| Key | Meaning |
|---|---|
| `repo` | `owner/name`, e.g. `glueful/thallo`. Must match `^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$`. |
| `folder` | The folder in the repo, e.g. `docs`. Relative, no `..`, no leading `/`; empty means the repo root. |
| `follow` | `release` or `branch`. |
| `branch` | With `branch`: the branch name. With `release`: unused. |
| `prereleases` | With `release`: whether pre-release tags count (`v1.0.0-beta.N`). On by default, because thallo.dev follows betas. |
| `content_type`, `publish`, `exclude`, `locale` | The Markdown import's own options. |
| `edit_branch` | The branch "Edit this page" links point at: with `branch`, that branch; with `release`, the repo's default branch, read from GitHub when the source is saved (a tag cannot be edited). |

**`docs_sync_runs`**, one row per Sync now or Preview:

| Column | Meaning |
|---|---|
| `id` | 12-character id. |
| `revision`, `source` | The source revision the run was started under, and a copy of the source then. The run uses only the copy. |
| `mode` | `commit` or `dry_run`. |
| `ref`, `sha` | What was followed (tag or branch name) and the commit it resolved to (§5). |
| `status` | `reserved` → `dispatched` → `running` → `finished` (§6). |
| `outcome` | Set when `finished` (§7). |
| `created`, `updated`, `unchanged`, `skipped`, `failed`, `gone` | Exact counters, incremented in each page's transaction (§6.3). |
| `attempts` | Execution attempts so far. |
| `cancel_requested` | Set by Cancel (§6.5). |
| `message` | Why a run was refused, failed or interrupted, in the owner's words. |
| `created_by`, `created_at`, `started_at`, `finished_at` | |

**`docs_sync_run_pages`**, one row per page a run handled: `run_id`, `path`, `slug`, `status`
(`created`, `updated`, `unchanged`, `skipped`, `failed`, `gone`, and with `dry_run` what it would
be), `message`, `broken_links` (JSON), `entry_uuid`. Unique on `(run_id, path)`. At most one row per
page and at most 2,000 pages (`MarkdownZip::MAX_FILES`), so the report is complete, never capped.

Runs and their pages are pruned after `import_export.retention_days` (30), except a site's
`synced_run`. Nothing else deletes them, so the card's history and counts do not depend on the
import-export cleanup.

**Saving and removing the source.** Saving checks the source against GitHub once (§5): the repo
exists and is public; with `release`, at least one version tag exists; with `branch`, the branch
exists. A source that fails the check is not saved, and the message says which part was wrong. A
successful save writes `source`, bumps `revision` and clears `synced_run` in one statement guarded by
`active_run IS NULL`: **while a run holds the slot, the source cannot be edited or removed**, and the
admin says a sync is running. Removing the source is the same statement with a null source; pages
already imported stay either way.

## 5. Talking to GitHub

Anonymous HTTPS through Symfony's HTTP client (already a dependency, as in
`PackagistReleaseFeed`), injected so tests use `MockHttpClient`. Only two hosts, fixed in code:
`api.github.com` and `codeload.github.com`. The repo, tag, branch and SHA only ever fill path
segments, URL-encoded; redirects are not followed off those hosts.

- **Repo:** `GET https://api.github.com/repos/{repo}` → `private` must be false;
  `default_branch` for edit links.
- **Latest release:** `GET https://api.github.com/repos/{repo}/git/matching-refs/tags/` (following
  `Link: rel="next"` pages) → every tag. Names are parsed with Composer's semver parser (the update
  check already uses `composer/semver`); names that are not versions are ignored; pre-releases kept
  or dropped per `prereleases`; the highest wins. **Not GitHub Releases**: `glueful/thallo` stopped
  creating Release pages at beta.21 and only pushes tags, so `releases/latest` answers beta.21.
- **The tag's commit.** A ref's `object` is a commit (a lightweight tag) or a tag object (an
  annotated tag; Thallo's are annotated). A tag object is followed with
  `GET https://api.github.com/repos/{repo}/git/tags/{sha}` until a commit, at most five steps.
- **Branch tip:** `GET https://api.github.com/repos/{repo}/commits/{branch}` with
  `Accept: application/vnd.github.sha` → the commit SHA as text.
- **Archive, always by commit:** `GET https://codeload.github.com/{repo}/zip/{sha}`. GitHub states a
  tag can move and its archive change; downloading by the resolved SHA means the archive is exactly
  the commit recorded on the run. GitHub's archives honour the repo's `export-ignore`, so Thallo's
  arrives without `docs/internal` and `admin/`; 13 MB today.

**Freshness compares commits.** The note says the site is up to date only when the followed
version's commit equals `synced_run.sha`. A tag moved to another commit after a sync reads as
"v1.0.0-beta.110 has changed since your sync".

Limits: timeouts of 8 s for API requests and 60 s for the archive; the archive is streamed to the
`uploads` disk at `docs-sync/{run}.zip` and refused past `import_export.max_file_size` (50 MB),
counted as it arrives. GitHub's anonymous limit is 60 requests an hour per server IP: the status
note is cached for five minutes per site, and a `403`/`429` with `X-RateLimit-Remaining: 0` says
"GitHub's hourly limit is reached; try again after HH:MM" from `X-RateLimit-Reset`.

## 6. A run

`POST /v1/admin/docs-sync/run` with `mode` `commit` (Sync now) or `dry_run` (Preview), validated to
one of the two. One service starts runs, so a scheduled task or webhook later calls the same thing.
The work happens in `DocsSyncJob`, queued on the `import-export` queue (`import_export.queue`), which
the documented worker command already takes.

### 6.1 Start: reserve and dispatch

In one transaction:

1. Insert the run (`status: reserved`, the current `revision`, a copy of `source`, `mode`).
2. Take the slot: `UPDATE docs_sync_state SET active_run = :run WHERE site = 'site' AND revision =
   :revision AND active_run IS NULL`. No affected row: refused (another run holds the slot, or the
   source just changed), the transaction rolls back, nothing else happens.
3. **With the `database` queue driver on the application's connection** (Thallo's default), push
   `DocsSyncJob {run}` and set `status: dispatched` inside the same transaction, so the run is either
   reserved and queued or neither.

**With another driver** (Redis), the push happens after commit, then `status: dispatched` is set.
A request that dies between commit and push leaves a `reserved` run holding the slot; whenever the
state is read (§9) or a start is refused because of it, a run still `reserved` after two minutes is
pushed again and marked `dispatched`. Pushing twice is safe (§6.2).

The request answers with the run straight away: resolving, downloading and writing happen in the job,
so the web request's time limit plays no part.

### 6.2 An execution attempt and its lease

Each delivery of `DocsSyncJob` is an execution attempt. It starts by claiming the lease with a new
random token, in one statement:

`UPDATE docs_sync_state SET lease_token = :token, lease_at = now WHERE site = 'site' AND active_run =
:run AND (lease_token IS NULL OR lease_at < now - 120 s)`

- **Claimed:** this attempt owns the run. `status: running`, `attempts + 1`, `started_at` if unset.
- **Run already finished, or no longer holding the slot:** the delivery is acknowledged and does
  nothing (a duplicate, or a run that was released).
- **Another attempt's lease is live:** the delivery re-queues itself with a 60-second delay instead
  of exiting, so a run never depends on a single message surviving. After the live attempt finishes,
  the re-queued delivery sees a finished run and stops.

An attempt proves its lease every time it writes (§6.3) and at least every 30 seconds while it
resolves or downloads, with `UPDATE … SET lease_at = now WHERE active_run = :run AND lease_token =
:token`. An attempt that finds no affected row has been fenced out: it stops at once and writes
nothing more. **A lease older than 120 seconds only allows a takeover to be attempted; it does not
prove the old attempt stopped.** The page transaction (§6.3) is what makes a takeover safe.

### 6.3 The work, page by page

An attempt:

1. **Resolves** the followed version to a commit (§5) on the first attempt and stores `ref` and
   `sha`; later attempts reuse them. With `release` it is the newest tag at that moment.
2. **Downloads** the archive at `sha` to `docs-sync/{run}.zip`, unless an earlier attempt left it.
3. **Reads** every page through the importer's read step (§8): the folder `within` the archive,
   front matter, slugs, and the links between pages, which need every page. A folder with no pages,
   or a GitHub refusal, finishes the run as `refused` (§7) before anything is written.
4. **Writes each page** not yet recorded for this run (`docs_sync_run_pages` has no row for its
   path), one transaction per page:
   1. prove the lease: `UPDATE docs_sync_state SET lease_at = now WHERE site = 'site' AND
      active_run = :run AND lease_token = :token`; no affected row: roll back and stop (fenced out);
   2. if the run's `cancel_requested` is set: roll back and finish as `cancelled` (§6.5);
   3. the importer's write step for this page (§8), or with `dry_run` its comparison only;
   4. insert the page's `docs_sync_run_pages` row and increment the run's counter for its status;
   5. commit.

   The lease update is the transaction's first write, so it locks the state row (a row lock on
   PostgreSQL and MySQL; SQLite's write lock is the whole database) until the page commits. A
   takeover, a Release or a Cancel that finishes the run all update that same row, so they wait for
   the page in flight to commit, and the old attempt's next page then fails step 4.1. **A page can
   never land after its slot was reassigned, and a page is never half-written.** The importer's own
   transactions nest inside as savepoints, which the framework supports; work it schedules for after
   commit (events, search indexing) waits for this transaction, so a page rolled back leaves nothing.
5. **Finishes** in one transaction, which proves the lease like 4.1: the pages a previous import
   made from files no longer in the folder are recorded as `gone` rows; the run's outcome is set
   (§7); for a `commit` run that landed, `synced_run` is set if the run's `revision` equals the
   state's and the current `synced_run` is null or older; the slot and lease are released
   (`active_run`, `lease_token` null); `status: finished`. The archive file is then deleted.

### 6.4 Interrupted attempts and time limits

The worker's time limit (60 s unless the site raises it) and crashes still apply. An attempt killed
mid-page rolls that page back with its transaction; the pages before it are committed with their
rows and counts. The queue delivers the job again (the worker's retry), or §6.2's re-queue does: the
next attempt takes over once the lease is 120 s old and continues with the pages not yet recorded,
so a large folder finishes over several attempts. After five attempts without finishing, the run is
finished as `interrupted`, keeping what it wrote. A synchronous side effect of a page that rolled
back (a cache purge, say) may already have happened; it is harmless, since the page will be written
again.

### 6.5 Cancel and Release

- **Cancel** (`POST /v1/admin/docs-sync/run/{run}/cancel`) sets `cancel_requested`. A running attempt
  sees it before its next page (§6.3, 4.2), keeps the slot until the page in flight commits, then
  finishes the run as `cancelled`. A run with no live lease (still reserved or dispatched, or its
  attempt died) is finished as `cancelled` straight away, by the same compare-and-set as Release.
- **Release** (`POST …/release`) is offered when a run's lease is older than 120 s or it was never
  claimed. It finishes the run as `interrupted` with `UPDATE docs_sync_state SET active_run = NULL,
  lease_token = NULL WHERE site = 'site' AND active_run = :run AND (lease_token IS NULL OR lease_at <
  now - 120 s)`. It waits for any page in flight, and refuses with "the sync is still working" if the
  lease turns out to be live. An old attempt that wakes afterwards is fenced out at its next write.

**Permissions:** what a manual zip import already needs, both of them: `content.manage` (the upload
route's) and `import_export.run_import`. That pair covers starting, cancelling and releasing runs,
and saving and removing the source (`PUT` / `DELETE /v1/admin/docs-sync/source`). Reading the card
and a run's pages (`GET /v1/admin/docs-sync`, `GET /v1/admin/docs-sync/runs/{run}`) needs
`import_export.view`. The routes sit in the zip upload's route group, so workspace binding is the same.

## 7. Outcomes

Decided when the run finishes, from its exact counters:

| Outcome | When | Card says | Becomes `synced_run`? |
|---|---|---|---|
| `success` | Every page written or unchanged; none failed. | "Up to date" (if the commit is current). | Yes |
| `partial` | Some pages failed, at least one created, updated or unchanged. | "Synced with errors: 2 pages failed". | Yes |
| `refused` | The folder holds no pages, or GitHub refused (private, missing tag or branch), before any write. | "Sync refused: the folder `doc` holds no pages". | No |
| `failed` | Every page failed, or the run could not continue (download error, archive refused). | "Sync failed: …". | No |
| `cancelled` | The owner cancelled; pages written before stay. | "Cancelled after 12 of 53 pages". | No |
| `interrupted` | Released, or five attempts without finishing; pages written before stay. | "Interrupted after 30 of 53 pages: …". | No |

A Preview (`dry_run`) run has the same outcomes but never becomes `synced_run`; the card shows it as
the last preview, with what would change.

## 8. Changes to the importer

- **`MarkdownFolderImport` exposes its two steps.** A read step takes a folder and options and
  returns every page resolved (front matter, slug, section, order, the links rewritten against the
  other pages, broken links), and the list of pages a previous import made from files no longer
  there. A write step takes one resolved page and writes it, or with `dry_run` only compares it, with
  the same find-by-URL-then-by-`source_path`, change detection, validation and publish rules as today,
  and returns its status. `run()` keeps its behaviour and its report by calling the two steps, so the
  command line and the zip upload are unchanged. The sync job calls the steps itself, one page per
  transaction (§6.3). No rule is copied.
- **An empty folder is refused** by the read step: a folder with no Markdown files at all throws, so
  neither a mistyped path on the command line nor a sync can report every page gone. The zip
  importer's planning already refuses an empty archive. This supersedes the separate "Markdown
  import: refuse a folder with no pages" proposal.
- **`MarkdownZip` gains `within`.** GitHub's archive holds one wrapping folder (`thallo-<sha>/`)
  around the whole repo. With `within: docs`, only entries under `<wrapper>/docs/` are extracted, and
  their path is relative to `docs/`. A page therefore has the path the folder import gives it from
  a checkout's `docs/`, so pages imported earlier by hand are found again by `source_path`, not
  duplicated. Markdown elsewhere in the repo is never read. Zip-slip, size and count rules are
  unchanged and apply to what `within` selects.
- **Gone pages are reported, not removed**, as today.

## 9. The screen

Settings › Import / Export gains a **Documentation from GitHub** card above the import form. With no
source saved it shows the form; once saved, the summary:

```
Documentation from GitHub
glueful/thallo · docs/ · latest release (pre-releases included) → Docs, published
Last synced v1.0.0-beta.109 (a1b2c3d) · 25 Nov 2026, 14:02 · by Michael · 3 updated, 50 unchanged, 1 gone
v1.0.0-beta.110 is out.                                   [Preview]  [Sync now]   Edit source

History
  25 Nov 14:02  Sync     v1.0.0-beta.109  Up to date        3 updated · 50 unchanged · 1 gone   [pages]
  25 Nov 13:58  Preview  v1.0.0-beta.109  Would change 3                                         [pages]
  19 Nov 10:11  Sync     v1.0.0-beta.108  Synced with errors: 1 page failed                      [pages]
```

- The note's states: up to date; a newer version is out (release) or the branch has new commits
  (branch, with the short SHA); the followed tag has moved since the sync; not synced from this
  source yet; GitHub unreachable or rate-limited.
- **History** lists the recent runs, newest first, with their outcome and counts. **[pages]** opens
  the run's page rows: each file, what happened to it, why a page failed, its broken links, and the
  pages gone.
- While a run holds the slot: the card shows it running ("Writing page 30 of 53"), polled from the
  run's counters; Sync now, Preview and Edit source are disabled; **Cancel** is offered; **Release**
  appears when the lease is older than 120 s or the run was never claimed. A run still `reserved`
  after two minutes is re-dispatched on this read (§6.1).
- The form: repo, folder, follow (latest release / branch), branch, pre-releases, content type
  (with **Set up documentation** when there is none, as the zip import offers), publish, folders to
  leave out. Saving runs the check in §4.
- **Remove source** clears it (§4); pages already imported stay.

## 10. Docs and changelog

- `docs/documentation-sites.md` gains a section "From a GitHub repo": set it up, press Sync now or
  Preview, what it reads and refuses, what each outcome means, that it needs a worker taking the
  `import-export` queue. The command-line and zip paths stay as they are.
- CHANGELOG `[Unreleased]` bullets: the feature (Added) and the empty-folder refusal (Fixed).

## 11. Testing

GitHub client, with `MockHttpClient`:

- Tag selection: semver order, pre-releases on and off, non-version tags ignored, pagination.
- Annotated and lightweight tags both resolve to the commit; a chain longer than five steps is refused.
- Branch SHA; private repo refused; missing repo, tag or branch; the rate-limit message.
- The archive is requested by SHA, never by tag; over the size cap it is refused mid-stream.
- No request goes to any other host.

Start and dispatch:

- Two simultaneous starts: exactly one takes the slot; the other is refused and queues nothing.
- A start after the source changed between the read and the reservation is refused.
- Database queue: a failure after the slot is taken and before commit leaves no run, no slot and no
  queued job.
- Other driver: a run reserved but never pushed is re-dispatched by the next read after two minutes.
- Editing or removing the source while a run holds the slot is refused.

Leases and fencing (the page content is asserted, not only the records):

- Two deliveries of one run: one claims the lease; the other re-queues and, once the run finishes,
  does nothing. Each page is written once.
- **Pause attempt A inside a page, release or take over the run, start run B, resume A:** the release
  or takeover waits for A's page to commit; A's next page is fenced out; the pages hold B's content,
  and A wrote nothing after the takeover.
- A takeover after the lease expired while A was paused between pages: A's next write is refused.
- An attempt killed mid-page: that page is rolled back, no row or count for it; the next attempt
  writes it and the counters equal the page rows.
- Five attempts without finishing: `interrupted`, with the pages already written kept.

Cancel:

- Cancel while a page is being written: the page commits, no further page is written, the run is
  `cancelled` with exact counts, the slot is released after the page.
- Cancel of a run that was never claimed finishes it at once.

Revisions and outcomes:

- Outcomes from real runs: all pages written → `success`; some failed → `partial`; all failed →
  `failed`; empty folder → `refused` with nothing written; download error → `failed`.
- A run whose revision no longer matches the state's does not become `synced_run`.
- More than 1,000 pages: the counters and the page rows are exact.
- Runs and pages survive `import-export:cleanup`; pruning keeps `synced_run`.
- Freshness: a tag moved between resolving and downloading changes nothing for that run (the archive
  is the resolved SHA); a tag moved after a successful sync reads as "has changed since your sync".

Importer:

- `run()` through the two steps gives the same report as today for the command line and the zip
  upload (their existing tests stay green).
- The read step refuses an empty folder and writes nothing.
- `MarkdownZip` `within`: only the folder's pages, paths relative to it, matching the folder import's
  paths from a checkout (a hand import followed by a sync updates, never duplicates); zip-slip still
  refuses the whole archive.
- **Preview executes its job** and the test asserts no entry, draft, version or publication was
  written and `synced_run` is unchanged; Sync now, run the same way, writes them.

Admin and access:

- Each route refuses a user missing either permission of its pair.
- Admin (vitest): the card's states (each outcome, running with progress, Cancel, Release,
  rate-limited), the history and a run's pages, and the form's validation messages.
- One manual end-to-end against the real `glueful/thallo` on a local site before release.

## 12. Left to the builder

- Class and file names, where the GitHub client lives (core or the importers pack), and the exact
  API response shapes.
- The migration's number, indexes beyond those named, and the pruning task (a scheduled entry beside
  `import_export_cleanup`).
- The exact shape of the importer's two steps, as long as `run()` keeps its behaviour and no rule is
  duplicated.
- The card's exact wording and layout within the admin's existing patterns.
