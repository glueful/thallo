<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptAuthority;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptContext;
use Glueful\Extensions\Commerce\Orders\CheckoutPresentation;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Console\PurgeCheckoutAttemptsCommand;
use Thallo\Commerce\Http\Shop\CartCookie;
use Thallo\Commerce\Http\Shop\GuestOrderCookie;
use Thallo\Commerce\Http\Shop\ShopCheckoutController;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Contracts\Delivery\StorefrontWishlistResolver;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;
use Thallo\Tenancy\System\SystemFlags;

use function config;

/**
 * Commerce-Slice-2 Task 10 (storefront-rendering spec §3/§6/§7/§8, verbatim): checkout
 * placement + durable attempt authority + payment presentation + guest-order cookie custody +
 * the confirmation/return/cancel routes. Two-connection PostgreSQL races live in the sibling
 * {@see ShopCheckoutRaceTest}. Mode (b) single-store, mirroring every other suite in this
 * directory.
 */
final class ShopCheckoutTest extends AppTestCase
{
    private const TENANT = 'checkouttnta';

    private static int $seq = 0;

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
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
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

    // ==================================================================
    // manual e2e: place -> pending_payment + manual VM -> confirmation pending
    // ==================================================================

    public function testManualPlacementReachesPendingPaymentWithManualVmThenConfirmationShowsPending(): void
    {
        $variant = $this->seedVariant();
        $cartToken = $this->addToCart($variant);

        $response = $this->place($cartToken, [
            'idempotency_key' => 'manual-e2e-key-1',
            'email' => 'manual-e2e@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $html = (string) $response->getContent();
        self::assertStringContainsString('Payment is collected manually', $html);
        self::assertStringContainsString('Payment pending', $html);

        $order = $this->orderByEmail('manual-e2e@example.com');
        self::assertSame('pending_payment', $order['status']);

        $cookie = $this->cookieValueFrom($response, GuestOrderCookie::NAME);
        self::assertNotNull($cookie);

        $confirm = $this->handle(Request::create(
            '/checkout/confirmation/' . $order['order_number'],
            'GET',
            [],
            [GuestOrderCookie::NAME => $cookie],
        ));
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('Payment pending', (string) $confirm->getContent());
        $this->assertNoStore($confirm);
    }

    // ==================================================================
    // storefront-v1 Task 6 (spec §5): EVERY shop page root emits the scope
    // ==================================================================

    public function testCheckoutAndConfirmationPageRootsCarryTheWishlistScope(): void
    {
        $scope = $this->container()->get(StorefrontWishlistResolver::class)->storageScope();
        self::assertNotNull($scope, 'precondition: the wishlist seam must answer a scope in this suite');

        $variant = $this->seedVariant();
        $cartToken = $this->addToCart($variant);

        $checkoutPage = $this->handle(Request::create('/checkout', 'GET', [], [CartCookie::NAME => $cartToken]));
        self::assertSame(200, $checkoutPage->getStatusCode());
        self::assertStringContainsString(
            'data-shop-scope="' . $scope . '"',
            (string) $checkoutPage->getContent(),
            'the checkout page root must carry the opaque wishlist scope',
        );

        $place = $this->place($cartToken, [
            'idempotency_key' => 'scope-key-1',
            'email' => 'scope-buyer@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ]);
        self::assertSame(200, $place->getStatusCode(), (string) $place->getContent());
        $order = $this->orderByEmail('scope-buyer@example.com');
        $cookie = $this->cookieValueFrom($place, GuestOrderCookie::NAME);
        self::assertNotNull($cookie);

        $confirm = $this->handle(Request::create(
            '/checkout/confirmation/' . $order['order_number'],
            'GET',
            [],
            [GuestOrderCookie::NAME => $cookie],
        ));
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString(
            'data-shop-scope="' . $scope . '"',
            (string) $confirm->getContent(),
            'the confirmation page root must carry the opaque wishlist scope',
        );
    }

    // ==================================================================
    // fake redirecting collector -> redirect VM, PRG 303, JSON carries it
    // ==================================================================

    public function testFakeRedirectingCollectorReturnsValidatedRedirectUrlOnJsonAndPrg303(): void
    {
        $variant = $this->seedVariant();
        $collector = $this->fakeRedirectingCollector('https://pay.example.test/session/abc123');
        $controller = $this->controllerWithCollector($collector);

        $jsonResponse = $controller->place($this->directRequest(
            $this->addToCart($variant),
            [
                'idempotency_key' => 'redirect-json-key',
                'email' => 'redirect-json@example.com',
                'addresses' => ['shipping' => ['country' => 'US']],
            ],
            ['HTTP_ACCEPT' => 'application/json'],
        ));
        self::assertSame(200, $jsonResponse->getStatusCode());
        $data = json_decode((string) $jsonResponse->getContent(), true);
        self::assertSame('redirect', $data['action']);
        self::assertSame('https://pay.example.test/session/abc123', $data['redirect_url']);

        $prgResponse = $controller->place($this->directRequest(
            $this->addToCart($variant),
            [
                'idempotency_key' => 'redirect-prg-key',
                'email' => 'redirect-prg@example.com',
                'addresses' => ['shipping' => ['country' => 'US']],
            ],
        ));
        self::assertSame(303, $prgResponse->getStatusCode());
        self::assertSame('https://pay.example.test/session/abc123', $prgResponse->headers->get('Location'));
    }

    // ==================================================================
    // return-before-webhook stays pending; the route mutates NOTHING
    // ==================================================================

    public function testReturnRouteNeverMutatesOrderStateBeforeWebhook(): void
    {
        $variant = $this->seedVariant();
        $cartToken = $this->addToCart($variant);
        $place = $this->place($cartToken, [
            'idempotency_key' => 'return-key-1',
            'email' => 'return-route@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ]);
        $order = $this->orderByEmail('return-route@example.com');
        $cookie = $this->cookieValueFrom($place, GuestOrderCookie::NAME);
        self::assertNotNull($cookie);

        $before = $this->connection()->table('commerce_orders')->where('uuid', '=', $order['uuid'])->first();
        $eventsBefore = $this->connection()->table('commerce_order_events')
            ->where('order_uuid', '=', $order['uuid'])->count();

        $return = $this->handle(Request::create(
            '/checkout/return/' . $order['order_number'],
            'GET',
            [],
            [GuestOrderCookie::NAME => $cookie],
        ));

        self::assertSame(303, $return->getStatusCode());
        self::assertSame(
            $this->container()->get(ShopUrlGenerator::class)->confirmation((string) $order['order_number']),
            $return->headers->get('Location'),
        );
        $this->assertNoStore($return);

        $after = $this->connection()->table('commerce_orders')->where('uuid', '=', $order['uuid'])->first();
        self::assertSame($before, $after, 'the return route must mutate NOTHING in the order row');
        self::assertSame(
            $eventsBefore,
            $this->connection()->table('commerce_order_events')->where('order_uuid', '=', $order['uuid'])->count(),
            'the return route must record no new order event',
        );

        $confirm = $this->handle(Request::create(
            '/checkout/confirmation/' . $order['order_number'],
            'GET',
            [],
            [GuestOrderCookie::NAME => $cookie],
        ));
        self::assertStringContainsString('Payment pending', (string) $confirm->getContent());
    }

    public function testCancelRouteAlsoMutatesNothingAndRedirectsToConfirmation(): void
    {
        $variant = $this->seedVariant();
        $cartToken = $this->addToCart($variant);
        $place = $this->place($cartToken, [
            'idempotency_key' => 'cancel-key-1',
            'email' => 'cancel-route@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ]);
        $order = $this->orderByEmail('cancel-route@example.com');
        $cookie = $this->cookieValueFrom($place, GuestOrderCookie::NAME);

        $before = $this->connection()->table('commerce_orders')->where('uuid', '=', $order['uuid'])->first();

        $cancel = $this->handle(Request::create(
            '/checkout/cancel/' . $order['order_number'],
            'GET',
            [],
            [GuestOrderCookie::NAME => $cookie],
        ));

        self::assertSame(303, $cancel->getStatusCode());
        self::assertSame(
            $this->container()->get(ShopUrlGenerator::class)->confirmation((string) $order['order_number']),
            $cancel->headers->get('Location'),
        );

        $after = $this->connection()->table('commerce_orders')->where('uuid', '=', $order['uuid'])->first();
        self::assertSame($before, $after, 'the cancel route must mutate NOTHING in the order row');
    }

    // ==================================================================
    // simulated webhook transition visible on confirmation refresh
    // ==================================================================

    public function testSimulatedWebhookTransitionVisibleOnConfirmationRefresh(): void
    {
        $variant = $this->seedVariant();
        $cartToken = $this->addToCart($variant);
        $place = $this->place($cartToken, [
            'idempotency_key' => 'webhook-key-1',
            'email' => 'webhook-buyer@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ]);
        $order = $this->orderByEmail('webhook-buyer@example.com');
        $cookie = $this->cookieValueFrom($place, GuestOrderCookie::NAME);

        $confirmBefore = $this->handle(Request::create(
            '/checkout/confirmation/' . $order['order_number'],
            'GET',
            [],
            [GuestOrderCookie::NAME => $cookie],
        ));
        self::assertStringContainsString('Payment pending', (string) $confirmBefore->getContent());

        // Drive Commerce's OWN payment-confirmation path directly (the shape a real provider
        // webhook drives) -- never anything this pack invents.
        $payable = new PayableReference(
            'commerce_order',
            (string) $order['uuid'],
            (int) $order['grand_total'],
            (string) $order['currency'],
        );
        $confirmation = new PaymentConfirmation(
            'paid',
            'fake-webhook-ref-1',
            (int) $order['grand_total'],
            (string) $order['currency'],
        );
        $this->container()->get(OrderPaymentConfirmationHandler::class)
            ->confirmed($this->appContext(), $payable, $confirmation);

        $refreshed = $this->connection()->table('commerce_orders')->where('uuid', '=', $order['uuid'])->first();
        self::assertSame('paid', $refreshed['status']);

        $confirmAfter = $this->handle(Request::create(
            '/checkout/confirmation/' . $order['order_number'],
            'GET',
            [],
            [GuestOrderCookie::NAME => $cookie],
        ));
        self::assertSame(200, $confirmAfter->getStatusCode());
        self::assertStringContainsString('Paid', (string) $confirmAfter->getContent());
    }

    // ==================================================================
    // duplicate place: same key+fingerprint -> same order_ref, ONE OrderPlaced,
    // credential re-delivered
    // ==================================================================

    public function testDuplicatePlaceSameKeyAndFingerprintReplaysOneOrder(): void
    {
        /** @var list<OrderPlaced> $captured */
        $captured = [];
        $this->container()->get(EventService::class)->addListener(
            OrderPlaced::class,
            function (OrderPlaced $e) use (&$captured): void {
                $captured[] = $e;
            },
        );

        $variant = $this->seedVariant();
        $body = [
            'idempotency_key' => 'dup-key-1',
            'email' => 'dup-buyer@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ];

        $first = $this->place($this->addToCart($variant), $body, ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $first->getStatusCode());
        $firstData = json_decode((string) $first->getContent(), true);
        $firstCookie = $this->cookieValueFrom($first, GuestOrderCookie::NAME);
        self::assertNotNull($firstCookie);

        // Same key + IDENTICAL payload (same fingerprint) -- no cart cookie at all this time.
        // Commerce's own replay path never even reaches cart validation.
        $second = $this->place(null, $body, ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $second->getStatusCode());
        $secondData = json_decode((string) $second->getContent(), true);
        $secondCookie = $this->cookieValueFrom($second, GuestOrderCookie::NAME);
        self::assertNotNull($secondCookie);

        self::assertSame($firstData['order_ref'], $secondData['order_ref']);
        self::assertSame(1, $this->connection()->table('commerce_orders')->count());

        $ownEvents = array_values(array_filter(
            $captured,
            fn (OrderPlaced $e): bool => (string) $e->order['order_number'] === $firstData['order_ref'],
        ));
        self::assertCount(1, $ownEvents, 'exactly ONE OrderPlaced for this order, even after the duplicate placement');

        // Credential re-delivered: BOTH cookies unlock the SAME order via the confirmation route.
        foreach ([$firstCookie, $secondCookie] as $cookie) {
            $confirm = $this->handle(Request::create(
                '/checkout/confirmation/' . $firstData['order_ref'],
                'GET',
                [],
                [GuestOrderCookie::NAME => $cookie],
            ));
            self::assertSame(200, $confirm->getStatusCode());
        }
    }

    public function testDifferentFingerprintSameKeyReturns409(): void
    {
        $variant = $this->seedVariant();

        $first = $this->place($this->addToCart($variant), [
            'idempotency_key' => 'mismatch-key-1',
            'email' => 'mismatch-a@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $first->getStatusCode());

        $second = $this->place($this->addToCart($variant), [
            'idempotency_key' => 'mismatch-key-1',
            'email' => 'mismatch-b@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(409, $second->getStatusCode());

        self::assertSame(1, $this->connection()->table('commerce_orders')->count());
    }

    public function testAPathologicallyLongIdempotencyKeyIsNormalizedNotA500(): void
    {
        // Review Minor: a >191-char client key must never overflow the
        // idempotency_key column into an uncaught PDOException/500 on the
        // money path. The key is hashed to a fixed 64-char width, so an
        // over-long key places cleanly AND a retry with the same over-long
        // key + payload still replays to the same order.
        $variant = $this->seedVariant();
        $longKey = str_repeat('k', 5000);
        $body = [
            'idempotency_key' => $longKey,
            'email' => 'long-key@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ];

        $first = $this->place($this->addToCart($variant), $body, ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $first->getStatusCode());
        $firstRef = json_decode((string) $first->getContent(), true)['order_ref'];

        $second = $this->place(null, $body, ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $second->getStatusCode());
        self::assertSame($firstRef, json_decode((string) $second->getContent(), true)['order_ref']);
        self::assertSame(1, $this->connection()->table('commerce_orders')->count());
    }

    // ==================================================================
    // guest cookie: capped at 5 oldest-evicted, encrypted, expiry honored, ownership 404s
    // ==================================================================

    public function testGuestCookieCapsAtFiveOldestEvictedAndEncryptsRawTokenOutOfBytes(): void
    {
        $cookie = $this->container()->get(GuestOrderCookie::class);
        $context = $this->appContext();

        $carriedRequest = Request::create('/', 'GET');
        foreach (range(1, 6) as $i) {
            $response = new Response();
            $cookie->remember($response, $carriedRequest, $context, self::TENANT, "ref-{$i}", "token-{$i}");
            $value = $this->cookieValueFrom($response, GuestOrderCookie::NAME);
            self::assertNotNull($value);
            foreach (range(1, $i) as $j) {
                self::assertStringNotContainsString(
                    "token-{$j}",
                    $value,
                    'the raw guest token must never appear in the cookie bytes',
                );
            }
            $carriedRequest = Request::create('/', 'GET', [], [GuestOrderCookie::NAME => $value]);
        }

        $final = $cookie->read($carriedRequest, self::TENANT);
        self::assertCount(5, $final, 'the guest cookie must never carry more than 5 entries');
        self::assertSame(['ref-2', 'ref-3', 'ref-4', 'ref-5', 'ref-6'], array_column($final, 'ref'));
        self::assertSame(['token-2', 'token-3', 'token-4', 'token-5', 'token-6'], array_column($final, 'token'));
    }

    public function testGuestCookieExpiryHonoredWithinTheConfiguredWindow(): void
    {
        $cookie = $this->container()->get(GuestOrderCookie::class);
        $before = time();
        $response = new Response();
        $cookie->remember(
            $response,
            Request::create('/', 'GET'),
            $this->appContext(),
            self::TENANT,
            'expiry-ref',
            'expiry-token',
        );

        $set = null;
        foreach ($response->headers->getCookies() as $c) {
            if ($c->getName() === GuestOrderCookie::NAME) {
                $set = $c;
                break;
            }
        }
        self::assertNotNull($set);
        self::assertTrue($set->isSecure());
        self::assertTrue($set->isHttpOnly());
        self::assertSame('lax', $set->getSameSite());
        self::assertFalse($set->isRaw());

        $days = (int) config($this->appContext(), 'thallo-commerce.guest_confirmation_days', 30);
        self::assertGreaterThanOrEqual(1, $days);
        self::assertLessThanOrEqual(90, $days);
        $expected = $before + $days * 86400;
        self::assertGreaterThanOrEqual($expected - 5, $set->getExpiresTime());
        self::assertLessThanOrEqual($expected + 60, $set->getExpiresTime());
    }

    public function testWrongOrAbsentCredentialReturns404OnConfirmationReturnAndCancel(): void
    {
        $variant = $this->seedVariant();
        $cartToken = $this->addToCart($variant);
        $place = $this->place($cartToken, [
            'idempotency_key' => 'ownership-key-1',
            'email' => 'ownership@example.com',
            'addresses' => ['shipping' => ['country' => 'US']],
        ]);
        $order = $this->orderByEmail('ownership@example.com');
        $ref = (string) $order['order_number'];

        // A forged cookie carrying the RIGHT ref but the WRONG token.
        $forged = new Response();
        $this->container()->get(GuestOrderCookie::class)->remember(
            $forged,
            Request::create('/', 'GET'),
            $this->appContext(),
            self::TENANT,
            $ref,
            'not-the-real-guest-token',
        );
        $forgedValue = $this->cookieValueFrom($forged, GuestOrderCookie::NAME);
        self::assertNotNull($forgedValue);

        foreach (['/checkout/confirmation/', '/checkout/return/', '/checkout/cancel/'] as $prefix) {
            $noCookie = $this->handle(Request::create($prefix . $ref, 'GET'));
            self::assertSame(404, $noCookie->getStatusCode(), "{$prefix}{$ref} without a cookie must 404");

            $malformed = $this->handle(Request::create(
                $prefix . $ref,
                'GET',
                [],
                [GuestOrderCookie::NAME => 'not-an-encrypted-value'],
            ));
            self::assertSame(404, $malformed->getStatusCode(), "{$prefix}{$ref} with a malformed cookie must 404");

            $wrongToken = $this->handle(Request::create(
                $prefix . $ref,
                'GET',
                [],
                [GuestOrderCookie::NAME => $forgedValue],
            ));
            self::assertSame(404, $wrongToken->getStatusCode(), "{$prefix}{$ref} with the wrong token must 404");
        }
    }

    // ==================================================================
    // post-commit payment initiation: a fake collector on a SECOND connection sees the
    // COMMITTED order (proves provider I/O runs strictly after the placement transaction commits)
    // ==================================================================

    public function testFakeCollectorReadingOnASecondConnectionSeesTheCommittedOrder(): void
    {
        $variant = $this->seedVariant();
        $reader = $this->secondConnection();

        $collector = new class ($reader) implements PaymentCollector {
            public ?array $seenRow = null;

            public function __construct(private readonly Connection $reader)
            {
            }

            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                $this->seenRow = $this->reader->table('commerce_orders')
                    ->where('uuid', '=', $payable->id)
                    ->first();

                return new PaymentInitiation('fake', 'ok', ['reference' => 'post-commit-ref']);
            }
        };

        $controller = $this->controllerWithCollector($collector);
        $response = $controller->place($this->directRequest(
            $this->addToCart($variant),
            [
                'idempotency_key' => 'post-commit-key-1',
                'email' => 'post-commit@example.com',
                'addresses' => ['shipping' => ['country' => 'US']],
            ],
        ));

        self::assertContains($response->getStatusCode(), [200, 303]);
        self::assertNotNull($collector->seenRow, 'the collector must have been invoked at all');
        self::assertSame('pending_payment', $collector->seenRow['status']);
        self::assertSame(
            (string) $this->orderByEmail('post-commit@example.com')['uuid'],
            (string) $collector->seenRow['uuid'],
        );
    }

    // ==================================================================
    // crash-after-commit-before-initiation, repaired by replay
    // ==================================================================

    public function testCrashAfterCommitBeforeInitiationIsRepairedByReplay(): void
    {
        // Manually construct EXACTLY what a process crash between the placement transaction's
        // commit and CheckoutService::initiatePayment() would leave behind: a completed attempt
        // + a committed pending_payment order, with NO payment_initiated/payment_init_failed
        // event at all -- initiatePayment() genuinely never ran.
        $orderUuid = Utils::generateNanoID();
        $orderRef = 'ORD-CRASH01';
        $guestToken = TokenHasher::generate();
        $ctx = new CheckoutAttemptContext('crash-key-1', 'crash-fingerprint-1');

        $this->container()->get(OrderRepository::class)->insert($this->appContext(), [
            'uuid' => $orderUuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => $orderRef,
            'status' => 'pending_payment',
            'email' => 'crash-recovery@example.com',
            'user_uuid' => null,
            'guest_token_hash' => $guestToken['hash'],
            'currency' => 'USD',
            'subtotal' => 1000,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'discount_code' => null,
            'shipping_method' => null,
            'addresses' => ['shipping' => ['country' => 'US']],
            'placed_at' => gmdate('Y-m-d H:i:s'),
            'marketplace_partitioned' => false,
        ]);

        $authority = $this->container()->get(CheckoutAttemptAuthority::class);
        $replay = $authority->claimOrReplay($this->appContext(), self::TENANT, $ctx);
        self::assertNull($replay, 'a brand-new key must claim, not replay');
        $authority->complete($this->appContext(), self::TENANT, $ctx, $orderUuid, $orderRef, $guestToken['raw']);

        self::assertSame(
            0,
            $this->connection()->table('commerce_order_events')->where('order_uuid', '=', $orderUuid)->count(),
            'the pre-crash state must carry NO payment-initiation event at all',
        );

        // "The retry after the crash": a real placeOrder() call, SAME key+fingerprint, with a
        // real (non-throwing) collector this time -- proving the replay repairs the missed call.
        $spyCollector = new class implements PaymentCollector {
            public int $calls = 0;

            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                $this->calls++;

                return new PaymentInitiation('fake', 'ok', ['reference' => 'repaired-ref']);
            }
        };
        $checkout = $this->checkoutServiceWithCollector($spyCollector);
        $result = $checkout->placeOrder(
            $this->appContext(),
            'a-cart-token-never-minted',
            ['email' => 'crash-recovery@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US']],
            null,
            $ctx,
        );

        self::assertSame($orderUuid, $result['order']['uuid']);
        self::assertSame($guestToken['raw'], $result['guest_token']);
        self::assertSame(1, $spyCollector->calls, 'replay must re-initiate payment for the SAME payable exactly once');
        self::assertSame(
            1,
            $this->connection()->table('commerce_order_events')
                ->where('order_uuid', '=', $orderUuid)
                ->where('type', '=', 'payment_initiated')
                ->count(),
        );
    }

    // ==================================================================
    // purge command
    // ==================================================================

    public function testPurgeCommandRespectsTheRetentionWindow(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $recent = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
        $this->insertAttemptRow('purge-old-1', $old);
        $this->insertAttemptRow('purge-recent-1', $recent);

        $tester = new CommandTester(new PurgeCheckoutAttemptsCommand($this->container(), $this->appContext()));
        self::assertSame(0, $tester->execute([], ['interactive' => false]));

        self::assertNull($this->attemptRow('purge-old-1'), 'a row older than the window must be purged');
        self::assertNotNull($this->attemptRow('purge-recent-1'), 'a row within the window must survive');
    }

    public function testPurgeCommandTenantOptionScopesTheSweep(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $this->insertAttemptRow('purge-tenant-a', $old, self::TENANT);
        $this->insertAttemptRow('purge-tenant-b', $old, 'othertenant1');

        $tester = new CommandTester(new PurgeCheckoutAttemptsCommand($this->container(), $this->appContext()));
        self::assertSame(0, $tester->execute(['--tenant' => self::TENANT], ['interactive' => false]));

        self::assertNull($this->attemptRow('purge-tenant-a'));
        self::assertNotNull($this->attemptRow('purge-tenant-b'), 'a different tenant must be untouched');

        // Cleanup: the "othertenant1" row would otherwise leak into a later test's global count.
        $this->connection()->table('thallo_commerce_checkout_attempts')
            ->where('idempotency_key', '=', 'purge-tenant-b')->delete();
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function insertAttemptRow(string $key, string $createdAt, string $tenant = self::TENANT): void
    {
        $this->connection()->table('thallo_commerce_checkout_attempts')->insert([
            'tenant_uuid' => $tenant,
            'idempotency_key' => $key,
            'request_fingerprint' => str_repeat('a', 64),
            'status' => 'completed',
            'order_uuid' => null,
            'order_ref' => null,
            'guest_credential_ciphertext' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function attemptRow(string $key): ?array
    {
        return $this->connection()->table('thallo_commerce_checkout_attempts')
            ->where('idempotency_key', '=', $key)->first();
    }

    /** @return array<string,mixed> */
    private function orderByEmail(string $email): array
    {
        $row = $this->connection()->table('commerce_orders')->where('email', '=', $email)->first();
        self::assertNotNull($row, "expected an order for {$email}");

        return $row;
    }

    private function expectedOrigin(): string
    {
        return $this->container()->get(CanonicalPublicOriginResolver::class)->currentOrigin($this->appContext());
    }

    /** @return array<string,string> */
    private function csrfServer(): array
    {
        return ['HTTP_ORIGIN' => $this->expectedOrigin()];
    }

    private function seedVariant(): string
    {
        $sku = 'checkout-sku-' . (++self::$seq);
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => strtolower($sku),
            'name' => 'Checkout test product',
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
        $token = $this->cookieValueFrom($response, CartCookie::NAME);
        self::assertNotNull($token, 'expected a minted cart cookie');

        return $token;
    }

    /** @param array<string,mixed> $body @param array<string,string> $extraServer */
    private function place(?string $cartToken, array $body, array $extraServer = []): Response
    {
        $cookies = $cartToken !== null ? [CartCookie::NAME => $cartToken] : [];

        return $this->handle(Request::create(
            '/_shop/checkout/place',
            'POST',
            $body,
            $cookies,
            [],
            $this->csrfServer() + $extraServer,
        ));
    }

    /** @param array<string,mixed> $body @param array<string,string> $extraServer */
    private function directRequest(?string $cartToken, array $body, array $extraServer = []): Request
    {
        $cookies = $cartToken !== null ? [CartCookie::NAME => $cartToken] : [];

        return Request::create('/_shop/checkout/place', 'POST', $body, $cookies, [], $extraServer);
    }

    private function cookieValueFrom(Response $response, string $name): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return (string) $cookie->getValue();
            }
        }

        return null;
    }

    /** Symfony's ResponseHeaderBag recomputes/reorders Cache-Control directives -- assert content, not exact order. */
    private function assertNoStore(Response $response): void
    {
        $value = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $value);
        self::assertStringContainsString('private', $value);
    }

    private function fakeRedirectingCollector(string $url): PaymentCollector
    {
        return new class ($url) implements PaymentCollector {
            public function __construct(private readonly string $url)
            {
            }

            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                return new PaymentInitiation('fake-gateway', 'ok', ['checkout_url' => $this->url]);
            }
        };
    }

    /**
     * Direct controller instantiation (test-only, mirrors
     * {@see \Glueful\Extensions\Commerce\Tests\Integration\Orders\CheckoutAttemptTest}'s own
     * `checkout()` helper): the shared, container-bound `CheckoutService`/`ShopCheckoutController`
     * always use `ManualPaymentCollector` (nothing in this app binds `PaymentCollector::class`), so
     * a test that needs a DIFFERENT collector builds its own controller backed by a manually
     * reconstructed `CheckoutService` -- every OTHER collaborator (cart, presentation, tenant
     * resolution, order repository, URLs, Twig) is the REAL, container-resolved instance, so this
     * still exercises the real `PackCheckoutAttemptAuthority`/render pipeline/guest-cookie custody.
     */
    private function controllerWithCollector(PaymentCollector $collector): ShopCheckoutController
    {
        $c = $this->container();

        return new ShopCheckoutController(
            $this->appContext(),
            $c->get(CartService::class),
            $c->get(CartCookie::class),
            $c->get(GuestOrderCookie::class),
            $this->checkoutServiceWithCollector($collector),
            $c->get(CheckoutPresentation::class),
            $c->get(CommerceTenantResolution::class),
            $c->get(OrderRepository::class),
            $c->get(ShopUrlGenerator::class),
            $c->get(TwigFactory::class),
            $c->get(RenderContextExtension::class),
        );
    }

    private function checkoutServiceWithCollector(PaymentCollector $collector): CheckoutService
    {
        $c = $this->container();
        $authority = $c->has(CheckoutAttemptAuthority::class) ? $c->get(CheckoutAttemptAuthority::class) : null;

        return new CheckoutService(
            $c->get(CartService::class),
            $c->get(DiscountRepository::class),
            $c->get(DiscountService::class),
            $c->get(StockRepository::class),
            $c->get(PricingEngine::class),
            $c->get(ShippingRateProvider::class),
            $c->get(TaxCalculator::class),
            $c->get(OrderNumberGenerator::class),
            $c->get(OrderRepository::class),
            $c->get(DownloadRepository::class),
            $collector,
            CommerceServiceProvider::makeTenantResolver($c, $this->appContext()),
            attemptAuthority: $authority,
        );
    }

    private function secondConnection(): Connection
    {
        return new Connection([
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'app_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ]);
    }
}
