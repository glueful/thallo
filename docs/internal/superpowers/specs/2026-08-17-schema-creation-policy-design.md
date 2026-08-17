# Schema Creation Policy — Boot-DDL Elimination and Schema-on-Enable

**Date:** 2026-08-17
**Status:** Approved (brainstorm 2026-08-17); Section A implements immediately, Section B is the
beta.3 release program.
**Origin:** v1.0.0-beta.2 clean-machine artifact gate findings.

## Decisions of record

1. **Boot performs no DDL.** Schema mutation happens only in deliberate migrate operations.
   The v1.0.0-beta.2 gate proved `php glueful list` creates the `migrations` table on any
   install with a reachable database.
2. **Full-schema-at-provision is transitional**, not the launch posture. It remains the
   behavior through beta.2 and is replaced in beta.3 by schema-on-enable.
3. **The extension is the schema authority.** Enabling an extension runs its pending
   migrations, then marks it enabled. Capabilities are runtime switches that never create
   tables and refuse to enable unless the owning extension is enabled and schema-ready.
4. **Nothing is ever dropped.** Disabling an extension or capability preserves all tables and
   data. Upgrades migrate only enabled extensions; disabled extensions catch up when next
   enabled.
5. **Enable surface is SPA + CLI** (deliberate reversal of the earlier CLI-only ruling —
   Thallo's tenancy admin flow already performs web-driven migration via its enablement state
   machine, so web-runtime DDL is the honest beta posture). A privileged worker / separate
   migration credential is the post-beta successor for least-privilege deployments.
6. **beta.1 and beta.2 are immutable.** Schema-on-enable targets v1.0.0-beta.3 or later and
   must support both installation histories: existing full-schema installs and new
   on-enable installs.

---

## Section A — Eliminate boot-time DDL (implements now)

### A1. Framework (glueful/framework 1.78.4): `MigrationManager` construction is DB-free

Root cause: the constructor ends with `$this->ensureVersionTable()`, so constructing the
manager connects and runs DDL. `Extensions\ServiceProvider::loadMigrationsFrom()` constructs
it merely to register a path, which makes every provider boot a DDL trigger when a database
is reachable (this survived 1.78.3, which fixed only the three `Migrate/*` commands).

**Contract (asymmetric by design):**

- `migrate()` is the **only** operation that ensures/creates the ledger.
- Status and pending reads treat a missing ledger as **zero applied migrations** and perform
  no DDL.
- `rollback()` reports nothing-to-rollback when the ledger is absent.
- A `$ledgerEnsured` guard is set only after a **successful** ensure.
- `addMigrationPath()` remains pure bookkeeping (no DB access).

Consequence: read-only operational commands work with read-only database credentials, and no
provider (framework or pack) needs any change.

**Invariant proven by 1.78.4:** *migration discovery and registration perform zero DDL.*
The broader claim ("application boot performs zero DDL") is **not** asserted; a boot-level
query-spy investigation (A3) enumerates the remaining eager-DDL classes (known suspects:
`DatabaseLogHandler`, `WebhookDispatcher`) as concrete findings for follow-up.

**Tests:** construct the manager and register paths under a query-spying connection —
assert zero queries; assert status/pending reads on a ledger-less database return empty
without DDL; assert rollback on a ledger-less database reports nothing-to-rollback without
DDL; assert the ledger DDL fires exactly once, at the first `migrate()`.

### A2. Thallo: provision `-n` accepts trust-auth PostgreSQL

- **Password presence is tracked separately from its value.** A `passwordProvided` boolean is
  derived at the input boundary — `InputInterface::getOption('db-password') !== null`, or the
  `DB_PGSQL_PASSWORD` env line being present (even if empty) — and passed into validation.
  `PgsqlDatabaseConfigFactory::fromEnv()` collapsing absent and empty to `''` must not erase
  that distinction.
- Explicitly provided empty password (`--db-password=""`, or a present-but-empty env line)
  means "none" and validates. A fully absent password still refuses in `-n`.
- Host defaults to `localhost` **only when absent**; an explicitly empty host still fails.
- The database-preflight connection attempt remains the real arbiter of the credentials.

**Tests (command-level):** absent password; explicit `--db-password=""`; empty
`DB_PGSQL_PASSWORD=` line; non-empty password; absent host (defaults); explicitly empty host
(fails).

### A3. Investigation: the 19-table partial state, and the boot query-spy

Stated as an investigation, not a prescribed outcome. Against a dedicated scratch database:

1. Reproduce the beta.2 gate's "failed provision left 19 tables" scenario capturing the
   command output, exit code, pre/post table inventory, and `.env` state at each step.
2. Classify whether the tables came from partial non-transactional migrations (the `.env`
   already held working credentials at that point) or from boot-time DDL.
3. Run a full application-boot query-spy and enumerate every class that performs DDL or
   writes during boot. Each becomes a named finding.
4. Correct the OUTSTANDING item for "provision that fails validation mutates schema" to match
   the verified mechanism.

**Sequencing:** A1 lands first in the framework repo (tests + changelog, committed locally
for human publication as 1.78.4); Thallo then bumps the requirement and lands A2/A3; full
gates re-run.

---

## Section B — Schema-on-enable (spec for the beta.3 release program)

This is a coordinated framework + first-party-extension release program, not a change to
`extensions:enable` alone.

