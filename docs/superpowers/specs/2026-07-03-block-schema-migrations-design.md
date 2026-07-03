# Block-Schema Migrations + Hard-Delete — Design

**Date:** 2026-07-03
**Status:** Approved (brainstorm 2026-07-03)
**Depends on:** page/block builder (2cd93bf), container blocks (c13fe62), block
reference expansion (ed8deae)

## Goal

Block-type schemas are slug-immutable but otherwise free-replace today: a field
rename orphans every stored instance's old key, which FieldValidator's
cleaned-payload semantics then SILENTLY STRIP on the entry's next save — data loss
by drift. Deletion is deactivate-only, with no way to remove a mistaken type. This
work adds (a) explicit, eager-only block-schema migrations with a backfill that
converges all current content, and (b) usage-gated hard-delete.

**Model decision (pinned):** block instances are embedded value objects, not
independently versioned resources. No per-instance schema version in
`{id, type, data}`; no read-time/view projection on any delivery/render path.
Schema changes are handled where they belong: at schema-change time (eager
backfill), plus a one-shot projection at RESTORE time.

## §1 Registry edit rule

- `BlockTypeRepository::updateSchema()` becomes **additive-only**: the new
  schema's field-name set must be a superset of the old. A removal (including the
  remove+add shape of a rename) rejects 422 with an error pointing at the migrate
  flow.
- Retype of an existing field stays FREE: the data keeps its key; an incompatible
  stored value surfaces as a visible validation error on the entry's next save —
  never silent loss.
- Label/icon/category/description edits unaffected. Slug stays immutable.
- The migration path (§2) applies its computed post-op schema through an internal
  path exempt from the additive-only guard.

## §2 Migration declaration

New table `lemma_block_type_migrations`:

| column | notes |
|---|---|
| `uuid` | PK identity |
| `block_type_uuid` | FK-by-convention to `lemma_block_types` |
| `ops` | json — the declared op list |
| `status` | `running` \| `completed` \| `failed` |
| `work_items_total` / `_done` / `_failed` | backfill accounting |
| `created_by` | actor |
| `created_at` | **microsecond precision** — this IS the chain identity (§5) |
| `completed_at` | nullable, microsecond precision |

**No version numbers.** With no per-instance stamps, `created_at` ordering is the
whole chain; the restore rule (§5) selects by timestamp suffix.

- Ops vocabulary identical to content types: `rename {from, to}`,
  `delete {name}`; validated against the CURRENT block schema with
  `MigrationService`'s collision rules (declared-source checks, duplicate
  source/target rejection, at-least-one-op).
- Declaring a migration atomically: records the row (`running`), applies the
  computed new schema to the registry, and queues the backfill job after commit.
- **One active migration per block type** — declaring another while one is
  `running` OR `failed` rejects 409. `failed` does not unlock anything: it is
  re-driven, not abandoned (§4).

## §3 Write gate during migration (pinned)

Content-type migrations flip schema immediately because versions carry
`schema_version` and the backfill filters by it. Block instances have NO stamp —
if the block schema flips before the backfill converges, an editor saving an
un-backfilled entry hits the exact data-loss path (old keys become unknown →
stripped). Therefore:

- A block-type migration is **active** until `completed` — `running` AND `failed`
  both count.
- While active, SAVING or PUBLISHING an entry whose submitted blocks data contains
  an instance of that block-type slug (any structural depth, any blocks field)
  returns **409** with a clear migration-in-progress error naming the type.
- Re-driving a failed backfill does not open the write gate; only `completed`
  does.
- What the gate inspects: on SAVE, the submitted payload (the thing that would be
  validated and cleaned); on PUBLISH, the stored draft's fields (publish has no
  payload). Entries not containing the migrating type save/publish normally.
- Implementation seam: an active-migrations lookup (one cheap query, usually
  empty) consulted in the save/publish path before FieldValidator's cleaning
  runs.

## §4 Backfill (eager-only)

`BlockBackfillRunner`, mirroring `BackfillRunner`:

- Scope: every non-deleted entry (archived included) of every content type whose
  schema has `blocks` fields, ALL locales. **Mirror `BackfillRunner`'s SHAPE, not
  its active-only predicate**: the content-type runner filters
  `entries.status = 'active'` (`BackfillRunner.php:188/:202`); the block backfill
  uses `entries.status != 'deleted'` — archived entries can return to current
  content, so skipping them would strand un-migrated instances behind the §3
  write gate's opening.
- Current drafts: rewritten in place, lock-version CAS; conflicts count as
  failures and are re-drivable.
- Current publications: rewritten as a NEW version + repin (content-type
  `processPublished` parity), so append-only versioning holds and the
  republished version's `created_at` postdates the migration row (§5 relies on
  this).
- The rewrite walks blocks fields descending nested blocks to `BlockDepth::MAX`,
  applying ops ONLY to instances of the migrating type. Items without the type
  are not work items (counted up front by the same walk).
- Failure tracking, `work_items_*` accounting, status flip to `completed` only at
  zero remaining — all per the content-type backfill's shape. CLI re-drive joins
  the existing backfill command family.
- On completion: invalidate cache tags for the affected content types (broad
  `lemma:type:{slug}` — page contents changed).

## §5 Restore projection (pinned)

**Codebase reality:** "restore" is `PublishService::rollback()` — it RE-PINS an
existing immutable version (append-only; no draft copy). The pin is therefore
realized as: rollback inspects the version's blocks BEFORE any write; when the
timestamp-suffix projection changes the fields, rollback MATERIALIZES a new
version with the projected (and strictly validated) fields and pins that — the
same append-and-repin shape the backfill uses; when the projection is a no-op,
today's plain re-pin runs unchanged (and skips the new validation, preserving
existing rollback behavior for unaffected content).

