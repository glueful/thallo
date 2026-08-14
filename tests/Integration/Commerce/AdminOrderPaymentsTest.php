<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\OrderPayable;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Handler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Thallo\Commerce\Http\AdminOrderPaymentsController;
use Thallo\Commerce\Payments\OrderPaymentSummaryRepository;

/**
 * Task 5 (orders-invoices-receipts plan): `GET /v1/admin/commerce/orders/{uuid}/payments` — the
 * admin order payment summary reading Payvia's own `payments`/`payment_intents` tables.
 *
 * Two drivers, matching this pack's established convention for this kind of surface
 * ({@see AdminOrderSearchTest}'s own docblock):
 *
 *  - Repository/controller behavior (tenant scoping, the closed field projections, ordering,
 *    availability semantics, the non-revealing 404, the uncaught-failure-propagates-as-500
 *    contract) is driven by constructing {@see OrderPaymentSummaryRepository} and
 *    {@see AdminOrderPaymentsController} DIRECTLY — never resolved from the container — so a
 *    test can hand the repository a specific `Connection` double (a genuinely unmigrated
 *    SQLite `:memory:` connection for "tables absent", or a hand-built subclass for "tables
 *    exist but a query blows up") without needing a second full application boot.
 *  - The route-level concerns (registration/authority matrix) are driven through the REAL
 *    kernel via `seedApiKeyUser()`-style actors, mirroring {@see AdminOrderSearchTest} exactly.
 *
 * The default test harness leaves Commerce tenancy resolution at sentinel mode ('' —
 * {@see \Thallo\Commerce\Tenancy\ThalloCommerceTenantResolution} mode (a)), so every order seeded
 * below (unless deliberately testing cross-tenant isolation) uses `tenant_uuid => ''` to match
 * what the controller actually resolves. `glueful/payvia`'s migrations run as part of this
 * suite's shared boot (`scripts/run-test-migrations.php`), so the shared connection's `payments`/
 * `payment_intents` tables are always physically present — proving `available():true` never
 * depends on anything from THIS test process; the "tables absent" scenarios instead construct
 * their own throwaway, never-migrated `Connection`.
 */
final class AdminOrderPaymentsTest extends AppTestCase
{
    private const ROUTE_TEMPLATE = '/v1/admin/commerce/orders/{uuid}/payments';

