# SP2b — Tenant Seed/Sync + starter_provenance (Design)

> Slice 2 of SP2 (`SP2-README.md` — invariants cited as "SP2 index §3.n"). Builds on the SP1
> spec's §8 seed/sync spine (`2026-07-09-sp1-foundation-enablement-design.md` §8.1–§8.5, which
> pins definition kinds, source_id/fingerprint semantics, the three-way sync rule, states, seed
> order, and the command surface) and on SP2a's held tenant lifecycle
> (`create → provisioning`; `TenantAdministration::markActive()` is the seed-success boundary).
> Starter policy and the seed/sync engine are Thallo-owned. Execution proved one neutral
> tenancy seam was missing: `TenantProvisioningRunner` scopes the committed provisioning
> tenant during seed without weakening the existing active-only `TenantContextRunner`.

## §1 Objective

A created tenant becomes a **usable site in one action**: creation seeds the starter surface
under `runAsProvisioningTenant` and transitions `provisioning → active` atomically. Upgrades propagate
starter changes to every tenant **without ever clobbering tenant divergence**, tracked in a
tenant-owned `starter_provenance` table that SP2c's disable gates will read.

## §2 Ownership

Starter definitions are **Thallo product policy**: the app owns the registry and engine
(`app/Content/Starter/`). The tenancy extension stays identity/infrastructure only (its
`ThalloTenantTables` registry slot for `starter_provenance` — `ThalloTenantTables.php:107` —
gets its physical table from a Thallo-owned migration, same ownership call as `media_assets`).

The existing tenant HTTP/CLI creation surfaces live in the `thallo-tenancy` pack. They invoke
the app-owned engine through narrow, Thallo-local `TenantSeedActivator` and `TenantSeedRepair`
interfaces declared by that pack and bound to the same shared `TenantSeeder` by the app
provider. Creation receives only activation; the repair command receives only repair. The pack never imports an
`App\...` class and owns no starter definitions or seed logic. This is an in-repository
dependency-inversion seam. The only cross-repo addition is the narrowly privileged
`TenantProvisioningRunner` contract and tenancy-extension bridge required to enter a
provisioning tenant context; ordinary sync remains on the active-only runner.

## §3 Components

Starter engine (`app/Content/Starter/`):

- **`StarterDefinitions`** — the single registry. Per kind it supplies: `source_id` (stable
  source identity), `definition_key` (natural key), a **normalizer** (canonical JSON — sorted
  associative keys with list order preserved, volatile fields stripped — hashed sha256 →
  `fingerprint`), an **applier**, an **exact-key locator**, and an adoption locator
  (block/content-type slug, region key, menu slug, homepage setting/entry) plus
  an ordered `adoption_keys` history for adopting rows that predate `source_id`. Every rename
  retains its former starter key in `adoption_keys`, so a default tenant whose first provenance
  run happens after a rename adopts the old starter instead of receiving a duplicate. Sources:
  block types delegate to `StarterBlockTypes::definitions()` (unchanged, stays the single
  source); content types + site settings + regions are **extracted from
  `SetupService::install()`** into the shared
  core (§8.1 DRY mandate — `install()` keeps only admin user/role + `installed` marker and
  consumes the same core; existing setup tests must prove install output unchanged); homepage
  entry + a `main` navigation-menu aggregate are new definitions. The aggregate contains the
  menu row and a visible `Home` URL item pointing to `/`, closing the header region's dangling
  `main` reference with usable navigation rather than an empty menu.
- **`TenantSeeder`** — seed-on-create + repair (§5).
- **`SeedContext` / `StarterApplyResult`** — all appliers receive tenant uuid/name, default
  locale, and nullable actor (required for install/seed, null for automated sync); they return
  `Applied` or `SkippedCollision`, so the seeder never writes provenance for a tenant-authored
  collision.
- **`StarterSync`** — the three-way sync engine (§6).
- **`StarterProvenanceRepository`** — all reads/writes of `starter_provenance`.
- **`StarterTransaction`** — the Thallo-local transaction boundary used by seed and sync. It
  records the incoming `Connection::transactionLevel()`, delegates to
  `Connection::transaction()`, and catches `\Throwable`; if the framework transaction manager
  did not catch it, the wrapper rolls back every level opened above the incoming level before
  rethrowing. This closes the framework manager's current `Exception`-only catch without adding
  a framework change to SP2b. Effects register through `Connection::afterCommit()`; the wrapper
  never flushes a private queue at a nested transaction boundary.

