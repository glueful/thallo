# Sections and Templates — Release S2 (layout sections and templates) Implementation Plan

> Amended 2026-09-30 after plan review: `LayoutTarget` carries the target's schema fields whole and diagnostics use field labels (L2, L3, a filterable scalar joins the matrix); the validator's fragment check returns normalised blocks and the library validates and returns exactly what it serves, checking the target once (L2, L4); saved layout sections are stored with their bindings normalised against the layout they came from, and save and rename answer from the stored row (L5); the candidate check matches the server's binding rules through shared PHP/TypeScript cases (L8); thumbnails are the real stage rendering through each surface's own frame, cropped (L7); the browser proof uses generated fixtures for the inserted and replaced documents, with deterministic block ids and no fallback stage (L10). The spec was amended to match the three planning rulings. Second review: the crop measures an annotated section's rendered children, since its wrapper is `display: contents` (L7); Entry content's `body` fallback is kept in the candidate check, with shared cases for a `content`-named body and a rich-text body (L8); thumbnails run on isolated targets with no eligible sample, proven from a database that has published content (L7).

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The layout editor (**Site › Layouts**) gets the Design view's Blocks / Sections / Templates switch. Every surface — single post, listing, archive, and with Commerce on the product page, the shop home and the shop categories — offers ready-made sections and three whole templates built for the layout's own content type. A template replaces the layout (blocks and Frame settings) in one undo step, asking first when there are unsaved changes. An editor can save a section from a layout, field blocks included, and reuse it in any layout of the same kind; a section showing a field the target type lacks is refused with the field named.

**Architecture:** A pattern's place gains `layout` with a `surface`. Core ships the `entry`, `listing` and `archive` layout patterns in a new `LayoutPatterns` class; commerce ships `product`, `shop_index` and `shop_category` through a new `LayoutPatternContributor` contract on the S1 registry. Layout patterns are **built for a target**: a builder receives a `LayoutTarget` (the surface, the target and the target type's fields) and returns a tree bound to that type's real fields, or null when it does not fit. `PatternLibrary::forLayout($surface, $target)` builds, validates (templates through the real `LayoutValidator`, sections through a new `LayoutValidator::fragment`, returning the validated, normalised trees) and returns them with the saved sections of that surface; `GET /patterns?surface=&target=` serves them. The admin layout page merges them with the shipped general page sections, hands them to the stage editor through new `StageHost` hooks (`patterns`, `candidateCheck`, `pageReplace`), checks every section insert against the complete candidate document (required blocks once, field bindings), and replaces the working copy with a template in one transaction. Saved sections gain a nullable `surface` column and scope `layout`; saving, renaming and deleting authorize by scope inside the controller.

**Tech Stack:** PHP 8.3 (Glueful, PostgreSQL, PHPUnit), Twig 3, Nuxt UI admin (Vue 3, pinia-colada, vitest, Playwright e2e in `admin/e2e`), thumbnails via `scripts/build-pattern-thumbnails` and `tools/style-proofs/capture-page.js`.

