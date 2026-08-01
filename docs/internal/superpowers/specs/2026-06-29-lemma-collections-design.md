# `glueful/lemma-collections` — Design

**Status:** Approved design (brainstorm complete). Next: implementation plan.
**Date:** 2026-06-29

**Goal:** A removable capability pack that gives Lemma a **BaaS data layer** — developer-defined
"collections" backed by **per-collection real database tables** (PocketBase-style), with an
auto-generated public CRUD/query API, an admin schema builder + data browser, scoped API-key auth,
and an owner/actor model that leaves room for row-level rules later.

**Architecture:** A composable-core capability pack (`lemma.collections`) that owns its own tables,
depends only on `lemma-contracts` + framework + the explicit extension deps it imports, and never on
`glueful/lemma`. A collection *definition* (metadata, JSON field list) drives **runtime DDL** (Glueful
`SchemaBuilder`) to materialize and evolve a real table. The pack reuses a new, deliberately small
shared **field-type registry** seam in `lemma-contracts`; it is otherwise self-contained (its own
storage, query engine, delivery surface, and admin).

**Tech stack:** PHP 8.3 / Glueful framework (DI `services()`, `SchemaBuilder` runtime DDL, Router +
middleware, EventService, `api_keys` + scopes, Aegis RBAC); Vue 3 + Nuxt UI admin SPA; MySQL /
PostgreSQL / SQLite.

---

## Global Constraints

- **Pack boundary.** `glueful/lemma-collections` depends on `glueful/lemma-contracts` and
  `glueful/framework` — **never on `glueful/lemma`**. `asset` validation/expansion uses the
  framework's `Glueful\Repository\BlobRepository` (blobs are framework-level — **no `glueful/media`
  dependency**); the api-key auth + public-API middleware aliases are core-provided and referenced by
  alias, never by class. No `App\*` references in pack source. Enforced by `composer boundaries` (the
  existing `scripts/check-pack-boundaries.php`).
- **Capability.** Ships a `lemma.collections` `Capability`; installed = migrations registered; enabled
  (default-on) = routes/jobs/subscribers/admin active. Disabling preserves all tables. **Never
  auto-drops data tables on disable/remove.** The enabled gate is applied at **boot** (routes are
  registered only when enabled — no per-request capability check), so **toggling enabled/disabled takes
  effect only after the route cache/manifest is rebuilt or the app reboots**, as with any extension toggle.
- **Public API is default-deny.** A collection is not readable/writable just because it exists.
- **Public IDs are `uuid`, never the internal numeric `id`.**
- **No silent data loss.** Every destructive operation is explicit-confirm + audited.

---

## 1. Positioning — what this is, and what it is not

Lemma is a composable core: a canonical **content engine** + `lemma-contracts` + removable capability
packs. `lemma-collections` is the **BaaS data layer** — the "I need a table-like thing with an API and
an admin UI" tool — and is deliberately **distinct from the content engine**.

- **It is** PocketBase-shaped: a *collection definition* owns and migrates a *real table*; you think in
  collections, the pack manages the underlying table.
- **It is not** Supabase-shaped (it does not reflect a raw, externally-managed Postgres schema).
- **It is not** the content engine. Content is editorial — drafts, versions, publish spine, locales,
  preview, delivery cache, JSONB fields. Collections are **structured transactional app data** — typed
  columns, real indexes, direct CRUD, no editorial lifecycle. Modeling app data as content types would
  be a disservice; this pack is the right tool for it.
- **It is not** a relational-database designer. No hard foreign keys, cascades, compound constraints,
  or migration choreography. Apps that need those use Glueful app code + normal migrations. Collections
  stays "fast table-like app data."

---

## 2. The two surfaces (split, by design)

| Surface | Audience | Auth | Responsibilities |
|---|---|---|---|
| **Admin** (rides existing `/v1/admin` + Aegis session) | Operators/developers | Aegis RBAC | Schema/model management, table materialization, index management, guarded drops, permissions, diagnostics, data browser |
| **Public data API** (`/v1/collections/{name}`) | App clients (SPA/mobile/server) | **Scoped API keys** (v1); end-user tokens in the actor model, richer per-user rules later | CRUD + query over collection rows |

