# Session-Wide Working-Copy Overlay — Design

**Date:** 2026-07-04
**Status:** Approved design, pending implementation
**Builds on:** loop C (`PreviewWorkingCopyStore`, token-path overlay),
preview v2 (session single-draft overlay at canonical URLs).

## 0. Summary

Today the Apply/auto-apply stash (the editor's validated, unsaved block
tree) is honored only by the direct `/_preview/{token}` stage render.
Navigating the same session to the entry's canonical URL overlays the
SAVED DRAFT — so the canvas stage and in-session navigation disagree.
This cycle makes an existing stash win over the draft wherever the
session overlays the draft, and closes an adjacent pre-existing gap: the
homepage never overlaid the session draft at all.

This is a focused fix, not a preview-session semantics expansion: the
single-draft scope, the published-only posture of listings/archives/
terms, the version-pin 409 posture, and the stash's lifecycle
(cache-only, TTL-bounded, keyed by entry+locale, cleared by saveDraft,
dies with the token) are all unchanged.

Decision pins:

- **The helper keys off the verified read result itself (review pin):**
  `overlayWorkingCopy(array $read)` decides which stash can apply from
  `$read['entry_uuid']` + `$read['locale']` — the reader's own output,
  never a route parameter or a separately-copied session payload. The
  read result is the thing being shaped, so it is the thing that decides.
  This keeps the helper honest across all three paths (direct preview,
  canonical path, homepage).
- **Draft-mode only:** the overlay applies IFF `$read['version_uuid'] ===
  null` — the existing hard pin; version-pinned tokens AND version-pinned
  sessions render their immutable version, never the working copy.
- **Homepage joins the session (scope decision):** `resolveEntry()` learns
  the optional `?PreviewSession` the same way `resolvePath()` did. This
  fixes BOTH the missing draft overlay and the missing working-copy
  overlay at `/` in one move.

## 1. One overlay authority (`EnginePublicRouteResolver`)

- New private helper:

  ```php
  /**
   * Loop C overlay, session-wide (this spec): an existing working copy
   * wins over the draft for DRAFT-MODE reads only. Keyed off the read
   * result's OWN entry/locale (pin) — never route/session copies.
   *
   * @param array{entry_uuid:string,locale:string,version_uuid:?string,...} $read
   */
  private function overlayWorkingCopy(array $read): array
  {
      if ($read['version_uuid'] !== null || $this->workingCopies === null) {
          return $read;
      }
      $working = $this->workingCopies->get($read['entry_uuid'], $read['locale']);
      if ($working !== null) {
          $read['fields'] = $working;
      }
      return $read;
  }
  ```

- `resolvePreview()` replaces its inline overlay block with
  `$read = $this->overlayWorkingCopy($read);`.
- `resolvePath()`'s single-draft session branch becomes
  `previewContent($this->overlayWorkingCopy($this->preview->readVerified($previewSession)))`,
  keeping the existing `PreviewNotFoundException` fall-through to the
  published render.

## 2. Homepage joins the session

- **Additive signature (review P1):** the existing contract is
  `resolveEntry(string $entryUuid, ?string $locale = null)`
  (`packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`) — the
  session parameter is APPENDED, never substituted:
  `resolveEntry(string $entryUuid, ?string $locale = null, ?PreviewSession
  $previewSession = null)`. Contract interface and implementation change
  together; existing callers/tests are untouched (optional trailing
  param).
- Behavior: when the session is present and `$previewSession->entry ===
  $entryUuid` and `$previewSession->locale` matches the resolved locale,
  return
  `previewContent($this->overlayWorkingCopy($this->preview->readVerified($previewSession)))`
  with the same `PreviewNotFoundException` fall-through. A session for a
  DIFFERENT entry leaves the homepage fully published (single-draft
  scope).
- `RenderController::home()` passes its already-verified session with the
  locale slot pinned to `null`:
  `$this->resolver->resolveEntry($homepageEntry, null, $session)`.
  Today's call passes NO locale (the controller's `$locale` variable is
  the config default used only as a no-entry template fallback) —
  forwarding that default instead of `null` would change resolution for
  homepage entries routed in a non-default locale.
- Locale note: the homepage resolves the entry's own routed locale; the
  session match uses the locale of the resolved read, consistent with the
  §0 pin (the read decides).

## 3. Safety carried by existing machinery

- Session renders bypass the page cache wholesale
  (`RenderPageCache` checks the session attribute first) — overlaid HTML
  is never cached. Existing behavior, now load-bearing: gets a regression
  assertion.
- Listings/archives/terms never enter the entry-content branch — their
  published-only posture holds by construction.
- Live (non-session) renders pass `null` sessions everywhere and are
  untouched.

## 4. Load-bearing tests (review list)

Extend `tests/Integration/Render/PreviewSessionTest.php` (and keep
`PreviewWorkingCopyTest` green through the refactor):

1. Canonical-URL session render uses the WORKING COPY when a stash exists
   and `version_uuid === null` (and the draft when no stash exists).
2. Canonical-URL render in a VERSION-PINNED session ignores an existing
   stash (renders the pinned version).
3. Homepage in a session whose entry IS the homepage entry renders the
   draft, and the working copy when stashed.
4. Homepage in a session for ANOTHER entry stays fully published.
5. A valid session render with a stash reads/writes NO `render:*`
   page-cache keys (the bypass regression assertion).

Plus: a different entry's canonical URL inside the session stays
published even when that other entry has its own stash (single-draft
scope over the store's entry-keyed reach).

## 5. Out of scope (recorded)

- Listing/archive/term pages reflecting working-copy values (published-
  only posture is deliberate).
- Multi-entry sessions.
- Partial DOM patching (its own cycle, next).
