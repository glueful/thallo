---
title: "Health checks and troubleshooting"
slug: troubleshooting
section: operations
order: 5
summary: "Find out what is wrong: the doctor, the Health page, the logs, and the usual causes."
---

When a site misbehaves there are four places to look, in this order: the doctor, which checks the
host and the install from a shell; the Health page, which checks the running application; the
logs; and the table of symptoms at the end of this page. Most faults an operator meets are in
that table.

## Run the doctor

From the site's directory:

```bash
$ php glueful thallo:doctor
```

It prints one row per check — OK, WARN or FAIL — and ends with a styled box: the environment
looks healthy, or some checks failed. The command's exit status follows: a FAIL fails it, and
`--strict` makes a WARN fail it too, which is what a deploy script wants.

| Check | What it looks at | Not OK when |
|---|---|---|
| `php` | The PHP version, against the required 8.3.0 | FAIL below 8.3.0 |
| `ext:pdo_pgsql` | The Postgres driver is loaded | FAIL when it is not |
| `env-target` | `.env` is writable, or can be created from `.env.example` | FAIL when `.env` is read-only, or absent with no readable `.env.example` or no writable project root |
| `storage` | `storage/` exists and is writable | FAIL when it is missing or read-only |
| `keys` | `APP_KEY`, `TOKEN_SALT` and `JWT_KEY` all have a value | WARN, naming the ones that are empty |
| `asset-routing` | Fetches `/theme-assets/site.css?t=default` on your public `BASE_URL` | WARN on 404: the web server is serving PHP-generated paths from disk |
| `theme-vocabulary` | The live theme (the one chosen on the Appearance page, else `RENDER_THEME`) has a `theme.json` that maps the platform vocabulary and lists its stylesheets | FAIL, with the reason the manifest was rejected |
| `style-artifact` | The compiled stylesheet for that theme is published under `storage/cache/style/` | WARN, naming the file — run `php glueful thallo:provision` |
| `api-routing` | Fetches `/v1/admin/render/templates/custom.css?theme=default` on your public `BASE_URL` | WARN on 404 or 405: the same web-server fault, for file-shaped API paths |
| `environment` | `APP_ENV` against the host in `BASE_URL` | WARN when a public host runs in anything but production mode |
| `database` | Connects with the credentials in `.env` | FAIL, with the connection error |

Three of those rows are conditional. `environment`, `asset-routing` and `api-routing` need a
`.env` to read. The two routing probes run only when `BASE_URL` names a public host — `localhost`,
`127.0.0.1`, `::1`, `0.0.0.0` and any host ending in `.localhost`, `.local` or `.test` are treated
as local and skipped — and they give no verdict at all when the host cannot be reached from the
machine you run the command on. The `database` row appears only once the database is configured
in `.env`.

`theme-vocabulary` checks the theme chosen on the **Appearance** page when the database can be
reached, and the one `RENDER_THEME` names in `.env` otherwise; the row says which it checked. A
chosen theme that no longer loads does not take the site down: the site serves the `RENDER_THEME`
theme until you fix it or choose another, and the row says so.

## Read the Health page

**Utilities › Health** reports on the application as it runs. It needs the `system.access`
permission. **Refresh** re-runs every check.

The page opens with an overall status — ok, warning or error — then lists the checks, each with
its own status and message, and any issues, warnings and recommendations the check produced.

| Check | What it does | Not OK when |
|---|---|---|
| database | Runs a query, then counts the `migrations` table | Warning when the table is missing, suggesting `php glueful migrate:run`; error when the connection fails |
| cache | Writes, reads back and deletes one key | Error when the value does not come back, or the driver throws |
| extensions | `pdo`, `json`, `mbstring` and `openssl` are loaded | Error, naming the missing ones |
| config | `APP_KEY` and `JWT_KEY` are set and not the shipped placeholders, and `.env` exists | Error, listing each issue. In production it also folds in `APP_DEBUG` being on and keys shorter than 32 characters |
| scheduler | How long ago the scheduler last ticked | Warning past five minutes, or when it has never ticked; the recommendation under the message is the cron line to add |

The overall status counts every check, the scheduler's included: error when any check errs,
warning when any warns. The config check's recommendations are different — a wildcard
`CORS_ALLOWED_ORIGINS`, an empty `CSP_HEADER`, no `HSTS_HEADER`, `LOG_LEVEL=debug` — they are
listed but never change the status.

Below the checks, **System** gives the Thallo version (or "development checkout"), the newest
published version when the update check has run, the framework version, the environment, the PHP
version, memory used against the limit, peak memory, free disk against total, and the time the
report was taken.

## Find the logs

Logs are files under `storage/logs/`. `LOG_FILE_PATH` in `.env` moves the directory.

| File | What is in it |
|---|---|
| `framework.log` | The framework channel: requests, exceptions, deprecations, boot and lifecycle, slow requests over `SLOW_REQUEST_THRESHOLD` ms and slow queries over `SLOW_QUERY_THRESHOLD` ms |
| `error-YYYY-MM-DD.log` | Application messages at error and above |
| `app-YYYY-MM-DD.log` | Application messages at info, notice and warning |
| `debug-YYYY-MM-DD.log` | Application messages at debug |

