# Changelog

All notable changes to Thallo are documented here. Format:
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/); versioning:
[SemVer](https://semver.org/spec/v2.0.0.html). Release tags are immutable — corrections ship
as the next release, never a mutated tag.

## [Unreleased]

## [1.0.0-beta.6] - 2026-09-06 — Developer Preview

A first-run polish release on beta.5: `thallo:provision` recognises a hand-filled `.env` and
asks for one confirmation instead of seven answers. No schema, API, or admin changes; beta.5
installs upgrade in place.

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
