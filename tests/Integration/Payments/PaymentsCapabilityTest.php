<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Extensions\Subscriptions\Bridge\StrictPayviaSubscriptionEventBridge;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\AdminPaymentLinkSendController;
use Thallo\Commerce\Http\Shop\ShopPaymentLinkController;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Capability\ManagementMode;
use Thallo\Contracts\Payments\OnlinePaymentInitiation;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Http\Controllers\PlatformPaymentsSettingsController;
use Thallo\Core\Payments\PaymentsGatedCollector;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\CapabilityBaseline;

/**
 * Payments is a capability (spec §7.7): core declares it over glueful/payvia, it turns on through
 * the activation flow, and off is a contract — every place that starts an online payment asks one
 * question and gets "manual collection" — while settlement, webhooks, reconciliation, refunds,
 * records and saved settings keep working (the continuity run below exercises those flows with
 * Payments off).
 */
final class PaymentsCapabilityTest extends AppTestCase
{
    private static ?ContainerInterface $off = null;

    /** An application booted with Payments off. */
    private static function off(): ContainerInterface
    {
        return self::$off ??= self::bootAppWithConfigOverride(
            'thallo',
            ['capabilities' => ['thallo.payments' => false]],
        )->getContainer();
    }

    public function testPaymentsIsDeclaredByCoreAsAnActivationOverPayvia(): void
    {
        $policy = $this->container()->get(FeatureManagementPolicy::class);
        self::assertSame(ManagementMode::Activation->value, $policy->capabilityManagement('thallo.payments'));
        self::assertSame('glueful/payvia', $policy->engineOf('thallo.payments')['package'] ?? null);
        self::assertSame('managed', $policy->managementOf('glueful/payvia')['class'], 'Payvia is managed by Payments');
        self::assertNotNull($policy->capability('thallo.payments')?->copy?->turnOff);
    }

    public function testTheInitiationQuestionFollowsTheCapability(): void
    {
        self::assertTrue($this->container()->get(OnlinePaymentInitiation::class)->allowed());
        $off = self::off()->get(OnlinePaymentInitiation::class);
        self::assertFalse($off->allowed());
        self::assertStringContainsString('manual collection', (string) $off->refusal());
    }

    public function testOnlineCheckoutCollectsManuallyWhileOffWithoutReachingTheGateway(): void
    {
        // Commerce resolves the PaymentCollector contract: core's binding gates Payvia's collector.
        self::assertInstanceOf(PaymentsGatedCollector::class, $this->container()->get(PaymentCollector::class));

        $inner = new class () implements PaymentCollector {
            public int $calls = 0;

            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                $this->calls++;
                return new PaymentInitiation('stripe', 'pending', ['checkout_url' => 'https://pay.example/x']);
            }
        };
        $payable = new PayableReference('order', 'ord000000001', 1000, 'USD', 'Order 1', ['email' => 'a@b.test']);
        $context = $this->appContext();

        $off = new PaymentsGatedCollector($inner, self::off()->get(OnlinePaymentInitiation::class));
        self::assertSame('manual', $off->initiate($context, $payable)->status);
        self::assertSame(0, $inner->calls, 'off: the gateway is never asked');

