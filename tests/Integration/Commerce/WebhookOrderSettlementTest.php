<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Payments\OrderPayable;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Helpers\Utils;
use Thallo\Commerce\Payments\WebhookOrderSettlementListener;

/**
 * THE WEBHOOK → ORDER SETTLEMENT LANE (payment-links final review).
 *
 * The final whole-program review established there was NO in-tree path from a provider webhook to
 * a paid order: `ConfirmationDispatcher` was reachable only from the AUTHENTICATED
 * `POST /payvia/payments/confirm`, and payvia's webhook fan-out (bus + strict lane + chargebacks)
 * touched none of it. {@see WebhookOrderSettlementListener} is that missing bridge, and this suite
 * is its acceptance.
 *
 * Verification posture, pinned rather than assumed: payvia's `WebhookService::ingest()` verifies
 * the provider signature and returns 401 BEFORE parsing/persisting/dispatching anything, so every
 * event reaching a strict listener is already provider-verified. This listener therefore performs
 * no signature work; what it verifies is OWNERSHIP — a `payment_intents` row for
 * `(tenant, gateway, reference)` whose payable is a Commerce order.
 *
 * Runs in the harness's default sentinel tenancy mode (tenant `''`), like the other suites here.
 */
final class WebhookOrderSettlementTest extends AppTestCase
{
    private const GATEWAY = 'stub-psp';

    private static int $seq = 0;

    /** @var list<string> */
    private array $seededIntents = [];

    protected function tearDown(): void
    {
        // `payment_intents` is payvia's table and is not in AppTestCase's truncate list.
        foreach ($this->seededIntents as $uuid) {
            $this->connection()->table('payment_intents')->where(['uuid' => $uuid])->delete();
        }
        $this->seededIntents = [];

        parent::tearDown();
    }

    // ==================================================================
    // Wiring
    // ==================================================================

    public function testTheListenerIsRegisteredOnPayviasStrictLaneAndNotMerelyBound(): void
    {
        self::assertTrue($this->container()->has(WebhookOrderSettlementListener::class));

        $tagged = $this->container()->has(StrictPaymentEventListener::CONTAINER_TAG)
            ? $this->container()->get(StrictPaymentEventListener::CONTAINER_TAG)
            : [];
        self::assertIsIterable($tagged);

        $classes = [];
        foreach ($tagged as $listener) {
            $classes[] = $listener::class;
        }

        // The strict lane, NOT the fault-isolated bus: a settlement failure must release the
        // webhook's dispatch lease and be retried, never be swallowed and logged.
        self::assertContains(WebhookOrderSettlementListener::class, $classes);
    }

    public function testOnlyVerifiedPaymentSuccessEventsCarryingAReferenceAreSupported(): void
    {
        $listener = $this->listener();

        self::assertTrue($listener->supports($this->event(EventType::PAYMENT_SUCCEEDED, 'ref-1', 100, 'USD')));
        self::assertFalse($listener->supports($this->event(EventType::PAYMENT_FAILED, 'ref-1', 100, 'USD')));
        self::assertFalse($listener->supports($this->event(EventType::INVOICE_PAID, 'ref-1', 100, 'USD')));
        self::assertFalse($listener->supports($this->event(EventType::CHARGEBACK_CREATED, 'ref-1', 100, 'USD')));
        // No reference means nothing addressable — and `supports()` must stay side-effect-free,
        // so the check is a pure payload read.
        self::assertFalse($listener->supports($this->event(EventType::PAYMENT_SUCCEEDED, '', 100, 'USD')));
    }

    // ==================================================================
    // The happy path
    // ==================================================================

