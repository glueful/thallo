# Upgrading Thallo

## What `composer update` does — and does not — do

A Thallo site installed with `composer create-project` is Composer's **root package**. Composer
manages `vendor/` only: `composer update` moves the Glueful framework, the extension engines
and the capability packs, and **never** Thallo itself — not the admin, not the application
code, not the migrations. A site created from `1.0.0-beta.19` stays beta.19 no matter how
often `composer update` runs. (This is the standard behaviour of any `create-project`
template; a Composer-updatable Thallo is planned — see "Roadmap" below.)

**Upgrading Thallo therefore means deploying the next release tag** and carrying across the
files that are yours. There are two ways to do that.

## Option A — deploy from the tag with `scripts/deploy-site` (recommended)

The script ships in every release. It checks out exactly one immutable tag into its own
directory, links your `.env`, `storage/` and theme overrides into it, installs the locked
dependencies without dev packages, provisions, clears the caches that outlive a release,
switches the live directory in one step, reloads PHP-FPM and runs the doctor. Rolling back is
re-pointing the `current` link to a previous release.

Layout (`DEPLOY_ROOT`, for example `/home/thallo/htdocs/thallo.dev`):

```
releases/<tag>/     one checkout per deployed tag
shared/.env         your environment — lives here, never inside a release
shared/storage/     uploads, cache, logs, backups
shared/themes/      your theme overrides (app-level themes/{name}/)
current -> releases/<tag>
```

Point the web server's document root at `current/public`.

### First deploy with this layout (once, from a flat install)

1. Create the layout and move your files into `shared/`:

   ```bash
   mkdir -p DEPLOY_ROOT/shared DEPLOY_ROOT/releases
   mv OLD_INSTALL/.env     DEPLOY_ROOT/shared/.env
   mv OLD_INSTALL/storage  DEPLOY_ROOT/shared/storage
   mv OLD_INSTALL/themes   DEPLOY_ROOT/shared/themes   # or mkdir if you have no overrides
   ```

2. Deploy the release you want:

   ```bash
   DEPLOY_ROOT=/home/thallo/htdocs/thallo.dev OLD_INSTALL/scripts/deploy-site v1.0.0-beta.20
   ```

3. Change the document root to `DEPLOY_ROOT/current/public` (keep the same server block as
   before, including the PHP-served asset locations from [production.md](production.md)), reload
   the web server, and check the site. Delete `OLD_INSTALL` once you are satisfied.

`FPM_RELOAD` defaults to `sudo systemctl reload php8.4-fpm`; set it to the command your panel
uses, or to `:` if the panel reloads for you. `REPO_URL` defaults to the public repository.

### Every upgrade after that

```bash
DEPLOY_ROOT=/home/thallo/htdocs/thallo.dev current/scripts/deploy-site v1.0.0-beta.21
```

Run `--dry-run` first to see every step without executing any. The script refuses anything
that is not a release tag: branches and commits are never deployed.

## Option B — fresh `create-project`, carry your files across

Without git on the server, or for a one-off move:

```bash
composer create-project --prefer-dist --no-dev glueful/thallo new-site 1.0.0-beta.20
cp  old-site/.env        new-site/.env
rm -rf new-site/storage && cp -R old-site/storage new-site/storage
cp -R old-site/themes/.  new-site/themes/        # your overrides, if any
cd new-site && php glueful thallo:provision --no-interaction && php glueful migrate:verify
php glueful route:cache:clear && php glueful render:cache:clear
```

Then point the document root at `new-site/public` and reload PHP-FPM.

## What every upgrade must include

- **`thallo:provision`** on the new release: pending migrations, install-role grants for any
  permission a new pack declared, starter block types the instance lacks (existing rows are
  never touched), and the extension cache production boot requires. `migrate:verify` confirms
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

## Roadmap: `composer update` as the upgrade

Thallo's application is moving into a package (`glueful/thallo-core`) that lives in `vendor/`,
with `create-project` reduced to a thin skeleton. From that release on, `composer update &&
php glueful thallo:provision` upgrades Thallo for real, and the admin will show a notice when a
newer version is published. Until then, the two options above are the upgrade path.

## Extensions

Extension engines (Commerce, Payvia, …) version independently and are pinned by this app's
`composer.json`. `composer update` moves them within the pinned constraints; their own
changelogs ship in `vendor/glueful/<name>/CHANGELOG.md`.
