---
title: "Configuration reference"
slug: configuration
section: reference
order: 2
summary: "The environment keys and config files a site owner sets, with their defaults."
---

A Thallo site is configured in three places: `.env` at the root of the project, the PHP files in
`config/`, and settings saved in the admin. This page says which of them wins, and lists the keys
a site owner sets, with their defaults.

## Which layer wins

Nothing reads `.env` on its own. The files in `config/` read it through `env()`, so a variable
does something only where a config file asks for it, under the name that file uses. A key added
to `.env` that no config file reads changes nothing.

Each config value is resolved key by key, lowest layer first:

1. Defaults a pack or an extension ships inside `vendor/`.
2. The framework's own config, inside `vendor/glueful/framework/config/`.
3. The project's `config/`.
4. The project's environment folder: `config/` plus the value of `APP_ENV`, so
   `config/development/queue.php` applies only when `APP_ENV=development`.
5. A setting saved in the admin, for the keys the admin covers.

Layers merge rather than replace, so a file in the project's `config/` needs only the keys it
changes; everything else keeps the shipped value.

A variable already present in the process environment wins over the same name in `.env`: the
loader never overwrites what the environment already holds. That is how a container or a CI job
overrides a committed `.env`.

## Changing a value a pack ships

Never edit a file under `vendor/`. An upgrade overwrites it. Create a file of the same name in
the project's `config/` and put only the keys you are changing in it. Thallo's own defaults, and
the config name each answers to:

| Config name | Holds |
|---|---|
| `thallo` | Site name, the delivery API's page sizes and cache, preview token lifetime, the capability map, the publish scheduler switch, the update check, version retention |
| `render` | The theme, the homepage entry, listing types, the render cache, custom CSS size |
| `search` | The search engine and Meilisearch index |
| `seo` | Title template, `og:image` default, per-type fallback fields, `robots.txt` groups |
| `analytics` | Analytics retention and query limits |
| `thallo-commerce` | The shop path prefix, order-email switches, the shop catalog cache |
| `forms` | Form descriptor lifetime, the time trap, submit rate limits, the fallback recipient |
| `signup` | Public-signup lifetimes, one-time-code limits and rate limits |
| `theme` | The colour-mode switch |
| `import_export` | Import and export routes, the source disk and roots |

The capability map is the switch that decides whether a pack's routes and jobs are registered at
all. `config/thallo.php` in the project:

```php
<?php

return [
    'capabilities' => [
        'thallo.search' => true,
    ],
];
```

Search is the one capability that ships off; every other id is on unless listed here as `false`.
See [capabilities](../concepts/06-capabilities.md).

## What the admin stores instead

**Settings › General** writes to the database. A saved value beats the `.env` or config default,
takes effect on the next request, and never rewrites `.env`.

| Field | Falls back to | Default |
|---|---|---|
| **Site name** | `thallo.site_name` (`SITE_NAME`) | `Thallo` |
| **Site preview URL** | `thallo.admin.site_preview_url` (`SITE_PREVIEW_URL`) | empty |
| **Admin URL** | `render.admin_url` (`RENDER_ADMIN_URL`) | empty |
| **Homepage** | `render.homepage_entry` (`RENDER_HOMEPAGE_ENTRY`) | empty |
| **Listing types** | `render.listing_types` (`RENDER_LISTING_TYPES`) | empty |
| **Default locale** | `thallo.admin.default_locale` (`ADMIN_DEFAULT_LOCALE`, then `I18N_DEFAULT_LOCALE`) | `en` |
| **Default items per page** | `thallo.delivery.default_per_page` (`DELIVERY_DEFAULT_PER_PAGE`) | `20` |
| **Max items per page** | `thallo.delivery.max_per_page` (`DELIVERY_MAX_PER_PAGE`) | `100` |
| **Cache TTL (seconds)** | `thallo.delivery.cache_ttl` (`DELIVERY_CACHE_TTL`) | `60` |
| **Publish scheduler** | `thallo.scheduler.enabled` (`CONTENT_SCHEDULER_ENABLED`) | on |
| **Content webhooks** | `thallo.pipeline.webhooks_enabled` (`PIPELINE_WEBHOOKS_ENABLED`) | on |
| **Content search** | the `thallo.search` entry of the `thallo.capabilities` map | off |

