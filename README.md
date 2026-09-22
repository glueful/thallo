# Thallo — Developer Preview

A self-hosted, composable CMS and commerce platform for developers building content-rich
sites and storefronts. One installable system — content, rendering, navigation, collections,
SEO, analytics, commerce with payment links and receipts, subscriptions, and multi-workspace
tenancy — designed together, running on the [Glueful](https://github.com/glueful/framework)
PHP framework with a Vue 3 admin.

> **Developer Preview.** The platform is production-engineered (the payment paths in
> particular are built fail-closed and race-tested), but the packaging, docs, and defaults are
> young. Read [docs/limitations.md](docs/limitations.md) before taking real money.

## Requirements

- PHP **8.3+** (CLI + your web SAPI)
- **PostgreSQL** (what Thallo's own site and test suite run). SQLite/MySQL are configurable
  but not currently tested lanes — see [docs/limitations.md](docs/limitations.md).
- A web server pointing at `public/` (the PHP built-in server works for evaluation)
- **cron** (per-capability entries; see [docs/production.md](docs/production.md))
- Optional: queue workers for background jobs, an SMTP/rich-mail transport for email features

## Quickstart

```bash
composer create-project --prefer-dist --stability=beta glueful/thallo my-site
cd my-site
createdb my_site                  # provision configures a database; it does not create one
php glueful thallo:provision      # prompts for the database; writes .env, keys, migrations, cache
php -S localhost:8000 -t public vendor/glueful/framework/router.php
```

Provision checks the host first and stops on a failure; `php glueful thallo:doctor` runs the full
set of checks on its own, and is the command to reach for when something fails. Then create the first admin: open the setup link provision printed
(it carries this install's one-time `SETUP_TOKEN`; re-run provision to print it again), or run
`php glueful thallo:create-admin` in the terminal.

`.env` ships in **production** mode (debug off). For the local quickstart above, set
`APP_ENV=development` and `APP_DEBUG=true` in `.env` before starting the server; the commented
"Local development baseline" block at the end of `.env.example` lists the full set.

Both commands take flags for scripted installs (`--help`; pass `-n` for non-interactive).
If `.env` already holds your `DB_PGSQL_*` values, provision shows them and asks you to confirm
instead of prompting field by field. The database it names must exist and be reachable.

Log in at `http://localhost:8000/admin`.

`BASE_URL` matters: every absolute URL Thallo emits (media, sitemaps, canonical/OG heads,
payment links) derives from it, never from the request's Host header. Plain HTTP is fine for
local development; **payment links specifically require a canonical HTTPS origin** (see
`.env.example`'s notes).

## What's on by default

Most features are capabilities, switched at **Extensions › Capabilities**. A fresh install has
these on: Accounts, Analytics, Data collections, Content importers, Navigation, Rendered delivery,
SEO, Subscriptions and the Approval workflow. Three are off:

- **Search** is off by default. It runs on your PostgreSQL database and needs nothing installed;
  switch it on, then run `php glueful search:reindex`.
- **Commerce** is off until its engine is enabled: `php glueful extensions:enable glueful/commerce`
  (and `glueful/payvia` for payments), then switch the capability on.
- **Multi-tenancy** (workspaces) is turned on through its own staged flow in
  **Settings › Workspaces**, never by `extensions:enable`.

[Capabilities and packs](docs/concepts/06-capabilities.md) has the full table. Each feature you
enable may add operational obligations (cron entries, config); they are listed per capability in
[docs/production.md](docs/production.md).

## Documentation

- [Production setup & operational obligations](docs/production.md)
- [Known limitations](docs/limitations.md)
- [Upgrading](docs/upgrading.md)

Thallo's own code is the `glueful/thallo-core` package (this repository's `core/`, published
with the packs at every release); `app/`, `routes/` and `database/migrations/` in an install are
yours and start empty. Upgrade with `composer update && php glueful thallo:provision`.
- [Security policy](SECURITY.md)

## Support

Open an issue on the repository. Security reports: see [SECURITY.md](SECURITY.md) — please do
not open public issues for vulnerabilities.

## Versioning

Semantic versioning. Developer Preview releases are `1.0.0-beta.N` and immutable — fixes ship
as the next `beta.N+1`. Upgrade notes live in [CHANGELOG.md](CHANGELOG.md) and
[docs/upgrading.md](docs/upgrading.md).

## License

MIT.