    public function testAVerifiedSuccessWebhookPaysTheOrderConsumesTheLinkAndSettlesTheIntent(): void
    {
        [$order, $linkUuid, $reference] = $this->pendingOrderWithLinkAndIntent();

        $this->listener()->handle($this->successFor($order, $reference));

        self::assertSame('paid', $this->orderStatus($order['uuid']));
        self::assertSame('consumed', $this->linkStatus($linkUuid));
        self::assertSame(
            PaymentIntentRepository::STATUS_CLOSED,
            $this->intentStatus(self::GATEWAY, $reference),
        );
    }

    // ==================================================================
    // Idempotency under redelivery
    // ==================================================================

    public function testARedeliveredWebhookIsANoOpRatherThanASecondSettlement(): void
    {
        [$order, $linkUuid, $reference] = $this->pendingOrderWithLinkAndIntent();
        $event = $this->successFor($order, $reference);

        $this->listener()->handle($event);
        $eventsAfterFirst = $this->orderEventTypes($order['uuid']);

        // Three more deliveries of the identical logical event.
        $this->listener()->handle($event);
        $this->listener()->handle($event);
        $this->listener()->handle($event);

        self::assertSame('paid', $this->orderStatus($order['uuid']));
        self::assertSame('consumed', $this->linkStatus($linkUuid));
        self::assertSame(
            PaymentIntentRepository::STATUS_CLOSED,
            $this->intentStatus(self::GATEWAY, $reference),
        );

        // The paid-order CAS refuses the repeat, so the money-moving events do not multiply;
        // the engine records the replays as late payments instead.
        $after = $this->orderEventTypes($order['uuid']);
        self::assertSame(
            $this->occurrencesOf($eventsAfterFirst, 'payment_confirmed'),
            $this->occurrencesOf($after, 'payment_confirmed'),
            'a redelivery must not confirm the payment a second time',
        );
    }

    public function testAWebhookForASupersededAttemptSettlesThatRowAndIsRefusedByThePaidOrderCas(): void
    {
        [$order, $linkUuid, $firstReference] = $this->pendingOrderWithLinkAndIntent();

        // The payer initiated twice: the first attempt is superseded, a second attempt opens with
        // its OWN reference. Then the SECOND one settles the order.
        $firstIntent = $this->intentRow(self::GATEWAY, $firstReference);
        $this->intents()->supersede($this->appContext(), (string) $firstIntent['uuid']);
        $secondReference = $this->seedIntent($order, 'ref-second-' . (++self::$seq));

        $this->listener()->handle($this->successFor($order, $secondReference));
        self::assertSame('paid', $this->orderStatus($order['uuid']));

        // NOW the provider delivers a late webhook for the SUPERSEDED attempt. It must settle
        // THAT row (reference-addressable, T3) and be refused by the order's paid CAS.
        $this->listener()->handle($this->successFor($order, $firstReference));

        self::assertSame(
            PaymentIntentRepository::STATUS_CLOSED,
            $this->intentStatus(self::GATEWAY, $firstReference),
            'the superseded attempt settles its OWN row',
        );
        self::assertSame(
            PaymentIntentRepository::STATUS_CLOSED,
            $this->intentStatus(self::GATEWAY, $secondReference),
            'the winning attempt stays settled',
        );
        self::assertSame('paid', $this->orderStatus($order['uuid']));
        self::assertSame('consumed', $this->linkStatus($linkUuid));
        self::assertContains(
            'payment_late_rejected',
            $this->orderEventTypes($order['uuid']),
            'a payment arriving after the order is paid is recorded, never applied twice',
        );
    }

    // ==================================================================
    // Refusals — identical to the authenticated route's
    // ==================================================================

    /** @dataProvider mismatchedMoney */
    public function testAnAmountOrCurrencyMismatchTakesTheSameRefusalPathAsTheAuthenticatedRoute(
        int $amountDelta,
        ?string $currency
    ): void {
        [$order, $linkUuid, $reference] = $this->pendingOrderWithLinkAndIntent();

        $this->listener()->handle($this->event(
            EventType::PAYMENT_SUCCEEDED,
            $reference,
            (int) $order['grand_total'] + $amountDelta,
            $currency ?? (string) $order['currency'],
        ));

        self::assertSame('pending_payment', $this->orderStatus($order['uuid']));
        self::assertSame('active', $this->linkStatus($linkUuid));
        self::assertContains('payment_amount_mismatch', $this->orderEventTypes($order['uuid']));
    }

