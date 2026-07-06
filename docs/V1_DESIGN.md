# Thallo V1 Technical Design

This document makes the architecture decisions that [APPROACH.md](APPROACH.md)
deliberately left open. These are the decisions that are expensive to reverse
once content exists in real installs: how dynamic fields are stored, what the
published read path is, where the locale dimension lives, and what the
publishing pipeline guarantees. Everything else in v1 can be refactored;
these four mostly cannot.

Status: **implemented — V1 headless backend closure review.**

---

## 1. Field storage: single entries spine + JSONB values

**Decision:** content-type field *values* live in a JSONB column on
version rows. There is no table-per-content-type and no EAV.

**Rejected alternatives:**

- *Table-per-content-type* (Craft/Drupal generated tables): runtime DDL on
  model changes is operationally dangerous (locks, migration ordering,
  multi-tenant explosion later) and makes model evolution a schema migration
  instead of a data operation.
- *EAV*: query complexity and row explosion for no benefit PostgreSQL's JSONB
  doesn't already provide with better ergonomics.

**Rationale:** JSONB keeps model changes as pure data operations, revisions as
cheap full snapshots, and PostgreSQL provides GIN/expression indexing for the
filterable subset. The schema stays relational for everything durable
(identity, state, routing, references, publication) per APPROACH.md — JSONB is
*only* the field-value payload.

### Core tables

```
content_types
  uuid, slug (unique), name, description
  schema JSONB              -- field definitions: name, type, validation,
                            -- localized?, filterable?, required?, ...
  schema_version INT        -- bumped on every schema change
  created_at, updated_at

entries                     -- locale-neutral identity spine
  uuid, content_type_uuid FK
  status                    -- 'active' | 'archived' | 'deleted' (soft)
  created_by, created_at, updated_at

entry_drafts                -- single mutable working copy (see §2)
  entry_uuid FK, locale
  fields JSONB
  schema_version INT
  lock_version INT          -- optimistic concurrency; stale writes get 409
  updated_by, updated_at
  PRIMARY KEY (entry_uuid, locale)

entry_versions              -- immutable, append-only; written at publish
  uuid, entry_uuid FK
  locale                    -- locale code; the default locale in v1
  version INT               -- monotonic per (entry, locale)
  fields JSONB              -- full snapshot of field values
  schema_version INT        -- content_types.schema_version at write time
  created_by, created_at
  UNIQUE (entry_uuid, locale, version)

entry_publications          -- the published read path (see §2)
  entry_uuid FK, locale
  version_uuid FK -> entry_versions
  published_at, published_by
  PRIMARY KEY (entry_uuid, locale)

entry_routes
  entry_uuid FK, content_type_uuid FK, locale, slug
  UNIQUE (content_type_uuid, locale, slug)
  -- content_type_uuid is denormalized (derivable via entries) precisely so
  -- the uniqueness constraint and route lookups never join entries

entry_references            -- normalized reference index (see §4)
  source_entry_uuid, source_field, target_entry_uuid
  UNIQUE (source_entry_uuid, source_field, target_entry_uuid)
```

### Querying dynamic fields

The delivery API filters/sorts only on fields the model marks `filterable`.
A filterable field **must declare a filter type** — `string`, `number`,
`boolean`, `datetime`, or `enum` — in its schema definition. The filter type
is what makes the rest deterministic: it fixes the index expression cast
(`(fields ->> 'price')::numeric`), the permitted filter operators (`gt`/`lt`
for number/datetime, equality/`in` for string/enum/boolean), and the query
validation that rejects anything else. Without it, index generation and
operator validation are guesswork.

For each filterable field, Thallo creates the corresponding PostgreSQL
expression index on `entry_versions` — created by a queued job when the model
changes (`CREATE INDEX CONCURRENTLY`), never inline in a request. The index is a
**latency optimization, not a filterability gate**: query validation only
requires that a field be schema-`filterable` with a declared `filter_type`, so
filtering works whether or not the expression index exists yet — with the index
it seeks, without it (e.g. a managed Postgres install that cannot run
`CREATE INDEX CONCURRENTLY`, or one created manually out-of-band) it scans but
returns the same results. See **Resolved V1 decisions → Expression-index
lifecycle**.

