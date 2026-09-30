# Sections and Templates — Release S2 (layout sections and templates) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The layout editor (**Site › Layouts**) gets the Design view's Blocks / Sections / Templates switch. Every surface — single post, listing, archive, and with Commerce on the product page, the shop home and the shop categories — offers ready-made sections and three whole templates built for the layout's own content type. A template replaces the layout (blocks and Frame settings) in one undo step, asking first when there are unsaved changes. An editor can save a section from a layout, field blocks included, and reuse it in any layout of the same kind; a section showing a field the target type lacks is refused with the field named.

**Architecture:** A pattern's place gains `layout` with a `surface`. Core ships the `entry`, `listing` and `archive` layout patterns in a new `LayoutPatterns` class; commerce ships `product`, `shop_index` and `shop_category` through a new `LayoutPatternContributor` contract on the S1 registry. Layout patterns are **built for a target**: a builder receives a `LayoutTarget` (the surface, the target and the target type's fields) and returns a tree bound to that type's real fields, or null when it does not fit. `PatternLibrary::forLayout($surface, $target)` builds, validates (templates through the real `LayoutValidator`, sections through a new `LayoutValidator::checkFragment`) and returns them with the saved sections of that surface; `GET /patterns?surface=&target=` serves them. The admin layout page merges them with the shipped general page sections, hands them to the stage editor through new `StageHost` hooks (`patterns`, `candidateCheck`, `pageReplace`), checks every section insert against the complete candidate document (required blocks once, field bindings), and replaces the working copy with a template in one transaction. Saved sections gain a nullable `surface` column and scope `layout`; saving, renaming and deleting authorize by scope inside the controller.

**Tech Stack:** PHP 8.3 (Glueful, PostgreSQL, PHPUnit), Twig 3, Nuxt UI admin (Vue 3, pinia-colada, vitest, Playwright e2e in `admin/e2e`), thumbnails via `scripts/build-pattern-thumbnails` and `tools/style-proofs/capture-page.js`.

