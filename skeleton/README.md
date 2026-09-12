# Thallo

A Glueful-based CMS. This directory is the **install template**: everything that is yours lives
here; Thallo itself is installed by Composer as `glueful/thallo-core` and upgraded the same way.

## Install

```bash
composer create-project --prefer-dist glueful/thallo my-site
cd my-site
php glueful thallo:doctor          # PHP, extensions, paths, database reachability
php glueful thallo:provision       # database + keys + migrations + admin bundle + API reference
```

or the same through the launcher: `./thallo doctor`, `./thallo provision`, or `./thallo setup`
for provision followed by the first admin. Provision prints the link to create the first admin
in your browser (or run `php glueful thallo:create-admin`). The API reference is served at
`/api-docs`, generated into `docs/` by provision from your install's own routes.

## Upgrade

```bash
composer update && php glueful thallo:provision
```

then reload PHP-FPM. Read the release's Upgrade Notes in the
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
