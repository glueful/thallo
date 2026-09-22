<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
use Glueful\Extensions\Payvia\Contracts\SubscriptionPlanChangeCapableGateway;

/**
 * A gateway that can move a live subscription to another plan (as Stripe can), recording each
 * call. Loaded only where glueful/payvia declares the capability (2.9+): the tests that use it
 * skip before then.
 */
final class RecordingPlanChangeGateway implements
    PaymentGatewayInterface,
    SubscriptionCapableGateway,
    SubscriptionPlanChangeCapableGateway
{
    /** @var list<array{id: string, plan: string}> */
    public array $changeCalls = [];

    /** @var array{status: 'changed'}|array{status: 'failed', message: string} */
    public array $answer = ['status' => 'changed'];

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
        return ['status' => 'canceled'];
    }

    public function changeSubscriptionPlan(string $gatewaySubscriptionId, string $providerPlanIdentifier): array
    {
        $this->changeCalls[] = ['id' => $gatewaySubscriptionId, 'plan' => $providerPlanIdentifier];

        return $this->answer;
    }
}
