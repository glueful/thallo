# Update from the admin — Implementation Plan

> Amended 2026-10-11 after plan review (no spec revision):
> - **Operation writes are fenced** (Task 3).
>   - Every claim is a compare-and-set on the expected id and status, and mints a fresh `owner`.
>   - Every `update` and `finish` names the id and owner, and a runner that lost ownership gets
>     `Fenced`, never a write.
>   - `tick` re-reads the operation after taking the run lock.
>   - Tested by a paused tick resuming after completion and a new start, and by a stale owner against
>     its successor.
> - **Child-process ownership is wired through** (Tasks 7, 12).
>   - The run lock is inherited by every child.
>   - Recovery and takeover record the new runner's pid and group.
>   - `ChildProcess::stopGroupMembers` confirms that nothing the update started is still running
>     before rollback or reopening, and after a timeout.
>   - Tested by a child timeout with a grandchild and a killed recovery runner with a surviving
>     grandchild.
> - **Commands are judged by their own exit code and output** (Tasks 7, 12).
>   - `Host::runCapture` replaces log scraping.
>   - Cache clearing and the worker signal must succeed.
>   - `RealHost::forget` drops released handles before they close.
>   - `reopen_ready_at` (renamed from `verified_target_at`) is written only after every prerequisite
>     succeeded.
>   - `RunnerRealHostTest` covers stale verification output, a failed cache clear, inherited
>     descriptors and the run lock, through real processes.
> - **First enablement has a readiness protocol** (Tasks 6, 12, 13, 14).
>   - `tick` creates `verify.secret` before any operation.
>   - The self-test no longer takes an exclusive admission lock.
>   - The first guard install writes `guard.installed` and signals `queue:restart`.
>   - Preflight refuses until every pre-guard process has moved over. The second amendment below
>     replaces the settling delay with a verified restart.
>   - Proof scenario 20 covers workers started before enablement and guarded idle workers afterwards.
> - **Snapshots are verified before any live change** (Task 10).
>   - Every copy and mkdir is checked, and each file is re-hashed against the live one.
>   - The manifest records contents and modes, and restore refuses a snapshot that does not match.
>   - The route cache is `storage/cache/routes_*.php` (`RouteCache.php:24,182`).
>   - The inventory proof diffs full before/after manifests, deletions included.
> - **The proof** (Tasks 17, 18):
>   - it keeps the permissive root constraint (the target artifacts are added after install, never
>     `composer require …:900`);
>   - deploy and PHP-FPM run as separate users in CI;
>   - scenario 1 goes through `selftest`, an authenticated preflight and `POST /v1/admin/updates`;
>   - new scenarios 4c, 20, 21 and 22.

> Amended again 2026-10-11 after the second plan review:
> - **First enablement is a verified restart, not a delay** (Task 13).
>   - Preflight reports `restart_required` until the owner runs
>     `php bin/thallo-updater confirm-restart --yes`.
>   - `confirm-restart` refuses while any PHP-FPM/Apache process, or any CLI process attributable to
>     the site, started before the guard. The runner re-checks before every update.
>   - New proof scenario 23: a pre-guard HTTP request and a non-queue console writer, both held past
>     30 s; neither writes after the backup begins.
> - **Timeouts never block** (Task 7). `ChildProcess::run` escalates SIGTERM → SIGKILL, polls until
>   the child exits, and stops and confirms its descendants before the call that waits on the child.
>   - Tested with a child and grandchild that ignore SIGTERM.
>   - Composer has its own timeout (`THALLO_UPDATE_COMPOSER_TIMEOUT`), which scenario 21 now really
>     exercises.
> - **No recovery claim while the previous group lives** (Task 12).
>   - This applies to every claim of a `running` or `recovery_required` operation, explicit Reopen
>     included.
>   - A failed recovery stops its own descendants before recording its result.
>   - Tested with a descendant that closes its descriptors and keeps writing; proof scenario 4d.
> - **Snapshots record directories** with their modes, so empty directories are restored (Task 10).

> Amended a third time 2026-10-11 (approved for implementation with these, under the framework-release gate):
> - **The runner enforces the restart confirmation itself** (Task 12).
>   - `requireNoProblems()` applies the admin preflight's rule (`guard.ready` newer than
>     `guard.installed`), not only the process scan.
>   - Tested by a queued operation with no visible old processes and a missing or stale
>     confirmation: it fails at preflight, with no staging or backup.
> - **The readiness proofs fully restart PHP-FPM** (scenarios 20 and 23).
>   - `fpm_restart` stops the old instance gracefully, verifies that its master and every worker have
>     exited, then starts a new one. A reload keeps the master's process identity, so it is not
>     enough.

> Amended a fourth time 2026-10-11, after the whole-branch review, with spec revision 4 §16 (the
> trust boundary). Tasks 19–24 below move everything the runner trusts out of PHP-FPM's reach and
> fix the review's other critical and important findings. Where they contradict Tasks 3–18, they
> win.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An owner of an explicitly enabled, single-host Thallo install presses **Update** in the
admin. A runner, separate from the web process, installs the approved release with recoverable,
crash-safe steps.

**Architecture:**

- **The updater core.** It lives in `core/src/Updater/` (namespace `Thallo\Core\Updater`), as
  dependency-free classes with no framework, no Composer autoload assumptions and no `use` of vendor
  code. Unit tests cover these classes directly.
- **Two self-contained files built from those classes.** `scripts/build-updater` concatenates the
  classes into two committed files, and CI rejects stale copies:
  - `core/resources/updater/thallo-updater`, the runner;
  - `core/resources/updater/update-guard.php`, the guard.
- **Installed into the site by provision.** Provision copies the two files to `bin/thallo-updater`
  and `bootstrap/update-guard.php`. The skeleton's `public/index.php` and `glueful` include the
  guard before booting.
- **PHP-FPM's side** is three admin routes (preflight, start, cancel) and the update notice's
  button.
- **The runner's side** reaches the application only through child commands:
  `thallo:update:verify`, `thallo:update:backup` and `thallo:update:clear-caches`.
- **The framework** gains `queue:restart`.

**Tech Stack:** PHP 8.4 (PHPUnit 10, PostgreSQL `pg_dump`/`pg_restore`, POSIX `flock`, `proc_open`,
`posix_*`), Glueful framework, Vue 3 + Nuxt UI 4 (vitest), Bash, nginx + PHP-FPM for the proof.

**Spec:** `docs/internal/superpowers/specs/2026-10-11-admin-update-button-design.md`, revision 3
(`1e07e721`). It was approved with the five Review Focus items below. Section references (§N) are to
that spec.

**Depends on:**

- **The framework release that ships `queue:restart`** (Task 1). This is a user gate: the user cuts
  and publishes framework releases. Task 2 raises Thallo's floor once that release is published.
- **The skeleton release that ships the guard includes** (Task 4). Existing installs add the include
  lines by hand, and preflight reports `guard_missing` until they do.

## Global Constraints

- `core/src/Updater/*` imports nothing outside PHP itself. No `use Glueful\…`, `use Symfony\…` or
  `use Thallo\…` from outside `Thallo\Core\Updater`. It calls no framework helpers (`config()`,
  `env()`, `app()`). The build concatenates these files and must run with no autoloader.
- **Generated files are never edited by hand:** `core/resources/updater/thallo-updater` and
  `core/resources/updater/update-guard.php`. To change them, edit `core/src/Updater/*` and run
  `php scripts/build-updater`. CI runs `php scripts/build-updater --check`.
- The setting is `thallo.admin_updates.enabled` ← `THALLO_ADMIN_UPDATES`, default `false`. Composer
  installs never change it.
- Admin routes require `content_permission:system.config`, inside the existing
  `['tenant_system','auth']` group.
- Every file the updater writes lives under `storage/framework/updates/`, `storage/backups/update-{id}/`
  or `storage/logs/update-{id}.log`, plus the live tree changes that the journal records.
  `operation.json` has mode 0640. The backup folder is 0700 and its files 0600.
- **Composer:**
  - the command is exactly `composer update glueful/thallo-core:<target> --with-all-dependencies --no-interaction --no-progress --no-ansi --no-scripts`, run with `--working-dir=<stage>` and `COMPOSER_HOME=<base>/storage/framework/composer`;
  - provision runs as `thallo:provision --no-interaction`, never `--force`.
- The terminal upgrade command, everywhere it is printed, is
  `composer update glueful/thallo-core -W && php glueful thallo:provision`, followed by
  `php glueful queue:restart`.
- **Secrets:** the recovery token and the verification secret are separate. Each is 32 random bytes,
  hex-encoded. Only the token's SHA-256 is stored; the secret lives in `verify.secret`, rotated per
  operation. A token never authorizes `/_update/verify`, and the secret never authorizes
  `/_update/status` or `/_update/recover`.
- **Kill points** (`Runner::killPoint()`) fire only when `storage/framework/updates/.proof` exists
  **and** `THALLO_UPDATER_KILL_AT` names the point. Without both they are inert. No other test hook
  exists in shipped code.
- Gates: phpcs is judged by its exit code (warnings fail). The admin uses `pnpm type-check`,
  `pnpm lint` and `pnpm fmt:check`, and formats touched files only with `npx oxfmt`. Each new
  `tests/Integration/<Dir>` joins one `INTEGRATION_SHARD_*` in `.github/workflows/ci.yml`.
- A changelog bullet goes under [Unreleased] in the same commit as each user-visible change. Commits
  carry no AI attribution trailers. Nothing is pushed.
- `docs/openapi.json` is spliced by hand.
- Each shipped file has its own namespace: `Thallo\ShippedUpdater\Runner` and
  `Thallo\ShippedUpdater\Guard`. They never use `Thallo\Core\Updater`, so the guard's classes,
  loaded before the autoloader, can never stand in for the application's.
- `runner.json`'s `web` key is either `null` (the probe could not be reached) or
  `{"status": int, …the probe's fields}`. `UpdatePreflight` and `Runner::main` both read it that way.

## Review Focus

These are the five items the approver required. Each line names the condition, the behaviour a
reasonable owner expects, and the test that pins it.

1. **Crash-safe reopening.** The runner can die anywhere between removing `closed.flag`, releasing
   the barrier and recording `succeeded`.
   - **Expected:** a later `tick` finishes the reopen and records `succeeded`. Once traffic has
     resumed, it never treats the install as an unfinished swap and never rolls it back.
   - **The marker:** `reopen_ready_at` is written only after every reopen prerequisite has succeeded:
     both verifications, cache clearing, the worker signal, and no descendant left. A death before it
     reopens nothing.
   - **Pinned by:** Task 12, `testADeathAfterVerificationCompletesTheReopen` (three kill points:
     `reopen.before-open`, `reopen.after-open`, `reopen.after-release`), and proof scenario 17.
2. **Lock inheritance through descendants.** The runner and its immediate child both die while a
   grandchild still runs.
   - **Expected:** no `tick` claims the operation, and none declares it dead, until the grandchild
     exits.
   - **Pinned by:** Task 7, `testAGrandchildKeepsTheOperationAlive` (the run lock **and** the process
     group), and proof scenario 4b.
3. **Staged autoloading after relocation.** Loading from the stage alone proves nothing.
   - **Expected:** after the staged `vendor/` is renamed into place and the stage deleted, all of
     these still load from the live tree: a root `app/` class (PSR-4), a `classmap` class and an
     `autoload.files` function.
   - **Pinned by:** Task 9, `testTheRelocatedVendorLoadsRootClassesAfterTheStageIsGone`, and proof
     scenario 1's post-swap assertions.
4. **Complete provision-output inventory.**
   - **Expected:** every path provision can change, including `bin/thallo-updater`,
     `bootstrap/update-guard.php`, `.env` and `config/extensions.php`, has an explicit rule:
     snapshot-and-restore, clear, or retain. A path provision changes that is not in the inventory
     fails the proof.
   - **Pinned by:** Task 10, `ProvisionOutputs` and its unit test, and proof scenario 18, which diffs
     the tree before and after a real provision against the inventory.
5. **Verification access.**
   - **Expected:** the recovery token cannot call `/_update/verify`, and the verification secret
     cannot call `/_update/status` or `/_update/recover`. A verification request made while the
     runner holds the barrier exclusively is answered and never waits on the barrier.
   - **Pinned by:** Task 6, `testTheRecoveryTokenCannotVerify`, `testTheSecretCannotReadStatus` and
     `testVerifyIsAnsweredWhileTheBarrierIsHeld`; proof scenario 19.

## Rulings made while planning (from the code)

1. **The backup runs its own `pg_dump --format=custom`.**
   - The framework `DatabaseBackupTask` writes `--format=plain` (`DatabaseBackupTask.php:97-121`),
     which `pg_restore --list` cannot read. It also returns `{backup_created, errors}` rather than
     throwing.
   - `thallo:update:backup` resolves the connection the same way (host, port, username, database,
     `PGPASSWORD`, `PGSSLMODE`) and runs `pg_dump -Fc` through the framework `ProcessRunner`.
   - **Cost if wrong:** one duplicated connection resolver.
2. **The runner and the guard are built from classes, not hand-written single files.**
   - The spec requires single self-contained files (§3). Hand-written 1,500-line files would be
     untestable.
   - Instead, the classes are tested normally and concatenated by `scripts/build-updater`, and CI
     checks the output is current, as `fragments-verified.json` is checked.
   - **Cost if wrong:** a build step to keep in sync, enforced by CI.
3. **A run that dies before any journaled rename and before provision is `failed`, and the site
   reopens**, not `recovery_required`.
   - The spec says a dead run is `recovery_required` (§5). But before step 6 the live install is
     untouched by construction (§8: "Steps 1–5 never change the live install"), and the journal
     proves it.
   - **Cost if wrong:** a site reopens after a staging-only death, which is safe because nothing live
     changed.
4. **A dead run that had verified the target completes the reopen** (Review Focus 1). The spec's
   "dead → recovery_required" applies only before `reopen_ready_at` is recorded.
5. **Liveness needs the run lock free *and* the runner's process group empty.**
   - The runner makes itself a process-group leader (`posix_setpgid(0, 0)`) and records the group id.
   - Descriptor inheritance alone cannot be relied on, because a descendant may close inherited
     descriptors. The group survives that.
   - **Cost if wrong:** a descendant that calls `setsid` escapes the group. Composer does not do
     this, and proof scenario 4b pins it.
6. **The admin has no permission store.** The card asks for preflight. A `403` (not `system.config`)
   or `{reasons:["disabled"]}` hides the button and keeps today's card.
7. **CSRF needs nothing.** Admin mutations are bearer-authenticated, and `security.skip_for_bearer_auth`
   is set.
8. **The guard and runner keep a small duplicated surface.** Both read the operation file. Both are
   generated from the same `OperationStore` class, so there is one implementation.
9. **`thallo:update:clear-caches`** wraps `UpgradeCaches::clear()` and invalidates the
   `thallo:render:page` and `thallo:shop:catalog` tags.
   - Rollback runs it under the restored, older code. It therefore exists in every release from the
     feature's first one on, and the feature's floor guarantees `from` has it.
10. **The guard and runner are installed only when the feature is on** (Task 13). A guard on every
    install would cost every request an `admission.lock` lock for nothing. Enabling the feature means
    one `thallo:provision` run, and the self-test says so. **Cost if wrong:** one documented step.
11. **The feature's changelog bullet lands with its docs (Task 16).** Tasks 13–15 are not usable
    until then, and no beta is cut between Tasks 13 and 18; the proof is the release gate.
12. **The guided installer itself is not in this plan**, because no guided installer exists yet.
    Its contract is `php bin/thallo-updater selftest` (Task 12) plus the documented opt-in (Task 16).
    The installer will call the self-test and enable the setting only when it passes.

## File map

| File | Responsibility |
|---|---|
| framework `src/Console/Commands/Queue/RestartCommand.php` | `queue:restart`: stores the restart timestamp |
| framework `src/Queue/QueueWorker.php` | exits after the current job when the restart timestamp is newer than its start |
| `core/src/Updater/Paths.php` | every updater file location from the base path |
| `core/src/Updater/FileLock.php` | `flock` helpers (exclusive, shared, non-blocking, with-lock) |
| `core/src/Updater/AtomicFile.php` | JSON write (temp + `fsync` + rename, mode) and read |
| `core/src/Updater/Status.php` | status constants and which are terminal |
| `core/src/Updater/UpdateRefused.php` | refusal with a reason code |
| `core/src/Updater/OperationStore.php` | the operation file and its compare-and-set transitions |
| `core/src/Updater/Barrier.php` | `closed.flag` and the admission lock (§7) |
| `core/src/Updater/GuardResponse.php` | status, headers and body, plus `send()` |
| `core/src/Updater/RecoveryPage.php` | the self-contained `/_update` HTML |
| `core/src/Updater/Guard.php` | web and console admission and the guard routes |
| `core/src/Updater/Host.php`, `RealHost.php` | process, HTTP, clock and sleep, behind an interface |
| `core/src/Updater/ChildProcess.php` | runs a child with the inherited lock descriptor, heartbeats while polling |
| `core/src/Updater/Liveness.php` | dead-run rule: run lock free and process group empty |
| `core/src/Updater/Journal.php` | journaled renames, resume, reverse |
| `core/src/Updater/ComposerShape.php` | the supported root `composer.json` shape (§8.1) |
| `core/src/Updater/ProvisionOutputs.php` | the provision-output inventory, snapshot and restore |
| `core/src/Updater/Runner.php` | tick, claim, the ten steps, reopen, recovery, token, selftest |
| `core/src/Updater/RunnerReport.php` | the runner-side preflight report (`runner.json`) |
| `scripts/build-updater` | concatenates the classes into the two shipped files; `--check` |
| `core/resources/updater/thallo-updater`, `update-guard.php` | generated, committed |
| `core/src/Setup/UpdaterInstaller.php` | provision step: installs the two files into the site |
| `core/src/Updates/UpdatePreflight.php` | FPM-side preflight (§4) |
| `core/src/Updates/Console/UpdateVerifyCommand.php` | `thallo:update:verify --json` |
| `core/src/Updates/Console/UpdateBackupCommand.php` | `thallo:update:backup --to= --json` |
| `core/src/Updates/Console/UpdateClearCachesCommand.php` | `thallo:update:clear-caches` |
| `core/src/Http/Controllers/AdminUpdateController.php` | preflight, start, cancel |
| `core/src/Http/DTOs/Responses/UpdatePreflightData.php`, `UpdateStartData.php` | response shapes |
| `skeleton/public/index.php`, `skeleton/glueful` (and the identical root copies) | include the guard |
| `admin/src/queries/updates.ts` | preflight query, start mutation, `UPGRADE_COMMAND` |
| `admin/src/components/UpdateAvailableCard.vue`, `UpdateConfirmModal.vue` | the button and the dialog |
| `scripts/update-proof`, `scripts/lib/update-proof/*` | the disposable-install proof (release gate) |
| `docs/upgrading.md`, `docs/production.md`, DISTRIBUTION.md, CHANGELOG.md | docs |

---

## Phase A — prerequisites

### Task 1: Framework `queue:restart` (repo `../framework`)

**Files:**
- Create: `../framework/src/Console/Commands/Queue/RestartCommand.php`
- Create: `../framework/src/Queue/RestartSignal.php`
- Modify: `../framework/src/Queue/QueueWorker.php:35-41` (constructor), `:50-134` (`daemon` loop)
- Test: `../framework/tests/Unit/Queue/QueueRestartTest.php`
- Modify: `../framework/CHANGELOG.md` ([Unreleased] → Added)

**Interfaces:**
- Produces:
  - `Glueful\Queue\RestartSignal`:
    - `__construct(?CacheStore $cache)`
    - `signal(int $at): void` — stores `queue:restart` = `$at`, with no TTL;
    - `requestedAfter(int $startedAt): bool` — true when a stored timestamp is `>= $startedAt`;
    - with a null cache, `signal` is a no-op and `requestedAfter` is false.
  - The `queue:restart` command, exit 0.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Cache\CacheStore;
use Glueful\Queue\RestartSignal;
use PHPUnit\Framework\TestCase;

final class QueueRestartTest extends TestCase
{
    public function testASignalAfterAWorkerStartedAsksItToRestart(): void
    {
        $signal = new RestartSignal($this->arrayCache());
        $signal->signal(1000);
        self::assertTrue($signal->requestedAfter(1000));
        self::assertTrue($signal->requestedAfter(999));
        self::assertFalse($signal->requestedAfter(1001), 'a worker started after the signal keeps running');
    }

    public function testNoSignalNoRestart(): void
    {
        self::assertFalse((new RestartSignal($this->arrayCache()))->requestedAfter(1));
    }

    public function testWithoutACacheNothingRestarts(): void
    {
        $signal = new RestartSignal(null);
        $signal->signal(5);
        self::assertFalse($signal->requestedAfter(1));
    }

    private function arrayCache(): CacheStore
    {
        // An in-memory CacheStore: reuse the framework's array driver the way QueueWorkerTest boots it
        // (config cache.php 'default' => 'array'), or a minimal anonymous class implementing get/set.
        return new \Glueful\Tests\Support\ArrayCacheStore();
    }
}
```

If `Glueful\Tests\Support\ArrayCacheStore` does not exist, create it in
`../framework/tests/Support/ArrayCacheStore.php`. It implements `CacheStore`: `get` and `set` use a
private array, and every other interface method has the simplest correct body (`has` checks the
array; `delete` unsets).

- [ ] **Step 2: Add the worker test** to the same file. It boots the way `QueueWorkerTest::setUp`
  does (copy its temp-app setup) and pushes three jobs. It signals a restart after the first job by
  pushing a job whose `handle()` calls `(new RestartSignal($cache))->signal(time())`. It then runs
  `daemon('database', ['default'], new WorkerOptions(sleep: 0, stopWhenEmpty: true))` and asserts:
  - the method returns `0`;
  - only that first job ran, and the other two are still queued.

- [ ] **Step 3: Run to see them fail**

Run: `cd ../framework && vendor/bin/phpunit tests/Unit/Queue/QueueRestartTest.php`
Expected: FAIL — `Class "Glueful\Queue\RestartSignal" not found`.

- [ ] **Step 4: Implement**

```php
<?php

declare(strict_types=1);

namespace Glueful\Queue;

use Glueful\Cache\CacheStore;

/**
 * A request for every running worker to exit after its current job (queue:restart). A supervisor
 * starts it again on whatever code is installed then. Without a cache store nothing is signalled.
 */
final class RestartSignal
{
    public const KEY = 'queue:restart';

    public function __construct(private readonly ?CacheStore $cache)
    {
    }

    public function signal(int $at): void
    {
        $this->cache?->set(self::KEY, $at);
    }

    public function requestedAfter(int $startedAt): bool
    {
        $at = $this->cache?->get(self::KEY);
        return is_int($at) && $at >= $startedAt;
    }
}
```

In `QueueWorker`, add the constructor parameter `private ?RestartSignal $restart = null` last, so
existing callers keep working. In `daemon()`, directly after `$this->process($job, $queue, $options);
$processedJobs++;` (L102) and again beside the `maxRuntime` check (L110), add:

```php
if ($this->restart?->requestedAfter($startedAt) === true) {
    return self::SUCCESS;
}
```

In `QueueProvider` (`src/Queue/ServiceProvider/QueueProvider.php:29-30`), register `RestartSignal`
with a factory that tries `container($context)->get(CacheStore::class)` and passes null when that
throws. Pass the signal to `QueueWorker`.

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Console\BaseCommand;
use Glueful\Queue\RestartSignal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:restart', description: 'Ask every queue worker to exit after its current job')]
final class RestartCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->getContainer()->get(RestartSignal::class)->signal(time());
        $this->info('Workers will exit after their current job; your supervisor starts them again.');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the queue tests and the framework gates**

Run: `cd ../framework && vendor/bin/phpunit tests/Unit/Queue && composer phpcs; echo phpcs=$? && composer phpstan`
Expected: PASS, `phpcs=0`, phpstan clean.

- [ ] **Step 6: Changelog and commit**

In `../framework/CHANGELOG.md`, under [Unreleased] → Added: "**`queue:restart`**: running workers
exit after their current job once it is run, so a supervisor restarts them on newly installed code."

```bash
cd ../framework && git add src/Queue/RestartSignal.php src/Console/Commands/Queue/RestartCommand.php src/Queue/QueueWorker.php src/Queue/ServiceProvider/QueueProvider.php tests/Unit/Queue/QueueRestartTest.php tests/Support/ArrayCacheStore.php CHANGELOG.md
git commit -m "feat(queue): queue:restart asks running workers to exit after their current job"
```

### Task 2: USER GATE — framework release, then raise Thallo's floor

**Files:**
- Modify: `core/composer.json:18`, `composer.json:80` (`glueful/framework` constraint)

- [ ] **Step 1: Stop and hand over.** Tell the user the framework commit is ready. Cutting and
  publishing the framework release is theirs. Wait for the published version number, e.g. `1.89.0`.
- [ ] **Step 2: Raise the floor** to `^<published>` in both files. Run `composer update glueful/framework`
  under MAMP PHP (`export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH`).
- [ ] **Step 3: Verify** with `php glueful list queue | grep queue:restart`.
  Expected: the command is listed.
- [ ] **Step 4: Commit** with the message
  "chore(deps): require glueful/framework ^<published> (queue:restart)".

### Task 3: Updater foundation — paths, locks, atomic files, operation store, build script

**Files:**
- Create: `core/src/Updater/{Paths,FileLock,AtomicFile,Status,UpdateRefused,OperationStore}.php`
- Create: `scripts/build-updater`
- Test: `tests/Unit/Updater/OperationStoreTest.php`, `tests/Unit/Updater/BuildUpdaterTest.php`
- Modify: `.github/workflows/ci.yml` (add the `--check` step to the skeleton-smoke job, beside the admin checks)

**Interfaces:**
- Produces (all `Thallo\Core\Updater`):

```php
final class Paths {
    public function __construct(public readonly string $base) {}
    public function dir(): string;            // <base>/storage/framework/updates
    public function operation(): string;      // dir/operation.json
    public function stateLock(): string;      // dir/state.lock
    public function runLock(): string;        // dir/run.lock
    public function admissionLock(): string;  // dir/admission.lock
    public function closedFlag(): string;     // dir/closed.flag
    public function runnerReport(): string;   // dir/runner.json
    public function verifySecret(): string;   // dir/verify.secret
    public function proofMarker(): string;    // dir/.proof
    public function opDir(string $id): string;      // dir/<id>
    public function backupDir(string $id): string;  // <base>/storage/backups/update-<id>
    public function log(string $id): string;        // <base>/storage/logs/update-<id>.log
}
final class FileLock {
    /** @return resource|null  null when $block is false and the lock is held elsewhere */
    public static function exclusive(string $path, bool $block = true);
    /** @return resource|null  non-blocking */
    public static function shared(string $path);
    /** @param resource $handle */
    public static function release($handle): void;
    public static function withExclusive(string $path, callable $fn): mixed;
}
final class AtomicFile {
    public static function writeJson(string $path, array $data, int $mode = 0640): void;
    public static function readJson(string $path): ?array;
    public static function write(string $path, string $contents, int $mode = 0640): void;
}
final class Status {
    public const REQUESTED = 'requested', RUNNING = 'running', SUCCEEDED = 'succeeded',
        FAILED = 'failed', ROLLED_BACK = 'rolled_back', RECOVERY_REQUIRED = 'recovery_required',
        RECOVERED_BY_OWNER = 'recovered_by_owner', CANCELLED = 'cancelled';
    public static function isTerminal(string $s): bool; // succeeded, failed, rolled_back, recovered_by_owner, cancelled
}
final class UpdateRefused extends \RuntimeException {
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}
final class Fenced extends \RuntimeException {} // declared in OperationStore.php: a write by a runner that no longer owns the operation
final class OperationStore {
    public const FORMAT = 1;
    public function __construct(private Paths $paths, private \Closure $now) {} // $now: fn(): int
    public function current(): ?array;
    /** @return array{0: array, 1: string} the operation and the one-time token */
    public function start(string $target, string $from, string $requestedBy): array; // UpdateRefused('in_progress'|'recovery_required')
    /** Each claim is a compare-and-set on the expected id and status, and mints a fresh `owner`. */
    public function claim(string $id, int $pid, ?int $pgid): ?array;          // requested → running
    public function claimRecovery(string $id, int $pid, ?int $pgid): ?array;  // recovery_required + request → running; `recovering` = the option
    public function takeOver(string $id, string $deadOwner, int $pid, ?int $pgid): ?array; // a dead run's ownership moves to the finder
    public function cancel(string $id): bool;                                 // requested → cancelled
    /** Fenced unless the current operation has this id, this owner and status running. */
    public function update(string $id, string $owner, callable $mutate): array;
    public function finish(string $id, string $owner, string $status, ?string $error = null): array;
    public function verifyToken(string $token): bool;
    public function rotateToken(string $id): ?string;
    public function requestRecovery(string $token, string $option): bool;     // token and status checked in one locked section
    public function requestRecoveryAsOwner(string $id, string $option): bool; // the terminal: file access is the authority
}
}
```

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Fenced;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\Status;
use Thallo\Core\Updater\UpdateRefused;

final class OperationStoreTest extends TestCase
{
    private string $base;
    private OperationStore $ops;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-ops-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        $this->ops = new OperationStore(new Paths($this->base), static fn (): int => 1_000);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testStartRecordsTheRequestAndReturnsATokenOnlyOnce(): void
    {
        [$op, $token] = $this->ops->start('1.0.0-beta.102', '1.0.0-beta.101', 'owner-uuid');
        self::assertSame(Status::REQUESTED, $op['status']);
        self::assertSame(64, strlen($token));
        $stored = (string) file_get_contents($this->base . '/storage/framework/updates/operation.json');
        self::assertStringNotContainsString($token, $stored, 'only the hash is stored');
        self::assertTrue($this->ops->verifyToken($token));
        self::assertFalse($this->ops->verifyToken(str_repeat('0', 64)));
        self::assertSame('0640', substr(sprintf('%o', fileperms($this->base . '/storage/framework/updates/operation.json')), -4));
    }

    public function testASecondStartIsRefusedWhileOneIsOpen(): void
    {
        $this->ops->start('2', '1', 'u');
        $this->expectExceptionObject(new UpdateRefused('in_progress'));
        $this->ops->start('2', '1', 'u');
    }

    public function testRecoveryRequiredBlocksANewStart(): void
    {
        [$op] = $this->ops->start('2', '1', 'u');
        $run = $this->ops->claim($op['id'], 10, 10);
        $this->ops->finish($op['id'], $run['owner'], Status::RECOVERY_REQUIRED, 'provision failed');
        try {
            $this->ops->start('2', '1', 'u');
            self::fail('expected a refusal');
        } catch (UpdateRefused $e) {
            self::assertSame('recovery_required', $e->reason);
        }
    }

    public function testClaimsAreCompareAndSetOnTheExpectedOperation(): void
    {
        [$op] = $this->ops->start('2', '1', 'u');
        self::assertNull($this->ops->claim('some-other-id', 10, 10), 'a claim names the operation it read');
        $run = $this->ops->claim($op['id'], 10, 10);
        self::assertSame(32, strlen($run['owner']));
        self::assertNull($this->ops->claim($op['id'], 11, 11), 'a second claim gets nothing');
        self::assertFalse($this->ops->cancel($op['id']), 'a claimed operation cannot be cancelled');
    }

    public function testAStaleOwnerCannotWriteAFinishedOrNewerOperation(): void
    {
        [$first] = $this->ops->start('2', '1', 'u');
        $run = $this->ops->claim($first['id'], 10, 10);
        $this->ops->finish($first['id'], $run['owner'], Status::SUCCEEDED);
        try {
            $this->ops->update($first['id'], $run['owner'], static fn (array $op): array => ['step' => 'late'] + $op);
            self::fail('a finished operation accepts no more writes');
        } catch (Fenced) {
        }
        [$second] = $this->ops->start('3', '2', 'u');
        foreach ([[$first['id'], $run['owner']], [$second['id'], $run['owner']]] as [$id, $owner]) {
            try {
                $this->ops->finish($id, $owner, Status::FAILED, 'late');
                self::fail('a stale runner cannot touch the successor');
            } catch (Fenced) {
            }
        }
        self::assertSame(Status::REQUESTED, $this->ops->current()['status']);
        self::assertSame($second['id'], $this->ops->current()['id']);
    }

    public function testTakeOverNeedsTheDeadOwner(): void
    {
        [$op] = $this->ops->start('2', '1', 'u');
        $run = $this->ops->claim($op['id'], 10, 10);
        self::assertNull($this->ops->takeOver($op['id'], 'not-the-owner', 20, 20));
        $new = $this->ops->takeOver($op['id'], $run['owner'], 20, 20);
        self::assertNotSame($run['owner'], $new['owner']);
        self::assertSame(20, $new['pgid'], 'the finder records its own process group');
        $this->expectException(Fenced::class);
        $this->ops->update($op['id'], $run['owner'], static fn (array $o): array => $o);
    }

    public function testRecoveryRequestsNeedTheTokenAndTheState(): void
    {
        [$op, $token] = $this->ops->start('2', '1', 'u');
        self::assertFalse($this->ops->requestRecovery($token, 'reopen'), 'not in recovery');
        $run = $this->ops->claim($op['id'], 10, 10);
        $this->ops->finish($op['id'], $run['owner'], Status::RECOVERY_REQUIRED);
        self::assertFalse($this->ops->requestRecovery(str_repeat('a', 64), 'rollback'), 'wrong token');
        self::assertFalse($this->ops->requestRecovery($token, 'format-disk'), 'unknown option');
        self::assertTrue($this->ops->requestRecovery($token, 'rollback'));
        $rec = $this->ops->claimRecovery($op['id'], 30, 30);
        self::assertSame('rollback', $rec['recovering']);
        self::assertSame(30, $rec['pgid'], 'recovery records fresh ownership');
        self::assertNotSame($run['owner'], $rec['owner']);
    }

    public function testRotatingTheTokenInvalidatesTheOldOne(): void
    {
        [$op, $old] = $this->ops->start('2', '1', 'u');
        $new = $this->ops->rotateToken($op['id']);
        self::assertFalse($this->ops->verifyToken($old));
        self::assertTrue($this->ops->verifyToken((string) $new));
        self::assertNull($this->ops->rotateToken('other-id'));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;

/** The shipped single files are current, lint clean, and use nothing outside PHP. */
final class BuildUpdaterTest extends TestCase
{
    public function testTheShippedFilesAreCurrent(): void
    {
        $root = dirname(__DIR__, 3);
        exec('php ' . escapeshellarg($root . '/scripts/build-updater') . ' --check 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    public function testTheUpdaterClassesImportNothing(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/core/src/Updater/*.php') as $file) {
            $src = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/^use (?!function |const )/m', $src, basename($file) . ' imports a class');
            self::assertDoesNotMatchRegularExpression('/\b(config|env|app|container)\(/', $src, basename($file) . ' calls a framework helper');
        }
    }
}
```

