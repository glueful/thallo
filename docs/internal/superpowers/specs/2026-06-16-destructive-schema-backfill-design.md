# Destructive Schema-Change Backfill (V1.x) — Design

**Goal:** Let an operator make a **destructive** content-type schema change — **delete a
field** or **rename a field** — and safely migrate existing draft and published content to
the new shape, without ever mutating an immutable published version and without a visible
half-migrated window.

**Scope of this iteration:** **delete + rename only.** Retype (value coercion) is deferred;
the op model is built to accept it later.

**Status:** ✅ Shipped (2026-06-17) — implemented and reviewed.

**Backlog item:** [POST_V1.md](../../POST_V1.md) §2. Resolves the deferral documented in
[V1_DESIGN.md](../../V1_DESIGN.md) §1 ("Backfill is a V1.x/V2 feature, not V1").

> V1_DESIGN §1: a destructive change "needs explicit rename/retype/delete intent, a queued
> backfill over published versions, a draft/version-history policy, reference/index rebuild,
> and failure reporting — none of which should ship half-built against immutable published
> content." Today `ContentTypeRepository::updateSchema` rejects delete/retype with a 422
> (`destructiveChanges()`); a rename surfaces as delete+add. That 422 stays the contract for
> the plain schema-edit endpoint; this feature adds an explicit, tracked migration path.

---

## The safety invariant (read this first)

**Lazy forward projection is the correctness invariant of this whole feature — not an
optimization.** Every fields-bearing row is tagged with the `schema_version` it was written
against (`content_types.schema_version`, `entry_versions.schema_version`,
`entry_drafts.schema_version` all already exist). A recorded, ordered **migration log** lets
any blob be **projected forward** to the current schema by replaying the ops between its
version and current. Because of this:

> Reading content at *any* point during a backfill is always correct: a row already
> materialized to the current version is served as-is; a row that still lags is projected
> on the fly. There is no state in which a published read returns the old field shape. The
> background backfill is merely **eager materialization** of a projection that is already
> correct lazily.

This is what makes "flip the schema first, converge in the background" safe, and it is why
the projector lives on the **delivery read path**, not just admin reads.

## Definition of behavior (the contract)

> An operator submits an explicit migration (a list of `rename from→to` / `delete field`
> ops) for a content type. Lemma validates it against the current schema, advances the
> schema version, and enqueues a backfill. From that moment every read reflects the new
> schema (via materialized rows or lazy projection). The backfill rewrites drafts in place
> and writes a new migrated published version for each published entry+locale, re-pinning
> it; prior versions are never mutated. A migration is forward-only; to reverse a change,
> submit a compensating migration.

## Scope decisions (settled during brainstorming)

1. **Delete + rename only.** Both are lossless, mechanical field-map transforms. Retype is
   deferred (needs value coercion / lossy-cast policy); `MigrationOp` is extensible for it.
2. **Strict immutability — new migrated version + re-pin.** For each currently-published
   `(entry, locale)`, the backfill writes a **new** `entry_versions` row with the projected
   fields and re-pins it. The pre-migration pinned version and all prior versions are never
   updated ("history stays as written").
3. **Explicit, dedicated migration endpoint.** `POST /content-types/{slug}/migrations` with
   an explicit ops list — rename intent **cannot be inferred** from a schema diff
   (delete-`a`+add-`b` is ambiguous), so it must be stated. The plain
   `PATCH …/schema` endpoint keeps rejecting destructive changes.
4. **Flip-first + lazy projection** (the invariant above). The schema version advances
   immediately; the backfill converges in the background; reads stay correct throughout.
5. **Forward-only + resume.** Migrations don't roll back. A failed/partial backfill is
   re-runnable and idempotent; reversal is a new compensating migration.
6. **One active migration per content type.** At most one non-terminal (`pending`/`running`)
   migration per type, enforced by a partial-unique index — serializes ordering, projection,
   and failure reporting. Different content types migrate independently.
7. **No new §5 content event.** A migration is a structural backfill, not an editorial
   publish: it emits **no** `entry.published`/`entry.unpublished` and **no** asset-detach
   *content* event. It does rebuild derived projections (references, filter indexes) and
   invalidates delivery cache so consumers see the migrated shape.

## Architecture (units, each independently testable)

### 1. Op model — `app/Content/Schema/Migration/` (pure, the heart)
- `MigrationOp` — `apply(array $fields): array`, plus `toArray()/fromArray()`.
- `RenameField(from, to)` — moves `$fields[from]` to `$fields[to]`.
- `DeleteField(name)` — unsets `$fields[name]`.
- `MigrationOpSet` — ordered list; `apply()` runs ops in order; array (de)serialization for
  storage. No I/O — exhaustively unit-testable. Extensible later (`RetypeField`).

