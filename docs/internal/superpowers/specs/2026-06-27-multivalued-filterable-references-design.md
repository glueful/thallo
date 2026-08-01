# Multi-valued + Filterable References — Design

**Date:** 2026-06-27
**Status:** Approved for planning
**Author:** (brainstormed)

## Goal

Extend Lemma's content-type `reference` (and `asset`) field primitive to support **many-to-many
relationships** (the headline use case being taxonomies: categories/tags) and to make references
**queryable at delivery** — `GET /v1/content/{type}?filter[category][eq|in]=<uuid|slug>` returns the
published entries that reference the given target(s).

Taxonomies are *not* a new entity in Lemma: a "Category"/"Tag" is just a content type whose entries
are the terms (hierarchy falls out of a self-referential `parent` field). What's missing is the
**relationship + query layer** — multi-valued references and the ability to filter delivery by a
reference target. This design adds exactly that layer; taxonomies then fall out of existing
primitives, and it unblocks a later WordPress categories/tags importer.

## Current state (what already exists)

- **Single-valued references.** `FieldDefinition` carries `referenceType` (one target content-type
  slug). `FieldValidator` validates `reference`/`asset` as a single non-empty uuid string.
- **Projection is already multi-value-ready.** `ReferenceProjectionRepository::rebuildForEntry()`
  rebuilds, on every draft save, the normalized `entry_references(source_entry_uuid, source_field,
  target_entry_uuid)` rows from the draft's reference/asset field values; its `targets()` parser
  already accepts a uuid **or a list of uuids**, and the table's unique key
  `(source_entry_uuid, source_field, target_entry_uuid)` already supports multiple targets per
  field. `entry_references` is the admin **"what links here"** reverse index — it is projected from
  the **draft**, so it is *not* a delivery-query source.
- **Scalar-only delivery filtering.** `FilterCompiler` compiles `?filter[field][op]=value` into a
  typed JSONB predicate over the published `entry_versions.fields`, restricted to scalar
  `filterable` types (`string|number|boolean|datetime|enum`); the predicate is built to hit the
  per-field expression index (`FilterIndexPlanner`/`EnsureFilterIndexesJob`). `reference`/`asset`
  are not currently filterable.
- **Admin reference picker.** `ReferencePicker.vue` is a single-select `USelectMenu` chosen by
  `ReferenceField.vue` when `field.referenceType` is set.

## Scope

**In scope (v1):**
- A per-field `multiple` flag for `reference` **and** `asset` fields → ordered uuid array storage.
- An optional `max_items` per-field cardinality cap.
- `filterable: true` allowed on `reference`/`asset`, with **membership (contains)** semantics.
- Delivery filtering of references by **uuid and slug**; assets by **uuid only**.
- Flipping an existing single-valued field to `multiple` with **no data migration** (read-time
  scalar→array tolerance + a delivery compatibility expression).
- Admin: builder controls (`multiple`, `max_items`, `filterable`, `reference_slug_field`) + entry-
  editor multi-select reference and asset pickers.

**Out of scope (explicit follow-ups):**
- **Term-archive endpoints + facet counts** (entries-per-term). These would be built on a future
  *published*-reference projection, which remains **additive** on top of this design — not required
  by it.
- **Hierarchy helpers** — nested terms work today via a single-valued self-`reference` `parent`
  field; no special primitive is added.
- **WordPress categories/tags importer** — unblocked by this work, but a separate adapter task.
- **Manual drag/reorder UX** for multi-pickers (v1 preserves insertion order; reordering is later
  polish).
- **`neq`** reference operator (trivial negated-containment add later); v1 ships `eq` + `in`.

## Design

### §1 — Schema / field model

New per-field attributes in the content-type schema JSON:

| Attribute | Type | Applies to | Default | Meaning |
|---|---|---|---|---|
| `multiple` | bool | `reference`, `asset` | `false` | Field stores an **ordered JSON array** of uuids. |
| `max_items` | ?int (>0) | `reference`, `asset` (when `multiple`) | none | Max array length, enforced at validation **after** dedupe. |
| `reference_slug_field` | ?string | `reference` | `slug` | Target-type field used to resolve slug filter values → uuids. |
| `filterable` | bool | now also `reference`, `asset` | `false` | Allows membership filtering (see §4). |