- [ ] **Step 2: Run to see them fail**

Run: `php vendor/bin/phpunit tests/Unit/Updater`
Expected: FAIL — `Class "Thallo\Core\Updater\OperationStore" not found`.

- [ ] **Step 3: Implement the foundation**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Every file the updater reads or writes, from the site's base path. Dependency-free: this class is
 * concatenated into bin/thallo-updater and bootstrap/update-guard.php (scripts/build-updater).
 */
final class Paths
{
    public function __construct(public readonly string $base)
    {
    }

    public function dir(): string { return $this->base . '/storage/framework/updates'; }
    public function operation(): string { return $this->dir() . '/operation.json'; }
    public function stateLock(): string { return $this->dir() . '/state.lock'; }
    public function runLock(): string { return $this->dir() . '/run.lock'; }
    public function admissionLock(): string { return $this->dir() . '/admission.lock'; }
    public function closedFlag(): string { return $this->dir() . '/closed.flag'; }
    public function runnerReport(): string { return $this->dir() . '/runner.json'; }
    public function verifySecret(): string { return $this->dir() . '/verify.secret'; }
    public function proofMarker(): string { return $this->dir() . '/.proof'; }
    public function opDir(string $id): string { return $this->dir() . '/' . $id; }
    public function backupDir(string $id): string { return $this->base . '/storage/backups/update-' . $id; }
    public function log(string $id): string { return $this->base . '/storage/logs/update-' . $id . '.log'; }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** flock helpers. A lock lives as long as its handle (or the process); release() closes it. */
final class FileLock
{
    /** @return resource|null */
    public static function exclusive(string $path, bool $block = true)
    {
        $h = fopen($path, 'c');
        if ($h === false) {
            throw new \RuntimeException("Cannot open lock {$path}");
        }
        if (!flock($h, $block ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($h);
            return null;
        }
        return $h;
    }

    /** @return resource|null */
    public static function shared(string $path)
    {
        $h = @fopen($path, 'c');
        if ($h === false) {
            return null;
        }
        if (!flock($h, LOCK_SH | LOCK_NB)) {
            fclose($h);
            return null;
        }
        return $h;
    }

    /** @param resource $handle */
    public static function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public static function withExclusive(string $path, callable $fn): mixed
    {
        $h = self::exclusive($path);
        try {
            return $fn();
        } finally {
            self::release($h);
        }
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** Whole-file writes that are never seen half-written: temp file, fsync, rename. */
final class AtomicFile
{
    public static function write(string $path, string $contents, int $mode = 0640): void
    {
        $tmp = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $h = fopen($tmp, 'x');
        if ($h === false) {
            throw new \RuntimeException("Cannot write {$tmp}");
        }
        fwrite($h, $contents);
        fflush($h);
        if (function_exists('fsync')) {
            fsync($h);
        }
        fclose($h);
        chmod($tmp, $mode);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot replace {$path}");
        }
    }

    public static function writeJson(string $path, array $data, int $mode = 0640): void
    {
        self::write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", $mode);
    }

    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class Status
{
    public const REQUESTED = 'requested';
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const ROLLED_BACK = 'rolled_back';
    public const RECOVERY_REQUIRED = 'recovery_required';
    public const RECOVERED_BY_OWNER = 'recovered_by_owner';
    public const CANCELLED = 'cancelled';

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::SUCCEEDED, self::FAILED, self::ROLLED_BACK, self::RECOVERED_BY_OWNER, self::CANCELLED], true);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class UpdateRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class Fenced extends \RuntimeException
{
}

/**
 * The one update operation (spec §5). Every transition is a compare-and-set under a short exclusive
 * lock on state.lock, on the operation id, status and owner the caller expects. A runner that lost
 * ownership (it was paused, found dead, or the operation finished) gets Fenced, never a write.
 */
final class OperationStore
{
    public const FORMAT = 1;
    public const RECOVERY_OPTIONS = ['rollback', 'retry_provision', 'reopen'];

    public function __construct(private readonly Paths $paths, private readonly \Closure $now)
    {
    }

    public function current(): ?array
    {
        return AtomicFile::readJson($this->paths->operation());
    }

    /** @return array{0: array, 1: string} */
    public function start(string $target, string $from, string $requestedBy): array
    {
        return $this->locked(function () use ($target, $from, $requestedBy): array {
            $op = $this->current();
            if ($op !== null && $op['status'] === Status::RECOVERY_REQUIRED) {
                throw new UpdateRefused('recovery_required');
            }
            if ($op !== null && !Status::isTerminal($op['status'])) {
                throw new UpdateRefused('in_progress');
            }
            $token = bin2hex(random_bytes(32));
            $op = [
                'format' => self::FORMAT,
                'id' => gmdate('YmdHis', ($this->now)()) . '-' . bin2hex(random_bytes(3)),
                'target' => $target,
                'from' => $from,
                'status' => Status::REQUESTED,
                'step' => null,
                'steps' => [],
                'requested_by' => $requestedBy,
                'requested_at' => ($this->now)(),
                'token_hash' => hash('sha256', $token),
                'owner' => null,
                'pid' => null,
                'pgid' => null,
                'heartbeat_at' => null,
                'provision_started' => false,
                'reopen_ready_at' => null,
                'journal' => [],
                'backup' => null,
                'provision_manifest' => null,
                'recovery_request' => null,
                'recovering' => null,
                'error' => null,
            ];
            $this->write($op);
            return [$op, $token];
        });
    }

    public function claim(string $id, int $pid, ?int $pgid): ?array
    {
        return $this->transition($id, static fn (array $op): bool => $op['status'] === Status::REQUESTED, $pid, $pgid);
    }

    public function claimRecovery(string $id, int $pid, ?int $pgid): ?array
    {
        return $this->transition($id, static fn (array $op): bool => $op['status'] === Status::RECOVERY_REQUIRED && $op['recovery_request'] !== null, $pid, $pgid, true);
    }

    public function takeOver(string $id, string $deadOwner, int $pid, ?int $pgid): ?array
    {
        return $this->transition($id, static fn (array $op): bool => $op['status'] === Status::RUNNING && hash_equals((string) $op['owner'], $deadOwner), $pid, $pgid);
    }

    public function cancel(string $id): bool
    {
        return $this->locked(function () use ($id): bool {
            $op = $this->current();
            if ($op === null || $op['id'] !== $id || $op['status'] !== Status::REQUESTED) {
                return false;
            }
            $op['status'] = Status::CANCELLED;
            $this->write($op);
            return true;
        });
    }

    public function update(string $id, string $owner, callable $mutate): array
    {
        return $this->locked(function () use ($id, $owner, $mutate): array {
            $op = $this->owned($id, $owner);
            $op = $mutate($op);
            $op['id'] = $id; // a mutation can never move the write to another operation
            $op['owner'] = $owner;
            $this->write($op);
            return $op;
        });
    }

    public function finish(string $id, string $owner, string $status, ?string $error = null): array
    {
        return $this->update($id, $owner, static function (array $op) use ($status, $error): array {
            $op['status'] = $status;
            $op['error'] = $error;
            $op['recovery_request'] = null;
            $op['recovering'] = null;
            return $op;
        });
    }

    public function verifyToken(string $token): bool
    {
        $op = $this->current();
        return $op !== null && $token !== '' && hash_equals((string) $op['token_hash'], hash('sha256', $token));
    }

    public function rotateToken(string $id): ?string
    {
        return $this->locked(function () use ($id): ?string {
            $op = $this->current();
            if ($op === null || $op['id'] !== $id) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $op['token_hash'] = hash('sha256', $token);
            $this->write($op);
            return $token;
        });
    }

    public function requestRecovery(string $token, string $option): bool
    {
        return $this->locked(function () use ($token, $option): bool {
            $op = $this->current();
            if ($op === null || $token === '' || !hash_equals((string) $op['token_hash'], hash('sha256', $token))) {
                return false;
            }
            return $this->recordRecovery($op, $option);
        });
    }

    public function requestRecoveryAsOwner(string $id, string $option): bool
    {
        return $this->locked(function () use ($id, $option): bool {
            $op = $this->current();
            return $op !== null && $op['id'] === $id && $this->recordRecovery($op, $option);
        });
    }

    private function recordRecovery(array $op, string $option): bool
    {
        if (!in_array($option, self::RECOVERY_OPTIONS, true) || $op['status'] !== Status::RECOVERY_REQUIRED) {
            return false;
        }
        $op['recovery_request'] = $option;
        $this->write($op);
        return true;
    }

    private function transition(string $id, \Closure $allowed, int $pid, ?int $pgid, bool $recovery = false): ?array
    {
        return $this->locked(function () use ($id, $allowed, $pid, $pgid, $recovery): ?array {
            $op = $this->current();
            if ($op === null || $op['id'] !== $id || !$allowed($op)) {
                return null;
            }
            if ($recovery) {
                $op['recovering'] = $op['recovery_request'];
                $op['recovery_request'] = null;
            }
            $op['status'] = Status::RUNNING;
            $op['owner'] = bin2hex(random_bytes(16));
            $op['pid'] = $pid;
            $op['pgid'] = $pgid;
            $op['heartbeat_at'] = ($this->now)();
            $this->write($op);
            return $op;
        });
    }

    private function owned(string $id, string $owner): array
    {
        $op = $this->current();
        if ($op === null || $op['id'] !== $id || $op['status'] !== Status::RUNNING || !hash_equals((string) $op['owner'], $owner)) {
            throw new Fenced("Operation {$id} is no longer owned by this runner");
        }
        return $op;
    }

    private function write(array $op): void
    {
        AtomicFile::writeJson($this->paths->operation(), $op);
    }

    private function locked(callable $fn): mixed
    {
        if (!is_dir($this->paths->dir())) {
            mkdir($this->paths->dir(), 0770, true);
        }
        return FileLock::withExclusive($this->paths->stateLock(), $fn);
    }
}
```

`scripts/build-updater` (PHP, executable):

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

// Concatenates core/src/Updater/*.php into the two self-contained files the site runs
// (spec §3): bin/thallo-updater and bootstrap/update-guard.php, shipped from
// core/resources/updater/. --check exits 1 when a committed file differs from a fresh build.

$root = dirname(__DIR__);
$src = $root . '/core/src/Updater';
// Dependency order: a class appears after everything it references at load time (interfaces first).
$runnerClasses = ['Paths', 'FileLock', 'AtomicFile', 'Status', 'UpdateRefused', 'OperationStore', 'Barrier',
    'Host', 'RealHost', 'ChildProcess', 'Liveness', 'Journal', 'ComposerShape', 'Stager', 'ProvisionOutputs',
    'RunnerReport', 'Runner'];
$guardClasses = ['Paths', 'FileLock', 'AtomicFile', 'Status', 'UpdateRefused', 'OperationStore', 'Barrier',
    'GuardResponse', 'RecoveryPage', 'Guard'];

$body = static function (array $classes) use ($src): string {
    $out = '';
    foreach ($classes as $class) {
        $file = "{$src}/{$class}.php";
        if (!is_file($file)) {
            fwrite(STDERR, "missing {$file}\n");
            exit(2);
        }
        $code = (string) file_get_contents($file);
        $code = preg_replace(['/^<\?php\s*/', '/^declare\(strict_types=1\);\s*/m', '/^namespace [^;]+;\s*/m'], '', $code);
        $out .= "\n// ---- {$class} ----\n" . trim((string) $code) . "\n";
    }
    return $out;
};

// Each shipped file gets its own namespace. The guard runs inside every web request before the
// autoloader. Sharing Thallo\Core\Updater would make the autoloader treat the guard's (possibly older)
// classes as the application's own, so AdminUpdateController would use a stale OperationStore.
$header = static fn (string $what, string $ns): string => "<?php\n\n// GENERATED by scripts/build-updater from core/src/Updater — do not edit. {$what}\n\n"
    . "declare(strict_types=1);\n\nnamespace {$ns};\n";

$files = [
    'thallo-updater' => "#!/usr/bin/env php\n" . $header('The update runner (spec §3).', 'Thallo\\ShippedUpdater\\Runner') . $body($runnerClasses)
        . "\n// ---- entry ----\nif (PHP_SAPI === 'cli' && isset(\$argv[0]) && realpath(\$argv[0]) === __FILE__) {\n"
        . "    exit(Runner::main(dirname(__DIR__), \$argv));\n}\n",
    'update-guard.php' => $header('The update guard (spec §7).', 'Thallo\\ShippedUpdater\\Guard') . $body($guardClasses)
        . "\n// ---- entry ----\nGuard::boot(dirname(__DIR__));\n",
];

$check = in_array('--check', $argv, true);
$stale = [];
foreach ($files as $name => $contents) {
    $path = "{$root}/core/resources/updater/{$name}";
    if ($check) {
        if (!is_file($path) || file_get_contents($path) !== $contents) {
            $stale[] = $name;
        }
        continue;
    }
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, $contents);
    if ($name === 'thallo-updater') {
        chmod($path, 0755);
    }
    exec('php -l ' . escapeshellarg($path), $lint, $code);
    if ($code !== 0) {
        fwrite(STDERR, implode("\n", $lint) . "\n");
        exit(1);
    }
}
if ($stale !== []) {
    fwrite(STDERR, 'Stale: ' . implode(', ', $stale) . " — run php scripts/build-updater\n");
    exit(1);
}
echo $check ? "updater files current\n" : "updater files built\n";
```

Until Tasks 4–12 add their classes, keep each class list limited to the classes that exist. Every
later task appends its classes to the right list in the order shown above. Task 4 adds the guard's
entry once `Guard::boot` exists. Before that, the guard file's entry line is commented out
(`// Guard::boot` …).

- [ ] **Step 4: Build and run the tests**

Run: `php scripts/build-updater && php vendor/bin/phpunit tests/Unit/Updater`
Expected: PASS (OperationStoreTest 9 tests, BuildUpdaterTest 2 tests).

- [ ] **Step 5: Add the CI check.** In `.github/workflows/ci.yml`'s skeleton-smoke job, after
  "Install the dev root", add the step `- name: Updater files current` with
  `run: php scripts/build-updater --check`.

- [ ] **Step 6: phpcs and commit**

Run: `vendor/bin/phpcs core/src/Updater scripts/build-updater tests/Unit/Updater; echo phpcs=$?`
Expected: `phpcs=0`.

```bash
git add core/src/Updater scripts/build-updater core/resources/updater tests/Unit/Updater .github/workflows/ci.yml
git commit -m "feat(updater): the operation store, locks and the single-file build"
```

### Task 4: Barrier, guard admission and the skeleton includes

**Files:**
- Create: `core/src/Updater/Barrier.php`, `core/src/Updater/GuardResponse.php`, `core/src/Updater/Guard.php` (admission only; routes come in Tasks 5–6)
- Modify: `skeleton/public/index.php`, `skeleton/glueful`, and the byte-identical root copies `public/index.php`, `glueful`
- Modify: `scripts/build-updater` (guard list gains `Barrier`, `GuardResponse`, `Guard`; enable the `Guard::boot` entry)
- Test: `tests/Unit/Updater/BarrierTest.php`, `tests/Unit/Updater/GuardAdmissionTest.php`, `tests/Unit/Updater/GuardIncludeTest.php`

**Interfaces:**
- Consumes: `Paths`, `FileLock`, `AtomicFile`, `OperationStore` (Task 3).
- Produces:

```php
final class Barrier {
    public function __construct(private Paths $paths) {}
    public function isClosed(): bool;
    public function close(): void;
    public function open(): void;
    /** @return resource|null  a shared admission handle, or null: refuse */
    public function admit();
    /** @return resource|null  the exclusive barrier, or null after $timeout seconds */
    public function acquire(int $timeout, \Closure $now, \Closure $sleep);
}
final class GuardResponse {
    public function __construct(public int $status, public array $headers, public string $body) {}
    public static function json(int $status, array $data): self;
    public function send(): void;
}
final class Guard {
    public function __construct(Paths $paths, OperationStore $ops, Barrier $barrier, ?\Closure $sleep = null) {}
    public static function boot(string $base): void;
    /** @param array<string,string> $headers lower-cased names */
    public function web(string $method, string $path, array $headers, string $body): ?GuardResponse; // null: admitted, continue to the app
    /** @param list<string> $argv @param array<string,string> $env */
    public function console(array $argv, array $env): ?int; // null: admitted, continue
    /** @return resource|null the handle this process holds (tests only read it) */
    public function held();
}
```

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\Paths;

final class BarrierTest extends TestCase
{
    private string $base;
    private Barrier $barrier;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-barrier-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        $this->barrier = new Barrier(new Paths($this->base));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testTheExclusiveBarrierWaitsForEveryAdmittedProcess(): void
    {
        $a = $this->barrier->admit();
        $b = $this->barrier->admit();
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNull($this->acquireNow(), 'two admitted holders block the barrier');
        FileLock::release($a);
        self::assertNull($this->acquireNow(), 'one still blocks it');
        FileLock::release($b);
        self::assertNotNull($this->acquireNow(), 'granted once no admitted process remains');
    }

    public function testNothingIsAdmittedWhileClosedOrWhileTheBarrierIsHeld(): void
    {
        $this->barrier->close();
        self::assertNull($this->barrier->admit(), 'the flag refuses');
        $this->barrier->open();
        $held = $this->acquireNow();
        self::assertNotNull($held);
        self::assertNull($this->barrier->admit(), 'a held barrier refuses');
    }

    public function testAcquireGivesUpAfterTheTimeout(): void
    {
        $holder = $this->barrier->admit();
        $t = 0;
        $got = $this->barrier->acquire(3, static function () use (&$t): int { return $t; }, static function () use (&$t): void { $t++; });
        self::assertNull($got);
        self::assertSame(3, $t, 'it polled once a second until the timeout');
        FileLock::release($holder);
    }

    /** @return resource|null */
    private function acquireNow()
    {
        return $this->barrier->acquire(0, static fn (): int => 0, static function (): void {});
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\Guard;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;

final class GuardAdmissionTest extends TestCase
{
    private string $base;
    private Paths $paths;
    private Barrier $barrier;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-guard-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        $this->paths = new Paths($this->base);
        $this->barrier = new Barrier($this->paths);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testAnOpenSiteAdmitsAndHoldsASharedLock(): void
    {
        $guard = $this->guard();
        self::assertNull($guard->web('GET', '/about', [], ''));
        self::assertNotNull($guard->held());
        self::assertNull($this->barrier->acquire(0, static fn (): int => 0, static function (): void {}), 'the admitted request blocks the barrier');
    }

    public function testAClosedSiteAnswersEveryRequestItselfAdminIncluded(): void
    {
        $this->barrier->close();
        foreach (['/', '/admin', '/v1/admin/entries', '/v1/auth/login'] as $path) {
            $r = $this->guard()->web('GET', $path, [], '');
            self::assertNotNull($r, $path);
            self::assertSame(503, $r->status, $path);
            self::assertSame('120', $r->headers['Retry-After'], $path);
        }
        $health = $this->guard()->web('GET', '/health', [], '');
        self::assertSame(['status' => 'updating'], json_decode($health->body, true));
    }

    public function testTheFlagIsRecheckedAfterTheSharedLock(): void
    {
        // A request that passed the first flag check but locks after the runner closed: refused.
        $guard = $this->guard(beforeLock: fn () => $this->barrier->close());
        self::assertSame(503, $guard->web('GET', '/', [], '')->status);
        self::assertNull($guard->held(), 'it released its shared lock');
    }

    public function testConsoleRules(): void
    {
        $this->barrier->close();
        self::assertSame(0, $this->guard()->console(['glueful', 'queue:scheduler', 'run'], []), 'the scheduler exits quietly');
        self::assertSame(1, $this->guard()->console(['glueful', 'migrate:run'], []), 'other commands are refused');
        self::assertNull($this->guard()->console(['glueful', 'thallo:provision'], ['THALLO_UPDATE_CHILD' => '1']), 'the runner\'s children pass');
    }

    public function testAWorkerWaitsUntilTheSiteReopens(): void
    {
        $this->barrier->close();
        $sleeps = 0;
        $guard = $this->guard(sleep: function () use (&$sleeps): void {
            if (++$sleeps === 3) {
                $this->barrier->open();
            }
        });
        self::assertNull($guard->console(['glueful', 'queue:work', '--queue=default'], []));
        self::assertSame(3, $sleeps);
        self::assertNotNull($guard->held(), 'once admitted the worker holds a shared lock for its life');
    }

    private function guard(?\Closure $sleep = null, ?\Closure $beforeLock = null): Guard
    {
        $barrier = $beforeLock === null ? $this->barrier : new class ($this->paths, $beforeLock) extends Barrier {
            public function __construct(Paths $p, private \Closure $hook) { parent::__construct($p); }
            public function admit()
            {
                // Simulate the race: the flag appears after the first check, before the shared lock.
                if ($this->isClosed()) { return null; }
                ($this->hook)();
                return parent::admitAfterFirstCheck();
            }
        };
        return new Guard($this->paths, new OperationStore($this->paths, static fn (): int => 1), $barrier, $sleep ?? static function (): void {});
    }
}
```

For the race test, `Barrier` exposes a protected `admitAfterFirstCheck()`. `admit()` is the first
flag check followed by `admitAfterFirstCheck()`, and `admitAfterFirstCheck()` takes the shared lock
and re-checks the flag. So `Barrier` is not `final`; it is the only non-final updater class.

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;

/** The site-owned entry points include the guard before anything loads vendor/ (spec §7). */
final class GuardIncludeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function entries(): iterable
    {
        $root = dirname(__DIR__, 3);
        yield 'skeleton index' => [$root . '/skeleton/public/index.php', "__DIR__ . '/../bootstrap/update-guard.php'"];
        yield 'skeleton console' => [$root . '/skeleton/glueful', "__DIR__ . '/bootstrap/update-guard.php'"];
        yield 'root index' => [$root . '/public/index.php', "__DIR__ . '/../bootstrap/update-guard.php'"];
        yield 'root console' => [$root . '/glueful', "__DIR__ . '/bootstrap/update-guard.php'"];
    }

    /** @dataProvider entries */
    public function testTheGuardComesBeforeTheApplication(string $file, string $guardPath): void
    {
        $src = (string) file_get_contents($file);
        $guard = strpos($src, $guardPath);
        $app = strpos($src, 'bootstrap/app.php');
        self::assertNotFalse($guard, "{$file} includes the guard");
        self::assertLessThan($app, $guard, "{$file} includes it before bootstrap/app.php");
    }
}
```

- [ ] **Step 2: Run to see them fail**

Run: `php vendor/bin/phpunit tests/Unit/Updater/BarrierTest.php tests/Unit/Updater/GuardAdmissionTest.php tests/Unit/Updater/GuardIncludeTest.php`
Expected: FAIL — `Barrier` not found, and the include test fails on all four files.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Closing the site and the barrier (spec §7). Admitted web requests and console processes hold a
 * shared flock on admission.lock for their whole life; the runner's exclusive lock is granted only
 * when none remains. closed.flag keeps new work out. Not final: tests reach admitAfterFirstCheck().
 */
class Barrier
{
    public function __construct(protected readonly Paths $paths)
    {
    }

    public function isClosed(): bool
    {
        return is_file($this->paths->closedFlag());
    }

    public function close(): void
    {
        AtomicFile::write($this->paths->closedFlag(), gmdate('c') . "\n", 0644);
    }

    public function open(): void
    {
        @unlink($this->paths->closedFlag());
    }

    /** @return resource|null */
    public function admit()
    {
        if ($this->isClosed()) {
            return null;
        }
        return $this->admitAfterFirstCheck();
    }

    /** @return resource|null */
    protected function admitAfterFirstCheck()
    {
        $h = FileLock::shared($this->paths->admissionLock());
        if ($h === null) {
            return null; // the runner holds the barrier
        }
        if ($this->isClosed()) {
            FileLock::release($h);
            return null;
        }
        return $h;
    }

    /** @return resource|null */
    public function acquire(int $timeout, \Closure $now, \Closure $sleep)
    {
        $start = $now();
        while (true) {
            $h = FileLock::exclusive($this->paths->admissionLock(), false);
            if ($h !== null) {
                return $h;
            }
            if ($now() - $start >= $timeout) {
                return null;
            }
            $sleep();
        }
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class GuardResponse
{
    /** @param array<string,string> $headers */
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    public static function json(int $status, array $data): self
    {
        return new self($status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * The guard (spec §7), included by public/index.php and glueful before bootstrap/app.php. Plain
 * PHP: it must work while vendor/ is being replaced. Guard routes (/_update…) never boot the
 * application and never take the barrier; everything else is admitted or refused here.
 */
final class Guard
{
    /** @var resource|null held for the request's or process's life */
    private static $process = null;
    /** @var resource|null */
    private $held = null;
    private \Closure $sleep;

    public function __construct(
        private readonly Paths $paths,
        private readonly OperationStore $ops,
        private readonly Barrier $barrier,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (): void { sleep(5); };
    }

    public static function boot(string $base): void
    {
        $paths = new Paths($base);
        $guard = new self($paths, new OperationStore($paths, static fn (): int => time()), new Barrier($paths));
        if (PHP_SAPI === 'cli') {
            $env = getenv();
            $code = $guard->console($_SERVER['argv'] ?? [], is_array($env) ? $env : []);
            if ($code !== null) {
                exit($code);
            }
        } else {
            $headers = [];
            foreach ($_SERVER as $k => $v) {
                if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                    $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
                }
            }
            $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $r = $guard->web((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), is_string($path) ? $path : '/', $headers, (string) file_get_contents('php://input'));
            if ($r !== null) {
                $r->send();
                exit;
            }
        }
        self::$process = $guard->held();
    }

    /** @param array<string,string> $headers */
    public function web(string $method, string $path, array $headers, string $body): ?GuardResponse
    {
        if ($path === '/_update' || str_starts_with($path, '/_update/')) {
            return $this->route($method, $path, $headers, $body);
        }
        $this->held = $this->barrier->admit();
        if ($this->held !== null) {
            return null;
        }
        if ($path === '/health') {
            return GuardResponse::json(503, ['status' => 'updating']);
        }
        return new GuardResponse(503, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '120', 'Cache-Control' => 'no-store'], self::closedPage());
    }

    /** @param list<string> $argv @param array<string,string> $env */
    public function console(array $argv, array $env): ?int
    {
        if (($env['THALLO_UPDATE_CHILD'] ?? '') === '1') {
            return null;
        }
        $command = $argv[1] ?? '';
        if ($command === 'queue:work') {
            while (($this->held = $this->barrier->admit()) === null) {
                ($this->sleep)();
            }
            return null;
        }
        $this->held = $this->barrier->admit();
        if ($this->held !== null) {
            return null;
        }
        if ($command === 'queue:scheduler') {
            return 0;
        }
        fwrite(STDERR, "Thallo is being updated; try again when it finishes (see /_update).\n");
        return 1;
    }

    /** @return resource|null */
    public function held()
    {
        return $this->held;
    }

    /** @param array<string,string> $headers */
    private function route(string $method, string $path, array $headers, string $body): GuardResponse
    {
        return GuardResponse::json(404, ['error' => 'not_found']); // Tasks 5 and 6 add the routes
    }

    private static function closedPage(): string
    {
        return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Back in a few minutes</title><body style="font:16px system-ui;display:grid;place-items:center;min-height:90vh;margin:0 16px">'
            . '<main style="max-width:32rem;text-align:center"><h1 style="font-size:1.4rem">Back in a few minutes</h1>'
            . '<p>This site is being updated. Please try again shortly.</p></main>';
    }
}
```

At the top of `skeleton/public/index.php` and `public/index.php`, after `declare(strict_types=1);`
and before the `use` line, add:

```php
// The update guard (docs/upgrading.md, Updating from the admin): closes the site while an update
// runs. Plain PHP, loaded before vendor/; absent until provision installs it.
if (is_file(__DIR__ . '/../bootstrap/update-guard.php')) {
    require __DIR__ . '/../bootstrap/update-guard.php';
}
```

At the top of `skeleton/glueful` and `glueful`, after `declare(strict_types=1);` and before the
`use` lines, add the same block with `__DIR__ . '/bootstrap/update-guard.php'`. The root copies must
stay byte-identical to the skeleton's; check with `diff skeleton/glueful glueful && diff
skeleton/public/index.php public/index.php`.

- [ ] **Step 4: Build, run the tests and the skeleton smoke**

Run: `php scripts/build-updater && php vendor/bin/phpunit tests/Unit/Updater && composer test:skeleton`
Expected:
- the unit tests PASS;
- the skeleton smoke passes. The guard file is not installed yet, so the `is_file` check keeps
  today's behaviour.

- [ ] **Step 5: phpcs and commit**

```bash
git add core/src/Updater scripts/build-updater core/resources/updater tests/Unit/Updater skeleton/public/index.php skeleton/glueful public/index.php glueful
git commit -m "feat(updater): the barrier and the guard's admission, included by the entry points"
```

### Task 5: Guard routes — status, recovery requests, the `/_update` page

**Files:**
- Create: `core/src/Updater/RecoveryPage.php`
- Modify: `core/src/Updater/Guard.php` (`route()`), `scripts/build-updater` (guard list gains `RecoveryPage`)
- Test: `tests/Unit/Updater/GuardRoutesTest.php`

**Interfaces:**
- Consumes: `OperationStore::verifyToken`, `::current`, `::requestRecovery($token, $option)` (token and state checked in one locked section); `Paths::log`.
- Produces:
  - `GET /_update` → 200 `text/html` (`RecoveryPage::html()`), with no token needed. The page holds
    no data.
  - `GET /_update/status`, header `x-thallo-update-token` → 200 JSON
    `{operation: <op without token_hash, pid, pgid>, log: string}`, or 403.
  - `POST /_update/recover`, same header, body `{"option":"rollback"|"retry_provision"|"reopen","confirm"?:string}`
    → 202, 403, or 409. `reopen` requires `confirm === "REOPEN"`, otherwise 422.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\Guard;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\Status;

final class GuardRoutesTest extends TestCase
{
    private string $base;
    private Paths $paths;
    private OperationStore $ops;
    private string $token;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-routes-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        mkdir($this->base . '/storage/logs', 0777, true);
        $this->paths = new Paths($this->base);
        $this->ops = new OperationStore($this->paths, static fn (): int => 1);
        [$op, $this->token] = $this->ops->start('2', '1', 'u');
        file_put_contents($this->paths->log($op['id']), implode("\n", array_map(static fn ($i) => "line {$i}", range(1, 300))));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testThePageLoadsWithoutAToken(): void
    {
        $r = $this->guard()->web('GET', '/_update', [], '');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('sessionStorage', $r->body);
        self::assertStringNotContainsString('<script src=', $r->body, 'self-contained: nothing loaded from elsewhere');
    }

    public function testStatusNeedsTheTokenAndHidesSecrets(): void
    {
        self::assertSame(403, $this->guard()->web('GET', '/_update/status', [], '')->status);
        self::assertSame(403, $this->guard()->web('GET', '/_update/status', ['x-thallo-update-token' => 'nope'], '')->status);
        $r = $this->guard()->web('GET', '/_update/status', ['x-thallo-update-token' => $this->token], '');
        self::assertSame(200, $r->status);
        $data = json_decode($r->body, true);
        self::assertSame('requested', $data['operation']['status']);
        self::assertArrayNotHasKey('token_hash', $data['operation']);
        self::assertSame(200, substr_count($data['log'], "\n") + 1, 'the last 200 log lines');
        self::assertStringEndsWith('line 300', $data['log']);
    }

    public function testStatusWorksWhileTheSiteIsClosedAndTheBarrierHeld(): void
    {
        $barrier = new Barrier($this->paths);
        $barrier->close();
        $held = $barrier->acquire(0, static fn (): int => 0, static function (): void {});
        self::assertNotNull($held);
        $r = $this->guard()->web('GET', '/_update/status', ['x-thallo-update-token' => $this->token], '');
        self::assertSame(200, $r->status);
    }

    public function testRecoveryRequests(): void
    {
        $h = ['x-thallo-update-token' => $this->token];
        self::assertSame(409, $this->guard()->web('POST', '/_update/recover', $h, '{"option":"rollback"}')->status, 'not in recovery');
        $run = $this->ops->claim($this->ops->current()['id'], 1, 1);
        $this->ops->finish($run['id'], $run['owner'], Status::RECOVERY_REQUIRED, 'x');
        self::assertSame(422, $this->guard()->web('POST', '/_update/recover', $h, '{"option":"reopen"}')->status, 'reopen needs the typed confirmation');
        self::assertSame(202, $this->guard()->web('POST', '/_update/recover', $h, '{"option":"reopen","confirm":"REOPEN"}')->status);
        self::assertSame('reopen', $this->ops->current()['recovery_request']);
        self::assertSame(403, $this->guard()->web('POST', '/_update/recover', [], '{"option":"rollback"}')->status);
    }

    private function guard(): Guard
    {
        return new Guard($this->paths, $this->ops, new Barrier($this->paths), static function (): void {});
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `php vendor/bin/phpunit tests/Unit/Updater/GuardRoutesTest.php`
Expected: FAIL — `/_update` answers 404.

- [ ] **Step 3: Implement.** Replace `Guard::route()`:

```php
/** @param array<string,string> $headers */
private function route(string $method, string $path, array $headers, string $body): GuardResponse
{
    if ($path === '/_update' && $method === 'GET') {
        return new GuardResponse(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'], RecoveryPage::html());
    }
    if ($path === '/_update/verify' && $method === 'POST') {
        return $this->verify($headers, $body); // Task 6
    }
    $token = $headers['x-thallo-update-token'] ?? '';
    if (!$this->ops->verifyToken($token)) {
        return GuardResponse::json(403, ['error' => 'token']);
    }
    if ($path === '/_update/status' && $method === 'GET') {
        $op = $this->ops->current() ?? [];
        unset($op['token_hash'], $op['pid'], $op['pgid']);
        return GuardResponse::json(200, ['operation' => $op, 'log' => $this->logTail((string) ($op['id'] ?? ''), 200)]);
    }
    if ($path === '/_update/recover' && $method === 'POST') {
        $in = json_decode($body, true);
        $option = is_array($in) ? (string) ($in['option'] ?? '') : '';
        if ($option === 'reopen' && (!is_array($in) || ($in['confirm'] ?? '') !== 'REOPEN')) {
            return GuardResponse::json(422, ['error' => 'confirm']);
        }
        return $this->ops->requestRecovery($token, $option)
            ? GuardResponse::json(202, ['requested' => $option])
            : GuardResponse::json(409, ['error' => 'not_recoverable']);
    }
    return GuardResponse::json(404, ['error' => 'not_found']);
}

private function logTail(string $id, int $lines): string
{
    $file = $id === '' ? '' : $this->paths->log($id);
    if ($file === '' || !is_file($file)) {
        return '';
    }
    $all = preg_split('/\r?\n/', rtrim((string) file_get_contents($file), "\n")) ?: [];
    return implode("\n", array_slice($all, -$lines));
}
```

Until Task 6 lands, `verify()` returns `GuardResponse::json(404, ['error' => 'not_found'])`.

`RecoveryPage::html()` returns one string: a complete HTML document with inline `<style>` and
`<script>`. It contains:
- **Token:** read from `sessionStorage['thallo.update.token']`. Without one, a field to paste a token
  from `php bin/thallo-updater token`, saved to sessionStorage on submit.
- **Polling:** `fetch('/_update/status', {headers: {'x-thallo-update-token': token}, cache: 'no-store'})`
  every 2000 ms.
  - A network error or non-2xx (except 403) shows "The server is busy — still checking" and keeps
    polling.
  - A 403 shows the token field.
- **Rendering:**
  - a heading per status: "Updating to {target}", "Updated to {target}", "Update failed — nothing
    changed", "Rolled back to {from}", "Your site needs attention";
  - the `steps[]` checklist with durations;
  - the log in a `<pre>` scrolled to the end;
  - in `recovery_required`: the backup path (`operation.backup.path`), the restore command
    `pg_restore --clean --if-exists --dbname <database> <path>/database.dump`, and the three
    buttons.
- **Recovery:** each button posts `{option}`. **Reopen** first reveals an input that must read
  `REOPEN`. After a 202 the page shows "Waiting for the runner (up to a minute)".
- **No operation:** "No update in progress" and a link to `/admin`.
- Colours as CSS custom properties on `:root`, with a `prefers-color-scheme: dark` block. A 16px side
  gutter. No external resources.

Write it as a PHP nowdoc (`<<<'HTML'`) so no `$` is interpolated.

- [ ] **Step 4: Build and run**

Run: `php scripts/build-updater && php vendor/bin/phpunit tests/Unit/Updater`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add core/src/Updater scripts/build-updater core/resources/updater tests/Unit/Updater
git commit -m "feat(updater): the guard's status, recovery requests and the /_update page"
```

### Task 6: Web verification — secret-gated, barrier-free (Review Focus 5)

**Files:**
- Modify: `core/src/Updater/Guard.php` (`verify()`)
- Test: `tests/Unit/Updater/GuardVerifyTest.php`

**Interfaces:**
- Consumes: `Paths::verifySecret()` (written by the runner in Task 12, 0640, one line of 64 hex chars).
- Produces: `POST /_update/verify`, header `x-thallo-verify: <secret>`, body
  `{"mode":"probe"}` or `{"mode":"boot"}`:
  - `probe` → 200 `{sapi, writable_code: bool, admission_ok: bool, state_writable: bool, opcache: {enabled, validate_timestamps, revalidate_freq, preload}}`.
    It never touches the application.
  - `boot` → it requires `<base>/bootstrap/app.php`, then answers 200
    `{version, php, missing_extensions: [], container: bool}`, or 500 `{error}`. A fatal error is
    caught by a shutdown handler that emits the 500 JSON.
  - A wrong or missing secret → 403. The recovery token never passes, and the secret never passes
    `/_update/status` or `/_update/recover`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\Guard;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;

final class GuardVerifyTest extends TestCase
{
    private string $base;
    private Paths $paths;
    private OperationStore $ops;
    private string $token;
    private string $secret;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-verify-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        mkdir($this->base . '/bootstrap', 0777, true);
        mkdir($this->base . '/vendor', 0777, true);
        file_put_contents($this->base . '/bootstrap/app.php', '<?php return new class { public function getContainer(): object { return new \stdClass(); } };');
        $this->paths = new Paths($this->base);
        $this->ops = new OperationStore($this->paths, static fn (): int => 1);
        [, $this->token] = $this->ops->start('2', '1', 'u');
        $this->secret = bin2hex(random_bytes(32));
        file_put_contents($this->paths->verifySecret(), $this->secret . "\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testProbeReportsTheWebProcessesView(): void
    {
        $r = $this->verify($this->secret, 'probe');
        self::assertSame(200, $r->status);
        $data = json_decode($r->body, true);
        self::assertArrayHasKey('writable_code', $data);
        self::assertArrayHasKey('validate_timestamps', $data['opcache']);
    }

    public function testBootReportsTheVersionAndTheContainer(): void
    {
        $data = json_decode($this->verify($this->secret, 'boot')->body, true);
        self::assertTrue($data['container']);
        self::assertIsString($data['version']);
        self::assertSame([], $data['missing_extensions']);
    }

    public function testTheRecoveryTokenCannotVerify(): void
    {
        self::assertSame(403, $this->verify($this->token, 'boot')->status);
        self::assertSame(403, $this->verify('', 'probe')->status);
    }

    public function testTheSecretCannotReadStatus(): void
    {
        $g = $this->guard();
        self::assertSame(403, $g->web('GET', '/_update/status', ['x-thallo-update-token' => $this->secret], '')->status);
        self::assertSame(403, $g->web('POST', '/_update/recover', ['x-thallo-update-token' => $this->secret], '{"option":"rollback"}')->status);
    }

    public function testVerifyIsAnsweredWhileTheBarrierIsHeld(): void
    {
        $barrier = new Barrier($this->paths);
        $barrier->close();
        $held = $barrier->acquire(0, static fn (): int => 0, static function (): void {});
        self::assertNotNull($held);
        self::assertSame(200, $this->verify($this->secret, 'probe')->status, 'verification never waits on the barrier');
    }

    private function verify(string $secret, string $mode): \Thallo\Core\Updater\GuardResponse
    {
        return $this->guard()->web('POST', '/_update/verify', ['x-thallo-verify' => $secret], json_encode(['mode' => $mode]));
    }

    private function guard(): Guard
    {
        return new Guard($this->paths, $this->ops, new Barrier($this->paths), static function (): void {});
    }
}
```

The boot test runs in the PHPUnit process, where `Composer\InstalledVersions` is loaded. The version
comes from it: a development string in this repository, a release in a site.

- [ ] **Step 2: Run to see it fail**

Run: `php vendor/bin/phpunit tests/Unit/Updater/GuardVerifyTest.php`
Expected: FAIL — verify answers 404.

- [ ] **Step 3: Implement `Guard::verify()`**

```php
/** @param array<string,string> $headers */
private function verify(array $headers, string $body): GuardResponse
{
    $file = $this->paths->verifySecret();
    $secret = is_file($file) ? trim((string) file_get_contents($file)) : '';
    $given = $headers['x-thallo-verify'] ?? '';
    if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
        return GuardResponse::json(403, ['error' => 'secret']);
    }
    $in = json_decode($body, true);
    $mode = is_array($in) ? ($in['mode'] ?? '') : '';
    if ($mode === 'probe') {
        return GuardResponse::json(200, [
            'sapi' => PHP_SAPI,
            'writable_code' => is_writable($this->paths->base . '/vendor') || is_writable($this->paths->base . '/composer.lock'),
            'admission_ok' => (static function (string $lock): bool {
                $h = FileLock::shared($lock);
                if ($h === null) {
                    return false;
                }
                FileLock::release($h);
                return true;
            })($this->paths->admissionLock()),
            'state_writable' => is_writable($this->paths->dir()),
            'opcache' => [
                'enabled' => (bool) ini_get('opcache.enable'),
                'validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
                'revalidate_freq' => (int) ini_get('opcache.revalidate_freq'),
                'preload' => (string) ini_get('opcache.preload'),
            ],
        ]);
    }
    if ($mode !== 'boot') {
        return GuardResponse::json(422, ['error' => 'mode']);
    }
    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
            GuardResponse::json(500, ['error' => $e['message']])->send();
        }
    });
    try {
        $app = require $this->paths->base . '/bootstrap/app.php';
        $missing = [];
        $core = $this->paths->base . '/vendor/glueful/thallo-core/composer.json';
        $require = is_file($core) ? (json_decode((string) file_get_contents($core), true)['require'] ?? []) : [];
        foreach (array_keys($require) as $name) {
            if (str_starts_with($name, 'ext-') && !extension_loaded(substr($name, 4))) {
                $missing[] = substr($name, 4);
            }
        }
        $version = class_exists('Composer\\InstalledVersions')
            ? \Composer\InstalledVersions::getPrettyVersion('glueful/thallo-core')
            : null;
        return GuardResponse::json(200, [
            'version' => $version,
            'php' => PHP_VERSION,
            'missing_extensions' => $missing,
            'container' => is_object($app) && method_exists($app, 'getContainer') && $app->getContainer() !== null,
        ]);
    } catch (\Throwable $e) {
        return GuardResponse::json(500, ['error' => $e->getMessage()]);
    }
}
```

`\Composer\InstalledVersions` is a global class from the site's own `vendor/composer`, and the
`bootstrap/app.php` it requires has already loaded it. It is not a framework import, so the
no-imports rule holds: the name is fully qualified, with no `use`.

- [ ] **Step 4: Build and run**

Run: `php scripts/build-updater && php vendor/bin/phpunit tests/Unit/Updater`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add core/src/Updater scripts/build-updater core/resources/updater tests/Unit/Updater
git commit -m "feat(updater): secret-gated web verification that never waits on the barrier"
```

## Phase B — the runner's parts

### Task 7: Child processes, heartbeat and liveness (Review Focus 2)

**Files:**
- Create: `core/src/Updater/Host.php`, `core/src/Updater/RealHost.php`, `core/src/Updater/ChildProcess.php`, `core/src/Updater/Liveness.php`
- Modify: `scripts/build-updater` (runner list gains `Host`, `RealHost`, `ChildProcess`, `Liveness`)
- Test: `tests/Unit/Updater/ChildProcessTest.php`, `tests/Unit/Updater/LivenessTest.php`, helpers `tests/fixtures/updater/hold-and-die.php`, `recover-and-die.php`, `timeout-with-grandchild.php`

**Interfaces:**
- Produces:

```php
interface Host {
    /** @param list<string> $argv @param array<string,string> $env  merged over the runner's environment */
    public function run(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): int;
    /** As run(), but this command's own stdout is returned (and also appended to the log): @return array{0:int,1:string} */
    public function runCapture(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): array;
    /** A short command whose output the runner reads (versions, sizes): @return array{0:int,1:string} exit code and stdout */
    public function capture(array $argv, string $cwd): array;
    /** @return array{0:int,1:string} status and body; status 0 when unreachable */
    public function http(string $url, array $headers, string $body): array;
    public function now(): int;
    public function sleep(int $seconds): void;
}
final class RealHost implements Host {
    /** @param list<resource> $inherit  handles every child receives as descriptors 3, 4, …
     *  $pgid/$self: the runner's process group and pid, passed to ChildProcess::run for timeout cleanup */
    public function __construct(private string $log, private array $inherit, private ?int $pgid = null, private ?int $self = null) {}
    /** @param resource $handle */ public function inherit($handle): void;
    /** Removes a handle before it is closed, so no later child is handed a closed resource. @param resource $handle */
    public function forget($handle): void;
}
final class ChildProcess {
    /**
     * Polls the child every 250 ms and calls $heartbeat every 5 s whatever the child prints.
     * On timeout it never blocks: SIGTERM, then up to 10 s of polling, then SIGKILL, then polling
     * until the child has exited. When $pgid is given, every other member of that group (the child's
     * descendants) is then stopped and confirmed gone. Only then does it call proc_close, which
     * cannot block on an exited child. Returns 124 on timeout.
     * $stdout: when given, the child's stdout goes to that file instead of the log (the caller appends it).
     * Throws \RuntimeException when a descendant survives SIGKILL.
     */
    public static function run(array $argv, string $cwd, array $env, array $inherit, string $log, callable $heartbeat, int $timeout,
        ?string $stdout = null, ?int $pgid = null, ?int $self = null): int;
    /** Stops every member of process group $pgid except $self: SIGTERM, up to 10 s, SIGKILL, then confirms none remain.
     *  Throws \RuntimeException when a member survives or `pgrep` is unavailable. Uses no Host: it runs pgrep itself. */
    public static function stopGroupMembers(int $pgid, int $self): void;
}
final class Liveness {
    public static function groupAlive(?int $pgid): bool;
    /** @return resource|null  the run lock, taken, when the operation is running and dead; null otherwise */
    public static function claimIfDead(array $op, Paths $paths);
}
```

- [ ] **Step 1: Write the failing tests**

`tests/fixtures/updater/hold-and-die.php` is the runner stand-in:
- it makes itself a process-group leader;
- it takes `run.lock`;
- it starts `sh` with the lock as descriptor 3, and `sh` starts a background grandchild;
- it then kills itself, so both the stand-in and its immediate child are gone:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Thallo\Core\Updater\ChildProcess;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\Paths;

[$script, $base, $grandchild] = $argv; // $grandchild: shell for the background process
posix_setpgid(0, 0);
$paths = new Paths($base);
$lock = FileLock::exclusive($paths->runLock());
file_put_contents($base . '/pgid', (string) posix_getpgid(0));
ChildProcess::run(['sh', '-c', $grandchild . ' & echo $! > ' . escapeshellarg($base . '/grandchild.pid')], $base, [], [$lock], $base . '/log', static function (): void {}, 30);
posix_kill(getmypid(), SIGKILL);
```

`recover-and-die.php` does the following:
- calls `posix_setpgid(0, 0)`;
- takes `run.lock`;
- claims the recovery with `$ops->claimRecovery($id, getmypid(), posix_getpgid(0))`;
- starts `sh -c 'sleep 30 & echo $! > grandchild.pid'` with the lock as descriptor 3;
- kills itself.

`timeout-with-grandchild.php` does the following:
- calls `posix_setpgid(0, 0)`;
- runs `ChildProcess::run(['sh', '-c', 'trap "" TERM; sleep 60 & echo $! > grandchild.pid; sleep 60'], …, timeout: 2, pgid: posix_getpgid(0), self: getmypid())`.
  The child **ignores SIGTERM**, and so does its grandchild, because an ignored disposition is
  inherited across exec. Only the supervisor's own escalation can end them;
- writes `"<exit code> <seconds taken>"` to `result`;
- exits 0.

Both fixtures require the repository's `vendor/autoload.php`, as `hold-and-die.php` does.

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Liveness;
use Thallo\Core\Updater\Paths;

/** @requires extension posix */
final class LivenessTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-live-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->base . '/grandchild.pid')) {
            @posix_kill((int) file_get_contents($this->base . '/grandchild.pid'), SIGKILL);
        }
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testAGrandchildKeepsTheOperationAlive(): void
    {
        $op = $this->runStandIn('sleep 30');
        self::assertNull(Liveness::claimIfDead($op, new Paths($this->base)), 'the grandchild holds the lock and the group');
        posix_kill((int) file_get_contents($this->base . '/grandchild.pid'), SIGKILL);
        $this->waitUntil(fn () => !Liveness::groupAlive($op['pgid']));
        self::assertNotNull(Liveness::claimIfDead($op, new Paths($this->base)), 'dead only once every descendant is gone');
    }

    public function testAGrandchildThatClosedTheLockStillKeepsItAliveThroughTheGroup(): void
    {
        $op = $this->runStandIn('sh -c "exec 3>&-; sleep 30"');
        self::assertNull(Liveness::claimIfDead($op, new Paths($this->base)), 'the process group still has a member');
    }

    public function testAKilledRecoveryRunnerWithASurvivingGrandchildIsStillAlive(): void
    {
        // A first run died; its group is long gone. A recovery runner claims with ITS OWN group,
        // starts a grandchild and is killed. Liveness must follow the recovery runner's group.
        $paths = new Paths($this->base);
        $ops = new \Thallo\Core\Updater\OperationStore($paths, static fn (): int => 1);
        [$op, $token] = $ops->start('2', '1', 'u');
        $dead = $ops->claim($op['id'], 1, 999_999); // a group that does not exist
        $ops->finish($op['id'], $dead['owner'], \Thallo\Core\Updater\Status::RECOVERY_REQUIRED);
        $ops->requestRecovery($token, 'rollback');
        $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/updater/recover-and-die.php', $this->base], [], $pipes);
        $this->waitUntil(fn () => !proc_get_status($p)['running'] && is_file($this->base . '/grandchild.pid'));
        proc_close($p);
        $now = $ops->current();
        self::assertNotSame(999_999, $now['pgid'], 'recovery recorded fresh ownership');
        self::assertNull(Liveness::claimIfDead($now, $paths), 'the grandchild keeps the recovery run alive');
        posix_kill((int) file_get_contents($this->base . '/grandchild.pid'), SIGKILL);
        $this->waitUntil(fn () => !Liveness::groupAlive($now['pgid']));
        self::assertNotNull(Liveness::claimIfDead($now, $paths));
    }

    public function testAChildIgnoringSigtermAndItsGrandchildAreStoppedWithinBounds(): void
    {
        $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/updater/timeout-with-grandchild.php', $this->base], [], $pipes);
        $this->waitUntil(fn () => !proc_get_status($p)['running']);
        $code = proc_close($p);
        self::assertSame(0, $code, (string) @file_get_contents($this->base . '/log'));
        [$code, $took] = explode(' ', trim((string) file_get_contents($this->base . '/result')));
        self::assertSame('124', $code);
        self::assertLessThan(30, (int) $took, 'termination is bounded even when SIGTERM is ignored');
        self::assertFalse(posix_kill((int) file_get_contents($this->base . '/grandchild.pid'), 0), 'the grandchild is gone');
    }

    public function testANotRunningOperationIsNeverDead(): void
    {
        self::assertNull(Liveness::claimIfDead(['status' => 'recovery_required', 'pgid' => 1], new Paths($this->base)));
    }

    /** @return array{status:string,pgid:int} */
    private function runStandIn(string $grandchild): array
    {
        $cmd = [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/updater/hold-and-die.php', $this->base, $grandchild];
        $p = proc_open($cmd, [], $pipes);
        $this->waitUntil(fn () => !proc_get_status($p)['running'] && is_file($this->base . '/grandchild.pid'));
        proc_close($p);
        return ['status' => 'running', 'pgid' => (int) file_get_contents($this->base . '/pgid')];
    }

    private function waitUntil(callable $ok): void
    {
        for ($i = 0; $i < 100 && !$ok(); $i++) {
            usleep(50_000);
        }
        self::assertTrue($ok(), 'timed out');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\ChildProcess;

final class ChildProcessTest extends TestCase
{
    public function testAQuietChildStillHeartbeats(): void
    {
        $beats = 0;
        $log = tempnam(sys_get_temp_dir(), 'cp');
        $code = ChildProcess::run(['sh', '-c', 'sleep 11'], sys_get_temp_dir(), [], [], $log, static function () use (&$beats): void { $beats++; }, 60);
        self::assertSame(0, $code);
        self::assertGreaterThanOrEqual(2, $beats, 'a heartbeat every 5 s while the child prints nothing');
    }

    public function testOutputGoesToTheLogAndTheExitCodeComesBack(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'cp');
        $code = ChildProcess::run(['sh', '-c', 'echo hello; echo oops >&2; exit 3'], sys_get_temp_dir(), [], [], $log, static function (): void {}, 60);
        self::assertSame(3, $code);
        self::assertStringContainsString("hello\noops", (string) file_get_contents($log));
    }

    public function testTheEnvironmentIsMergedNotReplaced(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'cp');
        ChildProcess::run(['sh', '-c', 'echo "$THALLO_UPDATE_CHILD:$PATH"'], sys_get_temp_dir(), ['THALLO_UPDATE_CHILD' => '1'], [], $log, static function (): void {}, 60);
        self::assertMatchesRegularExpression('/^1:\S+/', (string) file_get_contents($log));
    }
}
```

- [ ] **Step 2: Run to see them fail**

Run: `php vendor/bin/phpunit tests/Unit/Updater/ChildProcessTest.php tests/Unit/Updater/LivenessTest.php`
Expected: FAIL — `ChildProcess` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Runs one child of the runner (spec §3, §5). Its output goes to the operation log. The runner's
 * locks are passed as descriptors 3, 4, …, so the run lock and the barrier stay held while any
 * descendant lives. The runner polls instead of blocking on output, so a silent child still
 * heartbeats.
 */
final class ChildProcess
{
    /** @param list<string> $argv @param array<string,string> $env @param list<resource> $inherit */
    public static function run(array $argv, string $cwd, array $env, array $inherit, string $log, callable $heartbeat, int $timeout,
        ?string $stdout = null, ?int $pgid = null, ?int $self = null): int
    {
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdout ?? $log, $stdout === null ? 'a' : 'w'], 2 => ['file', $log, 'a']];
        foreach (array_values($inherit) as $i => $handle) {
            $spec[3 + $i] = $handle;
        }
        $base = getenv();
        $proc = proc_open($argv, $spec, $pipes, $cwd, array_merge(is_array($base) ? $base : [], $env));
        if (!is_resource($proc)) {
            return 127;
        }
        $started = time();
        $lastBeat = 0;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                proc_close($proc);
                return $status['exitcode'];
            }
            if (time() - $lastBeat >= 5) {
                $heartbeat();
                $lastBeat = time();
            }
            if (time() - $started >= $timeout) {
                self::terminate($proc, $status['pid']);
                if ($pgid !== null) {
                    self::stopGroupMembers($pgid, $self ?? getmypid());
                }
                proc_close($proc); // the child has exited: this cannot block
                return 124;
            }
            usleep(250_000);
        }
    }
}
```

`proc_get_status()['exitcode']` is valid only on the first call that sees the process exit. The
loop returns on that call, so the value is read once.

Add to `ChildProcess`:

```php
/** SIGTERM, up to 10 s, SIGKILL, then wait until the child has exited — never a blocking call before that. */
private static function terminate($proc, int $pid): void
{
    proc_terminate($proc, 15);
    for ($i = 0; $i < 40 && proc_get_status($proc)['running']; $i++) {
        usleep(250_000);
    }
    if (proc_get_status($proc)['running']) {
        proc_terminate($proc, 9);
        while (proc_get_status($proc)['running']) {
            usleep(50_000);
        }
    }
}

public static function stopGroupMembers(int $pgid, int $self): void
{
    $members = static function () use ($pgid, $self): array {
        $p = proc_open(['pgrep', '-g', (string) $pgid], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) {
            throw new \RuntimeException('pgrep is unavailable.');
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($p);
        if ($code > 1) { // 1: no match; anything higher: pgrep failed
            throw new \RuntimeException('Cannot list the runner\'s process group (pgrep exited ' . $code . ').');
        }
        return array_values(array_filter(array_map('intval', preg_split('/\s+/', trim($out)) ?: []), static fn (int $p): bool => $p > 0 && $p !== $self));
    };
    foreach ($members() as $pid) {
        posix_kill($pid, 15);
    }
    for ($i = 0; $i < 40 && $members() !== []; $i++) {
        usleep(250_000);
    }
    foreach ($members() as $pid) {
        posix_kill($pid, 9);
    }
    usleep(500_000);
    if (($left = $members()) !== []) {
        throw new \RuntimeException('Processes started by the update are still running: ' . implode(', ', $left));
    }
}
```

`pgrep` never lists itself, so the call that spawns it does not see it as a member. `selftest` and
`RunnerReport` require `pgrep`.

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * When a running operation is dead (spec §5; plan ruling 5): its run lock can be taken and its
 * process group has no member left. Either alone is not proof: a descendant can close the inherited
 * lock, and a group can outlive a lock.
 */
final class Liveness
{
    public static function groupAlive(?int $pgid): bool
    {
        if ($pgid === null || $pgid <= 1 || !function_exists('posix_kill')) {
            return false;
        }
        if (posix_kill(-$pgid, 0)) {
            return true;
        }
        return posix_get_last_error() === 1; // EPERM: members exist that we may not signal
    }

    /** @return resource|null */
    public static function claimIfDead(array $op, Paths $paths)
    {
        if (($op['status'] ?? null) !== Status::RUNNING) {
            return null;
        }
        $lock = FileLock::exclusive($paths->runLock(), false);
        if ($lock === null) {
            return null;
        }
        if (self::groupAlive(isset($op['pgid']) ? (int) $op['pgid'] : null)) {
            FileLock::release($lock);
            return null;
        }
        return $lock;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

interface Host
{
    /** @param list<string> $argv @param array<string,string> $env */
    public function run(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): int;

    /** @param list<string> $argv @param array<string,string> $env @return array{0:int,1:string} this command's own exit code and stdout */
    public function runCapture(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): array;

    /** @param list<string> $argv @return array{0:int,1:string} */
    public function capture(array $argv, string $cwd): array;

    /** @param array<string,string> $headers @return array{0:int,1:string} */
    public function http(string $url, array $headers, string $body): array;

    public function now(): int;

    public function sleep(int $seconds): void;
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class RealHost implements Host
{
    /** @param list<resource> $inherit */
    public function __construct(private readonly string $log, private array $inherit)
    {
    }

    /** @param resource $handle */
    public function inherit($handle): void
    {
        $this->inherit[] = $handle;
    }

    /** @param resource $handle */
    public function forget($handle): void
    {
        $this->inherit = array_values(array_filter($this->inherit, static fn ($h): bool => $h !== $handle));
    }

    public function run(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): int
    {
        return ChildProcess::run($argv, $cwd, $env, $this->inherit, $this->log, $heartbeat, $timeout, null, $this->pgid, $this->self);
    }

    public function runCapture(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): array
    {
        $out = tempnam(sys_get_temp_dir(), 'thallo-out-');
        try {
            $code = ChildProcess::run($argv, $cwd, $env, $this->inherit, $this->log, $heartbeat, $timeout, $out, $this->pgid, $this->self);
            $stdout = (string) file_get_contents($out);
            file_put_contents($this->log, $stdout, FILE_APPEND);
            return [$code, $stdout];
        } finally {
            @unlink($out);
        }
    }

    public function capture(array $argv, string $cwd): array
    {
        $proc = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd);
        if (!is_resource($proc)) {
            return [127, ''];
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($proc), $out];
    }

    public function http(string $url, array $headers, string $body): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $lines), 'content' => $body, 'ignore_errors' => true, 'timeout' => 20]]);
        $out = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return [$status, is_string($out) ? $out : ''];
    }

    public function now(): int
    {
        return time();
    }

    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
```

- [ ] **Step 4: Build and run**

Run: `php scripts/build-updater && php vendor/bin/phpunit tests/Unit/Updater`
Expected: PASS. The LivenessTest needs `posix`; MAMP and CI both have it.

- [ ] **Step 5: Commit**

```bash
git add core/src/Updater scripts/build-updater core/resources/updater tests/Unit/Updater tests/fixtures/updater
git commit -m "feat(updater): children inherit the run lock; a run is dead only when every descendant is gone"
```

### Task 8: The journal — renames that survive any kill point

**Files:**
- Create: `core/src/Updater/Journal.php`
- Modify: `scripts/build-updater` (runner list gains `Journal`)
- Test: `tests/Unit/Updater/JournalTest.php`

**Interfaces:**
- Consumes: `OperationStore::update($id, $owner, …)` (fenced), `::current`.
- Produces:

```php
final class JournalConflict extends \RuntimeException {} // declared in Journal.php
final class Journal {
    /** $killPoint(string $label) is called after the intent is persisted ("<label>.intended") and after the rename ("<label>.renamed") */
    public function __construct(private OperationStore $ops, private string $id, private string $owner, private ?\Closure $killPoint = null) {}
    public function rename(string $from, string $to, string $label): void;
    public function resume(): void;      // settles every "intended" entry from the filesystem; JournalConflict when both or neither path exist
    public function hasForwardChanges(): bool; // a done forward rename not yet reversed
    public function rollback(): void;    // reverses done forward renames newest first, each journaled
}
```

Entries are `{seq, kind: "forward"|"reverse", reverses: ?int, label, from, to, phase: "intended"|"done"|"abandoned"}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Journal;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;

final class JournalTest extends TestCase
{
    private const SWAP = [['vendor', 'op/vendor.old', 'swap.1'], ['stage/vendor', 'vendor', 'swap.2'], ['composer.lock', 'op/composer.lock.old', 'swap.3'], ['stage/composer.lock', 'composer.lock', 'swap.4']];
    private string $base;
    private OperationStore $ops;
    private string $id;
    private string $owner;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-journal-' . bin2hex(random_bytes(4));
        foreach (['storage/framework/updates', 'vendor', 'stage/vendor', 'op'] as $d) {
            mkdir("{$this->base}/{$d}", 0777, true);
        }
        file_put_contents("{$this->base}/vendor/marker", 'old');
        file_put_contents("{$this->base}/stage/vendor/marker", 'new');
        file_put_contents("{$this->base}/composer.lock", 'old-lock');
        file_put_contents("{$this->base}/stage/composer.lock", 'new-lock');
        $this->ops = new OperationStore(new Paths($this->base), static fn (): int => 1);
        [$op] = $this->ops->start('2', '1', 'u');
        $run = $this->ops->claim($op['id'], 1, 1);
        $this->id = $run['id'];
        $this->owner = $run['owner'];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testACompleteSwapThenRollbackRestoresTheOldTree(): void
    {
        $this->swap(new Journal($this->ops, $this->id, $this->owner));
        self::assertSame('new', file_get_contents("{$this->base}/vendor/marker"));
        (new Journal($this->ops, $this->id, $this->owner))->rollback();
        $this->assertOldTree();
    }

    /** @return iterable<string, array{string}> */
    public static function forwardKillPoints(): iterable
    {
        foreach (range(1, 4) as $n) {
            yield "swap.{$n}.intended" => ["swap.{$n}.intended"];
            yield "swap.{$n}.renamed" => ["swap.{$n}.renamed"];
        }
    }

    /** @dataProvider forwardKillPoints */
    public function testAKillAnywhereInTheSwapRollsBackCleanly(string $point): void
    {
        try {
            $this->swap(new Journal($this->ops, $this->id, $this->owner, static function (string $label) use ($point): void {
                if ($label === $point) {
                    throw new \RuntimeException('killed');
                }
            }));
            self::fail('the kill point did not fire');
        } catch (\RuntimeException) {
        }
        $after = new Journal($this->ops, $this->id, $this->owner); // a fresh runner reads only the persisted journal
        $after->resume();
        $after->rollback();
        $this->assertOldTree();
    }

    /** @return iterable<string, array{string}> */
    public static function rollbackKillPoints(): iterable
    {
        foreach (range(1, 4) as $n) {
            yield "reverse of swap.{$n}" => ["undo.swap.{$n}.renamed"];
        }
    }

    /** @dataProvider rollbackKillPoints */
    public function testAnInterruptedRollbackResumes(string $point): void
    {
        $this->swap(new Journal($this->ops, $this->id, $this->owner));
        try {
            (new Journal($this->ops, $this->id, $this->owner, static function (string $label) use ($point): void {
                if ($label === $point) {
                    throw new \RuntimeException('killed');
                }
            }))->rollback();
        } catch (\RuntimeException) {
        }
        $again = new Journal($this->ops, $this->id, $this->owner);
        $again->resume();
        $again->rollback();
        $this->assertOldTree();
        self::assertFalse($again->hasForwardChanges());
    }

    private function swap(Journal $j): void
    {
        foreach (self::SWAP as [$from, $to, $label]) {
            $j->rename("{$this->base}/{$from}", "{$this->base}/{$to}", $label);
        }
    }

    private function assertOldTree(): void
    {
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        self::assertSame('old-lock', file_get_contents("{$this->base}/composer.lock"));
        self::assertSame('new', file_get_contents("{$this->base}/stage/vendor/marker"), 'the new tree is back in the stage');
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `php vendor/bin/phpunit tests/Unit/Updater/JournalTest.php`
Expected: FAIL — `Journal` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class JournalConflict extends \RuntimeException
{
}

/**
 * Write-ahead record of every rename of the live install (spec §5). Each rename is persisted as
 * intended, performed, then marked done; a fresh runner settles intended entries from the
 * filesystem (resume) and can reverse everything (rollback), itself journaled, from any kill point.
 */
final class Journal
{
    public function __construct(
        private readonly OperationStore $ops,
        private readonly string $id,
        private readonly string $owner,
        private readonly ?\Closure $killPoint = null,
    ) {
    }

    public function rename(string $from, string $to, string $label): void
    {
        $this->journaled('forward', null, $label, $from, $to);
    }

    public function resume(): void
    {
        $this->ops->update($this->id, $this->owner, static function (array $op): array {
            foreach ($op['journal'] as $i => $e) {
                if ($e['phase'] !== 'intended') {
                    continue;
                }
                $fromExists = file_exists($e['from']) || is_link($e['from']);
                $toExists = file_exists($e['to']) || is_link($e['to']);
                if (!$fromExists && $toExists) {
                    $op['journal'][$i]['phase'] = 'done';
                } elseif ($fromExists && !$toExists) {
                    $op['journal'][$i]['phase'] = 'abandoned';
                } else {
                    throw new JournalConflict("Cannot tell whether {$e['from']} → {$e['to']} happened");
                }
            }
            return $op;
        });
    }

    public function hasForwardChanges(): bool
    {
        return $this->pendingReversals() !== [];
    }

    public function rollback(): void
    {
        foreach ($this->pendingReversals() as $e) {
            $this->journaled('reverse', $e['seq'], 'undo.' . $e['label'], $e['to'], $e['from']);
        }
    }

    /** @return list<array> done forward entries without a done reverse, newest first */
    private function pendingReversals(): array
    {
        $journal = $this->ops->current()['journal'] ?? [];
        $reversed = [];
        foreach ($journal as $e) {
            if ($e['kind'] === 'reverse' && $e['phase'] === 'done') {
                $reversed[$e['reverses']] = true;
            }
        }
        $pending = array_values(array_filter($journal, static fn (array $e): bool => $e['kind'] === 'forward' && $e['phase'] === 'done' && !isset($reversed[$e['seq']])));
        return array_reverse($pending);
    }

    private function journaled(string $kind, ?int $reverses, string $label, string $from, string $to): void
    {
        $seq = 0;
        $this->ops->update($this->id, $this->owner, static function (array $op) use ($kind, $reverses, $label, $from, $to, &$seq): array {
            $seq = count($op['journal']) + 1;
            $op['journal'][] = ['seq' => $seq, 'kind' => $kind, 'reverses' => $reverses, 'label' => $label, 'from' => $from, 'to' => $to, 'phase' => 'intended'];
            return $op;
        });
        $this->kill($label . '.intended');
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0770, true);
        }
        if (!rename($from, $to)) {
            throw new \RuntimeException("rename {$from} → {$to} failed");
        }
        $this->kill($label . '.renamed');
        $this->ops->update($this->id, $this->owner, static function (array $op) use ($seq): array {
            $op['journal'][$seq - 1]['phase'] = 'done';
            return $op;
        });
    }

    private function kill(string $label): void
    {
        if ($this->killPoint !== null) {
            ($this->killPoint)($label);
        }
    }
}
```

`build-updater` emits `JournalConflict` along with `Journal`, because both are in `Journal.php`.

- [ ] **Step 4: Build and run**

Run: `php scripts/build-updater && php vendor/bin/phpunit tests/Unit/Updater/JournalTest.php`
Expected: PASS (1 + 8 + 4 cases).

- [ ] **Step 5: Commit**

```bash
git add core/src/Updater scripts/build-updater core/resources/updater tests/Unit/Updater
git commit -m "feat(updater): a write-ahead journal that resumes and reverses from any kill point"
```

### Task 9: The supported Composer shape and staging (Review Focus 3)

**Files:**
- Create: `core/src/Updater/ComposerShape.php`, `core/src/Updater/Stager.php`
- Modify: `scripts/build-updater` (runner list gains `ComposerShape`, `Stager` before `ProvisionOutputs`)
- Test: `tests/Unit/Updater/ComposerShapeTest.php`, `tests/Unit/Updater/StagerRelocationTest.php`

**Interfaces:**
- Consumes: `Host` (Task 7).
- Produces:

```php
final class ComposerShape {
    public const PLUGIN_ALLOWLIST = ['php-http/discovery'];
    /** @return list<string> problems, each a sentence naming the key */
    public static function problems(array $composerJson): array;
    /** @return list<string> the top-level project paths the autoload sections name, e.g. ["app"] */
    public static function autoloadRoots(array $composerJson): array;
}
final class Stager {
    public function __construct(private Host $host, private string $php, private string $composer, private string $composerHome, private int $timeout = 1800) {}
    public function prepare(string $base, string $stage): void;              // copies composer.json, composer.lock, vendor/; links autoload roots
    public function update(string $stage, string $target, callable $heartbeat): int; // the pinned command (Global Constraints)
    public static function fingerprint(string $base): array;                 // sha256 of composer.json and composer.lock, size+mtime of vendor/composer/installed.json
    /** @return list<string> problems; [] means the stage is good */
    public function verify(string $base, string $stage, string $target, array $before, callable $heartbeat): array;
}
```

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\ComposerShape;

final class ComposerShapeTest extends TestCase
{
    public function testTheSkeletonShapeIsSupported(): void
    {
        $skeleton = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/skeleton/composer.json'), true);
        self::assertSame([], ComposerShape::problems($skeleton));
        self::assertSame(['app'], ComposerShape::autoloadRoots($skeleton));
    }

    /** @return iterable<string, array{array, string}> */
    public static function unsupported(): iterable
    {
        yield 'path repository' => [['repositories' => [['type' => 'path', 'url' => '../pkg']]], 'repositories'];
        yield 'relative artifact' => [['repositories' => [['type' => 'artifact', 'url' => 'zips/']]], 'repositories'];
        yield 'installer paths' => [['extra' => ['installer-paths' => ['web/{$name}' => ['type:x']]]], 'installer-paths'];
        yield 'vendor dir' => [['config' => ['vendor-dir' => 'lib/vendor']], 'vendor-dir'];
        yield 'all plugins' => [['config' => ['allow-plugins' => true]], 'allow-plugins'];
        yield 'unknown plugin' => [['config' => ['allow-plugins' => ['acme/rewriter' => true]]], 'acme/rewriter'];
        yield 'autoload outside' => [['autoload' => ['psr-4' => ['App\\' => '../shared/']]], 'autoload'];
        yield 'autoload in vendor' => [['autoload' => ['classmap' => ['vendor/x/']]], 'autoload'];
        yield 'absolute autoload' => [['autoload' => ['files' => ['/etc/x.php']]], 'autoload'];
    }

    /** @dataProvider unsupported */
    public function testUnsupportedShapesAreNamed(array $json, string $mentions): void
    {
        $problems = ComposerShape::problems($json);
        self::assertNotSame([], $problems);
        self::assertStringContainsString($mentions, implode(' ', $problems));
    }

    public function testADisabledPluginIsFine(): void
    {
        self::assertSame([], ComposerShape::problems(['config' => ['allow-plugins' => ['acme/rewriter' => false]]]));
    }

    public function testEveryAutoloadSectionContributesItsRoot(): void
    {
        $json = ['autoload' => ['psr-4' => ['App\\' => 'app/', 'Lib\\' => ['src/lib/']], 'classmap' => ['legacy/'], 'files' => ['helpers.php']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]];
        self::assertSame(['app', 'helpers.php', 'legacy', 'src', 'tests'], ComposerShape::autoloadRoots($json));
    }
}
```

`StagerRelocationTest` is Review Focus 3. It builds a real project and generates its autoloader in
the stage. It then renames the staged vendor into the live project, deletes the stage, and loads
from the live tree.

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\RealHost;
use Thallo\Core\Updater\Stager;

final class StagerRelocationTest extends TestCase
{
    private string $base;
    private string $composer;

    protected function setUp(): void
    {
        $this->composer = (string) (getenv('COMPOSER_BINARY') ?: trim((string) shell_exec('command -v composer')));
        if ($this->composer === '') {
            self::markTestSkipped('composer not found');
        }
        $this->base = sys_get_temp_dir() . '/thallo-stage-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/app', 0777, true);
        mkdir($this->base . '/legacy', 0777, true);
        file_put_contents($this->base . '/app/Greeter.php', "<?php\nnamespace App;\nfinal class Greeter { public function hi(): string { return 'hi'; } }\n");
        file_put_contents($this->base . '/legacy/OldThing.php', "<?php\nfinal class OldThing {}\n");
        file_put_contents($this->base . '/helpers.php', "<?php\nfunction thallo_helper(): string { return 'helped'; }\n");
        file_put_contents($this->base . '/composer.json', json_encode([
            'name' => 'acme/site', 'type' => 'project',
            'autoload' => ['psr-4' => ['App\\' => 'app/'], 'classmap' => ['legacy/'], 'files' => ['helpers.php']],
            'config' => ['optimize-autoloader' => true],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->composerIn($this->base, ['install', '--no-interaction', '--no-progress']);
    }

    protected function tearDown(): void
    {
        if (isset($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    public function testTheRelocatedVendorLoadsRootClassesAfterTheStageIsGone(): void
    {
        $stage = $this->base . '/storage/framework/updates/op/stage';
        $log = $this->base . '/stage.log';
        $stager = new Stager(new RealHost($log, []), PHP_BINARY, $this->composer, $this->base . '/composer-home');
        $stager->prepare($this->base, $stage);
        self::assertTrue(is_link($stage . '/app'), 'autoload roots are linked, not copied');
        // The autoloader is generated in the stage exactly as `composer update` would generate it.
        $this->composerIn($stage, ['dump-autoload', '--optimize', '--no-interaction']);

        rename($this->base . '/vendor', $this->base . '/vendor.old');
        rename($stage . '/vendor', $this->base . '/vendor');
        exec('rm -rf ' . escapeshellarg(dirname($stage)) . ' ' . escapeshellarg($this->base . '/vendor.old'));

        $classmap = (string) file_get_contents($this->base . '/vendor/composer/autoload_classmap.php');
        self::assertStringNotContainsString('/stage/', $classmap, 'no path points into the deleted stage');
        $probe = 'require "vendor/autoload.php"; echo (new App\Greeter())->hi(), class_exists("OldThing") ? ":classmap" : ":missing", ":", thallo_helper();';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        self::assertSame('hi:classmap:helped', implode('', $out));
    }

    /** @param list<string> $args */
    private function composerIn(string $dir, array $args): void
    {
        $cmd = escapeshellarg($this->composer) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' --working-dir=' . escapeshellarg($dir) . ' 2>&1';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}
```

If this test shows that Composer writes paths through the symlink into the stage, the fix belongs in
`Stager::prepare`: copy the roots instead of linking them. It never belongs in the assertion. Record
the change as a ruling.

- [ ] **Step 2: Run to see them fail**

Run: `php vendor/bin/phpunit tests/Unit/Updater/ComposerShapeTest.php tests/Unit/Updater/StagerRelocationTest.php`
Expected: FAIL — `ComposerShape` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** The root composer.json shapes staging supports (spec §8.1); anything else updates from the terminal. */
final class ComposerShape
{
    public const PLUGIN_ALLOWLIST = ['php-http/discovery'];

    /** @return list<string> */
    public static function problems(array $json): array
    {
        $problems = [];
        foreach ($json['repositories'] ?? [] as $repo) {
            $type = is_array($repo) ? ($repo['type'] ?? '') : '';
            $url = is_array($repo) ? (string) ($repo['url'] ?? '') : '';
            if ($type === 'path') {
                $problems[] = 'repositories: a "path" repository (symlinked packages) cannot be staged.';
            }
            if ($type === 'artifact' && !str_starts_with($url, '/')) {
                $problems[] = 'repositories: an "artifact" repository needs an absolute url.';
            }
        }
        if (isset($json['extra']['installer-paths'])) {
            $problems[] = 'extra.installer-paths: custom install paths cannot be staged.';
        }
        $vendorDir = $json['config']['vendor-dir'] ?? 'vendor';
        if ($vendorDir !== 'vendor') {
            $problems[] = 'config.vendor-dir: only the default "vendor" is supported.';
        }
        $plugins = $json['config']['allow-plugins'] ?? [];
        if ($plugins === true) {
            $problems[] = 'config.allow-plugins: "true" allows every plugin; list them instead.';
        } elseif (is_array($plugins)) {
            foreach ($plugins as $name => $allowed) {
                if ($allowed === true && !in_array($name, self::PLUGIN_ALLOWLIST, true)) {
                    $problems[] = "config.allow-plugins: {$name} is not on Thallo's plugin allowlist.";
                }
            }
        }
        foreach (self::autoloadPaths($json) as $path) {
            if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) || preg_match('#^vendor(/|$)#', $path)) {
                $problems[] = "autoload: \"{$path}\" must be a path inside the project and outside vendor/.";
            }
        }
        return $problems;
    }

