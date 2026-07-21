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
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Tenancy\System\SystemFlags;

use function config;

/**
 * Task 9 (storefront-rendering spec §6/§9/§11 verbatim): cart token custody + the
 * `/_shop/cart/*` mutation endpoints + `GET /_shop/cart` (mini-cart JSON) + `GET /cart` (the
 * themed page). Mode (b) single-store, mirroring ShopCatalogTest/ShopCsrfTest's identical
 * convention in this same directory.
 */
final class ShopCartTest extends AppTestCase
{
    private const TENANT_A = 'carttesttena';

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
        $pdo->exec('DELETE FROM commerce_discount_redemptions');
        $pdo->exec('DELETE FROM commerce_discounts');
        $pdo->exec('DELETE FROM commerce_cart_lines');
        $pdo->exec('DELETE FROM commerce_carts');
        $pdo->exec('DELETE FROM commerce_variants');
        $pdo->exec('DELETE FROM commerce_products');
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    // ------------------------------------------------------------------
    // cookie custody
    // ------------------------------------------------------------------

    public function testFirstMutationMintsTheCartCookieWithExactAttributes(): void
    {
        $variant = $this->seedVariant();
        $before = time();

        $response = $this->add($variant, 1);

        $cookie = $this->cartCookieFrom($response);
        self::assertNotNull($cookie);
        self::assertSame(CartCookie::NAME, $cookie->getName());
        self::assertNotSame('', (string) $cookie->getValue());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertFalse($cookie->isRaw());

        $ttlDays = (int) config($this->appContext(), 'commerce.cart.ttl_days', 30);
        $expected = $before + $ttlDays * 86400;
        self::assertGreaterThanOrEqual($expected - 5, $cookie->getExpiresTime());
        self::assertLessThanOrEqual($expected + 60, $cookie->getExpiresTime());
    }

    public function testSecondMutationOnAnAlreadyResolvableCartMintsNoNewCookie(): void
    {
        $variant = $this->seedVariant();
        $first = $this->add($variant, 1);
        $token = $this->tokenFrom($first);

        $second = $this->handle(Request::create(
            '/_shop/cart/update',
            'POST',
            ['variant_uuid' => $variant, 'quantity' => 3],
            [CartCookie::NAME => $token],
            [],
            $this->jsonServer(),
        ));

        self::assertSame(200, $second->getStatusCode());
        self::assertNull($this->cartCookieFrom($second));
    }