    /** @var list<string> */
    private array $paymentUuids = [];
    /** @var list<string> */
    private array $intentUuids = [];
    /** @var list<string> */
    private array $apiKeyUserUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function tearDown(): void
    {
        $db = $this->connection();
        $db->getPDO()->exec('DELETE FROM commerce_orders');
        if ($this->paymentUuids !== []) {
            $db->table('payments')->whereIn('uuid', $this->paymentUuids)->forceDelete();
        }
        if ($this->intentUuids !== []) {
            $db->table('payment_intents')->whereIn('uuid', $this->intentUuids)->forceDelete();
        }
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
    // Order-first: non-revealing 404, zero Payvia queries
    // ==================================================================

    /**
     * A cross-tenant order uuid must resolve to a 404 BEFORE the controller ever asks the
     * payment repository anything. Proven with an "exploding" `Connection` double whose every
     * method throws: if the controller mistakenly consulted Payvia after the order lookup came
     * back empty, this test would fail on an UNCAUGHT exception rather than cleanly asserting
     * 404 — the double stands in for "the Payvia provider isn't even present", since a
     * short-circuiting controller can never tell the difference.
     */
    public function testCrossTenantOrderUuidIs404WithZeroPayviaQueries(): void
    {
        $orderUuid = $this->seedOrder(Utils::generateNanoID(), []);
        $controller = $this->controllerWith(new OrderPaymentSummaryRepository($this->explodingConnection()));

        $response = $controller->payments(Request::create('/x', 'GET'), $orderUuid);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    /** Same zero-query proof for a uuid that was never an order at all. */
    public function testUnknownOrderUuidIs404WithZeroPayviaQueries(): void
    {
        $controller = $this->controllerWith(new OrderPaymentSummaryRepository($this->explodingConnection()));

        $response = $controller->payments(Request::create('/x', 'GET'), Utils::generateNanoID());

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * Draft-blindness (admin-order-creation cycle 2, Task 12): a draft's uuid cannot RESOLVE
     * through this endpoint at all -- not merely absent from a list, an outright 404 -- because
     * {@see OrderRepository::findByUuid()} defaults `includeDrafts: false` and this controller
     * never passes `true`. This pins that existing fail-closed default against regression (e.g. a
     * future call site accidentally passing `includeDrafts: true` here) rather than asserting new
     * behavior; zero Payvia queries follow for free from the SAME short-circuit the two tests
     * above already prove.
     */
    public function testDraftOrderUuidIs404WithZeroPayviaQueries(): void
    {
        $draftUuid = $this->seedDraftOrder('');
        $controller = $this->controllerWith(new OrderPaymentSummaryRepository($this->explodingConnection()));

        $response = $controller->payments(Request::create('/x', 'GET'), $draftUuid);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    /** Same proof through the real kernel/route, not just the directly-constructed controller. */
    public function testKernelDrivenRequestForADraftOrderUuidIs404(): void
    {
        $draftUuid = $this->seedDraftOrder('');
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);

        $response = $this->handle($this->apiKeyRequest('GET', $this->routeFor($draftUuid), $key));

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    // ==================================================================
    // Draft-uuid isolation proof for the OTHER mounted engine order endpoints (final review fix
    // wave, finding 2): the print/invoice path plus the surfaces that fold in cheaply alongside
    // it, driven through the REAL kernel/route with the SAME seeded-draft idiom as above.
    // ==================================================================

    /**
     * `AdminOrderController::invoiceData()` resolves via its own `order()` helper -- exactly the
     * same `findByUuid(..., includeDrafts: false)` default proven above -- so this is another pin
     * of an existing fail-closed default, not new behavior.
     */
    public function testKernelDrivenInvoiceDataForADraftOrderUuidIs404(): void
    {
        $draftUuid = $this->seedDraftOrder('');
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);

        $response = $this->handle(
            $this->apiKeyRequest('GET', "/v1/admin/commerce/orders/{$draftUuid}/invoice-data", $key),
        );

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    /** `AdminOrderController::notes()` -- same `order()` pre-check, GET side. */
    public function testKernelDrivenNotesIndexForADraftOrderUuidIs404(): void
    {
        $draftUuid = $this->seedDraftOrder('');
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);

        $response = $this->handle(
            $this->apiKeyRequest('GET', "/v1/admin/commerce/orders/{$draftUuid}/notes", $key),
        );

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    /** `AdminOrderController::addNote()` -- same `order()` pre-check, runs BEFORE DTO validation. */
    public function testKernelDrivenNotesStoreForADraftOrderUuidIs404(): void
    {
        $draftUuid = $this->seedDraftOrder('');
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);

        $response = $this->handle($this->apiKeyRequestWithBody(
            'POST',
            "/v1/admin/commerce/orders/{$draftUuid}/notes",
            $key,
            ['body' => 'note body', 'visibility' => 'internal'],
        ));

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    /** `AdminOrderFulfillmentService::fulfill()` pre-checks `order()` before ever transitioning. */
    public function testKernelDrivenFulfillForADraftOrderUuidIs404(): void
    {
        $draftUuid = $this->seedDraftOrder('');
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);

        $response = $this->handle(
            $this->apiKeyRequestWithBody('POST', "/v1/admin/commerce/orders/{$draftUuid}/fulfill", $key, []),
        );

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * `AdminOrderController::markPaid()` USED to be the one outlier in this group: it pre-checked
     * nothing, calling `OrderPaymentService::markPaid()` straight into
     * `OrderRepository::transition()` (which resolves `includeDrafts: true`), so a draft uuid was
     * found, refused as an invalid `draft -> paid` transition, and converted by the controller's
     * own `catch (\DomainException)` into a 409 that incidentally CONFIRMED the uuid was a real,
     * if unpayable, order.
     *
     * commerce 1.12.0 closed that leak deliberately -- CHANGELOG 1.12.0, "Changed":
     * "`AdminOrderController::markPaid()` runs the draft-blind `order()` precheck every sibling
     * order endpoint already ran: a draft (or unknown, or cross-tenant) uuid is now the same
     * non-revealing 404 instead of the 409 the transition CAS used to return", restated under
     * "Behavior changes (operator-visible)". So this route now joins the sibling endpoints above
     * instead of diverging from them, and this test pins the NON-REVEALING half of that promise,
     * not merely the status code: the draft response must be BYTE-IDENTICAL to the response for a
     * uuid that was never an order at all. A draft that answered 404 with even a different
     * message would still be an existence oracle.
     *
     * What has not changed, and is still asserted: a draft never actually becomes paid.
     */
    public function testKernelDrivenMarkPaidForADraftOrderUuidIsANonRevealingNotFound(): void
    {
        $draftUuid = $this->seedDraftOrder('');
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);

        $draftResponse = $this->handle(
            $this->apiKeyRequestWithBody('POST', "/v1/admin/commerce/orders/{$draftUuid}/mark-paid", $key, []),
        );
        $unknownResponse = $this->handle($this->apiKeyRequestWithBody(
            'POST',
            '/v1/admin/commerce/orders/' . Utils::generateNanoID() . '/mark-paid',
            $key,
            [],
        ));

        self::assertSame(404, $draftResponse->getStatusCode(), (string) $draftResponse->getContent());
        self::assertSame(404, $unknownResponse->getStatusCode(), (string) $unknownResponse->getContent());
        self::assertSame(
            (string) $unknownResponse->getContent(),
            (string) $draftResponse->getContent(),
            'a draft uuid must be indistinguishable from a uuid that was never an order',
        );
        self::assertStringNotContainsString('draft', (string) $draftResponse->getContent());
        self::assertSame('draft', $this->dbRow($draftUuid)['status'], 'a draft must never actually become paid');
    }

    /** @param array<string,mixed> $body */
    private function apiKeyRequestWithBody(string $method, string $path, string $key, array $body): Request
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
            (string) json_encode($body),
        );
    }

    /** @return array<string,mixed> */
    private function dbRow(string $uuid): array
    {
        $row = $this->connection()->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertIsArray($row, "order {$uuid} must exist");

        return $row;
    }

    // ==================================================================
    // Closed projection: hostile secret-bearing columns never reach the wire
    // ==================================================================

    public function testPaymentsProjectionIsClosedAndHostileFieldsAreAbsentFromTheRawResponse(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $this->seedPayment('', $orderUuid, [
            'metadata' => json_encode(['secret' => 'POISON-METADATA-XYZ']),
            'raw_payload' => json_encode(['card' => 'POISON-RAWPAYLOAD-XYZ']),
            'message' => 'POISON-MESSAGE-XYZ',
        ]);
        $controller = $this->controllerWith(new OrderPaymentSummaryRepository($this->connection()));

        $response = $controller->payments(Request::create('/x', 'GET'), $orderUuid);
        $raw = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode(), $raw);
        self::assertStringNotContainsString('POISON-METADATA-XYZ', $raw);
        self::assertStringNotContainsString('POISON-RAWPAYLOAD-XYZ', $raw);
        self::assertStringNotContainsString('POISON-MESSAGE-XYZ', $raw);

        $data = $this->decode($response)['data'];
        self::assertCount(1, $data['payments']);
        self::assertEqualsCanonicalizing(
            [
                'gateway', 'status', 'reference', 'gateway_transaction_id',
                'amount', 'currency', 'created_at', 'updated_at',
            ],
            array_keys($data['payments'][0]),
        );
    }

