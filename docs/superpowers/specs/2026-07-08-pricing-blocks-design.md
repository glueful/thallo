# Pricing Blocks — Design Spec

**Date:** 2026-07-08
**Status:** Draft for review (revised after review round 1)
**Scope:** Subsystem 1 of 2 (the authored *pricing trio*). The dynamic *blog pair*
(`blogPost`/`blogPosts`) is a separate spec/plan cycle and is **out of scope here**.

## Goal

Add three Nuxt-UI-Pro-modeled pricing blocks to the Thallo default theme —
`pricing_plan`, `pricing_plans`, and `pricing_table` (plus two supporting child
block types) — authored entirely inline, styled in the theme's plain-CSS BEM
convention, with **rounded** self-contained cards.

## Architecture

These are ordinary Thallo blocks: definitions in `StarterBlockTypes::definitions()`
(seeded into the `block_types` table), Twig templates under
`themes/default/templates/blocks/`, and CSS appended to `themes/default/assets/blocks.css`.
They follow the established patterns exactly:

- **Computed class strings** built in a `{% set %}` map at the top of each template,
  every enum lookup guarded with `?? default` (see `grid.twig` / `navigation.twig`).
- **Enum/boolean fields → `--modifier` classes**.
- **Parent→child cascade via descendant CSS** (never data threading): a
  `pricing_plans` modifier restyles its `.thallo-block-pricing_plan` children, the
  same technique `accordion` uses for its group id.

**Block-nesting depth budget (`BlockDepth::MAX = 3`).** The entry body's blocks
sit at depth 1; each nested `blocks` field adds one level; a `blocks` field whose
items would exceed depth 3 is **rejected at publish** (a 422 at its dot path — not
truncated). These blocks are designed to **nest one wrapper deep** (e.g. inside a
`container`/`section` for a background band): `container(1) → pricing_table(2) →
pricing_feature/pricing_tier(3)`, with no `blocks` field below depth 3. This drove
two structural choices:

1. **Flat CTA fields, no nested `button` block.** `pricing_plan` and `pricing_tier`
   carry `button_label` / `button_url` / `button_variant` and render an `<a>` styled
   as a button. (A nested `button` block would push the CTA to depth 4 when wrapped.)
2. **Flat feature list, no `pricing_section` block.** `pricing_table` has a single
   flat `features` list; a feature row flagged `is_section` renders as a full-width
   section heading. (A nested `pricing_section` block would push feature rows to
   depth 4 when wrapped.) Rendered output is identical to a grouped structure.

No new field types and **no new admin widgets** — every field uses an existing type
(`string`/`text`/`enum`/`boolean`/`blocks`). The `json` type is deliberately **not**
used (a raw JSON editor is wrong for a visual builder).

## Tech Stack

PHP 8.3 (block definitions + `FieldValidator`), Twig (templates), plain CSS (theme),
PHPUnit integration tests. Reference inputs: `packages/thallo-render/docs/refs.md`
(Nuxt UI Pro theme maps) and `/Users/michaeltawiahsowah/Sites/glueful/bk/tw-class.css`
(compiled Tailwind — the source of truth for exact sizing/spacing behind utilities).

## Global Constraints

- **5 new block types**, snake_case slugs: `pricing_plan`, `pricing_plans`,
  `pricing_table`, `pricing_tier`, `pricing_feature`.
- **Rounded corners**: cards/tiers use `border-radius: var(--radius-lg)`; inner
  controls (badges, buttons, feature bars) use `var(--radius)`. Deliberate exception
  to the squared band blocks — pricing blocks are self-contained cards.
- **Nestable one wrapper deep, no deeper.** No `blocks` field sits below depth 3.
- **No `json` field type** anywhere in these blocks.
- **Seeding**: create the new rows via the normal seed path (`thallo:blocks:seed`,
  idempotent — creates missing, skips existing). NOT a migration. `thallo:blocks:sync`
  is only for *additive schema evolution on existing* block types, not new ones.
