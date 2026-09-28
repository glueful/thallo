<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Thallo\Commerce\Http\Shop\ShopProductCardAssembler;
use Thallo\Commerce\Shop\ViewModels\ProductCardViewModel;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * The Product list and its card (type layouts plan C2, S1): every product on the page, each shown as
 * the card the layout designs once — the Product tile, the name linked to the product, the rating and
 * the price, as today's grid card shows them. On the stage the first card is the card's editable
 * slot and every other card a copy: each block id appears once. With no products the site shows the
 * page's empty text, and the stage one placeholder card. The tile's quick add stays honest: a real
 * no-JS form only where one variant can be added straight away, the options link otherwise, and no
 * token anywhere (the page is cached).
 */
final class ProductLoopRenderTest extends AppTestCase
{
    private ShopPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    /** @var array{categories: array<string,string>, products: list<string>, variants: list<list<string>>} */
    private array $seeded;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed = new ShopPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
        $this->seeded = $this->seed->seed();
    }

    protected function tearDown(): void
    {
        $this->extension()->setAnnotationScope('none');
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        parent::tearDown();
    }

    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    /**
     * The shop's first page as card items, as the page builder hands them to the frame.
     *
     * @return list<array<string,mixed>>
     */
    private function products(): array
    {
        $rows = $this->container()->get(ProductRepository::class)
            ->listActive($this->appContext(), ShopPageSeed::TENANT, 1, 24, null)['items'];
        return array_map(
            static fn (ProductCardViewModel $card): array => $card->toCardItem(),
            $this->container()->get(ShopProductCardAssembler::class)->cards(ShopPageSeed::TENANT, $rows),
        );
    }

    private static function block(string $id, string $type, array $data = []): array
    {
        return ['id' => str_pad($id, 12, '0'), 'type' => $type, 'data' => $data, 'settings' => []];
    }

    private static function box(string $id, array $content): array
    {
        return self::block($id, 'container', ['element' => 'div', 'content' => $content]);
    }

    /** The starter's card: the tile, then the name above a row of the rating and the price. */
    private static function card(): array
    {
        return [
            self::block('tile', 'product_tile'),
            self::box('body', [
                self::block('name', 'product_name', ['level' => 'h2', 'link' => true]),
                self::box('meta', [self::block('rating', 'product_rating'), self::block('price', 'product_price')]),
            ]),
        ];
    }

    /** @param array<string,mixed> $context */
    private function render(string $scope, array $loop, array $context): string
    {
        $this->extension()->resetPerRenderState();
        $this->extension()->setAnnotationScope($scope);
        return $this->container()->get(TwigFactory::class)->environment()
            ->createTemplate('{{ layout_blocks(layout.blocks) }}')
            ->render([
                'layout' => ['blocks' => [$loop], 'surface' => 'shop_index', 'target' => '@site'],
                'layout_context' => $context + [
                    'total' => 26, 'categories' => [], 'shop_index' => '/shop', 'category' => null,
                    'pagination' => [
                        'page' => 1, 'total_pages' => 2, 'prev_path' => null, 'next_path' => '/shop?page=2',
                    ],
                ],
            ]);
    }

    /** @return list<\DOMElement> */
    private static function cards(string $html): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        $xpath = new \DOMXPath($doc);
        return iterator_to_array($xpath->query(
            '//ul[contains(concat(" ", normalize-space(@class), " "), " shop-grid ")]/li',
        ) ?: []);
    }

    public function testEveryProductIsACardOnThePage(): void
    {
        $loop = self::block('loop', 'product_loop', ['card' => self::card()]);
        $html = $this->render('none', $loop, ['products' => $this->products()]);

        self::assertStringContainsString('<ul class="thallo-block-product_loop__cards shop-grid', $html);
        $cards = self::cards($html);
        self::assertCount(24, $cards);
        foreach ($cards as $li) {
            self::assertSame('thallo-loop-card shop-grid__item', $li->getAttribute('class'));
        }
        self::assertStringNotContainsString('data-thallo-block', $html);
        self::assertStringNotContainsString('data-thallo-slot', $html);
        self::assertStringNotContainsString('data-thallo-card-copy', $html);

        $first = $cards[0]->ownerDocument->saveHTML($cards[0]);
        // The tile: the cover, the category chip, the quick add; the name, linked, in a heading.
        self::assertStringContainsString('class="shop-grid__tile', $first);
        self::assertStringContainsString('class="shop-grid__image"', $first);
        self::assertStringContainsString('<span class="shop-grid__tag">Mugs</span>', $first);
        self::assertMatchesRegularExpression(
            '~<h2 class="thallo-block thallo-block-product_name shop-grid__name[^"]*">'
                . '<a href="/shop/products/tall-mug">Tall mug</a></h2>~',
            $first,
        );
        // Two reviews, 4.5 on average; the price.
        self::assertStringContainsString('shop-grid__rating', $first);
        self::assertStringContainsString('4.5', $first);
        self::assertStringContainsString('(2)', $first);
        self::assertStringContainsString('<span class="shop-grid__price-current">$24.00</span>', $first);

        // The second card: the struck "was" price; no cover, the empty panel.
        $second = $cards[1]->ownerDocument->saveHTML($cards[1]);
        self::assertStringContainsString('<s>$56.00</s>', $second);
        self::assertStringContainsString('shop-grid__image--empty', $second);
        // No reviews yet: zeros, dimmed, as today.
        self::assertStringContainsString('shop-grid__rating--none', $second);

        // In listing order.
        $names = array_map(
            static fn (\DOMElement $li): string
                => trim((string) $li->getElementsByTagName('h2')->item(0)?->textContent),
            $cards,
        );
        self::assertSame(
            ['Tall mug', 'Serving bowl', 'Mug pair', 'Gift box', 'Plain cup', 'Item 06'],
            array_slice($names, 0, 6),
        );
    }

    /** Review Focus 1: the quick add is honest, and nothing in a card is a token. */
    public function testTheTilesQuickAddIsHonestAndCacheSafe(): void
    {
        $loop = self::block('loop', 'product_loop', ['card' => self::card()]);
        $cards = self::cards($this->render('none', $loop, ['products' => $this->products()]));
        $html = static fn (int $i): string => $cards[$i]->ownerDocument->saveHTML($cards[$i]);

        // Tall mug: one variant, no add-on — a real form, one of that variant.
        self::assertStringContainsString(
            '<form class="shop-grid__cart-form" method="post" action="/_shop/cart/add">',
            $html(0),
        );
        self::assertStringContainsString(
            '<input type="hidden" name="variant_uuid" value="' . $this->seeded['variants'][0][0] . '">',
            $html(0),
        );
        self::assertStringContainsString('<input type="hidden" name="quantity" value="1">', $html(0));
        // Mug pair (two variants) and Gift box (a required add-on): the options link, no form.
        foreach ([2 => 'mug-pair', 3 => 'gift-box'] as $i => $slug) {
            self::assertStringNotContainsString('<form', $html($i), $slug);
            self::assertStringContainsString(
                'class="shop-grid__action shop-grid__action--options" href="/shop/products/' . $slug . '"',
                $html($i),
                $slug,
            );
        }
        foreach ($cards as $i => $_) {
            self::assertDoesNotMatchRegularExpression('~csrf|_token|cart_token~i', $html($i));
        }
    }

    public function testTheTilesOptionsTurnOff(): void
    {
        $card = self::card();
        $card[0]['data'] = ['hide_tag' => true, 'hide_actions' => true];
        $cards = self::cards($this->render('none', self::block('loop', 'product_loop', ['card' => $card]), [
            'products' => $this->products(),
        ]));
        $first = $cards[0]->ownerDocument->saveHTML($cards[0]);
        self::assertStringContainsString('shop-grid__image', $first);
        self::assertStringNotContainsString('shop-grid__tag', $first);
        self::assertStringNotContainsString('shop-grid__actions', $first);
        self::assertStringNotContainsString('data-shop-wishlist-toggle', $first);
    }

    public function testOnTheStageTheFirstCardIsTheSlotAndTheRestAreCopies(): void
    {
        $loop = self::block('loop', 'product_loop', ['card' => self::card()]);
        $html = $this->render('layout', $loop, ['products' => $this->products()]);
        $cards = self::cards($html);
        self::assertCount(24, $cards);
        self::assertSame('card', $cards[0]->getAttribute('data-thallo-slot'));
        self::assertFalse($cards[0]->hasAttribute('data-thallo-card-copy'));
        foreach (array_slice($cards, 1) as $copy) {
            self::assertTrue($copy->hasAttribute('data-thallo-card-copy'));
            self::assertFalse($copy->hasAttribute('data-thallo-slot'));
        }
        // Every authored block, containers included, is annotated once.
        foreach (['tile', 'body', 'name', 'meta', 'rating', 'price'] as $id) {
            self::assertSame(
                1,
                substr_count($html, 'data-thallo-block="' . str_pad($id, 12, '0') . '"'),
                "{$id} is annotated exactly once",
            );
        }
        self::assertSame(1, substr_count($html, 'data-thallo-block="' . str_pad('loop', 12, '0') . '"'));
    }

    public function testWithNoProductsTheSiteShowsTheEmptyTextAndTheStageOnePlaceholderCard(): void
    {
        $loop = self::block('loop', 'product_loop', ['card' => self::card()]);
        $home = $this->render('none', $loop, ['products' => []]);
        self::assertMatchesRegularExpression(
            '~<ul class="thallo-block-product_loop__cards shop-grid[^"]*"[^>]*>'
                . '\s*<li class="empty">No products yet\.</li>\s*</ul>~',
            $home,
        );
        $category = $this->render('none', $loop, [
            'products' => [], 'category' => ['name' => 'Vases', 'slug' => 'vases', 'url' => '/shop/categories/vases'],
        ]);
        self::assertStringContainsString('<li class="empty">No products in this category yet.</li>', $category);
        $own = $loop;
        $own['data']['empty_text'] = 'Back soon.';
        $backSoon = $this->render('none', $own, ['products' => []]);
        self::assertStringContainsString('<li class="empty">Back soon.</li>', $backSoon);

        $placeholder = $this->products()[0];
        $placeholder['name'] = 'Sample product';
        $stage = $this->render('layout', $loop, ['products' => [], 'placeholder_item' => $placeholder]);
        $cards = self::cards($stage);
        self::assertCount(1, $cards);
        self::assertSame('card', $cards[0]->getAttribute('data-thallo-slot'));
        self::assertStringContainsString('Sample product', $stage);
    }

    /** A block two containers deep in the card reads the card's product. */
    public function testTheCardsProductReachesANestedBlock(): void
    {
        $loop = self::block('loop', 'product_loop', ['card' => [
            self::box('outer', [self::box('inner', [self::block('name', 'product_name', ['level' => 'h3'])])]),
        ]]);
        $cards = self::cards($this->render('none', $loop, ['products' => array_slice($this->products(), 0, 3)]));
        self::assertCount(3, $cards);
        foreach (['Tall mug', 'Serving bowl', 'Mug pair'] as $i => $name) {
            $h3 = $cards[$i]->getElementsByTagName('h3')->item(0);
            self::assertNotNull($h3, $name);
            self::assertSame($name, trim($h3->textContent));
            self::assertStringContainsString('shop-grid__name', (string) $h3->getAttribute('class'));
        }
    }

    /** Long names: the full name in the link and every quick button's label, never truncated here. */
    public function testALongNameIsWholeInTheMarkup(): void
    {
        $this->seed->clear();
        $long = $this->seed->longNames();
        $cards = self::cards($this->render('none', self::block('loop', 'product_loop', ['card' => self::card()]), [
            'products' => $this->products(),
        ]));
        $html = implode('', array_map(
            static fn (\DOMElement $li): string => (string) $li->ownerDocument->saveHTML($li),
            $cards,
        ));
        $name = ShopPageSeed::LONG_NAME;
        self::assertStringContainsString('<a href="/shop/products/' . $long['slug'] . '">' . $name . '</a>', $html);
        self::assertStringContainsString('aria-label="Add ' . $name . ' to cart"', $html);
        self::assertStringContainsString('aria-label="Save ' . $name . ' to wishlist"', $html);
        $tag = '<span class="shop-grid__tag">' . ShopPageSeed::LONG_CATEGORY . '</span>';
        self::assertStringContainsString($tag, $html);
    }
}
