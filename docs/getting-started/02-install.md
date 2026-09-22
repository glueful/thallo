---
title: "Install Thallo"
slug: install
section: getting-started
order: 2
summary: "Create a project, set it up, and sign in to the admin."
---

By the end of this page you have a Thallo install running on your own machine: a project made by
Composer, a PostgreSQL database, the first admin account, and the admin open in your browser.

## What you need

- PHP 8.3 or newer, for the command line and for the web SAPI that will serve the site.
- The `pdo_pgsql` PHP extension. Provision refuses to run without it.
- PostgreSQL. It is what Thallo's own site and test suite run on; SQLite and MySQL are
  configurable but are not tested lanes, so see [known limitations](../limitations.md).
- Composer.
- A web server pointing at `public/`. PHP's built-in server is enough for this page.

A live site needs more — cron for the scheduler, a queue worker, a canonical origin — and that
is [running Thallo in production](../production.md).

## Create the project

```bash
$ composer create-project --prefer-dist --stability=beta glueful/thallo my-site
$ cd my-site
```

Composer installs Thallo into `vendor/`, copies `.env.example` to `.env`, and builds the
extension cache. The directories you own — `app/`, `routes/`, `config/`, `themes/` and
`database/migrations/` — start empty.

`.env` ships in production mode: debug off, API docs off, HTTPS enforced.

## Create the database

Provision configures a database; it does not create one. Make an empty database first, with a
name of your choosing:

```bash
$ createdb my_site
```

If your PostgreSQL asks for credentials for the role you will connect as, have the user name and
password to hand: provision prompts for them.

## Configure the database and run the migrations

```bash
$ php glueful thallo:provision
```

Provision is the first of two layers. It sets up the install; it creates no people. In order, it:

1. Runs the environment preflight — PHP version, `pdo_pgsql`, a writable `.env` target, a
   writable `storage/`, the security keys, the theme's vocabulary. A failure stops the command
   before anything is written.
2. Asks for the connection, one field at a time: Postgres host (`localhost`), Postgres port
   (`5432`), database name, database user, database password, schema (`public`) and SSL mode
   (`prefer`). `.env` ships with placeholder database values, so on a new project every field is
   asked. When `.env` already holds real values, provision shows them in a table and asks once to
   confirm; answering no re-asks each field with those values prefilled.
3. Tests the connection. If the test fails, nothing is written.
4. Writes the settings into `.env`, and generates `APP_KEY`, `TOKEN_SALT` and `JWT_KEY` where
   they are empty. Existing keys are kept unless you pass `--force`.
5. Applies every pending migration.
6. Finishes the install: mints `SETUP_TOKEN` when `.env` has none, grants the `superuser` and
   `administrator` roles their permissions, rebuilds the extension cache, publishes the admin
   bundle into `public/admin`, generates the API reference, compiles the style artifact, and
   clears the route and render caches.

It prints a table of steps 1 to 5 with a status and a detail for each, then a line for each job
in step 6, then:

```text
Next: create the first admin
  1. In your browser (recommended): http://localhost:8000/admin/setup?st=SETUP_TOKEN
  2. Or from this terminal:          php glueful thallo:create-admin
```

Keep that link: it carries this install's `SETUP_TOKEN`. Running provision again prints it again.

For a scripted install, give the connection as options — `--db-host`, `--db-port`, `--db-name`,
`--db-user`, `--db-password`, `--db-schema`, `--db-sslmode` — and pass `-n` so nothing is asked.

## Check the host when something fails

```bash
$ php glueful thallo:doctor
```

Doctor prints one row per check — `php`, `ext:pdo_pgsql`, `env-target`, `storage`, `keys`,
`theme-vocabulary`, `style-artifact`, `environment`, and, once `.env` holds database settings,
`database` — each marked OK, WARN or FAIL, with the detail that explains it. Any FAIL fails the
command; `--strict` fails it on a warning too. When `BASE_URL` names a public host, doctor also
probes that host for two web-server misconfigurations, `asset-routing` and `api-routing`.

## Switch to development mode

For a local try-out, set these two in `.env`:

```ini
APP_ENV=development
APP_DEBUG=true
```

The commented "Local development baseline" block at the end of `.env.example` lists the rest:
`API_DOCS_ENABLED`, `LOG_PROFILE`, `LOG_LEVEL` and `CORS_ALLOWED_ORIGINS`. Leave `BASE_URL` at
`http://localhost:8000`: doctor warns about a development install whose `BASE_URL` is public.

## Start the server

```bash
$ php -S localhost:8000 -t public vendor/glueful/framework/router.php
```

Leave it running. The rest happens in the browser.

## Create the first admin

Open the setup link provision printed. The form asks for **Site name**, **Admin email** and
**Admin password**. The password needs at least 8 characters, a number, a lower-case letter, an
upper-case letter and a special character; it may not contain whitespace or `1234`. **Create
admin** submits the form and takes you to the sign-in page.

Setup is single-use. Once the first admin exists, the endpoint refuses every later attempt, and
the token is blanked from `.env`. Completing setup in the browser also records the origin you
used as `BASE_URL`.

From the terminal instead:

```bash
$ php glueful thallo:create-admin
```

It asks for the site name (default `Thallo`), the first admin email, the password (at least 8
characters) and the default locale (default `en`), then prints where to sign in. For a scripted
install, pass `--site-name`, `--admin-email`, `--admin-password` and `--locale`; with `-n` the
email and password options are required.

Either way, the account is given the `superuser` and `administrator` roles, and the install is
seeded with three content types: **Pages**, **Posts** and **Categories**.

## Sign in

Go to `http://localhost:8000/admin` and sign in with the email and password you set. You land on
**Home**, headed "Welcome" and your email, with the sidebar down the left: **Content** holding
Pages, Posts and Categories, then **Media**, **Extensions**, **Settings** and **Utilities**,
among others. That is a working install.

## Why BASE_URL matters

`BASE_URL` is the install's canonical public origin. Every absolute URL Thallo emits — media
URLs, canonical and OG tags, payment links — is built from it, never from the request's Host
header. (The sitemap and `robots.txt` read `PUBLIC_URL_BASE` instead; see
[SEO](../guides/10-seo.md).) Unset or localhost counts as unconfigured: that is safe, but canonical
and OG URLs are then left out. Plain HTTP is fine for local development; minting payment links
requires an HTTPS origin with no non-default port. Set it to the real origin before the site is
public, and set `APP_ENV=production` with it.

## Next

[Build your first page](03-first-page.md).
