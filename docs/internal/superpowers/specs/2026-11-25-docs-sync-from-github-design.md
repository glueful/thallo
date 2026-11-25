# Docs sync from GitHub — design

Status: revision 2 (2026-11-25). Revision 2 answers the builder check: what counts as synced and
when it is recorded (§4.1), a source edited after a sync (§4.1), where the download runs (§6) and
the permissions (§6). Core, importers pack and admin. No framework change.

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
  ordinary import job, and the screen shows what it did.
- On opening the screen, a note says whether the repo has moved since the last sync ("v1.0.0-beta.110
  is out; you last synced v1.0.0-beta.109"). Reading only: nothing imports until Sync now.
- "Edit this page" links filled in from the source, with nothing to type.
- An empty or wrong source is refused before anything is written (§7).

Non-goals (this version):

- Automatic or scheduled syncs, and webhooks. The design keeps them easy to add later: a scheduled
  task or a webhook would call the same sync service.
- Private repos, tokens, and hosts other than GitHub. The source is a public GitHub repo; this is a
  requirement, not a limitation to work around.
- Deleting or unpublishing pages whose file is gone. They are reported, as today.
- Several sources per site. One source per site (per workspace with workspaces on), §4.

Anti-goals (a failure even if it works):

- A sync that marks every page "gone", or writes anything, because the folder, the tag or the
  download was wrong. The run on thallo.dev that reported 53 pages gone from an empty folder must
  not be repeatable through this feature.
- Markdown from outside the chosen folder becoming pages (the repo's READMEs, a package's
  CHANGELOG).
- A sync that duplicates pages already imported by hand: a page must be found again by the same
  path the folder import gave it.
- The site fetching from anywhere but GitHub, or anything but the two GitHub hosts named in §5.

## 3. Approaches

Considered:

- **A. GitHub repo + folder (chosen).** The site asks GitHub's API which commit the latest release
  tag or the branch tip is, downloads that commit's zip archive, keeps the folder and runs the zip
  import. One or two small API requests per check; one archive per sync. Follows releases. GitHub
  only.
- **B. Any archive URL.** Host-agnostic, but cannot follow "the latest release" by itself, cannot
  tell whether anything changed without downloading the archive, and cannot fill in edit links.
- Ruled out: fetching each file through GitHub's contents API (one request per page; the anonymous
  limit is 60 an hour per server), and `git clone` on the server (many hosts have no git).

The owner chose A, with **manual sync only**: nothing runs unattended in this version.

## 4. The source

Stored in the site's settings (`SettingsStore`, key `docs_sync.source`), so with workspaces on each
workspace has its own, like its content. Shape:

| Key | Meaning |
|---|---|
| `repo` | `owner/name`, e.g. `glueful/thallo`. Must match `^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$`. |
| `folder` | The folder in the repo, e.g. `docs`. Relative, no `..`, no leading `/`; empty means the repo root. |
| `follow` | `release` or `branch`. |
| `branch` | With `branch`: the branch name. With `release`: unused. |
| `prereleases` | With `release`: whether pre-release tags count (`v1.0.0-beta.N`). On by default, because thallo.dev follows betas. |
| `content_type`, `publish`, `exclude`, `locale` | The Markdown import's own options, as the zip import takes them. |
| `edit_branch` | The branch "Edit this page" links point at: with `branch`, that branch; with `release`, the repo's default branch, read from GitHub when the source is saved (a tag cannot be edited). |

Two records written by syncs, kept beside the source (§4.1): `docs_sync.attempt` and
`docs_sync.synced`.

### 4.1 Attempts and syncs