Other screens hold configuration the same way, in the database rather than in `.env`:

- **Site › Appearance** — the live theme (default `RENDER_THEME`), its accent and neutral
  colours, corner radius, typefaces, page background, the logos and the site icon.
- **Extensions › Capabilities** — every capability switch. **Content search** is the same state
  as the one in Settings › General, not a second switch.
- **Settings › Email** — the mail transport. Each value resolves per send: the saved row first,
  the `services.mail` config and its `MAIL_*` variables second.
- **Settings › Payments** — gateway credentials, stored encrypted. Consulted before the
  `PAYVIA_*` environment values.

## Site and URLs

| Key | Default | What it does |
|---|---|---|
| `APP_NAME` | `Glueful` | The title of the generated API reference. `.env.example` ships `Thallo`. |
| `APP_ENV` | `development` | `production`, `staging`, `development` or `testing`. It is the default for debug, HTTPS enforcement and the log profile, and it names the `config/` environment folder. `.env.example` ships `production`. |
| `APP_DEBUG` | off in production, on elsewhere | Detailed errors. |
| `BASE_URL` | `http://localhost` | The canonical public origin. Every absolute URL — media, sitemaps, canonical and OG tags, the storefront's CSRF origin — comes from this, never from the request's `Host` header. Payment links need it to be HTTPS with no non-default port. |
| `SITE_NAME` | `Thallo` | The default behind Settings › General **Site name**, which is `site.name` in every template, `og:site_name` and `{site_name}` in the SEO title. |
| `RENDER_ADMIN_URL` | empty | Where the admin lives, for the preview bar's Edit and Design links and the billing return. Empty means this site's own admin at `BASE_URL` + `/admin`. |
| `API_DOCS_ENABLED` | `true` | Serves the API reference. Set it to `false` to stop. |
| `API_DOCS_PATH` | `/api-docs` | Where the reference is served. `/docs` is the site's own documentation, not this. |

Three different keys spell the site's name. They feed different surfaces and none of them
follows the others: set all three to the same value unless you want them to differ.

The rest of the API's URL shape is in `config/api.php`. Thallo's own delivery and admin routes
are at `/v1` whatever you set there.

## Database

`php glueful thallo:provision` asks for these and writes them into `.env`; see
[install Thallo](../getting-started/02-install.md). PostgreSQL is the supported database.

| Key | Default | What it does |
|---|---|---|
| `DB_DRIVER` | `sqlite` | `pgsql`, `mysql` or `sqlite`. `.env.example` ships `pgsql`. |
| `DB_PGSQL_HOST` | `127.0.0.1` | |
| `DB_PGSQL_PORT` | `5432` | |
| `DB_PGSQL_DATABASE` | `glueful` | The database must already exist; provision connects, it does not create. |
| `DB_PGSQL_USERNAME` | `postgres` | |
| `DB_PGSQL_PASSWORD` | empty | |
| `DB_PGSQL_SCHEMA` | `public` | |
| `DB_PGSQL_SSL_MODE` | `require` in production, `prefer` elsewhere | |
| `DB_POOLING_ENABLED` | `true` | Connection pooling. `.env.example` ships `false`. |
| `DB_LAZY_LOADING_MODE` | `auto` | N+1 query detection: `off`, `warn`, `strict` or `auto` (warn in development, off elsewhere). |

`config/database.php` holds the MySQL and SQLite blocks, the pool sizes and the query-cache
settings (`QUERY_CACHE_ENABLED`, `QUERY_CACHE_TTL`, `QUERY_CACHE_STORE`).

## Mail

Values here are the fallback under **Settings › Email**, which is where a running site normally
sets them.

| Key | Default | What it does |
|---|---|---|
| `MAIL_MAILER` | `smtp` | The transport. SMTP needs nothing extra; an API transport (Postmark, Mailgun, SES, SendGrid, Brevo) needs its Symfony package installed first. |
| `MAIL_HOST` | `smtp.mailtrap.io` | |
| `MAIL_PORT` | `2525` | `.env.example` ships `587`. |
| `MAIL_USERNAME` | empty | |
| `MAIL_PASSWORD` | empty | |
| `MAIL_ENCRYPTION` | `tls` | `tls`, `ssl`, or empty for none. |
| `MAIL_FROM` | `noreply@glueful.com` | The From address. |
| `MAIL_FROM_NAME` | `Glueful` | The From name. |