**Framework note:** the Glueful query builder currently exposes
`whereJsonContains()` only. Richer JSONB predicates (`->>` comparisons, `@>`
containment with paths, typed casts) go through `whereRaw()` *with bindings*
in v1. If these call sites multiply, propose a `whereJsonPath()` family in the
framework rather than accumulating raw SQL — track as a framework work item,
not a Thallo blocker.

### Schema evolution

Field values are validated against `content_types.schema` on write. Old
versions keep the `schema_version` they were written under and are **not**
rewritten on schema change; the delivery layer tolerates missing/extra keys
(additive changes are free).

**V1 behavior — content models are field-append-only.** Additive changes (add a
field, change a field's `description`/`required`/`filterable`/`filter_type`) are
allowed. **Destructive changes — deleting or retyping a field — are rejected with
a `422`** (`ContentTypeRepository::updateSchema()` detects them via
`destructiveChanges()`). A **rename** is therefore not expressible in V1: it
surfaces as a delete + add, so the rejection stands; the workaround is to add the
new field and leave the old one (its values remain under the old key in existing
versions, harmlessly, since delivery tolerates extra keys). This keeps every
published `entry_versions` snapshot valid as written and avoids any read-time or
index-cast surprise on historical rows.

**Backfill is a V1.x/V2 feature, not V1.** Safely migrating existing draft and
published content across a destructive change is a feature in its own right — it
needs explicit rename/retype/delete intent, a queued backfill over published
versions, a draft/version-history policy, reference/index rebuild, and failure
reporting — none of which should ship half-built against immutable published
content. When it lands, the admin gains an explicit model-migration step that
enqueues a backfill job over current published versions only, with history
staying as written; until then, the `422` is the deliberate, documented V1
contract. **Implemented design:** [`docs/superpowers/specs/2026-06-16-destructive-schema-backfill-design.md`](superpowers/specs/2026-06-16-destructive-schema-backfill-design.md) (delete + rename; retype deferred) — shipped 2026-06-17.

---

## 2. Drafts, versions, and the published read path

**Decision:** three distinct lifecycles, each in its own table.

- **Draft** = one mutable working copy per (entry, locale) in `entry_drafts`.
  Editing and autosave write *this row only*, guarded by optimistic
  concurrency (`lock_version`; a stale write returns 409 so concurrent
  editors stomp on nothing silently). Discard draft = delete the row —
  published state is untouched. Autosave never creates versions, so history
  doesn't explode at keystroke granularity.
- **Version** = immutable, append-only `entry_versions` row, written **at
  publish** (the draft's fields are validated and snapshotted). History is
  therefore a list of meaningful publish points, not editing noise.
- **Publication** = the pin: publish upserts `entry_publications` (one row per
  entry+locale) pointing at the exact version, inside one transaction with
  the version write.

The delivery API reads **only** through
`entry_publications JOIN entry_versions` — there is no status column to filter
on and therefore no "forgot the WHERE clause" class of draft leakage. Drafts
aren't even in a table the delivery repository touches.

- Unpublish = delete the `entry_publications` row.
- Rollback = re-pin an older version row.
- **Scheduled publish/unpublish is implemented.** Scheduled publish is deferred
  execution of the normal publish action: at `run_at`, Thallo validates and
  publishes the current draft; if validation fails, the entry remains unchanged
  and the schedule row records the failure. Scheduled unpublish is the symmetric
  deferred call to the normal unpublish path. There are no special publication
  states and no delivery read-path changes. **Design + plan:**
  [`docs/superpowers/specs/2026-06-16-scheduled-publish-design.md`](superpowers/specs/2026-06-16-scheduled-publish-design.md)
  (+ [implementation plan](superpowers/plans/2026-06-16-scheduled-publish.md)).

**Product language rule:** because versions are written at publish, the UI
and docs call this **"version history"** (or "published revisions") — never
"revisions" unqualified or anything implying every save is captured. Editors
coming from per-save-revision CMSes would otherwise expect autosaves in the
history. If named draft snapshots ("save a checkpoint without publishing")
are ever wanted, that is a separate explicit feature appending to
`entry_versions` without pinning — an additive change, not the default save
path.

This is one indexed join on the hot path. If profiling ever shows the join
matters, the escape hatch is denormalizing `fields` onto `entry_publications`
at publish time — a pure optimization with the same semantics, deferred until
measured.

Draft reads (admin + preview) go through a separate repository over
`entry_drafts`; the public delivery repository physically cannot see them.

---

## 3. Locale dimension: i18n-backed content localization

**Decision:** `locale` is a column on `entry_drafts`, `entry_versions`,
`entry_publications`, and `entry_routes` from the first migration. V1 supports
localized content at the backend/API layer: enabled-locale validation, per-locale
drafts, per-locale publishing state, per-locale routes, locale-variant summary,
copy-to-locale draft creation, preview tokens bound to `{entry, locale}`, and
single-entry delivery fallback through the configured i18n fallback chain.

**Rationale:** retrofitting a locale dimension onto live content tables is the
single most painful CMS migration there is. Carrying the column costs one
config default; adding localization later becomes feature work instead of a
schema rewrite.

**Thallo V1 requires `glueful/i18n` for locale infrastructure.**
The extension ships a locale registry (`LocaleManagerInterface`) with
single-parent fallback chains (loop-protected), a request locale resolver
(`LocaleResolverInterface`: explicit → `?locale=`/`X-Locale` → user claims →
tenant → app locale), catalogs, pluralization, and a locale management
API/CLI. The division of labor:

- `ContentLocaleService` is Thallo's local seam over i18n. It reads
  enabled/default/fallback locales from `LocaleManagerInterface`; the
  `glueful/i18n` dependency is part of Thallo's V1 product core, not an
  optional localization mode.
- Admin authoring, route assignment, publishing, rollback/version listing, and
  preview-token minting validate locale params against the enabled locale set.
- `GET /v1/admin/entries/{uuid}/locales` lists the per-locale draft,
  publication, and route state that the admin UI needs for translation status.
- `POST /v1/admin/entries/{uuid}/locales/{locale}` creates a target-locale
  draft, optionally copying field values from a source-locale draft.
- Single-entry delivery reads (`/v1/content/{type}/{slugOrUuid}`) walk the i18n
  fallback chain for route slugs and entry UUIDs, while preserving the actual
  served locale in the response payload.

V1 supports backend per-locale RBAC through Aegis resource-filtered grants: locale-targeted
admin routes authorize against `locale:<code>` while locale-agnostic routes keep the coarse
`thallo` resource. Thallo does not add a UI/API for assigning per-locale grants; that remains an
admin-interface workflow layered on top of Aegis. **Implemented design:**
[`docs/superpowers/specs/2026-06-16-per-locale-rbac-design.md`](superpowers/specs/2026-06-16-per-locale-rbac-design.md)
(via Aegis resource filters; per-content-type deferred).

Field-level localization (`localized: true` in the field schema) is already
representable. V1 keeps whole-entry locale variants as the persisted unit; a
future editor can use the flag to identify translated fields. Thallo now automates
flag-aware copy-on-create for locale drafts; copy-on-change remains deferred.
**Implemented design:** [`docs/superpowers/specs/2026-06-16-field-localization-design.md`](superpowers/specs/2026-06-16-field-localization-design.md)
(flag-aware copy-on-create; copy-on-change deferred).

---

## 4. References: JSONB values + normalized index, resolved at delivery

**Decision:** reference fields store target **entry UUIDs** (never version
UUIDs) inside `fields` JSONB, and Thallo maintains the `entry_references` table
as a write-time projection (rebuilt on every draft save inside the same
transaction, snapshotted through publish).

- References point at *entries*; the delivery API resolves them to the
  target's **published** version at read time. A referenced entry that is
  unpublished resolves to null/omitted — never to a draft.
- `entry_references` exists for the queries JSONB is bad at: reverse lookups
  ("what links here"), orphan detection, delete-protection blocks, and
  cascade decisions in the admin.
- Expansion in the delivery API uses the framework's field-selection expander
  seam with batch loading (one query per referenced type per request), not
  per-entry lazy fetches.
- Circular references are allowed in data, bounded at delivery by the
  field-selection depth limit.

Deleting an entry that others reference is allowed but surfaced in the admin
(via `entry_references`) before confirming; broken references resolve to
omitted at delivery.

---

## 5. Publishing pipeline contract: events, cache, invalidation

Everything downstream of publish hangs off one transaction and its
`afterCommit` hook:

```
publish(entry, locale):
  db transaction:
    validate draft against content_types.schema
    append entry_versions snapshot from the draft
    upsert entry_publications pin
    write audit row
  afterCommit:
    dispatch domain events (BaseEvent)
    invalidate cache tags
    enqueue: webhook deliveries, CDN purge (if glueful/cdn),
             search index update (if a content reindexer is bound)
```

**`afterCommit` is verified real, with one stated limitation.** Glueful's
`Connection::afterCommit()` (backed by `TransactionManager`) executes the
callback immediately when outside a transaction, promotes callbacks to the
parent level in nested transactions so they fire only on the *outermost*
commit, and discards them on rollback. What it cannot survive is the process
dying between commit and callback execution: the side effects above are
in-process. Consequence for v1: every downstream effect must be **re-drivable
and idempotent** (cache tags can be re-invalidated, search can be reindexed,
CDN re-purged — and a `thallo:resync` command exists to do so), and a missed
webhook is acknowledged as possible. If webhook delivery guarantees ever
become a product requirement, the upgrade path is a transactional outbox
table written inside the publish transaction and drained by a worker — a
contained change to this section, not a redesign.

### Event taxonomy (v1, frozen as API contract)

| Event | Fired when |
| --- | --- |
| `entry.created` | entry identity created (first draft) |
| `entry.updated` | draft saved (autosave-debounced; not one event per keystroke) |
| `entry.published` | version pinned (includes re-publish/rollback) |
| `entry.unpublished` | publication row removed |
| `entry.deleted` | entry soft-deleted |
| `model.created` / `model.updated` / `model.deleted` | content type changes |
| `asset.attached` / `asset.detached` | blob reference added/removed from an entry |

Webhook subscriptions use core `Api\Webhooks` (subscription, signing, retries,
delivery tracking) with these event names — Thallo does not build webhook
infrastructure, it defines the taxonomy and payloads (entry UUID, type, locale,
version, actor, timestamp; never full field payloads by default — receivers
fetch via the delivery API with their own key).

### Cache tags

- `thallo:entry:{uuid}` — invalidated on publish/unpublish/delete of that entry.
- `thallo:type:{slug}` — invalidated on any publish within the type (covers
  list endpoints) and on model changes.

Delivery responses carry `ETag` (hash of version UUID + selection) and
`Cache-Control` per content-type setting. CDN purge mirrors the same two-tag
granularity.

---

## 6. Delivery API (public, read-only)

```
GET /v1/content/{type}                 -- list published entries
GET /v1/content/{type}/{slug-or-uuid}  -- single published entry
```

- **Auth:** core `api_keys` (`gf_live_*`/`gf_test_*`) with a `read:content`
  scope, or per-type scopes (`read:content:{type}`). Public/anonymous access
  is opt-in per content type via `public_delivery`; invalid supplied API keys
  still fail before public access is considered.
- **Field selection & expansion:** the framework's `?fields=` / `?expand=`
  (REST and GraphQL-style syntaxes) with the **content-type schema as the
  whitelist** — not the framework's static `#[Fields]` route attribute.
  Delivery is intentionally dynamic: the allowed fields are derived per request
  from the model definition (`schema->fields()`), so the whitelist tracks the
  model and rejects any undeclared field. `#[Fields]` suits fixed DTO/controller
  shapes; it cannot express a per-model dynamic surface, so Thallo deliberately
  uses the schema-derived allow-list instead. This is the product's answer to
  GraphQL — stated, deliberate.
- **Filtering/sorting:** `?filter[field]=` / `?sort=` restricted to the
  model's `filterable` fields (§1); cursor pagination (stable under
  publish churn), page/perPage also supported for convenience.
- **Rate limiting:** framework rate limiter on all delivery routes, keyed by
  API key (falling back to client IP for public types).
- **Preview:** `GET /v1/preview/{token}` — short-lived signed token (HMAC,
  `APP_KEY`, TTL ~ minutes, bound to entry+locale) minted by the admin API.
  Grants read of that entry's current draft (or a specific pinned version for
  historical preview). No "preview mode" flag on the public API — preview is
  a different, narrow door.

