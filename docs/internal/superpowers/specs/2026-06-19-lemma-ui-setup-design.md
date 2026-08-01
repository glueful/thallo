# Lemma UI (Web) First-Run Setup — Design / Spec

**Date:** 2026-06-19
**Status:** Settled design. **Canonical owner** of the web first-run setup *and* the shared
`App\Setup\SetupService` install contract. The `lemma` CLI spec
(`2026-06-19-lemma-cli-onboarding-design.md`) and the Admin SPA Phase 1 plan
(`2026-06-17-admin-spa-phase-1.md`, Task 0d/1.5) both **consume** the core defined here.

## Why this exists

Lemma is a create-project product app. After Layer 1 (infrastructure) is done, a fresh install
still has **no admin user** — the editorial loop has nobody to log in as. This spec covers the
**web** path that gets a configured install from "DB is up" to "first admin exists, site
configured," entirely in the browser.

### The two layers (shared vocabulary with the CLI spec)

- **Layer 1 — infrastructure:** Postgres connection, framework security keys
  (`APP_KEY`/`TOKEN_SALT`/`JWT_KEY`), migrations. **Never** done over HTTP — writing DB creds from a
  web request is a security/12-factor footgun. Layer 1 is the **precondition** for web setup and is
  handled by the `lemma` CLI (or a hand-edited `.env` + `php glueful migrate:run`).
- **Layer 2 — application:** create the first admin user, set site name + default locale. **This is
  what web setup does**, and the only thing it does.

If Layer 1 is not complete (DB unreachable / not migrated), the admin app cannot even read its
config (see data flow) — that is the intended signal to run the CLI first.

## Boundary (shared with the CLI spec)