    /** @return array<string, array{0: int, 1: ?string}> */
    public static function mismatchedMoney(): array
    {
        return [
            'the provider took more' => [1, null],
            'the provider took less' => [-1, null],
            'a different currency' => [0, 'XTS'],
        ];
    }

    public function testAnUnknownReferenceIsIgnoredQuietly(): void
    {
        [$order, $linkUuid, ] = $this->pendingOrderWithLinkAndIntent();

        // No exception: an unmatched reference is the ordinary case for a shared gateway
        // account, not an error — and throwing would put the webhook into an endless retry.
        $this->listener()->handle($this->event(
            EventType::PAYMENT_SUCCEEDED,
            'a-reference-this-store-never-issued',
            (int) $order['grand_total'],
            (string) $order['currency'],
        ));

        self::assertSame('pending_payment', $this->orderStatus($order['uuid']));
        self::assertSame('active', $this->linkStatus($linkUuid));
    }

    public function testAnEventStatingNoAmountOrNoCurrencyIsNotActedOn(): void
    {
        [$order, $linkUuid, $reference] = $this->pendingOrderWithLinkAndIntent();

        // We will not substitute the intent's own figures for facts the provider did not state:
        // doing so would assert an amount nobody confirmed AND neuter the mismatch guard.
        $this->listener()->handle($this->rawEvent(['reference' => $reference, 'currency' => 'USD']));
        $this->listener()->handle($this->rawEvent([
            'reference' => $reference,
            'amount' => (int) $order['grand_total'],
        ]));

        self::assertSame('pending_payment', $this->orderStatus($order['uuid']));
        self::assertSame('active', $this->linkStatus($linkUuid));
        self::assertSame(
            PaymentIntentRepository::STATUS_OPEN,
            $this->intentStatus(self::GATEWAY, $reference),
        );
    }

    public function testAnIntentForSomebodyElsesPayableIsLeftAlone(): void
    {
        $reference = 'ref-foreign-' . (++self::$seq);
        $uuid = Utils::generateNanoID();
        $this->intents()->createOpen($this->appContext(), [
            'uuid' => $uuid,
            'payable_type' => 'billing_subscription',
            'payable_id' => 'sub-' . self::$seq,
            'gateway' => self::GATEWAY,
            'reference' => $reference,
            'amount' => 1000,
            'currency' => 'USD',
        ]);
        $this->seededIntents[] = $uuid;

        $this->listener()->handle($this->event(EventType::PAYMENT_SUCCEEDED, $reference, 1000, 'USD'));

        // Payvia is shared infrastructure; only a Commerce-order payable is ours to settle, and
        // an intent we do not own is not settled by us either.
        self::assertSame(
            PaymentIntentRepository::STATUS_OPEN,
            $this->intentStatus(self::GATEWAY, $reference),
        );
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function listener(): WebhookOrderSettlementListener
    {
        return $this->container()->get(WebhookOrderSettlementListener::class);
    }

    private function intents(): PaymentIntentRepository
    {
        return $this->container()->get(PaymentIntentRepository::class);
    }

    /**
     * A pending admin-origin order with an active payment link and an OPEN payvia intent — the
     * exact state a payer is in the moment they land on a hosted checkout page.
     *
     * @return array{0: array<string,mixed>, 1: string, 2: string}
     */
    private function pendingOrderWithLinkAndIntent(): array
    {
        $order = $this->seedPayableOrder();
        $minted = $this->container()->get(PaymentLinkService::class)
            ->mint($this->appContext(), '', (string) $order['uuid'], null, 'actorwebhook');
        $reference = $this->seedIntent($order, 'ref-' . (++self::$seq) . '-' . substr((string) $order['uuid'], 0, 6));

        return [$order, $minted['link']->linkUuid, $reference];
    }

    /** @param array<string,mixed> $order */
    private function seedIntent(array $order, string $reference): string
    {
        $uuid = Utils::generateNanoID();
        $this->intents()->createOpen($this->appContext(), [
            'uuid' => $uuid,
            'payable_type' => OrderPayable::TYPE,
            'payable_id' => (string) $order['uuid'],
            'gateway' => self::GATEWAY,
            'reference' => $reference,
            'amount' => (int) $order['grand_total'],
            'currency' => (string) $order['currency'],
        ]);
        $this->seededIntents[] = $uuid;

        return $reference;
    }

    /** @return array<string,mixed> */
    private function seedPayableOrder(): array
    {
        $uuid = Utils::generateNanoID();
        $number = 'WHS-' . (++self::$seq) . '-' . substr($uuid, 0, 5);
        $this->connection()->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_number' => $number,
            'status' => 'pending_payment',
            'origin' => 'admin',
            'currency' => 'USD',
            'subtotal' => 2500,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 2500,
        ]);

