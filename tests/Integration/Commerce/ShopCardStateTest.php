<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Shop\ShopCatalogPage;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SeedsShopCatalog;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The shop pages' cards carry what a card's Add to cart button and badges read: whether the product
 * can be bought now, whether it is on sale, and when it was created — the Product grid's rules —
 * and every category and tag, which a card's Product tags block shows.
 */
final class ShopCardStateTest extends AppTestCase
{
    use SeedsShopCatalog;

    private const TENANT = 'cardstatetna';

    protected function setUp(): void
    {
        parent::setUp();
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

    public function testAShopPagesCardsSayStockSaleAndAge(): void
    {
        $this->product('plain', ['created_at' => '2026-01-03 10:00:00']);
        $this->product('sale', ['price' => 800, 'compare_at' => 1000, 'created_at' => '2026-01-02 10:00:00']);
        $this->product('gone', ['stock' => 0, 'created_at' => '2026-01-01 10:00:00']);
        $products = $this->container()->get(ShopCatalogPage::class)
            ->forIndex(self::TENANT, 1)['layout_context']['products'];
        $state = [];
        foreach ($products as $card) {
            $state[$card['name']] = [$card['inStock'], $card['onSale'], $card['createdAt']];
        }
        self::assertSame([
            'Plain' => [true, false, '2026-01-03 10:00:00'],
            'Sale' => [true, true, '2026-01-02 10:00:00'],
            'Gone' => [false, false, '2026-01-01 10:00:00'],
        ], $state);
    }

    public function testAShopPagesCardsCarryEveryCategoryAndTag(): void
    {
        $oud = $this->product('oud', ['created_at' => '2026-01-02 10:00:00']);
        $this->product('bare', ['created_at' => '2026-01-01 10:00:00']);
        $this->category('men', $oud);
        $this->tag('swiss-arabian', $oud);
        $this->tag('unisex', $oud);
        $products = $this->container()->get(ShopCatalogPage::class)
            ->forIndex(self::TENANT, 1)['layout_context']['products'];
        $labels = [];
        foreach ($products as $card) {
            $labels[$card['name']] = [
                array_column($card['categories'], 'name'),
                array_column($card['tags'], 'name'),
            ];
        }
        self::assertSame([
            'Oud' => [['Men'], ['Swiss-arabian', 'Unisex']],
            'Bare' => [[], []],
        ], $labels);
    }
}