        $on = new PaymentsGatedCollector($inner, $this->container()->get(OnlinePaymentInitiation::class));
        self::assertSame('pending', $on->initiate($context, $payable)->status);
        self::assertSame(1, $inner->calls);
    }

    public function testSendingAPaymentLinkIsRefusedWhileOff(): void
    {
        $send = static fn (ContainerInterface $c) => $c->get(AdminPaymentLinkSendController::class)
            ->send(Request::create('/x', 'POST'), 'ord000000404');
        self::assertSame(422, $send($this->container())->getStatusCode(), 'on: the request is validated');
        $response = $send(self::off());
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('payments_off', (string) $response->getContent());
    }

    public function testInitiatingAPaymentLinkShowsManualCollectionWhileOff(): void
    {
        $token = str_repeat('a', 64);                                  // well-formed, no such link
        $initiate = static fn (ContainerInterface $c) => $c->get(ShopPaymentLinkController::class)
            ->initiate(Request::create('/x', 'POST'), $token);
        self::assertNotSame(503, $initiate($this->container())->getStatusCode(), 'on: the token is looked up');
        self::assertSame(503, $initiate(self::off())->getStatusCode(), 'off: manual collection');
    }

    public function testNoPlanCheckoutUrlIsMintedWhileOff(): void
    {
        $resolver = new \Thallo\Subscriptions\Bridge\AdminBillingPlanCheckoutUrlResolver();
        self::assertNull($resolver->resolve(self::off()->get(ApplicationContext::class), 'pro'));
    }

    public function testSavedGatewaySettingsAreKeptAndReadableWhileOff(): void
    {
        $controller = self::off()->get(PlatformPaymentsSettingsController::class);
        $response = $controller->show(Request::create('/x'));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        self::assertFalse($data['payments_enabled']);
        self::assertArrayHasKey('gateways', $data);
    }

    public function testTheGatewayAvailabilityCheckIsUnchangedWhileOff(): void
    {
        $gateway = self::off()->get(\Thallo\Subscriptions\Checkout\PayviaCheckoutGateway::class);
        self::assertTrue($gateway->isAvailable(), 'reconciliation and existing originations still reach Payvia');
    }

    public function testRenewalsStillReachTheSubscriptionsBridgeWhileOff(): void
    {
        $registry = self::off()->get(CapabilityRegistry::class);
        self::assertFalse($registry->isEnabled('thallo.payments'));
        $tag = \Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener::CONTAINER_TAG;
        $classes = [];
        foreach (self::off()->get($tag) as $listener) {
            $classes[] = $listener::class;
        }
        self::assertContains(StrictPayviaSubscriptionEventBridge::class, $classes);
    }

    public function testSettlementRefundsAndCheckoutCleanupKeepWorkingWhileOff(): void
    {
        // The real flows, run by their own tests in a process where Payments is off (and the
        // sentinel below proves it was): a webhook settling a payment started before, the refund
        // block, abandoning and resolving a pending checkout, an existing origination's visibility,
        // and a self-serve checkout and a plan change refused before they reach the provider.
        $filter = implode('|', [
            'testPaymentsIsOffInThisRun',
            'testAVerifiedSuccessWebhookPaysTheOrderConsumesTheLinkAndSettlesTheIntent',
            'testRefundBlockEchoesOrderAggregates',
            'testAbandonConfirmedDeadTransitionsOriginationOpensGuardAndReleasesReservation',
            'testResolveConfirmedDeadReleasesReservationOpensGuardAndAdvancesToAbandoned',
            'testWhilePaymentsIsOffTheStatusSaysSoAndCheckoutIsRefused',
            'testWhilePaymentsIsOffAPlanChangeIsRefused',
        ]);
        $runs = [
            'tests/Integration/Payments/PaymentsCapabilityTest.php' => 1,       // the sentinel
            'tests/Integration/Commerce/WebhookOrderSettlementTest.php' => 1,
            'tests/Integration/Commerce/AdminOrderPaymentsTest.php' => 1,
            'tests/Integration/Subscriptions/WorkspaceBillingCancelAbandonTest.php' => 3,
            'tests/Integration/Subscriptions/WorkspaceBillingSelfServeTest.php' => 1,
        ];
        foreach ($runs as $file => $count) {
            $out = self::runWithPaymentsOff($filter, $file);
            self::assertMatchesRegularExpression("/OK \\({$count} tests?/", $out, $file . "\n" . $out);
        }
        CapabilityBaseline::restore($this->connection()->getPDO());     // the off runs cleared Payments
    }

    private static function runWithPaymentsOff(string $filter, string $file): string
    {
        $env = getenv();
        $env['THALLO_TEST_CAPABILITIES_OFF'] = 'thallo.payments';
        $root = dirname(__DIR__, 3);
        $proc = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/phpunit', '--filter', "/{$filter}/", $file],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $root,
            $env,
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        proc_close($proc);
        return $out;
    }

    public function testPaymentsIsOffInThisRun(): void
    {
        if (getenv('THALLO_TEST_CAPABILITIES_OFF') === false) {
            self::markTestSkipped('only in the Payments-off continuity run');
        }
        self::assertFalse($this->container()->get(CapabilityRegistry::class)->isEnabled('thallo.payments'));
        self::assertFalse($this->container()->get(OnlinePaymentInitiation::class)->allowed());
    }
}
