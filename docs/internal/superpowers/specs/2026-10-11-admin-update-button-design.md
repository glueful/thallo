# Update from the admin — design

Status: revision 2, draft for review (2026-10-11). Server (core, skeleton, two framework additions)
and admin. Reverses the "no update button" line of `docs/upgrading.md` for an explicitly enabled,
single-host install, and amends DISTRIBUTION.md decision 11 (§2). Revision 2 answers the first
review: the web process never needs writable code (§3), the site is closed by code outside the files
being replaced (§7), an uncertain install stays closed (§10), ownership is durable and does not ride
the queue (§5), the approved version is the one installed (§8), the backup is verified (§9), and
PHP runtimes that would keep old code are excluded (§4).

## 1. Purpose and audience

Thallo serves two audiences and gives each the update path it expects:

- **Code-first users** update locally, commit `composer.lock` and deploy. Nothing on their servers
  rewrites code; the update notice shows the exact command. The feature is off for them.
- **Owners coming from WordPress** run one server and expect to press **Update**. They turn the
  feature on (or a guided installer does, §2), press Update, watch it, and get the old code back if
  it fails before the database changed — and a closed site with clear instructions if it fails
  after.

## 2. The decision this reverses

`docs/upgrading.md` says there will be no update button because Composer runs as the deploy user,
never under the web worker. That separation is kept, not traded away:

- **Off by default.** `THALLO_ADMIN_UPDATES=false`. `composer create-project` leaves it off; a guided
  installer that sets up the update runner (§5) may turn it on.
- **The web process never writes code.** PHP-FPM needs write access only to
  `storage/framework/updates/` (to file a request and read progress). Code is changed by the
  **update runner**, a separate process the owner schedules as the user that owns the code — the
  deploy user.
- **Owner only.** The admin routes require `system.config`, held only by the `superuser` role.
- **One application host.** Supported only when one host serves the site and runs its workers. The
  setting is the owner's statement of this; it is not detected.

## 3. Who does what

| Process | Runs as | Needs | Does |
|---|---|---|---|
| PHP-FPM (admin) | web user | write `storage/framework/updates/` | preflight, files the request, serves the progress view |
| Update runner | deploy user (owns the code) | write code, `storage/`; Composer; `pg_dump` | claims and executes the update, recovery actions |
| Front-controller guard | web user / any CLI | read `storage/framework/updates/` | closes the site, serves status during the update |
| Queue workers, scheduler | as today | nothing new | stop for the update (§7) |

The runner is **not a queue job.** The queue's reservation timeout (90 s by default) would hand a
30-minute job to a second worker, and queue workers usually run as the web user. Instead the owner
adds one cron line, as the deploy user:

```
* * * * * cd /path/to/site && php vendor/glueful/thallo-core/bin/thallo-updater tick
```

`thallo-updater` is a **single self-contained PHP file** with no `require` of the autoloader or of
any other file once it starts executing. PHP compiles it whole at start, so replacing `vendor/`
underneath it cannot change the code it runs. Everything that needs the application — preflight,
backup, provision — runs as a child process (`php glueful …`) that loads whichever code is installed
at that moment. On `tick` with no request it exits at once.

## 4. Preflight — when the button is enabled

`GET /v1/admin/updates/preflight` (`system.config`) answers `{ready, target, reasons[]}` from PHP-FPM.
The checks that only the runner's user can answer are recorded by the runner on every `tick` in
`storage/framework/updates/runner.json` (`{checked_at, user, composer, pg_dump, writable, free_bytes,
composer_version}`) and read here; a missing or stale (> 5 min) report is itself a reason.

