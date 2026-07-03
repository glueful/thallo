# Canvas v8: In-Stage Formatting Bar — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Builds on:** v3 edit-in-place (rich sessions), v4 kind matrix, stage-toolbar DOM-placement mechanism

## 0. Summary

While a `rich` edit session is active in the stage iframe, a small
bridge-owned bar docked above the region offers Bold / Italic / Link /
Unlink. Marks apply via `document.execCommand`, and a NORMALIZATION pass
rewrites the output into the sanitizer's allowlist shape (`b→strong`,
`i→em`, unwrap `span[style]`) — that pass is the key reason this is safe
for v1, and its commit-time half also fixes a latent v3 bug: native Cmd+B
already produces `<b>`, which the save/render sanitizer drops WITH ITS
CHILDREN, so bolded text vanishes at the next auto-apply today.

Everything flows through the existing chain unchanged: region `input` →
debounced `text-changed` → kind-matrix re-validation → patch → auto-apply.
No new bridge messages; no parent/admin changes. This is a bridge-asset +
`preview.css` feature.

Decision pins:

- **Region-docked bar** (chosen over a selection-following bubble): same
  DOM-placement + static-CSS mechanism as the block toolbar — zero new CSP
  surface. The bubble variant would need a new positioning mechanism
  (CSSOM/constructed stylesheet) and is a recorded follow-up.
- **Normalize ONLY inside the active edit region (review pin).** The bridge
  must never walk the whole document or wrapper: theme markup may
  legitimately use `<b>`, `<i>`, or styled spans outside editable content.
  Live normalization operates on `editing.region`; commit normalization
  operates on a detached clone of that region. Nothing else is touched.
- **`createLink` validates BEFORE execCommand (review pin).** Empty or
  invalid prompt input → no-op, `execCommand` is never called. The safe-url
  predicate: relative paths (`/…`, `#…`, `?…`, bare paths), `http:`,
  `https:`, `mailto:` pass; protocol-relative `//…`, `javascript:`,
  `data:`, and every other scheme fail. (The save/render sanitizer stays
  the authority; the bridge check is UX honesty — a link that would be
  stripped never even appears.)
- **Sanitizer shape is the contract:** `TipTapHtmlSanitizer` allows
  `strong`, `em`, `s`, `u`, `a[href]` (http/https/mailto/relative, with a
  protocol-relative blocker) — NOT `b`, NOT `i`, and disallowed elements
  drop with children. All bar output must land allowlist-shaped.

## 1. The bar (DOM placement, static CSS)

- On a `rich` grant (`startEditing` with `kind === 'rich'`), the bridge
  inserts a zero-height anchor sibling immediately BEFORE the region:
  `<span class="lemma-canvas-shim lemma-canvas-format-anchor">` containing
  `<div class="lemma-canvas-format-bar">` with four buttons
  (`data-format="bold" | "italic" | "link" | "unlink"`).
- Positioning is static CSS in `preview.css` (anchor `position: relative`;
  bar absolute above it) — no style attributes, no injected `<style>`
  (CSP pin).
- The bar lives OUTSIDE the region by construction, so `region.innerHTML`
  commits can never contain it.
- Lifecycle: removed in `endEditing`; `.lemma-canvas-format-anchor` joins
  `stripCanvasState` (both the class strip and element removal alongside
  the shim rule) so mirrors/clones never carry it.
- `string`/`text` kinds never get a bar.

## 2. Marks + normalization

- Buttons dispatch `document.execCommand('bold' | 'italic' | 'createLink'
  | 'unlink')` on click.
- `normalizeRichRegion(root)` — the ONE normalization authority:
  - every `b` → `strong`, every `i` → `em` (children MOVED, not cloned, so
    live selections anchored in text nodes survive);
  - every `span[style]` unwrapped (children moved out, span dropped);
  - operates only on the node it is given (review pin: the active region
    or its detached clone — never the document).
