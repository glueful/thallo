# Thallo

A Glueful-based CMS. This directory is the **install template**: everything that is yours lives
here; Thallo itself is installed by Composer as `glueful/thallo-core` and upgraded the same way.

## Install

```bash
composer create-project --prefer-dist --stability=beta glueful/thallo my-site
cd my-site
createdb my_site                   # provision configures a database; it does not create one
php glueful thallo:provision       # database + keys + migrations + admin bundle + API reference
```

Provision checks the host first and stops on a failure; `php glueful thallo:doctor` runs the full
set of checks (PHP, extensions, paths, database reachability) when something fails.

The same commands run through the launcher: `./thallo doctor`, `./thallo provision`, or `./thallo setup`
for provision followed by the first admin. Provision prints the link to create the first admin
in your browser (or run `php glueful thallo:create-admin`). The API reference is served at
`/api-docs`, generated into `docs/` by provision from your install's own routes.

## Run

One cron entry drives every scheduled job (scheduled publishing, the daily update check, the
maintenance sweeps):

```
* * * * * php /path/to/my-site/glueful queue:scheduler run
```

and a queue worker handles background jobs such as mail: `php glueful queue:work`. See
[production](https://github.com/glueful/thallo/blob/main/docs/production.md).

## Upgrade

```bash
composer update && php glueful thallo:provision
```

then, only if OPcache runs with `opcache.validate_timestamps=0`, reload PHP-FPM. Read the
release's Upgrade Notes in the
[changelog](https://github.com/glueful/thallo/blob/main/CHANGELOG.md).

## What is yours

| Path | Purpose |
|---|---|
| `.env` | your configuration |
| `config/` | overrides of Thallo's defaults, key by key |
| `app/` | your own PHP code (`App\` namespace) |
| `routes/` | your own routes |
| `database/migrations/` | your own migrations |
| `themes/{name}/` | your theme overrides |
| `storage/`, `public/storage/` | uploads, cache, logs |

Thallo's code is `vendor/glueful/thallo-core` (with the capability packs alongside it); an upgrade
never touches the paths above.

Documentation: https://thallo.dev

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `skeleton/`.
