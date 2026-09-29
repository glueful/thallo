<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Commerce\Adoption\CommerceAdoptionContributor;
use Thallo\Core\Payments\Console\RepairPaymentTenancyCommand;
use Thallo\Core\Payments\Tenancy\PaymentAdoptionContributor;
use Thallo\Core\Payments\Tenancy\PaymentTables;
use Thallo\Core\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Core\Tests\Support\WalksPaymentTenancy;
use Thallo\Tenancy\Adoption\AdoptionContributorRegistry;

/**
 * The repair of a site that turned workspaces on before payments were adopted, under real
 * enforcement: its payments stayed unassigned, a checkout in the default workspace opened a second
 * intent for an order whose first it could not see, and another workspace has payments of its own.
 * The repair's dry run names the collision and the duplicate and changes nothing; `--apply` refuses
 * until the duplicate is reconciled, then moves only the unassigned rows — the other workspace's
 * untouched — and a second run finds nothing to do. Late settlement, refund and subscription work
 * then find the repaired rows.
 *
 * Opt-in (THALLO_TENANCY_DEV_LINK=1) and run in its own invocation, like every retrofit-harness class.
 */
final class PaymentRepairWalkTest extends RetrofitHarnessTestCase
{
    use WalksPaymentTenancy;

    protected static function includeTenancyExtensionOnEngineBoot(): bool
    {
        return false;
    }

    /** @return array{int, string} */
    private function repair(\Glueful\Bootstrap\ApplicationContext $app, array $options = []): array
    {
        // The repair runs as its own process: the payment work before it was a unit that has ended.
        $app->getContainer()->get(\Thallo\Tenancy\Adoption\AdoptionGate::class)->release();
        $tester = new CommandTester(new RepairPaymentTenancyCommand($app->getContainer(), $app));
        $status = $tester->execute($options, ['interactive' => false]);

        return [$status, $tester->getDisplay()];
    }