    // ==================================================================
    // Intents: open + closed both returned; deterministic tie-break ordering
    // ==================================================================

    public function testIntentsReturnsBothOpenAndClosedStatuses(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $this->seedIntent('', $orderUuid, ['status' => 'open']);
        $this->seedIntent('', $orderUuid, ['status' => 'closed']);
        $repository = new OrderPaymentSummaryRepository($this->connection());

        $rows = $repository->intentsFor('', $orderUuid);

        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing(['open', 'closed'], array_column($rows, 'status'));
        foreach ($rows as $row) {
            self::assertEqualsCanonicalizing(
                ['gateway', 'status', 'reference', 'amount', 'currency', 'created_at'],
                array_keys($row),
            );
        }
    }

    public function testPaymentOrderingIsCreatedAtDescendingWithIdTieBreak(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $earlier = $this->seedPayment('', $orderUuid, ['created_at' => '2026-01-01 00:00:00']);
        // Two rows sharing the SAME created_at, inserted in this order -> higher id must win.
        $tiedFirst = $this->seedPayment('', $orderUuid, ['created_at' => '2026-02-01 00:00:00']);
        $tiedSecond = $this->seedPayment('', $orderUuid, ['created_at' => '2026-02-01 00:00:00']);
        $repository = new OrderPaymentSummaryRepository($this->connection());

        $rows = $repository->paymentsFor('', $orderUuid);

        self::assertSame(
            [$tiedSecond, $tiedFirst, $earlier],
            array_column($rows, 'reference'),
        );
    }

