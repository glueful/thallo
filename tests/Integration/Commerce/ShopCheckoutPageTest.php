<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Http\Shop\CartCookie;
use Thallo\Commerce\Http\Shop\ShopCheckoutController;
use Thallo\Commerce\Http\Shop\ShopCsrfGuard;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The checkout PAGE (checkout-ui plan Task 3): the no-JS quote render (direct POST, never PRG),
 * HTML/JSON projection parity, state-preserving placement errors, the provider-neutral posture
 * line, optional shopper identity (email prefill + order ownership stamping), and the route
 * posture (tenant pair → optional identity → ShopCsrfGuard).
 */
final class ShopCheckoutPageTest extends AppTestCase
{
    private const TENANT = 'checkoutpgtn';

    private static int $seq = 0;

    /** @var list<string> */
    private array $createdUserUuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateCommerceCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT);
    }

    protected function tearDown(): void
    {
        $this->truncateCommerceCatalog();
        foreach ($this->createdUserUuids as $uuid) {
            $this->connection()->table('users')->where('uuid', '=', $uuid)->forceDelete();
        }
        $this->createdUserUuids = [];
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Route posture
    // ------------------------------------------------------------------

    public function testCheckoutRoutesCarryOptionalIdentityAfterTheTenantPair(): void
    {
        foreach ([['GET', '/checkout'], ['POST', '/checkout'], ['POST', '/_shop/checkout/place']] as [$m, $p]) {
            $route = $this->findRoute($m, $p);
            self::assertNotNull($route, "{$m} {$p} must be registered");
            $middleware = array_map(
                static fn ($e): string => is_string($e) ? $e : (string) $e,
                (array) ($route['middleware'] ?? []),
            );
            $tenantAt = array_search('tenant_bootstrap', $middleware, true);
            $sessionAt = array_search('session_cookie:optional', $middleware, true);
            $authAt = array_search('auth:optional', $middleware, true);
            self::assertNotFalse($sessionAt, "{$m} {$p} must carry session_cookie:optional");
            self::assertNotFalse($authAt, "{$m} {$p} must carry auth:optional");
            self::assertGreaterThan($tenantAt, $sessionAt, 'tenant pair resolves before identity');
            self::assertGreaterThan($sessionAt, $authAt, 'cookie adapter runs before auth');
        }

        // The no-JS quote POST carries the same provenance guard as every other shop form post.
        $quotePage = $this->findRoute('POST', '/checkout');
        self::assertContains(ShopCsrfGuard::class, (array) ($quotePage['middleware'] ?? []));
    }

    // ------------------------------------------------------------------
    // GET page: posture + fields + honest button targets
    // ------------------------------------------------------------------

    public function testGetCheckoutRendersPostureFieldsAndHonestActions(): void
    {
        $cartToken = $this->addToCart($this->seedVariant());

        $response = $this->handle(Request::create('/checkout', 'GET', [], [CartCookie::NAME => $cartToken]));

        self::assertSame(200, $response->getStatusCode());
        $this->assertNoStore($response);
        $html = (string) $response->getContent();
        // Provider-neutral posture: never a gateway name before placement.
        self::assertStringContainsString('payment instructions or a secure payment step will follow', $html);
        self::assertStringNotContainsString('Paystack', $html);
        self::assertStringNotContainsString('Stripe', $html);
        // The one form: quote render is the default action, placement is the submitter override.
        self::assertStringContainsString('action="/checkout"', $html);
        self::assertStringContainsString('formaction="/_shop/checkout/place"', $html);
        foreach (['name', 'line1', 'city', 'state', 'postcode', 'country'] as $field) {
            self::assertStringContainsString('name="addresses[shipping][' . $field . ']"', $html);
        }
        self::assertMatchesRegularExpression('/name="idempotency_key" value="[0-9a-f]{32}"/', $html);
    }

    // ------------------------------------------------------------------
    // The no-JS quote leg: direct POST render, state preserved
    // ------------------------------------------------------------------

    public function testNoJsQuoteRenderPreservesValuesTotalsAndTheSubmittedKey(): void
    {
        $cartToken = $this->addToCart($this->seedVariant());

        $response = $this->quotePage($cartToken, [
            'idempotency_key' => 'nojs-quote-key-0000000000000001',
            'email' => 'quote-buyer@example.com',
            'addresses' => ['shipping' => ['country' => 'US', 'city' => 'Austin']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNoStore($response);
        $html = (string) $response->getContent();
        // Submitted values survive the render (no PRG — nothing here is session-backed).
        self::assertStringContainsString('value="quote-buyer@example.com"', $html);
        self::assertStringContainsString('value="Austin"', $html);
        self::assertStringContainsString('value="nojs-quote-key-0000000000000001"', $html);
        // Quote-driven totals render formatted (10.00 USD subtotal from the seeded 1000-minor line).
        self::assertStringContainsString('data-shop-quote-subtotal>10.00 USD', $html);
        self::assertStringContainsString('data-shop-quote-total>', $html);
    }

    public function testQuoteJsonParityBetweenThePageRenderAndTheEndpoint(): void
    {
        $cartToken = $this->addToCart($this->seedVariant());
        $body = [
            'email' => 'parity@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ];

        $page = $this->quotePage($cartToken, $body, ['HTTP_ACCEPT' => 'application/json']);
        $endpoint = $this->handle(Request::create(
            '/_shop/checkout/quote',
            'POST',
            $body,
            [CartCookie::NAME => $cartToken],
            [],
            $this->csrfServer() + ['HTTP_ACCEPT' => 'application/json'],
        ));

        self::assertSame(200, $page->getStatusCode());
        self::assertSame(200, $endpoint->getStatusCode());
        self::assertSame(
            json_decode((string) $endpoint->getContent(), true),
            json_decode((string) $page->getContent(), true),
            'one shared projector: the page render and the endpoint may never fork',
        );
    }

    // ------------------------------------------------------------------
    // Placement errors: state-preserving render, never the lossy flag redirect
    // ------------------------------------------------------------------

    public function testPlaceValidationErrorRendersThePageWithStateNotARedirect(): void
    {
        $cartToken = $this->addToCart($this->seedVariant());

        $response = $this->handle(Request::create(
            '/_shop/checkout/place',
            'POST',
            [
                'idempotency_key' => 'nojs-place-key-0000000000000001',
                // email deliberately missing
                'addresses' => ['shipping' => ['country' => 'US', 'city' => 'Austin']],
            ],
            [CartCookie::NAME => $cartToken],
            [],
            $this->csrfServer(),
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'), 'never the lossy 303/?checkout_err redirect');
        $this->assertNoStore($response);
        $html = (string) $response->getContent();
        self::assertStringContainsString('The email field is required.', $html);
        self::assertStringContainsString('value="Austin"', $html);
        // The SUBMITTED key is reused verbatim — a same-key retry stays idempotent.
        self::assertStringContainsString('value="nojs-place-key-0000000000000001"', $html);
    }

    // ------------------------------------------------------------------
    // Optional shopper identity: prefill + ownership stamping (controller-level — the
    // post-auth `user` attribute is what auth:optional sets; kernel cookie minting is the
    // account suite's concern)
    // ------------------------------------------------------------------

    public function testSignedInVisitorGetsEmailPrefilledAndStampsOrderOwnership(): void
    {
        $email = 'shopper-prefill-' . uniqid() . '@example.test';
        $userUuid = $this->seedUser($email);
        $controller = $this->container()->get(ShopCheckoutController::class);
        $cartToken = $this->addToCart($this->seedVariant());

        $pageRequest = Request::create('/checkout', 'GET', [], [CartCookie::NAME => $cartToken]);
        $pageRequest->attributes->set('user', ['uuid' => $userUuid]);
        $html = (string) $controller->page($pageRequest)->getContent();
        self::assertStringContainsString('value="' . $email . '"', $html);

        $placeRequest = Request::create('/_shop/checkout/place', 'POST', [
            'idempotency_key' => 'signed-in-place-' . uniqid(),
            'email' => $email,
            'addresses' => ['shipping' => ['country' => 'US']],
        ], [CartCookie::NAME => $cartToken]);
        $placeRequest->attributes->set('user', ['uuid' => $userUuid]);
        $place = $controller->place($placeRequest);
        self::assertSame(200, $place->getStatusCode(), (string) $place->getContent());

        $order = $this->connection()->table('commerce_orders')->where('email', '=', $email)->first();
        self::assertSame($userUuid, $order['user_uuid'], 'authenticated placement stamps ownership');
    }

    public function testAnonymousPlacementLeavesOwnershipNull(): void
    {
        $email = 'anon-buyer-' . uniqid() . '@example.com';
        $cartToken = $this->addToCart($this->seedVariant());

        $response = $this->handle(Request::create('/_shop/checkout/place', 'POST', [
            'idempotency_key' => 'anon-place-' . uniqid(),
            'email' => $email,
            'addresses' => ['shipping' => ['country' => 'US']],
        ], [CartCookie::NAME => $cartToken], [], $this->csrfServer()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $order = $this->connection()->table('commerce_orders')->where('email', '=', $email)->first();
        self::assertNull($order['user_uuid']);
    }

    // ------------------------------------------------------------------
    // helpers (ShopCheckoutTest's idioms)
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $body @param array<string,string> $extraServer */
    private function quotePage(?string $cartToken, array $body, array $extraServer = []): Response
    {
        $cookies = $cartToken !== null ? [CartCookie::NAME => $cartToken] : [];

        return $this->handle(Request::create(
            '/checkout',
            'POST',
            $body,
            $cookies,
            [],
            $this->csrfServer() + $extraServer,
        ));
    }

    private function seedUser(string $email): string
    {
        $uuid = $this->container()->get(UserRepository::class)->create([
            'username' => $email,
            'email' => $email,
            'password' => password_hash('sufficiently-long-secret', PASSWORD_BCRYPT),
            'status' => 'active',
        ]);
        $this->createdUserUuids[] = $uuid;

        return $uuid;
    }

    private function seedVariant(): string
    {
        $sku = 'checkout-page-sku-' . (++self::$seq);
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => strtolower($sku),
            'name' => 'Checkout page product',
            'status' => 'active',
            'type' => 'digital',
            'variants' => [[
                'sku' => $sku,
                'price' => 1000,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);

        return (string) $product['variants'][0]['uuid'];
    }

    private function addToCart(string $variantUuid): string
    {
        $response = $this->handle(Request::create(
            '/_shop/cart/add',
            'POST',
            ['variant_uuid' => $variantUuid, 'quantity' => 1],
            [],
            [],
            $this->csrfServer() + ['HTTP_ACCEPT' => 'application/json'],
        ));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === CartCookie::NAME) {
                return (string) $cookie->getValue();
            }
        }
        self::fail('expected a minted cart cookie');
    }

    /** @return array<string,string> */
    private function csrfServer(): array
    {
        return ['HTTP_ORIGIN' => $this->container()->get(CanonicalPublicOriginResolver::class)
            ->currentOrigin($this->appContext())];
    }

    private function assertNoStore(Response $response): void
    {
        $value = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $value);
        self::assertStringContainsString('private', $value);
    }

    private function truncateCommerceCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM thallo_commerce_checkout_attempts');
        $pdo->exec('DELETE FROM commerce_order_events');
        $pdo->exec('DELETE FROM commerce_order_lines');
        $pdo->exec('DELETE FROM commerce_orders');
        $pdo->exec('DELETE FROM commerce_sequences');
        $pdo->exec('DELETE FROM commerce_cart_lines');
        $pdo->exec('DELETE FROM commerce_carts');
        $pdo->exec('DELETE FROM commerce_variants');
        $pdo->exec('DELETE FROM commerce_products');
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }
}