- **`docs_sync.attempt`** is written when Sync now or Preview creates a job: `job` (uuid), `mode`
  (`commit` or `dry_run`), `ref` (the tag, or the branch's commit SHA), `sha`, `at`, `by`. It is what
  the lock reads: while that job is queued or running, Sync now and Preview are both refused, so a
  commit never runs beside a Preview.
- **`docs_sync.synced`** is the last version that actually landed: `ref`, `sha`, `job`, `at`, `by`
  and the job's counts. It is never written at job creation. It is set from the attempt when a
  **commit** job has **finished** and was not refused as a whole (no `markdown_folder_refused`
  record). Pages that failed individually do not stop it: they are the job's failures, shown on the
  card with the report. A Preview never sets it, and neither does a failed or refused job.
- The record is promoted lazily, not by a hook in the queue: whenever the docs-sync status is read
  (`GET`, and before Sync now's lock check), a finished attempt that qualifies is copied to `synced`.
  An attempt that never qualifies stays visible on the card as the last attempt, with its outcome.
- **A source changed after a sync starts afresh.** Saving a source whose `repo`, `folder`, `follow`,
  `branch`, `content_type` or `locale` differs from the saved one clears `synced`, so the note says
  "not synced from this source yet" instead of "up to date". Changing only `publish`, `exclude` or
  `prereleases` keeps it (the next sync applies them). Pages already imported are not touched
  either way.

Saving the source checks it against GitHub once (§5): the repo exists and is public, and with
`release`, at least one version tag exists; with `branch`, the branch exists. A source that fails
the check is not saved, and the message says which part was wrong.

## 5. Talking to GitHub

Anonymous HTTPS through Symfony's HTTP client (already a dependency, as in
`PackagistReleaseFeed`), injected so tests use `MockHttpClient`. Only two hosts, fixed in code:
`api.github.com` and `codeload.github.com`. The repo, tag and branch only ever fill path segments,
URL-encoded; redirects are not followed off those hosts.

- **Repo:** `GET https://api.github.com/repos/{repo}` → `private` must be false;
  `default_branch` for edit links.
- **Latest release:** `GET https://api.github.com/repos/{repo}/git/matching-refs/tags/` (following
  `Link: rel="next"` pages) → every tag name; parsed with Composer's semver parser (the update check
  already uses `composer/semver`); names that are not versions are ignored; pre-releases kept or
  dropped per `prereleases`; the highest wins. **Not GitHub Releases**: `glueful/thallo` stopped
  creating Release pages at beta.21 and only pushes tags, so `releases/latest` answers beta.21.
- **Branch tip:** `GET https://api.github.com/repos/{repo}/commits/{branch}` with
  `Accept: application/vnd.github.sha` → the commit SHA as text.
- **Archive:** `GET https://codeload.github.com/{repo}/zip/refs/tags/{tag}` for a release,
  `.../zip/{sha}` for a branch (the SHA just read, so the archive is exactly the commit the note
  showed). GitHub's archives honour the repo's `export-ignore`, so Thallo's arrives without
  `docs/internal` and `admin/`; 13 MB today.

Limits: timeouts of 8 s for API requests and 60 s for the archive; the archive is streamed to the
`uploads` disk (where uploaded imports go) and refused past `import_export.max_file_size` (50 MB),
counted as it arrives. GitHub's anonymous limit is 60 requests an hour per server IP: the status
note is cached for five minutes per site, and a `403`/`429` with `X-RateLimit-Remaining: 0` says
"GitHub's hourly limit is reached; try again after HH:MM" from `X-RateLimit-Reset`.

## 6. Sync now

`POST /v1/admin/docs-sync/run` with `mode` `commit` (Sync now) or `dry_run` (Preview). In the same
route group as the zip upload, so workspace binding is the same.

