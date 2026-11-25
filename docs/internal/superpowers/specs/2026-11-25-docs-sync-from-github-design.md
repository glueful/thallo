# Docs sync from GitHub — design

Status: revision 3 (2026-11-25). Core, importers pack, tenancy pack (table registration) and admin.
No framework change.

Revision 3 answers the design review:

- one run at a time is a database reservation taken before anything is downloaded, and a run can
  always be traced to its job (§6);
- every saved source is a numbered revision, and a run counts only for its own (§4, §6);
- a run has one of four outcomes, matching how the import marks its jobs (§7);
- a release is pinned to its commit, annotated tags included, and freshness compares commits (§5);
- a run's outcome is recorded when its job ends, not when someone opens the screen (§7);
- Preview runs in the mode asked for, and is proven to write nothing (§6, §11).

Revision 2 answered the builder check (what counts as synced, a source edited after a sync, where the
download runs, permissions). Revision 3 supersedes its `docs_sync.attempt` / `docs_sync.synced`
settings: both are now rows (§4).

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
- **Sync now** runs the existing Markdown import on that folder at the followed version, as an
  ordinary import job; **Preview** runs it as a dry run. The screen shows what each did.
- On opening the screen, a note says whether the repo has moved since the last sync ("v1.0.0-beta.110
  is out; you last synced v1.0.0-beta.109"). Reading only: nothing imports until Sync now.
- "Edit this page" links filled in from the source, with nothing to type.
- An empty or wrong source is refused before anything is written (§8).

Non-goals (this version):

- Automatic or scheduled syncs, and webhooks. Both would later call the same sync service (§6).
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
- Two syncs of one site writing at once, or a sync recorded as landed when it did not.
- The site fetching from anywhere but GitHub, or anything but the two GitHub hosts named in §5.

## 3. Approaches

Considered:

- **A. GitHub repo + folder (chosen).** The site asks GitHub's API which commit the latest release
  tag or the branch tip is, downloads that commit's zip archive, keeps the folder and runs the zip
  import. A few small API requests per check; one archive per sync. Follows releases. GitHub only.
- **B. Any archive URL.** Host-agnostic, but cannot follow "the latest release" by itself, cannot
  tell whether anything changed without downloading the archive, and cannot fill in edit links.
- Ruled out: fetching each file through GitHub's contents API (one request per page; the anonymous
  limit is 60 an hour per server), and `git clone` on the server (many hosts have no git).

The owner chose A, with **manual sync only**: nothing runs unattended in this version.

## 4. State: the source and its runs

Two tables, after the palette's state-row-and-jobs pattern (`palette_state` / `palette_jobs`,
migration 043). Both are registered in `ThalloTenantTables` as definition tables, so with workspaces
on each workspace has its own source and runs, like its content; the state row's site unique widens
to `(tenant_uuid, site)` as `palette_state`'s does.

**`docs_sync_state`**, one row per site (the constant `site` under a unique index). It holds:

| Column | Meaning |
|---|---|
| `source` | JSON: the source (below), or null when none is saved. |
| `revision` | Integer, bumped on every save or removal of the source. |
| `active_run` | The id of the run that holds the site's single run slot, or null. |
| `synced_run` | The id of the last run that landed for the current revision, or null. |
| `updated_at` | |

The source:

| Key | Meaning |
|---|---|
| `repo` | `owner/name`, e.g. `glueful/thallo`. Must match `^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$`. |
| `folder` | The folder in the repo, e.g. `docs`. Relative, no `..`, no leading `/`; empty means the repo root. |
| `follow` | `release` or `branch`. |
| `branch` | With `branch`: the branch name. With `release`: unused. |
| `prereleases` | With `release`: whether pre-release tags count (`v1.0.0-beta.N`). On by default, because thallo.dev follows betas. |
| `content_type`, `publish`, `exclude`, `locale` | The Markdown import's own options, as the zip import takes them. |
| `edit_branch` | The branch "Edit this page" links point at: with `branch`, that branch; with `release`, the repo's default branch, read from GitHub when the source is saved (a tag cannot be edited). |

**`docs_sync_runs`**, one row per Sync now or Preview:

