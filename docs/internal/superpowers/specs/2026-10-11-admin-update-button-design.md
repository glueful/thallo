# Update from the admin — design

Status: revision 4 (2026-10-11). Revision 3 was approved and implemented. The whole-branch review
found that the runner trusted state PHP-FPM can write. **§16 (the trust boundary) supersedes every
file location and state-flow statement in §3, §5–§7 and §9–§11 that it contradicts.** Server (core,
skeleton, one framework addition) and admin.

Revision 3 answers the second review:

- the runner and recovery live outside the replaced tree, and every rename is journaled (§3, §9);
- a lock barrier, not a job count, proves the application has stopped (§7);
- once provision starts, any failure needs recovery (§10);
- PHP-FPM itself is verified before reopening (§8);
- staging supports a declared Composer shape and refuses the rest (§8.1);
- the progress and recovery page is served by the guard and survives a refresh (§11).

Findings from later reviews go to the implementation plan's Review Focus, not to a revision 4.

## 1. Purpose and audience

Thallo serves two audiences and gives each the update path it expects:

- **Code-first users** update locally, commit `composer.lock` and deploy. Nothing on their servers
  rewrites code; the update notice shows the exact command. The feature is off for them.
- **Owners coming from WordPress** run one server and expect to press **Update**. Once it is set up
  (§2), they press Update and watch it. A failure before the database could have changed gives them
  the old code back; a failure after leaves the site closed with clear recovery steps.

## 2. Enabling it

`docs/upgrading.md` says there will be no update button because Composer runs as the deploy user,
never under the web worker. That separation is kept:

- **Off for Composer installs.** `THALLO_ADMIN_UPDATES=false`; `composer create-project` never
  changes it.
- **An explicit setup choice.** A guided installer may *offer* it. It first explains that the
  runner's user can rewrite the site's code, then proves the setup by running
  `bin/thallo-updater selftest` (§3): the runner runs as the code owner, the guards are in place,
  PHP-FPM cannot write code, the web verification answers, and the barrier works. It enables the
  setting only if the owner accepts and the self-test passes. By hand, the same self-test is the
  documented last step.
- **The web process never writes code.** PHP-FPM needs write access only to
  `storage/framework/updates/`.
- **Owner only.** The admin routes require `system.config`, held only by the `superuser` role.
- **One application host.** The same host serves the site and runs its workers and cron. The setting
  is the owner's statement of this; the barrier (§7) relies on local file locks and is not valid
  across hosts or on network filesystems.

## 3. Processes

| Process | Runs as | Writes | Does |
|---|---|---|---|
| PHP-FPM (admin) | web user | `storage/framework/updates/` | preflight, files the request |
| **Update runner** `bin/thallo-updater` | deploy user (owns the code) | code, `storage/` | claims, executes, recovers |
| **Guard** `bootstrap/update-guard.php` | web user, every CLI | `storage/framework/updates/` (recovery requests) | admission, closing, status, recovery page, web verification |
| Workers, scheduler | as today | as today | pass the guard's barrier (§7) |

**The runner lives outside `vendor/`.** Provision installs it at `bin/thallo-updater` (site-owned),
copying it from `vendor/glueful/thallo-core/bin/` with an atomic rename whenever the shipped copy is
newer. The owner's cron line and every recovery command point at the site-owned copy:

```
* * * * * cd /path/to/site && php bin/thallo-updater tick
```

It is a single self-contained PHP file. Once it starts executing it never `require`s anything, so
replacing `vendor/`, or `bin/thallo-updater` itself, cannot change the code it runs. It reads and
writes the operation journal (§5). That format is versioned, and every runner reads every earlier
version, so a newer runner can recover an older run. Anything needing the application (preflight,
backup, provision) runs as a child `php glueful …` with `THALLO_UPDATE_CHILD=1`. With no work to
do, `tick` refreshes the runner report (§4) and exits.

## 4. Preflight — when the button is enabled

`GET /v1/admin/updates/preflight` (`system.config`) answers `{ready, target, reasons[]}`. Checks only
the runner's user can make are recorded by `tick` in `storage/framework/updates/runner.json`; a
missing or stale (> 5 min) report is the reason `runner_missing`.

