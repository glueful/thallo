# The Product grid — Implementation Plan

> Amended 2026-10-08 after plan review:
> - the seven part declarations and their `style_paths` test land in Task 10 with the templates that call `style_classes()` (Task 11 keeps the effects and the stylesheet);
> - the purge fallback drops the **event's** workspace's rendered pages (`purgeWorkspace($tenantUuid)` via `TenantCacheSegment::segmentFor()`), proved for an event in A while the request is in B and with no request workspace (Task 7);
> - categories and tags carry `max_items: 20`, enforced by the validator and the picker, each item meeting the string field's constraints; saved and rendered filters are proved to agree (Tasks 9, 10);
> - the option sources require `commerce.view`, proved against a content editor without it (Task 9);
> - the image links to the product, and the Title part sits on the focusable anchor, proved for pointer, keyboard and forced preview (Tasks 10, 12);
> - the browser proofs tap on touch, check the cart request's product and the cart's result, submit the no-JS form, and assert the Button's authored background (Task 12);
> - shared-contract task numbers corrected; the no-extra-read test counts reads (Task 6).

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Product grid is a source (All products, On sale, Manual) narrowed by multi-select Categories and Tags, ordered and cut to a count, rendered on the server with display toggles, badges and a seven-part Style tab — previewed live on the stage, cached safely, styled through the theme layer.

**Architecture:**
- **Engine (`glueful/commerce` 1.14.0).** `ResolvedProductFilters` takes lists plus `onSale` / `inStock`; `listActive()` takes a sort; batch category and tag projections. Released by the user before the Thallo tasks run.
- **Contracts.** A soft-bound `StorefrontProductGrid` seam returns a `ProductGridView` (card arrays, View-all URL, a storage tag, one cache guard). `StarterBlockTypeDefinition` gains `starterContent`. `RenderedPageCachePurge` gains `purgeWorkspace(string $tenantUuid)`; `TenantCacheSegment` gains `segmentFor()`.
- **Render.** `RenderContextExtension` collects render-scoped **cache hints** (private storage tags, guards, an uncacheable flag) and exposes `product_grid()`. The two page caches — `RenderPageCache` and `ShopPageCache` — read the hints from the request's attributes, store guards inside the entry and refuse a stale entry on every read.
- **Commerce pack.** `ProductGridQuery` normalizes block data; `ProductGrid` implements the seam (catalog generation, query, `gridCards()`); the purge listener rotates the generation and narrows its fallback; the block template renders server-side through `shop/_grid_card.twig`; the old endpoint and shop.js hydration go.
- **Admin.** Option-source string fields may be `multiple` (a multi-select); fields show `label` and `help`.

**Tech Stack:** PHP 8.4 (Glueful, PHPUnit), Twig 3, plain JS (`shop.js`), CSS layers (`@layer theme`, `@layer settings`), Vue 3 + Nuxt UI (vitest, oxfmt, `pnpm type-check`), Playwright (`tools/runtime-browser`, `admin/e2e`).

**Spec:** `docs/internal/superpowers/specs/2026-10-07-product-grid-design.md` (revision 4, `a0e80951`). Every section is in this release.

## Rulings made while planning (from the code)

- **The catalog generation key is `thallo:cataloggen:shop:{tenant}`**, not `{segment}shop:catalog-gen:{tenant}` as §3.2 sketches. It follows `ShopLayoutTags::generationKey()` (`thallo:layoutgen:shop:{surface}:{tenant}`); the commerce tenant is already per workspace, so it isolates the same way, and commerce never needs the tenancy segment. Cost if wrong: one key format.
- **The per-workspace catalog tag is a private *storage* tag, never in the public `Cache-Tag` header.** `ShopPageCache` deliberately keeps workspace ids out of the header visitors receive (`surrogateTags()` maps page tags to tenant tags). The grid's tag travels in the cache hints on the request attributes, and each cache adds it to the entry's tags. Cost if wrong: a CDN purge by this tag would need the header — none exists today.
- **Cache hints travel on the request's attributes** (`RenderCacheHints::ATTRIBUTE`), set by `RenderController::render()` and `ShopPageRenderer::render()` after a successful render. The middlewares and the controllers share the `Request` object.
- **Card data is a new `ProductGridCard`**, not new keys on `ProductCardViewModel`: that view model's `toArray()` keys are pinned (the wishlist JSON and `buildProductCard()` share them). The grid card wraps it and adds `categories`, `tags`, `onSale`, `isNew`.
- **On sale per card** is computed in the pack from the variants the assembler already loads (any active variant with `compare_at_price > price`), the same rule as the engine's `onSale` filter.
- **Category and tag lists are capped at 20 slugs each** by `ProductGridQuery` (a dropdown never needs more; bounds the slug lookups).
- **Display toggles default through `starter_content`** (new `StarterBlockTypeDefinition::$starterContent`), so a new grid's inspector shows them on; the template still applies the same defaults when a value is absent.
- **A hover-effect shadow yields to an authored card shadow.** `card_hover` lives in `@layer theme`; an author's Card shadow is `@layer settings` and wins at rest and on hover. The lift still moves. Stated in the docs.
- **The Card part's hover colours answer the pointer and the stage's forced preview; keyboard focus reaches the card's own links** (image, title, button), whose parts carry their own hover looks — a `<li>` takes no focus, so the compiled `:focus-visible` branch cannot fire on it. The card *effects* (lift, shadow, zoom) also answer `:focus-within`. Cost if wrong: a card-level `:focus-within` utility in the compiler.
- **The image link is out of the tab order and hidden from assistive tech** (`tabindex="-1" aria-hidden="true"`): the title link below names the same product, so a second stop per card would only repeat it.
- **The engine suite runs on SQLite** (its `CommerceTestCase`); PostgreSQL is exercised by Thallo's integration tests, which run every engine query this plan adds.

## Global Constraints

- Engine: `glueful/commerce` **1.14.0**; Thallo requires `glueful/commerce ^1.14.0` in the root `composer.json` and `packages/thallo-commerce/composer.json`, in the same commit as the scalar-filter caller updates.
- Engine SQL is portable (PostgreSQL, MySQL, SQLite): correlated `EXISTS` / subqueries only, no driver-specific functions.
- Product grid limits: *Products to show* 1–48 (default 12); categories and tags at most 20 each; New badge days 1–365 (default 7); badge text at most 24 characters.
- No new `StyleSchema` property; no settings-version bump.
- Every raw `<link … /_thallo/shop/shop.css>` in Thallo templates is removed; `shop.css` arrives only through `ShopStylesheetContributor` (`@layer theme`).
- Commit messages carry no AI attribution lines (the user's standing rule).
- PHP gates: MAMP PHP first on `PATH` (`export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH`); phpcs judged by exit code; integration shards one at a time; `composer test:reset-db && composer test:migrate` before the suite.
- Admin gates: `pnpm type-check`, `pnpm lint`, `pnpm exec oxfmt --check <touched files>`, vitest.
- Changelog bullets ride with each change under `[Unreleased]` (Thallo) / `[Unreleased]` (commerce).

## Review Focus

1. **A cached page with a grid served after a catalog change** — the next visitor gets fresh products (no stale body, no 304 for the stale ETag), on both cache families. Pinned in Task 6.
2. **A shop chrome block elsewhere on the page** (mini cart in the header) — the grid's authored styles still win. Pinned in Task 11.
3. **A grid whose chosen categories were all deleted** — shows nothing, never every product. Pinned in Task 8.
4. **A product with no active variants under a price order** — sorted last in both directions, never first. Pinned in Task 2.
5. **A Manual grid with Exclude out of stock** — drops the out-of-stock listed products and keeps the listed order. Pinned in Task 8.

---

## Shared contracts (named once, used by every task)

```php
// glueful/commerce — src/Catalog/ResolvedProductFilters.php (Task 1)
final class ResolvedProductFilters
{
    /**
     * @param list<string> $categoryUuids any-of
     * @param list<string> $tagUuids any-of (ANDed with the categories)
     * @param list<array{attribute_uuid:string, value_slug:string}> $attributePairs
     */
    public function __construct(
        public readonly array $categoryUuids = [],
        public readonly array $tagUuids = [],
        public readonly array $attributePairs = [],
        public readonly bool $onSale = false,
        public readonly bool $inStock = false,
    ) {}
    public function isEmpty(): bool;
}

// glueful/commerce — src/Catalog/ProductSort.php (Task 2)
final class ProductSort
{
    public const NEWEST = 'newest';
    public const PRICE_ASC = 'price_asc';
    public const PRICE_DESC = 'price_desc';
    public const NAME = 'name';
    public const ALL = [self::NEWEST, self::PRICE_ASC, self::PRICE_DESC, self::NAME];
    public static function apply(QueryBuilder $query, string $sort): QueryBuilder;
}
// ProductRepository::listActive(ApplicationContext, string $tenant, int $page, int $perPage,
//     ?ResolvedProductFilters $filters = null, string $sort = ProductSort::NEWEST): array{items, total}

// glueful/commerce — batch projections (Task 3)
// CategoryRepository::categoryProjectionsForProducts(ApplicationContext, string $tenant, array $productUuids)
//     : array<string, list<array{name: string, slug: string}>>   (position, name, uuid order)
// TagRepository::tagProjectionsForProducts(ApplicationContext, string $tenant, array $productUuids)
//     : array<string, list<array{name: string, slug: string}>>   (name, uuid order)

// thallo-contracts — src/Delivery/StorefrontProductGrid.php + ProductGridView.php (Task 8)
interface StorefrontProductGrid
{
    /** @param array<string,mixed> $data the block's data */
    public function grid(array $data): ProductGridView;
}
final readonly class ProductGridView
{
    /** @param list<array<string,mixed>> $cards ProductGridCard::toArray() rows */
    public function __construct(
        public array $cards,
        public ?string $viewAllUrl,
        public string $storageTag,   // thallo:shop:catalog:{tenant}
        public string $guardKey,     // thallo:cataloggen:shop:{tenant}
        public ?string $guardValue,  // null = unreadable → render uncacheable
    ) {}
}

// thallo-render — src/Cache/RenderCacheHints.php (Task 6)
final class RenderCacheHints
{
    public const ATTRIBUTE = 'thallo.render_cache_hints';
    /** @param list<string> $storageTags @param array<string,string> $guards */
    public function __construct(public readonly array $storageTags = [], public readonly array $guards = [],
        public readonly bool $uncacheable = false) {}
    public static function fromRequest(Request $request): self;  // empty hints when absent
}
// thallo-render — src/Cache/RenderCacheGuards.php (Task 6)
final class RenderCacheGuards
{
    /** @param array<string,string> $guards */
    public static function hold(CacheStore $cache, array $guards): bool; // every key still reads its value
}
// RenderContextExtension (Task 6): addStorageTag(string), observeGuard(string $key, ?string $value),
//     drainCacheHints(): RenderCacheHints; resetTags() also clears the hints.
// RenderContextExtension (Task 10): productGridView(array $data): ?array{cards: list<array>, view_all_url: ?string}
//     — Twig `product_grid(data)`.

// thallo-contracts — RenderedPageCachePurge (Task 7): purgeWorkspace(string $tenantUuid): bool
//     — drops that workspace's rendered pages (every rendered page while tenancy is off).
// thallo-tenancy — TenantCacheSegment::segmentFor(string $tenantUuid, string $surface = 'cache'): string
//     — 'tenant:{uuid}:' while tenancy is on, '' while off; throws MissingTenantForCacheException on ''.

// thallo-commerce — src/Shop/CatalogGeneration.php (Task 7)
final class CatalogGeneration
{
    public const TTL = 2592000;
    public static function key(string $tenant): string;            // thallo:cataloggen:shop:{tenant}
    public function __construct(CacheStore $cache) {}
    public function read(string $tenant): ?string;                 // setNx + re-read; null if unreadable
    public function rotate(string $tenant): void;
}

// thallo-commerce — src/Shop/ProductGridQuery.php (Task 8)
final readonly class ProductGridQuery
{
    public const SOURCES = ['all', 'on_sale', 'manual'];
    public const MAX_SLUGS = 20;
    /** @param list<string> $categories @param list<string> $tags */
    public function __construct(public string $source, public array $categories, public array $tags,
        public string $products, public bool $excludeOutOfStock, public string $orderBy, public int $limit,
        public int $newBadgeDays) {}
    /** @param array<string,mixed> $data */
    public static function fromData(array $data): self;
}

// thallo-commerce — src/Shop/ViewModels/ProductGridCard.php (Task 8)
final readonly class ProductGridCard
{
    /** @param list<array{name:string,slug:string}> $categories @param list<array{name:string,slug:string}> $tags */
    public function __construct(public ProductCardViewModel $card, public array $categories, public array $tags,
        public bool $onSale, public bool $isNew) {}
    /** @return array<string,mixed> $card->toCardItem() + categories, tags, onSale, isNew */
    public function toArray(): array;
}
// ShopProductCardAssembler::gridCards(string $tenant, array $products, int $newBadgeDays,
//     \DateTimeImmutable $now): list<ProductGridCard>
```

---

# Part A — `glueful/commerce` 1.14.0 (repo `~/Sites/glueful/extensions/commerce`)

Run from that repo: `export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH`; tests `vendor/bin/phpunit`; style `composer phpcs`; analysis `composer analyze`.

## Task 1: list filters, on sale, in stock

**Files:**
- Modify: `src/Catalog/ResolvedProductFilters.php`
- Modify: `src/Catalog/ProductRepository.php:316-381` (`activeFilteredQuery`)
- Modify: `src/Http/Storefront/ProductController.php:202`
- Modify: `tests/Integration/Catalog/ProductFilterQueryPlanTest.php:26-55`
- Create: `tests/Integration/Catalog/ProductListFiltersTest.php`
- Modify: `CHANGELOG.md` (`[Unreleased]`)

**Interfaces:** Produces `ResolvedProductFilters` (Shared contracts).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * The grid's filters (1.14.0): categories and tags are any-of lists, ANDed with each other; on sale
 * is any active variant priced below its compare-at price; in stock is any active variant that is
 * untracked or tracked above zero.
 */
final class ProductListFiltersTest extends CommerceTestCase
{
    private const TENANT = 'tenantPLF001';

    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->products = new ProductRepository();
    }

    /** @return list<string> */
    private function uuids(ResolvedProductFilters $filters): array
    {
        $rows = $this->products->listActive($this->context, self::TENANT, 1, 48, $filters)['items'];
        $uuids = array_map(static fn (array $r): string => (string) $r['uuid'], $rows);
        sort($uuids);
        return $uuids;
    }

    public function testCategoriesAreAnyOfAndDeduplicated(): void
    {
        $this->product('prodplfmen01');
        $this->product('prodplfwom01');
        $this->product('prodplfbot01');
        $this->product('prodplfnon01');
        $this->category('catplfmen001', ['prodplfmen01', 'prodplfbot01']);
        $this->category('catplfwom001', ['prodplfwom01', 'prodplfbot01']);

        self::assertSame(
            ['prodplfbot01', 'prodplfmen01', 'prodplfwom01'],
            $this->uuids(new ResolvedProductFilters(categoryUuids: ['catplfmen001', 'catplfwom001'])),
        );
        self::assertSame(
            3,
            $this->products->listActive($this->context, self::TENANT, 1, 48, new ResolvedProductFilters(
                categoryUuids: ['catplfmen001', 'catplfwom001'],
            ))['total'],
            'a product in both categories counts once',
        );
    }

    public function testCategoriesAndTagsMustBothMatch(): void
    {
        $this->product('prodplfboth1');
        $this->product('prodplfcato1');
        $this->category('catplfmen002', ['prodplfboth1', 'prodplfcato1']);
        $this->tag('tagplfsum001', ['prodplfboth1']);

        self::assertSame(['prodplfboth1'], $this->uuids(new ResolvedProductFilters(
            categoryUuids: ['catplfmen002'],
            tagUuids: ['tagplfsum001'],
        )));
    }

    public function testOnSaleIsAnyActiveVariantBelowItsCompareAtPrice(): void
    {
        $this->product('prodplfsale1', [['price' => 800, 'compare_at_price' => 1000]]);
        $this->product('prodplfeqal1', [['price' => 1000, 'compare_at_price' => 1000]]);
        $this->product('prodplfnull1', [['price' => 1000, 'compare_at_price' => null]]);
        $this->product('prodplfdrft1', [['price' => 800, 'compare_at_price' => 1000, 'status' => 'draft']]);
        $this->product('prodplfmix01', [
            ['price' => 1000, 'compare_at_price' => null],
            ['price' => 900, 'compare_at_price' => 1200],
        ]);

        self::assertSame(['prodplfmix01', 'prodplfsale1'], $this->uuids(new ResolvedProductFilters(onSale: true)));
    }

    public function testInStockIsAnyActiveVariantUntrackedOrAboveZero(): void
    {
        $tracked = $this->product('prodplfstck1');
        $empty = $this->product('prodplfempt1');
        $untracked = $this->product('prodplfuntr1');
        $this->product('prodplfnost1'); // no stock row: untracked by default
        $this->stock($tracked, 3, true);
        $this->stock($empty, 0, true);
        $this->stock($untracked, 0, false);

        self::assertSame(
            ['prodplfnost1', 'prodplfstck1', 'prodplfuntr1'],
            $this->uuids(new ResolvedProductFilters(inStock: true)),
            'the tracked-at-zero product is the only one dropped',
        );
    }

    public function testEmptyListsDoNotRestrict(): void
    {
        $this->product('prodplfany01');
        self::assertSame(['prodplfany01'], $this->uuids(new ResolvedProductFilters()));
        self::assertTrue((new ResolvedProductFilters())->isEmpty());
        self::assertFalse((new ResolvedProductFilters(inStock: true))->isEmpty());
    }

    /** @param list<array<string,mixed>> $variants */
    private function product(string $uuid, array $variants = [['price' => 1000]]): string
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => 'slug-' . $uuid,
            'name' => 'Product ' . $uuid, 'type' => 'physical', 'status' => 'active',
        ]);
        foreach ($variants as $i => $variant) {
            $this->connection->table('commerce_variants')->insert([
                'uuid' => substr($uuid, 0, 10) . 'v' . $i, 'tenant_uuid' => self::TENANT,
                'product_uuid' => $uuid, 'sku' => $uuid . '-' . $i, 'option_values' => '{}',
                'price' => $variant['price'], 'compare_at_price' => $variant['compare_at_price'] ?? null,
                'currency' => 'USD', 'status' => $variant['status'] ?? 'active',
            ]);
        }
        return $uuid;
    }

    /** @param list<string> $products */
    private function category(string $uuid, array $products): void
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => $uuid, 'name' => $uuid,
        ]);
        foreach ($products as $product) {
            $this->connection->table('commerce_product_categories')->insert([
                'product_uuid' => $product, 'category_uuid' => $uuid,
            ]);
        }
    }

    /** @param list<string> $products */
    private function tag(string $uuid, array $products): void
    {
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => $uuid, 'name' => $uuid,
        ]);
        foreach ($products as $product) {
            $this->connection->table('commerce_product_tags')->insert(['product_uuid' => $product, 'tag_uuid' => $uuid]);
        }
    }

    private function stock(string $product, int $quantity, bool $tracked): void
    {
        $this->connection->table('commerce_stock')->insert([
            'uuid' => substr($product, 0, 10) . 'st', 'tenant_uuid' => self::TENANT,
            'variant_uuid' => substr($product, 0, 10) . 'v0', 'quantity' => $quantity, 'tracked' => $tracked,
        ]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Catalog/ProductListFiltersTest.php`
Expected: FAIL — `Unknown named parameter $categoryUuids`.

- [ ] **Step 3: Replace the filter value**

```php
final class ResolvedProductFilters
{
    /**
     * @param list<string> $categoryUuids products in ANY of these categories
     * @param list<string> $tagUuids products with ANY of these tags (ANDed with the categories)
     * @param list<array{attribute_uuid:string, value_slug:string}> $attributePairs
     * @param bool $onSale at least one active variant priced below its compare-at price
     * @param bool $inStock at least one active variant untracked, or tracked above zero
     */
    public function __construct(
        public readonly array $categoryUuids = [],
        public readonly array $tagUuids = [],
        public readonly array $attributePairs = [],
        public readonly bool $onSale = false,
        public readonly bool $inStock = false,
    ) {
    }

    /** True when no filter was requested at all -- `listActive()` skips every EXISTS predicate. */
    public function isEmpty(): bool
    {
        return $this->categoryUuids === [] && $this->tagUuids === [] && $this->attributePairs === []
            && !$this->onSale && !$this->inStock;
    }
}
```

Update the class docblock's first paragraph to say category and tag are lists, matched any-of.

- [ ] **Step 4: Rewrite the category and tag predicates and add the two new ones in `activeFilteredQuery()`**

Replace the two `if ($filters->categoryUuid !== null)` / `if ($filters->tagUuid !== null)` blocks with:

```php
        if ($filters->categoryUuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($filters->categoryUuids), '?'));
            $query->whereRaw(
                <<<SQL
EXISTS (
    SELECT 1 FROM commerce_product_categories
    WHERE commerce_product_categories.product_uuid = commerce_products.uuid
    AND commerce_product_categories.category_uuid IN ({$placeholders})
)
SQL,
                array_values($filters->categoryUuids)
            );
        }

        if ($filters->tagUuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($filters->tagUuids), '?'));
            $query->whereRaw(
                <<<SQL
EXISTS (
    SELECT 1 FROM commerce_product_tags
    WHERE commerce_product_tags.product_uuid = commerce_products.uuid
    AND commerce_product_tags.tag_uuid IN ({$placeholders})
)
SQL,
                array_values($filters->tagUuids)
            );
        }

        if ($filters->onSale) {
            $query->whereRaw(
                <<<'SQL'
EXISTS (
    SELECT 1 FROM commerce_variants
    WHERE commerce_variants.product_uuid = commerce_products.uuid
    AND commerce_variants.status = 'active'
    AND commerce_variants.compare_at_price IS NOT NULL
    AND commerce_variants.compare_at_price > commerce_variants.price
)
SQL
            );
        }

        if ($filters->inStock) {
            // A variant with no stock row is untracked (InventoryService creates rows on demand).
            $query->whereRaw(
                <<<'SQL'
EXISTS (
    SELECT 1 FROM commerce_variants
    LEFT JOIN commerce_stock
        ON commerce_stock.variant_uuid = commerce_variants.uuid
        AND commerce_stock.tenant_uuid = commerce_variants.tenant_uuid
    WHERE commerce_variants.product_uuid = commerce_products.uuid
    AND commerce_variants.status = 'active'
    AND (commerce_stock.uuid IS NULL OR commerce_stock.tracked = ? OR commerce_stock.quantity > 0)
)
SQL,
                [false]
            );
        }
