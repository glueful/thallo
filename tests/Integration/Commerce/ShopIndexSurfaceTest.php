<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Layouts\ShopIndexSurface;
use Thallo\Contracts\Layouts\LayoutReader;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\CountingPdoStatement;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The shop home is a layout surface (type layouts plan C2, S2): commerce registers it while its
 * capability is on — one site-wide row on the Layouts page — with the home's first page as its one
 * sample, a placeholder that writes nothing, the page's own variables for the sample, and a starter
 * that is today's page in blocks. Commerce off hides it and keeps its layout (and the categories').
 */
final class ShopIndexSurfaceTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ShopPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The starter carries container layout settings, validated against the block types' style
        // declarations — synced here, as the layout tests do, whatever ran before.
        $this->syncBlockStyleDeclarations();
        $this->seed = new ShopPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        foreach (['shop_index', 'shop_category'] as $surface) {
            $this->container()->get(LayoutResolver::class)->forget($surface, '@site');
        }
        parent::tearDown();
    }

    private function surface(): ShopIndexSurface
    {
        $surface = $this->container()->get(LayoutSurfaceRegistry::class)->get('shop_index');
        self::assertInstanceOf(ShopIndexSurface::class, $surface);
        return $surface;
    }

    /** @return list<array<string,mixed>> */
    private function rows(\Glueful\Bootstrap\ApplicationContext|null $context = null): array
    {
        $container = $context?->getContainer() ?? $this->container();
        $response = $container->get(LayoutAdminController::class)->index(Request::create('/v1/layouts'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data']['layouts'];
    }

    public function testTheSurfaceIsRegisteredWhileCommerceIsOn(): void
    {
        $surface = $this->surface();
        $row = array_values(array_filter($this->rows(), static fn (array $r): bool => $r['surface'] === 'shop_index'));
        self::assertCount(1, $row);
        self::assertSame(
            ['@site', 'Products — shop home', 'Applies to every page of the shop home', 'theme'],
            [$row[0]['target'], $row[0]['label'], $row[0]['reach'], $row[0]['state']],
        );
        self::assertSame([['type' => 'product_loop']], $surface->required('@site'));
        self::assertSame(
            [['type' => 'product_loop', 'card' => 'card', 'items' => [
                'product_tile', 'product_name', 'product_rating', 'product_price',
            ]]],
            $surface->loops('@site'),
        );
        self::assertSame(
            ['product_loop', 'shop_title', 'category_rail', 'pagination', 'product_tile', 'product_name',
                'product_rating', 'product_price'],
            $surface->palette(),
        );
        self::assertSame([], $surface->pageTags('@site'), 'its pages live in the shop cache');
        self::assertSame([], $surface->bindable('@site'));
        self::assertSame('layouts/shop_index.twig', $surface->frame());
    }

    public function testTheOneSampleIsTheFirstPageWhileTheShopListsAProduct(): void
    {
        self::assertSame([], $this->surface()->samples('@site', null), 'no products, no sample');
        self::assertNull($this->surface()->defaultSample('@site'));
        $this->seed->seed();
        self::assertSame([['id' => '1', 'label' => 'Page 1']], $this->surface()->samples('@site', null));
        self::assertSame('1', $this->surface()->defaultSample('@site'));
    }

    /** Whether the shop lists anything is one small question, not a whole page of cards. */
    public function testTheSampleCheckAsksOnlyWhetherTheShopListsAProduct(): void
    {
        $this->seed->seed();
        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        $this->surface()->samples('@site', null); // warm: flags, settings and tenant memos
        foreach (['samples', 'defaultSample'] as $method) {
            $before = CountingPdoStatement::$count;
            $answer = $method === 'samples'
                ? $this->surface()->samples('@site', null)
                : $this->surface()->defaultSample('@site');
            self::assertSame($method === 'samples' ? [['id' => '1', 'label' => 'Page 1']] : '1', $answer);
            // A statement or two, never the page's cards, media, categories and variants (building
            // the page took 18).
            self::assertLessThanOrEqual(2, CountingPdoStatement::$count - $before, $method);
        }
    }

    public function testTheSampleContextIsThePagesOwnVariables(): void
    {
        self::assertNull($this->surface()->sampleContext('@site', '1'), 'no products, no sample');
        $this->seed->seed();
        $vars = $this->surface()->sampleContext('@site', '1');
        self::assertNotNull($vars);
        self::assertSame('/shop', $vars['canonical']);
        $context = $vars['layout_context'];
        self::assertCount(24, $context['products']);
        self::assertSame('Tall mug', $context['products'][0]['name']);
        self::assertSame('Mugs', $context['products'][0]['categoryName']);
        // The card's closed allowlist, key for key.
        self::assertSame(
            ['uuid', 'name', 'url', 'coverUrl', 'rating', 'priceFormatted', 'compareAtFormatted', 'categoryName',
                'cartMode', 'directVariantUuid'],
            array_keys($context['products'][0]),
        );
        self::assertSame('direct', $context['products'][0]['cartMode']);
        self::assertSame(26, $context['total']);
        self::assertSame(
            ['page' => 1, 'total_pages' => 2, 'prev_path' => null, 'next_path' => '/shop?page=2'],
            $context['pagination'],
        );
        self::assertSame(['Mugs', 'Bowls', 'Vases'], array_column($context['categories'], 'name'));
        self::assertSame([false, false, false], array_column($context['categories'], 'active'));
        self::assertNull($context['category']);
        self::assertSame('/shop', $context['shop_index']);
        self::assertArrayNotHasKey('placeholder_item', $context);
    }

    public function testThePlaceholderWritesNothing(): void
    {
        $before = $this->connection()->table('commerce_products')->count();
        $context = $this->surface()->placeholder('@site')['layout_context'];
        self::assertSame([], $context['products']);
        self::assertSame(0, $context['total']);
        self::assertSame(1, $context['pagination']['total_pages']);
        self::assertNull($context['category']);
        self::assertSame('Sample product', $context['placeholder_item']['name']);
        self::assertSame('$49.00', $context['placeholder_item']['priceFormatted']);
        self::assertSame($before, $this->connection()->table('commerce_products')->count());
    }

    /** Today's page in blocks: the title, the chips, the Product list and its card, the navigation. */
    public function testTheStarterIsTodaysPage(): void
    {
        $token = static fn (string $value): array => ['base' => ['type' => 'token', 'value' => $value]];
        $choice = static fn (string $value): array => ['base' => ['type' => 'choice', 'value' => $value]];
        $block = static fn (string $type, array $data = [], array $settings = []): array => [
            'type' => $type, 'data' => $data, 'settings' => $settings,
        ];
        $expected = [
            $block('shop_title', ['level' => 'h1']),
            $block('category_rail'),
            $block('product_loop', ['card' => [
                $block('product_tile'),
                $block('container', ['element' => 'div', 'content' => [
                    $block('product_name', ['level' => 'h2', 'link' => true]),
                    $block('container', ['element' => 'div', 'content' => [
                        $block('product_rating'),
                        $block('product_price'),
                    ]], ['style' => [
                        'layout' => [
                            'display' => $choice('flex'),
                            'direction' => $choice('row'),
                            'align_items' => $choice('center'),
                            'gap' => ['column' => $token('spacing.sm')],
                        ],
                        'alignment' => ['content' => $choice('between')],
                    ]]),
                ]], ['style' => ['layout' => [
                    'display' => $choice('grid'),
                    'gap' => ['row' => $token('spacing.xs')],
                ]]]),
            ]]),
            $block('pagination'),
        ];
        self::assertSame($expected, $this->surface()->starter('@site'));

        $blocks = self::withIds($expected);
        $clean = $this->container()->get(LayoutValidator::class)->validate('shop_index', '@site', $blocks, []);
        self::assertEquals($blocks, $clean['blocks'], 'it passes validation unchanged (key order aside)');
    }

    /**
     * Review Focus 2: commerce off with shop layouts saved — no surfaces, no rows, nothing errors, the
     * block-document walkers still reach both rows — and they serve again once commerce is on.
     */
    public function testCommerceOffHidesTheSurfacesAndKeepsTheLayouts(): void
    {
        $blocks = self::withIds($this->surface()->starter('@site'));
        foreach (['shop_index', 'shop_category'] as $surface) {
            $this->container()->get(LayoutWriteLock::class)->within($surface, '@site', fn (): int => $this->container()
                ->get(LayoutRepository::class)->saveExpected($surface, '@site', $blocks, [], 0, null));
        }

        // A second app boots with the flags it finds, as every other secondary boot in the suite does.
        $this->seed->restoreTenant($this->previousTenant);
        $this->previousTenant = [];
        $off = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
        try {
            $registry = $off->getContainer()->get(LayoutSurfaceRegistry::class);
            self::assertNull($registry->get('shop_index'));
            self::assertNull($registry->get('shop_category'));
            $offRows = array_filter(
                $this->rows($off),
                static fn (array $r): bool => in_array($r['surface'], ['shop_index', 'shop_category'], true),
            );
            self::assertSame([], $offRows);
            $walked = [];
            $off->getContainer()->get(\Thallo\Core\Content\Blocks\Sources\LayoutsSource::class)
                ->each(static function ($ref) use (&$walked): void {
                    $walked[] = $ref->sourceId;
                });
            self::assertContains('shop_index:@site', $walked, 'the walkers still reach it');
            self::assertContains('shop_category:@site', $walked);
        } finally {
            self::resetSharedRepositoryConnection();
        }

        $rows = array_column(array_filter(
            $this->rows(),
            static fn (array $r): bool => in_array($r['surface'], ['shop_index', 'shop_category'], true),
        ), 'state', 'surface');
        self::assertSame(['shop_index' => 'custom', 'shop_category' => 'custom'], $rows);
        foreach (['shop_index', 'shop_category'] as $surface) {
            $this->container()->get(LayoutResolver::class)->forget($surface, '@site');
            // Stored as jsonb, which orders an object's keys its own way: the same tree, not the same bytes.
            self::assertEquals($blocks, $this->container()->get(LayoutReader::class)->for($surface, '@site')['blocks']);
        }
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 's'): array
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
