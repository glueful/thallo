<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Application;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Http\Shop\CartCookie;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ProductPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Product pages render through the product layout (type layouts plan C1, P4): with one saved, every
 * product is the frame around the layout's blocks — at any depth — with the product's values; the
 * frame keeps the canonical link, the structured data, the shop script and the no-JS buy form, and
 * never links the raw shop stylesheet (the theme layer carries it, below authored settings).
 * Without one, the page is today's.
 */
final class ProductLayoutRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ProductPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    private int $version = 0;

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

    /** Save through a session and the admin's Save, as the editor does. */
    private function saveLayout(array $blocks, array $settings = []): void
    {
        $session = json_decode((string) $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => 'product', 'target' => '@site'],
            ),
        )->getContent(), true)['data'];
        $saved = $this->container()->get(LayoutAdminController::class)->save(
            (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                'token' => $session['token'], 'layout' => ['blocks' => $blocks, 'settings' => $settings],
                'expected_lock_version' => $this->version,
            ]),
            Request::create('/x', 'PUT'),
            'product',
            '@site',
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
        $this->version++;
    }

    /** @return list<array<string,mixed>> the product surface's starter, with ids */
    private function starter(): array
    {
        return self::withIds($this->container()->get(LayoutSurfaceRegistry::class)->get('product')->starter('@site'));
    }

    private function page(string $slug): Response
    {
        // A foreign query parameter keeps the shop page cache out of the way.
        $response = $this->handle(Request::create("/shop/products/{$slug}?render=1", 'GET'));
        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 400));
        return $response;
    }

    private static function tags(Response $response): array
    {
        return array_map('trim', explode(',', (string) $response->headers->get('Cache-Tag', '')));
    }

    public function testNoLayoutRendersTheThemeTemplateAndTagsThePageWithoutItsTenant(): void
    {
        $showcase = $this->seed->showcase();
        $mug = $this->seed->multiVariant();
        $lamp = $this->page('linen-lamp');
        self::assertStringContainsString(
            '<article class="shop-product" data-shop-scope=',
            (string) $lamp->getContent(),
        );
        self::assertStringNotContainsString('shop-product--layout', (string) $lamp->getContent());
        // The page's tag names no workspace: the header reaches every visitor (the shop cache adds
        // the workspace's own tag server-side, see ProductLayoutCacheTest).
        self::assertSame(['thallo:shop:layout:product', 'thallo:entry:' . $showcase['entry']], self::tags($lamp));
        self::assertSame(['thallo:shop:layout:product'], self::tags($this->page('stoneware-mug')));
        foreach ([$lamp, $this->page('stoneware-mug')] as $response) {
            self::assertStringNotContainsString(ProductPageSeed::TENANT, (string) $response->headers->get('Cache-Tag'));
        }
        unset($mug);
    }

    public function testALayoutRendersEveryProductThroughTheFrame(): void
    {
        $this->seed->showcase();
        $this->seed->multiVariant();
        $blocks = $this->starter();
        $blocks[] = [
            'id' => 'layoutextra1', 'type' => 'heading', 'data' => ['text' => 'Free returns within 30 days'],
            'settings' => [],
        ];
        $this->saveLayout($blocks);

        $html = (string) $this->page('linen-lamp')->getContent();
        self::assertStringContainsString('<article class="shop-product shop-product--layout"', $html);
        // The name and the buy area sit two containers deep in the starter.
        self::assertMatchesRegularExpression(
            '~<h1 class="thallo-block thallo-block-product_name shop-product__name[^"]*"[^>]*>Linen table lamp</h1>~',
            $html,
        );
        self::assertStringContainsString(
            '<form class="shop-product__add-to-cart" method="post" action="/_shop/cart/add" data-shop-buy',
            $html,
        );
        self::assertStringContainsString('shop-product__price-compare">$119.00', $html);
        self::assertSame(1, substr_count($html, '<script src="/_thallo/shop/shop.js" defer></script>'));
        self::assertStringNotContainsString(
            '<link rel="stylesheet" href="/_thallo/shop/shop.css">',
            $html,
            'no raw, unlayered stylesheet',
        );
        self::assertStringContainsString('Free returns within 30 days', $html);

        $mug = (string) $this->page('stoneware-mug')->getContent();
        self::assertStringContainsString('Free returns within 30 days', $mug, 'every product');
        self::assertStringContainsString('<select name="variant_uuid" required>', $mug);
    }

    public function testTheFrameKeepsTheCanonicalAndTheStructuredData(): void
    {
        $this->seed->showcase();
        $parts = static function (string $html): array {
            preg_match('~<link rel="canonical" href="[^"]*">~', $html, $canonical);
            preg_match('~<script type="application/ld\+json">.*?</script>~s', $html, $ld);
            return [$canonical[0] ?? null, $ld[0] ?? null];
        };
        $theme = $parts((string) $this->page('linen-lamp')->getContent());
        self::assertNotNull($theme[0]);
        self::assertNotNull($theme[1]);

        // A redesign that shows none of the name, price or gallery still carries both, unchanged.
        $this->saveLayout([['id' => 'onlybuy00001', 'type' => 'product_buy', 'data' => [], 'settings' => []]]);
        self::assertSame($theme, $parts((string) $this->page('linen-lamp')->getContent()));
    }

    /** Review Focus 3: the smart block makes the page's own decisions, and posts without JavaScript. */
    public function testTheBuyBlockWorksWithoutJavaScript(): void
    {
        $showcase = $this->seed->showcase();
        $this->seed->multiVariant();
        $this->seed->withRequiredAddon();
        $this->saveLayout($this->starter());

        $lamp = (string) $this->page('linen-lamp')->getContent();
        self::assertStringContainsString(
            '<input type="hidden" name="variant_uuid" value="' . $showcase['variant'] . '">',
            $lamp,
        );
        self::assertDoesNotMatchRegularExpression(
            '~name="(_token|csrf|cart)[^"]*"~',
            $lamp,
            'cache-safe: no token, no cart',
        );
        self::assertStringContainsString(
            '<select name="variant_uuid" required>',
            (string) $this->page('stoneware-mug')->getContent(),
        );
        $gift = (string) $this->page('gift-set')->getContent();
        self::assertStringContainsString('This product is not available for online purchase right now.', $gift);
        self::assertStringNotContainsString('shop-product__add-to-cart', $gift);

        // A physical product sells from stock: give the lamp some, through commerce's own service.
        $this->container()->get(\Glueful\Extensions\Commerce\Inventory\InventoryService::class)
            ->adjust($this->appContext(), $showcase['variant'], 5);
        $origin = $this->container()->get(CanonicalPublicOriginResolver::class)->currentOrigin($this->appContext());
        $posted = $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $showcase['variant'], 'quantity' => 1],
            [],
            [],
            ['HTTP_ORIGIN' => $origin, 'HTTP_REFERER' => $origin . '/shop/products/linen-lamp'],
        ));
        self::assertSame(303, $posted->getStatusCode(), (string) $posted->getContent());
        self::assertSame('/shop/products/linen-lamp', $posted->headers->get('Location'));
        // And the lamp is in the cart the POST minted.
        $cookie = null;
        foreach ($posted->headers->getCookies() as $candidate) {
            if ($candidate->getName() === CartCookie::NAME) {
                $cookie = $candidate;
            }
        }
        self::assertNotNull($cookie, 'the POST minted a cart');
        $cart = $this->handle(
            Request::create('/_shop/cart', 'GET', [], [CartCookie::NAME => (string) $cookie->getValue()]),
        );
        self::assertSame(200, $cart->getStatusCode());
        $body = json_decode((string) $cart->getContent(), true);
        self::assertSame(1, $body['item_count']);
        self::assertSame($showcase['variant'], $body['items'][0]['variant_uuid']);
    }

    public function testTheStoryKeepsItsEntryTag(): void
    {
        $showcase = $this->seed->showcase();
        $this->seed->multiVariant();
        $this->saveLayout($this->starter());

        $lamp = $this->page('linen-lamp');
        self::assertStringContainsString('shop-product__enrichment', (string) $lamp->getContent());
        self::assertStringContainsString('Made by hand', (string) $lamp->getContent());
        self::assertContains('thallo:entry:' . $showcase['entry'], self::tags($lamp));

        $mug = $this->page('stoneware-mug');
        self::assertStringNotContainsString('shop-product__enrichment', (string) $mug->getContent());
        self::assertSame(['thallo:shop:layout:product'], self::tags($mug));
    }

    public function testTheFrameSettingsApply(): void
    {
        $this->seed->showcase();
        $this->saveLayout($this->starter(), ['width' => 'full', 'footer' => 'hidden']);
        $html = (string) $this->page('linen-lamp')->getContent();
        self::assertStringContainsString('<main id="main" tabindex="-1" class="layout--full"', $html);
        self::assertStringNotContainsString('<footer class="site-footer"', $html);
        self::assertStringContainsString('<header class="site-header"', $html, 'only what the frame hides');
    }

    public function testCommerceOffLeavesNoProductPageToRender(): void
    {
        $this->seed->showcase();
        $this->saveLayout($this->starter());
        $this->seed->restoreTenant($this->previousTenant);
        $this->previousTenant = [];
        $off = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
        try {
            $status = (new Application($off))->handle(Request::create('/shop/products/linen-lamp', 'GET'))
                ->getStatusCode();
            self::assertSame(404, $status, 'no shop route, so nothing reads the product layout');
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 'r'): array
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