```

Before writing the in-stock predicate, confirm "no stock row = untracked" against `src/Inventory/StockRepository.php` (`stockProjectionsForProducts`, line 272): if it treats a missing row as tracked-at-zero, change `commerce_stock.uuid IS NULL OR` to drop that arm, update the test's expectation for `prodplfnost1`, and record the ruling in the ledger.

- [ ] **Step 5: The storefront list passes one-item lists**

In `ProductController.php:202`:

```php
        return new ResolvedProductFilters(
            $categoryUuid !== null ? [$categoryUuid] : [],
            $tagUuid !== null ? [$tagUuid] : [],
            $attributePairs,
        );
```

- [ ] **Step 6: Update the query-plan test's constructors**

`ProductFilterQueryPlanTest.php`: `categoryUuid: 'cat0000001'` → `categoryUuids: ['cat0000001']`; `tagUuid: 'tag0000001'` → `tagUuids: ['tag0000001']`; the combined case likewise.

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/phpunit tests/Integration/Catalog/ProductListFiltersTest.php tests/Integration/Catalog/ProductFilterQueryPlanTest.php tests/Integration/Http`
Expected: PASS (the storefront list HTTP tests still pass with one-item lists).

- [ ] **Step 8: Changelog and commit**

`CHANGELOG.md` under `## [Unreleased]`:

```markdown
### Changed
- **`ResolvedProductFilters` takes lists** (breaking): `categoryUuids` and `tagUuids` are any-of
  lists, ANDed with each other; the single `categoryUuid` / `tagUuid` arguments are gone. The
  storefront product list passes one-item lists; its API is unchanged.

### Added
- **On sale and in stock filters**: `ResolvedProductFilters(onSale: true)` keeps products with an
  active variant priced below its compare-at price; `inStock: true` keeps products with an active
  variant that is untracked or tracked above zero.
```

```bash
git add src/Catalog/ResolvedProductFilters.php src/Catalog/ProductRepository.php src/Http/Storefront/ProductController.php tests/Integration/Catalog/ProductListFiltersTest.php tests/Integration/Catalog/ProductFilterQueryPlanTest.php CHANGELOG.md
git commit -m "feat(catalog): list category and tag filters, on sale and in stock"
```

## Task 2: sorting

**Files:**
- Create: `src/Catalog/ProductSort.php`
- Modify: `src/Catalog/ProductRepository.php:265-284` (`listActive`)
- Create: `tests/Integration/Catalog/ProductSortTest.php`
- Modify: `CHANGELOG.md`

**Interfaces:** Consumes Task 1's filters. Produces `ProductSort`, `listActive(..., string $sort = ProductSort::NEWEST)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ProductSort;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/** listActive()'s order (1.14.0): newest, price both ways by the lowest active price, name. */
final class ProductSortTest extends CommerceTestCase
{
    private const TENANT = 'tenantPST001';

    /** @return list<string> */
    private function order(string $sort): array
    {
        $rows = (new ProductRepository())->listActive($this->context, self::TENANT, 1, 48, null, $sort)['items'];
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->product('prodpstaaa01', 'Banana', [500, 900], '2026-01-03 00:00:00');
        $this->product('prodpstbbb01', 'apple', [700], '2026-01-01 00:00:00');
        $this->product('prodpstccc01', 'Cherry', [300], '2026-01-02 00:00:00');
        $this->product('prodpstddd01', 'Date', [], '2026-01-04 00:00:00'); // no active variant
        $this->product('prodpsteee01', 'Elder', [300], '2026-01-02 00:00:00');
    }

    public function testNewestIsTheDefaultAndBreaksTiesOnUuid(): void
    {
        self::assertSame(['Date', 'Banana', 'Cherry', 'Elder', 'apple'], $this->order(ProductSort::NEWEST));
        $rows = (new ProductRepository())->listActive($this->context, self::TENANT, 1, 48)['items'];
        self::assertSame('Date', $rows[0]['name']);
    }

    public function testPriceOrdersByTheLowestActivePriceWithUnpricedLast(): void
    {
        self::assertSame(['Cherry', 'Elder', 'Banana', 'apple', 'Date'], $this->order(ProductSort::PRICE_ASC));
        self::assertSame(['apple', 'Banana', 'Cherry', 'Elder', 'Date'], $this->order(ProductSort::PRICE_DESC));
    }

    public function testNameIsCaseInsensitiveThenUuid(): void
    {
        self::assertSame(['apple', 'Banana', 'Cherry', 'Date', 'Elder'], $this->order(ProductSort::NAME));
    }

    public function testAnUnknownSortIsNewest(): void
    {
        self::assertSame($this->order(ProductSort::NEWEST), $this->order('random'));
    }

    /** @param list<int> $prices */
    private function product(string $uuid, string $name, array $prices, string $createdAt): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => 'slug-' . $uuid, 'name' => $name,
            'type' => 'physical', 'status' => 'active', 'created_at' => $createdAt,
        ]);
        foreach ($prices as $i => $price) {
            $this->connection->table('commerce_variants')->insert([
                'uuid' => substr($uuid, 0, 10) . 'v' . $i, 'tenant_uuid' => self::TENANT, 'product_uuid' => $uuid,
                'sku' => $uuid . '-' . $i, 'option_values' => '{}', 'price' => $price, 'currency' => 'USD',
                'status' => 'active',
            ]);
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Catalog/ProductSortTest.php`
Expected: FAIL — `Class "Glueful\Extensions\Commerce\Catalog\ProductSort" not found`.

- [ ] **Step 3: Write `ProductSort`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Database\QueryBuilder;

/**
 * The orders a product list can take (1.14.0). Price is the product's lowest ACTIVE variant price;
 * a product with no active variant sorts last both ways. Every order ends on uuid, so pages are
 * stable. Portable SQL: a correlated MIN() and a CASE, no driver functions.
 */
final class ProductSort
{
    public const NEWEST = 'newest';
    public const PRICE_ASC = 'price_asc';
    public const PRICE_DESC = 'price_desc';
    public const NAME = 'name';
    public const ALL = [self::NEWEST, self::PRICE_ASC, self::PRICE_DESC, self::NAME];

    private const MIN_PRICE = '(SELECT MIN(commerce_variants.price) FROM commerce_variants'
        . ' WHERE commerce_variants.product_uuid = commerce_products.uuid'
        . " AND commerce_variants.status = 'active')";

    public static function apply(QueryBuilder $query, string $sort): QueryBuilder
    {
        switch ($sort) {
            case self::PRICE_ASC:
            case self::PRICE_DESC:
                $direction = $sort === self::PRICE_ASC ? 'ASC' : 'DESC';
                $query->orderByRaw('CASE WHEN ' . self::MIN_PRICE . ' IS NULL THEN 1 ELSE 0 END ASC')
                    ->orderByRaw(self::MIN_PRICE . ' ' . $direction)
                    ->orderByRaw('LOWER(commerce_products.name) ASC');
                break;
            case self::NAME:
                $query->orderByRaw('LOWER(commerce_products.name) ASC');
                break;
            default:
                $query->orderBy('created_at', 'DESC');
        }
        return $query->orderBy('uuid', 'ASC');
    }
}
```

- [ ] **Step 4: `listActive()` takes the sort**

```php
    public function listActive(
        ApplicationContext $context,
        string $tenant,
        int $page,
        int $perPage,
        ?ResolvedProductFilters $filters = null,
        string $sort = ProductSort::NEWEST,
    ): array {
        $total = $this->activeFilteredQuery($context, $tenant, $filters)->count();
        $rows = ProductSort::apply($this->activeFilteredQuery($context, $tenant, $filters), $sort)
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();
```

Update its docblock: "Ordered by `$sort` ({@see ProductSort}); `newest` (the default) is `created_at DESC, uuid ASC`."

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Integration/Catalog`
Expected: PASS.

- [ ] **Step 6: Changelog and commit**

```markdown
- **Product list order**: `listActive()` takes a `ProductSort` — `newest` (default), `price_asc`,
  `price_desc` (by the lowest active variant price; unpriced products last) and `name`.
```

```bash
git add src/Catalog/ProductSort.php src/Catalog/ProductRepository.php tests/Integration/Catalog/ProductSortTest.php CHANGELOG.md
git commit -m "feat(catalog): product list sort — newest, price both ways, name"
```

## Task 3: batch category and tag projections

**Files:**
- Modify: `src/Catalog/CategoryRepository.php` (after `firstCategoryProjectionsForProducts`, line 242)
- Modify: `src/Catalog/TagRepository.php` (after `tagProjectionsForProduct`, line 182)
- Create: `tests/Integration/Catalog/ProductTaxonomyProjectionsTest.php`
- Modify: `CHANGELOG.md`

**Interfaces:** Produces the two batch methods (Shared contracts).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\CountingPdoStatement;

/** Every category and tag of a list of products, in one query each (1.14.0). */
final class ProductTaxonomyProjectionsTest extends CommerceTestCase
{
    private const TENANT = 'tenantPTP001';

    public function testEveryCategoryInPositionThenNameOrderInOneQuery(): void
    {
        $this->category('catptpbbb001', 'Women', 1);
        $this->category('catptpaaa001', 'Men', 1);
        $this->category('catptpccc001', 'Sale', 0);
        $this->link('commerce_product_categories', 'category_uuid', 'prodptp00001', ['catptpbbb001', 'catptpaaa001', 'catptpccc001']);
        $this->link('commerce_product_categories', 'category_uuid', 'prodptp00002', ['catptpaaa001']);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;
        $result = (new CategoryRepository())->categoryProjectionsForProducts(
            $this->context,
            self::TENANT,
            ['prodptp00001', 'prodptp00002', 'prodptp00003'],
        );
        self::assertSame(1, CountingPdoStatement::$count);
        self::assertSame(['Sale', 'Men', 'Women'], array_column($result['prodptp00001'], 'name'));
        self::assertSame([['name' => 'Men', 'slug' => 'catptpaaa001']], $result['prodptp00002']);
        self::assertArrayNotHasKey('prodptp00003', $result);
        self::assertSame([], (new CategoryRepository())->categoryProjectionsForProducts($this->context, self::TENANT, []));
    }

    public function testEveryTagInNameOrderInOneQuery(): void
    {
        $this->tag('tagptpbbb001', 'Summer');
        $this->tag('tagptpaaa001', 'linen');
        $this->link('commerce_product_tags', 'tag_uuid', 'prodptp00004', ['tagptpbbb001', 'tagptpaaa001']);

        $result = (new TagRepository())->tagProjectionsForProducts($this->context, self::TENANT, ['prodptp00004']);
        self::assertSame(['linen', 'Summer'], array_column($result['prodptp00004'], 'name'));
    }

    private function category(string $uuid, string $name, int $position): void
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => $uuid, 'name' => $name, 'position' => $position,
        ]);
    }

    private function tag(string $uuid, string $name): void
    {
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => $uuid, 'name' => $name,
        ]);
    }

    /** @param list<string> $targets */
    private function link(string $table, string $column, string $product, array $targets): void
    {
        foreach ($targets as $target) {
            $this->connection->table($table)->insert(['product_uuid' => $product, $column => $target]);
        }
    }
}
```

The tag sort is case-insensitive (`linen` before `Summer`), matching `ProductSort::NAME`.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Catalog/ProductTaxonomyProjectionsTest.php`
Expected: FAIL — `Call to undefined method …::categoryProjectionsForProducts()`.

- [ ] **Step 3: Implement both**

`CategoryRepository`:

```php
    /**
     * Every category of each product (1.14.0, the Product grid's card): ONE query for the whole
     * list, in the same `position, name, uuid` order as {@see self::firstCategoryProjectionsForProducts()}.
     * Input through {@see UuidBatch::normalize()}; an empty set issues NO query.
     *
     * @param array<mixed> $productUuids
     * @return array<string, list<array{name: string, slug: string}>> keyed by product_uuid
     */
    public function categoryProjectionsForProducts(ApplicationContext $context, string $tenant, array $productUuids): array
    {
        $productUuids = UuidBatch::normalize($productUuids);
        if ($productUuids === []) {
            return [];
        }
        $rows = db($context)->table('commerce_product_categories')
            ->join('commerce_categories', 'commerce_product_categories.category_uuid', '=', 'commerce_categories.uuid')
            ->select(['commerce_product_categories.product_uuid', 'commerce_categories.name', 'commerce_categories.slug'])
            ->where('commerce_categories.tenant_uuid', '=', $tenant)
            ->whereIn('commerce_product_categories.product_uuid', $productUuids)
            ->orderBy('commerce_product_categories.product_uuid', 'ASC')
            ->orderBy('commerce_categories.position', 'ASC')
            ->orderBy('commerce_categories.name', 'ASC')
            ->orderBy('commerce_categories.uuid', 'ASC')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['product_uuid']][] = ['name' => (string) $row['name'], 'slug' => (string) $row['slug']];
        }
        return $result;
    }
```

`TagRepository` (add `use Glueful\Extensions\Commerce\Support\UuidBatch;` if absent — check the namespace `CategoryRepository` imports it from):

```php
    /**
     * Every tag of each product (1.14.0, the Product grid's card): ONE query for the whole list,
     * name order (case-insensitive), then uuid. Empty input issues NO query.
     *
     * @param array<mixed> $productUuids
     * @return array<string, list<array{name: string, slug: string}>> keyed by product_uuid
     */
    public function tagProjectionsForProducts(ApplicationContext $context, string $tenant, array $productUuids): array
    {
        $productUuids = UuidBatch::normalize($productUuids);
        if ($productUuids === []) {
            return [];
        }
        $rows = db($context)->table('commerce_product_tags')
            ->join('commerce_tags', 'commerce_product_tags.tag_uuid', '=', 'commerce_tags.uuid')
            ->select(['commerce_product_tags.product_uuid', 'commerce_tags.name', 'commerce_tags.slug'])
            ->where('commerce_tags.tenant_uuid', '=', $tenant)
            ->whereIn('commerce_product_tags.product_uuid', $productUuids)
            ->orderBy('commerce_product_tags.product_uuid', 'ASC')
            ->orderByRaw('LOWER(commerce_tags.name) ASC')
            ->orderBy('commerce_tags.uuid', 'ASC')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['product_uuid']][] = ['name' => (string) $row['name'], 'slug' => (string) $row['slug']];
        }
        return $result;
    }
```

- [ ] **Step 4: Run the whole engine suite and gates**

Run: `vendor/bin/phpunit && composer phpcs && composer analyze`
Expected: all PASS.

- [ ] **Step 5: Changelog and commit**

```markdown
- **Batch taxonomy projections**: `CategoryRepository::categoryProjectionsForProducts()` and
  `TagRepository::tagProjectionsForProducts()` — every category or tag of a list of products in
  one query.
```

```bash
git add src/Catalog/CategoryRepository.php src/Catalog/TagRepository.php tests/Integration/Catalog/ProductTaxonomyProjectionsTest.php CHANGELOG.md
git commit -m "feat(catalog): batch category and tag projections for product lists"
```

## Task 4 (user): release `glueful/commerce` 1.14.0

- [ ] Report the three commits to the user; the user cuts 1.14.0 (changelog section, tag, push, Packagist). **Stop until the user says 1.14.0 is published.** Part B starts with the bump.

---

# Part B — Thallo

## Task 5: the dependency bump with every scalar-filter caller

**Files:**
- Modify: `composer.json` (`"glueful/commerce"`), `packages/thallo-commerce/composer.json`, `composer.lock`
- Modify: `packages/thallo-commerce/src/Shop/ShopCatalogPage.php:57`
- Modify: `packages/thallo-commerce/src/Layouts/ShopCategorySurface.php:97`
- Modify: `packages/thallo-commerce/src/Http/Shop/ShopBlockDataController.php:204,221` (kept working until Task 10 removes it)
- Test: `tests/Integration/Commerce/ShopCatalogTest.php`, `tests/Integration/Commerce/ShopCategoryLayoutTest.php` (find the category-page and category-layout tests with `grep -rln "category" tests/Integration/Commerce | xargs grep -ln "shop/category\|ShopCategorySurface"`)

**Interfaces:** Consumes Part A.

- [ ] **Step 1: Bump the requirement and update**

Set `"glueful/commerce": "^1.14.0"` in both `composer.json` files, then:
Run: `export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:/usr/local/bin:$PATH && composer update glueful/commerce --no-interaction`
Expected: `Upgrading glueful/commerce (v1.13.0 => v1.14.0)`.

- [ ] **Step 2: Run the category tests to see them fail**

Run: `vendor/bin/phpunit --filter 'Category' tests/Integration/Commerce`
Expected: FAIL — `TypeError … ResolvedProductFilters::__construct(): Argument #1 ($categoryUuids) must be of type array, string given`.

- [ ] **Step 3: Pass one-item lists**

```php
// ShopCatalogPage::forCategory()
        $filters = new ResolvedProductFilters([(string) $category['uuid']]);
// ShopCategorySurface::listsAProduct()
            ->activeFilteredQuery($this->context, $tenant, new ResolvedProductFilters([$category]))
// ShopBlockDataController (removed in Task 10)
        $filters = new ResolvedProductFilters([(string) $category['uuid']]);
        $filters = new ResolvedProductFilters([], [(string) $tag['uuid']]);
```

- [ ] **Step 4: Prove the public category page and the category layout's stage**

If they are not already covered, add to the category-page test class (the one that requests `/shop/category/{slug}`):

```php
    public function testTheCategoryPageListsExactlyItsProductsOnTheListFilterEngine(): void
    {
        $shoes = $this->seedCategory('shoes');
        $inShoes = $this->seedSimpleProduct('runner', 'Runner');
        $this->seedSimpleProduct('scarf', 'Scarf');
        $this->attachCategory($inShoes, $shoes);

        $html = (string) $this->handle(Request::create('/shop/category/shoes', 'GET'))->getContent();
        self::assertStringContainsString('Runner', $html);
        self::assertStringNotContainsString('Scarf', $html);
        self::assertSame(404, $this->handle(Request::create('/shop/category/nope', 'GET'))->getStatusCode());
    }
```

and to the category layout test class one stage render of the category surface asserting the category's product name and, for an empty category, the empty state (follow that class's existing stage helper; if it already asserts both, record that in the ledger and add nothing).

- [ ] **Step 5: Run the commerce shard**

Run: `composer test:reset-db >/dev/null && composer test:migrate >/dev/null && vendor/bin/phpunit tests/Integration/Commerce tests/Integration/Payments`
Expected: PASS.

- [ ] **Step 6: Changelog and commit**

`CHANGELOG.md` `[Unreleased]` → `### Changed`:

```markdown
- **Thallo requires `glueful/commerce ^1.14.0`**: the category page and the category layout pass
  the engine's list filters.
```

```bash
git add composer.json composer.lock packages/thallo-commerce/composer.json packages/thallo-commerce/src tests/Integration/Commerce CHANGELOG.md
git commit -m "chore(deps): require glueful/commerce ^1.14.0 — category surfaces on list filters"
```

## Task 6: render cache hints — storage tags, guards, refusing stale entries