| Check | Seen by | Reason code |
|---|---|---|
| Setting on | FPM | `disabled` |
| Runner reported within 5 min | FPM | `runner_missing` |
| A newer version exists that the root constraint for `glueful/thallo-core` allows (advisory; staging is the real test) | FPM | `outside_constraint` |
| Code writable by the runner; **not** writable by PHP-FPM (`vendor/`, `composer.*`, `public/admin/`) | runner, FPM | `not_writable`, `web_can_write_code` |
| Composer present and new enough for temporary inline constraints (floor pinned in the plan) | runner | `composer_missing` |
| The Composer project matches the supported shape (§8.1) | runner | `composer_config_unsupported` |
| `pg_dump`, `pg_restore` present | runner | `backup_unavailable` |
| Free disk ≥ §9's requirement | runner | `low_disk` |
| Guard included by `public/index.php` and `glueful`; `bin/thallo-updater` present | FPM, runner | `guard_missing` |
| The runner's web verification call (§8 step 9) reaches PHP-FPM and is answered | runner | `web_verify_unreachable` |
| PHP-FPM: `opcache.preload` empty | FPM | `opcache_preload` |
| PHP-FPM: `opcache.validate_timestamps` not `0` | FPM | `opcache_frozen` |
| PHP-FPM, mod_php or CGI — not a persistent runtime (RoadRunner, Swoole, FrankenPHP worker mode) | FPM | `persistent_runtime` |
| No operation in progress or awaiting recovery | FPM | `in_progress`, `recovery_required` |

On a multi-tenant install the routes and the button exist only in the host admin.

## 5. Operation state, journal and ownership

One operation at a time, in `storage/framework/updates/operation.json` (format-versioned; written
temp + `fsync` + rename; mode 0640):

- identity and request: `id`, `target`, `from`, `status`, `step`, `steps[]`, `requested_by`,
  `requested_at`, `token_hash`;
- progress: `heartbeat_at`, `provision_started`, `error`;
- recovery: `backup`, `journal[]`, `recovery_request`, `recovery_options[]`.

The full output goes to `storage/logs/update-{id}.log`.

**Status**:

- `requested → running → succeeded | failed | rolled_back | recovery_required`;
- `requested → cancelled`;
- `recovery_required → running` (a recovery action) or `→ recovered_by_owner`.

What the end states mean:

- `failed`: the live install was never changed.
- `rolled_back`: the install changed before provision started and was verifiably restored.
- `recovery_required`: anything else, with the site closed.

**Transitions** are compare-and-set under a short exclusive `flock` on `state.lock`, held only for
the read-check-write:

- **Start** (FPM): allowed when there is no operation, or the last is terminal and not
  `recovery_required`. Mints a 32-byte token, stores its hash, returns it once.
- **Claim** (runner, holding `run.lock`): `requested → running`.
- **Cancel** (FPM): `requested → cancelled` only.
- **Terminal** and **recovery** transitions (runner).
- **Recovery request** (guard, token holder): records `recovery_request` only; the runner acts on it.

**Exclusive execution.** The runner holds a non-blocking exclusive `flock` on `run.lock` for the
whole operation. It passes the locked descriptor to every child it starts, so the lock frees only
when the runner **and every descendant** (Composer included) have exited. Concurrent `tick`s fail
to take it and exit. There is no queue, so there are no reservations and no duplicate deliveries.

**Liveness.** The runner advances `heartbeat_at` every 5 s from its own loop while polling its
child, so a quiet Composer still heartbeats. A stale heartbeat is displayed and never acted on. A
run is dead only when `status` is `running` **and** a `tick` can take `run.lock`; it then becomes
`recovery_required`, never cleared.

**Journal.** Every filesystem change to the live install (§8 step 6, rollback, recovery) is first
appended to `journal[]` as `{seq, op: "rename", from, to, phase: "intended"}` and persisted, then
performed, then marked `"done"`. An `intended` entry whose `from` no longer exists while `to` does
was performed and is marked done on resume. Rollback is the done entries reversed, journaled the
same way, so an interrupted rollback resumes too. Given any kill point, the journal plus the
filesystem tell recovery exactly which tree is where.

## 6. Start

`POST /v1/admin/updates` (`system.config`, CSRF). The confirm dialog shows:

- the version change, `current → target`;
- the release notes and that release's Upgrade Notes;
- this warning: "Your site is closed to visitors and to other admins while the update runs, usually
  for a few minutes. A verified database backup is taken first."

