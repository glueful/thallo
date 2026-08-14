<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Glueful\Extensions\Commerce\Events\LatePaymentRejected;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Helpers\Utils;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Thallo\Commerce\Payments\LatePaymentVisibilityListener;

/**
 * Cleanup-train Task 10: `LatePaymentRejected` finally has a subscriber.
 *
 * ## Why the audit log, and not a notification row
 *
 * The engine already records a `payment_late_rejected` order EVENT, and the admin order page
 * already renders it — so the fact was never lost, it was only undiscoverable: an operator had to
 * already be looking at the one order it happened to. The OUTSTANDING entry asks for an operator
 * NOTIFICATION, and the cheapest honest surface in this tree that actually satisfies "an operator
 * can find this without knowing which order to open" is the audit log: `glueful/audit` is enabled
 * in `config/extensions.php`, ships `GET /audit-logs` behind `audit.view`, and the admin SPA
 * already has an Audit Log page with a category filter. Zero new tables, endpoints or pages.
 *
 * The framework's `notifications` table was rejected deliberately: nothing in this repo READS it
 * — no route, no SPA view — so a row written there would be an operator notification only in
 * name, and making it real would mean building the notification system the task forbids.
 *
 * `security` is the category because it is one of the categories the shipped filter dropdown
 * offers (`admin/src/queries/audit.ts`) and it is the one this app already uses for custody facts
 * (`App\Support\TenancyLifecycleAudit`). A category outside that list would file the entry where
 * an operator cannot filter for it.
 *
 * ## Why the de-dupe key is the payment reference
 *
 * `rejectLatePayment()` also fires on the T4 idempotent-concession path: when two settlement
 * paths race, the LOSER of the `pending_payment -> paid` CAS routes its confirmation here even
 * though the money was applied correctly. A provider that redelivers that same webhook — which is
 * exactly what providers do — re-fires it again, with the SAME reference every time. Those are
 * one payment, and one payment that settled this order needs no refund. A genuinely
 * refund-relevant late payment is a DIFFERENT reference: a second, distinct attempt against an
 * order somebody else already settled. So the surface records once per (order, reference).
 *
 * Fix round 1: the memory is OUR OWN audit writes, not the engine's order-event trail. The
 * engine's order event is written unconditionally and synchronously, before it dispatches, so two
 * concurrent rejections of the same reference both land their order-event row before either
 * listener invocation checks anything — counting THAT trail let both racers see two matching rows
 * and both skip, dropping the entry entirely. Counting our own audit rows means the same race can
 * at worst duplicate an entry, never drop one. See {@see LatePaymentVisibilityListener}'s docblock.
 */
