<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Http\Shop\ShopPaymentLinkController;
use Thallo\Commerce\Http\Shop\ShopCsrfGuard;
use Thallo\Commerce\Http\Shop\ShopPaymentLinkHeaders;
use Thallo\Commerce\Payments\PaymentLinkReturnSigner;
use Thallo\Commerce\Shop\Contribution\ShopReservedPathContributor;
use Thallo\Commerce\Shop\ShopPageCache;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * Task 11 (payment-links spec §2.3): the PUBLIC payment-link surface — `GET /checkout/pay/{token}`,
 * `POST /checkout/pay/{token}/initiate`, and the two signed, NON-AUTHORIZING receipt handles
 * `GET /checkout/pay/return|cancel/{linkUuid}/{signature}`.
 *
 * The suite runs in the harness's default sentinel tenancy mode (tenant `''`), exactly like
 * {@see AdminOrderPaymentsTest}, so every seeded order/link uses the tenant Commerce actually
 * resolves; the cross-tenant row is deliberately seeded under a DIFFERENT tenant so its
 * perfectly-valid token must still collapse into the one generic 404.
 *
 * Two drivers, mirroring this directory's established convention:
 *  - route-level posture, the landing state matrix, headers, the 404 triple and the receipts run
 *    through the REAL kernel;
 *  - the initiation outcomes (a live gateway 303, `manual`, a throwing collector, an untyped
 *    throwable) construct {@see ShopPaymentLinkController} DIRECTLY over a
 *    {@see PaymentLinkService} built with a stub {@see PaymentCollector} and an HTTPS
 *    return-URL provider — the harness's own canonical origin is `http://localhost`, so a
 *    container-resolved provider can only ever answer the (equally-valid, separately tested)
 *    unavailable state.
 */
final class ShopPaymentLinkTest extends AppTestCase
{
    private const OTHER_TENANT = 'plinkothert';

    /** A well-formed token that was never minted. */
    private const UNKNOWN_TOKEN = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12';