**Spec:** `docs/internal/superpowers/specs/2026-09-29-layout-sections-templates-design.md` (amended 2026-09-30). This release is §9 item 2 (S2): §3 (the layout place, sources, filtering), §3.1 (identity for layout patterns), §3.2 (targets and schema-aware patterns), §4 (the layout editor's Sections and Templates), §5 (saved sections in layouts), §6 (the layout patterns and their thumbnails), §7 (the S2 tests) and §8 (docs). S1 shipped as 1.0.0-beta.71: the contributor registry, `PatternBlocks` in contracts, the commerce page contributor, the `requires` hint and the thumbnail pipeline.

## Rulings made while planning (from the code)

- **Layout templates are whole trees, not section lists.** Spec §3.1 says a layout template names sections of its surface or page sections. But every template must hold its surface's required block (the primary body's Entry content block, the Entry list, the Product buy box, the Product list), and none of the §6 sections holds one — so a layout template cannot be assembled from §6 sections. A layout template is therefore a builder that returns the whole tree for a target, plus its settings payload. It holds no references, so the reference check of §3.1 applies to page templates only (as S1 built it). Shared parts are shared through PHP builder functions, not slugs. Cost if wrong: a template's parts cannot be reused by slug; nothing a person sees changes.
- **The Product hero section holds no buy box.** §6 lists "Product hero (gallery, name, rating, price, buy box)". The Product buy box is required exactly once and a layout's editor refuses to delete it (`requiredReason`), so every product layout always holds one, and a section holding a second one could never be inserted. The Product hero is the gallery beside the name, rating and price; its description says the layout's buy box stays where it is. Cost if wrong: one block to add to one section.
- **Builders see a `LayoutTarget`.** Packs may reference only `Thallo\Contracts`, so the builder input is a contracts class: `surface`, `target` and `fields` — the target type's fields as `list<array{name, type, format, filterable}>` in schema order (empty for the commerce surfaces, whose blocks bind no field). Core rebuilds a `ContentTypeSchema` from it (`ContentTypeSchema::fromArray`) to reuse `Starters`. Cost if wrong: one more property later.
- **One registry, two kinds of contributor.** `PatternContributorRegistry` gains `registerLayout(LayoutPatternContributor)` and `layoutContributors()`. Slugs stay unique across everything: core page and region patterns, core layout patterns (`LayoutPatterns::slugs()`, seeded as `core`), page contributors and layout contributors. Layout contributor ids are unique among layout contributors (commerce registers `thallo.commerce` in both lists). A layout section or template must name one of the six known surfaces. Cost if wrong: none; collisions are programming errors.
- **What the per-target endpoint returns.** `GET /patterns?surface={s}&target={t}` returns the layout place only: the shipped sections of that surface that fit the target, its templates that pass the target's `LayoutValidator`, and the saved sections of that surface. The shipped general page sections come from `GET /patterns` as today; the layout page merges them (§4's order: surface sections, then shipped page sections, then saved sections of the surface). `GET /patterns` (no query) never returns layout-place entries. An unknown or unregistered surface answers 422 `{surface: "unknown layout surface '…'"}`; a target that cannot have a layout answers 200 with an empty list. Cost if wrong: one merge moves to the server.
- **Saved sections are listed, not filtered, by binding.** A saved layout section of the surface is offered for every target of that surface. Its card is refused at insert time with the field named (§3.2, §5) — not hidden — so the editor learns why. Shipped sections are filtered server-side: they are built for the target and never bind a missing field.
- **The client learns the target's fields from the session.** `LayoutPreviewController::session` adds `bindable` (field name ⇒ type, `text:rich` for rich text, from `LayoutSurface::bindable`), `bindings` (block type ⇒ accepted field types, the validator's `BINDINGS`, now public) and `type_name` (the target content type's `name`, null for the commerce surfaces). The candidate check uses these; nothing is duplicated in the admin. Field names are shown humanised (`subtitle` → "Subtitle"): schema fields carry no label (`FieldDefinition` has none).
- **The candidate check covers pattern sections.** §4 names section inserts. The stage editor runs `host.candidateCheck` on the complete candidate document for every pattern section placement (click, Enter, drag) and in `paletteClickable` for `pattern:` keys, so the card is dimmed with the reason before any click. Plain block tiles keep today's behaviour (the server refuses a second required block on apply). Cost if wrong: extending it to tiles is one call.
- **Template replacement reuses the Header & footer path.** `replaceWithPage(slug, field)` already removes the field's blocks and inserts the template's in one `applyDrop` transaction (one undo entry; redo replays the same ops, so the minted ids come back — `editor-ops.spec.ts` "reuses ids on redo"). A new `StageHost.pageReplace(pattern)` hook appends a `SetPageSettings` op on `_layout_settings` to the same transaction. The confirm is an inline `role="alertdialog"` like the Header & footer page's, shown only while `dirty` is true (`dirty` compares against the history's saved position, which is the saved layout, or the starter when nothing is saved — exactly §4's rule).
- **Save-time validation of a layout section uses the layout it came from.** A layout section is validated with `FieldValidator::forLayouts()` and `LayoutValidator::checkFragment($surface, $target, [$block])`. The request carries the `target` it was saved from (validated, never stored). Insert and apply re-check against the real target later.
- **Permissions move into the controller.** `POST`, `PATCH` and `DELETE /saved-sections` keep a route floor of `content_permission:content.view` (any editor) and the controller decides: a `layout` section needs `templates.manage`; a page or region section needs `content.manage`. Saving reads the request's scope; renaming and deleting read the stored row's scope (`SavedSectionRepository::find`). A refusal is 403 `{code: FORBIDDEN}`, like the middleware's. Listing stays `content.view`.
- **Thumbnails of layout patterns are the stage's picture.** A layout pattern renders as the layout stage renders it with no published sample: the surface's `placeholder($target)`, annotation scope `layout` (placeholder cards in loops, the empty-field hints), inside `<article class="thallo-layout thallo-layout--{surface}">`, with `preview.css` inlined beside the theme. A new `showcase_layout_renderer()` in `scripts/lib/showcase.php` does it. Each pattern is pictured for one representative target per surface — `post` (entry, listing), `post:categories` (archive), `@site` (commerce) — which the build prepares inside its rolled-back transaction. A section is pictured alone.
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
    /** @param list<array{name: string, type: string, format: ?string, filterable: bool}> $fields the target type's fields, in schema order ([] off a content type) */
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
- Session (`LayoutSession` in `admin/src/queries/layouts.ts`) gains `bindable: Record<string, string>`, `bindings: Record<string, string[]>`, `typeName: string | null` (server keys `bindable`, `bindings`, `type_name`).

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

## Task L2: the validator checks a section for a target, and the session tells the admin the target's fields

**Files:**
- Modify: `core/src/Content/Layouts/LayoutValidator.php` (make `BINDINGS` public; add `checkFragment`), `core/src/Http/Controllers/LayoutPreviewController.php` (session payload), `admin/src/queries/layouts.ts` (`LayoutSession`)
- Test: `tests/Integration/Content/Layouts/LayoutFragmentTest.php` (new file in an existing directory), `tests/Integration/Content/Layouts/LayoutSessionTest.php` if it exists, else the session assertions go in `LayoutFragmentTest`

**Interfaces:** Produces `LayoutValidator::checkFragment(string $surface, string $target, array $blocks): array<string,string>` (errors keyed by dot path, `[]` when it fits) and the session keys `bindable`, `bindings`, `type_name`.

- [ ] **Step 1: Write the failing tests.** `LayoutFragmentTest extends AppTestCase` (use `SyncsBlockStyleDeclarations`; create a `post`-like type with `title` string, `body` blocks, `cover` asset, `categories` filterable reference, and a `page`-like type with `title` and `body` only, through `ContentTypeRepository::create` as other layout tests do — copy the set-up from `tests/Integration/Content/Layouts/LayoutSaveTest.php`):

```php
public function testASectionThatFitsHasNoErrors(): void
{
    $block = ['type' => 'entry_cover', 'data' => ['field' => 'cover', 'aspect' => '16:9'], 'settings' => []];
    self::assertSame([], $this->validator()->checkFragment('entry', 'lf_post', [$block]));
}

public function testAMissingFieldIsNamed(): void
{
    $block = ['type' => 'entry_field', 'data' => ['field' => 'subtitle', 'format' => 'text'], 'settings' => []];
    $errors = $this->validator()->checkFragment('entry', 'lf_page', [$block]);
    self::assertSame("this type has no field 'subtitle'", $errors['blocks.0.data.field'] ?? null);
}

public function testAnIncompatibleFieldIsRefused(): void
{
    $block = ['type' => 'entry_cover', 'data' => ['field' => 'title'], 'settings' => []];
    self::assertSame("'title' cannot be shown by this block", $this->validator()->checkFragment('entry', 'lf_post', [$block])['blocks.0.data.field'] ?? null);
}

public function testTheSurfacesPaletteAndCardsStillApply(): void
{
    $loop = ['type' => 'entry_loop', 'data' => ['card' => []], 'settings' => []];
    self::assertNotSame([], $this->validator()->checkFragment('entry', 'lf_post', [$loop]), 'an Entry list is not in the post palette');
    $title = ['type' => 'entry_title', 'data' => ['level' => 'h2'], 'settings' => []];
    self::assertSame("'entry_title' goes inside the Entry list's card", array_values($this->validator()->checkFragment('listing', 'lf_post', [$title]))[0] ?? null);
}

public function testRequiredBlocksAreNotAsked(): void
{
    // A fragment is a part of a layout: it need not hold the required Entry content block.
    $title = ['type' => 'entry_title', 'data' => ['level' => 'h1'], 'settings' => []];
    self::assertSame([], $this->validator()->checkFragment('entry', 'lf_post', [$title]));
}

public function testTheSessionCarriesTheTargetsFields(): void
{
    $data = $this->session('entry', 'lf_post');
    self::assertSame(['title' => 'string', 'body' => 'blocks', 'cover' => 'asset', 'categories' => 'reference'], $data['bindable']);
    self::assertSame(['asset'], $data['bindings']['entry_cover']);
    self::assertSame('LF posts', $data['type_name']);
}
```

`session()` calls `LayoutPreviewController::session(new LayoutSessionData(...))` and decodes `data`; `validator()` is the container's `LayoutValidator`. Name the created type `LF posts`. Match the exact card error text to what `cardErrors()` produces (read it before running, and correct the expectation if the loop label differs — the message form is `'{type}' goes inside the {LoopLabel}'s card`).

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Content/Layouts/LayoutFragmentTest.php`. Expected: FAIL (`checkFragment` undefined, session keys missing).
- [ ] **Step 3: Implement.**
  - `public const BINDINGS` (was private).
  - `checkFragment()`: return `['surface' => …]` for an unknown surface and `['target' => …]` for a target `LayoutTargets::find` does not offer (the same two checks `validate()` opens with); run the palette check over `walk()` against `allowedTypes($surface)`, then `cardErrors($surface, $target, $blocks)`, then `FieldValidator::forLayouts()->validate(ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]), ['blocks' => self::withTemporaryIds($blocks)], true)` catching `ValidationException` into the errors (a private `withTemporaryIds()` gives each block a 12-character id so strict validation does not refuse id-less pattern trees), then the **per-block** part of `assertBindings()` — the missing field, the incompatible type and the `entry_field` format checks. Extract that part of `assertBindings()` into a private `bindingErrors(array $blocks, array $bindable): array` that both call, so the wording stays one; `assertBindings()` keeps the duplicate-content, required and never-hidden checks on top.
  - `LayoutPreviewController::session`: add `'bindable' => $surface->bindable($input->target)`, `'bindings' => LayoutValidator::BINDINGS`, `'type_name' => $typeName` where `$typeName` is the name of `LayoutSaver::typeOf($input->surface, $input->target)`'s content type (`ContentTypeRepository::findBySlug`), or null. Inject `ContentTypeRepository` as an optional constructor argument (autowired). Update the `#[ApiResponse]`/doc text that lists the session keys.
  - `admin/src/queries/layouts.ts`: `LayoutSession` gains `bindable: Record<string, string>`, `bindings: Record<string, string[]>`, `typeName: string | null`, and the mapper reads `bindable ?? {}`, `bindings ?? {}`, `type_name ?? null`.
- [ ] **Step 4: Run** the new test, then `vendor/bin/phpunit tests/Integration/Content/Layouts` and `cd admin && pnpm exec vitest run src/__tests__/layout-host.spec.ts src/__tests__/layout-editor.spec.ts`. Expected: PASS (the vitest session builders gain the three keys where TypeScript asks for them).
- [ ] **Step 5: Commit** (phpcs, `pnpm type-check`, fmt) as `feat(layouts): check a section against a layout's target, and tell the editor the target's fields`.

## Task L3: core's layout patterns, built for every type shape

**Files:**
- Modify: `core/src/Content/Patterns/LayoutPatterns.php` (the stub from L1)
- Test: `tests/Integration/Content/Layouts/LayoutPatternsTest.php`

**Interfaces:** Produces `LayoutPatterns::sections(): list<LayoutSection>`, `LayoutPatterns::templates(): list<LayoutTemplate>` (core's, surfaces `entry`, `listing`, `archive`) and `LayoutPatterns::slugs(): array<string,string>` (every slug ⇒ surface, used by L1's registry). Consumes the L1 contracts, `Starters`, `PatternBlocks`.

- [ ] **Step 1: Write the failing test.** `LayoutPatternsTest extends AppTestCase` builds five types (§3.2) through `ContentTypeRepository::create` with `public_delivery => true`, and switches their listing pages on through `GeneralSettings` as `LayoutSaveTest`/the listing tests do:

| slug | fields |
|---|---|
| `lp_body` | `title` string, `excerpt` string, `cover` asset, `categories` reference (filterable, to a `lp_cat` type), `body` blocks |
| `lp_content` | `title` string, `content` blocks, `cover` asset |
| `lp_rich` | `title` string, `text` text (format `rich`) |
| `lp_nobody` | `title` string, `summary` string |
| `lp_nocover` | `title` string, `body` blocks |

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
                self::assertSame([], $validator->checkFragment($section->surface, $target, [$block]), "{$section->slug} for {$target}");
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

Helpers: `targets('entry'|'listing')` = the five slugs; `targets('archive')` = `['lp_body:categories']`; `layoutTarget($surface, $target)` builds `new LayoutTarget($surface, $target, $fields)` from the type's schema (`name`, `type`, `format`, `filterable` per field, schema order) — use the same conversion L4's library uses (L4 extracts it into `LayoutPatterns::targetFor()`; write it here in L3 as `public static function targetFor(string $surface, string $target, ?array $schema): LayoutTarget` taking the raw schema array, and use it in the test); `withIds()` gives every block a 12-character id recursively; `find($tree, $type)` walks nested block lists.

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Content/Layouts/LayoutPatternsTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** `LayoutPatterns` (use `PatternBlocks as B`; the private helpers below build trees without ids; `$schema = ContentTypeSchema::fromArray($t->fields)`):

```php
public const SURFACES = ['entry', 'listing', 'archive', 'product', 'shop_index', 'shop_category'];

public static function targetFor(string $surface, string $target, ?array $schema): LayoutTarget
{
    $fields = [];
    foreach ($schema ?? [] as $field) {
        if (is_array($field) && isset($field['name'], $field['type'])) {
            $fields[] = [
                'name' => (string) $field['name'],
                'type' => (string) $field['type'],
                'format' => isset($field['format']) ? (string) $field['format'] : null,
                'filterable' => (bool) ($field['filterable'] ?? false),
            ];
        }
    }
    return new LayoutTarget($surface, $target, $fields);
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

**Entry** (the target's schema drives every part; `$ref` = first filterable reference field, `$cover` = first asset field, `$title` = a `title` string field, `$body` = `Starters::primaryBody($schema)`, `$rich` = first rich text field, `$article` as `Starters::forSchema` decides — write private resolvers mirroring `Starters::forSchema`'s rules, and a private `content()` returning `entry_content {field: $body}` when there is a body, else `entry_field {field: $rich, format: 'rich'}`, else null):

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

`deactivateBlockType()` sets the type inactive through `BlockTypeRepository` as the S1 test `testATemplateGoesWhenACoreSectionItNamesGoes` does. In `PatternLibraryTest::testTheAdminReadsTheLibraryWithTheRightToSeeContent`, the `index()` call becomes `index(Request::create('/x'))`. In `admin/src/queries/patterns.spec.ts` add: `belongsIn(layoutPattern('entry'), { scope: 'layout', surface: 'entry' })` is true, false for surface `listing`, false for `{ scope: 'page' }`; a page pattern is not in a layout place.

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Content/Layouts/LayoutLibraryTest.php tests/Integration/Content/PatternLibraryTest.php` and `cd admin && pnpm exec vitest run src/queries/patterns.spec.ts`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `PatternLibrary` constructor gains optional `?LayoutSurfaceRegistry $surfaces = null, ?LayoutValidator $layouts = null, ?ContentTypeRepository $types = null` (autowired, after the S1 arguments). Every entry built by `all()` gains `'surface' => null, 'settings' => null` (`place()` sets them).
  - `forLayout($surface, $target)`:
    1. `$this->surfaces?->get($surface)` null → `throw new \InvalidArgumentException("unknown layout surface '{$surface}'")`.
    2. `$type = LayoutSaver::typeOf($surface, $target)`; `$schema = $type === null ? null : $this->types?->findBySlug($type)['schema'] ?? null`; `$lt = LayoutPatterns::targetFor($surface, $target, $schema)`.
    3. Sources: `LayoutPatterns::sections()`/`templates()` then each `layoutContributors()`'s, filtered to `->surface === $surface`.
    4. A section: `$block = ($s->build)($lt)`; skip null; skip when `$this->layouts->checkFragment($surface, $target, [$block]) !== []`; skip when `resolve([$block])` is null (the S1 type rule); entry `{slug, kind: 'section', label, category, description, requires: null, blocks: resolved, scope: 'layout', region: null, surface, settings: null, saved: false, id: null}`.
    5. A template: `$tree = ($t->build)($lt)`; skip null; skip when `resolve($tree)` is null; `validate($surface, $target, withIds($tree), $t->settings, [], false)` in a try — skip on `ValidationException`; entry with `kind: 'page'`, `category: 'Layouts'`, `blocks: resolved`, `settings: $t->settings` (as an object-safe array; `[]` stays `[]`).
    6. Saved sections: rows with `scope === 'layout'` and `surface === $surface` (L5 adds the column; until then none match), resolved like `savedSections()`, with `surface` set.
    7. Order: sections, templates, saved.
    8. A target `validate()` refuses as a target (closed, unknown) is caught: when `checkFragment` returns only a `target` error for the first section, return `[]`.
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

**Interfaces:** Produces the `surface` column and scope `layout`; `SavedSectionRepository::create(..., ?string $by, ?string $surface = null)`, `find(string $id): ?array`; `SaveSectionData` gains `?string $surface`, `?string $target`. Consumes L2 (`checkFragment`), L4 (`forLayout`).

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
    self::assertSame(['layout', 'entry'], [$section['scope'], $section['surface']]);
    self::assertSame('entry_cover', $section['blocks'][0]['type']);
    self::assertNotContains($section['slug'], array_column($this->library()->all(), 'slug'), 'not in the page library');
    self::assertContains($section['slug'], array_column($this->library()->forLayout('entry', 'lp_nocover'), 'slug'), 'offered in every layout of its surface');
    self::assertNotContains($section['slug'], array_column($this->library()->forLayout('listing', 'lp_body'), 'slug'));
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
  - Controller: constructor gains `?PermissionRequirementAuthority $authority = null, ?LayoutSurfaceRegistry $surfaces = null, ?LayoutValidator $layouts = null` (after `$classGuard`). A private `may(Request $request, string $scope): bool` returns `$this->authority?->allows($request, [$scope === 'layout' ? 'templates.manage' : 'content.manage']) ?? false`; a refusal returns `Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN'])`. `store()` checks it right after `place()`; `place()` accepts `layout` with a surface the registry knows (`['surface' => "unknown layout surface '{$surface}'"]` otherwise) and a non-empty `target`; a layout section validates with `$this->validator->forLayouts()` over the one-field schema and then `$this->layouts->checkFragment($surface, $target, [$block])` (errors remapped from `blocks.0` to `block` like today's), and is created with its surface. `update()` and `destroy()` take `Request $request` as their last argument, load `find($id)` (404 when null) and check `may($request, $row['scope'])` before changing anything.
  - Routes: the three routes' middleware becomes `content_permission:content.view`, with a comment that the controller decides by scope (sections and templates design §5).
  - `PatternLibrary`: `savedSections()` skips `scope === 'layout'`; `forLayout()` step 6 now reads the column.
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
        self::assertSame([], $validator->checkFragment($section->surface, '@site', [$block]), $section->slug);
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

## Task L7: thumbnails for every layout pattern

**Files:**
- Modify: `scripts/lib/showcase.php` (`showcase_layout_renderer`), `scripts/build-pattern-thumbnails` (layout patterns), `tests/Integration/Content/PatternLibraryTest.php` (the gate)
- Create: `admin/public/pattern-thumbs/<slug>.jpg` for the 31 layout slugs; regenerate `admin/src/editor/palette/patternThumbSizes.json`

**Interfaces:** Consumes `PatternLibrary::forLayout()` and `layoutSlugs()`.

- [ ] **Step 1: Widen the gate first** (red): in `testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan`, the slug list becomes `[...array_column($this->library()->all(), 'slug'), ...$this->library()->layoutSlugs()]`, sorted. Run `vendor/bin/phpunit --filter testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan`. Expected: FAIL naming the missing layout pictures.
- [ ] **Step 2: The renderer.** `showcase_layout_renderer(ContainerInterface $container, string $root): callable(string $surface, string $target, list<array> $blocks, array $settings, string $title): string` — built like `showcase_renderer($container, $root, 'default', true)` (the application's `ThemeLocator`, the contributed stylesheets), plus `packages/thallo-render/assets/preview/preview.css` in the inline CSS. Per call: `resetPerRenderState()`, `setAnnotationScope('layout')`; `$surface = registry->get($surface)`; the context is `$surface instanceof LayoutSampleContext ? $surface->placeholder($target) : ['entry' => $surface->placeholder($target), 'type' => $target]`, plus `layout => ['blocks' => $blocks, 'settings' => $settings, 'surface' => …, 'target' => …]`; the body is `$twig->createTemplate('<article class="thallo-layout thallo-layout--{{ s }}">{{ layout_blocks(layout.blocks) }}</article>')->render($context + ['s' => $surface->key()])`; the page wraps it exactly as `showcase_renderer` does, and resets the annotation scope to `none` after.
- [ ] **Step 3: The build.** Inside the existing rolled-back transaction, prepare the representative targets: a `post` content type (if the test database lacks it) with `title`, `excerpt`, `cover`, `categories` (filterable reference to a `category` type) and `body`, public delivery, listing pages on — reuse what `scripts/build-builder-proof-fixtures`' listing section does to open a listing target (read its lines 708+ and copy the settings write). Then for every surface in `LayoutPatterns::SURFACES` that the registry knows, with target `post` / `post` / `post:categories` / `@site` / `@site` / `@site`, loop `forLayout($surface, $target)` skipping saved entries; render each with `showcase_layout_renderer` (a section alone; a template with its settings); write `<slug>.html`; job `{page, out, width: 1200, scale: 0.5, quality: 80, trim: true, maxHeight: kind page ? 2400 : 1600}` (with `ready: 'shop', shopBlocks: N` when it holds shop blocks, as S1). Fail the build (exit 1) when a slug in `layoutSlugs()` got no picture.
- [ ] **Step 4: Build and look.** `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-pattern-thumbnails`. Read `entry-magazine.jpg`, `listing-card-grid.jpg`, `archive-term-grid.jpg`, `product-gallery-top.jpg` and `shop-index-banner.jpg`: the placeholder sample shows (a titled "Sample post", placeholder cards in loops, the sample product), no "Missing block template". Restore `hero-highlights.jpg` and `page-services.jpg` with `git checkout` if they drifted (S1 ruling). Every picture under 80,000 bytes.
- [ ] **Step 5: Run** the gate. Expected: PASS.
- [ ] **Step 6: Commit** the renderer, the build, the gate, the 31 pictures and the sizes as `feat(patterns): a picture of every layout section and template, on its surface's sample`.

## Task L8: the stage editor checks a section against the whole layout, and a template replaces it

**Files:**
- Create: `admin/src/editor/structure/layoutCandidate.ts`
- Modify: `admin/src/editor/stage/types.ts` (`StageHost` hooks), `admin/src/editor/stage/useStageEditor.ts`, `admin/src/editor/structure/legality.ts` (export `isInsideCard`)
- Test: `admin/src/__tests__/layout-candidate.spec.ts`, `admin/src/__tests__/stage-editor.spec.ts`

**Interfaces:**
- `checkLayoutCandidate(doc: EditorDocument, rules: LayoutRules): Legality` where `LayoutRules = { field: string; required: { type: string; field?: string }[]; bindable: Record<string, string>; bindings: Record<string, string[]>; typeName: string | null; label: (type: string) => string }`.
- `StageHost.patterns?: () => Pattern[]` (the library the editor inserts from; absent → `usePatterns()` as today), `StageHost.candidateCheck?(doc: EditorDocument): Legality`, `StageHost.pageReplace?(pattern: Pattern): { ops: OperationBody[] } | null`.
- `isInsideCard(doc: EditorDocument, id: string, cards: CardRules | null): boolean`.

- [ ] **Step 1: Write the failing tests.** `layout-candidate.spec.ts`:

```ts
const rules = (over: Partial<LayoutRules> = {}): LayoutRules => ({
  field: 'blocks',
  required: [{ type: 'product_buy' }, { type: 'entry_content', field: 'body' }],
  bindable: { title: 'string', body: 'blocks', cover: 'asset' },
  bindings: { entry_cover: ['asset'], entry_field: ['string', 'text', 'text:rich'], entry_content: ['blocks'] },
  typeName: 'Pages',
  label: (t) => ({ product_buy: 'Product buy box', entry_cover: 'Cover' })[t] ?? t,
  ...over,
})
const doc = (blocks: unknown[]): EditorDocument => ({ fields: { blocks } })
const b = (id: string, type: string, data: Record<string, unknown> = {}) => ({ id, type, data, settings: {} })

it('refuses a second required block, naming it', () => {
  const v = checkLayoutCandidate(doc([b('a', 'product_buy'), b('c', 'container', { content: [b('d', 'product_buy')] })]), rules())
  expect(v).toEqual({ ok: false, reason: 'type-not-allowed', message: 'The Product buy box can appear only once' })
})
it('refuses a second block showing the primary body', () => {
  const v = checkLayoutCandidate(doc([b('a', 'entry_content', { field: 'body' }), b('b', 'entry_content', { field: 'body' })]), rules())
  expect(v.ok).toBe(false)
})
it('names a field the type lacks', () => {
  const v = checkLayoutCandidate(doc([b('a', 'entry_field', { field: 'subtitle', format: 'text' })]), rules())
  expect(v).toEqual({ ok: false, reason: 'type-not-allowed', message: 'This section shows “Subtitle”, which Pages doesn’t have' })
})
it('names a field the block cannot show', () => {
  const v = checkLayoutCandidate(doc([b('a', 'entry_cover', { field: 'title' })]), rules())
  expect(v).toEqual({ ok: false, reason: 'type-not-allowed', message: 'The Cover block can’t show “Title”' })
})
it('lets an unbound field block through (the server binds its default)', () => {
  expect(checkLayoutCandidate(doc([b('a', 'entry_cover')]), rules()).ok).toBe(true)
})
it('passes a document that fits', () => {
  expect(checkLayoutCandidate(doc([b('a', 'product_buy'), b('b', 'entry_cover', { field: 'cover' })]), rules()).ok).toBe(true)
})
it('without a type name still names the field', () => {
  expect(checkLayoutCandidate(doc([b('a', 'entry_field', { field: 'subtitle' })]), rules({ typeName: null }))).toMatchObject({ message: 'This section shows “Subtitle”, which this page doesn’t have' })
})
```

In `stage-editor.spec.ts` (with its existing host builder), add:
  - **candidate refusal changes nothing**: a host with `patterns: () => [section]` and `candidateCheck: () => ({ ok: false, reason: 'type-not-allowed', message: 'The Product buy box can appear only once' })`; `paletteClickable(patternKey(section.slug))` returns that verdict; `insertFromPalette(patternKey(section.slug))` leaves `fields` and `currentSequence()` unchanged and warns with the message.
  - **the host's library is used**: with `patterns: () => [onlyHere]`, inserting `pattern:onlyHere` works though `usePatterns` (mocked to `[]`) does not have it.
  - **a replacement carries the host's extra ops**: `pageReplace: (p) => ({ ops: [{ type: 'SetPageSettings', field: '_layout_settings', from: present({}), to: present(p.settings ?? {}) }] })`; after `replaceWithPage('tpl', 'blocks')`, `fields._layout_settings` equals the template's settings and the blocks are the template's; one `undo()` restores both the old blocks and the old settings; `redo()` brings back blocks whose ids equal the ones the replacement minted.

- [ ] **Step 2: Run** `cd admin && pnpm exec vitest run src/__tests__/layout-candidate.spec.ts src/__tests__/stage-editor.spec.ts`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `layoutCandidate.ts`: walk every block in `doc.fields[rules.field]` recursively (nested block lists: any `data` value that is an array of objects with `type`). Count required types (`entry_content` by `data.field ?? rules.required`'s field); a count above one → `The ${label(type)} can appear only once`. For each block with a string `data.field`: not in `bindable` → `This section shows “${human(field)}”, which ${typeName ?? 'this page'} doesn’t have`; `bindings[type]` present and not including `bindable[field]` → `The ${label(type)} block can’t show “${human(field)}”`. `human = (f) => f.charAt(0).toUpperCase() + f.slice(1).replace(/_/g, ' ')`. Return `{ ok: false, reason: 'type-not-allowed', message }` or `{ ok: true }`.
  - `types.ts`: the three hooks with doc comments.
  - `useStageEditor.ts`: `patterns` becomes `computed(() => host.patterns?.() ?? patternData.value ?? [])`. A private `checkPattern(position: Position, block: BlockInstance): Legality` runs `checkInsertSubtree(currentDoc(), position, block, legalityContext())` and, when it is ok and `host.candidateCheck` is set, `host.candidateCheck(insertCandidate(currentDoc(), position, block, legalityContext()) ?? currentDoc())`. Every place that checks a pattern section's placement uses it — `paletteClickable` for `pattern:` keys, the click/Enter insert and the drag drop (grep `instantiate(` in the file) — and a refusal warns with its message and records nothing. In `replaceWithPage`, after building `drop`, `const extra = host.pageReplace?.(pattern) ?? null; if (extra) drop.push(...extra.ops)` before `applyDrop(drop)`.
  - `legality.ts`: `export function isInsideCard(doc, id, cards)` — `locateBlock` the id and walk up with the existing `cardAt()` logic; false when `cards` is null.
- [ ] **Step 4: Run** the specs, then `pnpm exec vitest run src/__tests__/structure-legality.spec.ts src/__tests__/regionsPage.spec.ts src/__tests__/blocks-palette-library.spec.ts`. Expected: PASS.
- [ ] **Step 5: Commit** (`pnpm type-check`, lint, fmt on touched files) as `feat(editor): a section is checked against the whole layout before it lands, and a template brings its settings`.

## Task L9: the layout editor's Sections and Templates

**Files:**
- Modify: `admin/src/pages/layouts/[surface]/[target].vue`, `admin/src/pages/layouts/useLayoutHost.ts` (host hooks), `admin/src/editor/inspector/BlockInspector.vue` (`canSaveSection` prop), `CHANGELOG.md`
- Test: `admin/src/__tests__/layout-editor.spec.ts`, `admin/src/__tests__/saved-sections.spec.ts`

**Interfaces:** Consumes L2 (session fields), L4 (`useLayoutPatterns`, `belongsIn`), L8 (hooks, `checkLayoutCandidate`, `isInsideCard`).

- [ ] **Step 1: Write the failing tests** in `layout-editor.spec.ts` (mock `useLayoutPatterns` and `usePatterns` from `@/queries/patterns`, keeping the real `belongsIn`; the session gains `bindable`, `bindings`, `typeName`):
  - **the three views**: the palette shows Blocks / Sections / Templates; Sections lists, in order, the surface's shipped section(s), then the shipped page sections (`faq`), then saved sections of this surface; a saved **page** section (`scope: 'page', saved: true`), a region section and another surface's section are absent; Templates lists the surface's templates; the layout query was called with the page's surface and target.
  - **a refused section**: a saved section binding `subtitle` (absent from `bindable`) is dimmed with the title "This section shows “Subtitle”, which LF posts doesn’t have"; clicking it records no apply and leaves `undo` disabled.
  - **a template on a clean layout** replaces blocks and settings with no question: click `entry-magazine` (settings `{ width: 'full' }`) → the working copy's blocks equal the template's (types, in order) and the Frame tab reads full width; one undo restores the old blocks and settings; redo brings back the same ids.
  - **a template on a changed layout asks first**: after an edit, clicking a template shows `[data-test="layout-template-replace"]` with "Replace this layout with **Magazine**? Your unsaved changes will be lost."; Keep leaves everything; Replace replaces.
  - **Save as section in a layout** sends `scope: 'layout'`, the surface and the target (assert the `useSavedSections().save` mock's argument), and the button is absent while a block inside the Entry list's card is selected.
- [ ] **Step 2: Run** `cd admin && pnpm exec vitest run src/__tests__/layout-editor.spec.ts`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `useLayoutHost.ts`: the host gains `patterns: () => library.value` (set by the page through a `setLibrary(list)` the host exposes), `candidateCheck: (doc) => session.value ? checkLayoutCandidate(doc, { field: 'blocks', required: session.value.required, bindable: session.value.bindable, bindings: session.value.bindings, typeName: session.value.typeName, label: typeLabel }) : { ok: true }` (`typeLabel` from the block types the page already reads), and `pageReplace: (p) => p.scope === 'layout' && p.kind === 'page' ? { ops: [{ type: 'SetPageSettings', field: SETTINGS_KEY, from: present(currentSettings()), to: present({ ...(p.settings ?? {}) }) }] } : null`.
  - `[target].vue`:
    - `const layoutLibrary = useLayoutPatterns(surface, target)` and `const { data: pageLibrary } = usePatterns()`; `library = computed(() => [...layout sections (scope layout, kind section, !saved, surface match), ...pageLibrary shipped sections (belongsIn page, kind section, !saved), ...layout saved (scope layout, saved, surface match), ...layout templates (kind page)])`; `watch(library, (l) => layout.setLibrary(l), { immediate: true })`.
    - Destructure `replaceClickable` and `replaceWithPage` from the editor. Mount `BlocksPalette` with `:patterns="library"`, `:page-clickable="(slug) => replaceClickable(slug, 'blocks')"`, `@insert-page="onInsertTemplate"`.
    - `pendingTemplate = ref<Pattern | null>(null)`; `onInsertTemplate(slug)`: find the pattern; `dirty.value` → set `pendingTemplate`; else `replaceWithPage(slug, 'blocks')`. The inline confirm (in the Blocks tab slot, like `regions/index.vue`'s) — `role="alertdialog"`, `data-test="layout-template-replace"`, text "Replace this layout with <strong>{{ label }}</strong>? Your unsaved changes will be lost.", buttons `layout-template-keep` ("Keep") and `layout-template-confirm` ("Replace"). Cleared when the palette view changes.
    - `BlockInspector`: `:section-place="{ scope: 'layout', surface }"`, `:section-target="target"`, `:can-save-section="!isInsideCard(currentDocument, selectedId, layout.host.cards?.() ?? null)"`.
  - `BlockInspector.vue`: props `canSaveSection?: boolean` (default true; the button's `v-if` adds it) and `sectionTarget?: string`, passed to `SaveSectionForm`, which adds `target` to the save payload when the place is a layout (`SavedSectionInput` accepts `target?: string`).
  - `CHANGELOG.md`: re-add `## [Unreleased]` above `## [1.0.0-beta.71]` with `### Added`: "**Sections and templates in the layout editor.** **Site › Layouts** has the Design view's **Sections** and **Templates**. Each kind of page — a single post, a listing, an archive, and with Commerce on the product page, the shop home and the shop categories — offers sections and three templates built for the layout's own content type. A template replaces the whole layout, its Frame settings included, and asks first when there are unsaved changes; one undo brings the old layout back. **Save as section** in a layout keeps its field blocks, and the section is offered in every layout of the same kind; one that shows a field the layout's type doesn't have says which. Saving, renaming and deleting a layout's section needs **Manage templates**."
- [ ] **Step 4: Run** the spec, then the whole admin vitest (`pnpm exec vitest run`). Expected: PASS.
- [ ] **Step 5: Commit** (type-check, lint, fmt on touched files) as `feat(layouts): the layout editor offers sections and templates, and saves sections of its own`.

## Task L10: browser proofs

**Files:**
- Modify: `scripts/build-builder-proof-fixtures` (write `api/patterns-layout-entry-post.json` from `PatternController::index(Request::create('/x', 'GET', ['surface' => 'entry', 'target' => 'post']))` after the post world exists), `admin/e2e/helpers.ts` (route `GET /patterns?surface=entry&target=post` to that file; `openLayoutStage` gains `fallbackStage?: string` — the named stage file served for an accepted document no fixture matches, still recorded in `unmatched`)
- Create: `admin/e2e/tests/layout-patterns.spec.ts`

- [ ] **Step 1: Write the proof** with `openLayoutStage(page, { world: 'post', fallbackStage: 'stage-baseline.html' })` and `openBlocksTab`:
  - `a section lands in the layout`: switch to Sections, click the `entry-neighbours` card; `layoutAcceptedIs`-style wait until the last recorded apply's `layout.blocks` holds one more root block whose type is `entry_neighbours`.
  - `a template replaces the layout and its frame`: switch to Templates, click `entry-magazine` (a clean layout: no question); the last apply's `layout.settings` equals `{ width: 'full' }` and its blocks' root types equal the template's; press the page's Undo; the next apply equals the baseline scenario's layout.
- [ ] **Step 2: Prove the proof bites.** L9 is already committed, so a red run needs a mutation: temporarily make the layout host's `pageReplace` return null, run the spec and watch `a template replaces the layout and its frame` fail on the settings, then restore it.
- [ ] **Step 3: Rebuild the fixtures** (`CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`) and run `cd admin/e2e && pnpm exec playwright test tests/layout-patterns.spec.ts --workers=8`, then the whole e2e suite on eight workers. Expected: PASS.
- [ ] **Step 4: Commit** as `test(layouts): the layout editor inserts a section and applies a template, proven in a browser`.

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

- **Spec coverage (S2):** the layout place and surface (§3): L1, L4. Sources — core `LayoutPatterns`, commerce through the contributor only with Commerce on: L3, L6. Filtering — unregistered surface hidden, templates through the real validator, sections through palette/cards/bindings: L2, L4. Per-target endpoint and query key: L4. Identity (§3.1) for layout patterns: L1 (references: ruled page-only). Schema-aware patterns and the type-shape matrix (§3.2): L3, L4. Layout editor Sections/Templates (§4), candidate-document validation, template replacement with settings, confirm, one undo, redo ids: L8, L9. Save as section with the layout place and not inside a card: L9. Saved layout sections (§5) — migration, surface validation, offered per surface, bindings refused with the field named, permissions by row scope, lifecycle (source unchanged; hidden and kept with the surface): L5, L8, L9. What ships (§6): L3, L6 (with the Product hero ruling). Thumbnails: L7. Tests (§7): each task. Browser proofs (§7): L10; the page Design view's commerce-off proof shipped in S1 (`shop-patterns.spec.ts`). Renders: L7's build renders every layout pattern on its placeholder sample and fails on a missing picture. Docs (§8): L11. Changelog: L9.
- **Types:** `LayoutTarget`, `LayoutSection`, `LayoutTemplate`, `LayoutPatternContributor`, `registerLayout`, `layoutContributors`, `checkFragment`, `forLayout`, `layoutSlugs`, `targetFor`, `LayoutRules`, `checkLayoutCandidate`, `isInsideCard`, `StageHost.patterns/candidateCheck/pageReplace` are named once above and used consistently in L1–L10.
- **Review Focus:** items 1–5 each pinned to a task.
