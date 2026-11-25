# Docs sync from GitHub — Implementation Plan

> Amended 2026-11-25 after plan review: recovery and pruning are recurring tasks in the scheduler's database table, registered by provision; the watchdog is gone (Rulings 1–2, Task 5). No network I/O inside a transaction (Global Constraints, Task 6). The retry limit is `getMaxAttempts()`, proven through a real delivery; the real worker-timeout proof and the `finish` pause hook are added (Task 6).
> Amended again: the timeout proof asserts rollback and recovery alongside a returned original delivery, not a failed-job row (Task 6); `before-finish` proves "Cancel wins" and `finish` "finish wins" (Tasks 6–7); Cancel is checked at the heartbeat and archive-record boundaries, so it stops a download (Task 6).

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A site owner saves a public GitHub repo + folder once; **Sync now** / **Preview** bring the site's docs to the followed release tag or branch through one dedicated, fenced, resumable sync job.

**Architecture:** Three tables (`docs_sync_state`, `docs_sync_runs`, `docs_sync_run_pages`) after the palette's state-row pattern. `DocsSyncService` reserves the site's slot and dispatches `DocsSyncJob {run, workspace}`. `DocsSyncRunner`, inside the run's workspace, claims a lease, resolves the commit through `GithubClient`, downloads an attempt-owned archive, reads the folder with the importer's read step and writes each page in its own lease-fenced transaction with a savepoint. Recovery runs on every state read and from a recurring scheduled task (`docs_sync_recover`, every 5 minutes); pruning from another (`docs_sync_prune`, daily). Provision registers both in the scheduler's `scheduled_jobs` table, so they reach existing installs.

**Tech Stack:** PHP 8.3 (MAMP PHP 8.4 locally), Glueful framework 1.93 (Connection transactions/savepoints, QueueManager, Job), Symfony HttpClient (`MockHttpClient` in tests), Composer semver, Vue 3 + Nuxt UI + Pinia Colada (admin), PHPUnit 10, Vitest.

**Spec:** `docs/internal/superpowers/specs/2026-11-25-docs-sync-from-github-design.md` (revision 5). Section numbers below (§N) are the spec's.

## Plan rulings (owner-confirmed after plan review)

- **Ruling 1: the recurring tasks live in the scheduler's database table, registered by provision** (§6.4). A `config/schedule.php` entry would reach new installs only: the scheduler reads the site's own file and nothing merges into it. But `JobScheduler` also loads `scheduled_jobs` rows (`loadJobsFromDatabase()`), and `registerInDatabase($name, $schedule, $handlerClass, $parameters)` is idempotent: an existing row is updated in place, keeping its uuid, history and `is_enabled`. `thallo:provision` runs on every install and every upgrade, so a never-fatal provision step registers:
  - `docs_sync_recover`, `*/5 * * * *`, handler `DocsSyncRecoverJob`;
  - `docs_sync_prune`, `17 3 * * *`, handler `DocsSyncPruneJob`.

  Its prerequisite is the scheduler's table (`capabilities.scheduler`, `SCHEDULE_DATABASE_STORE`, on by default). Without it the step warns: "Docs sync recovery runs only when its card is opened: the scheduler's database store is off (SCHEDULE_DATABASE_STORE)." No `config/schedule.php` entry is added, so nothing runs twice.
- **Ruling 2: there is no watchdog job.** With a recurring task the watchdog would only add a second chain that can itself be lost. Recovery runs from `docs_sync_recover` and on every state read (§9).
- Both recurring tasks extend `Glueful\Queue\Job` (as `UpdateCheckJob` does) and work across workspaces with `TenantContextRunner::forEachTenant()` when the runner is bound (else once, unscoped). `forEachTenant()` stops at the first workspace that throws, so each workspace's work is wrapped in its own try/catch and logged; one broken workspace never stops the others.

## Global Constraints

- No framework change; framework is `glueful/framework ^1.93.0` (already required).
- Only two outbound hosts: `api.github.com`, `codeload.github.com`; no redirects off them (§5).
- Repo pattern `^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$`; folder relative, no `..`, no leading `/` (§4).
- Timeouts: 8 s API, 60 s archive; archive cap `import_export.max_file_size` (52428800), counted as streamed (§5).
- Status note cache: 5 minutes per site. Lease expiry: 120 s. Lease proof at least every 30 s while resolving/downloading. Re-dispatch at most once per 2 minutes per run; `reserved` re-dispatched after 2 minutes. Attempt budget: 5. Tag chain: at most 5 steps. Page cap: `MarkdownZip::MAX_FILES` (2000) (§5, §6).
- Queue: `import_export.queue` (`import-export`). `DocsSyncJob` queue retry limit 1, by overriding `getMaxAttempts(): int { return 1; }` (the base `Glueful\Queue\Job::getMaxAttempts()` returns 3 and a property does not change it) (§6.4).
- No network I/O inside a database transaction. GitHub calls and the download run outside any transaction; only short transactions prove the lease (heartbeat), record `ref`/`sha`, and record `archive_path`, so Cancel, Release and recovery never wait behind a download.
- Permissions: write routes need `content.manage` AND `import_export.run_import`; read routes `import_export.view` (§6.5).
- Outcomes exactly: `success`, `partial`, `refused`, `failed`, `cancelled`, `interrupted` (§7). Statuses exactly: `reserved`, `dispatched`, `running`, `finished`, forward-only (§6.0).
- House rules: no `Co-Authored-By` or AI trailers in commits; commit only, never push; CHANGELOG `[Unreleased]` bullet in the same commit as the change; phpcs judged by exit code; admin gate is `pnpm type-check`; format touched admin files only (`npx oxfmt <files>`); every new `tests/Integration/*` folder is listed in one `INTEGRATION_SHARD_*` in `.github/workflows/ci.yml`; MAMP PHP first on PATH (`export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH`); gates run one at a time; after using the test DB, `composer test:reset-db && composer test:migrate`.