### B1. Manifest migration contract (new, closed)

- `extra.glueful` gains a migrations declaration. **Every package must explicitly declare
  either migration descriptors or `migrations: none` — fail closed.** An unknown/undeclared
  legacy package cannot participate in migrate-before-enable.
- `PackageManifest::getCandidates()` returns only glueful-extension-type packages; core
  companions (e.g. `glueful/thallo-commerce`) are libraries. A separate **all-package
  `migrationDescriptors()` projection** is added for schema purposes.
- Each descriptor carries: a **stable ID**, relative path, priority (closed enum), and mode
  `core | on_enable`. Multiple descriptors per package are allowed (identity is the
  descriptor ID, not merely the package name). Paths are validated: traversal and any path
  escaping the package directory are rejected.
- **Legacy ledger identities are preserved.** Existing sources (`thallo-commerce`,
  thallo-render's implicit registrations, `app:dependent`, …) would appear unapplied if
  `source` were simply forced to the composer name. Descriptors carry explicit **legacy
  source aliases**, and a verified **receipt-normalization step** migrates ledger rows to the
  descriptor identity before anything reads readiness from them.

### B2. Modes and their plain consequences

- `core` descriptors are always migrated at provision/upgrade regardless of enablement. The
  thallo-commerce link table (registered "on INSTALL, not enable" today) declares `core`:
  **Thallo's commerce link table continues provisioning before Commerce is enabled.**
  Schema-on-enable governs the engine extension's schema, not every table associated with the
  broader product.
- `on_enable` descriptors migrate only via the enable flow (or upgrade, if already enabled).

### B3. Readiness, dependencies, and gating

- *Schema-ready(descriptor)* = all of the descriptor's migration files have ledger receipts
  under its identity (including normalized legacy aliases). Ledger-driven; never `hasTable`
  probes.
- `requires.extensions` metadata must be **complete for all first-party extensions before
  release** (it exists today and is empty everywhere). Enable refuses with the ordered
  dependency list; there is no undeclared-package fallback behavior.
- Capability enable checks the owning extension is enabled and schema-ready; refusal names
  the exact remedy (SPA action or `php glueful extensions:enable <extension>`).

### B4. Execution: locking, transactions, failure

- A **migration-lock abstraction** (not hard-coded PostgreSQL advisory locks) serializes
  enable/migrate per source; drivers provide implementations.
- On transactional-DDL drivers (PostgreSQL), each migration's DDL **and its ledger insert
  run in the same transaction**.
- On drivers without transactional DDL, a failure enters an explicit **`manual_repair`**
  state — no automatic-resume promise. First-party migration idempotency is required as
  defense-in-depth, but is not treated as proof of recovery.

### B5. The enable operation is core-owned state

The SPA/CLI shared step machine persists its operation record in a **core-owned operation
table** — locking, actor, current step, failed migration, recovery state — because the target
extension's schema does not exist yet at enable time. The CLI and SPA drive the same
executor over the same operation record.

### B6. Tenancy exception

Tenancy remains a separate, protected lifecycle: its control-plane migrations are `core`;
its enforcement migrations run through the existing protected tenancy state machine
(`TenancyEnablement`), now backed by the **shared executor** (locking, transactions, failure
states) rather than a private path. Generic `extensions:enable` remains forbidden for
tenancy and directs to the dedicated flow.

### B7. Adoption of existing beta installs — measured, never assumed

- A real beta.2 fixture audit is part of the program.
- A verification command classifies each descriptor: **ready** (complete receipts),
  **adoptable**, or **divergent**.
- *Adoptable* requires a **package/migration-owned structural verifier**: receipts for
  missing `(source, migration)` rows are written only after that verifier passes. A generic
  command must not infer a migration ran merely because tables exist — **without a verifier,
  the state is classified divergent** (manual attention, with guidance).
- No table is ever dropped by classification, adoption, upgrade, or disablement.

### B8. Provision and SPA behavior in beta.3

- Provision creates: app-tier migrations, all `core` descriptors, and `on_enable`
  descriptors of extensions in the shipped enabled posture.
- The SPA shows installed / enabled / schema-ready / migration-pending / failed states, can
  drive the enable step machine, and displays the equivalent CLI command. Capability toggles
  stay available once the owning extension is ready.
- beta.3 release notes carry a prominent behavioral-change entry covering both installation
  histories.

### B9. Program sequencing

1. Framework release: manifest descriptor contract + `migrationDescriptors()`, lock
   abstraction, transactional runner, ledger-driven readiness, receipt normalization.
2. First-party extensions: adopt descriptors (or `migrations: none`), populate
   `requires.extensions`, provide structural verifiers.
3. Thallo: provision changes, enable step machine + core-owned operation table, SPA states,
   tenancy executor swap, adoption tooling, upgrade tests from a beta.2 fixture.
4. Cut v1.0.0-beta.3.

## Testing summary

- **A:** framework query-spy unit tests (construction, reads, rollback, first-migrate);
  provision validation matrix; boot query-spy investigation.
- **B:** fresh-install enable matrix per extension; upgrade-from-beta.2 fixture (ready /
  adoptable / divergent paths); disable-preserves-data; re-enable catch-up; mid-enable
  failure on transactional and non-transactional drivers; dependency refusal ordering;
  tenancy flow through the shared executor; path-traversal rejection in descriptors.
