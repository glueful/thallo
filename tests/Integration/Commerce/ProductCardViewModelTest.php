<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use PHPUnit\Framework\TestCase;
use Thallo\Commerce\Shop\ShopAssetMap;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\AddToCartViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductCardViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductViewModel;

/**
 * Storefront-v1 Task 5: the closed card projection shared by native shop grids, the
 * wishlist resolution endpoint (Task 7), and shop.js's `buildProductCard()` (Task 8).
 *
 * Two contracts are pinned here:
 *
 *  1. `toArray()` emits EXACTLY the Global-Constraints allowlist —
 *     `{uuid, name, url, cover_url, rating, price_formatted, compare_at_formatted,
 *     category_name, cart_mode, direct_variant_uuid}` — asserted via `array_keys`
 *     equality, so any future field added upstream can never leak through this
 *     projection unnoticed (the closed-projection guard).
 *
 *  2. `AddToCartViewModel::build()` stays the ONE cart-mode authority: its `direct`
 *     decision is the only thing that ever yields `cart_mode: 'direct'` (+ the variant
 *     uuid); every other decision (`select`, `link`, `unavailable`) reduces to
 *     `'options'` with a null `direct_variant_uuid` — the card never re-derives
 *     purchasability from variants/add-ons itself.
 */
final class ProductCardViewModelTest extends TestCase
{
    private const ALLOWLIST = [
        'uuid',
        'name',
        'url',
        'cover_url',
        'rating',
        'price_formatted',
        'compare_at_formatted',
        'category_name',
        'cart_mode',
        'direct_variant_uuid',
    ];

    private function urls(): ShopUrlGenerator
    {
        return new ShopUrlGenerator(
            'shop',
            new ShopAssetMap(dirname(__DIR__, 3) . '/packages/thallo-commerce/assets'),
        );
    }

    /** @return array<string,mixed> */
    private function productRow(): array
    {
        return [
            'uuid' => 'cardproduuid',
            'slug' => 'card-prod',
            'name' => 'Card prod',
            'type' => 'physical',
            'rating_sum' => 9,
            'rating_count' => 2,
        ];
    }

    /** @return array<string,mixed> */
    private function activeVariant(string $uuid = 'cardvariant1'): array
    {
        return [
            'uuid' => $uuid,
            'status' => 'active',
            'sku' => 'sku-' . $uuid,
            'price' => 1999,
            'compare_at_price' => 2999,
            'currency' => 'USD',
        ];
    }

    private function card(AddToCartViewModel $addToCart, ?string $categoryName = 'Lamps'): ProductCardViewModel
    {
        $product = ProductViewModel::fromRow(
            $this->productRow(),
            [$this->activeVariant()],
            '/api/v1/blobs/cardblobuuid/card.png',
            $this->urls(),
        );

        return ProductCardViewModel::fromProduct($product, $categoryName, $addToCart);
    }

    public function testToArrayKeysAreExactlyTheClosedCardAllowlist(): void
    {
        $addToCart = AddToCartViewModel::build(
            $this->productRow(),
            [$this->activeVariant()],
            false,
            $this->urls(),
            'USD',
        );

        self::assertSame(self::ALLOWLIST, array_keys($this->card($addToCart)->toArray()));
    }

    public function testDirectDecisionYieldsDirectCartModeWithTheVariantUuid(): void
    {
        $addToCart = AddToCartViewModel::build(
            $this->productRow(),
            [$this->activeVariant()],
            false,
            $this->urls(),
            'USD',
        );
        self::assertSame('direct', $addToCart->mode, 'fixture precondition: one active variant, no required add-on');

        $card = $this->card($addToCart)->toArray();

        self::assertSame('direct', $card['cart_mode']);
        self::assertSame('cardvariant1', $card['direct_variant_uuid']);
        // Reused ProductViewModel derivations — never re-derived by the card.
        self::assertSame('cardproduuid', $card['uuid']);
        self::assertSame('Card prod', $card['name']);
        self::assertSame('/shop/products/card-prod', $card['url']);
        self::assertSame('/api/v1/blobs/cardblobuuid/card.png', $card['cover_url']);
        self::assertSame(['average' => 4.5, 'count' => 2], $card['rating']);
        self::assertSame('$19.99', $card['price_formatted']);
        self::assertSame('$29.99', $card['compare_at_formatted']);
        self::assertSame('Lamps', $card['category_name']);
    }

    public function testSelectDecisionReducesToOptionsWithNullVariantUuid(): void
    {
        $addToCart = AddToCartViewModel::build(
            $this->productRow(),
            [$this->activeVariant('cardvariant1'), $this->activeVariant('cardvariant2')],
            false,
            $this->urls(),
            'USD',
        );
        self::assertSame('select', $addToCart->mode, 'fixture precondition: two active variants');

        $card = $this->card($addToCart)->toArray();

        self::assertSame('options', $card['cart_mode']);
        self::assertNull($card['direct_variant_uuid']);
    }

    public function testLinkDecisionReducesToOptionsWithNullVariantUuid(): void
    {
        $addToCart = AddToCartViewModel::build(
            $this->productRow(),
            [$this->activeVariant()],
            true, // a required add-on: one active variant is still never a direct add
            $this->urls(),
            'USD',
        );
        self::assertSame('link', $addToCart->mode, 'fixture precondition: required add-on forces link');

        $card = $this->card($addToCart)->toArray();

        self::assertSame('options', $card['cart_mode']);
        self::assertNull($card['direct_variant_uuid']);
    }

    public function testUnavailableDecisionReducesToOptionsWithNullVariantUuid(): void
    {
        $card = $this->card(AddToCartViewModel::unavailable())->toArray();

        self::assertSame('options', $card['cart_mode']);
        self::assertNull($card['direct_variant_uuid']);
    }

    public function testCategoryNameIsNullableWithoutDisturbingTheAllowlist(): void
    {
        $addToCart = AddToCartViewModel::build(
            $this->productRow(),
            [$this->activeVariant()],
            false,
            $this->urls(),
            'USD',
        );

        $card = $this->card($addToCart, null)->toArray();

        self::assertNull($card['category_name']);
        self::assertSame(self::ALLOWLIST, array_keys($card));
    }
}
