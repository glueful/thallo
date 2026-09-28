<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Psr\Container\ContainerInterface;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;

/**
 * The shop home and category pages the C2 proofs render (type layouts plan C2, S0): every value
 * fixed — names, slugs, SKUs, prices, reviews and creation times — so the shop lists the same cards
 * in the same order on every run and in every environment; only the ids the catalog generates vary,
 * and the callers name them. Shared by the PHP tests and scripts/build-shop-layout-proof-fixtures.
 *
 * Three categories (Mugs, Bowls, and Vases, which holds nothing) and 26 active products, newest
 * first: 24 on the home's first page and two on its second. The first five are the cases a card
 * shows differently — a cover, a category and reviews; a compare-at price; two variants; a required
 * add-on; neither cover nor category. The names are short, so no card's name ellipsizes at 375px and
 * the parity measurements never depend on a font's advance widths; long names have their own seed
 * ({@see self::longNames()}).
 *
 * The shop resolves its tenant as the product proofs do ({@see ProductPageSeed::useTenant()}).
 */
final class ShopPageSeed
{
    public const TENANT = ProductPageSeed::TENANT;

    /** blob uuid => the committed image a self-contained page inlines for it */
    public const IMAGES = [
        'shopblob0001' => 'tests/fixtures/commerce/product-cover.png',
    ];

    /** The long-name proofs' product and category (never in the parity pages). */
    public const LONG_NAME = 'Hand-thrown stoneware serving bowl with ash glaze, speckled finish';
    public const LONG_CATEGORY = 'Serving bowls, platters and large tableware';

    /** slug => [uuid, name], in rail order */
    public const CATEGORIES = [
        'mugs' => ['shopcat00001', 'Mugs'],
        'bowls' => ['shopcat00002', 'Bowls'],
        'vases' => ['shopcat00003', 'Vases'],
    ];