Admin/editor API lives under `/v1/admin/...` (full CRUD on models, entries,
versions, publications, routes), bearer-auth via `glueful/users`, and is the
contract the bundled admin SPA is built against. Both APIs are in the OpenAPI
doc from day one; the delivery API is the stability-promised surface.

---

## 7. Permissions (v1: coarse, honest about it)

Three hierarchical roles via `glueful/aegis`, named and seeded by Thallo:

- **admin** — content models, API keys, users, everything;
- **editor** — entries CRUD + publish across all types;
- **viewer** — read-only admin access.

Permission names are namespaced (`thallo.models.manage`, `thallo.entries.write`,
`thallo.entries.publish`, ...). Per-content-type restriction later uses
Aegis's **native resource-level filters** — checks take a resource argument
(`can($user, 'thallo.entries.write', 'content-type:{slug}')`) — not
type-encoded permission names, so the v1 permission list never has to be
renamed. Aegis's direct user grants and temporal (expiring) permissions come
for free with this choice. Per-locale and workflow-state permissions arrive
with their features, not before. V1 does not pretend to have fine-grained
editorial permissions; it just checks the namespaced permission with no
resource argument.

---

## 8. Media

As per APPROACH.md: field values store blob UUIDs; `thallo.media_disk` selects
the Glueful storage disk; `glueful/media` provides transforms when installed
(absent it, originals only — same degradation contract as the framework).
`asset.attached`/`asset.detached` events come from the reference projection
(§4) treating asset fields like entry references, giving "where is this asset
used" for free.