**Spec:** `docs/internal/superpowers/specs/2026-09-29-layout-sections-templates-design.md` (amended 2026-09-30). This release is §9 item 2 (S2): §3 (the layout place, sources, filtering), §3.1 (identity for layout patterns), §3.2 (targets and schema-aware patterns), §4 (the layout editor's Sections and Templates), §5 (saved sections in layouts), §6 (the layout patterns and their thumbnails), §7 (the S2 tests) and §8 (docs). S1 shipped as 1.0.0-beta.71: the contributor registry, `PatternBlocks` in contracts, the commerce page contributor, the `requires` hint and the thumbnail pipeline.

## Rulings made while planning (from the code)

- **The browser proof sees real stages for the new documents.** No fallback stage: serving the baseline after the editor accepted a different document is the stale-fixture problem closed before. The e2e build queues the block ids the admin will mint (`window.__thalloE2eBlockIds`, read by `nextInstanceId()` under `VITE_E2E`), so the inserted section's and the template's documents are known in advance; `scripts/build-builder-proof-fixtures` renders a stage for each (new scenarios in `admin/e2e/layouts-scenarios.json`), and the proof asserts the refreshed stage shows them with `unmatched` empty.
- **Layout templates are whole trees, not section lists.** Spec §3.1 says a layout template names sections of its surface or page sections. But every template must hold its surface's required block (the primary body's Entry content block, the Entry list, the Product buy box, the Product list), and none of the §6 sections holds one — so a layout template cannot be assembled from §6 sections. A layout template is therefore a builder that returns the whole tree for a target, plus its settings payload. It holds no references, so the reference check of §3.1 applies to page templates only (as S1 built it). Shared parts are shared through PHP builder functions, not slugs. Cost if wrong: a template's parts cannot be reused by slug; nothing a person sees changes.
- **The Product hero section holds no buy box.** §6 lists "Product hero (gallery, name, rating, price, buy box)". The Product buy box is required exactly once and a layout's editor refuses to delete it (`requiredReason`), so every product layout always holds one, and a section holding a second one could never be inserted. The Product hero is the gallery beside the name, rating and price; its description says the layout's buy box stays where it is. Cost if wrong: one block to add to one section.
- **Builders see a `LayoutTarget`.** Packs may reference only `Thallo\Contracts`, so the builder input is a contracts class: `surface`, `target` and `fields` — the target type's schema fields **exactly as stored** (`list<array<string,mixed>>`, schema order; empty for the commerce surfaces, whose blocks bind no field). Nothing is dropped, so core rebuilds the very `ContentTypeSchema` (`ContentTypeSchema::fromArray`) and reuses `Starters`; a filterable scalar keeps its `filter_type`, which `FieldDefinition` requires. A pack builder reads only `name`, `type` and `format`. Cost if wrong: none — it is the stored schema.
- **One registry, two kinds of contributor.** `PatternContributorRegistry` gains `registerLayout(LayoutPatternContributor)` and `layoutContributors()`. Slugs stay unique across everything: core page and region patterns, core layout patterns (`LayoutPatterns::slugs()`, seeded as `core`), page contributors and layout contributors. Layout contributor ids are unique among layout contributors (commerce registers `thallo.commerce` in both lists). A layout section or template must name one of the six known surfaces. Cost if wrong: none; collisions are programming errors.
- **What the per-target endpoint returns.** `GET /patterns?surface={s}&target={t}` returns the layout place only: the shipped sections of that surface that fit the target, its templates that pass the target's `LayoutValidator`, and the saved sections of that surface. The shipped general page sections come from `GET /patterns` as today; the layout page merges them (§4's order: surface sections, then shipped page sections, then saved sections of the surface). `GET /patterns` (no query) never returns layout-place entries. An unknown or unregistered surface answers 422 `{surface: "unknown layout surface '…'"}`; a target that cannot have a layout answers 200 with an empty list. Cost if wrong: one merge moves to the server.
- **Saved sections are listed, not filtered, by binding.** A saved layout section of the surface is offered for every target of that surface. Its card is refused at insert time with the field named (§3.2, §5) — not hidden — so the editor learns why. Shipped sections are filtered server-side: they are built for the target and never bind a missing field.
- **The client learns the target's binding rules from the session.** `LayoutPreviewController::session` adds `bindable` (field name ⇒ type, `text:rich` for rich text, from `LayoutSurface::bindable`), `field_labels` (field name ⇒ its `FieldDefinition::$label`, or the humanised name when it has none), `bindings` (binding block type ⇒ accepted field types, the validator's `BINDINGS`), `default_fields` (binding block type ⇒ the field an unbound block binds, `DEFAULT_FIELD`), `format_needs` (an `entry_field` format ⇒ the field types it needs, `FORMAT_NEEDS`) and `type_name` (the target content type's `name`, null for the commerce surfaces). The three constants become public. The candidate check reads these; no rule is written twice. A field the target lacks has no label there, so a saved section's missing field is named humanised (`subtitle` → "Subtitle").
- **The candidate check covers pattern sections, with the server's binding rules.** §4 names section inserts. The stage editor runs `host.candidateCheck` on the complete candidate document for every pattern section placement (click, Enter, drag) and in `paletteClickable` for `pattern:` keys, so the card is dimmed with the reason before any click. Plain block tiles keep today's behaviour (the server refuses a second required block on apply).
  - **What it enforces is what `validate()` enforces on the same document**, except that the preflight lets a candidate lack a required block — an allowance for a working copy that is mid-edit, nothing more: apply and save both run the full `validate()` and refuse an incomplete layout, as today: only **binding blocks** (the keys of `bindings`) are read as bindings; an unbound binding block binds its `default_fields` field when the type has it with a compatible type, and is otherwise unbound — **except Entry content**, which the server still counts as showing `body` (`assertBindings`' fallback), so an unbound Entry content on a type whose `body` is missing or not a blocks field is refused; a bound field must exist and be of an accepted type; an `entry_field`'s `format` must suit its field (`format_needs`); no `entry_content` field is placed twice (the primary body or any other blocks field); a required block without a field appears at most once.
  - **Partial and complete are different questions.** `LayoutValidator::fragment()` answers "does this part fit this target" (palette, cards, bindings — no counts); `validate()` answers "is this a whole layout"; the candidate check answers "is the whole document, with this part inserted, one the server will accept on apply, apart from what is still missing".
  - **Parity is tested, not hoped for.** One case file, `tests/fixtures/layouts/candidate-cases.json`, feeds a PHP test (each case through `validate()`, ignoring only the "must show" missing-block errors) and a vitest spec (each case through `checkLayoutCandidate`); both must agree on accepted versus refused, and the refused ones on the rule. A section the preflight accepts does not fail apply for any of these rules. Cost if wrong: one rule to add to both sides and a case to the file.
- **Template replacement reuses the Header & footer path.** `replaceWithPage(slug, field)` already removes the field's blocks and inserts the template's in one `applyDrop` transaction (one undo entry; redo replays the same ops, so the minted ids come back — `editor-ops.spec.ts` "reuses ids on redo"). A new `StageHost.pageReplace(pattern)` hook appends a `SetPageSettings` op on `_layout_settings` to the same transaction. The confirm is an inline `role="alertdialog"` like the Header & footer page's, shown only while `dirty` is true (`dirty` compares against the history's saved position, which is the saved layout, or the starter when nothing is saved — exactly §4's rule).
- **A layout section is validated and normalised against the layout it came from.** The request carries the `target` it was saved from (validated, never stored). `LayoutValidator::fragment($surface, $target, [$block])` checks it (palette, cards, `forLayouts()` blocks, bindings) and returns its blocks **normalised**: an unbound binding block is written bound to the field the server would choose there (`bindDefaults`). That normalised block is what is stored, so reusing the section elsewhere never picks a different field. Insert and apply re-check against the real target later.
- **Saved-section responses come from the stored row.** Today `SavedSectionController::entry()` finds the saved entry in `PatternLibrary::all()`, which no longer holds layout sections. A shared mapper, `PatternLibrary::savedEntry(array $row): ?array`, turns a stored row into its library entry; `savedSections()`, `forLayout()` and the controller's save and rename responses all use it (`SavedSectionRepository::find($id)` feeds it).
- **Permissions move into the controller.** `POST`, `PATCH` and `DELETE /saved-sections` keep a route floor of `content_permission:content.view` (any editor) and the controller decides: a `layout` section needs `templates.manage`; a page or region section needs `content.manage`. Saving reads the request's scope; renaming and deleting read the stored row's scope (`SavedSectionRepository::find`). A refusal is 403 `{code: FORBIDDEN}`, like the middleware's. Listing stays `content.view`.
- **Thumbnails of layout patterns are the real stage rendering.** Each surface's frame differs — the entry, listing and archive frames wrap blocks in `.thallo-layout.thallo-layout--{surface}`, the product frame in `.shop-product.shop-product--layout`, the shop frames in `.shop-index.shop-index--layout` and `.shop-category.shop-category--layout` — and the Frame settings reach the page only through the real presentation path (`presentationContext` for listing and archive, `FramePresentation::fixed` for the others). So a picture is not composed by hand: the build opens a real layout session (`LayoutPreviewController::session`), applies the document (`apply`), and renders the stage (`RenderController::preview` with `canvas=1`) — as `scripts/build-builder-proof-fixtures` renders its `stage-*.html` — then makes the page self-contained (the stylesheets and images inlined, the preview bridge script removed, as `build-shop-block-proof-fixtures`' `$selfContained` does) and captures a **crop**: a template to its frame element, a section to its root block. The stage wraps each block in `.thallo-preview-block[data-thallo-block]`, which `preview.css` sets to `display: contents` — a box-less element — so the crop measures the **union of the rendered boxes of the element's descendants** when the element itself has none. A section is applied as the surface's starter with the section appended (the starter supplies the required block; no section holds one). `capture-page.js` gains a `selector` crop.
- **Thumbnails show the placeholder sample, whatever the database holds.** A session with no sample asked for still picks the newest published one (`pickSample`), so the build pictures patterns on **isolated targets**: in their own rolled-back transaction, after the S1 page pictures (whose product fixtures they keep), it creates fresh types (`thumb_post`, with `thumb_category` for its archive field) that have no entries, and clears the products the product and shop surfaces could sample (`ShopPageSeed::clear()`). `layout_stage_html` refuses to render when the surface still offers a sample for the target ("sample '…' would replace the placeholder"). Targets: `thumb_post` (entry, listing), `thumb_post:categories` (archive), `@site` (commerce).
- **The thumbnail gate widens with the patterns.** `PatternLibrary::layoutSlugs()` lists every shipped layout pattern slug of the registered surfaces. `testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan` adds it to `all()`'s slugs in the same commit as the pictures (Task L7). Until then no layout slug is gated, so L3 and L6 land without pictures.

## Global Constraints

- Packs reference only `Thallo\Contracts\…`, never `Thallo\Core\…` (`composer boundaries`).
- Layout pattern slugs: core's start `entry-`, `listing-`, `archive-`; commerce's start `product-`, `shop-index-`, `shop-category-`. Every layout pattern uses only existing block types.
- No site-specific value ships in a pattern: no field name but those read from the target, no URL but `#`.
- Copy is sentence case with typographic quotes and dashes, like `StarterPatterns`.
- A changelog bullet rides in the commit of the change, under `## [Unreleased]` (re-added — beta.71 dropped the empty heading).
- Gates: `COMPOSER_PROCESS_TIMEOUT=0 composer test` and once with `API_USE_PREFIX=true`; harness classes each in their own process with `THALLO_TENANCY_DEV_LINK=1`; phpcs judged by exit code; `composer boundaries`; admin `pnpm type-check`, lint, `pnpm fmt:check`, `pnpm exec oxfmt` on touched files; admin vitest; e2e on eight workers; runtime-browser and style-proofs specs; `composer test:distribution`, `composer test:skeleton`; a new `tests/Integration` top-level entry joins an `INTEGRATION_SHARD_*` list in `ci.yml` (none is planned).

## Review Focus

1. **A saved section from one type inserted into another type's layout that lacks its field** (a Posts section showing `subtitle`, inserted into the Pages layout). The card is refused with "This section shows “Subtitle”, which Pages doesn't have"; clicking or dragging it changes neither the document nor the undo history; apply re-checks on the server. Pinned in Task L8 (candidate check) and Task L9 (the page).
2. **A template applied, then undone, then redone.** Undo brings back the old blocks *and* the old Frame settings in one step; redo restores the template with the same block ids, not fresh ones. Pinned in Task L9.
3. **Commerce switched off with product or shop layout sections saved.** The product and shop surfaces' shipped patterns and saved sections disappear (`GET /patterns?surface=product…` answers 422); the rows are kept and return when Commerce is back. Pinned in Task L5 and Task L6.
4. **A content type with no body, no title or no cover.** Every shipped template for it either passes the target's `LayoutValidator` or is not offered — never offered and then refused on Save; the Cover band is not offered for a type without a cover. Pinned in Task L3 (the type-shape matrix) and Task L4 (the library).
5. **The wrong editor renaming or deleting a saved section.** A user with only `content.manage` gets 403 renaming or deleting a layout section, and one with only `templates.manage` gets 403 on a page section; the rows are untouched. Pinned in Task L5.

## Shared contracts (named once, used by every task)

```php
namespace Thallo\Contracts\Patterns;

/** What a layout pattern is built for (sections and templates design §3.2). */
final class LayoutTarget
{
    /** @param list<array<string,mixed>> $fields the target type's schema fields exactly as stored, in schema order ([] off a content type) */
    public function __construct(
        public readonly string $surface,
        public readonly string $target,
        public readonly array $fields,
    ) {
    }
}

/** A layout section: one block tree built for a target, or null when it does not fit it. */
final class LayoutSection
{
    /** @param \Closure(LayoutTarget): (array<string,mixed>|null) $build */
    public function __construct(
        public readonly string $slug,
        public readonly string $surface,
        public readonly string $label,
        public readonly string $category,
        public readonly string $description,
        public readonly \Closure $build,
    ) {
    }
}

/** A layout template: the whole layout built for a target, and the Frame settings it brings. */
final class LayoutTemplate
{
    /**
     * @param \Closure(LayoutTarget): (list<array<string,mixed>>|null) $build
     * @param array<string,string> $settings width ('contained'|'full'), header and footer ('default'|'hidden'); an omitted key is the default
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $surface,
        public readonly string $label,
        public readonly string $description,
        public readonly \Closure $build,
        public readonly array $settings = [],
    ) {
    }
}

interface LayoutPatternContributor
{
    public function id(): string;

    /** @return list<LayoutSection> */
    public function layoutSections(): array;

    /** @return list<LayoutTemplate> */
    public function layoutTemplates(): array;
}

interface PatternContributorRegistry
{
    public function register(PatternContributor $contributor): void;          // S1

    /** @return list<PatternContributor> */
    public function all(): array;                                             // S1

    /** @throws \LogicException on a duplicate id, a slug collision or an unknown surface */
    public function registerLayout(LayoutPatternContributor $contributor): void;

    /** @return list<LayoutPatternContributor> */
    public function layoutContributors(): array;
}
```

- A library entry gains `surface: ?string` and `settings: ?array<string,string>` on **every** entry (null for page and region patterns and for layout sections; a layout template's settings payload). `scope` gains `'layout'`. `PatternData` gains `?string $surface` and `?array $settings`, in that order after `region`.
- Admin, `admin/src/queries/patterns.ts`: `PatternScope = 'page' | 'region' | 'layout'`; `Pattern` gains `surface?: string | null` and `settings?: Record<string, string> | null`; `SectionPlace` gains `{ scope: 'layout'; surface: string }`.
- Session (`LayoutSession` in `admin/src/queries/layouts.ts`) gains `bindable: Record<string, string>`, `fieldLabels: Record<string, string>`, `bindings: Record<string, string[]>`, `defaultFields: Record<string, string>`, `formatNeeds: Record<string, string[]>`, `typeName: string | null` (server keys `bindable`, `field_labels`, `bindings`, `default_fields`, `format_needs`, `type_name`).
- `LayoutValidator::fragment(string $surface, string $target, array $blocks): array{blocks: list<array<string,mixed>>, errors: array<string,string>}` — errors keyed by dot path (`[]` when it fits); `blocks` normalised (default bindings written). `LayoutValidator::targetError(string $surface, string $target): ?string` — the reason a target cannot have a layout (unknown surface, unoffered or closed target), else null.

---

## Task L1: the layout pattern contracts and their identity

**Files:**
- Create: `packages/thallo-contracts/src/Patterns/LayoutTarget.php`, `LayoutSection.php`, `LayoutTemplate.php`, `LayoutPatternContributor.php` (exactly as in Shared contracts)
- Modify: `packages/thallo-contracts/src/Patterns/PatternContributorRegistry.php` (the two methods), `core/src/Content/Patterns/DefaultPatternContributorRegistry.php`
- Create (stub, filled in L3): `core/src/Content/Patterns/LayoutPatterns.php` with `public const SURFACES = ['entry', 'listing', 'archive', 'product', 'shop_index', 'shop_category'];` and `public static function slugs(): array { return []; }` (`array<string,string>` slug ⇒ surface)
- Test: `tests/Unit/Patterns/PatternContributorRegistryTest.php`

**Interfaces:** Produces the contracts above; `DefaultPatternContributorRegistry::registerLayout()`, `layoutContributors()`, and `ownerOf()` covering layout slugs. Consumes S1's registry.

- [ ] **Step 1: Write the failing tests** in `PatternContributorRegistryTest`, beside the S1 ones, with a helper that builds a layout contributor from sections and templates:

```php
private static function layoutContributor(string $id, array $sections = [], array $templates = []): LayoutPatternContributor
{
    return new class ($id, $sections, $templates) implements LayoutPatternContributor {
        public function __construct(private string $id, private array $sections, private array $templates) {}
        public function id(): string { return $this->id; }
        public function layoutSections(): array { return $this->sections; }
        public function layoutTemplates(): array { return $this->templates; }
    };
}

private static function layoutSection(string $slug, string $surface = 'product'): LayoutSection
{
    return new LayoutSection($slug, $surface, 'Label', 'Product', 'A description.', static fn (): array => PatternBlocks::heading('Hi', 'h2', 'start'));
}

private static function layoutTemplate(string $slug, string $surface = 'product'): LayoutTemplate
{
    return new LayoutTemplate($slug, $surface, 'Label', 'A description.', static fn (): array => [], ['width' => 'full']);
}

public function testALayoutContributorIsListedAndOwnsItsSlugs(): void
{
    $registry = new DefaultPatternContributorRegistry();
    $registry->registerLayout(self::layoutContributor('a', [self::layoutSection('product-x')], [self::layoutTemplate('product-y')]));
    self::assertCount(1, $registry->layoutContributors());
    self::assertSame('a', $registry->ownerOf('product-x'));
    self::assertSame('a', $registry->ownerOf('product-y'));
}

public function testALayoutSlugMayNotTakeAPageSlugOrAnotherContributors(): void
{
    $registry = new DefaultPatternContributorRegistry();
    $registry->register(self::contributor('pages', [self::section('x-hero')]));
    foreach (['faq' => 'core', 'x-hero' => 'pages'] as $slug => $owner) {
        try {
            $registry->registerLayout(self::layoutContributor('b', [self::layoutSection($slug)]));
            self::fail("{$slug} must be refused");
        } catch (\LogicException $e) {
            self::assertSame("Pattern '{$slug}' of 'b' is already taken by '{$owner}'.", $e->getMessage());
        }
    }
    $registry->registerLayout(self::layoutContributor('c', [self::layoutSection('product-z')]));
    $this->expectExceptionMessage("Pattern 'product-z' of 'd' is already taken by 'c'.");
    $registry->registerLayout(self::layoutContributor('d', [], [self::layoutTemplate('product-z')]));
}

public function testALayoutPatternNamesAKnownSurface(): void
{
    $registry = new DefaultPatternContributorRegistry();
    $this->expectExceptionMessage("Pattern 'x-thing' of 'a' names the surface 'basket', which no layout has.");
    $registry->registerLayout(self::layoutContributor('a', [self::layoutSection('x-thing', 'basket')]));
}

public function testALayoutContributorIdIsUniqueAndRegistrationIsWhole(): void
{
    $registry = new DefaultPatternContributorRegistry();
    $registry->registerLayout(self::layoutContributor('a'));
    try {
        $registry->registerLayout(self::layoutContributor('a'));
        self::fail('a second contributor with the id must be refused');
    } catch (\LogicException $e) {
        self::assertSame("Layout pattern contributor 'a' is already registered.", $e->getMessage());
    }
    try {
        $registry->registerLayout(self::layoutContributor('b', [self::layoutSection('product-ok'), self::layoutSection('shop-index-bad', 'basket')]));
    } catch (\LogicException) {
    }
    self::assertNull($registry->ownerOf('product-ok'), 'nothing of a refused contributor is kept');
}

public function testAPageContributorAndALayoutContributorMayShareAnId(): void
{
    $registry = new DefaultPatternContributorRegistry();
    $registry->register(self::contributor('thallo.commerce', [self::section('shop-a')]));
    $registry->registerLayout(self::layoutContributor('thallo.commerce', [self::layoutSection('product-a')]));
    self::assertSame('thallo.commerce', $registry->ownerOf('product-a'));
}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Unit/Patterns/PatternContributorRegistryTest.php`. Expected: FAIL (classes not found).
- [ ] **Step 3: Implement.** Write the four contract files. In `DefaultPatternContributorRegistry`: seed `owners` also from `LayoutPatterns::slugs()` as `core` in the constructor; add `private array $layoutContributors = []`; `registerLayout()` refuses a duplicate id with "Layout pattern contributor '{$id}' is already registered.", claims every section's and template's slug with the S1 `claim()` (same message), refuses a surface not in `LayoutPatterns::SURFACES` with "Pattern '{$slug}' of '{$id}' names the surface '{$surface}', which no layout has.", and only then adds the claims to `owners` and the contributor to the list (all or nothing, like `register()`); `layoutContributors()` returns `array_values`.
- [ ] **Step 4: Run** it again. Expected: PASS, and the S1 tests in the file still pass.
- [ ] **Step 5: Commit** (`composer boundaries`, phpcs on touched files) as `feat(patterns): layout pattern contributors, with slugs unique across every place`.

## Task L2: the validator checks and normalises a section for a target, and the session tells the admin the target's binding rules

**Files:**
- Modify: `core/src/Content/Layouts/LayoutValidator.php` (make `BINDINGS`, `DEFAULT_FIELD`, `FORMAT_NEEDS` public; add `fragment` and `targetError`), `core/src/Http/Controllers/LayoutPreviewController.php` (session payload), `admin/src/queries/layouts.ts` (`LayoutSession`)
- Test: `tests/Integration/Content/Layouts/LayoutFragmentTest.php` (new file in an existing directory)

**Interfaces:** Produces `LayoutValidator::fragment()` and `targetError()` (Shared contracts) and the session keys `bindable`, `field_labels`, `bindings`, `default_fields`, `format_needs`, `type_name`.

- [ ] **Step 1: Write the failing tests.** `LayoutFragmentTest extends AppTestCase` (use `SyncsBlockStyleDeclarations`). Create, through `ContentTypeRepository::create` as `tests/Integration/Content/Layouts/LayoutSaveTest.php` does (copy its set-up), a type `lf_post` named "LF posts" — `title` string (label "Headline"), `body` blocks, `cover` asset, `categories` filterable reference — and a type `lf_page` named "LF pages" with `title` and `body` only.

```php
public function testASectionThatFitsHasNoErrorsAndComesBackNormalised(): void
{
    $block = ['type' => 'entry_cover', 'data' => ['aspect' => '16:9'], 'settings' => []];
    $result = $this->validator()->fragment('entry', 'lf_post', [$block]);
    self::assertSame([], $result['errors']);
    self::assertSame('cover', $result['blocks'][0]['data']['field'], 'an unbound Cover is written bound to the field the server chose');
}

public function testAMissingFieldIsNamed(): void
{
    $block = ['type' => 'entry_field', 'data' => ['field' => 'subtitle', 'format' => 'text'], 'settings' => []];
    self::assertSame("this type has no field 'subtitle'", $this->validator()->fragment('entry', 'lf_page', [$block])['errors']['blocks.0.data.field'] ?? null);
}

public function testAnIncompatibleFieldIsRefused(): void
{
    $block = ['type' => 'entry_cover', 'data' => ['field' => 'title'], 'settings' => []];
    self::assertSame("'title' cannot be shown by this block", $this->validator()->fragment('entry', 'lf_post', [$block])['errors']['blocks.0.data.field'] ?? null);
}

public function testAFormatTheFieldCannotTakeIsRefused(): void
{
    $block = ['type' => 'entry_field', 'data' => ['field' => 'title', 'format' => 'date'], 'settings' => []];
    self::assertArrayHasKey('blocks.0.data.format', $this->validator()->fragment('entry', 'lf_post', [$block])['errors']);
}

public function testTheSurfacesPaletteAndCardsStillApply(): void
{
    $loop = ['type' => 'entry_loop', 'data' => ['card' => []], 'settings' => []];
    self::assertNotSame([], $this->validator()->fragment('entry', 'lf_post', [$loop])['errors'], 'an Entry list is not in the post palette');
    $title = ['type' => 'entry_title', 'data' => ['level' => 'h2'], 'settings' => []];
    self::assertMatchesRegularExpression("~^'entry_title' goes inside the .+'s card$~", array_values($this->validator()->fragment('listing', 'lf_post', [$title])['errors'])[0] ?? '');
}

public function testRequiredBlocksAreNotAsked(): void
{
    // A fragment is a part of a layout: it need not hold the required Entry content block.
    $title = ['type' => 'entry_title', 'data' => ['level' => 'h1'], 'settings' => []];
    self::assertSame([], $this->validator()->fragment('entry', 'lf_post', [$title])['errors']);
}

public function testTargetError(): void
{
    self::assertNull($this->validator()->targetError('entry', 'lf_post'));
    self::assertSame("unknown layout surface 'basket'", $this->validator()->targetError('basket', 'x'));
    self::assertNotNull($this->validator()->targetError('entry', 'no_such_type'));
}

public function testTheSessionCarriesTheTargetsBindingRules(): void
{
    $data = $this->session('entry', 'lf_post');
    self::assertSame(['title' => 'string', 'body' => 'blocks', 'cover' => 'asset', 'categories' => 'reference'], $data['bindable']);
    self::assertSame(['title' => 'Headline', 'body' => 'Body', 'cover' => 'Cover', 'categories' => 'Categories'], $data['field_labels']);
    self::assertSame(['asset'], $data['bindings']['entry_cover']);
    self::assertSame('cover', $data['default_fields']['entry_cover']);
    self::assertSame(['datetime'], $data['format_needs']['date']);
    self::assertSame('LF posts', $data['type_name']);
}
```

`session()` calls `LayoutPreviewController::session(new LayoutSessionData(...))` and decodes `data`; `validator()` is the container's `LayoutValidator`. Fields other than `title` carry no label, so their labels are humanised.

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Content/Layouts/LayoutFragmentTest.php`. Expected: FAIL (`fragment` undefined, session keys missing).
- [ ] **Step 3: Implement.**
  - `BINDINGS`, `DEFAULT_FIELD` and `FORMAT_NEEDS` become public constants.
  - `targetError()`: the two checks `validate()` opens with (an unknown surface: "unknown layout surface '{$surface}'"; `LayoutTargets::find` missing or disabled: its reason, or "'{$target}' cannot have a layout"). `validate()` calls it instead of repeating them.
  - `fragment()`: `targetError()` non-null → `['blocks' => $blocks, 'errors' => ['target' => $reason]]`; else the palette check over `walk()` against `allowedTypes($surface)`, then `cardErrors($surface, $target, $blocks)`, then `FieldValidator::forLayouts()->validate(ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]), ['blocks' => $blocks], true)` catching `ValidationException` into the errors (strict validation wants ids: a block without one gets a temporary 12-character id for the run, removed again from the returned blocks, so an id-less pattern tree comes back id-less and a saved block keeps its own), then `bindDefaults()` over the blocks, then the **per-block** binding errors. Extract the per-block part of `assertBindings()` (the missing field, the incompatible type and the `entry_field` format checks) into a private `bindingErrors(array $blocks, array $bindable): array` that both call, so the wording stays one; `assertBindings()` keeps the duplicate-content, required and never-hidden checks on top. Return the normalised blocks with the errors.
  - `LayoutPreviewController::session`: add `'bindable' => $surface->bindable($input->target)`, `'field_labels'` (for each bindable field, its `FieldDefinition::$label` from the target type's schema, else the humanised name — `ucfirst(str_replace('_', ' ', $name))`), `'bindings' => LayoutValidator::BINDINGS`, `'default_fields' => LayoutValidator::DEFAULT_FIELD`, `'format_needs' => LayoutValidator::FORMAT_NEEDS`, `'type_name'` (the name of `LayoutSaver::typeOf($input->surface, $input->target)`'s content type via `ContentTypeRepository::findBySlug`, or null). Inject `ContentTypeRepository` as an optional autowired constructor argument. Update the doc text listing the session keys.
  - `admin/src/queries/layouts.ts`: `LayoutSession` gains the six keys of Shared contracts; the mapper reads each with an empty default (`{}` or `null`).
- [ ] **Step 4: Run** the new test, then `vendor/bin/phpunit tests/Integration/Content/Layouts` and `cd admin && pnpm exec vitest run src/__tests__/layout-host.spec.ts src/__tests__/layout-editor.spec.ts`. Expected: PASS (the vitest session builders gain the keys where TypeScript asks for them).
- [ ] **Step 5: Commit** (phpcs, `pnpm type-check`, fmt) as `feat(layouts): check and normalise a section against a layout's target, and tell the editor the target's binding rules`.

## Task L3: core's layout patterns, built for every type shape

**Files:**
- Modify: `core/src/Content/Patterns/LayoutPatterns.php` (the stub from L1)
- Test: `tests/Integration/Content/Layouts/LayoutPatternsTest.php`

**Interfaces:** Produces `LayoutPatterns::sections(): list<LayoutSection>`, `LayoutPatterns::templates(): list<LayoutTemplate>` (core's, surfaces `entry`, `listing`, `archive`) and `LayoutPatterns::slugs(): array<string,string>` (every slug ⇒ surface, used by L1's registry). Consumes the L1 contracts, `Starters`, `PatternBlocks`.

- [ ] **Step 1: Write the failing test.** `LayoutPatternsTest extends AppTestCase` builds six types (§3.2, plus a filterable scalar) through `ContentTypeRepository::create` with `public_delivery => true`, and switches their listing pages on through `GeneralSettings` as `LayoutSaveTest`/the listing tests do:

| slug | fields |
|---|---|
| `lp_body` | `title` string, `excerpt` string, `cover` asset, `categories` reference (filterable, to a `lp_cat` type), `body` blocks |
| `lp_content` | `title` string, `content` blocks, `cover` asset |
| `lp_rich` | `title` string, `text` text (format `rich`) |
| `lp_nobody` | `title` string, `summary` string |
| `lp_nocover` | `title` string, `body` blocks |
| `lp_filter` | `title` string, `sku` string (`filterable: true`, `filter_type: 'string'`), `price` number (`filterable: true`, `filter_type: 'number'`), `body` blocks |

```php
/** Every shipped template, for every type shape, is a layout its surface accepts — or is not built. */
public function testEveryTemplatePassesItsSurfaceForEveryTypeShape(): void
{
    $validator = $this->container()->get(LayoutValidator::class);
    $built = 0;
    foreach (LayoutPatterns::templates() as $template) {
        foreach ($this->targets($template->surface) as $target) {
            $tree = ($template->build)($this->layoutTarget($template->surface, $target));
            if ($tree === null) {
                continue;
            }
            try {
                $validator->validate($template->surface, $target, self::withIds($tree), $template->settings, [], false);
            } catch (ValidationException $e) {
                self::fail("{$template->slug} for {$target}: " . json_encode($e->errors()));
            }
            $built++;
        }
    }
    self::assertGreaterThanOrEqual(30, $built);
}