| Column | Meaning |
|---|---|
| `id` | 12-character id. |
| `revision` | The source revision the run was started under. |
| `source` | JSON: a copy of the source as it was then. The run uses only this copy. |
| `mode` | `commit` or `dry_run`. |
| `ref` | What was followed: the tag name, or the branch name. |
| `sha` | The commit the archive was downloaded at. |
| `status` | `reserved` → `queued` → `finished`; or `abandoned` (§6). |
| `outcome` | Set when `finished`: `success`, `partial`, `refused` or `failed` (§7). |
| `counts` | JSON: created, updated, unchanged, skipped, failed, gone. Copied from the job at the end. |
| `message` | Why a run was refused or failed, in the owner's words. |
| `job_uuid` | The import job, once created. |
| `created_by`, `created_at`, `finished_at` | |

Runs are kept after the import job and its report are deleted by `import-export:cleanup` (30 days),
so the card can still say what the last sync did. They are pruned with the same retention, except
that a site's `synced_run` is never pruned.

**Saving and removing the source.** Saving checks the source against GitHub once (§5): the repo
exists and is public; with `release`, at least one version tag exists; with `branch`, the branch
exists. A source that fails the check is not saved, and the message says which part was wrong. A
successful save writes `source`, bumps `revision` and clears `synced_run` in one statement guarded by
`active_run IS NULL`: **while a run holds the slot, the source cannot be edited or removed**, and the
admin says a sync is running. Every save starts a new revision, so nothing synced under an older
one is shown as current. Removing the source is the same statement with a null source; pages already
imported stay either way.

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
  The result is the commit SHA.
- **Branch tip:** `GET https://api.github.com/repos/{repo}/commits/{branch}` with
  `Accept: application/vnd.github.sha` → the commit SHA as text.
- **Archive, always by commit:** `GET https://codeload.github.com/{repo}/zip/{sha}`. GitHub states a
  tag can move and its archive change; downloading by the SHA just resolved means the archive is
  exactly the commit recorded on the run. A tag moved after resolving is simply not seen by this run.
  GitHub's archives honour the repo's `export-ignore`, so Thallo's arrives without `docs/internal`
  and `admin/`; 13 MB today.

**Freshness compares commits.** The note says the site is up to date only when the followed
version's commit equals `synced_run.sha`. A tag moved to another commit after a sync reads as
"v1.0.0-beta.110 has changed since your sync", and Sync now brings it in.

Limits: timeouts of 8 s for API requests and 60 s for the archive; the archive is streamed to the
`uploads` disk (where uploaded imports go) and refused past `import_export.max_file_size` (50 MB),
counted as it arrives. GitHub's anonymous limit is 60 requests an hour per server IP: the status
note is cached for five minutes per site, and a `403`/`429` with `X-RateLimit-Remaining: 0` says
"GitHub's hourly limit is reached; try again after HH:MM" from `X-RateLimit-Reset`.

## 6. Running a sync

`POST /v1/admin/docs-sync/run` with `mode` `commit` (Sync now) or `dry_run` (Preview), validated to
one of the two. One service does the work, so a later scheduled task or webhook calls the same thing.

1. **Reserve.** Insert a `docs_sync_runs` row (`status: reserved`, the current `revision` and a copy
   of `source`, `mode`), then take the site's slot with one compare-and-set:
   `UPDATE docs_sync_state SET active_run = :id WHERE site = 'site' AND revision = :revision AND
   active_run IS NULL`. One affected row means this run holds the slot; none means another run holds
   it or the source just changed, and the request is refused with which. Plain SQL, the same on
   SQLite, MySQL and PostgreSQL; simultaneous presses cannot both win. Nothing is downloaded before
   the slot is held.
2. **Resolve** the followed version to a commit (§5) and store `ref` and `sha` on the run. With
   `release` it is the newest tag at this moment, not necessarily the one the note showed.