**Rename collision semantics (decision):** `RenameField` moves `from→to` only when `to` is
absent. If `to` is **already present with a value**, that is a data anomaly (it cannot arise
from schema-conformant content, since schema validation guarantees `to` was not a declared
field before the rename). Two behaviors, deliberately different by caller:
- **Materialization (backfill):** `apply()` **throws** `MigrationCollisionException`; the
  backfill records that entry in the failure report and moves on (it is **not** overwritten).
- **Projection (read path):** a non-throwing variant keeps the existing `to`, drops `from`,
  and logs a warning — a read must never error. (Unreachable for conformant data; defense
  in depth.)

### 2. `entry_schema_migrations` table — the log *and* the operation record
```
entry_schema_migrations
  id                bigint pk autoincrement
  uuid              char(12)        -- public id (nanoid)
  content_type_uuid char(12)
  from_version      int             -- schema_version this migration advances FROM
  to_version        int             -- = from_version + 1
  ops               json            -- the serialized MigrationOpSet
  status            varchar         -- CHECK (status IN ('pending','running','completed','failed'))
  work_items_total  int default 0   -- count of {entry_uuid, locale, kind} units (see below)
  work_items_done   int default 0
  work_items_failed int default 0
  failure_report    json null       -- [{entry_uuid, locale, kind, reason}, ...]
  created_by        char(12) null
  created_at        timestamptz
  started_at        timestamptz null
  completed_at      timestamptz null
  INDEX (content_type_uuid, from_version)
  UNIQUE (content_type_uuid) WHERE status IN ('pending','running')  -- one active migration per type
```
**The unit of work is `{entry_uuid, locale, kind}`** where `kind ∈ {draft, published}`, **not**
`{entry, locale}`. An entry+locale that has *both* a draft and a pinned publication is **two**
work items — its draft transform and its published-version materialization succeed or fail
**independently**. So `work_items_total` counts every draft to transform **plus** every pinned
version to materialize; a draft that materializes while the published side errors records
`work_items_done += 1` **and** a `failure_report` entry `{entry_uuid, locale, kind: 'published',
reason}`. This removes the "is a both-draft-and-publication pair one item or two?" ambiguity:
it is two.
**`status` semantics — `failed` does not mean schema rollback.** `failed` means the runner
is exhausted/paused with one or more entries unmaterialized; **the schema stays flipped and
projection still protects every read.** A failed migration is resumable — re-running picks up
the entries still at `schema_version < to_version`. There is no status that reverts the
schema; reversal is a separate compensating migration (decision 5).

### 3. `SchemaProjector` — the lazy-projection service (the safety invariant)
`project(string $contentTypeUuid, int $fromSchemaVersion, array $fields): array`:
- Loads the migration ops to replay: **migrations where `from_version >= fromSchemaVersion`
  AND `to_version <= current_schema_version`, ordered by `from_version ASC`**, regardless of
  migration status (a `failed`/`running` migration still flipped the schema, so its ops are
  canonical). Applies each op-set in order using the non-throwing rename variant.
- **No-op fast path:** when `fromSchemaVersion === current_schema_version` (the common case
  once an entry is materialized), returns `$fields` untouched without loading anything.
- **Caches the op chain per `content_type_uuid`** (per request) so the hot delivery path
  pays at most one cheap lookup, then array transforms.

### 4. `MigrationService` — synchronous handler for `POST …/migrations`
1. Validate ops against the **current** schema: each `delete` target exists; each `rename`
   `from` exists and `to` does **not** collide with a declared field; no duplicate targets.
2. Compute the resulting schema (drop deleted fields; rename declared fields) and confirm it
   parses via `ContentTypeSchema::fromArray`.
3. Reject with **409** if a non-terminal migration already exists for the type.
4. **In one transaction:** insert the migration row (`status='running'`, `work_items_total` =
   count of affected drafts **plus** affected pinned published versions — one work item each,
   per `{entry_uuid, locale, kind}`), **flip** `content_types` (`schema_version = to_version`,
   store the new schema), persist `ops`.
5. **Enqueue the backfill *after commit*** — via `db()->afterCommit(...)`, **not** inside the
   transaction. If the framework queue dispatch is not transaction-aware, enqueuing inside
   the transaction could leave a queued job pointing at a migration row that a rollback
   discarded. After-commit enqueue guarantees the job only exists for a committed migration.
6. Return the migration row (the client polls it for progress).

The schema is canonical the instant step 4 commits; un-materialized reads are covered by the
projector from that instant.

### 5. Backfill — `BackfillRunner` + `RunBackfillJob` (queue) + `lemma:schema:backfill` (CLI)
(The runner/wrapper split mirrors the scheduled-publish design: `BackfillRunner` is the unit
of logic and the test target; the job is the queued entry point; the command is the
operator/resume entry point.)