`FieldDefinition` gains readonly props `multiple: bool`, `maxItems: ?int`,
`referenceSlugField: ?string`. `fromArray` parsing rules (throw `SchemaParseException` on
violation):
- `multiple`/`max_items` are only meaningful for `reference`/`asset`; ignored (or rejected) on
  other types. `max_items` must be a positive int.
- `reference_slug_field` is only meaningful for `reference`; it must match the schema field-name
  shape `^[a-z][a-z0-9_]*$`. Its **existence** on the target type is *not* checked at parse time
  (a field can't see other types' schemas) — it is verified at delivery resolution (fail-loud /
  no-match). Content-type save continues to validate that `reference_type` names a real type.
- `filterable` is accepted on `reference`/`asset`; their `filter_type` is implicitly "membership"
  (no scalar `filter_type` needed — `FILTER_TYPES` stays for scalars; ref/asset take a distinct
  predicate path).

`ContentTypeSchema::toArray()` round-trips the new attributes (`multiple`, `max_items`,
`reference_slug_field`).

### §2 — Validation & normalization

**Saves are strict** (`FieldValidator`):
- *Single* `reference`/`asset` (`multiple` false): unchanged — must be a non-empty uuid string.
- *Multiple* `reference`/`asset`: the submitted value **must be an array**. A scalar submitted to a
  `multiple` field is a **validation error on save** (read-time tolerance below is for *stored*
  legacy values, not new submissions).