3. **Download** the archive at `sha` (§5).
4. **Create the import job** with the existing `markdown.folder` adapter (`MarkdownZipImporter`)
   through `ImportExportService::createImport`, in the run's `mode`, with the run's copy of the
   source's options plus three more: `within` (the folder, §8), `edit_base`
   (`https://github.com/{repo}/edit/{edit_branch}/{folder}`) and `docs_sync_run` (the run's id).
   Planning refuses an archive whose folder holds no pages, before anything is queued.
5. **Record** `job_uuid` and `status: queued` on the run, and answer with the run.

**Ending without a job.** If steps 2–4 fail (GitHub unreachable, download cut short, planning
refused the folder), the run is `finished` with outcome `refused` or `failed` and its message, and
the slot is released in the same transaction. Nothing was queued.

**A run that died.** `createImport()` queues the job before it returns, so the process can die after
step 4 and before step 5. The run can still be found: the job carries `docs_sync_run` in its
options. Whenever the docs-sync state is read (`GET`, and step 1 before refusing), a run still
`reserved` is reconciled: if an import job names it, `job_uuid` is filled in and it becomes `queued`;
if none does and it is older than 15 minutes, it becomes `abandoned` and the slot is released.
A `queued` run whose job no longer exists (deleted by hand) is `finished` with outcome `failed`.

**The download runs in the request.** GitHub serves Thallo's 13 MB archive in a few seconds, and
downloading first keeps the main safeguard: planning refuses a wrong folder before anything is
queued. The handler raises its own limit with `set_time_limit(120)` where PHP allows it. A request
cut off by a host or proxy leaves the run `reserved`, which the reconciliation above abandons after
15 minutes; the admin offers **Release** on such a run so the owner need not wait. A scheduled sync
later would run the same service from the queue.

**Permissions:** what a manual zip import already needs, both of them: `content.manage` (the upload
route's) and `import_export.run_import` (creating an import job). That pair also covers saving and
removing the source (`PUT` / `DELETE /v1/admin/docs-sync/source`) and Release. Reading the card
(`GET /v1/admin/docs-sync`) needs `import_export.view`. The routes sit in the zip upload's route
group, so workspace binding is the same.

**Retrying an import job** from the Import / Export job list stays possible and is not routed through
the slot. A retried job still carries its run id, so its outcome is written to its own run only,
and it can become `synced_run` only under the rules in §7: never under a newer revision, never over
a newer run.

## 7. Outcomes, recorded when the job ends

The import marks a job `failed` whenever any page fails (`BatchRunner`), so the job's status alone
cannot say whether a sync landed. A run's outcome is decided from the job's final status and counts:

| Outcome | When | Card says | Becomes `synced_run`? |
|---|---|---|---|
| `success` | Job `completed`, no page failed. | "Up to date" (if the commit is current). | Yes |
| `partial` | Job `failed`, at least one page created, updated or unchanged, and some failed. | "Synced with errors: 2 pages failed [report]". | Yes |
| `refused` | The whole folder was refused (`markdown_folder_refused`), or steps 2–4 refused it. | "Sync refused: the folder `doc` holds no pages". | No |
| `failed` | The run could not finish: download or job error, job cancelled or gone, or every page failed. | "Sync failed: …". | No |

A Preview (`dry_run`) run gets an outcome the same way but never becomes `synced_run`; the card
shows it as the last preview with what would change.

**Recorded durably.** A listener on the import's `ImportExportJobCompleted` and `ImportExportJobFailed`
events (and the cancellation path) finds the run by the job's `docs_sync_run` option and, in one
transaction:

1. sets the run's `status: finished`, `outcome`, `counts` (from the job's report rows, before any
   retention can remove them) and `finished_at`;
2. releases the slot if this run holds it;
3. for a `commit` run with outcome `success` or `partial`, sets `docs_sync_state.synced_run` to this
   run **only if** the run's `revision` equals the state's current `revision` and the current
   `synced_run` is null or older than this run.

Reading the state repeats the same steps for any `queued` run whose job has ended, in case the
listener did not run (a worker that died mid-event). The screen presents what is recorded; it never
decides it.

## 8. Changes to the import

- **`MarkdownZip` gains `within`.** GitHub's archive holds one wrapping folder (`thallo-<sha>/`)
  around the whole repo. With `within: docs`, only entries under `<wrapper>/docs/` are pages, and
  their path is relative to `docs/`. A page therefore has the path the folder import gives it from
  a checkout's `docs/`, so pages imported earlier by hand are found again by `source_path`, not
  duplicated. Markdown elsewhere in the repo is never read. Zip-slip, size and count rules are
  unchanged and apply to what `within` selects.
- **An empty source is refused in both doors.** `MarkdownFolderImport::run` throws when the folder
  holds no Markdown files at all (the zip importer's planning already refuses an empty archive), so
  neither a mistyped path on the command line nor a sync can report every page gone. This supersedes
  the separate "Markdown import: refuse a folder with no pages" proposal.
- **The job keeps its run id.** `docs_sync_run` is carried in the job's options untouched; the
  import itself ignores it.
- **Gone pages are reported, not removed**, as today; their count is in the run's `counts`.

## 9. The screen

Settings › Import / Export gains a **Documentation from GitHub** card above the import form. With no
source saved it shows the form; once saved, the summary:

```
Documentation from GitHub
glueful/thallo · docs/ · latest release (pre-releases included) → Docs, published
Last synced v1.0.0-beta.109 (a1b2c3d) · 25 Nov 2026, 14:02 · by Michael · 3 updated, 50 unchanged, 1 gone [report]
v1.0.0-beta.110 is out.                                   [Preview]  [Sync now]   Edit source
```

- The note's states: up to date; a newer version is out (release) or the branch has new commits
  (branch, with the short SHA); the followed tag has moved since the sync; not synced from this
  source yet; GitHub unreachable or rate-limited.
- The last sync line shows the outcome: up to date, or synced with errors with the failed count.
- A run that did not land is shown beneath the last sync: the last preview with what would change,
  or "Sync of v1.0.0-beta.110 refused: the folder `doc` holds no pages", or "Sync failed: …".
- While a run holds the slot, the card shows it as running (following its job as the job list does),
  Sync now, Preview and Edit source are disabled, and a run reserved for over two minutes with no job
  offers **Release**.
- **[report]** links to the job's report while the job exists; after cleanup the line keeps the
  counts and drops the link.
- The form: repo, folder, follow (latest release / branch), branch, pre-releases, content type
  (with **Set up documentation** when there is none, as the zip import offers), publish, folders to
  leave out. Saving runs the check in §4.
- **Remove source** clears it (§4); pages already imported stay.

## 10. Docs and changelog

- `docs/documentation-sites.md` gains a section "From a GitHub repo": set it up, press Sync now or
  Preview, what it reads and refuses, what each outcome means. The command-line and zip paths stay
  as they are.
- CHANGELOG `[Unreleased]` bullets: the feature (Added) and the empty-folder refusal (Fixed).

## 11. Testing

GitHub client, with `MockHttpClient`:

- Tag selection: semver order, pre-releases on and off, non-version tags ignored, pagination.
- Annotated and lightweight tags both resolve to the commit; a chain longer than five steps is refused.
- Branch SHA; private repo refused; missing repo, tag or branch; the rate-limit message.
- The archive is requested by SHA, never by tag; over the size cap it is refused mid-stream.
- No request goes to any other host.

The run slot:

- Two simultaneous starts: exactly one holds the slot; the other is refused and downloads nothing.
- A start after the source changed between the read and the reservation is refused.
- Death after `createImport()` and before step 5: reconciliation links the job and the run proceeds.
- A reservation with no job past 15 minutes is abandoned and the slot released; Release does it at once.
- Editing or removing the source while a run holds the slot is refused.
- Retrying an older sync's job while a newer run is active writes only to the older run, and does
  not become `synced_run` over the newer one.

Revisions and outcomes:

- A run started under revision 3 that finishes after the source moved to revision 4 records its
  outcome but does not become `synced_run`; the same when the change happened between completion and
  the first read.
- Outcomes from real jobs: all pages imported → `success`; some pages failed → `partial` ("synced
  with errors"); every page failed → `failed`; folder refused → `refused`; job cancelled or deleted
  → `failed`.
- The listener records the outcome with nobody reading the screen; after `import-export:cleanup`
  deletes the job and its report, the first read still shows the counts, without a report link.
- Freshness: a tag moved between resolving and downloading changes nothing for that run (the archive
  is the resolved SHA); a tag moved after a successful sync reads as "has changed since your sync".

Import and Preview:

- `MarkdownZip` `within`: only the folder's pages, paths relative to it, matching the folder import's
  paths from a checkout (a hand import followed by a sync updates, never duplicates); zip-slip still
  refuses the whole archive.
- `MarkdownFolderImport`: an empty folder throws and writes nothing.
- **Preview executes its job** (dry run) through the queue and the test asserts no entry, draft,
  version or publication was written and `synced_run` is unchanged; Sync now's job, run the same
  way, writes them.
- Permissions: each route refuses a user missing either permission of its pair.
- Admin (vitest): the card's states (each outcome, running, Release, rate-limited, report link gone)
  and the form's validation messages.
- One manual end-to-end against the real `glueful/thallo` on a local site before release.

## 12. Left to the builder

- Class and file names, where the GitHub client lives (core or the importers pack), and the exact
  API response shapes.
- The migration's number and indexes beyond the ones named, and the runs' pruning task (a scheduled
  entry beside `import_export_cleanup`, or part of the listener).
- How the downloaded archive is cleaned up, as long as it follows the uploaded-import convention.
- The card's exact wording and layout within the admin's existing patterns.
