# Type Layouts — Release C2 (the shop home and categories) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An editor designs the shop's home (`/shop[?page=n]`) and, once for every category, the shop's category page (`/shop/categories/{slug}[?page=n]`) on the layout stage. Each page has a title, the category chips, a **Product list** whose card they design once, and the page navigation. They save it, and every such page renders through it. With no layout, every shop home and category page renders exactly as today.

**Architecture:** The commerce pack registers two more surfaces while its capability is on: `shop_index` and `shop_category`, both with target `@site`. They sit beside C1's `product` and reuse Release B's loop machinery unchanged:
- `loops()` declares `product_loop`, its card field and the blocks that go only inside that card;
- the validator and the admin's insert rules read that declaration;
- `loop_cards(card, items, 'product')` renders each card with `layout_context.product` and `item` set to that card's product. The first card is annotated on the stage and the rest are copies.

Four layout-only blocks arrive:
- `product_loop` (required, the card);
- `shop_title`;
- `category_rail`;
- `product_tile` (the card's picture, category chip and quick actions, with fixed internals).

C1's `product_name`, `product_price` and `product_rating` also go inside the card. They render the grid card's markup when they sit in a card, and the product page's markup elsewhere. B's `pagination` block serves both pages.

`ShopCatalogController::index()` and `category()` ask the layout reader before `shop/index.twig` and `shop/category.twig`, as `product()` does. Protected frames keep the canonical link and the wishlist scope. Each takes the shop styles from the layered theme artifact only (C1's rule), so authored settings win. The shop's cache tags become per-surface and per-workspace for all three commerce surfaces.

**Tech Stack:** PHP 8.3 (Glueful, PostgreSQL, PHPUnit), Twig 3 (render pack, commerce templates), `glueful/commerce` (catalog), Nuxt UI admin (Vue 3, vitest, Playwright e2e in `admin/e2e`), real-browser proofs in `tools/runtime-browser` (Playwright, `scripts/measure.js`).

**Spec:** `docs/internal/superpowers/specs/2026-09-26-type-layouts-design.md`. Sections: §1 (the `shop_index` and `shop_category` rows), §3 (`product_loop` required for the shop surfaces; commerce registers them while its capability is on), §4 (`item` inside a loop card), §5.2 (a shop with no products opens on the placeholder), §5.4 (loop cards), §5.6 (item-scoped blocks only inside a `product_loop` card), §7.1 (frames `layouts/shop_index.twig`, `layouts/shop_category.twig` in the commerce pack), §7.3 (`ShopPageRenderer` gains selection for its three surfaces), §7.4 (the surface tag on every commerce page; the `LayoutChanged` shop purge), §8, §11 item 4. The build order is Release A, then C1, B and now C2. B's seam holds: "C2's `product_loop` calls `loop_cards(data.card, layout_context.products, 'product')`". Section numbers below (§n) are the spec's. The spec does not name the shop's card blocks. This plan names them from what `packages/thallo-commerce/templates/shop/{index,category,_product_card}.twig` render today, so the starter can reproduce them.

## Rulings made while planning (from the code)

- **Which pages, which targets.** `ShopCatalogController::index()` serves `/shop` and `category($slug)` serves `/shop/categories/{slug}`. Both list 24 products a page with `?page=n` (`PER_PAGE`, `requestedPage()`). An unknown category answers the themed 404, and `ShopPageCache` 404s a non-canonical or out-of-range `page` (1..1000) before the controller runs. Both surfaces have one target, `@site`: one layout for the shop's home, and one for every category. A layout never makes a page exist. The 404s and the page grammar are unchanged. **The wishlist page** (`ShopWishlistController`) shares the grid but is not a surface; it is unchanged.
- **Labels and reach.** The stage's placeholder notice takes its noun from the label before " — " (`RenderController::layoutSampleOfSurface`), so both labels lead with "Products":

  | Surface | Label | Reach |
  |---|---|---|
  | `shop_index` | "Products — shop home" | "Applies to every page of the shop home" |
  | `shop_category` | "Products — shop categories" | "Applies to every shop category" |

  With those, the notice reads "No published products yet — showing a placeholder". B's reach-based copy then gives these required-block refusals:
  - "Every page of the shop home shows its Product list here, so the layout keeps this block. Move it instead."
  - "Every shop category shows its Product list here, so the layout keeps this block. Move it instead."

  Cost if wrong: two strings.
- **The loop and its card.** Both surfaces declare `loops()` = `[{type: 'product_loop', card: 'card', items: ['product_tile', 'product_name', 'product_rating', 'product_price']}]` and `required()` = `[{type: 'product_loop'}]`. The validator applies B's card rules unchanged:
  - `product_name` at the root of a shop layout is refused: "'product_name' goes inside the Product list's card";
  - `pagination` or `shop_title` inside the card is refused: "cannot go inside a card";
  - general blocks (heading, container, …) are allowed in the card.

  On the product surface, `product_name`, `product_price` and `product_rating` stay page blocks: that surface declares no loops. Each surface's `loops()` governs only its own layouts.
- **The card's item is an array.** `loop_cards()` skips an item that is not an array (`RenderContextExtension::loopCards`). The grid's cards are `ProductCardViewModel` objects. `ProductCardViewModel::toCardItem(): array` returns the view model's public properties under their own names (`uuid`, `name`, `url`, `coverUrl`, `rating`, `priceFormatted`, `compareAtFormatted`, `categoryName`, `cartMode`, `directVariantUuid`), so `product.coverUrl` reads the same in Twig for the object and the array. `toArray()` stays the snake_case JSON the wishlist endpoint and `shop.js` share.
- **A product block knows it sits in a card by `item`.** `blocks()` forwards `item` (B), and only `loop_cards()` sets it. `product_name`, `product_price` and `product_rating` render the **grid card's** markup when `item` is set, and the **product page's** markup otherwise; the product page's markup is unchanged.

  | Block | In a card | New setting |
  |---|---|---|
  | `product_name` | `<h{level} class="thallo-block thallo-block-product_name shop-grid__name">`, holding `<a href="{{ product.url }}">` when `link` is on | `link` boolean, default off (the product page is unaffected; the shop starters turn it on) |
  | `product_price` | `<div class="thallo-block thallo-block-product_price shop-grid__price">` with `shop-grid__price-current` and the `<s>`, as `_product_card.twig` | — (C1's `hide_compare_at` applies) |
  | `product_rating` | `<div class="thallo-block thallo-block-product_rating shop-grid__rating">` with the single star, the one-decimal average and "(n)", zeros dimmed, as `_product_card.twig` | — (C1's `hide_when_none` applies) |

  `product_category` and `product_gallery` are not card blocks. The tile carries the category chip, and a gallery's thumbnail swap has no place in a card. `product_buy`, `product_description`, `product_breadcrumb` and `product_story` stay page blocks. Cost if wrong: one entry in `items`.
- **Today's card name is a link, not a heading. The starter makes it an `h2` holding the link** (the page has one `h1`; the cards are its subsections). Card-scoped rules in `shop.css` give the heading the link's box: `.shop-grid__name:is(h1, h2, h3, h4)` resets margin, size, line-height and letter-spacing to the card's; `.shop-grid__name a` inherits colour and drops the underline except on hover. These rules match nothing on today's page, where the `<a>` itself is `.shop-grid__name`. S6 measures the result. **This is the one deliberate markup change in the starter. The user may overturn it at plan review** (the alternative is a `text` level on `product_name`, one enum value).
- **The tile is the card's smart block.** `product_tile` renders today's `.shop-grid__tile` whole. That is the media panel (the image, or the empty panel), the first-category chip and the hover actions: the no-JS direct-add form or the options link, and the hidden wishlist heart. The internals are fixed (§2.7's reasoning): the grid's cart honesty rule stays in one place. The tile's markup is extracted from `_product_card.twig` into `shop/_product_tile.twig` (`show_tag` and `show_actions` default to true). `_product_card.twig` and the block both include it, so the no-layout card cannot drift (S0's golden proves it). Settings: `tag` boolean (default on), `actions` boolean (default on).
- **The page blocks.**

  | Slug | Label | Settings (schema) | Holds blocks | Style |
  |---|---|---|---|---|
  | `product_loop` | Product list | `card` (blocks), `empty_text` string (blank: "No products yet." on the home, "No products in this category yet." on a category, as today) | the card | box on `root`, plus a `cards` target with the container's layout capabilities (`layout.display`, `layout.columns`, `layout.gap.column`, `layout.gap.row`, `alignment.content`, `layout.align_items`), as `entry_loop` declares them; **no Visibility** (required) |
  | `shop_title` | Shop title | `level` enum h1–h4 (default h1), `count` boolean (default on: "N products") | — | box on `root`; text on a `heading` target (`typography`, `colors.text`, `alignment.text`) |
  | `category_rail` | Category chips | `all_label` string (blank: "All") | — | box |
  | `product_tile` | Product tile | `tag` boolean (default on), `actions` boolean (default on) | — | box |

  All four: category **Fields**, `flags: ['layout_only' => true]`, `requiresCapability: 'thallo.commerce'`. They are contributed by a new `ShopLayoutBlocksContributor` beside C1's `ProductFieldBlocksContributor`, so `ShopBlocksTest`'s count and C1's nine stand. Markup:
  - `product_loop`: `<div class="thallo-block thallo-block-product_loop">` around `<ul class="thallo-block-product_loop__cards shop-grid">`, with each card a `<li class="thallo-loop-card shop-grid__item">`. With no products on the site, the `<ul class="shop-grid">` holds today's `<li class="empty">` with the empty text. On the stage it holds one placeholder card instead.
  - `shop_title`: `<div class="… shop-titlerow">`, holding `<h1>` ("Shop" on the home, the category's name on a category) and `<span class="shop-titlerow__count">`.
  - `category_rail`: today's `<nav class="shop-rail" aria-label="Categories">` and its chips. "All" is active on the home, the category's own chip on a category. With no categories it renders nothing, and on the stage the placeholder "Category chips — the shop has no categories".
- **The Product list's theme defaults are declared, so the inspector tells the truth (user, 2026-09-28).** With nothing set, `.shop-grid` renders an adaptive grid: `grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr))`, gaps 1.75rem row and 1.5rem column. But `effectiveDisplay()` (`admin/src/editor/inspector/layoutContext.ts`) answers `flex` for any unset or reset display, so the Layout tab would show Flex and hide Columns on a grid. The fix is a contract, not a starter value (Remove and Reset must be truthful too):
  - A style target declaration may carry `defaults`: the arrangement the theme gives that target when nothing is set. It is `{display: 'flex'|'grid', columns?: {label: string}, gap?: {row?: string, column?: string}}`: the mode, a label for tracks the vocabulary cannot name, and the gaps as the theme writes them (display text, not tokens).
  - `product_loop`'s `cards` target declares `{display: 'grid', columns: {label: 'Adaptive — as many 15rem columns as fit'}, gap: {row: '1.75rem', column: '1.5rem'}}`.
  - `entry_loop`'s `cards` target declares `{display: 'flex'}`. That is what the inspector assumes today, stated explicitly so every loop declares its truth; its behaviour is unchanged.
  - `StyleTargets::fromDeclaration()` validates `defaults` (unknown keys and modes throw `InvalidArgumentException`, as a bad target kind does) and exposes `defaults(string $target): ?array`. The declaration already reaches the admin as `style_targets` on the block type (`admin/src/queries/blockTypes.ts`).
  - `effectiveDisplay(block, breakpoint, classes, fallback = 'flex')`: nothing declared, a reset, or an unoffered stored value answers `fallback`. `dormantPaths(block, breakpoint, classes, role, fallback = 'flex')` passes the same fallback when `role` is `parent`, since today it calls `effectiveDisplay()` with the Flex fallback and would report the tracks dormant on an untouched grid. The Layout tab passes the edited target's declared `defaults.display` to both.
  - The tab shows the declared defaults as the unset state. Columns reads the label as "Theme default: Adaptive — as many 15rem columns as fit", and the gaps show the theme's values as their placeholder. Choosing a track count or gap overrides it, and resetting returns to it.
  - **The CSS is not truthful yet, and S1 fixes it.** `BlockStyleEmitter` emits `columns-auto` at every breakpoint where `layout.columns` is at its theme default: a span pairs with a track class, and `auto` is the one-track state. `StyleCompiler` compiles `columns-auto` to `grid-template-columns: none`, and in `@layer settings` that beats `.shop-grid`'s adaptive tracks in `@layer theme`. An untouched Product list would render one track.
    - The fix is a second default track state, `theme`: the tracks are the theme's own.
    - `BlockStyleEmitter` emits `columns-theme` in place of `columns-auto` for a theme-default resolution **only** on a target whose declared `defaults` carries `columns`. It reads the target's `StyleTargets::defaults()`.
    - `StyleCompiler` compiles `columns-theme` to no declaration, an empty rule, so the theme's `grid-template-columns` stands.
    - `spanRules()` pairs `theme` like `auto`, as a one-track state: a numeric span above 1 clamps to `1 / -1`, `full` is `1 / -1`, and `reset` is `revert-layer`. The number of adaptive tracks is unknown to the compiler, so the conservative clamp never overflows. No span occurs there today anyway: the cards are `loop_cards()`'s `<li>`, not blocks.
    - Every other target keeps `columns-auto` and today's compiled output byte for byte, span clamping included.
    - An explicit reset keeps today's rule, `grid-template-columns: revert-layer`: it reverts the whole settings layer for that property, a style class's value included, back to the theme's adaptive tracks.
    - `display` and the gaps need no change: an unset `display` emits no class, the theme's `display: grid` and gaps stand, a set value in `@layer settings` wins, and `display: flex` makes the tracks inert.
- **Palettes.** Both surfaces: `product_loop`, `shop_title`, `category_rail`, `pagination` (core's, from B), `product_tile`, `product_name`, `product_rating`, `product_price`. The palette names core's `pagination` by slug only (boundaries hold). The layout rows stay closed until every palette block is provisioned (`LayoutTargets::NOT_PROVISIONED`, C1's mechanism).
- **The page's variables reach blocks through `layout_context`** (C1's threaded key):

  `{products: list<card item>, total: int, pagination: {page, total_pages, prev_path, next_path}, categories: list<{name, url, active}>, shop_index: string, category: ?{name, slug, url}, placeholder_item?: card item}`

  `category` is null on the home. `placeholder_item` is set only by `placeholder()`: `loop_cards()` reads it only while annotating, with no items. `pagination` is B's shape, so B's `pagination` block renders today's `_pagination.twig` markup unchanged ("Newer" / "Older", "Page X of Y").
- **One page builder, shared with the stage.** `Thallo\Commerce\Shop\ShopCatalogPage` takes over what `index()` and `category()` build after resolving the request, moved verbatim: `buildGrid`, `categoryRail`, the page paths, `canonical`, `shop_index`. It adds `layout_context`. The controller and the two surfaces' `sampleContext()` both call it, so the stage renders what the site serves (C1's `ShopProductPage` pattern).
- **Samples.**
  - `shop_index`: one sample, `{id: '1', label: 'Page 1'}`, while the shop lists a product; none otherwise (the placeholder). The picker hides with one or no sample, as B's listing does.
  - `shop_category`: the categories that list at least one product, in the rail's order (`CategoryRepository::all`), `{id: uuid, label: name}`. A category is kept when `ProductRepository::activeFilteredQuery(ctx, tenant, new ResolvedProductFilters($uuid))` finds a row (limit 1). `q` filters by name (`ILIKE`, escaped as `ProductSurface::samples` does), at most 50.
  - `sampleContext()` answers page 1's variables. It answers null once the shop or the category lists no product, or the category is gone. The stage then renders the placeholder, as B's vanished term does.
- **Placeholders** write nothing. `products` is empty, `total` 0, one page, and `categories` is the real rail (a read). The category placeholder has `category` `{name: 'Sample category', slug: 'sample-category', url: ShopUrlGenerator::category('sample-category')}` and no active chip. `placeholder_item` is "Sample product": C1's `ShopProductPage::placeholder()` product (one active variant at 4900 minor units in `CommerceSettings::currency()`), turned into a card by `ProductCardViewModel::fromProduct($product, null, $product->addToCart)->toCardItem()`. One in-memory sample serves both the product and the shop stages, and formatting is real.
- **The starters reproduce today's pages.** The home and category starters are the same tree:
  1. `shop_title {level: h1, count: true}`;
  2. `category_rail {all_label: ''}`;
  3. `product_loop {empty_text: ''}`, whose card is:
     - `product_tile {tag: true, actions: true}`;
     - a `container` (div; layout display grid, `gap.row` token `spacing.xs`, i.e. `.shop-grid__body`'s 0.25rem) holding `product_name {level: h2, link: true}` and a `container` (display flex, direction row, `layout.align_items` center, `alignment.content` between, `gap.column` token `spacing.sm`, i.e. `.shop-grid__meta`'s 0.5rem) holding `product_rating` and `product_price`;
  4. `pagination {previous_label: '', next_label: '', count: true}`.

  The starters set no spacing: the frame's `section.shop-index` and `.shop-category` keep today's `shop.css` spacing (`.shop-titlerow`, `.shop-rail`, `.pagination` margins), and a settings value would override it. S0 freezes today's pages in a browser, and S6 measures the starter against them. **Every difference found is brought to the user to accept or fix before S6 commits**, as B7 did. Cost if wrong: CSS in `shop.css`.
- **Frames.** `packages/thallo-commerce/templates/layouts/shop_index.twig` and `layouts/shop_category.twig` extend `layout.twig`, with today's `<title>` blocks ("Shop — {site}", "{category} — {site}") and today's canonical `<link>` line. They also carry C1's placeholder notice and `<section class="shop-index shop-index--layout">` (and `shop-category shop-category--layout`) with `data-shop-scope` exactly as today, around `{{ layout_blocks(layout.blocks|default([])) }}`. **No raw `shop.css` link** (C1's ruling: the theme artifact's `@layer theme` delivers it, so `@layer settings` wins). Nothing else is added: today's shop pages link no `shop.js` of their own, so the frames link none either. Themes may override a frame by file; THEMING.md says what an override keeps.
- **Selection.** `index()`: `$this->layouts?->for('shop_index', '@site')`. `category()`, after the 404 check: `for('shop_category', '@site')`. With a layout, the frame renders through `ShopPageRenderer::render(..., $layout['settings'])` (C1's `FramePresentation::fixed`). Without one, the templates render as today. The stage uses the same presentation: `layoutSampleOfSurface` already applies `FramePresentation::fixed` to every non-core `LayoutSampleContext` surface, so no render-pack change is expected.
- **Caching (§7.4): one tag per surface, per workspace.** C1 tagged product pages `thallo:shop:layout:product` in the header and stored them under `…:{tenant}`. This generalizes to all three surfaces in `Thallo\Commerce\Layouts\ShopLayoutTags`:
  - `SURFACES = ['product', 'shop_index', 'shop_category']`;
  - `pageTag(string $surface): string` = `'thallo:shop:layout:' . $surface`;
  - `tenantTag(string $surface, string $tenant): string` = `pageTag($surface) . ':' . $tenant`.

  It **replaces** `ProductSurface::PAGE_TAG` and `ProductSurface::pageCacheTag()` at every caller (`ShopCatalogController`, `ShopPageCache`, `PurgeShopCacheOnLayoutChange`, `ProductLayoutCacheTest`); the product tag's value is unchanged.
- **An in-flight render never repopulates the shop cache with an old layout (user, 2026-09-28; amended the same day).** `ShopPageCache::handle()` reads its key, renders, then stores. A layout save can commit and purge between the read and the store, and the old render would then write the old page back. It stays there until the TTL, on tag-capable and tag-less drivers alike. This gap already exists for C1's product pages. The fix is `LayoutResolver`'s own generation scheme, applied per workspace and per surface:
  - `ShopLayoutTags::generationKey(string $surface, string $tenant): string` = `'thallo:layoutgen:shop:' . $surface . ':' . $tenant`. It sits **outside** both page-cache deletion patterns: `shop:{tenant}:*` needs the `shop:` prefix, and `tenant:*:shop:{tenant}:*` needs the tenant right after `:shop:`, where this key has the surface. So the tag-less fallback never deletes it. A test pins both patterns against every surface's key.
  - **The generation is a random token, not a counter**, so no loss, race or clock can ever repeat one. A token is `bin2hex(random_bytes(16))`.
  - Each layout route passes its surface to the cache middleware as a parameter: `product`, `shop_index` or `shop_category`. The wishlist route passes none, and its key is unchanged. Step 3 of S3 checks that the router takes a parameter on a class-named middleware (`handle(..., ...$params)` already accepts one); if not, it registers a `shop_page_cache` alias and the routes use that.
  - With a surface, the middleware reads the token **before** `$next()` (so before the controller reads the layout) and builds the key with `:{surface}g{token}` inserted after the page number. The render stores under the token it read.
  - **Atomic initialization.** A missing token is created with `CacheStore::setNx($key, $fresh, TTL)`. Whether that write won or lost, the middleware then **re-reads the key** and uses the stored value. Concurrent initializers therefore agree on the winner, and an initializer never overwrites a token a save has just written (`setNx` cannot overwrite). `TTL` is 30 days: expiry is only memory hygiene, because a lost token's replacement is fresh.
  - **Advancing on change.** `PurgeShopCacheOnLayoutChange` first **sets** a fresh token (an unconditional `set`, same TTL), then invalidates the tag or falls back to deleting keys, as before. `LayoutChanged` is dispatched after the outermost commit (C1), so a render that read the old layout necessarily read a token the change has since replaced.
  - **Recovery.** A token that is evicted, expired or deleted is replaced by a fresh one on the next request. Every entry stored under the lost token becomes unreachable, and the old entries are orphans until their own TTL. Two concurrent saves each set a fresh token; whichever lands last, it differs from the token any straddling render read.
  - This is correctness, not memory: the tag purge and the tag-less fallback stay, and free the old entries.
  - Isolation holds by construction. A's `shop_index` generation changes only A's home keys: A's category and product keys and all of B's keys are untouched. Each is proven by test. Every home page carries `Cache-Tag: thallo:shop:layout:shop_index`, and every category page `thallo:shop:layout:shop_category`, with or without a layout. `ShopPageCache` stores each as the workspace's own. `PurgeShopCacheOnLayoutChange` handles any surface in `SURFACES`: it invalidates that surface's tenant tag, and on a tag-less driver falls back to that workspace's shop keys, as C1 does. A `shop_index` save therefore evicts that workspace's home pages only (tag-capable drivers), never its category or product pages. The surfaces' `pageTags()` answer `[]`: no rendered-page purge, and never the tag-less `render:*` fallback (C1's rule).
- **Registration.** `registerLayoutSurface()` registers all three surfaces inside the capability-and-engine gate. Off, the rows and stages disappear; the stored rows stay and return on re-enable.
- **Existing sites get the four blocks, and `product_name`'s `link` setting, from `thallo:provision`** (with workspaces on, also `thallo:tenant:sync --all --kind=block_type`), as C1 and B did.

## Global Constraints

- **A shop home or category page with no layout renders exactly as today.** Markup is proven by deterministic goldens (S0); appearance by a frozen browser reference (S0, S6). `_product_card.twig`'s markup is unchanged after the tile extraction, and so is the wishlist page.
- **The controller decides which pages exist:** unknown categories 404, invalid `page` values 404 before the controller (`ShopPageCache`), and a page past the last renders as today; a layout never changes that (§7.2).
- **`product_loop` appears exactly once, never inside a card, and cannot be deleted or hidden;** a shop layout without it cannot be saved (§3, §5.6).
- **Card blocks only inside the card; page blocks never inside it** (§5.6). The server refuses them with the block's path, and the admin's insert and drop rules refuse them with the reason (B's rules, unchanged).
- **On the stage the first card is selectable and no block id appears twice;** later cards are copies and no drop destination (§5.4, B).
- **The no-JS quick add stays honest under a layout.** A direct-mode card's form posts to `/_shop/cart/add` as today, and an options-mode card links to its product. The markup carries no cart or CSRF token (cache-safe).
- **Every home page carries `Cache-Tag: thallo:shop:layout:shop_index` and every category page `thallo:shop:layout:shop_category`,** with or without a layout. A change in one workspace evicts only that workspace's pages of that surface on tag-capable drivers, and only that workspace's shop keys on tag-less ones (§7.4 as ruled in C1).
- **Save goes live; no overwrite.** Release A's Save/Remove contract applies unchanged to target `@site` (§5.5).
- **Product, entry, listing and archive layouts behave exactly as before.** Their tests pass unchanged, except the tag constant `ShopLayoutTags` replaces (named in S2).
- **Commerce off ⇒ no shop surfaces, no shop blocks rendered, nothing errors;** `InertnessTest`, `StorefrontInertnessTest` and `composer test:distribution` hold.
- **Pack boundaries:** commerce reaches layouts only through `Thallo\Contracts\Layouts\*` and the render pack; core and the render pack never name commerce; `composer boundaries` stays green.
- **Layout-only blocks are refused in entries, regions and saved sections by the server,** the four new ones included (§5.6).
- **Browser references are layout-determined.** No measurement depends on a glyph's advance width. Text-sized boxes (the title, count, chips, name, rating, price, navigation) are compared by height, top offset and computed style only. Widths and left offsets are compared only for boxes the layout sizes (the section, the grid tracks, cards, tiles, images). Seeded names in the parity pages are short enough that nothing ellipsizes or wraps at 375px, so CI's Linux fonts measure as macOS does. Long names get their own pages and proofs (overflow, ellipsis, accessible names: S1, S6), asserted by containment and computed style, never by a text width. When CI disagrees with a local measurement, **diagnose first**: find which property differs and why. A real regression is fixed. A difference that is only font metrics is re-expressed as a layout-determined assertion of the same behaviour, not dropped: font dependence alone does not make a useful assertion disposable. Never widen a tolerance.
- **The inspector tells the truth about the Product list's arrangement.** An untouched, switched or reset loop shows, on the Layout tab, the mode and tracks the page and the stage actually render (the declared theme defaults); a Removed layout's next session opens on the same truth.
- **An in-flight render never repopulates the shop cache with an old layout:** the sequence read old layout → commit and purge → old render finishes → next request always shows the new layout, for first save, edit and removal, on all three commerce surfaces, with workspace and surface isolation.
- **No compatibility shims:** a replaced API is replaced at every caller in the same task.
- **Every task's gates pass at its own commit.** Each user-visible change carries its CHANGELOG bullet under `## [Unreleased]` in the same commit (re-add the heading: the beta.69 cut removed it).
- **Gates**, run foreground and never concurrently:
  - PHP: `COMPOSER_PROCESS_TIMEOUT=0 composer test`, **also once with `API_USE_PREFIX=true`**; `vendor/bin/phpcs` (check the **exit code**); `composer boundaries`.
  - Tenancy: the proofs with `THALLO_TENANCY_DEV_LINK=1`, **each harness class in its own process**.
  - Admin: `pnpm exec oxfmt <touched files>` (through `xargs`), `pnpm type-check`, `pnpm lint`, `pnpm fmt:check`, `pnpm exec vitest run`.
  - e2e, for any admin or fixture change: `rm -rf admin/e2e/fixtures && CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures` (exit 0), then `cd admin/e2e && pnpm test`.
  - Browser proofs: `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-shop-layout-proof-fixtures` (exit 0), then `cd tools/runtime-browser && npx playwright test tests/shop-layout.spec.js`.
  - **Every fixture script also runs once on a freshly reset and migrated test database** (`composer test:reset-db && composer test:migrate`), as CI's jobs do. After `composer test:skeleton`, reset and migrate the test database again.
- `git diff` before every commit; no AI attribution trailers; never push, never tag. One release, one beta cut at the end when the user asks (the cut runs `php scripts/sync-docs-changelog` and includes `docs/reference/08-changelog.md`). The split, tags, pushes and the skeleton pin are the user's.

**Steps 1–4**, wherever a task says so, are the TDD cycle: write the failing tests named in the task, run each and watch it fail for the stated reason, implement the minimum, then run the file and the task's gates green. Step 5 is the commit.

## Review Focus

The inputs the spec implies but no requirement names, most likely to bite first. Each has its test in the owning task.

1. **A quick add from a card with JavaScript off, under a layout.** A single-variant product's tile form posts and lands the line in the cart, exactly as today's grid form. A multi-variant product's tile links to its product page. Neither carries a token. (Tasks S1, S3.)
2. **Commerce switched off with shop layouts saved, then on again.** Off: no shop rows, `GET /v1/admin/layouts` answers 200, and a block migration backfill walks the stored rows. On: both serve again, untouched. (Task S2.)
3. **`?page=` under a layout.** Page 2 renders its two cards and "Newer" to `/shop`. `?page=0`, `?page=abc` and `?page=1001` 404 before the controller. `?page=9` (past the last) renders what today's template renders for it. (Task S3.)
4. **Two workspaces, the tag separation, and a render that straddles a save.** A `shop_index` save in A evicts A's cached home pages only: A's category and product pages and all of B's pages stay hits. On a tag-less driver, only A's shop keys go. A render that read the old layout before the save committed never serves after it. Mode (c) proves each workspace renders its own layout. (Tasks S3, S4.)
5. **A category deleted, or emptied, while it is the stage's sample.** The next stage render is the placeholder, with the working copy intact. The deleted category's public page 404s under a layout as without one. (Task S4.)

---

## Shared contracts (named once, used by every task)

**Commerce (`packages/thallo-commerce`)**

- `Thallo\Commerce\Starter\ShopLayoutBlocksContributor implements StarterBlockTypeContributor`: the four definitions per the rulings, `sourceId: 'thallo-commerce:{slug}'`. It is registered by `registerShopBlockTypeContributor` beside `ProductFieldBlocksContributor`. `ProductFieldBlocksContributor`'s `product_name` gains `link` (boolean, default false).
- Templates:
  - `templates/blocks/{product_loop,shop_title,category_rail,product_tile}.twig`;
  - `templates/shop/_product_tile.twig`, extracted from `_product_card.twig` (context `product`, `show_tag`, `show_actions`);
  - `templates/blocks/product_{name,price,rating}.twig` gain the card branch (`item|default(null) is not null`).

  `product_loop.twig`: `{{ loop_cards(data.card|default([]), layout_context.products|default([]), 'product', 'card', 'shop-grid__item') }}` inside its `<ul>`.
- `ProductCardViewModel::toCardItem(): array` per the rulings.
- `Thallo\Commerce\Shop\ShopCatalogPage`:
  - `forIndex(string $tenant, int $page): array` → `{grid, categories, shop_index, canonical, layout_context}`;
  - `forCategory(string $tenant, array $category, int $page): array` → the same plus `category` (`CategoryViewModel`);
  - `placeholderIndex(): array`, `placeholderCategory(): array`.

  `buildGrid`, `categoryRail`, `indexPagePath` and `categoryPagePath` move here from the controller, verbatim.
- `Thallo\Commerce\Layouts\ShopIndexSurface` and `ShopCategorySurface` (`implements LayoutSurface, LayoutSampleContext`): keys `shop_index` and `shop_category`, target `@site`. `targets()` = `[{target: '@site', label, enabled: true, reason: null, link: null}]`. `palette()`, `required()`, `loops()`, `bindable()` `[]`, `pageTags()` `[]`, `frame()` `layouts/shop_index.twig` / `layouts/shop_category.twig`, and `starter()`, all per the rulings. Both carry `public const KEY` and `TARGET = '@site'`.
- `Thallo\Commerce\Layouts\ShopLayoutTags`: `SURFACES`, `pageTag()`, `tenantTag()`, `generationKey()` per the rulings; it replaces `ProductSurface::PAGE_TAG` and `pageCacheTag()`.
- `ShopCatalogController::index()` and `category()`: the selection, and `Cache-Tag: ShopLayoutTags::pageTag(...)`; they build through `ShopCatalogPage`.
- `ShopPageCache::surrogateTags()`: every `pageTag(s)` for `s` in `SURFACES` is stored as `tenantTag(s, $tenant)`. `ShopPageCache::handle(Request $request, callable $next, string ...$params)`: `$params[0]`, when present, is the route's surface, and its token (read before `$next()`, created with `setNx` and a re-read when missing) joins the key as `:{surface}g{token}`.
- `PurgeShopCacheOnLayoutChange`: acts for any surface in `SURFACES`; sets a fresh token at `generationKey(surface, tenant)`, then purges as before.
- Frames `templates/layouts/shop_index.twig` and `templates/layouts/shop_category.twig` per the rulings.
- `assets/shop.css`: the card-scoped heading rules (`.shop-grid__name:is(h1,h2,h3,h4)`, `.shop-grid__name a`), and the `thallo-field-empty` placeholder inside `.shop-index` and `.shop-category`. Nothing that matches today's no-layout markup changes.

**Tests' seed**

- `tests/Support/ShopPageSeed.php` is deterministic:
  - three categories with fixed names and slugs ("Mugs", "Bowls", "Vases");
  - 26 active products with fixed short names (≤ 12 characters), slugs, SKUs, prices and `created_at`, so the order is fixed and page 2 holds two. The first five are detailed:
    1. one with a cover (committed `tests/fixtures/commerce/product-cover.png`, stored as a blob **on the media disk**), a category and a review sum and count;
    2. one with a compare-at price;
    3. one multi-variant (options mode);
    4. one with a required add-on (options mode);
    5. one with no cover and no category.
  - "Vases" holds no product.
  - `ShopPageSeed::longNames()` (used only by the long-name proofs, never by the parity pages): a separate seed with a product named "Hand-thrown stoneware serving bowl with ash glaze, speckled finish" and a category named "Serving bowls, platters and large tableware", holding it and one short-named product.

  It uses `useTenant()`/`restoreTenant()` as `ProductPageSeed` does.

**Admin**

- `effectiveDisplay(block, breakpoint, classes, fallback: LayoutDisplay = 'flex')`, and the Layout tab reads the edited target's `style_targets.targets[target].defaults` (S5). Otherwise no source change is expected: the palette filter, card legality, reach-based copy and sample picker are B's and generic. A test that fails in S5 names the change, and it lands there.
- Container blocks keep their children in the `content` field, so a block beside the card's nested name has parent = the body container, field `content`.
- Test ids are reused. The rows are `layouts-row-shop_index-@site` and `layouts-row-shop_category-@site`.

---

## Task S0: freeze today's shop home and category pages

Before any template, CSS or controller change; its own commit.

**Files:**
- Create: `tests/Support/ShopPageSeed.php`, `tests/Integration/Commerce/ShopPagesGoldenTest.php`, `tests/fixtures/commerce/shop-page-{index,index-page2,category,category-empty}.html`, `scripts/build-shop-layout-proof-fixtures`, `scripts/capture-shop-layout-reference`, `tools/runtime-browser/tests/shop-layout.spec.js`, `tools/runtime-browser/references/shop-original.json`
- Modify: `.gitignore` (`tools/runtime-browser/fixtures/shop-layout/`), `.github/workflows/runtime-browser.yml` (the fixture step; `paths` gain `packages/thallo-commerce/templates/shop/**`, `packages/thallo-commerce/templates/layouts/shop_*.twig`, `packages/thallo-commerce/templates/blocks/**`, `packages/thallo-commerce/assets/shop.css`, the two scripts, `tests/Support/ShopPageSeed.php`)

**Interfaces:** Produces `ShopPageSeed` and the frozen references.

- [ ] **Step 1:** `ShopPageSeed` per Shared contracts.
- [ ] **Step 2:** `ShopPagesGoldenTest` fetches `/shop`, `/shop?page=2`, `/shop/categories/mugs` and `/shop/categories/vases` through the kernel. Each body is normalized by one explicit map, with every substitution **counted and pinned**:
  - uuids → `{product:n}`, `{variant:n}`, `{category:n}`;
  - blob URLs → `{blob:n}`;
  - fingerprinted and `?v=` asset URLs → `{asset:name}`;
  - the wishlist scope → `{scope}`.

  It compares markup parity against the four goldens (recorded with `THALLO_RECORD_SHOP_GOLDEN=1`) and the asset-name set, reported separately. `testTheGoldenSurvivesAFreshRebuild` reseeds with new uuids and compares again. It is green on today's templates.
- [ ] **Step 3:** The fixture script writes `shop-index-original.html` and `shop-category-original.html` (`/shop/categories/mugs`). It renders inside a rolled-back transaction, inlines the stylesheets and images as `build-listing-layout-proof-fixtures` does, and exits 1 on any throw. It runs green **on a freshly reset and migrated database**.

  The capture script measures both pages with `measure.js` at its widths (375, 700, 800, 1280), twice, refuses to write when the two measurements differ, and writes `shop-original.json`. The measured elements are:
  - the section;
  - the title row, its heading and count;
  - the rail and its first chip;
  - the grid;
  - the first, second and fifth cards: tile, image, tag, actions, name, rating, price;
  - the navigation;
  - on the home only, the empty page is not measured.

  Each property follows the layout-determined rule in Global Constraints. The browser test is `today's shop home and category pages match their frozen reference`.
- [ ] **Step 4:** the golden, the browser test and the fixture script on a fresh database are green; full suite; phpcs.
- [ ] **Step 5:** commit `test(commerce): freeze today's shop home and category pages` (changelog: none). If CI's Chromium disagrees with the local capture, **diagnose first**: name the property and element that differ and why. A regression is fixed. A difference that is only font metrics is re-expressed as a layout-determined assertion of the same behaviour (containment, alignment, computed style), recorded in the spec's header comment. An assertion is never dropped just because it depends on fonts, never recaptured around, and a tolerance is never widened.

## Task S1: the shop layout blocks, and product blocks in a card

**Files:**
- Create: `packages/thallo-commerce/src/Starter/ShopLayoutBlocksContributor.php`, `packages/thallo-commerce/templates/blocks/{product_loop,shop_title,category_rail,product_tile}.twig`, `packages/thallo-commerce/templates/shop/_product_tile.twig`
- Modify: `packages/thallo-commerce/templates/shop/_product_card.twig` (includes the tile; markup unchanged), `packages/thallo-commerce/templates/blocks/product_{name,price,rating}.twig` (the card branch; `name` gains `link`), `packages/thallo-commerce/src/Starter/ProductFieldBlocksContributor.php` (`link`), `packages/thallo-commerce/src/Shop/ViewModels/ProductCardViewModel.php` (`toCardItem`), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (the contributor), `packages/thallo-commerce/assets/shop.css` (per Shared contracts)
- Also modify: `packages/thallo-contracts/src/Style/StyleTargets.php` (`defaults` on a target: validated, exposed by `defaults()`), `core/src/Content/Blocks/StarterBlockTypes.php` (`entry_loop`'s `cards` declares `{display: 'flex'}`), `packages/thallo-render/src/Style/BlockStyleEmitter.php` (`columns-theme` for a target declaring default columns), `packages/thallo-render/src/Style/StyleCompiler.php` (`theme`: an empty rule, paired like `auto` in `spanRules()`)
- Test: `tests/Unit/Style/StyleTargetsDefaultsTest.php`, `tests/Integration/Render/LayoutColumnsThemeStateTest.php`, `tests/Integration/Commerce/ProductLoopRenderTest.php`, `tests/Integration/Commerce/ShopLayoutBlocksRenderTest.php`, `tests/Integration/Commerce/ShopLayoutBlocksProvisioningTest.php`, additions to `tests/Integration/Content/LayoutOnlyBlocksTest.php`, `tests/Integration/Content/BlockStyleDeclarationsTest.php` (`NEVER_HIDDEN` gains `product_loop`), `tests/Integration/Commerce/ProductFieldBlocksRenderTest.php` (the product page's markup unchanged; `link`)

**Interfaces:** Consumes B's `loop_cards` and `item` forwarding, and C1's `layout_context`. Produces the four slugs, the card branch, `toCardItem`, and `_product_tile.twig`.

- [ ] **Step 1: failing tests:**
  - `ProductLoopRenderTest` renders through `layout_blocks()`. The frame context holds `layout_context.products`: `ShopPageSeed`'s first page, built by `ShopProductCardAssembler` and `toCardItem()`. The starter card is `product_tile`, then a container holding `product_name {level: h2, link: true}`, then a container holding `product_rating` and `product_price`.
    - **Scope `none`:** 24 `<li class="thallo-loop-card shop-grid__item">` inside `<ul class="thallo-block-product_loop__cards shop-grid">`, in order. Each card holds its product's tile (image or empty panel, chip when it has a category), its linked name in an `h2.shop-grid__name`, the single-star rating with "(n)", and the price with the struck compare-at on product 2. No annotation attributes.
    - **Scope `layout`:** the first `<li>` carries `data-thallo-slot="card"` inside the loop's `data-thallo-block` wrapper. The other 23 carry `data-thallo-card-copy`. The first card's blocks, containers included, carry their ids once; every id appears exactly once.
    - **Empty products:** scope `none` prints `<li class="empty">No products yet.</li>` inside `ul.shop-grid`, and "No products in this category yet." when `layout_context.category` is set; a non-blank `empty_text` wins. Scope `layout` renders one annotated card for `placeholder_item` ("Sample product").
    - **Review Focus 1:** the tile of product 1 (direct mode) holds `<form class="shop-grid__cart-form" method="post" action="/_shop/cart/add">` with its variant's `variant_uuid` and `quantity` 1. Products 3 and 4 hold the options link to their product page. No card holds a CSRF or cart token. `tag: false` drops the chip; `actions: false` drops the form, link and heart.
    - `item` and `layout_context.product` reach a block two containers deep in the card.
    - **Long names** (`ShopPageSeed::longNames()`): the card's `h2.shop-grid__name` holds one `<a>` whose text is the **full** name (no server-side truncation); the tile's form button reads `aria-label="Add {full name} to cart"` (or the options link `View options for {full name}`), and the wishlist heart `Save {full name} to wishlist`; the tag holds the full category name.
  - `LayoutColumnsThemeStateTest` (render): the Product list's `cards` target untouched emits `columns-theme` at every breakpoint and **no** `columns-auto`, and the compiled rule for `columns-theme` holds no declaration. A plain container untouched still emits `columns-auto`, compiled to `grid-template-columns: none` as today. A span on a child of a `columns-theme` parent clamps as it does under `auto` (the span rule table for `theme` equals `auto`'s). The compiled stylesheet for every existing block type is byte-identical to before (the existing style snapshot tests green unchanged). An explicit `reset` of columns on the Product list over a style class declaring `layout.columns: 3` emits the reset class after the class's value, and the compiled reset is `grid-template-columns: revert-layer`.
  - `StyleTargetsDefaultsTest` (unit): `product_loop`'s declaration exposes `defaults('cards')` = the ruled grid defaults, and `entry_loop`'s `{display: 'flex'}`; a declaration with an unknown `defaults` key, or a `display` other than flex or grid, throws `InvalidArgumentException`; a target without `defaults` answers null; the block types API returns `style_targets.targets.cards.defaults` for both loops.
  - `ShopLayoutBlocksRenderTest`:
    - `shop_title` prints "Shop" with no category and the category's name with one, at its level, with "26 products" (and "1 product" for one); `count: false` drops the count.
    - `category_rail` marks "All" active (`aria-current="page"`) with no category, and the category's own chip otherwise. `all_label` renames "All". It prints nothing with no categories (scope `layout`: the named placeholder).
    - `pagination` (B's block) over `ShopCatalogPage`'s pagination prints `_pagination.twig`'s markup for `/shop?page=2`.
    - Each block's root carries `style_classes('root')`, and `shop_title` also `style_classes('heading')` on its heading.
  - `ProductFieldBlocksRenderTest` additions: `product_name`, `product_price` and `product_rating` outside a card render C1's markup byte for byte (existing assertions green); `product_name {link: true}` outside a card wraps the name in the product's link.
  - `ShopLayoutBlocksProvisioningTest`: a fresh tenant with commerce on gets the four (Fields, `layout_only`). `thallo:provision` on an existing site adds them and gives `product_name` its `link` setting. With commerce off they are hidden (the reconciler's existing behaviour).
  - `LayoutOnlyBlocksTest::testShopLayoutBlocksBelongToLayouts`: an entry draft, a region save and a saved section containing `product_loop` (or `product_tile`) are refused at the block's path.
- [ ] **Step 2:** run each — fail (no blocks, no card branch).
- [ ] **Step 3:** implement. Extract `_product_tile.twig` first and keep S0's golden green before writing any block template.
- [ ] **Step 4:** green. S0's golden and browser test stay green (no-layout markup and look unchanged); `ShopBlocksTest`, `ShopCatalogTest`, `NoJsAddToCartTest`, `StorefrontWalkTest` and the C1 and B render tests are green unchanged; full suite; phpcs; boundaries.
- [ ] **Step 5:** commit `feat(commerce): the Product list and its card, the shop title, the category chips and the product tile` (changelog: none yet; the feature bullet lands with S4).

## Task S2: the shop home and category surfaces

**Files:**
- Create: `packages/thallo-commerce/src/Shop/ShopCatalogPage.php`, `packages/thallo-commerce/src/Layouts/{ShopIndexSurface,ShopCategorySurface,ShopLayoutTags}.php`
- Modify: `packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php` (`index()` and `category()` build through `ShopCatalogPage`; the moved private methods go), `packages/thallo-commerce/src/Layouts/ProductSurface.php` (`PAGE_TAG` and `pageCacheTag` removed), every caller of those two (`ShopCatalogController`, `ShopPageCache`, `PurgeShopCacheOnLayoutChange`, `tests/Integration/Commerce/ProductLayoutCacheTest.php`), `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (services; `registerLayoutSurface` registers all three)
- Test: `tests/Integration/Commerce/ShopIndexSurfaceTest.php`, `tests/Integration/Commerce/ShopCategorySurfaceTest.php`, `tests/Unit/Commerce/ShopLayoutTagsTest.php`, S0's golden (still green)

**Interfaces:** Consumes S1 (slugs, `toCardItem`). Produces `ShopCatalogPage`, both surfaces and `ShopLayoutTags`.

- [ ] **Step 1: failing tests:**
  - `ShopIndexSurfaceTest`:
    - `testTheSurfaceIsRegisteredWhileCommerceIsOn`: `get('shop_index')` is a `ShopIndexSurface`, and `GET /v1/admin/layouts` has `{surface: 'shop_index', target: '@site', label: 'Products — shop home', reach: 'Applies to every page of the shop home', state: 'theme'}`.
    - `samples()` is `[{id: '1', label: 'Page 1'}]` with products and `[]` with none.
    - `sampleContext('@site', '1')`: `layout_context.products` holds page 1's 24 card items (arrays, camelCase keys, in `listActive` order), `total` 26, `pagination` page 1 of 2 with `next_path` `/shop?page=2`, `categories` the rail with nothing active, `category` null. It is null once no product is active.
    - `placeholderIndex()`: no products, one page, `placeholder_item` "Sample product" with a formatted price in the store currency; product and variant counts unchanged.
    - `palette()`, `required()` `[{type: 'product_loop'}]`, `loops()`, `pageTags()` `[]`, `frame()`.
    - `testTheStarterIsTodaysPage`: `starter('@site')` is exactly the ruled tree, and it passes `LayoutValidator::validate('shop_index', '@site', …)` unchanged.
    - Review Focus 2, `testCommerceOffHidesTheSurfacesAndKeepsTheLayouts`: save a `shop_index` and a `shop_category` layout (repository), then boot with `thallo.commerce` off (the `InertnessTest` arrangement). There are no shop rows, `GET /v1/admin/layouts` answers 200, and a block migration backfill walks both rows. Boot with commerce on again: both rows read **Custom layout** and `LayoutReader::for(…)` returns the saved blocks.
  - `ShopCategorySurfaceTest`:
    - `targets()` and labels per the rulings.
    - `samples()` lists Mugs and Bowls, not Vases (no products), in the rail's order; `q` filters by name.
    - `sampleContext('@site', $mugsUuid)` holds Mugs' products, `category` `{name: 'Mugs', slug: 'mugs', url: '/shop/categories/mugs'}`, and the rail with Mugs active. It is null for Vases, for a deleted category and for an unknown uuid.
    - `placeholderCategory()` has "Sample category" and no active chip.
    - The starter is as ruled and validates.
  - `ShopLayoutTagsTest`: `pageTag('product')` is `thallo:shop:layout:product` (C1's value, unchanged), `tenantTag('shop_index', 't1')` is `thallo:shop:layout:shop_index:t1`, and `SURFACES` holds the three.
  - `testTheControllerStillRendersTheGolden`: S0's golden is green after the move into `ShopCatalogPage`.
- [ ] **Step 2:** run — fail.
- [ ] **Step 3:** implement. Move the controller's page building into `ShopCatalogPage` verbatim first, and rerun the golden before adding the surfaces. `ShopLayoutTags` replaces `ProductSurface::PAGE_TAG` and `pageCacheTag` at every caller in the same change.
- [ ] **Step 4:** green; `ProductSurfaceTest`, `ProductLayoutCacheTest`, `StorefrontInertnessTest` and `InertnessTest` green; `composer test:distribution`; full suite; phpcs; boundaries.
- [ ] **Step 5:** commit `feat(commerce): the shop home and category pages are layout surfaces` (changelog: none yet).

## Task S3: shop pages render through their layout

**Files:**
- Create: `packages/thallo-commerce/templates/layouts/shop_index.twig`, `packages/thallo-commerce/templates/layouts/shop_category.twig`
- Modify: `packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php` (selection; the surface tag on every home and category page), `packages/thallo-commerce/src/Shop/ShopPageCache.php` (`surrogateTags` over `SURFACES`; the per-surface generation in the key, read before `$next()`), `packages/thallo-commerce/src/Layouts/ShopLayoutTags.php` (`generationKey`), `packages/thallo-commerce/src/Shop/Listeners/PurgeShopCacheOnLayoutChange.php` (any surface in `SURFACES`; advances the generation before purging), `packages/thallo-commerce/routes/shop-routes.php` (each layout route passes its surface to the cache middleware)
- Test: `tests/Integration/Commerce/ShopLayoutRenderTest.php`, `tests/Integration/Commerce/ShopLayoutCacheTest.php`

**Interfaces:** Consumes S1 and S2. Produces live selection and the per-surface purge.

- [ ] **Step 1: failing tests.** `ShopLayoutRenderTest` saves the starter through `LayoutSaver` unless a test says otherwise.
  - `testNoLayoutRendersTodaysPages`: every S0 golden holds, and each response's `Cache-Tag` holds `thallo:shop:layout:shop_index` or `thallo:shop:layout:shop_category`.
  - `testALayoutRendersTheHomeAndEveryCategoryThroughTheFrame`:
    - `/shop` renders through `layouts/shop_index.twig`: `section.shop-index.shop-index--layout` with `data-shop-scope`, the title "Shop" with "26 products", the rail with "All" active, 24 cards in order with the card's blocks two containers deep, and the navigation to page 2.
    - There is **no** `<link rel="stylesheet" href="/_thallo/shop/shop.css">`; the layered theme artifact is linked as on every page.
    - The canonical `<link>` is byte-identical to the theme page's.
    - `/shop/categories/mugs` renders the category frame with "Mugs", its products and Mugs' chip active.
    - `/shop/categories/vases` renders "No products in this category yet.".
    - A heading added to the category layout appears on Mugs and Bowls.
  - Review Focus 3, `testPagesUnderALayout`: `/shop?page=2` renders two cards and "Newer" to `/shop`. `?page=0`, `?page=abc` and `?page=1001` answer 404 through the cache middleware. `?page=9` renders the same product list and navigation today's template renders for it (asserted against the no-layout response's grid content). An unknown category answers the themed 404.
  - Review Focus 1, `testTheQuickAddWorksWithoutJavaScript`: under the starter, product 1's tile form posts (the `NoJsAddToCartTest` request, CSRF-guard origin included) and answers as today's grid form does, with the line in the cart. Products 3 and 4 link to their product pages.
  - `testTheFrameSettingsApply`: Frame `width: full`, `footer: hidden` renders the full-width presentation and no footer; with no Frame settings the presentation is today's (`FramePresentation::fixed`).
  - `testCommerceOffLeavesNoShopPageToRender`: commerce off with layouts saved; `/shop` is not a shop route and nothing reads the shop layouts.
- [ ] **Step 1b: failing tests.** `ShopLayoutCacheTest` covers Review Focus 4 in the default suite, with the `ProductLayoutCacheTest` arrangement (tenants `tnta` and `tntb`, the shop cache populated through `ShopPageCache`):
  - `testEachChangeRefreshesOnlyItsSurfaceInTheSavingTenant`: for each of first save, edit and removal of A's `shop_index` layout, A's next `/shop` request is a miss that renders the new state. A's cached `/shop/categories/mugs` and product page, and all of B's pages, are still hits and byte-identical. The same holds for `shop_category`, where A's home and product pages survive.
  - `testATaglessDriverFallsBackToTheTenantsOwnShopKeys`: a `LayoutChanged` for `('shop_category', '@site')` in `tnta` deletes `shop:tnta:*` and `tenant:*:shop:tnta:*` only.
  - `testEntryAndListingChangesLeaveTheShopAlone`.
  - `testARenderStraddlingASaveNeverRepopulatesTheOldLayout` — for each surface (`product`, `shop_index`, `shop_category`) and each change (first save, edit, removal). The pinned sequence: a request enters `ShopPageCache` and its controller reads the old state (a `LayoutReader` double that, on its first read, runs the change through `LayoutSaver` before answering the **old** layout, so the save commits and `LayoutChanged` purges in between); the request finishes and stores its response. The next request must be a miss that renders the new state, and a third request is a hit of the new state. Run on the array driver (tag-capable) and on a tag-less double.
  - `testTheGenerationIsPerWorkspaceAndPerSurface` — advancing A's `shop_index` token leaves the cache keys of A's category and product pages, and of all B's pages, unchanged (still hits); the wishlist page's key carries no token.
  - `testTheFallbackPurgeNeverDeletesAGenerationToken` — every surface's `generationKey` for `tnta` and `tntb` matches neither `shop:tnta:*` nor `tenant:*:shop:tnta:*` (glob-matched as the drivers match), and after a tag-less fallback purge in `tnta` every token key still holds its value.
  - `testALostTokenNeverServesItsEntries` — cache `/shop` under a real token (read back from the store, not assumed), delete that token key, request again: a miss under a new token, different from the lost one; restore the lost token's old entry by hand under its old key and request again: the old entry is not served. Repeated for an expired token (a driver double whose TTL has lapsed).
  - `testInitializationRacingASave` — both orders, driven by a `CacheStore` decorator that runs a hook inside `setNx`: (a) the save's `LayoutChanged` sets its token **before** the initializer's `setNx`, so the `setNx` loses, the request re-reads the save's token and renders the new layout; (b) the initializer's `setNx` wins, then the save commits and sets a fresh token before the render stores, so the stored entry is unreachable and the next request renders the new layout. Two concurrent initializers (the hook calls the middleware's initializer re-entrantly) end up using one token.
- [ ] **Step 2:** run — fail (no selection, no tag, and the straddling render serves the old layout on the next request).
- [ ] **Step 3:** implement per the rulings.
- [ ] **Step 4:** green; `ShopCatalogTest`, `ShopCacheTest`, `ProductLayoutRenderTest`, `ProductLayoutCacheTest` and the wishlist tests green unchanged; full suite (and with `API_USE_PREFIX=true`); phpcs; boundaries.
- [ ] **Step 5:** commit `feat(commerce): the shop home and category pages render through their layout` (changelog: none yet).

## Task S4: the shop stage, Save and Remove

**Files:**
- Modify: `CHANGELOG.md`; `packages/thallo-render/src/Http/Controllers/RenderController.php` only if a stage test shows the non-core branch mishandles these surfaces (none expected)
- Test: `tests/Integration/Commerce/ShopLayoutStageTest.php`, `tests/Integration/Commerce/ShopLayoutSaveTest.php`, `tests/Integration/Commerce/ShopLayoutRoutesTest.php`, `tests/Integration/Tenancy/ShopLayoutTenancyTest.php` (opt-in)

**Interfaces:** Consumes S2 and S3. With this task the feature is usable end to end.

- [ ] **Step 1: failing tests:**
  - `ShopLayoutStageTest`:
    - A `shop_index` session renders page 1's cards around the working copy. The first card's blocks are selectable once, the second card's not, and no id repeats (24 cards).
    - A `shop_category` session renders the chosen category.
    - A shop with no products opens on the placeholder: one annotated placeholder card, the notice "No published products yet — showing a placeholder", nothing written.
    - Review Focus 5: emptying the sample category mid-session renders the placeholder with the working copy intact. So does deleting it, and `/shop/categories/{its slug}` then answers 404 under the layout.
    - The stage's presentation equals the site's for the same Frame.
    - A retired session renders the removal page.
    - There is no raw `shop.css` link on the stage.
  - `ShopLayoutSaveTest`:
    - Release A's contract for `shop_index` and `shop_category` at `@site`: first save 1, stale save 409 with nothing written, remove tombstones and retires the session, and reopening continues the version.
    - A layout without `product_loop` is refused ("the layout must show the Product list block"), and one with two is refused at the second's path.
    - `product_name` at the root is refused ("goes inside the Product list's card").
    - `pagination` inside the card is refused.
    - A container holding `product_loop` hidden at a size is refused (C1's rule).
    - `entry_title` in a shop layout, and `product_loop` in a listing layout, are each refused at their path.
    - A `shop_index` save does not change the product layout's version, and a product save does not change the shop layouts' versions.
  - `ShopLayoutRoutesTest`: through the real kernel with an API key (the `ProductLayoutRoutesTest` arrangement): session, samples, `PUT /v1/admin/layouts/shop_category/%40site`, `GET /v1/admin/layouts` and `DELETE`. A caller without `templates.manage` is refused 403.
  - `ShopLayoutTenancyTest` (retrofit harness, `THALLO_TENANCY_DEV_LINK=1`), Review Focus 4 in mode (c): workspaces A and B each save a `shop_index` layout at version 1 and render their own. A's save leaves B's cached home a hit.
- [ ] **Step 2:** run. Expect failures only where a gap exists: most of the lifecycle passes on Release A's engine, and this test proves it at these targets.
- [ ] **Step 3:** implement any gap. Add the changelog bullet under `## [Unreleased]` → `### Added`: **Layouts for the shop home and category pages**:
  - Site › Layouts has **Products — shop home** and **Products — shop categories**;
  - design the page once around the Product list, whose card you design once for every product, from the product tile, name, rating and price;
  - Save applies to every page;
  - with no layout the pages are as today;
  - commerce off hides the rows;
  - on an existing site, run `thallo:provision` for the new blocks (with workspaces on, also `thallo:tenant:sync --all --kind=block_type`).
- [ ] **Step 4:** green (the tenancy class in its own process); the C1 and B stage and save tests green; full suite (and with `API_USE_PREFIX=true`); phpcs.
- [ ] **Step 5:** commit `feat(layouts): design the shop home and category pages on the stage` (changelog: the bullet).

## Task S5: the editor and its browser proof

**Files:**
- Modify: `scripts/build-builder-proof-fixtures`, `admin/e2e/helpers.ts`
- Create: `admin/e2e/tests/shop-layout-stage.spec.ts`, `admin/e2e/shop-scenarios.json` (`{name, layout}`, as `listing-scenarios.json`)
- Modify: `admin/src/editor/inspector/layoutContext.ts` (`effectiveDisplay`'s and `dormantPaths`' `fallback`), `admin/src/editor/inspector/LayoutTab.vue` (passes the edited target's declared `defaults.display`; shows the declared columns label and gaps as the theme default), `admin/src/queries/blockTypes.ts` (the `defaults` type on `style_targets`)
- Modify only if another test below fails on it: `admin/src/**` (the change is named in the ledger)
- Test: `admin/src/__tests__/layout-editor.spec.ts`, `admin/src/__tests__/layout-context.spec.ts`, `admin/src/__tests__/layout-tab.spec.ts` (additions)

**Fixture section.** A shop section inside the rolled-back transaction:
- seed with `ShopPageSeed`;
- mint `shop_index` and `shop_category` sessions through `LayoutPreviewController::session`;
- apply every scenario and render each canvas through `/_preview/{token}?canvas=1`;
- write `shop-session.json`, `shop-samples.json`, `shop-stages.json` and `shop-stage-{baseline,tile-rated,nested-rated,nested-after,empty-card,placeholder,grid,flex,cleared,class-three,class-reset}.html`. The scenarios:
- `cleared` is the `grid` scenario with the loop's declarations deleted again;
- `class-three` gives the Product list a style class declaring `layout.display: grid` and `layout.columns: 3`;
- `class-reset` is `class-three` with an **explicit** reset of `layout.columns` on the instance at every breakpoint (a stored reset value, not a deletion).

**Remove is a separate path:** save `class-three`, remove the layout through `LayoutAdminController::destroy`, mint a fresh session, and render its canvas into `shop-stage-after-remove.html`.

It runs green on a freshly migrated database. `LAYOUT_WORLDS` gains `shop` and `shop-placeholder`.

**Interfaces:** Consumes S4.

- [ ] **Step 1: failing tests:**
  - `layout-editor.spec`, with a `shop_index` session (palette per the rulings, loops, `required: [{type: 'product_loop'}]`):
    - the Blocks tab offers the general blocks and exactly the palette (no `product_buy`, no `entry_title`);
    - the `product_name` tile is disabled with "Product name goes inside the Product list's card" while the root is the target;
    - deleting the Product list is refused with "Every page of the shop home shows its Product list here, so the layout keeps this block. Move it instead.";
    - a working copy without it disables Save.
  - `layout-context.spec`: `effectiveDisplay(block, bp, [], 'grid')` answers `grid` for an unset display, after a reset, and for an unoffered stored value; a declared `flex` still answers `flex`; the existing default-`flex` cases stay green. `dormantPaths(block, bp, [], 'parent', 'grid')` on an untouched block carrying `layout.direction` reports `layout.direction` dormant and never `layout.columns`; with the fallback omitted, today's answers stand.
  - `layout-tab.spec`, the Product list selected, its `cards` target declaring the grid defaults:
    - untouched: the mode reads Grid, Columns shows "Theme default: Adaptive — as many 15rem columns as fit", and the gaps show 1.75rem and 1.5rem as their placeholders; no Flex-only row (direction, wrap);
    - switched to Flex: the Flex rows show and Columns is dormant;
    - deleted back (the `cleared` state): exactly the untouched state;
    - explicit reset over a style class declaring three columns: Columns shows the theme default, not the class's 3;
    - `entry_loop` untouched still reads Flex (its declared default).
  - e2e `shop-layout-stage.spec.ts`, against the real renderer's fixtures:
    - the first card's name selects and opens the Block tab naming "Product name"; a copy card's name selects the Product list;
    - **direct card insertion:** `product_rating` dragged onto the lower part of the first card's **tile** (aim 0.85, as the listing spec aims): the recorded apply puts the new block at **parent = the Product list's id, field `card`, index 1** (after the tile, before the body container), and the refreshed stage (`tile-rated`) shows it in every card;
    - **nested drop beside the name (the bridge's existing grid behaviour):** `product_rating` dragged over the first card's name. The body container is a Grid, which the bridge classifies as an `other` layout and **appends** to. So the recorded apply puts it at **parent = the body container's id, field `content`, index 2**, after the meta container. The drop line shows the `other` style, and the refreshed stage (`nested-rated`) shows the rating last in every card's body. The bridge is not changed for this fixture.
    - **placement right after the name, by insertion action:** select the first card's name, then click the **Product rating** tile in the Blocks tab (the "after the selected block" insertion target, `palette/target.ts` `{kind: 'after'}`). The recorded apply puts it at **parent = the body container's id, field `content`, index 1**, and the refreshed stage (`nested-after`) shows it between the name and the meta row in every card;
    - nothing drops over a copy card;
    - `product_price` dropped into an empty card, and into the placeholder world's card, lands at index 0;
    - `product_name` dragged to the root is refused with the reason; deleting the Product list is refused with the reach copy;
    - **the card boundary and inner controls:** selecting the tile (directly in the card) shows no item controls; selecting the name shows the **grid-item** controls of its immediate parent, the body container (span, align self). With the outer Product list on the `grid` scenario and then the `flex` scenario, the tile still has no item controls and the name's controls are unchanged, because the outer loop's mode does not reach them. The Product list's Layout tab reads "Arrange the cards";
    - **the loop's truth on the stage:**
      - On `baseline` (untouched), the Product list's Layout tab reads Grid with the adaptive theme default, and the stage's cards sit in the adaptive tracks.
      - The expected first-row count is **derived, never hard-coded**: the list's measured content width `w`, its computed `column-gap` `g` and the track minimum from its computed `grid-template-columns` give `floor((w + g) / (min + g))`, and that must equal the number of cards sharing the first card's top.
      - On `flex` the tab reads Flex and the cards are a flex row.
      - On `cleared` the tab and the stage are back to `baseline`'s.
      - On `class-three` the tab reads 3 columns (from the class) and three cards share a row.
      - On `class-reset` the tab shows the theme default and the stage is back to the adaptive count, so the explicit reset beats the class.
      - `after-remove` shows the untouched truth again.
- [ ] **Step 2:** run — the e2e fails (no fixtures). The vitest either passes on B's generic code, which proves it, or fails and names the change.
- [ ] **Step 3:** implement the fixture section, the helpers and any admin change a test named.
- [ ] **Step 4:** the fixture script on a fresh database exits 0 and writes every file; oxfmt on touched files; `pnpm type-check`; `pnpm lint`; `pnpm fmt:check`; vitest; the full e2e suite; the PHP suite (the fixture section is PHP).
- [ ] **Step 5:** commit `test(layouts): the shop layout editor, proven in a browser` (use `feat(layouts): …` instead if admin source changed; changelog: fold into S4's bullet only if wording changes).

## Task S6: the starter and authored styles, measured in a browser

**Files:**
- Modify: `scripts/build-shop-layout-proof-fixtures` (the states below, page and stage), `tools/runtime-browser/tests/shop-layout.spec.js`, `packages/thallo-commerce/assets/shop.css` (only the fixes the user rules)

- [ ] **Step 1:** add the states and tests:
  - **The starter against the frozen reference:** the home and category starters on the page and on the stage, compared with `shop-original.json` (layout-determined properties only, per Global Constraints).
  - **Authored values win on the page and the stage:**
    - `shop_title`'s heading: typography size `3xl`, colour `accent`;
    - `product_name` in the card: size `lg`;
    - `product_price`: colour `muted`;
    - `product_loop`'s `cards`: display grid, columns base `1` and md `3`, gaps `spacing.lg`. The cards sit three to a row at 800 and 1280 and one at 375, and a card's content keeps its own flow.
  - **Removing the values returns the defaults.**
  - **The loop's arrangement, page and stage (user, 2026-09-28):**
    - An untouched Product list renders the adaptive grid on the page and the stage. At every width the first-row card count equals `floor((w + g) / (min + g))`, computed from the list's measured content width `w`, its computed `column-gap` `g` and the track minimum parsed from its computed `grid-template-columns`. No per-viewport count is written down.
    - The untouched layout's list has the same computed `grid-template-columns` and gaps as the no-layout page's `.shop-grid`.
    - Switched to Flex, the cards are a flex row on both.
    - **Deleted back** (`cleared`), both return to the untouched measurements exactly.
    - **An explicit reset over a conflicting class** (`class-three`, then `class-reset`): the class gives three tracks, and the reset returns both page and stage to the adaptive count and the theme's computed tracks.
    - **Remove** is its own state: the saved `class-three` layout removed, the public page is the theme page (its grid equal to S0's reference), and the next stage opens on the untouched truth.
  - **Long names** (a `long-names.html` page and stage from `ShopPageSeed::longNames()`, with and without a layout): the name box stays inside its card (its right edge ≤ the card's), computes `text-overflow: ellipsis`, `white-space: nowrap`, `overflow: hidden`, and its `scrollWidth` exceeds its `clientWidth` (it is truncated visually, not wrapped); no card overflows its grid track and the page has no horizontal scroll at any width; the name link's accessible name (`page.getByRole('link', {name: fullName})`) is the full name, and the heading's too; the long category chip's behaviour in the tile is recorded as today's: whatever the no-layout page does, the layout page does the same (both measured by containment, and any overflow found on both is listed for the user, not silently asserted).
  - **Hover and focus:** the tile's actions reveal on a card's hover and focus-within on the page, as today.
  - **The no-layout pages still match** (S0's test, unchanged).
- [ ] **Step 2:** run, and **list every measured difference between the starter and today's pages** (page, element, property, widths). **Stop and bring the list to the user.** Each difference is either accepted, recorded as a relax-and-assert row as C1's accepted geometry is, or fixed in `shop.css` with S0's comparison staying green. The card name's `h2` is on the list, whatever it measures. No tolerance is widened.
- [ ] **Step 3:** implement the user's rulings.
- [ ] **Step 4:** the shop spec, the product and listing specs, and the fixture script on a fresh database are green; the PHP suite.
- [ ] **Step 5:** commit `test(commerce): the shop starters and authored styles, measured in a browser` (changelog: none; a CSS fix must not change a page without a layout).

## Task S7: docs

**Files:**
- Modify:
  - `docs/guides/20-layouts.md`: a **Shop home and category pages** section covering: open the two rows; the Product list and its card; which product blocks go in the card and how they show there; the four new blocks and their settings in a table; the no-JS quick add; what the frames keep; what commerce off does; provisioning.
  - `docs/guides/18-commerce.md`: one paragraph pointing to that section.
  - `docs/reference/04-block-library.md`: the four rows in **Fields**, marked Commerce; `product_name`'s `link`; which product blocks go inside a Product list card; the intro count.
  - `packages/thallo-render/docs/THEMING.md`: the two frames; what an override keeps (canonical, `data-shop-scope`, `layout_context`, no raw stylesheet link).
  - `packages/thallo-commerce/README.md`: the surfaces and the blocks.
  - `CHANGELOG.md`, if S4's bullet needs final wording.
- Test: `tests/Unit/Docs` (green)

- [ ] **Steps 1–4:** write; `vendor/bin/phpunit tests/Unit/Docs`; the docs' commands checked against the code.
- [ ] **Step 5:** commit `docs(layouts): design the shop home and category pages`.

## Final

- [ ] The full gates in order: the PHP suite twice (default and `API_USE_PREFIX=true`); every tenancy harness class in its own process; phpcs; boundaries; the admin gates; e2e from a fresh database; the shop, product and listing browser specs; docs tests; `composer test:distribution`.
- [ ] A fresh-context review of the whole branch against the spec and this plan's Review Focus; one fix pass, each fix RED→GREEN with the suite green.
- [ ] The beta cut when the user asks.

---

## Self-review

- **Spec coverage (C2):**
  - §1: the `shop_index` and `shop_category` rows, target `@site`, samples "—" (one page sample) and "a category with products" (S2).
  - §3: `targets`, `samples`, `palette`, `required` `product_loop`, `loops`, frames, starters (S2); registration while the capability is on (S2).
  - §4: `item` inside a card (S1, B's forwarding).
  - §5.2: the placeholder with its one card, and a vanished sample (S2, S4).
  - §5.4: the first card annotated, the rest copies, the placeholder card (S1, S4, S5).
  - §5.5: Save/Remove at `@site` (S4).
  - §5.6: item-scoped blocks only in a `product_loop` card, required and page blocks never in one, layout-only blocks refused elsewhere (S1, S4).
  - §5.7: no content-model coupling (targets are `@site`; a deleted category only loses its page, S4).
  - §6.1: the rows while the capability is on (S2). §6.2: the editor's palette, card rules and copy (S5).
  - §7.1: frames in the commerce pack, overridable (S3, S7).
  - §7.3: selection in the shop render for the two remaining surfaces (S3).
  - §7.4: a surface tag on every commerce page (per workspace, by C1's ruling) and the `LayoutChanged` purge for all three (S2, S3).
  - §8: rendering, stage and browser proofs (S3–S6).
  - §11 item 4 complete.
- **Placeholders:** none. S6's CSS depends, by design, on the user's ruling on measured differences, and so does the card name's `h2`, flagged in the rulings.
- **Type consistency:** these are used identically in every task:
  - `loops()` rows `{type: 'product_loop', card: 'card', items: [product_tile, product_name, product_rating, product_price]}`;
  - `loop_cards(data.card, layout_context.products, 'product', 'card', 'shop-grid__item')`;
  - `toCardItem()` in S1, S2 and the placeholder;
  - `ShopCatalogPage::forIndex/forCategory/placeholderIndex/placeholderCategory`;
  - `ShopLayoutTags::SURFACES/pageTag/tenantTag`;
  - `layout_context` keys (`products`, `total`, `pagination`, `categories`, `shop_index`, `category`, `placeholder_item`);
  - the labels "Products — shop home" and "Products — shop categories";
  - the four slugs.
- **Review Focus:** five items, each with its named test (S1+S3, S2, S3, S3+S4, S4).
- **Amended after the second review (user, 2026-09-28):** `columns-auto`'s `grid-template-columns: none` would override the adaptive tracks, so a `theme` track state keeps them, with span clamping unchanged for every other target (S1). `dormantPaths` takes the declared fallback (S5). Remove, deletion and an explicit reset over a conflicting class are separate proofs (S5, S6). The generation is a random token under a key outside both fallback patterns, initialized with `setNx` and a re-read, and advanced by an unconditional set; tests cover a lost real token and initialization racing a save in both orders (S3). The nested drop over the Grid body container asserts the bridge's append (index 2), and placement right after the name is proven by the Blocks tab's insert-after action (S5). Adaptive column counts are derived from measured width and computed dimensions only (S5, S6).
- **Amended after review (user, 2026-09-28):** the Product list's theme defaults are a declared contract the inspector reads, proven untouched, switched, reset and after Remove on page and stage (ruling; S1, S5, S6); S5's drops distinguish the card's own slot from the nested body container's, and the name's grid-item controls belong to that container whatever the loop's mode (S5); the shop cache keys carry a per-workspace, per-surface layout generation read before the render, so a render straddling a save never repopulates the old layout, for all three surfaces (ruling; S3); long-name proofs for overflow, ellipsis and accessible names are separate from the parity pages (S1, S6); a CI measurement disagreement is diagnosed before anything is re-expressed, never simply dropped (Global Constraints, S0).
