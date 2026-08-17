# Changelog

All notable changes to Thallo are documented here. Format:
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/); versioning:
[SemVer](https://semver.org/spec/v2.0.0.html). Release tags are immutable — corrections ship
as the next release, never a mutated tag.

## [Unreleased]

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
