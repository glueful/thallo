# Columns Block Sizing (widths + vertical alignment) — Design

**Date:** 2026-07-04
**Status:** Draft for review

## Goal

The `columns` block gains editor-controllable column **width ratios** and
**vertical alignment** — the two sizing controls it's missing — without
opening an arbitrary-CSS door. "Height" is deliberately NOT a control:
column height derives from content (fixed heights are the responsive
anti-pattern); what editors actually reach for when they say "height" is how
short columns sit next to tall ones, which is alignment.

## Contract

1. **Two new enum fields on the `columns` schema** (additive — the block-type
   schema rule):
   - `widths`: the ratio preset, values scoped by layout —
     - 2-column: `'50-50'` (default), `'33-67'`, `'67-33'`, `'25-75'`, `'75-25'`
     - 3-column: `'33-33-33'` (default), `'25-50-25'`, `'50-25-25'`, `'25-25-50'`
     - One flat enum carries all nine values (block schemas have no
       conditional enums); a `widths` that doesn't match the current `layout`
       falls back to equal columns at render (template guard, below) — never
       an error, never a broken grid.
   - `align`: `'stretch'` (default — cards equal-height like today),
     `'top'`, `'center'`, `'bottom'`.
2. **Exact class tokens are the contract (review pin), emitted from ONE
   allowlist.** The complete token vocabulary:
   - widths (layout 2): `lemma-block-columns--w-50-50`,
     `--w-33-67`, `--w-67-33`, `--w-25-75`, `--w-75-25`
   - widths (layout 3): `lemma-block-columns--w-33-33-33`,
     `--w-25-50-25`, `--w-50-25-25`, `--w-25-25-50`
   - align (non-default only): `lemma-block-columns--align-top`,
     `--align-center`, `--align-bottom` — `stretch` (the default) emits NO
     token; today's markup stays byte-identical.
   The template emits tokens by LOOKUP in a literal Twig map keyed
   `layout → widths → token` — never by string concatenation — so only
   allowlisted tokens can ever reach the class attribute, and the map itself
   is the single derivation site. `blocks.css` and the tests pin the same
   literal tokens.
3. **Layout↔widths mismatch emits NO width token, consistently (review
   pin).** A preset absent from the current layout's map row falls through to
   the base equal-columns rule — the one behavior for every mismatch, unknown
   value, or absent field. (No "default token": base CSS already IS equal
   columns; a redundant token would just be a second thing to keep in sync.)
4. **Responsive collapse unchanged:** below 40rem every preset stacks to one
   column (the existing breakpoint rule extends to the new modifiers).
5. **Reach — schema only, never a content rewrite (review pin).** New
   installs get the fields via the seeder; existing installs add them through
   the ADDITIVE schema path. Defaulting lives entirely in the CONSUMERS: the
   template defaults absent `widths`/`align` (equal columns, stretch), CSS's
   base rules are the defaults, and the editor renders an absent enum as its
   default option. No existing block instance data is rewritten anywhere —
   old rows keep rendering byte-identically. The dev instance gets the two
   fields via `BlockTypeRepository::updateSchema` (additive, schema only; no
   entry or region content is touched).
6. **No sandbox/policy change** (no new functions; filesystem template edit
   only — no CACHE_VERSION bump).
7. **Chrome context:** works in regions as-is; the card-skin neutralization
   from the region work already applies, and widths/alignment behave
   identically in a footer.

## Out of scope

- Fixed pixel/rem column widths or heights.
- Per-column arbitrary fraction input.
- Drag-to-resize in the canvas (a later canvas pass could map drag handles to
  the nearest preset).
- A 4-column layout.

## Testing

- Render: exact-token assertions (the literal strings from contract §2, not
  derived) — `layout: 2, widths: 33-67` emits `lemma-block-columns--w-33-67`;
  mismatched `layout: 2, widths: 33-33-33` emits NO `--w-` token at all;
  `align: center` emits `--align-center`; `align: stretch` and absent fields
  emit no new tokens (byte-compatible with today's markup).
- Seeder: field presence + enum values pinned in `SeedBlockTypesTest`'s
  container-style assertions.
- Fixture: `StarterTemplatesTest` columns fixture gains the new fields.

## Files touched

`StarterBlockTypes` (columns schema), `blocks/columns.twig`, `blocks.css`,
`SeedBlockTypesTest`, `StarterTemplatesTest`, `BlockLibraryRenderTest` (new
cases), dev-DB additive schema update, CHANGELOG.
