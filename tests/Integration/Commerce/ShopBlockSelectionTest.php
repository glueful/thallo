<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Links\ProductLinkRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * Featured product and Add to cart without a product (sections and templates design §6): on the
 * stage a named placeholder that keeps the block's styling and stays selectable; on the site no
 * shell when no product can be found (no slug, no entry), a shell that script hides for an unlinked
 * page, and the loading line hidden until script starts hydrating — with a link to the shop, not to
 * a product that may be gone, for a reader without JavaScript.
 */
final class ShopBlockSelectionTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ShopPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->seed = new ShopPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
    }

    protected function tearDown(): void
    {
        $this->extension()->setAnnotationScope('none');
        $this->connection()->getPDO()->exec('DELETE FROM thallo_commerce_product_links');
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        parent::tearDown();
    }

    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    /**
     * One block rendered as a page body renders it: on the stage (scope `entry`) or on the site.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $settings
     */
    private function render(string $type, array $data, bool $stage, ?string $entryUuid, array $settings = []): string
    {
        $this->extension()->resetPerRenderState();
        $this->extension()->setAnnotationScope($stage ? 'entry' : 'none');
        $blocks = [['id' => 'selblock0001', 'type' => $type, 'data' => $data, 'settings' => $settings]];
        $context = ['l' => $blocks] + ($entryUuid === null ? [] : ['entry' => ['uuid' => $entryUuid]]);
        try {
            return $this->container()->get(TwigFactory::class)->environment()
                ->createTemplate('{{ blocks(l) }}')->render($context);
        } finally {
            $this->extension()->setAnnotationScope('none');
        }
    }

    /** The class attribute of the element carrying `$marker` in its classes. */
    private static function classesOf(string $html, string $marker): string
    {
        self::assertSame(1, preg_match('~class="([^"]*' . preg_quote($marker, '~') . '[^"]*)"~', $html, $m), $html);
        return $m[1];
    }

    /** @return array<string,mixed> the decoded JSON of a block-data endpoint */
    private function blockData(string $block, array $query): array
    {
        $response = $this->handle(Request::create('/_shop/blocks/' . $block, 'GET', $query));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function entry(): string
    {
        return $this->container()->get(EntryRepository::class)->createEntry('ctype0000001', 'en', 1, null);
    }

    private function firstProduct(): array
    {
        $seeded = $this->seed->seed();
        $uuid = $seeded['products'][0];
        return (array) $this->connection()->table('commerce_products')->where('uuid', '=', $uuid)->first();
    }

    public function testTheStageSaysChooseAProductAndKeepsTheBlocksStyling(): void
    {
        $padding = ['padding' => ['top' => ['md' => ['type' => 'token', 'value' => 'spacing.lg']]]];
        $class = $this->container()->get(StyleClassRepository::class)->create([
            'name' => 'Spaced', 'style' => ['spacing' => $padding],
        ])['id'];
        $settings = [
            'classes' => [$class],
            'style' => ['spacing' => ['margin' => ['top' => ['base' => ['type' => 'token', 'value' => 'spacing.xl']]]]],
        ];
        foreach (['featured-product' => 'Featured product', 'add-to-cart' => 'Add to cart'] as $type => $name) {
            $stage = $this->render($type, ['product_slug' => ''], true, $this->entry(), $settings);
            self::assertStringContainsString('data-thallo-block="selblock0001"', $stage, "{$type}: selectable");
            self::assertStringContainsString("{$name} — choose a product", $stage);
            self::assertStringNotContainsString('data-shop-block', $stage, "{$type}: no shop shell on the stage");
            $classes = self::classesOf($stage, 'thallo-field-empty');
            self::assertStringContainsString("thallo-block-{$type}", $classes);
            self::assertStringContainsString('md:t-pt-lg', $classes, "{$type}: the style class shows");
            self::assertMatchesRegularExpression('~t-mt-xl~', $classes, "{$type}: the instance style shows");
        }
    }

    public function testTheStageNamesALinkedOrChosenProduct(): void
    {
        $product = $this->firstProduct();
        $chosen = $this->render('featured-product', ['product_slug' => $product['slug']], true, $this->entry());
        self::assertStringContainsString('Featured product — ' . $product['name'], $chosen);

        $entry = $this->entry();
        $this->container()->get(ProductLinkRepository::class)
            ->insert(ShopPageSeed::TENANT, (string) $product['uuid'], $entry);
        $linked = $this->render('add-to-cart', ['product_slug' => ''], true, $entry);
        self::assertStringContainsString('Add to cart — ' . $product['name'], $linked);
    }

    public function testTheProductLabelIsForTheStageOnly(): void
    {
        $product = $this->firstProduct();
        $twig = $this->container()->get(TwigFactory::class)->environment();
        $call = '[{{ shop_block_product_label(slug, "") }}]';

        $this->extension()->setAnnotationScope('none');
        self::assertNull($this->extension()->shopBlockProductLabel((string) $product['slug'], null));
        $site = $twig->createTemplate($call)->render(['slug' => $product['slug']]);
        self::assertSame('[]', $site, 'no lookup on the site');

        $this->extension()->setAnnotationScope('entry');
        self::assertSame($product['name'], $this->extension()->shopBlockProductLabel((string) $product['slug'], null));
    }

    public function testWithoutJavaScriptABlankBlockPromisesNoProduct(): void
    {
        $shop = $this->extension()->shopIndexUrl();
        foreach (['featured-product', 'add-to-cart'] as $type) {
            $site = $this->render($type, ['product_slug' => ''], false, $this->entry());
            self::assertSame(1, preg_match('~<noscript>(.*?)</noscript>~s', $site, $m), $type);
            self::assertStringContainsString('href="' . $shop . '"', $m[1], $type);
            $line = trim(strip_tags($m[1]));
            self::assertSame('Browse the shop', $line, "{$type}: nothing about a product it may not have");
        }
        // A chosen product keeps the line that names it.
        $chosen = $this->render('add-to-cart', ['product_slug' => 'stoneware-bowl'], false, $this->entry());
        self::assertStringContainsString('Browse the shop to add this product to your cart', $chosen);
    }

    public function testWithNoEntryContextNothingIsRendered(): void
    {
        foreach (['featured-product', 'add-to-cart'] as $type) {
            $site = $this->render($type, ['product_slug' => ''], false, null);
            self::assertStringNotContainsString("thallo-block-{$type}", $site, $type);
            self::assertStringNotContainsString('shop.js', $site, $type);
            self::assertStringNotContainsString('<noscript', $site, $type);
            self::assertStringNotContainsString('Loading', $site, $type);
        }
    }

    public function testAnUnlinkedPageRendersTheShell(): void
    {
        $entry = $this->entry();
        $site = $this->render('featured-product', ['product_slug' => ''], false, $entry);
        self::assertStringContainsString('data-shop-block="featured-product"', $site);
        self::assertStringContainsString('data-entry-uuid="' . $entry . '"', $site);
    }

    public function testAConfiguredBlockRendersItsShellWithLoadingHiddenAndALinkToTheShop(): void
    {
        $shop = $this->extension()->shopIndexUrl();
        self::assertNotNull($shop);
        foreach (
            [
                'featured-product' => 'data-shop-featured-empty',
                'add-to-cart' => 'data-shop-add-to-cart-status',
            ] as $type => $loading
        ) {
            $site = $this->render($type, ['product_slug' => 'stoneware-bowl'], false, $this->entry());
            self::assertStringContainsString('data-product-slug="stoneware-bowl"', $site, $type);
            self::assertMatchesRegularExpression(
                '~<p[^>]*' . $loading . '[^>]*hidden~',
                $site,
                "{$type}: loading hidden",
            );
            self::assertSame(1, preg_match('~<noscript>(.*?)</noscript>~s', $site, $m), $type);
            self::assertStringContainsString('href="' . $shop . '"', $m[1], "{$type}: the no-JS link is the shop");
            self::assertStringNotContainsString('/products/', $m[1], "{$type}: never a product link");
        }
    }

    public function testOnTheStageAProductGridIsANamedPlaceholder(): void
    {
        // shop.js never runs on the stage, so a hidden loading line would leave an empty, unselectable
        // box: the stage names what the grid shows instead, keeping the block's styling.
        $margin = ['top' => ['base' => ['type' => 'token', 'value' => 'spacing.xl']]];
        $settings = ['style' => ['spacing' => ['margin' => $margin]]];
        foreach (
            [
                'Product grid — the newest products' => ['source' => 'newest'],
                'Product grid — category mugs' => ['source' => 'category', 'category_slug' => 'mugs'],
                'Product grid — tag sale' => ['source' => 'tag', 'tag_slug' => 'sale'],
                'Product grid — chosen products' => ['source' => 'manual', 'products' => "a\nb"],
            ] as $label => $data
        ) {
            $stage = $this->render('product-grid', $data, true, $this->entry(), $settings);
            self::assertStringContainsString('data-thallo-block="selblock0001"', $stage, $label);
            self::assertStringContainsString($label, $stage);
            self::assertStringNotContainsString('data-shop-block', $stage, "{$label}: no shop shell on the stage");
            self::assertStringNotContainsString('Loading products', $stage, $label);
            $classes = self::classesOf($stage, 'thallo-field-empty');
            self::assertStringContainsString('thallo-block-product-grid', $classes);
            self::assertStringContainsString('t-mt-xl', $classes, "{$label}: the instance style shows");
        }
    }

    public function testAProductGridShipsItsLoadingLineHidden(): void
    {
        $site = $this->render('product-grid', ['source' => 'newest'], false, $this->entry());
        $loading = '~<p[^>]*data-shop-grid-empty[^>]*hidden[^>]*>Loading products…</p>~';
        self::assertMatchesRegularExpression($loading, $site);
        self::assertSame(1, preg_match('~<noscript>(.*?)</noscript>~s', $site, $m));
        self::assertStringContainsString('href="' . $this->extension()->shopIndexUrl() . '"', $m[1]);
    }

    public function testTheEndpointsSayWhenNothingIsConfigured(): void
    {
        $unlinked = $this->entry();
        foreach ([[], ['entry_uuid' => $unlinked]] as $query) {
            self::assertSame(
                ['product' => null, 'unconfigured' => true],
                $this->blockData('featured-product', $query),
            );
            $cart = $this->blockData('add-to-cart', $query);
            self::assertSame('unavailable', $cart['mode']);
            self::assertTrue($cart['unconfigured'] ?? false);
        }

        $gone = ['product_slug' => 'no-such-product'];
        self::assertSame(['product' => null], $this->blockData('featured-product', $gone));
        self::assertArrayNotHasKey('unconfigured', $this->blockData('add-to-cart', $gone));
    }

    public function testAnEmptyShopKeepsTheNotice(): void
    {
        $stage = $this->render('featured-product', ['product_slug' => ''], true, $this->entry());
        self::assertStringContainsString('Featured product — choose a product', $stage);
        self::assertTrue($this->blockData('add-to-cart', ['entry_uuid' => $this->entry()])['unconfigured'] ?? false);
    }
}
