<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Http\Admin\OrderProjection;
use Glueful\Extensions\Commerce\Orders\OrderFulfillmentService;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Handler;
use Glueful\Validation\ValidationException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Thallo\Commerce\Http\AdminCompleteSaleController;
use Thallo\Commerce\Orders\CompleteSaleCoordinator;

/**
 * Task 13 (admin-order-creation cycle 2, design spec §2.8): the server-orchestrated
 * `POST /v1/admin/commerce/orders/{uuid}/complete-sale` — the one-click walk-in finish that
 * chains the engine's `OrderPaymentService::markPaid()` and `OrderFulfillmentService::fulfill()`
 * with each step keeping its OWN CAS, audit rows, and events.
 *
 * Drivers, mirroring {@see AdminOrderPaymentsTest}'s established two-lane convention:
 *
 *  - The truth table and the five-outcome contract are driven by constructing
 *    {@see CompleteSaleCoordinator} + {@see AdminCompleteSaleController} DIRECTLY, so a test can
 *    hand the coordinator a specific failure seam without a second application boot. Every
 *    collaborator it is given is the REAL engine service (resolved from the live container, or
 *    constructed around the real {@see OrderRepository}) — nothing about the payment/fulfillment
 *    transitions themselves is faked.
 *  - Route registration, authority, the kernel-rendered 422, and the end-to-end DI wiring are
 *    driven through the REAL kernel with a genuine `X-API-Key` actor.
 *
 * FAILURE SEAMS — all four failure outcomes are reached without ever mocking a transition:
 *
 *  1. **Stale-row resolution** ({@see self::testMarkPaidDomainConflictIsAConflictWithFulfillSkipped()}):
 *     the coordinator is handed the order row as it was resolved, then the row is changed
 *     underneath it before `complete()` runs — exactly what a concurrent operator action does.
 *     The engine's own `OrderStateMachine` then raises a REAL `\DomainException`.
 *  2. **The engine's own documented `$afterPaidHook`** ({@see OrderPaymentService}'s test-only
 *     seam): throwing there aborts INSIDE `markPaid()`'s transaction, so the paid CAS genuinely
 *     rolls back and the reload is genuinely still `pending_payment`.
 *  3. **A real `OrderPaid` listener** that mutates the row: it runs in `markPaid()`'s
 *     after-commit callback — i.e. after the payment has genuinely committed and before
 *     `fulfill()` is called — so the subsequent fulfillment raises a REAL `\DomainException`.
 *  4. **An over-long `tracking_ref` passed straight to the coordinator** (bypassing the
 *     controller's own 191-char validation): PostgreSQL rejects the write INSIDE `fulfill()`'s
 *     transaction, producing a genuinely unexpected, non-domain failure with the order left
 *     `paid` — the exact state spec §2.8's fourth outcome describes.
 *
 * The coordinator's one narrow `$stepBoundaryProbe` seam (see its own docblock) is used only for
 * the two states the engine cannot be made to produce on demand: a throw AFTER `markPaid()` has
 * already committed (spec §2.8's "after-commit callback threw" case — the framework's
 * `TransactionManager` deliberately swallows after-commit callback failures, and `EventService`
 * is fault-isolated, so no listener can produce it) and a non-domain throw at the fulfillment
 * boundary on a driver that does not enforce column widths.
 *
 * Tenant is sentinel mode ('') — the default harness's Commerce tenancy resolution, matching
 * {@see AdminOrderPaymentsTest}'s identical seeding convention.
 */
final class CompleteSaleTest extends AppTestCase
{
    private const ROUTE_TEMPLATE = '/v1/admin/commerce/orders/{uuid}/complete-sale';
    private const POISON = 'POISON-COMPLETE-SALE-XYZ';

    /** Internals that must never cross this pack's response boundary. */
    private const FORBIDDEN_ORDER_KEYS = [
        'id',
        'tenant_uuid',
        'guest_token_hash',
        'marketplace_partitioned',
        'fulfillment_revision',
        'refund_revision',
        'draft_revision',
    ];