- **No AI/Anthropic attribution** in any commit or artifact.

---

## Block 1 — `pricing_plan` (a single plan card)

Maps to refs.md `pricingPlan`.

### Fields

| Field | Type | Notes |
|---|---|---|
| `title` | string | plan name |
| `description` | text | short blurb |
| `price` | string | "$29" / "Free" / "Custom" (string so non-numeric works) |
| `discount` | string | optional struck-through old price |
| `billing_period` | string | e.g. "/month" |
| `billing_cycle` | string | e.g. "billed annually" |
| `badge` | string | e.g. "Most popular" (renders only when set) |
| `features` | text | **one feature per line** |
| `feature_icon` | string (format: icon) | uniform per-line icon; **default `check`** |
| `tagline` | string | above the CTA |
| `terms` | text | fine print below the CTA |
| `button_label` | string | CTA text (CTA renders only when label + url set) |
| `button_url` | string | CTA href (passed through `safe_url`) |
| `button_variant` | enum: `solid`\|`outline` | CTA style; default `solid` |
| `variant` | enum: `outline`\|`solid`\|`soft`\|`subtle` | card style; default `outline` |
| `highlight` | boolean | featured ring |
| `orientation` | enum: `vertical`\|`horizontal` | internal card layout; default `vertical` |

**No `scale` field here** — enlargement is a group-level feature owned by
`pricing_plans` (see Block 2).

### Render / CSS

Root `thallo-block-pricing_plan`; modifiers `--variant-{v}`, `--highlight`,
`--orientation-{o}`. Features: split `features` on newline, trim blanks, render each
as the icon + text in a `__features`/`__feature` list. `badge`, `discount`,
`tagline`, `terms`, and the CTA render only when their values are non-empty.
Standalone-safe (renders fine dropped directly on a page).

**Feature icon**: resolved through `icon(feature_icon | default('check'))`. If
`icon()` returns null (unknown/empty name), the bullet renders **nothing** — the
raw icon string is never echoed. The same fixed `check` icon renders the table's
`✓` cell token.

Variant treatments (translated from refs.md):
- `outline` → `background: var(--bg)`; 1px `var(--line)` border.
- `solid` → `background: var(--ink)`; text `var(--accent-ink)`; muted text dimmed.
- `soft` → `background: var(--surface)`; no border.
- `subtle` → `background: var(--surface)`; 1px `var(--line)` border.
- `highlight` → 2px `var(--accent)` inset ring (overrides the variant border).

---

## Block 2 — `pricing_plans` (grid/stack of plans)

Maps to refs.md `pricingPlans`. Kept as a specialized wrapper (not folded into
`grid`) because it adds three behaviors `grid` structurally cannot.

### Fields

| Field | Type | Notes |
|---|---|---|
| `plans` | blocks → `['pricing_plan']` | the plan cards |
| `orientation` | enum: `horizontal`\|`vertical` | default `horizontal` (grid); `vertical` stacks |
| `compact` | boolean | tighter column gap |
| `scale` | boolean | enlarge the highlighted plan (group-level) |

### Render / CSS

Root `thallo-block-pricing_plans` + `--orientation-{o}`, `--compact`, `--scale`.
Renders `{{ blocks(data.plans) }}` inside a `__items` grid.

- **Auto `--count`**: the template sets inline `style="--count: N"` where N =
  `data.plans|length`; the grid is
  `grid-template-columns: repeat(var(--count, 1), minmax(0, 1fr))` in horizontal mode.
- **Featured-scale interaction** (group-level): when the parent has `--scale`, the
  highlighted child grows — rule targets
  `.thallo-block-pricing_plans--scale .thallo-block-pricing_plan--highlight`
  (`transform: scale(1.05)` + `z-index: 1`). The wrapper widens the column gap
  (`--compact` off → larger `column-gap`; `--scale` + not `--compact` → largest) so
  the enlarged card doesn't collide. `pricing_plan` has no own scale modifier.