    public function testIntentOrderingIsCreatedAtDescendingWithIdTieBreak(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $earlier = $this->seedIntent('', $orderUuid, ['created_at' => '2026-01-01 00:00:00']);
        $tiedFirst = $this->seedIntent('', $orderUuid, ['created_at' => '2026-02-01 00:00:00']);
        $tiedSecond = $this->seedIntent('', $orderUuid, ['created_at' => '2026-02-01 00:00:00']);
        $repository = new OrderPaymentSummaryRepository($this->connection());

        $rows = $repository->intentsFor('', $orderUuid);

        self::assertSame(
            [$tiedSecond, $tiedFirst, $earlier],
            array_column($rows, 'reference'),
        );
    }

    // ==================================================================
    // Both payments AND intents populated simultaneously — neither hides the other
    // ==================================================================

    public function testBothPaymentsAndIntentsRemainPopulatedWhenBothExist(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $this->seedPayment('', $orderUuid);
        $this->seedIntent('', $orderUuid);
        $controller = $this->controllerWith(new OrderPaymentSummaryRepository($this->connection()));

        $response = $controller->payments(Request::create('/x', 'GET'), $orderUuid);
        $data = $this->decode($response)['data'];

        self::assertCount(1, $data['payments']);
        self::assertCount(1, $data['intents']);
    }

    // ==================================================================
    // Availability: tables absent vs present vs provider (dis)enabled
    // ==================================================================

    /** A genuinely unmigrated, isolated SQLite connection -- no `payments`/`payment_intents` at all. */
    public function testAvailableIsFalseWhenTablesAreAbsentAndEnvelopeStaysIntact(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $controller = $this->controllerWith(new OrderPaymentSummaryRepository($this->freshUnmigratedConnection()));

        $response = $controller->payments(Request::create('/x', 'GET'), $orderUuid);
        $data = $this->decode($response)['data'];

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertFalse($data['available']);
        self::assertSame([], $data['payments']);
        self::assertSame([], $data['intents']);
        self::assertArrayHasKey('refund', $data);
    }

    /**
     * Tables present -> `available():true` with historical rows readable, entirely independent
     * of whether `glueful/payvia`'s own ServiceProvider/container bindings are in play: the
     * repository is constructed here from the bare shared `Connection` alone, never resolved via
     * `app($context, OrderPaymentSummaryRepository::class)` or any Payvia-bound container
     * service — "provider enablement is not a capability/config toggle" for this class by
     * construction, since it has no seam through which a provider's enabled/disabled state could
     * even reach it.
     */
    public function testAvailableIsTrueWithHistoricalRowsRegardlessOfProviderBinding(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $this->seedPayment('', $orderUuid, ['created_at' => '2025-01-01 00:00:00']);
        $repository = new OrderPaymentSummaryRepository($this->connection());

        self::assertTrue($repository->available());
        self::assertCount(1, $repository->paymentsFor('', $orderUuid));
    }

    // ==================================================================
    // Unexpected query failure propagates uncaught -> 500, never a catch-all
    // ==================================================================

    /**
     * Tables APPEAR available (the double's `getSchemaBuilder()` delegates to a real, migrated
     * throwaway connection so `hasTable()` reports true for both), but the double's `table()`
     * throws whenever the repository actually tries to read rows -- a generic query failure, NOT
     * an invalid-table-name subclass. The repository/controller carry no catch-all, so this
     * propagates uncaught; rendered through the framework's own {@see Handler} (the same
     * conversion `Application::handle()` performs for every uncaught exception reaching the
     * kernel) it is a 500.
     */
    public function testUnexpectedQueryFailurePropagatesAsFiveHundred(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $controller = $this->controllerWith(new OrderPaymentSummaryRepository($this->queryFailureConnection()));

        try {
            $controller->payments(Request::create('/x', 'GET'), $orderUuid);
            self::fail('Expected the simulated query failure to propagate uncaught.');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated Payvia query failure', $e->getMessage());
            $response = (new Handler())->render($e);
            self::assertSame(500, $response->getStatusCode());
        }
    }

    // ==================================================================
    // Refund block: echoed from the already-validated order row
    // ==================================================================

    public function testRefundBlockEchoesOrderAggregates(): void
    {
        $orderUuid = $this->seedOrder('', ['refunded_total' => 250, 'refund_revision' => 3]);
        $controller = $this->controllerWith(new OrderPaymentSummaryRepository($this->connection()));

        $response = $controller->payments(Request::create('/x', 'GET'), $orderUuid);
        $data = $this->decode($response)['data'];

        self::assertSame(['refunded_total' => 250, 'refund_revision' => 3], $data['refund']);
    }

