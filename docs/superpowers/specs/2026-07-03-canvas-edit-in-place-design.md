# Canvas v3: Edit-in-Place Text — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Builds on:** visual canvas v1 (select), v2 (stage toolbar/mirrors), loop C (Apply)

## 0. Summary

Double-clicking a prose block in the canvas stage turns its rendered
rich-field region into a bare `contenteditable`; typing flows to the parent on
a debounce and patches the canonical tree (`patchDataById`), so the inspector
updates live and the existing dirty/stageStale/Apply/Save machinery works
untouched. The server sees text only at Apply/Save, where the existing
sanitizer chain already runs (`TipTapHtmlSanitizer` in `FieldValidator`;
`safe_html` re-sanitizes at render — double fail-closed, so browser-generated
HTML can never persist or render unsanitized). Single-click select, toolbar,
mirrors: all v2 semantics unchanged.

Decision pins from brainstorm review:

- **Scope:** prose blocks only (the exactly-one-rich-text convention). Plain
  string fields stay inspector-edited; a template can use them in attributes,
  truncate, or filter them, so DOM↔field mapping is not generally safe. The
  future path for string fields is an opt-in `|editable` filter (recorded).
- **Commit model:** debounced tree-commit (~400ms + blur/Escape), explicit
  Apply. Typing costs zero requests; sanitization stays where it lives today.
- **Formatting:** bare contenteditable + native shortcuts (Cmd/Ctrl+B/I). No
  in-stage toolbar — the inspector's `ProseBlockEditor` is the full editor.
- **Activation:** double-click to edit; Escape or clicking outside
  commits-and-exits. Single click keeps v2 semantics exactly.
- **Marking is prose-gated at the RENDERER (review pin):** `safe_html` wraps
  its output only when the current block is prose — never inert markers in
  non-prose blocks. See §2; this keeps the one-region rule meaningful and
  multi-`safe_html` non-prose templates out of the picture entirely.

## 1. Prose convention (shared, both sides)

A block type is prose when its schema is **exactly one field** of
`type: 'text', format: 'rich'`; that field's name is the editable rich field.
This mirrors `admin/src/fields/components/blocks/proseDetection.ts`
byte-for-byte (`proseRichFieldName`). Per that file's pin, the convention is
NOT a stable identity contract — when `editor_mode` metadata lands, both
sides consult it first. The server-side mirror lives behind the contract in
§2 so the rule has exactly one implementation per side.

## 2. Renderer marking (the field↔DOM seam)

**New soft-bound contract** (same pattern as `RichHtmlSanitizer`):
`packages/lemma-contracts/src/Content/BlockEditableFieldResolver.php` —
`Glueful\Lemma\Contracts\Content\BlockEditableFieldResolver` with
`editableRichField(string $typeSlug): ?string`. The app implements it over
`BlockTypeRepository` schemas using the §1 convention; the render pack takes
it as an optional constructor dependency (null → no marking, ever — the
render pack stays removable).

**`RenderContextExtension`:** `blocks()` pushes a frame onto a request-local
**stack** around each `$env->render()` (stack, not scalar — nested `blocks()`
calls run inside parent templates; cleared per render by the reset family,
like `blockDepth`):

```php
['id' => $item['id'], 'editable_field' => $this->editableFields?->editableRichField($type)]
```

`safe_html` wraps its output **only when ALL hold**: annotations on (the same
`$annotateBlocks` gate as wrappers — live renders carry nothing), a current
frame exists, the frame's `id` is a string, AND `editable_field` is non-null
(the block is prose):

```html
<div class="lemma-edit-region"
     data-lemma-edit-block="{id}"
     data-lemma-edit-field="{field}">…sanitized html…</div>
```

Non-prose blocks that use `safe_html` (a hero with a rich body, multiple
sanitized snippets in one template) produce **no markers at all** — no
misleading markup, and the §3 one-region rule can only ever be evaluated on
actual prose blocks. The resolver is called once per rendered block instance;
the app implementation memoizes per request via the repository's existing
schema memo.

## 3. Bridge editing session (edit-grant protocol)

The bridge stays dumb, same as v2's delete-request:

- **Enter:** double-click inside a wrapper → `lemma:edit-request {id}`. The
  PARENT validates (block exists in the tree; type is prose by the client
  convention) and replies `lemma:edit-grant {id, field}`. The bridge then
  requires **exactly one** `.lemma-edit-region` inside that wrapper AND
  sanity-checks the region's `data-lemma-edit-field` equals the grant's
  `field` (mismatch → no editing, fail-safe). It sets `contenteditable`,
  focuses the caret at the double-click point, detaches the v2 toolbar for
  the duration, and swaps the ring to an editing style (static CSS class —
  CSP pin holds, no inline styles).
