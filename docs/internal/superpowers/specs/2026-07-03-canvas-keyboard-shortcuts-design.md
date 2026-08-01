# Canvas v7: Stage Keyboard Shortcuts — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Builds on:** v2 intents/mirrors, v3/v4 edit sessions, v6 drag

## 0. Summary

With a block selected in the stage iframe, the keyboard drives the EXISTING
intent protocol — no new mutation paths. One new notification message
(`block-deselect`) keeps parent selection state honest. Strict guards ensure
typing, dragging, and theme form controls never trigger shortcuts.

Decision pins from brainstorm review:

- **Alt+Arrows scheme** (plain arrows keep scrolling the page; Cmd/Ctrl+D
  beats the bookmark shortcut because focus is inside the iframe).
- **Enter mirrors the wrapper-level double-click fallback EXACTLY (review
  pin):** it acts only when the selected block OWNS exactly one
  `.lemma-edit-region` — i.e. regions matching
  `[data-lemma-edit-block="<selectedId>"]`, NOT every region in the
  wrapper's subtree (a container block's subtree includes nested child-block
  regions; counting those would edit a CHILD while the parent is selected —
  plan-review P1). Zero owned regions or more than one (CTA-style blocks
  with several editable fields) → ignored, no request. The rule is extracted
  into one bridge helper used by BOTH the keyboard path and the wrapper-level
  double-click fallback, so pointer and keyboard semantics stay aligned (this
  also fixes the same container-block leak in the shipped pointer fallback).
- **`block-deselect` is worth the new message:** without it the iframe looks
  deselected while the parent still thinks a block is active — outline and
  inspector state would lie.

## 1. Key map (bridge; active only while a block is selected)

| Key | Intent posted | Notes |
| --- | --- | --- |
| Alt+ArrowUp / Alt+ArrowDown | `block-move {id, delta: -1/+1}` | mirror flows back as with the toolbar buttons |
| Backspace / Delete | `block-delete-request {id}` | no rect → the confirm's centered fallback |
| Cmd/Ctrl+D | `block-duplicate {id}` | `preventDefault` suppresses the browser bookmark |
| Enter | `edit-request {id, field}` | ONLY when the selected block OWNS exactly one edit region (`[data-lemma-edit-block]` match — review pin); field read from that region |
| Escape | none — local deselect + `block-deselect {id}` | clears ring/toolbar/shim; parent clears `selected` |

Handled keys are `preventDefault`ed/`stopPropagation`ed; unhandled keys pass
through untouched.

## 2. Guards (airtight, in this order)

The bridge's keydown handler (document, capture phase) returns without
acting when ANY of:

- no block is selected (`selectedId === null`);
- an edit session is active (`editing` — typing must never move/delete
  blocks; Escape-while-editing keeps its commit-and-exit meaning via the
  edit session's own handler, which runs on the region);
- a drag is active (`drag` — Escape there means rollback, handled by the
  drag's own capture handler);
- the event target is a theme form control or editable: `input`,
  `textarea`, `select`, or `target.isContentEditable`;
- the event target is inside the bridge's own toolbar
  (`target.closest('.lemma-canvas-toolbar')`) — the handler is
  document-capture, so without this guard Enter on a focused toolbar
  button would be intercepted as "edit selected block" before the
  button's native activation runs, and Backspace/Delete could delete
  while focus sits on a toolbar control (review pin). Toolbar buttons
  keep normal keyboard semantics; pointer behavior stays on the
  existing toolbar click branch.

## 3. Parent side

- Composable: `onBlockDeselect(cb: (id: string) => void)`; message branch for
  `lemma:block-deselect {id}`.
- Page: `bridge.onBlockDeselect(() => { selected.value = null })`.
- Everything else (move mirror, delete confirm, duplicate selection-follow,
  edit grant) is the existing wiring, byte-identical.

## 4. Testing

- **Bridge direct suite:** each mapped key posts its intent only while
  selected; plain arrows post nothing (Alt required); Backspace posts the
  delete request; Cmd+D posts duplicate with `defaultPrevented`; Enter posts
  `edit-request` for a single-owned-region block and NOTHING for zero-region,
  two-region, and container-with-one-child-region blocks (review pins); the
  wrapper-level double-click fallback shares the same owned-region helper (a
  container double-click no longer adopts a child's region); a drag-guard
  test proves shortcuts are inert mid-drag and Escape means rollback, never
  `block-deselect` (plan-review P2); Escape clears the ring/toolbar and posts
  `block-deselect`; guards — no posts when the target is an `input`, when
  the target is a focused toolbar button (Enter on the toolbar must not
  post `edit-request`; review pin), during an edit session, or during a
  drag.
- **Vitest:** `onBlockDeselect` dispatch; page wiring clears `selected`.
- **Manual (recorded):** feel pass alongside drag; Alt+Arrow key-repeat
  rhythm.

## 5. Out of scope (recorded follow-ups)

- Parent-side shortcuts (outline/inspector focus contexts).
- A visible shortcut cheat-sheet.
- Key-repeat throttling for held Alt+Arrows (native repeat posts repeated
  moves; revisit only if it feels wrong).