The BCC address and the logo in mail templates are set in Settings › Email; there is no `.env`
key behind them.

## The queue and the scheduler

| Key | Default | What it does |
|---|---|---|
| `QUEUE_CONNECTION` | `database` | `database` or `redis`, the only drivers that exist. |
| `QUEUE_PAYLOAD_SIGNING` | `true` | HMAC-signs persisted queue and scheduler payloads with `APP_KEY`. |
| `QUEUE_REQUIRE_SIGNED_PAYLOADS` | `true` | Refuses unsigned payloads. Turn it off only while draining older jobs. |
| `QUEUE_WORKER_SLEEP` | `3` | Seconds a worker waits when a queue is empty. |
| `QUEUE_MAX_TRIES` | `3` | Attempts before a job is failed. |

Each queue has a memory limit, a timeout and a job cap, named for the queue:
`DEFAULT_QUEUE_MEMORY` (128 MB), `DEFAULT_QUEUE_TIMEOUT` (60 s), `DEFAULT_QUEUE_MAX_JOBS` (1000),
and the same three for `CRITICAL_`, `HIGH_`, `MAINTENANCE_`, `NOTIFICATIONS_`, `EMAIL_` and
`REPORTS_`. The scheduled jobs and their own switches are in `config/schedule.php`; see
[the scheduler and the queue](../operations/03-scheduler-and-queues.md).

## Storage and uploads

| Key | Default | What it does |
|---|---|---|
| `UPLOADS_ENABLED` | `true` | Registers the upload routes. |
| `UPLOADS_DISK` | `uploads` | A disk declared in `config/storage.php`. The default is local, rooted at `storage/uploads/`. |
| `MEDIA_DISK` | the value of `UPLOADS_DISK` | The disk asset fields validate against. It must match the disk uploads land on. |
| `UPLOADS_MAX_SIZE` | `10485760` | One file's ceiling, in bytes. |
| `UPLOADS_ACCESS` | `upload_only` | `upload_only` (the admin uploads, retrieval is public per file), `private` or `public`. |
| `UPLOADS_DEFAULT_VISIBILITY` | `private` | The visibility a new file gets. |
| `UPLOADS_SIGNED_URLS` | `true` | Signed, expiring URLs for private files. `UPLOADS_SIGNED_TTL` (3600 s) is their lifetime. |
| `UPLOADS_STRIP_EXIF` | `true` | Strips camera and location metadata on upload. |
| `UPLOADS_MAX_WIDTH`, `UPLOADS_MAX_HEIGHT` | `2048` | The largest resize candidate. |
| `UPLOADS_CACHE_TTL` | `604800` | How long a resized variant is kept. |
| `IMAGE_DRIVER` | `gd` | `gd` or `imagick`, for thumbnails and resizes. |
| `STORAGE_DEFAULT_DISK` | `uploads` | The default disk for everything else. |
| `CDN_URL` | empty | A base URL for public file URLs on the local disk. |

`config/storage.php` also declares an `s3` disk and `.env.example` carries `S3_*` keys, but only
the `local` and `memory` drivers are built in: pointing a disk at `s3` fails until
`glueful/storage-s3` is installed. See [media](../guides/08-media.md).

## Search

| Key | Default | What it does |
|---|---|---|
| `SEARCH_ENGINE` | `auto` | `auto`, `postgres` or `meilisearch`. `auto` picks Meilisearch when `MEILISEARCH_HOST` is set, and the site's own PostgreSQL otherwise. |
| `MEILISEARCH_HOST` | `http://localhost:7700` | Not in the shipped `.env.example`; write it in to use Meilisearch. |
| `MEILISEARCH_KEY` | empty | The server's key, if it needs one. |
| `SEARCH_INDEX` | `content` | The Meilisearch index name. |
| `SEARCH_SNIPPET_LENGTH` | `40` | Words of context in a highlighted excerpt. |

Search is off until its capability is switched on. See [search](../guides/11-search.md).

## Security

`php glueful thallo:provision` generates `APP_KEY`, `JWT_KEY`, `TOKEN_SALT` and `SETUP_TOKEN`
when they are empty. Keep them out of version control and back them up: encrypted values and
signed previews do not survive a change.