    /** @return list<string> */
    public static function autoloadRoots(array $json): array
    {
        $roots = [];
        foreach (self::autoloadPaths($json) as $path) {
            $first = explode('/', trim($path, '/'))[0];
            if ($first !== '') {
                $roots[$first] = true;
            }
        }
        $roots = array_keys($roots);
        sort($roots);
        return $roots;
    }

    /** @return list<string> */
    private static function autoloadPaths(array $json): array
    {
        $paths = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (['psr-4', 'psr-0'] as $kind) {
                foreach ($json[$section][$kind] ?? [] as $dirs) {
                    foreach ((array) $dirs as $dir) {
                        $paths[] = (string) $dir;
                    }
                }
            }
            foreach (['classmap', 'files'] as $kind) {
                foreach ($json[$section][$kind] ?? [] as $p) {
                    $paths[] = (string) $p;
                }
            }
        }
        return $paths;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** Spec §8 step 2: resolve and install the approved target in a copy, never touching the live tree. */
final class Stager
{
    public function __construct(
        private readonly Host $host,
        private readonly string $php,
        private readonly string $composer,
        private readonly string $composerHome,
        private readonly int $timeout = 1800, // THALLO_UPDATE_COMPOSER_TIMEOUT, via Runner settings
    ) {
    }

