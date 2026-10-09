<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Http\Shop\CartCookie;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Glueful\Application;

/**
 * The shop home and category pages render through their layouts (type layouts plan C2, S3): with a
 * layout, `layouts/shop_index.twig` or `layouts/shop_category.twig` around its blocks — the canonical
 * link and the wishlist scope kept, the shop stylesheet only through the theme artifact — and with
 * none, today's templates. Every page carries its surface's cache tag either way. A layout never
 * makes a page exist: an unknown category is the themed 404, a bad `page` the cache's 404.
 */
final class ShopLayoutRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ShopPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    /** @var array<string,int> surface => the next lock version */
    private array $version = ['shop_index' => 0, 'shop_category' => 0];

    /** @var array{categories: array<string,string>, products: list<string>, variants: list<list<string>>} */
    private array $seeded;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->seed = new ShopPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
        try {
            $this->seeded = $this->seed->seed();
        } catch (\Throwable $e) {
            // PHPUnit skips tearDown after a throwing setUp: never leave the proof tenant's flags.
            $this->seed->restoreTenant($this->previousTenant);
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        foreach (array_keys($this->version) as $surface) {
            $this->container()->get(LayoutResolver::class)->forget($surface, '@site');
        }
        parent::tearDown();
    }

    /**
     * Save through a session and the admin's Save, as the editor does.
     *
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     */
    private function saveLayout(string $surface, array $blocks, array $settings = []): void
    {
        $session = json_decode((string) $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => $surface, 'target' => '@site'],
            ),
        )->getContent(), true)['data'];
        $saved = $this->container()->get(LayoutAdminController::class)->save(
            (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                'token' => $session['token'], 'layout' => ['blocks' => $blocks, 'settings' => $settings],
                'expected_lock_version' => $this->version[$surface],
            ]),
            Request::create('/x', 'PUT'),
            $surface,
            '@site',
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
        $this->version[$surface]++;
    }

    /** @return list<array<string,mixed>> */
    private function starter(string $surface): array
    {
        return self::withIds($this->container()->get(LayoutSurfaceRegistry::class)->get($surface)->starter('@site'));
    }

    private function page(string $path, int $status = 200): Response
    {
        // A foreign query parameter keeps the shop page cache out of the way.
        $response = $this->handle(Request::create($path . (str_contains($path, '?') ? '&' : '?') . 'render=1', 'GET'));
        self::assertSame(
            $status,
            $response->getStatusCode(),
            $path . ': ' . substr((string) $response->getContent(), 0, 400),
        );
        return $response;
    }

    /** @return list<string> */
    private static function tags(Response $response): array
    {
        return array_map('trim', explode(',', (string) $response->headers->get('Cache-Tag', '')));
    }

    private static function canonicalLink(string $html): string
    {
        preg_match('~<link rel="canonical" href="[^"]*">~', $html, $m);
        return $m[0] ?? '';
    }

    public function testNoLayoutRendersTodaysPagesWithTheirTags(): void
    {
        $home = $this->page('/shop');
        self::assertStringContainsString('<section class="shop-index" data-shop-scope=', (string) $home->getContent());
        self::assertSame(['thallo:shop:layout:shop_index'], self::tags($home));
        $mugs = $this->page('/shop/categories/mugs');
        self::assertStringContainsString(
            '<section class="shop-category" data-shop-scope=',
            (string) $mugs->getContent(),
        );
        self::assertSame(['thallo:shop:layout:shop_category'], self::tags($mugs));
        self::assertStringNotContainsString(ShopPageSeed::TENANT, (string) $home->headers->get('Cache-Tag'));
    }

    public function testALayoutRendersTheHomeAndEveryCategoryThroughTheFrame(): void
    {
        $themeHome = (string) $this->page('/shop')->getContent();
        $themeMugs = (string) $this->page('/shop/categories/mugs')->getContent();
        $this->saveLayout('shop_index', $this->starter('shop_index'));
        $category = $this->starter('shop_category');
        array_unshift($category, ['id' => 'addedhead001', 'type' => 'heading',
            'data' => ['text' => 'Every category', 'level' => 'h2'], 'settings' => []]);
        $this->saveLayout('shop_category', $category);

        $home = $this->page('/shop');
        $html = (string) $home->getContent();
        self::assertStringContainsString('<section class="shop-index shop-index--layout" data-shop-scope=', $html);
        self::assertMatchesRegularExpression('~<h1 class="shop-titlerow__heading[^"]*">Shop</h1>~', $html);
        self::assertStringContainsString('<span class="shop-titlerow__count">26 products</span>', $html);
        self::assertStringContainsString(
            'shop-rail__chip shop-rail__chip--active" aria-current="page" href="/shop">All</a>',
            $html,
        );
        self::assertSame(24, substr_count($html, '<li class="thallo-loop-card shop-grid__item">'));
        // The card's blocks, two containers deep.
        self::assertStringContainsString('<a href="/shop/products/tall-mug">Tall mug</a></h2>', $html);
        self::assertStringContainsString('<a href="/shop?page=2" rel="next">Older</a>', $html);
        self::assertStringNotContainsString('<link rel="stylesheet" href="/_thallo/shop/shop.css">', $html);
        self::assertSame(self::canonicalLink($themeHome), self::canonicalLink($html));
        self::assertNotSame('', self::canonicalLink($html));
        self::assertSame(['thallo:shop:layout:shop_index'], self::tags($home));

        $mugs = $this->page('/shop/categories/mugs');
        $mugsHtml = (string) $mugs->getContent();
        self::assertStringContainsString(
            '<section class="shop-category shop-category--layout" data-shop-scope=',
            $mugsHtml,
        );
        self::assertMatchesRegularExpression('~<h1 class="shop-titlerow__heading[^"]*">Mugs</h1>~', $mugsHtml);
        self::assertStringContainsString('aria-current="page" href="/shop/categories/mugs">Mugs</a>', $mugsHtml);
        self::assertSame(2, substr_count($mugsHtml, '<li class="thallo-loop-card shop-grid__item">'));
        self::assertSame(self::canonicalLink($themeMugs), self::canonicalLink($mugsHtml));
        self::assertSame(['thallo:shop:layout:shop_category'], self::tags($mugs));
        self::assertStringContainsString('Every category', $mugsHtml);
        self::assertStringContainsString(
            'Every category',
            (string) $this->page('/shop/categories/bowls')->getContent(),
        );
        self::assertStringContainsString(
            '<li class="empty">No products in this category yet.</li>',
            (string) $this->page('/shop/categories/vases')->getContent(),
        );
        self::assertStringNotContainsString('Every category', $html, 'the home has its own layout');
    }

    /** Review Focus 3: a layout never changes which pages exist, or what a page past the last shows. */
    public function testPagesUnderALayout(): void
    {
        $pastTheEnd = $this->page('/shop?page=9');
        $this->saveLayout('shop_index', $this->starter('shop_index'));

        $page2 = (string) $this->page('/shop?page=2')->getContent();
        self::assertSame(2, substr_count($page2, '<li class="thallo-loop-card shop-grid__item">'));
        self::assertStringContainsString('<a href="/shop" rel="prev">Newer</a>', $page2);
        self::assertStringContainsString('<span>Page 2 of 2</span>', $page2);

        foreach (['0', 'abc', '1001'] as $bad) {
            $status = $this->handle(Request::create('/shop?page=' . $bad, 'GET'))->getStatusCode();
            self::assertSame(404, $status, "?page={$bad}");
        }
        // Past the last page: the same empty list and navigation as today's template.
        $layout = (string) $this->page('/shop?page=9')->getContent();
        $today = (string) $pastTheEnd->getContent();
        self::assertStringContainsString('<li class="empty">No products yet.</li>', $today);
        self::assertStringContainsString('<li class="empty">No products yet.</li>', $layout);
        preg_match('~<nav class="pagination">(.*?)</nav>~s', $today, $todayNav);
        preg_match(
            '~<nav class="thallo-block thallo-block-pagination pagination[^"]*"[^>]*>(.*?)</nav>~s',
            $layout,
            $layoutNav,
        );
        self::assertSame(
            preg_replace('~\s+~', ' ', trim($todayNav[1] ?? 'none')),
            preg_replace('~\s+~', ' ', trim($layoutNav[1] ?? 'none')),
        );
        $this->page('/shop/categories/nosuchcategory', 404);
    }

    /** Review Focus 1: the tile's quick add posts with JavaScript off, and the options cards link out. */
    public function testTheQuickAddWorksWithoutJavaScript(): void
    {
        $this->saveLayout('shop_index', $this->starter('shop_index'));
        $html = (string) $this->page('/shop')->getContent();
        $variant = $this->seeded['variants'][0][0];
        self::assertStringContainsString('<input type="hidden" name="variant_uuid" value="' . $variant . '">', $html);
        self::assertStringContainsString('shop-grid__action--options" href="/shop/products/mug-pair"', $html);
        self::assertStringContainsString('shop-grid__action--options" href="/shop/products/gift-box"', $html);
        self::assertDoesNotMatchRegularExpression('~name="(_token|csrf|cart)[^"]*"~', $html, 'cache-safe');

        $this->container()->get(\Glueful\Extensions\Commerce\Inventory\InventoryService::class)
            ->adjust($this->appContext(), $variant, 5);
        $origin = $this->container()->get(CanonicalPublicOriginResolver::class)->currentOrigin($this->appContext());
        $posted = $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variant, 'quantity' => 1],
            [],
            [],
            ['HTTP_ORIGIN' => $origin, 'HTTP_REFERER' => $origin . '/shop'],
        ));
        self::assertSame(303, $posted->getStatusCode(), (string) $posted->getContent());
        self::assertSame('/shop', $posted->headers->get('Location'));
        $cookie = null;
        foreach ($posted->headers->getCookies() as $candidate) {
            if ($candidate->getName() === CartCookie::NAME) {
                $cookie = $candidate;
            }
        }
        self::assertNotNull($cookie, 'the POST minted a cart');
        $cart = json_decode((string) $this->handle(
            Request::create('/_shop/cart', 'GET', [], [CartCookie::NAME => (string) $cookie->getValue()]),
        )->getContent(), true);
        self::assertSame(1, $cart['item_count']);
        self::assertSame($variant, $cart['items'][0]['variant_uuid']);
    }

    public function testTheFrameSettingsApply(): void
    {
        $this->saveLayout('shop_index', $this->starter('shop_index'), ['width' => 'full', 'footer' => 'hidden']);
        $html = (string) $this->page('/shop')->getContent();
        self::assertStringContainsString('<main id="main" tabindex="-1" class="layout--full"', $html);
        self::assertStringNotContainsString('<footer class="site-footer"', $html);
        self::assertStringContainsString('<header class="site-header"', $html, 'only what the frame hides');
        // No Frame settings: today's presentation.
        self::assertStringNotContainsString(
            'layout--full',
            (string) $this->page('/shop/categories/mugs')->getContent(),
        );
    }

    public function testTheFramesStylesPaintMain(): void
    {
        $style = [
            'spacing' => ['padding' => ['top' => ['lg' => ['type' => 'token', 'value' => 'spacing.lg']]]],
            'colors' => ['surface' => ['type' => 'token', 'value' => 'color.surface']],
        ];
        $this->saveLayout('shop_category', $this->starter('shop_category'), ['style' => $style]);
        $html = (string) $this->page('/shop/categories/mugs')->getContent();
        self::assertStringContainsString(
            '<main id="main" tabindex="-1" class="layout--centered lg:t-pt-lg t-bg-surface"',
            $html,
        );
    }

    public function testCommerceOffLeavesNoShopPageToRender(): void
    {
        $this->saveLayout('shop_index', $this->starter('shop_index'));
        $this->saveLayout('shop_category', $this->starter('shop_category'));
        $this->seed->restoreTenant($this->previousTenant);
        $this->previousTenant = [];
        $off = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
        try {
            foreach (['/shop', '/shop/categories/mugs'] as $path) {
                $status = (new Application($off))->handle(Request::create($path, 'GET'))->getStatusCode();
                self::assertSame(404, $status, "{$path}: no shop route, so nothing reads the shop layouts");
            }
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 'h'): array
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