**Files:**
- Create: `packages/thallo-render/src/Cache/RenderCacheHints.php`, `packages/thallo-render/src/Cache/RenderCacheGuards.php`
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (beside `collectTags` / `resetTags` / `drainTags`, ~2143-2183)
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php:1345-1348`
- Modify: `packages/thallo-render/src/Http/Middleware/RenderPageCache.php`
- Modify: `packages/thallo-commerce/src/Http/Shop/ShopPageRenderer.php:53-67`
- Modify: `packages/thallo-commerce/src/Shop/ShopPageCache.php` (hit and store paths, ~110-140)
- Create: `tests/Integration/Render/RenderCacheGuardTest.php`, `tests/Integration/Commerce/ShopCacheGuardTest.php`

**Interfaces:** Produces `RenderCacheHints`, `RenderCacheGuards`, the extension's `addStorageTag()`, `observeGuard()`, `drainCacheHints()`.

- [ ] **Step 1: Write the failing render-family tests**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Cache\RenderCacheHints;
use Thallo\Render\Http\Middleware\RenderPageCache;

/**
 * Product grid spec §3.2: a render's guards are stored in the entry and re-read before every
 * serve — a stale entry is refused on read, whoever stored it and whenever.
 */
final class RenderCacheGuardTest extends AppTestCase
{
    private const GEN = 'thallo:cataloggen:shop:guardtest';

    protected function tearDown(): void
    {
        $this->cache()->deletePattern('render:*');
        $this->cache()->delete(self::GEN);
        parent::tearDown();
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function middleware(): RenderPageCache
    {
        return new RenderPageCache($this->cache(), 'default', static fn (): string => 'fp', true, 3600);
    }

    /** A render that observed `$gen` (or is uncacheable) and says `$body`. */
    private function next(string $body, ?string $gen, bool $uncacheable = false, ?\Closure $during = null): \Closure
    {
        return function (Request $request) use ($body, $gen, $uncacheable, $during): Response {
            $during?->__invoke();
            $request->attributes->set(RenderCacheHints::ATTRIBUTE, new RenderCacheHints(
                ['thallo:shop:catalog:guardtest'],
                $gen === null ? [] : [self::GEN => $gen],
                $uncacheable,
            ));
            return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        };
    }

    private function get(string $path = '/grid-page', array $headers = []): Request
    {
        $request = Request::create($path, 'GET');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        return $request;
    }

    public function testAGuardedPageIsStoredWithItsGuardsAndServedWhileTheyHold(): void
    {
        $this->cache()->set(self::GEN, 'A', 3600);
        $first = $this->middleware()->handle($this->get(), $this->next('old', 'A'));
        $second = $this->middleware()->handle($this->get(), $this->next('fresh', 'A'));
        self::assertSame('old', $second->getContent(), 'served from cache while the guard holds');
        self::assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    public function testAChangeAfterTheStoreIsRefusedOnTheNextReadAndNoStale304(): void
    {
        $this->cache()->set(self::GEN, 'A', 3600);
        $stale = $this->middleware()->handle($this->get(), $this->next('old', 'A'));
        $this->cache()->set(self::GEN, 'B', 3600); // a catalog change lands; nothing deletes the entry

        $reader = $this->middleware()->handle(
            $this->get('/grid-page', ['If-None-Match' => (string) $stale->headers->get('ETag')]),
            $this->next('fresh', 'B'),
        );
        self::assertSame(200, $reader->getStatusCode(), 'no 304 for the stale ETag');
        self::assertSame('fresh', $reader->getContent());
    }

    public function testAWorkerThatStopsRightAfterStoringLeavesNothingServable(): void
    {
        // Simulated: an entry written with the old guard, then nothing else ever runs.
        $this->cache()->set(self::GEN, 'B', 3600);
        $this->middleware()->handle($this->get(), $this->next('fresh', 'B'));
        $key = $this->onlyRenderKey();
        $entry = $this->cache()->get($key);
        $entry['body'] = 'stale';
        $entry['guards'] = [self::GEN => 'A'];
        $this->cache()->set($key, $entry, 3600);

        $reader = $this->middleware()->handle($this->get(), $this->next('fresh again', 'B'));
        self::assertSame('fresh again', $reader->getContent());
    }

    public function testAChangeBeforeThePreStoreCheckLeavesThePageUnstored(): void
    {
        $this->cache()->set(self::GEN, 'A', 3600);
        $this->middleware()->handle($this->get(), $this->next('old', 'A', false, fn () => $this->cache()->set(self::GEN, 'B', 3600)));
        self::assertSame([], $this->renderKeys());
    }

    public function testAnUncacheableRenderIsServedButNotStored(): void
    {
        $response = $this->middleware()->handle($this->get(), $this->next('two grids disagreed', null, true));
        self::assertSame('two grids disagreed', $response->getContent());
        self::assertSame([], $this->renderKeys());
    }

    public function testThePrivateStorageTagJoinsTheEntryButNotTheHeader(): void
    {
        $this->cache()->set(self::GEN, 'A', 3600);
        $response = $this->middleware()->handle($this->get(), $this->next('old', 'A'));
        self::assertStringNotContainsString('guardtest', (string) $response->headers->get('Cache-Tag'));
        $this->cache()->invalidateTags(['thallo:shop:catalog:guardtest']);
        self::assertSame([], $this->renderKeys(), 'the entry was tagged with the storage tag');
    }

    public function testAPageWithoutGuardsServesWithNoExtraRead(): void
    {
        $plain = static fn (Request $r): Response => new Response('plain', 200, ['Content-Type' => 'text/html']);
        $this->middleware()->handle($this->get('/plain'), $plain); // stored
        $reads = [];
        $spy = $this->readCounting($this->cache(), $reads);
        $mw = new RenderPageCache($spy, 'default', static fn (): string => 'fp', true, 3600);
        $hit = $mw->handle($this->get('/plain'), static fn () => throw new \LogicException('must be a hit'));
        self::assertSame('plain', $hit->getContent());
        self::assertCount(1, $reads, 'the entry read only — no guard reads for a page without a grid');
    }

    /**
     * A CacheStore mock that records every get() and answers it from `$inner`. The hit path only
     * calls get(), so no other method needs forwarding.
     *
     * @param list<string> $reads
     */
    private function readCounting(CacheStore $inner, array &$reads): CacheStore
    {
        $spy = $this->createMock(CacheStore::class);
        $spy->method('get')->willReturnCallback(static function (string $key, mixed $default = null) use ($inner, &$reads): mixed {
            $reads[] = $key;
            return $inner->get($key, $default);
        });
        return $spy;
    }

    /** @return list<string> */
    private function renderKeys(): array
    {
        return array_values(array_filter(
            $this->cache()->getKeys('render:*'),
            static fn (string $k): bool => !str_ends_with($k, ':404') && !str_ends_with($k, ':410'),
        ));
    }

    private function onlyRenderKey(): string
    {
        $keys = $this->renderKeys();
        self::assertCount(1, $keys);
        return $keys[0];
    }
}
```

If `CacheStore` has no `getKeys()` (check `vendor/glueful/framework/src/Cache/CacheStore.php`; the Redis and array drivers have it), compute the key the way `RenderPageCache::key()` does instead: `'render:default:fp:' . rawurlencode('/grid-page')`, and assert `$this->cache()->get($key)` is null / an array.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/Render/RenderCacheGuardTest.php`
Expected: FAIL — `Class "Thallo\Render\Cache\RenderCacheHints" not found`.

- [ ] **Step 3: The hints and the guard check**

```php
<?php

declare(strict_types=1);

namespace Thallo\Render\Cache;

use Symfony\Component\HttpFoundation\Request;

/**
 * What a render tells the page caches beyond its HTML (product grid spec §3.2): private storage
 * tags (stored on the entry, never in the public Cache-Tag header), cache guards — cache keys and
 * the values the render read — and whether the render may be cached at all. Carried on the
 * request's attributes; never sent to the client.
 */
final class RenderCacheHints
{
    public const ATTRIBUTE = 'thallo.render_cache_hints';

    /**
     * @param list<string> $storageTags
     * @param array<string,string> $guards cache key => the value the render read
     */
    public function __construct(
        public readonly array $storageTags = [],
        public readonly array $guards = [],
        public readonly bool $uncacheable = false,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $hints = $request->attributes->get(self::ATTRIBUTE);
        return $hints instanceof self ? $hints : new self();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Render\Cache;

use Glueful\Cache\CacheStore;

/** Whether every guard still reads the value it was stored with (product grid spec §3.2). */
final class RenderCacheGuards
{
    /** @param array<string,string> $guards */
    public static function hold(CacheStore $cache, array $guards): bool
    {
        foreach ($guards as $key => $value) {
            $now = $cache->get($key);
            if (!is_string($now) || $now !== $value) {
                return false;
            }
        }
        return true;
    }
}
```

- [ ] **Step 4: The extension collects hints**

Beside `collectedTags` in `RenderContextExtension`:

```php
    /** @var array<string,string> render-scoped private storage tags (product grid spec §3.2) */
    private array $storageTags = [];
    /** @var array<string,string> render-scoped cache guards: key => the first value observed */
    private array $guards = [];
    private bool $uncacheable = false;

    /** A tag the page caches store on the entry but never put in the public Cache-Tag header. */
    public function addStorageTag(string $tag): void
    {
        $this->storageTags[$tag] = $tag;
    }

    /**
     * Record that this render read `$key` as `$value`. Null (unreadable) marks the render
     * uncacheable; a second observation of the same key that disagrees with the first does too —
     * the first is never replaced (two grids straddling a catalog change).
     */
    public function observeGuard(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->uncacheable = true;
            return;
        }
        if (isset($this->guards[$key]) && $this->guards[$key] !== $value) {
            $this->uncacheable = true;
            return;
        }
        $this->guards[$key] ??= $value;
    }

    /** The render's cache hints, drained (and cleared). */
    public function drainCacheHints(): RenderCacheHints
    {
        $hints = new RenderCacheHints(array_values($this->storageTags), $this->guards, $this->uncacheable);
        $this->storageTags = [];
        $this->guards = [];
        $this->uncacheable = false;
        return $hints;
    }
```

and in `resetTags()` add `$this->storageTags = []; $this->guards = []; $this->uncacheable = false;`. Import `Thallo\Render\Cache\RenderCacheHints`.

- [ ] **Step 5: The controllers put the hints on the request**

`RenderController.php` after `$this->mergeCacheTags($response, $this->extension->drainTags());`:

```php
        $request->attributes->set(RenderCacheHints::ATTRIBUTE, $this->extension->drainCacheHints());
```

(If `$request` is not in scope at that line, thread it from the calling action into `render()` the way `$locale` is threaded; read the method's callers first.)

`ShopPageRenderer::render()` after `$html = $this->extension->finish(...)`:

```php
        $request->attributes->set(RenderCacheHints::ATTRIBUTE, $this->extension->drainCacheHints());
```

- [ ] **Step 6: `RenderPageCache` stores guards and refuses stale entries**

In `handle()` replace the hit block and the 200 store block:

```php
        $key = $this->key($request->getPathInfo());
        $hit = $this->cache->get($key);
        if (is_array($hit)) {
            // A guarded entry is served only while every guard still holds — checked before any
            // 304 decision, so a stale ETag is never confirmed (product grid spec §3.2).
            if (RenderCacheGuards::hold($this->cache, $hit['guards'] ?? [])) {
                return $this->respond($request, $hit);
            }
            $this->cache->delete($key);
        }
```

```php
        if ($status === 200) {
            $hints = RenderCacheHints::fromRequest($request);
            $entry = $this->entry($body, 200, $contentType, $cacheTag, $hints->guards);
            if (!$hints->uncacheable && RenderCacheGuards::hold($this->cache, $hints->guards)) {
                $this->cache->set($key, $entry, $this->ttl);
                $this->cache->addTags(
                    $key,
                    [...$this->surrogateTags($cacheTag), ...$hints->storageTags, 'thallo:render:page'],
                );
            }
            return $this->respond($request, $entry);
        }
```

`entry()` gains `array $guards = []` and stores `'guards' => $guards`; update its `@return` shape and `respond()`'s `@param` shape to include `guards: array<string,string>`. The 404/410 call passes no guards.

- [ ] **Step 7: Run the render tests**

Run: `vendor/bin/phpunit tests/Integration/Render/RenderCacheGuardTest.php tests/Integration/Render/RenderPageCacheTest.php tests/Integration/Render/RenderPageCacheAppearanceTest.php`
Expected: PASS.

- [ ] **Step 8: Write the failing shop-family test**

`tests/Integration/Commerce/ShopCacheGuardTest.php` — the same five cases as Step 1 (stored-and-served, change-after-store refused with no stale 304, worker-stopped entry refused, change-before-store unstored, uncacheable unstored, and the read-count case), driving `ShopPageCache` with a `$next` that sets `RenderCacheHints` on the request. Build the middleware the way `ShopCacheTest` does (read its setup; reuse its tenant constant and construction helper), request `/shop`, and compute keys with that class's existing key helper. Copy each test body from Step 1, changing only the middleware construction, the path, and the key lookup.

- [ ] **Step 9: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Commerce/ShopCacheGuardTest.php`
Expected: FAIL — the stale body is served.

- [ ] **Step 10: `ShopPageCache` gets the same contract**

Hit block:

```php
        $hit = $this->cache->get($key);
        if (is_array($hit)) {
            if (RenderCacheGuards::hold($this->cache, $hit['guards'] ?? [])) {
                return $this->respond($request, $hit);
            }
            $this->cache->delete($key);
        }
```

Store block:

```php
        if ($status === 200) {
            $hints = RenderCacheHints::fromRequest($request);
            $entry = $this->entry($body, 200, $contentType, $cacheTag, $hints->guards);
            if (!$hints->uncacheable && RenderCacheGuards::hold($this->cache, $hints->guards)) {
                $this->cache->set($key, $entry, $this->ttl);
                $this->cache->addTags(
                    $key,
                    [
                        ...$this->surrogateTags($cacheTag, $tenant),
                        ...$hints->storageTags,
                        self::TENANT_TAG_PREFIX . $tenant,
                        self::GLOBAL_TAG,
                    ],
                );
            }
            return $this->respond($request, $entry);
        }
```

with `entry()` storing `guards` as in Step 6.

- [ ] **Step 11: Run the cache tests**

Run: `vendor/bin/phpunit tests/Integration/Commerce/ShopCacheGuardTest.php tests/Integration/Commerce/ShopCacheTest.php tests/Integration/Commerce/ShopLayoutCacheTest.php tests/Integration/Commerce/ProductLayoutCacheTest.php`
Expected: PASS.

- [ ] **Step 12: Commit**

```bash
git add packages/thallo-render/src packages/thallo-commerce/src tests/Integration/Render/RenderCacheGuardTest.php tests/Integration/Commerce/ShopCacheGuardTest.php
git commit -m "feat(render): cache hints — private storage tags and guards stored with the entry, refused when stale"
```

## Task 7: the catalog generation and the purge listener

**Files:**
- Create: `packages/thallo-commerce/src/Shop/CatalogGeneration.php`
- Modify: `packages/thallo-commerce/src/Shop/Listeners/PurgeShopCacheOnCatalogChange.php`
- Modify: `packages/thallo-contracts/src/Delivery/RenderedPageCachePurge.php`, `packages/thallo-render/src/Http/Middleware/RenderCachePurge.php` (+ its factory in `RenderServiceProvider`, to pass the optional `TenantCacheSegment`)
- Modify: `packages/thallo-tenancy/src/Cache/TenantCacheSegment.php` (add `segmentFor()`; `segment()` delegates to it)
- Create: `tests/Integration/Tenancy/CatalogPurgeWorkspaceTest.php` (a `RetrofittedTenantTestCase`; add it to the tenancy shard in `.github/workflows/ci.yml`)
- Modify: the two anonymous implementors in `tests/Integration/Content/Layouts/LayoutSaveTest.php:243` and `LayoutBindingsTest.php:256` (add the method)
- Create: `tests/Integration/Commerce/CatalogGenerationPurgeTest.php`

**Interfaces:** Produces `CatalogGeneration`, `RenderedPageCachePurge::purgeWorkspace(string $tenantUuid): bool` and `TenantCacheSegment::segmentFor()` (Shared contracts).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Thallo\Commerce\Shop\CatalogGeneration;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnCatalogChange;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Core\Tests\Support\AppTestCase;

/** Product grid spec §3.2: the catalog generation, and the purge's fallback per workspace. */
final class CatalogGenerationPurgeTest extends AppTestCase
{
    public function testAMissingGenerationIsInitializedAndReRead(): void
    {
        $cache = $this->container()->get(CacheStore::class);
        $cache->delete(CatalogGeneration::key('gentenant01'));
        $generation = new CatalogGeneration($cache);
        $first = $generation->read('gentenant01');
        self::assertIsString($first);
        self::assertSame($first, $generation->read('gentenant01'));
    }

    public function testAnUnreadableGenerationIsNullNotAMatch(): void
    {
        $store = $this->createMock(CacheStore::class);
        $store->method('get')->willReturn(null);
        $store->method('setNx')->willReturn(false);
        self::assertNull((new CatalogGeneration($store))->read('gentenant01'));
    }

    public function testEveryCatalogChangeRotatesTheGenerationBeforePurging(): void
    {
        $cache = $this->container()->get(CacheStore::class);
        $generation = new CatalogGeneration($cache);
        $before = $generation->read('gentenant02');
        $this->listener($cache)->onCatalogChanged(
            new StorefrontCatalogChanged('gentenant02', StorefrontCatalogChanged::REASON_ATTRIBUTE_CHANGED, null),
        );
        self::assertNotSame($before, $generation->read('gentenant02'));
        self::assertSame($generation->read('gentenant03'), $generation->read('gentenant03'), 'other tenants untouched');
    }

    public function testTheFallbackDeletesOnlyTheOwningWorkspacesShopAndRenderPages(): void
    {
        $store = $this->createMock(CacheStore::class);
        $store->method('invalidateTags')->willReturn(false);
        $store->method('get')->willReturn('g');
        $patterns = [];
        $store->method('deletePattern')->willReturnCallback(static function (string $p) use (&$patterns): bool {
            $patterns[] = $p;
            return true;
        });
        $renderPurged = [];
        $purge = new class ($renderPurged) implements RenderedPageCachePurge {
            /** @param list<string> $workspaces */
            public function __construct(private array &$workspaces)
            {
            }
            public function purge(array $tags): void
            {
            }
            public function purgeAll(): bool
            {
                return true;
            }
            public function purgeWorkspace(string $tenantUuid): bool
            {
                $this->workspaces[] = $tenantUuid;
                return true;
            }
        };
        $this->listener($store, $purge)->onCatalogChanged(
            new StorefrontCatalogChanged('gentenant04', StorefrontCatalogChanged::REASON_PRODUCT_UPDATED, null),
        );
        self::assertSame(['shop:gentenant04:*', 'tenant:gentenant04:shop:*'], $patterns);
        self::assertSame(['gentenant04'], $renderPurged, 'the EVENT\'s workspace, never the request\'s');
    }