    /** @var list<string> */
    private array $apiKeyUserUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function tearDown(): void
    {
        $db = $this->connection();
        $pdo = $db->getPDO();
        $pdo->exec('DELETE FROM commerce_order_events');
        $pdo->exec('DELETE FROM commerce_order_lines');
        $pdo->exec('DELETE FROM commerce_orders');
        if ($this->apiKeyUserUuids !== []) {
            $db->table('api_keys')->whereIn('user_uuid', $this->apiKeyUserUuids)->forceDelete();
            $db->table('user_roles')->whereIn('user_uuid', $this->apiKeyUserUuids)->forceDelete();
            $db->table('users')->whereIn('uuid', $this->apiKeyUserUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    // ==================================================================
    // Resolve FIRST — non-revealing 404
    // ==================================================================

    public function testUnknownOrderUuidIsNotFound(): void
    {
        $response = $this->complete(Utils::generateNanoID());

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayNotHasKey('data', $this->decode($response));
    }

    public function testCrossTenantOrderUuidIsNotFound(): void
    {
        $uuid = $this->seedOrder(Utils::generateNanoID(12));

        $response = $this->complete($uuid);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('pending_payment', $this->dbRow($uuid)['status']);
    }

    /**
     * Draft-blindness: a draft uuid cannot RESOLVE here at all, because
     * {@see OrderRepository::findByUuid()} defaults `includeDrafts: false` and this controller
     * never passes `true` — so the 404 is automatic, not a hand-written status guard.
     */
    public function testDraftOrderUuidIsNotFound(): void
    {
        $uuid = $this->seedDraftOrder();

        $response = $this->complete($uuid);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('draft', $this->dbRow($uuid)['status']);
    }

    /** Resolution genuinely comes FIRST: an unknown uuid 404s even with an unusable body. */
    public function testUnknownOrderUuidIsNotFoundEvenWithMalformedInput(): void
    {
        $response = $this->complete(Utils::generateNanoID(), ['tracking_ref' => 12345]);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    // ==================================================================
    // Wrong state -> 409 with ZERO transitions
    // ==================================================================

    /**
     * @return iterable<string,array{0:array<string,mixed>}>
     */
    public static function nonCompletableOrders(): iterable
    {
        yield 'already paid' => [['status' => 'paid']];
        yield 'already fulfilled' => [['status' => 'fulfilled', 'fulfillment_status' => 'fulfilled']];
        yield 'canceled' => [['status' => 'canceled']];
        yield 'refunded' => [['status' => 'refunded']];
        yield 'delivery order awaiting payment' => [['fulfillment_mode' => 'delivery']];
    }

    /**
     * @param array<string,mixed> $overrides
     * @dataProvider nonCompletableOrders
     */
    public function testNonCompletableOrderIsAConflictWithZeroTransitions(array $overrides): void
    {
        $uuid = $this->seedOrder('', $overrides);
        $before = $this->dbRow($uuid);
        $events = $this->captureEvents($uuid);

        $response = $this->complete($uuid);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        self::assertSame(
            [
                ['step' => 'mark_paid', 'status' => 'skipped'],
                ['step' => 'fulfill', 'status' => 'skipped'],
            ],
            $data['steps'],
        );
        self::assertSame($before['status'], $this->dbRow($uuid)['status']);
        self::assertSame(0, $events->paid);
        self::assertSame(0, $events->fulfilled);
        self::assertSame(0, $this->auditCount($uuid, 'status:paid'));
        self::assertSame(0, $this->auditCount($uuid, 'status:fulfilled'));
        $this->assertClosedProjection($data['order']);
    }

    // ==================================================================
    // Malformed input -> 422
    // ==================================================================

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'non-string tracking_ref' => ['{"tracking_ref": 12345}'];
        yield 'array tracking_ref' => ['{"tracking_ref": ["a"]}'];
        yield 'over-long tracking_ref' => ['{"tracking_ref": "' . str_repeat('t', 192) . '"}'];
        yield 'unknown field' => ['{"status": "paid"}'];
        yield 'JSON array body' => ['[1,2,3]'];
        yield 'unparseable JSON' => ['{not json'];
    }

    /** @dataProvider malformedBodies */
    public function testMalformedInputIsUnprocessableWithZeroTransitions(string $raw): void
    {
        $uuid = $this->seedOrder();
        $events = $this->captureEvents($uuid);

        try {
            $this->controller()->completeSale($this->rawRequest($raw), $uuid);
            self::fail('Expected a ValidationException for: ' . $raw);
        } catch (ValidationException $e) {
            self::assertSame(422, (new Handler())->render($e)->getStatusCode());
        }

        self::assertSame('pending_payment', $this->dbRow($uuid)['status']);
        self::assertSame(0, $events->paid);
        self::assertSame(0, $events->fulfilled);
    }

    /** An absent body and an explicit empty object are BOTH valid — tracking is optional. */
    public function testAbsentAndEmptyBodiesAreAccepted(): void
    {
        foreach (['', '{}', '{"tracking_ref": null}'] as $raw) {
            $uuid = $this->seedOrder();

            $response = $this->controller()->completeSale($this->rawRequest($raw), $uuid);

            self::assertSame(200, $response->getStatusCode(), $raw . ' => ' . (string) $response->getContent());
            self::assertNull($this->decode($response)['data']['order']['tracking_ref']);
        }
    }

    // ==================================================================
    // Outcome 5: happy path
    // ==================================================================

    public function testHappyPathMarksPaidThenFulfillsWithBothStepsDone(): void
    {
        $uuid = $this->seedOrder();
        $events = $this->captureEvents($uuid);

        $response = $this->complete($uuid, ['tracking_ref' => 'IN-STORE-1']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->decode($response);
        self::assertTrue($body['success']);
        self::assertSame(
            [
                ['step' => 'mark_paid', 'status' => 'done'],
                ['step' => 'fulfill', 'status' => 'done'],
            ],
            $body['data']['steps'],
        );

        $order = $body['data']['order'];
        self::assertSame('fulfilled', $order['status']);
        self::assertSame('fulfilled', $order['fulfillment_status']);
        self::assertSame('IN-STORE-1', $order['tracking_ref']);

        $row = $this->dbRow($uuid);
        self::assertSame('fulfilled', $row['status']);
        self::assertSame('fulfilled', $row['fulfillment_status']);
        self::assertSame('IN-STORE-1', $row['tracking_ref']);

        // Exactly one of each event, and each step's OWN audit row.
        self::assertSame(1, $events->paid);
        self::assertSame(1, $events->fulfilled);
        self::assertSame(1, $this->auditCount($uuid, 'status:paid'));
        self::assertSame(1, $this->auditCount($uuid, 'status:fulfilled'));
    }

    public function testSuccessOrderPayloadIsExactlyTheClosedAdminProjection(): void
    {
        $uuid = $this->seedOrder();

        $response = $this->complete($uuid);

        $order = $this->decode($response)['data']['order'];
        self::assertEqualsCanonicalizing(OrderProjection::FIELDS, array_keys($order));
        $this->assertClosedProjection($order);
        self::assertStringNotContainsString('guest_token_hash', (string) $response->getContent());
    }

    // ==================================================================
    // Outcome 1: mark-paid DOMAIN conflict -> 409, mark_paid failed, fulfill skipped
    // ==================================================================

    /**
     * The coordinator is handed the row as it was resolved; the row is then changed underneath
     * it (what a concurrent cancel/mark-paid does), so the engine's own state machine rejects
     * the transition for real.
     */
    public function testMarkPaidDomainConflictIsAConflictWithFulfillSkipped(): void
    {
        $uuid = $this->seedOrder();
        $stale = $this->repoRow($uuid);
        $events = $this->captureEvents($uuid);
        $this->connection()->table('commerce_orders')->where('uuid', '=', $uuid)->update(['status' => 'canceled']);

        $result = $this->coordinator()->complete('', $stale, null);
        $response = $this->respond($result);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        self::assertSame('mark_paid', $data['steps'][0]['step']);
        self::assertSame('failed', $data['steps'][0]['status']);
        self::assertNotSame('', (string) $data['steps'][0]['error']);
        self::assertSame(['step' => 'fulfill', 'status' => 'skipped'], $data['steps'][1]);
        self::assertSame('canceled', $data['order']['status']);
        self::assertSame(0, $events->paid);
        self::assertSame(0, $events->fulfilled);
        $this->assertClosedProjection($data['order']);
    }

    // ==================================================================
    // Outcome 2a: unexpected mark-paid failure BEFORE commit -> reload still pending
    // ==================================================================

    public function testUnexpectedMarkPaidFailureBeforeCommitReportsFailedAndSkipsFulfillment(): void
    {
        $uuid = $this->seedOrder();
        $events = $this->captureEvents($uuid);
        $logger = $this->capturingLogger();
        $coordinator = $this->coordinator(
            payments: $this->paymentServiceThrowingInsideItsTransaction(new \RuntimeException(self::POISON)),
            logger: $logger,
        );

        $response = $this->respond($coordinator->complete('', $this->repoRow($uuid), null));

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        self::assertSame('failed', $data['steps'][0]['status']);
        self::assertSame(['step' => 'fulfill', 'status' => 'skipped'], $data['steps'][1]);
        // The paid CAS genuinely rolled back with its transaction.
        self::assertSame('pending_payment', $data['order']['status']);
        self::assertSame('pending_payment', $this->dbRow($uuid)['status']);
        self::assertSame(0, $this->auditCount($uuid, 'status:paid'));
        self::assertSame(0, $events->paid);
        self::assertSame(0, $events->fulfilled);
        self::assertNotSame([], $logger->records, 'the unexpected failure must be logged server-side');
        $this->assertNoExceptionTextCrossedTheBoundary($response);
    }

    // ==================================================================
    // Outcome 2b: the payment COMMITTED, then the call threw -> mark_paid truthfully done
    // ==================================================================

    public function testUnexpectedMarkPaidFailureAfterCommitReportsDoneTruthfullyAndSkipsFulfillment(): void
    {
        $uuid = $this->seedOrder();
        $events = $this->captureEvents($uuid);
        $logger = $this->capturingLogger();
        $coordinator = $this->coordinator(
            probe: static function (string $point): void {
                if ($point === CompleteSaleCoordinator::POINT_AFTER_MARK_PAID) {
                    throw new \RuntimeException(self::POISON);
                }
            },
            logger: $logger,
        );

        $response = $this->respond($coordinator->complete('', $this->repoRow($uuid), null));

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        // Truthful: the reload says paid, so mark_paid is `done` even though the call threw.
        self::assertSame(['step' => 'mark_paid', 'status' => 'done'], $data['steps'][0]);
        self::assertSame(['step' => 'fulfill', 'status' => 'skipped'], $data['steps'][1]);
        self::assertSame('paid', $data['order']['status']);
        self::assertSame('unfulfilled', $data['order']['fulfillment_status']);
        self::assertSame('paid', $this->dbRow($uuid)['status']);
        // Never continued to fulfillment: exactly one paid event, zero fulfilled.
        self::assertSame(1, $events->paid);
        self::assertSame(0, $events->fulfilled);
        self::assertSame(1, $this->auditCount($uuid, 'status:paid'));
        self::assertSame(0, $this->auditCount($uuid, 'status:fulfilled'));
        self::assertNotSame([], $logger->records);
        $this->assertNoExceptionTextCrossedTheBoundary($response);
        $this->assertClosedProjection($data['order']);
    }

    /** The recovery path spec §2.8 names: the ORDINARY guarded fulfill still works afterwards. */
    public function testPaidButUnfulfilledOrderIsRecoverableThroughTheOrdinaryGuardedFulfill(): void
    {
        $uuid = $this->seedOrder();
        $coordinator = $this->coordinator(
            probe: static function (string $point): void {
                if ($point === CompleteSaleCoordinator::POINT_AFTER_MARK_PAID) {
                    throw new \RuntimeException(self::POISON);
                }
            },
        );
        $coordinator->complete('', $this->repoRow($uuid), null);
        self::assertSame('paid', $this->dbRow($uuid)['status']);

        $fulfilled = $this->container()->get(OrderFulfillmentService::class)
            ->fulfill($this->appContext(), '', $uuid, 'RECOVERED-1');

        self::assertSame('fulfilled', $fulfilled['status']);
        self::assertSame(1, $this->auditCount($uuid, 'status:paid'));
        self::assertSame(1, $this->auditCount($uuid, 'status:fulfilled'));
    }

    /** Complete sale is never blindly retried: a second call on the paid order is a 409. */
    public function testCompleteSaleIsNeverBlindlyRetriedAfterAPartialCompletion(): void
    {
        $uuid = $this->seedOrder();
        $coordinator = $this->coordinator(
            probe: static function (string $point): void {
                if ($point === CompleteSaleCoordinator::POINT_AFTER_MARK_PAID) {
                    throw new \RuntimeException(self::POISON);
                }
            },
        );
        $coordinator->complete('', $this->repoRow($uuid), null);
        $events = $this->captureEvents($uuid);

        $retry = $this->complete($uuid);

        self::assertSame(409, $retry->getStatusCode(), (string) $retry->getContent());
        self::assertSame(0, $events->paid);
        self::assertSame(1, $this->auditCount($uuid, 'status:paid'));
        self::assertSame(0, $this->auditCount($uuid, 'status:fulfilled'));
    }

    // ==================================================================
    // Outcome 3: mark-paid committed + fulfillment DOMAIN conflict -> 409
    // ==================================================================

    /**
     * Genuine seam: an `OrderPaid` listener (which the engine fires from `markPaid()`'s
     * after-commit callback, i.e. between the two steps) fulfills the order out from under the
     * coordinator — the concurrent-operator case. The engine's own state machine then rejects
     * `fulfilled -> fulfilled` for real.
     */
    public function testFulfillmentDomainConflictAfterACommittedPaymentIsAConflict(): void
    {
        $uuid = $this->seedOrder();
        $events = $this->captureEvents($uuid);
        $this->onOrderPaid($uuid, function () use ($uuid): void {
            $this->connection()->table('commerce_orders')->where('uuid', '=', $uuid)
                ->update(['status' => 'fulfilled', 'fulfillment_status' => 'fulfilled']);
        });

        $response = $this->complete($uuid);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        self::assertSame(['step' => 'mark_paid', 'status' => 'done'], $data['steps'][0]);
        self::assertSame('fulfill', $data['steps'][1]['step']);
        self::assertSame('failed', $data['steps'][1]['status']);
        self::assertNotSame('', (string) $data['steps'][1]['error']);
        self::assertSame(1, $events->paid);
        self::assertSame(0, $events->fulfilled, 'the losing fulfillment must dispatch nothing');
        self::assertSame(1, $this->auditCount($uuid, 'status:paid'));
        self::assertSame(0, $this->auditCount($uuid, 'status:fulfilled'));
        $this->assertClosedProjection($data['order']);
        $this->assertNoExceptionTextCrossedTheBoundary($response);
    }

    /** The same outcome with the payment left intact: the refreshed order is still PAID. */
    public function testFulfillmentDomainConflictReturnsTheRefreshedPaidOrder(): void
    {
        $uuid = $this->seedOrder();
        $events = $this->captureEvents($uuid);
        $coordinator = $this->coordinator(
            probe: static function (string $point): void {
                if ($point === CompleteSaleCoordinator::POINT_BEFORE_FULFILL) {
                    throw new \DomainException(self::POISON);
                }
            },
        );

        $response = $this->respond($coordinator->complete('', $this->repoRow($uuid), null));

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        self::assertSame(['step' => 'mark_paid', 'status' => 'done'], $data['steps'][0]);
        self::assertSame('failed', $data['steps'][1]['status']);
        self::assertSame('paid', $data['order']['status']);
        self::assertSame('unfulfilled', $data['order']['fulfillment_status']);
        self::assertSame(1, $events->paid);
        self::assertSame(0, $events->fulfilled);
        $this->assertClosedProjection($data['order']);
        $this->assertNoExceptionTextCrossedTheBoundary($response);
    }

    // ==================================================================
    // Outcome 4: unexpected fulfillment failure -> sanitized 500 + refreshed PAID order
    // ==================================================================

    /**
     * Genuine seam: the coordinator is called directly with a `tracking_ref` far wider than the
     * column (the controller's own validation refuses this, which is exactly why it is passed
     * below the controller). PostgreSQL rejects the write INSIDE `fulfill()`'s transaction — a
     * real, non-domain failure that leaves the order `paid`.
     */
    public function testUnexpectedFulfillmentFailureIsASanitizedFiveHundredWithTheRefreshedPaidOrder(): void
    {
        $uuid = $this->seedOrder();
        $events = $this->captureEvents($uuid);
        $logger = $this->capturingLogger();

        $response = $this->respond(
            $this->coordinator(logger: $logger)->complete('', $this->repoRow($uuid), str_repeat('x', 400)),
        );

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        self::assertSame(['step' => 'mark_paid', 'status' => 'done'], $data['steps'][0]);
        self::assertSame('fulfill', $data['steps'][1]['step']);
        self::assertSame('failed', $data['steps'][1]['status']);
        self::assertSame('paid', $data['order']['status']);
        self::assertSame('unfulfilled', $data['order']['fulfillment_status']);
        self::assertSame(1, $events->paid);
        self::assertSame(0, $events->fulfilled);
        self::assertSame(1, $this->auditCount($uuid, 'status:paid'));
        self::assertSame(0, $this->auditCount($uuid, 'status:fulfilled'));
        self::assertNotSame([], $logger->records);
        $this->assertClosedProjection($data['order']);
        $this->assertNoExceptionTextCrossedTheBoundary($response);
    }

    /** The same outcome reached at the fulfillment boundary itself, driver-independently. */
    public function testUnexpectedFulfillmentBoundaryFailureIsASanitizedFiveHundred(): void
    {
        $uuid = $this->seedOrder();
        $events = $this->captureEvents($uuid);
        $coordinator = $this->coordinator(
            probe: static function (string $point): void {
                if ($point === CompleteSaleCoordinator::POINT_BEFORE_FULFILL) {
                    throw new \RuntimeException(self::POISON);
                }
            },
        );

        $response = $this->respond($coordinator->complete('', $this->repoRow($uuid), null));

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        self::assertSame(['step' => 'mark_paid', 'status' => 'done'], $data['steps'][0]);
        self::assertSame('failed', $data['steps'][1]['status']);
        self::assertSame('paid', $data['order']['status']);
        self::assertSame(1, $events->paid);
        self::assertSame(0, $events->fulfilled);
        $this->assertNoExceptionTextCrossedTheBoundary($response);
    }

    // ==================================================================
    // Exception messages never cross the boundary — full sweep over EVERY failing outcome
    // ==================================================================

    public function testNoExceptionMessageTextEverCrossesTheResponseBoundary(): void
    {
        $throwAt = static fn (string $target, \Throwable $e): callable
            => static function (string $point) use ($target, $e): void {
                if ($point === $target) {
                    throw $e;
                }
            };

        $responses = [];

        // 1. mark-paid domain conflict (real state-machine rejection).
        $uuid = $this->seedOrder();
        $stale = $this->repoRow($uuid);
        $this->connection()->table('commerce_orders')->where('uuid', '=', $uuid)->update(['status' => 'canceled']);
        $responses['mark_paid domain'] = $this->respond($this->coordinator()->complete('', $stale, null));

        // 2a. unexpected mark-paid failure inside the transaction.
        $uuid = $this->seedOrder();
        $responses['mark_paid pre-commit'] = $this->respond(
            $this->coordinator(payments: $this->paymentServiceThrowingInsideItsTransaction(
                new \RuntimeException(self::POISON),
            ))->complete('', $this->repoRow($uuid), null),
        );

        // 2b. unexpected mark-paid failure after the commit.
        $uuid = $this->seedOrder();
        $responses['mark_paid post-commit'] = $this->respond(
            $this->coordinator(probe: $throwAt(
                CompleteSaleCoordinator::POINT_AFTER_MARK_PAID,
                new \RuntimeException(self::POISON),
            ))->complete('', $this->repoRow($uuid), null),
        );

        // 3. fulfillment domain conflict.
        $uuid = $this->seedOrder();
        $responses['fulfill domain'] = $this->respond(
            $this->coordinator(probe: $throwAt(
                CompleteSaleCoordinator::POINT_BEFORE_FULFILL,
                new \DomainException(self::POISON),
            ))->complete('', $this->repoRow($uuid), null),
        );

        // 4. unexpected fulfillment failure (real driver rejection).
        $uuid = $this->seedOrder();
        $responses['fulfill unexpected'] = $this->respond(
            $this->coordinator()->complete('', $this->repoRow($uuid), str_repeat('x', 400)),
        );

        self::assertCount(5, $responses);
        foreach ($responses as $label => $response) {
            self::assertContains($response->getStatusCode(), [409, 500], $label);
            $this->assertNoExceptionTextCrossedTheBoundary($response, $label);
            $data = $this->decode($response)['data'];
            $this->assertClosedProjection($data['order'], $label);
        }
    }

    // ==================================================================
    // Concurrency: two simultaneous calls -> exactly one winner, no duplicate side effects
    // ==================================================================

    /**
     * TWO real, wholly independent subprocesses call complete-sale on the SAME order with NO
     * parent-controlled winner — mirroring {@see ShopCheckoutRaceTest}'s second race shape (and
     * its `launchRaceChild()`/`collectRaceChild()` harness). Which process the OS schedules onto
     * the row first is nondeterministic, so every assertion is winner-agnostic. The whole suite
     * runs on real PostgreSQL (phpunit.xml pins `DB_DRIVER=pgsql`), so — exactly like
     * {@see ProductLinkRaceTest} — no `skipUnlessPgsql()` guard is needed.
     */
    public function testTwoSimultaneousCompleteSaleCallsProduceExactlyOneWinner(): void
    {
        $uuid = $this->seedOrder();

        $handles = [$this->launchRaceChild($uuid), $this->launchRaceChild($uuid)];
        $results = array_map(fn (array $h): array => $this->collectRaceChild($h), $handles);

        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([200, 409], $statuses, 'exactly one winner: ' . json_encode($results));

        $winner = $results[array_search(200, array_column($results, 'status'), true)];
        self::assertSame(
            [['step' => 'mark_paid', 'status' => 'done'], ['step' => 'fulfill', 'status' => 'done']],
            $winner['body']['data']['steps'],
        );

        $loser = $results[array_search(409, array_column($results, 'status'), true)];
        self::assertSame('skipped', $loser['body']['data']['steps'][1]['status']);
        self::assertNotSame([], $loser['body']['data']['order'], 'the loser gets the current order');
        $this->assertClosedProjection($loser['body']['data']['order'], 'race loser');

        // No duplicate side effects: one paid audit row, one fulfilled audit row, one final row.
        self::assertSame(1, $this->auditCount($uuid, 'status:paid'));
        self::assertSame(1, $this->auditCount($uuid, 'status:fulfilled'));
        $row = $this->dbRow($uuid);
        self::assertSame('fulfilled', $row['status']);
        self::assertSame('fulfilled', $row['fulfillment_status']);
    }

    /**
     * Deterministic half of the same guarantee: a second connection holds the order row's write
     * lock while flipping it to `paid`, so the real subprocess's own `pending_payment -> paid`
     * compare-and-set can only lose. Whether it loses at the pre-gate (the row was already
     * committed as paid when it resolved) or at the CAS itself depends on scheduling, so the
     * assertion covers both truthfully — what must ALWAYS hold is: a 409, fulfillment skipped,
     * and not a single fulfillment side effect.
     */
    public function testALoserOfTheMarkPaidRaceNeverProceedsToFulfillment(): void
    {
        $uuid = $this->seedOrder();
        $connB = $this->secondConnection();
        $connB->getTransactionManager()->begin();
        $connB->table('commerce_orders')->where('uuid', '=', $uuid)->update(['status' => 'paid']);

        $handle = $this->launchRaceChild($uuid);
        usleep(400_000);
        $connB->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);

        self::assertSame(409, $result['status'], json_encode($result));
        $steps = $result['body']['data']['steps'];
        self::assertSame('fulfill', $steps[1]['step']);
        self::assertSame('skipped', $steps[1]['status']);
        self::assertContains($steps[0]['status'], ['failed', 'skipped']);
        self::assertSame(0, $this->auditCount($uuid, 'status:fulfilled'));
        self::assertSame('paid', $this->dbRow($uuid)['status']);
    }

    // ==================================================================
    // Kernel-driven: route registration, authority, wiring
    // ==================================================================

    public function testRouteIsRegisteredWithManageAuthorityAndTheSpecifiedName(): void
    {
        $route = $this->findRoute('POST', self::ROUTE_TEMPLATE);

        self::assertNotNull($route, 'POST ' . self::ROUTE_TEMPLATE . ' must be registered');
        self::assertContains('content_permission:commerce.manage', (array) $route['middleware']);
        self::assertSame('thallo.commerce.admin.orders.complete_sale', $route['name']);
    }

    public function testAnonymousRequestIsRejectedWith401(): void
    {
        $uuid = $this->seedOrder();

        $response = $this->handle($this->jsonRequest('POST', $this->routeFor($uuid), []));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('pending_payment', $this->dbRow($uuid)['status']);
    }

    public function testViewOnlyActorIsRejectedWith403(): void
    {
        $uuid = $this->seedOrder();
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);

        $response = $this->handle($this->apiKeyRequest('POST', $this->routeFor($uuid), $key, []));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('pending_payment', $this->dbRow($uuid)['status']);
    }

    public function testKernelDrivenManageActorCompletesTheSaleEndToEnd(): void
    {
        $uuid = $this->seedOrder();
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);
        $events = $this->captureEvents($uuid);

        $response = $this->handle(
            $this->apiKeyRequest('POST', $this->routeFor($uuid), $key, ['tracking_ref' => 'KERNEL-1']),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        self::assertSame(
            [['step' => 'mark_paid', 'status' => 'done'], ['step' => 'fulfill', 'status' => 'done']],
            $data['steps'],
        );
        self::assertEqualsCanonicalizing(OrderProjection::FIELDS, array_keys($data['order']));
        self::assertSame(1, $events->paid);
        self::assertSame(1, $events->fulfilled);
    }

    public function testKernelDrivenMalformedBodyIsFourTwentyTwo(): void
    {
        $uuid = $this->seedOrder();
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);

        $response = $this->handle(
            $this->apiKeyRequest('POST', $this->routeFor($uuid), $key, ['tracking_ref' => 12345]),
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('pending_payment', $this->dbRow($uuid)['status']);
    }

    public function testKernelDrivenUnknownOrderIsFourOhFour(): void
    {
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);

        $response = $this->handle(
            $this->apiKeyRequest('POST', $this->routeFor(Utils::generateNanoID()), $key, []),
        );

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    // ==================================================================
    // assertions
    // ==================================================================

    /** @param array<string,mixed>|null $order */
    private function assertClosedProjection(?array $order, string $label = ''): void
    {
        self::assertIsArray($order, $label . ': an order payload must be present');
        self::assertSame(
            [],
            array_diff(array_keys($order), OrderProjection::FIELDS),
            $label . ': the order payload must be the closed admin projection',
        );
        foreach (self::FORBIDDEN_ORDER_KEYS as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $order, $label . ": '{$forbidden}' must never be projected");
        }
    }

    /**
     * The regex sweep: nothing that looks like raw exception plumbing (the seeded poison marker,
     * a class name, a driver SQLSTATE, a stack frame, a file path) may appear in the response.
     */
    private function assertNoExceptionTextCrossedTheBoundary(HttpResponse $response, string $label = ''): void
    {
        $raw = (string) $response->getContent();

        foreach (
            [
                '/' . self::POISON . '/',
                '/SQLSTATE/i',
                '/Exception/',
                '/Stack trace/i',
                '/#\d+ \//',
                '/\.php\b/',
                '/\bvalue too long\b/i',
                '/Invalid order transition/i',
                '/changed concurrently/i',
            ] as $pattern
        ) {
            self::assertDoesNotMatchRegularExpression($pattern, $raw, $label . ': ' . $pattern);
        }
    }

    // ==================================================================
    // drivers
    // ==================================================================

    /** @param array<string,mixed>|null $body */
    private function complete(string $uuid, ?array $body = null): HttpResponse
    {
        return $this->controller()->completeSale(
            $this->rawRequest($body === null ? '{}' : (string) json_encode($body)),
            $uuid,
        );
    }

    private function controller(?CompleteSaleCoordinator $coordinator = null): AdminCompleteSaleController
    {
        return new AdminCompleteSaleController(
            $this->appContext(),
            $this->container()->get(OrderRepository::class),
            $coordinator ?? $this->coordinator(),
            $this->container()->get(CommerceTenantResolution::class),
        );
    }

    private function coordinator(
        ?OrderPaymentService $payments = null,
        ?callable $probe = null,
        ?LoggerInterface $logger = null,
    ): CompleteSaleCoordinator {
        return new CompleteSaleCoordinator(
            $this->appContext(),
            $this->container()->get(OrderRepository::class),
            $payments ?? $this->container()->get(OrderPaymentService::class),
            $this->container()->get(OrderFulfillmentService::class),
            $logger ?? $this->capturingLogger(),
            $probe,
        );
    }

    /**
     * Renders a coordinator result exactly the way {@see AdminCompleteSaleController} does, so
     * the direct-coordinator lanes assert against the real wire shape (projection included).
     *
     * @param array<string,mixed> $result
     */
    private function respond(array $result): HttpResponse
    {
        return $this->controller()->respond($result);
    }

    private function rawRequest(string $content): Request
    {
        return Request::create(
            '/x',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $content,
        );
    }

    /** @return array<string,mixed> */
    private function decode(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded, (string) $response->getContent());

        return $decoded;
    }

