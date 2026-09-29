<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Preview\LayoutPreviewStore;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * The shop layouts on their stage (type layouts plan C2, S4): the session's working copy in the shop
 * home's or a category's frame around the sample page — the Product list's first card selectable,
 * the other cards copies, no id twice — or around the placeholder page while the shop lists nothing,
 * or once the sample category is emptied or deleted. The frame's presentation is the live page's.
 */
final class ShopLayoutStageTest extends AppTestCase
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
        foreach (['shop_index', 'shop_category'] as $surface) {
            $this->container()->get(LayoutResolver::class)->forget($surface, '@site');
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function session(string $surface, ?string $sample = null): array
    {
        $response = $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => $surface, 'target' => '@site'] + ($sample === null ? [] : ['sample' => $sample]),
            ),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /**
     * The starter with ids, a heading added, as the working copy.
     *
     * @param array<string,mixed> $settings
     * @return list<array<string,mixed>>
     */
    private function applyWorking(string $surface, string $token, array $settings = []): array
    {
        $blocks = self::withIds($this->container()->get(LayoutSurfaceRegistry::class)->get($surface)->starter('@site'));
        $blocks[] = [
            'id' => 'stagemarker1', 'type' => 'heading', 'data' => ['text' => 'WORKING-MARKER'], 'settings' => [],
        ];
        $response = $this->container()->get(LayoutPreviewController::class)->apply(
            (new RequestDataHydrator())->hydrate(ApplyLayoutData::class, [
                'token' => $token, 'layout' => ['blocks' => $blocks, 'settings' => $settings],
            ]),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return $blocks;
    }

    private function stage(string $token): string
    {
        $response = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}?canvas=1", 'GET'),
            $token,
        );
        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 400));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        return (string) $response->getContent();
    }

    /** @return list<string> every block id in a tree */
    private static function ids(array $tree): array
    {
        $ids = [];
        foreach ($tree as $block) {
            $ids[] = $block['id'];
            foreach ($block['data'] as $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $ids = [...$ids, ...self::ids($value)];
                }
            }
        }
        return $ids;
    }

    public function testTheHomeStageRendersTheWorkingCopyAroundTheFirstPage(): void
    {
        $this->seed->seed();
        $session = $this->session('shop_index');
        self::assertFalse($session['placeholder']);
        self::assertSame('Page 1', $session['sample']['label']);
        $blocks = $this->applyWorking('shop_index', $session['token']);

        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-canvas="layout"', $html);
        self::assertStringContainsString('shop-index--layout', $html);
        self::assertStringContainsString('WORKING-MARKER', $html);
        self::assertSame(24, substr_count($html, 'thallo-loop-card shop-grid__item'), 'the page\'s 24 cards');
        self::assertSame(1, substr_count($html, 'data-thallo-slot="card"'), 'one editable card');
        self::assertSame(23, substr_count($html, 'data-thallo-card-copy'), 'the rest are copies');
        foreach (self::ids($blocks) as $id) {
            self::assertSame(1, substr_count($html, 'data-thallo-block="' . $id . '"'), "{$id} is selectable, once");
        }
        preg_match_all('~data-thallo-block="([^"]+)"~', $html, $m);
        self::assertSame(count($m[1]), count(array_unique($m[1])), 'no id appears twice');
        self::assertStringNotContainsString('<link rel="stylesheet" href="/_thallo/shop/shop.css">', $html);
        self::assertStringNotContainsString('data-thallo-placeholder', $html);
    }

    public function testTheCategoryStageRendersTheChosenCategory(): void
    {
        $seeded = $this->seed->seed();
        $session = $this->session('shop_category', $seeded['categories']['bowls']);
        self::assertSame('Bowls', $session['sample']['label']);
        $this->applyWorking('shop_category', $session['token']);
        $html = $this->stage($session['token']);
        self::assertStringContainsString('shop-category--layout', $html);
        self::assertMatchesRegularExpression('~<h1 class="shop-titlerow__heading[^"]*">Bowls</h1>~', $html);
        self::assertSame(1, substr_count($html, 'thallo-loop-card shop-grid__item'), 'Bowls lists one product');
    }

    public function testNoProductsOpensOnThePlaceholder(): void
    {
        $before = $this->connection()->table('commerce_products')->count();
        foreach (['shop_index', 'shop_category'] as $surface) {
            $session = $this->session($surface);
            self::assertTrue($session['placeholder'], $surface);
            self::assertNull($session['sample'], $surface);
            $html = $this->stage($session['token']);
            self::assertStringContainsString('No published products yet — showing a placeholder', $html, $surface);
            self::assertSame(1, substr_count($html, 'thallo-loop-card shop-grid__item'), "{$surface}: one card");
            self::assertSame(1, substr_count($html, 'data-thallo-slot="card"'), "{$surface}: it is editable");
            self::assertStringContainsString('Sample product', $html, $surface);
        }
        self::assertSame($before, $this->connection()->table('commerce_products')->count(), 'nothing written');
    }

    /** Review Focus 5: the sample category emptied, then deleted, mid-session. */
    public function testAVanishedSampleCategoryFallsBackToThePlaceholder(): void
    {
        $seeded = $this->seed->seed();
        $bowls = $seeded['categories']['bowls'];
        $session = $this->session('shop_category', $bowls);
        $this->applyWorking('shop_category', $session['token']);

        $this->connection()->table('commerce_product_categories')->where('category_uuid', '=', $bowls)->delete();
        $emptied = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-placeholder', $emptied, 'emptied: the placeholder');
        self::assertStringContainsString('WORKING-MARKER', $emptied, 'the working copy is intact');

        $this->connection()->table('commerce_categories')->where('uuid', '=', $bowls)->delete();
        $deleted = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-placeholder', $deleted, 'deleted: the placeholder');
        self::assertStringContainsString('WORKING-MARKER', $deleted);
        self::assertSame(
            404,
            $this->handle(Request::create('/shop/categories/bowls?stage=1', 'GET'))->getStatusCode(),
            'the deleted category has no page',
        );
    }

    public function testTheStageUsesTheFramesPresentation(): void
    {
        $this->seed->seed();
        $session = $this->session('shop_index');
        $this->applyWorking('shop_index', $session['token'], ['width' => 'full']);
        self::assertStringContainsString('class="layout--full"', $this->stage($session['token']));
    }

    public function testARetiredSessionRendersTheRemovalPage(): void
    {
        $this->seed->seed();
        $session = $this->session('shop_index');
        $parts = explode('.', (string) $session['token'], 2);
        $id = (string) json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true)['s'];
        $this->container()->get(LayoutPreviewStore::class)->retire($id, time() + 600);
        self::assertStringContainsString('data-thallo-session-retired', $this->stage($session['token']));
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
