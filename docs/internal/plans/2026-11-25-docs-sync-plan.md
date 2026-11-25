# Docs sync from GitHub — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A site owner saves a public GitHub repo + folder once; **Sync now** / **Preview** bring the site's docs to the followed release tag or branch through one dedicated, fenced, resumable sync job.

**Architecture:** Three tables (`docs_sync_state`, `docs_sync_runs`, `docs_sync_run_pages`) after the palette's state-row pattern. `DocsSyncService` reserves the site's slot and dispatches `DocsSyncJob {run, workspace}`. `DocsSyncRunner`, inside the run's workspace, claims a lease, resolves the commit through `GithubClient`, downloads an attempt-owned archive, reads the folder with the importer's read step and writes each page in its own lease-fenced transaction with a savepoint. Recovery runs on every state read and from a self-rescheduling watchdog job.

**Tech Stack:** PHP 8.3 (MAMP PHP 8.4 locally), Glueful framework 1.93 (Connection transactions/savepoints, QueueManager, Job), Symfony HttpClient (`MockHttpClient` in tests), Composer semver, Vue 3 + Nuxt UI + Pinia Colada (admin), PHPUnit 10, Vitest.

**Spec:** `docs/internal/superpowers/specs/2026-11-25-docs-sync-from-github-design.md` (revision 5). Section numbers below (§N) are the spec's.

## Plan rulings (deviations from the spec, owner to confirm)

- **Ruling 1: the five-minute recovery is a self-rescheduling watchdog job, not a `config/schedule.php` entry** (§6.4). The scheduler reads only the site's own `config/schedule.php` (`JobScheduler::loadCoreJobsFromConfig`) and nothing merges into it, so an entry added there reaches new installs only. Instead, starting a run also pushes `DocsSyncWatchdogJob {run, workspace}` with `QueueManager::later(300, …)`; it runs recovery for that run and pushes itself again while the run is unfinished. Recovery on every state read (§9) stays. Cost if wrong: one more job class; a schedule entry can be added later without changing recovery.
- **Ruling 2: run pruning is done by the watchdog and on state read**, not a scheduled task, for the same reason (§4 "pruned after `import_export.retention_days`"): each finished run's watchdog, on its last pass, deletes this site's finished runs older than `import_export.retention_days` except `synced_run`, and their `docs-sync/{run}/` files once a day old.

## Global Constraints

- No framework change; framework is `glueful/framework ^1.93.0` (already required).
- Only two outbound hosts: `api.github.com`, `codeload.github.com`; no redirects off them (§5).
- Repo pattern `^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$`; folder relative, no `..`, no leading `/` (§4).
- Timeouts: 8 s API, 60 s archive; archive cap `import_export.max_file_size` (52428800), counted as streamed (§5).
- Status note cache: 5 minutes per site. Lease expiry: 120 s. Lease proof at least every 30 s while resolving/downloading. Re-dispatch at most once per 2 minutes per run; `reserved` re-dispatched after 2 minutes. Attempt budget: 5. Tag chain: at most 5 steps. Page cap: `MarkdownZip::MAX_FILES` (2000) (§5, §6).
- Queue: `import_export.queue` (`import-export`). `DocsSyncJob` queue retry limit 1 (§6.4).
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

### Task 5: Starting a run; recovery

**Files:**
- Create: `core/src/Content/DocsSync/DocsSyncService.php`, `core/src/Content/DocsSync/DocsSyncRunRepository.php`, `core/src/Content/Jobs/DocsSyncJob.php` (handle() stub calling `DocsSyncRunner` — Task 6), `core/src/Content/Jobs/DocsSyncWatchdogJob.php`
- Modify: `core/src/Providers/CoreServiceProvider.php` (bindings)
- Test: `tests/Integration/DocsSync/DocsSyncStartTest.php`, `tests/Integration/DocsSync/DocsSyncRecoveryTest.php`

**Interfaces:**
- Consumes: `DocsSyncStore` (Task 3).
- Produces:
  - `DocsSyncRunRepository`: `create(array $row): string`, `find(string $id): ?array`, `recent(int $limit): list<array>`, `pages(string $run): list<array>`, `increment(string $run, string $status): void`, `finish(string $run, string $outcome, ?string $message): void` (callers hold a fence), `markDispatched(string $run): void` (sets `last_dispatch_at`; `advanceStatus` to `dispatched`).
  - `DocsSyncService::start(string $mode, ?string $actor): array` (the run row) — validates `mode ∈ {commit, dry_run}`; throws `DocsSyncConflict::noSource()`, `::running()`, `::sourceChanged()`. One `transaction()`: insert run (`reserved`, revision, source copy, mode, workspace from the tenant context or null), slot CAS (§6.1 step 2), then **if** `config('queue.default')`'s driver is `database`: `QueueManager::push(DocsSyncJob::class, ['run' => $id, 'workspace' => $ws], $queue)` and `markDispatched()` inside the transaction; otherwise `afterCommit` push then `markDispatched()`. Also `later(300, DocsSyncWatchdogJob::class, same payload, $queue)` after commit.
  - `DocsSyncService::recover(): void` — §6.4 rules for the slot-holding run, under `slotFence` re-reading lease and now: live lease → nothing; `reserved` > 2 min or expired/no lease with `attempts < 5` and `last_dispatch_at` > 2 min ago → push `DocsSyncJob` again + `markDispatched()`; expired/no lease with `attempts >= 5` → finish `cancelled` if `cancel_requested` else `interrupted`, release slot. Prunes per Ruling 2.
  - `DocsSyncWatchdogJob::handle()`: inside the workspace (same entry as `RunPaletteReplaceJob`), `recover()`; if the run is not `finished`, `later(300, self::class, payload)`.