The server re-runs preflight and performs **Start**, answering `202 {id, token}`. The admin stores
the token in `sessionStorage` and navigates the tab to `/_update` (§11).

## 7. Closing the site and the barrier

`bootstrap/update-guard.php` is site-owned, plain PHP with no dependencies. `public/index.php` and
the `glueful` console include it **before** `bootstrap/app.php`. It works with two files in
`storage/framework/updates/`: the barrier `admission.lock` and the marker `closed.flag`.

**Admission (every web request and every console process):**

1. If the path is a guard route (`/_update`, `/_update/*`), serve it; it never boots the application
   or takes the barrier.
2. If `closed.flag` exists, refuse:
   - web: `503`, `Retry-After: 120`, a static "Back in a few minutes" page, and `/health` as
     `{"status":"updating"}`;
   - `queue:work`: sleep and recheck every 5 s, then continue — a supervisor's restart never gives up
     and never boots half-replaced code;
   - `queue:scheduler run`: exit 0;
   - other commands: refuse with a message.
3. Take a **shared** `flock` on `admission.lock`, non-blocking. If that fails, the runner holds it
   exclusively; refuse as in 2.
4. Re-check `closed.flag` (it may have appeared between 2 and 3); if present, release and refuse.
5. Keep the shared lock for the life of the request or process; PHP releases it when the request
   ends or the process exits.

Processes with `THALLO_UPDATE_CHILD=1` (the runner's children) skip admission.

**The barrier (§8 step 4):** the runner writes `closed.flag`, signals `queue:restart`, then takes an
**exclusive** `flock` on `admission.lock`, polling for up to 10 minutes. It is granted only when no
admitted process remains:

- a web request already past admission, including one paused before its write;
- a worker still running a job whose queue reservation has expired;
- a scheduler process between two jobs;
- a long-lived worker (it releases only by exiting, which `queue:restart` causes after its current
  job).

New work cannot enter (steps 2 to 4). The runner holds the exclusive lock until it reopens or ends
in `recovery_required`. If the 10 minutes pass, it removes the flag and ends `failed` with nothing
changed; the log lists the barrier as the cause.

## 8. The run

The runner claims the operation, then:

1. **Preflight as the runner** (§4, runner rows), fresh. Failure → `failed`.
2. **Stage** (§8.1) at `storage/framework/updates/{id}/stage/`. It pins the approved target and
   verifies the result. Failure → `failed`; the live install is untouched.
3. **Close** — write `closed.flag`.
4. **Barrier** — §7. Timeout → reopen, `failed`.
5. **Back up and snapshot** — §9.
6. **Swap** — journaled renames (§5):
   1. `vendor/` → `{id}/vendor.old`;
   2. `stage/vendor` → `vendor/`;
   3. `composer.lock` → `{id}/composer.lock.old`;
   4. `stage/composer.lock` → `composer.lock`.

   `composer.json` is unchanged by a pinned update and is verified equal, not swapped. Everything is
   on one filesystem (checked in step 1). A failure or kill here is rolled back by the journal: the
   status becomes `rolled_back` once step 9 verifies `from`, otherwise `recovery_required`.
7. **Provision** — set `provision_started`, then run `php glueful thallo:provision --no-interaction`
   as a child (never `--force`).
8. **CLI verify** — a child `php glueful thallo:update:verify --json`: the installed version from
   Composer runtime metadata equals `target`.
9. **Web verify** — the runner calls the guard's `POST /_update/verify` over loopback
   (`THALLO_UPDATE_VERIFY_URL`, default `http://127.0.0.1` with the `Host` of `BASE_URL`), carrying
   the secret in `storage/framework/updates/verify.secret` (written by the runner, readable by the
   web group, rotated per operation).

   The guard alone accepts that secret: it boots the application inside PHP-FPM while ordinary
   traffic stays refused. It answers with:
   - the installed version from Composer runtime metadata;
   - `PHP_VERSION`;
   - any PHP extension the release requires but the process lacks;
   - whether the container builds.

   Success means three consecutive answers report `target` and nothing missing, within
   `opcache.revalidate_freq + 30` seconds. OPcache rechecks a script's timestamp at most once per
   `revalidate_freq` seconds, when the script is next used; it never reloads anything ahead of time.
   So the verify asks PHP-FPM itself rather than waiting and assuming.
10. **Reopen** — clear the page and shop caches, remove `closed.flag`, release the barrier, and
    signal `queue:restart` again. Mark `succeeded`, then delete `{id}/vendor.old`, the stage and the
    lock copy.

Any failure from step 7 on → `recovery_required`, site closed (§10).

### 8.1 Staging and the supported Composer shape

Staging runs Composer with `--working-dir=stage`, `COMPOSER_HOME=storage/framework/composer`:

```
composer update glueful/thallo-core:<target> --with-all-dependencies \
  --no-interaction --no-progress --no-ansi --no-scripts
```

The inline constraint pins the **approved** version, so a newer release published while the request
waited is never installed. `-W` moves Thallo's whole dependency tree, including packages the root
also lists.

**Supported root shape** (anything else is `composer_config_unsupported`, update from the terminal):

- **Autoload:** `autoload` and `autoload-dev` hold `psr-4`, `psr-0`, `classmap` and `files` entries
  whose paths are inside the project and outside `vendor/`. Each top-level path is symlinked into the
  stage, so the optimized class map the skeleton asks for (`optimize-autoloader: true`) scans the real
  `app/`.
- **Locations:** the default `vendor-dir`, no `extra.installer-paths`, no custom installers.
- **Packages:** no `repositories` of type `path` (and so no symlinked packages), and no relative
  `artifact` URLs. VCS and Composer repositories are fine.
- **Plugins:** every `allow-plugins` entry set to `true` must be on Thallo's plugin allowlist (today:
  `php-http/discovery`, which the skeleton allows). A plugin outside it is refused rather than run.

