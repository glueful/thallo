<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Preview\LayoutPreviewStore;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ProductPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * The product layout on its stage (type layouts plan C1, P5): the session's working copy in the
 * product frame around one of the shop's active products — the layout's blocks selectable at any
 * depth, the linked story's blocks never — or around the placeholder product while there is none,
 * or once the sample is archived. The frame's presentation is the live page's.
 */
final class ProductLayoutStageTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ProductPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
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

    /** @return array<string,mixed> */
    private function session(): array
    {
        $response = $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => 'product', 'target' => '@site'],
            ),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /** The starter with ids, a heading added, as the working copy. */
    private function applyWorking(string $token, array $settings = []): array
    {
        $blocks = self::withIds(
            $this->container()->get(LayoutSurfaceRegistry::class)->get('product')->starter('@site'),
        );
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

    public function testTheStageRendersTheWorkingCopyAroundTheSampleProduct(): void
    {
        $this->seed->showcase();
        $session = $this->session();
        self::assertFalse($session['placeholder']);
        self::assertSame('Linen table lamp', $session['sample']['label']);
        $blocks = $this->applyWorking($session['token']);

        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-canvas="layout"', $html);
        self::assertStringContainsString('shop-product--layout', $html);
        self::assertStringContainsString('data-thallo-slot="blocks"', $html);
        self::assertStringContainsString('WORKING-MARKER', $html);
        // The name sits two containers deep in the starter: the sample's value reaches it.
        self::assertMatchesRegularExpression('~shop-product__name[^"]*"[^>]*>Linen table lamp</h1>~', $html);
        foreach (self::ids($blocks) as $id) {
            self::assertSame(1, substr_count($html, 'data-thallo-block="' . $id . '"'), "{$id} is selectable, once");
        }
        self::assertStringNotContainsString('<link rel="stylesheet" href="/_thallo/shop/shop.css">', $html);
        self::assertStringNotContainsString('data-thallo-placeholder', $html);
    }

    /** The story is the sample's content: rendered, never selectable, and no id appears twice. */
    public function testTheStorysBlocksAreNeverSelectable(): void
    {
        $this->seed->showcase();
        $session = $this->session();
        $this->applyWorking($session['token']);
        $html = $this->stage($session['token']);
        self::assertStringContainsString('Made by hand', $html);
        self::assertStringNotContainsString('data-thallo-block="proofstory01"', $html);
        self::assertStringNotContainsString('data-thallo-block="proofstory02"', $html);
        preg_match_all('~data-thallo-block="([^"]+)"~', $html, $m);
        self::assertSame(count($m[1]), count(array_unique($m[1])), 'no id appears twice');
    }

    public function testNoProductsOpensOnThePlaceholder(): void
    {
        $before = $this->connection()->table('commerce_products')->count();
        $session = $this->session();
        self::assertTrue($session['placeholder']);
        self::assertNull($session['sample']);
        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-placeholder', $html);
        self::assertStringContainsString('No published products yet — showing a placeholder', $html);
        self::assertStringContainsString('Sample product', $html);
        self::assertSame($before, $this->connection()->table('commerce_products')->count(), 'nothing written');
    }

    public function testAnArchivedSampleFallsBackToThePlaceholder(): void
    {
        $showcase = $this->seed->showcase();
        $session = $this->session();
        $this->applyWorking($session['token']);
        $this->connection()->table('commerce_products')->where('uuid', '=', $showcase['product'])
            ->update(['status' => 'archived']);
        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-placeholder', $html);
        self::assertStringContainsString('Sample product', $html);
        self::assertStringContainsString('WORKING-MARKER', $html, 'the working copy is intact');
    }

    public function testTheStageUsesTheFramesPresentation(): void
    {
        $this->seed->showcase();
        $session = $this->session();
        $this->applyWorking($session['token'], ['width' => 'full']);
        self::assertStringContainsString('class="layout--full"', $this->stage($session['token']));
    }

    public function testARetiredSessionRendersTheRemovalPage(): void
    {
        $this->seed->showcase();
        $session = $this->session();
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