Pack integration (`packages/thallo-tenancy/src/Contracts/`):

- **`TenantSeedActivator`** — `seedAndActivate(string $tenantUuid, string $ownerUserUuid): void`.
  `TenantSeeder` implements it; `ThalloServiceProvider` binds the interface to that app service.
  `TenantManagementController` and `TenantManageCommand` consume only this interface after
  `TenantAdministration::create()` returns the committed provisioning tenant.
- **`TenantSeedRepair`** — `repair(string $tenantUuid): void`; consumed only by
  `thallo:tenant:seed`, implemented by the same shared `TenantSeeder`.

Definition kinds: `content_type`, `block_type`, `region`, `setting`, `navigation_menu`,
`entry`. Seed order (§8.3): content types → block types → settings → regions → menu →
homepage. **Sync applies to definitions only** (`content_type`, `block_type`, `region`);
settings and instances (menu row, homepage entry) are seed-only.

## §4 Data model

`database/dependent-migrations/012_CreateStarterProvenanceTable.php` (next after 011; confirm
against the directory at implementation time — tenant-owned; folded per the pre-launch fold
rule if adjacent migrations move):

- `id` PK · `uuid(12)` unique · `tenant_uuid(12)` indexed · `definition_kind(32)` ·
  `definition_key(255)` · `source_id(255)` · `fingerprint(64)` ·
  `state(16)` ∈ `applied | customized | orphaned_source` · `created_at` · `updated_at`.
- `UNIQUE (tenant_uuid, definition_kind, definition_key)` — one provenance row per tenant row.
- `UNIQUE (tenant_uuid, definition_kind, source_id)` — **pinned**: sync uses `source_id` as
  identity across renames, so the database itself prevents duplicate provenance for one
  source.
- **Lifecycle:** the namespace-less migration creates `tenant_uuid` nullable and has no FK to
  the extension-owned `tenants` table, so clean-off migration remains independent. Clean-off
  paths write no provenance. The normal enable retrofit promotes it to NOT NULL; when the
  migration runs with `tenancy.schema_state=widened` (enabled or `disabled_widened`), the new
  empty table is promoted immediately. Both compound uniques are present in either state.

## §5 Seed flow

Creation (SP2a) commits the tenant row in `provisioning` — unchanged. It passes the chosen owner
UUID to `TenantSeeder`. The repair command resolves the first active owner deterministically
from `TenantAdministration::listMembers()` (created order, then uuid) and fails before writing
if none exists. That actor supplies `created_by`/`updated_by`; actor identity and timestamps are
volatile and excluded from fingerprints. `SetupService::install()` passes its newly-created
admin UUID into the same shared starter core.

Seeding runs as `runAsTenant($uuid, fn() => StarterTransaction::run(...))` in **one PostgreSQL
transaction on one connection**. This ordering keeps tenant context active through the commit
and every after-commit listener:

1. Under `runAsTenant($uuid)` (fail-closed stamper scopes every insert), apply definitions in
   seed order; write an `applied` provenance row only when the applier returns `Applied`.
2. **Collision rule (instances):** if the stored `homepage_entry` setting (or explicit
   `pages/home` fallback) or `main` menu already exists
   tenant-authored, skip WITHOUT claiming it — no provenance row, no state. A `main` collision
   skips the whole aggregate, including its `Home` item. For a newly-created menu, the seed
   applier inserts the menu + item on the outer transaction's connection; it does not call
   `MenuRepository::replaceTree()`, which owns a separate raw-PDO transaction. (SP2c's disable
   gates read definition provenance — `customized`/`orphaned_source` and unprovenanced
   *definition* rows — so instance-collision evidence is not needed; the SP2c spec may
   revisit.) A fresh homepage apply creates the entry/draft, assigns route slug `home`,
   publishes it, then writes its generated UUID to tenant setting `homepage_entry` in the same
   transaction. `/home` remains
   its canonical route; the setting is what makes the root renderer serve it at `/`.
3. `TenantAdministration::markActive($c, $uuid)` executes as the final statement **inside the
   same transaction**. Verified as-built: `transition()` is a bare CAS UPDATE via `db($c)`
   with no self-owned transaction (`ContractTenantAdministration.php`), so it joins the outer
   transaction plainly — no savepoint layering.
