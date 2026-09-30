# Sections and Templates — Release S1 (shop pages) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** With Commerce on, the page Design view's Sections and Templates offer eight shop sections and four shop page templates — **Shop landing**, **Product launch**, **Sale / collection**, **New arrivals** — built from the existing commerce page blocks, each with a thumbnail. With Commerce off, none of them exists. A Featured product or Add to cart block that has no product is honest about it: a notice on the stage, nothing broken on the site.

**Architecture:** A pattern contributor registry joins the pattern library. The contract and a public section-building toolkit (`PatternBlocks`, the helpers `StarterPatterns` uses today) move into `thallo-contracts`, so packs can build sections without touching core. The core registry enforces unique pattern slugs and checked template references at registration. `PatternLibrary` merges contributed page patterns after the shipped ones, keeps whole-template hiding, and carries a `requires` hint (`product`) to the admin. The commerce pack registers a `ShopPatternsContributor` only while `thallo.commerce` is enabled. Featured product and Add to cart gain an honest unconfigured state: a server-rendered stage notice through a soft-bound `StorefrontBlockPreview` contract, no public output when no product can be found, and an `unconfigured` flag on their block-data endpoints that `shop.js` honours.

**Tech Stack:** PHP 8.3 (Glueful, PostgreSQL, PHPUnit), Twig 3 (render pack, commerce templates), `shop.js` (plain JS, `tools/runtime-browser` Playwright proofs), Nuxt UI admin (Vue 3, vitest, Playwright e2e in `admin/e2e`), thumbnails via `scripts/build-pattern-thumbnails` and `tools/style-proofs/capture-page.js`.

**Spec:** `docs/internal/superpowers/specs/2026-09-29-layout-sections-templates-design.md` (amended 2026-09-30). This release is §9 item 1 (S1). It implements §3 (sources, contributor registry), §3.1 (identity and references), §6 (shop page patterns, portable settings, the product-selection state, thumbnails), §7 (the S1 tests) and §8 (docs). The layout place, targets (§3.2), the layout editor (§4) and saved layout sections (§5) are S2.

## Rulings made while planning (from the code)

