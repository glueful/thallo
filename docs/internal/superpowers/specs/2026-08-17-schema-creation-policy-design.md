# Schema Creation Policy — Boot-DDL Elimination and Schema-on-Enable

**Date:** 2026-08-17
**Status:** Approved (brainstorm 2026-08-17); Section A implements immediately, Section B is the
beta.3 release program.
**Origin:** v1.0.0-beta.2 clean-machine artifact gate findings.

## Decisions of record

1. **Boot performs no DDL.** Schema mutation happens only in deliberate migrate operations.
   The v1.0.0-beta.2 gate proved `php glueful list` created the `migrations` table on any
   install with a reachable database; framework 1.78.4 removed that path, and the patched
   beta.2 artifact's PostgreSQL statement log then proved a complete boot issues zero writes.
2. **Full-schema-at-provision is transitional**, not the launch posture. It remains the
   behavior through beta.2 and is replaced in beta.3 by schema-on-enable.
3. **The extension is the schema authority.** Enabling an extension runs its pending
   migrations, then marks it enabled. Capabilities are runtime switches that never create
   tables and refuse to enable unless the owning extension is enabled and schema-ready.
4. **Nothing is ever dropped.** Disabling an extension or capability preserves all tables and
   data. Upgrades migrate every `core` descriptor plus the `on_enable` descriptors of enabled
   extensions; disabled extensions' `on_enable` descriptors catch up when next enabled.
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
The completed A3 artifact investigation supplies the broader Thallo-level evidence: a
PostgreSQL statement-log spy observed zero writes during a complete application boot.

**Tests:** construct the manager and register paths under a query-spying connection —
assert zero queries; with one discoverable migration and no ledger, assert the applied list is
empty and the migration is reported pending without DDL; assert rollback on a ledger-less
database reports nothing-to-rollback without DDL; assert the ledger DDL fires exactly once,
at the first `migrate()`.

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

### A3. Completed investigation: the 19-table claim and the boot query-spy

The captured beta.2-artifact rerun closed both questions:

1. The failed-provision reproduction did **not** mutate schema. The earlier 19-table
   attribution did not survive a controlled pre/post inventory and was removed rather than
   preserved as a speculative defect.
2. With framework 1.78.4's lazy-ledger change applied, `php glueful list` and
   `migrate:status` create nothing on an empty database.
3. A PostgreSQL statement-log spy over full application boot recorded zero writes of any
   kind. No eager-DDL follow-up class remained to enumerate.
4. `docs/internal/OUTSTANDING.md` records the verified verdicts and the shipped framework and
   provision fixes.

**Sequencing:** A1 lands first in the framework repo (tests + changelog, committed locally
for human publication as 1.78.4); Thallo then bumps the requirement and lands A2/A3; full
gates re-run.

---

## Section B — Schema-on-enable (spec for the beta.3 release program)

This is a coordinated framework + first-party-extension release program, not a change to
`extensions:enable` alone.

### B1. Manifest migration contract and single inventory (new, closed)

- `extra.glueful` gains a migrations declaration. **Every Glueful package that declares a
  provider or otherwise participates in schema management must explicitly declare migration
  descriptors or `migrations: none` — fail closed.** This rule does not apply to arbitrary
  Composer dependencies. An unknown/undeclared Glueful package cannot participate in
  migrate-before-enable.
- `PackageManifest::getCandidates()` returns only glueful-extension-type packages; core
  companions (e.g. `glueful/thallo-commerce`) are libraries. A separate **all-package
  `migrationDescriptors()` projection** is added for schema purposes.
- Each descriptor carries: a **stable ID**, relative path, priority (closed enum), mode
  `core | on_enable`, and an optional structural-verifier FQCN. The verifier is manifest metadata
  rather than a provider contribution so it remains discoverable while an extension is disabled;
  it must implement the framework verifier contract, have a public zero-required-argument
  constructor, and report the descriptor's exact source identity. Missing, malformed,
  non-conforming, or source-mismatched verifier classes make adoption divergent, never inferred.
  Multiple descriptors per package are allowed (identity is the
  descriptor ID, not merely the package name). Paths are validated: traversal and any path
  escaping the package directory are rejected. A declared descriptor path must exist and
  contain at least one migration; an empty schema declares `migrations: none` instead.
- `on_enable` is valid only for a package of type `glueful-extension`; its owner is that
  package's provider. Library-typed Thallo packs have no independent extension-enable event
  and therefore declare their schema descriptors `core`. A future library-to-extension
  ownership indirection requires a new reviewed manifest contract rather than an inferred
  name match.
- **The manifest projection is the sole package migration inventory in beta.3.** First-party
  providers remove their `loadMigrationsFrom()` calls for manifest-described paths. The
  compatibility method may validate that a legacy registration exactly matches a descriptor,
  but it must not append a second source. Duplicate descriptor IDs, canonical paths, or legacy
  aliases fail closed; one physical migration file can enter one execution batch only once.
