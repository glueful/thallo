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
            $this->connection()->table('commerce_product_categories')->insert([
                'product_uuid' => $product,
                'category_uuid' => $uuid,
            ]);
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
            $this->connection()->table('commerce_product_tags')->insert([
                'product_uuid' => $product,
                'tag_uuid' => $uuid,
            ]);
        }
        return $uuid;
    }

    protected function clearCatalog(): void
    {
        foreach (
            ['commerce_stock', 'commerce_product_addons', 'commerce_product_categories', 'commerce_product_tags',
            'commerce_categories', 'commerce_tags', 'commerce_variants', 'commerce_products'] as $table
        ) {
            $this->connection()->getPDO()->exec('DELETE FROM ' . $table);
        }
    }
}
