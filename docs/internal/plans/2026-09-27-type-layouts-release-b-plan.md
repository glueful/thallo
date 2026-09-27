# Type Layouts — Release B (listings and archives) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An editor designs, on the layout stage, one layout for every page of a content type's listing (`/{type}[/page/n]`) and one for every archive of a reference field (`/{type}/{field}/{term}[/page/n]`) — a title, the term's description, an **Entry list** whose card they design once, and the page navigation — saves it, and every such page renders through it; with no layout every listing and archive page renders exactly as today.

**Architecture:** Core registers two more surfaces on Release A's engine — `listing` (target: the type slug) and `archive` (target: `{type}:{field}`) — built on the public route resolver that already computes a listing's page and an archive's term and members. A surface may now declare **loops**: a block whose blocks field is a card template, repeated once per item, with the field blocks that belong inside that card (`LayoutSurface::loops()`). The validator, the admin's insert rules and the renderer all read that one declaration, so C2's `product_loop` is one more declaration, not new machinery. Four layout-only blocks arrive — `entry_loop` (required, the card), `pagination`, `listing_title`, `term_description` — and the entry field blocks (`entry_title`, `entry_date`, `entry_cover`, `entry_excerpt`, `entry_terms`, `entry_field`) are reused inside the card, reading each item as `entry` (and `item`). On the stage the first card is annotated and every other card renders the same children without annotation, so no block id repeats.

**Tech Stack:** PHP 8.3 (Glueful, PostgreSQL, PHPUnit), Twig 3 (render pack, default theme), Nuxt UI admin (Vue 3, vitest, Playwright e2e in `admin/e2e`), real-browser proofs in `tools/runtime-browser` (Playwright).

**Spec:** `docs/internal/superpowers/specs/2026-09-26-type-layouts-design.md` — §1 (the `listing` and `archive` rows), §3 (`loops`, `required()` `entry_loop`), §4 (field blocks read `item` inside a card; the "In a card" column), §5.2 (placeholder: "an empty loop with its one placeholder card"), §5.4 (loop cards: the first annotated, the rest not; an empty sample's one placeholder card), §5.6 (item-scoped blocks only inside a card; required and slot blocks never inside one), §5.7 (archive targets under content-model changes), §6.1 (unlisted types' rows disabled with a link to Settings › General), §7.1–7.2 (frames `layouts/listing.twig`, `layouts/archive.twig`; selection), §7.4 (every listing and archive page carries its surface tag), §8, §11 item 2. Release C1 shipped first (user, 2026-09-27); this plan builds on it. Section numbers below (§n) are the spec's.

## Rulings made while planning (from the code)

