# Collections Tenancy — Design

**Status:** spec in review (HELD — not committed)
**Slice:** Bucket 2, item 2C (collections tenancy — unfence the deliberate `collections.*` fence).
**Release chain:** **Thallo-only.** `glueful/tenancy ^1.3.0` is already a hard dependency and
`TenantProvisioner` already exists; no framework/contract/engine source change is needed. Thallo activates
the installed engine provider from first boot so its identity-plane services and migrations are available.
**Date:** 2026-07-11
**Revision:** 3 — implementation-plan review findings integrated.

---

## §0 Context — as-built (source-verified)

- **Collections materialize physical tables.** Unlike content types (per-tenant definitions stored as
  JSON, entries as JSON — already row-scoped), each collection creates a real physical table with
  typed columns + indexes. That physical-table-per-definition model is the whole value (real SQL
  querying) and the reason collections were excluded from `ThalloTenantTables` and fenced off.
- **Global naming today.** `CollectionManager::tableNameFor($name) = 'coll_' . $name`;
  `collection_definitions` has `unique('uuid')`, `unique('name')`, `unique('table_name')` and **no**
  `tenant_uuid`. Two tenants' `posts` would both derive `coll_posts` — a collision.
- **The fence.** `Thallo\Tenancy\Runtime\CollectionsDisabledWhenTenantMiddleware` returns `503` while
  tenancy is enabled; it sits on the collection route groups as `collections_disabled_when_tenant`.
- **Public route auth is not tenant-bound.** Public/headless collection routes use `optional_api_key`
  + `collection_scope`, which default-denies unless `api_key_scopes` includes
  `collections.{name}.read|write|delete`. These scopes name a collection, **not** a workspace — so an
  `X-Tenant-Id` header plus `collections.products.read` must **not** be allowed to select an arbitrary
  tenant.
- **Physical-table consumers (must all become resolved-definition-only):** `CollectionManager`,
  `Data/RowRepository`, `Relations/RelationResolver` (**currently scans collections globally** — the
  sharpest cross-tenant leak), `Schema/SchemaMaterializer` + `Schema/DdlPlanner` + `Schema/ColumnMapper`
  (schema/index inspection + DDL), `Http/Controllers/CollectionDataController` +
  `CollectionAdminSchemaController`, `Http/CollectionAccessResolver`, any import/export path, and the
  audit/event emitters.
- **Purge hook.** `Thallo\Tenancy\Purge\Handlers\CollectionsPurgeHandler` is a registered no-op
  (slice 2), an explicit topological owner reserved for when collections tenancy lands. `PurgeJob`
  already durably checkpoints handler artifacts.
