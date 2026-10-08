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

    // ---- the Product tile ------------------------------------------------------------------

    public function testTheTilesPartsStyleTheirOwnPieces(): void
    {
        $token = static fn (string $t): array => [
            'colors' => ['surface' => ['type' => 'token', 'value' => 'color.' . $t]],
        ];
        $html = $this->render([self::block('tile', 'product_tile', [], ['parts' => [
            'image' => $token('accent'), 'quick_add' => $token('surface'), 'wishlist' => $token('muted'),
            'chip' => $token('white'),
        ]])], [self::item('mug')]);
        self::assertMatchesRegularExpression('~<span class="shop-grid__media t-bg-accent">~', $html);
        self::assertMatchesRegularExpression(
            '~<button class="shop-grid__action shop-grid__action--cart t-bg-surface"~',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~<button class="shop-grid__action shop-grid__action--wishlist t-bg-muted" type="button"~',
            $html,
        );
        self::assertMatchesRegularExpression('~<span class="shop-grid__tag t-bg-white">Men</span>~', $html);
    }

    public function testTheTileHidesQuickAddAndTheWishlistApart(): void
    {
        $noCart = $this->render([self::block('tile', 'product_tile', ['hide_cart' => true])], [self::item('mug')]);
        self::assertStringNotContainsString('shop-grid__action--cart', $noCart);
        self::assertStringContainsString('data-shop-wishlist-toggle', $noCart);
        $noHeart = $this->render([self::block('tile', 'product_tile', ['hide_wishlist' => true])], [self::item('mug')]);
        self::assertStringContainsString('shop-grid__action--cart', $noHeart);
        self::assertStringNotContainsString('data-shop-wishlist-toggle', $noHeart);
        $neither = $this->render(
            [self::block('tile', 'product_tile', ['hide_cart' => true, 'hide_wishlist' => true])],
            [self::item('mug')],
        );
        self::assertStringNotContainsString('shop-grid__actions', $neither, 'no empty action stack');
    }

    public function testTheTilesPictureOptions(): void
    {
        $html = $this->render([self::block('tile', 'product_tile', [
            'image_ratio' => 'portrait', 'image_fit' => 'cover', 'image_hover' => 'zoom',
        ])], [self::item('mug')]);
        self::assertMatchesRegularExpression(
            '~<div class="shop-grid__tile thallo-block thallo-block-product_tile'
                . ' thallo-block-product_tile--image-portrait'
                . ' thallo-block-product_tile--image-cover thallo-block-product_tile--image-zoom"~',
            $html,
        );
        $plain = $this->render([self::block('tile', 'product_tile', ['image_ratio' => 'bogus'])], [self::item('mug')]);
        self::assertStringNotContainsString('thallo-block-product_tile--image', $plain);
    }

    public function testTheTileShowsSaleAndNewBadges(): void
    {
        $products = [
            self::item('sale', ['onSale' => true, 'compare' => 'GHS 150.00']),
            self::item('fresh', ['createdAt' => gmdate('Y-m-d H:i:s', strtotime('-2 days'))]),
            self::item('old'),
        ];
        $off = $this->render([self::block('tile', 'product_tile')], $products);
        self::assertStringNotContainsString('shop-grid__badge', $off, 'badges are off until switched on');
        $badge = ['type' => 'token', 'value' => 'color.accent'];
        $html = $this->render([self::block('tile', 'product_tile', [
            'show_sale_badge' => true, 'sale_badge_text' => 'Promo', 'show_new_badge' => true,
            'new_badge_days' => 7, 'badge_position' => 'top-right',
        ], ['parts' => ['badge' => ['colors' => ['surface' => $badge]]]])], $products);
        self::assertSame(1, substr_count($html, 'shop-grid__badge--sale'));
        self::assertSame(1, substr_count($html, 'shop-grid__badge--new'));
        self::assertStringContainsString(
            '<span class="shop-grid__badges shop-grid__badges--top-right">'
                . '<span class="shop-grid__badge shop-grid__badge--sale t-bg-accent">Promo</span></span>',
            $html,
        );
        self::assertStringContainsString(
            '<span class="shop-grid__badge shop-grid__badge--new t-bg-accent">New</span>',
            $html,
        );
    }

    public function testTheShopStylesheetDrawsTheTileAsTheGridsCard(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-commerce/assets/shop.css');
        // A transparent frame until the Image part sets a background, as the grid's.
        self::assertMatchesRegularExpression(
            '~:is\(\.thallo-block-product-grid, \.thallo-block-product_tile\)'
                . ' \.shop-grid__media \{[^}]*background: transparent;~',
            $css,
        );
        // The heart is an outline until saved, whatever the Wishlist part's colours.
        self::assertMatchesRegularExpression(
            '~:is\(\.thallo-block-product-grid, \.thallo-block-product_tile\)'
                . ' \.shop-grid__action--wishlist path \{[^}]*fill: none;~',
            $css,
        );
        self::assertMatchesRegularExpression(
            '~\.thallo-block-product_tile--image-portrait\) \.shop-grid__image \{ aspect-ratio: 4 / 5; \}~',
            $css,
        );
        self::assertMatchesRegularExpression(
            '~\.thallo-block-product_tile--image-cover\) \.shop-grid__image \{ object-fit: cover;~',
            $css,
        );
        self::assertMatchesRegularExpression(
            '~\.shop-grid__item:hover \.thallo-block-product_tile--image-zoom \.shop-grid__image~',
            $css,
        );
    }

    // ---- the card itself, on the Product list ----------------------------------------------

    public function testTheCardPartStylesEveryCard(): void
    {
        $accent = ['type' => 'token', 'value' => 'color.accent'];
        $html = $this->render(
            [self::block('tile', 'product_tile')],
            [self::item('one'), self::item('two')],
            [],
            ['parts' => ['card' => ['colors' => ['surface' => $accent]]]],
        );
        self::assertSame(2, substr_count($html, '<li class="thallo-loop-card shop-grid__item t-bg-accent"'));
        self::assertStringNotContainsString('shop-grid t-bg-accent', $html, 'the list of cards is not a card');
    }

    public function testTheCardHoverEffect(): void
    {
        foreach (['lift', 'shadow'] as $effect) {
            $html = $this->render(
                [self::block('tile', 'product_tile')],
                [self::item('one')],
                ['card_hover' => $effect],
            );
            self::assertStringContainsString(
                '<div class="thallo-block thallo-block-product_loop thallo-block-product_loop--card-' . $effect,
                $html,
            );
        }
        $none = $this->render([self::block('tile', 'product_tile')], [self::item('one')], ['card_hover' => 'spin']);
        self::assertStringNotContainsString('thallo-block-product_loop--card-', $none);
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-commerce/assets/shop.css');
        self::assertMatchesRegularExpression(
            '~\.thallo-block-product_loop--card-lift\) \.shop-grid__item:hover'
                . ' \{ transform: translateY\(-0\.25rem\); \}~',
            $css,
        );
    }
}