- [ ] **Step 1: Failing tests:**
  - Start: `testOneStartTakesTheSlotAndASecondIsRefused` (second `start` throws `running`, no second job row in the queue table), `testAStartAfterTheSourceChangedIsRefused` (bump revision between reading state and CAS via a store spy), `testDatabaseQueueReservesAndQueuesInOneTransaction` (force an exception after the push inside the transaction → no run, `active_run` null, no queued job row), `testWorkspaceIsCapturedOnTheRunAndThePayload`, `testOnlyCommitAndDryRunAreAccepted`.
  - Recovery (a fake `QueueManager` subclass recording pushes, as in the framework's `VariantRecordingQueue`): `testAReservedRunNeverPushedIsRedispatchedAfterTwoMinutes`, `testRedispatchIsBoundedToOncePerTwoMinutes`, `testALiveLeaseIsLeftAloneEvenOnTheFifthAttempt`, `testAnExpiredFifthAttemptIsInterrupted`, `testAnExpiredFifthAttemptWithCancelRequestedIsCancelled`, `testTheWatchdogRequeuesItselfUntilTheRunFinishes`, `testPruningKeepsSyncedRun`.
- [ ] **Step 2:** Run both files → FAIL.
- [ ] **Step 3:** Implement. Time comes from an injectable clock (`\Psr\Clock\ClockInterface` if bound, else a small `DocsSyncClock` with `now(): \DateTimeImmutable`) so tests age leases without sleeping.
- [ ] **Step 4:** Run both files → PASS.
- [ ] **Step 5: Commit** `feat(docs-sync): reserve and dispatch a run; recovery and the watchdog`.

### Task 6: The runner — lease, archive, fenced pages, finish

**Files:**
- Create: `core/src/Content/DocsSync/DocsSyncRunner.php`
- Modify: `core/src/Content/Jobs/DocsSyncJob.php` (workspace entry exactly as `RunPaletteReplaceJob::handle()`, with `Workspace`/tenant context forgotten first so a long-lived worker starts clean; `$maxAttempts = 1`)
- Test: `tests/Integration/DocsSync/DocsSyncRunnerTest.php`, `tests/Integration/DocsSync/DocsSyncFencingTest.php`, `tests/Integration/DocsSync/DocsSyncWorkspaceTest.php`

**Interfaces:**
- Consumes: Task 1 `read()`/`writePage()`, Task 2 `within`, Task 3 store and fences, Task 4 client, Task 5 repository.
- Produces: `DocsSyncRunner::run(string $runId): void` implementing §6.2–§6.3 and §7:
  - Claim: one `transaction()` — lease CAS (§6.2), `advanceStatus(running)`, `attempts + 1`; budget-spent and live-lease branches per §6.2 (live lease → `later(60, DocsSyncJob, payload)`).
  - Resolve/download/read each under `ownerFence`, `.part` → check → rename → record `archive_path` (§6.3 step 2); extraction with `MarkdownZip::extract(..., within: $folder)` into a temp dir; `read()` with the source's options and `edit_base` `https://github.com/{repo}/edit/{edit_branch}/{folder}`; refusals → `refused`.
  - Each page not yet in `docs_sync_run_pages`: `transaction()` { `ownerFence` or stop; `cancel_requested` → finish `cancelled`; nested `transaction()` (savepoint) around `writePage()` with `dry_run` passed through; on throw the savepoint rolls back and the row is `failed` with the message; insert page row; `increment` }.
  - Finish: `transaction()` { `ownerFence`; `cancel_requested` → `cancelled`; `gone` rows from `MarkdownFolderRead::missing`; outcome from counters (§7 table); `synced_run` per §6.3 step 5; release slot; `finished` }; then delete own files.
  - Fencing hook for tests: an optional `?\Closure $pause` called with `('page', $path)` before each page's write and `('download')` after the `.part` is written, injected only by tests.

- [ ] **Step 1: Failing tests** — archives served by `MockHttpClient` from zips built in the test:
  - Runner: `testASyncWritesEveryPageAndLands` (outcome `success`, `synced_run` set, counters equal page rows), `testPartialFailedAndRefusedOutcomes` (one invalid page → `partial`; all invalid → `failed`; `within` matching nothing → `refused` with nothing written), `testAPageThatThrowsAfterItsDraftChangedLeavesNothing` (writer spy throws after `createDraft`: no entry, no draft, row `failed`, next page written), `testAHeldPublicationKeepsTheDraft` (review workflow on: `PublishBlocked` → row written with the message), `testPreviewWritesNothing` (run the real job from the queue with `dry_run`: no entry/draft/version/publication rows, `synced_run` unchanged; then `commit` writes them), `testMoreThanAThousandPagesCountExactly` (1,200 tiny pages), `testDeletedContentTypeIsRefused` (Review Focus 4), `testAHandImportFollowedBySyncUpdatesNotDuplicates` (run `MarkdownFolderImport::run()` on the same tree first).
  - Fencing: `testTwoDeliveriesWriteEachPageOnce`, `testATakeoverWaitsForThePageInFlightAndFencesTheOldAttempt` (two-process proof as the palette plan's Task 14: attempt A paused in a page via `$pause` in a child PHP process; parent attempts takeover/Release and watches it block (`pg_stat_activity` wait on PostgreSQL, or a busy wait on SQLite), releases A; A's page commits, A's next page is fenced; page content is B's), `testAStaleAttemptCannotRecordOrFinish`, `testAnAttemptKilledMidPageRollsThatPageBack` (child process `exit` inside the savepoint), `testOverlappingDownloadsKeepToTheirOwnFiles`, `testATruncatedDownloadIsDownloadedAgain`, `testAFastWorkerIsNotDowngradedByALateDispatched`.
  - Workspace: `testAFreshWorkerRunsInTheRunsWorkspace`, `testTwoWorkspacesInARowDoNotLeak` (tenancy harness, `RetrofittedTenantTestCase`, so these run in the `tenancy-harness` CI job), `testAMissingWorkspaceFailsTheRunWithoutLooping` (Review Focus 5).
- [ ] **Step 2:** Run the three files → FAIL.
- [ ] **Step 3:** Implement `DocsSyncRunner` and the job.
- [ ] **Step 4:** Run the three files → PASS; then `tests/Integration/Importers` → PASS.
- [ ] **Step 5: Commit** `feat(docs-sync): the fenced, resumable sync runner`.

### Task 7: Cancel and Release

**Files:**
- Modify: `core/src/Content/DocsSync/DocsSyncService.php`
- Test: `tests/Integration/DocsSync/DocsSyncCancelTest.php`

**Interfaces:**
- Produces: `DocsSyncService::cancel(string $run): array{finished: bool, outcome: ?string}` and `release(string $run): array{released: bool}` per §6.5 (slot fence first; Cancel on an unleased run finishes `cancelled` in the same transaction; Release only when no lease or lease > 120 s, finishing `interrupted`; a live lease → `release` returns `released: false`).

- [ ] **Step 1: Failing tests:** `testCancelDuringAPageLetsItCommitThenStops`, `testCancelBeforeTheLastPageFinishCommitsIsCancelled` and `testFinishBeforeCancelKeepsTheSuccess` (both orders, using the runner's `$pause` at finish), `testCancelOfAnUnclaimedRunFinishesItAtOnce`, `testReleaseRefusesALiveLease`, `testReleaseOfAStaleRunInterruptsItAndFencesTheOldAttempt`.
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

- [ ] **Step 1:** Write the docs section: set up, Sync now / Preview, what it reads and refuses, each outcome, that it needs a worker taking the `import-export` queue, and that runs are kept 30 days except the last sync.
- [ ] **Step 2:** CHANGELOG `### Added`: "**Documentation from a GitHub repo.** Settings › Import / Export can follow a public GitHub repo's docs folder, at its latest release tag or a branch: **Sync now** brings the pages to it, **Preview** shows what would change, and the card keeps each sync's outcome and pages."
- [ ] **Step 3:** `vendor/bin/phpunit tests/Unit/Docs` → PASS.
- [ ] **Step 4: Manual end-to-end** on the local site against the real `glueful/thallo`, `docs`, latest release, a worker on `import-export`: Preview, then Sync now; a second Sync now reports all unchanged; edit the source to a branch and back.
- [ ] **Step 5: Full gates** (one at a time): `composer phpcs` (exit 0), `vendor/bin/phpunit --testsuite Feature,Unit`, every `INTEGRATION_SHARD_*`, the tenancy harness files, `composer test:distribution`, `composer test:skeleton`, admin gates; then `composer test:reset-db && composer test:migrate`.
- [ ] **Step 6: Commit** `docs(docs-sync): documentation from a GitHub repo`.
