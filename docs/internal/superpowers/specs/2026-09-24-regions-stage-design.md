# Regions Stage — Design

**Status:** approved in brainstorming, 2026-09-24. Delivered as one release (one beta cut).
**Amended 2026-09-24 after review:** per-session preview copies (§4.2), one atomic save with a real
version contract (§4.5), exact revision-pair clearing (§4.5), one snapshot per render (§4.4), an
inert page body and an explicit stage marker (§4.4, §5.4), regions shown on pages that hide them
(§4.4), and the page switch and palette rules (§5.3). **Second amendment:** a pinned session
baseline (§4.2), saves serialized across both regions (§4.5), and page switch and renewal as an
explicit restore sequence (§5.3). **Third amendment:** both session records expire at the token's
absolute expiry, with no 300-second cap (§4.2, §4.3); every region writer is named and serialized
(§4.5).
**Extends:** `2026-07-04-global-regions-design.md` — this is its deferred "Canvas in-place region
editing" (§8, out of scope list) and the "real Regions stage" its 2026-09-19 amendment left open.
**Replaces:** the Regions page's form editor, its `POST /admin/regions/preview` endpoint,
`region-preview.twig` and the `blob:` preview frame.

## 1. Goal and scope

The Header & footer page (`admin/src/pages/regions/index.vue`) gets the Design view's interactive
stage: an editor clicks a header or footer block to select it, drags to move it, edits its text in
place, drags new blocks in, and undoes — the same stage, inspector and history as the Design view,
pointed at the site's chrome regions instead of an entry.

**Kept as they are:**

- Regions stay separate from pages. They have no draft, no versions and no locale, and a save goes
  live on every page. Nothing here changes the region model or its storage.
- The theme's fallback chrome for an empty region, outside the stage.
- The region palettes (`RegionDefinitions::PALETTES`) and settings vocabulary (`SETTINGS_KEYS`).

**Out of scope:** editing regions inside the Design view (option C in brainstorming: page drafts
and site-wide live edits stay on separate screens); block-by-block fragment updates for regions (the
stage re-renders whole, §4.6); per-locale regions; region drafts; anchor uniqueness across a page
and its regions.

## 2. Decisions from brainstorming

1. **Approach A — a region session on the real theme render.** The stage loads the same
   `/_preview/{token}` route the Design view uses. Rejected: serving `region-preview.twig` to the
   stage (a second layout that drifts from the real one, and a placeholder body), and allowing
   scripts in today's `blob:` frame (theme scripts on the admin's origin).
2. **Full stage.** Everything the Design view stage does with blocks: select, move, drag in from
   the Blocks tab, edit text in place, structure picker, grid fill, Outline, undo/redo.
3. **One Save for both regions.** Edits stay in the working copy until Save, which writes each
   changed region. Nothing goes live by itself; leaving with unsaved edits asks first.
4. **A real page between the chrome.** The stage shows a published page — the homepage by default,
   switched with a picker — read-only, so the chrome is judged against real content.
5. **Only the header and footer are selectable.** The page body is context: no block ids, no drop
   zones; clicking it does nothing.
6. **The Header / Footer switch moves to the top bar** and the side panel becomes the Design view's
   inspector (§3).
7. **Preview copies are per editor session, not shared.** What an editor sees on the stage is theirs
   until Save; two editors never overwrite each other's stage. The one shared moment is Save, which
   the version check guards (§4.5).

## 3. Page layout and navigation

**Top bar.** Title "Header & footer"; a **Header | Footer** switch; the Desktop / Tablet / Mobile
widths; a **page picker** (published pages, homepage by default); Undo / Redo; one **Save**, marked
while anything is unsaved. The switch follows the stage: selecting a footer block switches to
Footer. The switch sets the *current region* — which region the Blocks, Region and Outline tabs
work on.

**Side panel.** The Design view's inspector, with its tabs and its icon convention (labels for
editing tabs, icons with tooltips and screen-reader names for the rest):