- **The toolkit moves to contracts.** `thallo-commerce` may depend only on `thallo-contracts` (`scripts/check-pack-boundaries.php` refuses `Thallo\Core\` references in packs). So `StarterPatterns`' private builders (`block`, `token`, `choice`, `band`, `splitAtLg`, `stack`, `grid`, `header`, `heading`, `text`, `button`, `hero`, `feature`, `step`, `stat`, `quote`, `card`, `plan`, `question`, `cta`) become public static methods of `Thallo\Contracts\Patterns\PatternBlocks`. `StarterPatterns` calls them. The region-only helpers (`row`, `column`, `logo`, `menu`, `regionButton`, `links`, `copyright`, `social`) stay private in `StarterPatterns`. The moved methods produce byte-identical arrays: the refactor must leave `PatternLibrary::all()` unchanged, and Task P1 proves it. Cost if wrong: a public surface in contracts that S2 may extend.
- **Contributed patterns are page patterns in S1.** A contributor's sections and templates carry no region and no surface. The registry refuses a contributed section or template that names any other place. S2 widens the contract with the layout place. Cost if wrong: one refusal to relax in S2.
- **Identity.** A pattern slug is unique across `StarterPatterns` (every section, page, region section and region template) and every contributor. The registry refuses a collision with a `LogicException` naming both owners (`core` or the contributor's `id()`), and refuses a second contributor with an `id()` already registered. Commerce's slugs all start `shop-`. Cost if wrong: none; collisions are programming errors.
- **References.** A contributed template names sections by slug. Each must be one of the same contributor's sections or a core page section (`StarterPatterns::sections()`), never a region section. A missing or region reference is refused at registration. At request time a template whose section is unavailable (an inactive block type) is hidden whole, as today.
- **Where the registry is consulted.** `PatternLibrary` receives the registry through its constructor (optional, like `SavedSectionRepository`) and reads `all()` on every `all()` call. Commerce registers at boot, so the list is complete before the first request.
- **The `requires` hint.** A section or template may declare `requires: 'product'`. The library passes it through, and a template requires a product when any of its sections does. `PatternData` gains a nullable `requires`. The admin shows it on the card ("Choose a product after inserting it") and changes nothing else. Core patterns have `requires: null`.
- **Product grids ship portable.** Every shop section with a Product grid uses `source: 'newest'`, which renders on any shop that has products and shows the grid's empty state when it has none. "Category collection grid" also ships on `newest`, and its description says to pick a category. A blank `category` source fails the endpoint (`categoryRows` throws), so a portable pattern must not ship it.
- **The honest state for Featured product and Add to cart.**
  - Today, with no product, `featured-product.twig` shows "Loading…" forever: its empty paragraph's text is "Loading…" and `renderFeaturedProduct` only un-hides it. Add to cart ends on "This product is not available.".
  - Shop behaviour never runs on the canvas stage (`shop.js`: "All ten are canvas-skip"), so on the stage both show "Loading…" whatever their settings.
  - The fix has three parts:
    - **Stage** (server, `is_canvas()`): a named placeholder `thallo-field-empty`, the class `category_rail.twig` already uses. It reads "Featured product — {name}" or "Add to cart — {name}" when a product resolves, and "Featured product — choose a product" or "Add to cart — choose a product" when none does. The name comes from a new soft-bound contract, `Thallo\Contracts\Delivery\StorefrontBlockPreview::productLabel(?string $slug, ?string $entryUuid): ?string`. Commerce implements it with an explicit slug first, else the entry's linked product (`ProductLinkService::resolveByEntry`), buyer-available and active only. The render pack exposes it as `shop_block_product_label(slug, entry_uuid)`, allowlisted in `TemplatePolicy`. The stage is never cached, so a live lookup is safe there.
    - **Public, no product possible** (blank `product_slug` and no entry): the block renders **nothing** — no shell, no script tag, no noscript text.
    - **Public, product unresolved at run time** (a slug for a product that is gone or inactive, or an entry with no linked product): the endpoints add `"unconfigured": true` when no slug could be resolved at all. `shop.js` then sets `el.hidden = true` on the block root for Featured product whenever `product` is null (no endless "Loading…"), and for Add to cart when `unconfigured` is true. A configured but unavailable product keeps "This product is not available.", which is honest.
  - Public pages stay cache-safe: no product lookup on the public path.
  - Cost if wrong: two templates, two endpoints, two JS branches.
- **Thumbnails of commerce patterns** show fixture products, never shipped values.
  - `scripts/build-pattern-thumbnails` already rolls back a transaction around fixture data (the header menu). Inside it, it seeds three fixture products with `CatalogService` and substitutes their slug into Featured product and Add to cart blocks in the **picture only**, as `$configured` does for a form's recipient.
  - It calls `ShopBlockDataController::productGrid`, `featuredProduct` and `addToCart` with synthetic `Request`s to get the exact JSON the site would serve.
  - It injects, into each commerce page, an inline script defining a `window.fetch` stub for `/_shop/blocks/*` that answers with that JSON, plus a `<script>` loading `packages/thallo-commerce/assets/shop.js` by absolute `file://` path. It removes the page's `/_thallo/shop/shop.js` tag, which cannot resolve under `file://`.
  - `capture-page.js` gains a job option `expect: string[]` and waits (10 s) for each text before capturing, failing the build otherwise. So a thumbnail can never picture "Loading…".
- **Rename and delete by the saved row's scope** (a plan-level item in the spec) **moves to S2.** In S1 every saved section is `page` or `region` and keeps `content.manage`, so moving the gate adds no behaviour and would ship an untested branch. S2 introduces the `layout` scope and moves the gate together with its `templates.manage` tests. Cost if wrong: none in S1.
- **Commerce off, proven by a second boot.** `bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]])` is the pattern `InertnessTest` uses. The commerce-off assertions go through it, and the admin's browser proof covers commerce on. The admin does no capability filtering of its own: the pattern list comes from the server.

## Global Constraints

- Packs reference only `Thallo\Contracts\…`, never `Thallo\Core\…` (`composer boundaries`).
- Every commerce pattern slug starts `shop-`, and every commerce pattern uses only existing block types.
- No site-specific value ships in a pattern: no product slug, no category slug, no URL but `#`.
- Copy is sentence case with typographic quotes and dashes, like `StarterPatterns`.
- A changelog bullet rides in the commit of the change, under `## [Unreleased]`.
- Gates: `COMPOSER_PROCESS_TIMEOUT=0 composer test` and once with `API_USE_PREFIX=true`; harness classes each in their own process with `THALLO_TENANCY_DEV_LINK=1`; phpcs judged by exit code; admin `pnpm type-check`, lint, `pnpm fmt:check`, `pnpm exec oxfmt` on touched files; admin vitest; e2e on eight workers; `composer test:distribution`, `composer test:skeleton`; a new `tests/Integration` top-level entry joins an `INTEGRATION_SHARD_*` list in `ci.yml`.

## Review Focus

1. **A contributed template naming a core section whose block type is off**, e.g. a shop landing that reuses `faq` after the accordion type is disabled. The whole template disappears, as core templates do. Pinned in Task P3.
2. **A commerce section inserted on a shop with no products at all.** The grids show their empty state, Featured product and Add to cart show the stage notice, and the public page shows no broken card. Pinned in Task P4 (templates and endpoints) and Task P5 (the library resolves the patterns on an empty shop).
3. **A Featured product whose configured slug later points at a deleted product.** On the stage it reads "choose a product". In public, `shop.js` hides it instead of "Loading…" forever. Pinned in Task P4's browser proof.
4. **Commerce toggled off after shop sections were inserted into pages.** The patterns disappear from the palette. Pages keep their blocks, which render through the missing-template fallback, as today with no shop HTML. Pinned in Task P5's second-boot test.
5. **Two packs claiming one slug, or a pack reusing a core slug** (a future pack writing `faq`). Refused at boot with both owners named. Pinned in Task P2.

## Shared contracts (named once, used by every task)

```php
namespace Thallo\Contracts\Patterns;

/** A pack's sections and templates for the page library (spec §3). S1: page patterns only. */
interface PatternContributor
{
    /** Stable identity, unique across contributors (e.g. 'thallo.commerce'). */
    public function id(): string;

    /** @return list<PatternSection> */
    public function sections(): array;

    /** @return list<PatternTemplate> */
    public function templates(): array;
}

final class PatternSection
{
    /** @param array<string,mixed> $block one block tree, no ids (PatternBlocks builds it) */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $category,
        public readonly string $description,
        public readonly array $block,
        public readonly ?string $requires = null, // 'product' or null
    ) {
    }
}

final class PatternTemplate
{
    /** @param list<string> $sections section slugs, in order */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $description,
        public readonly array $sections,
        public readonly string $category = 'Pages',
    ) {
    }
}

interface PatternContributorRegistry
{
    /** @throws \LogicException on a duplicate id, a slug collision or a bad reference */
    public function register(PatternContributor $contributor): void;

    /** @return list<PatternContributor> */
    public function all(): array;
}
```

```php
namespace Thallo\Contracts\Delivery;

