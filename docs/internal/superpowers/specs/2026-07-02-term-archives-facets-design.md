# Term Archives + Facet Counts — Design

**Date:** 2026-07-02
**Status:** Approved design, pre-implementation
**Parent:** the delivery surface deferred by
`docs/superpowers/specs/2026-06-27-multivalued-filterable-references-design.md`
("Term-archive endpoints + facet counts… built on a future published-reference
projection"). Unblocks the rendered listing/archive follow-up track in
`docs/V2_DESIGN.md` §6.

The taxonomy read surface for content-type-as-terms: given `post.categories` referencing
a `category` type, consumers get **facet counts** ("PHP (12), Laravel (4)") and
**term-archive pages** (the term + its published entries) from the delivery API. Terms
are ordinary entries; no new taxonomy primitive exists — this is delivery surface over
the shipped multi-valued/filterable reference primitive.

**Placement: core delivery (`app/Content`)**, not a pack — it is the delivery surface of
a core primitive, reads core tables directly, and rides core pipeline events (the
core/extension boundary rule: primitives + self-dependency = core). No admin/SPA UI in
v1 — this is delivery-side only.

## 1. The published-reference projection (the one new table)

Delivery filtering answers membership via JSONB containment, which cannot GROUP BY
per-term efficiently — the references spec pinned a **published**-reference projection
as the additive layer for exactly this. (`entry_references` is draft-based and stays the
admin reverse index, untouched.)

**`published_entry_references`** — one row per (source entry, locale, field, target):

| Column | Notes |
|---|---|
| `source_entry_uuid` | the referencing (archive-member) entry |
| `locale` | the publication locale the row was projected from |
| `field` | source schema field name |
| `target_entry_uuid` | the term |
| `source_content_type_uuid` | denormalized — the facet GROUP BY / archive filter key |

- Unique: `(source_entry_uuid, locale, field, target_entry_uuid)`.
- Index: `(source_content_type_uuid, field, locale, target_entry_uuid)` — facet
  aggregation and archive membership.
- Index: `(target_entry_uuid)` — hygiene deletes.

**PINNED: the projection is the single source of "published source references published
term."** Both new endpoints read it — facets aggregate it, archives join it for
membership (§3). It projects **ALL `reference` fields** (not `asset` fields), regardless
of `filterable` — flipping `filterable` on later must not require a backfill; the
endpoints gate on `filterable` at read time. Scalar→array read tolerance applies (a
flipped single-valued field projects its scalar as a 1-element array), mirroring
`ReferenceProjectionRepository::targets()`.

**Maintenance = pipeline listener, NOT a DB trigger** (SQLite test portability; the
`lemma:resync` re-drive story). `ProjectPublishedReferencesListener`, wired beside
`InvalidateCacheTagsListener` on the existing after-commit events:

- `EntryPublished(entry, type, locale)` → delete rows for (entry, locale), re-insert
  from the **published version's** reference-field values. Idempotent delete-then-insert.
- `EntryUnpublished(entry, locale)` → delete rows for (entry, locale).
- `EntryDeleted(entry)` → delete rows where source = entry (all locales) AND where
  target = entry (hygiene — a deleted term's rows vanish).

**Delete hygiene is NOT the correctness mechanism for term liveness.** A term can be
*unpublished* without being deleted, leaving projection rows behind — both endpoints
therefore join the TARGET's publication at read time (§2/§3); the hygiene delete just
keeps the table from accumulating garbage.

**Backfill for existing installs:** none needed as a migration — `lemma:resync` walks
the published set and re-drives idempotent downstream effects; the projection listener
rides that re-drive. Operator story: migrate, then run `php glueful lemma:resync` once.

## 2. Facets endpoint

`GET /v1/content/{type}/facets?fields=categories,tags&locale=…&limit=…`

Response — the standard `Response::success()` envelope (no custom top-level keys):

```json
{
  "success": true,
  "message": "…",
  "data": {
    "categories": [
      { "uuid": "…", "slug": "php", "count": 12 },
      { "uuid": "…", "slug": "laravel", "count": 4 }
    ],
    "tags": [ … ]
  }
}
```

- `fields` (required): comma-separated source field names. Each must be a
  `filterable: true` `reference` field of `{type}` — anything else is the existing
  `UnfilterableFieldException` → 4xx validation response, same as the filter param.
- Count = `COUNT(DISTINCT source_entry_uuid)` grouped by `target_entry_uuid`, from the
  projection, **joined against the term type's published spine in the request locale**
  (`entry_publications` + `entries.status = 'active'`) — unpublished/deleted terms drop
  out at read time, matching filter semantics. `slug` is extracted from the term's
  published version via the field's `referenceSlugField` (default `slug`) — the exact
  expression `ReferenceFilterResolver` uses.
- Order: `count DESC, slug ASC`. `limit` default 100, hard max 500 (per field).
- Deliberately minimal term shape (`uuid`, `slug`, `count`): richer term display comes
  from the regular list endpoint or the archive envelope.
- **Visibility (fail closed):** every requested field's TARGET type must pass the same
  `DeliveryVisibility` check as the source type (§4) — facets enumerate the whole term
  set, which is a strictly bigger disclosure than the filter param's one-slug-at-a-time
  probe, so "it's only uuid+slug+count" does not exempt them. Any requested field whose
  target type is not visible under the request's credentials → 404 for the request (no
  partial per-field responses).
- Locale defaults follow the existing delivery list endpoint's locale resolution.

## 3. Archive endpoint

`GET /v1/content/{type}/archive/{field}/{term}?locale=…&sort=…&fields=…` (+ the list
endpoint's pagination params)

**Pagination mirrors `DeliveryListQuery` exactly** — same params (`perPage`, not
`per_page`), same mode switch, same envelopes as `GET /v1/content/{type}`:

Default (keyset cursor) — standard `Response::success()` with `term` inside `data`:

```json
{
  "success": true,
  "message": "…",
  "data": {
    "term": { …shaped term entry, same shape as the show endpoint… },
    "items": [ …shaped entries, same shape as the list endpoint… ],
    "next_cursor": "…"
  }
}
```

Explicit `?page`/`?perPage` — the list endpoint's flattened `Response::paginated()`
envelope with ONE additive top-level key `term` (that envelope has no `data` object to
nest into; `data` is the items array):

```json
{
  "success": true,
  "message": "…",
  "term": { …shaped term entry… },
  "data": [ …shaped entries… ],
  "current_page": 1, "per_page": 25, "total": 12, "total_pages": 1,
  "has_next_page": false, "has_previous_page": false
}
```

- `{field}` must be a `filterable: true` `reference` field of `{type}` (same gate as
  facets). `{term}` resolves **uuid-first, then `referenceSlugField`** against the term
  type's published spine in the request locale — the identical precedence
  `ReferenceFilterResolver` applies to filter values. Ambiguous slug (>1 published
  match) → the same error the filter path raises.
- Unknown/unpublished term → **404** (the thing a bare filter can't distinguish from an
  empty archive). Known term with zero members → 200 + empty `data`.
- **PINNED: membership comes from the projection, not the JSONB filter path.** The
  member query is the existing published-spine list query for (source type, locale) with
  an added join:
  `published_entry_references r ON r.source_entry_uuid = p.entry_uuid AND r.locale =
  p.locale AND r.source_content_type_uuid = :type AND r.field = :field AND
  r.target_entry_uuid = :termUuid`.
  Everything downstream — pagination, `sort`, field selection, shaping
  (`DeliveryItemShaper`), scopes, ETag — delegates to the existing delivery machinery.
  Compiling `filter[field][eq]` back through JSONB containment is explicitly rejected:
  the projection must be the single membership source, or facet counts and archive
  contents could diverge.
- The regular `filter` param still composes on top (e.g. archive + `filter[status]…`)
  through the existing `FilterCompiler` — only the TERM membership is projection-pinned.

## 4. Visibility

Both endpoints apply the same `DeliveryVisibility` check the per-type endpoints use, to
**the source type AND every referenced target type** — archive because the term's shaped
body is in the envelope, facets because whole-set term enumeration (uuids, slugs,
counts) is a disclosure surface even without bodies: a public `post.categories` field
referencing a non-public `category` type must not let anonymous clients enumerate the
private term set. Any check failing denies the whole request — no partial responses, no
per-field redaction. Statuses follow the enforcement layer (amended to match built
behavior): the SOURCE type is gated by the shared `lemma_delivery_access` route
middleware, which denies with **403** exactly as it does on the list/show routes; the
TARGET-type checks live in the controller and return **404** (the term set/body's
existence is hidden).

## 5. Caching + invalidation (zero new purge code)

Both endpoints emit the existing `Cache-Tag` surrogate headers via `DeliveryEtag`
mechanics:

- Facets: `lemma:type:{sourceSlug}, lemma:type:{termTypeSlug}` — any publish touching
  either type purges the counts.
- Archive: those two plus `lemma:entry:{termUuid}` and the member entries' tags (the
  list mechanics already emit per-member tags).

`InvalidateCacheTagsListener` and `PurgeCdnListener` handle purging unchanged, and the
render page cache composes automatically when rendered listing pages arrive. ETags: the
archive reuses the list ETag mechanics (member version uuids + selection key, plus the
term's version uuid); facets hash the computed payload.

## 6. Routing note

`/v1/content/{type}/archive/{field}/{term}` has a distinct segment count — no collision.
`/v1/content/{type}/facets` shares the segment count with `/{type}/{slugOrUuid}`: the
facets route is registered FIRST, a characterization test pins that it wins, and the
shadowing of a hypothetical entry literally slugged `facets` is documented (reserved
word), consistent with how reserved paths are handled elsewhere.

## 7. Testing

`tests/Integration/` additions:

- Projection lifecycle: publish → rows appear (per locale, per field, deduped);
  unpublish → that locale's rows gone; entry delete → source AND target rows gone;
  single-valued scalar field projects as 1-element; asset fields never project;
  re-publish is idempotent (no duplicate rows).
- Resync re-drive: truncate the projection, run the resync path, rows reconverge.
- Facets: counts correct across multi-valued fields (entry in 2 categories counts once
  per category, `COUNT(DISTINCT)` guards double-rows); **unpublished term drops out of
  facets while its projection rows still exist** (the read-time-join warning, proven);
  non-filterable/unknown field → 4xx; ordering + limit; locale isolation (fr counts ≠
  en counts); **target type non-public → 404, no term slugs/counts in the body** (the
  enumeration guard, proven for facets specifically).
- Archive: term envelope + members via projection (seed a JSONB/projection divergence
  deliberately and assert the projection wins); uuid and slug resolution; unknown term
  404 vs empty archive 200; BOTH pagination modes (default cursor `items`/`next_cursor`
  with `data.term`; `?page`/`?perPage` flattened envelope with top-level `term`);
  sort/field-selection compose; extra `filter` params compose; visibility 404s (source
  type non-public; term type non-public); Cache-Tag headers carry both type tags.
- Routing characterization: `/v1/content/{type}/facets` wins over the show route.

## 8. Out of scope (explicit follow-ups)

Filter-aware (drill-down) facet counts; hierarchy expansion (self-reference `parent`
works today); inline facets on the list endpoint; asset-field facets; term archives
spanning multiple source types in one call; admin/SPA UI; rendered listing/archive
pages (the next render follow-up — this spec is its prerequisite).
