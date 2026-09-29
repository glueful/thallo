<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Glueful\Extensions\Contracts\Tenancy\TenantContextRequiredException;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Helpers\Utils;
use Thallo\Commerce\Payments\OrderPaymentSummaryRepository;
use Thallo\Core\Payments\Tenancy\PaymentTables;
use Thallo\Core\Payments\Tenancy\PaymentTenancyAdoption;
use Thallo\Core\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Core\Tests\Support\WalksPaymentTenancy;
use Thallo\Tenancy\Adoption\AdoptionContributorRegistry;
use Thallo\Tenancy\Adoption\AdoptionGate;

/**
 * Payments through the whole enablement, under real enforcement: payment work done as the single
 * store joins the default workspace at the flip — same rows, same ids, never a second intent — the
 * work done while workspaces are being set up lands there too and no unassigned row can be
 * written, and after ON the late settlement, the refund and the subscription webhook all find
 * their rows. A payment without a workspace is still refused, and two workspaces never see each
 * other's payments.
 *
 * Opt-in (THALLO_TENANCY_DEV_LINK=1) and run in its own invocation, like every retrofit-harness class.
 */
final class PaymentAdoptionWalkTest extends RetrofitHarnessTestCase
{
    use WalksPaymentTenancy;

    protected static function includeTenancyExtensionOnEngineBoot(): bool
    {
        return false;
    }

