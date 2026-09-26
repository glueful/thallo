# Type Layouts — Release A (engine and single entries) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An editor designs one layout per content type on a stage — field blocks for the entry's title, date, cover, excerpt, terms and fields, and a slot for its own body — saves it once, and every entry of that type renders through it; the entry's Design view shows the layout inert around the editable body.

**Architecture:** Layouts are stored block documents (`layouts` table, tombstoned on removal so a version never goes backwards) read through one cached `LayoutResolver` behind a contract the render pack consumes. A fourth annotation scope and a third preview-session kind (`layout`) put a layout on the stage the way the Regions stage puts the chrome there, reusing its baseline / working copy / Save contract through a store base class extracted from `RegionPreviewStore`. Rendering asks the resolver before the template hierarchy; a per-surface frame template renders the layout, and two render helpers decide which blocks the stage may select — the layout's own on the layout stage, the entry's slot content on the entry stage — with the nesting depth restarted inside a slot.

**Tech Stack:** PHP 8.3 (Glueful, PostgreSQL, PHPUnit), Twig 3 (render pack), Nuxt UI admin (Vue 3, vitest, Playwright e2e in `admin/e2e`).

**Spec:** `docs/internal/superpowers/specs/2026-09-26-type-layouts-design.md`, approved for Release A at `a1993841` (two review rounds folded in). **Plan amended after review (2026-09-26):** transaction boundaries for save/remove (L7) and content-model changes (L8); schema-driven starters for every supported type shape (L2, L4, L6); the form-source contract change (L5); effective layout status after every accepted apply (L10); real two-process lock proofs (L1, L6, L8); Remove only inside the editor (L9); deleted types leave the list (L8). **Second amendment:** the lock proofs use the Regions arrangement exactly — the parent holds the lock, the child writes, the parent sees the child's pid waiting, then commits (L1, L8); post-commit effects are deferred with `Connection::afterCommit()` so they run after the **outermost** commit (L7, L8); the accepted-apply connection runs through the shared editor (L10); the starter's article parts are conditional (L2). **Third amendment:** L5 also fixes the header/footer form attribution defect — form source precedence becomes layout → region → entry → route → fallback. Section numbers below (§n) are the spec's. Release A is §11 item 1; listings (B), product (C1) and shop pages (C2) are later plans.

## Rulings made while planning (from the code)

- **Content-type slugs are immutable** (`ContentTypeRepository::updateMeta` has no slug; the content-type API offers no rename). §5.7's "renaming a content type rewrites the target" therefore has no event to hook; a type is only ever **deleted** (`softDelete`), which tombstones its layouts (L8). Cost if wrong: one listener when a rename lands.
- **Field renames and deletions** happen only through `Thallo\Core\Content\Services\MigrationService::migrate()` (`RenameField`, `DeleteField`), which commits in `MigrationRepository::recordAndFlip()`'s transaction. The direct `ContentTypeRepository::updateSchema()` already refuses deletion, rename and retyping — that restriction is preserved and asserted, not a second path (L8).
- **Form identity needs a contract change:** `FormSealer::describe()` has no layout argument and `FormSourceIdentity::resolve()` gives the entry precedence over the region — so today a header or footer form is attributed to whichever page it appears on. L5 adds a layout source and **fixes that defect in the same change**: precedence becomes **layout → region → entry → route → fallback**. `entry_slot()` clears the layout source; region rendering never inherits it. Forms rendered before the release keep their sealed `form_key` (the descriptor carries it), so their tokens still submit and stored submissions keep their attribution; newly rendered header and footer forms get their region's identity.
- **The layout-only guard lives in `FieldValidator`** (every entry, region and saved-section save already passes through it) behind a block-type flag `layout_only`, allowed only for a validator explicitly built for layouts (L3).
- **Fragments are turned off in `PreviewFragments`** (render pack) for an entry whose type has a layout, through the contract — the admin needs no change for the whole refresh, because a null `fragments` already means "refresh whole" (L5).

## Global Constraints

- **A surface with no layout renders exactly as today** (§ header, §7.2). The homepage route never consults layouts (§1).
- **Save goes live; there is no overwrite** — a stale save is 409 and the admin offers Reload only (§2.4, §5.5).
- **Order on Save: commit → install the committed baseline → clear the working copy on the exact pair** (§5.5). **Preview state, caches and purges change only after the outermost database transaction has committed** — they are registered with `Connection::afterCommit()` from inside the transaction, so they run after the outermost commit and are discarded by any rollback, including one of an outer transaction the caller opened; a rolled-back save or remove leaves the session's baseline and working copy untouched.
- **Lock order, everywhere:** the type lock (`LayoutWriteLock::withinType`) before the layout lock (`within`), never the reverse; a content-type migration takes the type lock.
- **A removal retires its editing session;** retirement and `accept()` take the same store lock, so an apply either completes before retirement (and its working copy is then deleted) or runs after it and answers 410 — it can never recreate the working copy (§5.5).
- **`lock_version` never goes backwards:** removal tombstones; a missing row is version 0 (§5.1).
- **Targets are never null:** a type slug in Release A (`{type}:{field}` and `@site` come with B and C) (§5.1).
- **Permission:** session, apply, save and remove require `templates.manage`; opening the Layouts page and reading rows require `content.view` (§9).
- **Layout-only blocks are refused in entries, regions and saved sections by the server** (§5.6).
- **Slot content is the entry's:** annotated only on the entry's stage, never on the layout's; depth restarts at the slot (§4.1, §5.4).
- **An entry with a custom layout gets no fragments;** every other entry patches as today (§6.3).
- **Every eligible entry page carries `thallo:layout:entry:{type}`** whether or not a layout exists (§7.4).
- **Pack boundaries:** the render pack reaches layouts only through `Thallo\Contracts\Layouts\*`; `composer boundaries` stays green.
- **PostgreSQL-only** (advisory locks, the expression index).
- **Entry sessions and region sessions behave exactly as today** for types without layouts; their tests pass unchanged.
- **No compatibility shims:** a replaced API is replaced at every caller in the same task.
- **Every task's gates pass at its own commit;** each commit carries its CHANGELOG bullet under `## [Unreleased]` (re-add the heading if a cut removed it).
- Gates, run foreground and never concurrently: `COMPOSER_PROCESS_TIMEOUT=0 composer test` (run `composer test:migrate` first after a migration), `vendor/bin/phpcs` (check the **exit code**), `composer boundaries`; after any theme template change re-record `THALLO_RECORD_FRAGMENT_VERIFICATION=1 vendor/bin/phpunit tests/Integration/Render/FragmentVerificationTest.php` and, after blocks.css changes, `php scripts/build-containment-inventory`; admin — `pnpm exec oxfmt <touched files>`, `pnpm type-check`, `pnpm lint`, `pnpm exec vitest run`, and the e2e suite (`php scripts/build-builder-proof-fixtures` with `DB_PGSQL_DATABASE=app_test APP_ENV=testing CACHE_DRIVER=array`, then `cd admin/e2e && pnpm test`) for any admin change.
- New template functions join `TemplatePolicy::FUNCTIONS` (bump `CACHE_VERSION`, update the pinned version in `BlocksRenderingTest`) **and** `admin/src/pages/templates/components/twigCompletions.ts`.
- New block types: bump the fixed count in `SeedBlockTypesTest`, add sample data in `StarterTemplatesTest`.
- `git diff` before every commit; no AI attribution trailers; never push, never tag. One release, one beta cut at the end (the cut runs `php scripts/sync-docs-changelog`); split, tags and pushes are the user's.