Process each **work item** — a `{entry_uuid, locale, kind}` unit whose stored
`schema_version < to_version` (this predicate makes the whole run **idempotent + resumable**):
- **`kind = draft`:** apply the op-set to `entry_drafts.fields` in place and set the draft's
  `schema_version = to_version`.
- **`kind = published`:** read the pinned version's fields, project them forward, reserve the
  next number with **`VersionRepository::reserveNextVersionNumber()`** (so migrated versions
  participate in the normal monotonic sequence and lock correctly), append a **new**
  `entry_versions` row (`schema_version = to_version`, `created_by` = the migration actor), and
  re-pin it. The old version row is left untouched. Then rebuild `entry_references` for the
  entry from the **migrated** field shape (the same projection the publish path uses) — for a
  deleted/renamed reference or asset field the reference rows change accordingly. Asset detach
  is a **projection** concern here (reference state rebuilt) — it emits **no** §5 content event.
- On a per-item error (incl. a rename collision), record `{entry_uuid, locale, kind, reason}`
  in `failure_report`, increment `work_items_failed`, and **continue** — the draft and
  published items of the same pair are independent (one can succeed while the other fails), and
  lazy projection keeps any unmaterialized item correct until a resume.

Once per migration (not per item), **after the schema flip**: **enqueue
`EnsureFilterIndexesJob` for the content type** — the same path `ContentTypeController::updateSchema`
already uses. That job recomputes the desired filterable-index set from the type's *current*
schema (`FilterIndexPlanner`) and creates/drops both the `lemma_filter_indexes` registry rows
and the physical Postgres expression indexes. **The backfill does not mutate the registry
itself** — doing so would duplicate the planner/reconciler algorithm; it reuses the existing
job, so deleted/renamed filterable fields are reconciled by the one owner of that logic. Also
invalidate the delivery cache for the content type so cached responses don't serve a stale shape.

On completion: `work_items_failed === 0` → `status='completed'`; otherwise `status='failed'`
(resumable — see status semantics). `started_at`/`completed_at` stamped.

### 6. Read-path integration (where the invariant is enforced)
**Every door that returns stored fields must project.** Each read passes any blob whose
`schema_version` lags the type's current version through `SchemaProjector` (no-op fast path
when already current, so materialized rows cost nothing). The doors:
- **Delivery** — `DeliveryRepository` published reads.
- **Admin** — version reads (`GET …/versions/{locale}`) and draft reads (`GET …/draft/{locale}`).
- **Preview** — **`PreviewReader`** resolves *both* a draft and a specific historical
  `version_uuid` directly via `findVersionByUuid` / `findDraft` (`PreviewReader.php:56`), so it
  is a raw-fields door too. It **must** project the resolved fields forward before returning —
  otherwise a still-valid preview token minted against a pre-migration version would expose the
  old field shape, contradicting the invariant. (Backfill does not delete versions, so the token
  still resolves; it just needs projecting.)
