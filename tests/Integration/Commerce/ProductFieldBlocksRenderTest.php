<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\AddToCartViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductViewModel;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * The product field blocks (type layouts plan C1, P2): each renders a part of today's product page
 * from the frame's `layout_context` — at the layout's root and two containers deep, on the public
 * page (scope `none`) and on the stage (scope `layout`) — with the `shop-product__*` class of the
 * markup it reproduces, so the shop's stylesheet styles it by default. An empty value renders
 * nothing on the site and a named placeholder on the stage.
 */
final class ProductFieldBlocksRenderTest extends AppTestCase
{
    private const SLUGS = [
        'product_breadcrumb', 'product_gallery', 'product_category', 'product_name', 'product_rating',
        'product_price', 'product_description', 'product_buy', 'product_story',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
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

    /**
     * @param list<array<string,mixed>> $variants
     * @param array<string,mixed> $overrides product row fields
     * @return array<string,mixed> a frame context as ShopProductPage builds it
     */
    private function frame(array $variants, array $overrides = [], bool $media = true, bool $addon = false): array
    {
        $urls = $this->container()->get(ShopUrlGenerator::class);
        $product = $overrides + [
            'uuid' => 'produuid0001', 'slug' => 'linen-lamp', 'name' => 'Linen table lamp', 'type' => 'physical',
            'status' => 'active', 'description' => '<p>A hand-thrown ceramic base.</p>',
            'rating_sum' => 9, 'rating_count' => 2,
        ];
        $gallery = $media ? [
            ['url' => '/media/cover.png', 'alt' => null],
            ['url' => '/media/alt.png', 'alt' => 'From above'],
        ] : [];
        $addToCart = AddToCartViewModel::build($product, $variants, $addon, $urls, 'USD');
        $vm = ProductViewModel::fromRow($product, $variants, $gallery[0]['url'] ?? null, $urls, $addToCart, $gallery);
        return [
            'product' => $vm,
            'breadcrumb_category' => $media ? ['slug' => 'lighting', 'name' => 'Lighting'] : null,
            'enrichment_html' => $media ? new \Twig\Markup('<h2 class="story-probe">Made by hand</h2>', 'UTF-8') : null,
            'shop_index' => '/shop',
        ];
    }

    private static function simple(): array
    {
        return [['uuid' => 'variant00001', 'status' => 'active', 'price' => 8900, 'compare_at_price' => 11900,
            'currency' => 'USD', 'sku' => 'lamp-1']];
    }

    private static function block(string $type, array $data = []): array
    {
        return ['id' => substr(str_replace('_', '', $type) . '000000000000', 0, 12), 'type' => $type,
            'data' => $data, 'settings' => []];
    }

    /** One block rendered through layout_blocks(), at the root and inside two nested containers. */
    private function render(string $scope, array $block, array $frame): array
    {
        $out = [];
        $nested = [self::box('outer', [self::box('inner', [$block])])];
        foreach (['root' => [$block], 'nested' => $nested] as $where => $tree) {
            $this->extension()->resetPerRenderState();
            $this->extension()->setAnnotationScope($scope);
            $twig = $this->container()->get(TwigFactory::class)->environment();
            $out[$where] = $twig->createTemplate('{{ layout_blocks(layout.blocks) }}')->render([
                'layout' => ['blocks' => $tree, 'surface' => 'product', 'target' => '@site'],
                'layout_context' => $frame,
            ]);
        }
        return $out;
    }

    private static function box(string $id, array $content): array
    {
        return ['id' => str_pad($id, 12, '0'), 'type' => 'container',
            'data' => ['element' => 'div', 'content' => $content], 'settings' => []];
    }

    public function testEachBlockRendersItsPartAtAnyDepthOnThePageAndTheStage(): void
    {
        $frame = $this->frame(self::simple());
        $expect = [
            'product_breadcrumb' => ['shop-product__breadcrumb', 'href="/shop"', '>Lighting</a>', 'Linen table lamp'],
            'product_gallery' => [
                'shop-product__gallery', 'data-shop-gallery', 'src="/media/cover.png"', 'shop-product__thumbs',
            ],
            'product_category' => ['shop-product__eyebrow', 'Lighting'],
            'product_name' => [
                '<h1 class="thallo-block thallo-block-product_name shop-product__name', 'Linen table lamp</h1>',
            ],
            'product_rating' => ['shop-product__rating', '<span>4.5</span>', '· 2 reviews'],
            'product_price' => ['shop-product__price-current">$89.00', 'shop-product__price-compare">$119.00'],
            'product_description' => ['shop-product__description', '<p>A hand-thrown ceramic base.</p>'],
            'product_buy' => [
                '<form class="shop-product__add-to-cart" method="post" action="/_shop/cart/add" data-shop-buy',
                '<input type="hidden" name="variant_uuid" value="variant00001">', 'shop-product__wishlist',
                'shop-product__availability',
            ],
            'product_story' => ['shop-product__enrichment', '<h2 class="story-probe">Made by hand</h2>'],
        ];
        foreach (self::SLUGS as $slug) {
            foreach (['none', 'layout'] as $scope) {
                foreach ($this->render($scope, self::block($slug), $frame) as $where => $html) {
                    self::assertStringContainsString("thallo-block-{$slug}", $html, "{$slug} {$scope} {$where}");
                    foreach ($expect[$slug] as $needle) {
                        self::assertStringContainsString($needle, $html, "{$slug} {$scope} {$where}: {$needle}");
                    }
                    self::assertStringNotContainsString('thallo-field-empty', $html, "{$slug} {$scope} {$where}");
                }
            }
        }
    }

    public function testTheBuyBlockMakesTheSameDecisionAsThePage(): void
    {
        $select = $this->render('none', self::block('product_buy'), $this->frame([
            ['uuid' => 'variant0000s', 'status' => 'active', 'price' => 1800, 'currency' => 'USD', 'sku' => 'mug-s'],
            ['uuid' => 'variant0000l', 'status' => 'active', 'price' => 2200, 'currency' => 'USD', 'sku' => 'mug-l'],
        ]))['nested'];
        self::assertStringContainsString('<select name="variant_uuid" required>', $select);

        $link = $this->render('none', self::block('product_buy'), $this->frame(self::simple(), [], true, true))['root'];
        self::assertStringContainsString('This product is not available for online purchase right now.', $link);
        self::assertStringNotContainsString('<form', $link);

        $simple = $this->frame(self::simple());
        $hidden = self::block('product_buy', ['hide_wishlist' => true, 'hide_availability' => true]);
        $bare = $this->render('none', $hidden, $simple)['root'];
        self::assertStringContainsString('data-shop-buy', $bare);
        self::assertStringNotContainsString('shop-product__wishlist', $bare);
        self::assertStringNotContainsString('shop-product__availability', $bare);

        $price = $this->render('none', self::block('product_price', ['hide_compare_at' => true]), $simple)['root'];
        self::assertStringContainsString('$89.00', $price);
        self::assertStringNotContainsString('shop-product__price-compare', $price);

        $noThumbs = $this->render('none', self::block('product_gallery', ['hide_thumbnails' => true]), $simple)['root'];
        self::assertStringContainsString('shop-product__cover', $noThumbs);
        self::assertStringNotContainsString('shop-product__thumbs', $noThumbs);
    }

    /**
     * A product with no images, no description, no category, no story and no reviews: the site shows
     * nothing for them (the rating shows zeros, as today); the stage names each empty one.
     */
    public function testEmptyValuesRenderNothingOnTheSiteAndAPlaceholderOnTheStage(): void
    {
        $frame = $this->frame(self::simple(), ['description' => null, 'rating_count' => 0, 'rating_sum' => 0], false);
        foreach (['product_gallery', 'product_description', 'product_category', 'product_story'] as $slug) {
            $site = $this->render('none', self::block($slug), $frame);
            self::assertSame('', trim($site['root']), "{$slug} renders nothing on the site");
            $stage = $this->render('layout', self::block($slug), $frame);
            self::assertStringContainsString('thallo-field-empty', $stage['nested'], "{$slug}: named on the stage");
            self::assertStringContainsString("thallo-block-{$slug}", $stage['nested']);
        }
        $zeros = $this->render('none', self::block('product_rating'), $frame)['root'];
        self::assertStringContainsString('shop-product__rating--none', $zeros);
        self::assertStringContainsString('· 0 reviews', $zeros);

        $hidden = self::block('product_rating', ['hide_when_none' => true]);
        self::assertSame('', trim($this->render('none', $hidden, $frame)['root']));
        self::assertStringContainsString('thallo-field-empty', $this->render('layout', $hidden, $frame)['root']);
    }
}
