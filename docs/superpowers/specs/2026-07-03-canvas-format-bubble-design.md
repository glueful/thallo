# Canvas v8.1: Selection-Following Format Bubble — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Amends:** `2026-07-03-canvas-format-bar-design.md` (v8, staged uncommitted —
v8.1 lands on top; one commit covers both)

## 0. Summary

The v8 region-docked bar positions poorly on tall prose regions (heading +
subheading + paragraph: the bar sits at the region top, far from the text
being formatted). v8.1 replaces the docking with TipTap-style behavior: one
bubble on `document.body`, shown only while the selection is non-collapsed
inside the active rich region, positioned off the SELECTION's own rect and
re-anchored on selection change and scroll.

Decision pins:

- **CSP pin reworded (user decision):** the bridge discipline becomes
  *no style ATTRIBUTES in emitted/serialized markup; all appearance in
  `preview.css`; bridge-owned UI may be positioned via CSSOM property
  assignment* (`el.style.transform = …`). This matches what strict
  `style-src` actually enforces — CSP blocks style attributes set by
  parsing/`setAttribute`, not CSSOM mutation, which is governed by
  `script-src` — so a strict theme CSP still cannot break. The bridge
  header comment is updated to say this. Geometry only: never colors,
  fonts, spacing via `el.style`.
- **Everything v8 pinned stays:** normalization (region-scoped, live +
  detached-clone commit pass), `runCommand` real-command-path discipline,
  `isSafeLinkUrl` before `execCommand`, `preventFocusSteal` on BOTH
  pointerdown and mousedown, deterministic `onEditInput()` after every
  successful action, capture-click bar exemption.
- **`window.prompt` stays for links in v8.1.** The TipTap inline
  "Paste a link…" input needs relatedTarget-aware blur surgery (typing in
  a bubble input blurs the region → commit-and-exit today) — recorded
  follow-up, not this change.

## 1. Bubble lifecycle

- `startEditing` (rich kind only): create the bubble (same four
  `data-format` buttons), append to `document.body`, hidden; register
  `document` `selectionchange` + `window` `scroll`/`resize` listeners.
- `endEditing`: remove the bubble and all three listeners.
- Visible IFF the current selection is non-collapsed AND inside
  `editing.region` (range containment against the region). Collapsed
  caret, selection outside the region, or no selection → hidden.
- `string`/`text` kinds never create a bubble (unchanged from v8).

## 2. Positioning

- `preview.css` owns appearance AND the static base:
  `position: fixed; top: 0; left: 0; visibility: hidden;` plus the
  existing dark-bar look. A `lemma-canvas-format-visible` class flips
  `visibility` — show/hide is class-driven, not style-driven.
- The bridge sets ONLY `bar.style.transform = 'translate(Xpx, Ypx)'`
  (relaxed pin, §0): viewport coordinates straight from
  `selection.getRangeAt(0).getBoundingClientRect()` — fixed positioning
  needs no scroll offsets.
- Placement: horizontally centered over the selection rect, clamped to
  the viewport (≥4px margins); vertically ABOVE the rect (8px gap),
  flipped BELOW (`rect.bottom + 8`) when there is no headroom.
- Reposition triggers: `selectionchange` (synchronous — the event is
  already coalesced by engines), `scroll` and `resize` (reposition only
  while visible).
- Width/height for centering/clamping come from
  `bar.getBoundingClientRect()` — measurable while `visibility: hidden`.

## 3. Unchanged machinery (v8)

Buttons, `applyFormat` → `runCommand` → `normalizeRichRegion(region)` →
`onEditInput()`, link validation, focus-steal prevention, the capture-click
`.lemma-canvas-format-bar [data-format]` exemption, and the commit-time
clone normalization all carry over verbatim. Because the bubble lives on
`document.body` — outside every wrapper — it structurally cannot appear in
committed `innerHTML` or in `mirror-duplicate` clones; that replaces (and
strengthens) the v8 shim-strip guarantee. The v8 shim-anchor
(`.lemma-canvas-format-anchor`) construction is REMOVED.

## 4. Testing (migrate the v8 bar tests, don't multiply)

jsdom has no real selection rects — stub `window.getSelection()` to return
`{ isCollapsed, rangeCount, getRangeAt: () => ({ getBoundingClientRect,
commonAncestorContainer }) }` shapes and dispatch
`document.dispatchEvent(new Event('selectionchange'))`.

- Rich grant creates the bubble hidden on `body`; string kind creates
  nothing; `endEditing` removes it and its listeners (a later
  `selectionchange` does nothing).
- Non-collapsed selection inside the region + `selectionchange` → visible
  class + `transform` matching the stubbed rect (centered, above).
- Flip case: rect near the viewport top → positioned below
  (`rect.bottom + 8`).
- Collapsed selection hides; non-collapsed selection OUTSIDE the region
  hides.
- Clamp case: selection at the far left → X clamps to the 4px margin.
- Action tests (bold/italic normalize + stay in session, deterministic
  commit, createLink validation, pointerdown/mousedown prevented) keep
  their v8 assertions with selectors moved from the wrapper to
  `document` (`document.querySelector('[data-format="bold"]')`).
- Committed HTML / duplicate clones never contain bubble markup
  (structural now, still asserted).

## 5. Out of scope (recorded follow-ups)

- Inline link input in the bubble (blur surgery).
- Paragraph-type dropdown; `s`/`u` marks.
- Cursor-following drag ghost (now unblocked by the same pin rewording).
