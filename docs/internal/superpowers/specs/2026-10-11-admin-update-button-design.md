# Update from the admin — design

Status: draft for review (2026-10-11). Server (core, one framework addition) and admin. Reverses
the "no update button" line of `docs/upgrading.md` and amends DISTRIBUTION.md decision 11 (§2).

## 1. Purpose

Upgrading Thallo means a terminal: `composer update` then `php glueful thallo:provision`. The
admin already knows when a release is out (the update notice: Home card, Health badge, sidebar
version) but can only print the command. An owner who runs a single server and updates over SSH
should be able to press **Update** instead, watch it happen, and get the old code back if it
fails.

The button never runs the upgrade in a web request. It queues a request; the queue worker — a
long-running CLI process with no request time limit — runs it in child processes and writes its
progress to a file the admin reads.

## 2. The decision this reverses

`docs/upgrading.md` says: "There is no update button, and there will not be one in the self-hosted
product: Composer runs as the deploy user with write access to `vendor/`, never under the web
worker." The concern stands and this design answers it instead of ignoring it:

- **Off by default.** `THALLO_ADMIN_UPDATES=false` unless the owner sets it. An install that keeps
  `vendor/` writable only by a deploy user leaves it off and nothing changes.
- **Not the web worker.** Composer runs under the queue worker's user, from the worker, never from
  PHP-FPM. Turning it on means that user can write the code (§4, check 4); the docs say so plainly.
- **Owner only.** The routes require `system.config`, which only the `superuser` install role holds.

The framework's extension installer already runs `composer require` (synchronously, from a web
request: `ExtensionInstaller`), so the precedent of the app driving Composer exists; this design is
the stricter version of it.

## 3. The command

```
composer update glueful/thallo-core --with-all-dependencies --no-interaction --no-progress --no-ansi
php glueful thallo:provision --no-interaction
```

Targeted, with `-W`: it moves `glueful/thallo-core` and its whole dependency tree (the framework,
Symfony, Twig, …), including packages the site's `composer.json` also lists, each within the
constraints the new release and the site's own `composer.json` allow. Packages outside Thallo's
tree stay at their `composer.lock` versions. Never `--force` on provision (it regenerates keys).

The terminal path changes to the same command, so the two never differ: `docs/upgrading.md` and
`UPGRADE_COMMAND` in `admin/src/queries/updates.ts` become
`composer update glueful/thallo-core -W && php glueful thallo:provision`. Extension engines that the
site pins directly (Commerce, Payvia) move only as far as Thallo's tree pulls them; the Extensions
paragraph of `upgrading.md` keeps plain `composer update` as the way to move them on their own.

## 4. Preflight — when the button is enabled

`GET /v1/admin/updates/preflight` (`system.config`) answers `{ready, target, reasons[]}`. The
button shows only when an update is available and the setting is on; it is enabled only when
`reasons` is empty, and otherwise lists each reason in plain words.

