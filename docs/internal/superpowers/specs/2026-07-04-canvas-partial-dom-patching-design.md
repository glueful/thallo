# Canvas v10: Partial DOM Patching — Design

**Date:** 2026-07-04
**Status:** Approved design, pending implementation
**Builds on:** loop C/v5 apply loop (`runApply`, `reloadStage`), the
annotated `data-lemma-block` carriers, the session-wide working-copy
overlay (the stash is behind the same token URL the iframe shows).

## 0. Summary

Today every successful Apply/auto-apply full-reloads the iframe — a white
flash, image refetch, and theme-JS restart on every 800ms keystroke batch.
v10 replaces the SUCCESS-path reload with a bridge-side patch: fetch a
REAL render of the working copy from the stage's own URL, prove the page
shell and top-level block skeleton identical, and swap only the wrappers
whose HTML changed. Anything unprovable answers `reload` and the parent
falls back to today's path — the honest-stage rule rests on a string
comparison, never on a diff algorithm.

Decision pins (design review):

- **Wrapper-boundary patch** (chosen over whole-body morph and server
  fragments): block wrappers are the patch contract; typing and
  content/style changes patch; structural uncertainty reloads.
- **Nonce + refresh id (review pin):** `stage-refresh` and
  `stage-refreshed` are nonce-enveloped like every bridge message, and
  each refresh carries a `refresh_id` echoed on the ack — a slow
  fetch/timeout can never resolve a LATER refresh's promise.
- **"Top-level wrapper" defined precisely (review pin):** an element with
  `data-lemma-block` and NO ancestor carrying `data-lemma-block`. The
  ordered id list is built from those only; ids must be unique on BOTH
  sides; duplicates, missing ids, or order drift → `reload`.
- **Fetch failure rules (review pin):** non-2xx, a redirected response, a
  response that fails to parse, a missing `<body>`, or a body with NO
  bridge annotations (zero `data-lemma-block` wrappers where the live DOM
  has some) all answer `reload` with the DOM untouched. Patching happens
  ONLY after the full gate passes.
- Failure paths (rejected apply, save failure, rejected drop) keep the
  DIRECT `reloadStage()` — unchanged semantics. Version-pinned sessions
  never apply; nothing changes for them. No server changes at all.

## 1. Protocol

- Inbound (parent → bridge): `lemma:stage-refresh { refresh_id }`.
- Outbound (bridge → parent): `lemma:stage-refreshed { refresh_id, mode }`
  with `mode: 'patched' | 'reload' | 'busy'`.
- Parent success path: post `stage-refresh`, await the MATCHING ack
  (refresh_id) with a ~4s timeout (a fetch rides inside it):
  - `patched` → done; no reload; scroll untouched.
  - `reload` or timeout (mid-reload stage, stale cached bridge) →
    `reloadStage()`.
  - `busy` → no-op (see §3).

## 2. The bridge's patch pass

On `stage-refresh`:

1. **Busy gate:** `editing` or `drag` active → ack `busy`, touch nothing.
2. **Fetch:** `fetch(window.location.href)` — same-origin, session cookie
   rides along; the stash is behind the same token URL, so this is a real
   render of the working copy (never a client-side guess). Apply the §0
   fetch-failure rules; any violation → ack `reload`, DOM untouched.
3. **Clean the live side:** clone `document.body`, run `stripCanvasState`
   on the clone, and remove body-mounted bridge UI (format bubble, drag
   ghost) from it. The fetched body needs no cleaning (annotations only).
4. **Skeleton gate:** for each body, list top-level wrappers (§0
   definition) in document order. Reload on: differing id sequences,
   duplicate ids on either side, or zero wrappers in the fetched body
   while the live body has some. Then build the SHELL SKELETON — the body
   serialization with each top-level wrapper's CONTENT emptied (the
   wrapper element itself, with its attributes, stays in place). Skeletons
   differ → ack `reload`.
   **Mirrored structural ops patch (review P2):** the gate compares the
   fetched render against the LIVE stage DOM — which already carries
   optimistic mirrors for move, drag, delete, and duplicate. When those
   mirrors did their job, the live id sequence MATCHES the fetched one and
   the patch proceeds (and any wrapper whose optimistic clone differs from
   the real render gets swapped to truth). Only UNMIRRORED structural
   drift reloads — add-after (no mirror by design), or a server render
   whose id list/order differs from the live stage.
