# Canvas v8.2: Inline Link Panel — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Amends:** `2026-07-03-canvas-format-bubble-design.md` (v8.1). Replaces the
`window.prompt` link capture with a TipTap-style inline input panel.

## 0. Summary

Clicking the bubble's link button opens a small input panel as a second row
inside the bubble instead of a browser prompt. This is the layer prompt()
was standing in for: it requires the rich-region session to SURVIVE focus
temporarily moving outside the region — solved with three focus exemptions,
a saved/restored selection range, and a positioning freeze while the panel
is open.

Decision pins (design review):

- **Saved range is session-scoped.** It is captured when the panel opens
  and CLEARED on `endEditing`, on `edit-flush`, and when the panel closes
  (apply, Escape, or hide). A range is never reused across sessions.
- **Empty trimmed URL = INVALID** (not unlink): unlink already has a
  dedicated top-row button, and empty-as-unlink is surprising. The panel
  stays open and marks invalid, like any other invalid URL.
- **Prefill scope:** the input prefills from the closest `<a>` ancestor of
  the selection ONLY when that `<a>` is inside `editing.region`. A
  link-like theme wrapper outside the region is ignored.
- **Close-on-success ordering:** a successful apply closes the panel AFTER
  the command + normalization + commit scheduling ran. An invalid apply
  keeps the panel open with an invalid-state class and runs nothing.
- **Positioning freeze:** while the panel is open, `positionFormatBubble`'s
  visibility/position recomputation is suspended — focusing the input
  collapses the selection, and without the freeze the bubble would hide
  itself exactly when the user clicks into the input. Closing the panel
  resumes normal recomputation (one immediate reposition).

## 1. Panel UI

- `.lemma-canvas-link-panel` — a second row inside the body-mounted bubble:
  `<input type="text" placeholder="Paste a link…">` + an apply button
  (`data-link-apply`, ↵ icon). Created lazily on first link-button click,
  shown/hidden per session interactions; removed with the bubble at
  `endEditing`.
- The link button (`data-format="link"`) TOGGLES the panel instead of
  prompting. Opening focuses the input (and prefills per §0). Escape in the
  input closes the panel and refocuses the region. Enter in the input
  applies (same path as the ↵ button).
- Invalid state: `lemma-canvas-link-invalid` class on the panel; cleared on
  the next input keystroke or successful apply. Appearance in
  `preview.css` only.

## 2. Focus surgery

- `preventFocusSteal` exempts the link input: pointerdown/mousedown on the
  INPUT proceed (it must be focusable); all other bubble mousedowns stay
  cancelled.
- The region's `blur` handler gets ONE exception: when
  `event.relatedTarget` is inside the bubble, the session stays alive
  (focus is visiting the panel). Blur to anywhere else keeps today's
  commit-and-exit.
- The capture click handler's editing exemption widens from
  `.lemma-canvas-format-bar [data-format]` to ANY click inside
  `.lemma-canvas-format-bar` — only `[data-format]` clicks dispatch format
  actions; panel-internal clicks (input, apply) are handled by the panel's
  own listeners and must not commit-and-exit.

## 3. Selection save/restore + apply flow

- Opening the panel saves `selection.getRangeAt(0).cloneRange()` (only when
  non-collapsed and inside the region — the same strict containment as
  visibility; otherwise the link button is a no-op).
- Apply: trim the input; `isSafeLinkUrl` (empty = invalid, §0) → invalid:
  mark and keep open. Valid: focus the region, restore the range
  (`removeAllRanges()` + `addRange(saved)`), then the EXISTING path —
  `runCommand('createLink', url)` → `normalizeRichRegion(region)` →
  `onEditInput()` → `positionFormatBubble()` — and close the panel (§0
  ordering pin). If `runCommand` reports failure (missing/throwing engine),
  close nothing, schedule nothing (v8 discipline).
- Prefill: closest `<a>` from `range.commonAncestorContainer`, accepted
  only if `editing.region.contains(a)` (§0 pin).

## 4. Positioning freeze

- New bubble state: `linkPanelOpen`. While true, `positionFormatBubble`
  returns without changing visibility or transform.
- Panel close (apply success, Escape, or endEditing) clears the state and
  triggers one immediate reposition (which may hide the bubble if the
  selection is gone — correct once the panel is closed).

## 5. Testing (bridge direct suite)

- Link click opens the panel (input + apply present, bubble still visible);
  prompt is NEVER called (assert a `window.prompt` spy stays uncalled).
- Prefill: selection inside a region `<a href="https://x.test/old">` →
  input value prefilled; enclosing link OUTSIDE the region → empty input.
- Input mousedown NOT default-prevented; format-button mousedown still
  prevented.
- Region blur with `relatedTarget` inside the bubble → session survives
  (no `edit-end`); blur with `relatedTarget` elsewhere → commit-and-exit
  (unchanged v3 behavior).
- Apply with valid URL: `removeAllRanges`/`addRange(savedRange)` called
  BEFORE `execCommand('createLink', false, url)` (spy call order), panel
  closes, deterministic commit still fires (fake timers).
- Apply with invalid URL (including empty/whitespace): no `execCommand`,
  panel open, invalid class present; next keystroke clears the class.
- Escape in the input closes the panel and refocuses the region; session
  still active.
- Freeze: with the panel open, a collapsed-selection `selectionchange`
  does NOT hide the bubble; after close, the same event hides it.
- Saved range cleared on `endEditing` and on `edit-flush` (reopening a
  panel in a NEW session never sees a stale range — assert via spies that
  apply after re-grant uses the newly saved range).
- Typing in the input never triggers stage keyboard shortcuts (formish
  guard, asserted with Backspace on the input target).

## 6. Out of scope (recorded follow-ups)

- Open-in-new-tab preview button; paragraph-type dropdown; `s`/`u` marks.