---

## 9. Export / portability (v1-adjacent, before any public release)

**Thallo does not build export machinery — `glueful/import-export` is the
engine.** It already ships job/batch/error/report persistence, queue-backed
deterministic batches, dry-run vs commit modes, engine-owned retry, streaming
NDJSON and ZIP-bundle readers/writers (ZIP-slip protected), lifecycle events,
and HTTP + CLI job management. Thallo implements the adapters
(`ExporterInterface` / `ImporterInterface`, registered by service tag), per
the boundary in [ADAPTER_NOTES.md](ADAPTER_NOTES.md): the engine runs the job;
Thallo defines what the records mean.

The v1 adapters produce/consume a versioned ZIP bundle: content types, entries
with full version history (or published-only as an adapter option), routes,
publications, and an asset manifest. The asset manifest is deliberately
metadata, not binary storage: it contains the referenced `blobs` row data plus
a `fetch_path` that tells an operator or storage sync process where the asset
bytes should come from. Import recreates the referenced blob metadata in the
core `blobs` table, stripping `fetch_path`, so restored content references
resolve by UUID when the same storage backend/bucket has already been restored
or mounted.

Thallo import/export does **not** copy asset bytes. In a cross-storage restore,
operators must transfer the files separately; otherwise the imported blob row
can resolve while the actual `/blobs/{uuid}` fetch still 404s. This is an
intentional boundary: Glueful core owns the blob table and configured storage
layer, while Thallo restores only the blob metadata that its content references
as part of a full content-bundle restore.

