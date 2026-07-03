# BlocksField Notion-like UX — Design

**Date:** 2026-07-03
**Status:** Approved (brainstorm 2026-07-03)
**Depends on:** page/block builder (2cd93bf), container blocks (c13fe62), starter
library (8bdeabd), rich HTML sanitization (29ff916)

## Goal

Make the admin block editor feel like a modern Notion/Gutenberg authoring surface —
inline insertion, searchable picker, drag (including across containers), keyboard
movement, chromeless prose, slash-to-widget — as a PURE SPA layer over the existing
structured editor. The stored model (`{id, type, data}` lists in entry JSON), the
depth cap, allowlist filtering, and every validation/migration contract are
untouched. The future visual canvas's inspector rehosts these exact components.

**Architecture decision (pinned):** hybrid — **Lemma owns the tree; TipTap powers
text editing, not the page model.** The block list is a native Vue structured
editor; TipTap/UEditor lives only INSIDE `rich_text`-style prose blocks. The
TipTap-as-document-shell alternative (blocks as ProseMirror node views) was
reviewed against the Nuxt UI editor template and REJECTED: our blocks are schema
forms, not text flow — a document of atom widgets carries the text engine as dead
weight and buys editor-in-editor nesting, attrs round-tripping, and container
re-modeling. The one residue of that review: Nuxt UI's editor suggestion-menu
primitives are used for the `/` menu inside prose regions.

## §1 Component architecture

`BlocksField.vue` becomes a thin shell over four units (all under
`admin/src/fields/components/blocks/`):

| unit | responsibility |
|---|---|
| `useBlockListOps.ts` | PURE list operations — move, duplicate, remove, insertAt, patchData, moveAcross (container→container), splitRichTextAt, canDropAt (depth math) — no DOM, unit-testable |
| `BlockCard.vue` | one block: header chrome (icon, label, summary, actions), delete-confirm, schema-form body, recursion for container regions |
| `BlockInsertMenu.vue` | the searchable type picker (type-to-filter, category groups) — used by the "+" dividers, the `/` keyboard shortcut, AND the in-prose slash bridge |
| `BlockOutlineRail.vue` | collapsible tree of the field's blocks; click scrolls to + selects the block; NO drag in v1 |

The existing picker/allowlist/`MAX_BLOCK_DEPTH` logic moves into these units
unchanged in behavior. Existing `data-test` hooks are preserved where the element
survives (`block-card-{id}`, `block-toggle-{id}`, `block-duplicate-{id}`,
`block-delete-{id}`, `add-block`, `block-picker`, `picker-item-{slug}`,
`max-depth-notice`); new surfaces get new hooks (`block-insert-{index}`,
`block-drag-{id}`, `block-outline`, `prose-block-{id}`, `tail-prose`).

## §2 Tree mechanics

- **Drag:** `vue-draggable-plus` (already a dependency), handle-based, one shared
  group across the field so blocks drag between container regions and nesting
  levels. The draggable binding stays THIN — all mutation goes through
  `useBlockListOps` so the logic is testable without sortable/jsdom fights (the
  recorded harness rule).
- **Depth guard at drop (pinned):** the cap is SUBTREE math, not target depth:

  ```
  targetDepth + draggedSubtreeDepth - 1 <= MAX_BLOCK_DEPTH
  ```

  where `draggedSubtreeDepth` is the dragged block's own nesting height (a leaf =
  1; a section containing columns containing leaves = 3). `canDropAt()` computes
  this BEFORE mutating; a violating drop is rejected in place with a transient
  notice — never a post-hoc validation 422.
- **Insertion:** a hover-revealed "+" divider between every adjacent pair and at
  both ends of every list (top level and container regions). Clicking opens
  `BlockInsertMenu` anchored at that index; choosing a type inserts there
  (expanded, focused).
- **Keyboard** (on the focused block header, roving `tabindex`):
  - `⌘/Alt + ↑ / ↓` — move block up/down within its list
  - `⌘D` — duplicate
  - `Delete`/`Backspace` — open the delete confirm
  - `Enter` — toggle expand/collapse
  - `/` — open `BlockInsertMenu` below the focused block
- **Duplicate** now DEEP-copies data (nested container lists get fresh block ids
  throughout — the current shallow `{...data}` shares nested arrays by reference,
  a latent aliasing bug this work fixes in `useBlockListOps.duplicate`).

## §3 Prose seam (the hybrid)

- **Prose detection (pinned as CONVENTION, not identity):** default rule — a block
  type whose schema is EXACTLY one `text` field with `format: 'rich'` renders as a
  prose region. The spec explicitly reserves a block-type metadata override
  (`editor_mode: prose | card`) as the durable escape hatch; v1 ships
  convention-only, and the detection function is a single exported predicate so
  the override slots in without touching call sites. The convention must never be
  treated as a durable identity contract by other features.