    public function testPaymentsFollowTheirOrdersThroughEnablement(): void
    {
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        $container1 = $boot1->getContainer();

        // 1. The single store's payment work, through the resolver the app binds: tenant ''.
        $seed = $this->seedSingleStorePayments($boot1, '000001');
        self::assertSame('', $this->tenantOf($boot1, 'payment_intents', $seed['intent']));
        self::assertTrue($container1->get(AdoptionGate::class)->isHeld(), 'single-store work holds the gate');
        $ids = [];
        foreach (PaymentTables::workspaceOwned() as $table) {
            $ids[$table] = $this->countWhere($boot1, "SELECT coalesce(sum(id), 0) FROM {$table}");
        }

        // 2. The flip: the contributors this app registers, payments' among them.
        $default = $this->confirmWith($boot1, $container1->get(AdoptionContributorRegistry::class));
        foreach (PaymentTables::workspaceOwned() as $table) {
            self::assertSame(0, $this->unassigned($boot1, $table), $table);
            self::assertSame(
                $ids[$table],
                $this->countWhere($boot1, "SELECT coalesce(sum(id), 0) FROM {$table}"),
                "{$table}: the same rows",
            );
        }
        self::assertSame($default, $this->tenantOf($boot1, 'payment_intents', $seed['intent']));
        self::assertSame(
            $default,
            $this->tenantOf($boot1, 'gateway_subscriptions', $this->subscriptionUuid($boot1, $seed)),
        );
        self::assertSame(
            PaymentTables::workspaceOwned(),
            $container1->get(PaymentTenancyAdoption::class)->diagnose($default)->constrained,
            'every payments table refuses an unassigned row',
        );
        self::assertSame(
            '1',
            $container1->get(\Thallo\Tenancy\System\SystemFlags::class)->get(PaymentTenancyAdoption::GUARDED_FLAG),
            'the flip certifies the guard',
        );

        // 3. While workspaces are being set up: the default workspace, the same intent, no second one.
        $intents1 = $container1->get(PaymentIntentRepository::class);
        self::assertSame($default, $container1->get(PayviaTenantResolver::class)->tenantUuid($boot1));
        $open = $intents1->findOpen($boot1, 'commerce_order', $seed['order']);
        self::assertSame($seed['intent'], $open['uuid'] ?? null);
        $again = $intents1->claimAttempt($boot1, [
            'payable_type' => 'commerce_order', 'payable_id' => $seed['order'], 'gateway' => 'paystack',
            'amount' => 2500, 'currency' => 'GHS',
        ]);
        self::assertSame($seed['intent'], $again['uuid'], 'paying again resumes the adopted intent');
        $transitional = $container1->get(PaymentRepositoryInterface::class)->createPayment($boot1, [
            'gateway' => 'paystack', 'reference' => 'refTransit01', 'amount' => 100, 'currency' => 'GHS',
            'status' => 'pending',
        ]);
        self::assertSame($default, $this->tenantOf($boot1, 'payments', $transitional));
        try {
            $container1->get(\Glueful\Database\Connection::class)->getPDO()->exec(
                "INSERT INTO payments (uuid, tenant_uuid, gateway, reference, amount) "
                . "VALUES ('paystray0001', '', 'paystack', 'refStray0001', 1)"
            );
            self::fail('an unassigned payment must be refused after adoption');
        } catch (\PDOException $refused) {
            self::assertStringContainsString(PaymentTenancyAdoption::CONSTRAINT, $refused->getMessage());
        }

        // 4. ON.
        $boot2 = $this->finalizeToOn();
        $container2 = $boot2->getContainer();

        // 5. Late work under enforcement, in the default workspace: settlement, refund, subscription.
        $late = $this->asTenant($boot2, $default, function () use ($container2, $boot2, $seed, $default): array {
            $payments = $container2->get(PaymentRepositoryInterface::class);
            $intents = $container2->get(PaymentIntentRepository::class);
            $found = $intents->findByReference($boot2, 'paystack', $seed['reference']);
            self::assertNotNull($found, 'the webhook finds the intent by its reference');
            $intents->close($boot2, (string) $found['uuid'], $seed['reference']);
            $settled = $payments->updateByReference($boot2, $seed['reference'], ['status' => 'success']);
            $refunded = $payments->updateByReference($boot2, $seed['reference'], ['status' => 'refunded']);
            $container2->get(ProviderCorrelationRepository::class)->upsertGatewaySubscription([
                'gateway' => 'stripe', 'gateway_subscription_id' => $seed['subscription'], 'status' => 'canceled',
            ]);
            $summary = $container2->get(OrderPaymentSummaryRepository::class);

            return [
                'settled' => $settled,
                'refunded' => $refunded,
                'intent' => $intents->findByUuid($boot2, $seed['intent'])['status'] ?? null,
                'payments' => count($summary->paymentsFor($default, $seed['order'])),
                'intents' => count($summary->intentsFor($default, $seed['order'])),
            ];
        });
        self::assertTrue($late['settled'], 'the late settlement finds its payment');
        self::assertTrue($late['refunded'], 'the refund finds its payment');
        self::assertSame('closed', $late['intent']);
        self::assertSame(1, $late['payments'], 'the order shows its payment');
        self::assertSame(1, $late['intents'], 'the order shows one intent');
        self::assertSame(
            'canceled',
            $container2->get(ProviderCorrelationRepository::class)
                ->findGatewaySubscriptionByGatewayId('stripe', $seed['subscription'])['status'] ?? null,
            'the subscription webhook updates the adopted subscription',
        );

        // 6. Enforcement stays strict: no workspace, no payment.
        try {
            $container2->get(PayviaTenantResolver::class)->tenantUuid($boot2);
            self::fail('a payment without a workspace must be refused under enforcement');
        } catch (TenantContextRequiredException) {
        }

        // 7. Two enforced workspaces never see each other's payments.
        $other = Utils::generateNanoID(12);
        $container2->get(TenantProvisioner::class)
            ->provisionDefault($boot2, $other, 'pay-walk-b', 'Walk B', 'user00000001');
        $container2->get(\Glueful\Database\Connection::class)->getPDO()->exec(
            "INSERT INTO commerce_orders (uuid, tenant_uuid) VALUES ('orderOther01', '{$other}')"
        );
        $theirs = $this->asTenant($boot2, $other, function () use ($container2, $boot2, $seed): array {
            $intents = $container2->get(PaymentIntentRepository::class);
            $claimed = $intents->claimAttempt($boot2, [
                'payable_type' => 'commerce_order', 'payable_id' => 'orderOther01', 'gateway' => 'paystack',
                'amount' => 700, 'currency' => 'GHS',
            ]);
            $container2->get(PaymentRepositoryInterface::class)->createPayment($boot2, [
                'gateway' => 'paystack', 'reference' => 'refOther0001', 'amount' => 700, 'currency' => 'GHS',
                'status' => 'pending',
            ]);

            return [
                'claimed' => (string) $claimed['uuid'],
                'seesDefaultIntent' => $intents->findByUuid($boot2, $seed['intent']) !== null,
                'seesDefaultPayment' => $container2->get(PaymentRepositoryInterface::class)
                    ->findByReference($boot2, $seed['reference']) !== null,
            ];
        });
        self::assertSame($other, $this->tenantOf($boot2, 'payment_intents', $theirs['claimed']));
        self::assertFalse($theirs['seesDefaultIntent']);
        self::assertFalse($theirs['seesDefaultPayment']);
        $ours = $this->asTenant($boot2, $default, fn (): array => [
            'seesOtherIntent' => $container2->get(PaymentIntentRepository::class)
                ->findByUuid($boot2, $theirs['claimed']) !== null,
            'seesOtherPayment' => $container2->get(PaymentRepositoryInterface::class)
                ->findByReference($boot2, 'refOther0001') !== null,
        ]);
        self::assertSame(['seesOtherIntent' => false, 'seesOtherPayment' => false], $ours);
    }

    /** @param array{subscription: string} $seed */
    private function subscriptionUuid(\Glueful\Bootstrap\ApplicationContext $app, array $seed): string
    {
        $statement = $app->getContainer()->get(\Glueful\Database\Connection::class)->getPDO()
            ->prepare('SELECT uuid FROM gateway_subscriptions WHERE gateway_subscription_id = ?');
        $statement->execute([$seed['subscription']]);

        return (string) $statement->fetchColumn();
    }
}
