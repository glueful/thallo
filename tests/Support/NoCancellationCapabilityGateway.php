<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;

/**
 * Task 17 (cancel matrix, workspace self-serve checkout plan): a driver double that implements
 * ONLY the base {@see SubscriptionCapableGateway} -- deliberately WITHOUT
 * `SubscriptionCancellationModeProvider` or `SubscriptionCheckoutLifecycleCapableGateway` -- design
 * spec §3.7's "a driver that does not implement the capability exposes no self-serve cancellation
 * modes" case. Proves `SelfBillingController::cancel()` refuses EVERY `mode` value 422 against such
 * a driver, and never calls `cancelSubscription()`.
 */
final class NoCancellationCapabilityGateway implements PaymentGatewayInterface, SubscriptionCapableGateway
{
    /** @var list<array{id:string,atPeriodEnd:bool}> */
    public array $cancelCalls = [];

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
}