    public function prepare(string $base, string $stage): void
    {
        if (!is_dir($stage)) {
            mkdir($stage, 0770, true);
        }
        copy($base . '/composer.json', $stage . '/composer.json');
        copy($base . '/composer.lock', $stage . '/composer.lock');
        $code = $this->host->run(['cp', '-a', $base . '/vendor', $stage . '/vendor'], $base, [], $this->timeout, static function (): void {});
        if ($code !== 0) {
            throw new \RuntimeException('Copying vendor/ into the stage failed');
        }
        $json = json_decode((string) file_get_contents($base . '/composer.json'), true) ?: [];
        foreach (ComposerShape::autoloadRoots($json) as $root) {
            if (file_exists($base . '/' . $root)) {
                symlink($base . '/' . $root, $stage . '/' . $root);
            }
        }
    }

    public function update(string $stage, string $target, callable $heartbeat): int
    {
        return $this->host->run(
            [$this->php, $this->composer, 'update', 'glueful/thallo-core:' . $target, '--with-all-dependencies',
                '--no-interaction', '--no-progress', '--no-ansi', '--no-scripts', '--working-dir=' . $stage],
            $stage,
            ['COMPOSER_HOME' => $this->composerHome, 'THALLO_UPDATE_CHILD' => '1'],
            $this->timeout,
            $heartbeat,
        );
    }

    public static function fingerprint(string $base): array
    {
        $installed = $base . '/vendor/composer/installed.json';
        clearstatcache();
        return [
            'composer.json' => hash_file('sha256', $base . '/composer.json'),
            'composer.lock' => hash_file('sha256', $base . '/composer.lock'),
            'installed.json' => is_file($installed) ? filesize($installed) . '@' . filemtime($installed) : null,
        ];
    }

