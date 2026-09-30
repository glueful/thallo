# Sections and Templates — Release S1 (shop pages) Implementation Plan

> Amended 2026-09-30 after plan review: P3 regenerates the API artifacts; P4 keeps authored styling on the stage placeholder, distinguishes the three public cases and resolves linked products by uuid; the thumbnail pipeline (P5) is built and proven before the contributor, and the contributor lands with its thumbnails in one commit (P6), since `PatternLibraryTest::testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan` fails for any pattern without one.

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
  - **Stage** (server, `is_canvas()`): a named placeholder, `thallo-field-empty` like `category_rail.twig`, that **keeps the block's authored styling**. Its root carries `style_classes('root')` and `style_attrs('root')` exactly as the shell does, so width, spacing, placement and style classes still show, and the block stays selectable.
    - It reads "Featured product — {name}" or "Add to cart — {name}" when a product resolves, and "… — choose a product" when none does.
    - The name comes from a new soft-bound contract, `Thallo\Contracts\Delivery\StorefrontBlockPreview::productLabel(?string $slug, ?string $entryUuid): ?string`. Commerce implements it exactly as `ShopBlockDataController::resolveSlug` resolves a block's product: an explicit slug first, else `ProductLinkService::resolveByEntry()`. That returns a link row with **`product_uuid`**, resolved with `findBuyerAvailableByUuid`. The product counts only when `status === 'active'`, then `findBuyerAvailableBySlug` for an explicit slug.
    - The render pack exposes it as `shop_block_product_label(slug, entry_uuid)`, allowlisted in `TemplatePolicy`. The stage is never cached, so a live lookup is safe there.
  - **Public**, cache-safe (no product lookup on the public path), in three cases:
    1. **No slug and no entry context** (a block rendered outside an entry, such as a header or footer): the server renders **no shell**, no script tag and no noscript text. An ordinary page always has an entry, so this case is narrow by design.
    2. **Entry context without a linked product** (an ordinary unlinked page, blank slug): the shell renders. The endpoint answers `"unconfigured": true`, and `shop.js` sets `el.hidden = true` on the block root, for both blocks.
    3. **An explicit slug whose product is gone or inactive**: Featured product hides (`product` null), and Add to cart keeps "This product is not available.", which is honest.
  - **Without JavaScript** (documented in the block reference): case 1 shows nothing. Case 2 shows the shell's existing noscript line, "Enable JavaScript to view the featured product." or "…to add this product to your cart.". Case 3 with a slug shows the existing noscript link to the product page. No dead link and no "Loading…" appears without JavaScript, because the "Loading…" paragraph is only revealed by script.
  - Cost if wrong: two templates, two endpoints, two JS branches.
- **Thumbnails of commerce patterns** show fixture products, never shipped values, and a picture is taken only once every commerce block in it is ready.
  - **Data.** Inside the build's rolled-back transaction (which already holds the header menu), `ShopPageSeed` seeds its products: active variants and committed cover images (`tests/fixtures/commerce/product-cover.png`, `product-alt.png`) stored as blobs. The seed's map from image record to committed file resolves every cover URL to its committed file.
  - **Picture-only configuration.** A Featured product or Add to cart block with a blank `product_slug` gets the first seeded product's slug in the picture only, as `$configured` does for a form's recipient.
  - **Responses match configuration.** After rendering a commerce pattern, the build reads every `[data-shop-block]` element's `data-*` attributes (DOMDocument). It builds the exact query `shop.js` builds, in the same parameter order:
    - `product-grid`: `source`, `category_slug`, `tag_slug`, `products`, `page_size`;
    - `featured-product` and `add-to-cart`: `product_slug`, `entry_uuid`.
    It calls `ShopBlockDataController` with that request, rewrites cover URLs in the JSON to the committed files' `file://` paths, and records `responses['<path>?<query>'] = json`.
  - **The stub** answers `window.fetch` by exact path and query. An unmatched URL answers 500 with `console.error`, so a mismatch fails the capture instead of picturing the wrong products.
  - **Stylesheets and scripts.** `showcase_renderer()` gains an optional `$withContributions` flag. When set, it builds the theme artifact with the packs' contributed stylesheets (`RenderContributionRegistry::frozenStylesheets()`, as `build-builder-proof-fixtures` does). The shop's styles then arrive inlined through the proper theme layer. The flag is set only for commerce patterns, so core thumbnails are untouched.
  - **Injection point.** The renderer's page ends with `</main>` and has no `</body>`. The build removes the templates' root-relative `/_thallo/shop/shop.css` link and `/_thallo/shop/shop.js` script tags, which cannot resolve under `file://`. Then it **appends to the end of the document, after `</main>`**, the stub `<script>` followed by `<script src="file://<repo>/packages/thallo-commerce/assets/shop.js">`.
  - **Readiness, per block.** A new module, `tools/style-proofs/shop-readiness.js`, exports `waitForShopReady(page, timeoutMs)`. It resolves only when every `[data-shop-block]` is ready:
    - a grid has painted at least one item and its items list is visible;
    - a featured product's body is visible;
    - an add to cart shows its form or its link.
    It also requires that no element showing "Loading…" or an error state is visible, and that every `<img>` in the page is `complete` with a `naturalWidth` above 0. Otherwise it rejects, naming the first block that is not ready.
  - `capture-page.js` calls it for a job with `ready: 'shop'`.
  - A Playwright test proves the negative: one block's stub fails while another succeeds, and readiness rejects.