    private function routeFor(string $uuid): string
    {
        return '/v1/admin/commerce/orders/' . $uuid . '/complete-sale';
    }

    // ==================================================================
    // seams
    // ==================================================================

    /**
     * A REAL {@see OrderPaymentService} around the REAL repository, whose engine-provided,
     * documented `$afterPaidHook` throws — i.e. the failure happens after the paid CAS but
     * still INSIDE `markPaid()`'s own transaction, so that transaction genuinely rolls back.
     */
    private function paymentServiceThrowingInsideItsTransaction(\Throwable $e): OrderPaymentService
    {
        return new OrderPaymentService(
            $this->container()->get(OrderRepository::class),
            null,
            static function () use ($e): void {
                throw $e;
            },
        );
    }

    /** Register a one-order-scoped `OrderPaid` listener (fires from markPaid's after-commit). */
    private function onOrderPaid(string $orderUuid, callable $handler): void
    {
        $this->container()->get(EventService::class)->addListener(
            OrderPaid::class,
            static function (OrderPaid $event) use ($orderUuid, $handler): void {
                if ((string) ($event->order['uuid'] ?? '') === $orderUuid) {
                    $handler();
                }
            },
        );
    }

    /**
     * Counts `OrderPaid`/`OrderFulfilled` dispatches for ONE order uuid. Listeners registered by
     * a test outlive it (the container is process-shared), which is harmless precisely because
     * every counter is pinned to its own freshly generated uuid.
     */
    private function captureEvents(string $orderUuid): object
    {
        $sink = new class {
            public int $paid = 0;
            public int $fulfilled = 0;
        };

        $events = $this->container()->get(EventService::class);
        $events->addListener(
            OrderPaid::class,
            static function (OrderPaid $event) use ($sink, $orderUuid): void {
                if ((string) ($event->order['uuid'] ?? '') === $orderUuid) {
                    $sink->paid++;
                }
            },
        );
        $events->addListener(
            OrderFulfilled::class,
            static function (OrderFulfilled $event) use ($sink, $orderUuid): void {
                if ((string) ($event->order['uuid'] ?? '') === $orderUuid) {
                    $sink->fulfilled++;
                }
            },
        );

        return $sink;
    }