    /** @return list<string> */
    public function verify(string $base, string $stage, string $target, array $before, callable $heartbeat): array
    {
        $problems = [];
        if (hash_file('sha256', $stage . '/composer.json') !== hash_file('sha256', $base . '/composer.json')) {
            $problems[] = 'A Composer plugin rewrote composer.json in the stage.';
        }
        if (self::fingerprint($base) !== $before) {
            $problems[] = 'The live composer.json, composer.lock or vendor/ changed while staging.';
        }
        $installed = json_decode((string) @file_get_contents($stage . '/vendor/composer/installed.json'), true);
        $version = null;
        foreach (($installed['packages'] ?? $installed ?? []) as $pkg) {
            if (is_array($pkg) && ($pkg['name'] ?? '') === 'glueful/thallo-core') {
                $version = ltrim((string) ($pkg['version'] ?? ''), 'v');
            }
        }
        if ($version !== ltrim($target, 'v')) {
            $problems[] = "The stage installed glueful/thallo-core " . ($version ?? 'nothing') . ", not {$target}.";
        }
        $probe = 'require ' . var_export($stage . '/vendor/autoload.php', true) . ';'
            . 'exit(class_exists("Thallo\\\\Core\\\\Updates\\\\UpdateChecker") ? 0 : 3);';
        if ($this->host->run([$this->php, '-r', $probe], $stage, ['THALLO_UPDATE_CHILD' => '1'], 120, $heartbeat) !== 0) {
            $problems[] = 'The staged autoloader cannot load glueful/thallo-core.';
        }
        return $problems;
    }
}
```

- [ ] **Step 4: Build and run**

Run: `php scripts/build-updater && php vendor/bin/phpunit tests/Unit/Updater/ComposerShapeTest.php tests/Unit/Updater/StagerRelocationTest.php`
Expected: PASS. Run with MAMP PHP first on PATH, so `composer` runs under PHP 8.4 (memory:
skeleton-smoke composer shim).

- [ ] **Step 5: Commit**

```bash
git add core/src/Updater scripts/build-updater core/resources/updater tests/Unit/Updater
git commit -m "feat(updater): stage the pinned target, refuse unsupported Composer shapes, prove relocation"
```

### Task 10: The provision-output inventory, snapshot and restore (Review Focus 4)

**Files:**
- Create: `core/src/Updater/ProvisionOutputs.php`
- Modify: `scripts/build-updater` (runner list gains `ProvisionOutputs`)
- Test: `tests/Unit/Updater/ProvisionOutputsTest.php`

**Interfaces:**
- Consumes: `Journal::rename`.
- Produces:

```php
final class ProvisionOutputs {
    /** Snapshot before provision; restore on rollback (journaled renames). */
    public const SNAPSHOT = ['.env', 'config/extensions.php', 'bootstrap/cache/extensions.php', 'public/admin',
        'docs/openapi.json', 'docs/index.html', 'storage/cache/style'];
    /** Cleared after a rollback by `thallo:update:clear-caches` under the restored code. Entries may be fnmatch patterns. */
    public const CLEAR = ['storage/cache/twig', 'storage/cache/container', 'storage/cache/routes_*.php'];
    /** Kept as the newer copy whatever happens: self-contained and backward compatible, or a report. */
    public const RETAIN = ['bin/thallo-updater', 'bootstrap/update-guard.php', 'storage/conversion', 'storage/framework/composer'];
    /** Owned by the runner and the journal, not provision's to roll back. */
    public const RUNNER_OWNED = ['vendor', 'composer.lock', 'composer.json', 'storage/logs', 'storage/framework/updates', 'storage/backups'];
    /**
     * Copies every SNAPSHOT path, checking every mkdir/copy, then re-reads the copy against the live tree.
     * Throws \RuntimeException on any failure, so the run fails before the swap (nothing live changed).
     * @return array{paths: array<string,bool>, dirs: array<string,int>, files: array<string,array{sha256:string,mode:int}>}
     *         dirs: every snapshotted directory, empty ones included, with its mode
     */
    public static function snapshot(string $base, string $to): array;
    /** Verifies the snapshot against the manifest (contents, readability) before any rename; throws when it does not match. */
    public static function restore(string $base, string $snapshotDir, array $manifest, string $workDir, Journal $journal): void;
    /** The rule for a changed path, or null when the inventory does not cover it. */
    public static function ruleFor(string $relativePath): ?string; // 'snapshot'|'clear'|'retain'|'runner'
}
```

The route cache is `storage/cache/routes_{dev|prod}.php`. The framework's `RouteCache` builds it
from `base_path('storage/cache')` and `routes_{$env}.php` (`RouteCache.php:24,182`), so it is a
`CLEAR` pattern. Proof scenario 18 compares full before/after manifests, so a wrong entry fails it.

**Database writes outside migrations:** capability adoption, role grants, starter blocks, font
library, `install_role_grants.offered`. They have no file rule. They are why any failure after
provision starts is `recovery_required` (spec §10), and the database backup covers them.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Journal;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\ProvisionOutputs;

final class ProvisionOutputsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-outputs-' . bin2hex(random_bytes(4));
        foreach (['storage/framework/updates', 'public/admin/assets', 'storage/cache/style', 'config', 'docs'] as $d) {
            mkdir("{$this->base}/{$d}", 0777, true);
        }
        file_put_contents("{$this->base}/.env", "APP_KEY=old\n");
        file_put_contents("{$this->base}/config/extensions.php", "<?php return ['enabled' => ['a']];");
        file_put_contents("{$this->base}/public/admin/index.html", 'old admin');
        file_put_contents("{$this->base}/public/admin/assets/app-old.js", 'old');
        file_put_contents("{$this->base}/storage/cache/style/settings-old.css", 'old css');
        file_put_contents("{$this->base}/docs/openapi.json", '{"v":"old"}');
        // docs/index.html and bootstrap/cache/extensions.php are absent before provision.
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testRestorePutsBackExactlyWhatProvisionChangedIncludingAbsence(): void
    {
        $manifest = ProvisionOutputs::snapshot($this->base, "{$this->base}/snap");
        self::assertFalse($manifest['paths']['docs/index.html']);
        self::assertTrue($manifest['paths']['public/admin']);
        self::assertSame(hash('sha256', 'old admin'), $manifest['files']['public/admin/index.html']['sha256']);

        // What a newer provision does:
        file_put_contents("{$this->base}/.env", "APP_KEY=old\nNEW_KEY=x\n");
        file_put_contents("{$this->base}/public/admin/index.html", 'new admin');
        unlink("{$this->base}/public/admin/assets/app-old.js");
        file_put_contents("{$this->base}/public/admin/assets/app-new.js", 'new');
        unlink("{$this->base}/storage/cache/style/settings-old.css");
        file_put_contents("{$this->base}/storage/cache/style/settings-new.css", 'new css');
        file_put_contents("{$this->base}/docs/index.html", 'new ui');
        mkdir("{$this->base}/bootstrap/cache", 0777, true);
        file_put_contents("{$this->base}/bootstrap/cache/extensions.php", '<?php return [];');

        $ops = new OperationStore(new Paths($this->base), static fn (): int => 1);
        [$op] = $ops->start('2', '1', 'u');
        $run = $ops->claim($op['id'], 1, 1);
        ProvisionOutputs::restore($this->base, "{$this->base}/snap", $manifest, "{$this->base}/work", new Journal($ops, $run['id'], $run['owner']));

        self::assertSame("APP_KEY=old\n", file_get_contents("{$this->base}/.env"));
        self::assertSame('old admin', file_get_contents("{$this->base}/public/admin/index.html"));
        self::assertFileExists("{$this->base}/public/admin/assets/app-old.js");
        self::assertFileDoesNotExist("{$this->base}/public/admin/assets/app-new.js");
        self::assertFileExists("{$this->base}/storage/cache/style/settings-old.css", 'the old code needs its own compiled style');
        self::assertFileDoesNotExist("{$this->base}/docs/index.html", 'absent before, absent after');
        self::assertFileDoesNotExist("{$this->base}/bootstrap/cache/extensions.php");
        self::assertTrue(is_dir("{$this->base}/snap"), 'the backup snapshot is left intact');
    }

    public function testEmptySnapshottedDirectoriesComeBackWithTheirModes(): void
    {
        unlink("{$this->base}/storage/cache/style/settings-old.css"); // a top-level snapshot path, now empty
        mkdir("{$this->base}/public/admin/empty", 0750);              // an empty directory inside one
        $manifest = ProvisionOutputs::snapshot($this->base, "{$this->base}/snap");
        self::assertArrayHasKey('storage/cache/style', $manifest['dirs']);
        self::assertSame(0750, $manifest['dirs']['public/admin/empty']);

        file_put_contents("{$this->base}/storage/cache/style/settings-new.css", 'new css');
        exec('rm -rf ' . escapeshellarg("{$this->base}/public/admin/empty"));

        $ops = new OperationStore(new Paths($this->base), static fn (): int => 1);
        [$op] = $ops->start('2', '1', 'u');
        $run = $ops->claim($op['id'], 1, 1);
        ProvisionOutputs::restore($this->base, "{$this->base}/snap", $manifest, "{$this->base}/work", new Journal($ops, $run['id'], $run['owner']));

        self::assertDirectoryExists("{$this->base}/storage/cache/style");
        self::assertSame([], array_values(array_diff(scandir("{$this->base}/storage/cache/style"), ['.', '..'])), 'empty, as before');
        self::assertDirectoryExists("{$this->base}/public/admin/empty");
        self::assertSame('0750', substr(sprintf('%o', fileperms("{$this->base}/public/admin/empty")), -4));
    }

    public function testAFailedCopyRefusesTheSnapshot(): void
    {
        chmod("{$this->base}/public/admin/assets/app-old.js", 0000);
        try {
            ProvisionOutputs::snapshot($this->base, "{$this->base}/snap");
            self::fail('an unreadable file must refuse the snapshot');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('public/admin/assets/app-old.js', $e->getMessage());
        } finally {
            chmod("{$this->base}/public/admin/assets/app-old.js", 0644);
        }
    }

    public function testRestoreRefusesATamperedSnapshotBeforeTouchingAnything(): void
    {
        $manifest = ProvisionOutputs::snapshot($this->base, "{$this->base}/snap");
        file_put_contents("{$this->base}/public/admin/index.html", 'new admin');
        file_put_contents("{$this->base}/snap/public/admin/index.html", 'tampered');
        $ops = new OperationStore(new Paths($this->base), static fn (): int => 1);
        [$op] = $ops->start('2', '1', 'u');
        $run = $ops->claim($op['id'], 1, 1);
        try {
            ProvisionOutputs::restore($this->base, "{$this->base}/snap", $manifest, "{$this->base}/work", new Journal($ops, $run['id'], $run['owner']));
            self::fail('a snapshot that does not match its manifest is never restored');
        } catch (\RuntimeException) {
        }
        self::assertSame('new admin', file_get_contents("{$this->base}/public/admin/index.html"), 'nothing was moved');
        self::assertSame([], $ops->current()['journal']);
    }

    public function testEveryInventoriedPathHasExactlyOneRule(): void
    {
        $all = array_merge(ProvisionOutputs::SNAPSHOT, ProvisionOutputs::CLEAR, ProvisionOutputs::RETAIN, ProvisionOutputs::RUNNER_OWNED);
        self::assertSame(count($all), count(array_unique($all)));
        self::assertSame('retain', ProvisionOutputs::ruleFor('bin/thallo-updater'));
        self::assertSame('snapshot', ProvisionOutputs::ruleFor('public/admin/assets/x.js'));
        self::assertSame('runner', ProvisionOutputs::ruleFor('vendor/autoload.php'));
        self::assertSame('clear', ProvisionOutputs::ruleFor('storage/cache/routes_prod.php'));
        self::assertNull(ProvisionOutputs::ruleFor('themes/default/theme.json'), 'an uninventoried path has no rule');
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `php vendor/bin/phpunit tests/Unit/Updater/ProvisionOutputsTest.php`
Expected: FAIL — `ProvisionOutputs` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Every file provision can change, each with one rule (Review Focus 4). The proof (scenario 18)
 * diffs full before/after manifests of a real provision against this list; a changed, added or
 * deleted path without a rule fails it.
 */
final class ProvisionOutputs
{
    public const SNAPSHOT = ['.env', 'config/extensions.php', 'bootstrap/cache/extensions.php', 'public/admin',
        'docs/openapi.json', 'docs/index.html', 'storage/cache/style'];
    public const CLEAR = ['storage/cache/twig', 'storage/cache/container', 'storage/cache/routes_*.php'];
    public const RETAIN = ['bin/thallo-updater', 'bootstrap/update-guard.php', 'storage/conversion', 'storage/framework/composer'];
    public const RUNNER_OWNED = ['vendor', 'composer.lock', 'composer.json', 'storage/logs', 'storage/framework/updates', 'storage/backups'];

    /** @return array{paths: array<string,bool>, dirs: array<string,int>, files: array<string,array{sha256:string,mode:int}>} */
    public static function snapshot(string $base, string $to): array
    {
        $manifest = ['paths' => [], 'dirs' => [], 'files' => []];
        foreach (self::SNAPSHOT as $path) {
            $src = $base . '/' . $path;
            $manifest['paths'][$path] = file_exists($src);
            if (!$manifest['paths'][$path]) {
                continue;
            }
            foreach (self::dirs($src, $path) as $rel => $abs) {
                $mode = fileperms($abs) & 0777;
                self::mustMkdir($to . '/' . $rel);
                $manifest['dirs'][$rel] = $mode;
            }
            foreach (self::files($src, $path) as $rel => $abs) {
                $hash = @hash_file('sha256', $abs);
                if ($hash === false) {
                    throw new \RuntimeException("Cannot read {$rel} for the snapshot.");
                }
                self::mustCopy($abs, $to . '/' . $rel);
                if (hash_file('sha256', $to . '/' . $rel) !== $hash) {
                    throw new \RuntimeException("The snapshot copy of {$rel} does not match.");
                }
                $manifest['files'][$rel] = ['sha256' => $hash, 'mode' => fileperms($abs) & 0777];
            }
        }
        return $manifest;
    }

    public static function restore(string $base, string $snapshotDir, array $manifest, string $workDir, Journal $journal): void
    {
        foreach ($manifest['files'] as $rel => $meta) {
            $copy = $snapshotDir . '/' . $rel;
            if (!is_readable($copy) || hash_file('sha256', $copy) !== $meta['sha256']) {
                throw new \RuntimeException("The snapshot of {$rel} is missing or changed; nothing was restored.");
            }
        }
        foreach (array_keys($manifest['dirs']) as $rel) {
            if (!is_dir($snapshotDir . '/' . $rel)) {
                throw new \RuntimeException("The snapshot of directory {$rel} is missing; nothing was restored.");
            }
        }
        foreach ($manifest['paths'] as $path => $present) {
            $live = $base . '/' . $path;
            if (file_exists($live)) {
                $journal->rename($live, $workDir . '/replaced/' . $path, 'restore.out.' . $path);
            }
            if (!$present) {
                continue;
            }
            // Restore from a fresh copy so the backup snapshot stays whole for the owner. Directories
            // first, from the manifest (empty ones included), then files; modes are applied last so a
            // read-only directory still receives its files.
            $under = static fn (string $rel): bool => $rel === $path || str_starts_with($rel, $path . '/');
            foreach ($manifest['dirs'] as $rel => $mode) {
                if ($under($rel)) {
                    self::mustMkdir($workDir . '/restore/' . $rel);
                }
            }
            foreach ($manifest['files'] as $rel => $meta) {
                if ($under($rel)) {
                    self::mustCopy($snapshotDir . '/' . $rel, $workDir . '/restore/' . $rel);
                    if (!@chmod($workDir . '/restore/' . $rel, $meta['mode'])) {
                        throw new \RuntimeException("Cannot set the mode of {$rel}.");
                    }
                }
            }
            foreach (array_reverse($manifest['dirs'], true) as $rel => $mode) {
                if ($under($rel) && !@chmod($workDir . '/restore/' . $rel, $mode)) {
                    throw new \RuntimeException("Cannot set the mode of {$rel}.");
                }
            }
            $journal->rename($workDir . '/restore/' . $path, $live, 'restore.in.' . $path);
        }
    }

    public static function ruleFor(string $relativePath): ?string
    {
        foreach (['snapshot' => self::SNAPSHOT, 'clear' => self::CLEAR, 'retain' => self::RETAIN, 'runner' => self::RUNNER_OWNED] as $rule => $paths) {
            foreach ($paths as $p) {
                $matches = str_contains($p, '*')
                    ? fnmatch($p, $relativePath)
                    : $relativePath === $p || str_starts_with($relativePath, rtrim($p, '/') . '/');
                if ($matches) {
                    return $rule;
                }
            }
        }
        return null;
    }

    /** @return iterable<string,string> relative path => absolute path: the directory itself and every directory under it */
    private static function dirs(string $abs, string $rel): iterable
    {
        if (!is_dir($abs)) {
            return;
        }
        yield $rel => $abs;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            if ($item->isDir()) {
                yield $rel . '/' . substr($item->getPathname(), strlen($abs) + 1) => $item->getPathname();
            }
        }
    }

    /** @return iterable<string,string> relative path => absolute path, for a file or every file under a directory */
    private static function files(string $abs, string $rel): iterable
    {
        if (is_file($abs)) {
            yield $rel => $abs;
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $item) {
            if ($item->isFile()) {
                yield $rel . '/' . substr($item->getPathname(), strlen($abs) + 1) => $item->getPathname();
            }
        }
    }

    private static function mustCopy(string $from, string $to): void
    {
        self::mustMkdir(dirname($to));
        if (!@copy($from, $to)) {
            throw new \RuntimeException("Cannot copy {$from} to {$to}.");
        }
    }

    private static function mustMkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }
    }
}
```

- [ ] **Step 4: Build and run**

Run: `php scripts/build-updater && php vendor/bin/phpunit tests/Unit/Updater/ProvisionOutputsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add core/src/Updater scripts/build-updater core/resources/updater tests/Unit/Updater
git commit -m "feat(updater): the provision-output inventory, snapshotted and restored by the journal"
```

### Task 11: The runner's child commands — verify, backup, clear caches

**Files:**
- Create: `core/src/Updates/Console/UpdateVerifyCommand.php`, `UpdateBackupCommand.php`, `UpdateClearCachesCommand.php`
- Create: `core/src/Updates/PgDumpConnection.php`
- Modify: `core/src/Providers/CoreServiceProvider.php`. Add the three commands to the service list (beside ProvisionCommand at ~3083) and to the command list (~3203), each as `['class' => X::class, 'shared' => true, 'autowire' => true]`.
- Test: `tests/Integration/Updates/UpdateChildCommandsTest.php` (shard E already holds `tests/Integration/Updates`)

**Interfaces:**
- Produces:
  - `php glueful thallo:update:verify --json` → stdout `{"version": "<pretty glueful/thallo-core>", "php": "8.4.x"}`, exit 0.
  - `php glueful thallo:update:backup --to=<dir> --json`:
    - writes `<dir>/database.dump` (custom format, 0600) and `<dir>/manifest.json`
      `{database, dumped_at, bytes, sha256}` (0600), and chmods `<dir>` to 0700;
    - stdout is the manifest;
    - exit 0 **only** when `pg_dump` exited 0, the file is non-empty, and `pg_restore --list` exited 0
      with output. Otherwise exit 1 with `{"error": "…"}`.
  - `php glueful thallo:update:backup --estimate --json` → `{"database_bytes": int}`.
  - `php glueful thallo:update:clear-caches` → runs `UpgradeCaches::clear()` and
    `invalidateTags(['thallo:render:page','thallo:shop:catalog'])`, exit 0.
  - `PgDumpConnection::resolve(): array{host:string,port:string,username:string,database:string,env:array<string,string>}`
    is ported line for line from `DatabaseBackupTask::dumpCommand()`
    (`vendor/glueful/framework/src/Tasks/DatabaseBackupTask.php:97-121`): host, port, username,
    database, `PGPASSWORD` and `PGSSLMODE` when set.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Updates;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Updates\Console\UpdateBackupCommand;
use Thallo\Core\Updates\Console\UpdateVerifyCommand;

final class UpdateChildCommandsTest extends AppTestCase
{
    public function testVerifyReportsTheInstalledVersion(): void
    {
        $tester = new CommandTester($this->container()->get(UpdateVerifyCommand::class));
        self::assertSame(0, $tester->execute(['--json' => true]));
        $data = json_decode($tester->getDisplay(), true);
        self::assertIsString($data['version']);
        self::assertSame(PHP_VERSION, $data['php']);
    }

    public function testABackupCountsOnlyWhenPgRestoreCanReadIt(): void
    {
        if (trim((string) shell_exec('command -v pg_dump')) === '') {
            self::markTestSkipped('pg_dump not installed');
        }
        $dir = sys_get_temp_dir() . '/thallo-backup-' . bin2hex(random_bytes(4));
        $tester = new CommandTester($this->container()->get(UpdateBackupCommand::class));
        self::assertSame(0, $tester->execute(['--to' => $dir, '--json' => true]), $tester->getDisplay());
        $manifest = json_decode($tester->getDisplay(), true);
        self::assertGreaterThan(0, $manifest['bytes']);
        self::assertSame(hash_file('sha256', $dir . '/database.dump'), $manifest['sha256']);
        self::assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
        self::assertSame('0600', substr(sprintf('%o', fileperms($dir . '/database.dump')), -4));
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testAnUnreadableDumpFails(): void
    {
        // A pg_dump that "succeeds" but writes garbage: PATH points at a stub.
        $bin = sys_get_temp_dir() . '/thallo-fake-pg-' . bin2hex(random_bytes(4));
        mkdir($bin);
        file_put_contents($bin . '/pg_dump', "#!/bin/sh\nfor a in \"\$@\"; do case \$a in --file=*) echo garbage > \"\${a#--file=}\";; esac; done\nexit 0\n");
        chmod($bin . '/pg_dump', 0755);
        $old = getenv('PATH');
        putenv('PATH=' . $bin . ':' . $old);
        try {
            $tester = new CommandTester($this->container()->get(UpdateBackupCommand::class));
            self::assertSame(1, $tester->execute(['--to' => $bin . '/out', '--json' => true]));
            self::assertStringContainsString('pg_restore', $tester->getDisplay());
        } finally {
            putenv('PATH=' . $old);
            exec('rm -rf ' . escapeshellarg($bin));
        }
    }
}
```

`UpdateBackupCommand` resolves `pg_dump` and `pg_restore` from `PATH` at execution time. That keeps
the stub test honest, and the framework's `ProcessRunner` receives the bare names.

- [ ] **Step 2: Run to see it fail**

Run: `php vendor/bin/phpunit tests/Integration/Updates/UpdateChildCommandsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement.** Model the three commands on `UpdateCheckCommand`
  (`core/src/Updates/Console/UpdateCheckCommand.php`): `#[AsCommand]`, extend
  `Glueful\Console\BaseCommand`, and add options in `configure()`. The backup's core:

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $conn = (new PgDumpConnection($this->getContext()))->resolve();
    $runner = $this->getService(ProcessRunner::class);
    if ($input->getOption('estimate')) {
        $bytes = (int) $this->getService(Connection::class)->query('SELECT pg_database_size(current_database()) AS b')->fetch()['b'];
        $output->writeln(json_encode(['database_bytes' => $bytes]));
        return self::SUCCESS;
    }
    $dir = (string) $input->getOption('to');
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        return $this->fail($output, "Cannot create {$dir}");
    }
    chmod($dir, 0700);
    $file = $dir . '/database.dump';
    $dump = $runner->run(['pg_dump', '--host=' . $conn['host'], '--port=' . $conn['port'], '--username=' . $conn['username'],
        '--no-password', '--format=custom', '--file=' . $file, $conn['database']], $dir, 3600, null, array_merge(getenv(), $conn['env']));
    if ($dump['exitCode'] !== 0) {
        return $this->fail($output, 'pg_dump failed: ' . trim($dump['output']));
    }
    if (!is_file($file) || filesize($file) === 0) {
        return $this->fail($output, 'pg_dump wrote nothing');
    }
    chmod($file, 0600);
    $list = $runner->run(['pg_restore', '--list', $file], $dir, 600, null, null);
    if ($list['exitCode'] !== 0 || trim($list['output']) === '') {
        return $this->fail($output, 'pg_restore cannot read the dump: ' . trim($list['output']));
    }
    $manifest = ['database' => $conn['database'], 'dumped_at' => gmdate('c'), 'bytes' => filesize($file), 'sha256' => hash_file('sha256', $file)];
    file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    chmod($dir . '/manifest.json', 0600);
    $output->writeln((string) json_encode($manifest));
    return self::SUCCESS;
}

private function fail(OutputInterface $output, string $error): int
{
    $output->writeln((string) json_encode(['error' => $error]));
    return self::FAILURE;
}
```

`Connection::class` is the database connection service the codebase already injects. Grep
`->get(Connection::class)` under `core/src` and use the same class.

`UpdateClearCachesCommand` builds `UpgradeCaches` exactly as `ProvisionCommand.php:404-409` does. It
calls `clear()`, then `$this->getService(CacheStore::class)->invalidateTags(['thallo:render:page', 'thallo:shop:catalog'])`.

`UpdateVerifyCommand` prints `json_encode(['version' => \Composer\InstalledVersions::getPrettyVersion('glueful/thallo-core'), 'php' => PHP_VERSION])`.

- [ ] **Step 4: Run, phpcs, commit**

Run: `php vendor/bin/phpunit tests/Integration/Updates/UpdateChildCommandsTest.php && vendor/bin/phpcs core/src/Updates; echo phpcs=$?`
Expected: PASS (the backup test is skipped without `pg_dump`), `phpcs=0`.

```bash
git add core/src/Updates core/src/Providers/CoreServiceProvider.php tests/Integration/Updates/UpdateChildCommandsTest.php
git commit -m "feat(updates): the runner's child commands — verify, verified backup, clear caches"
```

### Task 12: The runner — tick, the ten steps, crash-safe reopening, recovery (Review Focus 1)

**Files:**
- Create: `core/src/Updater/Runner.php`, `core/src/Updater/RunnerReport.php`
- Modify: `scripts/build-updater` (runner list complete: `… ProvisionOutputs, Stager, RunnerReport, Runner`)
- Test: `tests/Unit/Updater/RunnerTest.php`, `tests/Unit/Updater/FakeHost.php` (test support), `tests/Unit/Updater/RunnerRealHostTest.php`, fixture site `tests/fixtures/updater/realhost-site/` (a `glueful` stub and a `verify-router.php`)

**Interfaces:**
- Consumes: everything from Tasks 3–10, and the child commands of Task 11.
- Produces:

```php
final class Runner {
    public const STEPS = ['preflight', 'stage', 'close', 'barrier', 'backup', 'swap', 'provision', 'verify_cli', 'verify_web', 'reopen'];
    /**
     * $settings: php, composer, verify_url, verify_host, keep_backups, barrier_timeout (600), web_settle (opcache.revalidate_freq + 30)
     * $identity: fn(): array{0:int,1:?int} — pid and process group
     * $onKillPoint: tests only; shipped code uses the .proof marker rule (Global Constraints)
     */
    public function __construct(Paths $paths, OperationStore $ops, Barrier $barrier, Host $host, array $settings,
        \Closure $identity, ?\Closure $onKillPoint = null) {}
    public static function main(string $base, array $argv): int; // tick | recover --rollback|--retry-provision|--reopen | token | selftest
    public function tick(): int;
    public function killPoint(string $name): void;
}
final class RunnerReport {
    /** Writes runner.json: checked_at, user, uid, composer, composer_ok, pg_dump, pg_restore, writable, shape_problems, free_bytes, needed_bytes, guard_installed, web (probe result or null) */
    public static function write(Paths $paths, Host $host, array $settings): array;
}
```

- [ ] **Step 1: Write the test support and the failing tests**

`FakeHost` implements `Host`. It runs `cp` for real (so `Stager::prepare` copies) and scripts
everything else:
- `composer update` writes `stage/vendor/composer/installed.json` with the target version, and
  `stage/vendor/marker` = `new`.
- `php -r` exits 0.
- `glueful thallo:update:backup` creates the backup directory and its manifest, and exits with
  `$this->backupExit`.
- `thallo:provision` exits with `$this->provisionExit`, and runs `$this->onProvision`, which writes
  files the way provision does.
- `thallo:update:verify` returns, **through `runCapture`**, its own stdout `{"version": <current>}`:
  the target after a swap, `from` after a rollback, decided by reading `vendor/marker`. Its exit
  code is `$this->verifyExit`.
- `thallo:update:clear-caches` exits with `$this->clearExit`, and `queue:restart` exits with
  `$this->restartExit`.
- `capture(['pgrep', …])` answers `[1, '']`: no group members. The tests' identity has no process
  group, so `stopGroupMembers` is not called at all.
- `http()` answers the web verify the same way, from `vendor/marker`.
- `now()` and `sleep()` use a virtual clock.

Every call is recorded in `$this->calls`.

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use Thallo\Core\Updater\Host;

final class FakeHost implements Host
{
    /** @var list<list<string>> */
    public array $calls = [];
    public int $backupExit = 0;
    public int $provisionExit = 0;
    public int $stageExit = 0;
    public int $verifyExit = 0;
    public int $clearExit = 0;
    public int $restartExit = 0;
    public ?\Closure $onProvision = null;
    public int $clock = 1_000;

    public function __construct(private readonly string $base, private readonly string $from, private readonly string $target)
    {
    }

    public function run(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): int
    {
        return $this->runCapture($argv, $cwd, $env, $timeout, $heartbeat)[0];
    }

    public function runCapture(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): array
    {
        $this->calls[] = $argv;
        $heartbeat();
        $joined = implode(' ', $argv);
        if ($argv[0] === 'cp' || $argv[0] === 'rm') {
            exec(implode(' ', array_map('escapeshellarg', $argv)), $o, $code);
            return [$code, ''];
        }
        if (str_contains($joined, ' update glueful/thallo-core:')) {
            $stage = substr((string) end($argv), strlen('--working-dir='));
            @mkdir($stage . '/vendor/composer', 0777, true);
            file_put_contents($stage . '/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'glueful/thallo-core', 'version' => $this->target]]]));
            file_put_contents($stage . '/vendor/marker', 'new');
            return [$this->stageExit, ''];
        }
        if (in_array('-r', $argv, true)) {
            return [0, ''];
        }
        if (str_contains($joined, 'thallo:update:backup')) {
            foreach ($argv as $a) {
                if (str_starts_with($a, '--to=')) {
                    @mkdir(substr($a, 5), 0700, true);
                    file_put_contents(substr($a, 5) . '/manifest.json', '{"bytes":1}');
                }
            }
            return [$this->backupExit, '{"bytes":1}'];
        }
        if (str_contains($joined, 'thallo:provision')) {
            if ($this->onProvision !== null) {
                ($this->onProvision)();
            }
            return [$this->provisionExit, ''];
        }
        if (str_contains($joined, 'thallo:update:verify')) {
            return [$this->verifyExit, $this->verifyExit === 0 ? (string) json_encode(['version' => $this->installed()]) . "\n" : ''];
        }
        if (str_contains($joined, 'thallo:update:clear-caches')) {
            return [$this->clearExit, ''];
        }
        if (str_contains($joined, 'queue:restart')) {
            return [$this->restartExit, ''];
        }
        return [0, ''];
    }
    public function capture(array $argv, string $cwd): array
    {
        $this->calls[] = $argv;
        $joined = implode(' ', $argv);
        return match (true) {
            $argv[0] === 'pgrep' => [1, ''],
            $argv[0] === 'ps' => [0, ''],
            str_contains($joined, '--estimate') => [0, '{"database_bytes":1000}'],
            str_contains($joined, 'composer') && str_contains($joined, '--version') => [0, 'Composer version 2.8.4 2024-12-11'],
            str_contains($joined, '--version') => [0, 'pg_dump (PostgreSQL) 16.4'],
            $argv[0] === 'du' => [0, "1000\tvendor\n"],
            default => [0, ''],
        };
    }

    public function http(string $url, array $headers, string $body): array
    {
        $mode = json_decode($body, true)['mode'] ?? '';
        return [200, (string) json_encode($mode === 'probe'
            ? ['sapi' => 'fpm-fcgi', 'writable_code' => false, 'opcache' => ['enabled' => true, 'validate_timestamps' => true, 'revalidate_freq' => 2, 'preload' => '']]
            : ['version' => $this->installed(), 'missing_extensions' => [], 'container' => true])];
    }

    public function now(): int
    {
        return $this->clock;
    }

    public function sleep(int $seconds): void
    {
        $this->clock += $seconds;
    }

    private function installed(): string
    {
        return trim((string) @file_get_contents($this->base . '/vendor/marker')) === 'new' ? $this->target : $this->from;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\Runner;
use Thallo\Core\Updater\Status;

final class RunnerTest extends TestCase
{
    private string $base;
    private Paths $paths;
    private OperationStore $ops;
    private FakeHost $host;
    private string $token;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-runner-' . bin2hex(random_bytes(4));
        foreach (['storage/framework/updates', 'storage/logs', 'vendor/composer', 'public/admin', 'bootstrap'] as $d) {
            mkdir("{$this->base}/{$d}", 0777, true);
        }
        file_put_contents("{$this->base}/composer.json", (string) file_get_contents(dirname(__DIR__, 3) . '/skeleton/composer.json'));
        file_put_contents("{$this->base}/composer.lock", '{"packages":[]}');
        file_put_contents("{$this->base}/vendor/marker", 'old');
        file_put_contents("{$this->base}/public/admin/index.html", 'old admin');
        file_put_contents("{$this->base}/storage/framework/updates/guard.installed", '100');
        file_put_contents("{$this->base}/storage/framework/updates/guard.ready", '200'); // the owner confirmed the restart
        $this->paths = new Paths($this->base);
        $this->ops = new OperationStore($this->paths, fn (): int => $this->host->clock);
        $this->host = new FakeHost($this->base, '1.0.0-beta.101', '1.0.0-beta.102');
        [, $this->token] = $this->ops->start('1.0.0-beta.102', '1.0.0-beta.101', 'owner');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testTheHappyPathRunsEveryStepAndReopens(): void
    {
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::SUCCEEDED, $op['status'], (string) $op['error']);
        self::assertSame(Runner::STEPS, array_column($op['steps'], 'name'));
        self::assertSame('new', file_get_contents("{$this->base}/vendor/marker"));
        self::assertFileDoesNotExist($this->paths->closedFlag());
        self::assertNotNull($op['reopen_ready_at']);
        self::assertContains('queue:restart', array_merge(...$this->host->calls));
        self::assertDirectoryDoesNotExist($this->paths->opDir($op['id']) . '/stage');
    }

    /** @return iterable<string, array{?string}> */
    public static function unconfirmedRestarts(): iterable
    {
        yield 'never confirmed' => [null];
        yield 'confirmed before the guard was installed' => ['50'];
    }

    /** @dataProvider unconfirmedRestarts */
    public function testAQueuedOperationWithoutRestartConfirmationFailsBeforeStaging(?string $ready): void
    {
        $file = "{$this->base}/storage/framework/updates/guard.ready";
        $ready === null ? unlink($file) : file_put_contents($file, $ready);
        $this->runner()->tick(); // no old processes are visible (FakeHost's ps is empty)
        $op = $this->ops->current();
        self::assertSame(Status::FAILED, $op['status']);
        self::assertStringContainsString('confirm-restart', (string) $op['error']);
        self::assertSame(['preflight'], array_column($op['steps'], 'name'), 'nothing after preflight ran');
        $joined = implode("\n", array_map(static fn (array $c): string => implode(' ', $c), $this->host->calls));
        self::assertStringNotContainsString('update glueful/thallo-core', $joined, 'no staging');
        self::assertStringNotContainsString('thallo:update:backup', $joined, 'no backup');
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testAStagingFailureNeverClosesTheSite(): void
    {
        $this->host->stageExit = 2;
        $this->runner()->tick();
        self::assertSame(Status::FAILED, $this->ops->current()['status']);
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        self::assertNotContains('close', array_column($this->ops->current()['steps'], 'name'));
    }

    public function testABarrierTimeoutReopensWithNothingChanged(): void
    {
        $holder = (new Barrier($this->paths))->admit(); // a request that never finishes
        $this->runner()->tick();
        FileLock::release($holder);
        self::assertSame(Status::FAILED, $this->ops->current()['status']);
        self::assertStringContainsString('barrier', (string) $this->ops->current()['error']);
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testAFailedBackupChangesNothing(): void
    {
        $this->host->backupExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::FAILED, $this->ops->current()['status']);
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testAProvisionFailureNeedsRecoveryEvenWithoutAMigration(): void
    {
        $this->host->onProvision = fn () => file_put_contents("{$this->base}/public/admin/index.html", 'new admin');
        $this->host->provisionExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->ops->current()['status']);
        self::assertFileExists($this->paths->closedFlag(), 'the site stays closed');
    }

    public function testADeathBeforeAnyRenameFailsAndReopens(): void
    {
        $this->killAt('backup.done');
        $this->runner()->tick(); // a fresh runner finds the dead run
        self::assertSame(Status::FAILED, $this->ops->current()['status']);
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testADeathMidSwapRollsBackOnlyWhenTheOwnerAsks(): void
    {
        $this->killAt('swap.2.renamed');
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->ops->current()['status']);
        $this->ops->requestRecovery($this->token, 'rollback');
        $this->runner()->tick();
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        self::assertSame(Status::RECOVERY_REQUIRED, $this->ops->current()['status'], 'code back, still closed until the owner reopens');
        $this->ops->requestRecovery($this->token, 'reopen');
        $this->runner()->tick();
        self::assertSame(Status::RECOVERED_BY_OWNER, $this->ops->current()['status']);
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    /** @return iterable<string, array{string}> */
    public static function reopenKillPoints(): iterable
    {
        yield 'before removing the flag' => ['reopen.before-open'];
        yield 'after removing the flag' => ['reopen.after-open'];
        yield 'after releasing the barrier' => ['reopen.after-release'];
    }

    /** @dataProvider reopenKillPoints */
    public function testADeathAfterVerificationCompletesTheReopen(string $point): void
    {
        $this->killAt($point);
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::SUCCEEDED, $op['status']);
        self::assertSame('new', file_get_contents("{$this->base}/vendor/marker"), 'never rolled back once verified');
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testRollbackRecoveryRestoresProvisionOutputs(): void
    {
        $this->host->onProvision = fn () => file_put_contents("{$this->base}/public/admin/index.html", 'new admin');
        $this->host->provisionExit = 1;
        $this->runner()->tick();
        $this->ops->requestRecovery($this->token, 'rollback');
        $this->runner()->tick();
        self::assertSame('old admin', file_get_contents("{$this->base}/public/admin/index.html"));
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
    }

    public function testAFailedVerificationIsAFailureEvenWithAnOldSuccessInTheLog(): void
    {
        file_put_contents($this->paths->log($this->ops->current()['id']), '{"version":"1.0.0-beta.102"}' . "
");
        $this->host->verifyExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->ops->current()['status'], 'the stale log line is never read as this check's answer');
        self::assertFileExists($this->paths->closedFlag());
    }

    public function testAFailedCacheClearNeverReachesTheReopenMarker(): void
    {
        $this->host->clearExit = 1;
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::RECOVERY_REQUIRED, $op['status']);
        self::assertNull($op['reopen_ready_at']);
        self::assertFileExists($this->paths->closedFlag());
        $this->runner()->tick(); // and a later tick does not reopen it either
        self::assertFileExists($this->paths->closedFlag());
    }

    public function testAFailedWorkerSignalNeverReachesTheReopenMarker(): void
    {
        $this->host->restartExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::FAILED, $this->ops->current()['status'], 'the signal at close fails before anything changed');
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testAPausedTickResumingAfterCompletionAndANewStartTouchesOnlyWhatItNowOwns(): void
    {
        $first = $this->ops->current()['id'];
        $second = null;
        // Runner A pauses before taking the run lock; meanwhile runner B completes the operation and a new one starts.
        $paused = $this->runner(function (string $name) use (&$second): void {
            if ($name === 'tick.before-lock' && $second === null) {
                $this->runner()->tick();
                [$op] = $this->ops->start('1.0.0-beta.103', '1.0.0-beta.102', 'owner');
                $second = $op['id'];
            }
        });
        $paused->tick();
        $ops = $this->ops->current();
        self::assertSame($second, $ops['id'], 'the successor is current');
        self::assertNotSame($first, $ops['id']);
        // A claimed only the successor it re-read under the lock; nothing of the first was rewritten.
        self::assertContains($ops['status'], [Status::SUCCEEDED, Status::RECOVERY_REQUIRED, Status::FAILED]);
        self::assertSame('1.0.0-beta.103', $ops['target']);
    }

    public function testAStaleRunnerIsFencedOutOfTheSuccessor(): void
    {
        $this->runner()->tick();
        $done = $this->ops->current();
        [$next] = $this->ops->start('1.0.0-beta.103', '1.0.0-beta.102', 'owner');
        $this->expectException(\Thallo\Core\Updater\Fenced::class);
        $this->ops->finish($done['id'], (string) $done['owner'], Status::FAILED, 'late');
    }

    /** @requires extension posix */
    public function testNoRecoveryClaimWhileADescendantThatClosedItsLockKeepsWriting(): void
    {
        // A runner failed its descendant cleanup (recovery_required, its group recorded) and exited.
        // Its descendant closed every inherited descriptor and keeps writing: the run lock is free.
        $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/updater/fail-cleanup-and-die.php', $this->base], [], $pipes);
        for ($i = 0; $i < 100 && (proc_get_status($p)['running'] || !is_file($this->base . '/writer.pid')); $i++) {
            usleep(50_000);
        }
        proc_close($p);
        $writer = (int) file_get_contents($this->base . '/writer.pid');
        try {
            self::assertSame(Status::RECOVERY_REQUIRED, $this->ops->current()['status']);
            self::assertTrue($this->ops->requestRecovery($this->token, 'reopen'));
            $this->runner()->tick();
            self::assertSame(Status::RECOVERY_REQUIRED, $this->ops->current()['status'], 'not claimed while the group has a member');
            self::assertSame('reopen', $this->ops->current()['recovery_request'], 'the request waits');
            self::assertFileExists($this->paths->closedFlag(), 'the site stays closed');
            $grew = filesize($this->base . '/writes');
            usleep(500_000);
            clearstatcache();
            self::assertGreaterThan($grew, filesize($this->base . '/writes'), 'the descendant really is still writing');
        } finally {
            posix_kill($writer, SIGKILL);
        }
        for ($i = 0; $i < 40 && posix_kill($writer, 0); $i++) {
            usleep(50_000);
        }
        $this->runner()->tick();
        self::assertSame(Status::RECOVERED_BY_OWNER, $this->ops->current()['status'], 'claimed once the group is empty');
    }

    public function testASecondTickCannotClaimWhileTheFirstHoldsTheRunLock(): void
    {
        $held = FileLock::exclusive($this->paths->runLock());
        $this->runner()->tick();
        self::assertSame(Status::REQUESTED, $this->ops->current()['status']);
        FileLock::release($held);
    }

    /** Runs a tick that dies at $point: the kill throws, the runner's handles are dropped. */
    private function killAt(string $point): void
    {
        try {
            $this->runner(static function (string $name) use ($point): void {
                if ($name === $point) {
                    throw new \RuntimeException('killed at ' . $name);
                }
            })->tick();
            self::fail("kill point {$point} did not fire");
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('killed at', $e->getMessage());
        }
        gc_collect_cycles(); // the dead runner's lock handles close
    }

    private function runner(?\Closure $kill = null): Runner
    {
        return new Runner($this->paths, $this->ops, new Barrier($this->paths), $this->host,
            ['php' => PHP_BINARY, 'composer' => '/usr/local/bin/composer', 'verify_url' => 'http://127.0.0.1/_update/verify',
                'verify_host' => 'site.test', 'keep_backups' => 3, 'barrier_timeout' => 5, 'web_settle' => 10],
            static fn (): array => [12345, null], // no process group: a dropped runner is dead at once
            $kill);
    }
}
```

`tests/fixtures/updater/fail-cleanup-and-die.php` (it requires the repository autoloader) does the
following:
- calls `posix_setpgid(0, 0)`;
- takes `run.lock`;
- claims the operation in `<base>` with its own pid and group;
- writes `closed.flag`;
- starts `sh -c 'exec 3>&- 4>&-; echo $$ > writer.pid; while :; do date >> writes; sleep 0.1; done'`
  in the background, with the lock as descriptor 3;
- finishes the operation as `recovery_required` (error "cleanup failed"), as a failed
  `stopDescendants` would;
- exits, which releases its own lock handle.

The writer is still in the fixture's process group, and it no longer holds the lock.

The runner must **not** catch the kill exception: kill points rethrow past every `catch`. The code
below wraps it in `Killed`, which each step's `catch` rethrows as the original exception, so the test
sees its own.

**`RunnerRealHostTest`** drives the same runner through `RealHost`, real child processes and a real
HTTP server, so the descriptor handling is tested for real. In `setUp` it does the following:
- copies `tests/fixtures/updater/realhost-site/` to a temp base;
- starts `php -S 127.0.0.1:<free port> verify-router.php` there. The router answers
  `POST /_update/verify` from `vendor/marker`, exactly as `FakeHost::http` does;
- uses a `glueful` stub, a PHP script that does the following:
  - for each argument, does what `FakeHost` scripts;
  - for `thallo:update:verify`, prints the version from `vendor/marker`;
  - for `clear-caches`, exits with the code in `storage/clear-exit` (default 0);
  - always calls `fstat(STDOUT)`, and also checks that every inherited descriptor from 3 up is a
    valid open stream (`@fstat(fopen('php://fd/3', 'r'))`), exiting 99 when one is closed.
- Composer is a stub script too, set through `settings['composer']`, that writes the staged tree as
  the fake does.

The tests:
1. `testReopeningThroughRealHostPassesNoClosedDescriptor`: the full happy path through `RealHost`.
   Every child after the barrier's release exits 0, never 99, and the result is `succeeded`.
2. `testStaleVerificationOutputIsNotSuccessThroughRealHost`: the log already holds an old success
   line, and the stub `verify` exits 1 with no output. The result is `recovery_required`.
3. `testAFailedCacheClearThroughRealHostKeepsTheSiteClosed`: `storage/clear-exit` holds `1`. The
   result is `recovery_required`, `reopen_ready_at` is null, and `closed.flag` stays.
4. `testTheRunLockIsInheritedByChildren`: a stub command records whether descriptor 3 is a lock on
   `run.lock`, by calling `flock(LOCK_EX|LOCK_NB)` on a fresh `fopen` of `run.lock` from inside the
   child. That must fail while the runner runs.

- [ ] **Step 2: Run to see them fail**

Run: `php vendor/bin/phpunit tests/Unit/Updater/RunnerTest.php`
Expected: FAIL — `Runner` not found.

- [ ] **Step 3: Implement `Runner`**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class Killed extends \RuntimeException
{
    public function __construct(public readonly \Throwable $cause)
    {
        parent::__construct($cause->getMessage());
    }
}

/**
 * The update runner (spec §3, §8, §10). Run by cron as the code owner: `php bin/thallo-updater tick`.
 * Self-contained once started; everything that needs the application runs as a child. Every write
 * names the operation id and the owner this runner was given (OperationStore fencing).
 */
final class Runner
{
    public const STEPS = ['preflight', 'stage', 'close', 'barrier', 'backup', 'swap', 'provision', 'verify_cli', 'verify_web', 'reopen'];