public function testEverySectionFitsWhereItIsBuilt(): void
{
    $validator = $this->container()->get(LayoutValidator::class);
    foreach (LayoutPatterns::sections() as $section) {
        foreach ($this->targets($section->surface) as $target) {
            $block = ($section->build)($this->layoutTarget($section->surface, $target));
            if ($block !== null) {
                self::assertSame([], $validator->fragment($section->surface, $target, [$block])['errors'], "{$section->slug} for {$target}");
            }
        }
    }
}

public function testSchemaAwareParts(): void
{
    $cover = $this->section('entry-cover-band');
    self::assertNotNull(($cover->build)($this->layoutTarget('entry', 'lp_body')));
    self::assertNull(($cover->build)($this->layoutTarget('entry', 'lp_rich')), 'no cover field, no cover band');

    $classic = $this->template('entry-classic');
    $content = self::find(($classic->build)($this->layoutTarget('entry', 'lp_content')), 'entry_content');
    self::assertSame('content', $content['data']['field'], 'the primary body is the type’s own');
    self::assertNull(self::find(($classic->build)($this->layoutTarget('entry', 'lp_rich')), 'entry_content'));
    self::assertSame('text', self::find(($classic->build)($this->layoutTarget('entry', 'lp_rich')), 'entry_field')['data']['field']);
}

public function testTheClassicTemplatesAreTodaysStarters(): void
{
    $surfaces = $this->container()->get(LayoutSurfaceRegistry::class);
    self::assertSame($surfaces->get('entry')->starter('lp_body'), ($this->template('entry-classic')->build)($this->layoutTarget('entry', 'lp_body')));
    self::assertSame($surfaces->get('listing')->starter('lp_body'), ($this->template('listing-horizontal')->build)($this->layoutTarget('listing', 'lp_body')));
    self::assertSame($surfaces->get('archive')->starter('lp_body:categories'), ($this->template('archive-horizontal')->build)($this->layoutTarget('archive', 'lp_body:categories')));
}

public function testSlugsAreTheShippedOnes(): void
{
    $slugs = array_merge(array_map(fn ($s) => $s->slug, LayoutPatterns::sections()), array_map(fn ($t) => $t->slug, LayoutPatterns::templates()));
    self::assertSame(array_combine($slugs, array_map(fn ($p) => $p->surface, [...LayoutPatterns::sections(), ...LayoutPatterns::templates()])), LayoutPatterns::slugs());
    self::assertCount(16, $slugs);
}
```

Helpers: `targets('entry'|'listing')` = the six slugs; `targets('archive')` = `['lp_body:categories']`; `layoutTarget($surface, $target)` is `LayoutPatterns::targetFor($surface, $target, $type['schema'])` for the target's type (the archive's type is the part before `:`) — the same call L4's library makes; `withIds()` gives every block a 12-character id recursively; `find($tree, $type)` walks nested block lists.

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Content/Layouts/LayoutPatternsTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** `LayoutPatterns` (use `PatternBlocks as B`; the private helpers below build trees without ids; `$schema = ContentTypeSchema::fromArray($t->fields)`):

```php
public const SURFACES = ['entry', 'listing', 'archive', 'product', 'shop_index', 'shop_category'];

/** The target as a builder sees it: the type's schema fields exactly as stored, nothing dropped. */
public static function targetFor(string $surface, string $target, ?array $schema): LayoutTarget
{
    return new LayoutTarget($surface, $target, array_values(array_filter(
        $schema ?? [],
        static fn ($field): bool => is_array($field) && isset($field['name'], $field['type']),
    )));
}