- **Legacy ledger identities are preserved.** Existing sources (`thallo-commerce`,
  thallo-render's implicit registrations, `app:dependent`, …) would appear unapplied if
  `source` were simply forced to the composer name. Descriptors carry explicit **legacy
  source aliases**, and a verified **receipt-normalization step** migrates ledger rows to the
  descriptor identity before anything reads readiness from them. Normalization verifies the
  migration checksum before rewriting and refuses ambiguous aliases.

### B2. Modes and their plain consequences

- `core` descriptors are always migrated at provision/upgrade regardless of enablement. The
  thallo-commerce link table (registered "on INSTALL, not enable" today) declares `core`:
  **Thallo's commerce link table continues provisioning before Commerce is enabled.**
  Schema-on-enable governs the engine extension's schema, not every table associated with the
  broader product.
- Framework-owned migration leaves are classified explicitly: `auth`, `locks`, `uploads`,
  `queue`, `scheduler`, `notifications`, and `metrics` are all `core`. Their configuration
  flags continue to govern runtime behavior, not schema presence. This removes the current
  second policy in `CoreProvider` where registration depends on runtime configuration.
- Every schema-owning library-typed Thallo pack declares `core`; the first-party adoption
  matrix lists each pack and descriptor so no library descriptor is orphaned from an enable
  event.
- `on_enable` descriptors migrate only via the enable flow (or upgrade, if already enabled).
- Installing a package never performs DDL in the Composer request. Newly discovered `core`
  descriptors run on the next deliberate upgrade/migrate operation; enabling an extension
  first applies any pending core prerequisites and then only that extension's `on_enable`
  descriptors before changing enabled state.

### B3. Readiness, dependencies, and gating

- *Schema-ready(descriptor)* = the descriptor path is valid and non-empty, and every current
  migration file has a ledger receipt under its identity with the **exact current SHA-256
  checksum** (including verified, normalized legacy aliases). A missing path, checksum
  mismatch, removed migration with a historical receipt, or ambiguous identity is
  **divergent**, never ready and never silently rerun. Readiness remains ledger-driven; it
  never uses `hasTable` probes.
- `requires.extensions` metadata must be **complete for all first-party extensions before
  release** (it exists today and is empty everywhere). Enable refuses with the ordered
  dependency list; there is no undeclared-package fallback behavior.
- The capability contract gains an optional explicit owning-extension **Composer package**. It is
  mandatory for every engine-backed capability and absent for genuinely app-only
  capabilities. The identifier is syntax-validated at registration; an absent package leaves
  the capability registered but unavailable so the SPA can name the installation remedy. An
  installed owner must resolve to exactly one extension candidate/provider. Capability enable
  checks that owner is installed, enabled, and schema-ready; refusal names the owning package
  and exact remedy (SPA action or `php glueful extensions:enable <package>`). Capability IDs
  and naming conventions are never used to infer ownership.

### B4. Execution: locking, transactions, failure

- A **migration-lock abstraction** (not hard-coded PostgreSQL advisory locks) serializes all
  enable, disable, migrate, normalize, and adopt operations per extension/descriptor source;
  drivers provide implementations. Disable cannot race an enable operation's final state
  write.
- On transactional-DDL drivers (PostgreSQL), each migration's DDL **and its ledger insert
  run in the same transaction**.
- On drivers without transactional DDL, a failure enters an explicit **`manual_repair`**
  state — no automatic-resume promise. First-party migration idempotency is required as
  defense-in-depth, but is not treated as proof of recovery.

### B5. The enable operation is core-owned state

The SPA/CLI shared step machine persists its operation record in a **core-owned operation
table** — locking, actor, current step, failed migration, recovery state — because the target
extension's schema does not exist yet at enable time. The CLI, the framework extension HTTP
controller, and Thallo's extension HTTP controller all drive the same executor over the same
operation record. The executor runs an explicit descriptor set: generic enable never calls
global `MigrationManager::migrate()` and cannot apply an unrelated disabled extension's
schema. It migrates first, verifies readiness, writes enabled state last, then recompiles the
provider cache.

`ExtensionStateWriter` becomes executor-internal plumbing for generic flows; an architecture
test inventories mutation callers and rejects direct CLI/controller use. Tenancy's protected
state machine is the sole named exception and uses the shared migration executor before its
own activation step. Enable/disable are supported in production through both CLI and SPA:
the current `APP_ENV=production` refusals are removed. Existing platform authority,
the approved CSRF policy for cookie-authenticated requests, host-writability checks, audit
records, and the explicit beta acceptance of web-runtime DDL remain mandatory.

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
  the state is classified divergent** (manual attention, with guidance). An adopted receipt
  records the checksum of the exact shipped file that its verifier covers.
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
2. First-party engine extensions: adopt `on_enable` descriptors (or `migrations: none`),
   populate `requires.extensions`, and provide structural verifiers.
3. Thallo: adopt `core` descriptors (or `migrations: none`) across every library-typed pack;
   then land provision changes, the enable step machine + core-owned operation table, SPA
   states, tenancy executor swap, adoption tooling, and beta.2-fixture upgrade tests.
4. Cut v1.0.0-beta.3.

## Testing summary

- **A:** framework query-spy unit tests (construction, a non-empty pending read, rollback,
  first-migrate); provision validation matrix; completed artifact boot statement-log proof.
- **B:** fresh-install enable matrix per extension; upgrade-from-beta.2 fixture (ready /
  adoptable / divergent paths); disable-preserves-data; re-enable catch-up; mid-enable
  failure on transactional and non-transactional drivers; dependency refusal ordering;
  tenancy flow through the shared executor; path-traversal, missing/empty-path,
  duplicate-path/ID/alias, and checksum-mismatch rejection; provider/manifest
  single-inventory proof; source-scoped enable proof; capability-owner truth table;
  concurrent enable/disable serialization; production CLI/SPA enable coverage; newly
  installed core-descriptor catch-up; framework-core and Thallo-library classification
  inventories.