The three dated files rotate daily and `LOG_ROTATION_DAYS` (30) of them are kept. The scheduler's
and the worker's own output goes wherever their cron line or unit sends it; see
[the scheduler and the queue](03-scheduler-and-queues.md).

How much is written is set by `LOG_PROFILE`, which falls back to `APP_ENV`. The production
profile logs the framework channel at `warning` and the application channel at `warning`;
development logs `info` and `debug`. `FRAMEWORK_LOG_LEVEL` and `LOG_LEVEL` override the profile
one channel at a time, and `APP_DEBUG=true` forces the framework channel down to debug whatever
else is set. Turn a level up to reproduce a fault, and turn it back down: debug logging on a live
site fills a disk.

Who changed what is not in these files. **Users & Access › Audit Log** holds that.

## Clear a cache, and when that is the answer

**Utilities › Cache** shows the driver, the key prefix, whether tag invalidation is on, the key
count and whatever statistics the driver exposes. It offers two actions. **Clear one content
type** invalidates just that type's delivery cache. **Clear all cache** flushes every entry on
the instance and asks you to confirm first; pages and queries are recomputed on next access.

From a shell, four commands clear four different things:

| Command | What it clears |
|---|---|
| `php glueful cache:clear` | The whole application cache. `--tag` limits it to named tags; `--force` skips the confirmation |
| `php glueful render:cache:clear` | Every rendered page, on any cache driver |
| `php glueful route:cache:clear` | The compiled route table, which otherwise keeps serving the previous release's routes |
| `php glueful extensions:cache` | Rebuilds, rather than empties, the extension manifest that production boot requires |

`php glueful cache:status` and `php glueful cache:inspect` only read.

Clearing a cache is rarely the answer to stale content. Publishing, unpublishing, deleting and
changing a content type all purge the affected pages by tag as they happen, and on a driver that
cannot invalidate tags — the default `file` driver — the whole rendered-page cache is dropped
instead. Changing the appearance, a menu, a region, a style class or a template through the admin
purges too. What none of that covers is a change made to a theme's files on disk: after a deploy
that edits templates or theme CSS, run `render:cache:clear`. A Redis-backed cache survives a
restart, so restarting PHP is not a way to clear it.

## Symptoms and their usual causes

| Symptom | Usual cause | What to do |
|---|---|---|
| The rendered site, or the Design view, loads unstyled | A web-server rule serves every `.css`, `.js` and `.woff2` URL from the document root, so `/theme-assets/*` and `/_thallo/*` answer 404 instead of reaching PHP | Add the location rule above the static-file rule: [running in production](../production.md#php-served-asset-paths-web-server). `thallo:doctor` reports this as `asset-routing` |
| Saving the site's custom CSS fails | The same rule, eating the file-shaped API path `/v1/admin/render/templates/custom.css` | The same fix; the rule covers `/v1/` and `/api-docs/` too. `thallo:doctor` reports it as `api-routing` |
| `/admin` loads a blank page and its assets 404 | The admin bundle was never published into `public/admin`, so the web server has no files to serve | `php glueful thallo:provision` |
| `/admin` answers 404 entirely | `ADMIN_ENABLED=false` in `.env`, or the bundle has no `index.html`; boot records the skipped mount as a warning in `framework.log` | Unset `ADMIN_ENABLED`, then `php glueful thallo:provision` |
| Every request fails after a deploy, saying the extension cache is missing in production | `composer update` without provision | `php glueful extensions:cache`, or `php glueful thallo:provision` |
| Scheduled publishing never fires | No cron entry ticking the scheduler — the **scheduler** check on **Utilities › Health** is a warning and names the line — or **Settings › General › Publish scheduler** is off | [The scheduler and the queue](03-scheduler-and-queues.md) |
| An import or export stays "queued" | No worker is taking the `import-export` queue | [Import content from CSV, WordPress or Markdown](../guides/12-import-content.md) |
| The preview bar's **Edit** and **Design** links go somewhere that is not the admin | **Settings › General › Admin URL** holds an address that is not where the admin runs. Left empty, it means this site's own admin | Clear the field, or set it to the admin's real address |
| You changed a theme's templates or CSS on disk and the site still serves the old HTML | The rendered-page cache; nothing on disk raises an event | `php glueful render:cache:clear` |
| URLs in the admin and the media library carry an internal host instead of your domain | TLS or the host name is terminated at a proxy whose forwarded headers are not trusted | Set `TRUSTED_PROXIES` in `.env` to the proxy's addresses |
| Canonical and Open Graph URLs are missing from the rendered site | `BASE_URL` is unset or still the bare `http://localhost` default, so Thallo omits absolute URLs rather than publishing localhost | Set `BASE_URL` to the canonical public origin; see [titles, descriptions, sitemaps and redirects](../guides/10-seo.md) |

## Confirm the site is healthy

- `php glueful thallo:doctor --strict` exits without failing.
- **Utilities › Health** shows an overall status of ok, and the **scheduler** check is ok too.
- The site answers 200 for `/theme-assets/site.css?t=default` and for a page, and the admin loads
  at `/admin`.

If a fault survives all of that, gather the failing request's entry from `storage/logs/`, the
doctor's table and the Health page's checks before you ask for help: they are what anyone
diagnosing it will want first.
