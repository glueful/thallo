<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Cache\CacheStore;
use Thallo\Commerce\Shop\CatalogGeneration;
use Thallo\Contracts\Delivery\StorefrontProductGrid;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\CountingPdoStatement;
use Thallo\Core\Tests\Support\SeedsShopCatalog;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Product grid spec §2, §6: the grid's products — the source narrowed by categories and tags,
 * ordered and cut — and what its cards carry.
 */
final class ProductGridTest extends AppTestCase
{
    use SeedsShopCatalog;

    private const TENANT = 'gridtesttena';

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

    /** @return list<string> product names in grid order */
    private function names(array $data): array
    {
        $view = $this->container()->get(StorefrontProductGrid::class)->grid($data);
        return array_map(static fn (array $c): string => (string) $c['name'], $view->cards);
    }

    public function testAllProductsNewestFirstCutToTheCount(): void
    {
        $this->product('a', ['created_at' => '2026-01-01 00:00:00']);
        $this->product('b', ['created_at' => '2026-01-03 00:00:00']);
        $this->product('c', ['created_at' => '2026-01-02 00:00:00']);
        self::assertSame(['B', 'C'], $this->names(['limit' => 2]));
    }

    public function testCategoriesAnyOfDeduplicatedAndAndedWithTags(): void
    {
        $men = $this->product('m');
        $women = $this->product('w');
        $both = $this->product('x');
        $this->product('none');
        $this->category('men', $men, $both);
        $this->category('women', $women, $both);
        $this->tag('summer', $both);
        self::assertEqualsCanonicalizing(['M', 'W', 'X'], $this->names(['categories' => ['men', 'women']]));
        self::assertSame(['X'], $this->names(['categories' => ['men', 'women'], 'tags' => ['summer']]));
    }

    public function testChosenCategoriesAndTagsCostTheSameQueriesWhateverTheirNumber(): void
    {
        $product = $this->product('p');
        $categories = $tags = [];
        foreach (range(1, 5) as $i) {
            $this->category('cat-' . $i, $product);
            $this->tag('tag-' . $i, $product);
            $categories[] = 'cat-' . $i;
            $tags[] = 'tag-' . $i;
        }
        $grid = $this->container()->get(StorefrontProductGrid::class);
        $pdo = $this->connection()->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        try {
            $grid->grid(['categories' => ['cat-1'], 'tags' => ['tag-1']]); // warm-up
            $before = CountingPdoStatement::$count;
            $grid->grid(['categories' => ['cat-1'], 'tags' => ['tag-1']]);
            $one = CountingPdoStatement::$count - $before;
            $before = CountingPdoStatement::$count;
            $view = $grid->grid(['categories' => $categories, 'tags' => $tags]);
            $five = CountingPdoStatement::$count - $before;
        } finally {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        self::assertCount(1, $view?->cards ?? []);
        self::assertSame($one, $five, 'one lookup for the categories and one for the tags, not one per slug');
    }

    public function testDeletedCategoriesShowNothingNeverEverything(): void
    {
        $this->product('anything');
        self::assertSame([], $this->names(['categories' => ['gone', 'also-gone']]));
        $this->category('real', $this->product('kept'));
        self::assertSame(
            ['Kept'],
            $this->names(['categories' => ['gone', 'real']]),
            'an unknown slug among real ones is ignored',
        );
    }

    public function testOnSaleAndExcludeOutOfStockAndPriceOrder(): void
    {
        $this->product('sale', ['price' => 800, 'compare_at' => 1000, 'stock' => 2]);
        $this->product('soldout', ['price' => 500, 'compare_at' => 1000, 'stock' => 0]);
        $this->product('full', ['price' => 1000]);
        self::assertSame(['Soldout', 'Sale'], $this->names(['source' => 'on_sale', 'order_by' => 'price_asc']));
        self::assertSame(['Sale'], $this->names(['source' => 'on_sale', 'exclude_out_of_stock' => true]));
    }

    public function testManualKeepsTheListedOrderAndHonoursOnlyExcludeOutOfStock(): void
    {
        $this->product('first', ['stock' => 0]);
        $this->product('second');
        $this->product('third');
        $this->category('ignored', $this->product('other'));
        $data = ['source' => 'manual', 'products' => "third\nfirst\nmissing\nsecond", 'categories' => ['ignored'],
            'order_by' => 'name'];
        self::assertSame(['Third', 'First', 'Second'], $this->names($data));
        self::assertSame(['Third', 'Second'], $this->names($data + ['exclude_out_of_stock' => true]));
    }

    public function testCardsCarryEveryCategoryTagsSaleAndNewFlags(): void
    {
        $p = $this->product('p', [
            'price' => 800, 'compare_at' => 1000, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
        ]);
        $old = $this->product('old', ['created_at' => gmdate('Y-m-d H:i:s', strtotime('-30 days'))]);
        $this->category('women', $p);
        $this->category('men', $p);
        $this->tag('summer', $p);
        $cards = $this->container()->get(StorefrontProductGrid::class)->grid(['new_badge_days' => 7])->cards;
        $byName = array_column($cards, null, 'name');
        self::assertSame(['Men', 'Women'], array_column($byName['P']['categories'], 'name'));
        self::assertSame(['Summer'], array_column($byName['P']['tags'], 'name'));
        self::assertTrue($byName['P']['onSale']);
        self::assertTrue($byName['P']['isNew']);
        self::assertFalse($byName['Old']['isNew']);
        self::assertFalse($byName['Old']['onSale']);
    }

    public function testTheViewNamesTheStorageTagAndTheCurrentGeneration(): void
    {
        $view = $this->container()->get(StorefrontProductGrid::class)->grid([]);
        self::assertSame('thallo:shop:catalog:' . self::TENANT, $view->storageTag);
        self::assertSame(CatalogGeneration::key(self::TENANT), $view->guardKey);
        $generation = new CatalogGeneration($this->container()->get(CacheStore::class));
        self::assertSame($generation->read(self::TENANT), $view->guardValue);
    }
}
