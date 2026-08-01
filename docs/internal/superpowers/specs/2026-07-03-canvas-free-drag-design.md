# Canvas v6: Free Drag in the Stage — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Builds on:** v2 intent/mirror protocol, the anchored toolbar (+ void-host
shim), auto-apply (v5)

## 0. Summary

A grip on the stage toolbar drags the selected block within its list,
sortable-style: the wrapper reorders LIVE between siblings as the pointer
crosses gap midpoints (the mirror-move operation, applied continuously), and
the drop posts one neighbor-relative intent that `BlocksField` — still the
only tree authority — validates and applies. Cross-container drops stay
inspector-only.

Decision pins from brainstorm review:

- **Live reorder, toolbar grip** (chosen over indicator lines and
  press-and-hold): the DOM move IS the feedback, needs no positioned
  indicator (CSP pin holds), and the grip lives where structural ops already
  live.
- **One intent per drag (review pin):** the bridge posts `block-move-to`
  ONLY on `pointerup`. All pointermove reordering is visual; the model
  changes once. No tree patches mid-drag, so auto-apply stays quiet until
  the drop lands the single change.
- **Rejection reloads before auto-apply can run (review pin):** a failed
  `moveBlockToById` must NOT mutate `fields` and must call `reloadStage()` —
  the stage falls back to server truth, `stageStale` stays exactly as it was
  before the drag, and the honest-stage rule holds. (No tree change also
  means the auto-apply debounce never even schedules from the rejection.)
- **Same-list only:** contiguous-sibling DOM geometry is what makes live
  reorder exact, and it avoids duplicating the inspector's cross-container
  depth rules inside the bridge. Cross-container remains the inspector's
  drag.

## 1. Bridge drag session

- **Grip:** a new FIRST toolbar button (`data-action="drag"`, grip-vertical
  inline SVG, `cursor: grab` via static CSS). `pointerdown` on it starts a
  drag of the selected block; `setPointerCapture` where available (guarded —
  jsdom lacks it). The capture-phase click handler's toolbar branch ignores
  the grip (drags are pointer-driven; the grip never posts a click intent).
- **State:** `{ wrapper, originalNext }` — `originalNext` is the wrapper's
  next sibling at drag start, for Escape/cancel restore. The wrapper gets a
  static `.lemma-canvas-dragging` class (dimmed); the toolbar travels with
  the block (pointer capture keeps events on the grip).
- **pointermove:** compute same-list siblings — the dragged wrapper's element
  siblings that are `.lemma-preview-block` wrappers (the same DOM-contiguity
  guarantee mirror-move enforces). For each, measure its first element
  child's rect (skip element-less wrappers); when the pointer's `clientY`
  crosses a sibling's vertical midpoint, `insertBefore` the dragged wrapper
  accordingly. Vertical midpoints only in v1 (recorded limitation:
  horizontal lists).
- **pointerup:** if the wrapper's position changed from drag start, post ONE
  `lemma:block-move-to {id, beforeId | afterId}` (neighbor-relative, exactly
  one key, same family as mirror-move: `beforeId` = the wrapper's next
  sibling wrapper id, else `afterId` = its previous sibling wrapper id).
  Unchanged position → post nothing. Either way, end the drag and set the
  one-shot click-suppression flag (the click that follows a completed drag
  must not re-select or navigate).
- **Escape / pointercancel:** restore the wrapper before `originalNext`
  (append when null), end the drag, post nothing.
- **Suppression:** hover-ring handling is skipped while dragging. No drag
  can start during an edit session (the toolbar is detached while editing —
  the grip doesn't exist). Edge auto-scroll while dragging: recorded
  follow-up.

## 2. Parent handling (authority re-check, no mirror back)

- Composable: `onBlockMoveTo(cb: (id: string, neighbor: {beforeId: string} | {afterId: string}) => void)`.
- Page routes to `FieldEditor.moveBlockToById(id, neighbor)` →
  `BlocksField.moveBlockTo(id, neighbor): boolean`:
  - locate the dragged block AND the reference block; **deny unless both are
    in the SAME list** (parentId + region equal) — the bridge's geometry is a
    request, never authority;
  - compute the target index against the list WITHOUT the dragged block
    (`beforeId` → the ref's position in that reduced list; `afterId` → ref's
    position + 1);
  - apply via the existing `ops.moveAcross`; return `true`.
  - Any lookup/validation failure → return `false` with NO tree mutation
    (review pin).
- **No mirror is posted back** — the drag was the mirror; the stage already
  shows the accepted result. The single tree change triggers auto-apply's
  normal debounce for server truth.
- **Page failure path (review pin):** `moveBlockToById` returning `false` →
  `reloadStage()` immediately. `fields` was never mutated, so `stageStale`
  is untouched and the auto-apply scheduler has nothing to do; the reload
  simply snaps the stage back to server truth.

## 3. Coexistence

Auto-apply: silent during the drag (no tree changes), one debounce after the
drop. Scroll preservation: untouched (the drop's eventual auto-reload
restores position as usual). Edit sessions: mutually exclusive with drags by
construction. Mirrors/duplicate stripping: the `.lemma-canvas-dragging` class
joins `stripCanvasState`.

## 4. Testing

- **Bridge direct suite** (jsdom rects are zero — stub
  `getBoundingClientRect` on sibling first-children per test): grip
  pointerdown + pointermove across a midpoint reorders the DOM live and
  posts NOTHING; pointerup posts exactly ONE `block-move-to` with the right
  neighbor; a returned-to-origin drop posts nothing; Escape restores the
  original order and posts nothing; the post-drag click is swallowed
  (no `block-select`); dragging class stripped from duplicate clones.
- **Vitest:** composable message shape (beforeId/afterId variants, malformed
  dropped); `moveBlockTo` index math (beforeId, afterId, list end, adjacent
  no-op move accepted, nested lists) and the cross-list deny returning
  `false` without mutation; FieldEditor routing; canvas-page wiring
  including the `false → reloadStage()` path with `fields` asserted
  unchanged (via the next save payload) and no auto-apply scheduled.
- **Manual acceptance (recorded):** drag feel on the real theme, long pages
  near the fold, touchpad behavior, drag + auto-apply rhythm.

## 5. Out of scope (recorded follow-ups)

- Cross-container drops from the stage.
- Horizontal-list midpoint math.
- Edge auto-scroll during drag.
- Touch long-press initiation.
