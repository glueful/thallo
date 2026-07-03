# Canvas v2: Stage Toolbar Affordances — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Builds on:** `2026-07-03-visual-canvas-design.md` (v1 select-only stage)

## 0. Summary

Selecting a block in the canvas stage shows a floating toolbar injected by the
bridge **inside the iframe**: move up, move down, duplicate, delete, add-after.
Every action is an **intent** posted to the canvas page; the tree mutation
executes in the owning `BlocksField` (routed through `FieldEditor` by
`hasBlock`, exactly like v1's `selectBlockById`). After the parent commits the
mutation it posts a **mirror** command back, and the bridge updates the stage
DOM optimistically. The tree stays the single mutation authority — the stage
never mutates anything on its own, so a rejected or no-op intent (move up at
index 0) produces no mirror and nothing moves.

Decision pins from brainstorm review:

- **Interaction model:** floating toolbar with discrete ops — no drag physics
  in the iframe. Free drag is a recorded follow-up, not v2.
- **Stage sync:** optimistic DOM mirror for ops that are exact on existing
  rendered markup (move, delete, duplicate). Add-after shows nothing in the
  stage until the next Save & refresh — no fake placeholders.
- **Toolbar host:** inside the iframe, vanilla JS + static CSS. No parent-side
  geometry syncing.
- **Nesting scope:** same-list only. Move up/down reorders within the block's
  current list; add-after inserts a sibling. Cross-container restructuring
  stays in the inspector.
- **Op routing:** `BlocksField`-exposed methods via `FieldEditor` — each
  field's own `ops`/`regionsOf`/allowlist remain the single mutation
  authority; the canvas page never touches tree internals.
- **Toolbar positioning (review pin):** DOM placement with static CSS only —
  never inline geometry (`top`/`left` styles would violate the v1 CSP pin).
  Mechanism in §3.
- **Delete safety (review pin):** parent-side confirmation. The bridge stays
  dumb — it posts a delete *request*; the canvas page owns the confirm UI.
- **Picker rules (review pin):** `pickerTypesFor(id)` resolves the list
  containing `id` and applies **that list's** blocks-field `blockTypes`
  allowlist. The inspector's insert menu is aligned to the same per-list rule
  in this work (today it uses the root field's allowlist everywhere — see §5).

## 1. Bridge protocol additions

All messages ride the v1 envelope: nonce-echoed, origin-checked, silent until
`lemma:canvas-hello`.

**Intents (iframe → parent):**

| Type | Payload | Meaning |
| --- | --- | --- |
| `lemma:block-move` | `{ id, delta: 1 \| -1 }` | Reorder within the block's own list |
| `lemma:block-duplicate` | `{ id }` | Duplicate in place |
| `lemma:block-delete-request` | `{ id }` | Ask the parent to confirm + delete |
| `lemma:block-add-after` | `{ id }` | Open the parent-side type picker |

**Mirror commands (parent → iframe), posted only after the tree mutation
committed:**

| Type | Payload | DOM effect |
| --- | --- | --- |
| `lemma:mirror-move` | `{ id, beforeId?: string, afterId?: string }` (exactly one) | `insertBefore` the wrapper before `wrapperFor(beforeId)`, or immediately after `wrapperFor(afterId)`. Neighbor-relative on purpose: the bridge cannot know where a list ends in theme DOM, but "next to this sibling wrapper" is always exact. Guard: the reference wrapper must share the moved wrapper's DOM parent — a stale/mismatched reference in another container makes the mirror a no-op, never a cross-parent move (same-list pin) |
| `lemma:mirror-remove` | `{ id }` | Remove the wrapper (and hide the toolbar if it was selected) |
| `lemma:mirror-duplicate` | `{ sourceId, idMap }` | Deep-clone the source wrapper, **strip canvas UI state from the clone** (remove any `.lemma-canvas-toolbar` element and the `lemma-canvas-anchor` / `lemma-canvas-selected` / `lemma-canvas-hover` classes and their `-target` fallbacks — the source is usually the selected block, so its wrapper carries live toolbar/ring state), rewrite every `data-lemma-block` inside the clone via `idMap` (old id → new id, whole subtree — `reIdSubtree` re-ids everything), insert the clone after the source |

Why mirrors are exact under the same-list pin: the `blocks()` filter emits
sibling wrappers contiguously into wherever the template placed the output, so
same-list wrappers are consecutive DOM siblings under one parent element.
Sibling `insertBefore` is therefore a faithful reorder; delete removes exactly
the rendered instance; duplicate's clone renders identically because the data
is identical. A mirror whose wrapper id is missing from the DOM is ignored
(annotation only wraps successfully rendered instances with string ids).

**Add-after has no mirror.** A new block's render cannot be faked client-side;
it appears in the stage on the next Save & refresh. The intent's effect is
inspector-side: the new block is created, selected, and focused there, and the
dirty chip communicates the pending change.

## 2. Intent lifecycle (single flow, all ops)

```
stage click on toolbar button
  → bridge posts intent (nonce-echoed)
    → canvas page handler
      → FieldEditor routes by hasBlock(id) to the owning BlocksField
        → BlocksField method mutates the tree via its ops layer
      → on success: canvas page posts the mirror command (except add-after)
      → on no-op/reject (boundary move, unknown id): no mirror, nothing moves
```

Delete inserts a parent-side confirm between the intent and the mutation
(§4). The dirty state needs no new plumbing: mutations flow through
`v-model`, so the existing `dirty` computed and the Save & refresh loop work
untouched, and the next re-mint replaces all mirrors with truth. Stale-lock
409 semantics are unaffected — nothing in this feature saves.

**Save-failure reset rule (review pin):** on any Save & refresh failure after
optimistic mirrors (stale-lock 409, migration 409, network error), the canvas
**reloads the current iframe URL** to discard mirror-only DOM — the stage
falls back to the last-applied truth — while the local dirty fields are kept
and the existing error banner shows. No re-mint happens on failure; the user
re-mints only via the explicit Refresh preview affordance. This guarantees the
stage never keeps showing optimistic DOM that failed to save.

## 3. In-iframe toolbar

One toolbar element per document, created lazily on first selection and
**moved via DOM placement, never positioned with inline styles**:

- On select, the bridge inserts the toolbar as the **first child of the
  wrapper's first element child** and adds `lemma-canvas-anchor` to that same
  element. Static CSS does the rest:
  `.lemma-canvas-anchor { position: relative; }` and
  `.lemma-canvas-toolbar { position: absolute; }` pinned to the anchor's
  top-right corner with constant offsets baked into `preview.css` — no
  per-block geometry ever computed. (The `.lemma-preview-block` wrapper itself
  is `display: contents` and cannot anchor absolute positioning; the block's
  root element can.) Edge: a wrapper with no element child (text-only render)
  gets no toolbar — selection and mirrors still work; only the affordance is
  skipped.
- On deselect / re-select the bridge removes the toolbar and the anchor class
  from the previous host before re-inserting at the new one. `mirror-remove`
  of the selected block hides the toolbar and clears selection state.
- Buttons: move up, move down, duplicate, delete, add-after — inline SVG
  icons (no external fetches), `type="button"`, `aria-label`s, styled by new
  `preview.css` classes. All styling stays in the static stylesheet — the v1
  CSP pin (no inline styles anywhere in preview annotation) holds. If exact
  overlay geometry is ever needed, that is a separate CSP decision — recorded,
  out of scope.
- The v1 capture-phase click handler gains one branch: a click inside
  `.lemma-canvas-toolbar` dispatches the matching intent (still
  `preventDefault`/`stopPropagation`) instead of re-selecting; every other
  click behaves exactly as v1. Toolbar clicks never change selection.
- The toolbar renders only on annotated wrappers — unwrapped content (blocks
  that failed to render, non-block markup) never shows it, same as v1
  selection.

The bridge stays a single static, token-free asset. Growing complexity is the
trigger for direct tests (§6): the file is now evaluated in jsdom and driven
with synthetic `message` events, instead of only being covered indirectly.

## 4. SPA routing

**`BlocksField` exposed API grows** (v1 exposed `onDragEnd`, `selectBlock`,
`hasBlock`):

| Method | Returns | Semantics |
| --- | --- | --- |
| `moveBlock(id, delta)` | `{ beforeId: string } \| { afterId: string } \| null` | Same-list reorder via `ops.moveById`; `null` when it's a boundary no-op or `id` is unknown. Returns the moved block's new neighbor: `beforeId` (the sibling now following it) when one exists, else `afterId` (the sibling now preceding it — a committed move always has at least one neighbor, or it would have been a no-op). Exactly the payload `mirror-move` needs |
| `duplicateBlock(id)` | `{ newId: string, idMap: Record<string, string> } \| null` | `ops.duplicateById`, plus the old→new id map for the whole re-id'd subtree (computed by parallel-walking the source subtree and its copy — same shape by construction) |
| `deleteBlock(id)` | `boolean` | `ops.removeById`; `true` if the block existed |
| `insertAfter(id, typeSlug)` | `string \| null` | New empty block of `typeSlug` inserted as the next sibling of `id` via `ops.insertAt`; returns the new block id; selects/expands it like the inspector's insert |
| `pickerTypesFor(id)` | `BlockType[]` | Active types filtered by the **containing list's** allowlist (§5) |

**`FieldEditor`** grows matching routed wrappers over its live `blocksFields`
map, same pattern as `selectBlockById`: find the field where
`hasBlock(id)`, call, return the result (or `null`/`false`/`[]` when no field
owns the id).

**Canvas page** wires the bridge callbacks:

- `block-move` → `moveBlock` → on non-null result, post `mirror-move`.
- `block-duplicate` → `duplicateBlock` → post `mirror-duplicate` with the
  idMap; select the new block in the inspector (`selectBlockById(newId)`) and
  update `selected`.
- `block-delete-request` → open the canvas-page confirm (a small modal with
  Delete/Cancel — parent-side, mirroring the inspector's two-step per-card
  confirm semantics; the bridge never deletes). On confirm → `deleteBlock` →
  post `mirror-remove`; clear `selected` if it was the deleted block.
- `block-add-after` → open the parent-side type picker (§5). On choose →
  `insertAfter` → `selectBlockById(newId)`, update `selected`, no mirror.

The v1 outline (`CanvasOutline`) needs no changes: it renders from `fields`,
which every op mutates reactively.

## 5. Picker rules: per-list allowlists (canvas + inspector alignment)

`pickerTypesFor(id)` resolves the **list containing `id`**:

- Root list → the entry field's own `blockTypes` allowlist (v1 behavior).
- Nested region → the containing block type's blocks-typed schema field for
  that region; use **its** `blockTypes` allowlist. Empty allowlist = all
  active types, same convention as the root field.

Filter: `active` types ∩ that allowlist. **No picker-time depth gate is
needed:** a sibling insert lands in an already-rendered list, and container
regions at `MAX_BLOCK_DEPTH` render the max-depth notice instead of a list, so
no insert point exists past the cap. Container types remain insertable at the
cap boundary — their regions simply render capped, which is the inspector's
existing behavior.

**Inspector alignment (scope addition, flagged at review):** today
`BlockInsertMenu` reads the field-global `ctx.pickerTypes` for every list,
ignoring nested regions' own `blockTypes`. The pin "consistent with the
inspector's insert dividers" therefore requires fixing the inspector too:
`BlocksContext` gains a per-list resolver
(`pickerTypesForList(parentId: string | null, region: string | null): BlockType[]`),
`BlockInsertMenu` (and the tail-add path it powers) consumes it with its
list's identity, and `pickerTypesFor(id)` is the id-addressed convenience over
the same resolver. `ProseBlockEditor`'s `/` menu is the third consumer — its
widget entries insert as split-siblings into the same list, so it takes the
same per-list types (resolved by its `BlockCard`, which knows the list
identity). One rule, all consumers — the canvas and the inspector can never
drift.

## 6. Testing

**Vitest (admin):**

- `BlocksField` methods: `moveBlock` beforeId correctness incl. boundary
  no-ops and nested lists; `duplicateBlock` idMap covers the whole subtree
  with fresh ids; `insertAfter` sibling position + selection; `deleteBlock`;
  `pickerTypesFor` per-list allowlists (root vs nested region, empty-allowlist
  convention).
- `FieldEditor` routing of each new method across multiple blocks fields
  (extends the v1 `selectBlockById` routing suite).
- Canvas page: intent → mutation → mirror wiring with a mocked bridge; delete
  confirm flow (request → modal → confirm → mirror-remove, cancel → nothing);
  add-after picker (options from `pickerTypesFor`, choose → new block selected,
  no mirror posted); save-failure reset rule (409 → iframe reloads the SAME
  URL, no re-mint call, dirty fields kept, banner shown).
- **Bridge direct tests (new pattern):** a suite reads
  `packages/lemma-render/assets/preview/preview-bridge.js` as raw text and
  evaluates it in jsdom; drives it with synthetic `message` events and DOM
  fixtures. Covers: hello/nonce discipline (v1 behaviors now locked), toolbar
  insertion/anchor class on select, intent posts per button, mirror-move /
  mirror-remove / mirror-duplicate DOM effects (including idMap rewriting,
  missing-wrapper no-ops, and duplicate-clone stripping of toolbar/anchor/
  selected/hover state when the source is the selected block),
  toolbar-click-doesn't-reselect.
- Inspector: `BlockInsertMenu` per-list filtering (nested region allowlist
  respected, root unchanged).

**PHP:** no server changes. Existing asset-serving and annotation integration
tests keep passing; `preview.css`/`preview-bridge.js` content changes need no
new PHP assertions.

**Manual/browser acceptance (recorded):** toolbar placement across real theme
markup (anchor class on themed elements), mirror fidelity after mixed op
sequences vs the post-apply truth, confirm modal flow, and the outstanding v1
items (rings, link inertness, cross-origin posture).

## 7. Out of scope (recorded follow-ups)

- Free drag / drag handles in the stage; cross-container moves from the stage.
- Keyboard shortcuts on stage selection.
- Ephemeral render endpoint (would make add-after visible pre-apply).
- Inline-geometry overlay positioning (separate CSP decision if ever needed).
- Edit-in-place text, per-block style presets, merge-adjacent-prose, outline
  drag (pre-existing list).
