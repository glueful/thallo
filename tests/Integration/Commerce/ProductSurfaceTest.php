<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Layouts\ProductSurface;
use Thallo\Contracts\Layouts\LayoutReader;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ProductPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The product page is a layout surface (type layouts plan C1, P3): commerce registers it while its
 * capability is on — one site-wide row on the Layouts page — with the shop's active products as
 * samples, a placeholder that writes nothing, the page's own variables for a sample, and a starter
 * that is today's page in blocks.
 */
final class ProductSurfaceTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ProductPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        // The starter carries container layout settings, validated against the block types' style
        // declarations — synced here, as the layout tests do, whatever ran before.
        $this->syncBlockStyleDeclarations();
        $this->seed = new ProductPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
    }

    protected function tearDown(): void
    {
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        $this->container()->get(LayoutResolver::class)->forget('product', '@site');
        parent::tearDown();
    }

    private function surface(): ProductSurface
    {
        $surface = $this->container()->get(LayoutSurfaceRegistry::class)->get('product');
        self::assertInstanceOf(ProductSurface::class, $surface);
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

    public function testTheProductSurfaceIsRegisteredWhileCommerceIsOn(): void
    {
        $this->surface();
        $row = array_values(array_filter($this->rows(), static fn (array $r): bool => $r['surface'] === 'product'));
        self::assertCount(1, $row);
        self::assertSame('@site', $row[0]['target']);
        self::assertSame('Products — product page', $row[0]['label']);
        self::assertSame('Applies to every product', $row[0]['reach']);
        self::assertSame('theme', $row[0]['state']);
        self::assertSame([['type' => 'product_buy']], $this->surface()->required('@site'));
        self::assertSame([], $this->surface()->pageTags('@site'), 'its pages live in the shop cache');
    }

    /**
     * Commerce off, a saved product layout stays: no surface, no row, nothing errors — the
     * block-document walkers still reach the row — and it serves again once commerce is on.
     */
    public function testCommerceOffHidesTheSurfaceAndKeepsTheLayout(): void
    {
        $blocks = self::withIds($this->surface()->starter('@site'));
        $this->container()->get(LayoutWriteLock::class)->within('product', '@site', fn (): int => $this->container()
            ->get(LayoutRepository::class)->saveExpected('product', '@site', $blocks, [], 0, null));

        // A second app boots with the flags it finds: without the proof tenant's widened schema, as
        // every other secondary boot in the suite does.
        $this->seed->restoreTenant($this->previousTenant);
        $this->previousTenant = [];
        $off = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
        try {
            $registry = $off->getContainer()->get(LayoutSurfaceRegistry::class);
            self::assertNull($registry->get('product'));
            $offRows = array_filter($this->rows($off), static fn (array $r): bool => $r['surface'] === 'product');
            self::assertSame([], $offRows);
            $walked = [];
            $off->getContainer()->get(\Thallo\Core\Content\Blocks\Sources\LayoutsSource::class)
                ->each(static function ($ref) use (&$walked): void {
                    $walked[] = $ref->sourceId;
                });
            self::assertContains('product:@site', $walked, 'the walkers still reach it');
        } finally {
            self::resetSharedRepositoryConnection();
        }

        $row = array_values(array_filter($this->rows(), static fn (array $r): bool => $r['surface'] === 'product'));
        self::assertSame('custom', $row[0]['state']);
        $this->container()->get(LayoutResolver::class)->forget('product', '@site');
        // Stored as jsonb, which orders an object's keys its own way: the same tree, not the same bytes.
        self::assertEquals($blocks, $this->container()->get(LayoutReader::class)->for('product', '@site')['blocks']);
    }

    public function testSamplesAreTheActiveProductsNewestFirst(): void
    {
        $showcase = $this->seed->showcase();
        $mug = $this->seed->multiVariant();
        $gift = $this->seed->withRequiredAddon();
        $this->connection()->table('commerce_products')->where('uuid', '=', $gift['product'])
            ->update(['status' => 'draft']);
        $this->connection()->table('commerce_products')->where('uuid', '=', $mug['product'])
            ->update(['created_at' => '2030-01-01 00:00:00']);

        $samples = $this->surface()->samples('@site', null);
        self::assertSame([$mug['product'], $showcase['product']], array_column($samples, 'id'), 'active, newest first');
        self::assertSame(['Stoneware mug', 'Linen table lamp'], array_column($samples, 'label'));
        self::assertSame($mug['product'], $this->surface()->defaultSample('@site'));
        self::assertSame([$showcase['product']], array_column($this->surface()->samples('@site', 'LINEN'), 'id'));
        self::assertSame([], $this->surface()->samples('@site', '100%'), 'a wildcard in the query is literal');
    }

    public function testTheStarterIsTheProductPageWithTheAcceptedGeometry(): void
    {
        $token = static fn (string $value): array => ['base' => ['type' => 'token', 'value' => $value]];
        $block = static fn (string $type, array $data = []): array => [
            'type' => $type, 'data' => $data, 'settings' => [],
        ];
        $expected = [
            $block('product_breadcrumb'),
            ['type' => 'container', 'data' => ['element' => 'div', 'content' => [
                ['type' => 'container', 'data' => ['element' => 'div', 'content' => [$block('product_gallery')]],
                    'settings' => []],
                ['type' => 'container', 'data' => ['element' => 'div', 'content' => [
                    $block('product_category'),
                    $block('product_name', ['level' => 'h1']),
                    $block('product_rating'),
                    $block('product_price'),
                    $block('product_description'),
                    $block('product_buy'),
                ]], 'settings' => ['style' => ['layout' => [
                    'display' => ['base' => ['type' => 'choice', 'value' => 'grid']],
                    'gap' => ['row' => $token('spacing.sm')],
                ]]]],
            ]], 'settings' => ['style' => ['layout' => [
                'display' => ['base' => ['type' => 'choice', 'value' => 'grid']],
                'columns' => [
                    'base' => ['type' => 'choice', 'value' => '1'],
                    'md' => ['type' => 'choice', 'value' => '2'],
                ],
                'gap' => ['column' => $token('spacing.xl'), 'row' => $token('spacing.xl')],
                'align_items' => ['base' => ['type' => 'choice', 'value' => 'start']],
            ]]]],
            $block('product_story'),
        ];
        self::assertSame($expected, $this->surface()->starter('@site'));

        $blocks = self::withIds($expected);
        $clean = $this->container()->get(LayoutValidator::class)->validate('product', '@site', $blocks, []);
        self::assertEquals($blocks, $clean['blocks'], 'it passes validation unchanged (key order aside)');
    }

    public function testTheSampleContextIsThePagesOwnVariables(): void
    {
        $showcase = $this->seed->showcase();
        $vars = $this->surface()->sampleContext('@site', $showcase['product']);
        self::assertNotNull($vars);
        self::assertSame('Linen table lamp', $vars['product']->name);
        self::assertSame('direct', $vars['product']->addToCart->mode);
        self::assertSame('Lighting', $vars['breadcrumb_category']['name']);
        self::assertStringContainsString('Made by hand', (string) $vars['enrichment_html']);
        self::assertSame('/shop/products/linen-lamp', $vars['canonical']);
        self::assertSame(
            ['product', 'breadcrumb_category', 'enrichment_html', 'shop_index'],
            array_keys($vars['layout_context']),
        );

        $this->connection()->table('commerce_products')->where('uuid', '=', $showcase['product'])
            ->update(['status' => 'archived']);
        self::assertNull($this->surface()->sampleContext('@site', $showcase['product']), 'archived');
        self::assertNull($this->surface()->sampleContext('@site', 'nosuchprod01'), 'unknown');
    }

    public function testThePlaceholderWritesNothing(): void
    {
        $before = $this->connection()->table('commerce_products')->count();
        $vars = $this->surface()->placeholder('@site');
        self::assertSame('Sample product', $vars['product']->name);
        self::assertNotNull($vars['product']->priceFormatted);
        self::assertSame('direct', $vars['product']->addToCart->mode);
        self::assertSame($vars['product'], $vars['layout_context']['product']);
        self::assertSame($before, $this->connection()->table('commerce_products')->count());
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 'p'): array
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