/** A shop block's product, named for the stage (spec §6). Soft-bound: commerce implements it. */
interface StorefrontBlockPreview
{
    /** The product's name when $slug (or else $entryUuid's linked product) resolves to an active one. */
    public function productLabel(?string $slug, ?string $entryUuid): ?string;
}
```

- The `PatternLibrary::all()` item gains `'requires' => ?string`. The key already has a place in the array shape: add it after `description`.
- Admin, `admin/src/queries/patterns.ts`: `Pattern` gains `requires?: 'product' | null`.
- The endpoint JSON gains `unconfigured?: true`: `{"product": null, "unconfigured": true}` and `AddToCartViewModel::unavailable()->toArray() + ['unconfigured' => true]`.

---

## Task P1: the section toolkit moves to contracts

**Files:**
- Create: `packages/thallo-contracts/src/Patterns/PatternBlocks.php`
- Modify: `core/src/Content/Patterns/StarterPatterns.php`
- Test: `tests/Unit/Patterns/PatternBlocksTest.php`, plus the existing `tests/Integration/Content/PatternLibraryTest.php`

**Interfaces:** Produces `PatternBlocks::{block, token, choice, band, splitAtLg, stack, grid, header, heading, text, button, hero, feature, step, stat, quote, card, plan, question, cta}`: public static, same signatures and return values as today's private methods.

- [ ] **Step 1: Freeze today's library output.** Before touching code, write the library's resolved patterns to a scratch file (not committed):
  ```bash
  DB_PGSQL_DATABASE=app_test APP_ENV=testing php -r 'require "vendor/autoload.php"; require "scripts/lib/showcase.php"; $c = showcase_boot(getcwd()); file_put_contents("/tmp/patterns-before.json", json_encode($c->get(Thallo\Core\Content\Patterns\PatternLibrary::class)->all(), JSON_PRETTY_PRINT));'
  ```
  Expected: a JSON file with every current section, page and region pattern.
- [ ] **Step 2: Write the failing unit test** `tests/Unit/Patterns/PatternBlocksTest.php`. It asserts that `PatternBlocks::band([PatternBlocks::heading('Hi', 'h2', 'center')])` equals the literal array today's private `band()` and `heading()` produce. Copy the expected arrays from `StarterPatterns` lines 703–849. It also asserts `PatternBlocks::cta('T', 'D', 'soft', 'horizontal', ['A', 'B'])` gives a solid first button and an outline second.
- [ ] **Step 3: Run it.** `vendor/bin/phpunit tests/Unit/Patterns/PatternBlocksTest.php` — Expected: FAIL, class not found.
- [ ] **Step 4: Create `PatternBlocks`.** Move the listed helpers verbatim from `StarterPatterns`, changing `private` to `public` and `self::` calls among them to `self::` within the new class. Add a class docblock saying they are the page library's construction toolkit, shared with packs, and that the shapes are the structure picker's presets.
- [ ] **Step 5: Point `StarterPatterns` at it.** Delete the moved private methods, add `use Thallo\Contracts\Patterns\PatternBlocks;`, and replace each `self::<moved>(` with `PatternBlocks::<moved>(`. Region helpers keep calling the moved ones through `PatternBlocks::`.
- [ ] **Step 6: Prove byte-identical output.** Re-run Step 1's command into `/tmp/patterns-after.json`, then `diff /tmp/patterns-before.json /tmp/patterns-after.json`. Expected: no output. Then run `vendor/bin/phpunit tests/Unit/Patterns/PatternBlocksTest.php tests/Integration/Content/PatternLibraryTest.php`. Expected: PASS.
- [ ] **Step 7: Commit.**
  ```bash
  vendor/bin/phpcs packages/thallo-contracts/src/Patterns core/src/Content/Patterns tests/Unit/Patterns
  composer boundaries
  git add packages/thallo-contracts/src/Patterns/PatternBlocks.php core/src/Content/Patterns/StarterPatterns.php tests/Unit/Patterns/PatternBlocksTest.php
  git commit -m "refactor(patterns): the section toolkit moves to contracts, so packs can build sections"
  ```

## Task P2: the pattern contributor registry, with identities and references checked

**Files:**
- Create: `packages/thallo-contracts/src/Patterns/{PatternContributor,PatternSection,PatternTemplate,PatternContributorRegistry}.php` (as in Shared contracts)
- Create: `core/src/Content/Patterns/DefaultPatternContributorRegistry.php`
- Modify: `core/src/Providers/CoreServiceProvider.php` (`services()`: bind `PatternContributorRegistry::class` to `DefaultPatternContributorRegistry`, shared)
- Test: `tests/Unit/Patterns/PatternContributorRegistryTest.php`

**Interfaces:** Consumes `StarterPatterns::{sections,pages,regionSections,regionTemplates}` (slugs). Produces `DefaultPatternContributorRegistry::register()` and `all()`, plus `ownerOf(string $slug): ?string` (`'core'`, a contributor id, or null) for error messages.

- [ ] **Step 1: Write the failing tests.** Use anonymous `PatternContributor` classes built from `PatternSection`/`PatternTemplate`:
  - `testAContributorIsListedOnce`: registering `id: 'a'` then `all()` gives `[a]`; registering another `id: 'a'` throws `LogicException` mentioning `'a'`.
  - `testASlugTakenByCoreIsRefused`: a contributed section slug `faq` throws with "faq" and "core" in the message.
  - `testASlugTakenByAnotherContributorIsRefused`: contributor `a` has `x-hero`; contributor `b` with `x-hero` throws naming `a` and `b`; `all()` still lists only `a`.
  - `testATemplateMustNameKnownPageSections`: a template naming `x-missing` throws naming the template and `x-missing`; naming `header-logo-menu` (a region section) throws; naming its own `x-hero` plus core `faq` registers.
  - `testRegistrationIsAllOrNothing`: a contributor whose second template is bad leaves `all()` unchanged.
- [ ] **Step 2: Run them.** `vendor/bin/phpunit tests/Unit/Patterns/PatternContributorRegistryTest.php` — Expected: FAIL, classes not found.
- [ ] **Step 3: Create the contract files** exactly as in Shared contracts, with docblocks citing spec §3 and §3.1.
- [ ] **Step 4: Implement `DefaultPatternContributorRegistry`:**
  ```php
  final class DefaultPatternContributorRegistry implements PatternContributorRegistry
  {
      /** @var array<string,PatternContributor> */
      private array $contributors = [];
      /** @var array<string,string> slug => owner ('core' or a contributor id) */
      private array $owners = [];

      public function __construct()
      {
          foreach ([...StarterPatterns::sections(), ...StarterPatterns::pages(),
              ...StarterPatterns::regionSections(), ...StarterPatterns::regionTemplates()] as $pattern) {
              $this->owners[$pattern['slug']] = 'core';
          }
      }

      public function register(PatternContributor $contributor): void
      {
          $id = $contributor->id();
          if (isset($this->contributors[$id])) {
              throw new \LogicException("Pattern contributor '{$id}' is already registered.");
          }
          $claimed = [];
          $own = [];
          foreach ($contributor->sections() as $section) {
              $this->claim($section->slug, $id, $claimed);
              $own[$section->slug] = true;
          }
          $pageSections = array_column(StarterPatterns::sections(), 'slug');
          foreach ($contributor->templates() as $template) {
              $this->claim($template->slug, $id, $claimed);
              foreach ($template->sections as $slug) {
                  if (!isset($own[$slug]) && !in_array($slug, $pageSections, true)) {
                      throw new \LogicException(
                          "Template '{$template->slug}' of '{$id}' names '{$slug}', which is not a page section."
                      );
                  }
              }
          }
          $this->owners += $claimed;
          $this->contributors[$id] = $contributor;
      }

      /** @param array<string,string> $claimed */
      private function claim(string $slug, string $id, array &$claimed): void
      {
          $owner = $this->owners[$slug] ?? $claimed[$slug] ?? null;
          if ($owner !== null) {
              throw new \LogicException("Pattern '{$slug}' of '{$id}' is already taken by '{$owner}'.");
          }
          $claimed[$slug] = $id;
      }

      public function all(): array { return array_values($this->contributors); }
      public function ownerOf(string $slug): ?string { return $this->owners[$slug] ?? null; }
  }
  ```
- [ ] **Step 5: Bind it** in `CoreServiceProvider::services()`, next to the pattern library bindings:
  `\Thallo\Contracts\Patterns\PatternContributorRegistry::class => ['class' => DefaultPatternContributorRegistry::class, 'shared' => true]`.
- [ ] **Step 6: Run the tests.** Expected: PASS (5 tests).
- [ ] **Step 7: Commit** (phpcs and boundaries first) as `feat(patterns): packs contribute sections and templates, with unique slugs and checked references`.

## Task P3: the library offers contributed patterns, whole or not at all, with `requires`

**Files:**
- Modify: `core/src/Content/Patterns/PatternLibrary.php`, `core/src/Content/Http/DTOs/Responses/Patterns/PatternData.php`, and the `PatternLibrary` binding in `CoreServiceProvider` (pass the registry)
- Modify: `admin/src/queries/patterns.ts` (`requires?: 'product' | null`)
- Test: `tests/Integration/Content/PatternLibraryTest.php` (new cases)

**Interfaces:** Consumes `PatternContributorRegistry::all()`. Produces `PatternLibrary::all()` items with `requires`. Contributed sections are listed after the core page sections and before the region sections; contributed templates after the core page templates.

- [ ] **Step 1: Write the failing tests** in `PatternLibraryTest`. Build a `PatternLibrary` with a fresh `DefaultPatternContributorRegistry` holding a test contributor: section `x-banner` (a heading band), section `x-spot` with `requires: 'product'` (a `featured-product` block), and templates `x-page` (`['x-banner', 'faq']`) and `x-shop` (`['x-banner', 'x-spot']`).
  - `testContributedPatternsAreOfferedWithTheirPlace`: `x-banner` has `scope: page`, `region: null`, `requires: null`; `x-page` has `kind: page` and two blocks.
  - `testATemplateRequiresAProductWhenASectionDoes`: `x-shop` has `requires: 'product'`; `x-page` has `requires: null`.
  - `testATemplateGoesWhenACoreSectionItNamesGoes`: deactivate the `accordion` block type, as the existing type-off tests do. `faq` and `x-page` are both absent, and `x-banner` stays.
  - `testCorePatternsAreUnchanged`: with an empty registry, `all()` equals today's list with `requires: null` added to every item. Compare against a fresh `PatternLibrary` built without a registry.
- [ ] **Step 2: Run them.** `vendor/bin/phpunit tests/Integration/Content/PatternLibraryTest.php` — Expected: FAIL, unknown constructor argument and missing `requires`.
- [ ] **Step 3: Implement.** Add `private readonly ?PatternContributorRegistry $contributors = null` to the constructor.
  - In `all()`, after the core sections loop, resolve every contributed section into `$sections` with `'requires' => $section->requires`, keeping insertion order so contributed sections follow the core page sections. Region sections stay last among sections.
  - Page templates: build a combined list of core pages plus contributed templates, each as `['slug', 'label', 'category', 'description', 'sections']`. Resolve it with the existing whole-or-nothing loop.
  - Set `requires` on each template to `'product'` when any named section's `requires` is `'product'`, else null.
  - Add `'requires' => null` in `place()` for core patterns and saved sections.
  - Add `public readonly ?string $requires = null` to `PatternData`, mapped from the array.
- [ ] **Step 4: Pass the registry** into the `PatternLibrary` binding. Autowiring resolves `PatternContributorRegistry` once it is bound. Keep the `SavedSectionRepository` argument.
- [ ] **Step 5: Admin type.** Add `requires?: 'product' | null` to `Pattern` in `admin/src/queries/patterns.ts`, with a comment: "A shop pattern whose product block needs a product chosen after inserting it."
- [ ] **Step 6: Run** `vendor/bin/phpunit tests/Integration/Content` and `cd admin && pnpm type-check`. Expected: PASS.
- [ ] **Step 7: Commit** as `feat(patterns): the library offers contributed sections and templates, whole or not at all`.

## Task P4: Featured product and Add to cart are honest without a product

**Files:**
- Create: `packages/thallo-contracts/src/Delivery/StorefrontBlockPreview.php`
- Create: `packages/thallo-commerce/src/Shop/ShopBlockPreview.php` (implements it)
- Modify:
  - `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (bind `StorefrontBlockPreview::class`, beside `StorefrontLinkResolver`)
  - `packages/thallo-render/src/RenderContextExtension.php` (constructor `?StorefrontBlockPreview $blockPreview = null`; `TwigFunction('shop_block_product_label', …)`)
  - `packages/thallo-render/src/RenderServiceProvider.php` (soft-bind it, as `storefrontLinks` is)
  - `packages/thallo-render/src/Templates/TemplatePolicy.php` (allowlist the function)
  - `packages/thallo-commerce/templates/blocks/featured-product.twig`, `packages/thallo-commerce/templates/blocks/add-to-cart.twig`
  - `packages/thallo-commerce/src/Http/Shop/ShopBlockDataController.php` (`unconfigured`)
  - `packages/thallo-commerce/assets/shop.js` (`renderFeaturedProduct`, the add-to-cart unavailable branch)
- Test:
  - `tests/Integration/Commerce/ShopBlockSelectionTest.php`
  - `tools/runtime-browser/tests/shop-block-selection.spec.js` with its fixture page `tools/runtime-browser/fixtures/shop-block-selection/index.html`

**Interfaces:** Consumes `ProductLinkService::resolveByEntry`, `findBuyerAvailableBySlug`. Produces the Twig function `shop_block_product_label(?string slug, ?string entry_uuid): ?string` and endpoint JSON `unconfigured: true`.

- [ ] **Step 1: Write the failing PHP tests** in `ShopBlockSelectionTest`, modelled on `ShopLayoutRenderTest` with `ShopPageSeed`. Render a page entry whose body holds the block through `RenderController`, in canvas mode (`?canvas=1` through a preview session) and in public mode.
  - `testWithNoProductTheStageSaysChooseAProduct`: canvas render of `featured-product` with blank `product_slug` contains `thallo-field-empty` and `Featured product — choose a product`, and no `data-shop-block`. The same holds for `add-to-cart` with `Add to cart — choose a product`.
  - `testWithAProductTheStageNamesIt`: seed an active product "Stoneware bowl"; `product_slug: 'stoneware-bowl'` renders `Featured product — Stoneware bowl` on the stage.
  - `testWithNoProductPossibleThePublicPageRendersNothing`: a public render with a blank slug and no linked entry contains no `thallo-block-featured-product`, no `shop.js` script tag and no "Loading…". The same holds for add-to-cart.
  - `testAConfiguredBlockRendersItsShell`: a public render with a slug contains `data-shop-block="featured-product"` and `data-product-slug="stoneware-bowl"`.
  - `testTheEndpointsSayWhenNothingIsConfigured`: `GET /_shop/blocks/featured-product` with no slug and no entry answers `{"product": null, "unconfigured": true}`. `GET /_shop/blocks/add-to-cart` the same answers `mode: unavailable` and `unconfigured: true`. With a slug for a missing product both answer without `unconfigured`.
  - `testAnEmptyShopKeepsTheNotice`: with no products at all, the stage shows the "choose a product" notice and the public page shows nothing for blank blocks.
- [ ] **Step 2: Run it.** `vendor/bin/phpunit tests/Integration/Commerce/ShopBlockSelectionTest.php` — Expected: FAIL, notice text absent and `unconfigured` missing.
- [ ] **Step 3: The contract and implementation.** Create `StorefrontBlockPreview` (Shared contracts). `ShopBlockPreview::productLabel()`:
  1. Resolve the tenant (`CommerceTenantResolution`).
  2. Take the slug when it is non-empty; otherwise take the entry's linked product via `ProductLinkService::resolveByEntry($context, $entryUuid)` when `$entryUuid` is non-empty.
  3. Look it up with `findBuyerAvailableBySlug`.
  4. Return its `name` when `status === 'active'`, else null.
  Bind it in the commerce provider's `services()` beside `StorefrontLinkResolver`, gated identically.
- [ ] **Step 4: The Twig function.** In `RenderContextExtension`, add the constructor parameter and:
  ```php
  new TwigFunction('shop_block_product_label', fn (?string $slug, ?string $entryUuid): ?string =>
      $this->blockPreview?->productLabel($slug !== '' ? $slug : null, $entryUuid !== '' ? $entryUuid : null)),
  ```
  Soft-bind it in `RenderServiceProvider` exactly like `storefrontLinks` (`$container->has(StorefrontBlockPreview::class) ? … : null`), and add `'shop_block_product_label'` to `TemplatePolicy`'s function allowlist.
- [ ] **Step 5: The templates.** Wrap both templates. `featured-product.twig`:
  ```twig
  {% set slug = data.product_slug|default('') %}
  {% set entry_uuid = entry.uuid|default('') %}
  {% if is_canvas() %}
    {% set label = shop_block_product_label(slug, entry_uuid) %}
    <div class="thallo-block thallo-block-featured-product thallo-field-empty">Featured product — {{ label ?: 'choose a product' }}</div>
  {% elseif slug != '' or entry_uuid != '' %}
    {# …today's shell, unchanged, with its link and script tags… #}
  {% endif %}
  ```
  `add-to-cart.twig` is the same, reading "Add to cart — …". Keep the existing comments, and add one saying why the stage shows a named placeholder: shop behaviour never runs on the canvas.
- [ ] **Step 6: The endpoints.** In `featuredProduct()` and `addToCart()`, when `$slug === null` (neither an explicit slug nor a linked product), return `['product' => null, 'unconfigured' => true]` and `AddToCartViewModel::unavailable()->toArray() + ['unconfigured' => true]` respectively.
- [ ] **Step 7: Run the PHP tests.** Expected: PASS.
- [ ] **Step 8: Write the failing browser proof** `shop-block-selection.spec.js`. The fixture page holds a `featured-product` shell and an `add-to-cart` shell (copied markup with `data-shop-block`) and loads `/packages/thallo-commerce/assets/shop.js`.
  - `featured product with no product hides itself`: `page.route('**/_shop/blocks/featured-product*', r => r.fulfill({json: {product: null}}))`, then expect the block to be hidden and the text "Loading…" not visible.
  - `add to cart with nothing configured hides itself`: fulfil `{mode: 'unavailable', unconfigured: true}`, then expect the block hidden.
  - `add to cart for a gone product says so`: fulfil `{mode: 'unavailable'}`, then expect "This product is not available." to be visible.
  Run `cd tools/runtime-browser && npx playwright test tests/shop-block-selection.spec.js`. Expected: FAIL on the first two.
- [ ] **Step 9: `shop.js`.**
  - In `renderFeaturedProduct`, when `!product`, set `el.hidden = true` and return. Do not un-hide the "Loading…" paragraph.
  - In the add-to-cart render, when `data.unconfigured === true`, set `el.hidden = true` and return, before today's unavailable branch.
  - Comment both lines: an empty block is not shown to shoppers; the editor sees a named placeholder on the stage.
- [ ] **Step 10: Run the browser proof and the PHP tests.** Expected: PASS.
- [ ] **Step 11: Commit** with this changelog bullet under `### Fixed`: "**A Featured product or Add to cart block with no product no longer shows "Loading…" forever.** On the stage it says to choose a product, or names the product it shows; on the site it shows nothing until a product is chosen." Message: `fix(commerce): Featured product and Add to cart are honest without a product`.

## Task P5: the commerce pattern contributor

**Files:**
- Create: `packages/thallo-commerce/src/Patterns/ShopPatternsContributor.php`
- Modify: `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (`registerPatternContributor()`, called beside `registerStarterContributor()` inside the capability gate)
- Test: `tests/Integration/Commerce/ShopPatternsTest.php`

**Interfaces:** Consumes `PatternBlocks`, `PatternContributorRegistry`, and `ShopBlockTypesContributor::SLUG_PRODUCT_GRID` (`product-grid`), `SLUG_FEATURED_PRODUCT` (`featured-product`) and `SLUG_ADD_TO_CART` (`add-to-cart`). Produces contributor id `thallo.commerce` and these slugs:

| Section slug | Label | Category | Built from | requires |
|---|---|---|---|---|
| `shop-new-arrivals` | New arrivals | Shop | band: `header('New in', 'Just arrived', 'The latest additions to the shop.')` + `product-grid` `{source: newest, page_size: medium}` + button "Shop all" | — |
| `shop-collection-grid` | Collection grid | Shop | band: `header('Collection', 'Shop the collection', null)` + `product-grid` `{source: newest, page_size: large}` | — |
| `shop-featured-spotlight` | Featured product | Shop | band with `splitAtLg()`: stack(heading "Our pick this month", text, button "Shop now") + `featured-product` `{product_slug: ''}` | product |
| `shop-add-to-cart-cta` | Add-to-cart call to action | Shop | band, surface `color.surface-muted`: stack(heading "Ready when you are", text) + `add-to-cart` `{product_slug: ''}` | product |
| `shop-sale-banner` | Sale banner | Shop | `hero` `{headline: 'Limited time', title: 'The seasonal sale is on', description: '…', heading_level: 'h1'}` buttons ['Shop the sale'] | — |
| `shop-reasons` | Reasons to buy | Shop | band: `header('Why shop with us', 'Made to last, shipped with care', null)` + `grid('3', [feature('truck','Free delivery','…'), feature('rotate-ccw','Easy returns','…'), feature('shield-check','Secure checkout','…')])` | — |
| `shop-product-faq` | Product FAQ | Shop | band: `header('Questions', 'Before you buy', null)` + accordion of four `question()`s (delivery, returns, care, payment) | — |
| `shop-cta-band` | Shop call to action | Shop | `cta('Find something you'll love', '…', 'soft', 'horizontal', ['Browse the shop'])` | — |

| Template slug | Label | Sections, in order |
|---|---|---|
| `shop-landing` | Shop landing | `shop-sale-banner`, `shop-new-arrivals`, `shop-featured-spotlight`, `shop-reasons`, `shop-cta-band` |
| `shop-product-launch` | Product launch | `shop-featured-spotlight`, `features-grid`, `shop-product-faq`, `shop-add-to-cart-cta` |
| `shop-sale` | Sale / collection | `shop-sale-banner`, `shop-collection-grid`, `shop-reasons`, `shop-cta-band` |
| `shop-new-arrivals-page` | New arrivals | `page-header`, `shop-new-arrivals`, `shop-featured-spotlight`, `shop-cta-band` |

Every button's `url` is `#`, and every copy line is final text; write the "…" descriptions in full in the implementation, one or two short sentences each. The accordion wraps the questions exactly as core's `faq` section builds it (`StarterPatterns` `faq`, line 263). Read it and repeat its container shape. Icons must exist in the admin's lucide set: check `truck`, `rotate-ccw` and `shield-check` in the block library's icon picker data, and pick the closest existing name when one is missing.

- [ ] **Step 1: Write the failing tests** in `ShopPatternsTest`:
  - `testTheShopPatternsAreOfferedWithCommerceOn`: from the container's `PatternLibrary::all()`, the eight section slugs and four template slugs above are present. Every template has as many blocks as its section list. `shop-landing`, `shop-product-launch` and `shop-new-arrivals-page` have `requires: 'product'`; `shop-sale` has `requires: null`.
  - `testEveryShopPatternSavesAsAPage`: each template's blocks, given fresh ids, pass `FieldValidator` for a one-field `blocks` schema, as a page save would.
  - `testNoShopPatternShipsASiteSpecificValue`: walk every shop pattern. Every `product_slug` is `''`, every `category_slug` and `tag_slug` is absent or `''`, every `url` is `#`, and every product-grid `source` is `newest`.
  - `testTheShopPatternsResolveOnAnEmptyShop`: with `commerce_products` emptied, the same slugs are still offered. Patterns don't depend on data.
  - `testWithCommerceOffNoShopPatternExists`: a second boot via `bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]])`, in a `try/finally` that restores the shared connection and permission provider like `InertnessTest`. Its `PatternLibrary::all()` has no slug starting `shop-`, and its `PatternContributorRegistry::all()` has no `thallo.commerce`.
  - `testRegistrationIsIdempotent`: calling `registerPatternContributor()` twice leaves one contributor.
- [ ] **Step 2: Run them.** `vendor/bin/phpunit tests/Integration/Commerce/ShopPatternsTest.php` — Expected: FAIL, no `shop-` patterns.
- [ ] **Step 3: Implement `ShopPatternsContributor`** with the tables above, using `PatternBlocks::*` for every block and the three commerce slugs through `ShopBlockTypesContributor`'s constants. Its docblock cites spec §6: portable settings, `requires`, and newest grids.
- [ ] **Step 4: Register it.** Add `registerPatternContributor(ApplicationContext $context, ?PatternContributorRegistry $registry = null): bool` in the exact shape of `registerStarterContributor()` (guard `interface_exists`, container lookup, idempotent by id), and call it right after `registerStarterContributor($context)` inside the `thallo.commerce` gate, with a comment: user-facing batteries-included content, only while the capability is on.
- [ ] **Step 5: Run** `ShopPatternsTest` and `tests/Integration/Content/PatternLibraryTest.php`. Expected: PASS.
- [ ] **Step 6: Commit** with this changelog bullet under `### Added`: "**Shop sections and page templates.** With Commerce on, **Sections** and **Templates** in the Design view offer shop parts — new arrivals, a collection grid, a featured product, an add-to-cart call to action, a sale banner, reasons to buy, a product FAQ and a shop call to action — and four page templates built from them: **Shop landing**, **Product launch**, **Sale / collection** and **New arrivals**. A part with a featured product or add-to-cart block says so on its card: choose the product after inserting it. With Commerce off they are hidden." Message: `feat(commerce): shop sections and page templates for the page library`.

## Task P6: the admin shows what a pattern needs, proven in a browser

**Files:**
- Modify: `admin/src/editor/palette/BlocksPalette.vue` (a line on the card when `pattern.requires === 'product'`)
- Test:
  - `admin/src/__tests__/pattern-requires.spec.ts`
  - `admin/e2e/tests/shop-patterns.spec.ts`
  - fixtures from `scripts/build-builder-proof-fixtures`, whose `patterns.json` already comes from the live `PatternController::index()`, so rebuilding picks up the shop patterns

**Interfaces:** Consumes `Pattern.requires` from Task P3.

- [ ] **Step 1: Write the failing vitest.** Mount `BlocksPalette` with two patterns in the Sections view, one with `requires: 'product'` and one without, following the existing palette tests' mounting (see `admin/src/__tests__/saved-sections.spec.ts`). The first card shows "Choose a product after inserting it"; the second doesn't. The same holds in the Templates view.
- [ ] **Step 2: Run it.** `cd admin && pnpm exec vitest run src/__tests__/pattern-requires.spec.ts` — Expected: FAIL.
- [ ] **Step 3: Implement.** Under the card's description in `BlocksPalette.vue`, add `<p v-if="pattern.requires === 'product'" class="…muted text-xs…" data-test="pattern-requires">Choose a product after inserting it</p>`, reusing the card's existing muted-text classes.
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Rebuild the proofs' fixtures:** `DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`. Check that `admin/e2e/fixtures/api/patterns.json` contains `shop-landing`.
- [ ] **Step 6: Write the browser proof** `shop-patterns.spec.ts`, using the existing Design-view helpers (`openDesign` or equivalent in `helpers.ts`):
  - `the shop templates are offered with their note`: switch the palette to Templates. **Shop landing**, **Product launch**, **Sale / collection** and **New arrivals** are listed, and Shop landing's card shows "Choose a product after inserting it".
  - `a shop template inserts its sections`: click **Sale / collection**, then assert that the recorded apply/draft request holds four new top-level blocks in order: `hero`, a container holding `product-grid`, a container of features, `cta`.
  Run `cd admin/e2e && pnpm exec playwright test tests/shop-patterns.spec.ts --workers=8`. Expected: PASS.
- [ ] **Step 7: Commit** (type-check, lint, fmt on touched files) as `feat(admin): a pattern that needs a product says so on its card`.

## Task P7: thumbnails of the shop patterns, never of "Loading…"

**Files:**
- Modify: `scripts/build-pattern-thumbnails`, `tools/style-proofs/capture-page.js`
- Regenerate: `admin/public/pattern-thumbs/shop-*.jpg`, `admin/src/editor/palette/patternThumbSizes.json`
- Test: the existing `PatternThumbnailsTest` (a thumbnail for every pattern, no stale ones)

**Interfaces:** Consumes `ShopBlockDataController::{productGrid, featuredProduct, addToCart}`, `CatalogService::createProduct`, and `ShopPatternsContributor` slugs.

- [ ] **Step 1: See it fail.** Run `vendor/bin/phpunit --filter PatternThumbnailsTest`. Expected: FAIL, listing the twelve `shop-*` patterns without thumbnails.
- [ ] **Step 2: `capture-page.js` waits for proof.** After `document.fonts.ready`, for each `job.expect` string run `await page.getByText(text, { exact: false }).first().waitFor({ timeout: 10000 })`; a miss rejects with `thumbnail ${job.out}: never showed "${text}"`. Document the option in the header comment.
- [ ] **Step 3: Seed fixture products inside the rolled-back transaction.** In `build-pattern-thumbnails`, after the menu seeding and only when `ShopPatternsContributor` patterns are present, create three active products via `CatalogService::createProduct`:
  - "Stoneware bowl", 3 200;
  - "Linen apron", 4 500;
  - "Oak serving board", 5 800.
  Record the first slug.
- [ ] **Step 4: Capture the block data the site would serve.** Call `ShopBlockDataController::productGrid(Request::create('/_shop/blocks/product-grid', 'GET', ['source' => 'newest', 'page_size' => 'medium']))` and the same for `featuredProduct` and `addToCart` with `['product_slug' => $slug]`. Keep each response's JSON string.
- [ ] **Step 5: Picture-only configuration.** Extend `$configured`: a `featured-product` or `add-to-cart` block with a blank `product_slug` gets the fixture slug, in the picture only, as the form's recipient is.
- [ ] **Step 6: Make commerce pages hydrate under `file://`.** For a pattern whose blocks contain a `data-shop-block`, post-process the rendered HTML:
  - remove `<script src="/_thallo/shop/shop.js" defer></script>`;
  - insert before `</body>` an inline `<script>` stubbing `window.fetch` for URLs containing `/_shop/blocks/product-grid`, `/featured-product` and `/add-to-cart`, answering `new Response(<captured JSON>, {headers: {'Content-Type': 'application/json'}})` and delegating anything else to the original fetch;
  - then insert `<script src="file://<repo>/packages/thallo-commerce/assets/shop.js"></script>`.
  Set the job's `expect` to the fixture product names the pattern should show: grids expect "Stoneware bowl"; spotlight and templates with it expect "Stoneware bowl"; add-to-cart expects "Add to cart".
- [ ] **Step 7: Build and look.** Run `DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-pattern-thumbnails`. Expected: "Wrote N thumbnails", with twelve new `shop-*.jpg`. Open three of them (a grid, the spotlight, `shop-landing`) with the Read tool and confirm they show the fixture products and no "Loading…". Confirm with `git status` that no core thumbnail changed. A changed core thumbnail means the refactor or the capture changed output; investigate before committing.
- [ ] **Step 8: Run** `vendor/bin/phpunit --filter PatternThumbnailsTest` and `cd admin && pnpm fmt:check`. Expected: PASS.
- [ ] **Step 9: Commit** as `feat(patterns): thumbnails of the shop sections and templates, from fixture products`.

## Task P8: docs

**Files:** `docs/guides/04-sections-and-pages.md`, `docs/guides/18-commerce.md`, `docs/reference/04-block-library.md`

- [ ] **Step 1: The sections guide.** Under "What ships", add a "Shop sections and templates" subsection. It lists the eight sections and four templates, says they appear only with Commerce on, and says a card marked "Choose a product after inserting it" needs its Featured product or Add to cart block pointed at a product. It says what that block shows until then (a notice on the stage, nothing on the site) and that product grids start on the newest products.
- [ ] **Step 2: The commerce guide.** Add a short "Shop pages from templates" section pointing to the sections guide, and describe Featured product and Add to cart's no-product state in their block entries.
- [ ] **Step 3: The block reference.** Update Featured product and Add to cart: with no product, a stage notice and no public output.
- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Unit/Docs`. Expected: PASS.
- [ ] **Step 5: Commit** as `docs(patterns): shop sections and templates`.

## Final

- [ ] Run every gate in Global Constraints. `tests/Integration/Commerce`, `Content` and `tests/Unit/Patterns` already sit in CI (Commerce in shard B; `tests/Unit` runs whole). Confirm no new top-level `tests/Integration` entry was added; if one was, add it to a shard and replay the guard.
- [ ] Final whole-branch review by a fresh reviewer, with this plan's Review Focus verbatim.
- [ ] The beta cut only when the user asks.

## Self-review

- **Spec coverage (S1):**
  - Contributor registry: P2.
  - Identity and references (§3.1): P2.
  - Whole-template hiding: P3.
  - Commerce contributor gated on the capability: P5.
  - Eight sections and four templates: P5.
  - Portable settings: P5.
  - Product-selection state (notice, safe public output): P4.
  - Fixture-only thumbnails: P7.
  - Docs (§8): P8.
  - §7 S1 tests: P2–P7.
  - Deferred to S2 with a ruling: rename and delete by row scope.
  - S2's parts are out of this plan by design.
- **Types:** `PatternSection`, `PatternTemplate`, `PatternContributor`, `PatternContributorRegistry`, `StorefrontBlockPreview` and `requires` are named once in Shared contracts and used consistently in P2–P7.
- **Review Focus:** items 1–5 each pinned to a task.
