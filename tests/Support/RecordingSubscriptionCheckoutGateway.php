<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionInitiationCapableGateway;

/**
 * Task 16 (workspace self-serve checkout plan): a recording test double for
 * {@see SubscriptionInitiationCapableGateway}, registered in-container via
 * `GatewayManager::registerDriver()` so `WorkspaceBillingSelfServeTest`'s happy-path/failure
 * coverage exercises the REAL `SubscriptionCheckoutService`/`SubscriptionService::
 * reserveCheckoutFor()` stack (real ledger tables, real transactions) without a live Stripe
 * call. Mirrors `vendor/glueful/payvia`'s own (non-autoloadable-from-this-app)
 * `FakeSubscriptionInitiationGateway` test double.
 */
final class RecordingSubscriptionCheckoutGateway implements
    PaymentGatewayInterface,
    SubscriptionInitiationCapableGateway
{
    public int $calls = 0;

    /** @var list<SubscriptionCheckoutRequest> */
    public array $requests = [];

    /** @var array{reference:string, checkout_url:string, expires_at:?string, raw:array<string,mixed>}|null */
    public ?array $result = null;

    public ?\Throwable $throw = null;

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function initializeSubscription(SubscriptionCheckoutRequest $request): array
    {
        $this->calls++;
        $this->requests[] = $request;

        if ($this->throw !== null) {
            $throw = $this->throw;
            $this->throw = null;

            throw $throw;
        }

        return $this->result ?? [
            'reference' => 'cs_recording_' . $request->originationUuid,
            'checkout_url' => 'https://checkout.recording.test/' . $request->originationUuid,
            'expires_at' => null,
            'raw' => [],
        ];
    }
}