| Tab | Shown | Contents |
|---|---|---|
| **Block** | a block is selected | Content / Layout / Style / Advanced — the Design view's `BlockInspector`, unchanged |
| **Blocks** | always | the palette, limited to the current region's allowed blocks; click or drag in |
| **Region** | always | the current region's settings: header — Sticky, Width, Style; footer — Width, Style |
| **Outline** (icon) | always | the current region's block tree, the Design view's `CanvasOutline` |

The card list and its per-card settings buttons are removed; the stage and the Outline replace them.
`RegionBlockInspector.vue` (the off-stage inspector) is removed with them.

**Stage.** The picked page, rendered by the theme, with the header and footer from the working copy
and editable; the page body inert.

## 4. Server

### 4.1 Session

`POST /v1/admin/regions/preview/session` — body `{page?: string}` (an entry uuid; default: the
configured homepage entry) → `{token, expires_at, expires_in, theme_url, epoch: null, revision:
null, regions: {header: {blocks, settings, lock_version|null}, footer: {…}}, style_generation}`.
`theme_url` is `/_preview/{token}`, or null when the `thallo.render` capability is off. Permission:
`content.view`.

The token is `PreviewToken` with a new kind claim: `k: "regions"`, `s: <session id>` (a random id
minted with the token), `p: <page uuid|null>`, `l: <default locale>`, `exp` (TTL
`thallo.preview.ttl_seconds`). `verify()` keeps requiring `e`/`l`/`exp` for entry tokens and
requires `k`/`s`/`l`/`exp` for region tokens; an entry token carries no `k`.
`EnginePreviewSessionVerifier` maps a region token to `PreviewSession` with a new
`kind: 'entry'|'regions'` (default `'entry'`), `session` and `page`; every existing consumer reads
`kind === 'entry'` sessions only and refuses the other (§4.7).

**Initialisation.** Minting reads both saved regions and their `lock_version`s once, stores that
snapshot as the session's **baseline** (§4.2) and returns the same snapshot, so the inspector and the
stage start from one document. A new session has no working copy and a null pair; the stage renders
the baseline until the session's first apply. Nothing an editor does can read or replace another
session's baseline or copy. Renewal after expiry and a page switch are explicit restore sequences
run by the admin (§5.3).

### 4.2 Baseline and working copy

A region session has two records, both keyed by the token's session id `{s}` and tenant-segmented.
Both expire at the **token's absolute expiry** (`exp`) — set when written, re-set to the same `exp`
on every later write, never extended past it and never shorter. The entry working copy's 300-second
cap does not apply to regions: an idle editor's accepted edits stay on the stage for as long as the
session is valid.