- **Which pages, which targets.** Listings and archives render through `RenderController::renderCollection()` from `EnginePublicRouteResolver::resolveListing()` / `resolveArchive()` (core). A listed type is one in `GeneralSettings::listingTypes()` (Settings › General › Public listings, `listing_types`). An archived field is a `reference` field with `filterable: true` whose target type is publicly delivered. `listing` targets are **every publicly delivered type** (the `entry` surface's set); a type not in `listing_types` is listed **disabled**, reason "Listing pages are off for {Name}.", with a link to **Settings › General** (§6.1). `archive` targets are `{type}:{field}` for every archived field of every publicly delivered type, disabled with the same reason and link while the type is unlisted. Cost if wrong: one filter.
- **A target row may carry a link.** `LayoutSurface::targets()` rows gain `link: ?string` (an admin path). The Layouts page shows it under a disabled row's reason ("Turn on listing pages"). The entry and product surfaces answer `null`. Replaced at every implementer and at `LayoutTargets` in the same task.
- **Loops are a surface declaration.** `LayoutSurface` gains `loops(string $target): list<array{type: string, card: string, items: list<string>}>` — the loop block type, the name of its blocks field that holds the card, and the field blocks allowed **only** inside that card. `listing` and `archive` answer `[{type: 'entry_loop', card: 'card', items: ['entry_title', 'entry_date', 'entry_cover', 'entry_excerpt', 'entry_terms', 'entry_field']}]` (the spec's "In a card: yes" rows); `entry` and `product` answer `[]`. The session returns `loops`; the validator enforces them (below); the admin's insert and drop rules enforce them (Task B6). C2 declares `product_loop` the same way. Cost if wrong: a contract method.
- **The card rules** (§5.6), enforced by `LayoutValidator` from `loops()`, with the path of the offending block:
  - a slug in a loop's `items` outside that loop's card ⇒ `"'{type}' goes inside the {Loop label}'s card"`;
  - inside a card, a surface palette block that is not in that loop's `items` (the loop itself, `pagination`, `listing_title`, `term_description`, and every required or slot block) ⇒ `"'{type}' cannot go inside a card"`;
  - general content blocks (heading, container, …) are allowed inside a card, as the spec's "the general content blocks" are allowed anywhere.
  The required `entry_loop` is the field-less required rule C1 added (exactly once), plus "never inside a card" from the rule above; C1's "no block holding it can be hidden" rule applies to it unchanged.
- **The card reads its item as `entry` and `item`.** The entry field block templates read `entry`; a card renders its children with `entry` = the item and `item` = the item (§4 says "`item` inside a loop card"), so `entry_title`, `entry_date`, `entry_cover`, `entry_excerpt`, `entry_terms` and `entry_field` work inside a card unchanged. A listing item already has `uuid`, `fields`, `published_at` and `href` (`ListingItemShaper`), which is what they read.
- **One card renderer, generic.** A new Twig function `loop_cards(card, items, name)` in `RenderContextExtension` renders the card blocks once per item with `item`, `layout_context[name]` = the item, and — when `name` is `'entry'` — `entry` = the item. **Annotation:** in scope `layout`, the first card renders annotated and every later card renders with annotation off (the same save/set/restore of `annotateBlocks` that `entrySlot()` uses), so a block id appears once. **Empty:** with no items, in a canvas, one card renders annotated for `layout_context.placeholder_item`; on the site, nothing (the `entry_loop` template prints its empty text). Depth is the layout's own (a card is part of the layout tree; the cap holds). C2's `product_loop` calls `loop_cards(data.card, layout_context.products, 'product')`.
- **The page's variables reach blocks through `layout_context`** (C1's threaded key). A listing or archive frame's `layout_context` is `{items, pagination, type, type_name, term, field, placeholder_item}` (`term`/`field` null on a listing; `placeholder_item` only on the stage). `entry_terms` links need `type_listing`; collections now pass it (entries already do).
- **The blocks.**

  | Slug | Label | Settings (schema) | Holds blocks | Style |
  |---|---|---|---|---|
  | `entry_loop` | Entry list | `card` (blocks), `empty_text` string (default "Nothing here yet.") | the card | box **plus** the container's layout capabilities (`layout.display`, `layout.columns`, `layout.gap.column`, `layout.gap.row`, `alignment.content`, `layout.align_items`) — rows or a grid from the Style tab; **no Visibility** (required, as `product_buy`) |
  | `pagination` | Page navigation | `previous_label` (default "Newer"), `next_label` (default "Older"), `count` boolean (default on: "Page X of Y") | — | text |
  | `listing_title` | Listing title | `level` enum h1–h4 (default h1) | — | text |
  | `term_description` | Term description | — | — | text |

  All four: category **Fields**, `flags: ['layout_only' => true]`, declared in `StarterBlockTypes` beside the entry field blocks. `listing_title` shows the type's name on a listing and the term's title (`title`, else `slug`, else the uuid — today's heading) on an archive. `term_description` shows the term type's field named `description` (rich text as markup, as `entry_field` rich does; plain text escaped) and nothing when there is none (stage: "Description — this term has none"); a field setting is not added (YAGNI; cost if wrong: one setting later). `pagination` renders `_pagination.twig`'s markup with its labels (nothing when there is one page; stage: "Page navigation — one page"). Palettes: `listing` = `entry_loop`, `pagination`, `listing_title` + the six card blocks; `archive` = those + `term_description`.
- **The starters reproduce today's pages.** `listing.twig` is an `<h1>`, `_listing_rows.twig` (a `<ul class="listing-rows">` of rows: an optional 160/320 thumbnail from `fields.cover` claiming the priority image, the linked title, the date, the excerpt) and `_pagination.twig`. The listing starter is `listing_title {level: h1}`, `entry_loop` whose card is `entry_cover {field: cover}` (only when the type has an asset field named `cover`), `entry_title {level: h2, link: true}`, `entry_date {format: long}`, `entry_excerpt {field: excerpt}` (only when the type has it), then `pagination`. The archive starter is the same with `term_description` after the title. `entry_loop` renders its root as `<ul class="thallo-block thallo-block-entry_loop listing-rows">` and each card as `<li class="listing-row">`, so today's row styles apply to the starter. **The card's markup is not `_listing_rows.twig`'s** (field blocks render their own elements — `entry_cover` a figure, `entry_title` a heading), so the starter's look will differ in places: B0 freezes today's pages in a browser, B7 measures the starter against them, and **every difference found is brought to the user to accept or fix before B7 commits** (as the product starter's geometry was, C1). Cost if wrong: CSS in the default theme.
- **Frames and selection.** Default-theme frames `layouts/listing.twig` and `layouts/archive.twig` extend `layout.twig` with today's `<title>` blocks, the placeholder notice, and `{{ layout_blocks(layout.blocks|default([])) }}`; no SEO head (listings emit none today — `SeoHeadRenderTest::testNonEntryPagesEmitNoSeoTags` stands). `renderCollection()` asks `LayoutReader::for('listing', $type)` or `for('archive', "{$type}:{$field}")`; with a layout it renders the frame whatever `listing/{type}.twig` or `archive/{type}.twig` the theme ships (the docs type's `listing/docs.twig` included — the layout wins while it exists, §7.2). The resolver's 404s, 301s and pagination grammar are unchanged: a layout never makes a page exist.
- **Presentation.** Collections render with `presentationContext(null, null)` today. Under a layout: `presentationContext(null, null, $layout['settings'])` — the Frame's width and chrome over the theme's defaults — on the site **and** on the stage (the stage's `LayoutSampleContext` branch uses `FramePresentation::fixed()`, which is for shop pages; listing and archive stages use the collection rule instead, so the stage shows what the site serves).
- **Caching (§7.4).** Every listing page carries `thallo:layout:listing:{type}`, every archive page `thallo:layout:archive:{type}:{field}`, with or without a layout, beside today's tags. `pageTags()` answers the same. (The shared-across-workspaces over-eviction noted in C1 for entry pages applies equally; unchanged.)
- **Content-model changes (§5.7).** Type slugs are immutable, so only fields move. `LayoutBindings` reaches every layout of a type — `entry:{type}`, `listing:{type}` and `archive:{type}:*` — through a replaced `LayoutRepository::forType()` (all three surfaces; its one caller set updated). Renaming a field rebinds card blocks in listing and archive layouts, and **retargets** an archive layout whose target field it is (`post:categories` → `post:topics`, within the same lock). Deleting a field a card binds, or the field an archive layout targets, is refused naming the layout (the existing `LayoutBindingConflict`). Deleting a type tombstones its listing and archive layouts too. A field made non-filterable, or a type unlisted, keeps its layout: the row disappears or turns disabled, and returns when the setting does. `LayoutSaver::locked()` takes the type lock for `listing` (the target) and `archive` (the target's type part), as it does for `entry`.
- **The admin offers the surface's palette only.** `session.palette` is returned today but unused: the editor offers every layout-only block on every surface, and the server refuses what does not belong. B6 filters the palette to the general blocks plus `session.palette` (a product block never shows on a listing). Cost if wrong: none.
- **Required-block copy from the reach.** The editor's refusal reads "Every one of the {noun} shows its {label} here…", the noun cut from the label ("Posts — single post" → "posts"), which reads wrong for "Posts — listing pages". It now uses the reach: "Every {reach without 'Applies to every '} shows its {label} here, so the layout keeps this block. Move it instead." — "Every post shows its Entry content…", "Every product shows its Product buy box…", "Every page of the post listing shows its Entry list…". The vitest and e2e expectations for entry and product update in the same task.
- **Labels and reach.** Listing: label "{Name} — listing pages", reach "Applies to every page of the {singular} listing". Archive: label "{Name} — {Field label} archive", reach "Applies to every {Field label, singular} page of {Name}" ("Applies to every category page of Posts"). Singular as `EntrySurface::singular()`.
- **Samples.** Listing: one sample, `{id: '1', label: 'Page 1'}`, while the listing has members; none (placeholder) otherwise — the picker hides with one or no sample. Archive: the terms that have members, newest first, `{id: term uuid, label: term title}`, limit 50, `q` filtering by title. `sampleContext()` resolves page 1 through `resolveListing`/`resolveArchive` in the default locale and returns null when the listing or term no longer resolves (unlisted meanwhile, term unpublished) — the stage then renders the placeholder, as C1's vanished product does. Placeholder: `items` empty, `pagination` one page, a `placeholder_item` built as `EntrySurface::placeholder()` builds a sample entry ("Sample post", today's date, the type's fields empty), and on an archive a `term` whose title is "Sample {field singular}".
- **Existing sites get the blocks from `thallo:provision`**, and `LayoutTargets` keeps the listing and archive rows closed, naming the commands, until they have them (C1's mechanism, unchanged).

## Global Constraints

- **A listing or archive page with no layout renders exactly as today** — markup proven by deterministic goldens (B0), appearance by a frozen browser reference (B0, B7); `listing/docs.twig` unchanged.
- **The resolver decides which pages exist:** unlisted types, non-filterable fields, unknown or unpublished terms and pages beyond the last stay 404; `/page/1` stays a 301; a layout never changes that (§7.2).
- **`entry_loop` appears exactly once, never inside a card, and cannot be deleted or hidden;** a layout without it cannot be saved (§3, §5.6).
- **Card blocks only inside a card; page-level blocks never inside one** (§5.6) — refused by the server with the block's path, and refused by the admin's insert and drop rules with the reason.
- **On the stage the first card is selectable and no block id appears twice** (§5.4); slot content (none on these surfaces) stays unannotated.
- **Every listing page carries `Cache-Tag: thallo:layout:listing:{type}` and every archive page `thallo:layout:archive:{type}:{field}`,** with or without a layout (§7.4); the first save, an edit and a removal each show on the next request.
- **Save goes live; no overwrite** — Release A's Save/Remove contract, unchanged, for targets `{type}` and `{type}:{field}` (§5.5).
- **Entry and product layouts behave exactly as before;** their tests pass unchanged except the required-block copy the reach-based wording replaces (named in B6).
- **Layout-only blocks are refused in entries, regions and saved sections by the server** — the four new ones included (§5.6).
- **Pack boundaries:** core names no pack; the render pack names core surfaces only by key; `composer boundaries` stays green.
- **No compatibility shims:** a replaced API is replaced at every caller in the same task.
- **Every task's gates pass at its own commit;** each user-visible change carries its CHANGELOG bullet under `## [Unreleased]` in the same commit (re-add the heading; a cut removed it).
- Gates, run foreground and never concurrently: `COMPOSER_PROCESS_TIMEOUT=0 composer test` **also once with `API_USE_PREFIX=true`** (CI's default; the local `.env` sets false), `vendor/bin/phpcs` (check the **exit code**), `composer boundaries`; the tenancy proofs with `THALLO_TENANCY_DEV_LINK=1`, **each harness class in its own process**; admin — `pnpm exec oxfmt <touched files>`, `pnpm type-check`, `pnpm lint`, `pnpm exec vitest run`, and for any admin or fixture change `rm -rf admin/e2e/fixtures && CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures` (exit 0) then `cd admin/e2e && pnpm test`; browser proofs — `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-listing-layout-proof-fixtures` (exit 0) then `cd tools/runtime-browser && npx playwright test tests/listing-layout.spec.js`. **Every fixture script also runs once on a freshly reset and migrated test database** (`composer test:reset-db && composer test:migrate`), as CI's jobs do; and after `composer test:skeleton` reset and migrate the test database again.
- `git diff` before every commit; no AI attribution trailers; never push, never tag. One release, one beta cut at the end when the user asks (the cut runs `php scripts/sync-docs-changelog` and includes `docs/reference/08-changelog.md`); split, tags and pushes are the user's.

**Steps 1–4**, wherever a task says so, are the TDD cycle: write the failing tests named in the task, run and watch each fail for the stated reason, implement the minimum, run the file and the task's gates green. Step 5 is the commit.

## Review Focus

The inputs the spec implies but no requirement names, most likely to bite first. Each has its test in the owning task.

1. **An archive target with a colon** (`post:categories`) through the admin's URL, the router, the resolver's cache key on the Redis driver and the change announcement: saved, found, purged — Tasks B1 and B5.
2. **A type taken off Settings › General's listing types while it has a listing layout**, then put back: its listing and archive pages 404 as today, the rows turn disabled with the link, nothing errors, the layout is kept, and relisting serves it again — Task B3.
3. **A reference field renamed, deleted or made non-filterable while an archive layout targets it and a card binds it:** the rename retargets and rebinds; the delete is refused naming the layout; non-filterable hides the row and keeps the layout — Task B1.
4. **A card with a cover on a full page:** only the first card's cover claims the priority image, as today's first row does; every later card's image is lazy — Task B2.
5. **A card holding a container with nested blocks, on a page of ten items:** the stage annotates exactly the first card's blocks (container and children), every id appears once, and the other nine cards render identical markup without annotation — Task B2.

---

## Shared contracts (named once, used by every task)

**Contracts (`packages/thallo-contracts/src/Layouts/`)**

- `LayoutSurface::targets()` rows: `{target: string, label: string, enabled: bool, reason: ?string, link: ?string}`.
- `LayoutSurface::loops(string $target): array` — `list<array{type: string, card: string, items: list<string>}>`.

**Core (`core/src/Content/Layouts/`)**

- `ListingSurface implements LayoutSurface, LayoutSampleContext` — key `listing`; per the rulings. `ArchiveSurface implements LayoutSurface, LayoutSampleContext` — key `archive`, target `{type}:{field}`. Both registered by `LayoutSurfaces`' constructor beside `EntrySurface` (all three core surfaces).
- `LayoutValidator` — the card rules from `loops()`; unchanged otherwise.
- `LayoutRepository::forType(string $typeSlug): array` — every layout row (tombstones included) of surfaces `entry` and `listing` with target `$typeSlug`, and `archive` with target `LIKE $typeSlug . ':%'`.
- `LayoutRepository::retarget(string $surface, string $from, string $to): void` — moves a row's target (the archive retarget on a field rename).
- `LayoutBindings` — rename rebinds cards and retargets archives; delete refuses card bindings and archive targets; `tombstoneType()` tombstones all three surfaces' rows of the type.
- `LayoutSaver::locked()` — the type lock for `entry`, `listing` and `archive` (type = the target up to `:`).

**Render (`packages/thallo-render`)**

- `RenderContextExtension::loopCards(Environment $env, array $context, mixed $card, mixed $items, string $name): Markup`, registered as `loop_cards` (`needs_environment`, `needs_context`, `is_safe` html).
- `RenderController::renderCollection()` — the layout selection, `layout_context`, `type_listing`, the surface tag, the collection presentation.
- `RenderController::layoutSampleOfSurface()` — for `listing` and `archive`, `presentationContext(null, null, $layout['settings'])` in place of `FramePresentation::fixed()`.
- Templates (default theme): `blocks/entry_loop.twig`, `blocks/pagination.twig`, `blocks/listing_title.twig`, `blocks/term_description.twig`, `layouts/listing.twig`, `layouts/archive.twig`.

**Admin**

- `LayoutSession` gains `loops: {type: string, card: string, items: string[]}[]`; `LayoutRow` gains `link: string | null`.
- `admin/src/editor/structure/legality.ts` — two reason codes, `item-outside-card` and `not-in-card`, checked from a `cards` rule set the host passes in the legality context (`{ loops, palette }`); `checkDescendants` applies them to pasted subtrees.

---

## Task B0: freeze today's listing and archive pages

Before any template, CSS or controller change; its own commit.

**Files:**
- Create: `scripts/build-listing-layout-proof-fixtures`, `scripts/capture-listing-layout-reference`, `tools/runtime-browser/tests/listing-layout.spec.js`, `tools/runtime-browser/references/listing-original.json`, `tests/Support/ListingPageSeed.php`, `tests/Integration/Render/ListingPageGoldenTest.php`, `tests/fixtures/render/listing-page-{listing,archive,page2}.html`
- Modify: `.gitignore` (`tools/runtime-browser/fixtures/listing-layout/`), `.github/workflows/runtime-browser.yml` (the fixture step and `paths`: `packages/thallo-render/themes/default/templates/{listing,archive,_listing_rows,_pagination}.twig`, `packages/thallo-render/themes/default/assets/**`, the two scripts)

**Interfaces:** Produces `ListingPageSeed` (every later task seeds with it) and the frozen references.

- [ ] **Step 1:** `ListingPageSeed` — deterministic: a `post` type (title, slug, excerpt, cover asset, body, a `categories` reference field, filterable, to a `category` type with `title`, `slug`, `description` rich text), three published posts with fixed titles, slugs, excerpts and `published_at`, two with covers from committed image fixtures stored as blobs **on the media disk**, one category "Pottery" with a description holding all three posts; `post` in `listing_types`; per page 2 (the suite's). It publishes **without** the review workflow, as `ProductPageSeed` does (a fresh database grants no one the bypass). `useTenant()`/`restoreTenant()` as `ProductPageSeed`.
- [ ] **Step 2:** `ListingPageGoldenTest` — `/post`, `/post/page/2` and `/post/categories/pottery` through the kernel, normalized by one explicit map with every substitution **counted and pinned** (uuids → `{post:n}`, `{category:1}`; blob URLs → `{blob:n}`; fingerprinted and `?v=` asset URLs → `{asset:name}`); markup parity against `tests/fixtures/render/listing-page-*.html` (recorded with `THALLO_RECORD_LISTING_GOLDEN=1`) and the asset-name set, reported separately; `testTheGoldenSurvivesAFreshRebuild` reseeds with new uuids and compares again. Green on today's templates.
- [ ] **Step 3:** the fixture script (rolled-back transaction, stylesheets and images inlined as `build-product-layout-proof-fixtures` does, any throw exits 1) writes `listing-original.html` and `archive-original.html`; it runs green **on a freshly reset and migrated database**. The capture script measures both at 390 × 900 and 1280 × 900 twice, refuses to write when the measurements differ, and writes `listing-original.json` with the heading, the rows (thumbnail, title, date, excerpt), the pagination nav and the article. One browser test: `today's listing and archive pages match their frozen reference`.
- [ ] **Step 4:** the golden, the browser test and the fixture script on a fresh database green; full suite; phpcs.
- [ ] **Step 5:** commit `test(render): freeze today's listing and archive pages` (changelog: none).

## Task B1: loops, card rules, and every layout of a type

**Files:**
- Modify: `packages/thallo-contracts/src/Layouts/LayoutSurface.php` (`loops`, the row's `link`), `core/src/Content/Layouts/EntrySurface.php` (`loops` `[]`, `link` null), `packages/thallo-commerce/src/Layouts/ProductSurface.php` (the same), `tests/Support/FixtureLayoutSurface.php` (a loop declaration for the validator tests), `core/src/Content/Layouts/LayoutValidator.php` (card rules), `core/src/Content/Layouts/LayoutTargets.php` (rows keep `link`), `core/src/Content/Layouts/LayoutRepository.php` (`forType` for all three surfaces, `retarget`), `core/src/Content/Layouts/LayoutBindings.php`, `core/src/Content/Layouts/LayoutSaver.php` (`locked`), `core/src/Http/Controllers/LayoutAdminController.php` (row `link`), `core/src/Http/Controllers/LayoutPreviewController.php` (session `loops`)
- Test: additions to `tests/Integration/Content/Layouts/LayoutValidatorTest.php`, `LayoutBindingsTest.php`, `LayoutSaveTest.php`, `LayoutSessionTest.php`

**Interfaces:** Produces `loops()`, the card rules, the row `link`, `forType`/`retarget`, the widened bindings and lock.

- [ ] **Step 1: failing tests:**
  - `LayoutValidatorTest::testCardBlocksLiveInsideTheCardOnly` — the fixture surface declares `[{type: 'fixture_loop', card: 'card', items: ['button']}]` with a test loop block type (a blocks field `card`) and `button` in its palette: `button` at the root ⇒ `blocks.0.type` = "'button' goes inside the Fixture loop's card"; `button` inside `fixture_loop.card` ⇒ accepted; `button` in a container inside the card ⇒ accepted; a `heading` in the card ⇒ accepted.
  - `testPageBlocksNeverGoInsideACard` — the loop inside its own card, and a required block inside the card, each refused at its path with "cannot go inside a card"; the required block's "exactly once" still counts it (one inside a card and none outside ⇒ both errors).
  - `testCardRulesDoNotTouchSurfacesWithoutLoops` — an entry layout with `entry_title` at the root is accepted (existing entry tests green unchanged).
  - `LayoutBindingsTest::testAFieldRenameRebindsCardsAndRetargetsItsArchive` — a listing layout whose card has `entry_excerpt {field: summary}` and an archive layout at `post:categories` whose card binds `summary`: renaming `summary` → `teaser` rebinds both cards; renaming `categories` → `topics` moves the archive row to `post:topics` (same version, one `LayoutChanged` for the old and one for the new target) and the old target has no row.
  - `testDeletingAFieldAnArchiveOrCardUsesIsRefused` — deleting `summary` or `categories` answers `LayoutBindingConflict` naming the listing and the archive layout (label as the Layouts page shows it).
  - `testDeletingATypeTombstonesItsListingAndArchiveLayouts` — all three surfaces' rows become tombstones with bumped versions.
  - `LayoutSaveTest::testListingAndArchiveSavesTakeTheTypeLock` — a content-type migration holding `post`'s type lock blocks a save at `listing:post` and `archive:post:categories` until it releases (the entry test's arrangement).
  - `LayoutSessionTest::testTheSessionCarriesTheSurfacesLoops` — an entry session answers `loops: []`; the fixture surface's session answers its declaration. `LayoutAdminController::index` rows carry `link` (null for entry rows).
- [ ] **Step 2:** run — fail (no `loops`, no card rules, `forType` is entry-only).
- [ ] **Step 3:** implement per Shared contracts. In `LayoutValidator`, walk with the enclosing card: a stack of `(loop type, items)` pushed when descending into a loop block's `card` field; the loop's label comes from `BlockTypeRepository` (else the slug). `forType()` is replaced at its callers (`LayoutBindings` only).
- [ ] **Step 4:** green; every Release A and C1 layout test green unchanged; full suite (and with `API_USE_PREFIX=true`); phpcs; boundaries.
- [ ] **Step 5:** commit `feat(layouts): a surface declares its loops — card blocks live inside the card, and a type's listing and archive layouts follow its fields` (changelog: none — nothing a person sees yet).

## Task B2: the Entry list, its card, and the listing blocks

**Files:**
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php` (the four definitions), `packages/thallo-render/src/RenderContextExtension.php` (`loop_cards`), `packages/thallo-render/themes/default/assets/blocks.css` (the card's row styles under `.thallo-block-entry_loop`, the placeholders)
- Create: `packages/thallo-render/themes/default/templates/blocks/{entry_loop,pagination,listing_title,term_description}.twig`
- Test: `tests/Integration/Render/EntryLoopRenderTest.php`, `tests/Integration/Render/ListingBlocksRenderTest.php`, additions to `tests/Integration/Content/LayoutOnlyBlocksTest.php`, `tests/Integration/Content/BlockStyleDeclarationsTest.php` (`NEVER_HIDDEN` gains `entry_loop`), `tests/Integration/Blocks/BlockTypeFingerprintTest.php` (still green: the new definitions fingerprint as their rows)

**Interfaces:** Consumes B1 (the `card` field name). Produces the four slugs, `loop_cards`, and the `layout_context` keys the blocks read.

- [ ] **Step 1: failing tests:**
  - `EntryLoopRenderTest` — through `layout_blocks()` with a frame context holding `layout_context.items` (three `ListingPageSeed` posts, shaped by `ListingItemShaper`) and a card of `entry_title {link: true}`, `entry_date`, `entry_excerpt`:
    - scope `none`: three `<li class="listing-row">` inside `<ul class="thallo-block thallo-block-entry_loop listing-rows">`, each with that post's title linked to its `href`, its date and excerpt, in order; no `data-thallo-block`.
    - scope `layout`: the first card's blocks carry `data-thallo-block` with their authored ids; the second and third cards render the same markup for their posts **without** annotation; every id appears exactly once (Review Focus 5: a card holding a `container` with two children — the container and both children annotated once, in the first card only).
    - empty items: scope `none` prints `empty_text` ("Nothing here yet." by default); scope `layout` renders one annotated card for `layout_context.placeholder_item` ("Sample post").
    - Review Focus 4: a card with `entry_cover {field: cover}` over three posts with covers — only the first card's image is the priority image (`fetchpriority="high"`, no `loading="lazy"`), the others lazy — the rule today's first row follows.
    - a card inside two nested containers of the layout renders; the depth cap counts the card's blocks from the layout root.
    - `loop_cards(card, items, 'product')` sets `layout_context.product` and `item` for each card and not `entry` (C2's seam), with a test block template printing both.
  - `ListingBlocksRenderTest` — `listing_title` prints the type name on a listing and the term title (then slug, then uuid) on an archive at its level; `term_description` prints a rich description as markup, a plain one escaped, nothing without one (scope `layout`: the named placeholder); `pagination` prints today's `_pagination.twig` markup with its labels and "Page X of Y" (nothing on one page; scope `layout`: the placeholder), `rel="prev"`/`rel="next"` kept, `count: false` drops the count.
  - `LayoutOnlyBlocksTest::testListingBlocksBelongToLayouts` — an entry draft, a region save and a saved section containing `entry_loop` (or `pagination`) are refused at the block's path.
- [ ] **Step 2:** run — fail (no blocks, no `loop_cards`).
- [ ] **Step 3:** implement per the rulings. `entry_loop.twig`: `{% if layout_context.items|default([]) is empty and not is_canvas() %}<p class="listing-empty">{{ data.empty_text|default('Nothing here yet.') }}</p>{% else %}<ul class="thallo-block thallo-block-entry_loop listing-rows {{ style_classes('root') }}"{{ style_attrs('root') }}>{{ loop_cards(data.card|default([]), layout_context.items|default([]), 'entry') }}</ul>{% endif %}`; `loopCards` wraps each card in `<li class="listing-row">`.
- [ ] **Step 4:** green; `ListingPageGoldenTest` green (no template it covers changed); the Release A entry-block render tests green unchanged; full suite; phpcs; boundaries.
- [ ] **Step 5:** commit `feat(layouts): the Entry list and its card, page navigation, the listing title and the term description` (changelog: none yet — the feature bullet lands with B5).

## Task B3: the listing and archive surfaces

**Files:**
- Create: `core/src/Content/Layouts/ListingSurface.php`, `core/src/Content/Layouts/ArchiveSurface.php`
- Modify: `core/src/Content/Layouts/LayoutSurfaces.php` (register both), `core/src/Content/Layouts/Starters.php` (`forListing(array $schema, bool $archive): array`), `core/src/Content/Delivery/EnginePublicRouteResolver.php` (a public `archivedFields(array $type): list<array{name, label, target}>` extracted from `resolveArchive`'s checks, used by both), `core/src/Providers/CoreServiceProvider.php`
- Test: `tests/Integration/Content/Layouts/ListingSurfaceTest.php`, `tests/Integration/Content/Layouts/ArchiveSurfaceTest.php`

**Interfaces:** Consumes B1 (`loops`, `link`), B2 (slugs). Produces the two surfaces.

- [ ] **Step 1: failing tests:**
  - `ListingSurfaceTest`: `targets()` has `post` enabled (listed) and `page` (delivered, unlisted) disabled with "Listing pages are off for Pages." and `link` `/settings/general`; label "Posts — listing pages", reach "Applies to every page of the post listing"; `samples()` is `[{id: '1', label: 'Page 1'}]` with members and `[]` without; `sampleContext('post', '1')` has `layout_context.items` (page 1's two posts), `pagination` (page 1 of 2, `next_path` `/post/page/2`), `type`, `type_name`, `type_listing`, and null once `post` is unlisted; `placeholder('post')` has no items, one page, and a `placeholder_item` titled "Sample post"; `palette()`, `required()` `[{type: 'entry_loop'}]`, `loops()` per the rulings, `bindable()` the type's fields, `pageTags('post')` `['thallo:layout:listing:post']`, `frame()` `layouts/listing.twig`; `starter('post')` is exactly the ruled tree (cover and excerpt present for `post`, absent for a type without them) and passes `LayoutValidator::validate('listing', 'post', …)` unchanged.
  - `ArchiveSurfaceTest`: `targets()` has `post:categories` (label "Posts — Categories archive", reach "Applies to every category page of Posts"); a non-filterable reference field and a field to an undelivered type have no row; `post:categories` is disabled with the link while `post` is unlisted; `samples()` lists terms with members only, newest first, `q` filtering; `sampleContext('post:categories', $pottery)` has `term`, `field`, the members, pagination; an unpublished term answers null; `placeholder()` has a term titled "Sample category"; starter as ruled with `term_description`; `pageTags` `['thallo:layout:archive:post:categories']`.
  - Review Focus 2: `ListingSurfaceTest::testUnlistingKeepsTheLayoutAndRelistingServesIt` — save a listing layout (repository), remove `post` from `listing_types`: `/post` answers 404 as today, the row is disabled with the link, `GET /v1/admin/layouts` answers 200, a block migration backfill walks the row; put `post` back: the row reads **Custom layout** and `LayoutReader::for('listing', 'post')` returns the saved blocks.
- [ ] **Step 2:** run — fail (no surfaces).
- [ ] **Step 3:** implement per the rulings; `resolveArchive()` keeps its behaviour through the extracted `archivedFields()` (its tests green).
- [ ] **Step 4:** green; `ListingArchivePagesTest`, `PublicRouteResolverTest`, `TermIndexPagesTest` green unchanged; full suite; phpcs; boundaries.
- [ ] **Step 5:** commit `feat(layouts): listing and archive surfaces — targets from the listed types and archived fields, samples, placeholders and starters` (changelog: none yet).

## Task B4: listing and archive pages render through their layout

**Files:**
- Create: `packages/thallo-render/themes/default/templates/layouts/{listing,archive}.twig`
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php` (`renderCollection`: the selection, `layout_context`, `type_listing`, the surface tag on every page, the presentation; `layoutFor` generalized to `layoutFor(string $surface, string $target, ?array $presentation)` at its callers)
- Test: `tests/Integration/Render/ListingLayoutRenderTest.php`, `tests/Integration/Render/ListingLayoutCacheTest.php`

**Interfaces:** Consumes B2, B3. Produces live selection.

- [ ] **Step 1: failing tests:**
  - `ListingLayoutRenderTest`: with no layout, `/post`, `/post/page/2` and the archive render today's markup (the golden, B0) **and** now carry `thallo:layout:listing:post` / `thallo:layout:archive:post:categories` beside today's tags; with the listing starter saved, `/post` renders through `layouts/listing.twig` — the title, two cards in order, the navigation to page 2 — and `/post/page/2` the third post with "Newer" to `/post`; the archive renders the term title, its description and its members; `/post/page/9`, an unknown term and `/post/page/1` still answer 404, 404 and 301; a Frame of width full and header hidden renders `layout--full` and no header; a `listing/post.twig` the test theme ships is not used while the layout exists and is used again after Remove.
  - `ListingLayoutCacheTest` (the entry cache test's arrangement): `/post` cached through the theme → first save → the next request renders the layout → edit → the change shows → Remove → the theme page returns; the same for the archive; a save of the listing layout does not evict a cached archive page (different tags).
- [ ] **Step 2:** run — fail (no selection, no tag).
- [ ] **Step 3:** implement per the rulings.
- [ ] **Step 4:** green; `ListingArchivePagesTest`, `DocsPagesTest`, `SeoHeadRenderTest`, `PreviewSessionTest`, the entry layout render and cache tests green unchanged; full suite (and with `API_USE_PREFIX=true`); phpcs.
- [ ] **Step 5:** commit `feat(layouts): listing and archive pages render through their layout` (changelog: none yet).

## Task B5: the listing and archive stage, Save and Remove

**Files:**
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php` (`layoutSampleOfSurface`: the collection presentation for `listing`/`archive`), `CHANGELOG.md`
- Test: `tests/Integration/Content/Layouts/ListingLayoutStageTest.php`, `tests/Integration/Content/Layouts/ListingLayoutSaveTest.php`, `tests/Integration/Content/Layouts/ListingLayoutRoutesTest.php`, `tests/Integration/Tenancy/ListingLayoutTenancyTest.php` (opt-in)

**Interfaces:** Consumes B3, B4.

- [ ] **Step 1: failing tests:**
  - `ListingLayoutStageTest`: a session for `listing:post` renders page 1's cards around the working copy — the first card's blocks selectable once, the second not; an archive session renders the chosen term; a listing with no members opens on the placeholder (one annotated placeholder card, "No published posts yet — showing a placeholder", nothing written); unlisting `post` mid-session renders the placeholder with the working copy intact; the stage's Frame presentation equals the site's for the same settings; a retired session renders the removal page.
  - `ListingLayoutSaveTest`: Release A's contract at `listing:post` and `archive:post:categories` — first save 1, stale save 409 with nothing written, remove tombstones and retires the session, reopen continues the version; a layout without `entry_loop` refused ("the layout must show the Entry list block"); `entry_title` at the root refused ("goes inside the Entry list's card"); `pagination` inside the card refused; a container holding `entry_loop` hidden at a size refused (C1's rule).
  - Review Focus 1: `ListingLayoutRoutesTest` — through the real kernel with an API key (the `ProductLayoutRoutesTest` arrangement, paths `/v1/admin/…`): session, samples, `PUT /v1/admin/layouts/archive/post%3Acategories` and the same with the colon literal, `GET /v1/admin/layouts`, `DELETE`; a caller without `templates.manage` refused 403. `LayoutResolver::cacheKey('', 'archive', 'post:categories')` has no character of the Redis reserved set.
  - `ListingLayoutTenancyTest` (retrofit harness, `THALLO_TENANCY_DEV_LINK=1`): two workspaces each save a listing layout for `post` at version 1 and render their own.
- [ ] **Step 2:** run — fail where the stage presentation or a route is missing (most of the lifecycle passes on Release A's engine: the test proves it at these targets).
- [ ] **Step 3:** implement; add the changelog bullet under `## [Unreleased]` → `### Added`: **Layouts for listing and archive pages** — Site › Layouts has a row for each listed type's listing pages and each archived field; design the page once around the Entry list, whose card you design once for every entry; Save applies to every page; with no layout the pages are as today; an unlisted type's rows say how to turn listing pages on; on an existing site run `thallo:provision` for the new blocks (with workspaces on, also `thallo:tenant:sync --all --kind=block_type`).
- [ ] **Step 4:** green (the tenancy class in its own process); the Release A and C1 stage and save tests green; full suite (and with `API_USE_PREFIX=true`); phpcs.
- [ ] **Step 5:** commit `feat(layouts): design listing and archive pages on the stage` (changelog: the bullet).

## Task B6: the editor — the surface's palette, the card's rules, the rows' links

**Files:**
- Modify: `admin/src/queries/layouts.ts` (`loops`, `link`), `admin/src/pages/layouts/useLayoutHost.ts` (palette and card rules into the host), `admin/src/editor/stage/useStageEditor.ts` (`paletteTypes` filtered by the host's palette; the legality context carries the card rules), `admin/src/editor/structure/legality.ts` (`item-outside-card`, `not-in-card`), `admin/src/pages/layouts/[surface]/[target].vue` (required copy from the reach), `admin/src/pages/layouts/index.vue` (the row link), `admin/src/api/schema.d.ts` and `docs/openapi.json` (the row `link`, the session `loops` — the one description each, in place, as the style class change did; a full regeneration churns the unrelated packs), `scripts/build-builder-proof-fixtures` (a listing section), `admin/e2e/helpers.ts` (`LAYOUT_WORLDS.listing`)
- Create: `admin/e2e/tests/listing-layout-stage.spec.ts`, `admin/e2e/listing-scenarios.json` (the listing stage states, `{name, layout}` as `layouts-scenarios.json`)
- Test: `admin/src/__tests__/layout-editor.spec.ts`, `admin/src/__tests__/layouts-page.spec.ts`, `admin/src/__tests__/structure-legality.spec.ts`

**Interfaces:** Consumes B1 (`loops`, `link`), B5 (the server).

- [ ] **Step 1: failing tests:**
  - legality: with rules `{loops: [{type: 'entry_loop', card: 'card', items: ['entry_title']}]}`, inserting `entry_title` at the root ⇒ `item-outside-card` ("Entry title goes inside the Entry list's card"); into the card ⇒ allowed; into a container inside the card ⇒ allowed; inserting `pagination` or `entry_loop` into the card ⇒ `not-in-card` ("Page navigation can't go inside a card"); pasting a container holding `entry_title` at the root ⇒ refused by `checkDescendants`; no rules ⇒ today's behaviour (the existing legality tests green).
  - `layout-editor.spec`: a listing session offers the general blocks and exactly `session.palette` (no `product_name`, no `entry_content`); the required refusal reads "Every page of the post listing shows its Entry list here, so the layout keeps this block. Move it instead."; the entry and product refusals read "Every post shows its Entry content here…" and "Every product shows its Product buy box here…" (their expectations updated here); a palette tile for `entry_title` is disabled with the reason while the root is the target and enabled when the card is.
  - `layouts-page.spec`: a disabled listing row shows its reason and a "Turn on listing pages" link to `/settings/general`; no Edit.
  - e2e `listing-layout-stage.spec.ts` against the real renderer's fixtures (`LAYOUT_WORLDS.listing`, path `/admin/layouts/listing/post`, ready `.thallo-block-entry_loop`): the first card's title selects and opens the Block tab naming "Entry title"; the second card's title is not selectable; `entry_date` dragged onto the first card's title lands after it in the card and the stage refreshes with it in **every** card (the accepted document equals the fixture the renderer rendered); `entry_title` dragged to the layout root is refused with the reason; deleting the Entry list is refused with the reach-based copy.
- [ ] **Step 2:** run — fail.
- [ ] **Step 3:** implement. The fixture section writes `listing-session.json`, `listing-samples.json`, `listing-stages.json` and `listing-stage-{baseline,dated}.html` from the real controllers (mint, apply every state, render) inside the rolled-back transaction, seeding with `ListingPageSeed`; it runs green on a freshly migrated database.
- [ ] **Step 4:** green; oxfmt on touched files; `pnpm type-check`; `pnpm lint`; vitest; the full e2e suite; the PHP suite (the fixture script's section is PHP).
- [ ] **Step 5:** commit `feat(layouts): the editor offers a surface's own blocks, keeps card blocks in the card, and links an unlisted type to its setting` (changelog: fold into B5's bullet if the wording needs it).

## Task B7: the starter and authored styles, measured in a browser

**Files:**
- Modify: `scripts/build-listing-layout-proof-fixtures` (the starter, authored and reset states, page and stage, as the product script does), `tools/runtime-browser/tests/listing-layout.spec.js`, `packages/thallo-render/themes/default/assets/blocks.css` (only the fixes the user rules)

- [ ] **Step 1:** add the states and tests: the starter page and stage against `listing-original.json` for the listing and the archive; authored values on `listing_title` (size, colour), `entry_title` in the card (size) and `entry_loop` (a two-column grid from the Style tab) win on the page and the stage; removing them returns the defaults; blocks added beside the starter (a heading, text, an image) keep the theme's spacing.
- [ ] **Step 2:** run and **list every measured difference between the starter and today's page** (element, property, widths). **Stop and bring the list to the user**: each difference is either accepted (recorded as a relax-and-assert row, as C1's accepted geometry is) or fixed in `blocks.css` (the frozen `original` comparison must stay green). No tolerance is widened.
- [ ] **Step 3:** implement the user's rulings.
- [ ] **Step 4:** the listing spec green, the product spec green, the fixture script green on a fresh database; the PHP suite (the script is PHP).
- [ ] **Step 5:** commit `test(layouts): the listing starter and authored styles, measured in a browser` (changelog: none, unless a CSS fix changes what a person sees on a page without a layout — it must not).

## Task B8: docs

**Files:**
- Modify: `docs/guides/20-layouts.md` (a section **Design listing and archive pages**: open a row, the Entry list and its card, the blocks and their settings in a table, what shows on the stage, an unlisted type's row, provisioning), `docs/reference/04-block-library.md` (the four rows in **Fields**; the intro's "Holds blocks" and required notes; which field blocks go inside a card), `packages/thallo-render/docs/THEMING.md` (the two frames, overridable by file; `loop_cards` for theme authors), `CHANGELOG.md` if B5's bullet needs the final wording
- Test: `tests/Unit/Docs` (green)

- [ ] **Step 1–4:** write; `vendor/bin/phpunit tests/Unit/Docs`; phpcs (no PHP changed) — the docs' command examples checked against the code (`thallo:provision`, `thallo:tenant:sync --all --kind=block_type`).
- [ ] **Step 5:** commit `docs(layouts): design listing and archive pages`.

## Final

- [ ] The full gates in order (PHP suite twice — default and `API_USE_PREFIX=true`; every tenancy harness class in its own process; phpcs; boundaries; admin gates; e2e from a fresh database; the listing and product browser specs; docs tests; `composer test:distribution`).
- [ ] A fresh-context review of the whole branch against the spec and this plan's Review Focus; one fix pass, each fix RED→GREEN with the suite green.
- [ ] The beta cut when the user asks.

## Self-review

- **Spec coverage (B):** §1 listing and archive rows (B3); §3 `targets`, `samples`, `palette`, `required` `entry_loop`, frames, starters (B3), loops as the surface's declaration (B1); §4 the card reads `item` (B2), the "In a card" column (B1 `loops`); §5.2 the placeholder with its one card and a vanished sample (B3, B5); §5.4 the first card annotated, the rest not, the empty placeholder card (B2, B5); §5.5 Save/Remove at these targets (B5); §5.6 item-scoped blocks, required and page blocks never in a card, layout-only refused elsewhere (B1, B2, B5); §5.7 archive targets and bindings under field and type changes (B1); §6.1 unlisted rows disabled with the Settings › General link (B3, B6); §6.2 the editor's palette and required copy (B6); §7.1 frames (B4); §7.2 selection over per-type theme templates (B4); §7.4 the surface tags on every page, the first save and removal (B4); §8 lifecycle, samples, caching, validation, rendering, stage, browser proofs (B0–B7). `shop_index`, `shop_category` and `product_loop` are C2; they reuse `loops()`, the card rules, `loop_cards` and the admin's card legality.
- **Placeholders:** none; B7's CSS depends on the user's ruling on measured differences, by design.
- **Type consistency:** `loops()` rows `{type, card, items}` in the contract, the validator, the session and the admin; `link` on target rows and `LayoutRow`; `loop_cards(card, items, name)` in the extension, `entry_loop.twig` and C2's seam.