4. Any `\Throwable` → `StarterTransaction` restores the incoming transaction level and rolls
   back starter rows + provenance + the status CAS; **the tenant row itself (committed at
   creation) is never rolled back**. A fresh create therefore leaves a clean `provisioning`
   tenant with zero starter content. A repair over pre-existing manual content rolls back only
   that invocation's changes and never claims that the pre-existing content was absent.
5. **Cache purges, events, and every other external effect stay OUTSIDE the transaction**,
   fired only after commit.

**Repair:** `thallo:tenant:seed <uuid>` **accepts `provisioning` tenants** (it IS the
recovery path) and re-runs the **complete idempotent seed** — a failed transaction leaves no
committed tail, so there is no "missing tail" to complete. Provenance `source_id` lookup first,
then natural/adoption-key location, makes the full re-run safe after partial manual
intervention.
It refuses `suspended` tenants; on an `active` tenant it is a no-op-completing backfill.

## §6 Sync flow (three-way, per tenant, per kind)

Each `(tenant, definition_kind)` reconciliation runs through `StarterTransaction` as one
atomic PostgreSQL unit. Definition-row mutation, provenance upsert/state transition, rename,
and the kind's removed-source pass either commit together or all roll back. Reports and
external effects are emitted only after that kind commits. A crash after changing a row but
before refreshing its provenance therefore cannot make retry misclassify a starter as tenant
customization.

For each definition in the current registry, locate the tenant row **by provenance
`source_id` first; a known provenance row uses exact-key lookup, while a missing provenance row
uses the current natural key and then explicit `adoption_keys` in order**. Exact lookup and
adoption lookup are separate APIs so a historical alias cannot be mistaken for occupancy of a
rename target:

- **Absent** → add + `applied` provenance.
- **Present, fingerprint == recorded** → reset any prior `customized`/`orphaned_source` state
  to `applied`, update the row to the new source when needed, and refresh fingerprint. State is
  not permanently sticky after the tenant data genuinely reconverges.
- **Present, diverged** → NEVER overwrite; set `state=customized`; report
  "skipped — customized".
- **Rename (pinned):** `source_id` matches but the source's `definition_key` changed — if the
  tenant row is unchanged-from-fingerprint, rename the row AND update the provenance
  `definition_key`; if the row is `customized` or the new key collides with an existing
  tenant row, **skip and report** rather than overwrite.
- **Adoption:** natural-key match + fingerprint-equal → adopt `applied`; match + diverged →
  adopt `customized`; no match against any source → untouched (deliberately unprovenanced
  tenant-authored row). This is what makes §8.4's "sync applies to all tenants including the
  default" work — the default tenant's rows predate provenance. The initial SP2b baseline uses
  today's natural keys; every later starter rename MUST retain its previous key in
  `adoption_keys`, with a frozen test proving that history is not accidentally dropped.
- **Removed-source discovery (pinned):** iterating current definitions cannot discover
  removed starters. After processing a kind's registry, query provenance rows for that
  `(tenant, kind)` whose `source_id` was NOT encountered in the registry and mark them
  `orphaned_source` (report; never delete — destructive removal stays a separate explicit
  operator action per §8.4).

Sync and `--all` sweeps default to **active tenants only**; `--all` uses
`TenantContextRunner::forEachTenant` exactly as built (deterministic order, fail-fast,
`TenantIterationException` names the offending tenant). Schema changes remain additive-only.

## §7 Commands

- `thallo:tenant:seed <uuid>` — provisioning-eligible repair (§5).
- `thallo:tenant:sync [<uuid>|--all]` — umbrella, dependency order (content types → block
  types → regions).
- `thallo:tenant:blocks:sync [<uuid>|--all]` — block-type-only reconcile.
- **Legacy `thallo:blocks:seed|sync`** preserve their existing create-only/additive algorithms
  and exact output. **Pinned:
  when tenancy is ON they require an explicit `--tenant=<uuid>` or `--all` — never silently
  guess a tenant in CLI, then run those same algorithms inside the selected tenant context.
  When tenancy is OFF, current single-store behavior is preserved byte-for-byte and writes no
  provenance. They do not route through fingerprint sync: operator-added block fields must not
  prevent the legacy additive command from appending a newly-added starter field.**