| # | Check | Reason code |
|---|---|---|
| 1 | `thallo.admin_updates.enabled` is true | `disabled` |
| 2 | The update notice reports a newer version **that the site's `composer.json` constraint for `glueful/thallo-core` allows** (`Composer\Semver\Semver::satisfies`). `LatestVersion` ignores the constraint today; a 2.0 outside `^1.0` is reported as "needs a `composer.json` change — see the upgrade notes", never offered. | `outside_constraint` |
| 3 | Composer resolves (`ComposerBinaryResolver`) | `composer_missing` |
| 4 | `composer.json`, `composer.lock` and `vendor/` are writable (`HostCapability`'s checks) — as seen by PHP-FPM; the job repeats this as the worker's user before touching anything | `not_writable` |
| 5 | `pg_dump` resolves (the backup step needs it) | `backup_unavailable` |
| 6 | `opcache.validate_timestamps` is not `0` (with it off, PHP-FPM serves the old code until reloaded, which neither the worker nor the admin can do) | `opcache_frozen` |
| 7 | The site is not deployed as read-only / from an image: `THALLO_ADMIN_UPDATES` is the owner's statement of this; no detection | — |
| 8 | No update is in progress (§6 lock) | `in_progress` |
| 9 | Free disk ≥ 512 MB under the project root (vendor copy + backup) | `low_disk` |

There is no "is a worker alive" check: the framework binds a no-op worker monitor, so no heartbeat
exists. A queued request that no worker takes is handled honestly instead (§7, *Waiting*).

On a multi-tenant install the routes and the button exist only in the host admin, never in a
tenant's.

## 5. Start

`POST /v1/admin/updates` (`system.config`, CSRF as every admin write). The confirm dialog shows
`current → target`, the release notes link, the Upgrade Notes section of that release if it has
one, and: "Your site shows a maintenance page for a few minutes. A database backup is taken first."

The server re-runs preflight, takes the lock, writes the state file (§6) with `status: queued`, and
pushes `RunThalloUpdateJob` onto the `maintenance` queue (the queue Health's "Run now" uses) with
`{id, target, requested_by}`. It answers `202 {id}`; the admin opens the progress view.

## 6. State, lock and progress

- **State file** `storage/framework/updates/current.json`, written atomically (temp + rename):
  `{id, target, from, status, step, steps[{name, status, started_at, ended_at}], log_tail,
  requested_by, queued_at, heartbeat_at, error, backup_path}`. A file, not the database or cache:
  the update migrates the one and clears the other. The full output goes to
  `storage/logs/update-{id}.log`; `log_tail` is its last 200 lines.
- **Lock**: the framework `LockManagerInterface` (file driver), `thallo:update`, held from start to
  the end of the job. A second start, from the admin or a second click, gets `in_progress`.
- **Status**: `queued → running → succeeded | failed | rolled_back`. `GET /v1/admin/updates/current`
  (`system.config`) returns the file. Finished files are kept as `updates/{id}.json` for the history
  line on Health ("Updated to 1.0.0-beta.102 on 11 Oct by {name}").

## 7. The job

`RunThalloUpdateJob` (core) extends the framework `Job`: `getMaxAttempts() = 1` (an update is
never retried by the queue), `getTimeout() = 1800`, and `failed()` writes `failed` to the state
file. Each step runs in a child process through the framework `ProcessRunner` (so the new code is
loaded fresh, never into the worker), streaming output into the log and touching `heartbeat_at`.
`COMPOSER_HOME` is `storage/framework/composer`, as the extension installer sets it.

1. **Check** — repeat preflight checks 3, 4 and 5 as the worker's user. Fail here touches nothing.
2. **Backup** — copy `composer.json` and `composer.lock` to `storage/backups/update-{id}/`; dump
   the database with the framework `DatabaseBackupTask` into the same folder.
3. **Maintenance on** — write `storage/framework/updates/maintenance.json`.
4. **Update** — the composer command (§3). If it installs no new `glueful/thallo-core` version,
   stop as `failed` with "nothing to update" and restore (step R).
5. **Provision** — `php glueful thallo:provision --no-interaction`. It migrates (behind its
   `migrate:verify` gate), publishes the admin bundle, compiles styles and clears the route,
   render and container caches.
6. **Clear** — `opcache_reset()` is not reachable from the CLI for PHP-FPM; check 6 is why that is
   safe. Clear the page and shop caches (`cache:delete "render:*"`/`"shop:*"` equivalents).
7. **Maintenance off.**
8. **Restart workers** — `php glueful queue:restart` (§9), so every worker, this one included,
   exits after its current job and its supervisor starts it on the new code.

A failure in steps 1–3 changes no code: maintenance (if on) is turned off and the state is
`failed`. **Rollback (R)**, on a failure in steps 4–6: restore the backed-up `composer.json`/`composer.lock`,
run `composer install --no-interaction`, turn maintenance off, mark `rolled_back`, and keep the log.
If step 5 had started, migrations may have run: the code is back but the database may be newer
than it. The failure view says so and gives the backup path; restoring the database is the
owner's deliberate step, never automatic.

**Waiting** — while `status` is `queued`, the progress view says "Waiting for the queue worker…".
After 2 minutes it adds: "No worker has picked this up. Thallo needs `php glueful queue:work
--queue=maintenance` running (see Scheduler and queues)." and a **Cancel** button
(`DELETE /v1/admin/updates/current`, only while `queued`) that removes the job's state and lock;
a job that later starts and finds no matching `queued` state exits without doing anything.

**Stuck** — if `running` and `heartbeat_at` is older than 15 minutes (the worker was killed), the
view shows "The update stopped responding" with the log tail, the backup path and **Clear**
(`system.config`), which turns maintenance off and frees the lock; it does not touch code.

## 8. Maintenance page

A core middleware, `UpdateMaintenance`, on the public site's routes: while
`storage/framework/updates/maintenance.json` exists it answers `503` with `Retry-After: 120` and a
plain "Back in a few minutes" page (the theme is not rendered — its code is being replaced). It
passes `/admin`, `/v1/admin/*`, `/health` and the update status route. It is Thallo's own and not
the framework's `LockdownMiddleware`, which is a security lockdown with different semantics and no
Thallo route uses.

## 9. Framework addition: `queue:restart`

The framework has no way to tell running workers to exit, and a worker that ran the update holds
the old code in memory. Add to the framework (general-purpose, any app, any driver):
`queue:restart` stores a restart timestamp in the cache; `QueueWorker` reads it each loop and exits
after the current job when it is newer than its own start time. Thallo requires the framework
release that ships it; `docs/upgrading.md` and the operations page mention it for terminal upgrades
too.

## 10. Admin

- **Update notice** (`UpdateAvailableCard`, Health badge): when preflight is ready, an **Update to
  {target}** button beside the command; when not, the command as today plus the reasons, each a
  sentence ("Your server caches PHP code until PHP-FPM reloads; update from the terminal."). Users
  without `system.config` see today's card.
- **Progress view**: a page under Utilities, `/utilities/update`, reached from the dialog and from
  the notice while an update runs: the step checklist with ticks and timings, the live log tail
  (monospace, scrolls to the end), and the end states — succeeded (version, notes link, "Reload the
  admin" because its own bundle changed), failed / rolled back (the failing step, its output, the
  backup path, the database caveat when step 5 had started), waiting and stuck (§7).
- It polls `current` every 2 s. A failed poll during the swap shows "The server is busy — still
  checking" and keeps polling; it never declares failure from a poll.

## 11. Config and docs

- `thallo.admin_updates.enabled` ← `THALLO_ADMIN_UPDATES` (default `false`), in
  `core/config/thallo.php` and `.env.example` with a comment naming the writable-code trade-off.
- `docs/upgrading.md`: replace the "no update button" line with a section *Updating from the admin*
  (what it needs, what it does, rollback and the database caveat); the command change (§3).
- `docs/production.md` / operations: the `maintenance` queue worker and `queue:restart`.
- DISTRIBUTION.md decision 11 amended; `UpdateChecker`'s "Never an updater" docblock updated.
- OpenAPI: the four routes, hand-spliced.
- CHANGELOG under [Unreleased].

## 12. Testing

- **Unit**: preflight — one test per reason code; the constraint check (inside, outside, pre-release
  rules); state file transitions and atomic write; the lock refusing a second start.
- **Job** with a fake `ProcessRunner`: the happy path's step order and commands (exact argv, no
  `--force`); composer failing → rollback restores both files and runs `composer install`;
  provision failing → `rolled_back` with the database caveat flag; "nothing to update"; a job whose
  state is no longer `queued` (cancelled) does nothing; `getMaxAttempts() === 1`.
- **Integration**: the routes' `system.config` gate (administrator refused, superuser allowed);
  the setting off → 403/`disabled`; the maintenance middleware's 503 and its passes.
- **Framework**: `queue:restart` makes a worker exit after its current job.
- **Admin (vitest)**: the notice's three states (no permission, ready, reasons); the progress view's
  states from canned status sequences, including failed polls during the swap.
- **e2e**: start → queued → running → succeeded through mocked routes; the waiting message at
  2 minutes (clock) and Cancel.
- No test runs real Composer or pg_dump.

## 13. Not in this design

Automatic or scheduled updates; database rollback; updating extension engines on their own from the
admin; reloading PHP-FPM; installs where the owner cannot run a queue worker.