| Check | Seen by | Reason code |
|---|---|---|
| Setting on | FPM | `disabled` |
| The runner has reported within 5 minutes | FPM reads runner.json | `runner_missing` |
| A newer version exists and the root `composer.json` constraint for `glueful/thallo-core` allows it (advisory; the runner's staged resolve is the real test, §8) | FPM | `outside_constraint` |
| `composer.json`, `composer.lock`, `vendor/`, `public/admin/` writable | runner | `not_writable` |
| Composer ≥ the version that supports temporary inline constraints (the plan pins the exact floor) | runner | `composer_missing` |
| `pg_dump` and `pg_restore` resolve | runner | `backup_unavailable` |
| Free disk ≥ the requirement in §9 | runner | `low_disk` |
| The front controller and console carry the guard (§7) — an existing install whose `public/index.php` predates it gets the three lines to add | FPM | `guard_missing` |
| `opcache.preload` is empty in PHP-FPM (preloaded code needs a process restart) | FPM | `opcache_preload` |
| `opcache.validate_timestamps` is not `0` in PHP-FPM; `opcache.revalidate_freq` is recorded for §8 step 9 | FPM | `opcache_frozen` |
| PHP-FPM, Apache mod_php or CGI — not a persistent application runtime (RoadRunner, Swoole, FrankenPHP worker mode) | FPM (`PHP_SAPI`, known env markers) | `persistent_runtime` |
| No update is in progress or awaiting recovery (§5) | FPM | `in_progress` / `recovery_required` |

On a multi-tenant install the routes and the button exist only in the host admin.

## 5. Operation state and ownership

One operation at a time, recorded durably in `storage/framework/updates/operation.json` (atomic
write: temp file + rename; mode 0640). It holds `{id, target, from, status, step, steps[],
requested_by, requested_at, token_hash, claimed_by_pid, heartbeat_at, backup, migrations_before,
error, recovery_options[]}`. The full output goes to `storage/logs/update-{id}.log`.

**Status**: `requested → running → succeeded | failed | rolled_back | recovery_required`, plus
`cancelled` from `requested` only, and `recovery_required → running` (a recovery action) or
`→ recovered_by_owner` (§10). `failed` means the live install was never changed; `rolled_back`
means it was changed and verifiably restored with no database change; `recovery_required` means
anything else (§10).

**Transitions** are compare-and-set under a short exclusive `flock` on
`storage/framework/updates/state.lock`, held only for the read-check-write:

- *Start* (FPM): allowed only when no operation exists or the last one is terminal and not
  `recovery_required`. Mints a random 32-byte token, stores its hash, returns the token once.
- *Claim* (runner): `requested → running` only while holding the run lock (below).
- *Cancel* (FPM): `requested → cancelled` only; a claimed operation cannot be cancelled.
- *Terminal* (runner): `running → succeeded | failed | rolled_back | recovery_required`.

**Exclusive execution**: the runner takes a non-blocking exclusive `flock` on
`storage/framework/updates/run.lock` for the life of the operation and passes that descriptor to
every child it starts, so the lock is free only when the runner **and every child** (Composer
included) are gone. Overlapping cron ticks fail to take it and exit. There is no queue, so there is
no enqueue failure and no duplicate delivery; a second `tick` is the only duplicate, and the lock
excludes it.

**Liveness**: the runner advances `heartbeat_at` every 5 s from its own loop while it waits on a
child (it polls the child, it does not block on output), so a quiet Composer still heartbeats. A
stale heartbeat is only displayed ("no progress reported for N minutes"); it never frees anything.
An operation is declared dead only when its status is `running` **and** `run.lock` can be taken —
which the next `tick` checks. A dead operation becomes `recovery_required`; it is never cleared.

## 6. Start

`POST /v1/admin/updates` (`system.config`, CSRF). The confirm dialog shows `current → target`, the
release notes and that release's Upgrade Notes, and: "Your site is closed to visitors and to other
admins while the update runs — usually a few minutes. A verified database backup is taken first."
The server re-runs preflight and performs *Start* (§5) with the advertised target. It answers
`202 {id, token}`. The admin keeps the token in `sessionStorage` for the progress view (§11).

## 7. Closing the site — outside the replaced files

`vendor/` is what changes, so nothing that closes the site or reports progress may depend on it.

- **Front-controller guard.** The skeleton's `public/index.php` (site-owned; Composer never replaces
  it) gains, before `bootstrap/app.php` is required, an `include` of `bootstrap/update-guard.php`
  (site-owned, plain PHP, no dependencies). While `storage/framework/updates/maintenance.flag`
  exists it answers every request itself:
  - `GET /_update/status` with the operation token (header) → the operation file minus secrets, plus
    the last 200 log lines, as JSON (`hash_equals` against `token_hash`).
  - `POST /_update/recover` with the token → files a recovery request (§10).
  - `/health` → `503` JSON with `{"status":"updating"}`.
  - everything else, admin included → `503`, `Retry-After: 120`, a static "Back in a few minutes"
    page. Admin writes, the admin API and logins all stop.
  The status route also works when no flag exists (the guard serves it for the token holder always),
  so the progress view has one endpoint for the whole update.
- **Console guard.** The skeleton's `glueful` console entry includes the same guard file. While the
  flag exists: `queue:work` waits (sleeps, rechecking every 5 s) before booting, so a supervisor's
  restart never meets half-replaced code and never gives up; `queue:scheduler run` exits 0 without
  booting; every other command refuses with a message — unless the environment carries
  `THALLO_UPDATE_CHILD=1`, which the runner sets for its own children (provision, backup).
- **Draining.** Closing the web is not enough: running workers would keep taking jobs. Step 4 of §8
  signals `queue:restart` (framework addition, §12), so each worker exits after its current job and,
  when restarted, waits at the console guard. The runner then waits until the queue reports no
  reserved jobs (framework addition, §12) and the scheduler lock `scheduler:job:*` keys are clear,
  up to 10 minutes; past that it reopens and fails with nothing changed.

