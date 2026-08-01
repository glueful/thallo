# `lemma` CLI & Onboarding (v1) — Design / Spec

**Date:** 2026-06-19
**Status:** Settled design. Open decisions resolved (see "Resolved decisions"). Ready for its own
TDD implementation plan (`docs/superpowers/plans/…-lemma-cli.md`).

Captures the onboarding/CLI work that does **not** belong in the Admin SPA Phase 1 plan
(`2026-06-17-admin-spa-phase-1.md`), which keeps only the **web** first-run setup.

## Why this is separate

Lemma is a create-project product app (it dogfoods the framework's onboarding). Getting a fresh
install from "scaffolded" to "usable admin" has two layers:

- **Layer 1 — infrastructure:** Postgres connection, framework security keys, base URL, run
  migrations. This is `.env`/env + CLI — **never** a browser wizard (writing DB creds from an HTTP
  request is a security/12-factor footgun).
- **Layer 2 — application:** create the first admin user, set site name + default locale.

**Phase 1 (the Admin SPA plan) ships the *web* cut of Layer 2 only** — a gated `POST /admin/setup`
+ a `/setup` screen. **Everything else — the `lemma` CLI, the Layer-1 provisioning, and the CLI cut
of setup — lives here.**

## Boundary (the load-bearing decision)

**Framework owns install *primitives*; Lemma owns *product* setup.**

- The framework's `Glueful\Installer\` seams (shipped in framework 1.60.0 "Vega") own Layer 1:
  `DatabaseConfig`, `ConnectionTester`, `EnvWriter`, `Installer`, `InstallState`. They enforce two
  invariants Lemma inherits for free: a failed connection test mutates nothing (`.env` untouched),
  and the tested credentials are exactly the connection migrations run on.
- Lemma owns Layer 2 via a single **`App\Setup\SetupService`** (first admin + site settings),
  **defined and owned by the UI-setup spec** (`2026-06-19-lemma-ui-setup-design.md`). This CLI
  **consumes** it. That one shared service is the important design choice: it prevents the CLI and
  the Admin SPA setup endpoint from becoming two divergent installers.

## Resolved decisions

1. **v1 scope = (A) lightweight app-setup CLI.** Operates on an *existing* Lemma checkout: configure
   `.env`, generate keys, test + migrate, run setup (first admin + site). The full ghost-cli-style
   provisioner — scaffold via `create-project`, provision host (nginx/systemd/SSL) — is **(B), a
   later track**. The `lemma install` scaffold-then-setup bridge is **deferred out of v1** entirely.

2. **Distribution = in the Lemma app.** Build `php glueful lemma:provision` / `lemma:create-admin` /
   `lemma:doctor` as console
   commands in the Lemma repo, plus a thin branded **`lemma` bin** wrapper. **No separate
   `glueful/lemma-cli` global package** in v1 (the CLI only ever runs inside a Lemma checkout). If
   (B) ever lands, the bin graduates to a standalone repo then.

3. **Layer 1 runs in-process via the framework `Installer` seams — no shell-out.** "No shell-out" is
   a constraint on **PHP setup code**: `SetupService` and the framework `Installer` never spawn
   subprocesses. The `lemma` bin is exempt — it is a developer-convenience launcher that execs
   `php glueful`, which is expected and fine.

4. **Shared setup service (owned elsewhere).** "Create first admin + write site settings" is ONE
   service, `App\Setup\SetupService::install(siteName, adminEmail, adminPassword, locale)`, **owned
   by the UI-setup spec** along with its `lemma_settings` store + migration + the `installed` marker.
   The CLI **consumes** it — it does **not** define a settings table, a `SettingsStore`, or an
   `installed_at` key (an earlier draft of this spec did; that is superseded by the UI-setup spec's
   names). The web `POST /admin/setup` consumes the identical `install()` path.

## Components (all in the Lemma app, `App\` namespace)

### `App\Setup\SetupService` — consumed, not built here
Owned by the UI-setup spec (`2026-06-19-lemma-ui-setup-design.md`):
`isInstalled(): bool` + race-safe `install(string $siteName, string $adminEmail, string
$adminPassword, string $locale): void` (creates the first admin via **`glueful/users`**, grants admin
via **`glueful/aegis`**, writes `site_name`/`default_locale` to `lemma_settings`, sets the `installed`
marker). The CLI's Layer 2 is simply a call to `install()`; the already-installed guard reads
`isInstalled()`. This CLI spec introduces **no** new settings storage.

### Why two commands (the Layer 1 / Layer 2 process boundary)
Setup is split into **two console commands run in separate processes**, not one. The reason is a
hard runtime constraint: Glueful binds the `database` connection (and the `glueful/users`
`UserRepository` that creates the admin) as a **shared singleton built once at boot** from the
then-current `.env`. A single `php glueful` process that writes new DB creds in Layer 1 cannot
re-point that already-booted, container-bound connection — so creating the first admin in the same
process would write it to the **boot-time** (wrong/unconfigured) database. The fix is a process
boundary: Layer 1 writes `.env` + migrates; **Layer 2 runs in a fresh process** that boots against
the now-correct `.env`. The `lemma setup` bin verb orchestrates the two (see the bin section).

### `lemma:provision` console command (Layer 1)
Configures the database + framework security keys; runs migrations. No admin.
- Builds a `DatabaseConfig` and hands it to the framework `Installer` (preflight →
  `ConnectionTester.test()` → `EnvWriter` writes `.env` with DB creds + **framework security keys:
  `APP_KEY`, `TOKEN_SALT`, `JWT_KEY`** → migrate via the injected tested `Connection`).
  - **Engine is fixed to Postgres — never prompted.** The framework `Installer` is engine-agnostic,
    but Lemma is Postgres-required, so `lemma:provision` hardcodes `engine = pgsql` in the
    `DatabaseConfig` and prompts only for host / port / database / user / password.
  - **Keys must be generated by the framework `Installer`, not `generate:key`.** The `Installer`
    emits all three (`APP_KEY`/`TOKEN_SALT`/`JWT_KEY`); `generate:key` emits only `APP_KEY`/`JWT_KEY`
    and would silently omit `TOKEN_SALT`, which `config/session.php` marks REQUIRED and
    `TokenManager` uses for token hashing (defaulting to `''` — i.e. weakened — if absent).
- Pre-prompt `Doctor` checks run first; the DB config is **validated** (host/port/database/user/
  password) and **fails loudly** before the Installer if anything is missing/invalid.
- On success prints the configured database (host / port / database — Postgres fixed; password
  **never** shown) + "migrations applied".
- Flags: `--force` (re-runs Layer 1 infra only — regenerate keys / rewrite `.env` / re-run pending
  migrations), and **non-interactive mode** (`-n` / `--no-interaction`, or no TTY) — read DB creds
  from existing env / explicit `--db-*` options, no prompts;
  fail loudly on a missing/invalid required value — never fall back to implicit process config).