/** @return array<string,string> */
public static function slugs(): array
{
    $out = [];
    foreach ([...self::sections(), ...self::templates()] as $pattern) {
        $out[$pattern->slug] = $pattern->surface;
    }
    return $out;
}
```

**Entry** (the target's schema drives every part — `ContentTypeSchema::fromArray($t->fields)`, which accepts every stored field, a filterable scalar with its `filter_type` included; `$ref` = first filterable reference field, `$cover` = first asset field, `$title` = a `title` string field, `$body` = `Starters::primaryBody($schema)`, `$rich` = first rich text field, `$article` as `Starters::forSchema` decides — write private resolvers mirroring `Starters::forSchema`'s rules, and a private `content()` returning `entry_content {field: $body}` when there is a body, else `entry_field {field: $rich, format: 'rich'}`, else null):

| slug | kind | built as | null when |
|---|---|---|---|
| `entry-classic` | template, settings `[]` | `Starters::forSchema($schema)` | never (may be `[]` for a type with no title and no body — then return null) |
| `entry-magazine` | template, settings `['width' => 'full']` | a centred container (`width` token `width.content`, `alignment.self` center) of the header parts (terms when `$ref`, `entry_title` h1 when `$title`, `entry_date` long) — then `entry_cover {field: $cover, aspect: '16:9'}` at full width when `$cover` — then a centred `width.content` container of `content()` — then `entry_related {count: 3, style: 'cards'}` when `$article` | neither a title nor `content()` |
| `entry-minimal` | template, settings `[]` | `entry_title` h1 when `$title`, `entry_date {format: 'short'}`, `content()` | neither a title nor `content()` |
| `entry-article-header` | section, category `Article` | a stack container (`display` flex, `direction` column, `gap.row` token `spacing.xs`) of terms (when `$ref`, `style: 'badges'`, `link: true`), `entry_title` h1, `entry_date` long | no title field |
| `entry-cover-band` | section, category `Article` | `B::band([entry_cover {field: $cover, aspect: '16:9'}])` | no asset field |
| `entry-related` | section, category `Article` | `B::band([B::heading('Keep reading', 'h2', 'start'), entry_related {count: 3, style: 'cards'}])` | never |
| `entry-neighbours` | section, category `Article` | `entry_neighbours {previous_label: 'Previous', next_label: 'Next'}` | never |

**Listing and archive** (`$cover` = the `cover` field when it is an asset, `$excerpt` = a plain `excerpt` field, as `Starters::forListing` reads them; the archive's type is the target's part before `:`):

| slug | kind | built as |
|---|---|---|
| `listing-horizontal` / `archive-horizontal` | template, `[]` | `Starters::forListing($schema)` |
| `listing-card-grid` | template, `[]` | `listing_title` h1; `entry_loop` whose `settings.style.layout` is `display` grid, `columns` base `1` / md `2` / lg `3`, `gap` column and row token `spacing.lg`, and whose card is `entry_cover {field: 'cover', aspect: '4:3', link: true}` (when `$cover`), `entry_title {level: 'h3', link: true}`, `entry_excerpt {field: 'excerpt', clamp: 2}` (when `$excerpt`), in a stack container (`gap.row` token `spacing.xs`); `pagination {count: true}` |
| `listing-compact` | template, `[]` | `listing_title` h1; `entry_loop` whose card is a row container (`direction` row, `alignment.content` between, `align_items` baseline, `gap.column` token `spacing.sm`) of `entry_title {level: 'h3', link: true}` and `entry_date {format: 'short'}`; `pagination` |
| `archive-term-grid` | template, `[]` | the Term header section's tree, then the card grid's loop and pagination |
| `archive-compact` | template, `[]` | `listing_title` h1, the compact loop, `pagination` |
| `listing-header` | section, category `Listing` | a stack container of `listing_title` h1 and `B::text('<p>Everything published here, newest first.</p>', 'start', 'color.muted')` |
| `listing-page-nav` | section, category `Listing` | `B::band([pagination {count: true, previous_label: 'Newer', next_label: 'Older'}])` |
| `archive-term-header` | section, category `Archive` | a stack container of `listing_title` h1 and `term_description` |

(The archive's listing parts use the archive palette, which is the listing palette plus `term_description`; the Card grid and Compact loops are shared private builders.)

- [ ] **Step 4: Run** the test. Expected: PASS. If a template fails validation for a type shape, fix the builder — never the test.
- [ ] **Step 5: Commit** (phpcs) as `feat(layouts): three templates and a few sections for posts, listings and archives, built for each type`.

## Task L4: the library serves a layout's patterns for its target

**Files:**
- Modify: `core/src/Content/Patterns/PatternLibrary.php`, `core/src/Content/Http/Controllers/PatternController.php`, `core/src/Content/Http/DTOs/Responses/Patterns/PatternData.php`, `admin/src/queries/patterns.ts`, `admin/src/queries/keys.ts`; regenerate `docs/openapi.json` and `admin/src/api/schema.d.ts` (`composer docs:openapi`, `cd admin && pnpm gen:api`)
- Test: `tests/Integration/Content/PatternLibraryTest.php`, `tests/Integration/Content/Layouts/LayoutLibraryTest.php` (new), `admin/src/queries/patterns.spec.ts`

**Interfaces:** Produces `PatternLibrary::forLayout(string $surface, string $target): list<entry>` (throws `\InvalidArgumentException` for an unknown or unregistered surface), `PatternLibrary::layoutSlugs(): list<string>`, `GET /patterns?surface=&target=`, the `surface`/`settings` keys, and admin `useLayoutPatterns(surface, target)`, `qk.layoutPatterns(surface, target) = ['patterns', 'layout', surface, target]`, `belongsIn` for the layout place. Consumes L1–L3.

- [ ] **Step 1: Write the failing tests.** In `LayoutLibraryTest` (set up the `lp_*` types of L3 through a shared trait `tests/Support/LayoutTypeShapes.php` extracted from L3's test — move L3's set-up there in this step):

```php
public function testAPostLayoutGetsItsSectionsAndTemplatesBuiltForIt(): void
{
    $entries = array_column($this->library()->forLayout('entry', 'lp_body'), null, 'slug');
    self::assertSame(
        ['entry-article-header', 'entry-cover-band', 'entry-related', 'entry-neighbours', 'entry-classic', 'entry-magazine', 'entry-minimal'],
        array_keys($entries),
    );
    self::assertSame(['layout', 'entry', 'section'], [$entries['entry-cover-band']['scope'], $entries['entry-cover-band']['surface'], $entries['entry-cover-band']['kind']]);
    self::assertSame('page', $entries['entry-magazine']['kind']);
    self::assertSame(['width' => 'full'], $entries['entry-magazine']['settings']);
    self::assertNull($entries['entry-cover-band']['settings']);
}

public function testWhatDoesNotFitTheTargetIsNotOffered(): void
{
    $slugs = array_column($this->library()->forLayout('entry', 'lp_rich'), 'slug');
    self::assertNotContains('entry-cover-band', $slugs);
}

public function testATemplateTheValidatorRefusesIsNotOffered(): void
{
    // A block type switched off hides every pattern using it, as page patterns are hidden.
    $this->deactivateBlockType('entry_related');
    $slugs = array_column($this->library()->forLayout('entry', 'lp_body'), 'slug');
    self::assertNotContains('entry-magazine', $slugs);
    self::assertNotContains('entry-related', $slugs);
    self::assertContains('entry-minimal', $slugs);
}

public function testWhatIsServedIsWhatWasValidated(): void
{
    $validator = $this->container()->get(LayoutValidator::class);
    foreach ($this->library()->forLayout('entry', 'lp_body') as $entry) {
        if ($entry['kind'] === 'page') {
            $clean = $validator->validate('entry', 'lp_body', self::withIds($entry['blocks']), $entry['settings'], [], false);
            self::assertSame($entry['blocks'], self::withoutIds($clean['blocks']), "{$entry['slug']}: served already normalised");
        } else {
            self::assertSame($entry['blocks'], $validator->fragment('entry', 'lp_body', $entry['blocks'])['blocks'], $entry['slug']);
        }
        self::assertStringNotContainsString('"id"', json_encode($entry['blocks']), "{$entry['slug']}: no temporary ids");
    }
}

public function testAClosedTargetOffersNothing(): void
{
    self::assertNotSame([], $this->library()->forLayout('listing', 'lp_body'));
    $this->closeListing('lp_body'); // listing pages off for the type: the target is closed
    self::assertSame([], $this->library()->forLayout('listing', 'lp_body'));
}

public function testAnUnknownSurfaceIsRefusedAndAClosedTargetOffersNothing(): void
{
    try {
        $this->library()->forLayout('basket', 'x');
        self::fail('unknown surface');
    } catch (\InvalidArgumentException $e) {
        self::assertSame("unknown layout surface 'basket'", $e->getMessage());
    }
    self::assertSame([], $this->library()->forLayout('entry', 'no_such_type'));
}

public function testThePageLibraryHoldsNoLayoutPattern(): void
{
    foreach ($this->library()->all() as $entry) {
        self::assertNotSame('layout', $entry['scope'], $entry['slug']);
        self::assertNull($entry['surface']);
    }
}

public function testTheEndpointServesTheTarget(): void
{
    $response = $this->container()->get(PatternController::class)->index(Request::create('/x', 'GET', ['surface' => 'entry', 'target' => 'lp_body']));
    $data = json_decode((string) $response->getContent(), true)['data'];
    self::assertContains('entry-classic', array_column($data['patterns'], 'slug'));
    self::assertDataMatchesDtoShape($data['patterns'][0], PatternData::class);
    $unknown = $this->container()->get(PatternController::class)->index(Request::create('/x', 'GET', ['surface' => 'basket', 'target' => 'x']));
    self::assertSame(422, $unknown->getStatusCode());
}

public function testLayoutSlugsCoverEveryRegisteredSurface(): void
{
    self::assertEqualsCanonicalizing(array_keys(LayoutPatterns::slugs()), array_values(array_filter($this->library()->layoutSlugs(), fn ($s) => !str_starts_with($s, 'product-') && !str_starts_with($s, 'shop-'))));
}
```

The same case with a saved section present lands in L5, with the column (`testAClosedTargetOffersNothingEvenWithSavedSections`). `closeListing()` turns the type's listing pages off through `GeneralSettings`, the reverse of what the set-up does; it lives in the `LayoutTypeShapes` trait, which L5's test uses too. `withIds()`/`withoutIds()` are the library's helpers (make them public static on `PatternLibrary`, or copy them into the test). `deactivateBlockType()` sets the type inactive through `BlockTypeRepository` as the S1 test `testATemplateGoesWhenACoreSectionItNamesGoes` does. In `PatternLibraryTest::testTheAdminReadsTheLibraryWithTheRightToSeeContent`, the `index()` call becomes `index(Request::create('/x'))`. In `admin/src/queries/patterns.spec.ts` add: `belongsIn(layoutPattern('entry'), { scope: 'layout', surface: 'entry' })` is true, false for surface `listing`, false for `{ scope: 'page' }`; a page pattern is not in a layout place.

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Content/Layouts/LayoutLibraryTest.php tests/Integration/Content/PatternLibraryTest.php` and `cd admin && pnpm exec vitest run src/queries/patterns.spec.ts`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `PatternLibrary` constructor gains optional `?LayoutSurfaceRegistry $surfaces = null, ?LayoutValidator $layouts = null, ?ContentTypeRepository $types = null` (autowired, after the S1 arguments). Every entry built by `all()` gains `'surface' => null, 'settings' => null` (`place()` sets them).
  - `forLayout($surface, $target)`:
    1. `$this->surfaces?->get($surface)` null → `throw new \InvalidArgumentException("unknown layout surface '{$surface}'")`.
    2. **The target is checked once, here:** `$this->layouts->targetError($surface, $target) !== null` → return `[]` — nothing is offered for a target that cannot have a layout, saved sections included.
    3. `$type = LayoutSaver::typeOf($surface, $target)`; `$schema = $type === null ? null : ($this->types?->findBySlug($type)['schema'] ?? null)`; `$lt = LayoutPatterns::targetFor($surface, $target, $schema)`.
    4. Sources: `LayoutPatterns::sections()`/`templates()` then each `layoutContributors()`'s, filtered to `->surface === $surface`.
    5. **What is validated is what is returned.** A section: `$block = ($s->build)($lt)`, skip null; `$resolved = resolve([$block])` (the factory's defaults laid under it — the S1 type rule), skip null; `$checked = $this->layouts->fragment($surface, $target, $resolved)`, skip when `$checked['errors'] !== []`; the entry's `blocks` are `$checked['blocks']` — resolved, validated and normalised, and id-less (`fragment` removes the temporary ids it added). Entry: `{slug, kind: 'section', label, category, description, requires: null, blocks, scope: 'layout', region: null, surface, settings: null, saved: false, id: null}`.
    6. A template: `$tree = ($t->build)($lt)`, skip null; `$resolved = resolve($tree)`, skip null; `$clean = $this->layouts->validate($surface, $target, self::withIds($resolved), $t->settings, [], false)` in a try — skip on `ValidationException`; the entry's `blocks` are `self::withoutIds($clean['blocks'])` and its `settings` are `$clean['settings']` (the validated payload; `[]` stays `[]`); `kind: 'page'`, `category: 'Layouts'`. `withIds()` gives every block, nested ones too, a 12-character id; `withoutIds()` removes exactly those.
    7. Saved sections: rows with `scope === 'layout'` and `surface === $surface` (L5 adds the column; until then none match), each through the shared `savedEntry()` mapper (L5 introduces it; here use the existing saved-row resolution and let L5 extract it).
    8. Order: sections, templates, saved.
  - `layoutSlugs()`: `array_keys(LayoutPatterns::slugs())` for the registered core surfaces plus every layout contributor's slugs whose surface is registered.
  - `PatternController::index(Request $request)`: with a `surface` query, answer `['patterns' => $this->library->forLayout($surface, (string) $request->query->get('target', ''))]`, or `Response::validation(['surface' => $e->getMessage()])` on `\InvalidArgumentException`; without it, `all()` as today. Add `#[ApiParam]`-style query documentation as the controller's other query endpoints do (e.g. `LayoutAdminController::samples`' `q`).
  - `PatternData`: `?string $surface`, `?array $settings` after `region`.
  - Admin: the `Pattern`, `PatternScope`, `SectionPlace` changes of Shared contracts; `belongsIn` layout branch `p.scope === 'layout' && p.surface === place.surface`; `fetchLayoutPatterns(surface, target)` (`GET ${apiBase}/patterns?surface=…&target=…`) and `useLayoutPatterns(surface: MaybeRefOrGetter<string>, target: MaybeRefOrGetter<string>)` with key `qk.layoutPatterns`. `useSavedSections`' refresh (`invalidateQueries({ key: qk.patterns() })`) already covers the layout keys by prefix — assert it in the spec.
  - Regenerate the OpenAPI artifacts; commit them if they change.
- [ ] **Step 4: Run** the tests, `vendor/bin/phpunit tests/Integration/Content`, and `cd admin && pnpm exec vitest run src/queries`. Expected: PASS.
- [ ] **Step 5: Commit** (phpcs, `pnpm type-check`, fmt) as `feat(patterns): a layout's sections and templates, served for its target`.

