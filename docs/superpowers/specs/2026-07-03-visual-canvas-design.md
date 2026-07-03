# Visual Canvas (v1) — Design

**Date:** 2026-07-03
**Status:** Approved (brainstorm 2026-07-03)
**Depends on:** preview sessions (b7284fe), page/block builder (2cd93bf), Notion-like
BlocksField UX (0eebbc9)

## Goal

Structured visual editing over Lemma's block model: editors see the REAL
theme-rendered page while composing, select rendered blocks to edit their
structured fields, and never touch HTML/CSS. The Lemma tree stays canonical;
Twig/theme rendering stays authoritative; templates stay the Templates screen's
job. This is Storyblok/Gutenberg-with-real-Twig-preview — explicitly NOT an
Elementor-style freeform builder (that would create a second content model outside
schema validation, sanitization, migrations, usage scanning, expansion, and the
template policy).

**Editing-loop decision (pinned): EXPLICIT APPLY.** The canvas owns a local fields
copy; "Save & refresh" writes it through the existing `saveDraft` endpoint (full
payload + `lock_version`), then re-mints the preview and reloads the stage. No
autosave (would silently mutate drafts against the editor's explicit-save
contract), no ephemeral render endpoint in v1 (recorded follow-up; the canvas
architecture doesn't change if it lands — only the refresh trigger).

## §1 Placement (pinned)

- Route: `/content/{type}/{uuid}/design/{locale}` — a FULL-SCREEN sibling of the
  entry editor. A canvas needs spatial ownership; living inside the editor page
  would couple every future canvas behavior to PublishPanel/versions/locales/
  workflow density.
- Entry editor gains a **Design** action; the canvas a **Form editor** back link.
- The canvas loads the draft independently (`getDraft` → local fields +
  `lock_version`) and saves through the same draft endpoint. The stale-lock 409 is
  the race boundary between the two pages. NO shared Pinia draft state in v1.
- Layout: top command bar, center iframe stage, left outline rail, right
  inspector.

## §2 Render-side annotation (render pack)

- `RenderContextExtension` gains a reset-family flag `setBlockAnnotations(bool)`
  (default OFF, controller-set before every render like `setAssetBase`): when on,
  `blocks()` wraps each rendered instance in

  ```html
  <div class="lemma-preview-block" data-lemma-block="{id}">…</div>
  ```

- **On in EVERY preview-session render, never in live renders.** A dedicated
  canvas flag would spread mode checks through render code; preview is already
  token-gated and no-store, so ordinary previews carrying inert annotations is
  acceptable.
- **"Preview-session render" is DEFINED as both entry points (P1 pin):** the
  DIRECT token entrypoint (`RenderController::preview()` serving
  `/_preview/{token}` — which does NOT pass through `PreviewSessionMiddleware`;
  see `public-routes.php:31` vs `:41`) AND cookie-backed session renders of `/`
  and `/{path}`. The canvas iframe's initial load IS the direct token URL
  (`theme_url` from the mint endpoint), so keying annotation/injection off the
  session-middleware request attribute alone would ship a bridge-less first
  load. Implementation keys off "this render is a preview" wherever the
  controller already knows it (both paths), not off the middleware.
- The wrapper takes `display: contents` from a STATIC stylesheet — a CSS class,
  not an inline `style` attribute and not an injected `<style>` element (both
  fight strict CSP).
- **Documented HTML-shape limit:** block templates that must be literal children
  of semantic containers (`ul > li`, `table > tr`, direct-child selectors across a
  blocks boundary) are incompatible with annotation. Lemma blocks are page/layout
  fragments, so no starter block is affected; the limit goes in the render-pack
  README.

## §3 Bridge delivery + protocol

- Two STATIC, cacheable, token-free render-pack routes: `/_preview.css` (wrapper
  rule + hover/persistent highlight ring styles) and `/_preview-bridge.js`.
- **OpenAPI posture (pinned):** both routes join the OpenAPI exclusion/deny-list
  exactly like `/_preview/{token}` and theme-asset routes — static HTML-support
  assets, not API surface.
- The render controller post-processes **preview-session HTML responses only**,
  inserting `<link rel="stylesheet" href="/_preview.css">` and
  `<script src="/_preview-bridge.js" defer></script>` before `</body>`; when
  `</body>` is absent, append at end-of-document — NEVER fail the render. Themes
  and templates stay untouched.
- **Protocol (nonce-correlated, cross-origin-safe):** everything is postMessage;
  the parent never reaches into the iframe DOM.
  - The bridge is SILENT until it receives `{type: 'lemma:canvas-hello', nonce}`;
    it stores `{origin, nonce}` from that event, echoes the nonce on every
    outbound message, and posts only to that origin. The parent ignores any
    message without its nonce (correlation token, not auth — it prevents stale
    frames/same-window noise from impersonating the active session).
  - bridge → parent: `blocks-index {ids}` (on activate + DOM settle),
    `block-hover {id}`, `block-select {id}` (from `closest('[data-lemma-block]')`).
  - parent → bridge: `highlight {id}` (persistent ring), `scroll-to {id}`.
  - While ACTIVE (post-hello), links/buttons inside annotated regions are inert
    (capture-phase preventDefault) — editing must not navigate the stage. A plain
    preview tab (no hello) behaves exactly as today.

## §4 Stage interactions (pinned scope)

| v1 supports | v1 does NOT support |
|---|---|
| hover ring | drag on the stage |
| click-select (persistent ring) | add/delete from the stage |
| scroll-to from outline/inspector | edit-in-place text |
| viewport presets | any HTML/CSS manipulation |

Structure edits happen in the inspector/outline through the SAME BlocksField
Notion UX (dividers, drag, keyboard, split). The later canvas-overlay work
(follow-up) consumes this same bridge and selection model.

## §5 Inspector + outline + selection

- The inspector renders the **FULL entry fields form** — the same field components
  the editor page uses, blocks fields included (the inspector literally rehosts
  `BlocksField`). Rationale: `saveDraft` writes the full payload anyway, and
  hiding non-blocks fields invites "where did title go".
- `BlocksField` exposes `selectBlock(id)` via `defineExpose` (the existing context
  function) so canvas selection can expand ancestors, scroll, and focus the block.
- Selection mapping: `block-select {id}` → the page finds the owning blocks field
  (tree search using the block-type region map) → that field's `selectBlock(id)`;
  the stage keeps its ring.
- **Entry-wide block-id uniqueness (P2 pin — a NEW stored-contract invariant):**
  the original block-builder contract only guaranteed id uniqueness WITHIN a list
  (`FieldValidator` rejects per-list duplicates), which makes an id-only bridge
  key ambiguous across multiple blocks fields. Pinned going forward: block ids
  are unique across the WHOLE entry. Enforcement: `FieldValidator` extends its
  duplicate check to span all blocks fields of the entry (cross-field duplicate
  → validation error, same style/copy family as the within-list rejection —
  a data-integrity guard for write-around-API cases; UI-generated nanoids never
  collide in practice). With the invariant, the bridge key stays the bare `id`.
  Test story: cross-field duplicate rejects at save; distinct nanoids across
  fields validate; the canvas mapping test seeds two blocks fields and proves
  unambiguous selection.
- The left outline rail spans ALL blocks fields of the entry and syncs BOTH
  directions: rail click → inspector (`selectBlock`) + stage (`highlight` +
  `scroll-to`); stage click → rail highlight + inspector.

## §6 Command bar + apply loop

- Viewport presets: Desktop (100%), Tablet (768px), Mobile (390px) — stage iframe
  width only.
- Dirty indicator; locale display; **Form editor** back link.
- **Save & refresh:** one `saveDraft(fields, lock_version)` → on success, RE-MINT
  the preview token (`mintPreviewData`) and reload the iframe with the fresh
  session URL. Re-minting per apply is pinned — better than stretching a
  10-minute token or mutating iframe state.
- 409 handling mirrors the form editor EXACTLY: stale-draft banner vs the
  `BLOCK_MIGRATION_IN_PROGRESS` banner (same `apiErrorCode` branch).
- Degenerate states (pinned posture, copy free):
  - **Rendered delivery disabled** (`theme_url: null` from mint): the route LOADS
    — never an SPA-side 404 — explains that rendered delivery is disabled, and
    links back to the form editor. No stage, no inspector requirement.
  - **Token expired mid-browse:** the iframe 404s; the command bar offers
    "Refresh preview" (re-mint WITHOUT saving).

## §7 Testing

**PHP (render pack + preview + validation):**
- entry-wide block-id uniqueness: cross-field duplicate rejects at save;
  distinct ids across fields validate (the §5 invariant)
- annotation wrapper present in preview-session renders, absent in live renders
  and in the delivery API
- post-processing adds exactly the `<link>` + `<script>` on preview HTML;
  appends gracefully when `</body>` is absent; non-HTML/non-preview responses
  untouched
- `/_preview.css` and `/_preview-bridge.js` serve statically with cache headers,
  token-free, and are OpenAPI-excluded

**SPA:**
- bridge-client composable: nonce/origin filtering (drops foreign-nonce and
  foreign-origin messages), hello handshake, message dispatch — jsdom's
  `window.postMessage` covers this
- selection mapping across MULTIPLE blocks fields; outline↔inspector↔stage sync
  with a mocked bridge
- apply flow with mocked `saveDraft`/mint: success (re-mint + iframe src change),
  stale 409 banner, migration 409 banner
- viewport presets change the stage width; disabled-delivery empty state;
  expired-token refresh affordance
- `BlocksField.selectBlock` exposure component test

**Manual/browser (recorded, same class as the split routine):** real iframe
hover/click/scroll behavior, link inertness, ring rendering.

## §8 Follow-ups (recorded, not abandoned)

1. Canvas-overlay drag handles / add buttons on the stage (consumes this bridge +
   selection model).
2. Ephemeral render endpoint (loop C) for true liveness without draft writes.
3. Per-block style presets (`editor_mode` metadata lands with it).
4. Edit-in-place text on the stage.

## §9 Out of scope

- Any HTML/CSS editing surface; template editing from the canvas (Templates
  screen owns templates).
- Changes to the stored model, migrations, delivery, or live-render output
  (annotation is strictly preview-scoped). ONE validation change is in scope:
  the §5 entry-wide block-id uniqueness guard.
- Shared draft state between editor and canvas pages.