### `lemma:create-admin` console command (Layer 2)
Creates the first admin + writes site settings. Boots against the **already-configured** DB (run
after `lemma:provision`), so its container-bound connection is correct.
- Guards on `App\Setup\SetupService::isInstalled()`: if already installed, reports it and exits
  success **without** re-creating the admin (Layer 2 is permanent — there is no `--force` for it).
- Otherwise gathers site name / admin email / admin password / locale (prompts, or `--site-name` /
  `--admin-email` / `--admin-password` / `--locale` when non-interactive (`-n`); fail loudly on a missing required
  value), then calls `App\Setup\SetupService::install(siteName, adminEmail, adminPassword, locale)`.
- On success prints the admin email + admin URL + next steps.

### `lemma:doctor` console command — two check phases
- **Pre-prompt checks** (no credentials needed, run before any prompt): PHP 8.3+, required
  extensions (`pdo_pgsql`, …), and:
  - **Writable `.env` target, not "`.env` is writable".** If `.env` exists → it must be writable; if
    it does **not** exist → the project root must be writable **and** `.env.example` must exist and
    be readable (the framework `Installer` creates `.env` from `.env.example`). `storage/` must be
    writable.
  - **Keys writable/generatable, not "keys present".** On a fresh checkout keys are absent **by
    design** — Layer 1 generates them via the `Installer`. So in **`lemma:provision`** the pre-prompt
    check is "keys can be written/generated" (i.e. the `.env` target is writable, per above), never
    "keys present" — otherwise provision would abort before the step that creates them. **Standalone
    `lemma:doctor`** reports absent keys as a **warning** (default) or a **failure** under a strict
    mode, since a configured-but-keyless install is a real problem post-setup.
- **Post-credential checks** (need DB creds): Postgres reachability via `ConnectionTester` — runs in
  standalone `lemma:doctor` against the `DatabaseConfig` built from existing env (when configured).
  In `lemma:provision`, the framework `Installer`'s own preflight is the single connection test (no
  duplicate probe).

`lemma:provision` runs the pre-prompt block first. `lemma:doctor` standalone runs the pre-prompt
block, then the reachability check against existing env if creds are present.

### Thin `lemma` bin (Lemma repo root)
A **transparent front end** for the app's `php glueful` console: every command passes straight
through (`lemma <cmd> …` → `php glueful <cmd> …`), so a developer gets the **full framework console**
— colour and all — via `lemma` (`lemma cache:clear`, `lemma migrate:run`, `lemma list`, `lemma
--help`, …). No-arg `lemma` runs `php glueful list`. Three extras sit on top:
- **`lemma setup`** (special): runs the two setup commands as two sequential `php glueful` processes
  — `lemma:provision` then `lemma:create-admin` — which is what gives Layer 2 a fresh boot against
  the configured DB. Interactive front door (no command-specific flags forwarded); for
  non-interactive/CI, call `lemma:provision` / `lemma:create-admin` individually with their flags.