**After staging**, the runner verifies all of these before going further:

- the stage's `composer.json` is byte-identical to the live one, so no plugin rewrote it;
- the live `composer.json`, `composer.lock` and `vendor/` are unchanged (hashes and mtimes recorded
  before staging);
- `glueful/thallo-core` in the stage's `installed.json` is exactly `<target>`;
- the stage's autoloader resolves one class from `app/` and one from `glueful/thallo-core`, in a
  child process.

Any difference → `failed`.

## 9. Backup

- Step 5 runs the child `php glueful thallo:update:backup --to=<dir> --json`. It succeeds only if the
  dump exits 0, the file is non-empty, and `pg_restore --list` reads it (custom format, `-Fc`). The
  framework `DatabaseBackupTask` reports failures in its result rather than throwing, so the command
  checks that result.
- The same step snapshots provision's file outputs by **copy**: `public/admin/`, the generated API
  reference, the compiled style artifact and the extension cache. A copy, so a later rollback can
  restore them by rename.
- Stored in `storage/backups/update-{id}/` (folder `0700`, files `0600`) with the old `composer.lock`
  and a `manifest.json` (`{id, from, target, database, dumped_at, bytes, sha256}`).
- **Retention:** the last 3 (`thallo.admin_updates.keep_backups`), pruned after a `succeeded` run. A
  backup belonging to a `recovery_required` operation is never pruned.
- **Disk:** `pg_database_size` × 1.2, plus `vendor/` × 2 (stage and `vendor.old`), plus the snapshot,
  plus 256 MB.

## 10. Failure and recovery

**Reopening by itself** happens only for a failure before provision started (`provision_started`
false), and only when all of these hold:

- the journal rollback completed;
- the CLI verify reports `from`;
- the web verify reports `from`.

The status is then `rolled_back`.

**Once provision has started, any failure is `recovery_required`.** That holds even when the
migrations list is unchanged: provision also writes capability adoption, role grants, block
definitions and font data, which no migration records. The site stays closed, and the database is
never restored automatically.

A run found dead (§5) is `recovery_required` at whatever step it reached; the journal says where the
files are.

**Recovery options**, shown on the `/_update` page to the token holder and always also available in
the terminal:

| Option | Offered when | Runner does |
|---|---|---|
| **Roll back the code** | the journal shows the new tree live, or a half-done swap or rollback | journal rollback and snapshot restore; the CLI and web verify `from`; stays closed |
| **Retry provision** | the target tree is live | step 7 onward |
| **Reopen the site** | always, with a typed confirmation | removes `closed.flag`, releases the barrier; `recovered_by_owner`. For "I restored the database from the backup" or "I checked it and it works". |

