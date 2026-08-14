<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Events\LatePaymentRejected;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Helpers\Utils;
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
 * order somebody else already settled. So the surface records once per (order, reference) and the
 * order-event trail — which the engine writes before it dispatches — is the durable memory.
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
     * Fault isolation. This listener sits on the webhook settlement lane: a throw from it would
     * turn a correctly-refused late payment into a failed webhook the provider then retries
     * forever. It is best-effort by construction — an unusable payload is dropped, never raised.
     */
    public function testAnUnusablePayloadIsDroppedRatherThanRaised(): void
    {
        $listener = new LatePaymentVisibilityListener(
            $this->appContext(),
            $this->container()->get(OrderRepository::class),
            $this->container()->get(\Glueful\Extensions\Audit\Contracts\AuditRecorderInterface::class),
        );

        $listener->onLatePaymentRejected(new LatePaymentRejected([]));
        $listener->onLatePaymentRejected(new LatePaymentRejected(['order_uuid' => '']));

        self::assertSame([], $this->auditRows());
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
    private function auditRows(): array
    {
        $statement = $this->connection()->getPDO()->query(
            "SELECT * FROM audit_logs WHERE action = '"
            . LatePaymentVisibilityListener::AUDIT_ACTION . "' ORDER BY id ASC"
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

    private function seedOrder(): string
    {
        $uuid = Utils::generateNanoID();
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
