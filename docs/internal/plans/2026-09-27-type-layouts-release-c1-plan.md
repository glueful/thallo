# Type Layouts — Release C1 (the product page) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An editor designs the shop's product page once, on the layout stage, from product field blocks (name, price, gallery, rating, description, category, breadcrumb, the linked product story) and one Add to cart block, saves it, and every product renders through it; with no layout every product page renders exactly as today.

**Architecture:** The commerce pack contributes a `product` surface (target `@site`) to core's layout registry while its capability is on, plus nine layout-only field blocks. Release A's engine — storage, session, stage, Save/Remove, validation, block-document lifecycle — carries it unchanged except where it assumed the entry surface: the resolver's cache key (a target with `@`), the validator (required blocks without a field), the stage's sample render (a surface that builds its own frame variables), and the change announcement (one `LayoutChanged` event commerce listens to). Live product pages ask the layout reader before `shop/product.twig`; a protected frame, `layouts/product.twig`, keeps the canonical link, the Product JSON-LD, the shop stylesheet and script, and the no-JS buy form the theme template guarantees.

**Tech Stack:** PHP 8.3 (Glueful, PostgreSQL, PHPUnit), Twig 3 (render pack, commerce templates), `glueful/commerce` (catalog), Nuxt UI admin (Vue 3, vitest, Playwright e2e in `admin/e2e`).

**Spec:** `docs/internal/superpowers/specs/2026-09-26-type-layouts-design.md` — §2.6–2.7, §3 (`product` row), §4 (the product surface reads `product`), §5.4 (`product_story` carries no annotation), §5.6, §7.1, §7.3, §7.4 (commerce purge), §8 (rendering and stage proofs), §11 item 3. Release C1 is ordered before B by the user (2026-09-27): C1 needs only Release A's engine; C2 (shop home and categories, `product_loop`) follows B. Section numbers below (§n) are the spec's. The spec leaves the product field blocks unnamed ("its field and smart blocks"); this plan names them from what `packages/thallo-commerce/templates/shop/product.twig` renders today, so the starter can reproduce it.

## Rulings made while planning (from the code)