- **Orientation cascade**: `--orientation-horizontal` lays plans side by side with
  `divide`-style separators between them (border-inline on children via
  `.thallo-block-pricing_plans--orientation-horizontal .thallo-block-pricing_plan`);
  `--orientation-vertical` stacks them full width.

---

## Block 3 — `pricing_table` (comparison table)

Maps to refs.md `pricingTable` — faithful to Nuxt's tiers × features matrix, with a
**flat** feature list (section headings inline) and **positional string cells** (no
json), so nothing nests below depth 3.

### `pricing_table` fields

| Field | Type | Notes |
|---|---|---|
| `tiers` | blocks → `['pricing_tier']` | column headers (see tier count below) |
| `features` | blocks → `['pricing_feature']` | flat rows: section headings + feature rows |
| `highlight` | boolean | enable per-tier column shading (driven by each tier's `highlight`) |

**Tier count is render-time, not validated** (blocks child-count isn't enforced):
the template slices `tiers` to the first **4** and renders as many `value_N` columns
as there are tiers. It degrades gracefully with 1 tier and with 0 tiers (renders an
empty/near-empty table, never errors). Authoring beyond 4 tiers simply drops the
extra columns (a documented render cap).

### `pricing_tier` (a column header) — child via `tiers`

| Field | Type | Notes |
|---|---|---|
| `title` | string | tier name |
| `description` | text | short blurb |
| `price` | string | |
| `discount` | string | |
| `billing_period` | string | |
| `billing_cycle` | string | |
| `badge` | string | |
| `button_label` | string | per-tier CTA text |
| `button_url` | string | per-tier CTA href (through `safe_url`) |
| `button_variant` | enum: `solid`\|`outline` | default `solid` |
| `highlight` | boolean | shade this column |

Columns render in authored order (position of each `pricing_tier` child), which is
what the positional `value_N` cells align to — so tiers need no explicit key.

### `pricing_feature` (one row) — child via `features`

| Field | Type | Notes |
|---|---|---|
| `is_section` | boolean | true → render `title` as a full-width section heading row (values ignored) |
| `title` | string | feature label (row header) or section heading |
| `value_1` | string | cell for tier column 1 |
| `value_2` | string | cell for tier column 2 |
| `value_3` | string | cell for tier column 3 |
| `value_4` | string | cell for tier column 4 |

**Cell tokens** (per `value_N`): `✓` / `yes` (case-insensitive) → the check icon;
`-` / `no` / empty → dash (or blank); anything else → literal text ("10 GB"). Only
as many cells as there are tiers are rendered.

### Render / CSS

The `pricing_table` template renders both layouts (CSS toggles which shows):

- **Desktop `<table>`** (`__table`, shown `@media (min-width: 48em)`): a header row
  of `<th>` tier cells (title/price/badge/CTA); then, walking `features` in order,
  each `is_section` row emits a full-width heading `<tr>` and each normal row emits a
  row-header `<th>` + one `<td>` per tier reading the matching `value_N`. **Section
  rows are label-only**: when `is_section` is true the template reads `title` only and
  never touches `value_1..4`, so toggling a former feature row into a section row can
  never surface stale cell values. When
  `highlight` is on, a tier whose `highlight` is true gets a shaded column band with
  a rounded top (`--radius-lg`) — the signature "featured column".
- **Mobile stacked list** (`__list`, shown below the breakpoint): one `__item` card
  per tier, listing that tier's header plus every non-section feature with its value.

Both are emitted server-side from the same data; no JS.

---

## CSS Translation Table (Nuxt semantic token → Thallo theme token)

`tw-class.css` supplies exact px/rem for sizing utilities; Nuxt's *semantic* color
tokens map onto the theme's existing design tokens (`site.css`):

| Nuxt token | Thallo |
|---|---|
| `text-highlighted`, `text-default` | `var(--ink)` |
| `text-muted` | `var(--muted)` |
| `text-toned` | `color-mix(in srgb, var(--ink) 65%, var(--muted))` |
| `text-inverted` | `var(--accent-ink)` |
| `text-dimmed` (on solid) | `color-mix(in srgb, var(--accent-ink) 65%, transparent)` |
| `text-primary` (feature icon) | `var(--accent)` |
| `bg-default` | `var(--bg)` |
| `bg-elevated/50` | `var(--surface)` |
| `bg-inverted` | `var(--ink)` |
| `ring`/`ring-default` (1px) | `1px solid var(--line)` |
| `ring-2 ring-primary` | `2px solid var(--accent)` (inset) |
| `divide-default`/`divide-accented` | `var(--line)` borders |
| `rounded-lg` | `var(--radius-lg)` |
| `rounded-md` / smaller | `var(--radius)` |
| `shadow-lg` | `var(--shadow-lg)` |
| `size-5` | `1.25rem` |
| spacing (`gap-*`, `p-*`) | nearest `--space-*` or literal rem from `tw-class.css` |

Dark mode is automatic: all mapped tokens already flip via the `site.css`
`prefers-color-scheme`/`data-theme` overrides.

## Validation

All fields use existing types, so `FieldValidator` handles them with no changes:
`string`/`text` (lenient/strict length), `enum` (membership), `boolean`, `blocks`
(recursion into child types, depth-capped at `BlockDepth::MAX`). The `blocks`
`block_types` allowlists (`plans→[pricing_plan]`, `tiers→[pricing_tier]`,
`features→[pricing_feature]`) are **picker-only** (not enforced by validation, by
design). Depth stays within the cap by construction (§Architecture).

## Wiring

1. Add the 5 definitions to `app/Content/Blocks/StarterBlockTypes.php`.
2. Bump the expected count in `tests/Integration/Content/SeedBlockTypesTest.php`
   (37 → 42).
3. Create the new rows by running `thallo:blocks:seed` (idempotent). Not a migration;
   `thallo:blocks:sync` is not used (it is for additive schema changes to existing
   block types).
4. Add 5 Twig templates under
   `packages/thallo-render/themes/default/templates/blocks/`.
5. Append CSS to `packages/thallo-render/themes/default/assets/blocks.css`.

## Testing

New integration coverage (extend `BlockLibraryRenderTest` or add
`PricingBlockRenderTest`):

- **pricing_plan**: each `variant` emits its modifier + expected bg/border; `highlight`
  emits the accent ring; features split per line with the chosen icon; an unknown
  `feature_icon` renders no raw string; `badge`/`terms`/CTA render only when set; CTA
  href passes through `safe_url` (a `javascript:` url is dropped); rounded corners
  present (`--radius-lg`).
- **pricing_plans**: `--count` inline var equals the plan count; horizontal emits the
  grid + divider modifiers; `--scale` parent + `--highlight` child gets the enlarge
  rule; a standalone `pricing_plan` (no parent scale) is not enlarged.
- **pricing_table**: desktop table has one `<th>` per tier and one `<td>` per tier per
  feature; `value_N` tokens map correctly (`✓`/`yes`→check, `-`/`no`→dash, text→literal);
  an `is_section` row renders a full-width heading (no value cells); an `is_section`
  row **carrying stale `value_N` data renders no cells** (label-only guard); a
  highlighted tier shades its column; the mobile `__list` renders one item per tier.
- **tier-count edges** (render-time, since unvalidated): 5 tiers → only 4 columns
  render; 1 tier → renders without error; 0 tiers → empty-safe.
- **depth**: a top-level `pricing_table` and a `pricing_table` inside one `container`
  both validate (deepest child at exactly depth 3); assert nothing nests below.
- **seed**: `SeedBlockTypesTest` count matches (42); each new slug validates its own schema.

## Out of Scope

- Dynamic `blogPost`/`blogPosts` (separate spec/plan cycle).
- More than 4 tiers in `pricing_table` (positional render cap).
- Any new field type or admin widget.
- A radius control (corners are fixed `--radius-lg`; revisit only if requested).
- Nesting these blocks more than one wrapper deep (would exceed `BlockDepth::MAX`).