**Steps 1–4**, wherever a task says so, are the TDD cycle: write the failing tests named in the task, run and watch each fail for the stated reason, implement the minimum, run the file and the task's gates green. Step 5 is the commit with its changelog bullet.

## Review Focus

The inputs the spec implies but no requirement names, most likely to bite first. Each has its test in the owning task.

1. **The same editor with the surface open in two browser tabs** (two sessions): a Save in one makes the other's next Save a 409, and its Reload shows the saved layout, not the starter — Task L7.
2. **A form block placed in a layout** renders on every post of the type: its submissions must share one source per layout, not one per post and not the entry's — Task L5.
3. **A theme that ships no `layouts/entry.twig`** (every theme made before this release): the default theme's frame renders through the per-file fallback, with the theme's own `layout.twig` around it — Task L5.
4. **An entry in a non-default locale:** field blocks show the localized title and date, and the terms link to that locale's archive paths — Task L5.
5. **A post whose body holds a block type since switched off or removed:** the layout still renders around the missing-template fallback on the site and on both stages, and the layout stays editable — Task L6.

---

## Shared contracts (named once, used by every task)

**Contracts (`packages/thallo-contracts/src/Layouts/`)**

- `LayoutReader` — `for(string $surface, string $target): ?array` returning `{blocks: list<array>, settings: array{width?: 'contained'|'full', header?: 'default'|'hidden', footer?: 'default'|'hidden'}, lock_version: int}` or null (no layout, or a tombstone). Core binds `LayoutResolver`; the render pack takes it nullable (soft-bound: null reader ⇒ no layouts).
- `LayoutStageSnapshots` — `snapshot(string $session): ?array` returning `{source: 'working'|'baseline', layout: {blocks, settings}, lock_version: int, epoch: ?string, revision: ?int, retired: bool, surface: string, target: string, sample: ?string}`; null when the session's records are gone.
- `LayoutSurface` (interface) and `LayoutSurfaceRegistry` (interface: `get(string $key): ?LayoutSurface`, `all(): list<LayoutSurface>`). `LayoutSurface`:
  - `key(): string`, `label(string $target): string` ("Posts — single post")
  - `targets(): list<array{target: string, label: string, enabled: bool, reason: ?string}>`
  - `samples(string $target, ?string $query): list<array{id: string, label: string}>` (published only, up to 50), `defaultSample(string $target): ?string`
  - `placeholder(string $target): array` — the in-memory sample context (§5.2)
  - `palette(): list<string>` — field block slugs this surface adds
  - `required(string $target): list<array{type: string, field?: string}>`
  - `bindable(string $target): array<string, string>` — field name ⇒ field type, for binding checks
  - `frame(): string` (template name), `starter(string $target): list<array>` (a block tree without ids)
  - `reach(string $target): string` ("Applies to every post")

**Core (`core/src/Content/Layouts/`, namespace `Thallo\Core\Content\Layouts`)**

- Table `layouts` (migration `037_CreateLayoutsTable`): `id` (12), `tenant_uuid` (nullable, indexed), `surface` (40), `target` (160, not null), `blocks` (json, **nullable — null is a tombstone**), `settings` (json), `lock_version` (int, default 0), `updated_by` (12, nullable), `created_at`, `updated_at`; unique index `uniq_layouts_subject` on `(COALESCE(tenant_uuid, ''), surface, target)` created with raw SQL (`CREATE UNIQUE INDEX … ON layouts ((COALESCE(tenant_uuid, '')), surface, target)`), and the widened-tenancy `NOT NULL` block as `034_CreateSavedSectionsTable` has. `ThalloTenantTables` owns it; `AppTestCase::TABLES` truncates it.
- `LayoutWriteLock` — `within(string $surface, string $target, callable $fn): mixed`: opens a transaction when none is open (and commits it when `$fn` returns, rolls back when it throws), always takes `pg_advisory_xact_lock(key)` with `key = crc32("thallo:layouts:{$tenantSegment}:{$surface}:{$target}") & 0x7FFFFFFF`; `withinType(string $typeSlug, callable $fn): mixed` — the same, keyed `thallo:layouts:type:{$tenantSegment}:{$typeSlug}`, taken by a layout save/remove on the `entry` surface (before `within`) and by `MigrationService::migrate` for that type; `isHeld(string $surface, string $target): bool` and `isTypeHeld(string $typeSlug): bool` via `pg_locks` (as `RegionWriteLock::isHeld`). **Returning from `within` means committed only when `within` opened the transaction;** inside a caller's outer transaction it does not. So no caller changes preview state, caches or purges after `within` returns: every such effect is registered with `$db->afterCommit(fn)` **inside** the callback, and Glueful runs it after the outermost commit and discards it on rollback. `within` itself registers nothing.
- `LayoutVersionConflict` (exception) — `public readonly int $current`.
- `LayoutRepository`:
  - `find(string $surface, string $target): ?array` — `{surface, target, blocks: ?list, settings: array, lock_version: int, updated_by: ?string, updated_at: ?string}`; a tombstone has `blocks === null`.
  - `version(string $surface, string $target): int` — the row's `lock_version`, 0 for no row.
  - `saveExpected(string $surface, string $target, array $blocks, array $settings, int $expected, ?string $by): int` — asserts `LayoutWriteLock::isHeld`; `expected === 0` inserts (a unique violation or an existing row ⇒ `LayoutVersionConflict`); otherwise updates `WHERE lock_version = expected` (a tombstone row included, resurrecting it); returns the new version.
  - `tombstone(string $surface, string $target, int $expected, ?string $by): int` — same guard; sets `blocks = null`, bumps; returns the new version.
  - `live(): list<array>` — non-tombstone rows (for `LayoutsSource`), `persistBlocks(string $surface, string $target, int $expected, array $blocks): bool` — conditional, bumps.
  - `forType(string $typeSlug): list<array>` — rows whose surface is `entry` and target the slug (later releases widen it).
- `LayoutResolver implements LayoutReader` — reads through `LayoutRepository::find`, caching `{none: true}` or the layout under `{tenant}thallo:layout:{surface}:{target}` (TTL 3600); `forget(string $surface, string $target): void` deletes the key. **Every writer calls `forget` after committing** (save, remove, `LayoutsSource::persist`, `LayoutBindings`).
- `LayoutSurfaces implements LayoutSurfaceRegistry` — core registers `EntrySurface`; packs contribute by tag in later releases.
- `EntrySurface implements LayoutSurface` — key `entry`; targets = publicly delivered, **active** content types (a soft-deleted type is not a target); `required()` = `[{type: 'entry_content', field: <primary body>}]` when the type has a blocks field (primary body: the blocks field named `body`, else the first blocks field in schema order), `[]` otherwise; `bindable()` from the type schema; `frame()` = `layouts/entry.twig`; **`starter()` is built from the schema.** A type is **article-like** when it has a filterable reference field **or** a plain-text field named `excerpt` or `summary`. The starter is, in order: `entry_terms` (article-like only: the first filterable reference field, badges); `entry_title` h1 (any type with a `title` string field); `entry_date` (article-like only); `entry_excerpt` (article-like only, that field); `entry_cover` (article-like only, the first asset field when there is one); then the **content** — `entry_content` naming the primary body when there is a blocks field, otherwise `entry_field` (format rich) naming the first rich-text field, otherwise nothing; then `entry_related` (article-like only). So the seeded post gets the post design and the seeded page **exactly** `[entry_title h1, entry_content body]`, and any other shape a starter that validates (L4 proves every shape); `placeholder()` = `{fields: {title: 'Sample {singular}', …schema defaults, blocks fields: []}, published_at: today, placeholder: true}`.
- `LayoutValidator` — `validate(string $surface, string $target, array $blocks, array $settings): array{blocks, settings}` throws `ValidationException` (dot paths `blocks.3.data.field`, `settings.width`) (§5.6).
- `LayoutBindings` — `renameField(string $typeSlug, string $from, string $to): void`, `boundTo(string $typeSlug, array $fields): array<string, list<string>>` (field ⇒ layout labels using it), `tombstoneType(string $typeSlug): void`; `LayoutBindingConflict` (exception, `public readonly array $bound`).
- `Thallo\Core\Content\Blocks\Sources\LayoutsSource` — ID `layout`; documents `{blocks}` per live row, revision `lock_version`, persist through `persistBlocks` then `LayoutResolver::forget`.