    /** @var resource|null */
    private $runLock = null;
    /** @var resource|null */
    private $barrierLock = null;
    private ?Journal $journal = null;
    private ?string $id = null;
    private ?string $owner = null;

    public function __construct(
        private readonly Paths $paths,
        private readonly OperationStore $ops,
        private readonly Barrier $barrier,
        private readonly Host $host,
        private readonly array $settings,
        private readonly \Closure $identity,
        private readonly ?\Closure $onKillPoint = null,
    ) {
    }

    /** @param list<string> $argv */
    public static function main(string $base, array $argv): int
    {
        if (function_exists('posix_setpgid')) {
            @posix_setpgid(0, 0); // ruling 5: our children share this group
        }
        $paths = new Paths($base);
        $ops = new OperationStore($paths, static fn (): int => time());
        $env = self::dotenv($base . '/.env');
        $report = AtomicFile::readJson($paths->runnerReport()) ?? [];
        $settings = [
            'php' => PHP_BINARY,
            'composer' => getenv('COMPOSER_BINARY') ?: self::which('composer'),
            'verify_url' => $env['THALLO_UPDATE_VERIFY_URL'] ?? 'http://127.0.0.1/_update/verify',
            'verify_host' => (string) (parse_url($env['BASE_URL'] ?? '', PHP_URL_HOST) ?: ''),
            'keep_backups' => (int) ($env['THALLO_UPDATE_KEEP_BACKUPS'] ?? 3),
            'barrier_timeout' => (int) ($env['THALLO_UPDATE_BARRIER_TIMEOUT'] ?? 600),
            'child_timeout' => (int) ($env['THALLO_UPDATE_CHILD_TIMEOUT'] ?? 3600),
            'composer_timeout' => (int) ($env['THALLO_UPDATE_COMPOSER_TIMEOUT'] ?? 1800),
            // Spec §8 step 9: opcache.revalidate_freq (from PHP-FPM's own probe) + 30 s.
            'web_settle' => 30 + (int) ($report['web']['opcache']['revalidate_freq'] ?? 2),
        ];
        $op = $ops->current();
        $log = $op !== null ? $paths->log($op['id']) : $base . '/storage/logs/updater.log';
        $identity = static fn (): array => [getmypid(), function_exists('posix_getpgid') ? (posix_getpgid(0) ?: null) : null];
        [$pid, $pgid] = $identity();
        $runner = new self($paths, $ops, new Barrier($paths), new RealHost($log, [], $pgid, $pid), $settings, $identity);
        return match ($argv[1] ?? 'tick') {
            'tick' => $runner->tick(),
            'recover' => $runner->recoverFromTerminal($argv[2] ?? ''),
            'token' => $runner->printToken(),
            'selftest' => $runner->selftest(),
            'confirm-restart' => $runner->confirmRestart(in_array('--yes', $argv, true)),
            default => 2,
        };
    }

    public function tick(): int
    {
        $this->ensureSecret();
        RunnerReport::write($this->paths, $this->host, $this->settings);
        if ($this->ops->current() === null) {
            return 0;
        }
        $this->killPoint('tick.before-lock');
        $lock = FileLock::exclusive($this->paths->runLock(), false);
        if ($lock === null) {
            return 0; // a live runner, or a descendant still holding the inherited lock, owns execution
        }
        $this->runLock = $lock;
        if ($this->host instanceof RealHost) {
            $this->host->inherit($lock); // every child keeps execution ownership while it lives
        }
        $op = $this->ops->current(); // re-read: only what is true now that we own execution counts
        [$pid, $pgid] = ($this->identity)();
        $claimed = null;
        $then = null;
        // Execution ownership alone is not enough: a descendant of the previous runner may have closed
        // its inherited descriptors and still be writing. No claim of a running or recovering
        // operation — explicit Reopen included — proceeds while the recorded group has a member.
        if (in_array($op['status'], [Status::RUNNING, Status::RECOVERY_REQUIRED], true)
            && Liveness::groupAlive(isset($op['pgid']) ? (int) $op['pgid'] : null)) {
            $this->releaseRunLock();
            return 0;
        }
        if ($op['status'] === Status::RUNNING) {
            $claimed = $this->ops->takeOver($op['id'], (string) $op['owner'], $pid, $pgid);
            $then = fn (array $o) => $this->afterDeath($op, $o);
        } elseif ($op['status'] === Status::RECOVERY_REQUIRED && $op['recovery_request'] !== null) {
            $claimed = $this->ops->claimRecovery($op['id'], $pid, $pgid);
            $then = fn (array $o) => $this->recover((string) $o['recovering']);
        } elseif ($op['status'] === Status::REQUESTED) {
            $claimed = $this->ops->claim($op['id'], $pid, $pgid);
            $then = function (array $o): void {
                AtomicFile::write($this->paths->verifySecret(), bin2hex(random_bytes(32)) . "\n", 0640); // rotated per operation
                $this->runFrom('preflight');
            };
        }
        if ($claimed === null) {
            $this->releaseRunLock();
            return 0;
        }
        $this->id = $claimed['id'];
        $this->owner = $claimed['owner'];
        $this->journal = null;
        $then($claimed);
        return 0;
    }

    public function killPoint(string $name): void
    {
        if ($this->onKillPoint !== null) {
            try {
                ($this->onKillPoint)($name);
            } catch (\Throwable $t) {
                throw new Killed($t);
            }
            return;
        }
        if (is_file($this->paths->proofMarker()) && getenv('THALLO_UPDATER_KILL_AT') === $name && function_exists('posix_kill')) {
            posix_kill(getmypid(), 9);
        }
    }

    private function runFrom(string $first): void
    {
        $op = $this->current();
        $stage = $this->paths->opDir($op['id']) . '/stage';
        $steps = array_slice(self::STEPS, (int) array_search($first, self::STEPS, true));
        try {
            foreach ($steps as $step) {
                $this->begin($step);
                match ($step) {
                    'preflight' => $this->requireNoProblems(RunnerReport::write($this->paths, $this->host, $this->settings)),
                    'stage' => $this->stage($stage, $op['target']),
                    'close' => $this->close(),
                    'barrier' => $this->takeBarrier(),
                    'backup' => $this->backup($op['id']),
                    'swap' => $this->swap($op['id'], $stage),
                    'provision' => $this->provision(),
                    'verify_cli' => $this->verifyCli($op['target']),
                    'verify_web' => $this->verifyWeb($op['target']),
                    'reopen' => $this->reopen($op['id']),
                };
                $this->end($step);
                $this->killPoint($step . '.done');
            }
        } catch (Killed $k) {
            throw $k->cause;
        } catch (Fenced) {
            return; // we no longer own this operation: touch nothing
        } catch (\Throwable $e) {
            $this->onFailure($e->getMessage());
        }
    }

    private function onFailure(string $error): void
    {
        $op = $this->current();
        try {
            $this->stopDescendants(); // nothing we started may still be changing files
        } catch (\Throwable $e) {
            $this->finish(Status::RECOVERY_REQUIRED, $error . ' — ' . $e->getMessage());
            return;
        }
        if ($op['provision_started']) {
            $this->finish(Status::RECOVERY_REQUIRED, $error); // spec §10: never reopens by itself
            return;
        }
        $journal = $this->journal();
        if (!$journal->hasForwardChanges()) {
            $this->barrier->open();
            $this->releaseBarrier();
            $this->finish(Status::FAILED, $error);
            return;
        }
        try {
            $journal->resume();
            $journal->rollback();
            $this->verifyCli($op['from']);
            $this->verifyWeb($op['from']);
            $this->barrier->open();
            $this->releaseBarrier();
            $this->finish(Status::ROLLED_BACK, $error);
        } catch (Killed $k) {
            throw $k->cause;
        } catch (\Throwable $e) {
            $this->finish(Status::RECOVERY_REQUIRED, $error . ' — rollback: ' . $e->getMessage());
        }
    }

    /**
     * A dead run, now owned by this runner (spec §5; rulings 3 and 4; Review Focus 1). $dead is the
     * operation as found; reopen_ready_at is set only after every reopen prerequisite succeeded.
     */
    private function afterDeath(array $dead, array $op): void
    {
        if ($op['reopen_ready_at'] !== null) {
            $this->barrier->open();
            $this->finish(Status::SUCCEEDED, null);
            return;
        }
        try {
            $this->journal()->resume();
        } catch (JournalConflict $e) {
            $this->finish(Status::RECOVERY_REQUIRED, $e->getMessage());
            return;
        }
        if (!$op['provision_started'] && !$this->journal()->hasForwardChanges()) {
            $this->barrier->open();
            $this->finish(Status::FAILED, 'The runner stopped before changing anything (' . $dead['step'] . ').');
            return;
        }
        $this->finish(Status::RECOVERY_REQUIRED, 'The runner stopped during ' . $dead['step'] . '.');
    }

    private function recover(string $option): void
    {
        $op = $this->current();
        try {
            if ($option === 'reopen') {
                $this->barrier->open();
                $this->finish(Status::RECOVERED_BY_OWNER, null);
                return;
            }
            $this->takeBarrier();
            if ($option === 'retry_provision') {
                $this->runFrom('provision');
                return;
            }
            $journal = $this->journal();
            $journal->resume();
            $journal->rollback();
            if ($op['provision_started'] && is_array($op['provision_manifest'])) {
                ProvisionOutputs::restore($this->paths->base, $this->paths->backupDir($op['id']) . '/provision', $op['provision_manifest'],
                    $this->paths->opDir($op['id']) . '/restore-' . $this->host->now(), $journal);
            }
            $this->mustChild(['thallo:update:clear-caches'], 'Clearing caches');
            $this->verifyCli($op['from']);
            $this->verifyWeb($op['from']);
            $this->releaseBarrier();
            $this->finish(Status::RECOVERY_REQUIRED, 'The code is back on ' . $op['from'] . '. Restore the database from the backup if the update changed it, then reopen the site.');
        } catch (Killed $k) {
            throw $k->cause;
        } catch (Fenced) {
            return;
        } catch (\Throwable $e) {
            $cleanup = '';
            try {
                $this->stopDescendants(); // nothing this recovery started may outlive its verdict
            } catch (\Throwable $c) {
                $cleanup = ' — ' . $c->getMessage(); // our group stays recorded, so the next claim waits for it
            }
            $this->finish(Status::RECOVERY_REQUIRED, 'Recovery (' . $option . ') failed: ' . $e->getMessage() . $cleanup);
        }
    }

    private function stage(string $stage, string $target): void
    {
        $before = Stager::fingerprint($this->paths->base);
        $stager = new Stager($this->host, $this->settings['php'], (string) $this->settings['composer'], $this->paths->base . '/storage/framework/composer',
            (int) ($this->settings['composer_timeout'] ?? 1800));
        $stager->prepare($this->paths->base, $stage);
        if ($stager->update($stage, $target, $this->heartbeat()) !== 0) {
            throw new \RuntimeException('Composer could not install ' . $target . ' (see the log).');
        }
        $problems = $stager->verify($this->paths->base, $stage, $target, $before, $this->heartbeat());
        if ($problems !== []) {
            throw new \RuntimeException(implode(' ', $problems));
        }
    }

    private function close(): void
    {
        $this->barrier->close();
        $this->mustChild(['queue:restart'], 'Signalling the workers');
    }

    private function takeBarrier(): void
    {
        $this->barrierLock = $this->barrier->acquire((int) $this->settings['barrier_timeout'], fn (): int => $this->host->now(), fn () => $this->host->sleep(1));
        if ($this->barrierLock === null) {
            throw new \RuntimeException('The barrier timed out: a request, job or scheduler run did not finish within ' . $this->settings['barrier_timeout'] . ' s.');
        }
        if ($this->host instanceof RealHost) {
            $this->host->inherit($this->barrierLock);
        }
    }

    private function backup(string $id): void
    {
        $dir = $this->paths->backupDir($id);
        $this->mustChild(['thallo:update:backup', '--to=' . $dir, '--json'], 'The database backup');
        if (!@copy($this->paths->base . '/composer.lock', $dir . '/composer.lock')) {
            throw new \RuntimeException('Cannot copy composer.lock into the backup.');
        }
        $manifest = ProvisionOutputs::snapshot($this->paths->base, $dir . '/provision'); // throws on any failed copy
        $this->update(static function (array $op) use ($dir, $manifest): array {
            $op['backup'] = ['path' => $dir];
            $op['provision_manifest'] = $manifest;
            return $op;
        });
    }

    private function swap(string $id, string $stage): void
    {
        $op = $this->paths->opDir($id);
        $j = $this->journal();
        $j->rename($this->paths->base . '/vendor', $op . '/vendor.old', 'swap.1');
        $j->rename($stage . '/vendor', $this->paths->base . '/vendor', 'swap.2');
        $j->rename($this->paths->base . '/composer.lock', $op . '/composer.lock.old', 'swap.3');
        $j->rename($stage . '/composer.lock', $this->paths->base . '/composer.lock', 'swap.4');
    }

    private function provision(): void
    {
        $this->update(static fn (array $op): array => ['provision_started' => true] + $op);
        $this->mustChild(['thallo:provision', '--no-interaction'], 'Provision');
    }

    /** This command's own exit code and stdout — never an older line of the log. */
    private function verifyCli(string $expected): void
    {
        [$code, $out] = $this->child(['thallo:update:verify', '--json']);
        if ($code !== 0) {
            throw new \RuntimeException("The command-line check exited {$code}.");
        }
        $lines = preg_split('/\R/', trim($out)) ?: [];
        $data = json_decode((string) end($lines), true);
        $got = is_array($data) ? (string) ($data['version'] ?? '') : '';
        if ($got === '' || ltrim($got, 'v') !== ltrim($expected, 'v')) {
            throw new \RuntimeException("The command line reports '{$got}', expected {$expected}.");
        }
    }

    /** Spec §8 step 9: three consecutive answers from PHP-FPM itself report $expected. */
    private function verifyWeb(string $expected): void
    {
        $secret = trim((string) @file_get_contents($this->paths->verifySecret()));
        $headers = ['x-thallo-verify' => $secret, 'Content-Type' => 'application/json'];
        if ($this->settings['verify_host'] !== '') {
            $headers['Host'] = $this->settings['verify_host'];
        }
        $deadline = $this->host->now() + (int) $this->settings['web_settle'];
        $good = 0;
        $last = '';
        while ($this->host->now() <= $deadline) {
            [$status, $body] = $this->host->http((string) $this->settings['verify_url'], $headers, '{"mode":"boot"}');
            $data = json_decode($body, true);
            $ok = $status === 200 && is_array($data) && ltrim((string) ($data['version'] ?? ''), 'v') === ltrim($expected, 'v')
                && ($data['missing_extensions'] ?? ['?']) === [] && ($data['container'] ?? false) === true;
            $good = $ok ? $good + 1 : 0;
            $last = "{$status} {$body}";
            if ($good >= 3) {
                return;
            }
            $this->host->sleep(1);
        }
        throw new \RuntimeException("PHP-FPM did not confirm {$expected}: {$last}");
    }

    /**
     * Every prerequisite first — caches cleared, workers signalled, no descendant left — then the
     * durable marker; after it only flag removal, barrier release and the status remain, which a
     * later tick completes if this runner dies (Review Focus 1).
     */
    private function reopen(string $id): void
    {
        $this->mustChild(['thallo:update:clear-caches'], 'Clearing caches');
        $this->mustChild(['queue:restart'], 'Signalling the workers');
        $this->stopDescendants();
        $this->update(fn (array $op): array => ['reopen_ready_at' => $this->host->now()] + $op);
        $this->killPoint('reopen.before-open');
        $this->barrier->open();
        $this->killPoint('reopen.after-open');
        $this->releaseBarrier();
        $this->killPoint('reopen.after-release');
        $this->finish(Status::SUCCEEDED, null);
        $this->host->run(['rm', '-rf', $this->paths->opDir($id)], $this->paths->base, [], 600, static function (): void {});
        $this->pruneBackups();
    }