- **Tenancy modes.** `SystemFlags` tracks `tenancy.enabled`, `tenancy.schema_state`, and a shared
  `tenancy.default_tenant_uuid`. `CompatWriteScope` stamps that default only in `compat`
  (disabled_widened) mode. `Retrofit/DefaultTenant::ensure()` provisions the default tenant (under
  the enablement flow's lock, with persisted provisioning uuid + owner membership + default pointer)
  at enable-time. A clean,
  never-enabled install has **no** default tenant and no active stamper/table-hooks. `TenantProvisioner`
  (contract + `ContractTenantProvisioner`) and `TenantProvisioner::hasAnyTenant()` exist.
- **Provider availability.** The package is hard-required, but its Glueful provider is not currently in
  `config/extensions.php`; therefore `TenantProvisioner`, `TenantContextRunner`, and the tenant tables'
  migrations are unavailable during clean-install setup. Collections' uniform single-store model requires
  the provider to be **bundled-active as the identity/provisioning plane from first boot**. This does not
  enable row scoping: `tenancy.enabled` plus Thallo's empty-until-enabled table registry remain the
  enforcement switch. The SP1 install/activate states remain backward-compatible skips for upgraded hosts.

---

## §1 Goal & tenancy model

Make dynamic collections tenant-scoped so every workspace owns both its collection **definitions** and
its collection **data**, matching Thallo's established per-tenant ownership model. **Model A —
per-tenant tables & definitions**, one code path and one physical naming scheme in every tenancy mode:

- **Metadata tables** (`collection_definitions`, `collection_schema_changes`) — shared tables that gain
  `tenant_uuid` and are **explicitly self-scoped by every repository operation** (see §4). They also
  join `ThalloTenantTables` for ownership/retrofit metadata, but the repositories never rely on the
  registry/hooks for scoping (inert on clean-off installs).
- **Dynamic data tables** (`tc_…`) — **per-tenant physical tables**; isolation is **structural** (the
  table is the tenant's). They carry **no `tenant_uuid` column**, are **never** registered in
  `ThalloTenantTables`, and are never row-scoped. The manager resolves the current tenant, loads the
  tenant-scoped definition, and reads the definition's stored physical name — it never accepts a
  caller-supplied table string.

Rejected alternatives (brainstorm): global-schema/per-tenant-rows (reintroduces a shared model);
collections-as-JSON (removes the typed-SQL capability that justifies collections).

---

## §2 Single-store tenant

Collections require exactly one deterministic tenant owner in every mode. A `SingleStoreTenant` service
provides it and becomes the **canonical provisioner**.

**Resolution (`resolve(): string` tenant uuid):**
- **enabled** → the request's resolved current tenant. **Fail closed** if request resolution is absent
  — it must **never** fall back to the `default_tenant_uuid` pointer once tenancy is on.
- **compat (disabled_widened)** → `CompatWriteScope`'s default tenant.
- **disabled (clean)** → the ensured single-store tenant (the persisted `tenancy.default_tenant_uuid`).
- Missing identity in compat/disabled after `ensure()` should have run → **fail closed** (no silent
  global fallback), surfaced as a clear infrastructure error.

**Provisioning consolidation.** `SingleStoreTenant::ensure(...)` owns provisioning. On the shared
`Connection`, it opens/joins one transaction, acquires
`pg_advisory_xact_lock(hashtextextended('thallo:single-store-tenant', 0))`, then **freshly reloads**
the provisioning/default pointers after the lock. It reuses the persisted provisioning uuid when
present or records a new intended uuid, calls the idempotent `TenantProvisioner::provisionDefault()`
(tenant + active owner membership), and writes `tenancy.default_tenant_uuid` before commit. All flag,
tenant, and membership writes participate in that same transaction; rollback leaves no partial tenant
or pointer. A connection-participation/rollback test pins this invariant.

`DefaultTenant::ensure()` is **refactored to delegate** to `SingleStoreTenant`, so the enable-time
retrofit and `TenantProvisioner::hasAnyTenant()` observe the **same** tenant established during setup.
The old pre-existing-tenant refusal is centralized: an existing tenant is accepted only when it is the
uuid recorded by the provisioning/default pointer; unrelated tenant rows still fail closed.

**Eager establishment.** `SetupService` calls `SingleStoreTenant::ensure(...)` right after creating the
install administrator (owner = the install admin), inside the existing install transaction, and passes
the returned uuid into `SeedContext` for every starter write. Clean-off setup never relies on an inactive
stamper to infer tenant identity.
Provisioning is a deliberate install step, **not** an incidental DDL side effect of collection DDL.
There is no automatic collection-manager provisioning fallback. An existing installation missing the
pointer uses an explicit operator CLI repair command requiring a real owner user uuid; it invokes the same
idempotent `ensure()`. API-key, background-job, anonymous, or ownerless collection requests never provision
infrastructure: they fail closed and point to that command.

**Bundled-active prerequisite.** `Glueful\Extensions\Tenancy\TenancyServiceProvider` is activated before
`Thallo\Tenancy\TenancyServiceProvider`, and its migrations run before first-time setup. The extension's
configured table list is empty and Thallo registers owned tables only when `tenancy.enabled=1`, so identity,
provisioning, and context-runner services are available while scoping remains off. Tests pin both halves:
`TenantProvisioner` resolves during clean setup, while a clean-off collection metadata query receives no
automatic tenant predicate or stamp and therefore remains dependent on explicit repository scoping.

---

## §3 Physical naming & metadata schema

**Physical table name (opaque, bounded, identifier-safe):**

```
tc_<tenant-token>_<collection-token>
  tenant-token     = lowercase RFC 4648 base32 (no padding) of raw sha256 bytes,
                     first 10 chars                                             (deterministic)
  collection-token = 12 random chars from [a-z0-9]                              (per collection)
```

Rationale: nano-id tenant uuids are case-sensitive and may contain identifier-hostile characters — a
raw uuid must never appear in a PostgreSQL identifier. `tc_` (3) + 10 + `_` (1) + 12 = **26 chars**,
well under the 63-byte limit. A single `CollectionPhysicalName` helper owns tenant-token derivation,
random collection-token generation, exact table validation/tenant-prefix validation, and bounded
index naming. Index names are deterministic and at most 63 bytes, using a readable bounded prefix plus
a hash suffix over `{table_name,field_name,index_kind}`; add/drop paths call the same helper, preventing
PostgreSQL truncation collisions between long field names. `table_name` is stored as at most 63
characters and stays the **stored source of truth**; the manager reads it and never re-derives from the
human name. A **rename mutates `name`/`label` metadata only** — the physical table is never renamed.

**Metadata schema (folded pre-launch into the create-table migrations, not ALTER):**
- `collection_definitions`: add `tenant_uuid`. **Widen only** `unique('name') → unique('tenant_uuid','name')`.
  Give the two relevant constraints stable names: `uniq_collection_def_tenant_name` and
  `uniq_collection_def_table_name`.
  **Keep `unique('uuid')` and `unique('table_name')` globally unique** (tokens guarantee no cross-tenant
  collision; a global `table_name` unique is the backstop for the create-retry loop). Do **not** widen
  any other unique.
- `collection_schema_changes`: add `tenant_uuid`; add an index on `(tenant_uuid, collection_uuid)`.
  Every repository read/write against this table constrains **both** `tenant_uuid` **and**
  `collection_uuid`.

---

## §4 Manager, repositories & the resolved-definition-only rule

Every collection operation resolves `tenant_uuid` via `SingleStoreTenant` **first**, then self-scopes —
explicitly, in all modes (clean-off has no active stamper or table hooks; the registry cannot be
assumed to scope the request).

- **Metadata repositories self-scope.** `CollectionDefinitionRepository` constrains `tenant_uuid` on
  every read/write; the schema-changes writer constrains `tenant_uuid` + `collection_uuid`.
- **`create()`** — resolve tenant → generate `table_name` → **one transaction (or savepoint) per
  attempt** covering the definition insert + physical DDL. A PostgreSQL uniqueness violation **aborts
  the transaction**, so a `unique(table_name)` collision must **roll the whole attempt back and retry
  in a fresh transaction/savepoint** with a new collection token — never catch-and-retry inside an
  already-aborted transaction. Retry is bounded (five attempts) and occurs **only** for PostgreSQL
  SQLSTATE `23505` naming `uniq_collection_def_table_name`; a
  `uniq_collection_def_tenant_name` violation is a real duplicate-name conflict and returns immediately,
  while every other database/DDL failure propagates unchanged. Name uniqueness is enforced by
  `unique(tenant_uuid, name)`.
- **Resolved-definition-only rule.** Every physical-table consumer takes a **resolved
  `CollectionDefinition`** carrying both `tenantUuid` and the stored `table_name`, and never a
  caller-supplied physical table string. Repository hydration, schema-change records, and every
  collection event preserve that tenant identity. This rule must cover, exhaustively:
  `CollectionManager`, `RowRepository`,
  **`RelationResolver`** (retargeted from its current global scan to resolve only within the current
  tenant's definitions), `SchemaMaterializer`/`DdlPlanner`/`ColumnMapper` (schema + index inspection
  and DDL), `CollectionDataController`/`CollectionAdminSchemaController`, `CollectionAccessResolver`,
  any import/export path, and the audit/event emitters. Controllers resolve the tenant-scoped
  definition first, then hand the object down.
- **Rename** mutates metadata only (§3).

---

## §5 Tenant selection & authorization (security)

Tenant selection for collections must be **authorization-bound**, never an unbound `X-Tenant-Id` plus a
collection scope. Three surfaces, three rules:

- **Browser / public delivery** — resolve the tenant **strictly by verified host** (the tenancy public
  resolver). No header-based override.
- **Central headless API — one concrete mechanism.** Add the Thallo-owned, system-global
  `thallo_tenant_api_key_bindings` table: `api_key_uuid` (globally unique), `tenant_uuid`,
  `created_at`, `updated_at`, with an index on `tenant_uuid`; foreign keys target `api_keys.uuid` and
  `tenants.uuid` with cascade delete as a backstop (normal revocation/purge still deletes explicitly).
  It is authorization metadata, not a tenant-owned table, so it does **not** join
  `ThalloTenantTables`. `OptionalApiKeyAuthMiddleware`
  already emits the verified `api_key_uuid`; a new collections tenant-binding middleware runs after
  it and before `collection_scope`:
  - a key request must have exactly one binding;
  - a host-resolved tenant must equal that binding;
  - `X-Tenant-Id` is accepted only when it exactly equals that binding;
  - missing, malformed, mismatched, or multiply-resolved candidates fail closed before definition
    lookup.
  After validation, the middleware wraps the remaining pipeline with the neutral
  `TenantContextRunner::runAsTenant($boundTenantUuid, ...)`; it never mutates the extension's concrete
  request context directly.
  Collection scopes remain the per-collection allow-list *within* the bound tenant. Anonymous public
  access remains host-only. JWT/claim-based central selection is deferred; v1 has one authority path.
- **Binding lifecycle.** The existing API-key admin surface gains operator-only bind/unbind behavior
  (requires tenancy management authority). Key creation may bind a tenant; rotation copies the binding
  to the successor in the same application transaction; scope edits retain it; explicit revocation
  deletes the binding in the same application transaction. A key is never implicitly bound from its owner's
  current workspace selection.
- **Admin** — the existing chain: `tenant_profile:admin` → `tenant_bootstrap` → permission middleware.

Public and headless requests share the existing `/v1/collections` route surface, so they use one
conditional chain rather than duplicate route registrations:
`tenant_profile:public,soft` → `optional_api_key` → collections tenant binding → `tenant_bootstrap` →
`collection_scope`. With no API key, the binding middleware requires the verified-host resolver to have
selected a tenant and rejects `X-Tenant-Id`; with an API key, it requires exactly one stored binding,
rejects every conflicting host/header candidate, and enters that tenant through `runAsTenant`. Admin uses
`auth` → `tenant_profile:admin` → `tenant_bootstrap` → permission middleware in the separate
`admin-routes.php` file.

---

## §6 Fence removal, routes & permissions

- **Remove `CollectionsDisabledWhenTenantMiddleware`** (the `collections_disabled_when_tenant` fence)
  once the uniform per-tenant path is proven — collections stop returning `503` under tenancy.
- Admin collection routes run under the normal tenant-resolution/bootstrap middleware; public/headless
  routes adopt §5's binding.
- **Permissions are added to the workspace role matrix deliberately:** `owner` and `admin` receive
  `collections.manage`, `collections.schema.manage`, and `collections.data.manage`; `member` and
  `viewer` receive none in v1. The current `collections.data.manage` includes destructive operations
  (delete/truncate), so granting it to `member` would violate the editorial-role boundary. Finer
  collection read/write/delete permissions are a future matrix expansion, not inferred here.

---

## §7 Purge & single-collection deletion

Two distinct owners, no overlap:
- **`CollectionsPurgeHandler`** is the sole owner of **workspace purge** of collections. The implementation
  lives in `thallo-collections`, not `thallo-tenancy`, so the tenancy pack never imports an optional
  capability. `thallo-collections` has a declared one-way dependency on `thallo-tenancy` and publishes the
  handler under an optional service alias consumed by the tenancy registry factory. It plugs into
  the slice-2 `PurgeJob`, whose durable artifact checkpointing is the correct mechanism.
  `TablesPurgeHandler` excludes both collection metadata tables from its generic target set; ownership
  never overlaps merely because those metadata tables also appear in `ThalloTenantTables`.
  - **`prepare`** — from the tenant-scoped `collection_definitions`, capture durable artifact tuples
    `{definition_uuid, table_name}` plus the target tenant uuid, and capture the tenant's API-key
    binding uuids.
  - **`purge`** — derive the expected 10-character tenant token again from the **purge target uuid**.
    For each artifact table name: (1) validate it against the exact pattern
    `^tc_[a-z2-7]{10}_[a-z0-9]{12}$`; (2) require the exact prefix
    `tc_<derived-target-token>_`; (3) require a unique `{definition_uuid,table_name}` tuple in the
    prepared artifact set; (4) when the physical table still exists, require a live
    `collection_definitions` row matching **all three** of target `tenant_uuid`, `definition_uuid`, and
    `table_name`; an already-absent table is an idempotent retry skip; (5) `DROP TABLE IF EXISTS`.
    Pattern/artifact membership alone is insufficient — a valid table from another tenant must never
    be droppable through a corrupted artifact. Metadata remains until every drop succeeds, so this live
    ownership proof is available on every first or partial-retry drop. Delete the
    tenant's `collection_schema_changes`, `collection_definitions`, and
    `thallo_tenant_api_key_bindings` rows **only after every physical drop has succeeded**. The binding
    table is introduced solely for the collections headless surface in v1, so this handler owns its
    workspace-purge cleanup.
  - **`verify`** — receives the same durable artifact map passed to `purge`; none of this handler's prepared
    artifact tables, metadata rows, or API-key binding
    rows remain for the target tenant. It never asserts that no `tc_*` tables exist globally; other
    tenants' tables and bindings must remain.
  - Independent node in the topological registry (no dependencies).
- **`CollectionManager::dropCollection()`** still owns normal **operator deletion of one collection**
  (its existing confirmation + transactional definition-delete + `DROP TABLE`), now tenant-scoped.

Never interpolate a table name that fails pattern validation, target-token validation, artifact tuple
validation, or (for an existing table) live metadata ownership. `CollectionManager::dropCollection()`
applies the same target-token validation to its already tenant-resolved definition before normal
single-collection deletion.

---

## §8 Scoping-lint, `ThalloTenantTables` & retrofit

- **`ThalloTenantTables`** gains **only** `collection_definitions` + `collection_schema_changes`.
  `collection_definitions` declares only the widened `(tenant_uuid,name)` unique; its `uuid` and
  `table_name` uniques remain global. `collection_schema_changes` has **no widened unique** — it gains
  `tenant_uuid` plus the `(tenant_uuid,collection_uuid)` index. `tc_*` tables are never registered.
- **`RawPdoScopingLintTest`** declares `tc_*` as **PER_TENANT_PHYSICAL** (analogous to the existing
  `GLOBAL_BY_PROOF` list) with the proof: one physical table per tenant, name generated + stored +
  manager-derived, never caller-supplied, dropped as a unit on purge — so row-level `tenant_uuid`
  scoping is neither present nor required.
- Supported fresh installs receive `tenant_uuid`, `(tenant_uuid,name)`, and the schema-change
  composite index from the folded collection migrations before collection rows can exist. The
  enable-time retrofit is therefore idempotent for these tables: its definitions metadata knows only
  the widened `name` unique and normal tenant index, while diagnostics additionally requires the
  folded `(tenant_uuid,collection_uuid)` schema-change index. Runtime conversion of a legacy narrow
  collection schema is not supported (§9); `tc_*` tables are untouched by the retrofit.

---

## §9 Release chain & development adoption

- **Thallo-only.** No framework/contract/engine release; `glueful/tenancy ^1.3.0` and `TenantProvisioner`
  already provide everything needed. The installed provider becomes bundled-active so those identity-plane
  services and migrations exist before setup; runtime enforcement remains flag-controlled. Package
  dependency direction is one-way: `thallo-collections` → `thallo-tenancy`; the tenancy pack discovers the
  optional purge handler by service alias and never imports collections classes. The Thallo migration creating
  `thallo_tenant_api_key_bindings` is system-global and is deliberately excluded from
  `ThalloTenantTables`.
- **Existing local `coll_*` data is not converted** by the folded migrations. Ship an explicit one-time
  **local reset / dev-adoption procedure** (drop legacy `coll_*` tables + their `collection_definitions`
  rows and recreate under the tenant-scoped path, or reset the dev DB) documented in the plan. This is a
  pre-launch project; no runtime data migration is shipped.

---

## §10 Failure modes

- Two tenants create a same-named collection → both succeed; distinct `tc_*` tables; metadata rows
  distinguished by `(tenant_uuid, name)`.
- `table_name` token collision → the aborted attempt rolls back and retries in a fresh
  transaction/savepoint with a new token.
- Clean-off install creates the first collection → `SetupService` already established the single-store
  tenant; a pre-existing install repairs only through the explicit owner-identified operator command;
  API-key/background/anonymous/ownerless collection calls fail closed.
- `X-Tenant-Id` + a collection scope without a matching `thallo_tenant_api_key_bindings` row →
  refused before definition lookup (§5).
- Enabled mode with no resolved request tenant → fail closed, never the default pointer.
- Workspace purge → only the target tenant-token's prepared `tc_*` tables are dropped, then its
  metadata is deleted; a second tenant's physical tables and metadata remain; verify green gates the
  engine record purge.
- `RelationResolver` asked to expand across tenants → resolves only within the current tenant's
  definitions.
- Enabling tenancy after collections exist → the same `default_tenant_uuid` is adopted; no collection
  migration; `TenantProvisioner::hasAnyTenant()` sees the shared tenant without triggering the
  unrelated-tenant refusal.

---

## §11 Testing

- **Isolation:** two tenants' same-named collections get distinct physical tables + independent data;
  neither can read/write the other's rows; `RelationResolver` never crosses tenants. Hydrated
  `CollectionDefinition` objects and emitted collection events retain the correct `tenantUuid`.
- **Naming/retry:** token format `tc_[a-z2-7]{10}_[a-z0-9]{12}`; a forced `table_name` collision retries
  in a fresh transaction and succeeds; rename leaves `table_name` unchanged.
- **Single-store resolution:** enabled→current, compat→CompatWriteScope default, disabled→ensured
  tenant; enabled-with-no-resolution fails closed (no default fallback); `DefaultTenant` delegates so
  `TenantProvisioner::hasAnyTenant()` sees the setup-established tenant; `SetupService` establishes it
  eagerly. A same-connection rollback test proves tenant, membership, provisioning pointer, and default
  pointer all roll back together; concurrent ensure calls converge under the transaction advisory lock;
  API-key/system/ownerless repair attempts are refused.
- **Self-scoping in clean-off:** metadata reads/writes constrain `tenant_uuid` (and `collection_uuid`
  for schema_changes) with the registry/hooks inactive.
- **Authorization:** public resolves strictly by host; headless requires an API-key binding before an
  explicit tenant id; host/binding mismatch and unbound `X-Tenant-Id` are refused; rotation copies the
  binding; revocation removes it; the validated middleware establishes context through
  `TenantContextRunner`; admin chain remains intact.
- **Purge:** `prepare` captures definition/table tuples; `purge` validates pattern + target-derived
  tenant token + artifact tuple + live metadata ownership before `DROP TABLE IF EXISTS`, treats an
  already-absent artifact as an idempotent retry, deletes metadata only after all drops, removes the
  target's API-key bindings, and proves both a corrupted foreign artifact is refused and another tenant
  survives; `verify` gates completion; `dropCollection()` still deletes a single collection.
- **Permissions:** owner/admin receive all three management capabilities; member/viewer receive none.
- **Identifier safety:** the shared physical-name helper produces identical tenant tokens in create and
  purge, keeps table/index identifiers within 63 bytes, and gives long/similar field names distinct,
  reversible add/drop index names.
- **Regression:** tenancy off/on suites, slice-1/2/3 suites, and existing collections tests stay green
  (collections tests migrate to the tenant-scoped path).

---

## §12 Out of scope

PostgreSQL schema-per-tenant isolation; cross-tenant collection sharing/templates; JWT/claim-based
central tenant selection; finer member-level collection read/write/delete capabilities; runtime
migration of pre-existing `coll_*` data (dev-adoption procedure only); changing collections'
JSON-vs-physical storage model; non-`table` storage modes.
