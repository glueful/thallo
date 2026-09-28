<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Layouts\ShopCategorySurface;
use Thallo\Commerce\Layouts\ShopIndexSurface;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The shop's category pages are one layout surface (type layouts plan C2, S2): one site-wide row for
 * every category, the categories that list a product as its samples, a category's page as its
 * sample's variables, and a placeholder category that writes nothing.
 */
final class ShopCategorySurfaceTest extends AppTestCase
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
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        parent::tearDown();
    }

    private function surface(): ShopCategorySurface
    {
        $surface = $this->container()->get(LayoutSurfaceRegistry::class)->get('shop_category');
        self::assertInstanceOf(ShopCategorySurface::class, $surface);
        return $surface;
    }

    public function testOneRowForEveryCategory(): void
    {
        $surface = $this->surface();
        self::assertSame(
            [['target' => '@site', 'label' => 'Products — shop categories', 'enabled' => true, 'reason' => null,
                'link' => null]],
            $surface->targets(),
        );
        self::assertSame('Applies to every shop category', $surface->reach('@site'));
        self::assertSame('layouts/shop_category.twig', $surface->frame());
        self::assertSame([['type' => 'product_loop']], $surface->required('@site'));
        $index = $this->container()->get(LayoutSurfaceRegistry::class)->get('shop_index');
        self::assertInstanceOf(ShopIndexSurface::class, $index);
        self::assertSame($index->palette(), $surface->palette());
        self::assertSame($index->loops('@site'), $surface->loops('@site'));
        self::assertSame($index->starter('@site'), $surface->starter('@site'), 'the same page in blocks');
    }

    public function testTheSamplesAreTheCategoriesThatListAProduct(): void
    {
        $seeded = $this->seed->seed();
        $samples = $this->surface()->samples('@site', null);
        self::assertSame(
            [['id' => $seeded['categories']['mugs'], 'label' => 'Mugs'],
                ['id' => $seeded['categories']['bowls'], 'label' => 'Bowls']],
            $samples,
            'Vases lists nothing; in the rail\'s order',
        );
        self::assertSame($seeded['categories']['mugs'], $this->surface()->defaultSample('@site'));
        self::assertSame(['Bowls'], array_column($this->surface()->samples('@site', 'BOW'), 'label'));
        self::assertSame([], $this->surface()->samples('@site', '100%'), 'a wildcard in the query is literal');
    }

    public function testTheSampleContextIsTheCategorysPage(): void
    {
        $seeded = $this->seed->seed();
        $vars = $this->surface()->sampleContext('@site', $seeded['categories']['mugs']);
        self::assertNotNull($vars);
        self::assertSame('/shop/categories/mugs', $vars['canonical']);
        $context = $vars['layout_context'];
        self::assertSame(['name' => 'Mugs', 'slug' => 'mugs', 'url' => '/shop/categories/mugs'], $context['category']);
        self::assertSame(['Tall mug', 'Mug pair'], array_column($context['products'], 'name'));
        self::assertSame(2, $context['total']);
        self::assertSame(1, $context['pagination']['total_pages']);
        self::assertSame([true, false, false], array_column($context['categories'], 'active'));

        self::assertNull($this->surface()->sampleContext('@site', $seeded['categories']['vases']), 'lists nothing');
        self::assertNull($this->surface()->sampleContext('@site', 'nosuchcat001'), 'unknown');
        $this->connection()->table('commerce_categories')->where('uuid', '=', $seeded['categories']['bowls'])->delete();
        self::assertNull($this->surface()->sampleContext('@site', $seeded['categories']['bowls']), 'deleted');
    }

    public function testThePlaceholderIsASampleCategoryAndWritesNothing(): void
    {
        $before = $this->connection()->table('commerce_categories')->count();
        $context = $this->surface()->placeholder('@site')['layout_context'];
        self::assertSame(
            ['name' => 'Sample category', 'slug' => 'sample-category', 'url' => '/shop/categories/sample-category'],
            $context['category'],
        );
        self::assertSame([], $context['products']);
        self::assertSame('Sample product', $context['placeholder_item']['name']);
        self::assertSame($before, $this->connection()->table('commerce_categories')->count());
    }

    public function testTheStarterValidatesOnThisSurface(): void
    {
        $blocks = self::withIds($this->surface()->starter('@site'));
        $clean = $this->container()->get(LayoutValidator::class)->validate('shop_category', '@site', $blocks, []);
        self::assertEquals($blocks, $clean['blocks']);
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 'c'): array
    {
        foreach ($tree as $i => $block) {
            $tree[$i]['id'] = str_pad($prefix . $i, 12, '0');
            foreach ($block['data'] as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $tree[$i]['data'][$key] = self::withIds($value, $prefix . $i . 'n');
                }
            }
        }
        return $tree;
    }
}