## Task L5: saved sections in layouts, authorized by their scope

**Files:**
- Create: `core/database/migrations/038_SurfaceOnSavedSections.php`
- Modify: `core/src/Content/Patterns/SavedSectionRepository.php`, `core/src/Content/Http/Controllers/SavedSectionController.php`, `core/src/Content/Http/DTOs/SaveSectionData.php`, `core/routes/admin.php`, `core/src/Content/Patterns/PatternLibrary.php` (saved rows: `all()` skips layout rows; `forLayout` includes them)
- Test: `tests/Integration/Content/SavedLayoutSectionsTest.php` (new file, existing directory), `tests/Integration/Content/SavedSectionApiTest.php` (its `testSavingAndChangingNeedContentManage` becomes the controller-gate test)

**Interfaces:** Produces the `surface` column and scope `layout`; `SavedSectionRepository::create(..., ?string $by, ?string $surface = null)`, `find(string $id): ?array`; `PatternLibrary::savedEntry(array $row): ?array` (a stored row → its library entry, null when a block type in it cannot be used); `SaveSectionData` gains `?string $surface`, `?string $target`. Consumes L2 (`fragment`), L4 (`forLayout`).

- [ ] **Step 1: Write the failing tests** in `SavedLayoutSectionsTest extends AppTestCase` (layout types from `LayoutTypeShapes`). The controller is built with a fake authority (the `LayoutSaveTest` pattern):

```php
private function controller(array $granted): SavedSectionController
{
    $authority = new class ($granted) implements PermissionRequirementAuthority {
        public function __construct(private array $granted) {}
        public function allows(Request $request, array $requirements): bool
        {
            return array_intersect($requirements, $this->granted) !== [];
        }
    };
    return new SavedSectionController(
        $this->container()->get(SavedSectionRepository::class),
        $this->container()->get(PatternLibrary::class),
        $this->container()->get(FieldValidator::class),
        null,
        $authority,
        $this->container()->get(LayoutSurfaceRegistry::class),
        $this->container()->get(LayoutValidator::class),
    );
}

private const COVER = ['id' => 'cov000000001', 'type' => 'entry_cover', 'data' => ['field' => 'cover', 'aspect' => '16:9'], 'settings' => []];

public function testALayoutSectionKeepsItsFieldBlocksAndItsSurface(): void
{
    $res = $this->controller(['templates.manage'])->store(new SaveSectionData(name: 'Cover', block: self::COVER, scope: 'layout', surface: 'entry', target: 'lp_body'), $this->request());
    self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
    $section = json_decode((string) $res->getContent(), true)['data']['section'];
    $id = $section['id'];
    // The whole answer, built from the stored row — not looked up in the page library.
    self::assertSame($this->library()->savedEntry($this->container()->get(SavedSectionRepository::class)->find($id)), $section);
    self::assertSame(['saved-' . $id, 'section', 'Cover', 'Saved', '', 'layout', null, 'entry', null, true], [$section['slug'], $section['kind'], $section['label'], $section['category'], $section['description'], $section['scope'], $section['region'], $section['surface'], $section['settings'], $section['saved']]);
    self::assertSame('entry_cover', $section['blocks'][0]['type']);
    self::assertDataMatchesDtoShape($section, PatternData::class);
    self::assertNotContains($section['slug'], array_column($this->library()->all(), 'slug'), 'not in the page library');
    self::assertContains($section['slug'], array_column($this->library()->forLayout('entry', 'lp_nocover'), 'slug'), 'offered in every layout of its surface');
    self::assertNotContains($section['slug'], array_column($this->library()->forLayout('listing', 'lp_body'), 'slug'));
}

public function testAnUnboundFieldBlockIsStoredWithTheBindingItHadWhereItWasSaved(): void
{
    $cover = ['id' => 'cov000000002', 'type' => 'entry_cover', 'data' => ['aspect' => '16:9'], 'settings' => []];
    $res = $this->controller(['templates.manage'])->store(new SaveSectionData(name: 'Cover', block: $cover, scope: 'layout', surface: 'entry', target: 'lp_body'), $this->request());
    $id = json_decode((string) $res->getContent(), true)['data']['section']['id'];
    self::assertSame('cover', $this->container()->get(SavedSectionRepository::class)->find($id)['block']['data']['field']);
}

public function testRenameAnswersWithTheWholeSection(): void
{
    $repo = $this->container()->get(SavedSectionRepository::class);
    $id = $repo->create('Cover', 'Saved', null, self::COVER, null, null, 'entry');
    $res = $this->controller(['templates.manage'])->update(new UpdateSavedSectionData(name: 'Wide cover'), $id, $this->request());
    $section = json_decode((string) $res->getContent(), true)['data']['section'];
    self::assertSame('Wide cover', $section['label']);
    self::assertSame($this->library()->savedEntry($repo->find($id)), $section);
    // A page section answers the same way.
    $page = $repo->create('Hi', 'Saved', null, ['type' => 'heading', 'data' => ['text' => 'Hi', 'level' => 'h2'], 'settings' => []], null, null);
    $renamed = json_decode((string) $this->controller(['content.manage'])->update(new UpdateSavedSectionData(name: 'Hello'), $page, $this->request())->getContent(), true)['data']['section'];
    self::assertSame(['Hello', 'page', null], [$renamed['label'], $renamed['scope'], $renamed['surface']]);
}

public function testAClosedTargetOffersNothingEvenWithSavedSections(): void
{
    $this->container()->get(SavedSectionRepository::class)->create('Title', 'Saved', null, ['type' => 'listing_title', 'data' => ['level' => 'h1'], 'settings' => []], null, null, 'listing');
    self::assertNotSame([], array_filter($this->library()->forLayout('listing', 'lp_body'), fn ($e) => $e['saved']));
    $this->closeListing('lp_body');
    self::assertSame([], $this->library()->forLayout('listing', 'lp_body'));
}

public function testALayoutSectionFollowsItsSurfacesRules(): void
{
    $loop = ['id' => 'loop00000001', 'type' => 'entry_loop', 'data' => ['card' => []], 'settings' => []];
    $res = $this->controller(['templates.manage'])->store(new SaveSectionData(name: 'Loop', block: $loop, scope: 'layout', surface: 'entry', target: 'lp_body'), $this->request());
    self::assertSame(422, $res->getStatusCode());
    $unknown = $this->controller(['templates.manage'])->store(new SaveSectionData(name: 'X', block: self::COVER, scope: 'layout', surface: 'basket', target: 'x'), $this->request());
    self::assertSame(422, $unknown->getStatusCode());
}

public function testSavingAsksTheScopesPermission(): void
{
    self::assertSame(403, $this->controller(['content.manage'])->store(new SaveSectionData(name: 'Cover', block: self::COVER, scope: 'layout', surface: 'entry', target: 'lp_body'), $this->request())->getStatusCode());
    $heading = ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi', 'level' => 'h2'], 'settings' => []];
    self::assertSame(403, $this->controller(['templates.manage'])->store(new SaveSectionData(name: 'Hi', block: $heading), $this->request())->getStatusCode());
    self::assertSame(201, $this->controller(['content.manage'])->store(new SaveSectionData(name: 'Hi', block: $heading), $this->request())->getStatusCode());
}

public function testRenameAndDeleteAskTheStoredRowsScope(): void
{
    $repo = $this->container()->get(SavedSectionRepository::class);
    $layout = $repo->create('Cover', 'Saved', null, self::COVER, null, null, 'entry');
    $page = $repo->create('Hi', 'Saved', null, ['type' => 'heading', 'data' => ['text' => 'Hi', 'level' => 'h2'], 'settings' => []], null, null);

    $contentOnly = $this->controller(['content.manage']);
    self::assertSame(403, $contentOnly->update(new UpdateSavedSectionData(name: 'X'), $layout, $this->request())->getStatusCode());
    self::assertSame(403, $contentOnly->destroy($layout, $this->request())->getStatusCode());
    self::assertTrue($repo->exists($layout), 'kept');

    $templatesOnly = $this->controller(['templates.manage']);
    self::assertSame(403, $templatesOnly->update(new UpdateSavedSectionData(name: 'X'), $page, $this->request())->getStatusCode());
    self::assertSame(403, $templatesOnly->destroy($page, $this->request())->getStatusCode());
    self::assertSame(200, $templatesOnly->update(new UpdateSavedSectionData(name: 'Wide cover'), $layout, $this->request())->getStatusCode());
    self::assertSame(200, $templatesOnly->destroy($layout, $this->request())->getStatusCode());
    self::assertSame(200, $contentOnly->destroy($page, $this->request())->getStatusCode());
}

public function testExistingRowsAreUnchanged(): void
{
    $repo = $this->container()->get(SavedSectionRepository::class);
    $page = $repo->create('Hi', 'Saved', null, ['type' => 'heading', 'data' => ['text' => 'Hi', 'level' => 'h2'], 'settings' => []], null, null);
    $row = array_column($repo->all(), null, 'id')[$page];
    self::assertSame(['page', null], [$row['scope'], $row['surface']]);
}

public function testAVanishedSurfacesSectionsAreHiddenAndKept(): void
{
    $repo = $this->container()->get(SavedSectionRepository::class);
    $id = $repo->create('Story', 'Saved', null, ['type' => 'product_story', 'data' => [], 'settings' => []], null, null, 'product');
    $disabled = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
    try {
        $library = $disabled->getContainer()->get(PatternLibrary::class);
        self::assertNotContains('saved-' . $id, array_column($library->all(), 'slug'));
        $this->expectException(\InvalidArgumentException::class);
        $library->forLayout('product', '@site');
    } finally {
        self::resetSharedRepositoryConnection();
        self::restoreSharedPermissionProvider();
        self::assertTrue($repo->exists($id), 'kept for when the surface returns');
    }
}

public function testTheRoutesLeaveTheDecisionToTheController(): void
{
    foreach ([['POST', '/v1/admin/saved-sections'], ['PATCH', '/v1/admin/saved-sections/{id}'], ['DELETE', '/v1/admin/saved-sections/{id}']] as [$method, $path]) {
        $middleware = (array) ($this->findRoute($method, $path)['middleware'] ?? []);
        self::assertContains('content_permission:content.view', $middleware, "{$method} {$path}");
        self::assertNotContains('content_permission:content.manage', $middleware, "{$method} {$path}");
    }
}
```