**Normalization (pre-persistence, deterministic) for a multiple field** — the stored value and the
value returned in the response are the normalized value, so a reload never surprises a test:
1. Accept an **ordered** array.
2. **Empty-string / null elements are invalid → validation error** (never silently dropped).
3. **Duplicate uuids are collapsed, first occurrence kept** (order preserved).
4. **`max_items` is checked *after* dedupe** (duplicates are not distinct targets).
5. `asset` elements are existence-checked per element on the configured media disk (matching
   today's single-asset rule); `reference` elements are not existence-checked (matching today).
6. The normalized array is persisted; the save response returns it.

**Read-time scalar→array tolerance (the flip path):** a value stored as a scalar string in a field
that is *now* `multiple` is treated as a 1-element array. This is **not** a `FieldValidator` save
concern — the validator stays strict on new saves. Tolerance lives where stored values are read:
- `ReferenceProjectionRepository::targets()` (already tolerant; keep + characterize).
- a read/normalization helper if one is introduced for draft/version hydration.
- the delivery filter compatibility expression (§4).

Consequently, **flipping single→multi needs no data migration**: old scalar values keep projecting
and keep filtering; only new saves must submit arrays.

### §3 — Projection (unchanged)

`ReferenceProjectionRepository` is unchanged: it still rebuilds `entry_references` from the draft on
every draft save, deduped on `(source_field, target_entry_uuid)`, and remains the admin reverse
index. It is **not** read by delivery filtering. A future published-reference projection (for
term-archives/facets) would be a separate, additive table/trigger.

### §4 — Delivery filter (the compatibility-critical layer)

`FilterCompiler` gains a **membership predicate path** for filterable `reference`/`asset` fields;
the existing scalar JSONB path is untouched. Allowed operators: **`eq`** (single target) and
**`in`** (any of N). Ordered ops (`gt|gte|lt|lte`) are rejected for ref/asset.

**Compatibility expression (pinned).** Normalize the published `entry_versions.fields->'F'` to a
jsonb array regardless of stored shape, so single, multi, and flipped-across-versions filter
identically. `F` is the filtered field name, re-asserted against `^[a-z][a-z0-9_]*$` (reusing
`FilterCompiler`'s existing field-name guard) before interpolation:

```sql
CASE
  WHEN fields->'F' IS NULL                THEN '[]'::jsonb          -- absent field → no match
  WHEN jsonb_typeof(fields->'F') = 'array' THEN fields->'F'         -- multi (already array)
  ELSE jsonb_build_array(fields->'F')                               -- single / legacy scalar → [scalar]
END
```

This expression is `IMMUTABLE` and is used **both** in the predicate and the GIN expression index
(§5). Predicate semantics (all on `@>`, one operator, GIN-friendly):
- `eq` → `<expr> @> jsonb_build_array(?::text)`
- `in` → OR of per-value containment: `(<expr> @> jsonb_build_array(?) OR … )`

**Slug → uuid resolution (references only; assets are uuid-only).** Resolve all filter values for a
reference field through one query against the target type's published spine, then apply precedence
in PHP. The query is built with Glueful's query builder; candidate values are bound as **expanded
placeholders** (`IN (?, ?, …)` via `whereIn` — Glueful does **not** bind a single PHP array as
`ANY(?)`). The slug **key** is rendered via a single safe helper (the `FieldSqlExpression` pattern:
`reference_slug_field` regex-validated against `^[a-z][a-z0-9_]*$`, then interpolated, never bound)
so the lookup can hit the slug field's expression index (§5). Spine (matches `DeliveryRepository`):

```sql
-- <slug_field> = reference_slug_field, regex-validated + interpolated (never bound).
-- (?, …) = candidate values, expanded one placeholder per value (whereIn), each bound.
SELECT p.entry_uuid AS uuid, v.fields ->> '<slug_field>' AS slug
FROM entry_publications p
JOIN entry_versions v ON v.uuid = p.version_uuid
JOIN entries e        ON e.uuid = p.entry_uuid
WHERE e.content_type_uuid = ?                                       -- the reference_type's uuid
  AND e.status = 'active'
  AND p.locale = ?                                                  -- the delivery request locale
  AND (
        p.entry_uuid IN (?, ?, …)                                   -- candidate values
        OR v.fields ->> '<slug_field>' IN (?, ?, …)                 -- same candidate values
      )
```

Per-input precedence algorithm (resolves the uuid-vs-slug ambiguity deterministically):
1. For each input value, **if it equals a published target `entry_uuid`, resolve to that uuid.**
2. Else resolve by `reference_slug_field`:
   - **0 rows** → the value contributes no match (dropped from the predicate).
   - **exactly 1 row** → resolve to that uuid.
   - **>1 rows** → throw `InvalidFilterException` (ambiguous slug). The spec recommends/documents
     slug-uniqueness on taxonomy types.
3. **Dedupe the resolved uuids** before building the containment predicate.

If, after resolution, a reference filter has **zero** resolved targets, it matches nothing (an
explicit empty result, not a SQL error).

Assets skip resolution entirely: filter values are used directly as uuids in the containment
predicate; a value that is not a real blob uuid simply never matches.

**Security:** JSON **keys** are schema-derived identifiers, never bound — they are regex-validated
(`^[a-z][a-z0-9_]*$`) and interpolated through a single safe rendering helper (the existing
`FieldSqlExpression` pattern). This applies to the filtered field name `F` in the `CASE`/index
expression (reusing `FilterCompiler`'s re-assertion) and to `reference_slug_field` in the resolver.
Rendering the key (rather than binding it) is what lets the predicate match the expression index — a
bound JSON key would not. All **values** remain bound, as **expanded `?` placeholders** (`whereIn`
semantics), never `ANY(?)` and never interpolated.

**`in`-list cap:** the number of input values for an `in` (and the number of resolved targets) is
capped at a sane, config-backed limit (default **50**); exceeding it throws `InvalidFilterException`,
preventing a pathologically large OR expression. (Reuse an existing delivery filter limit if one is
present; otherwise add this one.)

### §5 — Filterable-index lifecycle

`FilterIndexPlanner` / `EnsureFilterIndexesJob` gain a variant for filterable `reference`/`asset`
fields: a **GIN expression index** on the §4 normalized-array expression using `jsonb_path_ops`
(supports `@>`), built `CONCURRENTLY` out-of-band like the existing scalar b-tree expression
indexes, and tracked in the same registry. The two index families coexist (b-tree for scalar casts,
GIN for membership).

**Slug-lookup performance caveat (documented):** the resolver's `v.fields->>'<slug_field>'` lookup
is index-backed only if the target type marks that slug field `filterable` (giving it its own scalar
expression index); otherwise it scans the published target set. This is acceptable for taxonomies
(small term sets) and is called out so catalog owners can mark the slug field filterable when a term
set is large.

### §6 — Admin UX

**Content-type builder** (the field editor in `settings/content-types/*`): for a `reference`/`asset`
field, add:
- a **`multiple`** toggle,
- a **`max_items`** number input (shown only when `multiple`),
- the now-allowed **`filterable`** toggle,
- (reference only) a **`reference_slug_field`** text input, defaulting to `slug`.

**Entry editor** (`ReferenceField.vue`): pick single vs multi off `field.multiple`.
- Single keeps today's `ReferencePicker`.
- Multi uses a multi-select variant binding an **ordered uuid array** (add/remove). The asset field
  gets the equivalent multi-blob picker.
- **Ordering requirement:** the field value is an ordered array and the UI **must preserve selection
  order**. A multi-select component may return values in option order rather than selection order;
  the picker therefore normalizes its output from explicit append/remove actions (not from raw
  component value order), and this is **verified by a component test**.

**`FieldDef`** (admin `@/fields/types`) carries `multiple`, `maxItems`, `referenceSlugField` through
the schema→FieldDef mapping, exactly as it already threads `referenceType`/`format`. The OpenAPI
`FieldSchemaData` (and regenerated FE types) carry the new attributes.

### §7 — Import/export (verbatim)

`LemmaContentImporter`/`LemmaContentExporter` round-trip `fields` JSON verbatim, so uuid arrays
serialize/import with no special handling (`targets()` already tolerant). No change required; a
round-trip test confirms multi-valued arrays survive export→import.

## Compatibility & migration

- **No schema/data migration.** Flipping a single-valued field to `multiple` is a schema-attribute
  change only. Existing stored scalar values keep working via read-time tolerance (§2) and the
  delivery `CASE` expression (§4). New saves to a `multiple` field must submit arrays.
- The new GIN expression index is created out-of-band by the existing index job when a ref/asset
  field is marked `filterable`.

## Testing strategy

- **Unit — validation (strict on save):** `FieldValidator` accepts an array for `multiple`; rejects
  a scalar submitted to a `multiple` field; rejects empty-string/null elements; collapses duplicates
  first-occurrence; enforces `max_items` after dedupe; single fields unchanged; asset elements
  existence-checked per element.
- **Unit — read tolerance (NOT in the validator):** characterize `ReferenceProjectionRepository::
  targets()` for scalar and array inputs; cover scalar→array at the delivery `CASE` (below) and in
  any read/normalization helper introduced.
- **Unit — filter compiler:** ref/asset membership predicate emits the `CASE … @>` SQL with bound
  values for `eq` and `in`; ordered ops rejected; asset values used uuid-only; `in`-cap enforced.
- **Unit — slug resolver:** uuid-precedence per input; slug 0/1/>1 rows (>1 → 422); resolved-uuid
  dedupe; locale scoping; the slug field name is regex-validated and safely rendered (interpolated
  via the `FieldSqlExpression` helper), while slug **values** are bound as expanded placeholders.
- **Integration — delivery (real product behavior):** seed a `category` type + terms + posts that
  reference them (multi); publish; assert `?filter[category][eq]=<uuid>`, `[eq]=<slug>`,
  `[in]=a,b` return the correct published entries; a field **flipped single→multi across two
  published versions** filters correctly via the `CASE`; absent field → no match; ambiguous slug →
  422; asset uuid filter matches.
- **Integration — index lifecycle:** `EnsureFilterIndexesJob` emits the GIN expression index for a
  filterable reference field (assert the index is planned/created).
- **Backfill (no migration):** an old scalar-valued published version still filters after its field
  is flipped to `multiple`.
- **Admin:** type-check; a vitest verifying the multi-picker preserves selection order; FieldDef
  multiplicity mapping.

## Open items (resolve at planning, non-blocking)

- Confirm the exact home of `FilterIndexPlanner`'s GIN variant vs. the existing scalar planner
  (one class with a branch, or a sibling) — a plan-time decomposition detail.
- Confirm whether an existing delivery filter limit already bounds `in`-list size (reuse it) or the
  default-50 cap is new.
