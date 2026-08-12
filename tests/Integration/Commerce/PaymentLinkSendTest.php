<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingRichEmailChannel;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Notifications\Services\ChannelManager;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Email\PaymentRequestMailer;
use Thallo\Commerce\Email\PaymentRequestSendResult;
use Thallo\Commerce\Email\RichEmailAvailability;
use Thallo\Commerce\Http\AdminPaymentLinkSendController;
use Thallo\Commerce\Payments\PaymentLinkDeliveryRepository;
use Thallo\Commerce\Payments\ThalloPaymentLinkPublicUrlProvider;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * Payment links Task 12 (payment-links spec §2.4): the pack's ONE payment-link route —
 * `POST /v1/admin/commerce/orders/{uuid}/payment-link/send` — its two modes, its exact 404/409
 * split, its delivery-idempotency semantics, and its CLOSED receipt shape.
 *
 * ## Drivers, mirroring {@see CompleteSaleTest}'s established two-lane convention
 *
 *  - The behaviour matrix constructs {@see AdminPaymentLinkSendController} DIRECTLY over the REAL
 *    engine {@see PaymentLinkService} (with an HTTPS-origin public-URL provider, exactly like
 *    {@see PaymentLinkHostSeamsTest}, because the harness's canonical origin is plain HTTP and
 *    would otherwise refuse every mint), the REAL {@see PaymentLinkDeliveryRepository}, and the
 *    REAL {@see PaymentRequestMailer} over a RECORDING rich channel. Nothing about minting,
 *    matching, or ledger arbitration is faked — only the SMTP transport.
 *  - Route registration, ownership (the engine catalog owns mint/revoke/status exactly once) and
 *    the authority requirement are asserted against the LIVE router.
 *
 * ## Custody
 *
 * The token is obtained the same way an operator obtains it: from the one-time mint URL. It is
 * submitted back for `mode=current` and never stored by this pack. Every assertion below that
 * touches the ledger also asserts the token is absent from it.
 */
final class PaymentLinkSendTest extends AppTestCase
{
    private const ROUTE_TEMPLATE = '/v1/admin/commerce/orders/{uuid}/payment-link/send';
    private const DELIVERIES = 'thallo_commerce_payment_link_deliveries';
    private const ORIGIN = 'https://shop.example';
    private const KEY = 'send-key-0123456789abcdef';

    /** The CLOSED receipt shape §2.4 pins. */
    private const RECEIPT_KEYS = [
        'delivery_uuid', 'order_uuid', 'link_uuid', 'mode', 'status',
        'error_code', 'provider_message_id', 'replayed', 'created_at', 'updated_at',
    ];