The page shows the backup path and the restore command (`pg_restore --clean --if-exists --dbname …`).
The terminal equivalents are `php bin/thallo-updater recover --rollback | --retry-provision | --reopen`
and `php bin/thallo-updater token`. That last one mints a fresh recovery token (rotating
`token_hash`) for a browser that lost it.

## 11. The `/_update` page

The guard serves `GET /_update`: a self-contained HTML page with its CSS and JS inline, inside the
guard file. It never touches the application, `vendor/` or the admin bundle, so it loads during the
vendor gap, after a failed provision and after a refresh.

- It reads the token from `sessionStorage`, which survives a refresh in the same tab. Without one,
  it shows a field for a token from `bin/thallo-updater token`.
- It polls `GET /_update/status` (token in a header; compared with `hash_equals`) every 2 s. The
  answer is the operation minus secrets, plus the last 200 log lines.
- It shows the step checklist with timings, the live log, and the end states:
  - succeeded: the version, the notes, a link back to the admin;
  - failed or rolled back: the reason, and that the site is open;
  - recovery required: what happened, the backup path, the options of §10.
- An option posts to `POST /_update/recover`, which records the request; the page then shows
  "Waiting for the runner (up to a minute)".
- A failed poll shows "The server is busy — still checking" and never declares failure.
- With no operation, it shows "No update in progress" and links to the admin.

The admin keeps only the notice and the dialog (§6). It has no progress view of its own.

## 12. Framework addition

**`queue:restart`** (general-purpose, any app, any driver). It stores a restart timestamp in the
cache, and `QueueWorker` checks it each loop: a worker exits after its current job once the timestamp
is newer than its own start. Thallo requires the framework release that ships it. The barrier
replaces the job-count addition of revision 2.

## 13. Config, skeleton and docs

- `thallo.admin_updates.enabled` ← `THALLO_ADMIN_UPDATES` (default `false`); `keep_backups` (3);
  `verify_url` ← `THALLO_UPDATE_VERIFY_URL`.
- Skeleton: `bootstrap/update-guard.php`; its include in `public/index.php` and `glueful`. Provision
  installs `bin/thallo-updater`.
- `docs/upgrading.md`: the "no update button" line replaced by *Updating from the admin*:
  - the setup choice, the cron line and the user it runs as, and the self-test;
  - what PHP-FPM may write, and single host only;
  - the supported Composer shape and the preflight reasons;
  - the run, recovery, and the database caveat.

  The terminal command becomes `composer update glueful/thallo-core -W && php glueful
  thallo:provision`, followed by `php glueful queue:restart`.
- DISTRIBUTION.md decision 11 amended; `UpdateChecker`'s "Never an updater" docblock updated.
- `UPGRADE_COMMAND` in `admin/src/queries/updates.ts` updated.
- OpenAPI: the admin routes, hand-spliced. CHANGELOG under [Unreleased].

## 14. Testing

**Disposable-install proof — the gate for this feature.** A script builds a throwaway site from the
skeleton:

- a local Composer repository with two controlled builds of `glueful/thallo-core`, `from` and
  `target`. The target adds one migration, one admin-bundle change and one provision step that writes
  stored data;
- a disposable database;
- **PHP-FPM with OPcache enabled** behind a local web server, with the real cron-style runner.

It runs locally and in CI. Scenarios:

1. Successful update: target installed, CLI and web verify pass, backup verified, site reopens.
2. Composer killed mid-stage: live install untouched, `failed`, site open.
3. Runner killed **between each pair of swap renames**, and between each rollback rename: the next
   `tick` finds the run dead. **Roll back the code** restores `from` from every kill point, and the
   web verify reports `from`.
4. Runner killed while its Composer child keeps running: no `tick` can claim until that child exits.
5. Provision fails after applying the migration: `recovery_required`, site closed. A `pg_restore`,
   then **Roll back the code** and **Reopen**, brings the site back on `from`.
6. Provision changes stored data without a migration, then fails: `recovery_required`, never
   reopened by itself.
7. Rollback fails (a snapshot made unreadable): `recovery_required`, never reopened.
8. Two `tick`s started together: exactly one claims.
9. Barrier: a web request paused before its write, a job running past its reservation timeout, and a
   scheduler between two jobs. Backup and swap wait for each, and nothing new is admitted meanwhile.
10. Barrier timeout: a request that never finishes leads to `failed`, the site reopens, nothing
    changed.
11. A loop of requests to `/` and `/_update/status` during steps 6–9 sees no PHP fatal, only the 503
    page and status JSON.
