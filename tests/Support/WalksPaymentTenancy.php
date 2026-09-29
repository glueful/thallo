<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Thallo\Tenancy\Adoption\AdoptionContributorRegistry;
use Thallo\Tenancy\Cache\CacheTransition;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\FinalizationProbe;
use Thallo\Tenancy\Enablement\TenancyEnablement;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The single store -> widened -> enforced walk for payments, over {@see RetrofitHarnessTestCase}'s
 * real machine: seed an order's payment work through Payvia's own repositories as the single store,
 * confirm (the flip), boot again with the tenancy extension, finalize to ON — the two-boot dance of
 * AdoptionWalkTest. The payment work goes through the container, so every tenant it carries comes
 * from the resolver the app binds.
 *
 * @phpstan-require-extends RetrofitHarnessTestCase
 */
trait WalksPaymentTenancy
{
    private RecordingExtensionActivation $activation;

    /**
     * The single store's payment work for one order: an intent claimed then opened with its
     * reference, a pending payment under that reference, and a subscription on a billing plan.
     *
     * @return array{order: string, intent: string, reference: string, plan: string, subscription: string}
     */
    private function seedSingleStorePayments(ApplicationContext $app, string $suffix): array
    {
        $container = $app->getContainer();
        $order = 'order' . $suffix;
        $reference = 'ref' . $suffix;
        $container->get(Connection::class)->getPDO()->exec(
            "INSERT INTO commerce_orders (uuid, tenant_uuid) VALUES ('{$order}', '')"
        );

        $intents = $container->get(PaymentIntentRepository::class);
        $intent = (string) $intents->claimAttempt($app, [
            'payable_type' => 'commerce_order', 'payable_id' => $order, 'gateway' => 'paystack',
            'amount' => 2500, 'currency' => 'GHS',
        ])['uuid'];
        self::assertTrue($intents->markOpen($app, $intent, $reference));
        $container->get(PaymentRepositoryInterface::class)->createPayment($app, [
            'gateway' => 'paystack', 'reference' => $reference, 'amount' => 2500, 'currency' => 'GHS',
            'status' => 'pending', 'payable_type' => 'commerce_order', 'payable_id' => $order,
        ]);

        $plan = 'plan' . $suffix;
        $container->get(Connection::class)->getPDO()->exec(
            "INSERT INTO billing_plans (uuid, tenant_uuid, name, gateway, amount) "
            . "VALUES ('{$plan}', '', 'Monthly', 'stripe', 900)"
        );
        $subscription = 'sub_' . $suffix;
        $container->get(ProviderCorrelationRepository::class)->upsertGatewaySubscription([
            'gateway' => 'stripe', 'gateway_subscription_id' => $subscription, 'billing_plan_uuid' => $plan,
            'tenant_uuid' => '', 'status' => 'active',
        ]);

        return [
            'order' => $order, 'intent' => $intent, 'reference' => $reference, 'plan' => $plan,
            'subscription' => $subscription,
        ];
    }

    /** confirm(): the retrofit, whose flip runs $registry's contributors. */
    private function confirmWith(ApplicationContext $app, AdoptionContributorRegistry $registry): string
    {
        $this->activation = new RecordingExtensionActivation();
        $service = $this->enablement($app, $registry);
        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $service->begin()->step);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $service->begin()->step);
        $confirmed = $service->confirm('pay-walk', 'Payment Walk', 'user00000001');
        self::assertSame(
            EnablementStep::RELOADING,
            $confirmed->step,
            (string) $app->getContainer()->get(EnablementStore::class)->failure(),
        );

        return (string) $app->getContainer()->get(SystemFlags::class)->defaultTenantUuid();
    }

    /** The second boot, with the tenancy extension, finalized to ON. */
    private function finalizeToOn(): ApplicationContext
    {
        self::resetTenancyGlobals();
        self::resetSharedRepositoryConnection();
        /** @var array{enabled: list<string>} $base */
        $base = require dirname(__DIR__, 2) . '/config/serviceproviders.php';
        $app = self::bootAppWithConfigOverride('serviceproviders', [
            'enabled' => [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'],
        ]);
        $container = $app->getContainer();

        $probe = $container->get(FinalizationProbe::class)->report($app);
        self::assertTrue($probe['enforcement'], 'payments\' tables are registered before ON: ' . json_encode($probe));
        $service = $this->enablement($app, $container->get(AdoptionContributorRegistry::class));
        self::assertSame(EnablementStep::ON, $service->finalize()->step);

        return $app;
    }

    /** @template T @param callable(): T $work @return T */
    private function asTenant(ApplicationContext $app, string $tenant, callable $work): mixed
    {
        return $app->getContainer()->get(TenantContextRunner::class)->runAsTenant($tenant, $work);
    }

    private function tenantOf(ApplicationContext $app, string $table, string $uuid): ?string
    {
        $statement = $app->getContainer()->get(Connection::class)->getPDO()
            ->prepare("SELECT tenant_uuid FROM {$table} WHERE uuid = ?");
        $statement->execute([$uuid]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function unassigned(ApplicationContext $app, string $table): int
    {
        return $this->countWhere($app, "SELECT count(*) FROM {$table} WHERE tenant_uuid = ''");
    }

    private function countWhere(ApplicationContext $app, string $sql, array $bindings = []): int
    {
        $statement = $app->getContainer()->get(Connection::class)->getPDO()->prepare($sql);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    private function enablement(ApplicationContext $app, AdoptionContributorRegistry $registry): TenancyEnablement
    {
        $container = $app->getContainer();

        return new TenancyEnablement(
            $app,
            $container->get(EnablementStore::class),
            $container->get(EnablementLock::class),
            $container->get(SystemFlags::class),
            $this->activation,
            $container->get(FinalizationProbe::class),
            $container->get(TenantRuntimeReadiness::class),
            $container->get(RetrofitMaintenanceGuard::class),
            $container->get(CacheTransition::class),
            $container->get(Connection::class),
            null,
            null,
            null,
            $registry,
        );
    }
}
