# Field-Level Localization Automation — Design

**Goal:** Make the existing `localized: true` field-schema flag *do* something: when a new
locale variant is created from a source locale, copy the **non-localized** field values
and leave the **localized** fields for the editor to translate — so editors only
re-author what is actually locale-specific.

**Status:** ✅ Shipped (2026-06-17) — implemented and reviewed.

**Backlog item:** [POST_V1.md](../../POST_V1.md) §5. Resolves the deferral documented in
[V1_DESIGN.md](../../V1_DESIGN.md) §3 ("Field-level localization … already representable").

> V1_DESIGN §3: "Field-level localization (`localized: true` in the field schema) is
> already representable. V1 keeps whole-entry locale variants as the persisted unit; a
> future editor can use the flag to automate copy behavior for non-localized fields."

---

## Definition of behavior (the contract)

> A field marked `localized: true` is locale-specific (needs translation per locale). A
> field without the flag (the default) is shared content that is the same across locales.
> When an editor creates a locale variant from a source locale, Lemma seeds the new
> draft's **non-localized** fields from the source and leaves the **localized** fields
> empty, so the editor's job is exactly "translate the marked fields." Today the create
> path copies *every* field indiscriminately; this feature makes the copy flag-aware.

## What exists today (the seam)

- `FieldDefinition::$localized` is parsed from schema JSON (`FieldDefinition.php:17,60`)
  and round-tripped through `ContentTypeSchema::toArray()` — **representable but inert**.
- `POST /v1/admin/entries/{uuid}/locales/{locale}` → `EntryController::createLocaleDraft`
  → `EntryRepository::createLocaleDraft(...)`. With a `source_locale`, the repository does
  `$fields = (array) $source['fields'];` — **copies all source fields verbatim**
  (`EntryRepository.php:244`).
- The persisted unit is one `entry_drafts.fields` JSON blob per `(entry, locale)`. There
  is **no per-field metadata or override tracking** — a draft is an opaque field map.
- `ContentTypeRepository::schemaFor($uuid): ContentTypeSchema` gives the field list with
  `->localized` per field; `ContentLocaleService::default()` gives the default locale.

## Scope decisions

1. **Flag-aware copy-on-create is the whole feature.** When `createLocaleDraft` copies from
   a source locale, partition the source fields by the schema's `localized` flag: copy
   non-localized values, omit localized ones. This is additive, low-risk, and the
   high-value automation editors actually feel.
2. **Localized fields start empty (omitted), not pre-filled.** A `localized` field is left
   absent in the new draft so the editor authors the translation fresh — and, if the field
   is `required`, publish validation correctly blocks the untranslated variant until it's
   filled. (Resolved: empty over pre-fill, so an editor can never accidentally publish
   un-translated source text in the target locale.)
3. **The schema is the source of truth for the partition.** Copy is computed by iterating
   `ContentTypeSchema` fields, not the raw blob's keys: fields in the schema but absent
   from the source are simply not seeded, and any stale key in the source blob that is no
   longer in the schema is not carried over. In practice drafts are already
   schema-conformant (`saveDraft` validates against the schema before write), so this drops
   nothing real — it just makes the copy schema-driven rather than blob-driven.
4. **No change to the persisted unit.** Still one whole-entry `fields` blob per
   `(entry, locale)`; no per-field override columns, no read-time field merging. V1_DESIGN
   §3 explicitly keeps the whole-entry variant as the unit.
5. **"Shared" is a seed, not an enforced invariant — in this iteration.** Copy-on-create
   *initializes* non-localized fields identically across locales; it does not keep them in
   sync afterward. Editing a non-localized field in any variant is allowed and simply
   diverges that variant. True ongoing sync (copy-on-change) is a deliberate **deferred
   follow-up** (resolved) because clobber-safe sync needs override awareness the whole-blob
   model doesn't have.
6. **No new permissions, routes, or events.** Reuses the existing
   `POST …/locales/{locale}` route, `lemma.entries.write`, and the §5 event taxonomy
   unchanged. The only behavioral change is *what gets copied* inside `createLocaleDraft`.
7. **Flag changes are prospective only.** `ContentTypeRepository::updateSchema` already
   permits flipping a field's `localized` flag (destructive detection only guards
   delete/retype — `ContentTypeRepository.php:133`), and this feature does **not** change
   that. Toggling a field `shared → localized` or `localized → shared` affects **only future
   locale-draft creation** via the seeder; **existing drafts and versions keep whatever
   values they already hold** — no retroactive copy, blanking, or backfill. (Retroactive
   re-shaping of existing content on a flag flip would be a separate backfill concern, akin
   to the destructive-schema-backfill feature, and is out of scope.)

## Architecture

One new pure unit + a wiring change:

- **`LocaleFieldSeeder` (pure service, the unit of logic and the direct test target)** —
  `seed(array $sourceFields, ContentTypeSchema $schema): array`. Returns the initial field
  map for a new variant: for each schema field, copy `$sourceFields[name]` **iff** the field
  is not `localized` **and `array_key_exists($field->name, $sourceFields)`**. Use key
  presence, **not** truthiness — a non-localized field whose value is `false`, `0`, `0.0`, or
  `''` must be copied verbatim, not dropped. Localized fields are omitted. No I/O, trivially
  unit-testable, one clear responsibility.
