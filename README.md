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
- A database: **SQLite** works out of the box; **PostgreSQL** is what Thallo's own site and
  test suite run and is recommended for production. MySQL is configurable but not part of the
  tested lanes.
- A web server pointing at `public/` (the PHP built-in server works for evaluation)
- **cron** (per-capability entries; see [docs/production.md](docs/production.md))
- Optional: queue workers for background jobs, an SMTP/rich-mail transport for email features

## Quickstart

```bash
composer create-project --prefer-dist glueful/thallo my-site
cd my-site
cp .env.example .env        # then edit: database, BASE_URL, mail
php glueful install         # generates keys, sets up the database, runs migrations
php glueful aegis:bootstrap-admin  # grant your first admin (see --help)
php -S localhost:8000 -t public vendor/glueful/framework/router.php
```

Log in at `http://localhost:8000/admin`.

`BASE_URL` matters: every absolute URL Thallo emits (media, sitemaps, canonical/OG heads,
payment links) derives from it — never from the request's Host header. Plain HTTP is fine for
local development; **payment links specifically require a canonical HTTPS origin** (see
`.env.example`'s notes).

## What's on by default

A fresh install activates the core CMS (content, render, collections, navigation, SEO,
analytics, media, i18n, users/RBAC, audit, import/export) plus the bundled subscriptions
billing engine. **Commerce, payments (Payvia), and Meilisearch ship installed but disabled** —
enable them from the in-admin extensions browser (Settings → Extensions) or:

```bash
php glueful extensions:enable Commerce
php glueful extensions:enable Payvia
php glueful migrate:run
```

Each feature you enable may add operational obligations (cron entries, config); they're
listed per capability in [docs/production.md](docs/production.md).

## Documentation

- [Production setup & operational obligations](docs/production.md)
- [Known limitations](docs/limitations.md)
- [Upgrading](docs/upgrading.md)
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