    // ==================================================================
    // Envelope invariant on every 200
    // ==================================================================

    public function testEnvelopeKeysArePresentOnEveryTwoHundredRegardlessOfAvailability(): void
    {
        $orderUuid = $this->seedOrder('', []);

        $available = $this->controllerWith(new OrderPaymentSummaryRepository($this->connection()))
            ->payments(Request::create('/x', 'GET'), $orderUuid);
        $unavailable = $this->controllerWith(new OrderPaymentSummaryRepository($this->freshUnmigratedConnection()))
            ->payments(Request::create('/x', 'GET'), $orderUuid);

        foreach ([$available, $unavailable] as $response) {
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $data = $this->decode($response)['data'];
            self::assertEqualsCanonicalizing(['available', 'payments', 'intents', 'refund'], array_keys($data));
            self::assertEqualsCanonicalizing(['refunded_total', 'refund_revision'], array_keys($data['refund']));
        }
    }

    // ==================================================================
    // Kernel-driven: route registration + authorization matrix
    // ==================================================================

    public function testRouteIsRegisteredWithViewAuthority(): void
    {
        $route = $this->findRoute('GET', '/v1/admin/commerce/orders/{uuid}/payments');
        self::assertNotNull($route, 'GET ' . self::ROUTE_TEMPLATE . ' must be registered');
        self::assertContains('content_permission:commerce.view,commerce.manage', (array) $route['middleware']);
    }

    public function testAnonymousRequestIsRejectedWith401(): void
    {
        $orderUuid = $this->seedOrder('', []);

        $response = $this->handle($this->jsonRequest('GET', $this->routeFor($orderUuid)));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testNoPermissionActorIsRejectedWith403(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $key = $this->seedApiKeyUser([], []);

        $response = $this->handle($this->apiKeyRequest('GET', $this->routeFor($orderUuid), $key));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testViewOnlyActorIsAllowed(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);

        $response = $this->handle($this->apiKeyRequest('GET', $this->routeFor($orderUuid), $key));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testManageActorIsAllowed(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);

        $response = $this->handle($this->apiKeyRequest('GET', $this->routeFor($orderUuid), $key));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    /** End-to-end proof that the real kernel/DI wiring (route -> container -> repository) works. */
    public function testKernelDrivenRequestReturnsTheInvariantEnvelope(): void
    {
        $orderUuid = $this->seedOrder('', []);
        $this->seedPayment('', $orderUuid);
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);

        $response = $this->handle($this->apiKeyRequest('GET', $this->routeFor($orderUuid), $key));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'];
        self::assertTrue($data['available']);
        self::assertCount(1, $data['payments']);
        self::assertEqualsCanonicalizing(['available', 'payments', 'intents', 'refund'], array_keys($data));
    }

    // ==================================================================
    // doubles
    // ==================================================================

    /** A fresh, genuinely empty (never-migrated) in-memory SQLite connection -- no tables at all. */
    private function freshUnmigratedConnection(): Connection
    {
        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
    }

    /**
     * A `Connection` whose every touched method throws -- standing in for "must never be
     * consulted at all". `getSchemaBuilder()` AND `table()` are both overridden, so even the
     * availability probe itself would blow up if the controller reached it.
     */
    private function explodingConnection(): Connection
    {
        return new class ([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]) extends Connection {
            public function getSchemaBuilder(): SchemaBuilderInterface
            {
                throw new \RuntimeException('unexpected Payvia query: getSchemaBuilder()');
            }

            public function table(string $table): QueryBuilder
            {
                throw new \RuntimeException('unexpected Payvia query: table(' . $table . ')');
            }
        };
    }

    /**
     * `getSchemaBuilder()` delegates to a REAL, migrated (both tables created) throwaway SQLite
     * connection -- so `hasTable()` genuinely reports true for both -- while `table()` throws a
     * plain `RuntimeException`, simulating an unexpected failure on an otherwise-available table
     * (a lost connection, a permissions error, ...), never an invalid-table-name condition.
     */
    private function queryFailureConnection(): Connection
    {
        $delegate = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $schema = $delegate->getSchemaBuilder();
        $schema->createTable('payments', static function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
        });
        $schema->createTable('payment_intents', static function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
        });

