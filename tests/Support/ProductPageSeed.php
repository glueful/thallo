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
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The product pages the C1 proofs render (type layouts plan C1): every value fixed, so a page renders
 * the same markup on every run and in every environment — only the ids the catalog generates vary,
 * and the callers name them. Shared by the PHP tests and scripts/build-product-layout-proof-fixtures.
 *
 * The shop resolves its tenant through the widened-schema default tenant ({@see self::useTenant()}),
 * as the storefront tests do.
 */
final class ProductPageSeed
{
    public const TENANT = 'prooftenant1';

    /** blob uuid => the committed image a self-contained page inlines for it */
    public const IMAGES = [
        'proofblob001' => 'tests/fixtures/commerce/product-cover.png',
        'proofblob002' => 'tests/fixtures/commerce/product-alt.png',
    ];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ApplicationContext $context,
    ) {
    }

    /**
     * Point the shop at the proof tenant.
     *
     * @return array<string,?string> the flags it replaced, for {@see self::restoreTenant()}
     */
    public function useTenant(): array
    {
        $flags = $this->container->get(SystemFlags::class);
        $previous = [
            'tenancy.schema_state' => $flags->get('tenancy.schema_state'),
            'tenancy.default_tenant_uuid' => $flags->get('tenancy.default_tenant_uuid'),
        ];
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', self::TENANT);
        return $previous;
    }

    /** @param array<string,?string> $previous */
    public function restoreTenant(array $previous): void
    {
        $flags = $this->container->get(SystemFlags::class);
        foreach ($previous as $key => $value) {
            $value === null ? $flags->forget($key) : $flags->put($key, (string) $value);
        }
    }

    /** Remove every catalog row a seed writes (the commerce tables are not truncated between tests). */
    public function clear(): void
    {
        $pdo = $this->container->get(Connection::class)->getPDO();
        $tables = [
            'commerce_product_addons', 'commerce_cart_lines', 'commerce_carts', 'commerce_stock',
            'commerce_stock_movements', 'commerce_product_media', 'commerce_product_categories',
            'commerce_categories', 'commerce_variants', 'commerce_products',
        ];
        foreach ($tables as $table) {
            $pdo->exec("DELETE FROM {$table}");
        }
    }

    /**
     * The showcase product at `/shop/products/linen-lamp`: a category, two images (cover first), a
     * compare-at price, two reviews and a linked story.
     *
     * @return array{product: string, variant: string, entry: string}
     */
    public function showcase(): array
    {
        $this->container->get(StarterBlockTypeSeeder::class)->seedMissing();
        $category = 'proofcat0001';
        (new CategoryRepository())->insert($this->context, [
            'uuid' => $category, 'tenant_uuid' => self::TENANT, 'slug' => 'lighting', 'name' => 'Lighting',
            'position' => 0,
        ]);
        $product = $this->container->get(CatalogService::class)->createProduct($this->context, [
            'slug' => 'linen-lamp',
            'name' => 'Linen table lamp',
            'status' => 'active',
            'type' => 'physical',
            'description' => '<p>A hand-thrown ceramic base under a natural linen shade.</p>'
                . '<p>Warm, dimmable light for a bedside or a reading corner.</p>',
            'variants' => [[
                'sku' => 'proof-lamp-1', 'price' => 8900, 'compare_at_price' => 11900, 'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);
        $uuid = (string) $product['uuid'];
        $this->container->get(ProductRepository::class)->update($this->context, self::TENANT, $uuid, [
            'rating_sum' => 9, 'rating_count' => 2,
        ]);
        (new CategoryRepository())->attachProduct($this->context, $uuid, $category);

        $db = $this->container->get(Connection::class);
        $position = 0;
        foreach (self::IMAGES as $blob => $file) {
            $db->table('blobs')->insert([
                'uuid' => $blob, 'name' => basename($file), 'mime_type' => 'image/png', 'size' => 1,
                'url' => 'uploads/' . basename($file), 'visibility' => 'public', 'status' => 'active',
                'created_by' => 'user00000001', 'created_at' => '2026-09-27 00:00:00',
            ]);
            $this->container->get(ProductMediaRepository::class)->insert($this->context, [
                'uuid' => 'proofmedia0' . ($position + 1), 'tenant_uuid' => self::TENANT, 'product_uuid' => $uuid,
                'blob_uuid' => $blob, 'role' => $position === 0 ? 'cover' : 'gallery', 'position' => $position,
                'alt' => $position === 0 ? 'The lamp, lit' : 'The lamp from above',
            ]);
            $position++;
        }

        $entry = $this->story();
        $this->container->get(ProductLinkService::class)->link($this->context, $uuid, $entry);

        return ['product' => $uuid, 'variant' => (string) $product['variants'][0]['uuid'], 'entry' => $entry];
    }

    /**
     * A product with two active variants at `/shop/products/stoneware-mug`: the buy area offers a
     * native select.
     *
     * @return array{product: string, variants: list<string>}
     */
    public function multiVariant(): array
    {
        $product = $this->container->get(CatalogService::class)->createProduct($this->context, [
            'slug' => 'stoneware-mug',
            'name' => 'Stoneware mug',
            'status' => 'active',
            'type' => 'physical',
            'variants' => [
                ['sku' => 'proof-mug-s', 'price' => 1800, 'currency' => 'USD', 'option_values' => []],
                ['sku' => 'proof-mug-l', 'price' => 2200, 'currency' => 'USD', 'option_values' => []],
            ],
        ]);
        return [
            'product' => (string) $product['uuid'],
            'variants' => array_map(static fn (array $v): string => (string) $v['uuid'], $product['variants']),
        ];
    }

    /**
     * A product with a required add-on at `/shop/products/gift-set`: not purchasable online.
     *
     * @return array{product: string, variant: string, addon: string}
     */
    public function withRequiredAddon(): array
    {
        $product = $this->container->get(CatalogService::class)->createProduct($this->context, [
            'slug' => 'gift-set',
            'name' => 'Gift set',
            'status' => 'active',
            'type' => 'physical',
            'variants' => [['sku' => 'proof-gift-1', 'price' => 4500, 'currency' => 'USD', 'option_values' => []]],
        ]);
        $addon = 'proofaddon01';
        $this->container->get(Connection::class)->table('commerce_product_addons')->insert([
            'uuid' => $addon, 'tenant_uuid' => self::TENANT, 'product_uuid' => (string) $product['uuid'],
            'name' => 'Gift wrap', 'field_type' => 'checkbox', 'required' => true, 'price_delta' => 0,
            'position' => 0, 'status' => 'active',
        ]);
        return [
            'product' => (string) $product['uuid'],
            'variant' => (string) $product['variants'][0]['uuid'],
            'addon' => $addon,
        ];
    }

    /** The story linked to the showcase product: a published entry of a type with a blocks body. */
    private function story(): string
    {
        $types = $this->container->get(ContentTypeRepository::class);
        $type = $types->findBySlug('proof_story')['uuid'] ?? $types->create([
            'slug' => 'proof_story', 'name' => 'Proof stories', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = $this->container->get(EntryRepository::class);
        $entry = $entries->createEntry((string) $type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', [
            'title' => 'Made by hand',
            'body' => [
                ['id' => 'proofstory01', 'type' => 'heading', 'data' => ['text' => 'Made by hand'], 'settings' => []],
                ['id' => 'proofstory02', 'type' => 'rich_text', 'data' => [
                    'body' => '<p>Each base is thrown on the wheel and glazed in small batches.</p>',
                ], 'settings' => []],
            ],
        ], 1, 0, 'user00000001');
        $this->container->get(RouteRepository::class)->assign($entry, (string) $type, 'en', 'made-by-hand');
        $this->container->get(PublishService::class)->publish($entry, 'en', 'user00000001');
        return $entry;
    }
}