12. `/_update` refreshed during the vendor gap and after a failed provision: it loads, keeps
    token-protected access, and refuses a wrong token.
13. A release published after the request: the approved target is installed.
14. Unsupported shapes (a path repository, a non-allowlisted plugin, `installer-paths`): refused at
    preflight and again at staging.
15. A plugin that rewrites the staged `composer.json`: `failed`, the live install untouched.
16. The web verify with a missing required extension: `recovery_required` after the swap, or
    `failed` at preflight when the old release already lacks it.

**Unit / integration:**
- preflight reasons one by one;
- CAS transitions and races;
- the journal's resume rules for every `intended` and `done` combination;
- the heartbeat advancing during a silent child;
- the backup command rejecting an empty or unreadable dump;
- the guard's admission order, including the re-check after the shared lock;
- the token and verify-secret checks;
- route gates (administrator refused, superuser allowed).

**Framework:** `queue:restart`.

**Admin:** the notice's states and the dialog's hand-off to `/_update`.

## 15. Not in this design

- automatic or scheduled updates;
- database restore by Thallo;
- updating extension engines on their own from the admin;
- reloading PHP-FPM or restarting persistent runtimes;
- multiple hosts or network filesystems;
- Composer shapes outside §8.1;
- installs without cron.

## 16. Revision 4: the trust boundary

The update runner runs as the code owner so that PHP-FPM cannot change code. Revision 3 kept the
runner's working state in `storage/framework/updates/`, which PHP-FPM must be able to write. A web
compromise could therefore plant a staged `vendor/`, forge the rename journal, inject Composer
configuration or plant a backup, and the runner would act on it as the code owner. Revision 4
separates what the runner trusts from what PHP-FPM can write.

**The guarantee.** Nothing PHP-FPM can write makes the runner install code other than the approved
release of `glueful/thallo-core` and its dependency tree, as resolved by the site's own Composer
configuration, or perform a filesystem operation outside the runner's fixed set. Tampering with what
PHP-FPM can write may confuse what the admin and `/_update` display, or block an update. It is not
claimed to do nothing at all.

### 16.1 Three locations

| Location | Owner, mode | PHP-FPM may | Holds |
|---|---|---|---|
| `<site>/.thallo-update/` (**private**) | code owner, `0700` | nothing (cannot list, read or enter) | the operation (state, journal, provision manifest, token hash), `run.lock`, `state.lock`, the readiness markers `guard.installed` and `guard.ready`, `ops/<id>/` (the staging copy, the old `vendor/`, the old `composer.lock`), `composer-home/`, `backups/<id>/` |
| `<site>/.thallo-update-control/` (**control**) | code owner, the web group, `0750`; files `0640` | read only | `status.json` (what `/_update` and the admin show, published by the runner), `closed.flag`, `admission.lock`, `verify.secret`, `runner.json` |
| `storage/framework/updates/` (**inbox**) | code owner, the web group, `2770` | write | `start.json` (a request), `recover.json` (a recovery request) |

- **Parents are protected.** The private and control folders sit directly in the site root. The site
  root and every ancestor must be writable by nobody but the code owner or root. A world-writable
  ancestor is accepted only if it has the sticky bit and the next path component belongs to the code
  owner or root, as `/tmp` does.
  - The runner checks the whole chain on every tick, using `lstat`, so a symlink anywhere is refused.
  - Each of the two folders must be a real directory owned by the code owner, with exactly its
    table mode.
  - Any failure: the runner refuses to claim, preflight reports `insecure_layout`, and `selftest`
    fails.
- **PHP-FPM cannot replace a control file.** It opens `admission.lock` read-only for its shared lock
  (flock allows a shared lock on a read-only descriptor); the runner takes the exclusive lock on its
  own read-write descriptor. Neither the folder nor the file is writable by PHP-FPM, so the barrier
  always has one lock file.
- **Runner-trusted configuration and readiness records live in the private folder.** That covers
  Composer's home, the readiness markers and the operation. Nothing the runner trusts is read from
  `storage/`, the inbox or the database.

### 16.2 Requests are untrusted

- **`start.json`.** PHP-FPM writes it after its own preflight: `{target, from, requested_by,
  requested_at, token_hash}`.