`request()` is a `Request` with `attributes 'user' => ['uuid' => 'editor000001']` (as `SavedSectionApiTest` builds it). Remove `SavedSectionApiTest::testSavingAndChangingNeedContentManage` (superseded by the route test above) and pass a `Request` to its `update()`/`destroy()` calls.

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Content/SavedLayoutSectionsTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - Migration `038_SurfaceOnSavedSections` (global `final class SurfaceOnSavedSections implements MigrationInterface`, guarded like `036_LockVersionOnSavedSections`): `up()` adds `string('surface', 40)->nullable()` when the column is missing; `down()` drops it; `getDescription()`: "Saved sections remember the layout surface they belong to."
  - Repository: `create()` gains trailing `?string $surface = null`; scope is `'layout'` when `$surface !== null`, else as today; `all()` rows gain `surface` and the scope normalisation keeps `'layout'`; `find(string $id): ?array` returns one row shaped like `all()`'s.
  - `SaveSectionData`: `#[Rule('string')] public ?string $surface = null`, `#[Rule('string')] public ?string $target = null`.
  - Controller: constructor gains `?PermissionRequirementAuthority $authority = null, ?LayoutSurfaceRegistry $surfaces = null, ?LayoutValidator $layouts = null` (after `$classGuard`). A private `may(Request $request, string $scope): bool` returns `$this->authority?->allows($request, [$scope === 'layout' ? 'templates.manage' : 'content.manage']) ?? false`; a refusal returns `Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN'])`. `store()` checks it right after `place()`; `place()` accepts `layout` with a surface the registry knows (`['surface' => "unknown layout surface '{$surface}'"]` otherwise) and a non-empty `target`; a layout section is checked with `$this->layouts->fragment($surface, $target, [$block])` (it runs `forLayouts()` block validation, palette, cards and bindings; errors remapped from `blocks.0` to `block` like today's), and **the normalised block it returns** (`fragment()['blocks'][0]`, default bindings written) is what `withoutIds()` and the class guard receive and what is stored, with its surface. The response of `store()` and `update()` is `savedEntry(find($id))` — replace today's `entry()` lookup in `all()` (which no longer holds layout sections) with it, for every scope. `update()` and `destroy()` take `Request $request` as their last argument, load `find($id)` (404 when null) and check `may($request, $row['scope'])` before changing anything.
  - Routes: the three routes' middleware becomes `content_permission:content.view`, with a comment that the controller decides by scope (sections and templates design §5).
  - `PatternLibrary`: extract the saved-row resolution of `savedSections()` into `public function savedEntry(array $row): ?array` (the entry of S1's shape plus `surface`, `settings: null`); `savedSections()` skips `scope === 'layout'` and maps through it; `forLayout()` step 7 reads the column and maps through it.
- [ ] **Step 4: Run** the new test, `vendor/bin/phpunit tests/Integration/Content/SavedSectionApiTest.php`, and `vendor/bin/phpunit --filter SavedSection`. Expected: PASS.
- [ ] **Step 5: Commit** (phpcs) as `feat(patterns): sections saved from a layout keep their field blocks and their surface, and only the right editor changes them`.

## Task L6: commerce's layout patterns, registered with Commerce

**Files:**
- Create: `packages/thallo-commerce/src/Patterns/ShopLayoutPatternsContributor.php`
- Modify: `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (`registerPatternContributor` also registers the layout contributor, idempotently)
- Test: `tests/Integration/Commerce/ShopLayoutPatternsTest.php`

**Interfaces:** Produces `ShopLayoutPatternsContributor implements LayoutPatternContributor` (id `thallo.commerce`), constructed with the three surfaces (`ProductSurface`, `ShopIndexSurface`, `ShopCategorySurface`) so its "close to today's" templates are the surfaces' own `starter()`. Consumes L1 and the registry's `registerLayout`.

- [ ] **Step 1: Write the failing test** (`SyncsBlockStyleDeclarations`):

```php
public function testEveryShopLayoutTemplatePassesItsSurface(): void
{
    $validator = $this->container()->get(LayoutValidator::class);
    foreach ($this->contributor()->layoutTemplates() as $template) {
        $tree = ($template->build)(new LayoutTarget($template->surface, '@site', []));
        self::assertNotNull($tree, $template->slug);
        try {
            $validator->validate($template->surface, '@site', self::withIds($tree), $template->settings, [], false);
        } catch (ValidationException $e) {
            self::fail("{$template->slug}: " . json_encode($e->errors()));
        }
    }
}

public function testEveryShopLayoutSectionFits(): void
{
    $validator = $this->container()->get(LayoutValidator::class);
    foreach ($this->contributor()->layoutSections() as $section) {
        $block = ($section->build)(new LayoutTarget($section->surface, '@site', []));
        self::assertSame([], $validator->fragment($section->surface, '@site', [$block])['errors'], $section->slug);
    }
}

public function testTheProductHeroHoldsNoSecondBuyBox(): void
{
    $hero = array_column(array_map(fn ($s) => ['slug' => $s->slug, 's' => $s], $this->contributor()->layoutSections()), 's', 'slug')['product-hero'];
    self::assertStringNotContainsString('product_buy', json_encode(($hero->build)(new LayoutTarget('product', '@site', []))));
}

public function testTheCloseToTodayTemplatesAreTheStarters(): void
{
    $surfaces = $this->container()->get(LayoutSurfaceRegistry::class);
    foreach (['product-gallery-left' => 'product', 'shop-index-adaptive' => 'shop_index', 'shop-category-adaptive' => 'shop_category'] as $slug => $surface) {
        self::assertSame($surfaces->get($surface)->starter('@site'), ($this->template($slug)->build)(new LayoutTarget($surface, '@site', [])));
    }
}

public function testOfferedWithCommerceOnAndGoneWithItOff(): void
{
    $slugs = array_column($this->container()->get(PatternLibrary::class)->forLayout('product', '@site'), 'slug');
    self::assertSame(['product-hero', 'product-details-band', 'product-story-band', 'product-gallery-left', 'product-gallery-top', 'product-story-led'], $slugs);
    $disabled = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
    try {
        $library = $disabled->getContainer()->get(PatternLibrary::class);
        self::assertSame([], array_values(array_filter($library->layoutSlugs(), fn ($s) => str_starts_with($s, 'product-') || str_starts_with($s, 'shop-'))));
    } finally {
        self::resetSharedRepositoryConnection();
        self::restoreSharedPermissionProvider();
    }
}

public function testRegistrationIsIdempotent(): void
{
    $provider = new CommerceIntegrationServiceProvider($this->container());
    self::assertTrue($provider->registerPatternContributor($this->appContext()));
    self::assertTrue($provider->registerPatternContributor($this->appContext()));
    $ids = array_map(fn ($c) => $c->id(), $this->container()->get(PatternContributorRegistry::class)->layoutContributors());
    self::assertSame(1, count(array_keys($ids, 'thallo.commerce', true)));
}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Commerce/ShopLayoutPatternsTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the contributor (`use PatternBlocks as B`; `$loop` is the Product list taken from the surface's starter — a private `loopOf(array $starter): array` finds the `product_loop` block — so every shop template keeps today's card):

| slug | surface | kind | built as |
|---|---|---|---|
| `product-gallery-left` | product | template `[]` | `$this->product->starter('@site')` |
| `product-gallery-top` | product | template `[]` | `product_breadcrumb`; `product_gallery`; a centred container (`width` token `width.content`) holding a stack (`gap.row` token `spacing.sm`) of `product_category`, `product_name` h1, `product_rating`, `product_price`, `product_description`, `product_buy`; `product_story` |
| `product-story-led` | product | template `['width' => 'full']` | a centred container (`width` token `width.container`) holding the starter's two-column grid (gallery beside a stack of `product_name` h1, `product_price`, `product_rating`, `product_buy`); then `B::band([product_description])`; then `product_story` at full width |
| `product-hero` | product | section, category `Product` | the two-column grid (one column below md, two from md, `gap` token `spacing.xl`, `align_items` start) of `product_gallery` beside a stack of `product_name` h1, `product_rating`, `product_price` — description: "The gallery beside the name, rating and price. The layout's buy box stays where it is." |
| `product-details-band` | product | section, `Product` | `B::band([product_category, product_description], [], 'color.surface-2')` |
| `product-story-band` | product | section, `Product` | `B::band([product_story])` |
| `shop-index-adaptive` | shop_index | template `[]` | `$this->index->starter('@site')` |
| `shop-index-banner` | shop_index | template `[]` | `B::band([shop_title h1], [], 'color.surface-2')`; `category_rail`; `$loop`; `pagination` |
| `shop-index-category-led` | shop_index | template `[]` | `B::band([shop_title h1, category_rail])`; `$loop`; `pagination` |
| `shop-index-banner-section` | shop_index | section, `Shop` label "Shop banner" | `B::band([shop_title h1], [], 'color.surface-2')` |
| `shop-index-chips-band` | shop_index | section, `Shop` label "Category chips band" | `B::band([category_rail])` |
| `shop-category-adaptive` | shop_category | template `[]` | `$this->category->starter('@site')` |
| `shop-category-banner` | shop_category | template `[]` | as `shop-index-banner` |
| `shop-category-chips-top` | shop_category | template `[]` | `category_rail`; `shop_title` h1; `$loop`; `pagination` |
| `shop-category-banner-section` | shop_category | section, `Shop` label "Category banner" | `B::band([shop_title h1], [], 'color.surface-2')` |

Registration: in `registerPatternContributor`, after the page contributor, when `!in_array('thallo.commerce', array_map(fn ($c) => $c->id(), $registry->layoutContributors()), true)`, call `$registry->registerLayout(new ShopLayoutPatternsContributor($container->get(ProductSurface::class), $container->get(ShopIndexSurface::class), $container->get(ShopCategorySurface::class)))`.
- [ ] **Step 4: Run** the test, `vendor/bin/phpunit tests/Integration/Commerce/ShopPatternsTest.php` (S1's, unchanged) and `composer boundaries`. Expected: PASS.
- [ ] **Step 5: Commit** (phpcs) as `feat(commerce): three templates and a few sections for the product page, the shop home and the shop categories`.

## Task L7: thumbnails for every layout pattern, from the real stage

**Files:**
- Create: `scripts/lib/layout-thumbnails.php` (`layout_stage_html`), `tests/Integration/Content/Layouts/LayoutThumbnailSourceTest.php`, `tools/style-proofs/tests/capture-selector.spec.js` and `tools/style-proofs/pages/capture-selector.html`
- Modify: `tools/style-proofs/capture-page.js` (a `selector` crop), `scripts/build-pattern-thumbnails` (layout patterns), `tests/Integration/Content/PatternLibraryTest.php` (the gate); `admin/public/pattern-thumbs/<slug>.jpg` for the 31 layout slugs; regenerate `admin/src/editor/palette/patternThumbSizes.json`

**Interfaces:** `layout_stage_html(ContainerInterface $container, string $surface, string $target, array $blocks, array $settings): string` — the layout stage exactly as the editor's iframe receives it for that document (canvas, no published sample), made self-contained. Consumes `PatternLibrary::forLayout()` and `layoutSlugs()`.

- [ ] **Step 1: Widen the gate first** (red): in `testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan`, the slug list becomes `[...array_column($this->library()->all(), 'slug'), ...$this->library()->layoutSlugs()]`, sorted. Run `vendor/bin/phpunit --filter testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan`. Expected: FAIL naming the missing layout pictures.
- [ ] **Step 2: The crop, proven on a real annotated section.** `capture-page.js`: a job with `selector` captures only that element's rendered area. In the page, `bounds(el)` is `el.getBoundingClientRect()` when it has a size (`getClientRects().length > 0` and a non-zero width and height); otherwise the union (min left/top, max right/bottom) of `bounds()` over its element children, recursively — so a `display: contents` wrapper yields its section's box. The screenshot then uses that rectangle as `clip` (plus the scroll offset). A selector matching nothing, or an element whose bounds are empty, fails with "no rendered box for {selector}". `capture-selector.spec.js`:
  - `a boxed element` — `pages/capture-selector.html`, a 1200px page with a 300px-tall `#target` box below a 500px header: the crop is the box's size.
  - `an annotated section` — `pages/capture-annotated.html`, the stage's own markup for a section: `<div class="thallo-preview-block" data-thallo-block="thumbsection1"><section class="thallo-block thallo-block-container">…</section></div>` below other blocks, with the **real** preview stylesheet added by `page.addStyleTag({ path: '<repo>/packages/thallo-render/assets/preview/preview.css' })` so the wrapper really is `display: contents`; assert the wrapper's own `boundingBox()` is empty or zero-sized (the problem) and the crop equals the inner `<section>`'s box (the fix).
  - `nothing rendered` — a selector for an empty `display: contents` element rejects.
  Run `cd tools/style-proofs && npx playwright test tests/capture-selector.spec.js --project=chromium`: the annotated case is red before the change, all green after.
- [ ] **Step 3: Write the failing source test.** `LayoutThumbnailSourceTest extends AppTestCase` (the `LayoutTypeShapes` types; commerce on):

```php
public function testAPictureIsTheStageTheEditorShows(): void
{
    $template = array_column($this->library()->forLayout('entry', 'lp_body'), null, 'slug')['entry-magazine'];
    $html = layout_stage_html($this->container(), 'entry', 'lp_body', $template['blocks'], $template['settings']);
    // The same document through the editor's own path: session, apply, the canvas stage.
    self::assertSame(self::withoutVolatile($this->stageThroughTheEditor('entry', 'lp_body', $template['blocks'], $template['settings'])), self::withoutVolatile($html));
    self::assertStringContainsString('thallo-layout--entry', $html);
    self::assertStringContainsString('No published', $html, 'the placeholder sample, named');
}

public function testAFullWidthTemplateGetsTheFullWidthFrame(): void
{
    $magazine = array_column($this->library()->forLayout('entry', 'lp_body'), null, 'slug')['entry-magazine'];
    $contained = layout_stage_html($this->container(), 'entry', 'lp_body', $magazine['blocks'], []);
    $full = layout_stage_html($this->container(), 'entry', 'lp_body', $magazine['blocks'], ['width' => 'full']);
    self::assertNotSame($contained, $full, 'the Frame setting reaches the page through the presentation path');
    self::assertSame($full, layout_stage_html($this->container(), 'entry', 'lp_body', $magazine['blocks'], $magazine['settings']));
}

public function testACommerceTemplateGetsItsOwnFrame(): void
{
    $template = array_column($this->library()->forLayout('product', '@site'), null, 'slug')['product-gallery-top'];
    $html = layout_stage_html($this->container(), 'product', '@site', $template['blocks'], $template['settings']);
    self::assertStringContainsString('shop-product shop-product--layout', $html);
    self::assertSame(self::withoutVolatile($this->stageThroughTheEditor('product', '@site', $template['blocks'], $template['settings'])), self::withoutVolatile($html));
    $shop = array_column($this->library()->forLayout('shop_index', '@site'), null, 'slug')['shop-index-banner'];
    self::assertStringContainsString('shop-index shop-index--layout', layout_stage_html($this->container(), 'shop_index', '@site', $shop['blocks'], $shop['settings']));
}
```

```php
public function testThumbnailTargetsShowThePlaceholderEvenWithPublishedContent(): void
{
    // A database with content every surface could sample: a published post-like entry, and products.
    $this->publishEntry('lp_body', ['title' => 'A real post', 'body' => []]);
    (new ShopPageSeed($this->container(), $this->appContext()))->seed();
    $targets = layout_thumbnail_targets($this->container()); // isolates: fresh types, no products
    foreach ($targets as $surface => $target) {
        self::assertSame([], $this->container()->get(LayoutSurfaceRegistry::class)->get($surface)->samples($target, null), "{$surface}: no sample to pick");
        $html = layout_stage_html($this->container(), $surface, $target, $this->container()->get(LayoutSurfaceRegistry::class)->get($surface)->starter($target), []);
        self::assertStringContainsString('data-thallo-placeholder', $html, "{$surface}: the placeholder sample");
        self::assertStringNotContainsString('A real post', $html);
    }
}

public function testAStageThatWouldShowASampleIsRefused(): void
{
    $this->publishEntry('lp_body', ['title' => 'A real post', 'body' => []]);
    $this->expectExceptionMessageMatches("~would replace the placeholder~");
    layout_stage_html($this->container(), 'entry', 'lp_body', $this->container()->get(LayoutSurfaceRegistry::class)->get('entry')->starter('lp_body'), []);
}
```

These two tests run inside the test's own transaction (as every `AppTestCase` test does), so the published entry, the seeded products and the isolated types disappear with it. `publishEntry()` publishes through the repositories as the layout sample tests do (grep `samples(` under `tests/Integration/Content/Layouts`). The first three tests above call `layout_thumbnail_targets()` first as well, and use its targets instead of `lp_body`/`@site`.

`stageThroughTheEditor()` does what `scripts/build-builder-proof-fixtures` does for a `stage-*.html` (read its post-layout section, lines 433–706): `LayoutPreviewController::session(new LayoutSessionData($surface, $target, null))`, then `apply` with the document (blocks given ids), then `RenderController::preview` for the session's token with `?canvas=1`, returning the body. `withoutVolatile()` removes the session token, the preview bridge script and inlined asset differences (compare the `<main>` element's inner HTML). `require_once` the lib in the test's set-up.

- [ ] **Step 4: Run** it. Expected: FAIL (`layout_stage_html` undefined).
- [ ] **Step 5: Implement `layout_stage_html`** in `scripts/lib/layout-thumbnails.php`: the `stageThroughTheEditor()` path itself (session, apply with the blocks given 12-character ids, `RenderController::preview` with `canvas=1`) — the thumbnail is that page, not a composition of it — then made self-contained exactly as `scripts/build-shop-block-proof-fixtures`' `$selfContained` does (stylesheets and images inlined through the application, the preview bridge `<script>` removed; move that closure into this lib as `layout_self_contained()` and have the shop script call it, so there is one). The test's `stageThroughTheEditor()` then calls the same session/apply/preview helper (`layout_stage_raw()`), so the proof compares the self-contained page against the raw one. Before opening the session, `layout_stage_html` throws `\RuntimeException("sample '{id}' would replace the placeholder")` when `$surface->samples($target, null)` is not empty.
  - `layout_thumbnail_targets(ContainerInterface $container): array<string,string>` (same lib): creates `thumb_category` and `thumb_post` (`title` string, `excerpt` string, `cover` asset, `categories` filterable reference to `thumb_category`, `body` blocks; public delivery) with no entries, opens `thumb_post`'s listing pages (the settings write `scripts/build-builder-proof-fixtures`' listing section uses, lines 708+), clears the products with `ShopPageSeed::clear()` when Commerce is registered, and returns `['entry' => 'thumb_post', 'listing' => 'thumb_post', 'archive' => 'thumb_post:categories', 'product' => '@site', 'shop_index' => '@site', 'shop_category' => '@site']` for the registered surfaces. It writes only inside the caller's transaction.
- [ ] **Step 6: The build.** After the existing (S1) transaction has rendered the page patterns and rolled back, open a **second** transaction for the layout patterns: `$targets = layout_thumbnail_targets($container)`, then for each `$surface => $target`, loop `forLayout($surface, $target)`, skipping saved entries (roll the transaction back at the end, as the first one is):
  - a **template**: `layout_stage_html($container, $surface, $target, $entry['blocks'], $entry['settings'])`; crop `selector` = the frame element (`.thallo-layout--{surface}`, `.shop-product--layout`, `.shop-index--layout`, `.shop-category--layout` — keyed by surface in a map).
  - a **section**: the surface's `starter($target)` with the section's blocks appended (the starter holds the required block); the section's root is given a known id (`thumbsection1`) before the call; crop `selector` = `[data-thallo-block="thumbsection1"]`.
  - write `<slug>.html`; job `{page, out, width: 1200, scale: 0.5, quality: 80, trim: true, maxHeight: kind page ? 2400 : 1600, selector}`, plus `ready: 'shop', shopBlocks: N` when it holds script-painted shop blocks (S1).
  - exit 1 when a slug in `layoutSlugs()` got no picture.
- [ ] **Step 7: Build and look.** `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-pattern-thumbnails`, once as the test database stands and once after publishing a post and seeding products outside the build (then clean them up): the pictures come out identical both times. Read `entry-magazine.jpg` (full width), `listing-card-grid.jpg`, `archive-term-grid.jpg`, `product-gallery-top.jpg`, `shop-index-banner.jpg` and one section (`entry-cover-band.jpg`): the placeholder sample shows (the placeholder notice cropped out by the selector, placeholder cards in loops, the sample product), no "Missing block template". Restore `hero-highlights.jpg` and `page-services.jpg` with `git checkout` if they drifted (S1 ruling). Every picture under 80,000 bytes.
- [ ] **Step 8: Run** the source test, the capture spec and the gate. Expected: PASS.
- [ ] **Step 9: Commit** the crop, the lib, the build, the tests, the 31 pictures and the sizes as `feat(patterns): a picture of every layout section and template, taken from the real stage`.

## Task L8: the stage editor checks a section against the whole layout, with the server's rules, and a template replaces it

**Files:**
- Create: `admin/src/editor/structure/layoutCandidate.ts`, `tests/fixtures/layouts/candidate-cases.json`, `tests/Integration/Content/Layouts/LayoutCandidateParityTest.php`
- Modify: `admin/src/editor/stage/types.ts` (`StageHost` hooks), `admin/src/editor/stage/useStageEditor.ts`, `admin/src/editor/structure/legality.ts` (export `isInsideCard`)
- Test: `admin/src/__tests__/layout-candidate.spec.ts`, `admin/src/__tests__/stage-editor.spec.ts`

**Interfaces:**
- `checkLayoutCandidate(doc: EditorDocument, rules: LayoutRules): Legality` where `LayoutRules = { field: string; required: { type: string; field?: string }[]; bindable: Record<string, string>; fieldLabels: Record<string, string>; bindings: Record<string, string[]>; defaultFields: Record<string, string>; formatNeeds: Record<string, string[]>; typeName: string | null; label: (type: string) => string }`.
- `StageHost.patterns?: () => Pattern[]` (the library the editor inserts from; absent → `usePatterns()` as today), `StageHost.candidateCheck?(doc: EditorDocument): Legality`, `StageHost.pageReplace?(pattern: Pattern): { ops: OperationBody[] } | null`.
- `isInsideCard(doc: EditorDocument, id: string, cards: CardRules | null): boolean`.

**The rules** (one list, both sides; "binding block" = a key of `bindings`):
1. A required block without a field (`product_buy`, `entry_loop`, `product_loop`) appears at most once — "The {label} can appear only once". The preflight lets a candidate *lack* a required block: an allowance for a working copy that is mid-edit, not permission to persist one — apply and save run the full `validate()` and refuse an incomplete layout, as today.
2. Only binding blocks are read as bindings; a `field` in any other block's data is ignored.
3. An unbound binding block binds `defaultFields[type]` when `bindable` has that field with a type in `bindings[type]`; otherwise it stays unbound and passes — **except `entry_content`**, which, still unbound, counts as showing `body` (the server's fallback in `assertBindings`), so rules 4, 5 and 7 judge it as `body`: refused on a type with no `body`, or whose `body` is not a blocks field.
4. A bound field must be in `bindable` — "This section shows “{label}”, which {typeName ?? 'this page'} doesn’t have" ({label} = `fieldLabels[field]` ?? humanised name).
5. A bound field's type must be in `bindings[type]` — "The {block label} block can’t show “{label}”".
6. An `entry_field` with a `format` that `formatNeeds` lists must show a field of one of those types — "“{label}” can’t be shown as a {format}".
7. No blocks field is shown by two `entry_content` blocks (after rule 3's defaults) — "“{label}” is already shown by another block".

- [ ] **Step 1: The shared cases.** `tests/fixtures/layouts/candidate-cases.json` is `{ "types": { … }, "cases": [ … ] }`. `types` defines the content types both sides use, by slug, each as `{ "name", "schema" }` — names "LF posts", "LF content", "LF rich", "LF categories" (the PHP test creates them; the vitest spec's `typeName` is the name; the vitest spec derives each type's `bindable` from them — `text` with `format: 'rich'` → `text:rich` — and its labels, humanised when absent):
  - `lf_post`: `title` string (label "Headline"), `body` blocks, `sidebar` blocks, `cover` asset, `categories` reference (filterable, to `lf_cat`);
  - `lf_content`: `title` string, `content` blocks (no `body`);
  - `lf_richbody`: `title` string, `body` text with `format: 'rich'`;
  - `lf_cat`: `title` string.
  Each case is `{ "name", "surface", "target", "blocks", "expect": "ok" | "<rule number>" }`. Write at least these cases, with ids on every block:
  - `fits` — the post starter's blocks → `ok`
  - `second buy box` — product: `product_buy` twice (one nested in a container) → `1`
  - `field in a non-binding block` — a `rich_text` whose data carries `field: 'subtitle'` → `ok`
  - `unbound cover binds its default` — `entry_cover` with no field, plus the starter → `ok`
  - `unbound excerpt with no excerpt field` — `entry_excerpt` with no field → `ok`
  - `missing field` — `entry_field {field: 'subtitle', format: 'text'}` → `4`
  - `incompatible field` — `entry_cover {field: 'title'}` → `5`
  - `date format on a string` — `entry_field {field: 'title', format: 'date'}` → `6`
  - `body shown twice` — the starter plus a second `entry_content {field: 'body'}` → `7`
  - `body shown twice by default` — the starter plus an `entry_content` with no field (its default is `body`) → `7`
  - `secondary blocks field shown twice` — `lf_post`: the starter plus two `entry_content {field: 'sidebar'}` → `7`
  - `unbound Entry content on a content-named body` — `lf_content`: `entry_title` and an `entry_content` with no field → `4` (it counts as `body`, which the type lacks)
  - `bound Entry content on a content-named body` — `lf_content`: `entry_title` and `entry_content {field: 'content'}` → `ok`
  - `unbound Entry content on a rich-text body` — `lf_richbody`: an `entry_content` with no field → `5` (`body` is not a blocks field)
  - `rich body shown as a field` — `lf_richbody`: `entry_field {field: 'body', format: 'rich'}` → `ok`
- [ ] **Step 2: Write the failing tests.**
  - `LayoutCandidateParityTest extends AppTestCase`: create the file's `types`; for each case, `validate($case['surface'], $case['target'], $case['blocks'], [], [], false)`; drop only the "must show" missing-block errors (the messages starting "the layout must show") — the preflight's allowance — and expect no error left for `ok` and at least one for a rule number. This pins what the server does for each case.
  - `layout-candidate.spec.ts`: import the same JSON (`import fixture from '../../../tests/fixtures/layouts/candidate-cases.json'`, enabling `resolveJsonModule` for the spec if needed); build each case's `LayoutRules` from `fixture.types[case.target]` (bindable and labels derived as above; `bindings`, `defaultFields` and `formatNeeds` the values L2's session test asserts; `required` from the surface: `entry_content` with the type's primary body for `entry`, `product_buy` for `product`); `checkLayoutCandidate` must answer `ok` for the `ok` cases and refuse the others with the rule's message (assert each message once in its own `it`, e.g. `This section shows “Subtitle”, which LF posts doesn’t have`, `The Cover block can’t show “Headline”`, `“Headline” can’t be shown as a date`, `“Body” is already shown by another block`, `The Product buy box can appear only once`, and for the two Entry content fallbacks `This section shows “Body”, which LF content doesn’t have` and `The Entry content block can’t show “Body”`).
  - `stage-editor.spec.ts` (with its existing host builder):
    - **candidate refusal changes nothing**: a host with `patterns: () => [section]` and a `candidateCheck` refusing with a message; `paletteClickable(patternKey(section.slug))` returns that verdict; inserting it (click, and the drag drop path) leaves `fields` and `currentSequence()` unchanged and warns with the message.
    - **the host's library is used**: with `patterns: () => [onlyHere]`, inserting `pattern:onlyHere` works though `usePatterns` (mocked to `[]`) does not have it.
    - **a replacement carries the host's extra ops**: `pageReplace: (p) => ({ ops: [{ type: 'SetPageSettings', field: '_layout_settings', from: present({}), to: present(p.settings ?? {}) }] })`; after `replaceWithPage('tpl', 'blocks')`, `fields._layout_settings` equals the template's settings and the blocks are the template's; one `undo()` restores the old blocks and the old settings; `redo()` brings back blocks whose ids equal the ones the replacement minted.
- [ ] **Step 3: Run** `vendor/bin/phpunit tests/Integration/Content/Layouts/LayoutCandidateParityTest.php` (expected: PASS — it states the server's answers; if a case disagrees with its `expect`, the case is wrong, fix it) and `cd admin && pnpm exec vitest run src/__tests__/layout-candidate.spec.ts src/__tests__/stage-editor.spec.ts` (expected: FAIL).
- [ ] **Step 4: Implement.**
  - `layoutCandidate.ts`: walk every block of `doc.fields[rules.field]` recursively (a nested block list is any `data` value that is an array of objects with `type`); resolve each binding block's field (its own, else rule 3's default, else `body` for `entry_content`, else none); apply rules 1–7 in that order and return the first refusal as `{ ok: false, reason: 'type-not-allowed', message }`, else `{ ok: true }`. `human = (f) => f.charAt(0).toUpperCase() + f.slice(1).replace(/_/g, ' ')`.
  - `types.ts`: the three hooks with doc comments.
  - `useStageEditor.ts`: `patterns` becomes `computed(() => host.patterns?.() ?? patternData.value ?? [])`. A private `checkPattern(position: Position, block: BlockInstance): Legality` runs `checkInsertSubtree(currentDoc(), position, block, legalityContext())` and, when that is ok and `host.candidateCheck` is set, `host.candidateCheck(insertCandidate(currentDoc(), position, block, legalityContext()) ?? currentDoc())`. Every place that checks a pattern section's placement uses it — `paletteClickable` for `pattern:` keys, the click/Enter insert and the drag drop (grep `instantiate(` in the file) — and a refusal warns with its message and records nothing. In `replaceWithPage`, after building `drop`, `const extra = host.pageReplace?.(pattern) ?? null; if (extra) drop.push(...extra.ops)` before `applyDrop(drop)`.
  - `legality.ts`: `export function isInsideCard(doc, id, cards)` — `locateBlock` the id and walk up with the existing `cardAt()` logic; false when `cards` is null.
- [ ] **Step 5: Run** the specs, the parity test, then `pnpm exec vitest run src/__tests__/structure-legality.spec.ts src/__tests__/regionsPage.spec.ts src/__tests__/blocks-palette-library.spec.ts`. Expected: PASS.
- [ ] **Step 6: Commit** (`pnpm type-check`, lint, fmt on touched files; phpcs) as `feat(editor): a section is checked against the whole layout with the server's rules before it lands, and a template brings its settings`.

## Task L9: the layout editor's Sections and Templates

**Files:**
- Modify: `admin/src/pages/layouts/[surface]/[target].vue`, `admin/src/pages/layouts/useLayoutHost.ts` (host hooks), `admin/src/editor/inspector/BlockInspector.vue` (`canSaveSection` prop), `CHANGELOG.md`
- Test: `admin/src/__tests__/layout-editor.spec.ts`, `admin/src/__tests__/saved-sections.spec.ts`

**Interfaces:** Consumes L2 (session fields), L4 (`useLayoutPatterns`, `belongsIn`), L8 (hooks, `checkLayoutCandidate`, `isInsideCard`).

- [ ] **Step 1: Write the failing tests** in `layout-editor.spec.ts` (mock `useLayoutPatterns` and `usePatterns` from `@/queries/patterns`, keeping the real `belongsIn`; the session gains the six binding keys):
  - **the three views**: the palette shows Blocks / Sections / Templates; Sections lists, in order, the surface's shipped section(s), then the shipped page sections (`faq`), then saved sections of this surface; a saved **page** section (`scope: 'page', saved: true`), a region section and another surface's section are absent; Templates lists the surface's templates; the layout query was called with the page's surface and target.
  - **a refused section**: a saved section binding `subtitle` (absent from `bindable`) is dimmed with the title "This section shows “Subtitle”, which LF posts doesn’t have"; clicking it records no apply and leaves `undo` disabled.
  - **a template on a clean layout** replaces blocks and settings with no question: click `entry-magazine` (settings `{ width: 'full' }`) → the working copy's blocks equal the template's (types, in order) and the Frame tab reads full width; one undo restores the old blocks and settings; redo brings back the same ids.
  - **a template on a changed layout asks first**: after an edit, clicking a template shows `[data-test="layout-template-replace"]` with "Replace this layout with **Magazine**? Your unsaved changes will be lost."; Keep leaves everything; Replace replaces.
  - **Save as section in a layout** sends `scope: 'layout'`, the surface and the target (assert the `useSavedSections().save` mock's argument), and the button is absent while a block inside the Entry list's card is selected.
- [ ] **Step 2: Run** `cd admin && pnpm exec vitest run src/__tests__/layout-editor.spec.ts`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `useLayoutHost.ts`: the host gains `patterns: () => library.value` (set by the page through a `setLibrary(list)` the host exposes), `candidateCheck: (doc) => session.value ? checkLayoutCandidate(doc, { field: 'blocks', required: session.value.required, bindable: session.value.bindable, fieldLabels: session.value.fieldLabels, bindings: session.value.bindings, defaultFields: session.value.defaultFields, formatNeeds: session.value.formatNeeds, typeName: session.value.typeName, label: typeLabel }) : { ok: true }` (`typeLabel` from the block types the page already reads), and `pageReplace: (p) => p.scope === 'layout' && p.kind === 'page' ? { ops: [{ type: 'SetPageSettings', field: SETTINGS_KEY, from: present(currentSettings()), to: present({ ...(p.settings ?? {}) }) }] } : null`.
  - `[target].vue`:
    - `const layoutLibrary = useLayoutPatterns(surface, target)` and `const { data: pageLibrary } = usePatterns()`; `library = computed(() => [...layout sections (scope layout, kind section, !saved, surface match), ...pageLibrary shipped sections (belongsIn page, kind section, !saved), ...layout saved (scope layout, saved, surface match), ...layout templates (kind page)])`; `watch(library, (l) => layout.setLibrary(l), { immediate: true })`.
    - Destructure `replaceClickable` and `replaceWithPage` from the editor. Mount `BlocksPalette` with `:patterns="library"`, `:page-clickable="(slug) => replaceClickable(slug, 'blocks')"`, `@insert-page="onInsertTemplate"`.
    - `pendingTemplate = ref<Pattern | null>(null)`; `onInsertTemplate(slug)`: find the pattern; `dirty.value` → set `pendingTemplate`; else `replaceWithPage(slug, 'blocks')`. The inline confirm (in the Blocks tab slot, like `regions/index.vue`'s) — `role="alertdialog"`, `data-test="layout-template-replace"`, text "Replace this layout with <strong>{{ label }}</strong>? Your unsaved changes will be lost.", buttons `layout-template-keep` ("Keep") and `layout-template-confirm` ("Replace"). Cleared when the palette view changes.
    - `BlockInspector`: `:section-place="{ scope: 'layout', surface }"`, `:section-target="target"`, `:can-save-section="!isInsideCard(currentDocument, selectedId, layout.host.cards?.() ?? null)"`.
  - `BlockInspector.vue`: props `canSaveSection?: boolean` (default true; the button's `v-if` adds it) and `sectionTarget?: string`, passed to `SaveSectionForm`, which adds `target` to the save payload when the place is a layout (`SavedSectionInput` accepts `target?: string`).
  - `CHANGELOG.md`: re-add `## [Unreleased]` above `## [1.0.0-beta.71]` with `### Added`: "**Sections and templates in the layout editor.** **Site › Layouts** has the Design view's **Sections** and **Templates**. Each kind of page — a single post, a listing, an archive, and with Commerce on the product page, the shop home and the shop categories — offers sections and three templates built for the layout's own content type. A template replaces the whole layout, its Frame settings included, and asks first when there are unsaved changes; one undo brings the old layout back. **Save as section** in a layout keeps its field blocks, and the section is offered in every layout of the same kind; one that shows a field the layout's type doesn't have says which. Saving, renaming and deleting a layout's section needs **Manage templates**."
- [ ] **Step 4: Run** the spec, then the whole admin vitest (`pnpm exec vitest run`). Expected: PASS.
- [ ] **Step 5: Commit** (type-check, lint, fmt on touched files) as `feat(layouts): the layout editor offers sections and templates, and saves sections of its own`.

## Task L10: browser proofs on real stages

**Files:**
- Modify: `admin/e2e/layouts-scenarios.json` (two scenarios), `scripts/build-builder-proof-fixtures` (the layout patterns fixture; the scenarios' stages are rendered by its existing loop), `admin/e2e/helpers.ts` (route `GET /patterns?surface=entry&target=post`; a way to queue the block ids the admin mints)
- Create: `admin/e2e/tests/layout-patterns.spec.ts`

No fallback stage: every document the editor accepts in this proof has its own rendered stage, and `unmatched` stays empty.

- [ ] **Step 1: The known ids.** The admin mints ids through `nextInstanceId()`, which under `VITE_E2E` takes them from `window.__thalloE2eBlockIds` first. `helpers.ts` gains `queueBlockIds(page: Page, ids: string[])` (an `addInitScript` setting the array before the page loads, or `page.evaluate` pushing onto it once loaded). The ids the proof queues: for the section, `neighbours001` (one block); for the template, one id per block of `entry-magazine` for `post`, in the order `allocateIds` walks them (the block first, then nested lists depth-first) — the build script writes that list (`api/layout-template-ids.json`) from the same tree so the two never drift.
- [ ] **Step 2: The scenarios.** In `admin/e2e/layouts-scenarios.json`, beside `baseline` and `dated`:
  - `with-neighbours`: the baseline's blocks followed by `{ "id": "neighbours001", "$factory": "entry_neighbours" }` with the Previous/next section's data (the same data `LayoutPatterns` ships — the build asserts the section served for `post` equals it, and exits 1 otherwise).
  - `magazine`: `entry-magazine`'s blocks for `post` with the queued ids, settings `{ "width": "full" }` — generated, not hand-written: the build writes this scenario's document from `forLayout('entry', 'post')` and the id list before its render loop, so the stage is the template as served.
  The script's existing loop validates each scenario through `LayoutValidator::validate('entry', 'post', …)` (unchanged, or exit 1), applies it and renders `stage-with-neighbours.html` and `stage-magazine.html` into `stages.json`. It also writes `api/patterns-layout-entry-post.json` from `PatternController::index(Request::create('/x', 'GET', ['surface' => 'entry', 'target' => 'post']))`.
- [ ] **Step 3: Write the proof.** `layout-patterns.spec.ts`, with `openLayoutStage(page, { world: 'post' })`, `openBlocksTab`, `layoutAcceptedIs` and `layoutStage`:
  - `a section lands in the layout and the stage shows it`: queue `['neighbours001']`; Sections view; click the `entry-neighbours` card; `layoutAcceptedIs(page, recorded, 'with-neighbours')`; the refreshed stage shows `[data-thallo-block="neighbours001"]`; Undo → `layoutAcceptedIs(…, 'baseline')` and the block is gone from the stage; Redo → `with-neighbours` again, the stage showing `neighbours001` — the same id.
  - `a template replaces the layout and its frame`: queue the template's ids; Templates view; click `entry-magazine` (a clean layout: no question); `layoutAcceptedIs(…, 'magazine')` — its `layout.settings` is `{ width: 'full' }`; the stage shows the magazine's blocks by their queued ids; Undo → `baseline` on the stage with its settings `{}`; Redo → `magazine` with the same ids.
  - Both end with `expect(recorded.unmatched).toEqual([])`.
- [ ] **Step 4: Prove the proof bites.** Temporarily make the layout host's `pageReplace` return null: the template test fails on `layoutAcceptedIs(…, 'magazine')` (settings `{}`, no stage for that document, `unmatched` not empty). Restore it.
- [ ] **Step 5: Rebuild the fixtures** (`CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`) and run `cd admin/e2e && pnpm exec playwright test tests/layout-patterns.spec.ts --workers=8`, then the whole e2e suite on eight workers. Expected: PASS.
- [ ] **Step 6: Commit** as `test(layouts): the layout editor inserts a section and applies a template, proven on real stages`.

## Task L11: docs

**Files:** `docs/guides/04-sections-and-pages.md`, `docs/guides/20-layouts.md`, `docs/guides/18-commerce.md`, `docs/reference/04-block-library.md`

- [ ] **Step 1: The sections guide.** A "Sections and templates in layouts" section: where they appear, the table of §6 (surface → templates → sections, with this plan's Product hero note), that templates are built for the layout's type (the cover band is offered only to types with a cover), saving a section from a layout (field blocks kept; offered in layouts of the same kind; a field the type lacks refuses the insert with the field named; **Manage templates** needed).
- [ ] **Step 2: The layouts guide.** "Start from a template": the template replaces the layout and its Frame settings, asks when there are unsaved changes, one undo; nothing is live until Save.
- [ ] **Step 3: The commerce guide.** The product and shop layouts' templates, one line each, pointing to the sections guide.
- [ ] **Step 4: The block reference**, where it lists patterns: the layout patterns.
- [ ] **Step 5: Run** `vendor/bin/phpunit tests/Unit/Docs`. Expected: PASS. Commit as `docs(layouts): sections and templates in the layout editor`.

## Final

- [ ] Run every gate in Global Constraints. Confirm no new top-level `tests/Integration` entry (all new tests sit in `Content`, `Content/Layouts` and `Commerce`).
- [ ] Final whole-branch review by a fresh reviewer, with this plan's Review Focus verbatim.
- [ ] The beta cut only when the user asks.

## Self-review

- **Spec coverage (S2):** the layout place and surface (§3): L1, L4. Sources — core `LayoutPatterns`, commerce through the contributor only with Commerce on: L3, L6. Filtering — unregistered surface hidden, templates through the real validator, sections through palette/cards/bindings: L2, L4. Per-target endpoint and query key: L4. Identity (§3.1) for layout patterns: L1 (references: ruled page-only). Schema-aware patterns and the type-shape matrix (§3.2): L3, L4. Layout editor Sections/Templates (§4), candidate-document validation, template replacement with settings, confirm, one undo, redo ids: L8, L9. Save as section with the layout place and not inside a card: L9. Saved layout sections (§5) — migration, surface validation, offered per surface, bindings refused with the field named, permissions by row scope, lifecycle (source unchanged; hidden and kept with the surface): L5, L8, L9. What ships (§6): L3, L6 (with the Product hero ruling). Thumbnails: L7. Tests (§7): each task. Browser proofs (§7): L10, on generated stages with known ids; the page Design view's commerce-off proof shipped in S1 (`shop-patterns.spec.ts`). Renders: L7's build renders every layout pattern on its placeholder sample and fails on a missing picture. Docs (§8): L11. Changelog: L9.
- **Types:** `LayoutTarget`, `LayoutSection`, `LayoutTemplate`, `LayoutPatternContributor`, `registerLayout`, `layoutContributors`, `fragment`, `targetError`, `savedEntry`, `forLayout`, `layoutSlugs`, `targetFor`, `LayoutRules`, `checkLayoutCandidate`, `isInsideCard`, `StageHost.patterns/candidateCheck/pageReplace` are named once above and used consistently in L1–L10.
- **Review Focus:** items 1–5 each pinned to a task.
