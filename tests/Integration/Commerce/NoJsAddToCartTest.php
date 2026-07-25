<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Http\Shop\CartCookie;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Commerce-Slice-2 Fix A: the canonical product detail page (`/shop/products/{slug}`) must
 * server-render a REAL add-to-cart `<form>` that works with zero JavaScript — the pinned
 * PRG/works-with-zero-JS promise (storefront-rendering spec §1/§3/§10). Before this fix the
 * product template rendered display + optional enrichment only; the ONLY add surface was the
 * add-to-cart BLOCK, which ships an empty shell whose form is `hidden` until `shop.js` injects
 * `variant_uuid` — so a no-JS client could never reach `/_shop/cart/add` from the product page
 * at all (cs2-final-review.md, "add-to-cart JS-only" finding).
 *
 * Mirrors ShopCatalogTest/ShopCartTest/ShopCsrfTest/ShopBlocksTest's identical mode (b)
 * single-store convention (widened schema + persisted default tenant) in this same directory.
 */
final class NoJsAddToCartTest extends AppTestCase
{
    private const TENANT_A = 'nojstesttena';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateCommerceCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
    }

    protected function tearDown(): void
    {
        $this->truncateCommerceCatalog();
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function truncateCommerceCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM commerce_product_addons');
        $pdo->exec('DELETE FROM commerce_cart_lines');
        $pdo->exec('DELETE FROM commerce_carts');
        $pdo->exec('DELETE FROM commerce_stock');
        $pdo->exec('DELETE FROM commerce_variants');
        $pdo->exec('DELETE FROM commerce_products');
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function urls(): ShopUrlGenerator
    {
        return $this->container()->get(ShopUrlGenerator::class);
    }

    // ------------------------------------------------------------------
    // 1. the product page renders a REAL, working, server-side form
    // ------------------------------------------------------------------

    public function testSimpleProductPageRendersAWorkingNoJsFormWithTheVariantUuid(): void
    {
        [, $variantUuid] = $this->seedSimpleProduct('nojs-simple');

        $response = $this->handle(Request::create('/shop/products/nojs-simple', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            '<form class="shop-product__add-to-cart" method="post" action="/_shop/cart/add">',
            $html,
        );
        self::assertStringContainsString(
            '<input type="hidden" name="variant_uuid" value="' . $variantUuid . '">',
            $html,
        );
        self::assertStringContainsString('name="quantity"', $html);
        self::assertStringContainsString('<button type="submit">Add to cart</button>', $html);
        // Never hidden — this is not a JS-revealed shell (contrast with the add-to-cart BLOCK's
        // own `data-shop-add-to-cart-form ... hidden` shell).
        self::assertDoesNotMatchRegularExpression(
            '/<form[^>]*shop-product__add-to-cart[^>]*\bhidden\b/',
            $html,
        );
    }

    // ------------------------------------------------------------------
    // 2. posting the no-JS form: 303 PRG, line added, lands back on the referring page
    // ------------------------------------------------------------------

    public function testPostingTheNoJsFormAddsTheLineAndRedirectsBackToTheProductPageAsPrg(): void
    {
        [, $variantUuid] = $this->seedSimpleProduct('nojs-prg');
        $productPath = $this->urls()->product('nojs-prg');

        // A real browser posting a real <form> from the product page sends BOTH Origin and
        // Referer pointing at that same page — no Accept header (no-JS).
        $response = $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variantUuid, 'quantity' => 1],
            [],
            [],
            [
                'HTTP_ORIGIN' => $this->expectedOrigin(),
                'HTTP_REFERER' => $this->expectedOrigin() . $productPath,
            ],
        ));

        self::assertSame(303, $response->getStatusCode());
        // The "flash": a no-JS submit lands the visitor back where they were — never a blind
        // redirect to some other page — so the mutation's effect is visible on next paint.
        self::assertSame($productPath, $response->headers->get('Location'));

        $cookie = $this->cartCookieFrom($response);
        self::assertNotNull($cookie, 'expected a minted cart cookie on the first mutation');
        $cart = $this->jsonBody($this->handle(Request::create(
            '/_shop/cart',
            'GET',
            [],
            [CartCookie::NAME => (string) $cookie->getValue()],
        )));
        self::assertSame(1, $cart['item_count']);
        self::assertSame($variantUuid, $cart['items'][0]['variant_uuid']);
    }

    // ------------------------------------------------------------------
    // 3. a multi-variant product renders a native <select> of real options
    // ------------------------------------------------------------------

    public function testMultiVariantProductPageRendersANativeSelectWithRealOptions(): void
    {
        [, $variantA, $variantB] = $this->seedMultiVariantProduct('nojs-select');

        $response = $this->handle(Request::create('/shop/products/nojs-select', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<select name="variant_uuid" required>', $html);
        self::assertStringContainsString('<option value="' . $variantA . '">', $html);
        self::assertStringContainsString('<option value="' . $variantB . '">', $html);
        // A select-mode product must never ALSO carry a pre-filled hidden variant_uuid — the
        // customer has to make a real choice.
        self::assertStringNotContainsString('<input type="hidden" name="variant_uuid"', $html);
        self::assertStringContainsString('<button type="submit">Add to cart</button>', $html);
    }

    // ------------------------------------------------------------------
    // 3b. a required-add-on product (no add-on picker UI exists) never renders an invalid form
    // ------------------------------------------------------------------

    public function testRequiredAddonProductRendersAnHonestStatusMessageNeverAnInvalidForm(): void
    {
        [$productUuid] = $this->seedSimpleProduct('nojs-addon');
        $this->connection()->table('commerce_product_addons')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => self::TENANT_A,
            'product_uuid' => $productUuid,
            'name' => 'Gift wrap',
            'field_type' => 'checkbox',
            'required' => true,
            'price_delta' => 0,
            'position' => 0,
            'status' => 'active',
        ]);

        $response = $this->handle(Request::create('/shop/products/nojs-addon', 'GET'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString(
            'shop-product__add-to-cart',
            $html,
            'never a form that would omit the required add-on',
        );
        self::assertStringContainsString('shop-product__unavailable', $html);
    }

    // ------------------------------------------------------------------
    // 4. server-side revalidation: an invalid / out-of-stock variant fails exactly like the
    //    JS (JSON) path — never a corrupted or invalid cart line.
    // ------------------------------------------------------------------

    public function testUnknownVariantSubmissionFailsValidationTheSameAsTheJsPath(): void
    {
        $productPath = $this->urls()->product('nojs-noop'); // no product needed — variant is bogus
        $response = $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => 'does-not-exist', 'quantity' => 1],
            [],
            [],
            [
                'HTTP_ORIGIN' => $this->expectedOrigin(),
                'HTTP_REFERER' => $this->expectedOrigin() . $productPath,
            ],
        ));

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('cart_err=1', (string) $response->headers->get('Location'));

        $cookie = $this->cartCookieFrom($response);
        self::assertNotNull($cookie);
        $cart = $this->jsonBody($this->handle(Request::create(
            '/_shop/cart',
            'GET',
            [],
            [CartCookie::NAME => (string) $cookie->getValue()],
        )));
        self::assertSame(0, $cart['item_count'], 'an invalid variant must never produce a cart line');
    }

    public function testOutOfStockVariantSubmissionFailsValidationTheSameAsTheJsPath(): void
    {
        // type: 'physical' -> StockRepository::ensureRow() creates the row TRACKED with
        // quantity 0 — the freshly created variant is genuinely out of stock by construction.
        [, $variantUuid] = $this->seedSimpleProduct('nojs-oos', physical: true);
        $productPath = $this->urls()->product('nojs-oos');

        // Precondition: the product page still renders the (mode=direct) form — availability
        // for FORM RENDERING is a status/type decision, not a stock decision; stock is
        // revalidated server-side at submit time, exactly like the JS path.
        $page = $this->handle(Request::create('/shop/products/nojs-oos', 'GET'));
        self::assertStringContainsString(
            '<input type="hidden" name="variant_uuid" value="' . $variantUuid . '">',
            (string) $page->getContent(),
        );

        $response = $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variantUuid, 'quantity' => 1],
            [],
            [],
            [
                'HTTP_ORIGIN' => $this->expectedOrigin(),
                'HTTP_REFERER' => $this->expectedOrigin() . $productPath,
            ],
        ));

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('cart_err=1', (string) $response->headers->get('Location'));

        $cookie = $this->cartCookieFrom($response);
        self::assertNotNull($cookie);
        $cart = $this->jsonBody($this->handle(Request::create(
            '/_shop/cart',
            'GET',
            [],
            [CartCookie::NAME => (string) $cookie->getValue()],
        )));
        self::assertSame(0, $cart['item_count'], 'an out-of-stock variant must never produce a cart line');
    }

    // ------------------------------------------------------------------
    // 5. cache-safety: the form markup carries no cart/CSRF token
    // ------------------------------------------------------------------

    public function testFormMarkupCarriesNoCartOrCsrfToken(): void
    {
        $this->seedSimpleProduct('nojs-cache-safe');

        $response = $this->handle(Request::create('/shop/products/nojs-cache-safe', 'GET'));
        $html = (string) $response->getContent();

        self::assertMatchesRegularExpression(
            '#<form class="shop-product__add-to-cart"[^>]*>(.*?)</form>#s',
            $html,
            'precondition: the form must be present',
        );
        preg_match('#<form class="shop-product__add-to-cart"[^>]*>(.*?)</form>#s', $html, $m);
        $formInner = $m[1];

        // Every <input>/<select> inside the form is one of the two field names the endpoint
        // actually reads — no csrf/_token/session-bound field of any kind.
        preg_match_all('/name="([^"]+)"/', $formInner, $names);
        self::assertSame(['variant_uuid', 'quantity'], $names[1]);
        self::assertStringNotContainsString('csrf', strtolower($formInner));
        self::assertStringNotContainsString('token', strtolower($formInner));

        // No cookie is set on a plain GET (nothing session-specific baked into the cached page).
        self::assertNull($this->cartCookieFrom($response));
    }

    // ------------------------------------------------------------------
    // 6. CSRF still applies to this exact no-JS flow
    // ------------------------------------------------------------------

    public function testCrossOriginNoJsFormPostIsRejected(): void
    {
        [, $variantUuid] = $this->seedSimpleProduct('nojs-csrf');

        $response = $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variantUuid, 'quantity' => 1],
            [],
            [],
            ['HTTP_ORIGIN' => 'https://evil.attacker.test'],
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // 7. the add-to-cart BLOCK's no-JS output is a product-page link, never an inert shell
    // ------------------------------------------------------------------

    public function testAddToCartBlockNoscriptFallbackIsAProductPageLinkNotAnInertShell(): void
    {
        $html = $this->renderBlock('add-to-cart', ['product_slug' => 'block-nojs-target']);

        self::assertMatchesRegularExpression('#<noscript>(.*?)</noscript>#s', $html);
        preg_match('#<noscript>(.*?)</noscript>#s', $html, $m);
        $noscriptInner = $m[1];

        self::assertStringContainsString(
            '<a href="' . $this->urls()->product('block-nojs-target') . '">',
            $noscriptInner,
            'the no-JS fallback must be a working link to the canonical product page',
        );
    }

    public function testAddToCartBlockNoscriptDegradesToPlainTextWithoutAResolvableSlug(): void
    {
        $html = $this->renderBlock('add-to-cart', []);

        preg_match('#<noscript>(.*?)</noscript>#s', $html, $m);
        self::assertStringNotContainsString('<a ', $m[1], 'no slug to resolve -> no dead link either');
        self::assertStringContainsString('Enable JavaScript', $m[1]);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function expectedOrigin(): string
    {
        return $this->container()->get(CanonicalPublicOriginResolver::class)->currentOrigin($this->appContext());
    }

    private function cartCookieFrom(Response $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === CartCookie::NAME) {
                return $cookie;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function jsonBody(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array{0: string, 1: string} [productUuid, variantUuid] */
    private function seedSimpleProduct(string $slug, bool $physical = false): array
    {
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'status' => 'active',
            'type' => $physical ? 'physical' : 'digital',
            'variants' => [[
                'sku' => 'sku-' . $slug . '-' . (++self::$seq),
                'price' => 1500,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);

        return [(string) $product['uuid'], (string) $product['variants'][0]['uuid']];
    }

    /** @return array{0: string, 1: string, 2: string} [productUuid, variantAUuid, variantBUuid] */
    private function seedMultiVariantProduct(string $slug): array
    {
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'status' => 'active',
            'type' => 'digital',
            'variants' => [
                ['sku' => 'sku-a-' . (++self::$seq), 'price' => 1000, 'currency' => 'USD', 'option_values' => []],
                ['sku' => 'sku-b-' . self::$seq, 'price' => 2000, 'currency' => 'USD', 'option_values' => []],
            ],
        ]);

        return [
            (string) $product['uuid'],
            (string) $product['variants'][0]['uuid'],
            (string) $product['variants'][1]['uuid'],
        ];
    }

    /** Mirrors ShopBlocksTest::renderBlock() — a pure Twig block render, no HTTP/DB required. */
    private function renderBlock(string $type, array $data, ?array $entry = null): string
    {
        $env = $this->container()->get(TwigFactory::class)->environment();
        /** @var RenderContextExtension $extension */
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetBlockDepth();
        $extension->resetBlockFrames();
        $extension->setBlockAnnotations(false);
        $extension->setLocale('en');

        return $extension->blocks($env, ['entry' => $entry, 'site' => []], [
            ['id' => 'test-block', 'type' => $type, 'data' => $data],
        ]);
    }
}
