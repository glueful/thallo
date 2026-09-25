# Regions Stage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Header & footer page edits the site's chrome on the Design view's interactive stage — select, drag, drop in, edit in place, undo — over a real published page, with per-session preview copies and one serialized save.

**Architecture:** A new kind of preview session (`RegionPreviewToken`, its own class) pins a baseline of both regions and keeps a per-session working copy in a new `RegionPreviewStore`; the existing `/_preview/{token}` route renders the picked page's published version with the chrome served from that session's snapshot and annotated for the stage (an annotation *scope* replaces the boolean). Every write to the `regions` table goes through one advisory-lock helper, and a new batch endpoint validates the complete candidate and checks both regions' versions under it. The Design page's stage editing is extracted into a composable with a host interface; the Regions page becomes a second host of it.

**Tech Stack:** PHP 8.3 (Glueful, PostgreSQL, PHPUnit), Twig 3 (render pack), the preview bridge (plain script), Nuxt UI admin (Vue 3, vitest, Playwright e2e in `admin/e2e`).

**Spec:** `docs/internal/superpowers/specs/2026-09-24-regions-stage-design.md` (approved at `db32aee8`, wording fix `4fed4a03`). Section numbers below (§n) are the spec's. The stage map this plan builds on — every file:line cited in the brainstorming exploration — is summarised under **Shared contracts**.

## Global Constraints

- **Regions stay separate from pages:** no draft, no versions, no locale; a save goes live on every page (§1).
- **Only the header and footer are selectable on the Regions stage;** the page body is inert: no ids, no drop zones, clicks, navigation, submission and keyboard activation cancelled, scrolling kept (§2.5, §5.4).
- **Preview copies are per editor session, not shared** (§2.7). Opening the Regions page never applies (§4.1, §5.2 `reconcileOnOpen: false`).
- **The stage never renders the live rows in a region session:** working copy, else baseline, else the expired page (§4.2).
- **Both session records expire at the token's absolute `exp`;** no 300-second cap for regions (§4.2).
- **Every writer of the `regions` table runs inside `RegionWriteLock::within()`** (§4.5 writer inventory).
- **Saves send both regions' expected versions** and validate the complete candidate under the lock (§4.5).
- **Clearing compares the full `{epoch, revision}` pair; a null pair clears nothing** (§4.5).
- **One working-copy (or baseline) read per render** (§4.4).
- **Entry sessions behave exactly as today** (§4.7); the existing Design view e2e suite passes unchanged (§7).
- **PostgreSQL-only** (the advisory lock uses `pg_advisory_xact_lock`).
- One release, one beta cut at the end — the cut, split, tags and pushes are the user's; work ends at the local commit.
- Every change carries its CHANGELOG bullet under `## [Unreleased]` in the same commit.
- Gates, run foreground and never concurrently: PHP — `COMPOSER_PROCESS_TIMEOUT=0 composer test` (run `composer test:migrate` first when the test DB is stale), `composer phpcs` (check its **exit code**; warnings fail CI), `composer boundaries`; render changes — re-record `packages/thallo-render/fragments-verified.json` with `THALLO_RECORD_FRAGMENT_VERIFICATION=1 vendor/bin/phpunit tests/Integration/Render/FragmentVerificationTest.php`, style proofs `cd tools/style-proofs && npx playwright test --project=chromium --project=webkit`; admin — `pnpm exec oxfmt <touched files>`, `pnpm type-check`, `pnpm lint`, `pnpm fmt:check`, `pnpm test`, and **the e2e suite `cd admin/e2e && pnpm exec playwright test`** for any admin change.
- `git diff` before every commit; no AI attribution trailers; never push, never tag.

**Steps 1–4**, wherever a task says so, are the TDD cycle: write the failing test(s) named in the task, run and watch each fail for the stated reason, implement the minimum, run the file and the task's gates green. Step 5 is the commit with the changelog bullet.

## Review Focus

The inputs the spec implies but no requirement names, most likely to bite first. Each has its test in the owning task.

