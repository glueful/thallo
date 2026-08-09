<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCancellationModeProvider;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCheckoutLifecycleCapableGateway;

/**
 * Task 17 (cancel/abandon, workspace self-serve checkout plan): a fully-capable recording test
 * double -- registered via `GatewayManager::registerDriver()` exactly like Task 16's
 * {@see RecordingSubscriptionCheckoutGateway} -- mirroring Stripe's own capability set
 * (`SubscriptionCapableGateway` + `SubscriptionCancellationModeProvider` declaring
 * `['stop_renewal', 'immediate']` + `SubscriptionCheckoutLifecycleCapableGateway`) so
 * `SelfBillingController::cancel()`/`abandon()` can be exercised against the REAL
 * `GatewayManager`/`PayviaCheckoutGateway` stack without a live provider HTTP call.
 */
final class RecordingSubscriptionLifecycleGateway implements
    PaymentGatewayInterface,
    SubscriptionCapableGateway,
    SubscriptionCancellationModeProvider,
    SubscriptionCheckoutLifecycleCapableGateway
{
    /** @var list<array{id:string,atPeriodEnd:bool}> */
    public array $cancelCalls = [];

    /** @var list<string> */
    public array $abandonCalls = [];

    /** @var list<'stop_renewal'|'immediate'> */
    public array $modes = ['stop_renewal', 'immediate'];

    /** @var 'confirmed_dead'|'still_live'|'unsupported'|'unknown' */
    public string $abandonOutcome = 'confirmed_dead';

    public ?\Throwable $abandonThrows = null;

    /**
     * Task 17 (code review Important #2): a side-effect hook invoked right before
     * `abandonSubscriptionCheckout()` returns its outcome -- the ONLY deterministic way, in a
     * single-threaded test, to inject a race between `SelfBillingController::abandon()`'s own
     * top-of-method guard/origination read and `finishAbandon()`'s later transition/guard-release
     * writes (both of which run strictly AFTER this call returns). Used to prove
     * `checkout_abandon_conflict` actually rolls back atomically when a concurrent actor rebinds
     * the guard, or moves the origination off `pending`, in that exact window.
     */
    public ?\Closure $onAbandonCall = null;

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function fetchSubscription(string $gatewaySubscriptionId): array
    {
        return ['id' => $gatewaySubscriptionId];
    }

    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): array
    {
        $this->cancelCalls[] = ['id' => $gatewaySubscriptionId, 'atPeriodEnd' => $atPeriodEnd];

        return ['status' => 'canceled'];
    }

    /** @return list<'stop_renewal'|'immediate'> */
    public function cancellationModes(): array
    {
        return $this->modes;
    }

    public function subscriptionCheckoutStatus(string $reference): string
    {
        return 'pending';
    }

    public function abandonSubscriptionCheckout(string $reference): string
    {
        $this->abandonCalls[] = $reference;

        if ($this->onAbandonCall !== null) {
            ($this->onAbandonCall)();
        }

        if ($this->abandonThrows !== null) {
            $throw = $this->abandonThrows;
            $this->abandonThrows = null;

            throw $throw;
        }

        return $this->abandonOutcome;
    }
}