**Preview (core/src/Content/Preview)**

- `SessionDocumentStore` (abstract, **extracted** from `RegionPreviewStore`: `putBaseline`, `baseline`, `accept`, `current`, `snapshot`, `clearIfPair`, keys `{tenant}thallo:preview:{namespace}:baseline:{s}` / `{tenant}thallo:preview:working:{namespace}:{s}`), with `abstract protected function namespace(): string`. `RegionPreviewStore extends SessionDocumentStore` (namespace `regions`, keys unchanged). `LayoutPreviewStore extends SessionDocumentStore` (namespace `layout`) adds `retire(string $s, int $exp): void` (replaces the baseline with `{retired: true}` and deletes the working copy, under the same lock `accept` takes) and `isRetired(string $s): bool`; its `accept` answers `{accepted: false, retired: true, …}` for a retired session.
- `LayoutPreviewToken` — claims `{k: 'layout', s, u (surface), t (target), x (sample|null), l, exp}`; `mint(string $session, string $surface, string $target, ?string $sample, string $locale, int $exp, string $key): string`; `verify(string $token, string $key, int $now): self` (requires `k === 'layout'`, `s`, `u`, `t`, `l`, `exp`). An entry token and a regions token fail its `verify`; it fails theirs.
- `PreviewSession` gains `KIND_LAYOUT = 'layout'` and `public readonly ?string $surface = null`, `?string $target = null`, `?string $sample = null` (defaults keep every existing constructor call valid). `EnginePreviewSessionVerifier::verify` tries entry, then regions, then layout.
- **`PreviewSession` consumer inventory** (each gets an explicit kind check in L6): `RenderController::session()`/`home()`/`page()` — no session; `RenderController::preview()` — the layout stage; `themedEnv()` — no per-preview theme; `EnginePublicRouteResolver` overlays — ignore; `PreviewReader::readVerified()` — refuses; `EntryController::applyPreview`, `PreviewFragments`, `resolvePreview` — refuse by construction (they parse with `PreviewToken::verify`), asserted by test; `RegionPreviewController::apply` — refuses (it parses with `RegionPreviewToken::verify`), asserted by test.

**Endpoints (`core/routes/admin.php`, beside the region routes)**

- `GET /v1/admin/layouts` (`content.view`) → `{layouts: [{surface, target, label, reach, state: 'theme'|'custom', enabled, reason, lock_version, updated_by, updated_at}]}`.
- `GET /v1/admin/layouts/{surface}/{target}/samples?q=` (`templates.manage`) → `{samples: [{id, label}], default: ?string}`.
- `POST /v1/admin/layouts/preview/session` (`templates.manage`) body `{surface, target, sample?}` → `{token, expires_at, expires_in, theme_url, epoch: null, revision: null, layout: {blocks, settings, lock_version}, starter: bool, required: [...], palette: [...], sample: ?{id, label}, placeholder: bool}`.
- `POST /v1/admin/layouts/preview/apply` (`templates.manage`) body `{token, layout: {blocks, settings}, epoch, base_revision, operations}` → `{epoch, revision, baseline, style_generation, applied_at, fragments: null}`; 409 `PREVIEW_REVISION_STALE` (`details.current`); 410 `LAYOUT_SESSION_EXPIRED` (records gone) or `LAYOUT_SESSION_RETIRED`; 422 dot paths.
- `PUT /v1/admin/layouts/{surface}/{target}` (`templates.manage`) body `{token, layout: {blocks, settings}, expected_lock_version, preview_revision: {epoch, revision}|null}` → `{layout: {blocks, settings, lock_version}, preview_cleared}`; 409 `LAYOUT_VERSION_CONFLICT` (`details.current`); 422.
- `DELETE /v1/admin/layouts/{surface}/{target}` (`templates.manage`) body `{token, expected_lock_version}` → `{lock_version}`; 409 as above.
- The entry preview mint (`POST /entries/{uuid}/preview/{locale}`) response **and the entry preview apply response** (`POST /entries/{uuid}/preview/{locale}/apply`) gain `layout: {surface: 'entry', target, label} | null` — the **effective** status for the document just accepted (the type has a layout and the accepted `_presentation.use_layout` is not false). The Design view takes it from every accepted apply, not only from the mint (L10).

**Render (`packages/thallo-render`)**

- `RenderContextExtension::setAnnotationScope()` accepts `'layout'` (four values); `is_canvas()` true for any non-`none` scope; `<html data-thallo-canvas="layout">`.
- New Twig functions (sandbox-allowed): `layout_blocks(list)` — renders a layout's own blocks, annotated **iff** scope is `layout`; `entry_slot(field)` — renders `entry.fields[field]`, annotated **iff** scope is `entry`, with `slot_attrs(field)` on its wrapper in that scope, the depth saved, set to 0, and restored after. Everywhere else a layout's blocks are rendered only through these two.
- **Form source (contracts + core):** `Thallo\Contracts\Content\FormSealer::describe(array $block, ?array $entry, ?string $currentPath, ?string $regionSlug, ?string $layoutSource = null): ?object`; `DefaultFormSealer` passes it on; `Thallo\Core\Content\Forms\FormSourceIdentity::resolve(?array $entry, ?string $regionSlug, ?string $currentPath, ?string $layoutSource = null): string` returns, in order: `'layout:' . $layoutSource` when given (e.g. `layout:entry:post`); `'region:' . $regionSlug` when given; `'entry:' . uuid`; `'route:' . path`; `'theme:path:/'`. (The region-before-entry order is the defect fix.) `regionBlocks()` renders a region's blocks with `layout_source` removed from the context, so chrome never inherits a layout's identity. `layout_blocks()` puts `layout_source` (`{surface}:{target}`) into the context it hands the layout's blocks; `blocks()` threads `layout_source` through to nested blocks as it threads `region_slug`; `entry_slot()` renders the entry's blocks with `layout_source` **removed**, so a form in the body keeps `entry:{uuid}`; the `form` block template passes `layout_source` to `form_render`.
- `RenderController`: `renderEntry` asks `layoutFor(typeSlug, presentation)` (the reader, `use_layout` honoured) before the template hierarchy; with a layout it renders `layouts/entry.twig` with `layout` in context and applies the frame precedence (§6.4); every entry render of a supported type gets `Cache-Tag: thallo:layout:entry:{type}`; `preview()` routes a `layout` session to `layoutStage()`.
- `Thallo\Render\Layouts\LayoutSessionReader implements LayoutReader` — answers the snapshot's layout for its `(surface, target)`, delegates the rest.
- Frame `themes/default/templates/layouts/entry.twig`; field block templates `themes/default/templates/blocks/entry_{title,date,cover,excerpt,terms,field,content,neighbours,related}.twig`; styles in `themes/default/assets/blocks.css`.
- Placeholder notice on the stage: `<div class="thallo-layout-placeholder-notice" data-thallo-placeholder>` inside the frame, rendered only in `layout` scope when the sample is a placeholder.