1. **The first save of a region that has never been saved** (a fresh install's footer is seeded, but a site that deleted a row, or a tenant without seed rows): `expected: null` must create the row, and a second editor's `null` must then conflict — Task R4.
2. **Undo past the saved position after a save:** the document differs from what was saved again, so the page is dirty and Save sends it with the advanced versions — Task R8.
3. **A picked page whose published version holds a block type since removed or a template that fails:** the stage must still load (the missing-template fallback), with the chrome editable — Task R5.
4. **Keyboard reaching the body:** Tab into a body link and Enter, or Space on a body button, must do nothing, while Tab and Enter inside a header block behave as on the Design view — Task R6.
5. **Reload or close the browser tab with unsaved edits:** the browser's own leave prompt fires (not only in-app navigation), and after a real reload the page starts from the saved regions with a fresh session — Task R8.

---

## Shared contracts (named once, used by every task)

**Server**

- `Thallo\Core\Content\Regions\RegionWriteLock` (new) — `within(callable $fn): mixed`: opens a transaction on the app connection, runs `SELECT pg_advisory_xact_lock(?)` with a fixed per-tenant key (`crc32('thallo:regions:' . $tenantSegment)` as a signed 32-bit int; the tenant segment from `TenantCacheSegment` when present, else `''`), runs `$fn`, commits (rolls back and rethrows on exception). Re-entrant within one transaction (a nested call on an open transaction just runs `$fn`).
- `RegionRepository` changes: `find(slug)` returns `lock_version: int|null` (`null` only for an absent row — never, since `find` returns null then; callers read `?->['lock_version']`); new `saveExpected(string $slug, array $blocks, array $settings, ?int $expected, ?string $by): int` — **must run inside `RegionWriteLock::within`** (asserts an open transaction), writes `WHERE lock_version = $expected` (or inserts when `$expected === null`, failing on an existing row), bumps and returns the new version, throws `RegionVersionConflict` when no row matched; the existing `save()` keeps its unconditional write and bump, now wrapped in `RegionWriteLock::within`.
- `Thallo\Core\Content\Regions\RegionVersionConflict` (new exception) — `public readonly array $moved` (slugs).
- `Thallo\Core\Content\Preview\RegionPreviewToken` (new) — claims `{k: "regions", s, p, l, exp}`; `mint(string $session, ?string $page, string $locale, int $exp, string $key): string`; `verify(string $token, string $key, int $now): self` (requires `k === 'regions'`, `s`, `l`, `exp`; same HMAC and base64url as `PreviewToken`); readonly `session`, `page`, `locale`, `expiresAt`. An entry token fails its `verify` (no `k`); a region token fails `PreviewToken::verify` (no `e`) — isolation by construction.
- `Thallo\Contracts\Delivery\PreviewSession` gains `public readonly string $kind = 'entry'`, `public readonly ?string $session = null`, `public readonly ?string $page = null` (constructor named args, defaults keep every existing `new PreviewSession(...)` valid). `EnginePreviewSessionVerifier::verify` tries `PreviewToken`, then `RegionPreviewToken`, and maps the latter to `kind: 'regions'`, `entry: ''`.
- `Thallo\Core\Content\Preview\RegionPreviewStore` (new) — constructed with `CacheStore`, `?TenantCacheSegment`, `?ApplicationContext`, `?\Closure $now = null` (defaults to `time(...)`). Records carry their `exp`; a record read at `now() > exp` is absent. Methods:
  - `putBaseline(string $s, array $regions, int $exp): void` — `$regions = ['header' => ['blocks'=>…, 'settings'=>…, 'lock_version'=>?int], 'footer' => …]`; cache TTL `max(1, $exp - now())`.
  - `baseline(string $s): ?array`
  - `accept(string $s, ?string $epoch, ?int $base, array $fields, array $ops, int $exp): array` — the `PreviewWorkingCopyStore::accept` compare-and-set, same result shape, `fields = ['header'=>['blocks','settings'], 'footer'=>…]`, TTL to `$exp`.
  - `current(string $s): ?array` (`{epoch, revision, fields, ops, accepted_at, exp}`)
  - `snapshot(string $s): ?array` — **one** read: `['source' => 'working'|'baseline', 'regions' => [...], 'epoch' => ?string, 'revision' => ?int]`, working copy first, else baseline, else null.
  - `clearIfPair(string $s, string $epoch, int $revision): bool` — under the lock, clears the working copy only when both match.
  - keys `{tenant}thallo:preview:regions:baseline:{s}` and `{tenant}thallo:preview:working:regions:{s}`.
- Endpoints (routes in `core/routes/admin.php` beside the region routes):
  - `POST /v1/admin/regions/preview/session` (`content.view`) → `{token, expires_at, expires_in, theme_url, epoch: null, revision: null, regions: {header: {blocks, settings, lock_version}, footer: …}, hidden: {header: bool, footer: bool}, style_generation}`.
  - `POST /v1/admin/regions/preview/apply` (`content.manage`) body `{token, regions: {header, footer}, epoch, base_revision, operations}` → `{epoch, revision, baseline, style_generation, applied_at}`; 409 `PREVIEW_REVISION_STALE` with `details.current {epoch, revision}` as entries do; 410 when the session's records are gone.
  - `PUT /v1/admin/regions` (`content.manage`) body `{token, regions: {header?, footer?}, expected: {header: ?int, footer: ?int}, preview_revision: {epoch, revision}|null}` → `{regions: {header: {blocks, settings, lock_version}, footer: …}, preview_cleared}`; 409 `REGION_VERSION_CONFLICT` `details.moved: string[]`; 422 dot-paths `regions.{slug}.…`.
  - `PUT /v1/admin/regions/{slug}` gains `expected: {header: ?int, footer: ?int}` (required) and runs the same section.
- Render: `RenderContextExtension::setAnnotationScope(string $scope)` with `'none'|'entry'|'regions'` replaces `setBlockAnnotations(bool)` (kept as a thin wrapper mapping `true → 'entry'`, `false → 'none'` for callers outside this plan); `is_canvas()` is true for either non-`none` scope. `regionBlocks()` annotates in `regions` scope; the entry body annotates in `entry` scope only.
- The region reader decorator `Thallo\Render\Regions\RegionSessionReader` (render pack) implements `RegionReader`, constructed per render with the snapshot's regions; `RenderController` swaps it in for the render's duration.
- Markup: `<html data-thallo-canvas="entry"|"regions">` on every canvas render (the value is the annotation scope; the theme runtime tests presence, the bridge reads the value); `layout.twig` header/footer inner elements carry `slot_attrs('header'|'footer')`; `<main data-thallo-epoch data-thallo-revision data-thallo-style-generation>` as today.

**Admin**

- `admin/src/queries/regions.ts` gains `mintRegionSession(page?: string)`, `applyRegions(token, fields, pair)`, `saveRegions(body)`, and `RegionData.lock_version`.
- `admin/src/editor/stage/useStageEditor.ts` (new) — the extracted stage editing (§5.2). Signature:
  ```ts
  export interface StageHost {
    schema: Ref<FieldDef[]>
    initial: Ref<Record<string, unknown> | null>
    mint(): Promise<{ token: string; themeUrl: string | null; accepted: RevisionPair | null }>
    apply(token: string, fields: Record<string, unknown>, pair: ApplyPair): Promise<ApplyResult>
    reconcileOnOpen: boolean
  }
  export function useStageEditor(host: StageHost, refs: { iframe: Ref<HTMLIFrameElement | null>; fieldEditor: Ref<FieldEditorExposed | null> }): StageEditor
  ```
  `StageEditor` exposes what the Design page template uses today: `fields`, `selection`, `selected`, `history` state (`canUndo`, `canRedo`, `undo`, `redo`, `isDirty`, `markSaved(seq)`, `currentSequence`), `accepted`, `iframeSrc`, `renderDisabled`, `onIframeLoad`, `runApply`, `remint`, the Blocks-tab and Outline bindings, and `applyDrop`. `ApplyPair`/`ApplyResult`/`RevisionPair` are the existing types from `admin/src/queries/preview.ts`, moved to `admin/src/editor/stage/types.ts` and re-exported.
- `admin/src/pages/regions/useRegionHost.ts` (new) — the region `StageHost` plus the document mapping (§5.1): `toDocument(regions) → {header, footer, _region_header, _region_footer}`, `toPayload(fields) → {header: {blocks, settings}, footer: …}`, the save baseline, `save()`, `restore(page)` (the restore sequence).
- Test ids: `regions-switch-header`, `regions-switch-footer`, `regions-page-picker`, `regions-save`, `regions-undo`, `regions-redo`, `regions-conflict`, `regions-conflict-reload`, `regions-hidden-notice`, `regions-switching`, `regions-tab-region`, `regions-stage`; the inspector keeps `canvas-inspector`, `inspector-tabs`, `block-inspector`.

---

## Task R1: one lock for every writer of the `regions` table

**Files:**
- Create: `core/src/Content/Regions/RegionWriteLock.php`, `core/src/Content/Regions/RegionVersionConflict.php`
- Modify: `core/src/Content/Regions/RegionRepository.php` (`find` returns `lock_version`; `save` wrapped; `saveExpected` added), `core/src/Content/Blocks/Sources/RegionsSource.php` (`persist` inside the lock, conditional write kept), `core/src/Content/Starter/Kinds/RegionKind.php` (`rename` inside the lock, bumps `lock_version`), `core/src/Providers/CoreServiceProvider.php` (register `RegionWriteLock`)
- Test: `tests/Integration/Content/RegionWriteLockTest.php` (new), `tests/Unit/Content/RegionWriterInventoryTest.php` (new)

**Interfaces:** Produces `RegionWriteLock::within`, `RegionRepository::saveExpected`, `RegionVersionConflict` (Shared contracts).

- [ ] **Steps 1–4.** Tests, each failing first:
  - `testWritersAreSerialized`: inside `$lock->within(fn () => …)` on the app connection, a **second PDO connection** (`new \PDO($dsn, $user, $pass)` from the test DB config) calls `SELECT pg_try_advisory_xact_lock(:key)` inside its own transaction and gets `false`; after `within` returns it gets `true`. (Proves every writer queues on one key; the key is read from `RegionWriteLock::key()` — make it a public method for this.)
  - `testSaveExpectedWritesOnlyAgainstTheExpectedVersion`: seed `header` at version 3; `saveExpected('header', …, 3)` returns 4; `saveExpected('header', …, 3)` throws `RegionVersionConflict` with `moved === ['header']` and the row is unchanged.
  - `testSaveExpectedNullCreatesAndThenConflicts` (**Review Focus 1**): no `footer` row; `saveExpected('footer', …, null)` creates it at version 1; a second `saveExpected('footer', …, null)` throws.
  - `testSaveExpectedOutsideTheLockIsRefused`: calling it with no open transaction throws `\LogicException`.
  - `testFindReturnsTheVersion`.
  - `testBackgroundPersistAndAdminSaveSerialize`: `RegionsSource::each` hands a `DocumentRef` at version 1; an admin `saveExpected(…, 1)` commits (version 2); `persist($ref, …)` returns `false` and the row keeps the admin's blocks; the reverse order — `persist` first (version 2), then `saveExpected(…, 1)` — throws `RegionVersionConflict`.
  - `testRenameBumpsTheVersion`: rename `old → header` leaves `lock_version` one higher.
  - `RegionWriterInventoryTest::testEveryRegionsWriteGoesThroughTheLock`: scans `core/src`, `packages/*/src` for `->table('regions')` followed by `->update(`, `->insert(`, `->delete(` (and `DELETE FROM regions` / `UPDATE regions` / `INSERT INTO regions` strings) and asserts each hit's file is in the allow-list `[RegionRepository.php, RegionsSource.php, RegionKind.php]`, and that each of those methods' bodies contains `RegionWriteLock` or `->within(` (a regex over the method source). A new direct writer fails this test with its path.
- [ ] **Step 3 detail.** `RegionWriteLock`:
  ```php
  final class RegionWriteLock
  {
      public function __construct(
          private readonly ApplicationContext $context,
          private readonly ?TenantCacheSegment $tenantCache = null,
      ) {
      }

      public function key(): int
      {
          $segment = $this->tenantCache?->segment($this->context, 'regions') ?? '';
          $crc = crc32('thallo:regions:' . $segment);
          return $crc > 0x7FFFFFFF ? $crc - 0x100000000 : $crc;
      }

      /** @template T @param callable(): T $fn @return T */
      public function within(callable $fn): mixed
      {
          $db = db($this->context);
          if ($db->inTransaction()) {
              return $fn();
          }
          return $db->transaction(function () use ($db, $fn): mixed {
              $db->statement('SELECT pg_advisory_xact_lock(?)', [$this->key()]);
              return $fn();
          });
      }
  }
  ```
  (Use the connection's real transaction/statement API — read `RegionRepository`'s existing `$this->db` usage and `db($this->context)->transaction(...)` in `PublishService` for the exact calls; keep the shape.)
- [ ] **Step 5.** Changelog `### Changed`: "Every write to the header and footer is serialized: saves, starter updates, style-class jobs and block backfills take one lock, so none can overwrite another." Commit `feat(regions): one lock serializes every write to the regions table`.

## Task R2: the region preview token and the region preview store

**Files:**
- Create: `core/src/Content/Preview/RegionPreviewToken.php`, `core/src/Content/Preview/RegionPreviewStore.php`
- Modify: `packages/thallo-contracts/src/Delivery/PreviewSession.php` (kind, session, page), `core/src/Content/Preview/EnginePreviewSessionVerifier.php` (region tokens), `core/src/Providers/CoreServiceProvider.php` (register the store)
- Test: `tests/Unit/Content/RegionPreviewTokenTest.php`, `tests/Integration/Content/RegionPreviewStoreTest.php` (new)

**Interfaces:** Produces `RegionPreviewToken`, `RegionPreviewStore`, `PreviewSession::$kind/$session/$page` (Shared contracts).

- [ ] **Steps 1–4.** Tests:
  - Token: round-trip; a tampered payload fails; an expired one fails; **an entry token fails `RegionPreviewToken::verify`** and **a region token fails `PreviewToken::verify`**; the verifier maps a region token to `kind: 'regions'` with `session`/`page`, and an entry token to `kind: 'entry'` unchanged.
  - Store (`now` injected as a closure over a mutable `$t`): `putBaseline` then `snapshot` gives `source: 'baseline'`, null pair; `accept(null, null, …)` then `snapshot` gives `source: 'working'`, revision 1; a stale pair is refused with the current pair returned; **`testTheWorkingCopyOutlivesFiveMinutesUntilTheTokenExpires`** — `exp = $t + 600`, accept at `$t`, set `$t += 301`, `snapshot` is still `working` with the edits; set `$t = exp + 1`, `snapshot` is null; **`testClearIfPairNeedsBothHalves`** — accept to `(A, 1)`; `clearIfPair(s, 'B', 1)` false and the copy remains; `clearIfPair(s, A, 2)` false; `clearIfPair(s, A, 1)` true; **`testSessionsAreIsolated`** — two session ids never read each other's baseline or copy.
- [ ] **Step 3 detail.** The store's `locked()`/`read()` mirror `PreviewWorkingCopyStore` (copy the lock idiom, keyed per record); every record stores `exp`, and `read` returns null when `($this->now)() > $record['exp']`. The TTL passed to the cache is `max(1, $exp - ($this->now)())`.
- [ ] **Step 5.** No changelog bullet (no behaviour yet). Commit `feat(preview): region preview tokens and a per-session region store`.

## Task R3: the session and apply endpoints

**Files:**
- Create: `core/src/Http/Controllers/RegionPreviewController.php` (or methods on `RegionAdminController` — follow the file's size: a new controller keeps `RegionAdminController` focused), `core/src/Http/DTOs/RegionSessionData.php`, `core/src/Http/DTOs/ApplyRegionsData.php`
- Modify: `core/routes/admin.php` (two routes), `core/src/Content/Regions/RegionValidator.php` (a `validateBoth(array $regions): array` that runs each region and the cross-region id check, errors prefixed `regions.{slug}.`)
- Test: `tests/Integration/Http/RegionPreviewApiTest.php` (new)

**Interfaces:** Consumes R2. Produces the two endpoints (Shared contracts) and `RegionValidator::validateBoth`.

- [ ] **Steps 1–4.** Tests:
  - Session: returns both saved regions with their `lock_version`s and `hidden` for the picked page (a page whose `_presentation.header` is `hidden` answers `hidden.header: true`); `theme_url` is `/_preview/{token}`, null with the render capability off; the baseline stored under the token's session equals the returned regions; an unknown or unpublished `page` falls back to the homepage entry (and `page` is null in the token when there is no homepage); `content.view` required.
  - Apply: a valid document is accepted (revision 1, `style_generation` present); a stale pair answers 409 `PREVIEW_REVISION_STALE` with `details.current`; an invalid region answers 422 with `regions.header.…` paths; **an id in both regions answers 422**; an entry token answers 403; an expired session's apply answers 410; `content.manage` required; **`testTwoSessionsDoNotTouchEachOther`** — mint A, apply A; mint B; apply B; A's `snapshot` is unchanged and B's holds B's document.
- [ ] **Step 5.** No changelog bullet yet (R8 carries the feature's). Commit `feat(regions): preview sessions and apply for the regions stage`.

## Task R4: the batch save

**Files:**
- Modify: `core/src/Http/Controllers/RegionAdminController.php` (new `saveAll` action; `update` routed through the same section), `core/routes/admin.php` (`PUT /regions`), `core/src/Http/DTOs/` (`SaveRegionsData.php` new; the per-region DTO gains `expected`)
- Test: `tests/Integration/Http/RegionSaveApiTest.php` (new)

**Interfaces:** Consumes R1 (`within`, `saveExpected`, `RegionVersionConflict`), R2 (`putBaseline`, `clearIfPair`), R3 (`validateBoth`). Produces `PUT /v1/admin/regions` and the conditional per-region update.

- [ ] **Steps 1–4.** Tests:
  - A save of the footer only with both expected versions writes the footer, bumps its version, leaves the header, purges the region cache once (count `RegionUpdated` dispatches), returns both regions with versions.
  - **Complete candidate:** a block moved from header to footer in one save (both posted) succeeds; the same block id posted only in the footer while the stored header still has it answers 422.
  - **Stale unchanged region:** header saved by another request since; a footer-only save with the old header version answers 409 `moved: ['header']` and writes nothing.
  - **The duplicate-id race, sequential form:** request A saves the header adding id `x` with expected `{h:1, f:1}`; request B saves the footer adding `x` with the same expected; B answers 409 (header moved). **Lock form:** inside `RegionWriteLock::within` on the app connection (simulating A mid-section), a second connection's `pg_try_advisory_xact_lock` is false — B cannot validate until A commits (reuse R1's helper).
  - **Clearing:** with a session whose working copy is at `(E, 3)`, a save with `preview_revision {E, 3}` clears it and advances the baseline to the committed regions and versions (`snapshot` → `source: 'baseline'` with the new versions); a save with `{E, 2}` clears nothing (baseline still advanced); a save with `null` clears nothing.
  - **Absent row:** expected `footer: null` with no row creates it; a second save with `footer: null` answers 409 (**Review Focus 1**).
  - The per-region `PUT /regions/{slug}` requires `expected`, checks both, validates the complete candidate, and answers 409 the same way.
  - `content.manage` required; a region token from another session is accepted only for its own clearing (the save's authority is the permission, the token only names which session to clear/advance).
- [ ] **Step 3 detail.** `saveAll` body: `$lock->within(function () use (…) { check both expected vs find(); build candidate = posted over stored; validateBoth(candidate) → 422; foreach posted: saveExpected; return committed snapshot; })`, then after commit: dispatch `RegionUpdated` once, `putBaseline(session, committed, exp)`, `clearIfPair` when `preview_revision` is non-null.
- [ ] **Step 5.** Changelog `### Changed`: "Saving the header and footer checks that neither changed since it was loaded: a save against a region someone else saved answers with a conflict instead of overwriting it." Commit `feat(regions): one batch save checks both regions' versions under the region lock`.

## Task R5: the regions stage render

**Files:**
- Create: `packages/thallo-render/src/Regions/RegionSessionReader.php`, `packages/thallo-render/themes/default/templates/partials/region-stage-placeholder.twig` (the body placeholder, moved from `region-preview.twig`), `packages/thallo-render/themes/default/templates/region-session-expired.twig`
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (annotation scope; `regionBlocks` scoped; empty-region slot in `regions` scope; `region_hidden_override` for the layout), `packages/thallo-render/src/Http/Controllers/RenderController.php` (`preview` branches on `kind === 'regions'`: one `snapshot()` read, the decorator swapped in for the render, `resolveEntry($page)` published, placeholder when none, expired page when `snapshot()` is null, `<html data-thallo-canvas="regions">`; an entry canvas gets `data-thallo-canvas="entry"`), `packages/thallo-render/themes/default/templates/layout.twig` (region inner `slot_attrs`, the hidden override, `data-thallo-canvas`), `packages/thallo-render/runtime/runtime.js` (`isCanvas()` reads `document.documentElement.hasAttribute('data-thallo-canvas')`), `packages/thallo-render/fragments-verified.json` (re-record)
- Test: `tests/Integration/Render/RegionsStageRenderTest.php` (new); existing `PreviewSessionTest`, `RegionRenderingTest`, `CanvasAnnotationTest` (or the annotation tests that exist) stay green unchanged

**Interfaces:** Consumes R2 (`snapshot`), R3 (a session to render). Produces the stage markup (Shared contracts).

- [ ] **Steps 1–4.** Tests:
  - In a region session: header and footer blocks carry `data-thallo-block`, their inners `data-thallo-slot="header"|"footer"`, text `thallo-edit-region`; **the page body carries none of them**; the body is the page's **published** fields while its draft differs.
  - The chrome comes from the session's working copy when present, else the baseline — never the live row: save a different header directly in the DB after mint, render, the baseline shows (**another editor saves between mint and first render**); after R4's save clears the copy, the committed snapshot shows.
  - **One snapshot:** a `RegionSessionReader` spy counts `RegionPreviewStore::snapshot` calls per render — exactly one — and the revision carrier equals the snapshot's pair.
  - Expired session: the render is the expired page and contains no region markup.
  - Empty header in the working copy: the header wrapper renders with an empty `data-thallo-slot="header"` (no fallback chrome); outside a session the fallback chrome renders as today.
  - Hidden regions: a page with `presentation.header: hidden`, `footer: hidden`, and both: the stage renders both; a public render of the same page hides them.
  - `<html data-thallo-canvas>` on a region stage **with both regions empty**, and on an entry canvas; absent on public renders and the review surface.
  - **Review Focus 3:** the picked page's published body holds a block of a type deleted since — the stage renders (missing-template fallback in the body) with the header and footer annotated.
  - Entry isolation: an entry canvas render is byte-identical to before for its body annotation and renders regions unannotated from the saved rows (snapshot a fixture's HTML before the change in the test's setup commit and compare).
  - Runtime: a style proof or a jsdom test that `isCanvas()` is true on `<html data-thallo-canvas>` with no `.thallo-preview-block` present.
- [ ] **Step 5.** Re-record fragments; run style proofs. No changelog bullet (R8's). Commit `feat(render): the regions stage — chrome from the session, annotated; the body inert and published`.

## Task R6: the bridge's region-only mode

**Files:**
- Modify: `packages/thallo-render/assets/preview/preview-bridge.js` (region-only mode: enabled when `document.documentElement.getAttribute('data-thallo-canvas') === 'regions'` — R5 sets the attribute's value to the scope; cancel `click`, `submit`, `auxclick` and `keydown` Enter/Space outside `[data-thallo-slot="header"],[data-thallo-slot="footer"]` in the capture phase, never select there; `wheel`/scroll untouched), `packages/thallo-render/src/Http/Controllers/RenderController.php` (attribute value `entry`|`regions`)
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts` (the existing bridge DOM spec: new cases with uniquely-id'd fixtures)

**Interfaces:** Consumes R5's markup.

- [ ] **Steps 1–4.** Cases: in region-only mode a click on a body link does not navigate (`defaultPrevented`) and posts no `block-select`; a body form's submit is prevented; **Review Focus 4:** a focused body link receiving Enter and a focused body button receiving Space are prevented, while Enter on a focused element inside a header block behaves as today; a wheel event in the body is not prevented; a click on a header block selects it; in entry mode (attribute `entry`) body clicks behave exactly as before (existing cases stay green).
- [ ] **Step 5.** No changelog bullet. Commit `feat(canvas): the stage's region-only mode keeps the page body inert`.

## Task R7: extract the stage editor from the Design page

**Files:**
- Create: `admin/src/editor/stage/useStageEditor.ts`, `admin/src/editor/stage/types.ts`
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` (becomes a host: builds the entry `StageHost` from `mintPreviewData`/`applyPreview`/`useDraft`, `reconcileOnOpen: true`, and keeps the page-only features), `admin/src/queries/preview.ts` (re-export the moved types)
- Test: the existing Design page specs (`canvas-page.spec.ts`, `canvas-revisions.spec.ts`, `canvas-fragments.spec.ts`) **unchanged**, plus `admin/src/__tests__/stage-editor.spec.ts` (new: the composable with a fake host)

**Interfaces:** Produces `useStageEditor`, `StageHost`, `StageEditor` (Shared contracts).

- [ ] **Step 1.** Write `stage-editor.spec.ts` against a fake host: `mint` is called on mount; with `reconcileOnOpen: false`, loading the iframe does **not** call `apply`; with `true`, it applies the hydrated tree once; an edit records history and applies with the drained ops; a 409 `PREVIEW_REVISION_STALE` adopts `details.current` and retries; a 410 re-mints once.
- [ ] **Step 2.** Run it — fails (module missing).
- [ ] **Step 3.** Move the stage-editing state and functions out of `design/[locale].vue` into the composable **verbatim** (the bridge wiring, history, `diffDocuments` watcher, `applyDrop`, `runApply`, `paintStage`, drag coordinator, structure picker, grid fill, selection, edit grant, Outline and Blocks-tab bindings), replacing the entry calls with `host.mint`/`host.apply`/`host.schema`/`host.initial`, and `maybeReconcileStash` with `if (host.reconcileOnOpen) …`. The page keeps: route params, draft hydration and `lock_version`, save/publish, routes, locales, SEO, versions, `_presentation`, restore to draft, the review mint. No behaviour change: move, don't rewrite.
- [ ] **Step 4.** `stage-editor.spec.ts` green; **the three Design page specs green without edits**; `pnpm type-check`, `lint`, `fmt:check`, `pnpm test`; **`cd admin/e2e && pnpm exec playwright test` — all 47 green unchanged.**
- [ ] **Step 5.** No changelog bullet (internal). Commit `refactor(canvas): the stage editor is a composable with a host`.

## Task R8: the Regions page on the stage

**Files:**
- Create: `admin/src/pages/regions/useRegionHost.ts`, `admin/src/pages/regions/components/RegionSettingsTab.vue` (Sticky, Width, region Style — moved from `index.vue`), `admin/src/pages/regions/components/RegionsTopBar.vue`
- Modify: `admin/src/pages/regions/index.vue` (rebuilt: top bar, the four inspector tabs — Block, Blocks, Region, Outline — and the stage iframe `data-test="regions-stage"`), `admin/src/queries/regions.ts` (the new calls; `lock_version`)
- Delete: `admin/src/pages/regions/components/RegionBlockInspector.vue`, `admin/src/__tests__/region-block-inspector.spec.ts` (the off-stage inspector — its Content tab work lives on as the Block tab)
- Test: `admin/src/__tests__/regionsPage.spec.ts` (rewritten), `admin/src/__tests__/region-host.spec.ts` (new)

**Interfaces:** Consumes R3/R4 endpoints, R7 `useStageEditor`. Produces the page.

- [ ] **Steps 1–4.** `region-host.spec.ts`:
  - `toDocument`/`toPayload` round-trip; the synthetic schema carries each region's palette on its root field only.
  - The save baseline is set from the first load and is **not** replaced by a mint response (renewal, page switch); a successful save advances it to the returned versions; Reload replaces it.
  - Save posts dirty regions only, `expected` for both, the accepted `preview_revision`; marks saved **the submitted sequence**; an edit made while the save is in flight stays dirty.
  - **Review Focus 2:** after a save, undo once — the page is dirty again and the next Save sends the region with the advanced versions.
  - 409 `REGION_VERSION_CONFLICT` shows `regions-conflict`; Reload discards edits and loads saved regions and versions.
  - **Restore sequence:** switching page calls mint then apply(whole document, null pair) and swaps the stage only after acceptance (`regions-switching` shown meanwhile); history and dirty kept; a second switch during the first abandons it (the first's responses ignored); renewal after a 410 runs the same sequence.

  `regionsPage.spec.ts`:
  - The top bar's switch sets the current region and **follows the selection** (selecting a footer block switches to Footer).
  - The Blocks tab offers only the current region's palette at the root; inside a container, the container's own rules apply.
  - The Region tab edits Sticky/Width/Style through history (undo reverts a Sticky change).
  - `regions-hidden-notice` shows when the session reports the picked page hides a region.
  - **Review Focus 5:** with unsaved edits, `beforeunload` is prevented (the handler sets `returnValue`), and the in-app guard asks on navigation; a fresh mount after a "reload" starts from the saved regions (the host mints a new session and applies nothing).
- [ ] **Step 5.** Changelog `### Added`: "**The header and footer are edited on the stage.** The Header & footer page shows a real page with its chrome live: click a header or footer block to open its settings, drag to move it, drag new blocks in from the Blocks tab, edit text in place, and undo — as on the Design view. The page body is shown for context and can't be selected. Edits stay yours until Save, which saves both regions at once and says so if someone else saved first." Commit `feat(regions): the header and footer are edited on the stage`.

## Task R9: proofs, removals, docs and gates

**Files:**
- Delete: the old `POST /v1/admin/regions/preview` route and `RegionAdminController::preview`, `packages/thallo-render/themes/default/templates/region-preview.twig`, its tests (`RegionAdminApiTest` preview cases), `usePreviewRegions` in `admin/src/queries/regions.ts`
- Create: `admin/e2e/tests/regions-stage.spec.ts`; the e2e fixture world gains region session/apply/save routes (follow `admin/e2e/helpers.ts` `openDesignPage` / `openRegionsPage` patterns)
- Modify: `admin/e2e/tests/regions-style.spec.ts` (rewritten for the stage: the header's Style lives on the Region tab; a block's settings open by selecting it on the stage), `docs/reference/` region docs and `packages/thallo-render/docs/THEMING.md` (the stage, `data-thallo-canvas`, the region slots), `docs/internal/superpowers/specs/2026-07-04-global-regions-design.md` (a one-paragraph amendment pointing at the new spec)
- Test: e2e

- [ ] **Steps 1–4.** `regions-stage.spec.ts`: selecting a header block on the stage opens its Block tab; drag reorder in the header; a block dragged from the Blocks tab into the footer; in-place text edit in a header block; undo; Save (the fixture receives one `PUT /regions` with both `expected`); **a click on a body link navigates nowhere and selects nothing**; a block not allowed at a region's root is refused while accepted inside a container that allows it; **both regions empty still loads as a stage** (`data-thallo-canvas` present, slots marked); **switching pages with unsaved edits and no further edit shows those edits on the new stage**. Then: the whole e2e suite green (the Design view's specs unchanged).
- [ ] **Step 5.** Full gates: `composer test` (after `test:migrate`), `phpcs` (exit code), `boundaries`, fragments re-recorded, style proofs, admin type-check/lint/fmt/vitest/e2e. Changelog `### Removed`: "The Header & footer page's separate preview (`POST /v1/admin/regions/preview` and `region-preview.twig`): the stage replaces it." Commit `chore(regions): the stage replaces the old region preview; proofs and docs`.

**Beta cut:** after R9, when the user asks — the release process in `docs/internal/RELEASING.md` (changelog cut commit, gates, `scripts/release-bake`, release commit, `scripts/verify-dist-archive`); split, tags and pushes are the user's.

## Self-review

- **Spec coverage.** §2 decisions → R2–R8; §3 layout → R8; §4.1 session and initialisation → R2, R3; §4.2 baseline, working copy, expiry → R2 (store, clock test), R5 (never live rows, expired page); §4.3 apply → R3; §4.4 render (snapshot, scope, slots, hidden, empty, marker, carrier, no page) → R5; §4.5 batch save, writer inventory, version contract, exact clearing, per-region endpoint → R1, R4; §4.6 refresh → R7 (unchanged path) and R5 (carrier); §4.7 isolation → R2 (token classes), R3 (403), R5 (entry byte-identical); §5.1 document and palettes → R8; §5.2 shared stage code → R7; §5.3 save baseline, save, restore sequence, leaving → R8; §5.4 inert body → R6; §6 edge cases → R1, R4, R5, R8; §7 tests → each task; §8 rollout → R9.
- **Placeholders.** None: every task names its files, tests and the behaviour each test pins; R1's lock is given in code; R7 is a move with named boundaries.
- **Type consistency.** `RegionWriteLock::within/key`, `saveExpected`, `RegionVersionConflict::$moved`, `RegionPreviewToken::mint/verify`, `RegionPreviewStore::putBaseline/baseline/accept/current/snapshot/clearIfPair`, `PreviewSession::$kind/$session/$page`, `RegionValidator::validateBoth`, `setAnnotationScope`, `RegionSessionReader`, `StageHost`/`useStageEditor`, `useRegionHost` — used with the same names and shapes in every task that consumes them.
- **Review Focus.** Five lines, each with its test: 1 → R1 and R4; 2 → R8; 3 → R5; 4 → R6; 5 → R8.
