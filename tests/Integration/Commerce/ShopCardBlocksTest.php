<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Layouts\ShopPageSurface;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * The shop layouts' card has what a Product grid card has: an Add to cart button block, the tile's
 * picture, quick add, wishlist, chip and badges styled apart, and the card itself styled and lifted
 * on hover. Cards are built here as the shop pages hand them over (ShopCardStateTest proves the
 * pages supply stock, sale and age).
 */
final class ShopCardBlocksTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    protected function tearDown(): void
    {
        $this->extension()->setAnnotationScope('none');
        parent::tearDown();
    }

    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    /** @param array<string,mixed> $o */
    private static function item(string $slug, array $o = []): array
    {
        return [
            'uuid' => str_pad($slug, 12, 'x'), 'name' => ucfirst($slug), 'url' => '/shop/products/' . $slug,
            'coverUrl' => '/v1/blobs/cover' . $slug, 'rating' => null, 'priceFormatted' => 'GHS 100.00',
            'compareAtFormatted' => $o['compare'] ?? null, 'categoryName' => 'Men',
            'cartMode' => $o['cartMode'] ?? 'direct',
            'directVariantUuid' => ($o['cartMode'] ?? 'direct') === 'direct' ? 'variant' . $slug : null,
            'inStock' => $o['inStock'] ?? true, 'onSale' => $o['onSale'] ?? false,
            'createdAt' => $o['createdAt'] ?? '2020-01-01 00:00:00',
        ];
    }

    private static function block(string $id, string $type, array $data = [], array $settings = []): array
    {
        return ['id' => str_pad($id, 12, '0'), 'type' => $type, 'data' => $data, 'settings' => $settings];
    }

    /**
     * @param list<array<string,mixed>> $card
     * @param list<array<string,mixed>> $products
     * @param array<string,mixed> $loopData
     * @param array<string,mixed> $loopSettings
     */
    private function render(
        array $card,
        array $products,
        array $loopData = [],
        array $loopSettings = [],
        string $scope = 'none',
    ): string {
        $this->extension()->resetPerRenderState();
        $this->extension()->setAnnotationScope($scope);
        $loop = self::block('loop', 'product_loop', ['card' => $card] + $loopData, $loopSettings);
        return $this->container()->get(TwigFactory::class)->environment()
            ->createTemplate('{{ layout_blocks(layout.blocks) }}')
            ->render([
                'layout' => ['blocks' => [$loop], 'surface' => 'shop_index', 'target' => '@site'],
                'layout_context' => [
                    'products' => $products, 'total' => count($products), 'categories' => [],
                    'shop_index' => '/shop', 'category' => null,
                    'pagination' => ['page' => 1, 'total_pages' => 1, 'prev_path' => null, 'next_path' => null],
                ],
            ]);
    }

    // ---- the Add to cart button block -------------------------------------------------------

    public function testTheAddToCartButtonGoesInTheCardOnly(): void
    {
        self::assertContains('product_add_to_cart', ShopPageSurface::CARD_BLOCKS);
    }

    public function testTheAddToCartButtonAddsAOneVariantProduct(): void
    {
        $html = $this->render([self::block('buy', 'product_add_to_cart')], [self::item('mug')]);
        self::assertMatchesRegularExpression(
            '~<div class="thallo-block thallo-block-product_add_to_cart">\s*'
                . '<form class="shop-grid__buy-form" method="post" action="/_shop/cart/add">\s*'
                . '<input type="hidden" name="variant_uuid" value="variantmug">\s*'
                . '<input type="hidden" name="quantity" value="1">\s*'
                . '<button class="shop-grid__buy" type="submit" aria-label="Add Mug to cart">Add to cart</button>~',
            $html,
        );
    }

    public function testTheAddToCartButtonAsksForOptionsOrSaysSoldOut(): void
    {
        $html = $this->render([self::block('buy', 'product_add_to_cart')], [
            self::item('sizes', ['cartMode' => 'options']),
            self::item('gone', ['inStock' => false]),
        ]);
        self::assertStringContainsString(
            '<a class="shop-grid__buy shop-grid__buy--options" href="/shop/products/sizes">Choose options</a>',
            $html,
        );
        self::assertStringContainsString(
            '<button class="shop-grid__buy shop-grid__buy--sold-out" type="button" disabled>Sold out</button>',
            $html,
        );
        self::assertStringNotContainsString('variantgone', $html, 'a sold-out product posts nothing');
    }

    public function testTheButtonPartStylesTheButton(): void
    {
        $accent = ['type' => 'token', 'value' => 'color.accent'];
        $html = $this->render(
            [self::block('buy', 'product_add_to_cart', [], [
                'parts' => ['button' => ['colors' => ['surface' => $accent]]],
            ])],
            [self::item('mug')],
        );
        self::assertStringContainsString('<button class="shop-grid__buy t-bg-accent" type="submit"', $html);
    }
}