Framework owns install **primitives** (`Glueful\Installer\` — Layer 1); Lemma owns **product**
setup (Layer 2). Within Lemma, **one** `App\Setup\SetupService` is the single source of truth for
Layer 2, called by **both** the web endpoint here and the future `php glueful lemma:setup` console
command. Neither re-implements install logic. This is the load-bearing decision: it stops the web
and CLI setups from becoming two divergent installers.

## Components (this spec owns all of these)

> **Namespace:** only the **HTTP-agnostic, CLI-shared `SetupService`** moves to a top-level
> **`App\Setup\`** (a cross-cutting app concern). The **web-only** `SetupController` + `SetupData`
> **stay in the HTTP layer** under `App\Content\Http\…`, alongside the other admin controllers/DTOs
> (`AdminConfigController`, the request DTOs) — that matches the existing codebase convention and
> avoids same-namespace imports. So Phase 1's plan keeps its `app/Content/Http/…` paths for the
> controller + DTO; only `app/Content/Setup/SetupService.php` shifts to `app/Setup/SetupService.php`.

### `App\Setup\SetupService` — shared Layer 2 seam (the core)
Console/HTTP-agnostic. Single source of truth for install:
- `isInstalled(): bool` — true once the `installed` marker is set.
- `install(string $siteName, string $adminEmail, string $adminPassword, string $locale): void` —
  **race-safe, transactional**: re-check `isInstalled()` inside the transaction → create the first
  admin via **`glueful/users`** → grant the admin role via **`glueful/aegis`** → write `site_name` +
  `default_locale` → set the `installed` marker.

**Race-safety:** the transaction re-checks `isInstalled()`, and a **unique constraint** is the
backstop — the `installed` settings key is a primary key, and `glueful/users` enforces email
uniqueness — so two concurrent installs cannot both win; the loser's insert violates the constraint
and its transaction rolls back, leaving exactly one admin.

Reuse, don't reinvent — it orchestrates `users` + `aegis`; it does not re-implement auth or RBAC.

### `lemma_settings` table + migration
`database/migrations/013_CreateLemmaSettingsTable.php` → **`lemma_settings`** key/value store
(`key` PK, `value`, `updated_at`). Holds `site_name`, `default_locale`, and the `installed` marker.
This is the product-settings contract the Admin SPA settings screen later reads/writes; the CLI
consumes it indirectly via `SetupService`.

### `App\Content\Http\DTOs\Requests\SetupData` — web request DTO
`RequestData` DTO (`site_name`, `admin_email`, `admin_password`, `locale`) with `#[Rule]`
validation. Web-only (the CLI gathers the same values via prompts, not this DTO).

### `App\Content\Http\Controllers\SetupController` — unauthenticated, self-locking web endpoint
`setup(SetupData $input): JsonResponse` registered as **`POST /admin/setup`** (unauthenticated,
auto-discovered via the existing `routes/lemma_admin_spa.php`). Returns **`409`** once installed
(self-locks permanently — it can never create a second "first" admin), else calls
`SetupService::install()` and returns success.

### `installed` in `/admin/config.json`
The existing unauthenticated `GET /admin/config.json` (Phase 1 Task 0b) adds an `installed: bool`
field (from `SetupService::isInstalled()`), so the SPA knows whether to route to `/setup`.

### `admin/src/views/SetupView.vue` — the SPA setup screen
First-run form (site name, default locale, admin email, password + confirm) → `POST /admin/setup`.
A **router guard** uses `config.json.installed`: when `installed === false`, the app forces `/setup`;
once `installed === true`, hitting `/setup` redirects to the login flow. On success the SPA proceeds
to login (the admin now exists).

## Security posture (explicit)

- `POST /admin/setup` is **unauthenticated** — there is no admin yet to authenticate against — but
  **self-locks permanently** (`409` forever once installed).
- `install()` is **race-safe** (transaction re-check + unique-constraint backstop, above).
- **DB credentials are never written from an HTTP request.** Web setup is Layer 2 only; Layer 1 is a
  precondition handled by the CLI / `.env`.

## Data flow (`/admin` in a browser, fresh install)

```
SPA boot
  → GET /admin/config.json  (requires Layer 1: DB reachable + migrated)
       → installed:false
  → router guard forces /setup
  → operator fills SetupView (site name, locale, admin email, password)
  → POST /admin/setup  { SetupData }
       → SetupController: isInstalled? no
       → SetupService.install(site, email, pw, locale)
            → create admin (glueful/users) + grant admin (glueful/aegis)
            → write site_name + default_locale + installed marker  (one transaction)
       → 200
  → SPA routes to login; admin now exists
  ── revisiting /setup later → config.json installed:true → redirect to login ──
```

## Error handling

- **Already installed** → `POST /admin/setup` returns `409`; the SPA guard redirects `/setup` → login.
- **Validation failure** (`SetupData` `#[Rule]`) → `422` with field errors rendered inline by
  `SetupView`.
- **Concurrent setup** → exactly one wins; the loser hits the unique-constraint backstop and rolls
  back (surfaced as `409`/`422`, never a second admin).
- **Layer 1 not done** (DB unreachable / unmigrated) → `config.json` itself fails to load; the SPA
  shows an "infrastructure not ready — run `lemma setup`" state rather than a setup form.

## Testing

- **`SetupService`** integration (`tests/Integration/Setup/SetupServiceTest.php`): `install()` creates
  admin + grant + settings + marker; `isInstalled()` reflects the marker; **race-safety** — a second
  `install()` after the first refuses (constraint/guard), leaving exactly one admin.
- **`lemma_settings` migration**: up/down; `key` PK uniqueness.
- **`SetupController`** integration (`tests/Integration/Http/SetupApiTest.php`): unauthenticated POST
  installs once and returns success; a second POST returns `409`; invalid `SetupData` returns `422`;
  `config.json` reports `installed` correctly before/after.
- **`SetupView.spec.ts`**: renders the form, submits valid data, renders `422` field errors, and
  handles the `installed`/`409` redirect.
- **Router guard test**: `installed:false` forces `/setup`; `installed:true` redirects `/setup` away.

## Relationship to the other docs

- **Admin SPA Phase 1 plan** (`2026-06-17-admin-spa-phase-1.md`): its Task 0d (backend) and Task 1.5
  (`SetupView.vue`) are the **implementation** of this design. When executed, they must (a) defer the
  setup design to this spec and (b) move only `SetupService` to `app/Setup/` (the controller + DTO
  keep their `app/Content/Http/…` paths).
- **`lemma` CLI spec** (`2026-06-19-lemma-cli-onboarding-design.md`): **consumes**
  `App\Setup\SetupService::install()` and the `lemma_settings` store defined here — it does not build
  them. (Reconciled: the CLI spec's earlier `SettingsStore`/`installed_at`/`run()` invention is
  removed in favour of these names.)

## Out of scope (tracked elsewhere)

- Layer 1 infrastructure (DB creds, keys, migrations) + the `lemma` CLI → the CLI spec.
- Server provisioning / `create-project` scaffolding → CLI spec decision (B), later.
- The broader Admin SPA editorial loop (entry list/edit, versions) → Admin SPA Phase 1 plan.

## Next step

Settled. The implementation lands via the Admin SPA Phase 1 plan (Task 0d + Task 1.5), which this
spec now governs. If those tasks need re-plumbing for the `App\Setup\` namespace, refresh that plan
before executing.