## Review Focus

1. **A folder whose case differs from the repo's** (`Docs` saved, repo has `docs`): the run is `refused` with "the folder `Docs` holds no pages", nothing written. Test in Task 2 (`within` is case-sensitive and says so) and Task 6.
2. **Tags with and without a `v`, and non-version tags mixed in** (`1.2.0`, `v1.3.0`, `latest`, `v2.0.0-rc.1`): the highest version by semver wins, `latest` is ignored, the pre-release counts only with `prereleases`. Test in Task 4.
3. **An archive response that redirects to another host, or a non-zip body with 200:** refused with a message, no `archive_path`. Test in Task 4.
4. **The saved content type deleted after the source was saved:** the run finishes `refused` with the importer's "There is no content type" message, nothing written, slot released. Test in Task 6.
5. **The run's workspace no longer exists when the job runs:** the run finishes `failed` with a message and releases its slot; it never loops through re-dispatch. Test in Task 6.

---

### Task 1: Importer read and write steps; empty folder refused

**Files:**
- Modify: `packages/thallo-importers/src/Markdown/MarkdownFolderImport.php`
- Create: `packages/thallo-importers/src/Markdown/MarkdownFolderRead.php`
- Test: `tests/Integration/Importers/MarkdownFolderImportTest.php`

**Interfaces:**
- Produces:
  - `final class MarkdownFolderRead` (readonly): `string $type`, `string $typeUuid`, `string $locale`, `string $bodyField`, `list<MarkdownPage> $pages` (links already resolved, `skip` pages included), `list<array{source_path: string, entry: string}> $missing`.
  - `MarkdownFolderImport::read(string $dir, array $options): MarkdownFolderRead`, same `$options` as `run()`. Throws `\InvalidArgumentException` for: no such type, type lacks `title`/body field, not a folder, **no Markdown file** (`"\"{$dir}\" holds no Markdown files (.md, .mdx, .markdown)."`), and the duplicate-URL rule moves here as a per-page `failed` marker, not an exception.
  - `MarkdownFolderImport::writePage(MarkdownFolderRead $read, MarkdownPage $page, array $options): array{status: string, slug: string, message?: string, broken_links?: list<string>, entry?: string}` — exactly today's per-page body (fields, `edit_url`, `write()`), including its `ValidationFailed`/`\Throwable` catch **removed**: it throws, and callers decide (`run()` catches as today; Task 6 rolls back a savepoint). `skip` pages return `status: skipped`.
  - `run()` keeps its signature and report, built from `read()` + `writePage()`.

