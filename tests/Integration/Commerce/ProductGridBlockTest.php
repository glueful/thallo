<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SeedsShopCatalog;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Product grid spec §3.1, §5, §6: the block renders its cards on the server — stage and site — with
 * the display toggles, title tag, badges and card/image classes; without JavaScript the cards work.
 */
final class ProductGridBlockTest extends AppTestCase
{
    use SeedsShopCatalog;
    use SyncsBlockStyleDeclarations;

    private const TENANT = 'gridblocktst';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$app?->getContainer()->get(\Glueful\Database\Connection::class)->getPDO()->exec(
            "ALTER TABLE entries ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$app?->getContainer()->get(\Glueful\Database\Connection::class)->getPDO()->exec(
            'ALTER TABLE entries DROP COLUMN IF EXISTS tenant_uuid'
        );
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->clearCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT);
    }

    protected function tearDown(): void
    {
        $this->clearCatalog();
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $settings
     */
    private function renderBlock(array $data, bool $stage = false, array $settings = []): string
    {
        /** @var RenderContextExtension $extension */
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope($stage ? 'entry' : 'none');
        $extension->setLocale('en');
        try {
            return $this->container()->get(TwigFactory::class)->environment()
                ->createTemplate('{{ blocks(l) }}')
                ->render(['l' => [
                    ['id' => 'gridblock001', 'type' => 'product-grid', 'data' => $data, 'settings' => $settings],
                ]]);
        } finally {
            $extension->setAnnotationScope('none');
        }
    }

    /** @param array<string,mixed> $data */
    private function blockDataErrors(array $data): array
    {
        foreach ((new ShopBlockTypesContributor())->blockTypeDefinitions() as $definition) {
            if ($definition->slug === ShopBlockTypesContributor::SLUG_PRODUCT_GRID) {
                try {
                    (new FieldValidator())->validate(ContentTypeSchema::fromArray($definition->schema), $data);
                    return [];
                } catch (ValidationException $e) {
                    return $e->errors();
                }
            }
        }
        self::fail('no product grid');
    }

    /** @param array<string,mixed> $data */
    private function assertBlockDataValid(array $data): void
    {
        self::assertSame([], $this->blockDataErrors($data));
    }

    /** @param array<string,mixed> $data one field's value, refused under that field's name */
    private function assertBlockDataInvalid(array $data): void
    {
        self::assertArrayHasKey((string) array_key_first($data), $this->blockDataErrors($data));
    }

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
        self::assertStringContainsString('<span class="shop-grid__media', $html, 'the image frame (no cover seeded)');
        self::assertMatchesRegularExpression(
            '~<h3 class="shop-grid__name"><a class="shop-grid__name-link[^"]*" href="[^"]+">P</a></h3>~',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~<a class="shop-grid__media-link" href="[^"]+" tabindex="-1" aria-hidden="true">\s*'
                . '<span class="shop-grid__media~',
            $html,
            'the image links to the product',
        );
        self::assertStringNotContainsString('shop-grid__rating', $html, 'no reviews, no rating line');
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
        foreach (
            [
                'shop-grid__media"', 'shop-grid__name', 'shop-grid__price', 'shop-grid__rating',
                'shop-grid__label--category',
            'shop-grid__action--cart', 'data-shop-wishlist-toggle'] as $absent
        ) {
            self::assertStringNotContainsString($absent, $html, $absent);
        }
        self::assertSame(['Summer'], $this->labels($html, 'tag'));
    }

    public function testWithTheTitleHiddenTheImageLinkIsTheNamedFocusableProductLink(): void
    {
        $this->product('p');
        $html = $this->renderBlock(['show_title' => false, 'show_add_to_cart' => false, 'show_wishlist' => false]);
        self::assertMatchesRegularExpression('~<a class="shop-grid__media-link" href="[^"]+" aria-label="P">~', $html);
        self::assertStringNotContainsString('tabindex="-1"', $html);
        self::assertStringNotContainsString('aria-hidden="true">' . "\n" . '    <span class="shop-grid__media', $html);
        self::assertStringNotContainsString('shop-grid__name', $html);
        self::assertStringNotContainsString('shop-grid__action', $html);
    }

    public function testTheTitlesPartClassesSitOnTheFocusableAnchor(): void
    {
        $this->product('p');
        $accent = ['type' => 'token', 'value' => 'color.accent'];
        $html = $this->renderBlock([], false, ['parts' => ['title' => ['hover' => ['colors' => ['text' => $accent]]]]]);
        self::assertMatchesRegularExpression('~<a class="shop-grid__name-link[^"]*t-hover-fg-accent~', $html);
        self::assertDoesNotMatchRegularExpression('~<h3 class="[^"]*t-hover-~', $html);
    }

    public function testTheImagesBackgroundSitsOnTheImageFrame(): void
    {
        $this->product('p');
        $white = ['type' => 'token', 'value' => 'color.white'];
        $html = $this->renderBlock([], false, ['parts' => ['image' => ['colors' => ['surface' => $white]]]]);
        self::assertMatchesRegularExpression('~<span class="shop-grid__media[^"]* t-bg-white[ "]~', $html);
        self::assertDoesNotMatchRegularExpression('~<li class="shop-grid__item[^"]*t-bg-white~', $html);
    }

    public function testTheDetailsPaddingSitsOnTheTextUnderTheImage(): void
    {
        $this->product('p');
        $lg = ['base' => ['type' => 'token', 'value' => 'spacing.lg']];
        $html = $this->renderBlock([], false, ['parts' => ['details' => ['spacing' => ['padding' => ['top' => $lg]]]]]);
        self::assertMatchesRegularExpression('~<span class="shop-grid__body[^"]* t-pt-lg[ "]~', $html);
        self::assertDoesNotMatchRegularExpression('~<(li|span) class="shop-grid__(item|media)[^"]*t-pt-lg~', $html);
    }

    public function testARatedProductShowsItsRatingUnderTheNameAndThePriceOnItsOwnLine(): void
    {
        $p = $this->product('p');
        $this->connection()->table('commerce_products')->where('uuid', '=', $p)
            ->update(['rating_sum' => 9, 'rating_count' => 2]);
        $this->category('men', $p);
        $html = $this->renderBlock([]);
        self::assertMatchesRegularExpression(
            '~<span class="shop-grid__rating">.*?4\.5\s*<span class="shop-grid__rating-count">\(2\)~s',
            $html,
        );
        // Name, then the rating, then the categories, then the price — the price no longer shares a row.
        $name = (int) strpos($html, 'shop-grid__name"');
        $rating = (int) strpos($html, 'shop-grid__rating"');
        $labels = (int) strpos($html, 'shop-grid__labels');
        $price = (int) strpos($html, 'shop-grid__price');
        self::assertTrue($name < $rating && $rating < $labels && $labels < $price, 'name, rating, categories, price');
        self::assertStringNotContainsString('shop-grid__meta', $html);
        self::assertStringNotContainsString('shop-grid__rating--none', $html);
    }

    public function testTheButtonStyleAddsToCartUnderThePrice(): void
    {
        $this->product('p');
        $html = $this->renderBlock(['add_to_cart_style' => 'button']);
        self::assertMatchesRegularExpression(
            '~<form class="shop-grid__buy-form" method="post" action="/_shop/cart/add">\s*'
                . '<input type="hidden" name="variant_uuid" value="[^"]+">\s*'
                . '<input type="hidden" name="quantity" value="1">\s*'
                . '<button class="shop-grid__buy" type="submit" aria-label="Add P to cart">Add to cart</button>~',
            $html,
        );
        self::assertLessThan(strpos($html, 'shop-grid__buy-form'), strpos($html, 'shop-grid__price'));
        self::assertStringNotContainsString('shop-grid__action--cart', $html, 'no icon beside the button');
        self::assertStringContainsString('data-shop-wishlist-toggle', $html, 'the heart stays on the picture');
    }

    public function testTheButtonAsksForOptionsOrSaysSoldOut(): void
    {
        $this->product('sizes', ['variants' => 2, 'created_at' => '2026-01-02 00:00:00']);
        $this->product('gone', ['stock' => 0, 'created_at' => '2026-01-01 00:00:00']);
        $html = $this->renderBlock(['add_to_cart_style' => 'button']);
        self::assertMatchesRegularExpression(
            '~<a class="shop-grid__buy shop-grid__buy--options" href="[^"]*/sizes">Choose options</a>~',
            $html,
        );
        self::assertStringContainsString(
            '<button class="shop-grid__buy shop-grid__buy--sold-out" type="button" disabled>Sold out</button>',
            $html,
        );
        self::assertSame(2, substr_count($html, 'class="shop-grid__buy '), 'a link and a sold-out button');
        self::assertStringNotContainsString('shop-grid__buy-form', $html, 'neither adds to the cart');
    }

    public function testTheIconStaysTheDefaultAndTheButtonTakesTheButtonPart(): void
    {
        $this->product('p');
        $html = $this->renderBlock([]);
        self::assertStringContainsString('shop-grid__action--cart', $html);
        self::assertStringNotContainsString('shop-grid__buy', $html);
        $accent = ['type' => 'token', 'value' => 'color.accent'];
        $html = $this->renderBlock(
            ['add_to_cart_style' => 'button'],
            false,
            ['parts' => ['button' => ['colors' => ['surface' => $accent]]]],
        );
        self::assertMatchesRegularExpression('~<button class="shop-grid__buy t-bg-accent"~', $html);
        $this->assertBlockDataValid(['add_to_cart_style' => 'button']);
        $this->assertBlockDataInvalid(['add_to_cart_style' => 'bar']);
    }

    public function testTheWishlistPartStylesTheHeartAlone(): void
    {
        $this->product('p');
        $accent = ['type' => 'token', 'value' => 'color.accent'];
        $html = $this->renderBlock([], false, ['parts' => ['wishlist' => ['colors' => ['surface' => $accent]]]]);
        self::assertMatchesRegularExpression(
            '~<button class="shop-grid__action shop-grid__action--wishlist t-bg-accent" type="button"~',
            $html,
        );
        self::assertDoesNotMatchRegularExpression('~shop-grid__action--cart[^"]*t-bg-accent~', $html);
    }

    public function testTheGapsSpaceTheCardsList(): void
    {
        $this->product('p');
        $html = $this->renderBlock([], false, ['style' => ['layout' => ['gap' => [
            'column' => ['base' => ['type' => 'token', 'value' => 'spacing.lg']],
            'row' => ['base' => ['type' => 'token', 'value' => 'spacing.md']],
        ]]]]);
        self::assertMatchesRegularExpression('~<ul class="thallo-block-product-grid__items[^"]* t-gapx-lg[ "]~', $html);
        self::assertMatchesRegularExpression('~<ul class="thallo-block-product-grid__items[^"]* t-gapy-md[ "]~', $html);
        self::assertDoesNotMatchRegularExpression(
            '~<div class="thallo-block thallo-block-product-grid[^"]*t-gap~',
            $html,
        );
    }

    public function testTheGridHasNoViewAllLink(): void
    {
        $this->product('p');
        $html = $this->renderBlock([]);
        self::assertStringContainsString('class="shop-grid__item', $html);
        self::assertStringNotContainsString('View all products', $html);
        self::assertStringNotContainsString('__view-all', $html);
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
        $html = $this->renderBlock(['categories' => $slugs, 'limit' => 48]);
        self::assertSame(20, substr_count($html, 'class="shop-grid__item'));
        $this->assertBlockDataInvalid(['categories' => [...$slugs, 'cat21']]);
        // Each slug at most the column's 191 characters, categories and tags alike.
        foreach (['categories', 'tags'] as $field) {
            $this->assertBlockDataValid([$field => [str_repeat('s', 191)]]);
            $this->assertBlockDataInvalid([$field => [str_repeat('s', 192)]]);
            $this->assertBlockDataInvalid([$field => ['']]);
        }
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
        $this->product('sale', [
            'price' => 800, 'compare_at' => 1000, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-40 days')),
        ]);
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
        $this->product('inside', ['created_at' => gmdate('Y-m-d H:i:s', strtotime('-6 days -23 hours'))]);
        $this->product('outside', ['created_at' => gmdate('Y-m-d H:i:s', strtotime('-7 days -1 hour'))]);
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
        self::assertStringContainsString(
            '<form class="shop-grid__cart-form" method="post" action="/_shop/cart/add">',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~data-shop-wishlist-toggle[^>]*hidden|hidden[^>]*data-shop-wishlist-toggle~',
            $html,
        );
    }

    /** @return list<string> */
    private function labels(string $html, string $kind): array
    {
        preg_match_all('~class="shop-grid__label shop-grid__label--' . $kind . '">([^<]+)<~', $html, $m);
        return $m[1];
    }
}