### Actor model

Every public request resolves to an **actor**: `api_key | user | admin`. The actor's `(type, id)` is
stamped into each row's `created_by_*` / `updated_by_*` system columns. This is the load-bearing
future-proofing: owner-scoped row-level rules land later with **zero schema change**.

### v1 authorization = scoped API keys, default-deny

- Public access requires an API key carrying the right **per-collection scope**:
  `collections.{name}.read`, `collections.{name}.write`, `collections.{name}.delete`.
  (Wildcard/admin scopes are a later addition; v1 starts explicit.)
- Reuses Lemma's hardened `api_keys` table + the existing public-API middleware pattern already used by
  the content **delivery API** (`OptionalApiKeyAuthMiddleware` + a `require_*_scope` middleware) — a
  proven, default-deny, scoped read/write surface, not net-new security design.
- **End-user tokens** are representable in the actor model from day one but are *not* the heavy v1
  security promise: without row-level rules, a user token grants collection-level access only.

### Permissions are split (schema ≠ data)

- **Admin/Aegis permissions** (who can model + manage): `collections.manage` (umbrella),
  `collections.schema.manage` (create/alter/drop structure + indexes), `collections.data.manage`
  (write rows through the admin data browser).
- **Public data scopes** (who can read/write rows over the API): `collections.{name}.{read|write|delete}`
  on API keys.
- "Who can change the schema" and "who can write a row" never blur into one permission.

---

## 3. Foundational layer — the shared field-type registry (`lemma-contracts`)

Collections, Content, and (later) Forms all model "fields." To prevent three divergent registries
ossifying — without pretending a `rich_text` and a `decimal(12,2)` are the same — `lemma-contracts`
gains a **small registration/discovery seam** (not a universal schema):

- **`FieldTypeDefinition`** — `key`, `label`, `valueShape`, `validationRules`, `adminWidget`, and
  capability flags (`filterable`, `sortable`, `indexable`, `multi`, `localized`).
- **`FieldTypeRegistry`** — `register()`, `get(key)`, `all()`.
- **Namespaced keys** to avoid collisions: `content.text`, `collections.text`, `collections.decimal`,
  `collections.relation`, … (admin UI may hide the prefix; contract keys are always namespaced).
- **Domain metadata stays domain-specific** and lives in each domain's own field payload — the registry
  standardizes only discovery/validation-hook/widget-mapping/capabilities:
  - content keeps `localized`, `format`, `reference_type`, …
  - collections keeps `column_type`, `length`, `precision`, `scale`, `nullable`, `unique`, `index`.
  - forms (later) keeps `placeholder`, `help_text`, submission behavior.

**Integration:** core/content **registers its editorial field types** through the registry (a modest
change to existing content schema wiring so there is one discovery surface); collections registers its
column-shaped types in its provider's `boot()`. This registry is built as the **first section of this
plan**, since collections is its driving consumer.

---

## 4. Data model

### 4.1 Definition metadata — `collection_definitions` (pack-owned)

The system of record for **structure** (the materialized table is the system of record for **data**):

| Column | Notes |
|---|---|
| `id` | internal bigint PK |
| `uuid` | public id |
| `name` | unique handle used in the API path (`/v1/collections/{name}`) |
| `label` | display name |
| `table_name` | the physical table, `collection_<uuid-or-hash>` (decoupled from `name`) |
| `storage_mode` | `varchar(16)` not-null default `'table'`; v1 accepts only `'table'` (reserved for future storage engines) |
| `fields` | JSON — the ordered field list (mirrors `content_types.schema`) |
| `schema_version` | bumped on each accepted schema change (definition is versionable) |
| `status` | `active` (room for future states) |
| `created_at`, `updated_at` | |

Physical tables are named `collection_<uuid/hash>`, **never the raw `name`** — avoids collisions and
makes a future display-name/slug change a metadata-only edit (no physical rename).