- Called in two places:
  - **after each bar action** on `editing.region` (live DOM stays
    allowlist-shaped; selection survives node moves);
  - **at commit** (`commitEditing`, rich kind only) on a DETACHED CLONE of
    the region — parse-free (`region.cloneNode(true)`), normalize the
    clone, post the clone's `innerHTML`. No live-DOM caret risk, and it
    catches HTML the bar never produced: native Cmd+B/Cmd+I output and
    rich paste.

## 3. Focus and session survival

During an edit session, any mousedown outside the region blurs it →
`onEditBlur` commits-and-ends; the capture click handler also ends the
session on outside clicks. Therefore:

- The bar `preventDefault`s BOTH its `pointerdown` AND its `mousedown`
  (review pin): on modern browsers focus changes are pointer-driven first —
  cancelling only `mousedown` (or only handling `click`) can let the region
  blur before the format action runs. With both cancelled, focus and the
  text selection never leave the region.
- The capture click handler's editing branch gets ONE exemption: a click
  inside `.lemma-canvas-format-bar` dispatches the format action
  (preventDefault + stopPropagation) and returns — it never
  commits-and-exits.
- Link capture uses `window.prompt` in v1 (no focus change, no new UI).
  Empty/cancelled/invalid input → no-op (review pin, §0).

## 4. Data flow (deterministic, not browser-event-dependent)

Every successful bar action calls the debounce scheduler (`onEditInput()`)
DIRECTLY after normalization (review P1): relying on `execCommand` to fire
`input` is not a safe contract — if an engine (or the stubbed test path)
doesn't emit it, the live DOM changes but the parent tree never receives
`text-changed`, and auto-apply/save miss the format change until
blur/flush. Calling the scheduler explicitly makes the flow deterministic;
when the browser DOES also fire `input`, the two calls coalesce into the
same 400ms debounce — no double commit.

From there the chain is unchanged: debounced `commitEditing` →
`text-changed {id, field, html}` → parent kind-matrix re-validation →
`patchBlockDataById` → auto-apply. Sanitize-at-save and
`safe_html`-at-render stay the authorities. "Successful" means the action
ran — for `createLink`, a validation no-op (§0 pin) schedules nothing.

## 5. Testing (bridge direct suite; jsdom has no execCommand — stub it)

- Rich grant inserts the bar (anchor sibling before the region); string
  and text grants do NOT.
- Bar never appears in committed HTML: commit after granting → posted
  `html` contains no `lemma-canvas-format` markup.
- Bold/italic clicks invoke the execCommand stub and normalize the region
  (`<b>`→`<strong>`, `<i>`→`<em>`, `span[style]` unwrapped).
- Bar `pointerdown` AND `mousedown` are BOTH `defaultPrevented` (review
  pin — cover the actual events the implementation cancels, not just
  click); a bar click does NOT end the session (no `edit-end` post) and
  does not commit-and-exit.
- Deterministic commit (review P1): a bar click — with NO synthetic
  `input` event dispatched — eventually posts `lemma:text-changed` with
  the normalized HTML (fake timers advance the 400ms debounce).
- **Native-shortcut regression (review pin):** set the region's HTML to
  `<p><b>Bold</b> and <i>Italic</i></p>` directly — no bar interaction —
  and commit; the posted `html` must be
  `<p><strong>Bold</strong> and <em>Italic</em></p>`. This proves the
  commit-time pass fixes the latent v3 issue, not only bar-generated
  markup.
- Normalization scope (review pin): theme `<b>`/`<i>`/`span[style]`
  OUTSIDE the region (elsewhere in the wrapper/document) are untouched by
  bar actions and by commit.
- `createLink`: valid URL → execCommand called with it; empty prompt,
  cancel (null), `javascript:…`, `data:…`, and `//evil.test` → execCommand
  NEVER called (review pin); relative `/path`, `#anchor`, `http(s)`,
  `mailto:` pass.
- `endEditing` removes the bar; `stripCanvasState` strips it from
  duplicate clones.

## 6. Out of scope (recorded follow-ups)

- Selection-following bubble polish (needs a CSSOM positioning mechanism).
- Active-state indication (lit B when the caret sits in bold text).
- Inline link input in the bar (needs relatedTarget-aware blur surgery).
- Additional marks (`s`, `u` are already sanitizer-allowed if wanted).