- **The product blocks are the parts of `shop/product.twig`.** Nine, category **Fields**, `layout_only`, contributed by a new `ProductFieldBlocksContributor` (the existing `ShopBlockTypesContributor` keeps its five, so `ShopBlocksTest`'s count stands): `product_breadcrumb`, `product_gallery`, `product_category`, `product_name`, `product_rating`, `product_price`, `product_description`, `product_buy` (the smart block: the variant picker, quantity stepper and Add to cart, plus the wishlist heart and the availability line, fixed internals, §2.7), `product_story` (the linked entry's content). Snake case, as the Fields family (`entry_title`) and the spec's own `product_buy`/`product_story` are; the kebab-case shop blocks (`add-to-cart`, `product-grid`) are unchanged and remain general content blocks.
- **The stage delegates only the sample, never the render.** The render pack cannot name commerce, but commerce already depends on the render pack. A new contract `Thallo\Contracts\Layouts\LayoutSampleContext` lets a surface build its frame's variables for a sample; `RenderController::layoutSample()` asks it when the surface implements it, and renders the frame itself as it does for entries — so annotation, the preview bridge, `Cache-Control: no-store` and the retired page stay in one place.
- **`product_story` needs no slot helper.** Its content is `enrichment_html`, already rendered by `EntryBlocksRenderer` with annotation off and its own depth before the frame renders; so on the stage it is never selectable and its depth is its own (§4.1's reset holds by construction). The template prints the markup.
- **One announcement for a layout change.** `LayoutSaver`, `LayoutsSource` and `LayoutBindings` each repeat "forget the resolver, purge the surface tag". C1 folds them into `LayoutChanges::announce()` (core), which also dispatches `Thallo\Contracts\Layouts\LayoutChanged`; commerce's `PurgeShopCacheOnLayoutChange` answers it for its surfaces (§7.4), beside `PurgeShopCacheOnThemeChange`.
- **The resolver key must not carry the raw target.** `LayoutResolver` caches under `thallo:layout:{surface}:{target}`; `@site` contains `@`, which the Redis driver rejects in keys (`RedisCacheDriver::validateKey`). The key encodes the target (`rawurlencode`). Tags are not keys (`addTags` does not validate), so the page tag stays `thallo:layout:product:@site`.
- **Registration is a push, inside the capability gate.** `LayoutSurfaceRegistry` gains `register(LayoutSurface $surface): void`; commerce registers `ProductSurface` where it registers its routes and starter content type (capability on **and** the commerce engine bound). Off, the Layouts row and the stage disappear; the stored row stays and returns on re-enable.
- **Existing sites get the blocks from `thallo:provision`** (or `thallo:blocks:seed`), as Release A's Fields blocks did: `ContributedBlockTypeReconciler` seeds a capability's contributions only when the capability first turns on.
- **The product page frame and the stage share one presentation rule.** Shop pages ignore the theme's per-type presentation today (`ShopPageRenderer` hard-codes centered, default chrome). `Thallo\Render\Layouts\FramePresentation::fixed(?array $frame)` applies a layout's Frame over that same default; `ShopPageRenderer` and the product stage both use it, so the stage shows what the site serves.

## Global Constraints

- **A product page with no layout renders exactly as today** — byte-identical `shop/product.twig` output (§ header, §7.2). Any markup extracted from it into a partial is proven identical by a golden captured before the extraction.
- **The product frame keeps what `shop/product.twig` guarantees:** canonical from `ShopUrlGenerator::product($slug)`, the Product JSON-LD, `/_thallo/shop/shop.css`, `/_thallo/shop/shop.js` once, the `data-shop-scope` root, and the server-built `AddToCartViewModel` behind `product_buy`'s no-JS form (§7.3). The frame is not designable (§2.6).
- **`product_buy` appears exactly once and cannot be deleted; a layout without it cannot be saved** (§2.7, §3).
- **Save goes live; no overwrite** — Release A's Save/Remove contract, unchanged, for target `@site` (§5.5).
- **Every product page carries `Cache-Tag: thallo:layout:product:@site`,** with or without a layout, beside the linked entry's `thallo:entry:{uuid}` (§7.3, §7.4).
- **Commerce off ⇒ no product surface, no product blocks rendered, nothing errors;** the pack's inertness contract (`InertnessTest`, `StorefrontInertnessTest`, `composer test:distribution`) holds.
- **Pack boundaries:** commerce reaches layouts only through `Thallo\Contracts\Layouts\*` (and the render pack it already uses); core never names commerce (§2.1); `composer boundaries` stays green.
- **Layout-only blocks are refused in entries, regions and saved sections by the server** — the nine product blocks included, through the existing `layout_only` flag (§5.6).
- **Entry layouts behave exactly as in Release A;** their tests pass unchanged.
- **No compatibility shims:** a replaced API is replaced at every caller in the same task.
- **Every task's gates pass at its own commit;** each user-visible change carries its CHANGELOG bullet under `## [Unreleased]` in the same commit (re-add the heading if a cut removed it).
- Gates, run foreground and never concurrently: `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `vendor/bin/phpcs` (check the **exit code**), `composer boundaries`; admin — `pnpm exec oxfmt <touched files>`, `pnpm type-check`, `pnpm lint`, `pnpm exec vitest run`, and for any admin or fixture change the e2e suite (`CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`, which must exit 0, then `cd admin/e2e && pnpm test`).
- `git diff` before every commit; no AI attribution trailers; never push, never tag. One release, one beta cut at the end when the user asks (the cut runs `php scripts/sync-docs-changelog` and includes `docs/reference/08-changelog.md`); split, tags and pushes are the user's.

**Steps 1–4**, wherever a task says so, are the TDD cycle: write the failing tests named in the task, run and watch each fail for the stated reason, implement the minimum, run the file and the task's gates green. Step 5 is the commit.

## Review Focus

The inputs the spec implies but no requirement names, most likely to bite first. Each has its test in the owning task.

1. **A site on the Redis cache driver** saves a product layout: the resolver's key for `@site` must be a valid key, or every product page 500s the moment a layout is read — Task P1.
2. **Commerce switched off with a product layout saved**, then on again: off, the Layouts page has no product row and no page or job errors on the stored row (block migrations still walk it); on, the same layout serves again, untouched — Task P3.
3. **A multi-variant product, and one with a required add-on, under a layout:** `product_buy` renders the same closed decision as today (a native `<select>`; the honest "not available for online purchase" line), and the no-JS form posts and redirects back to the product page — Task P4.
4. **A product with no images, no description, no category and no reviews** under a layout: the site renders nothing for the empty fields (the rating shows zeros, as today), the stage shows a named placeholder for each so it stays selectable — Task P2.
5. **Two workspaces (tenancy on), each with its own product layout,** both under target `@site`: each tenant's pages render its own layout, a save in one purges and forgets only that tenant's answer, and neither save conflicts with the other — Task P5.

---

## Shared contracts (named once, used by every task)

**Contracts (`packages/thallo-contracts/src/Layouts/`)**

- `LayoutSurfaceRegistry` gains `register(LayoutSurface $surface): void` — replaces a surface with the same key (a boot that runs twice is harmless).
- `LayoutSampleContext` (new interface) — `sampleContext(string $target, string $sample): ?array`: the frame's Twig variables for a published sample, or null when it is no longer available. A surface that implements it returns frame variables from `placeholder()` too (not an entry).
- `LayoutChanged` (new event, `extends Glueful\Events\Contracts\BaseEvent`) — `public readonly string $surface`, `public readonly string $target`. Dispatched after the outermost commit of any change to a stored layout.

**Core (`core/src/Content/Layouts/`)**

- `LayoutSurfaces::register()` per the contract; the constructor still registers `EntrySurface`.
- `LayoutChanges` — `announce(string $surface, string $target): void`: `LayoutResolver::forget`, `RenderedPageCachePurge::purge(["thallo:layout:{$surface}:{$target}"])` (when bound), then `EventService::dispatch(new LayoutChanged(...))` (when bound). Called from inside the `afterCommit` callbacks where `forget`+`purge` are called today: `LayoutSaver::forgetAndPurge` (removed), `LayoutsSource::persist`, `LayoutBindings` (rename and tombstone paths). `LayoutResolver::forget` stays public (the fixture script and tests call it).
- `LayoutResolver::key()` — `$prefix . 'thallo:layout:' . $surface . ':' . rawurlencode($target)`, exposed for the test as `public static function cacheKey(string $prefix, string $surface, string $target): string`.
- `LayoutValidator` — for every `required()` rule **without** `field`: the type must occur exactly once in the whole tree; none ⇒ `blocks` = `"the layout must show the {label} block"` (label from `BlockTypeRepository`, else the slug); a second ⇒ `{path}.type` = `"'{type}' can appear only once in a layout"`. The existing `entry_content` rule is unchanged.

**Render (`packages/thallo-render`)**

- `Thallo\Render\Layouts\FramePresentation::fixed(?array $frame): array` — `{show_title: true, layout: 'full'|'centered' (full only when frame width is full), header, footer ('hidden' only when the frame says so), style_classes: ''}`.
- `RenderController::layoutSample()` — when the surface `instanceof LayoutSampleContext`: `$vars = is_string($sample) ? $surface->sampleContext($target, $sample) : null`, published iff non-null, else `$surface->placeholder($target)` with `layout_placeholder` as today; render `$surface->frame()` with `entry` null, `$vars + ['layout' => …, 'presentation' => FramePresentation::fixed($layout['settings']), 'preview_revision' => …]`, in the default locale. The entry path is unchanged.

**Commerce (`packages/thallo-commerce`)**

- `Thallo\Commerce\Starter\ProductFieldBlocksContributor implements StarterBlockTypeContributor` — the nine definitions, `sourceId: 'thallo-commerce:{slug}'`, `requiresCapability: 'thallo.commerce'`, `category: 'Fields'`, `flags: ['layout_only' => true]`; settings:

  | Slug | Label | Settings (schema) | Style |
  |---|---|---|---|
  | `product_breadcrumb` | Product breadcrumb | `category` boolean (show the category step; default on) | text |
  | `product_gallery` | Product gallery | `thumbnails` boolean (default on), `aspect` enum natural\|1:1\|4:3 | box + `picture` target (as `entry_cover`) |
  | `product_category` | Product category | `link` boolean (to the category page) | text |
  | `product_name` | Product name | `level` enum h1–h4 | text |
  | `product_rating` | Product rating | `hide_when_none` boolean (default off: zeros, as today) | text |
  | `product_price` | Product price | `compare_at` boolean (show the "was" price; default on) | text |
  | `product_description` | Product description | — | text |
  | `product_buy` | Add to cart | `wishlist` boolean (default on), `availability` boolean (default on) | box |
  | `product_story` | Product story | — | box |

  "text" is `['spacing','width','alignment.self','alignment.text','typography','colors.text','visibility','layout.item']` with `StyleTargets::root('text', …)`; "box" is `['spacing','width','visibility','layout.item']` with `StyleTargets::root('box', …)` (the shapes `StarterBlockTypes::FIELD_TEXT` and the shop blocks use).
- Templates `packages/thallo-commerce/templates/blocks/product_*.twig` — each renders its root `thallo-block thallo-block-{slug}` **plus** the `shop-product__*` class of the markup it reproduces (so `shop.css` styles it), `style_classes('root')`/`style_attrs('root')`; an empty value renders nothing, and in a canvas a `thallo-field-empty` placeholder naming it ("Gallery — this product has no images"). They read `product` (a `ProductViewModel`), `breadcrumb_category`, `enrichment_html`, `shop_index` from the context.
- Partials extracted from `shop/product.twig` and included by it and by the frame/blocks: `shop/_product_head.twig` (canonical link, shop stylesheet, the JSON-LD script) and `shop/_product_buy.twig` (the whole `.shop-product__buy` area and the availability line, with `show_wishlist`/`show_availability` variables defaulting to true).
- `Thallo\Commerce\Shop\ShopProductPage` — `forProduct(string $tenant, array $product): array{vars: array<string,mixed>, entry_uuid: ?string}` — everything `ShopCatalogController::product()` builds after finding the product (variants, gallery, `AddToCartViewModel`, `ProductViewModel`, breadcrumb category, enrichment, `canonical`, `shop_index`), moved verbatim; `placeholder(): array` — the same variables for an in-memory product: name "Sample product", description `<p>A short description of the product.</p>`, one active variant at 4900 minor units in `CommerceSettings::currency()`, no media, no rating, no category, no enrichment, built through `ProductViewModel::fromRow` and `AddToCartViewModel::build` so formatting is real.
- `Thallo\Commerce\Layouts\ProductSurface implements LayoutSurface, LayoutSampleContext` — key `product`; `targets()` = `[{target: '@site', label: 'Products — product page', enabled: true, reason: null}]`; `label()` "Products — product page"; `reach()` "Applies to every product"; `samples($target, $q)` — active, buyer-available products (`ProductRepository::activeFilteredQuery(ctx, tenant, null)`, name `ILIKE %q%` when given, `created_at DESC, uuid ASC`, limit 50) as `{id: uuid, label: name}`; `defaultSample()` the first; `sampleContext($target, $uuid)` — `findBuyerAvailableByUuid`, status `active`, else null, then `ShopProductPage::forProduct(...)['vars']`; `placeholder()` — `ShopProductPage::placeholder()`; `palette()` the nine slugs; `required()` `[{type: 'product_buy'}]`; `bindable()` `[]`; `frame()` `layouts/product.twig`; `starter()` per Task P3.
- Frame `packages/thallo-commerce/templates/layouts/product.twig` — extends `layout.twig`; title as `shop/product.twig`'s; includes `shop/_product_head.twig`; the placeholder notice as `layouts/entry.twig` has it; `<article class="shop-product shop-product--layout"{% if wishlist_scope %} data-shop-scope="…"{% endif %}>{{ layout_blocks(layout.blocks|default([])) }}</article>`; `<script src="/_thallo/shop/shop.js" defer></script>` once.
- `ShopPageRenderer::render(Request $request, string $template, array $extra, int $status = 200, ?array $frame = null)` — `presentation` = `FramePresentation::fixed($frame)` (identical to today's when `$frame` is null).
- `ShopCatalogController::product()` — gains `?LayoutReader $layouts = null` (constructor, last); with `$layouts?->for('product', '@site')` it renders `layouts/product.twig` with `layout` (+ `surface`, `target`) and the frame, else `shop/product.twig` as today; `Cache-Tag` is the list `thallo:layout:product:@site` plus `thallo:entry:{uuid}` when linked.
- `Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnLayoutChange` — on `LayoutChanged` with surface `product`: `CacheStore::invalidateTags(['thallo:shop:catalog'])`; registered in `registerShopCachePurgeListeners` (outside the gate, like its siblings).

**Admin**

- The layout editor's required-block copy names the block by its type's label (`paletteTypes`): delete refusal "Every product shows its Add to cart here, so the layout keeps this block. Move it instead."; Save blocked "The layout must show the Add to cart block — add it from the Blocks tab." The `entry_content` wording (it names the field) is unchanged.
- Test ids reused from Release A; the product row is `layouts-row-product-@site`, its Edit `layouts-edit-product-@site`.

---

## Task P1: the engine takes a pack's surface

**Files:**
- Create: `packages/thallo-contracts/src/Layouts/{LayoutSampleContext,LayoutChanged}.php`, `core/src/Content/Layouts/LayoutChanges.php`
- Modify: `packages/thallo-contracts/src/Layouts/LayoutSurfaceRegistry.php` (`register`), `core/src/Content/Layouts/LayoutSurfaces.php`, `core/src/Content/Layouts/LayoutResolver.php` (encoded key, `cacheKey`), `core/src/Content/Layouts/LayoutValidator.php` (field-less required rules), `core/src/Content/Layouts/LayoutSaver.php`, `core/src/Content/Blocks/Sources/LayoutsSource.php`, `core/src/Content/Layouts/LayoutBindings.php` (all three through `LayoutChanges`), `core/src/Providers/CoreServiceProvider.php` (register `LayoutChanges`, shared, autowired; inject it where the resolver and purge were)
- Test: `tests/Integration/Content/Layouts/LayoutSurfaceRegistrationTest.php`, additions to `LayoutResolverTest`, `LayoutValidatorTest`, `LayoutSaveTest`, `LayoutDocumentsTest`, `LayoutBindingsTest`

**Interfaces:** Produces `LayoutSurfaceRegistry::register`, `LayoutSampleContext`, `LayoutChanged`, `LayoutChanges::announce`, the field-less required rule.

- [ ] **Step 1: failing tests:**
  - `LayoutSurfaceRegistrationTest` — a test surface (an anonymous `LayoutSurface`, key `fixture`, target `@site`, palette `['heading']`, required `[{type: 'button'}]`, starter one heading and one button) registered through `register()`: `get('fixture')` returns it; registering it twice leaves one; `GET /v1/admin/layouts` lists `{surface: 'fixture', target: '@site'}`; a session mints for it (`starter: true`), an apply is accepted, and `PUT /v1/admin/layouts/fixture/%40site` saves version 1 (the path's `@` decoded by the router) — the whole Release A lifecycle for a target that is not a type slug.
  - `LayoutResolverTest::testTheKeyOfASiteWideTargetIsAValidCacheKey` — `LayoutResolver::cacheKey('', 'product', '@site')` has no character of `{}()/\@` (the Redis driver's reserved set); `testASiteWideLayoutIsFoundAfterForget` — save for `('fixture', '@site')`, `for()` finds it after `forget()`.
  - `LayoutValidatorTest::testARequiredBlockWithoutAFieldIsNeededExactlyOnce` — with the fixture surface: no button ⇒ `blocks` names the Button block by its label; two buttons (one nested in a container) ⇒ the second's `blocks.1.data.content.0.type` refused; one ⇒ accepted. `entry_content` cases unchanged (existing tests).
  - `LayoutSaveTest::testASaveAnnouncesTheChange` — a listener on `LayoutChanged` receives `('entry', 'post')` once, **after** the commit (it queries the row from a second connection and sees the new version); an outer rollback dispatches nothing. `LayoutDocumentsTest` and `LayoutBindingsTest` each gain one assertion that their persist/rename/tombstone dispatches `LayoutChanged` for the layout it rewrote.
- [ ] **Step 2:** run the files — fail (no `register`, raw key, no field-less rule, no event).
- [ ] **Step 3:** implement per Shared contracts. `LayoutChanges` replaces the three `forget` + `purge` pairs at every call site in the same change.
- [ ] **Step 4:** the files green; the Release A layout tests green unchanged; full PHP suite; phpcs; boundaries.
- [ ] **Step 5:** commit `feat(layouts): the engine takes a pack's surface — site-wide targets, required blocks without a field, one change announcement` (changelog: none — nothing a person sees yet).

## Task P2: the product field blocks

**Files:**
- Create: `packages/thallo-commerce/src/Starter/ProductFieldBlocksContributor.php`, `packages/thallo-commerce/templates/blocks/product_{breadcrumb,gallery,category,name,rating,price,description,buy,story}.twig`, `packages/thallo-commerce/templates/shop/_product_head.twig`, `packages/thallo-commerce/templates/shop/_product_buy.twig`
- Modify: `packages/thallo-commerce/templates/shop/product.twig` (includes the two partials; output byte-identical), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (`registerShopBlockTypeContributor` also registers `ProductFieldBlocksContributor`), `packages/thallo-commerce/assets/shop.css` (the `thallo-field-empty` placeholder inside `.shop-product`, and spacing for the blocks outside the fixed two-column grid)
- Test: `tests/Integration/Commerce/ProductFieldBlocksRenderTest.php`, `tests/Integration/Commerce/ProductFieldBlocksProvisioningTest.php`, `tests/Integration/Commerce/ProductPageGoldenTest.php`, addition to `tests/Integration/Content/LayoutOnlyBlocksTest.php`

**Interfaces:** Consumes the `layout_only` flag (Release A). Produces the nine slugs, their templates, the two partials.

- [ ] **Step 1: failing tests:**
  - `ProductPageGoldenTest` — **written and run green before touching `product.twig`:** seed a simple product with a cover and two gallery images, a category and a linked story (as `StorefrontWalkTest::seedProduct`/`seedEnrichmentEntry`), a multi-variant product and a product with a required add-on; capture each `/shop/products/{slug}` body into `tests/fixtures/commerce/product-page-{simple,multi,addon}.html` with `THALLO_RECORD_PRODUCT_GOLDEN=1`; the test compares byte for byte. It stays green through the extraction.
  - `ProductFieldBlocksRenderTest` — each block rendered through the real Twig environment with a context the test builds from `ProductViewModel::fromRow` and `AddToCartViewModel::build` (the shape `ShopProductPage` gives in P3): markup and the `shop-product__*` class it carries; `product_buy` in `direct` mode emits the same form as the golden (`<form class="shop-product__add-to-cart" method="post" action="/_shop/cart/add" data-shop-buy`, the hidden `variant_uuid`), in `select` mode the native `<select name="variant_uuid" required>`, in `link` mode the "not available for online purchase" line; `wishlist: false` drops the heart, `availability: false` the "In stock" line; `product_price` with `compare_at: false` drops the struck price; `product_story` prints `enrichment_html` and nothing when it is empty. Review Focus 4: a product with no media, no description, no category and no rating — `product_gallery`, `product_description`, `product_category` render nothing and `product_rating` renders zeros outside a canvas; in `layout` scope each empty one renders a `thallo-field-empty` placeholder naming it, and `product_rating` with `hide_when_none` renders the placeholder.
  - `ProductFieldBlocksProvisioningTest` — a fresh tenant with commerce on gets the nine (category Fields, flag `layout_only`); `thallo:provision` on an existing single-store site with commerce on and the reconciler's flag already listing `thallo.commerce` adds the nine; with commerce off they are hidden from Settings › Block types (the reconciler's existing behaviour).
  - `LayoutOnlyBlocksTest::testProductFieldBlocksBelongToLayouts` — an entry draft, a region save and a saved section each containing `product_name` are refused at the block's path.
