# Composer-updatable Thallo: the package split and the update notice

Status: decided in principle (charter decisions 10 and 11, 2026-09-12); design draft, not
started. Owner: Michael Tawiah Sowah. Binds to `docs/internal/DISTRIBUTION.md`.

## Why

Dogfooding beta.20 on thallo.dev: `composer update` moved the framework to 1.84.0 and left
Thallo at beta.19. Thallo is installed with `create-project`, which makes it the ROOT package;
Composer manages only `vendor/`, and nothing ever rewrites the root. The published upgrade guide
(`docs/upgrading.md`) claimed `composer update` was the upgrade. It was not.

The goal is the WordPress expectation with Composer semantics: a site learns that a new Thallo
exists, and one documented command moves it there, keeping everything that is the operator's.

## Target shape

Two deliverables instead of one:

| | Today (`glueful/thallo`, type `project`) | After the split |
|---|---|---|
| `glueful/thallo` | everything | the **skeleton**: `public/index.php`, `bootstrap/app.php`, `glueful` CLI entry, `config/` (thin: env-driven values and overrides only), `.env.example`, `storage/`, `themes/` (operator overrides), `database/migrations/` (operator's own), `routes/` (operator's own, empty by default), `composer.json` requiring `glueful/thallo-core` |
| `glueful/thallo-core` (new, type `glueful-extension`) | — | the **product**: `app/` (`App\` → `Thallo\Core\`), admin routes, config defaults, migrations (app + dependent), the baked admin bundle, the 13 `packages/thallo-*` modules, the default theme (already in thallo-render), console commands, the starter library |

`composer create-project glueful/thallo my-site` installs the skeleton with `thallo-core` in
`vendor/`; `composer update && php glueful thallo:provision` upgrades the product. The skeleton
changes rarely (a new required env var, a bootstrap change) and its own upgrades are the
template re-pull operators expect from any framework skeleton.

## How the product runs from `vendor/`

Every mechanism already exists in the framework's extension seams; the app is today the only
Thallo code not using them.

- **Providers.** `thallo-core` declares `extra.glueful.provider` → `Thallo\Core\CoreServiceProvider`
  (today `App\Providers\ThalloServiceProvider`). The packs' providers stay listed by it; the
  skeleton's `config/serviceproviders.php` no longer names Thallo classes.
- **Routes.** `loadRoutesFrom(__DIR__ . '/../routes/admin.php')` etc. replace RouteManifest
  discovery of the app's `routes/`. The skeleton's `routes/` remains the operator's.
- **Migrations.** `loadMigrationsFrom()` for `database/migrations` and the DEPENDENT tier (the
  provider already does this for the dependent tier).
- **Config.** `mergeConfig('thallo', $defaults)` and per-pack merges; the skeleton's
  `config/thallo.php` holds only overrides. `config/documentation.php`, `config/app.php` stay
  skeleton files (they are the framework's shape), trimmed to env reads.
- **Admin bundle.** Baked into `thallo-core` at `resources/admin/`; `serveFrontend('/admin',
  <vendor path>)` — `thallo.admin.bundle_path` already exists for this.
- **Console commands, migrations tier, starter seeds, capability registrations:** unchanged
  code, new namespace.
- **Packs.** Bundled inside `thallo-core` (`packages/thallo-*` with their PSR-4 roots listed in
  the core's own `autoload`), not published separately — Packagist forbids path repositories,
  and charter decision 7 keeps the packs unpublished. Decision 7 is amended: "path-local"
  becomes "inside thallo-core". A pack graduates to its own package only when another
  application consumes it, as before.
- **Namespace.** `App\` is the skeleton's (the operator's own code, empty by default);
  Thallo's code moves to `Thallo\Core\`. Every `App\` reference in packs, tests, config and the
  admin's typed clients is updated; the OpenAPI document regenerated.

## What stays the operator's

`.env`, `storage/` (uploads, cache, logs), the database, `themes/{name}/` overrides,
`routes/`, `database/migrations/` (their own), `app/` (their own), and `config/` overrides.
An upgrade must never touch these. This list is the contract the clean-machine gate checks.

## Repository shape

One repo (decision 1) with two published packages, released together:

- `glueful/thallo-core` is built from the repo by subtree split of `core/` (or the repo root
  restructured so `core/` IS the package); `glueful/thallo` from `skeleton/`. Both tags carry
  the same version (`v1.0.0-beta.N`), and the skeleton pins `"glueful/thallo-core":
  "^1.0.0-beta.N"` — updated by the release script, not by hand.
- The release bake writes the admin bundle into `core/resources/admin/`; `verify-dist-archive`
  checks the CORE archive for the bundle and the icon set, and the SKELETON archive for the
  operator-facing files only.
- Tests: the dogfood suite runs against the repo (core + a skeleton-shaped test app), as now;
  the distribution smoke installs the skeleton from the built core.

## Migration for existing installs (beta.20 and earlier)

One-time, documented in `docs/upgrading.md` for beta.21 → first split release:
`create-project` the new skeleton beside the old install, copy `.env`, `storage/`, `themes/`
and any operator `app/`/`routes/` code, run `composer install`, `thallo:provision` (migrations
are idempotent; the schema is the same), switch the vhost. The old install is kept until the
new one serves. This is also the documented path TODAY from beta.19 to beta.20 (a git checkout
of the tag, or a fresh `create-project` with `.env` and `storage/` carried across), so the
guide is rewritten in beta.21 regardless of when the split lands.

## Phases

1. **Docs tell the truth (beta.21).** `docs/upgrading.md`: the tag-checkout / fresh-install
   path, what to carry across, compiled-container and route-cache clearing, FPM reload. Add
   `scripts/deploy-site` (website plan phase 3), which is the same flow scripted.
2. **Namespace and layout move.** `App\` → `Thallo\Core\`, `app/` → `core/src/` (or
   `core/app/`), routes/migrations/config/bundle loaded through provider seams while still one
   root package. Suite green, thallo.dev deployed from it. No published change yet.
3. **Publish the split.** `core/composer.json` as `glueful/thallo-core`; skeleton trimmed;
   release script builds and tags both; distribution smoke installs the skeleton from the core
   and upgrades it across two versions; `docs/upgrading.md` becomes `composer update &&
   php glueful thallo:provision`. Clean-machine gate: install AND upgrade.
4. **Update notice (decision 11).** Scheduled daily check of Packagist's public API for
   `glueful/thallo-core` (jitter; silent on failure; `UPDATE_CHECK_ENABLED=false` opts out);
   result in system flags; `/admin/config` gains `update: {current, latest, notesUrl}`;
   administrators see a dismissible badge and a Home card with the changelog link and the
   command. No button: Composer runs as the deploy user, never under the web worker.

Phase 1 is a beta.21 item. Phases 2–4 are Beta-gate items and are not scheduled against the
website plan's phase 1 (landing page), which proceeds independently.

## Risks and answers

- **Two archives, one bug surface.** The release script owns both; the dist gate runs per
  archive; a version mismatch between skeleton pin and core tag fails the gate.
- **`App\` is everywhere.** The move is mechanical but wide (packs, tests, OpenAPI, admin typed
  clients). Done in one commit with the suite as the safety net, before anything is published.
- **Operators who customised `app/`.** Their code was never Thallo's; after the split `app/`
  is theirs by definition. Any beta-era customisation inside Thallo's own classes has no
  upgrade path — the same truth as today, now stated.
- **Bundle in `vendor/`.** Served by the framework's `serveFrontend`, which already reads an
  arbitrary directory; `public/admin` in the skeleton is no longer needed.

## Acceptance

- A `create-project` of the skeleton at beta.N, upgraded with `composer update &&
  php glueful thallo:provision` to beta.N+1, serves the new admin and API with `.env`,
  `storage/`, theme overrides and operator code untouched — on a clean machine, from the public
  docs alone.
- The admin shows a new version within a day of its publication and hides the notice once the
  site is current.