- **Chromeless rendering:** prose blocks render as flowing UEditor text — bubble
  toolbar on selection, no fixed toolbar, no card border. Drag handle + action
  chrome (duplicate/delete/move) appear on hover/focus exactly like widget cards,
  so the tree mechanics stay uniform. Widget blocks keep the card look.
- **Tail prose:** an empty blocks field, or the area below the last block, renders
  a "Type here…" affordance; clicking (or focusing) it creates a block of the
  field's DEFAULT PROSE TYPE and focuses its editor. Selection rule (consistent
  with prose detection being a convention, not a `rich_text` dependency):
  1. the active, allowlist-permitted `rich_text` type when present;
  2. else the FIRST active, allowlist-permitted type where
     `isProseBlockType(type)` is true;
  3. none → the affordance is hidden (fields composed only of widgets keep the
     plain "+" button).
  This keeps `rich_text` as the starter default without making it a hard
  architectural dependency.
- **Slash-to-widget split (pinned identity rules):** inside a prose region, typing
  `/` (the standard suggestion trigger — any cursor position; the split rules
  below handle mid-paragraph cursors) opens the suggestion menu with two groups — text constructs
  (headings, lists, quote; Nuxt UI's own items, staying INSIDE the block's HTML)
  and Lemma block types (from `BlockInsertMenu`'s source, allowlist-filtered).
  Picking a block type asks the editor for cursor-split HTML halves and calls
  `useBlockListOps.splitRichTextAt(blockId, beforeHtml, afterHtml, newType)`:
  - the BEFORE half keeps the ORIGINAL block id when non-empty;
  - the inserted widget and the AFTER half get FRESH ids;
  - empty BEFORE → the original block is removed and the widget takes its
    position;
  - empty AFTER → no trailing `rich_text` is created;
  - the whole split is ONE operation on the list (one model emission) —
    undo-friendly shape, no intermediate states.
  Sanitization needs nothing new: the halves are ordinary rich values, sanitized
  at save (FieldValidator) and at render (`safe_html`).
- **TipTap stays bounded (pinned):** UEditor/TipTap NEVER controls block order,
  ids, or the tree. Its only structural output is the "insert widget at cursor
  with before/after HTML" event; the Vue block tree remains canonical. No
  ProseMirror types leak above the prose component.

## §4 Outline rail

A collapsible rail (per blocks field, hidden by default behind a toggle in the
field header) listing the tree: icon + label + summary per block, nesting
indented, container regions grouped. Click scrolls the block into view, selects
(focuses) its header, and expands ancestors. No drag, no rename, no multi-select
in v1.

## §5 Explicit follow-ups (recorded, not abandoned)

1. Merge adjacent prose blocks on delete-between.
2. Paragraph-level drag INSIDE a prose region (Nuxt UI drag handle scoped to one
   block).
3. Enter-to-exit prose / advanced prose keyboard conventions.
4. Drag/reorder from the outline rail.
5. Per-block style presets (`editor_mode` metadata rides with this one naturally).
6. Visual canvas / iframe preview editor (consumes these components as its
   inspector; separate spec).
7. TipTap suggestion items for intra-text constructs beyond Nuxt UI defaults.

## §6 Out of scope

- Any change to the stored `{id,type,data}` model, validation, depth cap
  semantics, migrations, delivery, or render.
- Entry-editor-wide outline (the rail is per blocks field).
- New dependencies (everything uses `@nuxt/ui`, `vue-draggable-plus`,
  `@vueuse/core` — already installed).

## §7 Testing

**Unit (`useBlockListOps` — no DOM):**
- move/insertAt/remove/patchData parity with current behavior
- duplicate deep-copies nested lists and re-ids every nested block
- moveAcross between container regions preserves ids and order
- `canDropAt`: leaf into depth MAX allowed; subtree of height 2 into depth MAX
  rejected; the exact `targetDepth + subtreeDepth - 1 <= MAX` boundary cases
- `splitRichTextAt`: all four identity rules + single-emission shape

**Component (established `data-test` conventions; no Nuxt UI internals, no portal
DOM, no sortable simulation):**
- insert dividers render between/around blocks; divider click opens the menu at
  the right index; chosen type inserts there expanded
- `/` on a focused header opens the menu; keyboard move/duplicate/delete-confirm/
  expand handlers fire the ops
- prose detection: single-rich-field type renders `prose-block-{id}` chromeless;
  widget types render cards
- tail-prose selection: prefers allowed `rich_text`; falls back to the first
  allowed custom prose type; hidden when no allowed prose type exists
- slash-to-widget: the prose component's insert event drives `splitRichTextAt`
  (event-level test; TipTap cursor mechanics exercised manually — jsdom cannot
  host a full editor interaction)
- outline: renders nesting, click selects/expands
- existing `blocksField.spec.ts` scenarios keep passing (updated hooks where
  chrome moved)

**Type/gates:** `pnpm type-check`, `pnpm test`, and the PHP suites untouched
(no backend change in this project).