- [ ] **Step 2:** run each — the golden passes (recorded on today's template), the rest fail.
- [ ] **Step 3:** implement. Extract `_product_head.twig` and `_product_buy.twig` from `product.twig` first and keep the golden green before writing any block template; `product_buy.twig` is `{% include 'shop/_product_buy.twig' with {show_wishlist: data.wishlist ?? true, show_availability: data.availability ?? true} %}` inside its root.
- [ ] **Step 4:** green; `ShopBlocksTest`, `NoJsAddToCartTest`, `StorefrontWalkTest`, `ShopJsRuntimeTest` green unchanged; full PHP suite; phpcs.
- [ ] **Step 5:** commit `feat(commerce): the product field blocks` (changelog: none yet — the feature bullet lands with P5).

## Task P3: the product surface

**Files:**
- Create: `packages/thallo-commerce/src/Shop/ShopProductPage.php`, `packages/thallo-commerce/src/Layouts/ProductSurface.php`
- Modify: `packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php` (`product()` finds the product and hands it to `ShopProductPage::forProduct`; its private `buildAddToCart`/`resolveEnrichment` move into `ShopProductPage`), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (services for both; `registerLayoutSurface($context)` inside the capability-and-engine gate, beside `registerStarterContributor`, when the container has `LayoutSurfaceRegistry`)
- Test: `tests/Integration/Commerce/ProductSurfaceTest.php`, `ProductPageGoldenTest` (still green)

**Interfaces:** Consumes P1 (`register`, `LayoutSampleContext`), P2 (slugs). Produces `ShopProductPage::forProduct/placeholder`, `ProductSurface`.

- [ ] **Step 1: failing tests** (`ProductSurfaceTest`):
  - `testTheProductSurfaceIsRegisteredWhileCommerceIsOn` — `LayoutSurfaceRegistry::get('product')` is a `ProductSurface`; `GET /v1/admin/layouts` has the row `{surface: 'product', target: '@site', label: 'Products — product page', reach: 'Applies to every product', state: 'theme'}`.
  - Review Focus 2: `testCommerceOffHidesTheSurfaceAndKeepsTheLayout` — save a product layout (through the repository, as `LayoutSaveTest` does), boot with `thallo.commerce` off (the `InertnessTest` arrangement): no `product` row and `get('product')` null; `GET /v1/admin/layouts` answers 200; a block migration backfill run walks the stored row without an error; boot with it on again: the row reads **Custom layout** and `LayoutReader::for('product', '@site')` returns the saved blocks.
  - `testSamplesAreActiveBuyerAvailableProductsNewestFirst` — an active, a draft and an archived product, and one whose seller is suspended with marketplace on: only the active, available ones, newest first; `q` filters by name.
  - `testTheStarterReproducesTheProductPageAndValidates` — `starter('@site')` is exactly: `product_breadcrumb {category: true}`; a `container` whose `settings.style.layout` is display grid, columns base `1`, md `2`, holding a `container` with `product_gallery {thumbnails: true, aspect: natural}` and a `container` with, in order, `product_category`, `product_name {level: h1}`, `product_rating`, `product_price {compare_at: true}`, `product_description`, `product_buy {wishlist: true, availability: true}`; then `product_story`. It passes `LayoutValidator::validate('product', '@site', …)` unchanged.
  - `testTheSampleContextIsThePagesOwnVariables` — for an active product, `sampleContext('@site', $uuid)` has `product` (its `ProductViewModel`, `addToCart` built), `breadcrumb_category`, `enrichment_html`, `canonical` (`ShopUrlGenerator::product($slug)`), `shop_index`; for an archived or unknown uuid, null.
  - `testThePlaceholderWritesNothing` — `placeholder('@site')` has "Sample product" with a formatted price in the store currency and a direct-mode `addToCart`; the product and variant counts are unchanged.
  - `testTheControllerStillRendersTheGolden` — `ProductPageGoldenTest` is green after the move into `ShopProductPage`.
- [ ] **Step 2:** run — fail. **Step 3:** implement; move the controller's page building into `ShopProductPage` verbatim first and rerun the golden before adding the surface. **Step 4:** green; `StorefrontInertnessTest`, `InertnessTest`, `composer test:distribution` green; full PHP suite; phpcs; boundaries (commerce imports only contracts from core's side). **Step 5:** commit `feat(commerce): the product page is a layout surface`.