    private function pruneBackups(): void
    {
        $dirs = glob($this->paths->base . '/storage/backups/update-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs); // ids start with a UTC timestamp, so name order is age order
        foreach (array_slice($dirs, (int) $this->settings['keep_backups']) as $dir) {
            $this->host->run(['rm', '-rf', $dir], $this->paths->base, [], 600, static function (): void {});
        }
    }

    /** @param list<string> $args @return array{0:int,1:string} */
    private function child(array $args): array
    {
        return $this->host->runCapture(array_merge([$this->settings['php'], $this->paths->base . '/glueful'], $args), $this->paths->base,
            ['THALLO_UPDATE_CHILD' => '1'], (int) $this->settings['child_timeout'], $this->heartbeat());
    }

    /** @param list<string> $args */
    private function mustChild(array $args, string $what): void
    {
        [$code] = $this->child($args);
        if ($code !== 0) {
            throw new \RuntimeException("{$what} failed (exit {$code}; see the log).");
        }
    }

    private function stopDescendants(): void
    {
        [$pid, $pgid] = ($this->identity)();
        if ($pgid !== null) {
            ChildProcess::stopGroupMembers($pgid, $pid);
        }
    }

    private function current(): array
    {
        $op = $this->ops->current();
        if ($op === null || $op['id'] !== $this->id || $op['owner'] !== $this->owner) {
            throw new Fenced('Operation changed hands');
        }
        return $op;
    }

    private function update(callable $mutate): array
    {
        return $this->ops->update((string) $this->id, (string) $this->owner, $mutate);
    }

    private function finish(string $status, ?string $error): void
    {
        $this->ops->finish((string) $this->id, (string) $this->owner, $status, $error);
    }

    private function heartbeat(): \Closure
    {
        return fn () => $this->update(fn (array $op): array => ['heartbeat_at' => $this->host->now()] + $op);
    }

    private function begin(string $step): void
    {
        $this->update(fn (array $op): array => array_merge($op, ['step' => $step, 'steps' => array_merge($op['steps'], [['name' => $step, 'status' => 'running', 'started_at' => $this->host->now(), 'ended_at' => null]])]));
    }

    private function end(string $step): void
    {
        $this->update(function (array $op): array {
            $last = count($op['steps']) - 1;
            $op['steps'][$last]['status'] = 'done';
            $op['steps'][$last]['ended_at'] = $this->host->now();
            return $op;
        });
    }

    private function requireNoProblems(array $report): void
    {
        $problems = array_merge($report['shape_problems'], $report['writable'] ? [] : ['The runner cannot write the code.'],
            $report['composer_ok'] ? [] : ['Composer is missing or too old.'], ($report['pg_dump'] && $report['pg_restore']) ? [] : ['pg_dump or pg_restore is missing.'],
            $report['unguarded_processes'] === [] ? [] : ['Processes started before the guard was installed are still running: ' . implode(', ', array_column($report['unguarded_processes'], 'pid')) . '.'],
            // The same readiness rule as admin preflight: an empty scan is not the owner's confirmation,
            // which covers the processes the scan cannot see (Task 13).
            ($report['guard_installed_at'] !== null && $report['guard_ready_at'] !== null && $report['guard_ready_at'] > $report['guard_installed_at'])
                ? [] : ['The restart after enabling updates has not been confirmed (php bin/thallo-updater confirm-restart --yes).']);
        if ($problems !== []) {
            throw new \RuntimeException(implode(' ', $problems));
        }
    }

    private function journal(): Journal
    {
        return $this->journal ??= new Journal($this->ops, (string) $this->id, (string) $this->owner, fn (string $label) => $this->killPoint($label));
    }

    private function ensureSecret(): void
    {
        // The probe needs a secret before any operation exists (first enablement, preflight, selftest).
        if (!is_file($this->paths->verifySecret())) {
            if (!is_dir($this->paths->dir())) {
                mkdir($this->paths->dir(), 0770, true);
            }
            AtomicFile::write($this->paths->verifySecret(), bin2hex(random_bytes(32)) . "\n", 0640);
        }
    }

    private function releaseBarrier(): void
    {
        if ($this->barrierLock !== null) {
            if ($this->host instanceof RealHost) {
                $this->host->forget($this->barrierLock); // never hand a closed handle to a later child
            }
            FileLock::release($this->barrierLock);
            $this->barrierLock = null;
        }
    }

    private function releaseRunLock(): void
    {
        if ($this->runLock !== null) {
            if ($this->host instanceof RealHost) {
                $this->host->forget($this->runLock);
            }
            FileLock::release($this->runLock);
            $this->runLock = null;
        }
    }
}
```

Also implement, in the same file:

- **`recoverFromTerminal(string $flag)`.** It maps `--rollback`, `--retry-provision` and `--reopen`
  to `requestRecoveryAsOwner($currentId, …)`, then calls `tick()`.
- **`printToken()`.** It prints the token from `rotateToken($currentId)` and the `/_update` URL from `BASE_URL`.
- **`selftest()`.** It prints one line per check and returns 0 only when all pass:
  - the runner owns `vendor/` (`fileowner === posix_geteuid()`);
  - the guard file exists and both entry points include it;
  - `verify.secret` exists (`tick` creates it on its first run, before any operation, so the probe
    works at first enablement);
  - the web probe answers 200 with all of:
    - `writable_code === false`;
    - `admission_ok === true`: PHP-FPM can open `admission.lock` and take a shared lock;
    - `state_writable === true`;
  - the state folder is writable by the runner;
  - **no exclusive admission lock is attempted.** A healthy, long-running guarded worker holds a
    shared lock for its whole life, so an exclusive check would fail on a correct install. The
    barrier itself is proven by the proof (scenarios 9 and 20);
  - `unguarded_processes` is empty, and `guard.ready` exists and is newer than `guard.installed`
    (Task 13);
  - `pgrep` and `ps` run;
  - Composer runs (`php composer --version`);
  - `pg_dump --version` and `pg_restore --version` run.
- **`confirmRestart(bool $yes)`.** It prints `RunnerReport::unguardedProcesses()`, then the sentence
  about what it cannot see. It returns 1 while any process is listed, or when `--yes` is absent. With
  `--yes` and nothing listed, it writes `guard.ready` (a timestamp and the user, 0644) and returns 0.
- **`dotenv()` and `which()`.** Minimal `KEY=VALUE` parsing that strips quotes, and a `PATH`
  search.

Every read of a command's output (versions, `du`, the estimate) goes through `Host::capture()`, never
`shell_exec`, so `FakeHost` scripts it.

`RunnerReport::write()` collects the runner rows of spec §4 into `runner.json`:
- the user (`posix_getpwuid(posix_geteuid())['name']`);
- the Composer path and version (`composer_ok` when `php composer --version` exits 0 and reports a
  version that supports inline constraints — **Composer ≥ 2.2**; check the
  `composer update vendor/pkg:version` docs and pin the exact floor here);
- `pg_dump` and `pg_restore` (`--version` exits 0);
- `writable` (the runner can write `vendor/`, `composer.json`, `composer.lock` and `public/admin/`);
- `shape_problems` (`ComposerShape::problems()`);
- the guard installed, and `guard_installed_at`, read from `storage/framework/updates/guard.installed`
  (Task 13);
- `unguarded_processes` (`RunnerReport::unguardedProcesses(int $installedAt): list<array{pid:int,args:string}>`),
  listed by the rules in Task 13. They come from `capture(['ps', '-eo', 'pid=,etime=,user=,args='])`,
  where `etime` is parsed as `[[dd-]hh:]mm:ss`, works on macOS and Linux, and gives
  start = now − elapsed;
- `guard_ready_at`, read from `guard.ready` (null when absent);
- the web probe result, as `{"status": int, …}`, or `null`.

It recomputes disk needs at most every 30 minutes, keeping `needed_bytes` in the report:
- `du -sk vendor` × 2;
- plus the snapshot paths' sizes;
- plus `thallo:update:backup --estimate` × 1.2;
- plus 256 MB.

- [ ] **Step 4: Build and run**

Run: `php scripts/build-updater && php vendor/bin/phpunit tests/Unit/Updater`
Expected: PASS. In particular, the three `testADeathAfterVerificationCompletesTheReopen` cases (Review
Focus 1).

- [ ] **Step 5: phpcs and commit**

```bash
git add core/src/Updater scripts/build-updater core/resources/updater tests/Unit/Updater
git commit -m "feat(updater): the runner — ten steps, crash-safe reopening, recovery, token, selftest"
```

## Phase C — wiring it into Thallo

### Task 13: Provision installs the runner and the guard

**Files:**
- Create: `core/src/Setup/UpdaterInstaller.php`
- Modify: `core/src/Setup/Console/ProvisionCommand.php`. After the admin-bundle publish block (~line 324), add a step in the existing try/warn shape.
- Modify: `skeleton/.gitignore`, `.gitignore` (add `/bin/thallo-updater` and `/bootstrap/update-guard.php`)
- Test: `tests/Unit/Setup/UpdaterInstallerTest.php`

**Interfaces:**
- Consumes: the generated `core/resources/updater/thallo-updater` and `update-guard.php` (Task 3 onward).
- Produces: `UpdaterInstaller::sync(string $base, string $resources, bool $enabled, ?array $operation): string`.
  It returns one of `installed-first`, `installed`, `current`, `removed`, `kept`, or `absent`:
  - **Enabled:** it copies each file, by temp file and rename, only when the content differs. The
    runner gets mode 0755 and the guard 0644.
  - **Disabled:** it removes both files, unless an operation is open (not terminal) or in
    `recovery_required`. Then it keeps them, because recovery needs them.
  - **First install (`installed-first`):** when the guard was absent before, it writes
    `storage/framework/updates/guard.installed` (a Unix timestamp, mode 0644).

**The move from unguarded to guarded processes** (plan reviews 4 and 5). A process started before
the guard existed holds no admission lock, and no delay proves it has ended: a paused request or a
long import can outlive any settling period. So first enablement **stays unavailable until the owner
completes the move, and the runner verifies what it can see**:

1. **Signal.** On `installed-first`, provision calls `RestartSignal::signal(time())`, so queue workers
   exit after their current job and their supervisor starts them through the guarded `glueful`.
2. **Preflight refuses.** Preflight reports `restart_required` until
   `storage/framework/updates/guard.ready` exists and is newer than `guard.installed`.
3. **The owner restarts** PHP-FPM, or Apache, and every queue worker and long-running job of the
   site. Provision prints exactly this, and the docs say so.
4. **The owner confirms.** `php bin/thallo-updater confirm-restart --yes` lists every process it can
   attribute to the site that started before `guard.installed`, and **refuses (exit 1) while any
   remains**. It writes `guard.ready` only when none does. Without `--yes` it only lists them.
   Attributable processes come from `ps -eo pid=,etime=,user=,args=`:
   - every `php-fpm` process (master and workers), every `httpd`/`apache2` process, and every
     `frankenphp` process. The host is single-site-supported, so a restart of the web server is
     required, not inferred;
   - every `php` CLI process whose working directory is the site (`readlink /proc/<pid>/cwd` on
     Linux), whose arguments contain the site's path, or whose arguments contain `glueful` or
     `thallo` while its cwd cannot be read. The runner's own pid, and any `thallo-updater` process,
     are excluded.
5. **What it cannot see** — another user's process started elsewhere, naming nothing of the site — is
   covered by the explicit confirmation. `confirm-restart` prints that sentence before asking for
   `--yes`.
6. **The runner re-checks.** Its own preflight step re-runs the same listing on every update and fails
   if anything pre-guard is found. That covers a worker started by hand without the guard.

`RunnerReport` records the listing as `unguarded_processes`, a list of `{pid, args}` (this replaces
revision 1's queue-only `unguarded_workers`). `selftest` fails while `guard.ready` is missing or older
than `guard.installed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Setup\UpdaterInstaller;

final class UpdaterInstallerTest extends TestCase
{
    private string $base;
    private string $res;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-inst-' . bin2hex(random_bytes(4));
        $this->res = $this->base . '/res';
        mkdir($this->res, 0777, true);
        mkdir($this->base . '/bootstrap', 0777, true);
        file_put_contents($this->res . '/thallo-updater', "#!/usr/bin/env php\n<?php // v1\n");
        file_put_contents($this->res . '/update-guard.php', "<?php // guard v1\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testEnabledInstallsOnceAndUpdatesWhenTheShippedCopyChanges(): void
    {
        $i = new UpdaterInstaller();
        self::assertSame('installed-first', $i->sync($this->base, $this->res, true, null));
        self::assertFileExists($this->base . '/storage/framework/updates/guard.installed');
        self::assertSame('0755', substr(sprintf('%o', fileperms($this->base . '/bin/thallo-updater')), -4));
        self::assertSame('current', $i->sync($this->base, $this->res, true, null));
        file_put_contents($this->res . '/update-guard.php', "<?php // guard v2\n");
        self::assertSame('installed', $i->sync($this->base, $this->res, true, null));
        self::assertStringContainsString('v2', (string) file_get_contents($this->base . '/bootstrap/update-guard.php'));
    }

    public function testDisabledRemovesThemUnlessRecoveryNeedsThem(): void
    {
        $i = new UpdaterInstaller();
        $i->sync($this->base, $this->res, true, null);
        self::assertSame('kept', $i->sync($this->base, $this->res, false, ['status' => 'recovery_required']));
        self::assertFileExists($this->base . '/bootstrap/update-guard.php');
        self::assertSame('removed', $i->sync($this->base, $this->res, false, ['status' => 'succeeded']));
        self::assertFileDoesNotExist($this->base . '/bin/thallo-updater');
        self::assertSame('absent', $i->sync($this->base, $this->res, false, null));
    }
}
```

- [ ] **Step 2: Run to see it fail**, then **implement** `UpdaterInstaller`. The temp-and-rename
  copy follows `AtomicFile::write` (it may call it directly, since `Thallo\Core\Updater\AtomicFile`
  is autoloaded in core). Wire it into provision:

```php
try {
    $op = (new \Thallo\Core\Updater\OperationStore(new \Thallo\Core\Updater\Paths($basePath), static fn (): int => time()))->current();
    $result = (new UpdaterInstaller())->sync($basePath, CoreServiceProvider::corePath('resources/updater'),
        (bool) config($this->getContext(), 'thallo.admin_updates.enabled', false), $op);
    if ($result === 'installed-first') {
        // Workers started before the guard hold no admission lock: ask them to restart through it.
        $this->getContainer()->get(\Glueful\Queue\RestartSignal::class)->signal(time());
    }
    $this->line('Update runner: ' . $result . '.');
} catch (\Throwable $e) {
    $this->warning('Update runner skipped (' . $e->getMessage() . ').');
}
```

- [ ] **Step 3: Run the test, the provision tests, and the skeleton smoke**

Run: `php vendor/bin/phpunit tests/Unit/Setup/UpdaterInstallerTest.php tests/Integration/Setup && composer test:skeleton`
Expected: PASS. The smoke runs with the setting off, so it prints `Update runner: absent.`

- [ ] **Step 4: Commit**

```bash
git add core/src/Setup/UpdaterInstaller.php core/src/Setup/Console/ProvisionCommand.php tests/Unit/Setup/UpdaterInstallerTest.php skeleton/.gitignore .gitignore
git commit -m "feat(setup): provision installs the update runner and guard when admin updates are on"
```

### Task 14: PHP-FPM side — config, preflight, routes

**Files:**
- Modify: `core/config/thallo.php` (after `update_check`, ~line 134), `.env.example`, `skeleton/.env.example`
- Create: `core/src/Updates/UpdatePreflight.php`, `core/src/Http/Controllers/AdminUpdateController.php`, `core/src/Http/DTOs/Responses/UpdatePreflightData.php`, `core/src/Http/DTOs/Responses/UpdateStartData.php`
- Modify: `core/routes/admin.php` (after `/update-status`, ~line 625), `core/src/Providers/CoreServiceProvider.php` (controller and `UpdatePreflight` service entries beside `UpdateStatusController`, ~2903-2917)
- Modify: `docs/openapi.json` (hand-spliced: the three operations)
- Test: `tests/Unit/Updates/UpdatePreflightTest.php`, `tests/Integration/Http/AdminUpdateApiTest.php`

**Interfaces:**
- Consumes: `UpdateChecker::status()`; `Thallo\Core\Updater\OperationStore`, `Paths`, `UpdateRefused`.
- Produces:
  - Config:

```php
// Updating from the admin (docs/upgrading.md). Off unless the owner sets it; a guided installer may
// turn it on after `php bin/thallo-updater selftest` passes. The runner's user can rewrite this
// site's code — that is the trade the owner accepts.
'admin_updates' => [
    'enabled' => (bool) env('THALLO_ADMIN_UPDATES', false),
    'keep_backups' => (int) env('THALLO_UPDATE_KEEP_BACKUPS', 3),
    'verify_url' => env('THALLO_UPDATE_VERIFY_URL'),
],
```

  - `UpdatePreflight::check(): array{ready: bool, current: ?string, target: ?string, reasons: list<string>}`.
    The reason codes are exactly those of spec §4: `disabled`, `runner_missing`, `outside_constraint`,
    `not_writable`, `web_can_write_code`, `composer_missing`, `composer_config_unsupported`,
    `backup_unavailable`, `low_disk`, `guard_missing`, `web_verify_unreachable`, `opcache_preload`,
    `opcache_frozen`, `persistent_runtime`, `in_progress`, `recovery_required`, plus `restart_required`
    and `unguarded_processes` from the readiness protocol (Task 13).
  - The routes, each `->middleware('content_permission:system.config')` inside the `['tenant_system','auth']` group:
    - `GET /v1/admin/updates/preflight` → 200 `{preflight: …}`;
    - `POST /v1/admin/updates` → 202 `{update: {id, token}}`, or 409 `{reasons}`;
    - `DELETE /v1/admin/updates/current` → 200 `{cancelled: true}`, or 409.

`UpdatePreflight` takes `ini` and `sapi` as closures, so tests can set them. Runner rows come from
`runner.json`, which is stale after 300 s. In it:
- `writable === false` → `not_writable`;
- `composer_ok === false` → `composer_missing`;
- a non-empty `shape_problems` → `composer_config_unsupported`;
- `pg_dump` or `pg_restore` false → `backup_unavailable`;
- `free_bytes < needed_bytes` → `low_disk`;
- `web === null` or `web.status !== 200` → `web_verify_unreachable`; `web.admission_ok === false` or
  `web.state_writable === false` → `not_writable`;
- a non-empty `unguarded_processes` → `unguarded_processes`;
- `guard_ready_at === null` or `guard_ready_at <= guard_installed_at` → `restart_required`.

FPM rows:
- `web_can_write_code`: `is_writable(vendor/)` here;
- `guard_missing`: the guard file is missing, or `public/index.php` or `glueful` lacks
  `update-guard.php`;
- `opcache_preload`: `ini('opcache.preload') !== ''`;
- `opcache_frozen`: OPcache is enabled and `validate_timestamps` is `0`;
- `persistent_runtime`: the SAPI is not `fpm-fcgi`, `apache2handler` or `cgi-fcgi`, or the
  environment has `FRANKENPHP_WORKER` or `RR_MODE`.

`outside_constraint` uses `Composer\Semver\Semver::satisfies($latest, $rootConstraint)`, where the
root constraint is `require['glueful/thallo-core']` in `<base>/composer.json`. `ready` is true only
when an update is available and `reasons === []`.

- [ ] **Step 1: Write the failing unit test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updates;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updates\UpdatePreflight;

final class UpdatePreflightTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-pre-' . bin2hex(random_bytes(4));
        foreach (['storage/framework/updates', 'bootstrap', 'public', 'vendor'] as $d) {
            mkdir("{$this->base}/{$d}", 0777, true);
        }
        file_put_contents("{$this->base}/composer.json", json_encode(['require' => ['glueful/thallo-core' => '^1.0.0-beta.101']]));
        file_put_contents("{$this->base}/bootstrap/update-guard.php", '<?php');
        file_put_contents("{$this->base}/public/index.php", "<?php require __DIR__ . '/../bootstrap/update-guard.php';");
        file_put_contents("{$this->base}/glueful", "<?php require __DIR__ . '/bootstrap/update-guard.php';");
        chmod("{$this->base}/vendor", 0555); // FPM cannot write code
        $this->report([]);
    }

    protected function tearDown(): void
    {
        chmod("{$this->base}/vendor", 0755);
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testAHealthySetupIsReady(): void
    {
        $r = $this->preflight()->check();
        self::assertSame([], $r['reasons']);
        self::assertTrue($r['ready']);
        self::assertSame('1.0.0-beta.102', $r['target']);
    }

    /** @return iterable<string, array{string, array, array, string}> */
    public static function reasons(): iterable
    {
        yield 'disabled' => ['disabled', ['enabled' => false], [], ''];
        yield 'outside constraint' => ['outside_constraint', ['latest' => '2.0.0'], [], ''];
        yield 'preload' => ['opcache_preload', ['ini' => ['opcache.preload' => '/app/preload.php']], [], ''];
        yield 'frozen' => ['opcache_frozen', ['ini' => ['opcache.validate_timestamps' => '0']], [], ''];
        yield 'persistent' => ['persistent_runtime', ['sapi' => 'cli-server'], [], ''];
        yield 'runner writes' => ['not_writable', [], ['writable' => false], ''];
        yield 'composer' => ['composer_missing', [], ['composer_ok' => false], ''];
        yield 'shape' => ['composer_config_unsupported', [], ['shape_problems' => ['x']], ''];
        yield 'backup' => ['backup_unavailable', [], ['pg_restore' => false], ''];
        yield 'disk' => ['low_disk', [], ['free_bytes' => 1, 'needed_bytes' => 2], ''];
        yield 'web' => ['web_verify_unreachable', [], ['web' => null], ''];
        yield 'stale runner' => ['runner_missing', [], ['checked_at' => 1], ''];
        yield 'guard' => ['guard_missing', [], [], 'public/index.php'];
        yield 'unguarded processes' => ['unguarded_processes', [], ['unguarded_processes' => [['pid' => 4242, 'args' => 'php glueful import']]], ''];
        yield 'restart not confirmed' => ['restart_required', [], ['guard_ready_at' => null], ''];
        yield 'confirmed before the guard' => ['restart_required', [], ['guard_installed_at' => 50, 'guard_ready_at' => 40], ''];
    }

    /** @dataProvider reasons */
    public function testEachReason(string $reason, array $opts, array $report, string $strip): void
    {
        $this->report($report);
        if ($strip !== '') {
            file_put_contents("{$this->base}/{$strip}", '<?php');
        }
        $r = $this->preflight($opts)->check();
        self::assertContains($reason, $r['reasons']);
        self::assertFalse($r['ready']);
    }

    public function testCodeTheWebProcessCanWriteIsRefused(): void
    {
        chmod("{$this->base}/vendor", 0777);
        self::assertContains('web_can_write_code', $this->preflight()->check()['reasons']);
    }

    private function report(array $over): void
    {
        file_put_contents("{$this->base}/storage/framework/updates/runner.json", json_encode(array_merge([
            'checked_at' => 10_000, 'writable' => true, 'composer_ok' => true, 'shape_problems' => [], 'pg_dump' => true,
            'pg_restore' => true, 'free_bytes' => 10, 'needed_bytes' => 1, 'unguarded_processes' => [], 'guard_installed_at' => 1, 'guard_ready_at' => 2,
            'web' => ['status' => 200, 'admission_ok' => true, 'state_writable' => true],
        ], $over)));
    }

    private function preflight(array $o = []): UpdatePreflight
    {
        $ini = array_merge(['opcache.enable' => '1', 'opcache.validate_timestamps' => '1', 'opcache.preload' => ''], $o['ini'] ?? []);
        return new UpdatePreflight(
            ['enabled' => $o['enabled'] ?? true],
            ['current' => '1.0.0-beta.101', 'latest' => $o['latest'] ?? '1.0.0-beta.102', 'available' => true],
            $this->base,
            static fn (string $k): string => $ini[$k] ?? '',
            static fn (): string => $o['sapi'] ?? 'fpm-fcgi',
            static fn (): int => 10_100,
        );
    }
}
```

Constructor: `UpdatePreflight(array $settings, array $status, string $base, \Closure $ini, \Closure $sapi, \Closure $now)`.
`$status` is `UpdateStatus::toArray()`. The container factory builds it from `UpdateChecker`,
`config(...,'thallo.admin_updates')`, `base_path($context)`, `ini_get`, `PHP_SAPI` and `time`.

- [ ] **Step 2: Run to see it fail**, then **implement** `UpdatePreflight` to pass every case.

- [ ] **Step 3: Write the failing integration test** (`tests/Integration/Http/AdminUpdateApiTest.php`,
  shard D already holds `tests/Integration/Http`):
  - All three routes carry `auth`, `tenant_system` and `content_permission:system.config`. Copy the
    middleware assertions in `CapabilityAdminApiTest.php:123-133`.
  - A user granted `system.access` but not `system.config` is refused (403) by the
    `content_permission` middleware, and a `system.config` user passes. Copy `userGranted()`,
    `jwtRequest()` and `passes()` from `RequirePermissionAnyOfTest.php`.
  - `start()`, with a preflight stub that is ready, writes `operation.json` under a temporary base and
    returns 202 with a 64-character token. A second `start()` returns 409 with `in_progress`. A
    preflight stub that is not ready returns 409 with its reasons.
  - `cancel()` returns 200 once, then 409. It cancels by the id it read: `$ops->cancel($ops->current()['id'] ?? '')`. The store's compare-and-set refuses it once a runner has claimed the operation.

  Construct `AdminUpdateController` directly with a stub preflight and an `OperationStore` on a
  temporary `Paths`, as `UpdateStatusApiTest` does with `UpdateStatusController`.

- [ ] **Step 4: Implement the controller**, with `#[ApiOperation]` and `#[ApiResponse]` attributes in
  the `CacheAdminController` shape and `tags: ['Utilities']`. Take the signed-in user's uuid the way
  existing controllers do: grep `attributes->get('user')` under `core/src/Http/Controllers` and copy
  it. Register the routes and service entries.

- [ ] **Step 5: Splice OpenAPI by hand.** Regenerate to a scratch file with
  `CACHE_DRIVER=array php glueful docs:openapi`, written into the scratchpad, not over the repo file.
  Copy only the three new operations and their two schemas into `docs/openapi.json`.

- [ ] **Step 6: Run, phpcs, commit**

Run: `php vendor/bin/phpunit tests/Unit/Updates tests/Integration/Http/AdminUpdateApiTest.php && vendor/bin/phpcs core/src/Updates core/src/Http/Controllers/AdminUpdateController.php core/routes/admin.php; echo phpcs=$?`
Expected: PASS, `phpcs=0`.

```bash
git add core/config/thallo.php .env.example skeleton/.env.example core/src/Updates core/src/Http core/routes/admin.php core/src/Providers/CoreServiceProvider.php docs/openapi.json tests/Unit/Updates tests/Integration/Http/AdminUpdateApiTest.php
git commit -m "feat(updates): admin preflight, start and cancel for updating from the admin"
```

### Task 15: Admin — the Update button and the confirm dialog

**Files:**
- Modify: `admin/src/queries/updates.ts`, `admin/src/components/UpdateAvailableCard.vue`
- Create: `admin/src/components/UpdateConfirmModal.vue`
- Test: `admin/src/__tests__/updateAvailableCard.spec.ts` (extend), `admin/src/__tests__/updateConfirmModal.spec.ts`

**Interfaces:**
- Consumes: the Task 14 routes.
- Produces:

```ts
export const UPGRADE_COMMAND =
  'composer update glueful/thallo-core -W && php glueful thallo:provision && php glueful queue:restart'
export interface UpdatePreflight { ready: boolean; current: string | null; target: string | null; reasons: string[] }
export async function fetchUpdatePreflight(): Promise<UpdatePreflight | null> // null on 403: not this user's to run
export function useUpdatePreflight(enabled: MaybeRefOrGetter<boolean>) // useQuery key ['utilities','update-preflight'], staleTime 60_000
export async function startUpdate(): Promise<{ id: string; token: string }>
export const UPDATE_TOKEN_KEY = 'thallo.update.token'
export const REASON_TEXT: Record<string, string> // one sentence per spec §4 code
```

Fix the stale header comment in `updates.ts`: "Operator-only (system.access)" becomes "Any signed-in
admin reads the status; preflight and start require system.config".

**The card's three states:**
1. **Preflight `null`** (a 403) or reasons `['disabled']`: today's card, with the new
   `UPGRADE_COMMAND`.
2. **Ready:** an **Update to {target}** button (`data-testid="update-available-start"`) beside the
   command. It opens the modal.
3. **Not ready, for other reasons:** the command, then "Updating from here is unavailable:" and one
   `REASON_TEXT` sentence per reason (`data-testid="update-available-reasons"`).

The small text "Composer runs on the server, never from this page." changes to: "The update runs on
the server as the code owner, never in this browser."

**The modal** (`UModal`) shows:
- `current → target`;
- the release-notes link;
- the sentence "Your site is closed to visitors and to other admins while the update runs — usually
  a few minutes. A verified database backup is taken first.";
- **Cancel** and **Update** buttons.

On Update it calls `startUpdate()`, stores the token with
`sessionStorage.setItem(UPDATE_TOKEN_KEY, token)` (in try/catch), then `window.location.assign('/_update')`.
A 409 shows the reasons inline and does not navigate.

- [ ] **Step 1: Write the failing specs.** Mount the card with a mocked `fetchUpdatePreflight`
  (`vi.mock('@/queries/updates', …)`, keeping the real `UPGRADE_COMMAND` and `REASON_TEXT` through
  `importActual`). Assert:
  - a `null` preflight shows no start button;
  - a ready preflight shows `Update to 1.0.0-beta.102`;
  - reasons `['opcache_preload','guard_missing']` render both sentences;
  - the command text equals the new `UPGRADE_COMMAND`.