- **While editing:** `input` → 400ms debounce →
  `lemma:text-changed {id, field, html}` (the region's `innerHTML`). Blur and
  Escape commit immediately and post `lemma:edit-end`. The capture-phase
  click handler gets one carve-out: clicks INSIDE the active editing region
  pass through (caret placement); clicks outside commit-and-exit, then behave
  as v2 (select/toolbar/inert links).
- **Flush (load-bearing):** before Apply, the parent posts
  `lemma:edit-flush`. The bridge commits any active session (final
  `text-changed` + `edit-end`) and then ALWAYS posts `lemma:edit-flushed` —
  with or without an active session — so the parent has a deterministic ack
  to await. The last sub-debounce keystrokes are never lost.
- **Clones:** `stripCanvasState` additionally removes `contenteditable` and
  the editing class from mirror-duplicate clones, and `mirrorDuplicate`
  rewrites `data-lemma-edit-block` through the same idMap as the wrapper ids —
  a duplicated prose block is immediately editable under its NEW id (review
  pin; without the rewrite, its region would point at the source id until the
  next Apply).
- All messages ride the v1 envelope (nonce-echoed, origin-checked).

## 4. SPA side

- `BlocksField` exposes `patchBlockData(id: string, field: string, value: unknown): boolean`
  (wraps `ops.patchDataById` through `apply`; `false` when the id is unknown).
  `FieldEditor` routes it as `patchBlockDataById`, same pattern as the rest.
- Canvas page wiring: `edit-request` → look up the block's type in the tree,
  check the prose convention via the block-types query (`proseRichFieldName`)
  → `edit-grant` with the field (or nothing — an ungranted request simply
  never edits); `text-changed` → **re-validated** (review pin: iframe scripts
  can see the nonce after hello, so edit messages are requests, not
  authority) — the claimed field must equal the block's prose rich field,
  computed by the same helper the grant uses; anything else is ignored — then
  `patchBlockDataById(id, field, html)`.
  Reactivity keeps the inspector's `ProseBlockEditor` current. No mirrors are
  involved — the contenteditable region IS the stage DOM.
- `applyWorking()` posts `edit-flush` and awaits the bridge's
  `lemma:edit-flushed` ack (200ms timeout fallback — a stage with no bridge,
  e.g. mid-reload, must not wedge Apply) before reading `fields.value`. The
  ack is deterministic because the bridge replies unconditionally (§3);
  message ordering guarantees any final `text-changed` arrives before it.
- Grant validation is the parent's job; the bridge's field sanity-check (§3)
  is defense in depth, not the gate.

## 5. Security posture

Unchanged. The editable region only exists in annotated (preview-session)
renders; the marker is added AFTER sanitization by the same filter that
guards output today, and the typed HTML re-enters the system only through
the tree — sanitized at Apply/Save by `FieldValidator` and re-sanitized at
render by `safe_html`. The bridge accepts no new inbound commands that touch
the DOM beyond enabling `contenteditable` on a server-marked region for a
parent-granted block.

## 6. Testing

- **PHP:** extension/integration tests — the edit region appears in annotated
  preview renders for prose blocks (with both data attributes), never for
  non-prose blocks using `safe_html`, never in live renders; stack
  correctness for a prose block nested inside a container block; resolver
  contract mirrors the §1 convention (exactly-one-rich-field schemas, and
  nothing else).
- **Bridge direct suite (jsdom eval):** edit-request on double-click;
  grant → contenteditable + one-region + field sanity rules; text-changed on
  debounce/blur/Escape; the click carve-out vs v2 inertness; flush protocol;
  clone stripping of `contenteditable`.
- **Vitest:** `patchBlockData` + routing; canvas-page grant logic
  (prose-only, unknown ids ignored); text-changed → tree patch → inspector
  value; edit-flush ordering before Apply.
- **Manual acceptance (recorded):** caret placement on real themes, IME
  input, native mark shortcuts surviving the sanitizer round-trip,
  double-click-vs-select feel.

## 7. Out of scope (recorded follow-ups)

- In-stage formatting toolbar (selection bubble).
- Plain string fields via an opt-in `|editable` Twig filter.
- Multi-region blocks; `editor_mode` metadata escape hatch.
- Collaborative cursors / multi-editor presence.