Existing installs predate the guard; preflight reports `guard_missing` with the exact lines, and the
skeleton release that ships the guard is the floor for the feature.

## 8. The run

The runner claims the operation, then:

1. **Preflight as the runner** — the runner-side checks of §4, fresh. Failure → `failed`.
2. **Stage** — copy `composer.json`, `composer.lock` and `vendor/` into
   `storage/framework/updates/{id}/stage/`. Run there, with `COMPOSER_HOME=storage/framework/composer`:
   ```
   composer update glueful/thallo-core:<target> --with-all-dependencies \
     --no-interaction --no-progress --no-ansi --no-scripts
   ```
   The inline constraint pins the **approved** version, so a release published while the request
   waited is never installed; `-W` moves Thallo's whole dependency tree, including packages the
   site's `composer.json` also lists. Then verify from the stage's `vendor/composer/installed.json`
   that `glueful/thallo-core` is exactly `<target>`. A resolve failure or a mismatch → `failed`; the
   live install was never touched. This is the feasibility check the root constraint cannot be.
3. **Close** — write `maintenance.flag`.
4. **Drain** — §7. Timeout → remove the flag, `failed`.
5. **Back up and snapshot** — §9: verified database dump; record the applied migrations
   (`migrations_before`); snapshot provision's file outputs (§10).
6. **Swap** — `rename()` live `vendor/` → `{id}/vendor.old`, stage `vendor/` → `vendor/`, then the
   same for `composer.lock` and `composer.json` (all on one filesystem; checked in step 1). Composer's
   generated autoloader resolves paths from its own directory, so the moved tree is valid in place.
7. **Provision** — `php glueful thallo:provision --no-interaction` (never `--force`), as a child with
   `THALLO_UPDATE_CHILD=1`. It migrates behind its `migrate:verify` gate, publishes the admin bundle,
   compiles styles and clears the route, render and container caches.
8. **Verify** — a child `php glueful thallo:update:verify --json` reports the installed version from
   Composer's runtime metadata; it must equal `<target>`. Clear the page and shop caches.
9. **Settle** — wait `opcache.revalidate_freq + 1` seconds (recorded by preflight) so PHP-FPM
   revalidates every script before the first visitor arrives.
10. **Reopen** — remove `maintenance.flag`, `queue:restart` again so any worker that started before
    the swap reloads, mark `succeeded`, delete `{id}/vendor.old` and `{id}/stage`.

Steps 1–5 never change the live install; a failure there is `failed` and the site reopens. A failure
in steps 6–9 goes to §10.

## 9. Backup

- A child `php glueful thallo:update:backup --to=<dir> --json` (step 5). It succeeds only if the
  dump command exits 0, the file is non-empty, and `pg_restore --list` reads it (custom format,
  `-Fc`). The framework `DatabaseBackupTask` reports failures in its result rather than throwing; the
  command checks that result, never just that the call returned.
- Written to `storage/backups/update-{id}/` — directory `0700`, files `0600` — with the
  `composer.json`/`composer.lock` copies and a `manifest.json` (`{id, from, target, database,
  dumped_at, bytes, sha256}`).
- **Retention**: the last 3 update backups are kept (`thallo.admin_updates.keep_backups`); older ones
  are deleted after a `succeeded` run. A backup belonging to a `recovery_required` operation is never
  deleted.
- **Disk requirement** (runner check): database size (`pg_database_size`) × 1.2 + `vendor/` size × 2
  (stage + `vendor.old`) + the provision snapshot size + 256 MB margin.

## 10. Failure, rollback and recovery

**Code rollback** (a failure in steps 6–9): rename `vendor/` back from `vendor.old` and restore
`composer.json`/`composer.lock`; restore the provision snapshot. The snapshot is taken in step 5
from a manifest of provision's file outputs — `public/admin/`, the generated API reference, the
compiled style artifact, the extension cache — and restored by rename; the route, render and
container caches are then cleared rather than restored. No network, no `composer install`.

**When the site may reopen by itself**: only when all of these hold —

- the failure happened before step 7 started, **or** the applied-migrations list read after rollback
  equals `migrations_before`;
- the code rollback and snapshot restore completed;
- a child `thallo:update:verify` reports the installed version equals `from`.

Then the status is `rolled_back` and the site reopens. **In every other case** — migrations changed,
rollback failed, verification failed, or the runner died (§5) — the status is `recovery_required`
and `maintenance.flag` stays. The database is never restored automatically.

**Recovery** — shown on the progress view to the token holder (served by the guard, so it works when
the app cannot boot) and as terminal commands, always both:

| Option | Offered when | Runner does |
|---|---|---|
| **Roll back the code** | code is new or unknown | the code rollback above; stays closed |
| **Retry provision** | code is the target | step 7 onward |
| **Reopen the site** | always, with a typed confirmation | removes the flag; status `recovered_by_owner`. For "I restored the database from the backup" or "I checked it and it works". |

The view gives the backup path and the restore command (`pg_restore --clean --dbname …`). A recovery
request is written by the guard into the operation file (CAS under `state.lock`) and executed by the
runner's next `tick`; terminal equivalents: `php vendor/glueful/thallo-core/bin/thallo-updater
recover --rollback | --retry-provision | --reopen`.

## 11. Admin

- **Update notice** (`UpdateAvailableCard`, Health badge): for `system.config` users with the
  setting on, **Update to {target}** when preflight is ready, or the reasons in plain words otherwise
  ("Your server preloads PHP code, which needs a PHP-FPM restart. Update from the terminal."). Others
  see today's card with the command.
- **Progress view** `/utilities/update`: the step checklist with timings, the live log tail, and the
  end states. It polls the guard's `/_update/status` every 2 s with the token, before, during and
  after maintenance, so it never depends on the application booting. It ships in the admin's main
  bundle (no lazy chunk to 404 after the bundle is republished). A failed poll shows "The server is
  busy — still checking" and never declares failure. On `succeeded` it asks for a reload (the admin
  bundle changed). A browser that lost the token (another device) sees the static maintenance page
  and, once the site reopens, the result on Health.
- `UPGRADE_COMMAND` becomes `composer update glueful/thallo-core -W && php glueful thallo:provision`.

## 12. Framework additions (general-purpose, any app, any driver)

- **`queue:restart`** — stores a restart timestamp in the cache; `QueueWorker` checks it each loop and
  exits after its current job when the timestamp is newer than its own start.
- **In-flight count** — a queue driver method answering how many jobs are reserved now (database:
  `reserved_at` set and not expired; Redis: the reserved set's size), exposed as
  `queue:status --json`.

Thallo requires the framework release that ships both.

## 13. Config, skeleton and docs

- `thallo.admin_updates.enabled` ← `THALLO_ADMIN_UPDATES` (default `false`); `keep_backups` (3).
- Skeleton: `bootstrap/update-guard.php`; the include in `public/index.php` and `glueful`.
- `docs/upgrading.md`: the "no update button" line replaced by *Updating from the admin* — the cron
  line and the user it runs as, what FPM may write, single host only, the preflight reasons, the
  run, recovery and the database caveat; the terminal command changes to the targeted `-W` form.
- `docs/production.md` / operations: `queue:restart` after a terminal upgrade.
- DISTRIBUTION.md decision 11 amended; `UpdateChecker`'s "Never an updater" docblock updated.
- OpenAPI: the admin routes, hand-spliced. CHANGELOG under [Unreleased].

## 14. Testing

**Disposable-install proof** (the gate for this feature, run locally and in CI): a script builds a
throwaway site from the skeleton against a local Composer repository holding two controlled builds
of `glueful/thallo-core` — `from` and `target`, the target adding one migration and one admin-bundle
change — then drives the real runner and real Composer, `pg_dump` and provision against a disposable
database. Scenarios:

1. Successful update: target installed and verified, migration applied, site reopens, backup verified.
2. Composer interrupted (killed mid-stage): live install untouched, `failed`, site open.
3. Runner killed after the swap: next `tick` finds the run lock free → `recovery_required`, site
   closed; **Roll back the code** restores `from`.
4. Provision fails after applying the migration: `recovery_required`, site closed, backup path shown;
   **Reopen** after a `pg_restore` brings the site back on `from`.
5. Rollback fails (snapshot made unreadable): `recovery_required`, never reopened.
6. Duplicate execution: two `tick`s started together — exactly one claims; the other exits.
7. Availability during the swap: a loop of requests to `/_update/status` and `/` during steps 6–7
   never sees a PHP fatal — only status JSON and the 503 page.
8. A newer release published after the request: the approved target is installed, not the newer one.

**Unit / integration**: preflight reasons one by one; CAS transitions (start, claim, cancel,
terminal) including the races; heartbeat advancing during a silent child; the backup command
rejecting an empty or unreadable dump; the guard's routes and token check; route gates
(administrator refused, superuser allowed). **Framework**: `queue:restart` and the in-flight count
per driver. **Admin**: the notice's states and the progress view from canned status sequences,
including failed polls; e2e through mocked guard responses.

## 15. Not in this design

Automatic or scheduled updates; database restore by Thallo; updating extension engines on their own
from the admin; reloading PHP-FPM or restarting persistent runtimes; multi-host installs; installs
without cron.