  For the modal: Update calls `startUpdate` once, and `window.location.assign` is called with
  `/_update` (spy on it with `vi.spyOn(window.location, 'assign')`, or stub `window.location` per the
  existing specs' pattern). A rejected start with `{reasons:['in_progress']}` shows the sentence and
  never navigates.
- [ ] **Step 2: Run them to see them fail** with `cd admin && pnpm vitest run src/__tests__/updateAvailableCard.spec.ts src/__tests__/updateConfirmModal.spec.ts`.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Gates.** Run `npx oxfmt` on the touched files only, then
  `pnpm type-check && pnpm lint && pnpm fmt:check && pnpm test`.
  Expected: all pass. The lint warnings already in `structurePicker.ts` stay as they are.
- [ ] **Step 5: Commit**

```bash
git add admin/src/queries/updates.ts admin/src/components/UpdateAvailableCard.vue admin/src/components/UpdateConfirmModal.vue admin/src/__tests__/updateAvailableCard.spec.ts admin/src/__tests__/updateConfirmModal.spec.ts
git commit -m "feat(admin): an Update button on the update notice, handing off to /_update"
```

### Task 16: Docs and the changelog

**Files:**
- Modify: `docs/upgrading.md`:
  - lines 8-22 (the sequence): the targeted `-W` command, then `queue:restart`;
  - lines 124-143: replace the "no update button" bullet, and add a new section *Updating from the
    admin* after *Knowing when to upgrade*.
- Modify: `docs/production.md` (cron: the optional runner line; workers: `queue:restart` after a terminal upgrade)
- Modify: `docs/internal/DISTRIBUTION.md` (decision 11 amended, with the date and spec path)
- Modify: `core/src/Updates/UpdateChecker.php` docblock ("Never an updater" → "The notice; the updater is `bin/thallo-updater`, off unless enabled"), `core/config/thallo.php` `update_check` comment
- Modify: `CHANGELOG.md` ([Unreleased] → Added)

- [ ] **Step 1: Write *Updating from the admin*.** Cover:
  - **Who it is for:** one server, updated by its owner. It is off for Composer installs.
  - **Turning it on**, in order:
    1. set `THALLO_ADMIN_UPDATES=true`;
    2. run `php glueful thallo:provision` (it installs `bin/thallo-updater` and
       `bootstrap/update-guard.php`);
    3. add the cron line as the user that owns the code;
    4. make `storage/framework/updates/` group-writable for the web user and that owner;
    5. restart PHP-FPM, or Apache, and every queue worker and long-running job of the site, then run
       `php bin/thallo-updater confirm-restart --yes`. It refuses while anything started before the
       guard is still running;
    6. run `php bin/thallo-updater selftest` and fix every failing line.
  - **What the web process may write:** only `storage/framework/updates/`.
  - **What it does:** the ten steps in plain words, and that visitors and other admins see a
    maintenance page during it.
  - **When it is unavailable:** the preflight reasons, each with its fix.
  - **The supported Composer setup** (spec §8.1), and "update from the terminal" otherwise.
  - **When something fails:** the three end states; `/_update` and its options;
    `php bin/thallo-updater token` for another browser; the terminal `recover` commands; and that the
    database is restored only by the owner, with `pg_restore --clean --if-exists --dbname …`.
  - **Not supported:** more than one server, network filesystems, persistent runtimes, PHP preloading.
- [ ] **Step 2: Add the changelog bullet** under [Unreleased] → Added: "**Update Thallo from the
  admin** (off unless you turn it on): on a single server, an owner presses Update on the update
  notice. A separate runner, run by cron as the code owner, stages the release, takes a verified
  backup, closes the site, installs and provisions it, and checks that PHP-FPM serves it before
  reopening. A failure before the database could change puts the old code back; any later failure
  keeps the site closed and offers recovery at `/_update`. See *Updating from the admin* in the
  upgrading guide. The terminal upgrade is now `composer update glueful/thallo-core -W && php glueful
  thallo:provision`, followed by `php glueful queue:restart`."
- [ ] **Step 3: Check that the docs page renders** with `php vendor/bin/phpunit --filter DocsPage`
  (or the docs test that covers `docs/upgrading.md`; grep `upgrading.md` under `tests/`).
- [ ] **Step 4: Commit**

```bash
git add docs/upgrading.md docs/production.md docs/internal/DISTRIBUTION.md core/src/Updates/UpdateChecker.php core/config/thallo.php CHANGELOG.md
git commit -m "docs: updating from the admin; the targeted -W terminal upgrade"
```

## Phase D — the release gate

### Task 17: The disposable-install proof — harness and the first scenarios

**Files:**
- Create: `scripts/update-proof` (bash, executable): the orchestrator
- Create: `scripts/lib/update-proof/build-artifacts.php`: zips `from` and `target` builds of `core/` and every `packages/*`
- Create: `scripts/lib/update-proof/request.php`: starts an operation in the site and prints the token. Used only by scenarios that are not about the admission path.
- Create: `scripts/lib/update-proof/make-owner.php`: boots the site's app and creates a `superuser`. It assigns the role with the same repositories `RequirePermissionAnyOfTest::userGranted()` uses, and prints the email and a generated password into `$WORK/owner.json` (0600).
- Create: `scripts/lib/update-proof/site.sh`: build, snapshot and restore the site; start and stop PHP-FPM and nginx
- Create: `scripts/lib/update-proof/scenarios.sh`: one bash function per scenario
- Modify: `composer.json` scripts (`"test:update-proof": "bash scripts/update-proof"`)

**Interfaces:**
- `scripts/update-proof [scenario…]` runs all scenarios, or the named ones, and exits non-zero on the
  first failure. It prints `PASS <n> <name>` or `FAIL <n> <name>: <why>`, followed by the failing
  operation's log tail.
- **Requirements**, each checked up front with a clear message:
  - `nginx` on PATH;
  - `PHP_FPM_BIN`, defaulting to `php-fpm` on PATH, then `/Applications/MAMP/bin/php/php8.4.17/sbin/php-fpm`;
  - `composer`, `pg_dump`, `pg_restore`, `psql`;
  - the dev `.env`'s database credentials. The proof uses its own database, `app_update_proof`,
    created and dropped by the script.

**How it works (`site.sh`):**

1. **Artifacts** (`build-artifacts.php <out>`):
   - It copies `core/` and each `packages/*` into a temp tree and sets each `composer.json`
     `"version"`. The `from` set is `1.0.0-beta.900`. The `target` set is `1.0.0-beta.901`, and
     also the variants `901-failmig` and `901-faildata`, each a separate core zip of
     `1.0.0-beta.901` built into a variant directory.
   - **The target's mutations.** Each is a `str_replace` on a named anchor that fails the build when
     the anchor is missing, so a refactor breaks the proof loudly, never silently:
     - **A migration:** a new file in core's migrations directory with the next free number,
       creating `update_proof(id int)`.
     - **An admin bundle change:** append `<!-- update-proof 901 -->` to `resources/admin/index.html`.
     - **A data write without a migration:** add one starter block type, `update_proof_block`, to the
       definitions `StarterBlockTypes` returns. The anchor is the first array entry's
       `'name' =>` line.
   - **`failmig`:** the target, plus `throw new \RuntimeException('update-proof: compile fails');` as
     the first statement of `StyleArtifactCompiler::compile()`. The migration has already run by
     then (scenario 5).
   - **`faildata`:** the target without the migration, plus the same throw (scenario 6).
   - **`failclear`:** the target, plus `return self::FAILURE;` as the first statement of
     `UpdateClearCachesCommand::execute()` (scenario 22).
   - **`slowcomposer`** is not a zip. It is a `COMPOSER_BINARY` wrapper script in `$WORK/bin`
     (scenarios 4, 4b and 21).
   - It zips each tree into `<out>/<variant>/`, where Composer's artifact repository reads it.
2. **Site** (`build_site <variant-dir>`):
   - Copy `skeleton/` to `$WORK/site`.
   - Set `repositories` to `[{"type":"artifact","url":"<abs>/from"},{"type":"artifact","url":"<abs>/<variant>"}]`.
   - Require `"glueful/thallo-core": "^1.0.0-beta.900"` and keep that permissive constraint.
     **Never** run `composer require glueful/thallo-core:1.0.0-beta.900`: it rewrites the root
     constraint to an exact pin, and the target could never install. Instead, the target artifact
     directory is **empty** when the site is installed, so `composer install` resolves to `900`, the
     only version that exists. The target zips are copied into that directory only after the
     snapshot, for each scenario that updates.
   - After install, assert that `composer.json`'s constraint is still exactly `^1.0.0-beta.900` and
     that `composer.lock` holds `1.0.0-beta.900`.
   - Add these, for Review Focus 3:
     - `app/Proof/Greeter.php` (`App\Proof\Greeter`);
     - `autoload.classmap: ["legacy/"]` with `legacy/ProofLegacy.php`;
     - `autoload.files: ["proof-helpers.php"]` defining `update_proof_helper()`.
   - Write `.env`: the proof database, `BASE_URL=http://update-proof.test:$PORT`,
     `THALLO_ADMIN_UPDATES=true`, `THALLO_UPDATE_VERIFY_URL=http://127.0.0.1:$PORT/_update/verify`.
   - Run `php glueful thallo:provision --no-interaction`, then `touch storage/framework/updates/.proof`.
   - Snapshot it: `tar` of the site, plus `pg_dump -Fc` of the proof database. `restore_site` puts
     both back between scenarios, which is far faster than rebuilding.
3. **Users** (plan review 6). The update runs as the code owner and PHP-FPM as the web user, and they
   are different users:
   - **CI (required):** `sudo useradd --system thallo-deploy` and `sudo groupadd thallo-site`.
     `thallo-deploy` and `www-data` both join `thallo-site`.
     - The site is `chown -R thallo-deploy:thallo-site`, with code files 0644 and directories 0755,
       so `www-data` cannot write code.
     - `storage/` is `2770` for the group. `storage/framework/updates/` is `2770`, and `umask 0007`
       is set for the runner.
     - The PHP-FPM pool runs as `www-data:thallo-site`.
     - Every `composer`, `php glueful …` and `php bin/thallo-updater …` call in the scenarios runs as
       `sudo -u thallo-deploy`.
   - **Local macOS:** `UPDATE_PROOF_SINGLE_USER=1`, the default on Darwin, runs as one user. It prints
     `SKIP user separation (CI enforces it)` and skips only the assertions that need two users
     (`web_can_write_code` false, `writable_code` false). CI sets `UPDATE_PROOF_SINGLE_USER=0` and
     fails if it cannot create the users.
4. **Web:**
   - A PHP-FPM pool on a Unix socket with `opcache.enable=1`, `opcache.validate_timestamps=1` and
     `opcache.revalidate_freq=2`.
   - nginx on `$PORT` (default 18080) with root `$WORK/site/public`, `try_files $uri /index.php?$query_string`,
     and FastCGI to the pool.
   - Both start once per run, and are stopped by a `trap`.
5. **Cron:** `tick` runs `php bin/thallo-updater tick` in the site. `tick_until <status> [max]` ticks
   once a second until `operation.json` has that status. `request <target>` runs `request.php`, which
   uses the site's own `vendor/autoload.php` and `Thallo\Core\Updater\OperationStore`, and saves the
   token in `$WORK/token`.
6. **Assertions:** `status_is`, `version_is <v>` (CLI, `php glueful thallo:update:verify --json`),
   `web_version_is <v>` (POST `/_update/verify` boot with the secret), `site_open` / `site_closed`
   (GET `/` gives 200 or 503), and `table_exists update_proof`.

**Scenarios in this task:**
- **1. Successful update, through the advertised path** (plan review 6):
  - `sudo -u thallo-deploy php bin/thallo-updater tick` once (it creates `verify.secret` and
    `runner.json`), then `php bin/thallo-updater selftest` exits 0;
  - `make-owner.php`, then sign in through the site's real login endpoint (grep `login` in
    `core/routes`) to get a bearer token;
  - `GET /v1/admin/updates/preflight` with the bearer token returns `ready: true` and `target`
    `1.0.0-beta.901`;
  - `POST /v1/admin/updates` returns 202, with the token kept for `/_update`;
  - a non-superuser's bearer token gets 403 on both;
  - then `tick_until succeeded`, and:
  - `version_is` and `web_version_is` the target, and `table_exists update_proof`;
  - `/admin/` contains `update-proof 901`;
  - `site_open`;
  - the backup folder holds `database.dump` that `pg_restore --list` reads, mode 0600;
  - **post-swap autoload** (Review Focus 3): `php -r` in the site loads `App\Proof\Greeter`,
    `ProofLegacy` and `update_proof_helper()`, and `vendor/composer/autoload_*.php` contain no
    `/storage/framework/updates/` path;
  - the stage is deleted.
- **2. Composer killed mid-stage.** Use `THALLO_UPDATER_KILL_AT=stage.done`, then also a real kill:
  background a `tick` and `kill -9` its process group 2 s into the stage. The next `tick` gives
  `failed`, `site_open` and `version_is` from.
- **13. A newer release after the request.** Request `901`, then add a `902` core zip to the
  artifacts before the first tick. The result is `version_is 1.0.0-beta.901`.
- **14. Unsupported shapes.** Add a `path` repository to the site's `composer.json`. The admin
  preflight (`php glueful` tinker-free: run `UpdatePreflight` through a small PHP one-liner using the
  site's container) contains `composer_config_unsupported`, and a forced request ends `failed` at
  `preflight` with nothing changed.
- **15. A plugin rewriting composer.json.** Add an allowlisted-name local plugin stub (an artifact
  zip named `php-http/discovery` at a fake version that appends a key in its `post-update` event
  handler). The result is `failed` at `stage`, and the live `composer.json` is byte-identical.
- **18. Provision inventory** (Review Focus 4). Write a full manifest of the site: every file's
  relative path with its sha256, excluding the runner-owned paths. Write it right before provision
  runs under the target code, using kill point `swap.done` then `retry_provision`, and again right
  after it. The diff is every **added, changed and deleted** path. Every path in it must have
  `ProvisionOutputs::ruleFor() !== null`, checked by a PHP one-liner over the diff. The scenario
  prints every uncovered path with its kind (added, changed or deleted). Modification times are
  never used.

- [ ] **Step 1: Write `build-artifacts.php` and run it alone.** `php scripts/lib/update-proof/build-artifacts.php /tmp/up`
  lists the zips. A deliberately broken anchor makes it exit 1 and name the anchor.
- [ ] **Step 2: Write `site.sh` and build one site** with `bash scripts/update-proof --only-build`. It
  provisions `from`, nginx answers `/` with 200, and `/_update` answers 200.
- [ ] **Step 3: Write scenarios 1, 2, 13, 14, 15 and 18.** Run them with
  `bash scripts/update-proof 1 2 13 14 15 18`.
  Expected: six `PASS` lines. A scenario that fails against the real runner is fixed in the runner
  (Tasks 3–12), never in the scenario's expectation. Record each fix in the ledger as a ruling.
- [ ] **Step 4: Commit**

```bash
git add scripts/update-proof scripts/lib/update-proof composer.json
git commit -m "test(updater): the disposable-install proof — harness and the first scenarios"
```

### Task 18: The proof's remaining scenarios, and the CI release gate

**Files:**
- Modify: `scripts/lib/update-proof/scenarios.sh`
- Modify: `.github/workflows/ci.yml` (new job `update-proof`)
- Modify: `docs/internal/RELEASING.md` (gate line)

**Scenarios:**
- **3. Kill between each pair of swap renames, and between each rollback rename.** For each of
  `swap.1.renamed` … `swap.4.renamed`, set `THALLO_UPDATER_KILL_AT` and then:
  - `tick` (it dies), then `tick` again, giving `recovery_required` and `site_closed`;
  - request recovery `rollback` through `/_update/recover` with the token;
  - `tick`, giving `version_is` and `web_version_is` from;
  - then `reopen` (with `confirm`) and `tick`, giving `recovered_by_owner` and `site_open`.

  Then for `undo.swap.2.renamed`: kill during the rollback itself, and the next recovery `tick`
  completes it.
- **4. Killed runner, live Composer child.** `COMPOSER_BINARY` points at a wrapper that runs the real
  Composer, then `sleep 20`. Kill the runner's PID only. Two further `tick`s do nothing (the status
  stays `running`, and the log says so) until the wrapper exits. The run is then found dead and ends
  `failed` (before any rename).
- **4b. Killed runner and child, live grandchild** (Review Focus 2). The wrapper starts `sleep 20 &`
  and exits at once. Kill the runner. `tick` claims nothing while the grandchild lives (check with
  `pgrep -g <pgid>`). After it exits, the run ends `failed`.
- **5. Provision fails after the migration** (`failmig` variant): `recovery_required`, `site_closed`,
  and `table_exists update_proof`. Then:
  - `pg_restore --clean --if-exists` from the backup path the status shows;
  - recover `rollback`, then `reopen`;
  - `version_is` from, `site_open`, and the `update_proof` table is gone.
- **6. A data write without a migration, then a failure** (`faildata` variant): `recovery_required`,
  never reopened by itself. Check `update_proof_block` exists in the block types table while the site
  stays closed.
- **7. Rollback fails.** Use `failmig`, then `chmod 000` the backup's `provision/` snapshot before
  requesting `rollback`. The result is `recovery_required`, the error names the restore, and the site
  is still closed.
- **8. Two ticks at once.** `tick & tick & wait`: exactly one log line says it claimed, and the result
  is `succeeded`.
- **9. The barrier waits** for each of three cases, started before `request`:
  - a request to a test route that sleeps 8 s before writing (add `public/proof-slow.php` to the
    site, which includes the guard, sleeps, then writes a file);
  - a queue job that runs 120 s with `retry_after` set to 90 (push it with the site's `QueueManager`,
    and run `php glueful queue:work --once` in the background);
  - `queue:scheduler run` with a 10 s scheduled job.

  In each case the `backup` step's `started_at` comes after the holder finished. While the runner
  waits, a new GET `/` returns 503 and the slow route's write did happen.
- **10. Barrier timeout.** The site's `.env` sets `THALLO_UPDATE_BARRIER_TIMEOUT=5` (read by
  `Runner::main` into `barrier_timeout`). A request that sleeps 60 s makes the result `failed` with
  the barrier named, `site_open`, and the version unchanged.
- **11. No fatal during the swap.** A background loop does `curl -s -o /dev/null -w '%{http_code}'`
  on `/` and `/_update/status` (with the token) every 50 ms during scenario 1's steps 6–9. Every code
  is 200 or 503, and the nginx error log has no `PHP Fatal`.
- **12. Refreshing `/_update`.** At `swap.2.renamed` (killed), and after `failmig`: GET `/_update`
  returns 200 HTML; `/_update/status` with the token returns 200 and with a wrong token 403.
- **16. A missing extension.** The target's `composer.json` gains `"ext-proofmissing": "*"` (a variant
  zip), installed with `--ignore-platform-req=ext-proofmissing`. Use
  `COMPOSER_IGNORE_PLATFORM_REQ=ext-proofmissing` in the runner's environment for this scenario only.
  Web verify reports `missing_extensions: ["proofmissing"]`, and the result is `recovery_required`.
- **17. Crash-safe reopening** (Review Focus 1). For each of `reopen.before-open`, `reopen.after-open`
  and `reopen.after-release`: kill, then `tick`, giving `succeeded`, `site_open`, `version_is`
  target, and no rename in the journal after the kill.
- **4c. A killed recovery runner with a surviving grandchild** (plan review 2). Use `failmig`, which
  gives `recovery_required`, then request `rollback`. The recovery `tick` runs with
  `THALLO_UPDATER_KILL_AT=undo.swap.3.renamed`. Before it, a test command started in the runner's
  group (a `queue:restart` wrapper that starts `sleep 20 &`) leaves a grandchild. Then:
  - `operation.json`'s `pgid` is the recovery runner's, not the first run's;
  - `tick` claims nothing while the grandchild lives;
  - after it exits, the run is found dead (`recovery_required`), and a further `rollback` request
    completes it.
- **4d. No recovery claim while a lock-closing descendant writes** (plan review 3, third round). Use
  `failmig`, then a recovery `tick` whose `queue:restart` wrapper starts
  `sh -c 'exec 3>&- 4>&-; while :; do date >> $WORK/writes; sleep 0.2; done' &` and then fails the
  recovery (wrapper exit 1). The descendant survives `stopDescendants` only because the proof marks it
  `SIGKILL`-immune: it runs as a different user under `sudo`, so the runner cannot signal it (CI only;
  skipped single-user). Then:
  - the result is `recovery_required`, with the error naming the survivor;
  - **Reopen** is requested, and `tick` claims nothing while `$WORK/writes` keeps growing;
  - after the proof kills the writer as root, the next `tick` reopens.
- **20. Readiness at first enablement: workers** (plan review 4):
  1. Build the site with `THALLO_ADMIN_UPDATES=false`.
  2. Start a supervisor loop that runs `php glueful queue:work` and restarts it when it exits. Start
     it **before** the guard exists.
  3. Set `THALLO_ADMIN_UPDATES=true` and provision. It prints `Update runner: installed-first`, the
     restart instructions, and signals restart.
  4. Right after, preflight contains `restart_required`, and `confirm-restart --yes` exits 1 listing
     the PHP-FPM processes and the pre-guard worker, if it has not cycled yet.
  5. The worker cycles through the guard. The proof **fully restarts PHP-FPM**, using `fpm_restart` below.
     A reload is not enough: it re-executes the master in place, which keeps its PID and start time, so
     the scanner would still list it.
  6. `confirm-restart --yes` exits 0, and preflight is ready.
  7. With that guarded worker idle and holding its shared lock, `selftest` passes (no exclusive
     probe).
  8. An update then succeeds. The worker exits on `queue:restart`, waits at the guard while the site
     is closed, and runs again afterwards with a new PID.
- **`fpm_restart`** (in `site.sh`) does the following, in order:
  1. sends `SIGQUIT` to the old master, a graceful stop that waits for running requests up to
     `process_control_timeout`;
  2. polls `pgrep -f "php-fpm.*$WORK/fpm.conf"` until **no** process of the old instance remains,
     master or worker, failing after 150 s;
  3. asserts that the old master's PID is gone;
  4. starts a new instance with the same config and waits for its socket.

  Scenarios 20 and 23 use it, and `confirm-restart` then sees only processes newer than
  `guard.installed`.
- **23. Readiness at first enablement: a pre-guard request and a console writer outlive any delay**
  (plan review 5):
  1. With the feature off, start two writers:
     - a request to `public/proof-slow.php?sleep=90`, which boots the app, sleeps, then writes
       `slow-wrote` with `microtime(true)`;
     - a console writer that is not a queue command, run in the site's directory as the deploy user:
       `php -r 'require "bootstrap/app.php"; sleep(90); file_put_contents("console-wrote", microtime(true));'`.
  2. Enable the feature and provision. Wait 30 s, longer than any settling period would have been.
  3. Check that the update cannot start:
     - preflight contains `restart_required`;
     - `confirm-restart --yes` exits 1 and lists PHP-FPM and the console writer;
     - `POST /v1/admin/updates` returns 409 with `restart_required`.
  4. Wait for the console writer to finish. **Fully restart PHP-FPM** with `fpm_restart`. The pool has
     `process_control_timeout = 120`, and the graceful stop lets the slow request finish (it writes
     `slow-wrote`) before the old master exits.
  5. `confirm-restart --yes` exits 0.
  6. Start an update through the admission path, which ends `succeeded`. Both `slow-wrote` and
     `console-wrote` hold times **earlier than the `backup` step's `started_at`**: neither wrote after
     the backup began.
- **21. A Composer timeout with a grandchild, both ignoring SIGTERM** (plan reviews 2 and 3). Set
  `THALLO_UPDATE_COMPOSER_TIMEOUT=5`, the timeout `Stager` actually uses for Composer, and
  `COMPOSER_BINARY` to a wrapper that runs `trap "" TERM; sleep 600 & sleep 600`. The wrapper
  outlives the timeout by minutes, so only the supervisor's escalation ends it. The result is `failed`
  at `stage` within 60 s, with the log naming the timeout. `pgrep -g` on the recorded group lists
  nothing but the runner before the status is written, and `site_open`. Repeat with
  `THALLO_UPDATE_CHILD_TIMEOUT=5` and a `failclear`-style variant whose `thallo:update:clear-caches`
  sleeps 600 s ignoring SIGTERM. That gives `recovery_required` within 60 s, with nothing left in the
  group.
- **22. A failed cache clear at reopen** (`failclear`): `recovery_required`, `site_closed`,
  `reopen_ready_at` null, and a further `tick` does not reopen. **Roll back the code**, then
  **Reopen**, gives `site_open` on `from`.
- **19. Verification access** (Review Focus 5):
  - the token sent as `x-thallo-verify` returns 403;
  - the secret sent as `x-thallo-update-token` returns 403 on status and on recover;
  - while scenario 9's runner holds the barrier, a verify `probe` with the secret returns 200 within
    1 s.

- [ ] **Step 1: Write the scenarios.** Run `bash scripts/update-proof`.
  Expected: every scenario prints `PASS`. Fix failures in the runner or guard (Tasks 3–12), and
  record each as a ruling.
- [ ] **Step 2: Add the CI job** `update-proof`, modelled on the `skeleton-smoke` job:
  - the same postgres service, checkout and `.env` creation;
  - `shivammathur/setup-php` with `php-version: '8.4'`, `extensions: pdo_pgsql, posix, pcntl` and
    `tools: composer`. PHP-FPM comes with the Linux setup. Confirm `php-fpm8.4` is on the runner
    image, or install `php8.4-fpm`.
  - `sudo apt-get install -y nginx postgresql-client` if absent;
  - build the admin bundle as skeleton-smoke does;
  - `timeout --signal=KILL 1800 composer test:update-proof`.
- [ ] **Step 3: Add the gate line** to `docs/internal/RELEASING.md`'s pre-cut checklist: "The
  `update-proof` CI job is green on the commit being cut (it is the release gate for updating from
  the admin)."
- [ ] **Step 4: Run the full gates once, attached and never concurrently.**
  - The PHPUnit shards with MAMP PHP.
  - phpcs on everything touched, judged by its exit code.
  - The admin gates.
  - The e2e: rebuild fixtures with `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`,
    then `npx playwright test` in `admin/e2e`.
  - `composer test:skeleton`.
  - `bash scripts/update-proof`.
  - Then `composer test:reset-db && composer test:migrate`.
- [ ] **Step 5: Commit**

```bash
git add scripts/lib/update-proof .github/workflows/ci.yml docs/internal/RELEASING.md
git commit -m "test(updater): every proof scenario, gated in CI as the release gate"
```

---

## Revision 4 — the trust boundary (after the final review)

The guarantee is spec §16: nothing PHP-FPM can write makes the runner install anything but the
approved release resolved by the site's own Composer configuration, or perform a filesystem
operation outside its fixed set. Every task is test-first. The two-user proof (Task 24) is the
evidence; nothing claims more than it shows.

### Task 19: The three locations and their parents

- `Paths` gains `privateDir()` (`<base>/.thallo-update`), `controlDir()`
  (`<base>/.thallo-update-control`) and `inbox()` (`storage/framework/updates`).
- Every file moves to its §16.1 location:
  - operation, `state.lock`, `run.lock`, `guard.installed`, `guard.ready`, `ops/<id>`,
    `composer-home`, `backups/<id>`: private;
  - `status.json`, `closed.flag`, `admission.lock`, `verify.secret`, `runner.json`: control;
  - `start.json`, `recover.json`: inbox.
- New `Layout::problems(Paths, int $euid): list<string>` checks:
  - each folder is a real directory (`lstat`, not a link) owned by `$euid`, with the exact mode;
  - every ancestor up to `/` is writable by nobody but `$euid` or root, unless it is sticky and the
    next component is owned by `$euid` or root.
- **Tests (LayoutTest):** a correct layout; a symbolic-link private folder; a group-writable site
  root; a private folder `0750`; a sticky `/tmp`-like ancestor accepted; a non-sticky
  world-writable ancestor refused.
- `UpdaterInstaller::sync` creates the layout (§16.4): control group = the group of `storage/`,
  `admission.lock` `0640`, inbox `2770`. A run on an existing install migrates nothing from revision 3
  except removing the old files in the inbox.
- **Tests (UpdaterInstallerTest):** modes, group, and an `admission.lock` that exists before the guard
  is first used.

### Task 20: The guard reads control, writes only the inbox

- Admission opens `admission.lock` read-only for `LOCK_SH`.
- If the file cannot be opened and `closed.flag` is absent, the guard admits and calls `error_log`.
- `/_update/status` reads `status.json`; the token is checked against its `token_hash`.
- `/_update/recover` validates the shape and writes `inbox/recover.json` (temp file and rename). It
  answers 202 without asserting the option is accepted; the runner decides.
- **Tests (GuardAdmissionTest, GuardRoutesTest, GuardVerifyTest):**
  - admission with a read-only lock file;
  - admission when the lock file is missing or unreadable (admitted, logged, not 503);
  - status and recover against a published `status.json`;
  - the token/secret separation unchanged.

### Task 21: The runner trusts only its private folder

- **Claim.** `rename(inbox/start.json → private/request-<n>.json)`, then validate the private copy:
  - not a link, at most 4 KB, exact keys;
  - `target` syntax, and newer than the live `composer.lock`'s version;
  - `requested_by` display syntax.
  - A rejected request is recorded as a `failed` operation, with the reason published.
- **Recovery.** `recover.json` is renamed in and validated in the same way. It is accepted only when
  `sha256(token)` matches and the option is in `recovery_options` (computed from the journal: §16.2).
- **The journal** refuses any rename outside the fixed set: the four swap renames and the inventory
  restore renames, all under `base`, `privateDir` or `ops/<id>`.
- **`status.json`** is published after every operation write: the operation without owner, pid,
  pgid or journal, plus `recovery_options`, the log tail and `token_hash`.
- **Composer's home** is `private/composer-home`. Backups go to `private/backups/<id>`.
- **Clean-up:** `ops/<id>` at every terminal outcome with no live forward change, including
  `afterDeath`'s succeeded branch, plus pruning.
- **`requireNoProblems`** adds: `Layout::problems`, web status 200, free ≥ needed, guard installed and
  included, and one filesystem (`stat` dev of `vendor`, `privateDir`, `backups`).
- **No report while busy.** While an operation is open or `closed.flag` exists, tick neither collects
  a report nor runs an application child.
- **Smaller fixes:**
  - `onFailure` resumes the journal before asking for forward changes;
  - `Runner::main` sets `umask(0002)`;
  - `AtomicFile` creates its temp file under the target mode.
- **Tests (RunnerTest, JournalTest):**
  - a symbolic-link `start.json`, an oversized one, extra keys, a bad target, and a target not newer
    than the live one: all refused;
  - a recover with a wrong token, and one with an option not on offer: ignored;
  - retry refused after a half-done swap;
  - a journal entry outside the fixed set: refused;
  - `ops/<id>` removed after failed, rolled back and the death-completed reopen;
  - no application child while closed;
  - `onFailure` after a rename whose "done" write was lost.

### Task 22: PHP-FPM's side writes only the inbox

- **Preflight** reads `control/runner.json` and `control/status.json`, and adds `insecure_layout`
  from the runner's report.
- **`POST /v1/admin/updates`** takes `{target}`. It refuses (409 `target_changed`) when that differs
  from preflight's target, then writes `inbox/start.json` (temp file and rename) and returns the
  token.
- **`DELETE /v1/admin/updates/current`** deletes `inbox/start.json` if present, otherwise 409.
- The admin's `startUpdate(target)` sends the dialog's target.
- **Tests:** AdminUpdateApiTest, UpdatePreflightTest and the modal spec.

### Task 23: Docs and changelog

- `upgrading.md`: the three folders and their owners/modes, the backup path, and the restore as
  the code owner.
- The CHANGELOG bullet: close, then back up.
- The `skeleton/.env.example` line order.
- Drop `ProvisionCommand`'s `class_exists` guard.
- The guard's console refusal names the terminal recovery commands.

### Task 24: The proof of the boundary

- The harness follows the new locations.
- **Scenario 24** (two users, CI): every §16.7 attempt from the PHP-FPM user (`sudo -u www-data`), then
  an update that installs exactly the target. Afterwards it asserts no file owned by the code owner
  exists at any attacker-chosen path, and the planted marker never appears in `vendor/` or the
  autoloader.
- **Scenario 25** (two users, CI): enabling from scratch with PHP-FPM running as www-data before any
  manual permission step. `/` and `/health` keep answering non-503.
- Locally (one user) both print SKIP. The unit tests of Tasks 19–21 pin the runner's own refusals on
  every machine.
- The full proof passes locally, and the CI job carries the two-user scenarios.

