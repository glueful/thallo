<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * The shop page's own blocks (type layouts plan C2, S1): the Shop title with its product count, the
 * Category chips, and Release B's Page navigation over the shop's pages — each renders what
 * `shop/index.twig` and `shop/category.twig` show today, from the frame's `layout_context`, on the
 * public page and on the stage.
 */
final class ShopLayoutBlocksRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const RAIL = [
        ['name' => 'Mugs', 'url' => '/shop/categories/mugs', 'active' => false],
        ['name' => 'Bowls', 'url' => '/shop/categories/bowls', 'active' => false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // The blocks' style declarations as shipped, and no memo an earlier test left behind.
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

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $context layout_context over the shop home's
     */
    private function render(
        string $type,
        array $data = [],
        array $context = [],
        string $scope = 'none',
        array $settings = [],
    ): string {
        $this->extension()->resetPerRenderState();
        $this->extension()->setAnnotationScope($scope);
        return $this->container()->get(TwigFactory::class)->environment()
            ->createTemplate('{{ layout_blocks(layout.blocks) }}')
            ->render([
                'layout' => [
                    'blocks' => [['id' => 'shopblock001', 'type' => $type, 'data' => $data, 'settings' => $settings]],
                    'surface' => 'shop_index',
                    'target' => '@site',
                ],
                'layout_context' => $context + [
                    'products' => [], 'total' => 26, 'categories' => self::RAIL, 'shop_index' => '/shop',
                    'category' => null,
                    'pagination' => [
                        'page' => 1, 'total_pages' => 2, 'prev_path' => null, 'next_path' => '/shop?page=2',
                    ],
                ],
            ]);
    }

    private const MUGS = ['name' => 'Mugs', 'slug' => 'mugs', 'url' => '/shop/categories/mugs'];

    public function testTheShopTitleNamesThePageAndCountsItsProducts(): void
    {
        $home = $this->render('shop_title');
        self::assertMatchesRegularExpression(
            '~<div class="thallo-block thallo-block-shop_title shop-titlerow[^"]*">'
                . '\s*<h1 class="shop-titlerow__heading[^"]*">Shop</h1>'
                . '\s*<span class="shop-titlerow__count">26 products</span>\s*</div>~',
            $home,
        );
        $mugs = $this->render('shop_title', ['level' => 'h2'], ['category' => self::MUGS, 'total' => 1]);
        self::assertMatchesRegularExpression('~<h2 class="shop-titlerow__heading[^"]*">Mugs</h2>~', $mugs);
        self::assertStringContainsString('<span class="shop-titlerow__count">1 product</span>', $mugs);
        $hidden = $this->render('shop_title', ['hide_count' => true]);
        self::assertStringNotContainsString('shop-titlerow__count', $hidden);
    }

    /** The row takes the box styles, the heading the text styles. */
    public function testTheTitlesRowAndHeadingTakeTheirOwnStyles(): void
    {
        $token = static fn (string $value): array => ['base' => ['type' => 'token', 'value' => $value]];
        $html = $this->render('shop_title', [], [], 'none', ['style' => [
            'spacing' => ['margin' => ['bottom' => $token('spacing.none')]],
            'typography' => ['size' => $token('typography.size.3xl')],
        ]]);
        self::assertMatchesRegularExpression(
            '~class="thallo-block thallo-block-shop_title shop-titlerow [^"]*t-mb-none~',
            $html,
        );
        self::assertMatchesRegularExpression('~<h1 class="shop-titlerow__heading [^"]*t-size-3xl~', $html);
        self::assertDoesNotMatchRegularExpression('~shop-titlerow [^"]*t-size-3xl~', $html);
    }

    public function testTheChipsMarkThePagesOwnChip(): void
    {
        $home = $this->render('category_rail');
        self::assertStringContainsString(
            '<a class="shop-rail__chip shop-rail__chip--active" aria-current="page" href="/shop">All</a>',
            $home,
        );
        self::assertStringContainsString('<a class="shop-rail__chip" href="/shop/categories/mugs">Mugs</a>', $home);
        self::assertStringContainsString('aria-label="Categories"', $home);

        $rail = self::RAIL;
        $rail[0]['active'] = true;
        $mugs = $this->render(
            'category_rail',
            ['all_label' => 'Everything'],
            ['categories' => $rail, 'category' => self::MUGS],
        );
        self::assertStringContainsString('<a class="shop-rail__chip" href="/shop">Everything</a>', $mugs);
        self::assertStringContainsString(
            '<a class="shop-rail__chip shop-rail__chip--active" aria-current="page"'
                . ' href="/shop/categories/mugs">Mugs</a>',
            $mugs,
        );
    }

    public function testNoCategoriesShowNothingOnTheSiteAndAPlaceholderOnTheStage(): void
    {
        $none = $this->render('category_rail', [], ['categories' => []]);
        self::assertStringNotContainsString('thallo-block-category_rail', $none);
        self::assertStringContainsString(
            'Category chips — the shop has no categories',
            $this->render('category_rail', [], ['categories' => []], 'layout'),
        );
    }

    /** Release B's Page navigation renders the shop's pages as `_pagination.twig` does today. */
    public function testThePageNavigationRendersTheShopsPages(): void
    {
        $page2 = $this->render('pagination', [], [
            'pagination' => ['page' => 2, 'total_pages' => 2, 'prev_path' => '/shop', 'next_path' => null],
        ]);
        self::assertStringContainsString('<a href="/shop" rel="prev">Newer</a>', $page2);
        self::assertStringContainsString('<span>Page 2 of 2</span>', $page2);
        self::assertStringNotContainsString('rel="next"', $page2);
        self::assertStringContainsString('<a href="/shop?page=2" rel="next">Older</a>', $this->render('pagination'));
    }
}