- **Rollback** (`PublishService::rollback`) — **behavior/contract is unchanged** (correcting an
  earlier misstatement): rollback today **re-pins the existing version directly and does not
  append a new version**, and the endpoint returns the **requested** `version_uuid`; this feature
  keeps both. The *only* change is internal: rollback must **project the target version's fields
  forward before rebuilding `entry_references`** (today it rebuilds from the raw version fields
  against the current schema, which would mismatch a pre-migration version). The pin therefore
  stays on the requested (possibly pre-migration) version row, and the delivery/preview/admin
  doors above serve it forward via projection. **Consequence:** a post-migration rollback can
  leave a pinned version whose `schema_version` lags — served correctly by projection, not
  re-materialized. The invariant is *reads are always correct*, **not** *every pin is
  materialized*. (Alternative considered: append a migrated version on rollback so the pin is
  always current-schema — rejected to avoid changing rollback's response contract.)
- **Raw historical storage is never exposed.** Old immutable versions retain their original
  stored JSON, but every read *through Lemma* (delivery, admin, **preview**, rollback-served
  content) projects forward; V1 exposes no "raw historical fields" read path.

## Data model delta

- **New:** `entry_schema_migrations` (above).
- **No new columns** — every fields-bearing row already carries `schema_version`.
- **No change to `entry_versions` immutability** — the backfill only **appends** versions and
  updates the `entry_publications` pin; it never updates a version row.

## Failure reporting & recovery

- The migration row **is** the report: `work_items_total/done/failed` + `failure_report`
  (each failure tagged `{entry_uuid, locale, kind, reason}`).
- For delete+rename, the only realistic failures are operational (DB/lock contention) or the
  unreachable-in-practice rename collision — never value loss (the transforms are pure).
- Recovery = re-run `lemma:schema:backfill <migration-uuid>` (or let the job retry); the
  `schema_version < to_version` predicate makes it process only the unmaterialized remainder.
- Throughout, **lazy projection guarantees reads are correct** even with a `failed`/partial
  migration — the failure is a *materialization* gap (a small ongoing read cost), never a
  correctness gap.

## Events & projections

- **No §5 content event** (decision 7): migration emits no `entry.published`/`unpublished`
  and no asset-detach content event.
- **Derived projections are rebuilt:** `entry_references` per migrated entry; filterable
  indexes are reconciled by **enqueuing `EnsureFilterIndexesJob`** after the schema flip (the
  same path `ContentTypeController::updateSchema` uses — the backfill does not touch the
  `lemma_filter_indexes` registry directly); the delivery cache is invalidated for the type.

## Testing (Postgres, `LemmaTestCase`; op model + projector pure)

- **Op model (pure):** rename moves the value; delete drops the key; op-set applies in order;
  idempotent re-apply; `to/fromArray` round-trips; rename collision **throws** (materialization
  variant) and **keeps-existing-`to`** (projection variant).
- **`SchemaProjector`:** a v1 blob projects through two migrations to v3 (ordered by
  `from_version ASC`); a blob already at current is returned untouched (no-op fast path);
  bounds (`from_version >= blob.version AND to_version <= current`) are respected after
  multiple migrations.
- **`MigrationService`:** valid rename/delete flips `schema_version`, records ops, enqueues
  after commit; invalid ops → 422 (delete-missing, rename-from-missing, rename-to-collides,
  duplicate target); a second active migration → 409; a rolled-back transaction leaves **no**
  queued job (after-commit enqueue).
- **`BackfillRunner`:** a published entry gets a **new** migrated version + re-pin while the
  pre-migration version row is byte-for-byte preserved; a draft is transformed in place and
  re-tagged; migrated versions use `reserveNextVersionNumber()` (sequence continuity); a
  re-run is a no-op (idempotent); resume materializes the remainder and flips
  `failed → completed`.
- **Work-item accounting (P2):** an entry+locale with **both** a draft and a publication counts
  as **two** `work_items_total`; when the published item is forced to fail but the draft
  succeeds, the row shows `work_items_done = 1` **and** a `failure_report` entry
  `{kind: 'published', …}` — the pair is not all-or-nothing.
- **Read integration:** delivery of a not-yet-materialized published entry returns the
  **projected** (new-schema) fields; admin draft/version reads project; **a preview token
  minted against a pre-migration version returns projected (new-schema) fields** (not the raw
  old shape); a draft preview during an in-flight migration projects too.
- **Rollback (P1):** rolling back to a pre-migration version **re-pins that existing version**
  (no new version appended) and the endpoint returns the **requested** `version_uuid`
  (unchanged contract); the served content (delivery/preview) and the rebuilt
  `entry_references` reflect the **projected** new-schema shape — the old field shape never
  surfaces, and the pinned row may legitimately lag `schema_version`.
- **Projections / filter indexes (P2):** after a migration that deletes a filterable field, the
  backfill **enqueues `EnsureFilterIndexesJob`** (assert it's enqueued for the type) and, once
  it runs, the dropped field's `lemma_filter_indexes` row + physical index are gone — the
  backfill itself does not write registry rows; `entry_references` reflect the migrated shape;
  delivery cache invalidated.

## Out of scope / follow-ups

- **Retype (value coercion)** — the `MigrationOp` model is ready (`RetypeField`); the
  lossy-cast + failure policy is its own iteration.
- **Reversible/undo migrations** — forward-only; compensating migration instead.
- **Required-field-add backfill / default backfill** — adds are non-destructive today; a
  `SetDefault`-style op can extend the model later.
- **Per-entry manual review/approval UI**, and a migration-status admin UI — frontend
  follow-ups on top of the polled migration record.

## Open items for the plan

- Confirm the exact delivery cache-invalidation hook used elsewhere on publish, and reuse it.
- Confirm the queue-dispatch API and that `db()->afterCommit()` is the right after-commit hook
  (the scheduled-publish work established the `afterCommit` semantics).
- Decide batch/paging size for the backfill scan on large catalogs (correctness doesn't depend
  on it; throughput does).

## Success criteria

- An operator can delete or rename a content-type field via the migration endpoint; the
  schema advances and existing content migrates.
- At no point does a published read return the old field shape — materialized or projected.
- Prior `entry_versions` rows are never mutated; migrated content is new versions + re-pins.
- A partial/failed backfill is resumable and never serves incorrect reads; reversal is a
  compensating migration.
- Full suite green on Postgres CI; op-model, projector, service, runner, read-integration,
  and projection tests pass.