- **`EntryRepository::createLocaleDraft`** gains a `ContentTypeSchema $schema` parameter (or
  a `LocaleFieldSeeder` collaborator). When `$sourceLocale !== null`, it builds the seed via
  `LocaleFieldSeeder::seed($source['fields'], $schema)` instead of the verbatim
  `(array) $source['fields']` copy. The no-source path (empty draft) is unchanged.
  **`overwrite: true` interaction (called out):** `createLocaleDraft(..., overwrite: true)`
  replaces the *whole* target draft today (`EntryRepository.php:225`). Under the seeded copy,
  re-creating a locale from a source with `overwrite: true` will **intentionally drop the
  target's existing localized-field values** (localized fields are omitted from the seed), so
  the target is reset to "non-localized values from source, localized fields blank." This is
  consistent with the model (re-seed = fresh translation), but it is destructive to prior
  translations — documented here and covered by a test so it isn't surprising.
- **`EntryController::createLocaleDraft`** already resolves the content type; it passes the
  resolved `ContentTypeSchema` (via `types->schemaFor(...)`) into the repository call. No
  route/DTO change (`CopyLocaleData` stays `source_locale` + `overwrite`).

Data flow: `POST …/locales/{locale}` → controller validates locales + resolves schema →
`createLocaleDraft(..., $schema)` → `LocaleFieldSeeder::seed(sourceFields, schema)` →
draft written with non-localized values seeded, localized fields empty.

## Data model

**No schema change, no new table.** The change is entirely in the copy logic. Existing
`entry_drafts.fields` blob, `content_types.schema` (with the already-stored `localized`
flags), and all routes are untouched.

## Testing (Postgres, `LemmaTestCase`; seeder unit tests pure)

- **`LocaleFieldSeeder` (pure):** mixed schema (some `localized`, some not) → non-localized
  source values copied, localized fields absent; field in source but not in schema dropped;
  field in schema but absent from source not invented; empty source → empty result.
- **`LocaleFieldSeeder` preserves falsy non-localized values (P2):** a non-localized field
  whose source value is `false`, `0`, `0.0`, or `''` is **present in the seed with that exact
  value** (key-presence copy, not truthiness) — explicitly assert `array_key_exists` semantics
  so a `boolean`/`number`/`string` field set to a falsy value is never silently dropped.
- **createLocaleDraft (integration):** create `fr` from `en` where `title` is localized and
  `price` is not → `fr` draft has `price` = en's value, no `title`; the `en` source draft is
  unchanged.
- **overwrite re-seed drops target localized values (P3):** an existing `fr` draft with a
  translated localized `title`, re-created from `en` with `overwrite: true` → `fr`'s `title`
  is gone (localized omitted from the seed) and `fr`'s non-localized `price` is reset to en's
  value. Locks in the intentional-but-destructive overwrite behavior so it isn't a surprise.
- **flag change is prospective (P2):** flipping a field `shared → localized` (or back) via
  `updateSchema` leaves existing `en`/`fr` drafts and versions byte-unchanged; only the
  **next** `createLocaleDraft` reflects the new partition.
- **required localized field blocks publish:** a required `localized` field left empty in
  the new variant → publish validation fails for that locale until translated (asserts the
  scope-decision-2 behavior using the existing validation path).
- **all-localized schema:** new variant copies nothing (every field needs translation).
- **all-shared (no localized) schema:** new variant copies everything (today's behavior,
  now via the seeder) — proves the feature is a strict superset of current behavior when no
  field is marked localized.
- **no-source create:** empty-draft creation path is unchanged (no seeding).

## Out of scope / follow-ups

- **Copy-on-change / ongoing sync of non-localized fields** — deferred (see Resolved
  decisions). The clobber-safe shape that fits the whole-blob model, when it's picked up:
  on saving the **default-locale** draft, update a sibling locale's non-localized field
  **iff** the sibling's current value equals the default's *pre-save* value (i.e. never
  overridden) — no new columns, but write amplification + a "previous default value" read on
  every default-locale save.
- **Locking non-localized fields in non-default variants** (read-only, sourced from the
  default) — a stricter "shared" enforcement that would change the editor contract and the
  read/publish merge; not in this iteration.
- **Per-field override tracking / read-time field merging** — would change the persisted
  unit, which V1_DESIGN §3 keeps as the whole-entry variant.
- **Admin UI for translation status** — a frontend concern (V1_DESIGN §3 notes UI-only
  translation state is out of the backend contract).

## Resolved decisions

1. **Localized fields on create → empty/omitted** (not pre-filled). Forces fresh
   translation; required-field validation gates publish. Chosen over pre-filling with the
   source value because pre-fill risks an editor publishing un-translated source text in the
   target locale.
2. **Copy-on-change sync → deferred.** This iteration is purely additive (copy-on-create
   only). Ongoing sync of non-localized fields is a follow-up (shape documented under Out of
   scope); pulled in only if shared-field drift becomes a real concern.

## Success criteria

- A `localized` field is no longer inert: creating a locale variant from a source copies
  non-localized values and leaves localized fields for translation.
- When no field is marked `localized`, behavior is identical to today (full copy).
- Persisted unit, routes, permissions, and the event taxonomy are unchanged.
- Full suite green on Postgres CI; the seeder unit tests and the create-path integration
  tests pass.
