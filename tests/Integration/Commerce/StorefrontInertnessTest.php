<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Starter\Kinds\BlockTypeKind;
use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Console\CommerceDiagnoseCommand;
use Thallo\Commerce\Http\Shop\CartCookie;
use Thallo\Commerce\Http\Shop\GuestOrderCookie;
use Glueful\Cache\CacheStore;
use Thallo\Commerce\Shop\CapabilityFlipPurge;
use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Templates\RuntimeAssetMap;
use Thallo\Render\TwigFactory;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Commerce-Slice-2 Task 12 (storefront-rendering spec §12 + this task's own brief): the
 * inertness matrix for the whole storefront-rendering feature.
 *
 * - Capability `thallo.commerce` disabled: every shop/cart/checkout/`_shop` route 404s, the 4
 *   shop block types are absent from {@see BlockTypeKind::definitions()} (not just the
 *   registry — the actual starter-provisioning surface a fresh tenant would see), the render
 *   catch-all still serves an ordinary builder page at an unrelated route, and the fingerprinted
 *   asset route 404s too.
 * - `commerce.marketplace.enabled=true`: the existing `thallo:commerce:diagnose` WARN surface
 *   (slice-1) still flags it, but a complete catalog-browse + cart + checkout flow works
 *   identically to a normal boot — no behavioral fork.
 *
 * Every scenario here runs against a SECOND boot (mirrors `ShopCatalogTest`/`InertnessTest`'s
 * established `bootAppWithConfigOverride()`/hand-rolled-boot convention) — nothing needs the
 * retrofit harness.
 */
final class StorefrontInertnessTest extends AppTestCase
{
    private static int $seq = 0;

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        // Children before parents: an orphaned commerce_variants/commerce_carts/commerce_orders
        // row (a bare DELETE FROM commerce_products alone leaves these behind, no FK cascade)
        // would otherwise collide on a fixed SKU/email the next time this suite runs in a fresh
        // process (self::$seq restarts at 0 each process, so the FIRST run always reuses the
        // same literal values).
        foreach (
            [
                'commerce_order_events', 'commerce_order_lines', 'commerce_orders', 'commerce_sequences',
                'commerce_cart_lines', 'commerce_carts',
                'commerce_stock_movements', 'commerce_stock',
                'commerce_variants', 'commerce_products',
            ] as $table
        ) {
            $pdo->exec('DELETE FROM ' . $table);
        }
        parent::tearDown();
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    // ==================================================================
    // capability disabled: every shop/_shop route 404s
    // ==================================================================

    public function testCapabilityDisabledMeansEveryShopRouteFourOhFours(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        try {
            $hit = static fn (string $method, string $path): int => (new Application($disabledApp))->handle(
                Request::create($path, $method, [], [], [], [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ]),
            )->getStatusCode();

            // The shop pack registered NOTHING, so every probe falls through to the rest of
            // the app: GETs land on render's `GET /{path}` catch-all (which 404s unknown
            // paths); non-GETs match that catch-all's PATH template with the wrong method
            // and 405 — production-parity either way, and both prove the shop route is
            // absent (a registered shop route would accept the method).

            // Catalog.
            self::assertSame(404, $hit('GET', '/shop'), 'shop index');
            self::assertSame(404, $hit('GET', '/shop/products/whatever'), 'product detail');
            self::assertSame(404, $hit('GET', '/shop/categories/whatever'), 'category archive');

            // Cart.
            self::assertSame(404, $hit('GET', '/cart'), 'cart page');
            self::assertSame(404, $hit('GET', '/_shop/cart'), 'mini-cart json');
            self::assertSame(405, $hit('POST', '/_shop/cart/add'), 'cart add');
            self::assertSame(405, $hit('POST', '/_shop/cart/update'), 'cart update');
            self::assertSame(405, $hit('POST', '/_shop/cart/remove'), 'cart remove');
            self::assertSame(405, $hit('POST', '/_shop/cart/discount'), 'cart discount');

            // Checkout.
            self::assertSame(404, $hit('GET', '/checkout'), 'checkout page');
            self::assertSame(405, $hit('POST', '/_shop/checkout/quote'), 'checkout quote');
            self::assertSame(405, $hit('POST', '/_shop/checkout/place'), 'checkout place');
            self::assertSame(404, $hit('GET', '/checkout/return/whatever'), 'payment return');
            self::assertSame(404, $hit('GET', '/checkout/cancel/whatever'), 'payment cancel');
            self::assertSame(404, $hit('GET', '/checkout/confirmation/whatever'), 'confirmation');

            // `/_shop/*` block-data + fingerprinted assets.
            self::assertSame(404, $hit('GET', '/_shop/blocks/product-grid'), 'product-grid block data');
            self::assertSame(404, $hit('GET', '/_shop/blocks/featured-product'), 'featured-product block data');
            self::assertSame(404, $hit('GET', '/_shop/blocks/add-to-cart'), 'add-to-cart block data');
            self::assertSame(404, $hit('GET', '/_shop/assets/shop.js'), 'fingerprinted asset alias');
            self::assertSame(404, $hit('GET', '/_shop/assets/shop-deadbeefdead.js'), 'fingerprinted asset');
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ==================================================================
    // capability disabled: the 4 shop blocks are absent from the ACTUAL
    // starter block-type provisioning surface (BlockTypeKind::definitions())
    // ==================================================================

    public function testCapabilityDisabledMeansTheFourShopBlocksAreAbsentFromBlockTypeKindDefinitions(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        try {
            $kind = $disabledApp->getContainer()->get(BlockTypeKind::class);
            $slugs = array_map(
                static fn ($definition): string => $definition->definitionKey,
                $kind->definitions(),
            );

            foreach (
                [
                    ShopBlockTypesContributor::SLUG_PRODUCT_GRID,
                    ShopBlockTypesContributor::SLUG_FEATURED_PRODUCT,
                    ShopBlockTypesContributor::SLUG_ADD_TO_CART,
                    ShopBlockTypesContributor::SLUG_MINI_CART,
                ] as $shopSlug
            ) {
                self::assertNotContains(
                    $shopSlug,
                    $slugs,
                    "block_type '{$shopSlug}' must not appear in BlockTypeKind::definitions() "
                        . 'while thallo.commerce is disabled',
                );
            }
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ==================================================================
    // capability disabled: the pack templates leave the render chain — stored
    // shop blocks render NO shop HTML, no shop.js tag, no /cart links — while
    // stored catalog data survives and re-enabling restores everything
    // ==================================================================

    public function testCapabilityDisabledRemovesShopMarkupFromRenderedPagesButNeverStoredData(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        // Stored catalog data created BEFORE the disabled boot (capability-boundary pin:
        // disabling removes the rendered integration, never the underlying data).
        $slug = 'capability-off-preserve-' . (++self::$seq);
        $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => 'Capability Off Preserve Product',
            'status' => 'active',
            'type' => 'digital',
            'variants' => [[
                'sku' => 'capability-off-sku-' . self::$seq,
                'price' => 1000,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);

        // Control on the ENABLED (primary) boot: the shop templates ARE in the chain.
        $enabledEnv = $this->container()->get(TwigFactory::class)->environment();
        self::assertTrue(
            $enabledEnv->getLoader()->exists('blocks/mini-cart.twig'),
            'sanity: enabled boot serves shop templates',
        );

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        try {
            $container = $disabledApp->getContainer();

            // The pack template dir left the Twig loader entirely.
            $env = $container->get(TwigFactory::class)->environment();
            foreach (
                [
                    'blocks/mini-cart.twig',
                    'blocks/product-grid.twig',
                    'blocks/featured-product.twig',
                    'blocks/add-to-cart.twig',
                    'shop/index.twig',
                ] as $template
            ) {
                self::assertFalse(
                    $env->getLoader()->exists($template),
                    "'{$template}' must not resolve while thallo.commerce is disabled",
                );
            }

            // Stored shop blocks render through the NORMAL missing-template fallback:
            // no shop HTML, no shop.js script tag, no /cart links (capability-boundary pin).
            /** @var RenderContextExtension $extension */
            $extension = $container->get(RenderContextExtension::class);
            $extension->resetBlockDepth();
            $extension->resetBlockFrames();
            $extension->setBlockAnnotations(false);
            $extension->setLocale('en');
            $html = $extension->blocks($env, ['entry' => null, 'site' => []], [
                ['id' => 'b1', 'type' => 'mini-cart', 'data' => []],
                ['id' => 'b2', 'type' => 'product-grid', 'data' => ['source' => 'latest']],
            ]);
            self::assertStringNotContainsString('data-shop-', $html);
            self::assertStringNotContainsString('shop.js', $html);
            self::assertStringNotContainsString('/cart', $html);
            self::assertMatchesRegularExpression(
                '/no template for block "mini-cart"|Missing block template: blocks\/mini-cart\.twig/',
                $html,
                'stored shop blocks fall to blocks()\' ordinary missing-template fallback',
            );

            // boot() ran the flip reconciler on this capability-off boot (its own store's
            // marker records the state — this is what makes the NEXT flip purge fire).
            self::assertSame(
                'off',
                $container->get(CacheStore::class)->get(CapabilityFlipPurge::MARKER_KEY),
                'the capability-off boot must record its state for flip detection',
            );

            // The general theme runtime is render-owned and unaffected (capability pin).
            self::assertNotNull(
                $container->get(RuntimeAssetMap::class)->fingerprintedName('runtime.js'),
                'runtime.js delivery must not depend on thallo.commerce',
            );

            // Stored catalog data survived the disabled boot untouched.
            $row = $this->connection()->table('commerce_products')->where('slug', '=', $slug)->first();
            self::assertNotNull($row, 'disabling the capability must never delete stored catalog data');
        } finally {
            self::resetSharedRepositoryConnection();
        }

        // Re-enabling restores the templates with no migration or resync — registration is
        // purely boot-time (capability-boundary pin).
        $reEnabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => true],
        ]);
        try {
            $reEnv = $reEnabledApp->getContainer()->get(TwigFactory::class)->environment();
            self::assertTrue(
                $reEnv->getLoader()->exists('blocks/mini-cart.twig'),
                're-enable restores shop templates without migration or resync',
            );
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ==================================================================
    // capability disabled: the catch-all still renders an ordinary builder page
    // ==================================================================

    public function testCapabilityDisabledLeavesTheCatchAllRenderingOrdinaryBuilderPages(): void
    {
        // A genuine second-boot HTTP round trip cannot prove this in this harness:
        // ServiceProvider::loadRoutesFrom() latches each extension route FILE in a
        // process-global static with no reset hook (AppTestCase's own documented convention),
        // so ANY second framework boot in this process drops thallo-render's OWN catch-all
        // route (`/{path}`) too — unrelated to thallo.commerce, and reproducible even with the
        // capability left ENABLED on the second boot (verified directly: a second boot's own
        // Router::getAllRoutes() carries zero `/{path}`/`/_preview/*` entries regardless).
        //
        // The structurally correct proof instead resolves the EXACT collaborator
        // `RenderController::page()` consults before ever attempting a render —
        // {@see \Thallo\Render\ReservedPaths::isReserved()} — directly from the disabled boot's
        // container. `ShopReservedPathContributor` registers `{prefix}`/`cart`/`checkout`/
        // `_shop` UNCONDITIONALLY (outside the `thallo.commerce` gate), so this reserved set is
        // identical whether the capability is on or off; proving an ordinary, unrelated path is
        // NOT reserved on the disabled boot is exactly the fact that lets the catch-all proceed
        // to a normal render for it. That the catch-all really does render such a page when the
        // reserved-path guard passes is already proven end-to-end, capability ENABLED, by
        // {@see ShopCatalogTest::testBuilderPageAtShopPrefixCannotShadowTheCatalogIndexWhenCapabilityEnabled}
        // and the ordinary Render suite tests that render builder pages through the same
        // catch-all every day; nothing in `CommerceIntegrationServiceProvider::boot()`'s
        // capability-gated branch touches the reserved-path registry, so disabling the
        // capability cannot alter that outcome.
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $enabledReserved = $this->container()->get(\Thallo\Render\ReservedPaths::class);

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        try {
            $disabledReserved = $disabledApp->getContainer()->get(\Thallo\Render\ReservedPaths::class);

            // The reservation itself still functions on the disabled boot (not silently empty).
            self::assertTrue($disabledReserved->isReserved('/shop'), 'the shop prefix stays reserved');
            self::assertTrue($disabledReserved->isReserved('/cart'), 'cart stays reserved');
            self::assertTrue($disabledReserved->isReserved('/checkout'), 'checkout stays reserved');
            self::assertTrue($disabledReserved->isReserved('/_shop/anything'), '_shop stays reserved');

            // An ordinary, unrelated path is reserved identically (i.e. NOT reserved) whether
            // the capability is on or off — disabling it changes nothing about this guard.
            self::assertFalse($disabledReserved->isReserved('/inertness-unrelated-page'));
            self::assertSame(
                $enabledReserved->isReserved('/inertness-unrelated-page'),
                $disabledReserved->isReserved('/inertness-unrelated-page'),
                'the reserved-path outcome for an unrelated page must not depend on thallo.commerce',
            );
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ==================================================================
    // marketplace enabled=true: existing diagnostic still flags it, but a full
    // catalog + cart + checkout flow works identically — NO behavioral fork
    // ==================================================================

    public function testMarketplaceEnabledStillFlagsInDiagnoseWithNoBehavioralForkAcrossCatalogCartAndCheckout(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $marketplaceApp = $this->bootWithCommerceMarketplaceEnabled();
        $container = $marketplaceApp->getContainer();

        try {
            self::assertTrue(
                (bool) config($marketplaceApp, 'commerce.marketplace.enabled'),
                'sanity: the config override actually took effect on this boot',
            );

            // The existing diagnostic surface (slice-1 InertnessTest) still flags it — a WARN,
            // not a failure.
            $tester = new CommandTester(new CommerceDiagnoseCommand($container, $marketplaceApp));
            $exit = $tester->execute([]);
            $display = $tester->getDisplay();
            self::assertStringContainsString('WARN marketplace', $display);
            self::assertStringContainsString('unsupported configuration in Thallo v1', $display);
            self::assertSame(0, $exit, 'a marketplace warning alone must not fail the diagnose command');

            // NO behavioral fork: an ordinary catalog browse -> cart add -> checkout place ->
            // confirmation flow works identically to any other boot in this suite. Driven via
            // DIRECT controller construction (mirrors ShopCheckoutTest's established
            // `controllerWithCollector()` convention) rather than `Application::handle()`:
            // ServiceProvider::loadRoutesFrom() latches each extension route FILE in a
            // process-global static with no reset hook, so THIS second boot's router (like any
            // second boot in this harness) never re-registers shop-routes.php at all — a pure
            // testing-harness artifact orthogonal to `commerce.marketplace.enabled`, not a real
            // 404. Calling the SAME controller classes the router would have dispatched to,
            // resolved from THIS boot's own container, exercises the real business logic
            // (cart/checkout services, CSRF-guard-free since that guard is router middleware,
            // never part of the controller itself) without depending on route-table state.
            $slug = 'marketplace-noop-product-' . (++self::$seq);
            $product = $container->get(CatalogService::class)->createProduct($marketplaceApp, [
                'slug' => $slug,
                'name' => 'Marketplace Noop Product',
                'status' => 'active',
                'type' => 'digital',
                'variants' => [[
                    'sku' => 'marketplace-noop-sku-' . self::$seq,
                    'price' => 1000,
                    'currency' => 'USD',
                    'option_values' => [],
                ]],
            ]);
            $variantUuid = (string) $product['variants'][0]['uuid'];

            $catalogController = $container->get(\Thallo\Commerce\Http\Shop\ShopCatalogController::class);
            $cartController = $container->get(\Thallo\Commerce\Http\Shop\ShopCartController::class);
            $checkoutController = $container->get(\Thallo\Commerce\Http\Shop\ShopCheckoutController::class);

            $catalog = $catalogController->product(Request::create('/shop/products/' . $slug, 'GET'), $slug);
            self::assertSame(200, $catalog->getStatusCode());
            self::assertStringContainsString('Marketplace Noop Product', (string) $catalog->getContent());

            $add = $cartController->add(Request::create(
                '/_shop/cart/add',
                'POST',
                ['variant_uuid' => $variantUuid, 'quantity' => 1],
                [],
                [],
                ['HTTP_ACCEPT' => 'application/json'],
            ));
            self::assertSame(200, $add->getStatusCode(), (string) $add->getContent());
            $cartToken = null;
            foreach ($add->headers->getCookies() as $cookie) {
                if ($cookie->getName() === CartCookie::NAME) {
                    $cartToken = (string) $cookie->getValue();
                }
            }
            self::assertNotNull($cartToken, 'cart add must mint a cart cookie identically to a normal boot');

            $place = $checkoutController->place(Request::create(
                '/_shop/checkout/place',
                'POST',
                [
                    'idempotency_key' => 'marketplace-noop-checkout-key',
                    'email' => 'marketplace-noop@example.test',
                    'addresses' => ['shipping' => ['country' => 'US']],
                ],
                [CartCookie::NAME => $cartToken],
            ));
            self::assertSame(200, $place->getStatusCode(), (string) $place->getContent());
            self::assertStringContainsString('Payment pending', (string) $place->getContent());

            $order = $this->connection()->table('commerce_orders')
                ->where('email', '=', 'marketplace-noop@example.test')->first();
            self::assertNotNull($order);
            self::assertSame('pending_payment', $order['status']);

            $guestCookie = null;
            foreach ($place->headers->getCookies() as $cookie) {
                if ($cookie->getName() === GuestOrderCookie::NAME) {
                    $guestCookie = (string) $cookie->getValue();
                }
            }
            self::assertNotNull($guestCookie);

            $confirm = $checkoutController->confirmation(
                Request::create('/checkout/confirmation/' . $order['order_number'], 'GET', [], [
                    GuestOrderCookie::NAME => $guestCookie,
                ]),
                (string) $order['order_number'],
            );
            self::assertSame(200, $confirm->getStatusCode());
            self::assertStringContainsString('Payment pending', (string) $confirm->getContent());

            $this->connection()->table('commerce_orders')->where('email', '=', 'marketplace-noop@example.test')
                ->forceDelete();
        } finally {
            $this->cleanupCommerceMarketplaceOverride();
            self::resetSharedRepositoryConnection();
        }
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /**
     * Mirrors slice-1 `InertnessTest::bootWithCommerceMarketplaceEnabled()` exactly:
     * `AppTestCase::bootAppWithConfigOverride()` deletes its override file in its OWN finally
     * immediately after `boot()` returns, but `commerce.*` config is read LAZILY (memoized per
     * namespace on first read, not during boot itself) — so that shared helper's override would
     * already be gone before this test's own reads happen. Boot by hand instead and defer file
     * cleanup to this test's own finally.
     */
    private function bootWithCommerceMarketplaceEnabled(): ApplicationContext
    {
        $root = dirname(__DIR__, 3);
        $overrideDir = $root . '/config/testing';
        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }
        file_put_contents(
            $overrideDir . '/commerce.php',
            "<?php\nreturn ['marketplace' => ['enabled' => true]];\n",
        );

        \Glueful\Routing\RouteManifest::reset();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        return \Glueful\Framework::create($root)
            ->withConfigDir($root . '/config')
            ->withEnvironment('testing')
            ->boot()
            ->getContext();
    }

    private function cleanupCommerceMarketplaceOverride(): void
    {
        $root = dirname(__DIR__, 3);
        @unlink($root . '/config/testing/commerce.php');
        \Glueful\Routing\RouteManifest::reset();
    }
}