    private function listener(CacheStore $cache, ?RenderedPageCachePurge $purge = null): PurgeShopCacheOnCatalogChange
    {
        $container = $this->container()->with(array_filter([
            CacheStore::class => static fn () => $cache,
            RenderedPageCachePurge::class => $purge !== null ? static fn () => $purge : null,
        ]));
        return new PurgeShopCacheOnCatalogChange($container);
    }
}
```

Check `StorefrontCatalogChanged`'s constructor order in `vendor/glueful/commerce/src/Events/StorefrontCatalogChanged.php` (`$tenantUuid, $reason, $productUuid`, per the dispatcher) and `$this->container()->with()` (used by `RenderPageCacheTest:371`).

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Commerce/CatalogGenerationPurgeTest.php`
Expected: FAIL — `Class "Thallo\Commerce\Shop\CatalogGeneration" not found`.

- [ ] **Step 3: `CatalogGeneration`**

```php
<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Cache\CacheStore;
use Thallo\Commerce\Layouts\ShopLayoutTags;

/**
 * A workspace's catalog generation (product grid spec §3.2): a random token every storefront
 * catalog change replaces. A grid reads it before querying products and records it as a cache
 * guard, so a page rendered from the old catalog is refused on read once the token moves.
 */
final class CatalogGeneration
{
    public const TTL = ShopLayoutTags::GENERATION_TTL;

    public function __construct(private readonly CacheStore $cache)
    {
    }

    public static function key(string $tenant): string
    {
        return 'thallo:cataloggen:shop:' . $tenant;
    }

    /** The stored token; a missing one is created (setNx) and re-read; null when unreadable. */
    public function read(string $tenant): ?string
    {
        $key = self::key($tenant);
        $token = $this->cache->get($key);
        if (ShopLayoutTags::isGeneration($token)) {
            return $token;
        }
        $this->cache->setNx($key, ShopLayoutTags::freshGeneration(), self::TTL);
        $token = $this->cache->get($key);
        return ShopLayoutTags::isGeneration($token) ? $token : null;
    }

    public function rotate(string $tenant): void
    {
        $this->cache->set(self::key($tenant), ShopLayoutTags::freshGeneration(), self::TTL);
    }
}
```

- [ ] **Step 4: The listener rotates first and narrows its fallback**

```php
    public function onCatalogChanged(object $event): void
    {
        if (!$event instanceof StorefrontCatalogChanged) {
            return;
        }
        $cache = $this->container->get(CacheStore::class);
        // Before the purge: a render that read the old catalog now holds a stale guard, so
        // whatever it stores is refused on read (product grid spec §3.2).
        (new CatalogGeneration($cache))->rotate($event->tenantUuid);
        if ($cache->invalidateTags(['thallo:shop:catalog:' . $event->tenantUuid])) {
            return;
        }
        // No tag invalidation: drop the owning workspace's shop pages and rendered pages (a grid
        // can sit on either), never another workspace's.
        $cache->deletePattern('shop:' . $event->tenantUuid . ':*');
        $cache->deletePattern('tenant:' . $event->tenantUuid . ':shop:*');
        // The event names its workspace (the commerce tenant IS the workspace — both resolve
        // through TenancyModePolicy); never the request's, which background or cross-workspace
        // work may not share.
        if ($this->container->has(RenderedPageCachePurge::class)) {
            $this->container->get(RenderedPageCachePurge::class)->purgeWorkspace($event->tenantUuid);
        }
    }
```

Update the class docblock: the fallback is per workspace and reaches rendered pages.

- [ ] **Step 5: `purgeWorkspace()` on the contract, the segment for an explicit workspace**

Contract:

```php
    /**
     * Drop every rendered page of workspace `$tenantUuid` — every rendered page while tenancy is
     * off. The caller names the workspace; it is never taken from the current request.
     */
    public function purgeWorkspace(string $tenantUuid): bool;
```

`TenantCacheSegment`:

```php
    /** The key prefix of workspace `$tenantUuid` ('' while tenancy is off). */
    public function segmentFor(string $tenantUuid, string $surface = 'cache'): string
    {
        if (!$this->flags->tenancyEnabled()) {
            return '';
        }
        if ($tenantUuid === '') {
            throw new MissingTenantForCacheException($surface);
        }
        return 'tenant:' . $tenantUuid . ':';
    }
```

and `segment()` ends with `return $this->segmentFor($tenantUuid, $surface);` after resolving the uuid as today.

`RenderCachePurge` gains `?TenantCacheSegment $tenantCache = null` (passed by its `RenderServiceProvider` factory when bound, as for `RenderPageCache`) and:

```php
    public function purgeWorkspace(string $tenantUuid): bool
    {
        try {
            $prefix = $this->tenantCache?->segmentFor($tenantUuid, 'render') ?? '';
        } catch (MissingTenantForCacheException) {
            // Tenancy on but no workspace named: never guess one — drop every rendered page.
            return $this->purgeAll();
        }
        return $this->cache->deletePattern($prefix . 'render:*');
    }
```

Add `purgeWorkspace(string $tenantUuid): bool { return true; }` to the two anonymous test implementors.

`tests/Integration/Tenancy/CatalogPurgeWorkspaceTest.php` (a `RetrofittedTenantTestCase`, as `CommercePurgePipelineTest`), with a `CacheStore` whose `invalidateTags()` answers false and the real `RenderCachePurge`:

```php
    public function testAnEventForAWhileTheRequestIsInBPurgesAOnly(): void
    {
        [$a, $b] = [$this->provisionTenant(), $this->provisionTenant()]; // the harness's own helpers
        $store = $this->fallbackStore($patterns); // invalidateTags() false; deletePattern() recorded
        $this->runAsTenant($b, function () use ($a, $store): void {
            $this->listenerWith($store)->onCatalogChanged(
                new StorefrontCatalogChanged($a, StorefrontCatalogChanged::REASON_TAG_CHANGED, null),
            );
        });
        self::assertContains('tenant:' . $a . ':render:*', $patterns);
        self::assertNotContains('tenant:' . $b . ':render:*', $patterns);
    }

    public function testAnEventWithNoRequestWorkspaceStillPurgesItsOwn(): void
    {
        $a = $this->provisionTenant();
        $store = $this->fallbackStore($patterns);
        // Outside runAsTenant(): no request workspace (a queue worker's context).
        $this->listenerWith($store)->onCatalogChanged(
            new StorefrontCatalogChanged($a, StorefrontCatalogChanged::REASON_PRODUCT_UPDATED, null),
        );
        self::assertContains('tenant:' . $a . ':render:*', $patterns);
    }
```

Use the harness's real helper names for provisioning and running as a tenant (read `RetrofittedTenantTestCase`); `fallbackStore()` and `listenerWith()` are local helpers built like `CatalogGenerationPurgeTest`'s.

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit tests/Integration/Commerce/CatalogGenerationPurgeTest.php tests/Integration/Tenancy/CatalogPurgeWorkspaceTest.php tests/Integration/Commerce/ShopCacheTest.php tests/Integration/Content/Layouts tests/Integration/Render/RenderPageCacheTest.php tests/Integration/Tenancy`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/thallo-commerce/src packages/thallo-contracts/src packages/thallo-render/src packages/thallo-tenancy/src tests/Integration .github/workflows/ci.yml
git commit -m "feat(commerce): catalog generation rotated on every change; the purge fallback drops the event's workspace's shop and rendered pages"
```

## Task 8: the grid's query — normalizer, seam, cards

**Files:**
- Create: `packages/thallo-contracts/src/Delivery/StorefrontProductGrid.php`, `packages/thallo-contracts/src/Delivery/ProductGridView.php`
- Create: `packages/thallo-commerce/src/Shop/ProductGridQuery.php`, `packages/thallo-commerce/src/Shop/ProductGrid.php`, `packages/thallo-commerce/src/Shop/ViewModels/ProductGridCard.php`
- Modify: `packages/thallo-commerce/src/Http/Shop/ShopProductCardAssembler.php` (add `gridCards()` beside `cards()`)
- Modify: `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php` (bind `StorefrontProductGrid`, beside `StorefrontBlockPreview` ~460)
- Create: `tests/Unit/Commerce/ProductGridQueryTest.php`, `tests/Integration/Commerce/ProductGridTest.php`, `tests/Support/SeedsShopCatalog.php`

**Interfaces:** Consumes Tasks 1–3, 7. Produces `StorefrontProductGrid`, `ProductGridView`, `ProductGridQuery`, `ProductGridCard`, `gridCards()`.

- [ ] **Step 1: Write the failing normalizer test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;
use Thallo\Commerce\Shop\ProductGridQuery;

final class ProductGridQueryTest extends TestCase
{
    public function testDefaults(): void
    {
        $q = ProductGridQuery::fromData([]);
        self::assertSame(['all', [], [], '', false, 'newest', 12, 7], [
            $q->source, $q->categories, $q->tags, $q->products, $q->excludeOutOfStock, $q->orderBy, $q->limit,
            $q->newBadgeDays,
        ]);
    }

    public function testValidValues(): void
    {
        $q = ProductGridQuery::fromData([
            'source' => 'on_sale', 'categories' => ['men', ' women ', 'men', ''], 'tags' => ['summer'],
            'exclude_out_of_stock' => true, 'order_by' => 'price_desc', 'limit' => 4, 'new_badge_days' => 30,
        ]);
        self::assertSame('on_sale', $q->source);
        self::assertSame(['men', 'women'], $q->categories, 'trimmed, deduplicated, blanks dropped');
        self::assertSame(['summer'], $q->tags);
        self::assertTrue($q->excludeOutOfStock);
        self::assertSame('price_desc', $q->orderBy);
        self::assertSame(4, $q->limit);
        self::assertSame(30, $q->newBadgeDays);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function invalid(): iterable
    {
        yield 'removed sources' => [['source' => 'category']];
        yield 'removed newest source' => [['source' => 'newest']];
        yield 'unknown order' => [['order_by' => 'random']];
        yield 'limit low' => [['limit' => 0]];
        yield 'limit high' => [['limit' => 200]];
        yield 'limit text' => [['limit' => 'many']];
        yield 'categories not a list' => [['categories' => 'men']];
        yield 'days high' => [['new_badge_days' => 1000]];
    }

    /** @dataProvider invalid */
    public function testInvalidValuesReadAsDefaultsOrClamped(array $data): void
    {
        $q = ProductGridQuery::fromData($data);
        self::assertSame('all', $q->source);
        self::assertSame('newest', $q->orderBy);
        self::assertContains($q->limit, [1, 12, 48]);
        self::assertSame([], $q->categories);
        self::assertContains($q->newBadgeDays, [7, 365]);
    }

    public function testListsAreCappedAtTwenty(): void
    {
        $slugs = array_map(static fn (int $i): string => 'c' . $i, range(1, 30));
        self::assertCount(20, ProductGridQuery::fromData(['categories' => $slugs])->categories);
    }
}
```

(`#[DataProvider('invalid')]` attribute if the suite uses PHPUnit 10 attributes — check a sibling unit test.)

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Commerce/ProductGridQueryTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: `ProductGridQuery`**

```php
<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Extensions\Commerce\Catalog\ProductSort;

/**
 * A Product grid's query, read from its block data (product grid spec §2): missing or invalid
 * values read as the defaults; nothing from the old block (category/tag/newest sources,
 * category_slug, tag_slug) is mapped (§2.4).
 */
final readonly class ProductGridQuery
{
    public const SOURCES = ['all', 'on_sale', 'manual'];
    public const MAX_SLUGS = 20;

    /**
     * @param list<string> $categories
     * @param list<string> $tags
     */
    public function __construct(
        public string $source,
        public array $categories,
        public array $tags,
        public string $products,
        public bool $excludeOutOfStock,
        public string $orderBy,
        public int $limit,
        public int $newBadgeDays,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromData(array $data): self
    {
        $source = in_array($data['source'] ?? null, self::SOURCES, true) ? $data['source'] : 'all';
        $orderBy = in_array($data['order_by'] ?? null, ProductSort::ALL, true) ? $data['order_by'] : ProductSort::NEWEST;
        return new self(
            $source,
            self::slugs($data['categories'] ?? null),
            self::slugs($data['tags'] ?? null),
            is_string($data['products'] ?? null) ? $data['products'] : '',
            ($data['exclude_out_of_stock'] ?? false) === true,
            $orderBy,
            self::int($data['limit'] ?? null, 1, 48, 12),
            self::int($data['new_badge_days'] ?? null, 1, 365, 7),
        );
    }

    /** @return list<string> */
    private static function slugs(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $slugs = [];
        foreach ($value as $slug) {
            if (is_string($slug) && trim($slug) !== '') {
                $slugs[trim($slug)] = true;
            }
        }
        return array_slice(array_keys($slugs), 0, self::MAX_SLUGS);
    }

    private static function int(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value)) && !is_float($value)) {
            return $default;
        }
        return max($min, min($max, (int) round((float) $value)));
    }
}
```

- [ ] **Step 4: Run it**

Run: `vendor/bin/phpunit tests/Unit/Commerce/ProductGridQueryTest.php`
Expected: PASS.

- [ ] **Step 5: The seed trait for the integration tests**

`tests/Support/SeedsShopCatalog.php` — a trait for `AppTestCase` subclasses with a `TENANT` constant supplied by the using class:

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Helpers\Utils;

/** Catalog rows for storefront tests, written straight to the engine's tables. */
trait SeedsShopCatalog
{
    private static int $catalogSeq = 0;