    private ?RecordingRichEmailChannel $channel = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store()->putMany(['thallo-commerce.email.payment_request.enabled' => '1']);
    }

    protected function tearDown(): void
    {
        $this->store()->forget('thallo-commerce.email.payment_request.enabled');
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM ' . self::DELIVERIES);
        $pdo->exec('DELETE FROM commerce_payment_links');
        $pdo->exec('DELETE FROM commerce_order_events');
        $pdo->exec('DELETE FROM commerce_orders');
        parent::tearDown();
    }

    // ==================================================================
    // Route ownership + authority
    // ==================================================================

    public function testTheSendRouteIsRegisteredUnderManageAuthorityAndIsPackOwned(): void
    {
        $route = $this->findRoute('POST', self::ROUTE_TEMPLATE);

        self::assertNotNull($route, 'the pack-owned send route must be registered');
        self::assertSame(
            [AdminPaymentLinkSendController::class, 'send'],
            $route['handler'],
        );
        self::assertSame('thallo.commerce.admin.orders.payment_link.send', $route['name']);

        /** @var list<string> $middleware */
        $middleware = $route['middleware'];
        self::assertSame('content_permission:commerce.manage', end($middleware));
        self::assertContains('auth', $middleware);
        self::assertContains('admin_tenant_binding', $middleware);
        self::assertContains('tenant_bootstrap', $middleware);
    }

    public function testTheEngineCatalogOwnsMintRevokeAndStatusExactlyOnceWithNoPackShadow(): void
    {
        $mintPath = '/v1/admin/commerce/orders/{uuid}/payment-link';

        foreach ([['POST', 'store'], ['GET', 'show'], ['DELETE', 'destroy']] as [$method, $action]) {
            $matching = array_values(array_filter(
                $this->router()->getAllRoutes(),
                static fn (array $r): bool => (string) $r['path'] === $mintPath
                    && strtoupper((string) $r['method']) === $method,
            ));

            self::assertCount(
                1,
                $matching,
                "{$method} {$mintPath} must be registered EXACTLY once (the engine catalog's own mount)",
            );
            self::assertSame(
                \Glueful\Extensions\Commerce\Http\Admin\AdminOrderPaymentLinkController::class,
                $matching[0]['handler'][0],
                "{$method} {$mintPath} must be owned by the ENGINE controller, never shadowed by the pack",
            );
            self::assertSame($action, $matching[0]['handler'][1]);
        }
    }

    public function testNoPackControllerEverMintsRevokesOrReadsPaymentLinkStatus(): void
    {
        $packRoutes = array_values(array_filter(
            $this->router()->getAllRoutes(),
            static fn (array $r): bool => str_contains((string) $r['path'], 'payment-link')
                && is_array($r['handler'] ?? null)
                && str_starts_with((string) $r['handler'][0], 'Thallo\\Commerce\\'),
        ));

        self::assertCount(1, $packRoutes, 'the pack owns exactly ONE payment-link admin route: send');
        self::assertSame(self::ROUTE_TEMPLATE, $packRoutes[0]['path']);
    }

    public function testAnUnauthenticatedSendIsRejectedByTheRealKernel(): void
    {
        $request = $this->jsonRequest(
            'POST',
            '/v1/admin/commerce/orders/' . Utils::generateNanoID() . '/payment-link/send',
            ['mode' => 'regenerate'],
        );
        $request->headers->set('Idempotency-Key', self::KEY);

        self::assertSame(401, $this->handle($request)->getStatusCode());
    }

    // ==================================================================
    // Idempotency-Key validation (422, before anything else)
    // ==================================================================

    /** @dataProvider badIdempotencyKeys */
    public function testAMalformedOrAbsentIdempotencyKeyIs422(?string $key): void
    {
        $order = $this->seedPayableOrder();

        $response = $this->send($order, ['mode' => 'regenerate'], $key);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, $this->deliveryCount(), 'a malformed key must never create a ledger row');
    }

    /** @return array<string, array{0:?string}> */
    public static function badIdempotencyKeys(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'too short (15)' => [str_repeat('k', 15)],
            'too long (129)' => [str_repeat('k', 129)],
            'contains whitespace' => ['idem key 0123456789abc'],
            'contains a control character' => ["idem\tkey0123456789abc"],
        ];
    }

    public function testTheKeyBoundsThemselvesAreInclusive(): void
    {
        $order = $this->seedPayableOrder();

        self::assertSame(200, $this->send($order, ['mode' => 'regenerate'], str_repeat('a', 16))->getStatusCode());

        $other = $this->seedPayableOrder();
        self::assertSame(200, $this->send($other, ['mode' => 'regenerate'], str_repeat('b', 128))->getStatusCode());
    }

    // ==================================================================
    // Body validation
    // ==================================================================

    /** @dataProvider badBodies */
    public function testAMalformedBodyIs422(array $body): void
    {
        $order = $this->seedPayableOrder();

        $response = $this->send($order, $body);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, $this->deliveryCount());
    }

    /** @return array<string, array{0:array<string,mixed>}> */
    public static function badBodies(): array
    {
        return [
            'no mode' => [[]],
            'unknown mode' => [['mode' => 'silent']],
            'non-string mode' => [['mode' => 42]],
            'current without a token' => [['mode' => 'current']],
            'current with a non-string token' => [['mode' => 'current', 'token' => 1234]],
            'regenerate carrying a token' => [['mode' => 'regenerate', 'token' => 'x']],
            'current carrying a ttl' => [['mode' => 'current', 'token' => 'x', 'ttl_days' => 7]],
            'non-integer ttl' => [['mode' => 'regenerate', 'ttl_days' => 'soon']],
            'unknown field' => [['mode' => 'regenerate', 'send_sms' => true]],
        ];
    }

    // ==================================================================
    // Send refusals that happen BEFORE the ledger is claimed
    // ==================================================================

    public function testAnOrderWithNoEmailAddressIsRefusedWithNoLedgerRow(): void
    {
        $order = $this->seedPayableOrder(['email' => null]);

        $response = $this->send($order, ['mode' => 'regenerate']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_has_no_email', $this->reason($response));
        self::assertSame(0, $this->deliveryCount());
    }

    public function testTheDisabledTemplateToggleIsATypedRefusalWithNoLedgerRow(): void
    {
        $this->store()->forget('thallo-commerce.email.payment_request.enabled');
        $order = $this->seedPayableOrder();

        $response = $this->send($order, ['mode' => 'regenerate']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payment_request_email_disabled', $this->reason($response));
        self::assertSame(0, $this->deliveryCount());
    }

    public function testAMissingRichEmailChannelIsATypedSendRefusalNotABootFailure(): void
    {
        $order = $this->seedPayableOrder();
        $availability = new RichEmailAvailability(new ChannelManager());

        $response = $this->controller(availability: $availability)
            ->send($this->request(['mode' => 'regenerate'], self::KEY), $order);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(PaymentRequestSendResult::EMAIL_UNAVAILABLE, $this->reason($response));
        self::assertSame(0, $this->deliveryCount());
    }

    // ==================================================================
    // mode=current — engine-authoritative match, exact 404/409 split
    // ==================================================================

    public function testCurrentModeSendsTheOrdersCurrentLinkAndRecordsTheDelivery(): void
    {
        $order = $this->seedPayableOrder();
        $token = $this->mintToken($order);

        $response = $this->send($order, ['mode' => 'current', 'token' => $token]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->data($response);
        self::assertSame('sent', $data['receipt']['status']);
        self::assertSame('current', $data['receipt']['mode']);
        self::assertFalse($data['receipt']['replayed']);
        self::assertNull($data['url'], 'a successful send never echoes the tokened URL');

        self::assertCount(1, $this->channelCalls());
        self::assertSame(
            self::ORIGIN . '/checkout/pay/' . $token,
            $this->channelCalls()[0]['data']['template_data']['action_url'],
        );
    }

    public function testAnUnknownOrderIs404AndACrossTenantOrderIsTheSame404(): void
    {
        $unknown = $this->send(Utils::generateNanoID(), ['mode' => 'current', 'token' => $this->wellFormedToken()]);
        self::assertSame(404, $unknown->getStatusCode(), (string) $unknown->getContent());

        $foreign = $this->seedPayableOrder(['tenant_uuid' => Utils::generateNanoID(12)]);
        $crossTenant = $this->send($foreign, ['mode' => 'current', 'token' => $this->wellFormedToken()]);
        self::assertSame(404, $crossTenant->getStatusCode(), (string) $crossTenant->getContent());

        self::assertSame($unknown->getStatusCode(), $crossTenant->getStatusCode());
        self::assertSame(0, $this->deliveryCount(), 'a 404 must never leave a ledger row');
    }

    public function testAStaleTokenForAnOwnedOrderIs409PaymentLinkChanged(): void
    {
        $order = $this->seedPayableOrder();
        $stale = $this->mintToken($order);
        $this->mintToken($order); // regenerate: the first token is no longer current.

        $response = $this->send($order, ['mode' => 'current', 'token' => $stale]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('payment_link_changed', $this->reason($response));
        self::assertSame(0, $this->deliveryCount());
        self::assertSame([], $this->channelCalls());
    }

    public function testAnOrderWithNoActiveLinkAtAllIs409PaymentLinkChanged(): void
    {
        $order = $this->seedPayableOrder();

        $response = $this->send($order, ['mode' => 'current', 'token' => $this->wellFormedToken()]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payment_link_changed', $this->reason($response));
    }

    /** @dataProvider malformedTokens */
    public function testAMalformedTokenIsShapeGatedInto409BeforeTheEngineIsCalled(string $token): void
    {
        $order = $this->seedPayableOrder();
        $this->mintToken($order);

        $response = $this->send($order, ['mode' => 'current', 'token' => $token]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('payment_link_changed', $this->reason($response));
    }

    /** @return array<string, array{0:string}> */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'too short' => [str_repeat('a', 63)],
            'too long' => [str_repeat('a', 65)],
            'uppercase hex' => [str_repeat('A', 64)],
            'non-hex' => [str_repeat('z', 64)],
            'trailing newline' => [str_repeat('a', 64) . "\n"],
        ];
    }

    // ==================================================================
    // mode=regenerate — claim first, then mint, then send
    // ==================================================================

    public function testRegenerateMintsANewLinkInvalidatesThePriorOneAndSendsIt(): void
    {
        $order = $this->seedPayableOrder();
        $old = $this->mintToken($order);

        $response = $this->send($order, ['mode' => 'regenerate']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->data($response);
        self::assertSame('sent', $data['receipt']['status']);
        self::assertSame('regenerate', $data['receipt']['mode']);
        self::assertIsString($data['receipt']['link_uuid']);
        self::assertSame($data['link']['link_uuid'], $data['receipt']['link_uuid']);
        self::assertNull($data['url']);

        // The prior link is genuinely dead: mode=current with the OLD token now 409s.
        $stale = $this->send($order, ['mode' => 'current', 'token' => $old], 'another-key-0123456789ab');
        self::assertSame(409, $stale->getStatusCode());

        $sentUrl = (string) $this->channelCalls()[0]['data']['template_data']['action_url'];
        self::assertStringStartsWith(self::ORIGIN . '/checkout/pay/', $sentUrl);
        self::assertStringNotContainsString($old, $sentUrl, 'the regenerated URL must carry a NEW token');
    }

    public function testRegenerateHonoursAnExplicitTtl(): void
    {
        $order = $this->seedPayableOrder();

        $data = $this->data($this->send($order, ['mode' => 'regenerate', 'ttl_days' => 1]));

        self::assertSame('sent', $data['receipt']['status']);
        self::assertIsString($data['link']['expires_at']);
    }

    public function testRegenerateForANonAdminOriginOrderIs409(): void
    {
        $order = $this->seedPayableOrder(['origin' => 'storefront']);

        $response = $this->send($order, ['mode' => 'regenerate']);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('order_not_admin_origin', $this->reason($response));
    }

    public function testRegenerateForAPaidOrderIs409AndTheLedgerRecordsTheRefusal(): void
    {
        $order = $this->seedPayableOrder(['status' => 'paid']);

        $response = $this->send($order, ['mode' => 'regenerate']);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('order_not_pending_payment', $this->reason($response));

        // The claim happened FIRST (§2.4), so the refusal is recorded rather than lost.
        $row = $this->deliveryRow(self::KEY);
        self::assertSame('failed', (string) $row['status']);
        self::assertSame('order_not_pending_payment', (string) $row['error_code']);
        self::assertNull($row['link_uuid']);
    }

    public function testRegenerateWithNoPublicUrlProviderIs503AndMintsNothing(): void
    {
        $order = $this->seedPayableOrder();

        $response = $this->controller(publicUrls: $this->refusingPublicUrls())
            ->send($this->request(['mode' => 'regenerate'], self::KEY), $order);

        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('public_url_unavailable', $this->reason($response));
        self::assertSame(0, (int) $this->connection()->table('commerce_payment_links')->count());
        self::assertSame('failed', (string) $this->deliveryRow(self::KEY)['status']);
    }

    // ==================================================================
    // Delivery failure keeps the link and returns the URL
    // ==================================================================

    public function testARegenerateDeliveryFailureKeepsTheLinkActiveAndReturnsTheUrlForManualCopy(): void
    {
        $order = $this->seedPayableOrder();

        $response = $this->controller(channel: $this->failingChannel())
            ->send($this->request(['mode' => 'regenerate'], self::KEY), $order);

        self::assertSame(502, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->data($response);
        self::assertSame('failed', $data['receipt']['status']);
        self::assertSame(PaymentRequestSendResult::SEND_FAILED, $data['receipt']['error_code']);
        self::assertIsString($data['url']);
        self::assertStringStartsWith(self::ORIGIN . '/checkout/pay/', (string) $data['url']);

        // The link STAYS active: the returned URL's token is still the order's CURRENT one,
        // asserted against the engine's own authority rather than a second send (whose transport
        // is deliberately broken for the length of this test).
        $token = substr((string) $data['url'], strrpos((string) $data['url'], '/') + 1);
        $match = $this->serviceWith($this->publicUrls())
            ->matchCurrentToken($this->appContext(), '', $order, $token);

        self::assertNotNull($match, 'a failed delivery must NOT revoke the link it failed to deliver');
        self::assertSame('active', $match->status);
    }

    public function testACurrentModeDeliveryFailureNeverReturnsAUrlBecauseTheClientAlreadyHasIt(): void
    {
        $order = $this->seedPayableOrder();
        $token = $this->mintToken($order);

        $response = $this->controller(channel: $this->failingChannel())
            ->send($this->request(['mode' => 'current', 'token' => $token], self::KEY), $order);

        self::assertSame(502, $response->getStatusCode());
        $data = $this->data($response);
        self::assertSame('failed', $data['receipt']['status']);
        self::assertNull($data['url']);
    }

    // ==================================================================
    // Replay / conflict / indeterminate
    // ==================================================================

    public function testTheSameKeyAndRequestReplaysTheRecordedOutcomeWithoutAResend(): void
    {
        $order = $this->seedPayableOrder();
        $first = $this->data($this->send($order, ['mode' => 'regenerate']));
        self::assertCount(1, $this->channelCalls());

        $replay = $this->send($order, ['mode' => 'regenerate']);

        self::assertSame(200, $replay->getStatusCode(), (string) $replay->getContent());
        $data = $this->data($replay);
        self::assertTrue($data['receipt']['replayed']);
        self::assertSame('sent', $data['receipt']['status']);
        self::assertSame($first['receipt']['delivery_uuid'], $data['receipt']['delivery_uuid']);
        self::assertNull($data['url'], 'a replay NEVER carries a raw URL');
        self::assertNull($data['link'], 'a replay never re-mints, so there is no fresh link to report');

        self::assertCount(1, $this->channelCalls(), 'a replay must not resend');
        self::assertSame(1, (int) $this->connection()->table('commerce_payment_links')->count());
        self::assertSame(1, $this->deliveryCount());
    }

    public function testTheSameKeyWithADifferentRequestIs409(): void
    {
        $orderA = $this->seedPayableOrder();
        $orderB = $this->seedPayableOrder();
        $this->send($orderA, ['mode' => 'regenerate']);

        $response = $this->send($orderB, ['mode' => 'regenerate']);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('idempotency_key_conflict', $this->reason($response));
        self::assertCount(1, $this->channelCalls());
    }

    public function testASameKeyModeSwitchIsAlsoAFingerprintConflict(): void
    {
        $order = $this->seedPayableOrder();
        $token = $this->mintToken($order);
        $this->send($order, ['mode' => 'current', 'token' => $token]);

        $response = $this->send($order, ['mode' => 'regenerate']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('idempotency_key_conflict', $this->reason($response));
    }

    public function testACrashedProcessingClaimPastTheStaleWindowReportsIndeterminateAndInstructsRecovery(): void
    {
        $order = $this->seedPayableOrder();
        $this->seedCrashedProcessingClaim($order, self::KEY, 'regenerate', null);

        $response = $this->send($order, ['mode' => 'regenerate']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->data($response);
        self::assertTrue($data['receipt']['replayed']);
        self::assertSame('indeterminate', $data['receipt']['status']);
        self::assertSame(
            AdminPaymentLinkSendController::RECOVERY_NEW_KEY_OR_REGENERATE,
            $data['recovery'],
            'plaintext is unrecoverable — the operator must be told to use a new key or regenerate',
        );
        self::assertNull($data['url']);
        self::assertSame([], $this->channelCalls(), 'an indeterminate replay must never resend');
    }

    public function testAFreshProcessingClaimInsideTheStaleWindowReportsProcessingWithNoRecoveryAdvice(): void
    {
        $order = $this->seedPayableOrder();
        $this->seedCrashedProcessingClaim($order, self::KEY, 'regenerate', null, ageSeconds: 30);

        $data = $this->data($this->send($order, ['mode' => 'regenerate']));

        self::assertTrue($data['receipt']['replayed']);
        self::assertSame('processing', $data['receipt']['status']);
        self::assertNull($data['recovery']);
        self::assertSame([], $this->channelCalls());
    }

    public function testANewKeyAfterAnIndeterminateAttemptSendsForReal(): void
    {
        $order = $this->seedPayableOrder();
        $this->seedCrashedProcessingClaim($order, self::KEY, 'regenerate', null);
        $this->send($order, ['mode' => 'regenerate']);

        $recovered = $this->send($order, ['mode' => 'regenerate'], 'recovery-key-0123456789abc');

        self::assertSame(200, $recovered->getStatusCode(), (string) $recovered->getContent());
        self::assertSame('sent', $this->data($recovered)['receipt']['status']);
        self::assertCount(1, $this->channelCalls());
    }

    // ==================================================================
    // Receipt shape + custody
    // ==================================================================

    public function testTheReceiptIsAClosedShapeCarryingNoTokenAddressBodyOrExceptionText(): void
    {
        $order = $this->seedPayableOrder();
        $token = $this->mintToken($order);

        $data = $this->data($this->send($order, ['mode' => 'current', 'token' => $token]));

        self::assertSame(['receipt', 'link', 'url', 'recovery'], array_keys($data));
        self::assertSame(self::RECEIPT_KEYS, array_keys($data['receipt']));

        $encoded = (string) json_encode($data);
        self::assertStringNotContainsString($token, $encoded, 'the receipt must never carry the token');
        self::assertStringNotContainsString('payer@example.com', $encoded, 'no recipient address');
        self::assertStringNotContainsString('Pay for order', $encoded, 'no rendered subject/body');
    }

    public function testTheFailureReceiptCarriesTheSafeCodeAndNoTransportExceptionText(): void
    {
        $order = $this->seedPayableOrder();

        $response = $this->controller(channel: $this->failingChannel())
            ->send($this->request(['mode' => 'regenerate'], self::KEY), $order);

        $encoded = (string) json_encode($this->data($response)['receipt']);
        self::assertStringContainsString(PaymentRequestSendResult::SEND_FAILED, $encoded);
        self::assertStringNotContainsString('smtp', strtolower($encoded));
        self::assertStringNotContainsString('hunter2', $encoded);
    }

    public function testTheLedgerItselfStoresNoTokenAndNoEmailAddress(): void
    {
        $order = $this->seedPayableOrder();
        $token = $this->mintToken($order);
        $this->send($order, ['mode' => 'current', 'token' => $token]);

        $rows = (string) json_encode($this->connection()->table(self::DELIVERIES)->get());

        self::assertStringNotContainsString($token, $rows);
        self::assertStringNotContainsString('payer@example.com', $rows);
        self::assertStringContainsString(
            hash('sha256', 'payer@example.com'),
            $rows,
            'the ledger identifies the recipient by HASH only',
        );
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function send(string $orderUuid, array $body, ?string $key = self::KEY): Response
    {
        return $this->controller()->send($this->request($body, $key), $orderUuid);
    }

    /** @param array<string,mixed> $body */
    private function request(array $body, ?string $key): Request
    {
        $request = Request::create(
            '/v1/admin/commerce/orders/x/payment-link/send',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
        if ($key !== null) {
            $request->headers->set('Idempotency-Key', $key);
        }

        return $request;
    }

    private function controller(
        ?RecordingRichEmailChannel $channel = null,
        ?RichEmailAvailability $availability = null,
        ?PaymentLinkPublicUrlProvider $publicUrls = null,
    ): AdminPaymentLinkSendController {
        if ($channel !== null) {
            $this->channel = $channel;
        }
        $this->channel ??= new RecordingRichEmailChannel(
            \Glueful\Notifications\Results\NotificationResult::success('provider-message-7')
        );

        $manager = new ChannelManager();
        $manager->registerChannel($this->channel);
        $resolved = $availability ?? new RichEmailAvailability($manager);
        $urls = $publicUrls ?? $this->publicUrls();

        return new AdminPaymentLinkSendController(
            $this->appContext(),
            $this->container()->get(OrderRepository::class),
            $this->container()->get(CommerceTenantResolution::class),
            $this->container()->get(PaymentLinkDeliveryRepository::class),
            new PaymentRequestMailer($this->appContext(), $resolved),
            $resolved,
            $this->serviceWith($urls),
            $urls,
        );
    }

    /** @return list<array{notifiable:mixed, data:array<string,mixed>}> */
    private function channelCalls(): array
    {
        return $this->channel?->calls ?? [];
    }

    private function failingChannel(): RecordingRichEmailChannel
    {
        return new RecordingRichEmailChannel(\Glueful\Notifications\Results\NotificationResult::failure(
            'transport_exception',
            'SMTP connect to smtp.example:587 refused (credentials: hunter2)',
        ));
    }

    private function publicUrls(): ThalloPaymentLinkPublicUrlProvider
    {
        return new ThalloPaymentLinkPublicUrlProvider(
            $this->fixedOrigin(self::ORIGIN),
            $this->container()->get(ShopUrlGenerator::class),
        );
    }

    /** A provider that can never compose a usable URL — the engine's typed unavailable state. */
    private function refusingPublicUrls(): PaymentLinkPublicUrlProvider
    {
        return new ThalloPaymentLinkPublicUrlProvider(
            $this->fixedOrigin('http://localhost'),
            $this->container()->get(ShopUrlGenerator::class),
        );
    }

    private function serviceWith(PaymentLinkPublicUrlProvider $publicUrls): PaymentLinkService
    {
        $seam = $this->container()->get(CommerceTenantResolution::class);
        $resolver = new class ($seam) implements CurrentTenantResolver {
            public function __construct(private readonly CommerceTenantResolution $seam)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->seam->tenantUuid($context);
            }
        };

        return new PaymentLinkService(
            $this->container()->get(OrderRepository::class),
            $this->container()->get(PaymentLinkRepository::class),
            $resolver,
            $publicUrls,
        );
    }

    /** Mints through the REAL engine and returns the raw token from the one-time URL. */
    private function mintToken(string $orderUuid): string
    {
        $minted = $this->serviceWith($this->publicUrls())
            ->mintPublic($this->appContext(), '', $orderUuid, null, 'actorsender1');
        $url = (string) $minted['url'];

        return substr($url, strrpos($url, '/') + 1);
    }

    private function wellFormedToken(): string
    {
        return str_repeat('ab', 32);
    }

    private function seedCrashedProcessingClaim(
        string $orderUuid,
        string $key,
        string $mode,
        ?int $ttlDays,
        int $ageSeconds = 3600,
    ): void {
        $at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$ageSeconds} seconds");
        $this->connection()->table(self::DELIVERIES)->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'idempotency_key' => $key,
            'fingerprint' => PaymentLinkDeliveryRepository::fingerprint(
                $orderUuid,
                $mode,
                PaymentLinkDeliveryRepository::recipientHash('payer@example.com'),
                $ttlDays,
            ),
            'order_uuid' => $orderUuid,
            'recipient_hash' => PaymentLinkDeliveryRepository::recipientHash('payer@example.com'),
            'mode' => $mode,
            'status' => 'processing',
            'created_at' => $at->format('Y-m-d H:i:s'),
            'updated_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed> */
    private function data(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded, (string) $response->getContent());
        self::assertArrayHasKey('data', $decoded, (string) $response->getContent());

        return (array) $decoded['data'];
    }

    private function reason(Response $response): ?string
    {
        $decoded = (array) json_decode((string) $response->getContent(), true);
        $error = (array) ($decoded['error'] ?? []);
        $details = (array) ($error['details'] ?? []);

        return isset($details['reason']) ? (string) $details['reason'] : null;
    }

    /** @return array<string,mixed> */
    private function deliveryRow(string $key): array
    {
        $row = $this->connection()->table(self::DELIVERIES)->where('idempotency_key', '=', $key)->first();
        self::assertIsArray($row, "delivery row for '{$key}' must exist");

        return $row;
    }

    private function deliveryCount(): int
    {
        return (int) $this->connection()->table(self::DELIVERIES)->count();
    }

    /** @param array<string,mixed> $overrides */
    private function seedPayableOrder(array $overrides = []): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_orders')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_number' => 'PLS-' . substr($uuid, 0, 6),
            'status' => 'pending_payment',
            'origin' => 'admin',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 0,
            'refund_revision' => 0,
            'refunded_total' => 0,
            'email' => 'payer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('c', 64),
            'currency' => 'USD',
            'subtotal' => 1500,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1500,
            'placed_at' => null,
            'created_at' => '2026-02-01 09:00:00',
        ], $overrides, ['uuid' => $uuid]));

        return $uuid;
    }

    private function fixedOrigin(string $origin): CanonicalPublicOriginResolver
    {
        return new class ($origin) implements CanonicalPublicOriginResolver {
            public function __construct(private readonly string $origin)
            {
            }

            public function currentOrigin(ApplicationContext $c): string
            {
                return $this->origin;
            }

            public function originForTenant(ApplicationContext $c, string $tenantUuid): string
            {
                return $this->origin;
            }
        };
    }

    private function store(): \App\Settings\SettingsStore
    {
        return $this->container()->get(\App\Settings\SettingsStore::class);
    }
}