final class LatePaymentVisibilityTest extends AppTestCase
{
    private const REFERENCE = 'ps_ref_late_0001';

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM audit_logs WHERE action = '"
            . LatePaymentVisibilityListener::AUDIT_ACTION . "'");
        $pdo->exec('DELETE FROM commerce_order_events');
        $pdo->exec('DELETE FROM commerce_orders');
        parent::tearDown();
    }

    /**
     * End to end through the REAL engine service and the REAL event bus: nothing here constructs
     * the listener or dispatches the event by hand, so a passing assertion is also proof the
     * pack's provider actually wired the subscriber on this boot.
     */
    public function testARealLateRejectionLandsAnOperatorVisibleAuditEntry(): void
    {
        $order = $this->seedOrder();

        $this->reject($order, self::REFERENCE);

        $rows = $this->auditRows();
        self::assertCount(1, $rows, 'the late rejection must be discoverable outside the order page');

        $row = $rows[0];
        self::assertSame('security', $row['category']);
        self::assertSame('commerce_order', $row['target_type']);
        self::assertSame($order, $row['target_uuid']);
        self::assertNull($row['actor_uuid'], 'a provider-driven rejection has no operator actor');

        $context = json_decode((string) $row['context'], true);
        self::assertIsArray($context);
        self::assertSame(self::REFERENCE, $context['reference']);
        self::assertSame(1500, $context['amount']);
        self::assertSame('USD', $context['currency']);
        self::assertSame($this->tenant(), $context['tenant_uuid']);
    }

    /**
     * The de-dupe proper. Three deliveries of the SAME reference — the provider-retry shape — is
     * one refund question, so it is one entry; the engine's own event trail still records all
     * three, which is what makes the de-dupe a display decision rather than a lost fact.
     */
    public function testRepeatedDeliveriesOfTheSameReferenceRecordExactlyOneEntry(): void
    {
        $order = $this->seedOrder();

        $this->reject($order, self::REFERENCE);
        $this->reject($order, self::REFERENCE);
        $this->reject($order, self::REFERENCE);

        self::assertCount(1, $this->auditRows(), 'a redelivered reference is not a new refund question');
        self::assertSame(
            3,
            $this->lateEventCount($order),
            'the engine trail must still hold every rejection — the de-dupe is ours, not its',
        );
    }

    /** A DIFFERENT reference against the same order is the genuine case, and must surface again. */
    public function testADifferentReferenceOnTheSameOrderIsSurfacedSeparately(): void
    {
        $order = $this->seedOrder();

        $this->reject($order, self::REFERENCE);
        $this->reject($order, 'ps_ref_late_0002');
        $this->reject($order, self::REFERENCE);

        $references = array_map(
            static fn (array $row): string => (string) json_decode((string) $row['context'], true)['reference'],
            $this->auditRows(),
        );

        self::assertSame([self::REFERENCE, 'ps_ref_late_0002'], $references);
    }

    /** Two orders never de-dupe against each other, even on an identical reference string. */
    public function testTheDeDupeIsScopedToTheOrder(): void
    {
        $first = $this->seedOrder();
        $second = $this->seedOrder();

        $this->reject($first, self::REFERENCE);
        $this->reject($second, self::REFERENCE);

        self::assertCount(2, $this->auditRows());
    }

    /**
     * Fault isolation. Every failure is caught rather than allowed to propagate — see the
     * listener's class docblock for why (NOT to protect a webhook retry loop; the ordinary
     * EventService dispatch bus already fault-isolates each listener on its own). An unusable
     * payload is dropped, never raised.
     */
    public function testAnUnusablePayloadIsDroppedRatherThanRaised(): void
    {
        $listener = new LatePaymentVisibilityListener(
            $this->appContext(),
            $this->container()->get(AuditRecorderInterface::class),
        );

        $listener->onLatePaymentRejected(new LatePaymentRejected([]));
        $listener->onLatePaymentRejected(new LatePaymentRejected(['order_uuid' => '']));

        self::assertSame([], $this->auditRows());
    }

    /**
     * Fix round 1 (Important finding #1): reproduces the exact interleaving that used to zero
     * out the audit entry. The OLD predicate counted matching `payment_late_rejected` order
     * EVENTS; the engine writes that event unconditionally and synchronously, before it
     * dispatches, so two racing `rejectLatePayment()` calls for the same reference both land
     * their order-event row before either listener invocation checks anything. Seeding that exact
     * precondition directly — two order events, ZERO audit rows — and then running the listener
     * once proves the corrected predicate (which counts audit rows, not order events) still
     * writes the entry instead of seeing "2 matches" and skipping.
     */
    public function testTheRaceThatUsedToZeroOutTheEntryStillWritesOne(): void
    {
        $order = $this->seedOrder();

        $orders = $this->container()->get(OrderRepository::class);
        $orders->recordEvent(
            $this->appContext(),
            $order,
            'payment_late_rejected',
            ['reference' => self::REFERENCE],
        );
        $orders->recordEvent(
            $this->appContext(),
            $order,
            'payment_late_rejected',
            ['reference' => self::REFERENCE],
        );

        self::assertSame(
            [],
            $this->auditRows(),
            'precondition: two order-event rows exist but neither racer wrote an audit row yet',
        );

        $listener = new LatePaymentVisibilityListener(
            $this->appContext(),
            $this->container()->get(AuditRecorderInterface::class),
        );
        $listener->onLatePaymentRejected(new LatePaymentRejected([
            'order_uuid' => $order,
            'tenant_uuid' => $this->tenant(),
            'reference' => self::REFERENCE,
            'status' => 'success',
            'amount' => 1500,
            'currency' => 'USD',
        ]));

        self::assertCount(
            1,
            $this->auditRows(),
            'the old order-event-counting predicate would see 2 rows and skip, losing the entry; '
                . 'counting audit rows instead must still write it',
        );
    }

    /**
     * Fix round 1 (Important finding #2): a failure must be diagnosable, not silently discarded.
     * The rationale for swallowing here is display-decision fault isolation, not "an uncaught
     * exception would break the webhook" — the ordinary EventService dispatch bus already
     * catches and logs each listener independently. So a throwing audit recorder must still
     * produce a log line through the injected logger before the failure is swallowed.
     */
    public function testAThrowingAuditRecorderIsLoggedNotSilentlySwallowed(): void
    {
        $order = $this->seedOrder();
        $logger = $this->capturingLogger();

        $listener = new LatePaymentVisibilityListener(
            $this->appContext(),
            $this->throwingAuditRecorder(),
            $logger,
        );

        $listener->onLatePaymentRejected(new LatePaymentRejected([
            'order_uuid' => $order,
            'tenant_uuid' => $this->tenant(),
            'reference' => self::REFERENCE,
            'status' => 'success',
            'amount' => 1500,
            'currency' => 'USD',
        ]));

        self::assertNotSame([], $logger->records, 'a swallowed failure must still be logged');
        self::assertSame('error', $logger->records[0]['level']);
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function reject(string $orderUuid, string $reference): void
    {
        $this->container()->get(OrderPaymentService::class)->rejectLatePayment(
            $this->appContext(),
            $this->tenant(),
            $orderUuid,
            ['reference' => $reference, 'status' => 'success', 'amount' => 1500, 'currency' => 'USD'],
        );
    }

    /** @return list<array<string,mixed>> */
    /**
     * Scoped to the orders THIS test seeded: sibling classes (the webhook settlement suite)
     * legitimately produce late rejections of their own, and within-process class order is not
     * guaranteed — an unscoped count is a cross-class contamination hazard, not extra rigor.
     */
    private function auditRows(): array
    {
        $uuids = "'" . implode("','", $this->seededOrders) . "'";
        $statement = $this->connection()->getPDO()->query(
            "SELECT * FROM audit_logs WHERE action = '"
            . LatePaymentVisibilityListener::AUDIT_ACTION . "'"
            . " AND target_uuid IN ({$uuids}) ORDER BY id ASC"
        );

        return $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function lateEventCount(string $orderUuid): int
    {
        return (int) $this->connection()->table('commerce_order_events')
            ->where('order_uuid', $orderUuid)
            ->where('type', 'payment_late_rejected')
            ->count();
    }

    private function tenant(): string
    {
        return $this->container()->get(CommerceTenantResolution::class)->tenantUuid($this->appContext());
    }

    private function throwingAuditRecorder(): AuditRecorderInterface
    {
        return new class implements AuditRecorderInterface {
            public function record(AuditEntry $entry): void
            {
                throw new \RuntimeException('audit backend unavailable');
            }
        };
    }

    /** Mirrors CompleteSaleTest's spy logger. */
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

    /** @var list<string> */
    private array $seededOrders = [];

    private function seedOrder(): string
    {
        $uuid = Utils::generateNanoID();
        $this->seededOrders[] = $uuid;
        $this->connection()->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $this->tenant(),
            'order_number' => 'LPV-' . substr($uuid, 0, 6),
            'status' => 'paid',
            'origin' => 'storefront',
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
        ]);

        return $uuid;
    }
}
