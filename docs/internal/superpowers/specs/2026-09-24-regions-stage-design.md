# Regions Stage — Design

**Status:** approved in brainstorming, 2026-09-24. Delivered as one release (one beta cut).
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
configured homepage entry) → `{token, expires_at, expires_in, theme_url, epoch, revision}`, the
shape of `PreviewController::mint`. `theme_url` is `/_preview/{token}`, or null when the
`thallo.render` capability is off. Permission: `content.view`.

The token is `PreviewToken` with a new kind claim: `k: "regions"`, `p: <page uuid|null>`,
`l: <default locale>`, `exp` (TTL `thallo.preview.ttl_seconds`). `verify()` keeps requiring
`e`/`l`/`exp` for entry tokens and requires `k`/`l`/`exp` for region tokens; an entry token carries
no `k`. `EnginePreviewSessionVerifier` maps a region token to `PreviewSession` with a new
`kind: 'entry'|'regions'` (default `'entry'`) and `page` for regions; every existing consumer reads
`kind === 'entry'` sessions only and refuses the other (§4.7).

### 4.2 Working copy

`PreviewWorkingCopyStore` gains a region key, `thallo:preview:working:regions` (tenant-segmented as
today), used with the existing `accept` / `current` / `fields` / `clearIfRevision`. The record's
`fields` is `{header: {blocks, settings}, footer: {blocks, settings}}`. The compare-and-set protocol
(`epoch`, `base_revision`, `baseline`) is unchanged.

### 4.3 Apply

`POST /v1/admin/regions/preview/apply` — body `{token, regions: {header?, footer?}, epoch,
base_revision, operations}` → `{epoch, revision, baseline, applied_at}`. Permission:
`content.manage` (as a region save). Steps: verify a `regions` token; cap the payload (1 MB, as
entries); validate each posted region with `RegionValidator` (errors prefixed `regions.{slug}.`);
check block ids are unique across both regions (§6.2); `accept` into the region working copy with
TTL `min(token remaining, 300)`. A region absent from the payload keeps its stored copy, or the saved
row if there is none. No fragments: `fragments` is always null (§4.6).

### 4.4 Render

`RenderController::preview` accepts a `regions` session. It renders the picked page's **published**
version (never a draft) through the normal page path, with:

- **Chrome from the working copy.** A region reader decorator in front of `EngineRegionReader`
  answers `blocks(slug)` / `settings(slug)` from the region working copy while a `regions` session is
  active, falling back to the saved row per slug.
- **Annotation scoped to the chrome.** `RenderContextExtension` replaces the boolean
  `annotateBlocks` with an annotation scope: `none`, `entry` (today's canvas) or `regions`.
  `regionBlocks()` annotates when the scope is `regions` and suppresses as today otherwise; the entry
  body annotates only when the scope is `entry`. `is_canvas()`, `slot_attrs()` and edit-in-place
  marking follow the same scope inside each subtree.
- **Root slots named after the regions.** `layout.twig`'s header and footer inner elements carry
  `slot_attrs('header')` / `slot_attrs('footer')`, which emit only in the `regions` scope, so the
  stage's drop zones have `parent: null, slot: 'header'|'footer'`.
- **Empty regions.** In the `regions` scope, an empty region renders its wrapper with an empty slot
  (the stage marks it as a placeholder) instead of the fallback chrome, so a first block can be added.
  Outside the stage the fallback rule is unchanged.
- **Revision carrier.** `main[data-thallo-epoch][data-thallo-revision]` carries the region working
  copy's pair in a `regions` session.
- **No page.** When the picked page and the homepage are both unavailable, the body is the
  placeholder from today's `region-preview.twig`, moved into a partial the page path can render.

The stage URL keeps `withPreviewBridge()` injection, the `thallo_preview` / `thallo_preview_canvas`
cookies and the `/_preview/*` framing allowance.

### 4.5 Save

`PUT /v1/admin/regions/{slug}` is unchanged, plus two things: an optional `lock_version` (§6.1) and
an optional `preview_revision {epoch, revision}`. After a successful save the endpoint calls
`clearIfRevision` on the region working copy with it, as `saveDraft` does for entries, and answers
`preview_cleared`. The admin's one Save calls it for each changed region.

### 4.6 Refresh

Every apply is followed by a whole-stage refresh (`bridge.stageRefresh()`), the Design view's
fallback path. The bridge's `applyStagePatch` compares the top-level annotated block ids and the
shell outside them; in a `regions` session the top-level ids are the header's and footer's, and the
page body is shell, which is identical between refreshes. A reload remains the fallback when the
patch refuses. Region fragments are left for later.

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
}
```

The Design page provides the entry host (today's `mintPreviewData` / `applyPreview`, draft
hydration); the Regions page provides the region host (§4.1, §4.3, `useRegions()`). Page-only
features — draft lock, publish, routes, locales, SEO, versions, `_presentation`, restore to draft —
stay in the Design page. The refactor is behaviour-preserving for the Design view (§7).

### 5.3 The Regions page

`regions/index.vue` becomes a host of the stage editor with the §3 layout: the top bar, the four
tabs, and the stage. Save writes each dirty region (`PUT /regions/{slug}` with `lock_version` and
`preview_revision`), marks the history saved, and clears the unsaved mark. Leaving with unsaved
edits uses the existing unsaved-changes guard. The Header / Footer switch sets the current region
and follows `selection` (the root slot of the selected block).

## 6. Edge cases

1. **Concurrent saves.** The region's `lock_version` is sent as loaded. A mismatch answers 409
   `REGION_VERSION_CONFLICT`; the page shows "Changed by someone else" with Reload (discard mine and
   load theirs). `RegionRepository::save` already bumps the version.
2. **Block ids across both regions.** Apply and save refuse an id used in both the header and the
   footer (422 on the later one), so the stage's DOM↔id bridge stays unambiguous.
3. **The picked page.** Published pages only. An unpublished or deleted page falls back to the
   homepage; with no homepage, the placeholder body (§4.4).
4. **Render off.** The page shows the Design view's "rendered delivery is off" state.
5. **Session expiry.** A 403/410 on apply re-mints once and retries, as the Design view does.
6. **Invalid apply.** The stage keeps its last good state; the error shows until an apply succeeds.

## 7. Testing

- **PHP:** the region token (kind, verify, refusal by entry endpoints and vice versa); the session
  endpoint; apply's validation, cross-region id check and compare-and-set; the render — in a
  `regions` session only header and footer carry `data-thallo-block` / `data-thallo-slot`, their
  blocks come from the working copy, an empty region renders a slot, the body is untagged, the page
  is the published version — and an entry session renders exactly as before; save clearing the
  working copy; the lock conflict; permissions.
- **Admin vitest:** the region document and synthetic schema; the Region tab writing settings through
  history; the switch following the selection; Save of dirty regions only; the conflict message.
- **Admin e2e:** selecting a header block opens its Block tab; drag reorder in the header; a block
  dragged from the Blocks tab into the footer; in-place text edit; undo; Save; the page body is not
  selectable; a block not allowed in a region is refused. **The existing Design view e2e suite
  passes unchanged** — the proof that §5.2's refactor preserved it.

## 8. Rollout

One beta. No migrations. The old region preview endpoint, `region-preview.twig` (its placeholder
moves to a partial, §4.4) and the `blob:` frame are removed in the same release; nothing else uses
them.