5. **Patch:** skeletons equal → for each top-level id, compare the CLEANED
   live wrapper's `outerHTML` to the fetched wrapper's; where they differ,
   swap the fetched wrapper in wholesale (nested blocks ride along; the
   LIVE wrapper — not the cleaned clone — is what gets replaced). Ack
   `patched`.
6. **Canvas-state restore:** if `selectedId` lives inside a swapped
   wrapper, re-run the selection (`selectWrapper(findBlock(selectedId))`)
   so ring/toolbar re-anchor; if the selected block no longer resolves,
   clear selection AND post the existing `block-deselect` notification so
   the parent stays honest. Scroll is never touched; no hello, no nonce
   change — the session never restarts.

## 3. Busy semantics

An active edit session or drag answers `busy`; the parent does NOT reload.
Typing in the region IS the working copy's content, and the existing
edit-end re-arm (`stageStale` → `scheduleAuto`) re-applies anything the
stage missed. This is the same suppression philosophy auto-apply already
uses — the patch never fights the user's hands.

## 4. Parent-side wiring

- `useCanvasBridge` gains
  `stageRefresh(): Promise<'patched' | 'reload' | 'busy'>` — generates the
  `refresh_id`, posts, resolves on the MATCHING ack or `'reload'` on
  timeout (~4s), mirroring `editFlush`'s ack-or-timeout shape but with id
  correlation (review pin).
- The page gains `refreshStage()`: `await bridge.stageRefresh()` →
  `'reload'` → `reloadStage()`; `'patched'`/`'busy'` → nothing.
- `runApply`'s success line changes from `reloadStage()` to
  `await refreshStage()`. Scheduler, suspension, coalescing, snapshot
  honesty: untouched. All failure-path `reloadStage()` calls: untouched.

## 5. Testing

- **Bridge direct suite** (stub `window.fetch` with controllable HTML):
  - One changed wrapper swaps; unchanged wrappers keep DOM IDENTITY
    (`toBe` on the element), shell untouched; ack `patched` with the
    echoed `refresh_id`.
  - Shell change (e.g. `<h1>` outside wrappers) → `reload`, DOM untouched.
  - UNMIRRORED structural drift → `reload`: an id present in only one
    side (add-after shape), an order mismatch between live and fetched,
    or duplicate ids on either side.
  - **Mirrored structural ops patch (review P2, positive case):** a live
    DOM whose mirrors already reordered/duplicated/removed wrappers so
    its top-level id sequence MATCHES the fetched render → `patched`,
    not `reload` — and a mirror-clone wrapper whose content differs from
    the real render is swapped to the rendered truth.
  - Live DOM with selection ring + toolbar + (stub) bubble patches
    cleanly: canvas UI never poisons the comparison; a swapped selected
    wrapper re-selects (toolbar present in the NEW wrapper); a vanished
    selected id clears selection and posts `block-deselect`.
  - `busy` during an edit session; `busy` during a drag; DOM untouched.
  - Fetch rejection, non-2xx, redirected, unparseable, bodyless, and
    annotation-less responses → `reload`, DOM untouched.
  - Nested wrappers count as part of their top-level parent (a child-only
    change swaps the parent wrapper exactly once).
  - A stale ack (wrong `refresh_id`) is ignored by the parent (composable
    test) — and the bridge echoes whatever id it was given.
- **Composable:** `stageRefresh` resolves `patched`/`reload`/`busy` on the
  matching ack; ignores acks with a different `refresh_id`; resolves
  `reload` on timeout.
- **Page (canvas-page.spec):** apply success posts `stage-refresh` and
  does NOT remount the iframe on `patched`; remounts on `reload` and on
  timeout; apply FAILURE still reloads directly; save failure still
  reloads directly.
- **PHP:** none — no server changes.

## 6. Out of scope (recorded)

- Structural patching for inserts/removals (list-op reconciliation
  against the skeleton).
- Morphing INSIDE wrappers (finer-grained than wrapper swap).
- Theme-JS state preservation across a swap (wholesale swap resets it;
  heavy-JS themes that mutate the shell get the reload fallback via
  skeleton drift, which is the honest outcome).