- [ ] **Step 1: Write the failing tests** in `MarkdownFolderImportTest`:
  - `testAnEmptyFolderIsRefusedAndNothingIsWritten`: empty `$this->dir` (delete the `guides`/`internal` dirs' contents; keep dirs); `run($this->dir, ['type' => 'docs'])` throws `\InvalidArgumentException` matching `/holds no Markdown files/`; `EntryRepository` count for `docs` unchanged.
  - `testReadResolvesEveryPageAndWritePageWritesOne`: two files linking to each other; `read()` returns 2 pages whose bodies have resolved `/docs/...` links; `writePage()` on the first returns `status: created` and only that entry exists.
  - `testWritePageThrowsWhereRunReportsFailed`: a page invalid for the type; `writePage()` throws `ValidationFailed`; `run()` still reports it `failed` with `Not valid:` (today's text).
- [ ] **Step 2:** `vendor/bin/phpunit tests/Integration/Importers/MarkdownFolderImportTest.php` → the three new tests FAIL (no `read`, empty folder not refused); existing 8 PASS.
- [ ] **Step 3:** Implement by moving code, not rewriting it: `read()` holds today's type checks, folder check, the empty check (count of files from `files()` is 0), page reading, link resolution and the `missing` query; `writePage()` holds today's per-page field building and `write()`; `run()` loops `writePage()` inside today's try/catch and assembles the same report.
- [ ] **Step 4:** Run `tests/Integration/Importers` (all files) → PASS; and `tests/Integration/Importers/MarkdownZipImporterTest.php` unchanged → PASS.
- [ ] **Step 5: Commit** with CHANGELOG `[Unreleased]` → `### Fixed`: "**A Markdown import of an empty folder is refused.** It reported every page as gone; now it says the folder holds no Markdown files and writes nothing."

```bash
git commit -m "fix(importers): split the Markdown folder import into read and write steps; refuse an empty folder"
```

### Task 2: `MarkdownZip` `within`

**Files:**
- Modify: `packages/thallo-importers/src/Markdown/MarkdownZip.php`
- Test: `tests/Integration/Importers/MarkdownZipWithinTest.php` (new file, Importers folder already sharded)

**Interfaces:**
- Produces: `MarkdownZip::extract(string $zipPath, string $into, array $keep = [], array $exclude = [], int $maxFiles = self::MAX_FILES, int $maxBytes = self::MAX_BYTES, ?string $within = null): list<string>` and `MarkdownZip::count(string $zipPath, array $keep = [], array $exclude = [], ?string $within = null): int`. With `$within`: the archive must have exactly one top folder (`<wrapper>/`); only entries under `<wrapper>/<within>/` are selected, path relative to `<within>/`; the wrapper-stripping rule for `$keep` does not apply. Matching is case-sensitive. `$within === ''` means the whole wrapper.

- [ ] **Step 1: Failing tests** (build zips with `ZipArchive` in a temp dir, wrapper `thallo-abc123/`):
  - `testWithinTakesOnlyTheFolderWithPathsRelativeToIt`: entries `README.md`, `docs/guides/a.md`, `docs/index.md`, `packages/x/README.md` → `extract(..., within: 'docs')` returns `['guides/a.md', 'index.md']`.
  - `testWithinPathsMatchTheFolderImportFromACheckout`: the paths equal what `MarkdownFolderImport::read()` gives `source_path` for the same tree on disk.
  - `testWithinIsCaseSensitive`: `within: 'Docs'` on the archive above → `count()` returns 0 (Review Focus 1).
  - `testZipSlipUnderWithinStillRefusesTheWholeArchive`: entry `thallo-abc123/docs/../../evil.md` → `\InvalidArgumentException`.
  - `testAnArchiveWithoutASingleWrapperIsRefusedWithin`: two top folders → `\InvalidArgumentException` "has no single top folder".
- [ ] **Step 2:** `vendor/bin/phpunit tests/Integration/Importers/MarkdownZipWithinTest.php` → FAIL (unknown parameter).
- [ ] **Step 3:** Implement in `selected()`/`pages()`: when `$within !== null`, take the single top folder, filter by prefix `<wrapper>/<within>/`, strip it; size/count/zip-slip checks run on the selection as today.
- [ ] **Step 4:** Run the new file and `MarkdownZipImporterTest.php` → PASS.
- [ ] **Step 5: Commit** `feat(importers): select one folder of a repository archive (MarkdownZip within)`.

### Task 3: Tables, tenancy registration and the state store

**Files:**
- Create: `core/database/migrations/049_CreateDocsSyncTables.php`
- Modify: `packages/thallo-tenancy/src/ThalloTenantTables.php` (three definition rows; `docs_sync_state` widened unique `['uniq_docs_sync_state_site', ['tenant_uuid', 'site']]`)
- Create: `core/src/Content/DocsSync/DocsSyncSource.php`, `core/src/Content/DocsSync/DocsSyncStore.php`, `core/src/Content/DocsSync/DocsSyncConflict.php`
- Test: `tests/Integration/DocsSync/DocsSyncStoreTest.php`; add `tests/Integration/DocsSync` to `INTEGRATION_SHARD_F` in `.github/workflows/ci.yml`

**Interfaces:**
- Produces:
  - Tables exactly as §4 (`docs_sync_runs` also has `last_dispatch_at` timestamp for the 2-minute re-dispatch bound; `docs_sync_run_pages` unique `(run_id, path)`, index `run_id`; `docs_sync_runs` index `status`).
  - `final class DocsSyncSource` (readonly; `fromArray(array): self` validating repo/folder/follow rules and throwing `\InvalidArgumentException` naming the field; `toArray(): array`; `sameTarget(self $other): bool`).
  - `final class DocsSyncStore`:
    - `state(): array{source: ?DocsSyncSource, revision: int, active_run: ?string, lease_token: ?string, lease_at: ?string, synced_run: ?string}` (creates the `site` row if missing, inside a savepoint like `palette_state`).
    - `saveSource(DocsSyncSource $source): int` (new revision) — one `UPDATE … SET source, revision = revision + 1, synced_run = NULL WHERE site = 'site' AND active_run IS NULL`; 0 rows → `throw DocsSyncConflict::running()`.
    - `removeSource(): int` — same with null source.
    - `ownerFence(string $run, string $token): bool`, `slotFence(string $run): bool` — the two §6.0 statements; return whether one row was affected. Callers use them as the first write inside their own `transaction()`.
    - `advanceStatus(string $run, string $to): bool` — forward-only per §6.0 (`dispatched` from `reserved`; `running` from `reserved|dispatched|running`; `finished` from not-`finished`).

- [ ] **Step 1: Failing tests** (`DocsSyncStoreTest extends AppTestCase`):
  - `testSavingASourceBumpsTheRevisionAndClearsSynced`.
  - `testTheSourceCannotChangeWhileARunHoldsTheSlot` (set `active_run` directly; `saveSource` and `removeSource` throw `DocsSyncConflict`).
  - `testSourceValidation`: data provider of bad repos (`../x`, `a/b/c`, 40-char owner), folders (`../docs`, `/docs`), follow `tag` → `\InvalidArgumentException` naming the field.
  - `testFencesAffectOneRowOnlyForTheOwner`: `ownerFence` true for the right token, false for another token or another run; `slotFence` false after `active_run` cleared.
  - `testStatusOnlyMovesForward`: `finished` → `dispatched` returns false and the row stays `finished`.
  - `testTablesAreRegisteredForTenancy`: `ThalloTenantTables::all()` has the three keys.
- [ ] **Step 2:** `composer test:migrate` then run the file → FAIL (no tables/classes).
- [ ] **Step 3:** Implement migration (palette migration 043 style, `hasTable` guards, `down()` drops), registration, store.
- [ ] **Step 4:** Run the file → PASS; run `tests/Integration/Tenancy` → PASS (registration does not break the retrofit).
- [ ] **Step 5: Commit** `feat(docs-sync): state tables, tenancy registration and the fenced state store`.

### Task 4: GitHub client

**Files:**
- Create: `core/src/Content/DocsSync/GithubClient.php`, `core/src/Content/DocsSync/GithubRefused.php`
- Test: `tests/Unit/Content/DocsSync/GithubClientTest.php`

**Interfaces:**
- Produces `final class GithubClient` (constructor `?HttpClientInterface $http = null`, default `HttpClient::create(['max_redirects' => 0])`):
  - `repo(string $repo): array{private: bool, default_branch: string}` — `GithubRefused` for 404 ("no public repo `x/y`") and `private: true`.
  - `latestRelease(string $repo, bool $prereleases): array{tag: string, sha: string}` — paginated `matching-refs/tags/`, semver pick, tag-object deref ≤ 5 steps.
  - `branchTip(string $repo, string $branch): string` (SHA).
  - `download(string $repo, string $sha, string $toPath, int $maxBytes, callable $heartbeat): void` — streams `codeload.github.com/{repo}/zip/{sha}` to `$toPath`, calls `$heartbeat()` at least every 30 s, throws `GithubRefused` over `$maxBytes`, on short body vs `Content-Length`, on a body `ZipArchive::open` rejects, or on any 3xx.
  - Every 403/429 with `X-RateLimit-Remaining: 0` throws `GithubRefused` "GitHub's hourly limit is reached; try again after HH:MM" (`X-RateLimit-Reset`, site timezone). API timeout 8, archive 60.

- [ ] **Step 1: Failing tests** with `MockHttpClient` + `MockResponse` (record every requested URL):
  - `testHighestVersionTagWinsAcrossPages` (two pages via `Link`), `testPreReleasesCountOnlyWhenAsked`, `testMixedTagNamesAndNonVersionsAreHandled` (Review Focus 2: `1.2.0`, `v1.3.0`, `latest`, `v2.0.0-rc.1` → `v1.3.0` without prereleases, `v2.0.0-rc.1` with).
  - `testAnnotatedTagResolvesToItsCommit`, `testLightweightTagIsItsCommit`, `testATagChainLongerThanFiveIsRefused`.
  - `testBranchTipIsTheShaText` (asserts the `Accept: application/vnd.github.sha` header).
  - `testPrivateOrMissingRepoIsRefused`, `testRateLimitSaysWhenToTryAgain`.
  - `testArchiveIsRequestedBySha` (URL ends `/zip/{sha}`), `testArchiveOverTheCapIsRefusedMidStream`, `testTruncatedArchiveIsRefused`, `testRedirectOrNonZipBodyIsRefused` (Review Focus 3).
  - `testNoRequestGoesToAnotherHost`: every recorded URL's host ∈ {`api.github.com`, `codeload.github.com`}.
- [ ] **Step 2:** `vendor/bin/phpunit tests/Unit/Content/DocsSync/GithubClientTest.php` → FAIL.
- [ ] **Step 3:** Implement. Semver: `Composer\Semver\VersionParser::normalize()` in try/catch to drop non-versions; `Composer\Semver\Semver::rsort()`; stability via `VersionParser::parseStability()`.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `feat(docs-sync): GitHub client pinned to commits`.

### Task 5: Starting a run; recovery and pruning, recurring

**Files:**
- Create: `core/src/Content/DocsSync/DocsSyncService.php`, `core/src/Content/DocsSync/DocsSyncRunRepository.php`, `core/src/Content/DocsSync/DocsSyncScheduleStep.php`, `core/src/Content/Jobs/DocsSyncJob.php` (handle() stub calling `DocsSyncRunner` — Task 6), `core/src/Content/Jobs/DocsSyncRecoverJob.php`, `core/src/Content/Jobs/DocsSyncPruneJob.php`
- Modify: `core/src/Providers/CoreServiceProvider.php` (bindings), `core/src/Setup/Console/ProvisionCommand.php` (run `DocsSyncScheduleStep` beside the font library step, same never-fatal try/catch and one-line report)
- Test: `tests/Integration/DocsSync/DocsSyncStartTest.php`, `tests/Integration/DocsSync/DocsSyncRecoveryTest.php`, `tests/Integration/DocsSync/DocsSyncPruneTest.php`, `tests/Integration/DocsSync/DocsSyncScheduleStepTest.php`

**Interfaces:**
- Consumes: `DocsSyncStore` (Task 3).
- Produces:
  - `DocsSyncRunRepository`: `create(array $row): string`, `find(string $id): ?array`, `recent(int $limit): list<array>`, `pages(string $run): list<array>`, `increment(string $run, string $status): void`, `finish(string $run, string $outcome, ?string $message): void` (callers hold a fence), `markDispatched(string $run): void` (sets `last_dispatch_at`; `advanceStatus` to `dispatched`).
  - `DocsSyncService::start(string $mode, ?string $actor): array` (the run row) — validates `mode ∈ {commit, dry_run}`; throws `DocsSyncConflict::noSource()`, `::running()`, `::sourceChanged()`. One `transaction()`: insert run (`reserved`, revision, source copy, mode, workspace from the tenant context or null), slot CAS (§6.1 step 2), then **if** `config('queue.default')`'s driver is `database`: `QueueManager::push(DocsSyncJob::class, ['run' => $id, 'workspace' => $ws], $queue)` and `markDispatched()` inside the transaction; otherwise `afterCommit` push then `markDispatched()`.
  - `DocsSyncService::recover(): void` — §6.4 rules for the current workspace's slot-holding run, under `slotFence` re-reading lease and now: live lease → nothing; `reserved` > 2 min or expired/no lease with `attempts < 5` and `last_dispatch_at` > 2 min ago → push `DocsSyncJob` again + `markDispatched()`; expired/no lease with `attempts >= 5` → finish `cancelled` if `cancel_requested` else `interrupted`, release slot. One short transaction; no network.
  - `DocsSyncService::prune(): array{runs: int, files: int}` — for the current workspace: deletes finished runs (and their page rows) whose `finished_at` is older than `import_export.retention_days`, except `synced_run`; deletes the `docs-sync/{run}/` directory of every run finished more than a day ago, `synced_run`'s included (its history row stays, its archive goes).
  - `DocsSyncRecoverJob::handle()` / `DocsSyncPruneJob::handle()`: `forEachTenant()` (or once, unscoped, with no runner), each workspace in its own try/catch → `recover()` / `prune()`.
  - `DocsSyncScheduleStep::run(): array{registered: list<string>, skipped: ?string}` — if `config('capabilities.scheduler')` is false or the `scheduled_jobs` table is missing, returns `skipped` with the warning text from Ruling 1; else `JobScheduler::registerInDatabase()` for both tasks.

- [ ] **Step 1: Failing tests:**
  - Start: `testOneStartTakesTheSlotAndASecondIsRefused` (second `start` throws `running`, no second job row in the queue table), `testAStartAfterTheSourceChangedIsRefused` (bump revision between reading state and CAS via a store spy), `testDatabaseQueueReservesAndQueuesInOneTransaction` (force an exception after the push inside the transaction → no run, `active_run` null, no queued job row), `testWorkspaceIsCapturedOnTheRunAndThePayload`, `testOnlyCommitAndDryRunAreAccepted`.
  - Recovery (a fake `QueueManager` subclass recording pushes, as in the framework's `VariantRecordingQueue`): `testAReservedRunNeverPushedIsRedispatchedAfterTwoMinutes`, `testRedispatchIsBoundedToOncePerTwoMinutes`, `testALiveLeaseIsLeftAloneEvenOnTheFifthAttempt`, `testAnExpiredFifthAttemptIsInterrupted`, `testAnExpiredFifthAttemptWithCancelRequestedIsCancelled`.
  - **Recovery without the card:** `testARequestKilledBeforeDispatchIsRecoveredByTheRecurringTask` (non-database driver path: commit the reservation, skip the push as if the process died; no state read; `DocsSyncRecoverJob::handle()` with the clock 2 minutes on → one `DocsSyncJob` pushed for the run with its workspace) and `testADeliveryWhoseRetriesRanOutIsRecoveredByTheRecurringTask` (run `dispatched`, its delivery in failed jobs, no lease; `DocsSyncRecoverJob::handle()` → re-dispatched; after five such attempts → `interrupted`). Neither test calls `GET /docs-sync`.
  - `testRecoveryRunsInEveryWorkspaceAndOneFailureDoesNotStopTheOthers` (tenancy harness: two workspaces with stuck runs; the first's recovery throws → the second is still recovered).
  - Pruning (`DocsSyncPruneTest`, clock-driven, no card read): `testAnIdleSiteIsPrunedAcrossBothDeadlines` — a finished run that is `synced_run` and an older finished run, both with archive files: after 1 day + `DocsSyncPruneJob::handle()` both archive directories are gone and both rows remain; after 31 days the older run and its page rows are gone, `synced_run` and its page rows remain.
  - Schedule step: `testProvisionRegistersBothTasksOnce` (run the step twice → one `scheduled_jobs` row per name, same uuid, `is_enabled` kept when an operator disabled it), `testWithoutTheSchedulerStoreItWarnsAndRegistersNothing`.
- [ ] **Step 2:** Run the four files → FAIL.
- [ ] **Step 3:** Implement. Time comes from an injectable clock (`\Psr\Clock\ClockInterface` if bound, else a small `DocsSyncClock` with `now(): \DateTimeImmutable`) so tests age leases without sleeping.
- [ ] **Step 4:** Run the four files → PASS; run `thallo:provision` on the local site and check its output line and the two rows in `scheduled_jobs`.
- [ ] **Step 5: Commit** `feat(docs-sync): reserve and dispatch a run; recurring recovery and pruning`.

### Task 6: The runner — lease, archive, fenced pages, finish

**Files:**
- Create: `core/src/Content/DocsSync/DocsSyncRunner.php`
- Modify: `core/src/Content/Jobs/DocsSyncJob.php` (workspace entry exactly as `RunPaletteReplaceJob::handle()`, with the tenant context forgotten first so a long-lived worker starts clean; `public function getMaxAttempts(): int { return 1; }`)
- Test: `tests/Integration/DocsSync/DocsSyncRunnerTest.php`, `tests/Integration/DocsSync/DocsSyncFencingTest.php`, `tests/Integration/DocsSync/DocsSyncWorkspaceTest.php`, `tests/Integration/DocsSync/DocsSyncWorkerTimeoutTest.php`

**Interfaces:**
- Consumes: Task 1 `read()`/`writePage()`, Task 2 `within`, Task 3 store and fences, Task 4 client, Task 5 repository.
- Produces: `DocsSyncRunner::run(string $runId): void` implementing §6.2–§6.3 and §7:
  - Claim: one `transaction()` — lease CAS (§6.2), `advanceStatus(running)`, `attempts + 1`; budget-spent and live-lease branches per §6.2 (live lease → `later(60, DocsSyncJob, payload)`).
  - **Network outside transactions.** Resolving and downloading hold no transaction open. The only database calls during them are the heartbeat, `GithubClient::download()`'s `$heartbeat` running `ownerFence` as its own one-statement transaction (at least every 30 s; false aborts the download), and two short transactions: after resolving { `ownerFence`; record `ref`, `sha` }, and after the `.part` → check → rename { `ownerFence`; record `archive_path` } — fenced out → delete own file, stop (§6.3 step 2). **Each of these three boundaries also reads `cancel_requested` after its `ownerFence`**, in the same short transaction: if set, it finishes the run `cancelled` there (slot and lease released, counters as they are), the download is aborted and the attempt's own files deleted. So Cancel stops a run at its next heartbeat (≤ 30 s), not only at its next page. Extraction with `MarkdownZip::extract(..., within: $folder)` into a temp dir; `read()` with the source's options and `edit_base` `https://github.com/{repo}/edit/{edit_branch}/{folder}`; refusals → `refused`.
  - Each page not yet in `docs_sync_run_pages`: `transaction()` { `ownerFence` or stop; `cancel_requested` → finish `cancelled`; nested `transaction()` (savepoint) around `writePage()` with `dry_run` passed through; on throw the savepoint rolls back and the row is `failed` with the message; insert page row; `increment` }.
  - Finish: `transaction()` { `ownerFence`; `cancel_requested` → `cancelled`; `gone` rows from `MarkdownFolderRead::missing`; outcome from counters (§7 table); `synced_run` per §6.3 step 5; release slot; `finished` }; then delete own files.
  - Fencing hook for tests: an optional `?\Closure $pause` called with `('page', $path)` inside each page's transaction just before the write, `('download')` after the `.part` is written and before `archive_path` is recorded, `('before-finish')` after the last page and **before** the finishing transaction opens (no lock held), and `('finish')` inside the finishing transaction just after its `ownerFence` (lock held). Task 7 uses `before-finish` for "Cancel wins" and `finish` for "finish wins". Injected only by tests; null in production.

- [ ] **Step 1: Failing tests** — archives served by `MockHttpClient` from zips built in the test:
  - Runner: `testASyncWritesEveryPageAndLands` (outcome `success`, `synced_run` set, counters equal page rows), `testPartialFailedAndRefusedOutcomes` (one invalid page → `partial`; all invalid → `failed`; `within` matching nothing → `refused` with nothing written), `testAPageThatThrowsAfterItsDraftChangedLeavesNothing` (writer spy throws after `createDraft`: no entry, no draft, row `failed`, next page written), `testAHeldPublicationKeepsTheDraft` (review workflow on: `PublishBlocked` → row written with the message), `testPreviewWritesNothing` (run the real job from the queue with `dry_run`: no entry/draft/version/publication rows, `synced_run` unchanged; then `commit` writes them), `testMoreThanAThousandPagesCountExactly` (1,200 tiny pages), `testDeletedContentTypeIsRefused` (Review Focus 4), `testAHandImportFollowedBySyncUpdatesNotDuplicates` (run `MarkdownFolderImport::run()` on the same tree first).
  - Fencing: `testTwoDeliveriesWriteEachPageOnce`, `testATakeoverWaitsForThePageInFlightAndFencesTheOldAttempt` (two-process proof as the palette plan's Task 14: attempt A paused in a page via `$pause` in a child PHP process; parent attempts takeover/Release and watches it block (`pg_stat_activity` wait on PostgreSQL, or a busy wait on SQLite), releases A; A's page commits, A's next page is fenced; page content is B's), `testAStaleAttemptCannotRecordOrFinish`, `testAnAttemptKilledMidPageRollsThatPageBack` (child process `exit` inside the savepoint), `testOverlappingDownloadsKeepToTheirOwnFiles`, `testATruncatedDownloadIsDownloadedAgain`, `testAFastWorkerIsNotDowngradedByALateDispatched`.
  - **Retry limit through a real delivery:** `testTheQueueDeliversTheSyncJobOnlyOnce` — push `DocsSyncJob` onto the database queue, make its handle throw, run a real `php glueful queue:work --queue=import-export --stop-when-empty` child process: the job is in failed jobs after one attempt, not three.
  - **Cancel is not blocked by a download:** `testCancelDuringADownloadDoesNotWaitAndStopsAtTheNextBoundary` — in a child process `$pause('download')` holds the attempt with no transaction open; the parent's Cancel returns within a second; on resume the archive-record transaction sees `cancel_requested` and finishes the run `cancelled`: no `archive_path` recorded, no page row, the attempt's file deleted, slot released. A second case pauses inside a slow download and asserts the next heartbeat does the same.
  - Workspace: `testAFreshWorkerRunsInTheRunsWorkspace`, `testTwoWorkspacesInARowDoNotLeak` (tenancy harness, `RetrofittedTenantTestCase`, so these run in the `tenancy-harness` CI job), `testAMissingWorkspaceFailsTheRunWithoutLooping` (Review Focus 5).
  - **Real worker timeout** (`DocsSyncWorkerTimeoutTest`; skipped when `pcntl_alarm` is unavailable): a 5-page run whose third page's `$pause('page', …)` sleeps 5 s; a real child `queue:work --queue=import-export --timeout=2 --stop-when-empty` is killed by its timeout mid-page. **Nothing is asserted about the failed-jobs table here:** the worker's timeout handler writes its failure record through the same connection, inside the open page transaction, so that record and the delivery's removal can roll back with the page and the original delivery can come back. Asserted: pages 1–2 have rows and entries, page 3 has neither (rolled back). Then, with the clock past the 120 s lease, `DocsSyncRecoverJob::handle()` re-dispatches, so the queue may now hold **both** the original delivery (returned) and the recovery's. A second worker (no pause) runs until the queue is empty: one delivery claims the lease and finishes, outcome `success`; the other either re-queues behind the live lease and then finds the run finished, or finds it finished at once; five page rows, each page's entry written exactly once, no page row duplicated. A variant spends the budget: every attempt times out → after the fifth, recovery finishes the run `interrupted` with the committed pages kept. (The retry limit itself is proven by `testTheQueueDeliversTheSyncJobOnlyOnce`, an ordinary exception outside any transaction.)
- [ ] **Step 2:** Run the four files → FAIL.
- [ ] **Step 3:** Implement `DocsSyncRunner` and the job.
- [ ] **Step 4:** Run the four files → PASS; then `tests/Integration/Importers` → PASS.
- [ ] **Step 5: Commit** `feat(docs-sync): the fenced, resumable sync runner`.

### Task 7: Cancel and Release

**Files:**
- Modify: `core/src/Content/DocsSync/DocsSyncService.php`
- Test: `tests/Integration/DocsSync/DocsSyncCancelTest.php`

**Interfaces:**
- Produces: `DocsSyncService::cancel(string $run): array{finished: bool, outcome: ?string}` and `release(string $run): array{released: bool}` per §6.5 (slot fence first; Cancel on an unleased run finishes `cancelled` in the same transaction; Release only when no lease or lease > 120 s, finishing `interrupted`; a live lease → `release` returns `released: false`).

- [ ] **Step 1: Failing tests:** `testCancelDuringAPageLetsItCommitThenStops`, `testCancelBeforeTheLastPageFinishCommitsIsCancelled` and `testFinishBeforeCancelKeepsTheSuccess` (both orders, from a child process with Cancel from the parent: **Cancel wins** pauses at `before-finish`, before the finishing transaction takes the state row, lets Cancel commit, then resumes → the finish sees `cancel_requested`, outcome `cancelled`, `synced_run` unchanged; **finish wins** pauses at `finish`, with the lock held, and asserts Cancel blocks until the finish commits, then answers that the run had finished, outcome `success`), `testCancelOfAnUnclaimedRunFinishesItAtOnce`, `testReleaseRefusesALiveLease`, `testReleaseOfAStaleRunInterruptsItAndFencesTheOldAttempt`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `feat(docs-sync): cancel and release, ordered by the state lock`.

### Task 8: HTTP API

**Files:**
- Create: `core/src/Content/Http/Controllers/DocsSyncController.php`
- Modify: `core/routes/admin.php` (in the group of `/import-export/upload`), `docs/openapi.json` (regenerate with `CACHE_DRIVER=array php glueful docs:openapi`, splice only the new operations if the run touches others)
- Test: `tests/Integration/DocsSync/DocsSyncApiTest.php`

**Interfaces:**
- Consumes: Tasks 3–7.
- Produces (all under `/v1/admin`):
  - `GET /docs-sync` → `{source, revision, active_run, synced, recent: [...10 runs], note: {state, followed: {ref, sha}|null, message}}` — runs `recover()` first; note cached 5 min per site; GitHub failures become `note.state = 'unreachable'|'rate_limited'` with the message, never a 5xx.
  - `GET /docs-sync/runs/{run}` → run + its page rows.
  - `PUT /docs-sync/source` (validates with `DocsSyncSource::fromArray`, checks GitHub per §4, fills `edit_branch`), `DELETE /docs-sync/source` → 409 while running.
  - `POST /docs-sync/run` `{mode}` → 202 with the run; 409 `running`/`source_changed`; 422 bad mode.
  - `POST /docs-sync/runs/{run}/cancel`, `POST /docs-sync/runs/{run}/release`.
  - Middleware: reads `import_export_permission:import_export.view`; writes `content_permission:content.manage` + `import_export_permission:import_export.run_import`.

- [ ] **Step 1: Failing tests:** one per route's happy path; `testTheNoteComparesCommitsNotTagNames` (same tag, new SHA after a sync → `note.state = 'tag_moved'`; same SHA → `up_to_date`; newer tag → `newer`); `testEachWriteRouteNeedsBothPermissions` (data provider: user with only one of the pair → 403); `testTheNoteSurvivesGithubBeingDown`; `testSavingASourceGithubRejectsIsNotSaved` (private repo → 422 naming the field).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS; `vendor/bin/phpunit tests/Unit/Docs` → PASS.
- [ ] **Step 5: Commit** `feat(docs-sync): admin API`.

### Task 9: The admin card

**Files:**
- Create: `admin/src/queries/docsSync.ts`, `admin/src/pages/settings/import-export/DocsSyncCard.vue`, `admin/src/pages/settings/import-export/DocsSyncForm.vue`, `admin/src/pages/settings/import-export/DocsSyncRunPages.vue`
- Modify: `admin/src/pages/settings/import-export/index.vue` (card above the import form)
- Test: `admin/src/__tests__/docsSyncCard.spec.ts`, `admin/src/__tests__/docsSyncForm.spec.ts`

**Interfaces:**
- Consumes: Task 8 routes. Regenerate `admin/src/api/schema.d.ts` / `core-schema.d.ts` the way the repo does today.
- Produces: `useDocsSync()` (query, polls every 2 s while `active_run`), `useSaveDocsSource()`, `useRemoveDocsSource()`, `useStartDocsSync()`, `useCancelDocsSync()`, `useReleaseDocsSync()`, `useDocsSyncRun(id)`.

- [ ] **Step 1: Failing specs** (copy exact strings from §9): summary line; each note state (up to date, newer out, branch moved with short SHA, tag moved, not synced from this source, unreachable, rate-limited); each outcome in History ("Up to date", "Synced with errors: 2 pages failed", "Sync refused: …", "Sync failed: …", "Cancelled after 12 of 53 pages", "Interrupted after 30 of 53 pages: …", preview "Would change 3"); running state "Writing page 30 of 53" with Sync now / Preview / Edit source disabled and Cancel shown; Release shown only when the API marks the run releasable; form validation messages from a 422; "Set up documentation" when no docs type.
- [ ] **Step 2:** `cd admin && pnpm vitest run src/__tests__/docsSyncCard.spec.ts src/__tests__/docsSyncForm.spec.ts` → FAIL.
- [ ] **Step 3:** Implement with the page's existing patterns (`MarkdownFolderFields.vue` for the type picker and Set up documentation).
- [ ] **Step 4:** The specs PASS; then `pnpm run -s lint`, `npx oxfmt <touched files>`, `pnpm run -s fmt:check`, `pnpm type-check`, `pnpm test` → all exit 0.
- [ ] **Step 5: Commit** `feat(admin): Documentation from GitHub card`.

### Task 10: Docs, changelog, end-to-end

**Files:**
- Modify: `docs/documentation-sites.md` (new section "From a GitHub repo", §10), `CHANGELOG.md` (`[Unreleased]` → `### Added`)
- Test: `vendor/bin/phpunit tests/Unit/Docs`

- [ ] **Step 1:** Write the docs section: set up, Sync now / Preview, what it reads and refuses, each outcome, that it needs a worker taking the `import-export` queue and the scheduler running (`queue:scheduler run` from cron) for a stuck sync to be recovered on its own, that `thallo:provision` registers the two tasks (and warns when the scheduler's database store is off), and that runs are kept 30 days except the last sync.
- [ ] **Step 2:** CHANGELOG `### Added`: "**Documentation from a GitHub repo.** Settings › Import / Export can follow a public GitHub repo's docs folder, at its latest release tag or a branch: **Sync now** brings the pages to it, **Preview** shows what would change, and the card keeps each sync's outcome and pages."
- [ ] **Step 3:** `vendor/bin/phpunit tests/Unit/Docs` → PASS.
- [ ] **Step 4: Manual end-to-end** on the local site against the real `glueful/thallo`, `docs`, latest release, a worker on `import-export`: Preview, then Sync now; a second Sync now reports all unchanged; edit the source to a branch and back.
- [ ] **Step 5: Full gates** (one at a time): `composer phpcs` (exit 0), `vendor/bin/phpunit --testsuite Feature,Unit`, every `INTEGRATION_SHARD_*`, the tenancy harness files, `composer test:distribution`, `composer test:skeleton`, admin gates; then `composer test:reset-db && composer test:migrate`.
- [ ] **Step 6: Commit** `docs(docs-sync): documentation from a GitHub repo`.