- **Baseline** — `thallo:preview:regions:baseline:{s}`: both regions' `{blocks, settings,
  lock_version}` as of the mint, or as of this session's last successful save (§4.5). It is the
  document the stage shows when there is no working copy.
- **Working copy** — `thallo:preview:working:regions:{s}` in `PreviewWorkingCopyStore`, with the
  existing compare-and-set protocol (`epoch`, `base_revision`, `baseline`). Its `fields` is
  `{header: {blocks, settings}, footer: {blocks, settings}}` — always both regions.

**What the stage renders:** the working copy if there is one, otherwise the baseline — never the
live rows. If both are gone (the session expired), the stage renders an "editing session expired"
page instead of any region content, and apply answers 410; the admin renews (§5.3).

### 4.3 Apply

`POST /v1/admin/regions/preview/apply` — body `{token, regions: {header, footer}, epoch,
base_revision, operations}` → `{epoch, revision, baseline, style_generation, applied_at}`.
Permission: `content.manage` (as a region save). Steps: verify a `regions` token; cap the payload
(1 MB, as entries); validate each region with `RegionValidator` (errors prefixed `regions.{slug}.`);
check block ids are unique across both regions (§6.2); `accept` into the session's working copy,
expiring at the token's `exp` (§4.2). Both regions are always sent. No fragments: `fragments` is always
null (§4.6). `style_generation` is the style-class generation the stage already consumes.

### 4.4 Render

`RenderController::preview` accepts a `regions` session. It renders the picked page's **published**
version (never a draft) through the normal page path, with:

- **One snapshot per render.** The render reads the session's working copy — or, without one, its
  baseline — **once**, at the start, and every region read in that render (header blocks and
  settings, footer blocks and settings) and the revision carrier come from that one record, even if
  an apply or a save completes during the render. A region reader decorator in front of
  `EngineRegionReader` serves that snapshot while a `regions` session is active; it never falls back
  to the live rows (§4.2).
- **Annotation scoped to the chrome.** `RenderContextExtension` replaces the boolean
  `annotateBlocks` with an annotation scope: `none`, `entry` (today's canvas) or `regions`.
  `regionBlocks()` annotates when the scope is `regions` and suppresses as today otherwise; the entry
  body annotates only when the scope is `entry`. `is_canvas()`, `slot_attrs()` and edit-in-place
  marking follow the same scope inside each subtree.
- **Root slots named after the regions.** `layout.twig`'s header and footer inner elements carry
  `slot_attrs('header')` / `slot_attrs('footer')`, which emit only in the `regions` scope, so the
  stage's drop zones have `parent: null, slot: 'header'|'footer'`.
- **Both regions always shown.** In a `regions` session the layout ignores the page's
  `presentation.header: hidden` / `presentation.footer: hidden`, so the region being edited is always
  on the stage; the session response and the stage carry which regions the picked page hides, and the
  admin shows a notice ("This page hides its header on the live site"). Public rendering and entry
  sessions honour the presentation exactly as today.
- **Empty regions.** In the `regions` scope, an empty region renders its wrapper with an empty slot
  (the stage marks it as a placeholder) instead of the fallback chrome, so a first block can be added.
  Outside the stage the fallback rule is unchanged.
- **Stage marker.** A canvas render sets `data-thallo-canvas` on `<html>`, independent of block
  annotations. The theme runtime's canvas check reads it instead of looking for
  `.thallo-preview-block` (which is absent when both regions are empty and the body is untagged); the
  entry canvas sets it too.
- **Revision carrier.** `main[data-thallo-epoch][data-thallo-revision][data-thallo-style-generation]`
  carries the snapshot's pair (empty before the first apply) and the style generation in a `regions`
  session.
- **No page.** When the picked page and the homepage are both unavailable, the body is the
  placeholder from today's `region-preview.twig`, moved into a partial the page path can render.

The stage URL keeps `withPreviewBridge()` injection, the `thallo_preview` / `thallo_preview_canvas`
cookies and the `/_preview/*` framing allowance.

### 4.5 Save

**One batch save.** `PUT /v1/admin/regions` — body `{token, regions: {header?: {blocks, settings},
footer?: {…}}, expected: {header: lock_version|null, footer: lock_version|null}, preview_revision:
{epoch, revision}|null}` → `{regions: {header: {blocks, settings, lock_version}, footer: {…}},
preview_cleared}`. Permission: `content.manage`. `regions` holds the dirty regions only; `expected`
always holds **both** regions' versions, the unchanged one included. It runs as one serialized
section:

1. **Serialize.** Open a transaction and take the region write lock — a transaction-scoped advisory
   lock on a fixed per-tenant key (`pg_advisory_xact_lock`) — held until commit. Absent rows cannot
   be row-locked, which is why this is an advisory lock and not `SELECT … FOR UPDATE`. Every region
   writer takes it (the inventory below).
2. **Check both versions** against the stored rows, the unchanged region included — `null` means
   "the row must not exist". Any mismatch answers 409 `REGION_VERSION_CONFLICT` naming the regions
   that moved, and writes nothing.
3. **Validate the complete candidate** — the posted regions over the stored ones, read under the
   lock — with `RegionValidator` and the cross-region id check, so a block moved from the header to
   the footer is judged on the final document, and two concurrent saves cannot each validate against
   the other region's old contents.
4. **Write** every posted region, bumping its `lock_version`, and commit.
5. **After commit:** dispatch the region-updated cache purge once; set the session's **baseline** to
   the committed snapshot of both regions and their new versions (§4.2); clear the session's working
   copy only if it still holds exactly the submitted `preview_revision` pair (below). The response
   carries that committed snapshot.

**Writer inventory.** The lock lives in one helper (working name `RegionWriteLock::within(callable)`:
open a transaction, take the advisory lock, run, commit), and every code path that writes the
`regions` table runs inside it:

| Writer | Path | Keeps |
|---|---|---|
| Batch save | `PUT /v1/admin/regions` (this section) | both-version check, complete-candidate validation |
| Per-region save | `PUT /v1/admin/regions/{slug}` → `RegionAdminController::update` | the same checks |
| Repository save | `RegionRepository::save()` — starter seeding and updates (`RegionKind::save`), `RetireAccountLinkCommand` | its unconditional write and version bump |
| Document source | `RegionsSource::persist()` — style-class jobs and block backfills | its conditional `WHERE lock_version = ?` write and bump; a refused write is still recorded and retried by its caller |
| Starter rename | `RegionKind::rename()` — the direct slug update | now also bumps `lock_version`, so an editor holding the old version gets 409 |

A new writer must go through the helper; a test enumerates the writers (§7).

**Version contract.** `RegionRepository::find()` returns `lock_version` (null for an absent row);
`index`, the session response and the save response carry it. `save()` gains a conditional form that
writes against the caller's expected version instead of retrying against the latest; the existing
unconditional form stays for jobs.

**Exact pair clearing.** `PreviewWorkingCopyStore` gains `clearIfPair(key, epoch, revision)`: under
the lock it clears only when the stored record's epoch **and** revision equal the given pair, and
never when the pair is null. The existing `clearIfRevision()` is not reused for regions. (Its
revision-only comparison would let a delayed save from epoch A clear epoch B when their revision
numbers match.)

`PUT /v1/admin/regions/{slug}` stays for API clients with the same protection: it takes the region
write lock, checks both regions' expected versions (`expected` in its body), validates the complete
candidate under the lock, and writes.

### 4.6 Refresh

Every apply is followed by a whole-stage refresh (`bridge.stageRefresh()`), the Design view's
fallback path, and the refresh acknowledgement carries `style_generation` as it does today. The
bridge's `applyStagePatch` compares the top-level annotated block ids and the shell outside them; in
a `regions` session the top-level ids are the header's and footer's, and the page body is part of the
shell. The body can change between refreshes (a publish, dynamic content); the existing shell
comparison then answers `reload`, and the stage reloads. Region fragments are left for later.

### 4.7 Isolation

Entry sessions must behave exactly as today: `EntryController::applyPreview`, `PreviewFragments`,
`EnginePublicRouteResolver::resolvePreview` and the entry apply/mint routes refuse a `regions` token
(403, as a token for another entry), and the region endpoints refuse an entry token. In an entry
session, regions keep rendering from the saved rows with annotation suppressed.

## 5. Admin

### 5.1 The document

The page edits one `EditorDocument`: `fields = {header: BlockInstance[], footer: BlockInstance[],
_region_header: settings, _region_footer: settings}`. `header` and `footer` are the root block lists
(`blockFields()`); the two settings keys are page-settings keys, so region settings go through
`SetPageSettings` and undo covers them. A synthetic schema drives `FieldEditor` and the legality
context: `[{name: 'header', type: 'blocks', blockTypes: palette(header)}, {name: 'footer', …}]`,
built from `useRegions()`'s `palette`. The region host (§5.2) converts between this document and
the server's `{header: {blocks, settings}, footer: {…}}` shape on apply and save.

**Palettes.** A region's palette constrains insertion at the **root** of that region only. Inside a
block, insertion follows the selected slot's own rules (its block type's `block_types`, depth), as
on the Design view.

### 5.2 Shared stage code

The Design page (`design/[locale].vue`) is split along one seam: **stage editing** — the bridge,
history, apply loop, drag coordinator, structure picker, grid fill, selection, edit-in-place grant,
Outline and Block tab — and **document hosting** — loading, saving, publishing and page-only panels.
The stage editing moves into a composable (working name `useStageEditor`) that takes a host:

```ts
interface StageHost {
  schema: Ref<FieldDef[]>            // root block fields (+ their palettes)
  initial: Ref<Record<string, unknown> | null>
  mint(): Promise<{ token: string; themeUrl: string | null; accepted: RevisionPair | null }>
  apply(token: string, fields: Record<string, unknown>, pair: ApplyPair): Promise<ApplyResult>
  /** Whether opening reconciles a stored working copy by applying the loaded document. */
  reconcileOnOpen: boolean
}
```

The Design page provides the entry host (today's `mintPreviewData` / `applyPreview`, draft
hydration, `reconcileOnOpen: true`); the Regions page provides the region host (§4.1, §4.3,
`useRegions()`, `reconcileOnOpen: false` — a fresh session has no copy to reconcile, §4.1).
Page-only features — draft lock, publish, routes, locales, SEO, versions, `_presentation`, restore to
draft — stay in the Design page. The refactor is behaviour-preserving for the Design view (§7).

### 5.3 The Regions page

`regions/index.vue` becomes a host of the stage editor with the §3 layout: the top bar, the four
tabs, and the stage. The Header / Footer switch sets the current region and follows `selection`
(the root slot of the selected block).

- **Save versions.** The page keeps a **save baseline**: both regions' `lock_version`s as first
  loaded. Only a successful save advances it (to the versions the save returns) and only Reload
  replaces it. A mint response never replaces it — renewal and page switches keep the original
  versions, so a save after either still detects another editor's save.
- **Save.** One call to the batch save (§4.5) with the dirty regions, both expected versions from the
  save baseline and the accepted `preview_revision`. On success it advances the save baseline and
  marks saved **the history position that was submitted**; edits made while the save was in flight
  stay dirty. On 409 it shows "Changed by someone else" with Reload, which discards the unsaved edits
  and loads the saved regions and versions.
- **Restore sequence** — used for a page switch and for renewal after expiry, never overlapping:
  1. mint a session (for the chosen page, or the same page on renewal);
  2. apply the **current complete document** to it with a null pair;
  3. show the new stage only after that apply is accepted (until then the old stage stays, marked
     "Switching…").
  History, dirty state and the save baseline are kept throughout. Each sequence carries a generation
  number; starting a new one abandons the one in flight, and any response (mint, apply, refresh)
  from an abandoned sequence or an old session is ignored.
- **Leaving** with unsaved edits uses the existing unsaved-changes guard.

### 5.4 The inert body

The stage script gains a **region-only mode**, enabled in a `regions` session: outside
`[data-thallo-slot="header"]` and `[data-thallo-slot="footer"]` it cancels clicks, link navigation,
form submission and keyboard activation (Enter and Space on focusable elements), and never selects;
scrolling, wheel and scroll-position reporting are untouched. Inside the regions the stage behaves as
on the Design view (block-internal links are already inert there).

## 6. Edge cases

1. **Concurrent saves.** Covered by the version contract (§4.5): a save against a moved version
   writes nothing and answers 409; the page offers Reload.
2. **Block ids across both regions.** Apply and save refuse an id used in both the header and the
   footer (422), judged on the complete candidate, so the stage's DOM↔id bridge stays unambiguous
   and a move between regions is never refused mid-way.
3. **The picked page.** Published pages only. An unpublished or deleted page falls back to the
   homepage; with no homepage, the placeholder body (§4.4). A page that hides a region shows it
   anyway, with a notice (§4.4).
4. **Render off.** The page shows the Design view's "rendered delivery is off" state.
5. **Session expiry.** A 403/410 on apply, or the stage's "editing session expired" page, runs the
   restore sequence (§5.3) for the same page, so the renewed stage shows the editor's document, not
   the live rows.
6. **Invalid apply.** The stage keeps its last good state; the error shows until an apply succeeds.
7. **Two editors.** Each has their own session, baseline and working copy. Opening the page never
   applies, so editor B opening it cannot change editor A's stage; their applies land in separate
   records; saves are serialized and check both regions' versions, so the first save wins and the
   second gets 409 (§4.5) — even when one saved the header and the other the footer.
8. **Another editor saves while this one is editing.** This editor's stage keeps showing their own
   document (baseline or working copy, §4.2) until they save, which then answers 409.

## 7. Testing

- **PHP:**
  - the region token (kind, session id, verify, refusal by entry endpoints and vice versa) and the
    session endpoint, including the saved regions and versions it returns;
  - apply's validation, cross-region id check and compare-and-set, against the session's own key;
  - **two sessions:** opening a second session and applying in it leaves the first session's copy and
    stage untouched;
  - **the pinned baseline:** another editor saving between this session's mint and its first render
    does not change what the stage shows; after this editor's save clears the working copy, the stage
    shows the committed snapshot, not a newer live row; an expired session renders the expired page,
    never live rows;
  - the render — in a `regions` session only header and footer carry `data-thallo-block` /
    `data-thallo-slot`, their blocks come from the session copy, an empty region renders a slot, the
    body is untagged, the page is the published version, `<html data-thallo-canvas>` is set — and an
    entry session renders exactly as before;
  - **one snapshot:** a render during which an apply lands shows one record throughout (both regions
    and the revision carrier);
  - pages with a hidden header, a hidden footer, and both hidden: the stage shows both, public render
    hides them;
  - the batch save: the complete candidate validated (a block moved between regions saves), all
    regions written or none, versions returned, a stale version answering 409 with nothing written,
    a stale version of the **unchanged** region answering 409, an absent row with `null`, the cache
    purged once;
  - **the duplicate-id race:** two concurrent saves — one writing the header, one the footer, each
    adding the same block id — leave exactly one committed and the other refused (409 or 422), never
    both; the per-region endpoint is held to the same;
  - **background writes versus an admin save:** a `RegionsSource::persist()` write (a style-class job
    or backfill) racing a batch save leaves the two serialized — the save answers 409 if the
    background write landed first, and the background write is refused and retried if the save landed
    first — and a starter rename bumps the version an open editor holds;
  - **the writer inventory:** every code path that writes the `regions` table goes through
    `RegionWriteLock` (a test that fails when a new direct writer appears);
  - **the session lifetime:** with a controlled clock, apply edits, advance past 300 seconds while the
    token is still valid, render — the edits are still on the stage; past the token's `exp` both
    records are gone and the stage renders the expired page;
  - **exact pair clearing:** a delayed save from epoch A does not clear epoch B's record at the same
    revision number; a null pair clears nothing;
  - permissions.
- **Admin vitest:** the region document and synthetic schema; the Region tab writing settings through
  history; the switch following the selection; Save sending dirty regions with their versions and
  marking only the submitted position saved; the conflict message and Reload; a mint response not
  replacing the save baseline; the restore sequence applying the whole document with a null pair,
  showing the new stage only after acceptance, and a second switch abandoning the first.
- **Admin e2e:** selecting a header block opens its Block tab; drag reorder in the header; a block
  dragged from the Blocks tab into the footer; in-place text edit; undo; Save; a click on a body link
  navigates nowhere and selects nothing; a block not allowed at a region's root is refused while the
  same block is accepted inside a container that allows it; the empty-regions stage (both regions
  empty) still loads as a stage; **switching pages with unsaved edits and making no further edit
  still shows those edits on the new stage**. **The existing Design view e2e suite passes unchanged** — the proof
  that §5.2's refactor preserved it.

## 8. Rollout

One beta. No migrations. The old region preview endpoint, `region-preview.twig` (its placeholder
moves to a partial, §4.4) and the `blob:` frame are removed in the same release; nothing else uses
them. The per-region `PUT /v1/admin/regions/{slug}` stays, now conditional on `lock_version`.