    private function capturingLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];

            /** @param array<string,mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    // ==================================================================
    // race harness (mirrors ProductLinkRaceTest / ShopCheckoutRaceTest)
    // ==================================================================

    /** @return array{0: resource, 1: array<int,resource>} */
    private function launchRaceChild(string $orderUuid): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/fixtures/complete_sale_race_child.php',
                $orderUuid,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
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

    // ==================================================================
    // seeding / reads
    // ==================================================================

    /**
     * A finalized walk-in order awaiting payment — the ONLY shape complete-sale accepts.
     *
     * @param array<string,mixed> $overrides
     */
    private function seedOrder(string $tenant = '', array $overrides = []): string
    {
        $uuid = Utils::generateNanoID();
        $defaults = [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'pending_payment',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 0,
            'refund_revision' => 0,
            'refunded_total' => 0,
            'tracking_ref' => null,
            'email' => null,
            'user_uuid' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1500,
            'grand_total' => 1500,
            'customer_name' => 'Walk-in customer',
            'phone_normalized' => '+15550000001',
            'phone_display' => '(555) 000-0001',
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'draft_revision' => 0,
            'placed_at' => '2026-02-01 09:00:00',
            'created_at' => '2026-02-01 09:00:00',
        ];
        $this->connection()->table('commerce_orders')->insert(array_merge($defaults, $overrides, ['uuid' => $uuid]));

        return $uuid;
    }

    /** A draft per the engine's walk-in schema — mirrors AdminOrderPaymentsTest::seedDraftOrder(). */
    private function seedDraftOrder(): string
    {
        return $this->seedOrder('', [
            'order_number' => null,
            'status' => 'draft',
            'placed_at' => null,
        ]);
    }

    /**
     * The raw `commerce_orders` row, read straight from the table — usable for a cross-tenant or
     * draft row, which the (tenant-scoped, draft-blind) repository deliberately cannot resolve.
     *
     * @return array<string,mixed>
     */
    private function dbRow(string $uuid): array
    {
        $row = $this->connection()->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertIsArray($row, "order {$uuid} must exist");

        return $row;
    }

    /**
     * The repository-decoded row, exactly as {@see AdminCompleteSaleController} resolves it —
     * the input the direct-coordinator lanes hand to `complete()`.
     *
     * @return array<string,mixed>
     */
    private function repoRow(string $uuid): array
    {
        $row = $this->container()->get(OrderRepository::class)->findByUuid($this->appContext(), '', $uuid);
        self::assertIsArray($row, "order {$uuid} must resolve");

        return $row;
    }

    private function auditCount(string $orderUuid, string $type): int
    {
        return count(
            $this->connection()->table('commerce_order_events')
                ->where('order_uuid', '=', $orderUuid)
                ->where('type', '=', $type)
                ->get(),
        );
    }

    // ==================================================================
    // actors
    // ==================================================================

    /**
     * @param list<string> $grantedPermissionSlugs
     * @param list<string> $scopes
     */
    private function seedApiKeyUser(array $grantedPermissionSlugs, array $scopes): string
    {
        $userUuid = Utils::generateNanoID();
        $this->apiKeyUserUuids[] = $userUuid;

        $this->connection()->table('users')->insert([
            'uuid' => $userUuid,
            'username' => 'completesale_' . substr($userUuid, 0, 8),
            'email' => $userUuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($grantedPermissionSlugs !== []) {
            $this->grantRole($userUuid, $grantedPermissionSlugs);
        }
        $this->provider()->invalidateAllCache();

        $created = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $userUuid,
            'name' => 'complete-sale-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'completesale_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'complete sale test role',
            'level' => 30,
            'is_system' => false,
            'status' => 'active',
        ]);

        $permissions = new PermissionRepository($this->connection());
        $rolePermissions = new RolePermissionRepository($this->connection());
        foreach ($permissionSlugs as $slug) {
            $permission = $permissions->findPermissionBySlug($slug);
            self::assertNotNull($permission, "permission {$slug} must exist (pack seed migration)");
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid(), []);
        }

        self::assertTrue($this->provider()->assignRole($userUuid, $roleSlug));
    }

    /** @param array<string,mixed>|null $body */
    private function apiKeyRequest(string $method, string $path, string $key, ?array $body = null): Request
    {
        return Request::create(
            $path,
            $method,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer unused-clears-the-auth-middleware-bearer-gate',
                'HTTP_X_API_KEY' => $key,
            ],
            $body === null ? null : (string) json_encode($body),
        );
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
