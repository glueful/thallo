# Upgrading Thallo

## The sequence (from 1.0.0-beta.21 on)

```bash
composer update && php glueful thallo:provision
```

then reload PHP-FPM. Thallo is installed as `glueful/thallo-core` in `vendor/` (with the
capability packs alongside it), so `composer update` moves Thallo the way it moves the framework,
and `thallo:provision` finishes the job: pending migrations, install-role grants, starter block
types, the extension cache, and the release's admin bundle published into `public/admin`. Read
the release's **Upgrade Notes** in [CHANGELOG.md](../CHANGELOG.md) first.

Your own files are never touched by an upgrade: `.env`, `config/` overrides, `app/`, `routes/`,
`database/migrations/`, `themes/{name}/`, `storage/`, `public/storage/`.

## One-time move for installs created before beta.21

Installs up to `1.0.0-beta.20` were `create-project` root packages: Thallo's code sat in the
install directory itself, where Composer never updates it. Move once to the new template, then
the sequence above applies forever:

1. Install the template beside the old site (the version you are moving to):

   ```bash
   composer create-project --prefer-dist --no-dev glueful/thallo new-site 1.0.0-beta.21
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

## Deploying the website from a tag

`scripts/deploy-site <tag>` remains the tag-pinned deploy for the Thallo website (charter:
deploy from the tag, never from a checkout). It checks out this repository at the tag — whose
tree is a complete, lock-pinned install (the template plus `core/` and the packs as path
packages) — into a `releases/` + `shared/` + `current` layout with instant rollback. Ordinary
sites do not need it: `composer update` is their upgrade.

## What every upgrade must include

- **`thallo:provision`** on the new release: pending migrations, install-role grants for any
  permission a new pack declared, starter block types the instance lacks (existing rows are
  never touched), the extension cache production boot requires, and the admin bundle published
  into `public/admin` (the release's copy replaces the previous one; stale files are removed). `migrate:verify` confirms
  every declared migration source is Ready; a non-zero exit stops the sequence.
- **Cache clears that outlive a release**: `route:cache:clear` (the compiled route table — a
  stale one keeps serving the previous release's routes) and `render:cache:clear` (rendered
  pages). The compiled container is signed by its definitions and recompiles itself.
- **A PHP-FPM reload**, so OPcache drops the previous release's classes.
- **The release's Upgrade Notes** in [CHANGELOG.md](../CHANGELOG.md).

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
- `php glueful thallo:update:check` (add `--force` to ask now) shows the same status on the
  command line.
- A failed check is silent: the previous answer stands until the next one succeeds.
- There is no update button, and there will not be one in the self-hosted product: Composer
  runs as the deploy user with write access to `vendor/`, never under the web worker.

## Extensions

Extension engines (Commerce, Payvia, …) version independently and are pinned by this app's
`composer.json`. `composer update` moves them within the pinned constraints; their own
changelogs ship in `vendor/glueful/<name>/CHANGELOG.md`.
