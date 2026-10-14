---
section: operations
order: 2
summary: "The upgrade sequence, and how to know when a newer Thallo is out."
---
# Upgrading Thallo

## The sequence (from 1.0.0-beta.21 on)

```bash
composer update glueful/thallo-core -W && php glueful thallo:provision && php glueful queue:restart
```

then, only if OPcache runs with `opcache.validate_timestamps=0`, reload PHP-FPM. Thallo is
installed as `glueful/thallo-core` in `vendor/` (with the capability packs alongside it).
`composer update glueful/thallo-core -W` moves Thallo and its whole dependency tree — the
framework, Symfony, Twig, and any of those your own `composer.json` also lists — while packages
outside Thallo's tree stay at their `composer.lock` versions. `thallo:provision` finishes the job: pending migrations, install-role grants, starter block
types, the extension cache, and the release's admin bundle published into `public/admin`. Read
the release's **Upgrade Notes** in [CHANGELOG.md](https://github.com/glueful/thallo/blob/main/CHANGELOG.md) first.

`queue:restart` asks every running queue worker to exit after its current job, so its supervisor
starts it on the new code.

Your own files are never touched by an upgrade: `.env`, `config/` overrides, `app/`, `routes/`,
`database/migrations/`, `themes/{name}/`, `storage/`.

## One-time move for installs created before beta.21

Installs up to `1.0.0-beta.20` were `create-project` root packages: Thallo's code sat in the
install directory itself, where Composer never updates it. Move once to the new template, then
the sequence above applies forever:

1. Install the template beside the old site (the version you are moving to):

   ```bash
   composer create-project --prefer-dist --no-dev --stability=beta glueful/thallo new-site
   ```

2. Carry your files across:

   ```bash
   cp  old-site/.env                 new-site/.env
   rm -rf new-site/storage && cp -R old-site/storage new-site/storage
   cp -R old-site/themes/.           new-site/themes/            # your overrides, if any
   cp -R old-site/app/. old-site/routes/. old-site/database/migrations/.  \
         new-site/{app,routes,database/migrations}/               # only if you added your own
   ```

3. Provision and switch the web server's document root to `new-site/public`:

   ```bash
   cd new-site && php glueful thallo:provision --no-interaction && php glueful migrate:verify
   ```

   Provision recognises the database as already migrated: Thallo's migrations were recorded under
   the old source names (`app`, `app:dependent`) and are adopted under the new ones
   (`glueful/thallo-core`, `glueful/thallo-core:dependent`) — nothing re-runs. Then reload
   PHP-FPM and delete `old-site` once the new one serves.

Customisations you made inside Thallo's own files (under the old `app/`, `routes/` or
`database/migrations/` of a release) were never yours to keep; re-apply them as overrides in the
paths listed above.

## The Thallo website is an ordinary install

thallo.dev runs the same template every site runs — `composer create-project --prefer-dist
--stability=beta glueful/thallo`, provisioned, upgraded with `composer update glueful/thallo-core -W
&& php glueful thallo:provision`. There is no separate deploy path for it: the published release IS what the
website runs, and every release is exercised there by the exact upgrade every operator runs.

## What every upgrade must include