## Task P4: product pages render through the layout

**Files:**
- Create: `packages/thallo-commerce/templates/layouts/product.twig`, `packages/thallo-render/src/Layouts/FramePresentation.php`, `packages/thallo-commerce/src/Shop/Listeners/PurgeShopCacheOnLayoutChange.php`
- Modify: `packages/thallo-commerce/src/Http/Shop/ShopPageRenderer.php` (`$frame`), `packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php` (selection, tags), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (the listener, its factory, in `registerShopCachePurgeListeners`)
- Test: `tests/Integration/Commerce/ProductLayoutRenderTest.php`, `tests/Unit/Render/FramePresentationTest.php`

**Interfaces:** Consumes P1–P3. Produces the live rendering, `FramePresentation::fixed`, the shop purge on `LayoutChanged`.

- [ ] **Step 1: failing tests** (`ProductLayoutRenderTest`, a saved product layout through `LayoutSaver` unless a test says otherwise):
  - `testNoLayoutRendersTheThemeTemplateUnchanged` — without a layout every golden is byte-identical, and each response's `Cache-Tag` holds `thallo:layout:product:@site` (and `thallo:entry:{uuid}` for the linked one).
  - `testALayoutRendersEveryProductThroughTheFrame` — the starter saved: the page holds `shop-product--layout`, the layout's blocks in order, one `shop.js`, one `shop.css`; a heading added to the layout appears on two different products.
  - `testTheFrameKeepsTheCanonicalAndTheStructuredData` — under a layout that drops the name, price and gallery blocks, the canonical `<link>` and the `application/ld+json` script are byte-identical to the theme page's for the same product.
  - Review Focus 3: `testTheBuyBlockWorksWithoutJavaScript` — under the starter layout: a simple product's form posts (the `NoJsAddToCartTest` request, CSRF-guard origin included) and answers 303 back to the product page with the line in the cart; a multi-variant product renders the native `<select>`; a product with a required add-on renders the "not available for online purchase" line and no form; the form carries no cart or CSRF token (cache-safe).
  - `testTheStoryKeepsItsEntryTag` — a linked product under a layout with `product_story`: the story's content renders and `Cache-Tag` holds `thallo:entry:{uuid}`; unlinked: nothing renders, the tag list is the surface tag alone.
  - `testTheFrameSettingsApply` — Frame `width: full`, `footer: hidden`: the body carries the full-width presentation and no footer region; with no Frame settings the presentation is today's (`FramePresentationTest` pins the mapping, including `fixed(null)` equal to the old hard-coded array).
  - `testFirstSaveThenEditThenRemovePurgesTheShopCache` — the `ShopCacheTest` arrangement: a cached theme product page; first save ⇒ the next request renders the layout (not the cached theme page); an edit ⇒ the change shows; remove ⇒ the theme page returns; each through `LayoutChanged` → `PurgeShopCacheOnLayoutChange`. A `LayoutChanged` for `('entry', 'post')` leaves the shop cache alone.
  - `testCommerceOffLeavesNoProductPageToRender` — commerce off with a layout saved: `/shop/products/{slug}` is not a shop route (the existing inertness answer) and nothing reads the product layout.