| Key | Default | What it does |
|---|---|---|
| `APP_KEY` | empty | Encryption key. Also the fallback secret for signed URLs and the analytics actor hash. |
| `JWT_KEY` | empty | Signs session tokens. |
| `TOKEN_SALT` | empty | Salts token generation. |
| `SETUP_TOKEN` | empty | Guards the first-run web setup at `POST /admin/setup`, sent as the `X-Setup-Token` header. Required in production: with none set, setup is refused. |
| `ACCESS_TOKEN_LIFETIME` | `3600` | Seconds. `.env.example` ships `900`. |
| `REFRESH_TOKEN_LIFETIME` | `604800` | Seconds. |
| `FORCE_HTTPS` | on in production | Override only when TLS terminates where the framework cannot see it. |
| `TRUSTED_PROXIES` | empty | Comma-separated proxy IPs or CIDRs. Required behind a load balancer for correct client IP and HTTPS detection; empty trusts none. |
| `CSP_HEADER` | empty | The Content-Security-Policy for responses that set none of their own. `.env.example` ships a policy for the rendered site. |
| `CSP_REPORT_ONLY` | `false` | `.env.example` ships `true`: the browser logs what the policy would block and blocks nothing. Set it to `false` once the console is quiet. |
| `HSTS_HEADER` | `max-age=31536000; includeSubDomains` in production | |
| `CORS_ALLOWED_ORIGINS` | empty | Comma-separated origins for cross-origin API clients. The admin is same-origin and needs none. |
| `CORS_SUPPORTS_CREDENTIALS` | `false` | Credentialed cross-origin requests. It cannot be combined with a `*` origin. |
| `HEALTH_IP_ALLOWLIST` | empty | Comma-separated addresses or CIDRs allowed to reach the health endpoints. |

## Rendering and the caches

