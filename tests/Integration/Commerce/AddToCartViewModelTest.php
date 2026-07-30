<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use PHPUnit\Framework\TestCase;
use Thallo\Commerce\Shop\ShopAssetMap;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\AddToCartViewModel;

/**
 * Storefront-v1 Task 6: the buy-area price projection. The product page's stepper/price-in-button
 * JS needs exact minor units plus the currency exponent — so each select-mode option gains
 * `price_minor` (int), and the view model exposes top-level `currency` (resolved via the existing
 * default-currency rule), `currencyExponent` (?int, ONLY ever from commerce `Money::exponentFor()`)
 * and `directPriceMinor` (?int — the single variant's minor price in `direct` mode). Pinned in
 * select AND direct modes; `link`/`unavailable` decisions carry no price projection at all.
 */
final class AddToCartViewModelTest extends TestCase
{
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
            'uuid' => 'buyproduuid1',
            'slug' => 'buy-prod',
            'name' => 'Buy prod',
        ];
    }

    /** @return array<string,mixed> */
    private function variant(string $uuid, int $price, ?string $currency = 'USD'): array
    {
        $row = [
            'uuid' => $uuid,
            'status' => 'active',
            'sku' => 'sku-' . $uuid,
            'price' => $price,
        ];
        if ($currency !== null) {
            $row['currency'] = $currency;
        }

        return $row;
    }

    public function testDirectModeExposesCurrencyExponentAndDirectPriceMinor(): void
    {
        $vm = AddToCartViewModel::build(
            $this->productRow(),
            [$this->variant('buyvariant01', 8900)],
            false,
            $this->urls(),
            'USD',
        );

        self::assertSame('direct', $vm->mode, 'fixture precondition: one active variant, no required add-on');
        self::assertSame('USD', $vm->currency);
        self::assertSame(2, $vm->currencyExponent);
        self::assertSame(8900, $vm->directPriceMinor);
        self::assertSame([], $vm->variants);

        $array = $vm->toArray();
        self::assertSame('USD', $array['currency']);
        self::assertSame(2, $array['currency_exponent']);
        self::assertSame(8900, $array['direct_price_minor']);
    }

    public function testSelectModeCarriesPriceMinorPerOptionAndANullDirectPrice(): void
    {
        $vm = AddToCartViewModel::build(
            $this->productRow(),
            [$this->variant('buyvariant01', 1999), $this->variant('buyvariant02', 2500)],
            false,
            $this->urls(),
            'USD',
        );

        self::assertSame('select', $vm->mode, 'fixture precondition: two active variants');
        self::assertSame('USD', $vm->currency);
        self::assertSame(2, $vm->currencyExponent);
        self::assertNull($vm->directPriceMinor);
        self::assertSame([1999, 2500], array_column($vm->variants, 'price_minor'));
        self::assertSame(
            ['uuid', 'label', 'price_formatted', 'price_minor'],
            array_keys($vm->variants[0]),
            'the option projection stays closed: exactly these keys, price_minor added',
        );

        $array = $vm->toArray();
        self::assertNull($array['direct_price_minor']);
        self::assertSame([1999, 2500], array_column($array['variants'], 'price_minor'));
    }

    public function testVariantWithoutCurrencyFallsBackToTheDefaultCurrencyRule(): void
    {
        $vm = AddToCartViewModel::build(
            $this->productRow(),
            [$this->variant('buyvariant01', 500, null)],
            false,
            $this->urls(),
            'JPY',
        );

        self::assertSame('JPY', $vm->currency);
        // JPY's REAL ISO exponent is 0 — falsy, so downstream emitters must test null,
        // never truthiness, or a zero-decimal store loses its exponent attribute.
        self::assertSame(0, $vm->currencyExponent);
        self::assertSame(500, $vm->directPriceMinor);
    }

    public function testUnknownCurrencyYieldsANullExponentInDirectMode(): void
    {
        $vm = AddToCartViewModel::build(
            $this->productRow(),
            [$this->variant('buyvariant01', 1000, 'XYZ')],
            false,
            $this->urls(),
            'USD',
        );

        // Money::exponentFor() is the ONLY exponent source — an unknown code answers null,
        // the template omits the attribute, and the JS leaves the label alone.
        self::assertSame('XYZ', $vm->currency);
        self::assertNull($vm->currencyExponent);
        self::assertSame(1000, $vm->directPriceMinor);
    }

    public function testLinkAndUnavailableModesCarryNoPriceProjection(): void
    {
        $link = AddToCartViewModel::build(
            $this->productRow(),
            [$this->variant('buyvariant01', 1000)],
            true, // a required add-on forces the link decision
            $this->urls(),
            'USD',
        );
        self::assertSame('link', $link->mode, 'fixture precondition: required add-on forces link');
        self::assertNull($link->currency);
        self::assertNull($link->currencyExponent);
        self::assertNull($link->directPriceMinor);

        $unavailable = AddToCartViewModel::unavailable();
        self::assertNull($unavailable->currency);
        self::assertNull($unavailable->currencyExponent);
        self::assertNull($unavailable->directPriceMinor);
    }
}