**Admin**

- `admin/src/queries/layouts.ts`: `useLayouts()`, `fetchLayoutSamples(surface, target, q)`, `mintLayoutSession(surface, target, sample?)`, `applyLayout(token, fields, options)`, `saveLayout(surface, target, body)`, `removeLayout(surface, target, body)`; types `LayoutRow`, `LayoutSession`.
- `admin/src/pages/layouts/useLayoutHost.ts` — the `StageHost` (schema: one root `blocks` field named `blocks` whose `blockTypes` is `session.palette ∪ the general content blocks`; `_layout_settings` document key for the Frame tab; `toDocument`/`toPayload`; `reconcileOnOpen: false`; `renew` = the Regions restore sequence; `save()`, `remove()`, `switchSample(id)` guarded by a generation number).
- Pages: `admin/src/pages/layouts/index.vue` (the list), `admin/src/pages/layouts/[surface]/[target].vue` (the editor). Module `admin/src/registry/layoutsModule.ts` — Site item **Layouts** (`/layouts`, icon `i-lucide-layout-template`, requires `thallo.render`), slotted after `regionsModule` in `manifest.ts`.
- `BlocksPalette` gains `leadCategory?: string` (that category's tiles first; the layout editor passes `'Fields'`).
- **Remove lives only in the editor** (the DELETE needs the editor's session token and version); the list offers Edit.
- Test ids: `layouts-list`, `layouts-row-<surface>-<target>`, `layouts-edit-<surface>-<target>`, `layout-sample-picker`, `layout-placeholder-notice`, `layout-save`, `layout-reach`, `layout-undo`, `layout-redo`, `layout-conflict`, `layout-conflict-reload`, `layout-menu`, `layout-reset-starter`, `layout-remove`, `layout-remove-confirm`, `layout-retired`, `layout-stage`, `layout-tab-frame`; Design view `design-layout-strip`, `design-layout-edit`, `page-use-layout`.

---

## Task L1: storage, the write lock and the repository

**Files:**
- Create: `core/database/migrations/037_CreateLayoutsTable.php`, `core/src/Content/Layouts/LayoutWriteLock.php`, `core/src/Content/Layouts/LayoutVersionConflict.php`, `core/src/Content/Layouts/LayoutRepository.php`
- Modify: `packages/thallo-tenancy/src/ThalloTenantTables.php` (`'layouts' => self::row($def)`), `tests/Unit/Tenancy/ThalloTenantTablesTest.php`, `tests/Support/AppTestCase.php` (`TABLES`), `core/src/Providers/CoreServiceProvider.php` (register lock and repository, shared, autowire)
- Test: `tests/Integration/Content/Layouts/LayoutRepositoryTest.php`

**Interfaces:** Produces `LayoutWriteLock`, `LayoutRepository`, `LayoutVersionConflict` (Shared contracts).

- [ ] **Step 1: failing tests** in `LayoutRepositoryTest`:
  - `testAFirstSaveCreatesAtVersionOneAndASecondFirstSaveConflicts` — `saveExpected('entry','post',…, 0)` inside `within` returns 1; a second `saveExpected(…, 0)` throws `LayoutVersionConflict` with `current === 1`.
  - `testConcurrentFirstSavesResolveToOneRow` — the Regions arrangement exactly (`RegionWriteLockTest::testConcurrentWritersQueueBeforeReadingAndValidating`): the **parent** enters `within('entry','post')` and saves with expected 0; still inside, it starts the **child** writer (`proc_open`, the same helper) that attempts `saveExpected(…, 0)` for the same subject and reports ready with its backend pid; the parent polls `pg_locks` until **the child's pid** is waiting (`NOT granted`) on the advisory key, then returns from `within` and commits; the child then answers `LayoutVersionConflict` with `current === 1`. Exactly one row.
  - `testWithinCommitsOnReturnAndRollsBackOnThrow` — a save inside `within` whose callback then throws leaves no row, visible from a second connection; a save that returns is visible to a second connection as soon as `within` returns.
  - `testSaveNeedsTheExpectedVersion` — expected 1 on a version-2 row throws with `current === 2`, row unchanged.
  - `testRemovalTombstonesAndTheVersionContinues` — tombstone at 1 → 2, `find()['blocks'] === null`; `saveExpected(…, 2)` → 3 with blocks; `saveExpected(…, 0)` throws (the row exists).
  - `testOneRowPerSubjectEvenWithoutATenant` — raw insert of a second `(NULL tenant, 'entry', 'post')` row violates `uniq_layouts_subject`.
  - `testWritesRefuseOutsideTheLock` — `saveExpected` outside `within` throws `LogicException`.
- [ ] **Step 2:** run `vendor/bin/phpunit tests/Integration/Content/Layouts/LayoutRepositoryTest.php` — fails (no table/classes).
- [ ] **Step 3:** implement per Shared contracts; `composer test:migrate`.
- [ ] **Step 4:** the file green; `ThalloTenantTablesTest` green; phpcs.
- [ ] **Step 5:** commit `feat(layouts): storage with tombstones, one write lock per layout` (changelog: none yet — the feature bullet lands with L9).

## Task L2: contracts, the resolver and the entry surface

**Files:**
- Create: `packages/thallo-contracts/src/Layouts/{LayoutReader,LayoutStageSnapshots,LayoutSurface,LayoutSurfaceRegistry}.php`, `core/src/Content/Layouts/{LayoutResolver,LayoutSurfaces,EntrySurface}.php`, `core/src/Content/Layouts/Starters.php` (the post and page starter trees)
- Modify: `core/src/Providers/CoreServiceProvider.php` (bind `LayoutReader` → `LayoutResolver`, `LayoutSurfaceRegistry` → `LayoutSurfaces`)
- Test: `tests/Integration/Content/Layouts/LayoutResolverTest.php`, `tests/Integration/Content/Layouts/EntrySurfaceTest.php`

**Interfaces:** Consumes L1. Produces the contracts, `LayoutResolver::for/forget`, `EntrySurface`.

- [ ] **Step 1: failing tests:**
  - `LayoutResolverTest::testNoneIsCachedAndAFirstSaveIsFoundAfterForget` — `for()` null; a direct repository save; `for()` still null (cached none); `forget()`; `for()` returns the layout. `testATombstoneReadsAsNone`.
  - `EntrySurfaceTest::testTargetsAreThePubliclyDeliveredTypes` (a non-public type absent; a soft-deleted type absent); `testThePrimaryBodyIsRequiredOnce` (a type with `body` and `sidebar` blocks fields ⇒ `[{entry_content, body}]`; a type whose only blocks field is `content` ⇒ that field; a type with a rich-text body and no blocks field ⇒ `[]`); `testTheStarterFollowsTheSchema` over five type shapes built in the test — the seeded post (terms, title, date, excerpt, cover, body slot, related), the seeded page — pinned **exactly**: `[{type: entry_title, data: {level: h1}}, {type: entry_content, data: {field: body}}]`, no date — **a type whose only blocks field is `content`** (`entry_content` names `content`), **a type with a rich-text `body` and no blocks field** (`entry_field` rich, no slot), **a type with neither, not article-like** (title only) — asserting each tree; that each validates is L4's, that each opens through the real mint is L6's; `testThePlaceholderWritesNothing` (entries count unchanged; `placeholder: true`).
- [ ] **Step 2:** run both files — fail.
- [ ] **Step 3:** implement. Starters use only field block slugs defined in L3 by name (strings), so L2 does not depend on L3's code.
- [ ] **Step 4:** green; `composer boundaries` (contracts only in the render pack's future use).
- [ ] **Step 5:** commit `feat(layouts): the layout reader, resolver cache and the entry surface`.

## Task L3: field blocks, the layout render helpers and the layout-only guard

**Files:**
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php` (nine types, category `Fields`, flag `layout_only: true`: `entry_title` {level enum h1–h4, link bool}, `entry_date` {format enum long|short|relative, prefix string}, `entry_cover` {field string, aspect enum natural|16:9|4:3|1:1, link bool}, `entry_excerpt` {field string, clamp number}, `entry_terms` {field string, style enum text|badges, link bool}, `entry_field` {field string, format enum text|rich|number|date}, `entry_content` {field string}, `entry_neighbours` {previous_label string, next_label string}, `entry_related` {count number 1–6, style enum list|cards}; style capabilities as `heading`/`image`/`rich_text` for the matching kinds), `core/src/Content/Blocks/BlockTypeRepository.php` (`FLAGS` gains `layout_only`; `layoutOnlySlugs(): list<string>`), `core/src/Content/Validation/FieldValidator.php` (`validateBlocks` rejects a `layout_only` type with `"'{type}' belongs to layouts"` unless the instance was made by `forLayouts(): self`), `packages/thallo-render/src/RenderContextExtension.php` (`layout_blocks`, `entry_slot`, scope `layout`), `packages/thallo-render/src/Templates/TemplatePolicy.php` (functions, `CACHE_VERSION` 29), `admin/src/pages/templates/components/twigCompletions.ts`, `admin/src/editor/palette/BlocksPalette.vue` (`leadCategory`)
- Create: `packages/thallo-render/themes/default/templates/blocks/entry_*.twig` (nine), CSS in `themes/default/assets/blocks.css`
- Test: `tests/Integration/Render/FieldBlocksRenderTest.php`, `tests/Integration/Render/LayoutRenderHelpersTest.php`, `tests/Integration/Content/LayoutOnlyBlocksTest.php`, admin `src/__tests__/blocks-palette-lead-category.spec.ts`; update `SeedBlockTypesTest` (+9), `StarterTemplatesTest`, `BlocksRenderingTest` (28→29), `BlockStyleDeclarationsTest` unaffected (isolation count note)

**Interfaces:** Produces the field block slugs, `layout_blocks()`, `entry_slot()`, `FieldValidator::forLayouts()`, `BlocksPalette.leadCategory`.

- [ ] **Step 1: failing tests:**
  - `FieldBlocksRenderTest` — each field block against a context `entry` (title, `published_at`, cover blob, excerpt, a category reference, a number field): markup and classes; an empty field renders nothing outside a canvas and a muted placeholder naming the field (`thallo-field-empty`) in `layout` scope; `entry_terms` links through `type_listing.archives[field]` when present, plain text otherwise; `entry_related` excludes the entry and lists at most `count`.
  - `LayoutRenderHelpersTest` — scope `layout`: `layout_blocks` annotates the layout's blocks, `entry_slot('body')` does not annotate the entry's; scope `entry`: the reverse, and `entry_slot` emits `data-thallo-slot="body"`; scope `none`: neither; **depth:** an entry body at `BlockDepth::MAX` renders whole through `entry_slot` placed four containers deep in the layout (and the same body placed through plain `blocks()` at that depth is cut — proving the reset is what saves it); the depth after `entry_slot` is restored.
  - `LayoutOnlyBlocksTest` — an entry draft, a region save (`RegionValidator`) and a saved section each containing `entry_title` are refused with the path of the block; `FieldValidator::forLayouts()` accepts it.
  - Admin: `leadCategory: 'Fields'` puts the Fields tiles first; absent, the order is unchanged.
- [ ] **Step 2:** run each — fail.
- [ ] **Step 3:** implement. `entry_content`'s template is `{{ entry_slot(data.field|default('body')) }}` inside its root; `entry_slot` returns `''` when the field is not a list. The palette hides `layout_only` types unless the page asks for them (the layout editor passes the session's palette) — `paletteTypes` filtering in `useStageEditor` gains `allowLayoutOnly: boolean` from the host (default false).
- [ ] **Step 4:** green; re-record fragments; containment inventory; admin gates.
- [ ] **Step 5:** commit `feat(layouts): field blocks, the layout render helpers and the layout-only guard`.

## Task L4: the layout validator

**Files:**
- Create: `core/src/Content/Layouts/LayoutValidator.php`
- Test: `tests/Integration/Content/Layouts/LayoutValidatorTest.php`

**Interfaces:** Consumes L2 (`LayoutSurface::required/bindable/palette`), L3 (`FieldValidator::forLayouts()`), `StyleClassReferenceGuard`. Produces `LayoutValidator::validate`.

- [ ] **Step 1: failing tests:** `testEveryStarterShapeValidates` — the five shapes of L2's `testTheStarterFollowsTheSchema`, each `starter()` passed through `validate()` without an error; `entry_content` missing, twice, or naming a non-blocks field ⇒ 422 at its path; an `entry_content` naming `sidebar` (optional) accepted once and refused twice; `entry_cover` bound to a text field, `entry_terms` to a non-reference field, `entry_field` to a blocks field ⇒ refused; a field that does not exist ⇒ refused; a general content block (heading, container) accepted anywhere; `settings.width` outside `contained|full` refused; an archived style class refused through the guard; a block type not in the palette or the content blocks refused.
- [ ] **Step 2:** run — fail. **Step 3:** implement (walk the whole tree; required counts; bindings from `bindable()`; `forLayouts()->validate` for the block payloads; guard). **Step 4:** green. **Step 5:** commit `feat(layouts): the layout validator`.

## Task L5: rendering entries through a layout

**Files:**
- Create: `packages/thallo-render/themes/default/templates/layouts/entry.twig`
- Modify: `packages/thallo-contracts/src/Content/FormSealer.php`, `core/src/Content/Forms/DefaultFormSealer.php`, `core/src/Content/Forms/FormSourceIdentity.php` (the layout source and the corrected precedence, Shared contracts), `docs/guides/07-forms.md` (which form a submission belongs to: a layout's form is one form across every page of the type, a header or footer form one form across the site, a body form its page's; newly rendered header and footer forms group their submissions under the region from this release, while earlier submissions keep their original grouping), `packages/thallo-render/src/RenderContextExtension.php` (`layout_source` threading in `layout_blocks`/`blocks`/`entry_slot`, `form_render` argument), `packages/thallo-render/themes/default/templates/blocks/form.twig`, `core/src/Content/Http/Controllers/EntryController.php` (`applyPreview` answers the effective `layout`), `packages/thallo-render/src/Http/Controllers/RenderController.php` (`layoutFor`, `renderEntry` selection and frame precedence, surface cache tag on every supported entry render, homepage untouched), `packages/thallo-render/src/Fragments/PreviewFragments.php` (null when the previewed entry renders through a layout), `packages/thallo-render/src/RenderServiceProvider.php` (inject `LayoutReader` nullable), `core/src/Content/Validation/FieldValidator.php` (`_presentation.use_layout` boolean), `core/src/Http/Controllers/PreviewController.php` (mint response `layout`), render `setAnnotationScope` users unchanged
- Test: `tests/Integration/Render/LayoutEntryRenderTest.php`, `tests/Integration/Render/FragmentVerificationTest.php` (assert `render()` returns null for a layout entry), `tests/Integration/Content/PreviewMintLayoutTest.php`

**Interfaces:** Consumes L1–L3. Produces the public rendering, `layout` in the mint response.

- [ ] **Step 1: failing tests** (`LayoutEntryRenderTest`):
  - `testAnEntryRendersThroughItsTypesLayoutAndOthersAsBefore` — a saved post layout; `/post/hello` contains the frame and the field blocks' output; a page (no page layout) renders `entry.twig` byte-identical to before.
  - `testTheHomepageRouteNeverUsesALayout` — a page layout saved; the homepage entry is a page: `/` renders `index.twig`; an ordinary page `/about` renders through the layout.
  - `testUseLayoutOffRendersTheThemeTemplate`.
  - `testFramePrecedence` — layout Frame `footer: hidden`; an entry whose `_presentation.footer = default` shows the footer; one without shows none; `show_title` has no effect under a layout.
  - `testEveryEligiblePageCarriesTheSurfaceTag` — with and without a layout, `Cache-Tag` contains `thallo:layout:entry:post`; the homepage response does not.
  - `testCachedThemePageThenFirstSaveThenRemoval` — cache a post page (theme), save a layout through the repository + `forget` + purge the tag, next request renders the layout; tombstone + forget + purge ⇒ theme again.
  - Review Focus 2 and the attribution fix: `testEveryFormKeepsItsOwnSource` — a form block in the post layout, in the header, in the footer, and in the body of posts `a` and `b`; rendering `/post/a` and `/post/b`, the sealed sources are: **layout forms** `layout:entry:post` on both; **header forms** `region:header` on both; **footer forms** `region:footer` on both; **body forms** `entry:{uuid of a}` and `entry:{uuid of b}` — so layout context reaches neither the body nor the chrome, and the chrome no longer takes the page's identity.
  - `testFormsIssuedBeforeTheFixStillSubmit` — seal a footer form the old way (a descriptor whose `form_key` was derived from `entry:{uuid}`, as a page cached before the release carries), submit it through `FormSubmitController`: accepted, and the stored submission's `form_key` is the old one; a submission stored before the release keeps its `form_key` and appears unchanged in the submissions list.
  - Unit tests: `FormSourceIdentityTest::testPrecedenceIsLayoutRegionEntryRouteFallback` (each level beats the ones after it) and `DefaultFormSealerTest` passing the layout source through.
  - Review Focus 3: `testAThemeWithoutAFrameFallsBackToTheDefaultFrame` — activate a fixture theme with its own `layout.twig` and no `layouts/`; the response has the theme's shell and the default frame.
  - Review Focus 4: `testAnEntryInAnotherLocaleShowsItsLocalizedFields` — a `fr` post: title and date localized, terms linking under `/fr/post/…`.
  - `PreviewMintLayoutTest` — mint for a post with a layout returns `layout: {surface: 'entry', target: 'post', label}`; for a page without, null; with `use_layout` off, null; **an apply that sets `use_layout: false` answers `layout: null`, the next apply restoring it answers the layout again** (the effective status follows the accepted document).
  - `FragmentVerificationTest` addition — `PreviewFragments::render` returns null for an entry of a type with a layout, and fragments as before for one without.
- [ ] **Step 2:** run — fail. **Step 3:** implement. **Step 4:** green; fragments re-record (the frame joins the templates hashed but entries with layouts never ask); full PHP suite. **Step 5:** commit `feat(layouts): entries render through their type's layout` — with a separate changelog bullet under **Fixed**: *A form in the header or footer is one form across the site* (it used to be attributed to each page it appeared on; submissions made before keep their grouping).

## Task L6: the layout session and the layout stage

**Files:**
- Create: `core/src/Content/Preview/SessionDocumentStore.php` (extracted), `core/src/Content/Preview/LayoutPreviewStore.php`, `core/src/Content/Preview/LayoutPreviewToken.php`, `core/src/Http/Controllers/LayoutPreviewController.php`, `core/src/Http/DTOs/{LayoutSessionData,ApplyLayoutData}.php`, `packages/thallo-render/src/Layouts/LayoutSessionReader.php`
- Modify: `core/src/Content/Preview/RegionPreviewStore.php` (extends the base; behaviour and keys unchanged), `packages/thallo-contracts/src/Delivery/PreviewSession.php`, `core/src/Content/Preview/EnginePreviewSessionVerifier.php`, `packages/thallo-render/src/Http/Controllers/RenderController.php` (`layoutStage`, consumer checks), `core/routes/admin.php`, `core/src/Providers/CoreServiceProvider.php` (bind `LayoutStageSnapshots` → `LayoutPreviewStore`)
- Test: `tests/Integration/Content/Layouts/LayoutSessionTest.php`, `tests/Integration/Render/LayoutStageRenderTest.php`, existing `RegionPreviewStoreTest`/`RegionsStage*` tests pass unchanged

**Interfaces:** Consumes L1–L5. Produces the session/apply endpoints, the stage render, `LayoutPreviewStore::retire/isRetired`.

- [ ] **Step 1: failing tests:**
  - `LayoutSessionTest`: `testSessionStartsFromTheSavedLayoutOrTheStarter` (`starter: true`, `lock_version` 0 / the tombstone's); `testApplyValidatesAndAcceptsCompareAndSet` (stale pair ⇒ 409 with `current`); `testTokensDoNotCross` (a layout token refused by `EntryController::applyPreview`, `RegionPreviewController::apply` and `PreviewFragments`; entry and regions tokens refused by layout apply); `testNeedsTemplatesManage` (route middleware); `testExpiredRecordsAnswer410`; `testRetirementAndApplyInBothLockOrders` — both legal orders, since retirement cannot interleave inside a held `accept()` lock: **(a)** retirement takes the store lock first (a worker process holds it via the L1 handshake while the apply waits), then the apply answers 410 and `current()` stays null; **(b)** the apply is accepted first, then retirement deletes the working copy, and a later apply answers 410 without recreating it; `testEveryStarterShapeOpens` — the five L2 shapes each mint a session through the real endpoint (`starter: true`) and render on the stage without an error.
  - `LayoutStageRenderTest`: `testTheStageRendersTheWorkingCopyOverTheSample` (layout blocks carry `data-thallo-block`, the body's blocks do not); `testNoSampleRendersThePlaceholderAndWritesNothing` (a type with nothing published: `data-thallo-placeholder`, entries unchanged); `testAVanishedSampleFallsBackToThePlaceholder` (unpublish the sample mid-session: placeholder, working copy intact); `testARetiredSessionRendersTheRemovalPage`; Review Focus 5: `testAMissingBlockTemplateInTheBodyLeavesTheLayoutEditable` (the body holds a removed type: fallback comment in the slot, layout blocks annotated).
- [ ] **Step 2:** run — fail. **Step 3:** implement; extract `SessionDocumentStore` first and run the Regions tests before adding the layout store. **Step 4:** green; full PHP suite. **Step 5:** commit `feat(layouts): the layout session and the layout stage`.

## Task L7: save and remove

**Files:**
- Create: `core/src/Http/Controllers/LayoutAdminController.php` (`index`, `samples`, `save`, `destroy`), `core/src/Http/DTOs/{SaveLayoutData,RemoveLayoutData}.php`
- Modify: `core/routes/admin.php`, `core/src/Providers/CoreServiceProvider.php`
- Test: `tests/Integration/Content/Layouts/LayoutSaveTest.php`

**Interfaces:** Consumes L1, L2, L4, L6. Produces the save/remove/list/samples endpoints.

- [ ] **Step 1: failing tests:**
  - `testFirstSaveCreatesAndAnswersTheVersion`; `testConcurrentFirstSavesOneWins` (two sessions, both expected 0: 200 and 409 `LAYOUT_VERSION_CONFLICT` with `current`).
  - `testStaleSaveWritesNothing`.
  - `testBaselineIsInstalledOnlyAfterTheCommitIsVisible` — a store subclass, on `putBaseline`, queries the layout row **from a second connection**: it already sees the new version (the transaction has committed); it then renders the stage from inside `clearIfPair`'s start and sees the committed layout, never the old baseline.
  - `testAnOuterRollbackDiscardsTheEffects` — the test opens its own transaction, calls the save service inside it (so `within` joins it and returns without committing), asserts that the session's baseline, working copy and the resolver's cached entry are **still unchanged** after the inner call returns, then rolls the outer transaction back: no row, no preview change, no purge, no forget. Committing the outer transaction instead runs the effects once, in order.
  - `testARolledBackSaveLeavesPreviewStateUntouched` — a repository subclass throws after `saveExpected` inside the transaction: no row change visible from a second connection, 500/422 answered, the session's baseline and working copy exactly as before, no purge issued, the resolver's cache untouched. The same for a rolled-back remove (the session is not retired).
  - `testClearOnlyOnTheExactPair` — a later apply after the save's pair: working copy kept, `preview_cleared: false`.
  - `testSavePurgesTheSurfaceTagAndForgetsTheResolver`.
  - `testRemoveTombstonesRetiresAndPurges` — 200 `{lock_version}`; the session is retired (apply ⇒ 410); `/post/x` renders the theme template; a fresh session opens on the starter at the tombstone's version.
  - `testRemoveWithAStaleVersionConflicts`.
  - Review Focus 1: `testTwoTabsOneEditor` — sessions A and B; A saves; B's save ⇒ 409; B re-mints (Reload) and its baseline is A's saved layout.
  - `testIndexListsEveryTargetWithItsState` and `testSamplesArePublishedOnly`.
- [ ] **Step 2:** run — fail. **Step 3:** implement: **inside** `withinType($type, fn () => within($surface, $target, …))` — token check, validation that reads the database (bindings, style-class guard), the version comparison and `saveExpected`/`tombstone`, and then **`$db->afterCommit(…)`** registering, in order, `putBaseline` then `clearIfPair` (save) or `retire` (remove), `LayoutResolver::forget` and the surface-tag purge. They run after the outermost commit; any rollback discards them. The response is built after `withinType` returns, from values captured in the callback. **Step 4:** green; full PHP suite. **Step 5:** commit `feat(layouts): save and remove, with the session contract`.

## Task L8: layouts in the block-document lifecycle and content-model changes

**Files:**
- Create: `core/src/Content/Blocks/Sources/LayoutsSource.php`, `core/src/Content/Layouts/{LayoutBindings,LayoutBindingConflict}.php`
- Modify: `core/src/Providers/CoreServiceProvider.php` (register the source in `makeBlockDocumentSources`), `core/src/Content/Blocks/Migration/BlockBackfillRunner.php` (`migrationSources` adds `LayoutsSource::ID`), `core/src/Content/Style/Classes/StyleClassUsage.php` (`layouts` count), `core/src/Content/Style/Classes/StyleClassJobRunner.php` (`purgeFor`: forget + purge the layout's surface tag), DTO `StyleClassUsageData`, admin `StyleClassSaveDialog.vue` + `styleClasses.ts`, `core/src/Content/Services/MigrationService.php` (takes `LayoutWriteLock::withinType($typeSlug)` around its validation and flip; inside that one transaction — the one `MigrationRepository::recordAndFlip()` runs in, joined, not a second one — a `DeleteField` of a bound field throws `LayoutBindingConflict` before the flip (→ 422 naming the layouts) and a `RenameField` rewrites the bindings through `LayoutBindings::renameField`, bumping each layout's `lock_version`; registered with `afterCommit` inside that transaction, `LayoutResolver::forget` and the surface-tag purge for each rewritten layout), `core/src/Content/Repositories/MigrationRepository.php` (`recordAndFlip` accepts an `?callable $withinTransaction` run inside its transaction before the flip), `core/src/Content/Http/Controllers/ContentTypeController.php` (`destroy` ⇒ `tombstoneType` in the delete's transaction, forget + purge through `afterCommit`); `ContentTypeRepository::updateSchema` keeps refusing deletion, rename and retyping, unchanged
- Test: `tests/Integration/Content/Layouts/LayoutDocumentsTest.php`, `tests/Integration/Content/Layouts/LayoutBindingsTest.php`, update `StyleClassApiTest` shape, admin `styleClassesPage.spec.ts`

**Interfaces:** Consumes L1, L2. Produces `LayoutsSource`, `LayoutBindings`.

- [ ] **Step 1: failing tests:** `LayoutDocumentsTest` — a block type migration rewrites a layout (as `SavedSectionDocumentsTest`); usage counts `layouts`; remove-everywhere cleans it and forgets the resolver; a persist at a stale version is refused. `LayoutBindingsTest`:
  - `RenameField excerpt→summary` rewrites `entry_excerpt.field` and bumps the layout's version, and a session holding the old version gets 409 on Save.
  - Deleting a bound field through a migration answers 422 naming "Posts — single post", and nothing changed (schema version, layout); deleting an unbound field passes.
  - `testARolledBackMigrationLeavesLayoutsUnchanged` — the flip throws after the binding rewrite: the layout's field and version are as before, seen from a second connection; no purge.
  - `testASaveCannotSlipABindingPastADeletion` — the Regions arrangement: the **parent** runs the migration deleting `excerpt` inside `withinType('post')`; before it commits, it starts the **child**, which attempts a layout save binding `excerpt` and reports its pid; the parent polls `pg_locks` until the child's pid waits on the type lock, then commits; the child's save answers 422 (the field no longer exists) and no layout binds `excerpt`.
  - `ContentTypeRepository::updateSchema` still refuses a schema that drops a field (existing behaviour, asserted here).
  - Deleting the type tombstones its layout (row kept, `blocks` null), and the type disappears from the Layouts list (it is no longer a target).
  - A binding broken by a raw schema write renders nothing on the site and a "field missing" placeholder on the stage.
- [ ] **Step 2–4:** as usual; full PHP suite. **Step 5:** commit `feat(layouts): layouts follow block migrations, style classes and content-model changes`.

## Task L9: the admin — the Layouts page and the layout editor

**Files:**
- Create: `admin/src/queries/layouts.ts`, `admin/src/pages/layouts/index.vue`, `admin/src/pages/layouts/[surface]/[target].vue`, `admin/src/pages/layouts/useLayoutHost.ts`, `admin/src/pages/layouts/components/{LayoutTopBar,LayoutFrameTab}.vue`, `admin/src/registry/layoutsModule.ts`
- Modify: `admin/src/registry/manifest.ts`, `admin/src/api/schema.d.ts` (`pnpm gen:api` after `CACHE_DRIVER=array composer docs:openapi` — commit only the layout paths' changes; if the regeneration drags in unrelated routes from the local environment, regenerate on a clean checkout of the capability set CI uses), `docs/openapi.json`
- Test: `admin/src/__tests__/layouts-page.spec.ts`, `admin/src/__tests__/layout-editor.spec.ts`, `admin/src/__tests__/layout-host.spec.ts`; e2e `admin/e2e/tests/layout-stage.spec.ts` with fixtures from `scripts/build-builder-proof-fixtures` (add a post type layout session capture)

**Interfaces:** Consumes the endpoints (L6, L7), `useStageEditor` (`allowLayoutOnly` from L3), `BlocksPalette.leadCategory`.

- [ ] **Step 1: failing tests:**
  - `layouts-page.spec` — rows from `useLayouts()` with Theme template / Custom layout; Edit routes to the editor; there is no Remove on the list (it lives in the editor, which holds the session token and version); a disabled row shows its reason.
  - `layout-host.spec` — `toDocument`/`toPayload` round-trip (`blocks`, `_layout_settings`); `renew` runs mint → apply(document, null pair) and keeps the saved version; `switchSample` re-mints and carries the unsaved layout; a stale generation's answer is ignored.
  - `layout-editor.spec` — the Blocks tab offers Fields first and the general blocks after; a required block's delete is refused with its reason; Save shows the reach ("Applies to every post") and marks only the submitted position saved; 409 shows `layout-conflict` with Reload only; the placeholder notice when `placeholder: true`; Remove from the menu confirms ("Every post goes back to the theme's design; your unsaved edits are discarded"), calls `removeLayout` with the session token and the editor's saved version, then returns to `/layouts`; a 410 `LAYOUT_SESSION_RETIRED` shows `layout-retired`; leaving with unsaved edits asks.
  - e2e `layout-stage.spec` — open the post layout, select the title block on the stage, drag an `entry_date` in from the Blocks tab, Save, conflict → Reload.
- [ ] **Step 2–4:** as usual; admin gates; e2e. **Step 5:** commit `feat(layouts): the Layouts page and the layout editor` — the changelog's feature bullet: **Layouts for content types** (what an editor can do, the Save reach, Remove, the permission, migration `037`).

## Task L10: the entry's Design view under a layout

**Files:**
- Modify: `admin/src/editor/stage/types.ts` (`ApplyPreviewResult` gains `layout?: {surface: string; target: string; label: string} | null`; `StageHost` gains optional `onAccepted?(result: ApplyPreviewResult): void`), `admin/src/editor/stage/useStageEditor.ts` (`runApply` calls `host.onAccepted?.(result)` **immediately after** `accepted.value = { epoch, revision }` — i.e. only for a response that passed the existing epoch/revision staleness check at lines 1561-1566; a dropped response never reaches it), `admin/src/queries/preview.ts` (`applyPreview` maps `layout`), `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` (`effectiveLayout` — a ref set from the mint's `layout` and replaced in the entry host's `onAccepted`; the strip, the Page tab's Layout control state and the Show page title note all read it, so the stage and the inspector agree after each acceptance; the Page tab's **Layout: Type layout | Theme template** writing `_presentation.use_layout`; **Show page title** replaced by a note while a layout applies), `admin/src/queries/preview.ts` (mint type gains `layout`)
- Test: `admin/src/__tests__/design-layout-strip.spec.ts`; e2e `admin/e2e/tests/design-under-layout.spec.ts`

**Interfaces:** Consumes the mint response (L5).

- [ ] **Step 1: failing tests:** the strip appears with "This post uses the Posts layout" and **Edit layout** linking to `/layouts/entry/post`; **within one session, no re-mint:** opting out (the control writes `use_layout: false` through history; the mocked apply answers `layout: null`) removes the strip and brings back Show page title; opting in again restores both; **undo** of the opt-out and **redo** of it each trigger an apply whose answer the page follows, and after each the strip, the control and the stage agree; while an apply is pending the previous acknowledged state stays shown; **a delayed obsolete response** — apply #1 (opt-out) answered after apply #2 (opt-in) was accepted, carrying an older revision — is dropped by the editor and changes neither the strip nor the controls; `stage-editor.spec` gains `onAccepted fires only for accepted responses` (an out-of-order older revision does not call it); e2e — a post with a layout: selecting a body block works, clicking a layout block selects nothing, an edit refreshes the stage (no fragments).
- [ ] **Step 2–4:** as usual; admin gates; e2e. **Step 5:** commit `feat(layouts): the Design view shows an entry inside its layout` (changelog bullet under the L9 feature entry's **Changed**).

## Task L11: docs, the full gates and the beta cut

**Files:**
- Create: `docs/guides/20-layouts.md` (with its `docs/internal/docs-writing/PAGES.md` row; the corpus rules), sections: what a layout is, open and edit, field blocks, slots, samples and the placeholder, Save and its reach, Remove, opting a page out, what the Design view shows, permissions
- Modify: `docs/reference/04-block-library.md` (a **Fields** section: the nine blocks), `docs/reference/03-template-functions.md` (`layout_blocks`, `entry_slot`), `packages/thallo-render/docs/THEMING.md` (frames and the two helpers), `docs/concepts/04-themes.md` (layouts before the template hierarchy), `docs/reference/06-permissions.md` (`templates.manage` opens layouts)
- Gates: full PHP suite, phpcs, boundaries, admin gates, e2e, docs tests; then the cut (`php scripts/sync-docs-changelog` in the cut commit, no empty `[Unreleased]` heading), `composer test:distribution`, `composer test:skeleton`, `scripts/release-bake`, the release commit, `scripts/verify-dist-archive`.

- [ ] **Step 1:** write the docs; `vendor/bin/phpunit tests/Unit/Docs`.
- [ ] **Step 2:** full gates, each read by its real result (failure count, exit code), never by a chained command's last status.
- [ ] **Step 3:** commit `docs(layouts): design a layout`.
- [ ] **Step 4:** the beta cut when the user asks.

---

## Self-review

- **Spec coverage (Release A):** §1 homepage route (L5), §2 decisions (L2–L10), §3 surface contract (L2), §4 field blocks (L3), §4.1 contract and depth (L2, L3, L4), §5.1 storage (L1), §5.2 session and samples (L6), §5.3 apply (L6), §5.4 stage annotation (L3, L6; loop cards are Release B), §5.5 save/remove (L7), §5.6 validation (L3, L4), §5.7 lifecycle (L8, with the rename ruling), §6.1–6.2 admin (L9), §6.3 Design view (L5, L10), §6.4 precedence (L5), §6.5 opt-out (L5, L10), §7.1–7.2 frames and selection (L5), §7.4 caching (L2, L5, L7), §8 proofs (spread per task), §11 A items all mapped. §7.3 commerce is Release C.
- **Names:** `LayoutReader::for`, `LayoutResolver::forget`, `LayoutRepository::saveExpected/tombstone/persistBlocks/forType/live/version`, `LayoutPreviewStore::retire/isRetired`, `layout_blocks`, `entry_slot`, `forLayouts()`, `allowLayoutOnly`, `leadCategory` are used identically in every task.
- **Review Focus:** five items, each with its named test (L5 ×3, L6, L7).