- HTTP: `POST /v1/admin/tenancy/tenants` (SP2a) now runs seed-and-activate synchronously.
  Validation before tenant creation remains 422. A seed failure after the tenant row was
  committed returns 500 with `{ tenant_uuid, status: 'provisioning', failed_definition,
  repair_command }` under the framework error envelope's `error.details`; it MUST NOT return a
  bare validation response that hides the persisted resource and makes a retry collide on slug.
  (Creation UI ships after SP2b per the index — this completes its prerequisite.)
- The HTTP controller and `thallo:tenancy:tenant create` command call
  `TenantSeedActivator::seedAndActivate()`; neither imports `TenantSeeder` or any other
  app-owned starter implementation. If the activator binding is unavailable, they fail before
  creating a tenant (HTTP 503 / CLI failure), never return a false `active` status.

## §8 Failure modes

Seed failure → rollback, `provisioning`, non-zero exit / structured HTTP 500 with the tenant
uuid and failing definition named. Suspended tenant → seed refuses; sync skips (active-only
default). `--all` failure → sweep stops with the offending uuid. Fingerprint drift → a unit
test freezes each kind's canonical form so a normalizer change that shifts fingerprints must
be deliberate (test updated in the same change). Rename collision / customized rename →
skip-and-report (never overwrite). Duplicate source provenance → impossible by the §4 unique
constraint (violation = engine bug surfacing loudly).

## §9 Testing

- **Acceptance:** create → one action → `active` tenant whose subdomain serves the starter
  homepage at `/` through its stored `homepage_entry`, with header (logo + a visible `Home`
  item from `main`) and footer; a failpoint
  injected inside the seed transaction → tenant back to clean `provisioning` (zero starter
  rows, zero provenance), then `thallo:tenant:seed` completes it and the site serves.
- **Transaction participation (pinned):** integration test proving
  `TenantAdministration::markActive()` executes on the SAME PostgreSQL connection as the
  starter writes (assert PDO identity) and rolls back with them — after the induced failure,
  tenant status is still `provisioning` AND no starter/provenance rows exist. Cache purges /
  events asserted to fire only post-commit. A separate `\Error` failpoint proves
  `StarterTransaction` restores its incoming level and a subsequent write succeeds on the
  same connection.
- **Actor resolution:** create passes the selected owner through unchanged; repair chooses the
  first active owner deterministically; a tenant with no active owner fails before any starter
  or provenance write; actor/timestamp differences do not change fingerprints.
- **Sync matrix:** source change → propagates to unchanged tenants; customized tenant
  skipped + `customized` recorded; source removal → `orphaned_source` marked via the
  removed-source query (including a tenant that never had the definition — no false
  orphan); rename happy path (row + provenance key renamed) + rename-collision and
  rename-of-customized (skip + report); default-tenant adoption run over install-era rows
  (adopt `applied`/`customized` by natural/adoption key; tenant-authored rows untouched); an
  injected failure between definition mutation and provenance refresh rolls the kind back and
  retry applies normally rather than marking it customized; restoring a customized row to its
  recorded fingerprint rejoins `applied` and allows later source updates.
- **Command matrix:** legacy `thallo:blocks:*` × (tenancy on: bare invocation fails with
  guidance, `--tenant`/`--all` work) × (tenancy off: byte-identical current behavior), plus the
  exact `thallo:tenant:blocks:sync` command and an operator-field + starter-field additive
  regression.
- **Boundary wiring:** controller + tenant-management CLI tests inject a fake
  `TenantSeedActivator`, while the repair command injects `TenantSeedRepair`; an architecture
  assertion rejects `App\` imports under the pack's tenant-creation surfaces; both app bindings
  resolve to the shared `TenantSeeder` instance.
- **Regression:** tenancy-off and tenancy-on full suites; SP2a inert/full-resolution
  acceptance untouched; `SetupService::install()` output proven unchanged by existing setup
  tests.

## §10 Out of scope

Destructive removal of orphaned starters (separate operator command, post-SP2c); template
(`render_templates`) seeding (§8.5 defers — templates are shared theme files); queued/async
seeding; per-tenant starter *pack selection* (all tenants get the one Thallo starter surface);
the SP2c disable path itself (it consumes `starter_provenance`; its gates are specified
there).