    /**
     * @param array{price?:int, compare_at?:?int, created_at?:string, stock?:?int, variants?:int, name?:string} $o
     */
    protected function product(string $slug, array $o = []): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_products')->insert([
            'uuid' => $uuid, 'tenant_uuid' => static::TENANT, 'slug' => $slug, 'name' => $o['name'] ?? ucfirst($slug),
            'type' => 'physical', 'status' => 'active', 'created_at' => $o['created_at'] ?? gmdate('Y-m-d H:i:s'),
        ]);
        for ($i = 0; $i < ($o['variants'] ?? 1); $i++) {
            $variant = Utils::generateNanoID();
            $this->connection()->table('commerce_variants')->insert([
                'uuid' => $variant, 'tenant_uuid' => static::TENANT, 'product_uuid' => $uuid,
                'sku' => 'sku-' . (++self::$catalogSeq), 'option_values' => '{}',
                'price' => ($o['price'] ?? 1000) + $i * 100, 'compare_at_price' => $o['compare_at'] ?? null,
                'currency' => 'USD', 'status' => 'active',
            ]);
            if (array_key_exists('stock', $o) && $o['stock'] !== null) {
                $this->connection()->table('commerce_stock')->insert([
                    'uuid' => Utils::generateNanoID(), 'tenant_uuid' => static::TENANT, 'variant_uuid' => $variant,
                    'quantity' => $o['stock'], 'tracked' => true,
                ]);
            }
        }
        return $uuid;
    }

    protected function category(string $slug, string ...$products): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_categories')->insert([
            'uuid' => $uuid, 'tenant_uuid' => static::TENANT, 'slug' => $slug, 'name' => ucfirst($slug),
        ]);
        foreach ($products as $product) {
            $this->connection()->table('commerce_product_categories')->insert(['product_uuid' => $product, 'category_uuid' => $uuid]);
        }
        return $uuid;
    }

    protected function tag(string $slug, string ...$products): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_tags')->insert([
            'uuid' => $uuid, 'tenant_uuid' => static::TENANT, 'slug' => $slug, 'name' => ucfirst($slug),
        ]);
        foreach ($products as $product) {
            $this->connection()->table('commerce_product_tags')->insert(['product_uuid' => $product, 'tag_uuid' => $uuid]);
        }
        return $uuid;
    }

    protected function clearCatalog(): void
    {
        foreach (['commerce_stock', 'commerce_product_addons', 'commerce_product_categories', 'commerce_product_tags',
            'commerce_categories', 'commerce_tags', 'commerce_variants', 'commerce_products'] as $table) {
            $this->connection()->getPDO()->exec('DELETE FROM ' . $table);
        }
    }
}
```

- [ ] **Step 6: Write the failing grid test**

`tests/Integration/Commerce/ProductGridTest.php`, set up like `ShopBlocksTest` (its `setUpBeforeClass`/`tearDownAfterClass` `entries.tenant_uuid` column, `setUp` flags `tenancy.schema_state` = `widened` and `tenancy.default_tenant_uuid` = `self::TENANT`, `clearCatalog()` in setUp/tearDown), `use SeedsShopCatalog;`, `private const TENANT = 'gridtesttena';`, and:

```php
    /** @return list<string> product names in grid order */
    private function names(array $data): array
    {
        $view = $this->container()->get(StorefrontProductGrid::class)->grid($data);
        return array_map(static fn (array $c): string => (string) $c['name'], $view->cards);
    }

    public function testAllProductsNewestFirstCutToTheCount(): void
    {
        $this->product('a', ['created_at' => '2026-01-01 00:00:00']);
        $this->product('b', ['created_at' => '2026-01-03 00:00:00']);
        $this->product('c', ['created_at' => '2026-01-02 00:00:00']);
        self::assertSame(['B', 'C'], $this->names(['limit' => 2]));
    }

    public function testCategoriesAnyOfDeduplicatedAndAndedWithTags(): void
    {
        $men = $this->product('m');
        $women = $this->product('w');
        $both = $this->product('x');
        $this->product('none');
        $this->category('men', $men, $both);
        $this->category('women', $women, $both);
        $this->tag('summer', $both);
        self::assertEqualsCanonicalizing(['M', 'W', 'X'], $this->names(['categories' => ['men', 'women']]));
        self::assertSame(['X'], $this->names(['categories' => ['men', 'women'], 'tags' => ['summer']]));
    }

    public function testDeletedCategoriesShowNothingNeverEverything(): void
    {
        $this->product('anything');
        self::assertSame([], $this->names(['categories' => ['gone', 'also-gone']]));
        $this->category('real', $this->product('kept'));
        self::assertSame(['Kept'], $this->names(['categories' => ['gone', 'real']]), 'an unknown slug among real ones is ignored');
    }

    public function testOnSaleAndExcludeOutOfStockAndPriceOrder(): void
    {
        $this->product('sale', ['price' => 800, 'compare_at' => 1000, 'stock' => 2]);
        $this->product('soldout', ['price' => 500, 'compare_at' => 1000, 'stock' => 0]);
        $this->product('full', ['price' => 1000]);
        self::assertSame(['Soldout', 'Sale'], $this->names(['source' => 'on_sale', 'order_by' => 'price_asc']));
        self::assertSame(['Sale'], $this->names(['source' => 'on_sale', 'exclude_out_of_stock' => true]));
    }

    public function testManualKeepsTheListedOrderAndHonoursOnlyExcludeOutOfStock(): void
    {
        $this->product('first', ['stock' => 0]);
        $this->product('second');
        $this->product('third');
        $this->category('ignored', $this->product('other'));
        $data = ['source' => 'manual', 'products' => "third\nfirst\nmissing\nsecond", 'categories' => ['ignored'],
            'order_by' => 'name'];
        self::assertSame(['Third', 'First', 'Second'], $this->names($data));
        self::assertSame(['Third', 'Second'], $this->names($data + ['exclude_out_of_stock' => true]));
    }

    public function testViewAllIsTheCategoryPageOnlyForExactlyOneCategoryAndNoTags(): void
    {
        $p = $this->product('p');
        $this->category('shoes', $p);
        $this->tag('summer', $p);
        $this->category('boots', $p);
        $grid = $this->container()->get(StorefrontProductGrid::class);
        $shop = (string) $grid->grid([])->viewAllUrl;
        self::assertStringEndsWith('/shoes', (string) $grid->grid(['categories' => ['shoes']])->viewAllUrl);
        self::assertStringEndsWith('/shoes', (string) $grid->grid(['source' => 'on_sale', 'categories' => ['shoes']])->viewAllUrl);
        self::assertSame($shop, $grid->grid(['categories' => ['shoes'], 'tags' => ['summer']])->viewAllUrl);
        self::assertSame($shop, $grid->grid(['categories' => ['shoes', 'boots']])->viewAllUrl);
        self::assertSame($shop, $grid->grid(['source' => 'manual', 'products' => 'p'])->viewAllUrl);
    }

    public function testCardsCarryEveryCategoryTagsSaleAndNewFlags(): void
    {
        $p = $this->product('p', ['price' => 800, 'compare_at' => 1000, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days'))]);
        $old = $this->product('old', ['created_at' => gmdate('Y-m-d H:i:s', strtotime('-30 days'))]);
        $this->category('women', $p);
        $this->category('men', $p);
        $this->tag('summer', $p);
        $cards = $this->container()->get(StorefrontProductGrid::class)->grid(['new_badge_days' => 7])->cards;
        $byName = array_column($cards, null, 'name');
        self::assertSame(['Men', 'Women'], array_column($byName['P']['categories'], 'name'));
        self::assertSame(['Summer'], array_column($byName['P']['tags'], 'name'));
        self::assertTrue($byName['P']['onSale']);
        self::assertTrue($byName['P']['isNew']);
        self::assertFalse($byName['Old']['isNew']);
        self::assertFalse($byName['Old']['onSale']);
    }

    public function testTheViewNamesTheStorageTagAndTheCurrentGeneration(): void
    {
        $view = $this->container()->get(StorefrontProductGrid::class)->grid([]);
        self::assertSame('thallo:shop:catalog:' . self::TENANT, $view->storageTag);
        self::assertSame(CatalogGeneration::key(self::TENANT), $view->guardKey);
        self::assertSame((new CatalogGeneration($this->container()->get(CacheStore::class)))->read(self::TENANT), $view->guardValue);
    }
```

Category names in `testCardsCarry…` sort by position then name (both position 0): Men, Women.

- [ ] **Step 7: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Commerce/ProductGridTest.php`
Expected: FAIL — `Thallo\Contracts\Delivery\StorefrontProductGrid` not found.

- [ ] **Step 8: The seam**

`StorefrontProductGrid.php` and `ProductGridView.php` exactly as in Shared contracts, each with a docblock naming product grid spec §3 and "soft-bound — the commerce pack implements it; without it `product_grid()` answers null and the block renders nothing".

- [ ] **Step 9: `ProductGridCard` and `gridCards()`**

```php
<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

/**
 * A Product grid's card (product grid spec §5, §6): the closed shop card plus what only the grid
 * shows — every category, the tags, and whether it is on sale and new. The closed card's own
 * keys stay pinned; these sit beside them.
 */
final readonly class ProductGridCard
{
    /**
     * @param list<array{name:string,slug:string}> $categories
     * @param list<array{name:string,slug:string}> $tags
     */
    public function __construct(
        public ProductCardViewModel $card,
        public array $categories,
        public array $tags,
        public bool $onSale,
        public bool $isNew,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->card->toCardItem() + [
            'categories' => $this->categories,
            'tags' => $this->tags,
            'onSale' => $this->onSale,
            'isNew' => $this->isNew,
        ];
    }
}
```

In `ShopProductCardAssembler` (inject `TagRepository` in the constructor, after `CategoryRepository`):

```php
    /**
     * The Product grid's cards (product grid spec §5, §6): {@see self::cards()} plus every
     * category and tag (one batched read each), on sale (any active variant priced below its
     * compare-at price — the engine's onSale rule) and new (created within `$newBadgeDays`).
     *
     * @param list<array<string,mixed>> $products
     * @return list<ProductGridCard>
     */
    public function gridCards(string $tenant, array $products, int $newBadgeDays, \DateTimeImmutable $now): array
    {
        $uuids = array_map(static fn (array $p): string => (string) $p['uuid'], $products);
        $cards = $this->cards($tenant, $products);
        $categories = $this->categories->categoryProjectionsForProducts($this->context, $tenant, $uuids);
        $tags = $this->tags->tagProjectionsForProducts($this->context, $tenant, $uuids);
        $variants = $this->variants->forProducts($this->context, $tenant, $uuids);
        $newSince = $now->modify('-' . $newBadgeDays . ' days');

        $items = [];
        foreach ($products as $i => $product) {
            $uuid = (string) $product['uuid'];
            $onSale = false;
            foreach ($variants[$uuid] ?? [] as $variant) {
                $compareAt = $variant['compare_at_price'] ?? null;
                if (($variant['status'] ?? null) === 'active' && $compareAt !== null && (int) $compareAt > (int) $variant['price']) {
                    $onSale = true;
                }
            }
            $created = is_string($product['created_at'] ?? null) ? new \DateTimeImmutable($product['created_at']) : null;
            $items[] = new ProductGridCard(
                $cards[$i],
                $categories[$uuid] ?? [],
                $tags[$uuid] ?? [],
                $onSale,
                $created !== null && $created >= $newSince,
            );
        }
        return $items;
    }
```

`cards()` reads variants too; a second `forProducts` call keeps `cards()` untouched — record that in the ledger as a known extra query (bounded: one per grid).

- [ ] **Step 10: `ProductGrid`**

```php
<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Contracts\CommerceTenantResolution;
use Thallo\Commerce\Http\Shop\ShopProductCardAssembler;
use Thallo\Contracts\Delivery\ProductGridView;
use Thallo\Contracts\Delivery\StorefrontProductGrid;

/**
 * The Product grid's products (product grid spec §2, §3): the source narrowed by categories and
 * tags, ordered and cut, as grid cards. Reads the catalog generation BEFORE the products, so the
 * render's guard is never newer than what it shows.
 */
final class ProductGrid implements StorefrontProductGrid
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly ShopProductCardAssembler $cards,
        private readonly ShopUrlGenerator $urls,
        private readonly CacheStore $cache,
    ) {
    }

    public function grid(array $data): ProductGridView
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $guard = (new CatalogGeneration($this->cache))->read($tenant);
        $query = ProductGridQuery::fromData($data);
        $rows = $query->source === 'manual' ? $this->manual($tenant, $query) : $this->listed($tenant, $query);
        $cards = array_map(
            static fn ($card): array => $card->toArray(),
            $this->cards->gridCards($tenant, $rows, $query->newBadgeDays, new \DateTimeImmutable('now')),
        );
        return new ProductGridView(
            $cards,
            $this->viewAll($query),
            'thallo:shop:catalog:' . $tenant,
            CatalogGeneration::key($tenant),
            $guard,
        );
    }

    /** @return list<array<string,mixed>> */
    private function listed(string $tenant, ProductGridQuery $query): array
    {
        $categoryUuids = $this->resolve($query->categories, fn (string $s): ?string => $this->categories->findUuidBySlug($this->context, $tenant, $s));
        $tagUuids = $this->resolve($query->tags, fn (string $s): ?string => $this->tags->findUuidBySlug($this->context, $tenant, $s));
        // Chosen but all gone: nothing, never everything (§2.2).
        if (($query->categories !== [] && $categoryUuids === []) || ($query->tags !== [] && $tagUuids === [])) {
            return [];
        }
        $filters = new ResolvedProductFilters(
            $categoryUuids,
            $tagUuids,
            [],
            $query->source === 'on_sale',
            $query->excludeOutOfStock,
        );
        return $this->products->listActive($this->context, $tenant, 1, $query->limit, $filters, $query->orderBy)['items'];
    }

    /** @return list<array<string,mixed>> the listed products, in order, at most `limit` */
    private function manual(string $tenant, ProductGridQuery $query): array
    {
        try {
            $slugs = ManualProductListNormalizer::normalize($query->products);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $rows = [];
        foreach ($slugs as $slug) {
            $row = $this->products->findBuyerAvailableBySlug($this->context, $tenant, $slug);
            if ($row !== null && ($row['status'] ?? null) === 'active') {
                $rows[] = $row;
            }
        }
        if ($query->excludeOutOfStock && $rows !== []) {
            $inStock = array_column(
                $this->products->activeFilteredQuery($this->context, $tenant, new ResolvedProductFilters(inStock: true))
                    ->whereIn('uuid', array_map(static fn (array $r): string => (string) $r['uuid'], $rows))
                    ->select(['uuid'])->get(),
                'uuid',
            );
            $rows = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['uuid'], $inStock, true)));
        }
        return array_slice($rows, 0, $query->limit);
    }

    /**
     * @param list<string> $slugs
     * @param \Closure(string): ?string $find
     * @return list<string>
     */
    private function resolve(array $slugs, \Closure $find): array
    {
        return array_values(array_filter(array_map($find, $slugs), static fn (?string $u): bool => $u !== null));
    }

    private function viewAll(ProductGridQuery $query): ?string
    {
        if ($query->source !== 'manual' && count($query->categories) === 1 && $query->tags === []) {
            return $this->urls->category($query->categories[0]);
        }
        return $this->urls->shopIndex();
    }
}
```

Read `ShopBlockDataController::manualRows()` (L230–247) before writing `manual()` and keep its exact availability rule; match `CommerceTenantResolution`'s namespace to the import `ShopPageCache` uses.

- [ ] **Step 11: Bind it**

In `CommerceIntegrationServiceProvider`'s service map, beside `StorefrontBlockPreview`:

```php
            // Product grid spec §3.1: the grid's products for `product_grid()`, soft-bound by the
            // render pack like the block preview above.
            \Thallo\Contracts\Delivery\StorefrontProductGrid::class => [
                'class' => \Thallo\Commerce\Shop\ProductGrid::class,
                'shared'  => true,
                'autowire' => true,
            ],
```

- [ ] **Step 12: Run the tests**

Run: `vendor/bin/phpunit tests/Integration/Commerce/ProductGridTest.php tests/Unit/Commerce/ProductGridQueryTest.php`
Expected: PASS.

- [ ] **Step 13: Commit**

```bash
git add packages/thallo-contracts/src/Delivery packages/thallo-commerce/src tests/Unit/Commerce tests/Integration/Commerce/ProductGridTest.php tests/Support/SeedsShopCatalog.php
git commit -m "feat(commerce): the Product grid's query — source plus filters, order, count, grid cards"
```

## Task 9: content fields — `multiple` option sources, labels, help, starter content

**Files:**
- Modify: `core/src/Content/Schema/FieldDefinition.php:205-216`
- Modify: `core/src/Content/Validation/FieldValidator.php:294` (a branch before the reference/asset one)
- Modify: `packages/thallo-contracts/src/Starter/StarterBlockTypeDefinition.php`, `core/src/Content/Starter/Kinds/BlockTypeKind.php:276-282`
- Create: `packages/thallo-commerce/src/Fields/CategoryOptionSource.php`, `packages/thallo-commerce/src/Fields/TagOptionSource.php`; register them in `CommerceIntegrationServiceProvider` (the capability-enabled branch, like `SearchServiceProvider::registerBlockType()`)
- Modify (admin): `admin/src/fields/types.ts`, `admin/src/fields/normalize.ts`, `admin/src/queries/contentTypes.ts`, `admin/src/fields/components/OptionsSourceField.vue`, `admin/src/fields/components/blocks/BlockFields.vue`
- Create: `tests/Integration/Content/MultipleOptionSourceFieldTest.php`, `tests/Integration/Commerce/CommerceOptionSourcesTest.php`; extend `admin/src/__tests__/optionsSourceField.spec.ts`; create `admin/src/__tests__/blockFieldsHelp.spec.ts`

**Interfaces:** Produces `multiple` on `string` + `options_source` (value `list<string>`), `FieldDef.help`, `StarterBlockTypeDefinition::$starterContent`, the sources `thallo-commerce.categories` and `thallo-commerce.tags`.

- [ ] **Step 1: Write the failing server test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Schema\FieldDefinition;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;

/** Product grid spec §5.3: an option-source string field may hold several values. */
final class MultipleOptionSourceFieldTest extends AppTestCase
{
    private function field(array $extra = []): FieldDefinition
    {
        return FieldDefinition::fromArray(['name' => 'categories', 'type' => 'string', 'multiple' => true,
            'max_items' => 20, 'options_source' => 'thallo-commerce.categories'] + $extra);
    }

    public function testMoreThanMaxItemsIsRefusedAndTheLimitIsKept(): void
    {
        self::assertSame(20, $this->field()->maxItems);
        [, $errors] = $this->validate(array_map(static fn (int $i): string => 'c' . $i, range(1, 21)));
        self::assertArrayHasKey('categories', $errors);
        [$clean, $ok] = $this->validate(array_map(static fn (int $i): string => 'c' . $i, range(1, 20)));
        self::assertSame([], $ok);
        self::assertCount(20, $clean['categories']);
    }

    public function testEachItemMeetsTheStringFieldsConstraints(): void
    {
        // A pattern on the field applies to every item, as it would to a single value.
        [, $errors] = $this->validate(['ok-slug', 'Not A Slug!'], ['pattern' => '[a-z0-9-]+']);
        self::assertArrayHasKey('categories', $errors);
    }

    public function testMultipleIsKeptOnAnOptionSourceStringAndDroppedOnAPlainOne(): void
    {
        self::assertTrue($this->field()->multiple);
        self::assertFalse(FieldDefinition::fromArray(['name' => 'x', 'type' => 'string', 'multiple' => true])->multiple);
    }

    public function testAListOfStringsIsValidAndDeduplicated(): void
    {
        [$clean, $errors] = $this->validate(['men', 'women', 'men']);
        self::assertSame([], $errors);
        self::assertSame(['men', 'women'], $clean['categories']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'a string' => ['men'];
        yield 'a number in the list' => [['men', 3]];
        yield 'a map' => [['a' => 'men']];
        yield 'too long' => [[str_repeat('x', 192)]];
    }

    /** @dataProvider invalid */
    public function testAnythingElseIsRefused(mixed $value): void
    {
        [, $errors] = $this->validate($value);
        self::assertArrayHasKey('categories', $errors);
    }

    /**
     * @param array<string,mixed> $extra extra field schema keys
     * @return array{array<string,mixed>, array<string,string>}
     */
    private function validate(mixed $value, array $extra = []): array
    {
        // Use FieldValidator the way its existing tests do (find one with
        // `grep -rln "new FieldValidator\|FieldValidator::class" tests`), with a schema of $this->field().
    }
}
```

Before writing `validate()`, open the existing FieldValidator test it names and copy its construction exactly — then replace the comment with that code. The "too long" case relies on the string field's own length constraint reached through `checkConstraints()`; if string fields have no default length bound (`grep -n "max_length\|maxLength\|191" core/src/Content/Validation/FieldValidator.php`), give the test field `'max_length' => 191` (or the key `checkConstraints()` reads) and keep the case.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Content/MultipleOptionSourceFieldTest.php`
Expected: FAIL — `multiple` is false on the string field.

- [ ] **Step 3: Parse and validate**

`FieldDefinition` (replace the comment and the `if`):

```php
        // `multiple` and `max_items` apply to reference and asset fields, and to a string field
        // whose choices come from an options source (product grid spec §5.3): its value is a list
        // of the chosen option values.
        $multiple = false;
        $maxItems = null;
        $optionSourceString = $type === 'string' && is_string($raw['options_source'] ?? null) && $raw['options_source'] !== '';
        if ($type === 'reference' || $type === 'asset' || $optionSourceString) {
            $multiple = (bool) ($raw['multiple'] ?? false);
```

(the `max_items` lines unchanged). Update the property's docblock to name option-source strings.

`FieldValidator`, before the reference/asset `multiple` branch:

```php
            // A multi-valued option-source string (product grid spec §5.3): a list of strings,
            // deduplicated in order. Whether each is still an option is the admin's concern
            // (a removed category stays stored and shows unavailable).
            if ($field->type === 'string' && $field->multiple) {
                if (!is_array($value) || !array_is_list($value)) {
                    $errors[$field->name] = 'must be a list of strings';
                    continue;
                }
                $items = [];
                foreach ($value as $item) {
                    // Each item meets what a single value of this string field must (type, length,
                    // pattern — checkType/checkConstraints, as for a single value).
                    $itemError = is_string($item)
                        ? ($this->checkType($field, $item) ?? $this->checkConstraints($field, $item))
                        : 'must be a list of strings';
                    if ($itemError !== null) {
                        $errors[$field->name] = $itemError;
                        continue 2;
                    }
                    if (!in_array($item, $items, true)) {
                        $items[] = $item;
                    }
                }
                if ($field->maxItems !== null && count($items) > $field->maxItems) {
                    $errors[$field->name] = 'must have at most ' . $field->maxItems . ' items';
                    continue;
                }
                $clean[$field->name] = $items;
                continue;
            }
```

- [ ] **Step 4: Starter content on contributed block types**

`StarterBlockTypeDefinition`, after `$flags`:

```php
        /** Data a new block of this type starts with (inserted by the editor). @var array<string,mixed>|null */
        public ?array $starterContent = null,
```

`BlockTypeKind` payload: add `'starter_content' => $definition->starterContent,`.

- [ ] **Step 5: The commerce option sources and their test**

```php
<?php

declare(strict_types=1);

namespace Thallo\Commerce\Fields;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Contracts\CommerceTenantResolution;
use Thallo\Contracts\Fields\FieldOptionSource;

/** The store's categories for a block field (product grid spec §5.3): value slug, label name. */
final class CategoryOptionSource implements FieldOptionSource
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly CategoryRepository $categories,
    ) {
    }

    public function id(): string
    {
        return 'thallo-commerce.categories';
    }

    /** The catalogue's own read permission (spec §5.3) — `commerce.manage` grants satisfy it too. */
    public function permission(): string
    {
        return 'commerce.view';
    }

    public function options(): array
    {
        $rows = $this->categories->all($this->context, $this->tenants->tenantUuid($this->context));
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
        return array_map(static fn (array $row): array => [
            'value' => (string) $row['slug'], 'label' => (string) $row['name'], 'available' => true, 'reason' => null,
        ], $rows);
    }
}
```

`TagOptionSource` is the same with `TagRepository`, id `thallo-commerce.tags`. Register both where the pack's capability-enabled services are registered:

```php
        if ($container->has(\Thallo\Contracts\Fields\FieldOptionSourceRegistry::class)) {
            $registry = $container->get(\Thallo\Contracts\Fields\FieldOptionSourceRegistry::class);
            $registry->register($container->get(\Thallo\Commerce\Fields\CategoryOptionSource::class));
            $registry->register($container->get(\Thallo\Commerce\Fields\TagOptionSource::class));
        }
```

`tests/Integration/Commerce/CommerceOptionSourcesTest.php` (ShopBlocksTest-style setup, `use SeedsShopCatalog`): seed categories `women`, `Men` and tags; request `GET {apiBase}/field-options/thallo-commerce.categories` the way `tests` already call the search scopes source (`grep -rln "field-options" tests`), assert `[{value: 'Men'…}, {value: 'women'…}]` order by name case-insensitively, `available` true. Permission cases, both sources: a **content editor without `commerce.view`** (holds `content.edit` only) gets 403; with `commerce.view` 200; with `commerce.manage` only — check how `FieldOptionsController` checks the permission: if it does not treat `commerce.manage` as satisfying `commerce.view` the way `CommerceMetaController` does, assert the 403 and record in the ledger that a manage-only role cannot list choices.

- [ ] **Step 6: Run the server tests**

Run: `vendor/bin/phpunit tests/Integration/Content/MultipleOptionSourceFieldTest.php tests/Integration/Commerce/CommerceOptionSourcesTest.php tests/Integration/Blocks`
Expected: PASS (the block-type fingerprint tests still pass: the payload's `starter_content` is null for every existing contribution).

- [ ] **Step 7: Write the failing admin tests**

In `admin/src/__tests__/optionsSourceField.spec.ts`, add (reusing that file's mount helper and its mocked `useFieldOptions`):

```ts
describe('a multiple options-source field', () => {
  it('selects several values and emits the list', async () => {
    const w = mountField({ multiple: true }, ['men'])
    const select = w.findComponent({ name: 'USelectMenu' })
    expect(select.props('multiple')).toBe(true)
    expect(select.props('modelValue')).toEqual(['men'])
    await select.vm.$emit('update:modelValue', ['men', 'women'])
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual([['men', 'women']])
  })
  it('keeps a stored value that is no longer an option and shows it unavailable', () => {
    const w = mountField({ multiple: true }, ['gone', 'men'])
    expect(w.find('[data-test="options-source-categories-gone"]').exists()).toBe(true)
    expect(w.findComponent({ name: 'USelectMenu' }).props('modelValue')).toEqual(['gone', 'men'])
  })
  it('never emits more than maxItems and says so at the limit', async () => {
    const twenty = Array.from({ length: 20 }, (_, i) => `c${i}`)
    const w = mountField({ multiple: true, maxItems: 20 }, twenty)
    expect(w.find('[data-test="options-source-limit-categories"]').exists()).toBe(true)
    await w.findComponent({ name: 'USelectMenu' }).vm.$emit('update:modelValue', [...twenty, 'c20'])
    expect((w.emitted('update:modelValue')?.at(-1) as string[][])[0]).toHaveLength(20)
  })
  it('reads a non-list value as no selection', () => {
    const w = mountField({ multiple: true }, 'men')
    expect(w.findComponent({ name: 'USelectMenu' }).props('modelValue')).toEqual([])
  })
})
```

Adapt `mountField(extraFieldProps, modelValue)` to the file's existing helper signature (read it first; add the `multiple` and `name: 'categories'` props through it). Create `admin/src/__tests__/blockFieldsHelp.spec.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BlockFields from '@/fields/components/blocks/BlockFields.vue'

describe('block field labels and help', () => {
  it('shows the schema label and the help under the field', () => {
    const w = mount(BlockFields, {
      props: {
        block: { id: 'b1', type: 'product-grid', data: {}, settings: {} },
        schema: [{ name: 'limit', label: 'Products to show', type: 'number', help: 'Columns step down on phones.' }],
      } as never,
    })
    expect(w.text()).toContain('Products to show')
    expect(w.find('[data-test="field-help-limit"]').text()).toBe('Columns step down on phones.')
  })
})
```

(Match `BlockFields`' real props by reading its `defineProps` — it may take the block type rather than a bare schema; pass whatever carries the schema.)

- [ ] **Step 8: Run them to verify they fail**

Run: `cd admin && npx vitest run src/__tests__/optionsSourceField.spec.ts src/__tests__/blockFieldsHelp.spec.ts`
Expected: FAIL.

- [ ] **Step 9: Implement the admin side**

`queries/contentTypes.ts` `ContentTypeField`: add `/** Guidance shown under the field in the editor. */ help?: string | null` and `options_source?: string | null` if absent. `fields/types.ts` `FieldDef`: add `help?: string`. `fields/normalize.ts`: add `label: f.label ?? undefined,` and `help: f.help ?? undefined,`.

`OptionsSourceField.vue` — accept `multiple`:

```vue
<script setup lang="ts">
import { computed, toRef } from 'vue'
import type { FieldDef } from '../types'
import { useFieldOptions } from '@/queries/fieldOptions'
import UnavailableChoice from './UnavailableChoice.vue'
import { fromSelectValue, optionItems, toSelectValue } from '../optionsSourceItems'

// A string field whose choices come from the server (`options_source`, search block spec §3.9).
// The stored value is never rewritten: while the choices load, if they fail to load, or if a
// stored one is no longer available, it is shown as it is and saved unchanged. With `multiple`
// (product grid spec §5.3) the value is a list and the control a multi-select.
const props = defineProps<{ field: FieldDef & { optionsSource: string }; modelValue?: unknown }>()
const emit = defineEmits<{ 'update:modelValue': [value: string | string[]] }>()

const { data, status } = useFieldOptions(toRef(() => props.field.optionsSource))
const items = computed(() => optionItems(data.value ?? []))

const stored = computed(() => (typeof props.modelValue === 'string' ? props.modelValue : ''))
const match = computed(() => data.value?.find((o) => o.value === stored.value))
const unavailable = computed(() => {
  if (status.value !== 'success') return { kind: 'loading' as const }
  if (!match.value) return stored.value === '' ? null : { kind: 'removed' as const }
  return match.value.available
    ? null
    : { kind: 'disabled' as const, label: match.value.label, reason: match.value.reason }
})
const selected = computed(() => toSelectValue(stored.value))
const choose = (v: unknown) => emit('update:modelValue', fromSelectValue(v))

const storedList = computed<string[]>(() =>
  Array.isArray(props.modelValue) ? props.modelValue.filter((v): v is string => typeof v === 'string') : [],
)
const unavailableInList = computed(() =>
  status.value !== 'success'
    ? []
    : storedList.value.filter((v) => !data.value?.some((o) => o.value === v && o.available)),
)
// At most `maxItems` (the server refuses more): a choice past the limit is not taken.
const atLimit = computed(() => props.field.maxItems !== undefined && storedList.value.length >= props.field.maxItems)
const chooseMany = (v: unknown) => {
  const next = Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : []
  const max = props.field.maxItems
  emit('update:modelValue', max !== undefined && next.length > max ? next.slice(0, max) : next)
}
</script>

<template>
  <UFormField :label="field.label ?? field.name" :name="field.name">
    <div v-if="field.multiple" class="space-y-1.5">
      <UnavailableChoice
        v-for="value in unavailableInList"
        :key="value"
        :state="{ kind: 'removed' }"
        :value="value"
        :data-test="`options-source-${field.name}-${value}`"
      />
      <USelectMenu
        :model-value="storedList"
        :items="items"
        value-key="value"
        multiple
        class="w-full"
        :loading="status === 'pending'"
        :data-test="`options-source-select-${field.name}`"
        @update:model-value="chooseMany"
      />
      <p v-if="atLimit" class="text-xs text-muted" :data-test="`options-source-limit-${field.name}`">
        At most {{ field.maxItems }}.
      </p>
    </div>
    <div v-else class="space-y-1.5">
      <UnavailableChoice
        v-if="unavailable"
        :state="unavailable"
        :value="stored"
        :data-test="`options-source-${field.name}`"
      />
      <USelect
        v-if="status === 'success'"
        :model-value="selected"
        :items="items"
        class="w-full"
        :data-test="`options-source-select-${field.name}`"
        @update:model-value="choose"
      />
    </div>
  </UFormField>
</template>
```

Check `optionItems()`' item shape (`fields/optionsSourceItems.ts`): if it maps the value through `toSelectValue` (an empty-string sentinel), map `value` back in `chooseMany` with `fromSelectValue` per item.

`BlockFields.vue`: the `OptionsSourceField` emit handler type becomes `(v: string | string[]) => patchData(f.name, v)`; after the field chain inside the row template, render the help once:

```vue
        <p
          v-if="toFieldDef(f).help"
          class="mt-1 text-xs text-muted"
          :data-test="`field-help-${f.name}`"
        >
          {{ toFieldDef(f).help }}
        </p>
```

placed inside `DefineFieldRow`'s template after the last `<component … v-else />` (read lines 100–160 to place it inside the same row wrapper).

- [ ] **Step 10: Run the admin gates**

Run: `cd admin && npx vitest run && pnpm type-check && pnpm lint && pnpm exec oxfmt --check src/fields src/queries/contentTypes.ts src/__tests__/optionsSourceField.spec.ts src/__tests__/blockFieldsHelp.spec.ts`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add core/src packages/thallo-contracts/src packages/thallo-commerce/src admin/src tests/Integration/Content/MultipleOptionSourceFieldTest.php tests/Integration/Commerce/CommerceOptionSourcesTest.php
git commit -m "feat(fields): multi-select option-source fields, field labels and help, starter content for contributed blocks; commerce category and tag sources"
```

## Task 10: the block — schema, server render, display, badges; the old endpoint goes

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (constructor param `?StorefrontProductGrid $productGrid = null` after `$blockPreview`; Twig function; method), `packages/thallo-render/src/RenderServiceProvider.php:801` (soft-bind like `blockPreview`), `packages/thallo-render/src/Templates/TemplatePolicy.php:105` (allow `product_grid`)
- Modify: `packages/thallo-commerce/src/Starter/ShopBlockTypesContributor.php:43-67` (schema, starter content **and the seven part declarations** — the templates call `style_classes('<part>')`, and `RenderContextExtension::styleFrame()` throws for an undeclared part)
- Rewrite: `packages/thallo-commerce/templates/blocks/product-grid.twig`
- Create: `packages/thallo-commerce/templates/shop/_grid_card.twig`
- Modify: `packages/thallo-commerce/templates/shop/_product_tile.twig` (optional `link_media`, `media_class`, `action_class`, `show_cart`, `show_wishlist`, `badges`, `no_media` — defaults keep today's output byte-identical)
- Modify: `packages/thallo-commerce/src/Patterns/ShopPatternsContributor.php:196-199`
- Remove: `ShopBlockDataController::productGrid()` and its helpers; the route at `packages/thallo-commerce/routes/shop-routes.php:199`; `hydrateProductGrids`/`hydrateProductGrid`/`renderProductGrid` and the `shop-product-grid` registration in `assets/shop.js`; the endpoint entry in `admin/src/api/core-schema.d.ts:87-94` and `docs/openapi.json` (hand-splice; `docs:openapi` needs `CACHE_DRIVER=array`)
- Modify tests: `tests/Integration/Commerce/ShopBlocksTest.php` (remove the endpoint tests 438–560 and the template tests 673–705; update `testProductGridSchemaHasTheDocumentedFieldsAndEnums`), `ShopBlockSelectionTest.php:187-240`, `StorefrontInertnessTest.php:122,244`, `ShopJsRuntimeTest.php:1287,1516,1676-1730`, `tests/Integration/Render/RuntimeShopCoexistenceTest.php:457`, `ShopPatternsTest.php` (grid data)
- Create: `tests/Integration/Commerce/ProductGridBlockTest.php`, `tests/Integration/Commerce/ProductGridCacheTest.php`, `tests/Integration/Commerce/ProductGridStyleTest.php`

**Interfaces:** Consumes Tasks 6, 8, 9. Produces the Twig `product_grid(data)`, the seven parts (`card`, `image`, `title`, `price`, `meta`, `button`, `badge`), and the grid markup classes later tasks style: root `thallo-block-product-grid` + modifiers `--cols-N`, `--card-{lift|shadow}`, `--image-{portrait|landscape}`, `--image-cover`, `--image-zoom`, `--empty`; card `shop-grid__item`; `shop-grid__media`, `shop-grid__badges shop-grid__badges--{top-left|top-right}`, `shop-grid__badge shop-grid__badge--{sale|new}`, `shop-grid__media-link` (the image link), `shop-grid__name` (heading) with `shop-grid__name-link` (its anchor, carrying the `title` part), `shop-grid__labels`, `shop-grid__label shop-grid__label--{category|tag}`, `shop-grid__price`, `shop-grid__action`.

- [ ] **Step 1: Write the failing block test**

`tests/Integration/Commerce/ProductGridBlockTest.php` (ShopBlocksTest setup, `use SeedsShopCatalog`, TENANT `gridblocktst`), with a `renderBlock(array $data, bool $stage = false): string` copied from `ShopBlocksTest::renderBlock()` plus `$extension->setAnnotationScope($stage ? 'canvas' : 'none')` — check how `ShopBlockSelectionTest::render()` puts the extension in stage mode and copy that instead if it differs. Tests:

```php
    public function testTheCardsRenderOnTheServerInGridOrder(): void
    {
        $this->product('first', ['created_at' => '2026-01-02 00:00:00']);
        $this->product('second', ['created_at' => '2026-01-01 00:00:00']);
        $html = $this->renderBlock(['limit' => 12]);
        self::assertSame(2, substr_count($html, 'class="shop-grid__item'));
        self::assertLessThan(strpos($html, 'Second'), strpos($html, 'First'));
        self::assertStringNotContainsString('data-shop-grid-items', $html, 'no client-filled shell');
        self::assertStringContainsString('/_thallo/shop/shop.js', $html);
    }

    public function testTheStageShowsTheSameCardsWithoutTheScript(): void
    {
        $this->product('first');
        $stage = $this->renderBlock([], true);
        self::assertStringContainsString('First', $stage);
        self::assertStringNotContainsString('/_thallo/shop/shop.js', $stage);
    }

    public function testAnEmptyResultIsAPlaceholderOnTheStageAndAnEmptyRootOnTheSite(): void
    {
        self::assertStringContainsString('Product grid — no products match', $this->renderBlock([], true));
        $site = $this->renderBlock([]);
        self::assertStringContainsString('thallo-block-product-grid--empty', $site);
        self::assertStringNotContainsString('no products match', $site);
    }

    public function testTheExactDisplayDefaults(): void
    {
        $p = $this->product('p', ['price' => 800, 'compare_at' => 1000]);
        $this->category('women', $p);
        $this->category('men', $p);
        $this->tag('summer', $p);
        $html = $this->renderBlock([]);
        self::assertStringContainsString('<img class="shop-grid__image', $html);
        self::assertMatchesRegularExpression('~<h3 class="shop-grid__name"><a class="shop-grid__name-link[^"]*" href="[^"]+">P</a></h3>~', $html);
        self::assertMatchesRegularExpression('~<a class="shop-grid__media-link" href="[^"]+" tabindex="-1" aria-hidden="true">\s*<span class="shop-grid__media~', $html, 'the image links to the product');
        self::assertStringContainsString('shop-grid__rating', $html);
        self::assertStringContainsString('shop-grid__price', $html);
        self::assertSame(['Men', 'Women'], $this->labels($html, 'category'));
        self::assertSame([], $this->labels($html, 'tag'), 'tags are off by default');
        self::assertStringContainsString('shop-grid__action--cart', $html);
        self::assertStringContainsString('data-shop-wishlist-toggle', $html);
        self::assertStringNotContainsString('shop-grid__badge', $html, 'badges are off by default');
    }

    public function testEachToggleTurnsItsPartOff(): void
    {
        $p = $this->product('p');
        $this->tag('summer', $p);
        $html = $this->renderBlock(['show_image' => false, 'show_title' => false, 'show_price' => false,
            'show_rating' => false, 'show_categories' => false, 'show_tags' => true, 'show_add_to_cart' => false,
            'show_wishlist' => false]);
        foreach (['<img', 'shop-grid__name', 'shop-grid__price', 'shop-grid__rating', 'shop-grid__label--category',
            'shop-grid__action--cart', 'data-shop-wishlist-toggle'] as $absent) {
            self::assertStringNotContainsString($absent, $html, $absent);
        }
        self::assertSame(['Summer'], $this->labels($html, 'tag'));
    }

    public function testTheTitlesPartClassesSitOnTheFocusableAnchor(): void
    {
        $this->product('p');
        $html = $this->renderBlock([], false, ['parts' => ['title' => ['hover' => ['colors' => ['text' => ['type' => 'token', 'value' => 'color.accent']]]]]]);
        self::assertMatchesRegularExpression('~<a class="shop-grid__name-link[^"]*t-hover-fg-accent~', $html);
        self::assertDoesNotMatchRegularExpression('~<h3 class="[^"]*t-hover-~', $html);
    }

    public function testSavedSelectionsAndRenderedFiltersAgreeAtTheLimit(): void
    {
        // Twenty categories, the field's max_items: a block that passes validation renders all twenty.
        $slugs = [];
        foreach (range(1, 20) as $i) {
            $slugs[] = 'cat' . $i;
            $this->category('cat' . $i, $this->product('p' . $i));
        }
        $this->assertBlockDataValid(['categories' => $slugs]);
        self::assertSame(20, substr_count($this->renderBlock(['categories' => $slugs, 'limit' => 48]), 'class="shop-grid__item'));
        $this->assertBlockDataInvalid(['categories' => [...$slugs, 'cat21']]);
    }

    public function testTitleTag(): void
    {
        $this->product('p');
        self::assertStringContainsString('<h2 class="shop-grid__name', $this->renderBlock(['title_tag' => 'h2']));
        self::assertStringContainsString('<h4 class="shop-grid__name', $this->renderBlock(['title_tag' => 'h4']));
        self::assertStringContainsString('<h3 class="shop-grid__name', $this->renderBlock(['title_tag' => 'script']));
    }

    public function testBadges(): void
    {
        $this->product('sale', ['price' => 800, 'compare_at' => 1000, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-40 days'))]);
        $this->product('fresh', ['created_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day'))]);
        $html = $this->renderBlock(['show_sale_badge' => true, 'sale_badge_text' => '<b>Deal</b>',
            'show_new_badge' => true, 'new_badge_text' => str_repeat('N', 40), 'new_badge_days' => 7,
            'badge_position' => 'top-right']);
        self::assertSame(1, substr_count($html, 'shop-grid__badge--sale'));
        self::assertSame(1, substr_count($html, 'shop-grid__badge--new'));
        self::assertStringContainsString('&lt;b&gt;Deal&lt;/b&gt;', $html, 'escaped');
        self::assertStringContainsString('>' . str_repeat('N', 24) . '<', $html, 'at most 24 characters');
        self::assertStringContainsString('shop-grid__badges--top-right', $html);
    }

    public function testTheNewBadgeWindowBoundary(): void
    {
        $this->product('inside', ['created_at' => gmdate('Y-m-d H:i:s', strtotime('-6 days 23 hours'))]);
        $this->product('outside', ['created_at' => gmdate('Y-m-d H:i:s', strtotime('-7 days 1 hour'))]);
        $html = $this->renderBlock(['show_new_badge' => true, 'new_badge_days' => 7]);
        self::assertSame(1, substr_count($html, 'shop-grid__badge--new'));
        self::assertLessThan(strpos($html, 'Outside'), strpos($html, 'shop-grid__badge--new'));
    }

    public function testCardEffectAndImageClasses(): void
    {
        $this->product('p');
        $html = $this->renderBlock(['columns' => '4', 'card_hover' => 'lift', 'image_ratio' => 'portrait',
            'image_fit' => 'cover', 'image_hover' => 'zoom']);
        foreach (['--cols-4', '--card-lift', '--image-portrait', '--image-cover', '--image-zoom'] as $class) {
            self::assertStringContainsString('thallo-block-product-grid' . $class, $html);
        }
        self::assertStringNotContainsString('--card-', $this->renderBlock(['card_hover' => 'bogus']));
    }

    public function testWithoutJavaScriptTheCardsAreUsable(): void
    {
        $this->product('p');
        $html = $this->renderBlock([]);
        self::assertStringContainsString('<form class="shop-grid__cart-form" method="post" action="/_shop/cart/add">', $html);
        self::assertMatchesRegularExpression('~data-shop-wishlist-toggle[^>]*hidden|hidden[^>]*data-shop-wishlist-toggle~', $html);
    }

    /** @return list<string> */
    private function labels(string $html, string $kind): array
    {
        preg_match_all('~class="shop-grid__label shop-grid__label--' . $kind . '">([^<]+)<~', $html, $m);
        return $m[1];
    }
```

`renderBlock(array $data, bool $stage = false, array $settings = [])` passes `$settings` as the block's `settings`. `assertBlockDataValid()` / `assertBlockDataInvalid()` validate `['type' => 'product-grid', 'data' => $data]` against the seeded block type through the same validator a save uses (find it: `grep -rn "validateBlocks\|BlockValidator" core/src/Content/Validation | head`), asserting no error / an error on `categories`.

Also create `tests/Integration/Commerce/ProductGridStyleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Core\Content\Blocks\BlockTypeStylePaths;
use Thallo\Core\Tests\Support\AppTestCase;

/** Product grid spec §7.1: the seven parts, and resting opacity beside hover opacity. */
final class ProductGridStyleTest extends AppTestCase
{
    /** @return array<string,mixed> */
    private function paths(): array
    {
        foreach ((new ShopBlockTypesContributor())->blockTypeDefinitions() as $d) {
            if ($d->slug === ShopBlockTypesContributor::SLUG_PRODUCT_GRID) {
                return BlockTypeStylePaths::for([
                    'style_capabilities' => $d->styleCapabilities,
                    'style_targets' => $d->styleTargets,
                ]);
            }
        }
        self::fail('no product grid');
    }

    public function testThePublishedStylePaths(): void
    {
        $parts = $this->paths()['parts'];
        self::assertSame(['card', 'image', 'title', 'price', 'meta', 'button', 'badge'], array_keys($parts));
        foreach (['card', 'button'] as $part) {
            self::assertContains('opacity', $parts[$part], $part);
            self::assertContains('hover.opacity', $parts[$part], $part);
            self::assertContains('hover.colors.surface', $parts[$part], $part);
        }
        self::assertContains('hover.colors.text', $parts['button']);
        self::assertContains('hover.colors.text', $parts['title']);
        self::assertNotContains('opacity', $parts['title']);
        foreach (['image', 'price', 'meta', 'badge'] as $part) {
            self::assertSame([], array_values(array_filter($parts[$part], static fn (string $p): bool => str_starts_with($p, 'hover.'))), $part);
        }
        self::assertContains('radius', $parts['image']);
        self::assertContains('typography.size', $parts['price']);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/Commerce/ProductGridBlockTest.php tests/Integration/Commerce/ProductGridStyleTest.php`
Expected: FAIL — the shell has no cards; the block declares no parts.

- [ ] **Step 3: `product_grid()` in the render extension**

Constructor param after `$blockPreview`:

```php
        /**
         * Soft-bound (product grid spec §3.1): null → product_grid() answers null and the block
         * renders nothing.
         */
        private readonly ?\Thallo\Contracts\Delivery\StorefrontProductGrid $productGrid = null,
```

`getFunctions()`: `new TwigFunction('product_grid', $this->productGrid(...)),` — name the method `productGridView` to avoid clashing with the property:

```php
    /**
     * A Product grid's cards and View-all link (product grid spec §3.1), recording its cache
     * hints: the workspace's catalog storage tag and the catalog generation as a guard — even when
     * nothing matches, so the first matching product purges the page (§3.2).
     *
     * @param array<string,mixed> $data
     * @return array{cards: list<array<string,mixed>>, view_all_url: ?string}|null
     */
    public function productGridView(array $data): ?array
    {
        if ($this->productGrid === null) {
            return null;
        }
        $view = $this->productGrid->grid($data);
        $this->addStorageTag($view->storageTag);
        $this->observeGuard($view->guardKey, $view->guardValue);
        return ['cards' => $view->cards, 'view_all_url' => $view->viewAllUrl];
    }
```

register as `new TwigFunction('product_grid', $this->productGridView(...))`. `RenderServiceProvider`: pass `productGrid: $container->has(StorefrontProductGrid::class) ? $container->get(...) : null` exactly like `blockPreview`. `TemplatePolicy`: add `'product_grid'` to the function allowlist beside `'shop_wishlist_scope'`.

- [ ] **Step 4: The schema**

Replace the product-grid definition's `description`, `schema`, `styleCapabilities` and `styleTargets`, and add `starterContent`:

```php
                description: 'A grid of products: all, on sale or hand-picked, narrowed by categories and tags.',
                schema: [
                    ['name' => 'source', 'label' => 'Source', 'type' => 'enum', 'group' => 'Query',
                        'enum' => ['all', 'on_sale', 'manual'],
                        'enum_labels' => ['all' => 'All products', 'on_sale' => 'On sale', 'manual' => 'Manual selection']],
                    ['name' => 'categories', 'label' => 'Categories', 'type' => 'string', 'group' => 'Query',
                        'multiple' => true, 'max_items' => 20, 'options_source' => 'thallo-commerce.categories',
                        'help' => 'Products in any of these. Not used by Manual selection.'],
                    ['name' => 'tags', 'label' => 'Tags', 'type' => 'string', 'group' => 'Query',
                        'multiple' => true, 'max_items' => 20, 'options_source' => 'thallo-commerce.tags',
                        'help' => 'Products with any of these (and in a chosen category). Not used by Manual selection.'],
                    // One product slug per line — normalized/deduped/capped by ManualProductListNormalizer.
                    ['name' => 'products', 'label' => 'Products', 'type' => 'text', 'group' => 'Query',
                        'help' => 'Manual selection only — one product slug per line.'],
                    ['name' => 'exclude_out_of_stock', 'label' => 'Exclude out of stock', 'type' => 'boolean', 'group' => 'Query'],
                    ['name' => 'order_by', 'label' => 'Order by', 'type' => 'enum', 'group' => 'Query',
                        'enum' => ['newest', 'price_asc', 'price_desc', 'name'],
                        'enum_labels' => ['newest' => 'Newest', 'price_asc' => 'Price: low to high',
                            'price_desc' => 'Price: high to low', 'name' => 'Name'],
                        'help' => 'Not used by Manual selection.'],
                    ['name' => 'limit', 'label' => 'Products to show', 'type' => 'number', 'min' => 1, 'max' => 48,
                        'group' => 'Query',
                        'help' => 'Columns step down to 3 on tablets and 2 on phones; 4 products in 4 columns is one row on a desktop.'],
                    ['name' => 'columns', 'label' => 'Columns', 'type' => 'enum', 'group' => 'Query',
                        'enum' => ['auto', '2', '3', '4', '5', '6'], 'enum_labels' => ['auto' => 'Auto']],
                    ['name' => 'show_image', 'label' => 'Show image', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'show_title', 'label' => 'Show title', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'title_tag', 'label' => 'Title tag', 'type' => 'enum', 'group' => 'Display',
                        'enum' => ['h2', 'h3', 'h4'], 'enum_labels' => ['h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4']],
                    ['name' => 'show_price', 'label' => 'Show price', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'show_rating', 'label' => 'Show rating', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'show_categories', 'label' => 'Show categories', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'show_tags', 'label' => 'Show tags', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'show_add_to_cart', 'label' => 'Show add to cart', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'show_wishlist', 'label' => 'Show wishlist', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'show_sale_badge', 'label' => 'Show sale badge', 'type' => 'boolean', 'group' => 'Badges'],
                    ['name' => 'sale_badge_text', 'label' => 'Sale badge text', 'type' => 'string', 'group' => 'Badges'],
                    ['name' => 'show_new_badge', 'label' => 'Show new badge', 'type' => 'boolean', 'group' => 'Badges'],
                    ['name' => 'new_badge_text', 'label' => 'New badge text', 'type' => 'string', 'group' => 'Badges'],
                    ['name' => 'new_badge_days', 'label' => 'New badge days', 'type' => 'number', 'min' => 1, 'max' => 365,
                        'group' => 'Badges', 'help' => 'Products created within this many days.'],
                    ['name' => 'badge_position', 'label' => 'Badge position', 'type' => 'enum', 'group' => 'Badges',
                        'enum' => ['top-left', 'top-right'], 'enum_labels' => ['top-left' => 'Top left', 'top-right' => 'Top right']],
                    ['name' => 'card_hover', 'label' => 'Card hover effect', 'type' => 'enum', 'group' => 'Card',
                        'enum' => ['none', 'lift', 'shadow'], 'enum_labels' => ['none' => 'None', 'lift' => 'Lift', 'shadow' => 'Shadow']],
                    ['name' => 'image_ratio', 'label' => 'Image ratio', 'type' => 'enum', 'group' => 'Card',
                        'enum' => ['square', 'portrait', 'landscape'],
                        'enum_labels' => ['square' => 'Square', 'portrait' => 'Portrait (4:5)', 'landscape' => 'Landscape (4:3)']],
                    ['name' => 'image_fit', 'label' => 'Image fit', 'type' => 'enum', 'group' => 'Card',
                        'enum' => ['contain', 'cover'], 'enum_labels' => ['contain' => 'Whole product', 'cover' => 'Fill the frame']],
                    ['name' => 'image_hover', 'label' => 'Image hover effect', 'type' => 'enum', 'group' => 'Card',
                        'enum' => ['none', 'zoom'], 'enum_labels' => ['none' => 'None', 'zoom' => 'Zoom']],
                ],
                starterContent: [
                    'source' => 'all', 'categories' => [], 'tags' => [], 'exclude_out_of_stock' => false,
                    'order_by' => 'newest', 'limit' => 12, 'columns' => 'auto',
                    'show_image' => true, 'show_title' => true, 'title_tag' => 'h3', 'show_price' => true,
                    'show_rating' => true, 'show_categories' => true, 'show_tags' => false,
                    'show_add_to_cart' => true, 'show_wishlist' => true,
                    'show_sale_badge' => false, 'sale_badge_text' => 'Sale', 'show_new_badge' => false,
                    'new_badge_text' => 'New', 'new_badge_days' => 7, 'badge_position' => 'top-left',
                    'card_hover' => 'none', 'image_ratio' => 'square', 'image_fit' => 'contain', 'image_hover' => 'none',
                ],
                styleCapabilities: ['spacing', 'width', 'visibility', 'layout.item'],
                styleTargets: StyleTargets::root('box', ['spacing', 'width', 'visibility', 'layout.item']) + ['parts' => [
                    'card' => ['label' => 'Card', 'capabilities' => [
                        'colors.surface', 'colors.border', 'border', 'radius', 'shadow',
                        'spacing.padding.top', 'spacing.padding.right', 'spacing.padding.bottom', 'spacing.padding.left',
                        'opacity', 'hover',
                    ]],
                    'image' => ['label' => 'Image', 'capabilities' => ['radius']],
                    // On the name's anchor, where pointer and keyboard focus land.
                    'title' => ['label' => 'Title', 'capabilities' => ['typography', 'colors.text', 'hover']],
                    'price' => ['label' => 'Price', 'capabilities' => ['typography', 'colors.text']],
                    'meta' => ['label' => 'Meta', 'capabilities' => ['typography', 'colors.text']],
                    'button' => ['label' => 'Button', 'capabilities' => [
                        'colors', 'border', 'radius', 'typography',
                        'spacing.padding.top', 'spacing.padding.right', 'spacing.padding.bottom', 'spacing.padding.left',
                        'opacity', 'hover',
                    ]],
                    'badge' => ['label' => 'Badge', 'capabilities' => ['colors.surface', 'colors.text', 'radius', 'typography']],
                ]],
```

Run `ProductGridStyleTest`; adjust the capability lists only to match what `StyleCapabilities` accepts (e.g. if `border` already implies `colors.border`, drop the duplicate) and keep the asserted expansion.

Run `vendor/bin/phpunit --filter testContributorSchemasPassBlockSchemaValidation tests/Integration/Commerce/ShopBlocksTest.php` now: if the block schema validator refuses `label`, `help` or `group`, add them to its allowed keys (they pass through raw today; record a ruling if a change is needed).

- [ ] **Step 5: `_product_tile.twig` takes the grid's options (defaults unchanged)**

Change the tile so every new hook is opt-in. The image link (spec §3.1: "the image and title link to the product") is a pointer convenience: out of the tab order and hidden from assistive tech, since the title link right below names the same product.

```twig
<div class="shop-grid__tile{{ tile_class|default('') }}{% if no_media|default(false) %} shop-grid__tile--no-media{% endif %}"{{ tile_attrs|default('') }}>
    {% if not no_media|default(false) %}
    {% if link_media|default(false) %}<a class="shop-grid__media-link" href="{{ product.url }}" tabindex="-1" aria-hidden="true">{% endif %}
    <span class="shop-grid__media{{ media_class|default('') }}">
      …unchanged img / empty span…
    </span>
    {% if link_media|default(false) %}</a>{% endif %}
    {% endif %}
    {{ badges|default('') }}
    {% if product.categoryName and (show_tag ?? true) %}
      …unchanged chip…
    {% endif %}
    {%~ if (show_actions ?? true) %}
    <div class="shop-grid__actions">
      {% if not (show_cart ?? true) %}
      {% elseif product.cartMode == 'direct' %}
        …form, with `class="shop-grid__action shop-grid__action--cart{{ action_class|default('') }}"` on its button…
      {% else %}
        …options link with `{{ action_class|default('') }}` appended to its class…
      {% endif %}
      {% if show_wishlist ?? true %}
      …unchanged wishlist button…
      {% endif %}
    </div>
    {%~ endif %}
  </div>
```

Keep the indentation and whitespace-control markers so the shop index HTML and the Product tile block are byte-identical with no options passed — run `vendor/bin/phpunit --filter 'Tile|ShopCatalog|BuildProductCard' tests/Integration/Commerce` before and after and compare.

- [ ] **Step 6: `_grid_card.twig`**

```twig
{# _grid_card.twig — one Product grid card (product grid spec §3.1, §5, §6). Context: `product`
   (ProductGridCard::toArray()), `show` (the display toggles, defaults applied), `title_tag`,
   `badge` (show_sale, show_new, sale_text, new_text, position). Part classes (`card`, `image`,
   `title`, `price`, `meta`, `button`, `badge`) repeat on every card. Without JavaScript every
   card is usable: links to the product, a real add-to-cart form, the heart stays hidden. #}
{% set badge_markup %}
  {%- if (badge.show_sale and product.onSale) or (badge.show_new and product.isNew) -%}
  <span class="shop-grid__badges shop-grid__badges--{{ badge.position }}">
    {%- if badge.show_sale and product.onSale %}<span class="shop-grid__badge shop-grid__badge--sale{{ style_classes('badge') }}">{{ badge.sale_text }}</span>{% endif -%}
    {%- if badge.show_new and product.isNew %}<span class="shop-grid__badge shop-grid__badge--new{{ style_classes('badge') }}">{{ badge.new_text }}</span>{% endif -%}
  </span>
  {%- endif -%}
{% endset %}
<li class="shop-grid__item{{ style_classes('card') }}"{{ style_attrs('card') }}>
  {% include 'shop/_product_tile.twig' with {
    show_tag: false,
    no_media: not show.image,
    link_media: true,
    media_class: style_classes('image'),
    action_class: style_classes('button'),
    show_actions: show.add_to_cart or show.wishlist,
    show_cart: show.add_to_cart,
    show_wishlist: show.wishlist,
    badges: badge_markup,
  } %}
  <span class="shop-grid__body">
    {% if show.title %}
    {# The Title part sits on the anchor: pointer hover and keyboard focus (:focus-visible) both
       land there, and so does the stage's forced preview. #}
    <{{ title_tag }} class="shop-grid__name"><a class="shop-grid__name-link{{ style_classes('title') }}" href="{{ product.url }}">{{ product.name }}</a></{{ title_tag }}>
    {% endif %}
    {% if (show.categories and product.categories is not empty) or (show.tags and product.tags is not empty) %}
    <span class="shop-grid__labels{{ style_classes('meta') }}">
      {%- if show.categories %}{% for c in product.categories %}<span class="shop-grid__label shop-grid__label--category">{{ c.name }}</span>{% endfor %}{% endif -%}
      {%- if show.tags %}{% for t in product.tags %}<span class="shop-grid__label shop-grid__label--tag">{{ t.name }}</span>{% endfor %}{% endif -%}
    </span>
    {% endif %}
    {% if show.rating or (show.price and product.priceFormatted) %}
    <span class="shop-grid__meta">
      {% if show.rating %}
        {# the rating span copied verbatim from _product_card.twig #}
      {% endif %}
      {% if show.price and product.priceFormatted %}
        <span class="shop-grid__price{{ style_classes('price') }}">
          <span class="shop-grid__price-current">{{ product.priceFormatted }}</span>
          {% if product.compareAtFormatted %}<s>{{ product.compareAtFormatted }}</s>{% endif %}
        </span>
      {% endif %}
    </span>
    {% endif %}
  </span>
</li>
```

Replace the rating comment with the exact rating `<span>` block from `_product_card.twig` (lines 30–41) before committing.

- [ ] **Step 7: `product-grid.twig`**

```twig
{# product-grid — product grid spec §3: the source narrowed by categories and tags, rendered on the
   server (stage and site alike) as cards the Style tab's parts reach. product_grid() records the
   page's cache hints (the workspace's catalog tag, the catalog generation). shop.css arrives in the
   theme layer (ShopStylesheetContributor) — never linked here (§3.4). On the site shop.js binds the
   cart forms and wishlist hearts; without it the cards still link and add. #}
{% set grid = product_grid(data) %}
{% set pick = {
  columns: data.columns|default('auto') in ['2', '3', '4', '5', '6'] ? ' thallo-block-product-grid--cols-' ~ data.columns : '',
  card: {lift: ' thallo-block-product-grid--card-lift', shadow: ' thallo-block-product-grid--card-shadow'}[data.card_hover|default('')] ?? '',
  ratio: {portrait: ' thallo-block-product-grid--image-portrait', landscape: ' thallo-block-product-grid--image-landscape'}[data.image_ratio|default('')] ?? '',
  fit: data.image_fit|default('') == 'cover' ? ' thallo-block-product-grid--image-cover' : '',
  zoom: data.image_hover|default('') == 'zoom' ? ' thallo-block-product-grid--image-zoom' : '',
} %}
{% set show = {
  image: data.show_image ?? true, title: data.show_title ?? true, price: data.show_price ?? true,
  rating: data.show_rating ?? true, categories: data.show_categories ?? true, tags: data.show_tags ?? false,
  add_to_cart: data.show_add_to_cart ?? true, wishlist: data.show_wishlist ?? true,
} %}
{% set title_tag = {h2: 'h2', h3: 'h3', h4: 'h4'}[data.title_tag|default('')] ?? 'h3' %}
{% set badge = {
  show_sale: data.show_sale_badge ?? false, show_new: data.show_new_badge ?? false,
  sale_text: (data.sale_badge_text|default('') != '' ? data.sale_badge_text : 'Sale')|slice(0, 24),
  new_text: (data.new_badge_text|default('') != '' ? data.new_badge_text : 'New')|slice(0, 24),
  position: data.badge_position|default('') == 'top-right' ? 'top-right' : 'top-left',
} %}
{% set cards = grid ? grid.cards : [] %}
{% set wishlist_scope = is_canvas() ? null : shop_wishlist_scope() %}
<div class="thallo-block thallo-block-product-grid{{ pick.columns }}{{ pick.card }}{{ pick.ratio }}{{ pick.fit }}{{ pick.zoom }}{% if cards is empty %} thallo-block-product-grid--empty{% endif %}{{ style_classes('root') }}"{{ style_attrs('root') }}
     {%- if wishlist_scope %} data-shop-scope="{{ wishlist_scope }}"{% endif %}>
  {% if cards is empty %}
    {% if is_canvas() %}<p class="thallo-block-product-grid__empty thallo-field-empty">Product grid — no products match</p>{% endif %}
  {% else %}
  <ul class="thallo-block-product-grid__items">
    {% for product in cards %}{% include 'shop/_grid_card.twig' %}{% endfor %}
  </ul>
  {% if grid.view_all_url %}<a class="thallo-block-product-grid__view-all" href="{{ grid.view_all_url }}">View all products</a>{% endif %}
  {% endif %}
</div>
{% if not is_canvas() and cards is not empty %}
{# The stable, deploy-invariant asset alias (see ShopAssetController). #}
<script src="/_thallo/shop/shop.js" defer></script>
{% endif %}
```

- [ ] **Step 8: Remove the endpoint, the hydration and their tests; update the pattern**

- Delete `ShopBlockDataController::productGrid()` and every private helper only it used (`categoryRows`, `tagRows`, `manualRows`, `clampPageSize`, and `noStore` if unused); if the class has no other action left, delete the class, its route line and its container binding.
- `shop-routes.php`: remove the `/_shop/blocks/product-grid` route.
- `shop.js`: remove the `// ---- block hydration: product-grid` section (`hydrateProductGrids`, `hydrateProductGrid`, `renderProductGrid`) and the `register('shop-product-grid', …)` line; update the header comment (line 6) and the "nine/ten registrations" comments' counts. Keep `buildProductCard()` and `enhanceBuiltCards()` (the wishlist page uses them).
- `ShopJsRuntimeTest`: drop `'shop-product-grid'` from the registration lists (1287, 1516); rewrite the containment test (1676–1730) to make `shop-featured-product` the throwing module (`data-shop-block="featured-product"`, throwing on its first attribute read) and assert `shop-add-to-cart` still enhances. `RuntimeShopCoexistenceTest:457`: drop the name.
- `ShopBlocksTest`: delete the endpoint tests (438–560) and the old template tests (673–705); update `testProductGridSchemaHasTheDocumentedFieldsAndEnums` to the new field list and `source` enum `['all', 'on_sale', 'manual']`, and assert `starterContent['title_tag'] === 'h3'`.
- `ShopBlockSelectionTest`: delete `testAConfiguredBlockRendersItsShellWithLoadingHiddenAndALinkToTheShop`, `testOnTheStageAProductGridIsANamedPlaceholder`, `testAProductGridShipsItsLoadingLineHidden` (covered by `ProductGridBlockTest`).
- `StorefrontInertnessTest`: line 122's route assertion goes; line 244's `'latest'` data becomes `['source' => 'all']`.
- `ShopPatternsContributor::grid()`: `['source' => 'all', 'order_by' => 'newest', 'limit' => $limit]`; update `ShopPatternsTest` to match.
- `core-schema.d.ts` / `docs/openapi.json`: remove the `/_shop/blocks/product-grid` operation (regenerate with `CACHE_DRIVER=array php glueful docs:openapi` if it runs; otherwise hand-splice the path out and keep the rest byte-identical).

- [ ] **Step 9: The cache proofs**

`tests/Integration/Commerce/ProductGridCacheTest.php` — through the real kernel (as `RenderPageCacheTest` and `ShopCacheTest` do), a published page holding a grid (seed with `SeedsPublishedContent` and a body containing `['type' => 'product-grid', 'data' => []]`) and a shop layout page holding one (follow `ShopLayoutCacheTest`'s layout seeding):

```php
    public function testAPageWithAGridIsPurgedByACatalogChangeInBothFamilies(): void
    // request twice → the second is a cache hit (same ETag); create a product via CatalogService →
    // the next request shows it.

    public function testAnEmptyGridIsTaggedAndTheFirstMatchingProductPurgesIt(): void
    // no products: page cached with --empty; seed one through CatalogService; next request shows it.

    public function testTwoWorkspacesStayIsolated(): void
    // use RetrofittedTenantTestCase::runAsTenant() (see CommercePurgePipelineTest): cache a grid page
    // in A and in B; change A's catalog; A refreshes, B's entry is still served (same ETag).

    public function testTheFallbackDeletesOnlyTheOwningWorkspace(): void
    // as CatalogGenerationPurgeTest's fallback case, but end-to-end: a CacheStore whose
    // invalidateTags() answers false; assert B's render key still exists after A's change.

    public function testTwoGridsStraddlingAChangeLeaveThePageUnstored(): void
    // a page with two grids; a StorefrontProductGrid decorator rotates the generation between the
    // first and second grid() call; assert no render key is stored.
```

Write each body fully, following the named precedents. The fallback and isolation tests need the tenancy harness; put them in a `RetrofittedTenantTestCase` subclass (`ProductGridCacheTenancyTest.php`) and add that file to the tenancy shard in `.github/workflows/ci.yml` (memory: every Integration entry belongs to one shard).

- [ ] **Step 10: Run the commerce, render and runtime tests**

Run: `composer test:reset-db >/dev/null && composer test:migrate >/dev/null && vendor/bin/phpunit tests/Integration/Commerce tests/Integration/Render`
Expected: PASS.

- [ ] **Step 11: Changelog and commit**

```markdown
### Changed
- **The Product grid is a source narrowed by categories and tags** (breaking): Source is All
  products, On sale or Manual selection; Categories and Tags are multi-select dropdowns (any of
  them, and both when both are set); Exclude out of stock; Order by newest, price or name;
  Products to show; Columns. Grids saved before this release must have their source and
  categories or tags chosen again.
- **Its cards render on the server, and the stage shows them.** The `/_shop/blocks/product-grid`
  endpoint and shop.js's grid loading are gone.

### Added
- **Product grid display options**: show or hide the image, title, price, rating, categories, tags,
  add to cart and wishlist; the title's heading level (H3 by default). Cards show every category.
- **Sale and New badges** with their own text, a New window in days, and a position.
- **Product grid Style tab**: Card, Image, Title, Price, Meta, Button and Badge sections; the Card,
  Title and Button have hover looks, and the Card and Button opacity.
```

```bash
git add -A packages core admin/src/api docs/openapi.json tests .github/workflows/ci.yml CHANGELOG.md
git commit -m "feat(commerce): the Product grid renders on the server — source plus filters, display, badges, Style tab parts; the grid endpoint goes"
```

## Task 11: card and image effects, the stylesheet through the theme layer

**Files:**
- Modify: `packages/thallo-commerce/assets/shop.css` (grid card, image link, title link, labels, badges, effects, no-media tile)
- Remove the raw `<link … shop.css>` from: `templates/blocks/wishlist-link.twig:39`, `featured-product.twig:38`, `mini-cart.twig:53`, `templates/shop/wishlist.twig:6`, `checkout.twig:5`, `confirmation.twig:5`, `product.twig:10`, `index.twig:6` (and any other hit of `grep -rn "shop/shop.css" packages/thallo-commerce/templates packages/thallo-render/themes`)
- Create: `tests/Integration/Commerce/ShopStylesheetDeliveryTest.php`

**Interfaces:** Consumes Task 10's markup and parts.

- [ ] **Step 1: Write the failing stylesheet test**

`tests/Integration/Commerce/ShopStylesheetDeliveryTest.php`:

```php
    public function testNoThalloTemplateLinksTheShopStylesheet(): void
    {
        $root = dirname(__DIR__, 3) . '/packages';
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')
                && str_contains((string) file_get_contents($file->getPathname()), 'shop/shop.css')) {
                $hits[] = $file->getPathname();
            }
        }
        self::assertSame([], $hits);
    }

    public function testTheShopStylesheetIsInsideTheThemeLayer(): void
    {
        // The theme artifact (ThemeStylesheetArtifacts) contains shop.css's first rule inside
        // `@layer theme`. Build it as ThemeStylesheetArtifacts does in its own test and assert
        // `@layer theme{` precedes `.shop-grid{` (or the first selector of shop.css).
    }
```

Write the second body after reading `ThemeStylesheetArtifacts`' existing test (`grep -rln "ThemeStylesheetArtifacts" tests`) — copy its construction.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/Commerce/ShopStylesheetDeliveryTest.php`
Expected: FAIL — eight templates link shop.css.

- [ ] **Step 3: The stylesheet**

In `shop.css`, after the existing `.thallo-block-product-grid` rules (~828–850), add:

```css
/* Product grid cards (product grid spec §3.1, §5–§7): the name is a heading holding the link; the
   categories and tags wrap onto further lines rather than overflow. Theme layer — authored part
   styles (@layer settings) win over all of it. */
.thallo-block-product-grid .shop-grid__name {
  margin: 0;
  font: inherit;
  font-weight: 600;
}
.thallo-block-product-grid .shop-grid__name-link {
  color: inherit;
  text-decoration: none;
}
.thallo-block-product-grid .shop-grid__media-link {
  display: block;
}
.shop-grid__labels {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.5rem;
  min-width: 0;
  font-size: 0.75rem;
  color: color-mix(in srgb, currentColor 60%, transparent);
}
.shop-grid__label {
  overflow-wrap: anywhere;
}
.shop-grid__badges {
  position: absolute;
  top: 0.625rem;
  display: flex;
  gap: 0.375rem;
  pointer-events: none;
}
.shop-grid__badges--top-left { left: 0.625rem; }
.shop-grid__badges--top-right { right: 0.625rem; }
.shop-grid__badge {
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  background: var(--shop-accent, #16211c);
  color: #fff;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}
/* Image off: the tile keeps the badges and the actions, in the flow. */
.shop-grid__tile--no-media .shop-grid__badges,
.shop-grid__tile--no-media .shop-grid__actions {
  position: static;
  opacity: 1;
  transform: none;
}
.shop-grid__tile--no-media .shop-grid__actions { flex-direction: row; }
.thallo-block-product-grid--image-portrait .shop-grid__image { aspect-ratio: 4 / 5; }
.thallo-block-product-grid--image-landscape .shop-grid__image { aspect-ratio: 4 / 3; }
.thallo-block-product-grid--image-cover .shop-grid__image { object-fit: cover; padding: 0; }
/* Card and image hover effects (§7.2): only where the device can hover, on keyboard focus within
   the card, and under the stage's forced preview; no movement for reduced motion. */
@media (prefers-reduced-motion: no-preference) {
  .thallo-block-product-grid:is(.thallo-block-product-grid--card-lift, .thallo-block-product-grid--card-shadow) .shop-grid__item {
    transition: transform 0.18s ease, box-shadow 0.18s ease;
  }
  .thallo-block-product-grid--image-zoom .shop-grid__image { transition: transform 0.3s ease; }
}
@media (hover: hover) {
  .thallo-block-product-grid--card-lift .shop-grid__item:hover { transform: translateY(-0.25rem); }
  .thallo-block-product-grid:is(.thallo-block-product-grid--card-lift, .thallo-block-product-grid--card-shadow) .shop-grid__item:hover {
    box-shadow: 0 0.75rem 1.75rem rgb(0 0 0 / 0.12);
  }
  .thallo-block-product-grid--image-zoom .shop-grid__item:hover .shop-grid__image { transform: scale(1.06); }
}
.thallo-block-product-grid--card-lift .shop-grid__item:is(:focus-within, [data-thallo-hover]) { transform: translateY(-0.25rem); }
.thallo-block-product-grid:is(.thallo-block-product-grid--card-lift, .thallo-block-product-grid--card-shadow) .shop-grid__item:is(:focus-within, [data-thallo-hover]) {
  box-shadow: 0 0.75rem 1.75rem rgb(0 0 0 / 0.12);
}
.thallo-block-product-grid--image-zoom .shop-grid__item:is(:focus-within, [data-thallo-hover]) .shop-grid__image { transform: scale(1.06); }
@media (prefers-reduced-motion: reduce) {
  .thallo-block-product-grid .shop-grid__item,
  .thallo-block-product-grid .shop-grid__image { transform: none !important; }
}
```

`!important` inside `@layer theme` — confirm `ThemeCssLint` allows it (`packages/thallo-render/src/Style/ThemeCssLint.php`); if it refuses, raise the reduced-motion selectors' specificity instead (`.thallo-block-product-grid.thallo-block-product-grid .shop-grid__item:is(:hover, :focus-within, [data-thallo-hover])`).

- [ ] **Step 4: Remove every raw link**

Delete each `<link rel="stylesheet" href="/_thallo/shop/shop.css">` (and its preceding comment) from the eight templates. For the shop page templates (`templates/shop/*.twig`), confirm they render through the theme layout that emits `theme_stylesheet_url()` (open `index.twig`'s `extends`); if one does not, it gets the theme artifact link instead of the raw one — record it.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Integration/Commerce tests/Integration/Render`
Expected: PASS (fix any test asserting the raw link's presence — e.g. `ShopBlocksTest`'s mini cart test asserted `shop.js` only; search `grep -rn "shop/shop.css" tests` and invert those assertions).

- [ ] **Step 6: Changelog and commit**

```markdown
- **Product grid card effects**: a **Card** group sets the hover effect (lift, shadow), image ratio
  and fit, and image zoom on hover.
### Fixed
- **Shop blocks and pages no longer load a second, unlayered copy of the shop stylesheet**, which
  let the shop's defaults override styles set in the Style tab on any page holding a mini cart,
  wishlist link or featured product.
```

```bash
git add packages/thallo-commerce tests/Integration/Commerce CHANGELOG.md
git commit -m "feat(commerce): Product grid card and image effects; the shop stylesheet only through the theme layer"
```

## Task 12: browser and stage proofs

**Files:**
- Create: `scripts/build-product-grid-fixtures` (copy `scripts/build-shop-block-proof-fixtures`' skeleton: seed in a rolled-back transaction, render public and stage pages, inline stylesheets; but **keep scripts** for `grid-js.html`)
- Create: `tools/runtime-browser/tests/product-grid.spec.js`
- Modify: `.github/workflows/runtime-browser.yml` (a build step for the new fixtures, beside the hover one)
- Modify: `admin/e2e` — a proof that the stage shows real cards and updates on a part style change (fixtures: extend `scripts/build-builder-proof-fixtures` with a grid block, or add a captured grid fragment to the e2e world; follow `hover-preview.spec.ts`)

**Interfaces:** Consumes Tasks 10–11.

- [ ] **Step 1: The fixture builder**

Pages written to `tools/runtime-browser/fixtures/product-grid/`:
- `public.html` — a page with a mini cart (header region or a block) and three grids: (a) styled — Card background `color.surface`, Title text `color.accent` with hover text `color.text`, Button background `color.accent`, `card_hover: lift`, `image_hover: zoom`, `image_ratio: portrait`, `image_fit: cover`, badges on, `badge_position: top-right`; (b) a product with five long category names (`'Outerwear & Rainwear'`, …) at 4 columns; (c) defaults. Stylesheets inlined, scripts **dropped** (the no-JS reader).
- `grid-js.html` — the same page with `shop.js` and the runtime core **kept** (served from the fixture directory; copy the two assets next to it), for the after-initialization and cart/wishlist proofs.
- `stage.html` — the stage render of the same entry.

Seed products with prices, a compare-at price, `created_at` yesterday, and one direct-mode product (one active variant, no required add-on).

- [ ] **Step 2: Write the browser spec**

```js
// The Product grid in a browser (product grid spec §3.4, §5.1, §7.2): authored part styles win
// over the shop's defaults after shop.js starts and with a mini cart on the page; cards work
// without JavaScript; long category labels wrap; card lift and image zoom only where hover exists.
'use strict';

const { test, expect } = require('@playwright/test');

const BASE = '/tools/runtime-browser/fixtures/product-grid/';
const grid = (page, n) => page.locator('.thallo-block-product-grid').nth(n);
const probe = (page, prop, value) =>
  page.evaluate(([p, v]) => {
    const el = document.createElement('span');
    el.style[p] = v;
    document.body.appendChild(el);
    const out = getComputedStyle(el)[p];
    el.remove();
    return out;
  }, [prop, value]);

test('authored part styles hold after shop.js initializes, with a mini cart on the page', async ({ page }) => {
  await page.goto(BASE + 'grid-js.html');
  await page.waitForFunction(() => document.querySelector('[data-shop-wishlist-toggle]:not([hidden])'));
  expect(await page.locator('[data-shop-mini-cart]').count()).toBeGreaterThan(0);
  const card = grid(page, 0).locator('.shop-grid__item').first();
  expect(await card.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
    await probe(page, 'backgroundColor', 'var(--t-color-surface)'),
  );
  expect(await card.locator('.shop-grid__name-link').evaluate((el) => getComputedStyle(el).color)).toBe(
    await probe(page, 'color', 'var(--t-color-accent)'),
  );
  // The Button part's authored background, over the shop's own action-button background.
  expect(await card.locator('.shop-grid__action--cart').evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
    await probe(page, 'backgroundColor', 'var(--t-color-accent)'),
  );
});

test('add to cart posts that product and the cart shows it; the heart saves it', async ({ page }) => {
  await page.goto(BASE + 'grid-js.html');
  const card = grid(page, 0).locator('.shop-grid__item').filter({ has: page.locator('.shop-grid__action--cart') }).first();
  const variant = await card.locator('input[name="variant_uuid"]').getAttribute('value');
  let posted = null;
  await page.route('**/_shop/cart/add', async (route) => {
    posted = route.request().postData();
    // Answer the way ShopCartController answers an XHR add (read its JSON shape and the mini cart's
    // count field in shop.js before relying on these keys).
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ count: 1, lines: [] }) });
  });
  const heart = card.locator('[data-shop-wishlist-toggle]');
  await expect(heart).toBeVisible();
  await heart.click();
  await expect(heart).toHaveAttribute('aria-pressed', 'true');
  await card.locator('.shop-grid__action--cart').click();
  await expect.poll(() => posted).not.toBeNull();
  expect(new URLSearchParams(posted).get('variant_uuid')).toBe(variant);
  expect(new URLSearchParams(posted).get('quantity')).toBe('1');
  await expect(page.locator('[data-shop-cart-count]').first()).toHaveText('1');
});

test('without JavaScript a card adds to the cart by posting its form', async ({ page }) => {
  await page.goto(BASE + 'public.html');
  const card = grid(page, 0).locator('.shop-grid__item').first();
  expect(await card.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
    await probe(page, 'backgroundColor', 'var(--t-color-surface)'),
  );
  await expect(card.locator('.shop-grid__name-link')).toHaveAttribute('href', /\/shop\//);
  await expect(card.locator('.shop-grid__media-link')).toHaveAttribute('href', /\/shop\//);
  await expect(card.locator('[data-shop-wishlist-toggle]')).toBeHidden();
  const form = grid(page, 0).locator('form.shop-grid__cart-form').first();
  const variant = await form.locator('input[name="variant_uuid"]').getAttribute('value');
  let posted = null;
  await page.route('**/_shop/cart/add', async (route) => {
    posted = { method: route.request().method(), body: route.request().postData() };
    await route.fulfill({ status: 303, headers: { Location: '/cart-landed' } });
  });
  await page.route('**/cart-landed', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>cart</p>' }));
  await form.locator('button[type="submit"]').click(); // a real form submission: no script on this page
  await page.waitForURL('**/cart-landed');
  expect(posted.method).toBe('POST');
  expect(new URLSearchParams(posted.body).get('variant_uuid')).toBe(variant);
});

test('the title link answers pointer, keyboard and the forced preview', async ({ page }) => {
  await page.goto(BASE + 'public.html');
  const link = grid(page, 0).locator('.shop-grid__name-link').first();
  const rest = await link.evaluate((el) => getComputedStyle(el).color);
  const hoverColour = await probe(page, 'color', 'var(--t-color-text)'); // the fixture's Title hover colour
  await link.hover();
  expect(await link.evaluate((el) => getComputedStyle(el).color)).toBe(hoverColour);
  await page.mouse.move(0, 0);
  expect(await link.evaluate((el) => getComputedStyle(el).color)).toBe(rest);
  await link.focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab'); // keyboard focus → :focus-visible
  expect(await link.evaluate((el) => el === document.activeElement)).toBe(true);
  expect(await link.evaluate((el) => getComputedStyle(el).color)).toBe(hoverColour);
  await link.evaluate((el) => el.blur());
  await link.evaluate((el) => el.setAttribute('data-thallo-hover', ''));
  expect(await link.evaluate((el) => getComputedStyle(el).color)).toBe(hoverColour);
});

test('several long category labels wrap without overflowing the card', async ({ page }) => {
  await page.goto(BASE + 'public.html');
  const labels = grid(page, 1).locator('.shop-grid__labels').first();
  const box = await labels.evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth, h: el.getBoundingClientRect().height,
    line: parseFloat(getComputedStyle(el).lineHeight) || 16 }));
  expect(box.scroll).toBeLessThanOrEqual(box.client + 1);
  expect(box.h).toBeGreaterThan(box.line * 1.5);
});

test('card lift and image zoom under hover; ratio, fit and badge position', async ({ page }) => {
  await page.goto(BASE + 'public.html');
  const card = grid(page, 0).locator('.shop-grid__item').first();
  const image = card.locator('.shop-grid__image');
  const ratio = await image.evaluate((el) => el.getBoundingClientRect().width / el.getBoundingClientRect().height);
  expect(ratio).toBeCloseTo(4 / 5, 1);
  expect(await image.evaluate((el) => getComputedStyle(el).objectFit)).toBe('cover');
  const badges = card.locator('.shop-grid__badges');
  const [b, c] = [await badges.boundingBox(), await card.boundingBox()];
  expect(c.x + c.width - (b.x + b.width)).toBeLessThan(20);
  const before = await card.evaluate((el) => el.getBoundingClientRect().top);
  await card.hover();
  await page.waitForTimeout(400);
  expect(await card.evaluate((el) => el.getBoundingClientRect().top)).toBeLessThan(before);
  expect(await image.evaluate((el) => getComputedStyle(el).transform)).not.toBe('none');
});

test('a tap on a touch screen leaves no lift or zoom behind', async ({ browser }) => {
  const touch = await browser.newContext({ hasTouch: true, isMobile: true });
  const page = await touch.newPage();
  await page.goto(BASE + 'public.html');
  const card = grid(page, 0).locator('.shop-grid__item').first();
  // Tap the card's body (not a link), as a shopper scrolling past would.
  await card.locator('.shop-grid__body').tap({ position: { x: 2, y: 2 } });
  await page.waitForTimeout(400);
  expect(await card.evaluate((el) => getComputedStyle(el).transform)).toBe('none');
  expect(await card.locator('.shop-grid__image').evaluate((el) => getComputedStyle(el).transform)).toBe('none');
  await touch.close();
});

test('no movement with reduced motion; the forced preview draws the effects', async ({ browser }) => {
  const ctx = await browser.newContext();
  const reduced = await ctx.newPage();
  await reduced.emulateMedia({ reducedMotion: 'reduce' });
  await reduced.goto(BASE + 'public.html');
  const card = grid(reduced, 0).locator('.shop-grid__item').first();
  await card.hover();
  expect(await card.evaluate((el) => getComputedStyle(el).transform)).toBe('none');

  const stage = await ctx.newPage();
  await stage.goto(BASE + 'stage.html');
  const forced = grid(stage, 0).locator('.shop-grid__item').first();
  await forced.evaluate((el) => el.setAttribute('data-thallo-hover', ''));
  expect(await forced.evaluate((el) => getComputedStyle(el).transform)).not.toBe('none');
  await ctx.close();
});
```

`isMobile` contexts are Chromium-only; the runtime-browser project is Chromium (check `playwright.config.js`).

- [ ] **Step 3: Build and run**

Run: `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-product-grid-fixtures && (cd tools/runtime-browser && npx playwright test tests/product-grid.spec.js)`
Expected: PASS. Then prove the proofs can fail, one at a time, restoring after each:
- re-add the raw `<link>` to `mini-cart.twig`, rebuild: the first test FAILS (the unlayered copy beats the authored Card and Button backgrounds);
- move the Title part's classes back onto the heading in `_grid_card.twig`, rebuild: the title test's keyboard assertion FAILS;
- drop the `(hover: hover)` wrapper from the lift rule in `shop.css`, rebuild: the touch test FAILS.

- [ ] **Step 4: The e2e stage proof**

Following `admin/e2e/tests/hover-preview.spec.ts`: select the grid block on the stage, open Style → Card, set Background; assert the stage's first `.shop-grid__item` computed background changes after the apply. If the e2e world answers applies from captured fragments, capture a styled grid fragment in the fixture builder (as the hover plan's Task 8 did with `World.fragments`).

Run: `cd admin/e2e && npx playwright test tests/product-grid-stage.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/build-product-grid-fixtures tools/runtime-browser/tests/product-grid.spec.js .github/workflows/runtime-browser.yml admin/e2e scripts/build-builder-proof-fixtures
git commit -m "test(commerce): Product grid browser and stage proofs — layered styles after shop.js, no-JS cards, wrapping labels, hover effects"
```

## Task 13: docs and the release gates

**Files:**
- Modify: `docs/reference/04-block-library.md` (the Product grid row), `docs/reference/05-style-settings.md` (its parts), the storefront guide (`grep -rln "Product grid" docs/guides docs/reference`)
- Modify: `CHANGELOG.md` (Upgrade Notes under `[Unreleased]`)

- [ ] **Step 1: Docs**

- Block library row: fields by group (Query, Display, Badges, Card) with types and defaults as in §5.1/§7.2; parts list.
- Style settings: a "Product grid" subsection with the seven parts and their settings; note that the Card group's effects are content settings and that a hover shadow yields to an authored card shadow.
- Storefront guide: building a home-page grid (4 products, 4 columns, one row); what On sale and Exclude out of stock mean; that a New badge follows page caching.

- [ ] **Step 2: Upgrade Notes**

```markdown
### Upgrade Notes
- `composer update && php glueful thallo:provision`. Requires `glueful/commerce ^1.14.0`.
  Provision gives existing installs the Product grid's new fields, starter content and Style tab
  parts.
- **A Product grid saved before this release must have its source and categories or tags chosen
  again**: the `category`, `tag` and `newest` sources and the `category_slug` / `tag_slug` fields
  are gone, and such a grid shows all products until edited.
- A theme that overrides `product-grid.twig` must be rewritten: the cards render on the server
  (see the default template); the `/_shop/blocks/product-grid` endpoint is gone.
- Thallo's templates no longer link `/_thallo/shop/shop.css` — it arrives in the theme layer. A
  theme template that links it itself should stop, or its copy will override Style tab settings.
- Product grid cards name the product in an `h3` by default.
```

- [ ] **Step 3: Full gates**

Run, attached and one at a time:
- `vendor/bin/phpcs` (exit 0) on every touched PHP file
- `vendor/bin/phpunit --testsuite Unit`
- `composer test:reset-db && composer test:migrate`, then each integration shard A–G from `.github/workflows/ci.yml`, then the tenancy harness
- `composer test:distribution`, `composer test:skeleton`, then `composer test:reset-db && composer test:migrate`
- `cd admin && npx vitest run && pnpm type-check && pnpm lint && pnpm exec oxfmt --check <touched files>`
- e2e (rebuild fixtures first per the e2e fixtures memory) and runtime-browser (rebuild every fixture builder)
Expected: all PASS.

- [ ] **Step 4: Commit**

```bash
git add docs CHANGELOG.md
git commit -m "docs: the Product grid — fields, parts, effects, storefront guide; upgrade notes"
```

---

## Self-review (run while writing; kept for the reviewer)

**Spec coverage:**
- §2.1–2.3 → Tasks 1, 2, 8 (filters, order, count, View all); §2.4 → Task 8 (`ProductGridQuery` maps nothing) and Task 10 (schema, patterns).
- §3.1 server render, stage, no-JS → Tasks 10, 12; §3.2 cache families, tagging, empty results, purge (the event's workspace), isolation, late renders, guards on read, multiple grids, missing generations → Tasks 6, 7, 10 (`ProductGridCacheTest`, `ProductGridCacheTenancyTest`); §3.3 endpoint removal → Task 10; §3.4 stylesheet → Tasks 11, 12; §3.5 count and columns → Task 10 (help text).
- §4 engine → Tasks 1–3; Thallo callers with the bump → Task 5.
- §5.1 fields and exact defaults → Tasks 9 (starter content), 10; §5.2 help texts → Tasks 9, 10; §5.3 multi-select, sources, slugs → Task 9.
- §6 badges → Task 10. §7.1 parts and opacity → Task 10; §7.2 effects → Tasks 10 (classes), 11 (CSS), 12 (browser); §7.3 stage → Task 12.
- §8 docs → Task 13. §9 tests → each task. §10 order → Part A then Task 5 first.

**Placeholder scan:** Steps that defer to an existing helper name the file to copy from and what to copy (`FieldValidator` construction, `ShopCacheTest` middleware, `ThemeStylesheetArtifacts` test, the e2e world). `ProductGridCacheTest` bodies are described by precedent and must be written fully before the task's commit — the implementer writes them in Step 9.

**Type consistency:** `ResolvedProductFilters(categoryUuids, tagUuids, attributePairs, onSale, inStock)` in Tasks 1, 5, 8; `listActive(…, $filters, $sort)` in Tasks 2, 8; `ProductGridView(cards, viewAllUrl, storageTag, guardKey, guardValue)` in Tasks 8, 10; `RenderCacheHints(storageTags, guards, uncacheable)` in Tasks 6, 10; `CatalogGeneration::key/read/rotate` in Tasks 7, 8; `productGridView()` registered as `product_grid` in Task 10; `purgeWorkspace(string $tenantUuid)` in Task 7's contract, implementor and test doubles.

**Review Focus:** each line has its test — stale-on-read (Task 6 Step 1, Task 10 Step 9), chrome-block stylesheet (Task 12 Step 2 + the deliberate re-add in Step 3), all-deleted categories (Task 8 Step 6), unpriced products last (Task 2 Step 1), Manual + out of stock (Task 8 Step 6).
