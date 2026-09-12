# Changelog

All notable changes to Thallo are documented here. Format:
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/); versioning:
[SemVer](https://semver.org/spec/v2.0.0.html). Release tags are immutable — corrections ship
as the next release, never a mutated tag.

## [Unreleased]

### Added
- **The update notice** (charter decision 11). Once a day the install asks Packagist's public
  metadata for the newest published `glueful/thallo-core` it may move to — a plain GET, no
  install identifier — and keeps the answer in the system flags. Administrators with
  `system.access` see a dismissible card on Home and an **Update** badge on Utilities → Health,
  both with the release notes link and `composer update && php glueful thallo:provision`; the
  Health page shows the installed and newest versions; `GET /v1/admin/update-status` serves the
  same to the API. A pre-release install is offered newer pre-releases and stable, a stable
  install only stable; the notice clears the moment the upgrade has run. `UPDATE_CHECK_ENABLED=false`
  turns it off; `php glueful thallo:update:check [--force]` shows it on the command line. Never an
  updater: Composer runs as the deploy user, not under the web worker.

### Upgrade Notes
- **One scheduler cron entry is required:** `* * * * * php /path/to/site/glueful queue:scheduler run`.
  It evaluates every job in `config/schedule.php` — scheduled publishing, the update check, the
  signup and domain-reverification sweeps. Earlier guides listed only `thallo:schedules:run`,
  which fires scheduled publishing alone, and called the sweeps automatic; they were not running
  on an install without this tick. Queue workers do not tick the scheduler.
- `thallo:provision` generates the API reference: `docs/openapi.json` and the `/api-docs` UI,
  from the install's live routes, refreshed on every provision (what `php glueful
  generate:openapi -f --ui` writes). An install from the template answered 404 at `/api-docs`
  before: the docs route serves those two files, and only the development repository had them.
- The install template ships the `thallo` launcher beside `glueful`: `./thallo setup`,
  `./thallo doctor`, `./thallo provision`, `./thallo create-admin`, and every other command
  passed through to the console.

### Changed
- `scripts/release-split` is idempotent and resumable: a local split tag that already names
  the split head is kept (an annotated tag is a new object each time it is written, which a
  mirror that holds it rejects), a mirror that already publishes the tag receives only `main`,
  a mirror publishing it at another commit is refused, and every mirror is pushed before the
  summary names what did not land. The runbook pushes the mirrors before the development
  repository's own tag.

## [1.0.0-beta.21] - 2026-09-12 — Developer Preview

Thallo becomes a Composer package. `composer create-project glueful/thallo` installs a thin
template whose `vendor/` holds `glueful/thallo-core` and the thirteen capability packs, and
`composer update && php glueful thallo:provision` is every upgrade from here on. Existing
installs move once; their databases need nothing. Framework 1.85.3 required.

### Upgrade Notes
- **Installs created before this release move once to the template.** `create-project` beside
  the old site, carry `.env`, `storage/`, theme overrides and any code of your own across,
  provision, switch the document root. The database needs nothing: Thallo's migrations were
  recorded under `app` / `app:dependent` and are adopted under the core package's lanes
  (framework 1.85 `previous_sources`) — nothing re-runs, nothing looks pending. Exact steps in
  `docs/upgrading.md`.
- Customisations made inside Thallo's own files under a previous release's `app/`, `routes/` or
  `database/migrations/` are not carried by an upgrade; those directories are now yours and
  start empty, so re-apply such changes as overrides in `config/` and your own files there.
- Framework 1.85.3 is required (repinned): `previous_sources` on migration descriptors, its
  `migrate:run` adoption fix, `env()` reading the real process environment (a CI job or
  container that exports `DB_*` no longer sends a fresh install's first connections to sqlite),
  and the boot environment read the same way, with the extension cache stamped for the
  environment it was compiled under.
- The bootstrap passes `env('APP_ENV', 'development')` to the framework instead of the `$_ENV`
  array alone, so an `APP_ENV` the process exports is honoured under PHP's default
  `variables_order`.

### Changed
- **Thallo is a Composer package.** The application — `core/` in the development repository,
  namespace `Thallo\Core` — is published as `glueful/thallo-core`, a library with a Glueful
  manifest declaring its provider and its two migration lanes; the packs are published at the
  same version and pinned to it. The template (`skeleton/`) ships only the operator's tree:
  entry points, config overrides, `app/`, `routes/`, `database/migrations/`, `themes/`,
  `storage/`. Thallo loads its routes, migrations, config defaults and the admin bundle from
  `vendor/glueful/thallo-core`; `thallo:provision` publishes the bundle into `public/admin` so
  the web server keeps serving it from disk.
- **The release is fifteen artifacts.** `scripts/release-split` subtree-splits the core, the
  template and the packs to read-only mirror repositories and tags them together;
  `scripts/verify-dist-archive` checks every artifact from the release commit;
  `scripts/skeleton-smoke` installs the template against the local packages (also in CI).

### Added
- **`scripts/deploy-site`** — the website's deploy-from-tag: checks out this repository at a
  release tag (a complete, lock-pinned install) into a `releases/` + `shared/` + `current`
  layout with instant rollback; refuses branches and commits; `--dry-run` prints every step.

## [1.0.0-beta.20] - 2026-09-11 — Developer Preview

The framework's API reference moves to `/api-docs`, freeing `/docs` for the site's own
documentation, and the admin's API Reference link works again. No schema changes; beta.19
installs upgrade in place.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes.
- Framework 1.84.0 is required (repinned). **The framework's API reference moved from `/docs`
  to `/api-docs`** (`API_DOCS_PATH`), so `/docs` now belongs to the site — Thallo will deliver
  its own documentation there. The regenerated reference page ships in this release; links to
  `/docs/` for the API need updating, or set `API_DOCS_PATH=/docs`.

### Fixed
- **The admin's "API Reference" link works.** It pointed at a hardcoded (and misspelled) host;
  it now opens the running site's API reference at the configured path, delivered through
  `/admin/config` as `apiDocsPath`.
- The render pack reserves `/api-docs` from page slugs and no longer needs `/docs`.

## [1.0.0-beta.19] - 2026-09-11 — Developer Preview

The Site › Regions preview renders again in production, publishing confirms with one toast,
and the review divider no longer dangles for direct publishers. No schema changes; beta.18
installs upgrade in place.

### Upgrade Notes
- The documented sequence applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes.
- Framework 1.83.4 is required (repinned): the regions preview iframe was blocked by the
  admin document's Content Security Policy (no `frame-src`, so a `blob:` preview document was
  refused); 1.83.4 allows a mounted SPA to frame itself and its own blobs.

### Fixed
- **Site › Regions preview renders again.** The header/footer preview showed nothing in
  production — see the framework note above; no Thallo code changed.
- **One toast per publish.** Publish/Update in the editor and the design canvas now reports a
  single "Published" (or "Updated") toast; the draft and route saves it performs stay silent,
  while their failures still report. Save draft on its own still confirms.
- **No stray divider under Unpublish.** The line between the publishing controls and the
  Review section now belongs to the Review section, so it disappears with it (a direct
  publisher on a bare draft saw an empty rule).

## [1.0.0-beta.18] - 2026-09-11 — Developer Preview

Block-type icons render again, a pack's starter blocks arrive the moment its capability is
switched on and leave the listing when it is switched off, publishing saves the page's route,
direct publishers no longer see a review prompt, and Settings › Block types is searchable. No
schema changes; beta.17 installs upgrade in place.

### Upgrade Notes
- The documented sequence still applies (docs/upgrading.md): `composer update`, then
  `php glueful thallo:provision`, then reload PHP-FPM so OPcache drops the previous release's
  classes. Provision heals any starter block type the instance lacks; nothing else is required.
- Switching Commerce or Accounts on now seeds that pack's block types on the next request;
  switching it off hides them (rows kept). No command either way on a single-store install.
- Pack authors: `StarterBlockTypeDefinition` gained an optional `requiresCapability` argument.
  A contribution that sets it is seeded only while that capability is on and hidden while it
  is off; contributors should now be registered unconditionally and rely on that field.

### Added
- **Search on Settings › Block types.** A search box filters the cards by label, slug or
  description as you type; an empty result says what was searched for.

### Fixed
- **The admin ships the whole lucide icon set.** The block picker, block cards and Settings ›
  Block types showed blank icons for 20 starter block types (accordion, blog posts, call to
  action, …): icon names on block types are data — seeded from PHP or chosen in the icon
  picker — and reach the admin through the API, so the build's source scan could never see
  them and the browser fell back to api.iconify.design, which the CSP blocks. The build now
  embeds every lucide icon (~90KB gzipped, cached with the bundle), and the release gate
  requires it.
- **Publish saves the route.** The editor's Publish/Update button now saves the slug shown in
  the Publishing panel (the title suggestion on a new page, or an edit) before publishing, so
  a page never goes live without a URL; a failed route save stops the publish.
- **No "Submit for review" for direct publishers.** The review overview reports whether the
  requesting user holds `workflow.bypass` (`can_bypass`); the Review section hides for them
  while nothing is in review, and never offers Submit — reviewer actions on a submission are
  unchanged.

### Changed
- **A pack's starter blocks seed themselves when its capability turns on.** The first request
  after Commerce or Accounts is switched on creates that pack's missing block types — no
  `thallo:provision` or `thallo:blocks:seed` run. Existing rows are never touched. A system flag
  records which capabilities were seeded, so it happens once per switch-on. Single-store only:
  with workspaces on, `thallo:blocks:seed --all` / `thallo:tenant:sync --kind=block_type` remain
  the per-workspace path.
- **A disabled pack's block types leave the listing, not the table.** Settings › Block types and
  the block picker omit Commerce (and Accounts) block types while the capability is off; their
  rows and any content using them are kept, and they reappear when it is on again. Starter
  block-type definitions carry the capability that gates them
  (`StarterBlockTypeDefinition::$requiresCapability`, new optional field), and packs now declare
  their contributions unconditionally — the app applies the switch, seeding a gated definition
  only while its capability is on.

## [1.0.0-beta.17] - 2026-09-09 — Developer Preview

A fresh install gets the whole starter block library, a refused homepage says why, the
production container is compiled once instead of on every request, and the docs carry the
web-server block that makes PHP-served assets work. No schema changes; beta.16 installs upgrade
in place.

### Upgrade Notes
- **Run `php glueful thallo:provision` once after updating, then reload PHP-FPM.** Provision
  seeds the starter block types a beta.16 install is missing (30 of 46) and grants the install
  roles any new permission; the FPM reload drops the previous release's OPcache copies. This is
  now the documented upgrade sequence (docs/upgrading.md).
- Framework 1.83.3 is required (repinned).

### Changed
- **Framework 1.83.3.** The production container is compiled once, atomically, under a name
  signed by its definitions — no more per-request rewrites of `CompiledContainer.runtime.php`,
  half-written files falling back to the runtime container, or stale OPcache copies surviving a
  deploy. The old runtime artifact is pruned on the first boot.

### Fixed
- **A fresh install gets the whole starter block library.** Setup seeded content types, settings
  and regions but not block types, so an instance had only the 16 slugs migration 021 (re)seeded
  — no rich text, hero, image, heading, CTA, gallery, video, pricing, HTML … — until someone ran
  `thallo:blocks:seed`, which nothing mentioned. Setup now seeds the full library (fixed set plus
  pack contributions), and `thallo:provision` seeds any starter block type an installed
  single-store instance lacks, never touching existing rows — so beta.16 installs are completed
  by the next provision run, and a starter added in a later release lands on upgrade.
- **"Set as homepage" says why it refused, and no longer offers what it would refuse.** The
  homepage check needs a published locale AND a saved route (slug); the Pages list shows only
  the first, so a page reading "published" could still be turned down with "must be a published
  entry of a publicly delivered content type" and nothing else. The 422 now names the failing
  condition ("published in locale "en" but has no route yet — save a slug in the Publishing
  panel", "not published in locale "en"", "content type "category" is not publicly delivered"),
  and the editor's house button stays disabled with a matching tooltip until both hold.

## [1.0.0-beta.16] - 2026-09-09 — Developer Preview

The first admin can publish: a refused publish explains itself, empty required fields are marked
where they are, a bypass holder is never trapped by their own submission, and the designer's
support assets live under the one proxied prefix pair. No schema changes; beta.15 installs
upgrade in place.

### Upgrade Notes
- **Web server:** every PHP-served asset now sits under `/theme-assets/*` or `/_thallo/*`. If
  your vhost serves `.css`/`.js`/`.woff2` from disk, the location rule for those two prefixes
  must sit above that rule (docs/production.md); `php glueful thallo:provision` now warns
  (`asset-routing`) when it does not.
- Seeded Pages/Posts on existing installs keep a required `body`; make it optional on the
  content type in the admin if you want the fresh-install behaviour.

### Fixed
- **Canvas preview assets are served under `/_thallo/`.** The preview injected `/_preview.css`
  and `/_preview-bridge.js` at the site root; a web-server rule that serves every `.css`/`.js`
  URL from disk answered 404 even on a host that had proxied the documented prefixes, so the
  designer loaded unstyled and without its bridge. Every PHP-served asset now lives under the
  documented `/theme-assets/*` + `/_thallo/*` pair.
- **`thallo:provision` and `thallo:doctor` warn when the web server eats PHP-served assets.**
  A new `asset-routing` check probes one theme asset on a public `BASE_URL`; a 404 names the
  misconfiguration and the docs row that fixes it, instead of an unstyled site being the first sign.
- **A bypass holder may approve their own submission.** The self-review rule protects nothing
  against someone who can publish directly; applying it to them only trapped an admin who had
  submitted their own page.
- **A refused publish says why.** A review-gated publish now reads "Needs a review before
  publishing" with the next step, and a forbidden one names `content.publish`, instead of a
  bare "Couldn't publish".
- **Required-field misses are marked inline.** A 422 on save now highlights each failing field
  under the editor ("body is required") instead of a toast that read as a failed save.
- **No commerce request on installs without Commerce.** The entry editor's commerce panel gate
  fetched `/v1/admin/commerce/meta` on every entry page regardless of the capability (gate
  hooks run before the capability filter); the query now stays idle while `thallo.commerce` is off.

### Changed
- **Seeded Pages and Posts no longer require a body.** A page must be publishable with a title
  alone (a landing page composed in the designer, a placeholder); the first-run Publish must
  not 422. Existing installs keep the schema they were seeded with — make `body` optional on the
  content type in the admin if you want the same.
- **glueful/audit 1.4.1.** The audit log lists newest first even for rows that share a second
  (a login, a first-run setup burst): the insertion id now breaks `occurred_at` ties.

## [1.0.0-beta.15] - 2026-09-08 — Developer Preview

A fresh install works end to end: `thallo:provision` from the sample `.env` with real credentials
typed at the prompt migrates, grants the install roles the whole catalog, and hands off to the
setup link; the first admin can administer everything. Proven by provisioning a clean database
from the archive. No schema changes; beta.14 installs upgrade in place.

### Upgrade Notes
- **beta.14 installs: run `php glueful thallo:provision` once after updating.** beta.14's grant
  step skipped itself on the server; this one applies the grants.
- Framework 1.83.2 is required (repinned).

### Changed
- **Framework 1.83.2.** The Installer publishes freshly written database credentials to the
  provisioning process, so third-party migrations that open their own connection (Aegis's role
  seed) see the real database on a fresh install.

### Fixed
- **Pack permission seeds migrate the database they are handed.** Seven seed migrations opened
  their own `new Connection()`, which reads the live environment — on a fresh `create-project`
  that is the sample's placeholder user, and provision failed at migrate with "role
  your_database_user does not exist" whenever the real credentials were typed at the prompt.
  They now use `$schema->getConnection()`, and a unit test refuses any migration that opens its
  own connection. Pairs with framework 1.83.2, which also publishes the written credentials to
  the provisioning process for third-party migrations (Aegis's role seed).
- **`thallo:provision` grants the install roles on a fresh install.** Aegis decides at boot
  whether to activate its permission provider (the RBAC tables must already exist) and provision
  runs the migrations that create them in the same process, so the grant step found no active
  provider and printed "Install role grants skipped (No persistent RBAC provider …)". The grantor
  now activates the provider itself, the way the extension's boot would once the tables exist.

## [1.0.0-beta.14] - 2026-09-08 — Developer Preview

The first admin can actually administer: the install roles now hold the whole permission
catalog, every admin icon ships inside the bundle, the health report names its findings, and a
fresh install's switchboard and sample `.env` describe what is really on. No schema changes;
beta.13 installs upgrade in place.

### Upgrade Notes
- **Existing installs: run `php glueful thallo:provision` once after updating.** It grants the
  install roles the full catalog (the 403s on form submissions, the audit log and analytics for
  the first admin) and rebuilds the caches. `thallo:create-admin` and the web setup do the same
  for new installs.
- **`.env` copied from an earlier sample:** set `API_USE_PREFIX=false` (Thallo mounts everything
  under `/v1`; the old sample's `/api` prefix left the login route unreachable), then
  `php glueful route:cache:clear`.
- Framework 1.83.1 is required (repinned).

### Fixed
- **Every admin icon is embedded; none is fetched from api.iconify.design.** beta.13 bundled
  the icons named in `.vue` files but the scan's default globs skip `.ts`, so the 28 icons
  named only in the module registries (`src/registry/*.ts`: analytics, code-xml, settings,
  wrench …) were still requested from the Iconify API at runtime and blocked by the admin's
  `connect-src 'self'` policy — blank icons in production. The scan now covers `.ts`, and
  `scripts/verify-dist-archive` refuses a release whose baked bundle lacks any referenced icon.
- **The first admin really has full access.** Aegis seeds the install roles with its own 15
  permissions only; Thallo's packs seed theirs by migration and Thallo's core catalog
  (`content.manage`, `content.publish`, `content.routes`, `tenant.*.manage`, `billing.manage`)
  was never persisted at all — so the superuser could not manage content models, triage form
  submissions, read the audit log or see analytics (403 on every one of them). The provider now
  declares the catalog to the framework's permission registry, and web setup, `thallo:create-admin`
  and `thallo:provision` run `InstallRoleGrants`: persist the catalog, then grant `superuser`
  every permission and `administrator` everything but `system.config`. Additive and idempotent;
  re-running provision on an existing install heals it.
- **The dashboard's first-run card asks for a page, not a "categorie".** The picker looked for
  slug `page` (the seed is `pages`) and fell through to the first type alphabetically; the
  singular was made by chopping a trailing "s". It now prefers Pages, then Posts, then any
  non-taxonomy type, and singularizes properly (Categories → Category).

### Changed
- **Sample `.env`.** `API_USE_PREFIX=false` so framework routes (login, blobs) sit under `/v1`
  like everything else; `CSP_HEADER` ships as a permissive policy in report-only mode
  (`CSP_REPORT_ONLY=true`), so nothing is blocked and the production recommendation is quiet;
  the users lookup/list endpoints are on.
- **Framework 1.83.1.** Production recommendations are logged once per boot cache instead of
  on every request, and a recommendation no longer degrades the config health check — so a
  thallo.dev-style host with an empty `CSP_HEADER` stops filling the error log and reports
  `ok` health.
- **The admin health report says what is wrong.** `GET /v1/admin/health` flattened every
  framework check to name/status/message, so "Configuration warnings detected" reached the
  operator with no way to learn which setting. Each check now carries its `issues`, `warnings`
  and `recommendations` lists when the framework provides them, and the Health page lists them
  under the check. Pairs with framework 1.83.2, where a recommendation no longer degrades the
  check's status.
- **An untouched capability switch follows its engine.** The switchboard defaulted every
  capability to requested, so a fresh install showed Commerce and Multi-tenancy switched on
  with a "Requested · engine unavailable" warning — on-looking rows for features that are not
  active. With no explicit answer (no stored row, no `thallo.capabilities` config entry) a
  capability is now requested only while its owning engine is available: tier-2 packs read
  plainly Off until the extension is enabled from the extensions browser, and the tenancy
  switch reads Off until the Workspaces flow enables enforcement. An explicit switchboard
  choice still outranks the engine. Existing installs that never touched a switch see the same
  rows as a fresh install.
- **"Storefront accounts" is now "Accounts"** in the capabilities switchboard, described as the
  site's visitor accounts — it is not a commerce feature.

## [1.0.0-beta.13] - 2026-09-08 — Developer Preview

Admin icons ship inside the bundle, and the browser first-run works on a production host:
provision prints a one-time setup link. No schema or API changes beyond the setup gate's
messages; beta.12 installs upgrade in place.

### Changed
- **The browser first-run is a link.** Provision prints `<BASE_URL>/admin/setup?st=<SETUP_TOKEN>`;
  the setup page reads the token once, drops it from the address bar, and sends it back as the
  `X-Setup-Token` header the production gate requires. A completed setup blanks `SETUP_TOKEN` in
  `.env`, so the link is single-use on top of the endpoint's own 409 lock. Re-running provision
  prints the link again; the production 403 says so. Local zero-config setup (no token, not
  production) is unchanged.

### Fixed
- **Admin icons are embedded in the build instead of fetched from the Iconify API.** The Vite
  plugin's `icon.clientBundle.scan` only embeds icons from an INSTALLED collection, and the admin
  had none, so every icon was resolved at runtime from `api.iconify.design` — which the admin's
  document Content-Security-Policy (`connect-src 'self'`, framework 1.82.2) now blocks, leaving
  icons blank. `@iconify-json/lucide` is a dev dependency; the scan bundles the lucide icons the
  admin uses and the runtime fetch is no longer attempted.

### Removed
- **`CSP_HEADER` is gone from `.env.example`.** Framework 1.83.0 (repinned here) makes the
  variable real: a non-empty value is sent verbatim as `Content-Security-Policy` on every
  response that does not set its own. Thallo's rendered site is not written for a blanket policy
  (inline colour-mode resolver, theme assets, headless media), so the sample no longer suggests
  one. Operators who want a CSP can still set `CSP_HEADER` — the admin's own document policy
  keeps precedence — and audit it first with `CSP_REPORT_ONLY=true`.

## [1.0.0-beta.12] - 2026-09-08 — Developer Preview

First-run and admin housekeeping on beta.11: provision mints `SETUP_TOKEN`, and the admin moves
to Nuxt UI 4.11. No schema or API changes; beta.11 installs upgrade in place.

### Changed
- **Admin: Nuxt UI 4.11.1.** The Vite plugin's `icon` option is typed correctly upstream, so the
  local cast is gone. The switch component's render tree changed; the admin tests that drive
  switches now resolve them through the rendered `<button role="switch">`.
- **`SETUP_TOKEN` is minted by provision and listed in `.env.example`.** The unauthenticated
  first-run `POST /admin/setup` is gated by it in production (sent as the `X-Setup-Token`
  header); until now nothing generated or documented it, so a production host answered
  "First-run setup is disabled" with no hint where the value came from. Provision now mints it
  exactly like `APP_KEY`/`JWT_KEY`/`TOKEN_SALT` (only when empty, never overwritten) and prints
  it at the end.

## [1.0.0-beta.11] - 2026-09-07 — Developer Preview

A lock-only release on beta.10: framework 1.82.3 serves the admin's HTML document with a
Content-Security-Policy a built front-end can run under, and the `php -S` quickstart serves
admin deep links. No Thallo code, schema, API, or admin changes; beta.10 installs upgrade in
place.

### Changed
- `glueful/framework` 1.82.3 in the lock:
  - (1.82.3) **The `php -S … router.php` quickstart serves admin deep links.** With the admin
    bundle at `public/admin/index.html`, PHP's built-in server resolved `/admin/setup` to that
    directory index and Symfony stripped `/admin` as a base path, so every admin deep link or
    reload 404'd locally (nginx/Apache were unaffected). The router script now presents the
    front controller the way a real web server does.
  - (1.82.2) The SPA mount controller applied the static-asset
  header set — `style-src 'self'`, no inline allowance — to `index.html` too, so the admin's
  runtime-injected styles were blocked in every environment where PHP serves the bundle: the
  primary button on the setup screen rendered with no background. `index.html` now carries a
  document policy (inline styles allowed, `data:`/`blob:` images, scripts still self-only);
  assets keep the strict policy. Surfaced on thallo.dev's `/admin/setup`.

## [1.0.0-beta.10] - 2026-09-07 — Developer Preview

A small follow-up to beta.9 from the first thallo.dev walkthrough: provision hands off to the
browser setup screen, and the production checklist covers the web-server rule that otherwise
leaves the rendered site unstyled. No schema, API, or admin changes; beta.9 installs upgrade
in place.

### Changed
- **Provision ends by naming both ways to create the first admin**, browser first:
  `<BASE_URL>/admin/setup` (recommended) and `php glueful thallo:create-admin`. The README
  quickstart says the same. Surfaced by dogfooding: the old one-liner only mentioned the CLI.
- **Production checklist: PHP-served asset paths must reach PHP.** `/theme-assets/*` and
  `/_thallo/runtime/*` are served by Thallo, not from disk; a web-server rule that answers every
  `.css`/`.js`/`.woff2` URL straight from the document root (CloudPanel's template does) turns
  them into 404s and every rendered page loads unstyled. `docs/production.md` now carries the
  required row with the nginx location to add above the static-file rule.

## [1.0.0-beta.9] - 2026-09-07 — Developer Preview

Framework 1.82.1 makes the compiled container real and lets a never-installed production
checkout boot quietly; Thallo's two boot-time container re-pins now guard on the framework's new
`RebindableContainer` interface so they reach that compiled container. No schema, API, or admin
changes; beta.8 installs upgrade in place.

### Changed
- **Boot-time re-pins reach the compiled container.** The subscriptions pre-engine seam and the
  commerce payment-link seams re-bind services on the built container from `boot()`; both
  guarded on the concrete runtime `Container` class, which production's compiled container is
  not. With compilation now succeeding they would have silently no-op'd — exactly what
  `SubjectResolverCompiledContainerGateTest` was written to catch, and it did. The guards target
  `Glueful\Container\RebindableContainer` (framework ≥ 1.82.1) and the gate test now asserts
  the production contract directly: build the compiled container, run the re-pin, resolve.
- `glueful/framework` 1.82.1 in the lock:
  - **The compiled container actually engages in production.** Every production boot used to
    log `[Container][WARNING] container compilation failed` and run the runtime container;
    static factories, closure factories and the live `ApplicationContext` now all compile or
    hydrate, and the artifact lives in `storage/cache/container/`.
  - **A fresh production checkout is quiet before provision.** Until the security keys exist,
    the framework skips its boot-time security validation and resolves extensions live once,
    writing the cache — so the `composer create-project` hook and the first `php glueful`
    call print no warnings and no "Extension cache missing" failure. The remaining
    pre-provision line, Aegis' "RBAC tables not found", is handled by Aegis 1.16.0 below.
  - The "FORCE_HTTPS not enabled" recommendation no longer fires on production hosts that
    leave it unset (unset = enabled).
  - Compiled autowiring mirrors the runtime autowirer for optional dependencies and for
    object defaults built in the initializer (1.82.1), and compiled containers accept
    boot-time `load()` re-pins through `RebindableContainer`.
- **A fresh production checkout prints nothing before provision.** Two Thallo lines the
  quiet framework boot exposed are gone: the commerce pack no longer declares webhook
  settlement "DEAD" on installs where Payvia is not active (tier 2 is off by default and
  payments degrade to manual collection by design — it now checks for Payvia's own services,
  not merely its classes), and `thallo:payments:migrate-platform-credentials` no longer
  resolves its encryption-backed collaborators at construction, so the console can register it
  before `APP_KEY` exists.
- `glueful/aegis` 1.16.0 in the lock: the boot-time "RBAC tables not found" warning is silent
  before first run (no security keys yet) and unchanged once installed. With it, a fresh
  production checkout prints nothing at all before `thallo:provision`.

### Upgrade Notes
- **Production now runs the compiled container.** If anything behaves differently only in
  production, set `APP_DEBUG=true` to compare against the runtime container and report it.
  Delete `storage/cache/container/` to force a fresh compile.

## [1.0.0-beta.8] - 2026-09-07 — Developer Preview

A lock-only release on beta.7: framework 1.81.2 stops a stale, host-shared command manifest
from breaking every production boot. No Thallo code, schema, API, or admin changes; beta.7
installs upgrade in place.

### Changed
- `glueful/framework` 1.81.2 in the lock: the production console command manifest now lives
  in the app's `storage/cache` and is re-validated on load. Before, every host shared one
  `/tmp/glueful_commands_manifest.php` and trusted it verbatim, so a manifest left by an older
  framework on the same VPS fed a phantom command class into every production boot — container
  compilation failed and the console threw a 500 before `thallo:provision` could run. Surfaced
  on thallo.dev's server, which once ran a pre-1.41 framework.

### Upgrade Notes
- **If a host ever showed `Cannot compile autowire definition for unknown class` at boot**:
  after `composer update`, run `php glueful commands:clear` once to delete the old shared
  temp manifest, or simply delete `/tmp/glueful_commands_manifest.php` as root.

## [1.0.0-beta.7] - 2026-09-07 — Developer Preview

Hotfix on beta.6: the production mode beta.6 made the default could not provision a fresh
install. Three defects in Thallo and one in the framework, all surfaced by the first
production-mode deploy of thallo.dev and each pinned by a test; a fresh install from the dist
archive now provisions in production mode end to end. No schema, API, or admin changes.

### Fixed
- **A fresh install boots in production mode** — three defects the first production-mode
  provision on thallo.dev surfaced, all fixed and pinned by tests:
  - The app provider used two closure factories. The compiled container refuses closures and
    skips the WHOLE provider, so the capability registry vanished, every pack failed to boot,
    and no `thallo:*` command existed. Both are static factories now, and an architecture test
    forbids closure factories in every Thallo provider.
  - Production boot needs the compiled extension cache, which a fresh checkout lacks.
    `composer create-project` now builds it right after copying `.env`, and `thallo:provision`
    rebuilds it after migrating.
  - `thallo:doctor`, `thallo:provision` and `thallo:create-admin` register in the provider's
    register() phase, not boot(): boot needs a reachable database, and a production boot failure
    is logged and skipped, which silently removed the very commands that diagnose it. The boot
    also no longer dies when the tenancy flag cannot be read pre-provision.
- `glueful/framework` 1.81.1 in the lock: providers loaded from the extension cache now get
  `register()` called. Without it the first-run commands above never existed in production,
  because production boots from that cache.


### Upgrade Notes
- **beta.6 installs that never completed first run**: update to beta.7 and run
  `php glueful thallo:provision` again — it now builds the extension cache itself.
- **Installs running in production already**: `composer update` then
  `php glueful extensions:cache`, because the framework 1.81.1 fix changes what the cached
  boot registers.

## [1.0.0-beta.6] - 2026-09-06 — Developer Preview

A first-run polish release on beta.5: `thallo:provision` recognises a hand-filled `.env` and
asks for one confirmation instead of seven answers, and `.env.example` ships in production
mode. No schema, API, or admin changes; beta.5 installs upgrade in place.

> **Known issue — fixed in beta.7.** A FRESH beta.6 install cannot complete its first run in
> the new default production mode (`thallo:provision` reports no `thallo` commands). Install
> beta.7, or set `APP_ENV=development` in `.env` for the first run. Existing installs upgraded
> in place are unaffected.

### Changed
- **`thallo:provision` confirms a pre-filled `.env` instead of re-asking**: when `.env` already
  holds real `DB_PGSQL_*` values (non-empty database and user, none of them the `.env.example`
  placeholders), the interactive run shows the settings — password masked — and asks one
  question. "No" walks the usual prompts with those values prefilled, and an empty password
  answer keeps the stored one. A placeholder or empty `.env` gets the plain prompts as before;
  `-n` is unchanged. Surfaced by dogfooding: with credentials written by hand, seven prompts
  after three boot warnings read like the command had stopped.
- **`.env.example` ships in production mode.** Thallo is installed to be deployed, so the
  template now defaults to `APP_ENV=production`, `APP_DEBUG=false`, API docs off, HTTPS
  enforcement on, production logging, and no CORS origins (the admin is same-origin). The
  commented block at the end of the file is the local-development baseline, and the README
  quickstart says to apply it before starting the built-in server. `thallo:doctor` now warns
  when a public `BASE_URL` runs in development mode.
### Upgrade Notes
- **Existing `.env` files are untouched** — this only changes what a fresh copy of
  `.env.example` contains. Installs that copied the previous template and never changed
  `APP_ENV` are running in development mode on their public host; set `APP_ENV=production`
  and `APP_DEBUG=false` (or run `php glueful system:production`), then clear the compiled
  container and run `php glueful extensions:cache` — production boot refuses to start
  without that cache.

## [1.0.0-beta.5] - 2026-09-06 — Developer Preview

A maintenance release on beta.4: framework 1.81.0, whose boot profiler no longer aborts boot
on hosts where `/tmp/boot_profile.log` belongs to another OS user — the defect that stopped
`thallo:provision` on the first thallo.dev deploy. No schema, API, or admin changes; beta.4
installs upgrade in place.

### Changed
- `glueful/framework` 1.81.0 in the lock: the framework's boot profiler no longer writes a
  hard-coded `/tmp/boot_profile.log` on every boot. On a host where another OS user had
  created that file first (a second site, or a root CLI run followed by the site user), the
  denied write became a fatal `ErrorException` and no command — `thallo:provision`
  included — could boot. The dump is now opt-in via `BOOT_PROFILE_LOG` and best-effort.
  Surfaced by dogfooding thallo.dev on CloudPanel.
- `.env.example` leads with PostgreSQL: the database block named SQLite as the no-setup
  default and listed PostgreSQL as an alternative, while the effective values were already
  PostgreSQL. SQLite and MySQL are now commented blocks marked unsupported, matching
  `docs/limitations.md`.

## [1.0.0-beta.4] - 2026-09-06 — Developer Preview

A maintenance release on beta.3: the framework lock moves to 1.80.2 so `migrate:verify`
never misclassifies an untouched migration source on a healthy install. No schema, API, or
admin changes; beta.3 installs upgrade in place.

### Changed
- `glueful/framework` 1.80.2 in the lock: an untouched migration source (a disabled engine's
  schema on a fresh install) classifies `pending`, never `divergent`, so `migrate:verify`
  exits 0 on healthy installs. Beta.3 artifacts lock 1.80.1 but never hit the defect — the
  first-run sequence doesn't run verify, and the upgrade chain's `composer update` pulls the
  fix before verify executes.

## [1.0.0-beta.3] - 2026-08-18 — Developer Preview

The schema-on-enable release: schema exists exactly when the feature that owns it is
provisioned or enabled — never as a side effect of boot — and every migration operation is
locked, truthful, and recorded.

### Changed — the schema-on-enable program

- **BREAKING — pre-beta.3 installs are not upgradable in place.** Developer Preview builds up
  to `1.0.0-beta.2` recorded pack migration receipts under pre-manifest ledger names
  (`thallo-*`, render's bare `migrations`); beta.3's ledger is canonical from provision and
  ships no migration path for those receipts. Re-provision, or rewrite the ledger `source`
  values by hand before upgrading (see [docs/upgrading.md](docs/upgrading.md)).
- **Fresh provision is ONE locked, failure-aware complete pass**: `thallo:provision` applies
  the app schema, every core pack descriptor (the eight schema-owning packs and the tenancy
  platform tier), and every shipped-enabled engine together under an all-source migration
  lock, and a failed migration fails provision naming the file — never a quiet success.
  Disabled engines (Commerce, Payvia) get their schema later through the executor's
  migrate-first enable, which is the point of the program. The create-admin catch-up pass now
  applies only the app's dependent-grants lane; its first-pass-ordering retry is obsolete
  (render's permission seed moved to the dependent tier with the other packs).
- **Extension toggling works in production, truthfully**: the admin SPA and CLI both drive the
  shared schema executor — migrate-first, lock-serialized, with a persisted operation record
  (id, terminal status, failed migration, error) surfaced through the API and UI. A stale
  provider cache is a warning on success; failures and manual-repair states are 409s carrying
  the record. The extensions list shows each package's schema state (ready/pending/divergent/
  none/undeclared) with reasons and the CLI equivalent; a divergent schema blocks the toggle.
- **Capabilities know their owning engine**: each engine-backed capability declares the
  Composer package whose activation defines it (accounts→glueful/users, commerce→
  glueful/commerce, importers→glueful/import-export, search→glueful/meilisearch,
  subscriptions→glueful/subscriptions, tenancy→glueful/tenancy). Effective capability state is
  now *requested AND available* — an engine that is missing, disabled, or schema-unready turns
  its capability off everywhere at once, with the reason and remedy named, instead of leaving
  a half-alive surface.
- **One system-scoped capability switchboard**: requested state lives in
  `capability.<id>.enabled` system rows with an operator-only management surface
  (`GET /v1/admin/capabilities/manage`, `PUT /v1/admin/capabilities/{id}`, and a Capabilities
  tab on the extensions page). Disable is always allowed; enable refuses while the owning
  engine cannot back it; the Settings › General search toggle now reads and writes through
  the same authority (its legacy `search_enabled` row is retired on first write).
- Dependency stack: `glueful/framework` `^1.80` (1.80.1 in the lock — complete provision,
  the protected migration lane, unconditional manifest enforcement) and the adopted extension
  minors (aegis ^1.15, audit ^1.4, commerce ^1.13, email-notification ^1.13, i18n ^1.2,
  import-export ^1.2, media ^1.2, meilisearch ^1.7, payvia ^2.8, subscriptions ^2.3,
  tenancy ^2.1, users ^2.4). Tenancy's enablement flow migrates through the executor's
  protected lane (`protected_migrate` operations) while keeping sole custody of the provider
  state write.

### Changed
- `glueful/framework` requirement raised to `^1.78.4`: application boot performs no schema
  work at all — migration discovery and registration are database-free, and only an actual
  `migrate` operation creates the migrations ledger. (Beta.2's framework fix covered the
  migrate commands; this closes the remaining boot path through extension providers.)

### Fixed
- **Provision accepts passwordless (trust/peer-auth) PostgreSQL**: `thallo:provision -n`
  refused any empty password, so the common local trust-auth setup could not pass validation
  at all. Password *presence* is now tracked separately from its value — `--db-password=""`
  or a present-but-empty `DB_PGSQL_PASSWORD=` line means "none" and validates; a fully absent
  password still refuses. The host now defaults to `localhost` only when absent (an
  explicitly empty host still fails), and the preflight connection test remains the real
  arbiter of the credentials.

## [1.0.0-beta.2] - 2026-08-16 — Developer Preview

Corrections from the beta.1 clean-machine artifact gate (tags are immutable — beta.1 stands
as published; install from beta.2).

### Fixed
- **Fresh installs could not run any console command**: the framework console connected to the
  `.env` database on boot, and the shipped `.env.example` pointed at a database name no
  quickstart ever created. Fixed on both sides: `.env.example` now names the quickstart
  database (`thallo`) and documents the credentials requirement, and `glueful/framework`
  1.78.3 resolves migration services lazily so the console works before the database does.
- **PostgreSQL table detection was privilege-blind**: a table owned by another role (e.g.
  created during a mis-credentialed first boot) surfaced as an inexplicable "Duplicate
  table" error. `glueful/framework` 1.78.3 reads `pg_catalog` instead of the
  privilege-filtered information schema.

### Changed
- `glueful/framework` requirement raised to `^1.78.3` (carries both fixes above).
- **Dependency advisories**: `league/commonmark` updated past its published advisories
  (2.8.3 → 2.10.0). The one remaining `composer audit` finding is a dev-only tool
  (`php_codesniffer`) that never ships in `--no-dev` installs.

## [1.0.0-beta.1] - 2026-08-15 — Developer Preview

The initial public release: a self-hosted, composable CMS and commerce platform for
developers, on the Glueful PHP framework with a Vue 3 admin.

### The platform

- **Content & rendering** — block-based pages and entries with revisioning, themeable
  server rendering with caching (+ edge purge), scheduled publish/unpublish, previews
  through the theme, navigation, SEO (canonical/OG heads, sitemaps), collections and term
  index pages, forms with spam guarding, media, i18n, import/export.
- **Commerce** (installed-but-disabled tier: enable from the admin) — catalog with variants
  and stock, carts and storefront checkout, walk-in draft orders finalized through a single
  atomic authority, printable invoices/receipts (A4 + thermal), refunds, marketplace
  seller machinery, and **payment links**: hash-custodied bearer URLs with a zero-third-party
  landing page, provider webhook settlement, and a session-exposure guard that blocks
  automatic cancellation while a live checkout session could still collect money.
- **Payments** (Payvia; installed-but-disabled) — Stripe + Paystack behind one fail-closed
  collector: ensure-live hosted sessions, reference-addressable attempts with durable
  idempotency, verify-first Paystack recovery, amount-revalidated session reuse,
  attribution-bound manual confirmation. Keyless installs degrade to manual collection.
- **Subscriptions** (bundled billing engine, enabled) — provider-agnostic hosted checkout
  with its own origination ledger and reconciliation; workspace SaaS billing.
- **Multi-workspace tenancy** — full lifecycle (enable → widen → confirm → finalize) managed
  in Settings → Workspaces; tenant purge/adoption with coherence probes.
- **Admin** — Vue 3 SPA (shipped prebuilt in release tags), capability-gated areas,
  extensions browser, audit log, analytics.

### Operational contract

The production obligations (cron entries, log redaction, key generation, gateway settings)
are documented per capability in `docs/production.md`; deliberate boundaries in
`docs/limitations.md`; the upgrade sequence — including the required compiled-state clear —
in `docs/upgrading.md`.

### Pre-release development

Thallo was built May–August 2026 through successive reviewed programs: the render/content
core and collections; forms; multi-tenancy (through `glueful/tenancy` 2.0.0); the commerce
slices (catalog → checkout → invoices/receipts → walk-in draft orders); payment links
(payvia 2.6.0 / commerce 1.11.0 / framework 1.78.0); a cross-repo hardening train
(payvia 2.7.0 / commerce 1.12.0 / framework 1.78.1 — attribution binding, settlement
idempotency, draft-artifact lifecycle); and the distribution posture split behind this
release. The complete engineering record is the git history and the extension changelogs
(`vendor/glueful/*/CHANGELOG.md`).