- [ ] **Step 2:** run — fail. **Step 3:** implement. **Step 4:** green; full PHP suite; phpcs; boundaries. **Step 5:** commit `feat(commerce): product pages render through the product layout`.

## Task P5: the product layout stage and its lifecycle

**Files:**
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php` (`layoutSample` asks a `LayoutSampleContext` surface, per Shared contracts)
- Test: `tests/Integration/Commerce/ProductLayoutStageTest.php`, `tests/Integration/Commerce/ProductLayoutSaveTest.php`

**Interfaces:** Consumes P1–P4. Produces the product stage; with it, the feature is usable end to end.

- [ ] **Step 1: failing tests:**
  - `ProductLayoutStageTest`: `testTheStageRendersTheWorkingCopyAroundTheSampleProduct` — mint `{surface: 'product', target: '@site'}`, apply a layout adding a heading: the canvas holds the sample product's name, the frame, `data-thallo-canvas="layout"`, and `data-thallo-block` on every layout block including `product_buy`, inside `data-thallo-slot="blocks"`; `testTheStorysBlocksAreNeverSelectable` — a linked sample whose story holds a heading: the story's heading renders with no `data-thallo-block`, and no id appears twice; `testNoProductsOpensOnThePlaceholder` — no active product: `placeholder: true` in the mint, the canvas shows "Sample product" and the notice "No published products yet — showing a placeholder", nothing written; `testAnArchivedSampleFallsBackToThePlaceholder` — archive the sample mid-session: the next render is the placeholder, the working copy intact; `testTheStageUsesTheFramesPresentation` — a working copy with `width: full` renders the full-width presentation on the stage, as `FramePresentation::fixed` gives the live page; `testARetiredSessionRendersTheRemovalPage` for the product surface.
  - `ProductLayoutSaveTest`: `testSaveAndRemoveKeepTheContract` — first save 1, stale save 409 `LAYOUT_VERSION_CONFLICT` with nothing written, remove tombstones and retires (apply ⇒ 410), a fresh session opens on the starter at the tombstone's version, the save after it continues the version; `testALayoutWithoutTheBuyBlockIsRefused` — Save and apply of a layout without `product_buy` answer 422 naming the Add to cart block; with two, 422 at the second's path; `testEntryBlocksAreRefusedOnTheProductSurfaceAndProductBlocksOnEntrySurfaces` — `entry_title` in a product layout and `product_name` in a post layout each 422 at their path; Review Focus 5: `testTwoWorkspacesKeepTheirOwnProductLayout` — tenancy on, tenants A and B: each saves its own product layout at version 1 (no conflict between them); A's product page renders A's layout and B's renders B's; a save in A dispatches `LayoutChanged` and leaves B's cached resolver answer and pages in place.
- [ ] **Step 2:** run — fail (the stage renders the entry frame with an entry placeholder). **Step 3:** implement. **Step 4:** green; the Release A stage tests green unchanged; full PHP suite; phpcs; boundaries. **Step 5:** commit `feat(layouts): the product page on the layout stage` — the changelog's feature bullet under **Added**: **Layouts for product pages** (Site › Layouts › Products — product page; the nine blocks; Add to cart is required; Save applies to every product; the canonical link, structured data and the no-JS Add to cart are kept; commerce off hides it; on an existing site `thallo:provision` adds the product blocks).

## Task P6: the admin copy and the browser proof

**Files:**
- Modify: `admin/src/pages/layouts/[surface]/[target].vue` (required-block copy by label, per Shared contracts), `scripts/build-builder-proof-fixtures` (a product layout section: inside the rolled-back transaction, create one simple product through `CatalogService::createProduct`, mint a `product`/`@site` session through `LayoutPreviewController::session`, render its canvas through `/_preview/{token}?canvas=1`, write `admin/e2e/fixtures/layouts/product-session.json` and `product-stage.html`; any throw exits 1, as the layouts section does), `admin/e2e/helpers.ts` (`openLayoutStage(page, {surface: 'product', target: '@site'})` reading those fixtures)
- Test: `admin/src/__tests__/layout-editor.spec.ts` (additions), `admin/e2e/tests/product-layout-stage.spec.ts`

**Interfaces:** Consumes the product session and stage (P5).

- [ ] **Step 1: failing tests:**
  - `layout-editor.spec` — with a product session (`required: [{type: 'product_buy'}]`, palette the nine, `label: 'Products — product page'`): deleting the Add to cart block is refused with "Every product shows its Add to cart here, so the layout keeps this block. Move it instead."; a working copy without it disables Save with "The layout must show the Add to cart block — add it from the Blocks tab."; the post session's Entry content copy is unchanged; the Blocks tab leads with the nine product tiles.
  - e2e `product-layout-stage.spec` — open `/layouts/product/@site` against the fixtures: the stage shows the sample product; selecting the Add to cart block opens the Block tab; its delete is refused with the reason; dragging **Product rating** from the Blocks tab onto the stage applies (the recorded apply holds a `product_rating`).
- [ ] **Step 2:** run — fail. **Step 3:** implement. **Step 4:** the fixture script exits 0 and writes both files; admin gates; the full e2e suite. **Step 5:** commit `feat(layouts): the product layout editor names its required block` (changelog: the P5 bullet gains "the editor names the block a layout must keep").

## Task P7: docs, the full gates and the beta cut

**Files:**
- Modify: `docs/guides/20-layouts.md` (a **Product pages** section: open **Products — product page**, the nine blocks and their settings in a table, Add to cart is required and why, the linked product story, what the frame keeps, what commerce off does, `thallo:provision` on an existing site), `docs/guides/18-commerce.md` (one paragraph pointing to it), `docs/reference/04-block-library.md` (the **Fields** section gains the nine product blocks, marked Commerce; the intro count), `packages/thallo-render/docs/THEMING.md` (the product frame, `layouts/product.twig`, and what a theme override must keep), `packages/thallo-commerce/README.md` (the surface and the blocks)
- Gates: full PHP suite, phpcs, boundaries, admin gates, e2e, `vendor/bin/phpunit tests/Unit/Docs`; then, when the user asks, the cut (`php scripts/sync-docs-changelog` in the cut commit, no empty `[Unreleased]` heading), `composer test:distribution`, `composer test:skeleton`, `scripts/release-bake`, the release commit, `scripts/verify-dist-archive`.

- [ ] **Step 1:** write the docs; `vendor/bin/phpunit tests/Unit/Docs`.
- [ ] **Step 2:** full gates, each read by its real result (failure count, exit code), never a chained command's last status.
- [ ] **Step 3:** commit `docs(layouts): design the product page`.
- [ ] **Step 4:** the beta cut when the user asks.

---

## Self-review

- **Spec coverage (C1):** §2.1 one engine, commerce through the registry (P1, P3); §2.6 the frame is fixed (P4); §2.7 the smart block, required (P1 rule, P2, P5, P6); §3 the `product` surface — key, `@site`, targets, samples, palette, required, frame, starter (P3); §4 the product surface reads `product` (P2); §5.1 non-null `@site` target (P1, P5); §5.2 placeholder and a vanished sample (P3, P5); §5.4 the stage — layout blocks annotated, `product_story` inert (P5); §5.5 Save/Remove for `@site` (P1, P5); §5.6 validation — allowed per surface, required once, layout-only refused elsewhere (P1, P2, P5); §5.7 lifecycle — `LayoutsSource` walks product rows, capability off (P1, P3); §6.1 the row while the capability is on (P3); §6.2 the editor, required block copy (P6); §7.1 the frame in the commerce pack, overridable by file (P4, P7); §7.3 selection in the shop render, canonical, JSON-LD, stylesheet, script, no-JS form, `product_story` with its entry tag (P4); §7.4 the surface tag on every product page and the `LayoutChanged` shop purge (P1, P4); §8 rendering and stage proofs for the product (P4, P5), browser proof (P6). Loop surfaces (`shop_index`, `shop_category`, `product_loop`) are C2.
- **Names:** `LayoutSurfaceRegistry::register`, `LayoutSampleContext::sampleContext`, `LayoutChanged`, `LayoutChanges::announce`, `LayoutResolver::cacheKey`, `FramePresentation::fixed`, `ShopProductPage::forProduct/placeholder`, `ProductSurface`, `ProductFieldBlocksContributor`, `PurgeShopCacheOnLayoutChange`, the nine slugs and `_product_head.twig`/`_product_buy.twig` are used identically in every task.
- **Review Focus:** five items, each with its named test (P1, P2, P3, P4, P5).
