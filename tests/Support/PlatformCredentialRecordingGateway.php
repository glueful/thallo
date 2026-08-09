<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionInitiationCapableGateway;
use Glueful\Extensions\Payvia\Support\PayviaSettings;

/**
 * Task 8 (platform-payments-settings plan) — a recording double serving BOTH checkout consumers
 * (commerce's one-time {@see InitiationCapableGateway} and subscriptions'
 * {@see SubscriptionInitiationCapableGateway}): on EITHER call it resolves its own secret via
 * {@see PayviaSettings::gatewayConfig()} — the same call a real Paystack/Stripe driver makes
 * internally at call time (see `vendor/glueful/payvia/src/Gateways/PaystackGateway.php`'s own
 * `$this->gatewayConfig()` reads) — so the captured value is what a live gateway call would
 * actually have carried under whatever ambient tenant context happens to be current, never a
 * value the test handed it directly. Registered in-container via `GatewayManager::registerDriver()`,
 * mirroring {@see RecordingSubscriptionCheckoutGateway}.
 */
final class PlatformCredentialRecordingGateway implements
    PaymentGatewayInterface,
    InitiationCapableGateway,
    SubscriptionInitiationCapableGateway
{
    public int $initiateCalls = 0;
    public int $subscriptionCalls = 0;
    public ?string $lastInitiateSecret = null;
    public ?string $lastSubscriptionSecret = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly string $gatewayName,
    ) {
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function initialize(PayableReference $payable, array $options = []): array
    {
        $this->initiateCalls++;
        $config = PayviaSettings::gatewayConfig($this->context, $this->gatewayName);
        $secret = $config['secret_key'] ?? null;
        $this->lastInitiateSecret = is_string($secret) ? $secret : null;

        return [
            'reference' => 'rec_commerce_' . $payable->id,
            'checkout_url' => 'https://checkout.recording.test/commerce/' . $payable->id,
        ];
    }

    /** @return array{reference:string, checkout_url:string, expires_at:?string, raw:array<string,mixed>} */
    public function initializeSubscription(SubscriptionCheckoutRequest $request): array
    {
        $this->subscriptionCalls++;
        $config = PayviaSettings::gatewayConfig($this->context, $request->gateway);
        $secret = $config['secret_key'] ?? null;
        $this->lastSubscriptionSecret = is_string($secret) ? $secret : null;

        return [
            'reference' => 'rec_sub_' . $request->originationUuid,
            'checkout_url' => 'https://checkout.recording.test/sub/' . $request->originationUuid,
            'expires_at' => null,
            'raw' => [],
        ];
    }
}