`storage_mode` is **reserved for future storage engines**. V1 supports only `table` (per-collection real
tables, the system of record); document/JSONB-backed collections are explicitly **rejected until v2**.
Carrying the column from v1 means a future engine is additive — no definition row needs migrating.

### 4.2 Materialized data table — `collection_<uuid/hash>`

Every collection table carries Glueful's standard identity/audit shape:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK autoincrement | internal only |
| `uuid` | string, unique | **public id**, prefixed nanoid (e.g. `prod_abc123`); the only id the API exposes |
| `created_at`, `updated_at` | timestamp | |
| `created_by_type`, `created_by_id` | string | actor model (`api_key\|user\|admin`, + id) |
| `updated_by_type`, `updated_by_id` | string | |

…plus one real column per user-defined field (§4.3).

### 4.3 v1 field types → column mapping

| Field type | Column | Field metadata |
|---|---|---|
| `text` | `varchar(length)` (default 255) | `length` |
| `longtext` | `text` | — |
| `integer` | `int`, or `bigint` if `bigint:true` | `bigint` |
| `decimal` | `decimal(precision, scale)` | `precision`, `scale` |
| `boolean` | boolean | — |
| `date` | `date` | — |
| `datetime` | `timestamp`/`timestamptz` | — |
| `json` | `json`/`jsonb` | — |
| `email` | `varchar(255)` | + email validation |
| `url` | `varchar(2048)` | + URL validation |
| `enum` | `varchar` | `values[]` (app-level allowed-value validation — portable, evolvable; not a DB enum) |
| `relation` (single) | `varchar` (target row `uuid`) | `target: collection:{name}`, soft |
| `relation` (multiple) | `json` (array of target `uuid`s) | `target`, `multi:true` |
| `asset` (single) | `varchar` (blob `uuid`) | validate + expand only |
| `asset` (multiple) | `json` (array of blob `uuid`s) | `multi:true` |

Common per-field metadata: `nullable`, `unique`, `index`, plus the registry capability flags. The
capability flags follow storage reality: scalar types are `filterable`/`sortable`/`indexable` —
**except `longtext`** (→ `TEXT`), which is `indexable` but **not `filterable`/`sortable`** (a `TEXT`
column can't be plainly indexed or sorted); `json`/`relation`/`asset` are neither filterable nor
sortable.

**`asset` is tightly scoped:** collections validate blob UUIDs and can **expand** blob
metadata/display URLs on read. Upload/storage stays in the existing media/blob layer. Collections never
auto-delete blobs, transform images, or become a media library.

---

## 5. Schema lifecycle (runtime DDL)

Glueful's `SchemaBuilder` (`createTable`/`alterTable`/`dropTable`/`hasTable`, per-driver SQL
generators) executes the DDL. A **DDL planner** diffs the new definition against the current one and
emits the allowed operations.

### Allowed in v1
- **Create collection** — insert `collection_definitions` row + `createTable(collection_<hash>)` with
  system columns + field columns + indexes.
- **Add field** — `alterTable` add column (+ index if requested).
- **Add / remove index** — single-field `normal` or `unique` (composite indexes deferred).
- **Drop field** — destructive flow (below).
- **Drop collection** — destructive flow (below); drops the table + the definition row.

### Blocked in v1 (return a clear, typed error)
- **Rename field** (no rename op — a name change is a `drop` + `add`).
- **Any in-place change to an existing field's column definition** — `type` (retype), `nullable`,
  `length`, `precision`, `scale`, `bigint`, the relation/asset `target`, and a single↔multiple flip —
  throws `BlockedSchemaChangeException`. v1 has **no "alter column" op**: to remodel a field's physical
  definition, **explicitly drop + re-add it** (through the destructive drop flow). Algorithm: compare
  the field's **storage signature excluding `index`/`unique`**; if it changed for an existing field
  name, block — then diff `index`/`unique` *separately* into `add_index`/`drop_index`.
- A **`unique` index** added on existing data that violates it is rejected by the materializer's
  pre-flight (Task 6) — a clear "duplicate data" error.