Import upserts content records by UUID and uses the engine's dry-run mode for
validation previews. This is the portability promise behind "canonical source",
the content-level backup story, and — not incidentally — the test fixture
format.

The WordPress, Markdown/MDX, and CSV importers (post-v1, ADAPTER_NOTES.md)
are additional adapters on the same engine; the bundle adapters built here
establish the pattern they follow.

---

## 10. Database & testing posture

- **PostgreSQL is required for v1.** The JSONB decisions (§1) are
  Postgres-semantics; supporting MySQL/SQLite would mean designing for the
  intersection and losing the reason Postgres was chosen. Revisit only with
  real demand, as a port, not a v1 constraint.
- **CI runs against real PostgreSQL.** The framework's SQLite test harness
  does not exercise JSONB operators or expression indexes; Thallo's integration
  suite needs a Postgres service from the first pipeline. Unit tests that
  don't touch JSONB can keep SQLite for speed.

### Multi-tenancy: deliberately not in the v1 schema

`glueful/tenancy` (built; row-level shared-database tenancy) scopes
tenant-owned tables through a `tenant_uuid` column, a `BelongsToTenant` ORM
trait, and a raw-SQL interceptor backstop. Thallo v1 integrates with the broader
Glueful ecosystem but does **not** carry `tenant_uuid` columns in its content
tables. This is the opposite call from the locale decision (§3) for a structural
reason:

- **Locale is identity-defining** — it multiplies rows (versions,
  publications, routes exist *per locale*), so retrofitting it would redefine
  primary keys and row identity. It must be in migration one.
- **Tenant is a pure partition dimension** — the retrofit is bounded and
  additive: add `tenant_uuid`, backfill every row with a default tenant,
  set NOT NULL, extend the unique constraints
  (e.g. `entry_routes` becomes `UNIQUE (tenant_uuid, content_type_uuid,
  locale, slug)`), add the `BelongsToTenant` trait to Thallo models. No row
  identity changes, no data rewrite.