    public function testTokenNeverAppearsInAnyResponseBodyOrRedirectLocation(): void
    {
        $variant = $this->seedVariant();

        $jsonResponse = $this->add($variant, 1);
        $token = $this->tokenFrom($jsonResponse);
        self::assertStringNotContainsString($token, (string) $jsonResponse->getContent());

        $prg = $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variant, 'quantity' => 1],
            [CartCookie::NAME => $token],
            [],
            ['HTTP_ORIGIN' => $this->expectedOrigin()],
        ));
        self::assertSame(303, $prg->getStatusCode());
        self::assertStringNotContainsString($token, (string) $prg->headers->get('Location'));
        self::assertStringNotContainsString($token, (string) $prg->getContent());

        $page = $this->handle(Request::create('/cart', 'GET', [], [CartCookie::NAME => $token]));
        self::assertStringNotContainsString($token, (string) $page->getContent());

        $mini = $this->handle(Request::create('/_shop/cart', 'GET', [], [CartCookie::NAME => $token]));
        self::assertStringNotContainsString($token, (string) $mini->getContent());
    }

    // ------------------------------------------------------------------
    // putLine convergence through the HTTP surface
    // ------------------------------------------------------------------

    public function testIdenticalAddReplayedConvergesToOneLineAtTheDesiredQuantity(): void
    {
        $variant = $this->seedVariant();

        $first = $this->add($variant, 2);
        $token = $this->tokenFrom($first);

        $second = $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variant, 'quantity' => 2],
            [CartCookie::NAME => $token],
            [],
            $this->jsonServer(),
        ));

        $data = $this->jsonBody($second);
        self::assertCount(1, $data['items']);
        self::assertSame(2, $data['items'][0]['quantity']);
        self::assertSame(2, $data['item_count']);
    }

    public function testUpdateSetsTheLineToTheSubmittedQuantity(): void
    {
        $variant = $this->seedVariant();
        $token = $this->tokenFrom($this->add($variant, 1));

        $update = $this->handle(Request::create(
            '/_shop/cart/update',
            'POST',
            ['variant_uuid' => $variant, 'quantity' => 5],
            [CartCookie::NAME => $token],
            [],
            $this->jsonServer(),
        ));

        $data = $this->jsonBody($update);
        self::assertCount(1, $data['items']);
        self::assertSame(5, $data['items'][0]['quantity']);
        self::assertSame(5, $data['item_count']);
    }

    public function testUpdateToZeroRemovesTheLine(): void
    {
        $variant = $this->seedVariant();
        $token = $this->tokenFrom($this->add($variant, 2));

        $update = $this->handle(Request::create(
            '/_shop/cart/update',
            'POST',
            ['variant_uuid' => $variant, 'quantity' => 0],
            [CartCookie::NAME => $token],
            [],
            $this->jsonServer(),
        ));

        $data = $this->jsonBody($update);
        self::assertSame([], $data['items']);
        self::assertSame(0, $data['item_count']);
    }

    public function testRemoveEndpointRemovesTheLine(): void
    {
        $variant = $this->seedVariant();
        $token = $this->tokenFrom($this->add($variant, 2));

        $remove = $this->handle(Request::create(
            '/_shop/cart/remove',
            'POST',
            ['variant_uuid' => $variant],
            [CartCookie::NAME => $token],
            [],
            $this->jsonServer(),
        ));

        $data = $this->jsonBody($remove);
        self::assertSame([], $data['items']);
    }

    public function testDiscountApplyAndRemoveRoundTrip(): void
    {
        $variant = $this->seedVariant();
        $token = $this->tokenFrom($this->add($variant, 1));
        $this->seedDiscount('SAVE10', 300);

        $apply = $this->handle(Request::create(
            '/_shop/cart/discount',
            'POST',
            ['code' => 'SAVE10'],
            [CartCookie::NAME => $token],
            [],
            $this->jsonServer(),
        ));
        $applied = $this->jsonBody($apply);
        self::assertSame('SAVE10', $applied['discount_code']);
        self::assertSame(300, $applied['discount_total']);

        $remove = $this->handle(Request::create(
            '/_shop/cart/discount',
            'POST',
            ['code' => ''],
            [CartCookie::NAME => $token],
            [],
            $this->jsonServer(),
        ));
        $removed = $this->jsonBody($remove);
        self::assertNull($removed['discount_code']);
        self::assertSame(0, $removed['discount_total']);
    }

    // ------------------------------------------------------------------
    // GETs: no mint, empty-cart safe, no-store
    // ------------------------------------------------------------------

    public function testGetCartRoutesReturnAnEmptyCartAndMintNothingWhenNoCookieIsPresent(): void
    {
        $json = $this->handle(Request::create('/_shop/cart', 'GET'));
        self::assertSame(200, $json->getStatusCode());
        self::assertNull($this->cartCookieFrom($json));
        $data = $this->jsonBody($json);
        self::assertSame([], $data['items']);
        self::assertSame(0, $data['item_count']);

        $page = $this->handle(Request::create('/cart', 'GET'));
        self::assertSame(200, $page->getStatusCode());
        self::assertNull($this->cartCookieFrom($page));
        self::assertStringContainsString('Your cart is empty', (string) $page->getContent());
    }

    public function testCartPageAndMiniCartJsonAndMutationsAreAllPrivateNoStoreAndNoindex(): void
    {
        $page = $this->handle(Request::create('/cart', 'GET'));
        $this->assertNoStore($page);
        self::assertSame('noindex', $page->headers->get('X-Robots-Tag'));

        $json = $this->handle(Request::create('/_shop/cart', 'GET'));
        $this->assertNoStore($json);
        self::assertSame('noindex', $json->headers->get('X-Robots-Tag'));

        $variant = $this->seedVariant();
        $mutation = $this->add($variant, 1);
        $this->assertNoStore($mutation);
        self::assertSame('noindex', $mutation->headers->get('X-Robots-Tag'));
    }

    public function testCartPageRendersLineDataForARealCart(): void
    {
        $variant = $this->seedVariant();
        $token = $this->tokenFrom($this->add($variant, 2));

        $page = $this->handle(Request::create('/cart', 'GET', [], [CartCookie::NAME => $token]));
        $html = (string) $page->getContent();

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Cart test product', $html);
        self::assertStringNotContainsString('Your cart is empty', $html);
    }

    // ------------------------------------------------------------------
    // closed view model — never raw commerce rows
    // ------------------------------------------------------------------

    public function testClosedCartViewModelNeverLeaksInternalColumns(): void
    {
        $variant = $this->seedVariant();
        $token = $this->tokenFrom($this->add($variant, 1));

        // Poison an internal-only cart-row column and the product row's internal metadata —
        // neither is ever read into CartService::pricedLines()'s (or CartViewModel's) allowlisted
        // shape (mirrors ShopCatalogTest::testProductViewModelNeverLeaksInternalColumns()).
        $pdo = $this->connection()->getPDO();
        $pdo->exec("UPDATE commerce_carts SET user_uuid = 'POISONUSR01'");
        $pdo->exec('UPDATE commerce_products SET metadata = \'{"poison":"POISON-METADATA-MARKER-XYZ"}\'');

        $json = $this->handle(Request::create('/_shop/cart', 'GET', [], [CartCookie::NAME => $token]));
        $page = $this->handle(Request::create('/cart', 'GET', [], [CartCookie::NAME => $token]));

        foreach ([$json, $page] as $response) {
            $body = (string) $response->getContent();
            self::assertStringNotContainsString('POISONUSR01', $body);
            self::assertStringNotContainsString('POISON-METADATA-MARKER-XYZ', $body);
            self::assertStringNotContainsString(self::TENANT_A, $body, 'tenant_uuid must never leak into cart output');
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function expectedOrigin(): string
    {
        return $this->container()->get(CanonicalPublicOriginResolver::class)->currentOrigin($this->appContext());
    }

    /** @return array<string,string> */
    private function jsonServer(): array
    {
        return ['HTTP_ORIGIN' => $this->expectedOrigin(), 'HTTP_ACCEPT' => 'application/json'];
    }

    private function add(string $variant, int $quantity): Response
    {
        return $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variant, 'quantity' => $quantity],
            [],
            [],
            $this->jsonServer(),
        ));
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

    private function tokenFrom(Response $response): string
    {
        $cookie = $this->cartCookieFrom($response);
        self::assertNotNull($cookie, 'expected a minted cart cookie on this response');
        $token = (string) $cookie->getValue();
        self::assertNotSame('', $token);

        return $token;
    }

    /** Symfony's ResponseHeaderBag recomputes/reorders Cache-Control directives — assert content, not exact order. */
    private function assertNoStore(Response $response): void
    {
        $value = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $value);
        self::assertStringContainsString('private', $value);
    }

    /** @return array<string,mixed> */
    private function jsonBody(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function seedVariant(): string
    {
        // type: 'digital' -> StockRepository::ensureRow() creates the row UNTRACKED, so
        // CartService::assertVariantCanSupply() never rejects the add for "exceeding stock" —
        // this suite cares about cart/CSRF behavior, not inventory levels.
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => 'cart-test-' . (++self::$seq),
            'name' => 'Cart test product',
            'status' => 'active',
            'type' => 'digital',
            'variants' => [[
                'sku' => 'cart-sku-' . self::$seq,
                'price' => 1000,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);

        return (string) $product['variants'][0]['uuid'];
    }

    private function seedDiscount(string $code, int $value): void
    {
        $this->connection()->table('commerce_discounts')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => self::TENANT_A,
            'code' => $code,
            'type' => 'fixed',
            'value' => $value,
            'status' => 'active',
        ]);
    }
}