### Destructive flow (drop field / drop collection)
1. Mark the change destructive; require the client to **type the field/collection name** to confirm +
   a data-loss acknowledgment.
2. **Empty-table light path:** if the collection has **no rows**, allow the drop without the heavy
   acknowledgment (free iteration while modeling).
3. Execute DDL in a **transaction where supported** (Postgres/SQLite). **MySQL auto-commits DDL** — so
   on MySQL the **`collection_schema_changes` audit record + idempotent re-apply** carries the recovery
   guarantee a transaction cannot.
4. Write a `collection_schema_changes` record.
5. **Never auto-drop** on capability disable/remove.

### Audit — `collection_schema_changes` (pack-owned)
`id`, `uuid`, `collection_uuid`, `change_type` (`create|add_field|drop_field|add_index|drop_index|drop_collection`),
`payload` (JSON diff), `actor_type`, `actor_id`, `destructive` (bool), `created_at`.
The pack owns this table so `glueful/audit` is **not** a hard dependency; audit can subscribe/enrich
later.

---

## 6. Public data API — `/v1/collections/{name}` (keyed by `uuid`)

Default-deny; per-request actor resolution; per-collection API-key scope required.

### Read
- `GET /v1/collections/{name}` — list. Query params:
  - `filter[field][op]=value` — ops: `eq, ne, lt, lte, gt, gte, like, in, null` (over real columns —
    simpler/cheaper than content's JSONB filtering).
  - `sort=field,-other`
  - `fields=` — field selection.
  - `expand=rel1,rel2` — relation expansion, **one level, bounded** (no recursive expansion).
  - **offset pagination** — `page` / `perPage` (keyset is a later opt-in for large collections).
- `GET /v1/collections/{name}/{uuid}` — one row.

### Write
- `POST /v1/collections/{name}` — create one row. Validate against field rules + `required`/`nullable`/
  `unique`; set `created_by_*` / `updated_by_*` from the actor; return the created row (with `uuid`).
- `PATCH /v1/collections/{name}/{uuid}` — **partial** update (merge changed fields only).
- `DELETE /v1/collections/{name}/{uuid}` — delete one row, subject to **restrict-if-referenced** (§7).
- `POST /v1/collections/{name}/bulk` — **strict all-or-nothing bulk create**:
  - capped by config (`max_bulk` default 100);
  - **validate every row first**; if any fails, **reject the whole request** with per-row errors;
  - if all pass, insert in **one transaction where supported**; return created rows/ids;
  - **no partial success** in v1.

### Deferred (explicitly not v1)
Bulk **patch**/**delete** by filter, **upsert**, partial-success imports, keyset pagination.

---

## 7. Relations (soft, collection ↔ collection)

- **Soft references** — stored as the target row's `uuid` (single → varchar column; multiple → JSON
  array). No hard DB foreign keys, no cascades.
- **Target descriptor** is future-proof: `target: "collection:{name}"` in v1; `target: "content:{type}"`
  reserved (resolved later via the existing `ContentDeliveryReader` contract — **not** v1).
- **Validate on write** — referenced row ids must exist unless the relation is nullable/empty.
- **Expand on read** — `?expand=field`, one level, bounded.
- **Restrict delete if referenced** — deleting a row that other rows point at is refused (soft
  *integrity*, via a reverse-reference check across relation fields targeting this collection). A later
  `on_delete: restrict|nullify|cascade` option is out of scope for v1.

---

## 8. Change events

On row create/update/delete, the pack emits `CollectionRowCreated` / `CollectionRowUpdated` /
`CollectionRowDeleted` on the framework `EventService` (mirroring content's lifecycle events). This is
the **subscription seam**: the deferred `lemma-realtime` pack, webhooks, and search subscribe later
without coupling. v1 emits pack-owned event classes; **promoting them to `lemma-contracts`** is the
clean step when a cross-pack subscriber (realtime) is actually built (YAGNI until then).

---

## 9. Admin (Vue, in the existing SPA — gated on `lemma.collections`)

Both ship in v1 — a schema builder without a data browser feels unfinished. Kept basic:

- **Schema builder** — list collections; create a collection; add/remove fields (type + per-type
  settings: length/precision/scale/nullable/unique/index, relation target, enum values, asset
  single/multi); manage single-field indexes; guarded **drop field / drop collection** flows (type-name
  confirm, data-loss ack, empty-table light path).
- **Data browser** — per-collection table list (paginated), a **create/edit drawer**, **delete** with
  restrict errors surfaced. No advanced relation visualizations, no inline bulk tools in v1.
- **Permissions** — manage per-collection API-key scopes from the admin.
- Mounts via the capability registry, matched by `lemma.collections` id (the established static V1
  admin-module pattern).

---

## 10. Boundary, dependencies, and what's added

- **New in `lemma-contracts`:** `Schema/FieldTypeDefinition`, `Schema/FieldTypeRegistry` (§3).
- **New pack `glueful/lemma-collections`** owns: `collection_definitions`, `collection_schema_changes`,
  and every `collection_<hash>` data table; the DDL planner; the public data API + middleware; the
  admin controllers; the `lemma.collections` capability + admin module.
- **Core/content change:** registers its editorial field types through the new `FieldTypeRegistry`.
- **Composer deps:** `lemma-contracts` + `glueful/framework` — never `glueful/lemma`. `asset`
  validation/expansion uses the framework `Glueful\Repository\BlobRepository`; api-key auth + public-API
  middleware are core-provided and used by alias (no `glueful/media` dep).

---

## 11. Out of scope (seams deliberately left)

| Deferred | Seam that keeps it additive |
|---|---|
| **Realtime** | Its own `lemma-realtime` pack, subscribing to the §8 change events |
| **Row-level / expression rules** | The `created_by_*` actor columns (§4.2) + the actor model (§2) |
| **Rename / retype field** | Physical-table naming decoupled from `name` (§4.1); definition is versioned |
| **Bulk patch/delete by filter, upsert** | Single-record + strict bulk-create shipped first (§6) |
| **Collection → content relations** | `target` descriptor already supports `content:{type}` (§7) |
| **Composite indexes, keyset pagination** | Single-field indexes + offset shipped first (§5, §6) |
| **File upload/transform ownership** | `asset` fields reference the existing media/blob layer only (§4.3) |
| **Multi-tenancy** | A future `tenant_*` column slots into the §4.2 system columns |

---

## 12. Testing strategy

- **DDL planner unit tests** — definition-diff → expected operation list; blocked-op rejections;
  pre-flight validations (unique-on-dup, nullable-tighten-on-nulls).
- **Schema lifecycle integration** (per driver where feasible) — create/add-field/add-index/drop-field/
  drop-collection materialize and evolve a real table; empty-table light path vs populated destructive
  flow; audit records written; disable/remove never drops tables.
- **Field-type mapping** — each type → correct column + validation (including `asset` UUID validation,
  `relation` existence checks, `enum` allowed values).
- **Public API** — CRUD + partial PATCH; filter/sort/offset/fields/expand; strict all-or-nothing bulk
  create (all-pass insert, any-fail whole-reject with per-row errors, cap enforcement); default-deny +
  per-collection scope enforcement; actor stamping into `created_by_*`.
- **Relations** — validate-on-write, bounded one-level expand, restrict-delete-if-referenced.
- **Change events** — emitted on create/update/delete.
- **Boundary** — `composer boundaries` stays green (no `App\`, no `glueful/lemma`).
- **Removability** — disabling the capability preserves tables and hides the admin + public API; the
  content engine and other packs are unaffected.

---

## 13. Open questions (for the plan, not blockers)

- **Public-id prefix** — per-collection configurable prefix (e.g. `prod_`) vs a global scheme; nanoid
  alphabet/length.
- **Reserved field/collection names** — guard against colliding with system columns (`id`, `uuid`,
  `created_by_*`, …) and SQL keywords during definition validation.
- **`enum` storage** — app-level validation is decided; whether to also emit a DB `CHECK` on Postgres is
  a plan-time call (portability vs enforcement).