    public function testASiteEnabledBeforeTheFixIsRepairedUnderEnforcement(): void
    {
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        $container1 = $boot1->getContainer();
        $seed = $this->seedSingleStorePayments($boot1, '000002');

        // 1. Enabled the old way: every contributor but payments'.
        $old = new AdoptionContributorRegistry();
        foreach ($container1->get(AdoptionContributorRegistry::class)->all() as $contributor) {
            if ($contributor->id() !== PaymentAdoptionContributor::ID) {
                $old->register($contributor);
            }
        }
        self::assertSame([CommerceAdoptionContributor::ID], array_map(static fn ($c): string => $c->id(), $old->all()));
        $default = $this->confirmWith($boot1, $old);
        self::assertSame('', $this->tenantOf($boot1, 'payment_intents', $seed['intent']), 'payments stayed behind');
        $boot2 = $this->finalizeToOn();
        $container2 = $boot2->getContainer();
        $pdo = $container2->get(Connection::class)->getPDO();

        // 2. The damage: the default workspace cannot see the order's intent, so paying opens a second.
        $duplicate = $this->asTenant($boot2, $default, function () use ($container2, $boot2, $seed): string {
            $intents = $container2->get(PaymentIntentRepository::class);
            self::assertNull($intents->findOpen($boot2, 'commerce_order', $seed['order']), 'the first is invisible');

            return (string) $intents->claimAttempt($boot2, [
                'payable_type' => 'commerce_order', 'payable_id' => $seed['order'], 'gateway' => 'paystack',
                'amount' => 2500, 'currency' => 'GHS',
            ])['uuid'];
        });

        // 3. Another workspace, already populated.
        $other = Utils::generateNanoID(12);
        $container2->get(TenantProvisioner::class)
            ->provisionDefault($boot2, $other, 'repair-b', 'Repair B', 'user00000001');
        $pdo->exec("INSERT INTO commerce_orders (uuid, tenant_uuid) VALUES ('orderRepairB', '{$other}')");
        $theirs = $this->asTenant($boot2, $other, function () use ($container2, $boot2): string {
            $container2->get(PaymentRepositoryInterface::class)->createPayment($boot2, [
                'gateway' => 'paystack', 'reference' => 'refRepairB01', 'amount' => 300, 'currency' => 'GHS',
                'status' => 'success', 'payable_type' => 'commerce_order', 'payable_id' => 'orderRepairB',
            ]);

            return (string) $container2->get(PaymentIntentRepository::class)->claimAttempt($boot2, [
                'payable_type' => 'commerce_order', 'payable_id' => 'orderRepairB', 'gateway' => 'paystack',
                'amount' => 300, 'currency' => 'GHS',
            ])['uuid'];
        });
        $otherRows = $this->countWhere($boot2, "SELECT count(*) FROM payment_intents WHERE tenant_uuid = ?", [$other])
            + $this->countWhere($boot2, "SELECT count(*) FROM payments WHERE tenant_uuid = ?", [$other]);

        // 4. The dry run: the collision the duplicate causes, the duplicate itself; nothing changes.
        [$status, $display] = $this->repair($boot2);
        self::assertSame(1, $status, $display);
        self::assertMatchesRegularExpression('~payment_intents\s+1\s+1\s+1~', $display);
        self::assertStringContainsString('payment_intents #', $display);
        self::assertStringContainsString('commerce_order ' . $seed['order'] . ' — 2 intent(s)', $display);
        self::assertSame('', $this->tenantOf($boot2, 'payment_intents', $seed['intent']));

        // 5. --apply refuses until the duplicate is reconciled, writing nothing.
        [$status, $display] = $this->repair($boot2, ['--apply' => true]);
        self::assertSame(1, $status, $display);
        self::assertStringContainsString('Refused: nothing was changed', $display);
        self::assertSame('', $this->tenantOf($boot2, 'payments', $this->paymentUuid($boot2, $seed['reference'])));

        // The operator reconciles: the second intent was never paid, so it is superseded.
        $this->asTenant($boot2, $default, fn (): bool => $container2->get(PaymentIntentRepository::class)
            ->supersede($boot2, $duplicate));

        // 6. --apply moves exactly the unassigned rows.
        [$status, $display] = $this->repair($boot2, ['--apply' => true]);
        self::assertSame(0, $status, $display);
        self::assertStringContainsString("into workspace {$default}", $display);
        foreach (PaymentTables::workspaceOwned() as $table) {
            self::assertSame(0, $this->unassigned($boot2, $table), $table);
        }
        self::assertSame($default, $this->tenantOf($boot2, 'payment_intents', $seed['intent']));
        self::assertSame(
            $default,
            $this->tenantOf($boot2, 'payment_intents', $duplicate),
            'the default workspace keeps its own',
        );
        self::assertSame($other, $this->tenantOf($boot2, 'payment_intents', $theirs));
        self::assertSame(
            $otherRows,
            $this->countWhere($boot2, "SELECT count(*) FROM payment_intents WHERE tenant_uuid = ?", [$other])
                + $this->countWhere($boot2, "SELECT count(*) FROM payments WHERE tenant_uuid = ?", [$other]),
            'the other workspace keeps exactly its rows',
        );

        // 7. Again: nothing to do.
        [$status, $display] = $this->repair($boot2, ['--apply' => true]);
        self::assertSame(0, $status, $display);
        self::assertStringContainsString('Nothing to repair', $display);

        // 8. Late work in the default workspace finds the repaired rows; the other workspace does not.
        $late = $this->asTenant($boot2, $default, function () use ($container2, $boot2, $seed): array {
            $intents = $container2->get(PaymentIntentRepository::class);
            $payments = $container2->get(PaymentRepositoryInterface::class);
            $open = $intents->findOpen($boot2, 'commerce_order', $seed['order']);
            $intents->close($boot2, (string) ($open['uuid'] ?? ''), $seed['reference']);
            $container2->get(ProviderCorrelationRepository::class)->upsertGatewaySubscription([
                'gateway' => 'stripe', 'gateway_subscription_id' => $seed['subscription'], 'status' => 'past_due',
            ]);

            return [
                'open' => $open['uuid'] ?? null,
                'settled' => $payments->updateByReference($boot2, $seed['reference'], ['status' => 'success']),
                'refunded' => $payments->updateByReference($boot2, $seed['reference'], ['status' => 'refunded']),
            ];
        });
        self::assertSame(['open' => $seed['intent'], 'settled' => true, 'refunded' => true], $late);
        self::assertSame(
            'past_due',
            $container2->get(ProviderCorrelationRepository::class)
                ->findGatewaySubscriptionByGatewayId('stripe', $seed['subscription'])['status'] ?? null,
        );
        self::assertNull($this->asTenant(
            $boot2,
            $other,
            fn (): ?array => $container2->get(PaymentRepositoryInterface::class)
                ->findByReference($boot2, $seed['reference']),
        ));
    }

    private function paymentUuid(\Glueful\Bootstrap\ApplicationContext $app, string $reference): string
    {
        $statement = $app->getContainer()->get(Connection::class)->getPDO()
            ->prepare('SELECT uuid FROM payments WHERE reference = ?');
        $statement->execute([$reference]);

        return (string) $statement->fetchColumn();
    }
}