Version restore projects ONCE through completed block migrations before the
restored fields become the new current version:

- For each block type present in the restored fields: apply completed migrations
  for that type where `migration.created_at > version.created_at`, ordered by
  `created_at ASC` — the timestamp suffix. Ops are tolerant (skip-if-missing) but
  ONLY within that suffix; the full chain is never applied blindly (rename-chain
  reuse is the documented corruption case).
- Restore validates the projected result BEFORE writing anything.
- Backfill-republished versions postdate their migration row, so they are never
  reprojected.
- **Precision pin:** the comparison requires microsecond timestamps on BOTH
  sides. `entry_versions.created_at` currently gets second-precision writes
  (`VersionRepository::appendVersion()` uses `date('Y-m-d H:i:s')`); going
  forward it MUST be written with microseconds (Postgres `timestamp` columns
  store µs natively; the write format is the fix — a column-precision migration
  is added only if the installed column declares precision 0). Same for
  `lemma_block_type_migrations.created_at`. A test pins the same-second case: a
  version and a migration created within one second must still select the right
  suffix, and a backfill-created version must not be reprojected.
- Comparison is strictly `>`: an exact-equal timestamp (µs collision) applies
  nothing — the safe side, since the only same-instant writer is the backfill
  itself.
- Restore of a version containing a HARD-DELETED (unknown) block type is BLOCKED
  before write with a dot-path validation error naming the type. No silent strip.
  (Deactivated types remain fully valid for stored content — deactivate-over-
  delete stays the editorial posture; genuinely missing types are exceptional.)
- Historical PREVIEW stays as-authored in v1 — no view projection. A
  pre-migration version previewed through current templates may show blank block
  fields; documented, not data mutation.

## §6 Usage + hard-delete

**Usage** — "current content that could become editable/live again":

| surface | counts? |
|---|---|
| current drafts (all locales) | yes |
| current pinned publications (all locales) | yes |
| archived (non-deleted) entries' drafts/publications | yes |
| nested blocks up to `BlockDepth::MAX` | yes |
| historical versions | no (§5 restore gate fences them) |
| content-type `block_types` picker allowlists | REPORT only, never gates |
| theme template files | ignored |

`GET /v1/admin/block-types/{slug}/usage` → on-demand scan (admin cold path, no
projection table): per-content-type counts (drafts, publications), total, a small
sample (`entry_uuid` + title), and allowlist appearances (content-type slugs).

**Delete** — `DELETE /v1/admin/block-types/{slug}`:

- Re-runs the SAME scan server-side inside the delete request; refuses 409 when
  `total > 0` (the UI's earlier read is never trusted).
- At zero usage: hard `DELETE` of the registry row. No force flag — a force
  would manufacture exactly the unknown-slug save/restore failure path §5 keeps
  exceptional.
- Deactivate-before-delete is NOT required: deactivate = editorial lifecycle
  (hide from picker, keep validating/rendering); delete = destructive cleanup for
  unused/mistaken types only.
- Deleting a type with an active migration: 409 (the migration owns the type
  until completed).
- No `deleted_at` soft-delete on the registry — deactivate already is the soft
  path.

## §7 Admin SPA (Block Types screen)

- **Usage panel** per type: total + per-type counts + samples from the usage
  endpoint (fetched on demand, not on list load).
- **Delete** action: enabled at zero usage, confirmation dialog; surfaces the
  409 races cleanly.
- **Migrate fields** dialog: rename/delete op rows against the current schema;
  shows migration status (running/failed + work-item progress) and blocks a
  second declaration while one is active. (Mirror the content-type migration UI
  if one exists in the SPA; otherwise a minimal ops form — verified at plan
  time.)
- Schema editor surfaces the additive-only 422 with the migrate pointer.
- Entry editor: the §3 write-gate 409 surfaces as a clear "block type X is
  migrating" error on save/publish.

## §8 Out of scope

- Read-time/view projection for historical versions (documented v1 trade-off).
- Per-instance schema stamps.
- Op vocabulary beyond rename/delete (retype/transform ops).
- Auto-cleaning `block_types` allowlists on delete (reported; admin tidies).
- Theme template file management.

## §9 Testing

**Unit:** ops validation (collision rules), additive-only superset guard (rename
shape rejected; retype allowed), timestamp-suffix selection (incl. same-second µs
case and strict-`>` tie behavior).

**Integration (migrations):**
- backfill rewrites instances in drafts + publications, all locales, nested to
  `BlockDepth::MAX`, republishing publications as new versions
- an ARCHIVED entry containing the migrating block is rewritten (the
  not-deleted predicate, not BackfillRunner's active-only)
- items without the migrating type untouched; work-item accounting; CAS-conflict
  failure counted and re-drivable; status flips only at zero remaining
- write gate: save/publish of an entry containing the migrating slug 409s while
  running AND while failed; unrelated entries save; gate opens on completed
- second declaration 409s until completed
- registry edit: removal/rename via updateSchema 422s; additive edit passes

**Integration (restore):**
- restore applies exactly the timestamp suffix (rename-reuse chain test: a→b then
  b→a; a version from each era restores correctly)
- backfill-republished version restores with NO reprojection
- same-second version/migration pair selects correctly (µs precision)
- restore of a version containing a deleted type is blocked before write, error
  names the type

**Integration (usage/delete):**
- usage counts drafts + publications + archived + nested; ignores historical
  versions; reports allowlists without gating
- delete 409 at nonzero usage (server-side re-scan), deletes at zero; 409 with
  an active migration

**SPA:** usage panel, delete confirmation flow, migrate dialog, write-gate error
surfacing — per the existing vitest patterns (data-testid hooks, no Nuxt UI
internals).
