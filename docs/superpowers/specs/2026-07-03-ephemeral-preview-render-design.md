# Ephemeral Preview Render (Loop C) — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Builds on:** `2026-07-03-visual-canvas-design.md` (v1), `2026-07-03-canvas-stage-toolbar-design.md` (v2)

## 0. Summary

The visual canvas gains an **Apply** action: it posts the current working block
tree to a permission-gated endpoint, the server validates it with the full
pre-save guard set, stashes the **cleaned** fields in cache keyed by
`{entry, locale}`, and the stage iframe reloads its existing
`/_preview/{token}` URL — the resolver overlays the stashed working copy over
the DB draft before the shared shaping, so reference expansion, template pick,
annotation, bridge injection, and the session cookie all run unchanged.
Nothing is persisted; the render pack needs **zero changes**.

Decision pins from brainstorm review:

- **Loop UX:** two actions — Apply (ephemeral render, instant) and Save draft
  (persists via `saveDraft` as today, no stage reload on success). The v2
  mirrors keep covering the between-applies gap; the save-failure stage-reset
  rule stays.
- **Transport:** cache stash + same-URL iframe reload. No fresh token per
  apply; no `srcdoc` (which would break the bridge's origin pinning).
- **Validation:** the full `saveDraft` guard set, byte-mirrored — see §2.
- **Gate parity (review pin):** Apply runs `BlockMigrationGate` exactly like
  `saveDraft`. Apply is presented as "what would be saveable"; rendering a
  tree the editor cannot actually save would hide exactly the drift the gate
  exists to prevent. Active/failed migration touching the payload → 409
  `BLOCK_MIGRATION_IN_PROGRESS`, **no stash written**, canvas surfaces it
  exactly like Save draft.
- **Cleaned fields only (review pin):** the stash stores the validator's
  normalized output, never the raw submitted payload. The overlay never
  renders unsanitized client input.
- **Clear-on-save (review pin):** a successful `saveDraft` clears the stash
  for its `{entry, locale}` — the DB draft now matches the working tree, and a
  stale stash must not shadow later manual refreshes.
- **Stash keying (derived from clear-on-save):** the stash is keyed by
  `{entry_uuid, locale}`, not by token — `saveDraft` never sees the preview
  token, so save-side clearing requires entry-addressable keys. One working
  copy per entry+locale; concurrent canvases share it last-writer-wins, the
  same collision semantics the draft itself has. The write door still
  requires a verified token naming that exact entry+locale (§2), so keying
  does not widen the trust model.

## 1. Loop semantics

| Action | What it does | Persistence | Stage |
| --- | --- | --- | --- |
| **Apply** | validate + stash cleaned working tree | none (cache, TTL) | reloads same `/_preview/{token}` URL |
| **Save draft** | `saveDraft` (lock_version, 409 branches) + clear stash | DB draft | no reload — the stage already shows the working tree |

Add-after's new block becomes visible on the next Apply (the v2 "no mirror for
add-after" pin is unchanged — the copy just points at Apply now). The dirty
chip keeps meaning "differs from the saved draft"; the Apply button carries its
own indicator when the tree differs from the last-applied state.

## 2. Apply endpoint (app-side)

`POST /v1/admin/entries/{uuid}/preview/{locale}/apply`
Body: `{ token: string, fields: object }`. Route middleware:
`lemma_permission:content.edit` — Apply reveals **unsaved** edits through the
preview token, so it takes the editor's permission, not the viewer's.

Server steps, fail-closed, in order:

1. **Verify the token** (same HMAC verification as the preview reader):
   expired → 410, malformed/invalid → 403. Generic messages, exactly like
   `PreviewController::show()`.
2. **Bind the token to the route**: the token's `{entry, locale}` must equal
   the route params — mismatch → 403. A token can never be pointed at another
   entry. **Version-pinned tokens (hard requirement, review pin):**
   `version_uuid` present → 409 with error code `PREVIEW_VERSION_PINNED`, no
   stash written. 409 (not 422) because the token itself is valid — the
   operation conflicts with immutable-version semantics, exactly the conflict
   class 409 names.
3. **Locale check** mirrors mint: unknown locale → 422.
4. **Payload cap**: JSON-encoded `fields` above 1 MB → 413. (The stash is a
   cache row; the cap keeps a hostile or runaway payload from becoming a
   cache-pressure primitive.)
5. **BlockMigrationGate** (gate parity pin): the same `assertWritable` call
   `saveDraft` makes, with the same payload inspection — active/failed
   migration touching a block type in the payload → 409
   `BLOCK_MIGRATION_IN_PROGRESS` with the same error `details` shape. No
   stash write on 409.
6. **Full FieldValidator**: identical rule set and 422 error shape as
   `saveDraft` — schema rules, nesting depth, entry-wide block-id uniqueness
   (the annotation pipeline depends on unique ids). The validator's
   **cleaned/normalized output** is what proceeds; the raw payload is
   discarded.
7. **Stash**: write the cleaned fields via `PreviewWorkingCopyStore` (§3),
   TTL = `min(remaining token TTL, 300s)`. Overwrites any prior working copy.
   → 200 `{ applied_at }`.

## 3. `PreviewWorkingCopyStore` + resolver overlay

A small app-owned service (`App\Content\Preview\PreviewWorkingCopyStore`),
framework-cache-backed:

- `put(string $entryUuid, string $locale, array $cleanFields, int $ttl): void`
- `get(string $entryUuid, string $locale): ?array`
- `clear(string $entryUuid, string $locale): void`

Key shape: `lemma:preview:working:{entryUuid}:{locale}`. Values are the
cleaned fields only (no metadata beyond what the cache layer stores). Not
single-use: a manual iframe reload shows the same working copy until the next
Apply, a successful Save (clear), or TTL expiry — after which the render falls
back to the DB draft.

**Overlay point:** `EnginePublicRouteResolver::resolvePreview()` — after
`PreviewReader::read($token)` returns a **draft-mode** result
(`version_uuid === null`), replace `$read['fields']` with the stashed working
copy when one exists for `{entry_uuid, locale}`. Then the existing
`previewContent()` shaping runs unchanged: published-spine reference
expansion, type/template resolution, annotation, bridge injection, cookie.
**Pinned-version reads are never overlaid (hard requirement, review pin):**
a `version_uuid`-carrying token renders the pinned version only — the
overlay branch is structurally unreachable for it (gated on
`version_uuid === null`, the same discriminator the reader branches on).
Scope pin: the overlay applies only
to the direct `/_preview/{token}` stage render; cookie-session navigation to
other pages keeps today's draft semantics.

**Clear-on-save:** `EntryController::saveDraft`, after a successful persist,
calls `PreviewWorkingCopyStore::clear($uuid, $locale)`. Failure paths (409,
422) leave the stash untouched.

The render pack is untouched: mint, apply, stash, and resolve all live
app-side (`EnginePublicRouteResolver` is app code; the pack keeps consuming
its output).

## 4. Canvas changes

- **Apply button** (primary, replaces "Save & refresh"): POSTs
  `{token, fields}` → on 200, `reloadStage()` (same-URL reload — no re-mint);
  on 422, surface validation errors through the existing notify pattern; on
  409, byte-mirror the editor's `BLOCK_MIGRATION_IN_PROGRESS` banner; on
  410/403 (token expired/invalid mid-session), re-mint once via
  `mintAndLoad()` and retry the apply with the fresh token — a second failure
  surfaces the error. **Apply-failure reset (review pin):** any final Apply
  failure reloads the current iframe URL before surfacing the error — the
  server wrote no stash, so optimistic mirror DOM from the stage toolbar must
  not keep masquerading as applied; local dirty fields are kept (same rule as
  the v2 save-failure reset).
- **Save draft button**: `saveDraft` with today's 409 handling; on success no
  stage reload (the stage already shows the applied tree). The save-failure
  stage-reset rule (v2) still reloads the stage on failure to discard
  mirror-only DOM.
- **Staleness signals**: `dirty` (fields ≠ saved draft) keeps driving the
  chip; new `applied` tracking (fields ≠ last-applied) drives an indicator on
  the Apply button.
- The current token must be available to Apply: `mintAndLoad()` keeps the
  minted token alongside `theme_url`.

## 5. Security posture

Unchanged trust model. The token remains the only read capability and could
already see the whole draft; the stash adds only *unsaved* content, written
through an authenticated, `content.edit`-gated, token-bound door. The stash
is cache-only (never touches the DB), TTL-bounded, size-capped, cleared on
save, and readable only through a verified token render. Preview responses
stay `no-store` / `noindex`. Version-pinned tokens can neither write (409
`PREVIEW_VERSION_PINNED`) nor read a working copy (overlay gated on
draft-mode) — both sides are hard requirements.

## 6. Testing

**PHP integration** (`tests/Integration/Content/PreviewApplyTest.php` + render
overlay additions):

- Apply endpoint: 401 unauthenticated; 403 wrong-entry token; 403 invalid
  token; 410 expired token; 409 `PREVIEW_VERSION_PINNED` for a version-pinned
  token (no stash written); 422 unknown locale;
  422 validation mirror (schema rule + duplicate block ids across fields);
  409 during an active block migration (no stash written — assert the store
  is empty after); 413 over-cap payload; 200 happy path (stash holds the
  CLEANED fields — e.g. a trimmed/normalized value, not the raw one).
- Overlay render: after Apply, `GET /_preview/{token}` shows working-copy
  content INCLUDING a block that exists only in the working copy, with its
  `data-lemma-block` annotation; reference expansion runs on the working
  tree; after `saveDraft`, the stash is cleared and the render shows the
  (now-identical) draft; after TTL expiry, render falls back to the draft;
  a version-pinned token's render is never overlaid.

**SPA (vitest)**: Apply wiring (POST body carries token + fields; 200 →
same-URL reload, no mint call); 422 error surfacing; 409 migration banner
byte-mirror; 410 → single re-mint-and-retry; Save success does NOT reload the
stage and posts no mint; applied-state indicator toggles on tree change and
clears on Apply.

**Manual/browser acceptance (recorded):** perceived apply latency on a real
theme, add-after visible pre-save, two-tab collision behavior (shared stash
last-writer-wins), stash expiry mid-session.

## 7. Out of scope (recorded follow-ups)

- Auto-apply (debounced live preview) — the endpoint is designed to tolerate
  it later (idempotent overwrite), but the UX stays explicit-apply.
- Working-copy overlay on cookie-session navigation (other pages).
- Edit-in-place text, free drag in the stage (separate cycles).
- Ephemeral render for the FORM editor's preview tab (canvas-only for now).
