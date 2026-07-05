# Icon Picker (searchable modal over the vendored inventory) — Design

**Date:** 2026-07-05
**Status:** Draft for review

## Goal

Everywhere an editor currently types a Lucide/brand icon name from memory —
the icon block, `social_link.icon`, `feature.icon`, navigation menu items —
they instead pick from a searchable, paginated modal listing exactly the
icons the site can render.

## Pinned contract

1. **Inventory endpoint: `GET /admin/icons?set=lucide|brands`**, sourced from
   the RENDER PACK'S VENDORED directories (`resources/icons/{lucide,brands}`)
   — never from Iconify/admin-side assumptions. Parity by construction: the
   list is precisely what `icon()` can resolve, and it survives vendored-set
   refreshes with zero admin changes. Response: `{icons: string[]}` (bare
   names, brands WITHOUT the `brand:` prefix), sorted, server-side cached
   per process (a directory glob is cheap; no invalidation surface needed).
   Gate: `content.view`. Unknown `set` → 422; the endpoint 409s if the
   render pack is absent (the regions-preview posture).
2. **String field formats — editor hints, not validation.**
   - `format: 'icon'` → Lucide picker; `format: 'brand-icon'` → brand picker.
   - New `FieldDefinition::STRING_FORMATS = ['icon', 'brand-icon']`, parsed
     in a `$type === 'string'` branch mirroring the existing text branch
     (null default — plain string input; unknown value = loud
     `SchemaParseException`). **`TEXT_FORMATS` stays text-only and
     untouched.**
   - Backend semantics: format on strings is presentation metadata ONLY —
     validation remains the field's `pattern`/`enum`/`required` rules,
     exactly as today. `FieldDefinitionData`/`FieldSchemaData` already carry
     `?string $format`, so the API round-trip is free; the request DTO's
     accepted values widen to the union of TEXT_FORMATS and STRING_FORMATS
     (validated against the field's type at parse).
3. **One reusable `IconPickerModal`** (modal — NOT a popover/dropdown: 1,745
   icons need room to scan and compare shapes):
   - Search input at top, focused on open; filters CLIENT-SIDE over the
     server inventory (one fetch per set per session, cached query).
   - Grid of results, **paginated with page numbers** (catalog, not feed):
     80 icons per page; previous / next / current page / total pages;
     "Showing 1–80 of 1,745"-style count; **search change resets to page
     1**; empty state when no matches.
   - **Never a giant always-rendered grid (review pin):** exactly one page
     (≤ 80 tiles) is in the DOM at any time.
   - Current selection pinned at the top (preview + name) with a **Clear**
     button for optional fields.
   - Selecting an icon writes the SERVER NAME and closes. Previews render
     via `i-lucide-{name}` / `i-simple-icons-{name}` — presentation only; a
     name whose admin-side preview is missing still shows as a name chip and
     is still selectable (the server inventory is the truth).
   - Tabs/segmented Lucide|Brands only where a caller allows both; the
     schema-driven callers are single-set, so v1 renders one set per opening
     (the prop is a set, not a toggle — tabs are a later need).
   - Keyboard: search + click for v1; arrows/Enter are a later nicety.
4. **The field stays compact:** preview icon + name + "Choose" button (and
   Clear when set). No inline grids.
5. **Wiring:**
   - **Schema-driven** (the field registry, not the BlockCard special-case
     ladder): a `string` field whose `format` is `icon`/`brand-icon` renders
     the compact picker field instead of the plain text input — custom block
     types and content types get it by declaring the format. Adopted by:
     `icon` block's `icon` field (`format: 'icon'`), `social_link.icon`
     (`format: 'brand-icon'`), `feature.icon` (`format: 'icon'`).
     Additive schema edits: seeder for new installs, `updateSchema` for the
     dev instance (schema only, no content rewrite).
   - **Direct use** for navigation menu-item icons (`MenuTreeEditor` — tree
     items are not schema fields): the text input + preview chip becomes the
     compact picker field bound to `item.icon`.
6. **Save value = server name only.** The stored string never carries a
   namespace for schema fields (social_link keeps its `brand:` prefix in
   DATA? — NO: see §7). Unknown/stale saved names keep degrading at render
   through `icon()` returning null (label/text fallback) — the picker
   reduces the chance of bad names but the render contract is unchanged.
7. **The `brand:` prefix stays in the DATA for `social_link.icon`** (its
   pattern demands it; the template calls `icon(data.icon)` which expects
   the namespace). The picker handles this transparently: for
   `format: 'brand-icon'` fields it displays and lists bare brand names but
   READS/WRITES the value with the `brand:` prefix. Menu items and
   `format: 'icon'` fields store bare Lucide names as today.
8. **Brand-prefixed storage is enforced at the VALIDATOR boundary, not only
   in the picker (review pin).** The UI hiding `brand:` must never become
   the only thing standing between API-written content and a silently
   degraded render: every seeded schema that declares
   `format: 'brand-icon'` PAIRS it with the brand-prefixed pattern
   (`brand:[a-z0-9]+(-[a-z0-9]+)*` — `social_link.icon` already carries it),
   so a bare `github` written around the picker 422s at save. The general
   rule stands — `format` itself adds no validation; the pattern is the
   contract — but the pairing is pinned for seeded schemas, and tests prove
   BOTH directions: the API rejects bare names on brand-icon fields, and the
   picker writes prefixed values.

## Out of scope

- Arrows/Enter keyboard grid navigation (search + click is v1).
- Lucide|Brands tabs in one opening (no caller needs both yet).
- Virtualized scrolling (page numbers make it unnecessary).
- Server-side search (client filtering over ~1,745 short strings is
  instant).
- Recently-used / favorites.
- The existing BlockCard special cases (columns, navigation menu) — they are
  behavioral, not icon inputs; unchanged.

## Testing

- **Endpoint**: lucide set returns >1,500 sorted names including `activity`;
  brands set returns the curated 27 (matches VENDORED.md); unknown set 422;
  the counts agree with the vendored directories (glob parity, not a pinned
  literal).
- **Schema**: `['type' => 'string', 'format' => 'icon']` parses;
  `format: 'rich'` on a string field fails loudly; `format: 'icon'` on a
  text field fails loudly (each type validates against its own allowlist);
  format round-trips through the type API (FieldSchemaData).
- **Validation unchanged**: a string field with `format: 'icon'` + pattern
  still 422s on a bad name and accepts a good one — format adds no rules.
- **Brand-prefix enforcement (P2 pin)**: `social_link.icon` rejects a bare
  `github` (API-written, no picker) with a 422 and accepts `brand:github`;
  the picker vitest proves selections on brand-icon fields emit
  `brand:`-prefixed values.
- **IconPickerModal (vitest)**: search filters and resets to page 1; page
  numbers paginate 80/page with correct total; only one page of tiles in the
  DOM; select emits the name and closes; Clear emits undefined; brand mode
  lists bare names but emits `brand:`-prefixed values when the caller is a
  brand-icon field.
- **Field wiring**: a `format: 'icon'` string field renders the compact
  picker field (registry-level, no slug special-case); a plain string field
  keeps the text input; MenuTreeEditor opens the picker and writes
  `item.icon`.

## Files touched

| Area | Change |
| --- | --- |
| `app/Content/Schema/FieldDefinition.php` | `STRING_FORMATS` + string-branch parse |
| `app/Content/Http/DTOs/FieldDefinitionData.php` | accept the widened format union (type-aware) |
| `app/Http/Controllers/IconInventoryController.php` (new) + `routes/lemma_admin.php` | `GET /admin/icons` |
| `app/Content/Blocks/StarterBlockTypes.php` | `format` on icon/social_link/feature icon fields |
| `admin/src/queries/icons.ts` (new) | inventory query (cached per set) |
| `admin/src/fields/components/IconPickerModal.vue` (new) | the modal |
| `admin/src/fields/components/IconField.vue` (new) + registry/normalize | compact field for `format: icon`/`brand-icon` strings |
| `admin/src/pages/navigation/components/MenuTreeEditor.vue` | picker replaces the text input |
| tests | endpoint, schema parse, DTO round-trip, modal vitest, field wiring, dev `updateSchema` |
| CHANGELOG, OpenAPI regen | — |