        return new class ($delegate) extends Connection {
            public function __construct(private readonly Connection $delegate)
            {
                // Deliberately skip parent::__construct(): every method below either
                // delegates to the real, already-migrated $delegate (getSchemaBuilder(), so
                // hasTable() reports true) or throws (table()) -- nothing else is ever called.
            }

            public function getSchemaBuilder(): SchemaBuilderInterface
            {
                return $this->delegate->getSchemaBuilder();
            }

            public function table(string $table): QueryBuilder
            {
                throw new \RuntimeException('simulated Payvia query failure');
            }
        };
    }

    // ==================================================================
    // drivers
    // ==================================================================

    private function controllerWith(OrderPaymentSummaryRepository $summaries): AdminOrderPaymentsController
    {
        return new AdminOrderPaymentsController(
            $this->appContext(),
            $this->container()->get(OrderRepository::class),
            $summaries,
            $this->container()->get(CommerceTenantResolution::class),
        );
    }

    /** @return array<string,mixed> */
    private function decode(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function routeFor(string $orderUuid): string
    {
        return '/v1/admin/commerce/orders/' . $orderUuid . '/payments';
    }

    // ==================================================================
    // seeding
    // ==================================================================

    /**
     * @param array<string,mixed> $overrides
     * @return string the seeded order's uuid
     */
    private function seedOrder(string $tenant, array $overrides): string
    {
        $uuid = Utils::generateNanoID();
        $defaults = [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 0,
            'refund_revision' => 0,
            'refunded_total' => 0,
            'email' => 'buyer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => '2026-01-15 12:00:00',
            'created_at' => '2026-01-15 12:00:00',
        ];
        $this->connection()->table('commerce_orders')->insert(array_merge($defaults, $overrides, ['uuid' => $uuid]));

        return $uuid;
    }

    /**
     * A draft order per the engine's walk-in-order schema — mirrors
     * {@see AdminOrderSearchTest::seedDraftOrder()}.
     *
     * @return string the seeded draft's uuid
     */
    private function seedDraftOrder(string $tenant): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => null,
            'status' => 'draft',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 0,
            'refund_revision' => 0,
            'refunded_total' => 0,
            'email' => null,
            'user_uuid' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'draft_revision' => 0,
            'placed_at' => null,
            'created_at' => '2026-01-15 12:00:00',
        ]);

        return $uuid;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return string the seeded payment's reference (unique, used as the ordering probe)
     */
    private function seedPayment(string $tenant, string $orderUuid, array $overrides = []): string
    {
        $uuid = Utils::generateNanoID();
        $defaults = [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'payable_type' => OrderPayable::TYPE,
            'payable_id' => $orderUuid,
            'gateway' => 'paystack',
            'gateway_transaction_id' => 'gtx_' . $uuid,
            'reference' => 'pay_' . $uuid,
            'amount' => 1500,
            'currency' => 'USD',
            'status' => 'succeeded',
            'message' => null,
            'metadata' => null,
            'raw_payload' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
        $row = array_merge($defaults, $overrides, ['uuid' => $uuid]);
        $this->connection()->table('payments')->insert($row);
        $this->paymentUuids[] = $uuid;

        return (string) $row['reference'];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return string the seeded intent's reference (unique, used as the ordering probe)
     */
    private function seedIntent(string $tenant, string $orderUuid, array $overrides = []): string
    {
        $uuid = Utils::generateNanoID();
        $defaults = [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'payable_type' => OrderPayable::TYPE,
            'payable_id' => $orderUuid,
            'idempotency_key' => OrderPayable::TYPE . ':' . $orderUuid . ':' . $uuid,
            'gateway' => 'paystack',
            'reference' => 'int_' . $uuid,
            'status' => 'open',
            'amount' => 1500,
            'currency' => 'USD',
            'payload' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => null,
        ];
        $row = array_merge($defaults, $overrides, ['uuid' => $uuid]);
        $this->connection()->table('payment_intents')->insert($row);
        $this->intentUuids[] = $uuid;

        return (string) $row['reference'];
    }

    /** @param list<string> $scopes */
    private function seedApiKeyUser(array $grantedPermissionSlugs, array $scopes): string
    {
        $userUuid = Utils::generateNanoID();
        $this->apiKeyUserUuids[] = $userUuid;

        $this->connection()->table('users')->insert([
            'uuid' => $userUuid,
            'username' => 'ordpayments_' . substr($userUuid, 0, 8),
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
            'name' => 'order-payments-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'ordpayments_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'order payments test role',
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

    /** Real X-API-Key header, mirrors AdminOrderSearchTest::apiKeyRequest(). */
    private function apiKeyRequest(string $method, string $path, string $key): Request
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
        );
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