        $order = $this->container()->get(OrderRepository::class)
            ->findByUuid($this->appContext(), '', $uuid);
        self::assertIsArray($order);

        return $order;
    }

    /** @param array<string,mixed> $order */
    private function successFor(array $order, string $reference): PaymentProviderEventInterface
    {
        return $this->event(
            EventType::PAYMENT_SUCCEEDED,
            $reference,
            (int) $order['grand_total'],
            (string) $order['currency'],
        );
    }

    private function event(
        string $type,
        string $reference,
        int $amount,
        string $currency
    ): PaymentProviderEventInterface {
        return $this->rawEvent([
            'reference' => $reference,
            'amount' => $amount,
            'amount_unit' => 'minor',
            'currency' => $currency,
            'status' => 'success',
        ], $type);
    }

    /** @param array<string,mixed> $normalized */
    private function rawEvent(
        array $normalized,
        string $type = EventType::PAYMENT_SUCCEEDED
    ): PaymentProviderEventInterface {
        $reference = (string) ($normalized['reference'] ?? 'none');

        return ProviderEvent::create(
            self::GATEWAY,
            $type,
            'evt_' . $reference,
            'delivery_' . $reference,
            $reference,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $normalized,
            [],
        );
    }

    private function orderStatus(string $uuid): string
    {
        $rows = $this->connection()->table('commerce_orders')
            ->select(['status'])->where(['uuid' => $uuid])->limit(1)->get();

        return (string) ($rows[0]['status'] ?? '');
    }

    private function linkStatus(string $linkUuid): string
    {
        $rows = $this->connection()->table(PaymentLinkRepository::TABLE)
            ->select(['status'])->where(['uuid' => $linkUuid])->limit(1)->get();

        return (string) ($rows[0]['status'] ?? '');
    }

    private function intentStatus(string $gateway, string $reference): string
    {
        $row = $this->intentRow($gateway, $reference);

        return (string) ($row['status'] ?? '');
    }

    /** @return array<string,mixed> */
    private function intentRow(string $gateway, string $reference): array
    {
        $row = $this->intents()->findByReference($this->appContext(), $gateway, $reference);
        self::assertIsArray($row, "no intent for {$gateway}/{$reference}");

        return $row;
    }

    /** @return list<string> */
    private function orderEventTypes(string $orderUuid): array
    {
        $rows = $this->connection()->table('commerce_order_events')
            ->select(['type'])->where(['order_uuid' => $orderUuid])->get();

        return array_map(static fn (array $row): string => (string) $row['type'], $rows);
    }

    /** @param list<string> $types */
    private function occurrencesOf(array $types, string $type): int
    {
        return count(array_filter($types, static fn (string $t): bool => $t === $type));
    }
}