- **The thumbnail gate** is `PatternLibraryTest::testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan` (there is no `PatternThumbnailsTest`). It fails for any offered pattern without a picture, so the contributor registers **in the same commit** as its thumbnails (Task P6), after the pipeline is built and proven on its own (Task P5).
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
2. **A commerce section inserted on a shop with no products at all.** The grids show their empty state, Featured product and Add to cart show the stage notice, and the public page shows no broken card. Pinned in Task P4 (templates and endpoints) and Task P6 (the library resolves the patterns on an empty shop).
3. **A Featured product whose configured slug later points at a deleted product.** On the stage it reads "choose a product". In public, `shop.js` hides it instead of "Loading…" forever. Pinned in Task P4's browser proof.
4. **Commerce toggled off after shop sections were inserted into pages.** The patterns disappear from the palette. Pages keep their blocks, which render through the missing-template fallback, as today with no shop HTML. Pinned in Task P6's second-boot test.
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
- Regenerate: `docs/openapi.json`, `admin/src/api/schema.d.ts` (the pattern schema's hunks only)
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
- [ ] **Step 6: Regenerate the API artifacts.** Run `composer docs:openapi` and `cd admin && pnpm gen:api`. Keep only the hunks that add `requires` to the pattern schema in `docs/openapi.json` and `admin/src/api/schema.d.ts`. `docs/internal/OUTSTANDING.md` notes that a regeneration also refreshes unrelated stale paths; those stay out of this commit. `git diff --stat` must show only those two files' pattern hunks.
- [ ] **Step 7: Run** `vendor/bin/phpunit tests/Integration/Content tests/Integration/Commerce/AdminOpenApiGateTest.php` and `cd admin && pnpm type-check`. Expected: PASS.
- [ ] **Step 8: Commit** as `feat(patterns): the library offers contributed sections and templates, whole or not at all`, including `docs/openapi.json` and `admin/src/api/schema.d.ts`.

## Task P4: Featured product and Add to cart are honest without a product

**Files:**
- Create: `packages/thallo-contracts/src/Delivery/StorefrontBlockPreview.php`, `packages/thallo-commerce/src/Shop/ShopBlockPreview.php`
- Modify:
  - `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (bind `StorefrontBlockPreview::class` beside `StorefrontLinkResolver`)
  - `packages/thallo-render/src/RenderContextExtension.php` (constructor `?StorefrontBlockPreview $blockPreview = null`; `TwigFunction('shop_block_product_label', …)`)
  - `packages/thallo-render/src/RenderServiceProvider.php` (soft-bind it, as `storefrontLinks` is)
  - `packages/thallo-render/src/Templates/TemplatePolicy.php` (allowlist the function)
  - `packages/thallo-commerce/templates/blocks/featured-product.twig`, `packages/thallo-commerce/templates/blocks/add-to-cart.twig`
  - `packages/thallo-commerce/src/Http/Shop/ShopBlockDataController.php` (`unconfigured`)
  - `packages/thallo-commerce/assets/shop.js`
- Test:
  - `tests/Integration/Commerce/ShopBlockSelectionTest.php`
  - `tools/runtime-browser/tests/shop-block-selection.spec.js` with its fixture page `tools/runtime-browser/fixtures/shop-block-selection/index.html`

**Interfaces:** Consumes `ProductLinkService::resolveByEntry()` (link row with `product_uuid`), `ProductRepository::findBuyerAvailableByUuid()` and `findBuyerAvailableBySlug()`. Produces the Twig function `shop_block_product_label(?string slug, ?string entry_uuid): ?string` and endpoint JSON `unconfigured: true`.

- [ ] **Step 1: Write the failing PHP tests** in `ShopBlockSelectionTest`, modelled on `ShopLayoutRenderTest` and `ProductFieldBlocksRenderTest` with `ShopPageSeed`. Assert on the markup these produce:
  - a page entry rendered through `RenderController`, both public and on the stage (a preview session with `?canvas=1`);
  - where a test needs "no entry context", a render of `{{ blocks(l) }}` through the render pack's Twig with no `entry` in context, as `scripts/lib/showcase.php` renders.

  Cases:
  - `testTheStageSaysChooseAProductAndKeepsTheBlocksStyling`: a page with `featured-product` (blank slug), carrying an instance style (margin top `spacing.xl`) and a style class. The stage render has one element with `data-thallo-block="<id>"` (selectable), classes `thallo-field-empty`, the instance style's class and the style class, and the text `Featured product — choose a product`, and no `data-shop-block`. The same holds for `add-to-cart` with `Add to cart — choose a product`.
  - `testTheStageNamesALinkedOrChosenProduct`: seed the active product "Stoneware bowl". `product_slug: 'stoneware-bowl'` renders `Featured product — Stoneware bowl` on the stage. Blank slug on an entry linked to that product (`ProductLinkService::link`) renders the same.
  - `testWithNoEntryContextNothingIsRendered`: a render without an entry, blank slug, contains no `thallo-block-featured-product`, no `shop.js` script tag, no noscript text and no "Loading…". The same holds for add-to-cart.
  - `testAnUnlinkedPageRendersTheShell`: a public render of an ordinary page (it has an entry uuid) with a blank slug contains `data-shop-block="featured-product"` and `data-entry-uuid="<uuid>"`. Script hides it once hydrated (Step 8).
  - `testAConfiguredBlockRendersItsShell`: a public render with `product_slug: 'stoneware-bowl'` contains `data-product-slug="stoneware-bowl"`.
  - `testTheEndpointsSayWhenNothingIsConfigured`: with no slug and no entry, `GET /_shop/blocks/featured-product` answers `{"product": null, "unconfigured": true}` and `add-to-cart` answers `mode: unavailable, unconfigured: true`. With the entry uuid of an unlinked page, both answer `unconfigured: true`. With a slug for a missing product, both answer without `unconfigured`.
  - `testAnEmptyShopKeepsTheNotice`: with no products at all, the stage shows "choose a product" and an unlinked page's endpoints answer `unconfigured: true`.
- [ ] **Step 2: Run it.** `vendor/bin/phpunit tests/Integration/Commerce/ShopBlockSelectionTest.php` — Expected: FAIL (notice absent, `unconfigured` missing, shell rendered without an entry).
- [ ] **Step 3: The contract and implementation.** Create `StorefrontBlockPreview` (Shared contracts). `ShopBlockPreview::productLabel()` mirrors `ShopBlockDataController::resolveSlug` and then names the product:
  ```php
  public function productLabel(?string $slug, ?string $entryUuid): ?string
  {
      $tenant = $this->tenants->tenantUuid($this->context);
      if ($slug !== null && $slug !== '') {
          $product = $this->products->findBuyerAvailableBySlug($this->context, $tenant, $slug);
      } elseif ($entryUuid !== null && $entryUuid !== '') {
          $link = $this->links->resolveByEntry($this->context, $entryUuid);
          $product = $link === null ? null
              : $this->products->findBuyerAvailableByUuid($this->context, $tenant, (string) $link['product_uuid']);
      } else {
          return null;
      }

      return $product !== null && ($product['status'] ?? null) === 'active' ? (string) $product['name'] : null;
  }
  ```
  Bind it in the commerce provider's `services()` beside `StorefrontLinkResolver`, gated identically.
- [ ] **Step 4: The Twig function.** In `RenderContextExtension`:
  ```php
  new TwigFunction('shop_block_product_label', fn (?string $slug, ?string $entryUuid): ?string =>
      $this->blockPreview?->productLabel($slug !== '' ? $slug : null, $entryUuid !== '' ? $entryUuid : null)),
  ```
  Soft-bind it in `RenderServiceProvider` exactly like `storefrontLinks` (`$container->has(StorefrontBlockPreview::class) ? … : null`), and add `'shop_block_product_label'` to `TemplatePolicy`'s function allowlist.
- [ ] **Step 5: The templates.** `featured-product.twig` becomes:
  ```twig
  {% set slug = data.product_slug|default('') %}
  {% set entry_uuid = entry.uuid|default('') %}
  {% if is_canvas() %}
    {# Shop behaviour never runs on the stage, so it shows a named placeholder that keeps the
       block's authored styling (width, spacing, placement, style classes) and stays selectable. #}
    {% set label = shop_block_product_label(slug, entry_uuid) %}
    <div class="thallo-block thallo-block-featured-product thallo-field-empty{{ style_classes('root') }}"{{ style_attrs('root') }}>Featured product — {{ label ?: 'choose a product' }}</div>
  {% elseif slug != '' or entry_uuid != '' %}
    {# …today's shell, unchanged: its root keeps style_classes('root') and style_attrs('root'),
       its noscript line, its stylesheet link and its script tag… #}
  {% endif %}
  ```
  `add-to-cart.twig` is the same, reading "Add to cart — …". Keep every existing comment.
- [ ] **Step 6: The endpoints.** In `featuredProduct()` and `addToCart()`, when `$slug === null` (neither an explicit slug nor an active linked product), return `['product' => null, 'unconfigured' => true]` and `AddToCartViewModel::unavailable()->toArray() + ['unconfigured' => true]` respectively.
- [ ] **Step 7: Run the PHP tests.** Expected: PASS.
- [ ] **Step 8: Write the failing browser proof** `shop-block-selection.spec.js`. The fixture page holds a `featured-product` shell and an `add-to-cart` shell (the templates' shell markup with `data-shop-block`, `data-entry-uuid="e1"`) and loads `/packages/thallo-commerce/assets/shop.js`.
  - `an unlinked page's featured product hides itself`: route `**/_shop/blocks/featured-product*` to `{product: null, unconfigured: true}`. Expect the block root hidden and "Loading…" not visible.
  - `a gone product's featured product hides itself`: route to `{product: null}`. Expect it hidden.
  - `an unlinked page's add to cart hides itself`: route `**/_shop/blocks/add-to-cart*` to `{mode: 'unavailable', unconfigured: true}`. Expect it hidden.
  - `a gone product's add to cart says so`: route to `{mode: 'unavailable'}`. Expect "This product is not available." visible.
  Run `cd tools/runtime-browser && npx playwright test tests/shop-block-selection.spec.js`. Expected: FAIL on the first three.
- [ ] **Step 9: `shop.js`.**
  - In `renderFeaturedProduct`, when `!product`, set `el.hidden = true` and return, without un-hiding the "Loading…" paragraph.
  - In the add-to-cart render, when `data.unconfigured === true`, set `el.hidden = true` and return, before the unavailable branch.
  - Comment both: an empty block is not shown to shoppers; the editor sees a named placeholder on the stage.
- [ ] **Step 10: Run the browser proof and the PHP tests.** Expected: PASS.
- [ ] **Step 11: Commit** with this changelog bullet under `### Fixed`: "**A Featured product or Add to cart block with no product no longer shows "Loading…" forever.** On the stage it says to choose a product, or names the product it shows, keeping its styling; on the site it shows nothing until a product is chosen, and an Add to cart whose product is gone says the product is not available." Message: `fix(commerce): Featured product and Add to cart are honest without a product`.

## Task P5: a thumbnail pipeline for shop patterns, proven on its own

**Files:**
- Create: `tools/style-proofs/shop-readiness.js`, `tools/style-proofs/tests/shop-readiness.spec.js`, `tools/style-proofs/pages/shop-readiness/index.html` (the test page)
- Modify:
  - `tools/style-proofs/capture-page.js` (`ready: 'shop'`)
  - `scripts/lib/showcase.php` (`showcase_renderer(..., bool $withContributions = false)`)
  - `scripts/build-pattern-thumbnails` (seeding, picture-only configuration, matched responses, injection); used for real in P6
- Test: the readiness spec; `PatternLibraryTest` stays green (no pattern added yet)

**Interfaces:** Produces `waitForShopReady(page, timeoutMs = 10000): Promise<void>`, which rejects with `shop block "<data-shop-block>" not ready: <reason>`. Also produces `showcase_renderer`'s `$withContributions` flag, and in `build-pattern-thumbnails` a function `shop_block_responses(string $html, ContainerInterface $c, array $imageFiles): array<string,string>` returning path-and-query to JSON.

- [ ] **Step 1: Write the failing readiness test.** Serve the test page from the style-proofs static server, as the existing tests do. It holds a product-grid shell, a featured-product shell and an add-to-cart shell, and loads `packages/thallo-commerce/assets/shop.js`.
  - `ready when every block has painted`: route all three endpoints to valid fixture JSON (a grid of one item with a committed image, a featured product, `mode: direct`). `waitForShopReady` resolves.
  - `not ready when one block fails`: the grid succeeds and the featured endpoint answers 500. It rejects naming `featured-product`.
  - `not ready while an image is missing`: the grid item's image URL returns 404. It rejects naming `product-grid`.
  - `not ready while loading shows`: the add-to-cart route never answers (hangs). It rejects after the timeout (use 1500 ms in the test) naming `add-to-cart`.
  Run `cd tools/style-proofs && npx playwright test tests/shop-readiness.spec.js`. Expected: FAIL, module not found.
- [ ] **Step 2: Implement `shop-readiness.js`.**
  - Use `page.waitForFunction` with a predicate. Every `[data-shop-block]` must be ready:
    - `product-grid`: `[data-shop-grid-items]` is visible with at least one child;
    - `featured-product`: `[data-shop-featured-body]` is visible;
    - `add-to-cart`: `[data-shop-add-to-cart-form]` or `[data-shop-add-to-cart-link]` is visible.
  - No visible element's text may be "Loading…", no block may show a visible error or empty state (`[data-shop-featured-empty]` or an error class), and every `img` must have `complete && naturalWidth > 0`.
  - On timeout, evaluate the page again to name the first unready block and its reason, and throw that.
- [ ] **Step 3: Run it.** Expected: PASS (4 tests).
- [ ] **Step 4: Wire it into `capture-page.js`.** For a job with `ready: 'shop'`, `await waitForShopReady(page, job.readyTimeout || 10000)` after `document.fonts.ready`; a rejection fails the whole build with the job's `out` in the message. Document `ready` in the header comment.
- [ ] **Step 5: The renderer's contributed stylesheets.** In `showcase_renderer`, add `bool $withContributions = false`. When true, build the artifact with `ThemeStylesheetArtifact::build($sheets, $container->get(RenderContributionRegistry::class)->frozenStylesheets())`, exactly as `build-builder-proof-fixtures` does on its line 229. The default leaves every existing caller unchanged.
- [ ] **Step 6: The build's commerce helpers** (unused until P6):
  - `shop_block_responses($html, $container, $imageFiles)`:
    - parse `$html` with `DOMDocument`;
    - for each `[data-shop-block]` build the query in `shop.js`'s order. For `product-grid` that is `source` (default `newest`), `category_slug`, `tag_slug`, `products`, `page_size` (default `24`). For `featured-product` and `add-to-cart` it is `product_slug`, `entry_uuid`;
    - call `ShopBlockDataController::{productGrid,featuredProduct,addToCart}(Request::create($path, 'GET', $params))`;
    - replace every cover URL in the JSON that contains a blob uuid from `$imageFiles` (blob uuid to repo-relative file, e.g. `ShopPageSeed::IMAGES`) with `file://<repo>/<file>`;
    - key the result by `"{$path}?{$query}"`.
  - `shop_inject($html, $responses, $root)`:
    - remove `<link rel="stylesheet" href="/_thallo/shop/shop.css">` and `<script src="/_thallo/shop/shop.js" defer></script>`;
    - append after `</main>` a `<script>` defining `window.fetch` over `const R = <json of $responses>`. It answers `new Response(R[key], {headers: {'Content-Type': 'application/json'}})` for `key = url.pathname + url.search`, or `console.error` and a 500 for an unmatched shop URL, and delegates everything else;
    - then append `<script src="file://{$root}/packages/thallo-commerce/assets/shop.js"></script>`.
- [ ] **Step 7: Run** `vendor/bin/phpunit --filter testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan` (still green: no new pattern) and `php -l scripts/build-pattern-thumbnails`.
- [ ] **Step 8: Commit** as `feat(patterns): a thumbnail pipeline that waits for every shop block to be ready`.

## Task P6: the commerce pattern contributor, with its thumbnails

**Files:**
- Create: `packages/thallo-commerce/src/Patterns/ShopPatternsContributor.php`
- Modify: `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (`registerPatternContributor()`, called beside `registerStarterContributor()` inside the capability gate), `scripts/build-pattern-thumbnails` (use the P5 helpers for commerce patterns)
- Regenerate: `admin/public/pattern-thumbs/shop-*.jpg`, `admin/src/editor/palette/patternThumbSizes.json`
- Test: `tests/Integration/Commerce/ShopPatternsTest.php`; `PatternLibraryTest::testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan`

**Interfaces:** Consumes `PatternBlocks`, `PatternContributorRegistry`, `ShopBlockTypesContributor::SLUG_PRODUCT_GRID` (`product-grid`), `SLUG_FEATURED_PRODUCT` (`featured-product`) and `SLUG_ADD_TO_CART` (`add-to-cart`), and P5's helpers and `ready: 'shop'`. Produces contributor id `thallo.commerce` and these slugs:

| Section slug | Label | Category | Built from | requires |
|---|---|---|---|---|
| `shop-new-arrivals` | New arrivals | Shop | band: `header('New in', 'Just arrived', 'The latest additions to the shop.')` + `product-grid` `{source: newest, page_size: medium}` + button "Shop all" | — |
| `shop-collection-grid` | Collection grid | Shop | band: `header('Collection', 'Shop the collection', null)` + `product-grid` `{source: newest, page_size: large}` | — |
| `shop-featured-spotlight` | Featured product | Shop | band with `splitAtLg()`: stack(heading "Our pick this month", text, button "Shop now") + `featured-product` `{product_slug: ''}` | product |
| `shop-add-to-cart-cta` | Add-to-cart call to action | Shop | band, surface `color.surface-muted`: stack(heading "Ready when you are", text) + `add-to-cart` `{product_slug: ''}` | product |
| `shop-sale-banner` | Sale banner | Shop | `hero` `{headline: 'Limited time', title: 'The seasonal sale is on', description, heading_level: 'h1'}`, buttons ['Shop the sale'] | — |
| `shop-reasons` | Reasons to buy | Shop | band: `header('Why shop with us', 'Made to last, shipped with care', null)` + `grid('3', [three feature()s: delivery, returns, secure checkout])` | — |
| `shop-product-faq` | Product FAQ | Shop | band: `header('Questions', 'Before you buy', null)` + an accordion of four `question()`s (delivery, returns, care, payment), shaped exactly like core's `faq` section (`StarterPatterns`, line 263) | — |
| `shop-cta-band` | Shop call to action | Shop | `cta('Find something you'll love', description, 'soft', 'horizontal', ['Browse the shop'])` | — |

| Template slug | Label | Sections, in order |
|---|---|---|
| `shop-landing` | Shop landing | `shop-sale-banner`, `shop-new-arrivals`, `shop-featured-spotlight`, `shop-reasons`, `shop-cta-band` |
| `shop-product-launch` | Product launch | `shop-featured-spotlight`, `features-grid`, `shop-product-faq`, `shop-add-to-cart-cta` |
| `shop-sale` | Sale / collection | `shop-sale-banner`, `shop-collection-grid`, `shop-reasons`, `shop-cta-band` |
| `shop-new-arrivals-page` | New arrivals | `page-header`, `shop-new-arrivals`, `shop-featured-spotlight`, `shop-cta-band` |

- Every button's `url` is `#`, and every description is final text of one or two short sentences, written in full in the implementation.
- The three feature icons must exist in the admin's icon set. Check `truck`, `rotate-ccw` and `shield-check` against the icon picker's data, and use the closest existing name when one is missing.
- The collection grid's description says to pick a category for the grid.

- [ ] **Step 1: Write the failing tests** in `ShopPatternsTest`:
  - `testTheShopPatternsAreOfferedWithCommerceOn`: from the container's `PatternLibrary::all()`, the eight section and four template slugs above are present, each template with as many blocks as its section list. `shop-landing`, `shop-product-launch` and `shop-new-arrivals-page` have `requires: 'product'`; `shop-sale` has `requires: null`.
  - `testEveryShopPatternSavesAsAPage`: each template's blocks, with fresh ids, pass `FieldValidator` for a one-field `blocks` schema, as a page save would.
  - `testNoShopPatternShipsASiteSpecificValue`: walk every shop pattern. Every `product_slug` is `''`, every `category_slug` and `tag_slug` is absent or `''`, every `url` is `#`, and every product-grid `source` is `newest`.
  - `testTheShopPatternsResolveOnAnEmptyShop`: with `commerce_products` emptied, the same slugs are offered.
  - `testWithCommerceOffNoShopPatternExists`: a second boot via `bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]])`, in a `try/finally` restoring the shared connection and permission provider like `InertnessTest`. Its `PatternLibrary::all()` has no `shop-` slug, and its `PatternContributorRegistry::all()` has no `thallo.commerce`.
  - `testRegistrationIsIdempotent`: calling `registerPatternContributor()` twice leaves one contributor.
- [ ] **Step 2: Run them.** `vendor/bin/phpunit tests/Integration/Commerce/ShopPatternsTest.php` — Expected: FAIL, no `shop-` patterns.
- [ ] **Step 3: Implement `ShopPatternsContributor`** per the tables, every block through `PatternBlocks::*`, and the commerce slugs through `ShopBlockTypesContributor`'s constants. Its docblock cites spec §6: portable settings, `requires`, and newest grids.
- [ ] **Step 4: Register it.** Add `registerPatternContributor(ApplicationContext $context, ?PatternContributorRegistry $registry = null): bool` in the shape of `registerStarterContributor()` (guard, container lookup, idempotent by id). Call it right after `registerStarterContributor($context)` inside the `thallo.commerce` gate, with a comment: user-facing batteries-included content, only while the capability is on.
- [ ] **Step 5: Run** `ShopPatternsTest`. Expected: PASS. Then run `vendor/bin/phpunit --filter testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan`. Expected: FAIL, listing the twelve `shop-*` patterns without pictures. The next steps fix that inside this same commit.
- [ ] **Step 6: Use the pipeline for commerce patterns.** In `build-pattern-thumbnails`:
  - Seed inside the rolled-back transaction: `$seed = new ShopPageSeed($container, $context); $seed->useTenant(); $seed->seed();`. `useTenant()` writes the widened-schema flags the seed works under, and the rollback removes them with everything else. Take `ShopPageSeed::IMAGES` (blob uuid to repo-relative committed file) as `$imageFiles`: `shop_block_responses()` rewrites any cover URL containing one of those blob uuids to `file://<repo>/<file>`.
  - Extend `$configured` so a blank-slug `featured-product` or `add-to-cart` gets the first seeded product's slug, in the picture only.
  - For a pattern whose rendered HTML contains `data-shop-block`, render with `$withContributions = true`, compute `shop_block_responses()`, apply `shop_inject()`, and give the job `ready: 'shop'`.
- [ ] **Step 7: Build and look.** Run `DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-pattern-thumbnails`. Expected: "Wrote N thumbnails", with twelve new `shop-*.jpg` and no readiness failure.
  - Open `shop-new-arrivals.jpg`, `shop-featured-spotlight.jpg`, `shop-add-to-cart-cta.jpg` and `shop-landing.jpg` with the Read tool. Confirm they show the seeded products with their cover images, prices, the add-to-cart control and shop styling, and no "Loading…".
  - `git status` must show no changed core thumbnail. A changed one means the refactor or the renderer default changed output; investigate before committing.
- [ ] **Step 8: Run** `ShopPatternsTest`, `PatternLibraryTest` (the thumbnail gate included) and `cd admin && pnpm fmt:check`. Expected: PASS.
- [ ] **Step 9: Commit everything in this task together**: the contributor, its registration, the build changes and the regenerated thumbnails and sizes. Add this changelog bullet under `### Added`: "**Shop sections and page templates.** With Commerce on, **Sections** and **Templates** in the Design view offer shop parts — new arrivals, a collection grid, a featured product, an add-to-cart call to action, a sale banner, reasons to buy, a product FAQ and a shop call to action — and four page templates built from them: **Shop landing**, **Product launch**, **Sale / collection** and **New arrivals**. A part with a featured product or add-to-cart block says so on its card: choose the product after inserting it. With Commerce off they are hidden." Message: `feat(commerce): shop sections and page templates for the page library, with their thumbnails`.

## Task P7: the admin shows what a pattern needs, proven in a browser

**Files:**
- Modify: `admin/src/editor/palette/BlocksPalette.vue` (a line on the card when `pattern.requires === 'product'`)
- Test:
  - `admin/src/__tests__/pattern-requires.spec.ts`
  - `admin/e2e/tests/shop-patterns.spec.ts`
  - fixtures from `scripts/build-builder-proof-fixtures`, whose `patterns.json` comes from the live `PatternController::index()`

**Interfaces:** Consumes `Pattern.requires` (P3) and the shop patterns (P6).

- [ ] **Step 1: Write the failing vitest.** Mount `BlocksPalette` with two patterns in the Sections view, one with `requires: 'product'` and one without, following `admin/src/__tests__/saved-sections.spec.ts`. The first card shows "Choose a product after inserting it"; the second doesn't. The same holds in the Templates view.
- [ ] **Step 2: Run it.** `cd admin && pnpm exec vitest run src/__tests__/pattern-requires.spec.ts` — Expected: FAIL.
- [ ] **Step 3: Implement.** Under the card's description, add `<p v-if="pattern.requires === 'product'" data-test="pattern-requires" class="…">Choose a product after inserting it</p>`, reusing the card's existing muted-text classes.
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Rebuild the proofs' fixtures:** `DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`. Check that `admin/e2e/fixtures/api/patterns.json` contains `shop-landing`.
- [ ] **Step 6: Write the browser proof** `shop-patterns.spec.ts` with the existing Design-view helpers in `helpers.ts`:
  - `the shop templates are offered with their note`: switch the palette to Templates. The four shop templates are listed, and Shop landing's card shows "Choose a product after inserting it".
  - `a shop template inserts its sections`: click **Sale / collection**, then assert the recorded apply holds four new top-level blocks in order: `hero`, a container holding `product-grid`, a container of features, `cta`.
  Run `cd admin/e2e && pnpm exec playwright test tests/shop-patterns.spec.ts --workers=8`. Expected: PASS.
- [ ] **Step 7: Commit** (type-check, lint, fmt on touched files) as `feat(admin): a pattern that needs a product says so on its card`.

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
  - Commerce contributor gated on the capability: P6.
  - Eight sections and four templates: P6.
  - Portable settings: P6.
  - Product-selection state (notice, styling kept, the three public cases): P4.
  - Thumbnails: pipeline and readiness in P5; fixture-only thumbnails in P6, landing with the contributor.
  - API artifacts for `PatternData`: P3.
  - Docs (§8): P8.
  - §7 S1 tests: P2–P7 (the thumbnail gate `testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan` passes at every commit).
  - Deferred to S2 with a ruling: rename and delete by row scope.
  - S2's parts are out of this plan by design.
- **Types:** `PatternSection`, `PatternTemplate`, `PatternContributor`, `PatternContributorRegistry`, `StorefrontBlockPreview` and `requires` are named once in Shared contracts and used consistently in P2–P7.
- **Review Focus:** items 1–5 each pinned to a task.