| Key | Default | What it does |
|---|---|---|
| `RENDER_THEME` | `default` | The theme used when none is chosen in Site › Appearance. Resolved at boot. |
| `RENDER_HOMEPAGE_ENTRY` | empty | The entry rendered at `/` when Settings › General names none. A value that resolves to nothing is a hard error, not a themed 404. |
| `RENDER_LISTING_TYPES` | empty | Comma-separated content-type slugs that get `/{type}` listings and term archives. Empty keeps the whole grammar dormant. |
| `RENDER_LISTING_PER_PAGE` | `10` | Items on a rendered listing page. |
| `RENDER_CACHE_ENABLED` | `true` | The full-page render cache. Set it to `false` while theming. |
| `RENDER_CACHE_TTL` | `3600` | Seconds a rendered page is held. Surrogate tags do the real invalidation; on a cache driver without tags this is the only bound. |
| `RENDER_DB_TEMPLATES` | `true` | Templates edited in the admin, layered over the theme's files. `false` also unregisters the template admin routes. |
| `CUSTOM_CSS_MAX_BYTES` | `262144` | The size cap on the site's custom stylesheet. |
| `THALLO_COLOR_MODE_ENABLED` | `true` | `false` renders the site light-only: no toggle, no dark CSS, whatever the visitor prefers. |
| `CACHE_DRIVER` | `file` | `file`, `redis`, `memcached` or `array`. Only a driver with tag invalidation purges page by page; on `file`, a content change drops every rendered page. |
| `CACHE_PREFIX` | `glueful:` | Key namespace. |
| `CACHE_TTL` | `3600` | Default cache lifetime, in seconds. |
| `CACHE_TAGS` | `true` | Tagged cache entries. |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB` | `127.0.0.1`, `6379`, none, `0` | Used by the Redis cache store and the Redis queue driver. |

## Logging

| Key | Default | What it does |
|---|---|---|
| `LOG_PROFILE` | the value of `APP_ENV` | `development`, `staging`, `production` or `testing`. It sets the defaults for everything else here. |
| `LOG_LEVEL` | the profile's | Explicit override. |
| `LOG_FILE_PATH` | `storage/logs` | Where log files are written. |
| `LOG_TO_FILE` | `true` | |
| `LOG_TO_DB` | `false` | |
| `LOG_RETENTION_DAYS` | `30` | How long the scheduled log cleanup keeps files. Per-channel retention has its own keys, from `LOG_RETENTION_DEBUG_DAYS` (7) to `LOG_RETENTION_SECURITY_DAYS` (180). |
| `SLOW_REQUEST_THRESHOLD` | `1000` | Milliseconds before a request is logged as slow. `SLOW_QUERY_THRESHOLD` (200) is the same for a query. |

## Workspaces

These prepare host resolution; the enablement itself happens in **Settings › Workspaces**. See
[turn on workspaces](../operations/07-multi-site.md).

| Key | Default | What it does |
|---|---|---|
| `TENANCY_BASE_DOMAIN` | empty | The domain each workspace's subdomain hangs off. Required before full host resolution. |
| `TENANCY_DEFAULT_HOSTS` | empty | Comma-separated hosts that resolve to the default workspace. |
| `TENANCY_PUBLIC_SCHEME` | `https` | The scheme used when building a workspace's public origin. |
| `TENANCY_REVERIFICATION_ENABLED` | `true` | The hourly custom-domain re-verification sweep. |

## Commerce and payments

Commerce is an extension: its keys live in `config/commerce.php`, which you create in the project
to override them. The storefront rendering and blocks are a Thallo pack, configured under
`thallo-commerce`.

| Key | Default | What it does |
|---|---|---|
| `COMMERCE_CURRENCY` | `USD` | The store's currency. |
| `COMMERCE_TAX_BPS` | `0` | Flat tax rate in basis points. |
| `COMMERCE_ORDER_NUMBER_FORMAT` | `ORD-{seq}` | |
| `COMMERCE_ORDER_EXPIRY_MINUTES` | `60` | Before a pending-payment order is stale. |
| `COMMERCE_ORDER_DRAFT_PURGE_DAYS` | `30` | Before a cancelled draft order is hard-deleted by the expiry sweep. Clamped to 1–365; there is no value that disables it. |
| `COMMERCE_CART_TTL_DAYS` | `30` | Before an active cart is abandoned. |
| `THALLO_COMMERCE_SHOP_PREFIX` | `shop` | The catalog's path segment. It must be exactly one non-empty segment; a bad value fails at boot. |
| `THALLO_COMMERCE_SHOP_CACHE_ENABLED` | `true` | The catalog page cache. `THALLO_COMMERCE_SHOP_CACHE_TTL` (3600 s) is its safety net. |
| `PAYVIA_DEFAULT_GATEWAY` | `paystack` | The default payment gateway. |

Gateway credentials belong in **Settings › Payments**, where they are stored encrypted, rather
than in `.env`. See [sell products](../guides/18-commerce.md).

## Other keys worth knowing

| Key | Default | What it does |
|---|---|---|
| `UPDATE_CHECK_ENABLED` | `true` | The daily Packagist check for a newer `glueful/thallo-core`. It sends no install identifier and never updates anything; `false` turns the check and the notice off. |
| `ANALYTICS_RETENTION_DAYS` | `90` | How long raw analytics facts are kept. Rollups are kept for good. |
| `PREVIEW_TTL` | `600` | A preview token's life, in seconds. |
| `VERSION_KEEP`, `VERSION_MAX_AGE_DAYS` | unset | Version pruning. Unset means no pruning. |
| `FORMS_DEFAULT_RECIPIENT` | empty | The address a form submission goes to when its block names none. Empty makes such a form un-routable. |
| `FORMS_RATE_MAX`, `FORMS_RATE_WINDOW` | `5`, `60` | Submissions allowed per form and IP, and the window in seconds. |
| `EXTENSIONS_INSTALL_PHP_BINARY`, `COMPOSER_BINARY` | empty | Absolute paths for the in-admin extension installer. Leave them blank unless the installer says it cannot find a CLI PHP or Composer. |

Everything else in the shipped `.env.example` and in `config/` is framework configuration. Read
the file that names the key: it carries the comment that explains it.

## Check what took effect

`php glueful thallo:doctor` reads the running configuration and reports on the PHP version, the
`pdo_pgsql` extension, the `.env` target, `storage/`, the security keys, the theme vocabulary,
the style artifact, the environment and the database connection. Anything it marks FAIL fails
the command.

```bash
$ php glueful thallo:doctor
```

For a value the admin owns, open the screen that owns it: a saved setting wins over `.env`, so a
key you changed there and nowhere else will not be what the site is using.