    private readonly ProductPageSeed $products;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ApplicationContext $context,
    ) {
        $this->products = new ProductPageSeed($container, $context);
    }

    /** @return array<string,?string> the flags it replaced, for {@see self::restoreTenant()} */
    public function useTenant(): array
    {
        return $this->products->useTenant();
    }

    /** @param array<string,?string> $previous */
    public function restoreTenant(array $previous): void
    {
        $this->products->restoreTenant($previous);
    }

    /** Remove every catalog row a seed writes (the commerce tables are not truncated between tests). */
    public function clear(): void
    {
        $this->products->clear();
        $this->container->get(Connection::class)->getPDO()
            ->exec("DELETE FROM blobs WHERE uuid LIKE 'shopblob%'");
    }

    /**
     * The shop: three categories and 26 products, newest first.
     *
     * @return array{categories: array<string,string>, products: list<string>, variants: list<list<string>>}
     *     categories slug => uuid; products and their variants in listing order (newest first)
     */
    public function seed(): array
    {
        $this->container->get(StarterBlockTypeSeeder::class)->seedMissing();
        $categories = new CategoryRepository();
        $position = 0;
        foreach (self::CATEGORIES as $slug => [$uuid, $name]) {
            $categories->insert($this->context, [
                'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => $slug, 'name' => $name,
                'position' => $position++,
            ]);
        }

        $products = [];
        $variants = [];
        foreach (self::catalog() as $i => $spec) {
            $product = $this->container->get(CatalogService::class)->createProduct($this->context, [
                'slug' => $spec['slug'],
                'name' => $spec['name'],
                'status' => 'active',
                'type' => 'physical',
                'variants' => $spec['variants'],
            ]);
            $uuid = (string) $product['uuid'];
            // Newest first, one minute apart: the listing's order never falls to its uuid tie-break.
            $this->container->get(Connection::class)->table('commerce_products')
                ->where('uuid', '=', $uuid)
                ->update(['created_at' => sprintf('2026-09-01 10:%02d:00', 59 - $i)]);
            if (isset($spec['rating'])) {
                $this->container->get(ProductRepository::class)->update($this->context, self::TENANT, $uuid, [
                    'rating_sum' => $spec['rating'][0], 'rating_count' => $spec['rating'][1],
                ]);
            }
            if (isset($spec['category'])) {
                $categories->attachProduct($this->context, $uuid, self::CATEGORIES[$spec['category']][0]);
            }
            if (isset($spec['addon'])) {
                $this->container->get(Connection::class)->table('commerce_product_addons')->insert([
                    'uuid' => $spec['addon'], 'tenant_uuid' => self::TENANT, 'product_uuid' => $uuid,
                    'name' => 'Gift wrap', 'field_type' => 'checkbox', 'required' => true, 'price_delta' => 0,
                    'position' => 0, 'status' => 'active',
                ]);
            }
            if (isset($spec['cover'])) {
                $this->cover($uuid, $spec['cover'], $spec['name']);
            }
            $products[] = $uuid;
            $variants[] = array_map(static fn (array $v): string => (string) $v['uuid'], $product['variants']);
        }

        return [
            'categories' => array_map(static fn (array $c): string => $c[0], self::CATEGORIES),
            'products' => $products,
            'variants' => $variants,
        ];
    }

    /**
     * The long-name shop: one category with a long name holding a product with a long name (one
     * variant, so its tile carries the quick add) and one short-named product. Seeded instead of
     * {@see self::seed()}, for the overflow, ellipsis and accessible-name proofs only.
     *
     * @return array{category: string, product: string, slug: string, short: string}
     */
    public function longNames(): array
    {
        $this->container->get(StarterBlockTypeSeeder::class)->seedMissing();
        $category = 'shopcatlong1';
        (new CategoryRepository())->insert($this->context, [
            'uuid' => $category, 'tenant_uuid' => self::TENANT, 'slug' => 'serving', 'name' => self::LONG_CATEGORY,
            'position' => 0,
        ]);
        $made = [];
        $specs = [['stoneware-serving-bowl', self::LONG_NAME, 'shop-long-1'], ['cup', 'Cup', 'shop-long-2']];
        foreach ($specs as $i => $spec) {
            $product = $this->container->get(CatalogService::class)->createProduct($this->context, [
                'slug' => $spec[0], 'name' => $spec[1], 'status' => 'active', 'type' => 'physical',
                'variants' => [['sku' => $spec[2], 'price' => 3000, 'currency' => 'USD', 'option_values' => []]],
            ]);
            $uuid = (string) $product['uuid'];
            $this->container->get(Connection::class)->table('commerce_products')
                ->where('uuid', '=', $uuid)
                ->update(['created_at' => sprintf('2026-09-01 10:%02d:00', 59 - $i)]);
            $made[] = $uuid;
        }
        (new CategoryRepository())->attachProduct($this->context, $made[0], $category);
        return [
            'category' => $category, 'product' => $made[0], 'slug' => 'stoneware-serving-bowl', 'short' => $made[1],
        ];
    }

    /** A product's cover image: a committed fixture stored as a blob. */
    private function cover(string $product, string $blob, string $alt): void
    {
        $file = self::IMAGES[$blob];
        $this->container->get(Connection::class)->table('blobs')->insert([
            'uuid' => $blob, 'name' => basename($file), 'mime_type' => 'image/png', 'size' => 1,
            'url' => 'uploads/' . basename($file), 'visibility' => 'public', 'status' => 'active',
            'created_by' => 'user00000001', 'created_at' => '2026-09-28 00:00:00',
        ]);
        $this->container->get(ProductMediaRepository::class)->insert($this->context, [
            'uuid' => 'shopmedia' . substr($blob, -3), 'tenant_uuid' => self::TENANT, 'product_uuid' => $product,
            'blob_uuid' => $blob, 'role' => 'cover', 'position' => 0, 'alt' => $alt,
        ]);
    }

    /**
     * The 26 products, newest first.
     *
     * @return list<array<string,mixed>> each `{slug, name, variants}` with, where it applies, `rating`
     *     `[sum, count]`, `category` (a slug of {@see self::CATEGORIES}), `addon` and `cover` (a blob uuid)
     */
    private static function catalog(): array
    {
        $variant = static fn (string $sku, int $price, ?int $compareAt = null): array => array_filter([
            'sku' => $sku, 'price' => $price, 'compare_at_price' => $compareAt, 'currency' => 'USD',
            'option_values' => [],
        ], static fn (mixed $v): bool => $v !== null);
        $catalog = [
            [
                'slug' => 'tall-mug', 'name' => 'Tall mug', 'variants' => [$variant('shop-tall-mug', 2400)],
                'rating' => [9, 2], 'category' => 'mugs', 'cover' => 'shopblob0001',
            ],
            [
                'slug' => 'serving-bowl', 'name' => 'Serving bowl',
                'variants' => [$variant('shop-serving-bowl', 4200, 5600)], 'category' => 'bowls',
            ],
            [
                'slug' => 'mug-pair', 'name' => 'Mug pair',
                'variants' => [$variant('shop-mug-pair-s', 3600), $variant('shop-mug-pair-l', 4000)],
                'category' => 'mugs',
            ],
            [
                'slug' => 'gift-box', 'name' => 'Gift box', 'variants' => [$variant('shop-gift-box', 5000)],
                'addon' => 'shopaddon001',
            ],
            ['slug' => 'plain-cup', 'name' => 'Plain cup', 'variants' => [$variant('shop-plain-cup', 1500)]],
        ];
        for ($n = 6; $n <= 26; $n++) {
            $catalog[] = [
                'slug' => sprintf('item-%02d', $n), 'name' => sprintf('Item %02d', $n),
                'variants' => [$variant(sprintf('shop-item-%02d', $n), 1000 + 100 * $n)],
            ];
        }
        return $catalog;
    }
}