**Permissions:** what a manual zip import already needs, both of them: `content.manage` (the upload
route's) and `import_export.run_import` (creating an import job). That pair also covers saving and
removing the source (`PUT` / `DELETE /v1/admin/docs-sync/source`). Reading the card
(`GET /v1/admin/docs-sync`) needs `import_export.view`.

1. Refuse if the attempt's job (§4.1) is still queued or running.
2. Resolve the followed version (§5). With `release` it is the newest tag at this moment, not the
   one the note showed: whatever is newest when the owner presses the button.
3. Download the archive (§5).
4. Create an import job with the existing `markdown.folder` adapter (`MarkdownZipImporter`) and `mode: commit`, through
   `ImportExportService::createImport`, with the source's options plus two new ones (§7): `within`
   (the folder) and `edit_base` (`https://github.com/{repo}/edit/{edit_branch}/{folder}`). Planning
   refuses an archive whose folder holds no pages, so nothing is queued for a wrong folder.
5. Save `docs_sync.attempt` (§4.1) and answer with the job.

**The download runs in the request**, my call. GitHub serves Thallo's 13 MB archive in a few seconds,
and downloading first keeps the design's main safeguard: planning refuses a wrong folder before
anything is queued. The handler raises its own time limit with `set_time_limit(120)` where PHP
allows it, and a download cut short (host or proxy timeout, GitHub error) leaves no job and no
attempt, with a message that says what failed. A later scheduled sync would run the same service
from the queue, where the request limit does not apply.

The job runs on the `import-export` queue like any import and shows in the Import / Export job list
with its report. **Sync now writes straight away**, my call: it is started by a person, nothing is
deleted, each page keeps its version history, and the report lists every change. A **Preview**
link beside it starts the same job with `mode: dry_run`, for an owner who wants to look first.

`GET /v1/admin/docs-sync` returns the source, `attempt`, `synced`, and the status note: the followed
version now, and whether it differs from `synced.ref`. If GitHub cannot be reached, the screen still loads and says
so; Sync now stays available.

## 7. Changes to the import

- **`MarkdownZip` gains `within`.** GitHub's archive holds one wrapping folder (`thallo-<ref>/`)
  around the whole repo. With `within: docs`, only entries under `<wrapper>/docs/` are pages, and
  their path is relative to `docs/`. A page therefore has the path the folder import gives it from
  a checkout's `docs/`, so pages imported earlier by hand are found again by `source_path`, not
  duplicated. Markdown elsewhere in the repo is never read. Zip-slip, size and count rules are
  unchanged and apply to what `within` selects.
- **An empty source is refused in both doors.** `MarkdownFolderImport::run` throws when the folder
  holds no Markdown files at all (the zip importer's planning already refuses an empty archive), so
  neither a mistyped path on the command line nor a sync can report every page gone. This supersedes
  the separate "Markdown import: refuse a folder with no pages" proposal.
- **Gone pages are reported, not removed**, as today; the sync screen shows their count with a link
  to the job's report.

## 8. The screen

Settings › Import / Export gains a **Documentation from GitHub** card above the import form. With no
source saved it shows the form; once saved, the summary:

```
Documentation from GitHub
glueful/thallo · docs/ · latest release (pre-releases included) → Docs, published
Last synced v1.0.0-beta.109 · 25 Nov 2026, 14:02 · by Michael · 3 updated, 50 unchanged, 1 gone [report]
v1.0.0-beta.110 is out.                                   [Preview]  [Sync now]   Edit source
```

- The note's states: up to date; a newer version is out (release) or the branch has new commits
  (branch, with the short SHA); not synced from this source yet; GitHub unreachable or rate-limited.
- When the last attempt did not land (a Preview, a refused or failed job), the card shows it under
  the last sync: "Preview of v1.0.0-beta.110 · 25 Nov, 14:10 · 3 would change [report]" or "Sync of
  v1.0.0-beta.110 refused: the folder `doc` holds no pages [report]".
- While the job runs, the card shows it as running and Sync now is disabled; it follows the job like
  the job list does.
- The form: repo, folder, follow (latest release / branch), branch, pre-releases, content type
  (with **Set up documentation** when there is none, as the zip import offers), publish, folders to
  leave out. Saving runs the check in §4.
- **Remove source** clears the setting; pages already imported stay.

## 9. Docs and changelog

- `docs/documentation-sites.md` gains a section "From a GitHub repo": set it up, press Sync now,
  what it reads and refuses. The command-line and zip paths stay as they are.
- CHANGELOG `[Unreleased]` bullets: the feature (Added) and the empty-folder refusal (Fixed).

## 10. Testing

- GitHub client with `MockHttpClient`: tag selection (semver order, pre-releases on and off,
  non-version tags ignored, pagination), branch SHA, private repo refused, missing repo or branch,
  rate-limit message, archive over the size cap refused mid-stream, no request to any other host.
- `MarkdownZip` `within`: only the folder's pages, paths relative to it, matching the folder
  import's paths from a checkout (so a hand import followed by a sync updates, never duplicates);
  a folder with no pages refused; zip-slip still refuses the whole archive.
- `MarkdownFolderImport`: an empty folder throws and writes nothing.
- Sync endpoint (integration, archive served by the mock): creates a commit job with `within` and
  `edit_base`; refuses while a commit or a Preview is running; writes `attempt` only; Preview creates
  a dry run; a download cut short leaves no job and no attempt.
- `synced`: set only after a finished commit job, never by a Preview or a refused job; a failed page
  still counts; saving a source with another repo, folder, follow, branch, type or locale clears it;
  changing only publish, exclude or pre-releases keeps it.
- Permissions: each route refuses a user missing either permission of its pair.
- Admin: the card's states and the form's validation messages (vitest).
- One manual end-to-end against the real `glueful/thallo` on a local site before release.

## 11. Left to the builder

- Class and file names, where the GitHub client lives (core or the importers pack), and the exact
  API response shapes.
- How the downloaded archive is cleaned up, as long as it follows the uploaded-import convention.
- The card's exact wording and layout within the admin's existing patterns.