    private static int $seq = 0;

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM commerce_payment_links');
        $pdo->exec('DELETE FROM commerce_order_events');
        $pdo->exec('DELETE FROM commerce_order_lines');
        $pdo->exec('DELETE FROM commerce_orders');
        parent::tearDown();
    }

    // ==================================================================
    // Route posture
    // ==================================================================

    public function testTheFourRoutesAreRegisteredWithTheTenantPairHeadersAndIpRateLimits(): void
    {
        $routes = [
            ['GET', '/checkout/pay/{token}'],
            ['POST', '/checkout/pay/{token}/initiate'],
            ['GET', '/checkout/pay/return/{linkUuid}/{signature}'],
            ['GET', '/checkout/pay/cancel/{linkUuid}/{signature}'],
        ];

        foreach ($routes as [$method, $path]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} must be registered");
            $middleware = (array) ($route['middleware'] ?? []);
            self::assertContains('tenant_profile:public', $middleware, "{$method} {$path}");
            self::assertContains('tenant_bootstrap', $middleware, "{$method} {$path}");
            // The header stamper wraps EVERY response, including the ones middleware below it
            // produces (a CSRF 403, a rate-limit 429) — spec §2.3 says "all responses".
            self::assertContains(ShopPaymentLinkHeaders::class, $middleware, "{$method} {$path}");
            // The engine's per-link ceiling cannot see an UNKNOWN token, so the IP limit on
            // these routes is the only defense against token enumeration.
            self::assertContains('rate_limit', $middleware, "{$method} {$path}");
            // Never page-cached: these are per-bearer, no-store responses.
            self::assertNotContains(ShopPageCache::class, $middleware, "{$method} {$path}");
            // The header stamper must sit OUTSIDE the rate limiter (and, on the POST, outside the
            // CSRF guard) — that ordering is what makes "headers on every response" structural
            // rather than a list of places somebody remembered.
            self::assertLessThan(
                array_search('rate_limit', $middleware, true),
                array_search(ShopPaymentLinkHeaders::class, $middleware, true),
                "{$method} {$path}: headers must wrap the rate limiter",
            );
        }

        // The POST carries the STOCK anonymous-checkout provenance policy — nothing widened
        // for this surface. `Referrer-Policy: strict-origin` is what makes that sufficient
        // (a same-origin form POST still sends a real Origin), and the bespoke
        // ShopPaymentLinkCsrfGuard wrapper that `no-referrer` used to require is deleted.
        $initiateMiddleware = (array) ($this->findRoute('POST', '/checkout/pay/{token}/initiate')['middleware'] ?? []);
        self::assertContains(ShopCsrfGuard::class, $initiateMiddleware);
        // Deleted, not merely unused. Asserted on the FILE rather than class_exists(), which a
        // stale composer classmap can answer true for long after the source is gone.
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/packages/thallo-commerce/src/Http/Shop/ShopPaymentLinkCsrfGuard.php',
            'the payment-link CSRF wrapper must be gone, not merely unused',
        );
        foreach ($initiateMiddleware as $entry) {
            self::assertStringNotContainsString('ShopPaymentLinkCsrfGuard', (string) $entry);
        }
    }

    public function testEveryPaymentLinkRouteCarriesAnIpKeyedRateLimitCeiling(): void
    {
        foreach ($this->paymentLinkRouteObjects() as $path => $route) {
            $configs = $route->getRateLimitConfig();
            self::assertNotSame([], $configs, "{$path} must declare a rate-limit ceiling");
            foreach ($configs as $config) {
                self::assertSame('ip', $config['by'] ?? null, "{$path} must be limited BY IP");
                self::assertGreaterThan(0, (int) ($config['attempts'] ?? 0), $path);
            }
        }
    }

    public function testTheHeaderStamperCoversAnInnerRateLimit429AndOverridesAWeakerPolicy(): void
    {
        // The 429 is produced by the framework's rate limiter, BELOW this middleware and above
        // the controller — the one payment-link status no request-driven test can reach without
        // burning a shared IP bucket for every other test in the process. Driving the middleware
        // directly pins the same guarantee: whatever the inner chain returns leaves with these
        // three headers, and a weaker Cache-Control set inside is overwritten, not merged.
        $inner = new Response('{"error":"rate limited"}', 429, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=60',
        ]);

        $response = $this->container()->get(ShopPaymentLinkHeaders::class)
            ->handle(Request::create('/checkout/pay/' . self::UNKNOWN_TOKEN . '/initiate', 'POST'), fn () => $inner);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(429, $response->getStatusCode());
        $this->assertPaymentLinkHeaders($response);
    }

    public function testThePaymentLinkPathsSitUnderTheAlreadyReservedCheckoutPrefix(): void
    {
        $contributor = new ShopReservedPathContributor('shop');

        self::assertContains('checkout', $contributor->reservedPrefixes());
    }

    public function testTheGuestCookieConfirmationRoutesAreUntouchedByThisSurface(): void
    {
        // Byte-identical guarantee, pinned structurally: the pre-existing checkout return/cancel/
        // confirmation routes still resolve to ShopCheckoutController — the payment-link paths
        // never shadow them.
        foreach (['/checkout/return/{ref}', '/checkout/cancel/{ref}', '/checkout/confirmation/{ref}'] as $path) {
            $route = $this->findRoute('GET', $path);
            self::assertNotNull($route, "{$path} must still be registered");
            $handler = (array) ($route['handler'] ?? []);
            self::assertSame(\Thallo\Commerce\Http\Shop\ShopCheckoutController::class, $handler[0] ?? null);
        }
    }

    // ==================================================================
    // Landing state matrix
    // ==================================================================

    public function testActiveLinkRendersTheSummaryAndANoJsPayForm(): void
    {
        [$token, ] = $this->mintedLink();

        $response = $this->handle(Request::create('/checkout/pay/' . $token));

        self::assertSame(200, $response->getStatusCode());
        $this->assertPaymentLinkHeaders($response);
        $html = (string) $response->getContent();
        self::assertStringContainsString('data-payment-link-state="active"', $html);
        self::assertStringContainsString('Widget A', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('action="/checkout/pay/' . $token . '/initiate"', $html);
        // A no-JS form: a real submit button, not a script-driven handler.
        self::assertStringContainsString('type="submit"', $html);
    }

    public function testTheTokenAppearsOnlyOnceInTheLandingMarkupAsTheFormTarget(): void
    {
        [$token, ] = $this->mintedLink();

        $html = (string) $this->handle(Request::create('/checkout/pay/' . $token))->getContent();

        // The bearer credential's ONE legitimate appearance is the form's own action; nothing
        // else (no canonical link, no nav path echo, no hidden field) may repeat it.
        self::assertSame(1, substr_count($html, $token));
    }

    public function testTheLandingPageLoadsNoThirdPartyAssets(): void
    {
        [$token, ] = $this->mintedLink();

        $html = (string) $this->handle(Request::create('/checkout/pay/' . $token))->getContent();

        self::assertNotSame('', $html);
        preg_match_all('/(?:src|href)="([^"]*)"/i', $html, $matches);
        $foreign = [];
        foreach ($matches[1] as $url) {
            if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
                continue; // relative/anchor — same origin by construction
            }
            if (!str_starts_with($url, $this->expectedOrigin())) {
                $foreign[] = $url;
            }
        }
        self::assertSame([], $foreign, 'payment-link pages must load zero third-party assets');
    }

    public function testPaidOrderRendersTheThankYouStateWithNoPayForm(): void
    {
        [$token, $order] = $this->mintedLink();
        $this->connection()->table('commerce_orders')
            ->where('uuid', '=', $order['uuid'])->update(['status' => 'paid']);

        $response = $this->handle(Request::create('/checkout/pay/' . $token));

        self::assertSame(200, $response->getStatusCode());
        $this->assertPaymentLinkHeaders($response);
        $html = (string) $response->getContent();
        self::assertStringContainsString('data-payment-link-state="paid"', $html);
        self::assertStringNotContainsString('/initiate"', $html);
    }

    public function testRevokedLinkRendersTheInvalidStateWithNoCommercialContent(): void
    {
        [$token, $order] = $this->mintedLink();
        $this->paymentLinks()->revoke($this->appContext(), '', $order['uuid'], 'actorrevoker');

        $response = $this->handle(Request::create('/checkout/pay/' . $token));

        self::assertSame(410, $response->getStatusCode());
        $this->assertPaymentLinkHeaders($response);
        $html = (string) $response->getContent();
        self::assertStringContainsString('data-payment-link-state="invalid"', $html);
        self::assertStringContainsString('no longer valid', $html);
        // The engine hands back a CONTENT-REDACTED view for a revoked link; the page must not
        // invent any of it back.
        self::assertStringNotContainsString($order['number'], $html);
        self::assertStringNotContainsString('Widget A', $html);
        self::assertStringNotContainsString('/initiate"', $html);
    }

    public function testExpiredLinkRendersTheInvalidState(): void
    {
        [$token, ] = $this->mintedLink();
        $this->connection()->table('commerce_payment_links')
            ->where('status', '=', 'active')->update(['expires_at' => '2020-01-01 00:00:00']);

        $response = $this->handle(Request::create('/checkout/pay/' . $token));

        self::assertSame(410, $response->getStatusCode());
        $this->assertPaymentLinkHeaders($response);
        self::assertStringContainsString('data-payment-link-state="invalid"', (string) $response->getContent());
        self::assertStringContainsString('no longer valid', (string) $response->getContent());
    }

    public function testCanceledOrderRendersTheInvalidState(): void
    {
        [$token, $order] = $this->mintedLink();
        $this->connection()->table('commerce_orders')
            ->where('uuid', '=', $order['uuid'])->update(['status' => 'canceled']);

        $response = $this->handle(Request::create('/checkout/pay/' . $token));

        self::assertSame(410, $response->getStatusCode());
        $this->assertPaymentLinkHeaders($response);
        self::assertStringContainsString('data-payment-link-state="invalid"', (string) $response->getContent());
        self::assertStringNotContainsString('/initiate"', (string) $response->getContent());
    }

    // ==================================================================
    // The generic 404 triple
    // ==================================================================

    public function testUnknownMalformedAndCrossTenantTokensAreOneByteIdentical404(): void
    {
        $foreign = $this->mintedLink(self::OTHER_TENANT)[0];

        $unknown = $this->handle(Request::create('/checkout/pay/' . self::UNKNOWN_TOKEN));
        $malformed = $this->handle(Request::create('/checkout/pay/not-a-token'));
        $crossTenant = $this->handle(Request::create('/checkout/pay/' . $foreign));

        foreach ([$unknown, $malformed, $crossTenant] as $response) {
            self::assertSame(404, $response->getStatusCode());
            $this->assertPaymentLinkHeaders($response);
        }
        self::assertSame((string) $unknown->getContent(), (string) $malformed->getContent());
        self::assertSame((string) $unknown->getContent(), (string) $crossTenant->getContent());
    }

    public function testAnUppercaseTokenIsMalformedAndNeverReachesTheEngine(): void
    {
        [$token, ] = $this->mintedLink();

        $response = $this->handle(Request::create('/checkout/pay/' . strtoupper($token)));

        self::assertSame(404, $response->getStatusCode());
    }

    // ==================================================================
    // POST initiate
    // ==================================================================

    public function testInitiateRedirectsWith303OnlyToARevalidatedAbsoluteHttpsUrl(): void
    {
        [$token, ] = $this->mintedLink();
        $controller = $this->controllerWith($this->collectorReturning('ok', [
            'checkout_url' => 'https://psp.example/checkout/abc',
        ]));

        $response = $controller->initiate($this->postRequest($token), $token);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://psp.example/checkout/abc', $response->headers->get('Location'));
        $this->assertPaymentLinkHeaders($response);
    }

    public function testAManualCollectorRendersTheUnavailableStateAndNeverALocation(): void
    {
        [$token, ] = $this->mintedLink();
        $controller = $this->controllerWith($this->collectorReturning('manual', ['instructions' => 'Pay by bank']));

        $response = $controller->initiate($this->postRequest($token), $token);

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        $this->assertPaymentLinkHeaders($response);
        $html = (string) $response->getContent();
        self::assertStringContainsString('data-payment-link-state="unavailable"', $html);
        self::assertStringNotContainsString('Pay by bank', $html);
    }

    public function testAThrowingCollectorRendersTheUnavailableStateWithNoProviderText(): void
    {
        [$token, ] = $this->mintedLink();
        $controller = $this->controllerWith(new class implements PaymentCollector {
            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                throw new \RuntimeException('gateway-secret-detail-42');
            }
        });

        $response = $controller->initiate($this->postRequest($token), $token);

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        self::assertStringNotContainsString('gateway-secret-detail-42', (string) $response->getContent());
    }

    public function testAnUntypedThrowableFromTheEngineNeverLeaksAndNeverRedirects(): void
    {
        // Calling initiation inside an ambient transaction is the engine's own documented
        // LogicException guard — the canonical UNTYPED throwable a controller must survive.
        [$token, ] = $this->mintedLink();
        $controller = $this->controllerWith($this->collectorReturning('ok', [
            'checkout_url' => 'https://psp.example/checkout/abc',
        ]));

        $response = $this->connection()->transaction(
            fn (): Response => $controller->initiate($this->postRequest($token), $token)
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        self::assertStringContainsString('data-payment-link-state="unavailable"', (string) $response->getContent());
        self::assertStringNotContainsString('ransaction', (string) $response->getContent());
    }

    // ==================================================================
    // Landing copy honesty (final-review Important 5)
    // ==================================================================

    /**
     * The retired single `unavailable` message promised BOTH "please try again later" AND
     * "Nothing has been charged". On a manual-collection store the first is permanently false;
     * on a driver that threw mid-initiation the second is unknowable. Each sub-state now carries
     * copy that is true for it, and NO 503 sub-state asserts what the provider did with the
     * money.
     *
     * @dataProvider unavailableCopyVariants
     * @param list<string> $mustContain
     * @param list<string> $mustNotContain
     */
    public function testEachUnavailableSubStateRendersItsOwnHonestCopy(
        string $errorCode,
        string $expectedReason,
        int $expectedStatus,
        array $mustContain,
        array $mustNotContain
    ): void {
        [$state, $reason, $status] = ShopPaymentLinkController::stateForErrorCode($errorCode);
        self::assertSame('unavailable', $state, $errorCode);
        self::assertSame($expectedReason, $reason, $errorCode);
        self::assertSame($expectedStatus, $status, $errorCode);

        $html = $this->renderUnavailable($reason);
        self::assertStringContainsString('data-payment-link-reason="' . $reason . '"', $html);
        foreach ($mustContain as $needle) {
            self::assertStringContainsString($needle, $html, "{$errorCode}: {$needle}");
        }
        foreach ($mustNotContain as $needle) {
            self::assertStringNotContainsString($needle, $html, "{$errorCode} must not claim: {$needle}");
        }
    }

    /** @return array<string, array{0:string,1:string,2:int,3:list<string>,4:list<string>}> */
    public static function unavailableCopyVariants(): array
    {
        // "Nothing has been charged" is gone from EVERY branch; "try again" survives only where
        // it is honest advice (the rate limit, and the two genuinely-unknown states).
        $noChargeClaim = ['Nothing has been charged', 'nothing has been charged'];

        return [
            'manual collection is permanently futile' => [
                'checkout_manual',
                'manual',
                503,
                ['Online payment isn', 'contact the merchant'],
                [...$noChargeClaim, 'try again'],
            ],
            'a missing return URL is a store-side fault' => [
                'return_url_unavailable',
                'misconfigured',
                503,
                ['contact the merchant', 'Retrying won'],
                $noChargeClaim,
            ],
            'a missing checkout URL is the same fault' => [
                'checkout_url_missing',
                'misconfigured',
                503,
                ['contact the merchant'],
                $noChargeClaim,
            ],
            'an untrusted checkout URL is the same fault' => [
                'checkout_url_untrusted',
                'misconfigured',
                503,
                ['contact the merchant'],
                $noChargeClaim,
            ],
            'a failed initiation leaves the provider state unknown' => [
                'checkout_initiation_failed',
                'provider_unknown',
                503,
                ['couldn', 'confirm the payment session', 'try again shortly'],
                $noChargeClaim,
            ],
            'an unrecognised code falls through to the same unknown state' => [
                'something_the_engine_grew_later',
                'provider_unknown',
                503,
                ['confirm the payment session'],
                $noChargeClaim,
            ],
            'the rate limit is the one honest retry promise' => [
                'payment_link_rate_limited',
                'rate_limited',
                429,
                ['try again shortly'],
                $noChargeClaim,
            ],
        ];
    }

    public function testAManualCollectorRendersTheManualCopyRatherThanARetryPromise(): void
    {
        [$token, ] = $this->mintedLink();
        $controller = $this->controllerWith($this->collectorReturning('manual', ['instructions' => 'Pay by bank']));

        $html = (string) $controller->initiate($this->postRequest($token), $token)->getContent();

        self::assertStringContainsString('data-payment-link-reason="manual"', $html);
        self::assertStringNotContainsString('Nothing has been charged', $html);
        self::assertStringNotContainsString('Pay by bank', $html);
    }

    public function testAThrowingCollectorRendersTheProviderUnknownCopyAndClaimsNoRefund(): void
    {
        [$token, ] = $this->mintedLink();
        $controller = $this->controllerWith(new class implements PaymentCollector {
            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                throw new \RuntimeException('gateway-secret-detail-77');
            }
        });

        $html = (string) $controller->initiate($this->postRequest($token), $token)->getContent();

        self::assertStringContainsString('data-payment-link-reason="provider_unknown"', $html);
        self::assertStringNotContainsString('Nothing has been charged', $html);
        self::assertStringNotContainsString('gateway-secret-detail-77', $html);
    }

    public function testANonPayableLinkRendersTheInvalidStateWithNoLocation(): void
    {
        [$token, $order] = $this->mintedLink();
        $this->paymentLinks()->revoke($this->appContext(), '', $order['uuid'], 'actorrevoker');
        $controller = $this->controllerWith($this->collectorReturning('ok', [
            'checkout_url' => 'https://psp.example/checkout/abc',
        ]));

        $response = $controller->initiate($this->postRequest($token), $token);

        self::assertSame(410, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        self::assertStringContainsString('data-payment-link-state="invalid"', (string) $response->getContent());
    }

    public function testAMalformedTokenPostedToInitiateIsTheSameGeneric404(): void
    {
        $controller = $this->controllerWith($this->collectorReturning('ok', ['checkout_url' => 'https://psp.test/x']));

        $response = $controller->initiate($this->postRequest('nope'), 'nope');

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        self::assertSame(
            (string) $this->handle(Request::create('/checkout/pay/' . self::UNKNOWN_TOKEN))->getContent(),
            (string) $response->getContent(),
        );
    }

    /** @dataProvider hostileRedirectTargets */
    public function testTheIndependentFinalUrlCheckRefusesEveryNonHttpsTarget(string $url): void
    {
        self::assertFalse(ShopPaymentLinkController::isRedirectableCheckoutUrl($url));
    }

    /** @return list<array{0: string}> */
    public static function hostileRedirectTargets(): array
    {
        return [
            [''],
            ['/relative'],
            ['//evil.example/x'],
            ['http://psp.example/checkout'],
            ['javascript:alert(1)'],
            ['data:text/html,<script>'],
            ['https://psp.example@evil.example/checkout'],
            ['https://user:pass@evil.example/checkout'],
            ['https:///nohost'],
        ];
    }

    public function testTheIndependentFinalUrlCheckAcceptsAPlainAbsoluteHttpsTarget(): void
    {
        self::assertTrue(ShopPaymentLinkController::isRedirectableCheckoutUrl('https://psp.example/checkout?s=1'));
    }

    // ==================================================================
    // The CSRF posture on the POST
    // ==================================================================

    public function testACrossOriginInitiatePostIsRejectedWithTheNoStoreHeadersIntact(): void
    {
        [$token, ] = $this->mintedLink();

        $response = $this->handle(Request::create(
            '/checkout/pay/' . $token . '/initiate',
            'POST',
            [],
            [],
            [],
            ['HTTP_ORIGIN' => 'https://evil.attacker.test'],
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        $this->assertPaymentLinkHeaders($response);
    }

    /**
     * PINNED (user decision): under `Referrer-Policy: strict-origin` a same-origin form POST
     * still carries a real `Origin`, so the STOCK guard's ordinary comparison decides it. No
     * opaque-`null` reconciliation, no Fetch-Metadata-only acceptance.
     */
    public function testModernOriginValidationSucceedsOnTheInitiatePost(): void
    {
        [$token, ] = $this->mintedLink();

        // Not merely "not rejected": it passes THROUGH the guard into the engine, which refuses
        // with the unavailable state because this harness's canonical origin is not HTTPS.
        $response = $this->handle($this->initiatePost($token, [
            'HTTP_ORIGIN' => $this->expectedOrigin(),
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]));

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
    }

    /**
     * PINNED: with no `Origin` and no `Sec-Fetch-Site`, the stock guard's Referer fallback is the
     * only thing left — and `strict-origin` sends exactly an ORIGIN-ONLY Referer
     * (`https://host/`), never the payment-link path. `ShopCsrfGuard::normalizeOrigin()` is a
     * plain `parse_url()` that keeps scheme/host/port and discards the path, so the origin-only
     * form passes; a foreign one does not.
     */
    public function testAMissingOriginSucceedsOnlyWithACorrectStrictOriginRefererAndTheTokenIsNeverInIt(): void
    {
        [$token, ] = $this->mintedLink();

        // What a strict-origin browser actually sends: the bare origin with a trailing slash.
        $strictOriginReferer = rtrim($this->expectedOrigin(), '/') . '/';
        self::assertStringNotContainsString($token, $strictOriginReferer);
        self::assertStringNotContainsString('/checkout/pay', $strictOriginReferer);

        $accepted = $this->handle($this->initiatePost($token, ['HTTP_REFERER' => $strictOriginReferer]));
        // The same host with no trailing slash at all is still just an origin, and still passes.
        $bare = $this->handle($this->initiatePost($token, ['HTTP_REFERER' => rtrim($this->expectedOrigin(), '/')]));
        $foreign = $this->handle($this->initiatePost($token, ['HTTP_REFERER' => 'https://evil.attacker.test/']));
        $none = $this->handle($this->initiatePost($token, []));

        self::assertSame(503, $accepted->getStatusCode(), 'an origin-only strict-origin Referer must pass');
        self::assertSame(503, $bare->getStatusCode());
        self::assertSame(403, $foreign->getStatusCode(), 'foreign provenance still fails');
        self::assertSame(403, $none->getStatusCode(), 'absent provenance still fails');
    }

    /**
     * PINNED: the policy header IS the contract for what a browser puts in `Referer`. Assert its
     * value on BOTH the landing GET and the initiate POST (and on the 403 the guard produces),
     * because a payment token may never leave in a referrer.
     */
    public function testTheStrictOriginPolicyIsConsistentOnLandingAndOnThePost(): void
    {
        [$token, ] = $this->mintedLink();

        $landing = $this->handle(Request::create('/checkout/pay/' . $token));
        $post = $this->handle($this->initiatePost($token, ['HTTP_ORIGIN' => $this->expectedOrigin()]));
        $refused = $this->handle($this->initiatePost($token, ['HTTP_ORIGIN' => 'https://evil.attacker.test']));

        foreach (['landing' => $landing, 'initiate' => $post, 'refused' => $refused] as $label => $response) {
            self::assertSame(
                ShopPaymentLinkHeaders::REFERRER_POLICY,
                $response->headers->get('Referrer-Policy'),
                $label,
            );
            self::assertSame('strict-origin', $response->headers->get('Referrer-Policy'), $label);
        }
    }

    public function testASameOriginInitiatePostReachesTheEngineAndNeverEmitsALocation(): void
    {
        // Through the REAL container: the harness's canonical origin is http://localhost, so the
        // bound return-URL provider answers null and the engine refuses BEFORE any provider call.
        [$token, ] = $this->mintedLink();

        $response = $this->handle($this->initiatePost($token, [
            'HTTP_ORIGIN' => $this->expectedOrigin(),
        ]));

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        $this->assertPaymentLinkHeaders($response);
        self::assertStringContainsString('data-payment-link-state="unavailable"', (string) $response->getContent());
    }

    // ==================================================================
    // Signed, non-authorizing receipts
    // ==================================================================

    public function testTheReturnReceiptRendersGenericCopyWithNoOrderOrLinkFields(): void
    {
        [, $order, $linkUuid] = $this->mintedLink();
        $signature = $this->signer()->sign($this->appContext(), PaymentLinkReturnSigner::PURPOSE_RETURN, $linkUuid);

        $response = $this->handle(Request::create('/checkout/pay/return/' . $linkUuid . '/' . $signature));

        self::assertSame(200, $response->getStatusCode());
        $this->assertPaymentLinkHeaders($response);
        $html = (string) $response->getContent();
        self::assertStringContainsString('Payment submitted', $html);
        self::assertStringContainsString('confirmation may take a moment', $html);
        self::assertStringNotContainsString($order['number'], $html);
        self::assertStringNotContainsString('Widget A', $html);
        self::assertStringNotContainsString($linkUuid, $html);
        // Non-authorizing: it grants nothing — no credential, no navigation into an owned page.
        self::assertSame([], $response->headers->getCookies());
        self::assertNull($response->headers->get('Location'));
    }

    public function testTheCancelReceiptRendersItsOwnGenericCopy(): void
    {
        [, , $linkUuid] = $this->mintedLink();
        $signature = $this->signer()->sign($this->appContext(), PaymentLinkReturnSigner::PURPOSE_CANCEL, $linkUuid);

        $response = $this->handle(Request::create('/checkout/pay/cancel/' . $linkUuid . '/' . $signature));

        self::assertSame(200, $response->getStatusCode());
        $this->assertPaymentLinkHeaders($response);
        self::assertStringContainsString('Payment canceled', (string) $response->getContent());
        self::assertStringContainsString('reopen the original link', (string) $response->getContent());
    }

    public function testPurposeSeparationIsEnforcedInBothDirections(): void
    {
        [, , $linkUuid] = $this->mintedLink();
        $returnSig = $this->signer()->sign($this->appContext(), PaymentLinkReturnSigner::PURPOSE_RETURN, $linkUuid);
        $cancelSig = $this->signer()->sign($this->appContext(), PaymentLinkReturnSigner::PURPOSE_CANCEL, $linkUuid);

        self::assertNotSame($returnSig, $cancelSig);
        $onCancel = $this->handle(Request::create('/checkout/pay/cancel/' . $linkUuid . '/' . $returnSig));
        $onReturn = $this->handle(Request::create('/checkout/pay/return/' . $linkUuid . '/' . $cancelSig));

        self::assertSame(404, $onCancel->getStatusCode());
        self::assertSame(404, $onReturn->getStatusCode());
        $this->assertPaymentLinkHeaders($onCancel);
        $this->assertPaymentLinkHeaders($onReturn);
    }

    public function testHostileSignaturesAndShapesCollapseIntoTheSameGeneric404(): void
    {
        [, , $linkUuid] = $this->mintedLink();
        $generic = (string) $this->handle(Request::create('/checkout/pay/' . self::UNKNOWN_TOKEN))->getContent();

        $hostile = [
            '/checkout/pay/return/' . $linkUuid . '/' . str_repeat('0', 64),
            '/checkout/pay/return/' . $linkUuid . '/short',
            '/checkout/pay/return/' . $linkUuid . '/' . str_repeat('Z', 64),
            '/checkout/pay/return/' . Utils::generateNanoID() . '/' . str_repeat('a', 64),
            '/checkout/pay/cancel/' . $linkUuid . '/' . str_repeat('0', 64),
        ];

        foreach ($hostile as $path) {
            $response = $this->handle(Request::create($path));
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame($generic, (string) $response->getContent(), $path);
        }
    }

    public function testAReceiptSignatureIsBoundToItsOwnLinkUuid(): void
    {
        [, , $linkUuid] = $this->mintedLink();
        $signature = $this->signer()->sign($this->appContext(), PaymentLinkReturnSigner::PURPOSE_RETURN, $linkUuid);
        $otherUuid = Utils::generateNanoID();

        $response = $this->handle(Request::create('/checkout/pay/return/' . $otherUuid . '/' . $signature));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReceiptHandlesCarryNoTokenAnywhere(): void
    {
        [$token, , $linkUuid] = $this->mintedLink();
        $urls = $this->container()->get(ShopUrlGenerator::class);
        $signature = $this->signer()->sign($this->appContext(), PaymentLinkReturnSigner::PURPOSE_RETURN, $linkUuid);

        $path = $urls->paymentLinkReturn($linkUuid, $signature);

        self::assertStringNotContainsString($token, $path);
        self::assertStringContainsString($linkUuid, $path);
    }

    // ==================================================================
    // Documentation
    // ==================================================================

    public function testThePackReadmeDocumentsTokenLogRedaction(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-commerce/README.md');

        self::assertStringContainsString('/checkout/pay/', $readme);
        self::assertStringContainsString('log_format', $readme, 'a reverse-proxy redaction example');
        self::assertStringContainsString('redact', strtolower($readme));
    }

    /**
     * Cleanup-train Task 10. The custody prose counted TWO egress points for a raw token — the
     * one-time mint/regenerate response and the send-time email body — and predates the landing
     * page's no-JS Pay form, whose `action` attribute carries the token as a matter of design
     * ({@see \Thallo\Commerce\Http\Shop\ShopPaymentLinkController::landing()} says so, and
     * `testTheTokenAppearsOnlyOnceInTheLandingMarkupAsTheFormTarget` above proves it). Three is the
     * honest number; an operator reasoning about where the credential can leak from must be
     * counting the same places the code does.
     */
    public function testThePackReadmeCountsAllThreeTokenEgressPoints(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-commerce/README.md');

        self::assertStringContainsString('three egress points', strtolower($readme));
        self::assertMatchesRegularExpression(
            '/three egress points.{0,900}action/is',
            $readme,
            'the Pay form action must be named as the sanctioned third egress point',
        );
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /** Render the landing template for one unavailable sub-state, through the real renderer. */
    private function renderUnavailable(string $reason): string
    {
        $controller = $this->controllerWith($this->collectorReturning('ok', ['checkout_url' => 'https://psp.test/x']));
        $render = new \ReflectionMethod($controller, 'renderState');
        $render->setAccessible(true);

        return (string) $render->invoke($controller, 'unavailable', $reason, null, null, 503)->getContent();
    }

    private function assertPaymentLinkHeaders(Response $response): void
    {
        // Symfony's ResponseHeaderBag appends `private` to a bare `no-store` (it is strictly
        // narrower — it can only reduce who may cache), so the assertion is on the directive.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringNotContainsString('max-age', $cacheControl);
        self::assertSame('strict-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
    }

    /**
     * Mint a real link over a real payable order.
     *
     * @return array{0: string, 1: array{uuid: string, number: string}, 2: string}
     */
    private function mintedLink(string $tenant = ''): array
    {
        $order = $this->seedPayableOrder($tenant);
        $minted = $this->paymentLinks()->mint($this->appContext(), $tenant, $order['uuid'], null, 'actorminter1');

        return [(string) $minted['rawToken'], $order, $minted['link']->linkUuid];
    }

    /** @return array{uuid: string, number: string} */
    private function seedPayableOrder(string $tenant): array
    {
        $uuid = Utils::generateNanoID();
        $number = 'PL-' . (++self::$seq) . '-' . substr($uuid, 0, 4);
        $this->connection()->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => $number,
            'status' => 'pending_payment',
            'origin' => 'admin',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 0,
            'refund_revision' => 0,
            'refunded_total' => 0,
            'email' => 'payer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('b', 64),
            'currency' => 'USD',
            'subtotal' => 2500,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 2500,
            'placed_at' => null,
            'created_at' => '2026-02-01 09:00:00',
        ]);
        $this->connection()->table('commerce_order_lines')->insert([
            'uuid' => Utils::generateNanoID(),
            'order_uuid' => $uuid,
            'variant_uuid' => Utils::generateNanoID(),
            'product_name' => 'Widget A',
            'sku' => 'WID-A',
            'option_values' => '[]',
            'unit_price' => 2500,
            'quantity' => 1,
            'line_total' => 2500,
        ]);

        return ['uuid' => $uuid, 'number' => $number];
    }

    private function paymentLinks(): PaymentLinkService
    {
        return $this->container()->get(PaymentLinkService::class);
    }

    private function signer(): PaymentLinkReturnSigner
    {
        return $this->container()->get(PaymentLinkReturnSigner::class);
    }

    private function controllerWith(PaymentCollector $collector): ShopPaymentLinkController
    {
        $service = new PaymentLinkService(
            $this->container()->get(OrderRepository::class),
            $this->container()->get(PaymentLinkRepository::class),
            $this->tenantResolver(),
            null,
            $collector,
            $this->httpsReturnUrls(),
        );

        return new ShopPaymentLinkController(
            $this->appContext(),
            $service,
            $this->container()->get(ShopUrlGenerator::class),
            $this->container()->get(\Thallo\Commerce\Http\Shop\ShopPageRenderer::class),
            $this->signer(),
        );
    }

    /** @param array<string,mixed> $payload */
    private function collectorReturning(string $status, array $payload): PaymentCollector
    {
        return new class ($status, $payload) implements PaymentCollector {
            /** @param array<string,mixed> $payload */
            public function __construct(private readonly string $status, private readonly array $payload)
            {
            }

            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                return new PaymentInitiation('stub', $this->status, $this->payload);
            }
        };
    }

    private function httpsReturnUrls(): PaymentLinkReturnUrlProvider
    {
        return new class implements PaymentLinkReturnUrlProvider {
            /** @return array{return: string, cancel: string}|null */
            public function urlsFor(ApplicationContext $context, string $linkUuid): ?array
            {
                return [
                    'return' => 'https://site.test/checkout/pay/return/' . $linkUuid . '/' . str_repeat('a', 64),
                    'cancel' => 'https://site.test/checkout/pay/cancel/' . $linkUuid . '/' . str_repeat('a', 64),
                ];
            }
        };
    }

    private function tenantResolver(): CurrentTenantResolver
    {
        $seam = $this->container()->get(CommerceTenantResolution::class);

        return new class ($seam) implements CurrentTenantResolver {
            public function __construct(private readonly CommerceTenantResolution $seam)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->seam->tenantUuid($context);
            }
        };
    }

    private function postRequest(string $token): Request
    {
        return Request::create('/checkout/pay/' . $token . '/initiate', 'POST');
    }

    /** @param array<string,string> $server */
    private function initiatePost(string $token, array $server): Request
    {
        return Request::create('/checkout/pay/' . $token . '/initiate', 'POST', [], [], [], $server);
    }

    private function expectedOrigin(): string
    {
        return $this->container()->get(CanonicalPublicOriginResolver::class)->currentOrigin($this->appContext());
    }

    /** @return array<string,\Glueful\Routing\Route> */
    private function paymentLinkRouteObjects(): array
    {
        $wanted = [
            '/checkout/pay/{token}',
            '/checkout/pay/{token}/initiate',
            '/checkout/pay/return/{linkUuid}/{signature}',
            '/checkout/pay/cancel/{linkUuid}/{signature}',
        ];
        $found = [];
        foreach ($this->router()->getDynamicRoutes() as $routes) {
            foreach ($routes as $route) {
                if (in_array($route->getPath(), $wanted, true)) {
                    $found[$route->getPath()] = $route;
                }
            }
        }
        self::assertCount(count($wanted), $found, 'every payment-link route must be dynamic-registered');

        return $found;
    }
}
