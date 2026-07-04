# Canvas Polish Batch (v9) — Design

**Date:** 2026-07-04
**Status:** Combined design+spec (single review gate by agreement); on
approval, execution proceeds directly with the plan as a working document.
**Builds on:** v6 free drag, v7 keyboard shortcuts, v8.x formatting bubble
(and its reworded CSP pin: appearance in `preview.css`, bridge-owned UI
geometry via CSSOM property assignment).

## 0. Batch contents and shared rules

Four independent polish items. Any one can be dropped from the batch
without touching the others.

1. Bubble active-state indication (B lights up when the caret is in bold).
2. Cursor-following drag ghost.
3. Edge auto-scroll while dragging.
4. Outline keyboard shortcuts (parent-side twin of the v7 stage scheme).

Shared rules:

- **No new mutation paths.** Items 1–3 are bridge-visual only — they post
  NOTHING new. Item 4 reuses the page's existing intent handlers verbatim
  (the same functions the bridge callbacks call); the outline only emits.
- **CSP pin (v8.1 wording) holds:** ghost/auto-scroll geometry via CSSOM
  `transform`/`scrollBy` only; appearance in `preview.css`.
- Bridge state hygiene: ghost and auto-scroll state live on the `drag`
  session and are torn down on pointerup, pointercancel, AND Escape
  rollback; active-state classes live on the bubble, torn down with it.

## 1. Bubble active-state indication

- While the bubble is visible, buttons reflect the caret/selection's marks:
  `document.queryCommandState('bold' | 'italic' | 'underline' |
  'strikeThrough')` toggles a `lemma-canvas-format-active` class per
  button. (Underline/strikethrough buttons were added post-v8.2 by user
  request in commit 71bc3eb — the link-panel spec's `s`/`u` deferral is
  superseded; the bubble has six controls and all four mark buttons get
  state.) Link active-state comes from containment instead (the selection's
  common ancestor has a closest `<a>` INSIDE the region — same rule as the
  panel prefill); unlink shares the link state.
- Updated inside `positionFormatBubble()` when the bubble is placed
  (selectionchange/scroll/resize/post-action already funnel through it).
  **No-stale pin (review P2):** whenever the bubble is NOT placed (hidden),
  all active classes are CLEARED — a bold/link state can never survive a
  hide and flash stale on the next show; every show recomputes from the
  live selection. While frozen (link panel open) everything is left as-is —
  the saved selection the panel operates on IS the state shown.
- Defensive: `queryCommandState` missing (jsdom) or THROWING (some engines
  throw on detached selections) → that button simply stays inactive. No
  crash, no stale `true`.
- CSS: `.lemma-canvas-format-bar button.lemma-canvas-format-active`
  gets the hover background plus an accent icon color — static rules.
- Tests: stubbed `queryCommandState` (bold→true, italic→false) →
  selectionchange marks B active, I inactive; selection inside a region
  `<a>` marks link+unlink active; missing/throwing `queryCommandState` →
  no classes, no throw; states update after a bar action (re-anchor path);
  **no-stale (review P2):** bold-active, then collapse (hide) → classes
  cleared, then reopen over plain text with bold→false → B stays inactive.

## 2. Cursor-following drag ghost

- On the FIRST `pointermove` of a drag (not on gripDown — a click without
  movement must not flash), the bridge builds
  `<div class="lemma-canvas-drag-ghost">` on `document.body` containing a
  `stripCanvasState`-cleaned `cloneNode(true)` of the dragged wrapper's
  host element, and positions it at the pointer with
  `ghost.style.transform = 'translate(x+12, y+12)'` per pointermove
  (CSSOM geometry, allowed by the reworded pin).
- Appearance (static CSS): fixed at 0/0, `pointer-events: none`, capped
  `max-width`/`max-height` with `overflow: hidden`, reduced opacity,
  card-like radius+shadow — a compact preview, not a full-size copy.
- Torn down in `endDrag()` (covers pointerup, pointercancel, and Escape
  rollback — all funnel through it).
- The existing in-list dimming (`lemma-canvas-dragging`) stays: the ghost
  complements, not replaces, the live reorder.
- Tests: gripDown alone → no ghost; first pointermove → ghost on body with
  pointer-tracking transform and NO toolbar/canvas classes inside (strip
  applied); second pointermove updates transform; pointerup removes it;
  Escape rollback removes it.

## 3. Edge auto-scroll while dragging

- During a drag, when `clientY` enters the top or bottom 48px of the
  viewport, a 16ms interval scrolls `window.scrollBy(0, ±12)` until the
  pointer leaves the zone or the drag ends. Direction: up-zone scrolls up,
  bottom-zone scrolls down. One interval at a time; zone membership is
  re-evaluated on every pointermove (entering the opposite zone swaps
  direction; leaving clears).
- Viewport height read from `window.innerHeight` (guarded non-positive →
  feature inert).
- Interval cleared in `endDrag()` and on zone exit. Reorder targeting
  self-corrects on the next real pointermove after scrolling (v1: no
  synthetic re-evaluation while the pointer is stationary — recorded
  follow-up if it feels off).
- Tests (fake timers, `scrollBy` spy, stubbed `innerHeight`): pointer in
  bottom zone + advance → repeated positive scrollBy; move to middle →
  interval stops; top zone → negative; endDrag/Escape clears.

## 4. Outline keyboard shortcuts

- `CanvasOutline` rows are already native `<button>`s. The outline root
  gets ONE `keydown` handler; it acts on the CURRENTLY SELECTED block
  (`props.selected`) regardless of which row has focus, mirroring the v7
  stage scheme: Alt+ArrowUp/Down → `move` emit (∓/±1); Backspace/Delete →
  `delete-request` emit; Cmd/Ctrl+D → `duplicate` emit (preventDefault
  beats the bookmark); Escape → `deselect` emit. No selection → inert.
  Enter/Space keep native button semantics (row selection) — NOT
  intercepted.
- New emits: `move: [id: string, delta: 1 | -1]`,
  `deleteRequest: [id: string]`, `duplicate: [id: string]`,
  `deselect: []`.
- Page refactor (shared handlers, no new mutation paths): extract the
  bodies of the existing bridge callbacks into named functions —
  `moveBlockAndMirror(id, delta)`, `openDeleteConfirm(id, anchor)` (null
  anchor → centered confirm, exactly the keyboard path the stage already
  uses), `duplicateAndMirror(id)` — and wire BOTH the bridge callbacks and
  the outline emits to them. `deselect` sets `selected.value = null` AND
  posts `bridge.highlight('')`: the bridge's existing highlight handler
  calls `clearSelection()` when the id resolves to no wrapper, so the
  stage ring and toolbar clear too — full parity with v7's stage Escape,
  through an existing message (no protocol change).
- Tests (canvas-page.spec): focus an outline row, Alt+ArrowDown →
  `mirrorMove` called (tree moved); Backspace → delete confirm rendered
  (centered variant); Cmd+D → `mirrorDuplicate` called and selection
  follows the new id; Escape → outline highlight cleared; with nothing
  selected, keys are inert; plain ArrowDown does nothing (Alt required).

## 5. Out of scope (recorded)

- Session-wide working-copy overlay; partial DOM patching (each gets its
  own full cycle).
- Ghost drop-animation (ghost flying to the slot); synthetic reorder
  re-evaluation while auto-scrolling with a stationary pointer.
- Outline drag-to-reorder; outline focus-follows-selection.