- **`thallo:provision`** on the new release: pending migrations, install-role grants for any
  permission a new pack declared, starter block types the instance lacks (existing rows are
  never touched), the extension cache production boot requires, and the admin bundle published
  into `public/admin` (the release's copy replaces the previous one; stale files are removed). `migrate:verify` confirms
  every declared migration source is Ready; a non-zero exit stops the sequence.
- **Cache clears that outlive a release** — done by `thallo:provision`: the compiled route
  table (a stale one keeps serving the previous release's routes), the rendered pages, the
  compiled templates and the compiled container. The container is named by its list of services,
  so a release that only changes what a service is built with would otherwise keep the old build.
  A deploy that skips provision runs `route:cache:clear` and `render:cache:clear` itself and
  empties `storage/cache/container`.
- **A PHP-FPM reload only when OPcache has timestamp validation off**
  (`opcache.validate_timestamps=0`, a common production tuning): OPcache then never re-reads
  changed files and keeps serving the previous release's classes. With PHP's default
  (validation on), nothing to do. Provision cannot do this for you: the CLI has its own OPcache.
- **The release's Upgrade Notes** in [CHANGELOG.md](https://github.com/glueful/thallo/blob/main/CHANGELOG.md).

**Why the cache clears matter:** a compiled artifact from a previous release can construct
services with outdated constructor signatures or route the old paths. Thallo's security-relevant
services fail **loud** in that state rather than silently downgrade — a skipped clear shows up
as a clear error, not a quiet vulnerability. Clear and it heals.

## What is yours and survives every upgrade

`.env`, `storage/` (uploads, cache, logs, backups), the database, your theme overrides under
`themes/{name}/`, and any code you added under `app/`, `routes/` or `database/migrations/`.
An upgrade never rewrites these. Changes you made *inside* Thallo's own files are not carried
across — put customisations in the places above.

## Capabilities

Switching a pack's capability on (Settings › Capabilities, e.g. Commerce or Accounts) needs no
command: the first request afterwards seeds that pack's starter block types, skipping any slug
that already exists. Switching it off keeps the rows but drops them from Settings › Block types
and the block picker until it is on again. With workspaces on, seed each workspace with
`php glueful thallo:blocks:seed --all` instead.

## Older installs

**Pre-beta.3 installs are not upgradable in place.** Developer Preview builds up to
`1.0.0-beta.2` recorded pack migration receipts under pre-manifest ledger names
(`thallo-*`, render's bare `migrations`); beta.3's ledger is canonical from provision and ships
no migration path for those receipts. Re-provision, or rewrite the ledger `source` values by
hand before upgrading.

## Versioning expectations

- Semantic versioning. Developer Preview releases are `1.0.0-beta.N`.
- **Tags are immutable.** A correction ships as the next `beta.N+1` (later: patch releases);
  a tag you have already installed will never change underneath you.
- Behavioral defaults never change in a patch. Minor releases may add features, env keys, and
  operational obligations — each is listed in the release's Upgrade Notes and reflected in
  [production.md](production.md).

## Knowing when to upgrade

Once a day (04:00 server time, `update_check` in `config/schedule.php`, run by
`php glueful queue:scheduler run` from cron) the install asks Packagist's public metadata for
the newest published `glueful/thallo-core` it may move to — a plain GET with no install
identifier; nothing about the site leaves it. The answer is kept in the system flags, and
administrators with `system.access` see it: a card on Home and an **Update** badge on
Utilities → Health, both with the release notes link and the command above; the Health page
shows the installed and newest versions. Dismissing the card hides that version in that
browser only; the next release shows again. The notice clears the moment the upgrade has run.

- A pre-release install (`1.0.0-beta.N`) is offered newer pre-releases and stable releases; a
  stable install is never pointed at a pre-release.
- `UPDATE_CHECK_ENABLED=false` in `.env` turns the check and the notice off.
- **Check for updates** on Utilities → Health asks Packagist now instead of waiting for 04:00,
  and says what it found. It is limited to six checks a minute per user.
- `php glueful thallo:update:check` (add `--force` to ask now) shows the same status on the
  command line.
- A failed daily check is silent: the previous answer stands until the next one succeeds.
  **Check for updates** says when it could not reach Packagist.
- On a single server that turned it on, the owner can also update from the card: see
  *Updating from the admin* below. Composer still never runs under the web worker.

## Updating from the admin

For owners who run one server and would rather press **Update** than open a terminal. It is
**off** unless you turn it on, and installs made with `composer create-project` never turn it on.
When it is on, an **update runner** does the update as the user that owns the code; PHP-FPM
checks, files the request and shows progress.

How you turn it on depends on which user PHP-FPM runs as:

- **The same user that owns the code** — CloudPanel, most hosting control panels, shared hosting.
  This is **simple mode**: one setting, no cron line.
- **A separate web user** (`www-data`) with the code owned by a deploy user. The runner runs from
  cron as the deploy user, and PHP-FPM cannot change the code itself.

Thallo tells them apart on its own: simple mode is when PHP-FPM can write `vendor/` and the private
update folder.

### Turning it on in simple mode

1. Set `THALLO_ADMIN_UPDATES=true` in `.env`.
2. Run `php glueful thallo:provision`. It installs `bin/thallo-updater` and
   `bootstrap/update-guard.php`, creates the update folders, and adds the guard's include to
   `public/index.php` and `glueful` on an install made before 1.0.0-beta.102.

That is all. Pressing **Update** starts the runner in the background from PHP-FPM, the way the
extension store runs Composer. There is no cron line and no restart to confirm. PHP-FPM can
already write the code here, so separating the two users would protect nothing.

The runner checks the release from PHP-FPM through `BASE_URL` (or `THALLO_UPDATE_VERIFY_URL`).
When the server cannot reach its own site that way, the command-line check confirms the release
instead, as a terminal upgrade does.

### Turning it on with a separate web user

1. Set `THALLO_ADMIN_UPDATES=true` in `.env`.
2. Run `php glueful thallo:provision` **as the user that owns the code**. It installs
   `bin/thallo-updater` and `bootstrap/update-guard.php`, which `public/index.php` and `glueful`
   include, and creates the three update folders (below). (An install made before this release
   adds the three include lines from the skeleton's `public/index.php` and `glueful` by hand; the
   admin says so until they are there.)
3. Add the runner to cron **as the user that owns the code** (the deploy user, not the web user):

   ```text
   * * * * * cd /path/to/site && php bin/thallo-updater tick
   ```

4. Put the web user and the code owner in one group, the group of `storage/`. Provision creates
   the folders with these owners and modes; the runner refuses to act if they change:

   | Folder | Owner | Mode | Holds |
   | --- | --- | --- | --- |
   | `.thallo-update/` | code owner | `0700` | everything the runner trusts: the operation and its journal, locks, the staged `vendor/`, Composer's home, logs and the update backups |
   | `.thallo-update-control/` | code owner, the web group | `2750` (setgid; files `0640`) | what the runner publishes for PHP-FPM to read: status, the closed flag, the admission lock, the verification secret |
   | `storage/framework/updates/` | the web group | `2770` | requests from the admin — the only update folder the web user can write |

   The site's folder and every folder above it must be writable only by the code owner or root, so
   that nobody else can swap the update folders for others. The web user writes nothing else either:
   `vendor/`, `composer.json`, `composer.lock` and `public/admin/` stay writable only by the owner.
5. Restart PHP-FPM (or Apache), every queue worker and every long-running job of the site, then
   run `php bin/thallo-updater confirm-restart --yes`. Processes started before the guard existed
   are invisible to it, so the command refuses while it can still see one; what it cannot see —
   another user's process started elsewhere — is what your `--yes` confirms.
6. Run `php bin/thallo-updater selftest` and fix every line that fails.

### What an update does

When you press **Update** and confirm, the browser moves to `/_update`, a page the guard serves
even while the site's code is being replaced. The runner, within a minute:

1. checks again that it can write the code and that Composer, `pg_dump` and disk space are there;
2. installs the approved version into a copy of `vendor/` (`composer update
   glueful/thallo-core:<version> -W`), so the live site is untouched if that fails;
3. closes the site — visitors **and other admins** see a maintenance page — and asks workers to
   restart;
4. waits until every request, job and scheduler run already under way has finished;
5. takes a database backup and checks that `pg_restore` can read it, and copies what provision
   will change (the admin bundle, the API reference, the compiled styles, the extension cache);
6. swaps the new `vendor/` and `composer.lock` in;
7. runs `thallo:provision`;
8. checks the installed version on the command line and **from PHP-FPM itself**;
9. clears the caches, asks workers to restart, and reopens the site.

### When it is unavailable

The card lists why, one sentence each. In simple mode, PHP-FPM may be unable to start the runner
(`proc_open` disabled, or `bin/thallo-updater` not installed). In either mode: the runner has not
checked in (its cron line, with a separate web user), the code is
not writable by the runner or **is** writable by PHP-FPM, Composer is missing or older than 2.2,
`pg_dump`/`pg_restore` are missing, there is not enough disk, the guard is not installed, PHP-FPM
cannot be reached to verify (`THALLO_UPDATE_VERIFY_URL`), PHP preloads code
(`opcache.preload`), OPcache never rechecks files (`opcache.validate_timestamps=0`), the site runs
on a persistent runtime (RoadRunner, Swoole, FrankenPHP worker mode), the restart after enabling
has not been confirmed, the update folders are not laid out as above, or the release is outside
what your `composer.json` allows. The cron line, PHP-FPM writing the code, the verification URL,
the restart and the folder layout apply only with a separate web user. Each of those
means: update from the terminal, as above.

A persistent runtime keeps the old code in memory after the files change, so updating from the
admin refuses it in both modes: update from the terminal and restart the runtime's workers.

The runner supports this `composer.json` shape: autoload paths inside the project (outside
`vendor/`), the default `vendor-dir`, no `path` repositories and no relative `artifact` URLs, no
`extra.installer-paths`, and only allowlisted Composer plugins (`php-http/discovery`). Anything
else updates from the terminal.

### When something fails

- **Failed** — nothing live changed; the site is open on the old version.
- **Rolled back** — the swap failed before provision started; the old code is back and verified,
  and the site is open.
- **Your site needs attention** — anything after provision started, or a runner that stopped. The
  site **stays closed**. `/_update` shows what happened, the backup's path and the choices the
  runner's own records allow: **Roll back the code** (while anything it replaced is still in
  place), **Retry provision** (only when the whole new `vendor/` and `composer.lock` are in place)
  and **Reopen the site** (typed confirmation).

The database is never restored by Thallo. If an update changed it and you roll the code back,
restore it yourself first, as the code owner (only that user can read the backup), into an empty
schema — `pg_restore --clean` alone drops only what the dump holds, so a table the failed update
created would survive it:

```bash
psql --dbname <database> -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;"
pg_restore --no-owner --dbname <database> .thallo-update/backups/<id>/database.dump
```

The same choices work from the terminal, as the code owner:
`php bin/thallo-updater recover --rollback | --retry-provision | --reopen`. A browser that lost
its token (another device) gets a new one from `php bin/thallo-updater token`. The last three
update backups are kept (`THALLO_UPDATE_KEEP_BACKUPS`).

### What the web user can and cannot do

PHP-FPM can file a request to update to the version the admin offers, cancel it before the runner
picks it up, and choose one of the recovery choices `/_update` shows. It cannot write anything the
runner trusts. The runner checks every request itself: the version must be a release newer than the
installed one, and Composer resolves it against your `composer.json` and its repositories, so a
request never carries a path, a command, Composer options or environment settings. A request from
the web user therefore cannot make the runner install code other than that release, or rename,
write or delete anything outside its own fixed set of files.

### Not supported

More than one application server, network filesystems for the site, persistent PHP runtimes, PHP
preloading, and installs without cron when PHP-FPM runs as a separate web user.

## Extensions

Extension engines (Commerce, Payvia, …) version independently and are pinned by this app's
`composer.json`. `composer update` moves them within the pinned constraints; their own
changelogs ship in `vendor/glueful/<name>/CHANGELOG.md`.