- **Claiming a request.** The runner claims by **renaming** the file into the private folder, then
  reads the private copy. It refuses a symbolic link, a file over 4 KB, or anything that is not that
  exact JSON shape.
- **Validation:**
  - `target` must match `^\d+\.\d+\.\d+(-(alpha|beta|RC)\.?\d+)?$`;
  - it must be newer than the version in the live `composer.lock`, which the code owner owns;
  - it is then resolved only by Composer, against the site's own `composer.json` repositories and
    constraint. Composer refuses an inline constraint outside the root requirement, and refuses a
    version no trusted repository publishes;
  - the staged result must report exactly `target`;
  - `requested_by` is kept only as a display string (`[A-Za-z0-9._@-]{1,64}`);
  - the operation id is minted by the runner.
- **What a request never supplies:** a path, a command, a Composer option or an environment
  variable.
- **`recover.json`** is `{option, token, confirm}`.
  - The runner accepts it only if `sha256(token)` equals the hash in its private operation, and
    `option` is one of the operation's current `recovery_options`.
  - It deletes the file whether or not it accepts it.
- **`recovery_options`** are computed by the runner from its private journal:
  - **Roll back the code** when forward renames are done and not reversed;
  - **Retry provision** only when all four swap renames are done and none is reversed;
  - **Reopen** always.

  They are published in `status.json`, and `/_update` shows only those.
- **Cancel.** PHP-FPM deletes `start.json` while it is still in the inbox. Once the runner has
  renamed it, there is nothing left to cancel.
- **Fixed operations.** The journal holds only the runner's own fixed rename set: the four swap
  renames and the inventory's restore renames. Restore paths come from the inventory in code, never
  from a file.

### 16.3 The guard

- **What it reads:** `closed.flag`, `admission.lock` and `status.json` in the control folder.
- **What it writes:** only `recover.json` in the inbox.
- **`/_update/status`** checks the token against the `token_hash` the runner published in
  `status.json`.
- **When the lock cannot be opened.** If `admission.lock` cannot be opened (missing, or permissions
  not yet set) and `closed.flag` is absent, the guard admits the request and logs the
  misconfiguration. That is not "an update is running", and must never put the site behind the
  maintenance page.

### 16.4 Enabling it

- **What provision does (as the code owner)** when the feature is on:
  - creates the private folder `0700`;
  - creates the control folder `0750` with group = the group of `storage/`, and its files: an empty
    `admission.lock` at `0640`, and `verify.secret`;
  - sets the inbox `2770` with that group;
  - checks the parent chain.
- **Order does not matter.** Following the docs in order never puts the site behind the maintenance
  page.

### 16.5 Backup and recovery

- **Where backups go:** `.thallo-update/backups/<id>/`, which PHP-FPM cannot read. The owner
  restores as the code owner, using the path in `status.json`:
  `psql … -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;"` then `pg_restore --no-owner`.
- **Retention:** the last three are kept.
- **Clean-up.** The `ops/<id>/` folder (a full copy of `vendor/`) is deleted at every terminal outcome
  where the journal shows no forward change still live: succeeded, failed, rolled back, cancelled,
  recovered by the owner, and a reopen completed after the runner died.

### 16.6 The runner's own checks

- **Before claiming, freshly:** the parent chain; code writable by the runner; Composer; `pg_dump`
  and `pg_restore`; free disk against the requirement; the guard installed and included; PHP-FPM's
  probe answering 200; and the private folder, the live `vendor/` and the backups on one filesystem
  (`stat` device).
- **While an operation is open or `closed.flag` exists,** a tick collects no report and boots no
  application child: no estimate, no probe.

### 16.7 Proof of the boundary (two users)

From the PHP-FPM user, in the two-user proof:
- replace or plant the staging copy;
- forge or edit the operation and its journal;
- inject Composer configuration (a global `config.json` with a repository, or a global plugin) where
  revision 3 kept it, and anywhere PHP-FPM can write;
- redirect `.thallo-update/` or a control file through a symbolic link;
- replace `admission.lock`;
- post `recover.json` with a wrong token, an option not on offer, and a symbolic-link `start.json`.

Every attempt must be refused by the operating system or rejected by the runner. An update run
afterwards must install exactly the approved release, with no file written as the code owner at a
path the attacker chose.

A second scenario enables the feature from scratch with PHP-FPM already running as the web user,
before any manual permission step. The site must keep serving pages throughout.