- **Bare-name shortcuts** for the branded setup verbs: `lemma doctor` / `lemma provision` /
  `lemma create-admin` map to their `lemma:` command. (Everything else, including `lemma
  lemma:doctor`, is the plain passthrough.)
The launcher resolves its own path through symlinks (so it can live on `$PATH`) and is an `sh`/PHP
polyglot — running it as `php lemma` by mistake prints a hint instead of dumping the script.

## Data flow (`./lemma setup`)

```
./lemma setup   (bin verb — interactive)
  ── process 1: php glueful lemma:provision ──────────────────────────────
  → doctor pre-prompt checks (PHP, extensions, writable .env target, storage)
  → prompt pgsql creds (host/port/db/user/pass; engine fixed to pgsql) → DatabaseConfig → validate
  → framework Installer: test → EnvWriter writes .env (DB + APP_KEY/TOKEN_SALT/JWT_KEY) → migrate
  → summary: database (host/port/db — Postgres fixed; NEVER the password) + migrations applied
  ── process 2: php glueful lemma:create-admin (fresh boot → configured DB) ─
  → isInstalled()? if yes → report + exit success (admin never re-created)
  → prompt site name / admin email+password / locale
  → SetupService.install(...) → admin (glueful/users) + grant (glueful/aegis) + lemma_settings + marker
  → summary: admin email + admin URL + next steps
```

## Error handling

- **Connection test fails** (in `lemma:provision`) → abort; `.env` untouched (framework invariant);
  friendly message including `sqlState`.
- **Already installed** (`SetupService::isInstalled()` true) → `lemma:create-admin` reports it and
  exits success **without** re-creating the admin. Layer 2 is permanent; there is no `--force` for it
  (`--force` exists only on `lemma:provision`, for Layer 1 infra).
- **Missing extension / unwritable `.env` target / unwritable `storage/`** → `lemma:provision`'s
  pre-prompt doctor fails fast, before any prompt. **Absent security keys on a fresh checkout are NOT
  a `lemma:provision` failure** — Layer 1 generates them; they only matter as a standalone-`doctor`
  warning/failure.
- **`lemma:provision --quiet`** → read existing env (or `--db-*` options) → build `DatabaseConfig` →
  validate → hand to the `Installer`; no prompts; fail loudly on a missing/invalid required value.
- **`lemma:create-admin --quiet`** → take site/admin/locale from options; fail loudly on a missing
  required value before calling `install()`.

## Testing

- **`SetupService` / `lemma_settings`**: built + tested by the prerequisite (the UI-setup spec /
  Phase 1 Task 0d). `lemma:create-admin` is tested against the real service in the test DB.
- **`lemma:provision`**: the Layer-1 work delegates to the already-tested framework `Installer`, so
  the command test focuses on the **fail-fast** wiring — a missing/invalid DB field (e.g. a
  non-numeric `--db-port`) aborts **before** the Installer, mutating nothing. `Doctor` + the DB-config
  factory are unit-tested directly.
- **`lemma:create-admin`**: integration test against the test DB — creates the admin, the
  already-installed guard exits success without a second admin, and a missing required option in
  non-interactive mode fails fast.
- **`lemma:doctor`**: each pre-prompt check unit-tested with fakes — including **`.env` target
  writable when `.env` is absent** (root writable + `.env.example` readable) and the
  **keys-writable-vs-keys-present** distinction (fresh checkout passes `setup` pre-prompt, standalone
  `doctor` warns); reachability check against a reachable/unreachable `DatabaseConfig`.
- **`lemma` bin**: smoke test that `setup` forwards to `php glueful lemma:provision` then
  `lemma:create-admin`, and that other verbs forward to `php glueful lemma:<cmd>`, exiting with
  the delegated status code.

## Out of scope (tracked elsewhere)

- The **web** first-run setup (`/setup` screen + `POST /admin/setup` + `installed` surface) **and the
  shared `App\Setup\SetupService` + `lemma_settings` core** → **UI-setup spec**
  (`2026-06-19-lemma-ui-setup-design.md`), implemented via the Admin SPA Phase 1 plan.
- Server provisioning / managed-hosting / `create-project` scaffolding → future, decision (B).

## Next step

Settled. Proceed to the TDD implementation plan
(`docs/superpowers/plans/2026-06-19-lemma-cli.md`). Linked from the Admin SPA Phase 1 plan's
first-run-setup task and from `docs/NEXT.md`.