Carrying the column now would also be actively awkward: with the extension
absent it references nothing, and a *nullable* `tenant_uuid` inside unique
constraints does not enforce uniqueness on PostgreSQL (NULLs compare
distinct), so single-tenant installs would need a sentinel tenant value — a
permanent wart for a future feature.

So tenancy is not blocked by ecosystem availability; it is consciously scoped
as a V1.x/V2 Thallo integration track. When Thallo needs tenant-owned content,
the work is a coordinated content-schema migration and repository/model
adoption of `glueful/tenancy`, not a prerequisite for closing the V1 headless
backend.

Design rules that keep the retrofit cheap (cost nothing today):

- all cross-table joins/identifiers are UUIDs (already true), so cache tags
  (`thallo:entry:{uuid}`) and references stay globally unique under tenancy;
- repositories never assume table-wide uniqueness of slugs beyond the
  constraint itself — uniqueness checks go through the constraint, not
  application-level "does this slug exist" scans;
- background jobs already carry context (the tenancy extension propagates
  tenant into queue/scheduler/CLI when installed).

---

## 11. Build order

Thallo is scaffolded from **`glueful/api-skeleton`** — the documented
quick-start — as deliberate dogfooding of the new-project experience
(see APPROACH.md, Technical Foundation). Any onboarding friction found while
building Thallo is filed against the skeleton/framework, not worked around.

1. **Framework release first, then a tagged api-skeleton** — Thallo pins a
   released `glueful/framework`, and `composer create-project` needs a tagged
   skeleton that itself pins that release (the skeleton's current state is
   untagged on dev; the tenancy extension is waiting on the same framework
   release — do not start a second product on `dev`).
2. Scaffold the Thallo app from the skeleton (users/aegis enabled via the
   capabilities switchboard, PostgreSQL configured); then migrations +
   models/repositories for §1's tables; validation of field values against
   `content_types.schema` (framework DTO/Validator).
3. Admin API: content types CRUD, entries/versions, immediate/scheduled publish/unpublish
   (§2 semantics), routes.
4. Delivery API: list/single + field selection + filterable-field indexes +
   API-key scopes + rate limits + ETag/cache tags.
5. Publishing pipeline: events, webhooks taxonomy, cache invalidation;
   CDN/search enqueues behind capability checks.
6. Preview tokens.
7. Admin SPA (Vue 3 + Vite + Nuxt UI) against the admin API; typed client
   from the OpenAPI doc.
8. Export/import bundle adapters on the `glueful/import-export` engine.
9. Hardening pass: permissions seeding, rate-limit defaults, OpenAPI polish,
   Postgres CI matrix.

Each step is shippable to a demo; nothing depends on a later step to be
correct.

---

## Resolved V1 decisions

- **Expression-index lifecycle:** V1 keeps generated filter indexes as an
  operational optimization, not a hard correctness dependency. Index creation
  is queued/out-of-band where supported; managed Postgres installs that cannot
  run `CREATE INDEX CONCURRENTLY` may create equivalent indexes manually or run
  without them. Query validation still enforces filterable fields and typed
  operators either way.
- **Version retention:** V1 keeps unlimited published version history by
  default. Configurable pruning is deferred until after export/import is
  established as the safety net; pruning must never run before a portable
  content bundle can preserve history. **Implemented design:**
  [`docs/superpowers/specs/2026-06-16-version-pruning-design.md`](superpowers/specs/2026-06-16-version-pruning-design.md)
  (CLI-only this iteration; scheduled pruning deferred) — shipped 2026-06-17.
- **Redirects:** `entry_routes` carries current route rows only in V1.
  Redirect rows wait for the SEO/routing module so status codes, chains,
  canonical URLs, and admin UX land together instead of as a half-feature in
  core content routing. **Implemented design:**
  [`docs/superpowers/specs/2026-06-16-seo-routing-module-design.md`](superpowers/specs/2026-06-16-seo-routing-module-design.md)
  (full SEO/routing module) — shipped 2026-06-17.
- **License/distribution:** the backend source is distributed under the
  repository license (`MIT` today). Product packaging, hosted/commercial tiers,
  or admin-build distribution can be decided separately; they do not block the
  V1 headless backend.
