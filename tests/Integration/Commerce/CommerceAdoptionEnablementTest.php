<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\RecordingExtensionActivation;
use App\Tests\Support\RetrofitHarnessTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Helpers\Utils;
use Thallo\Commerce\Adoption\CommerceAdoptionContributor;
use Thallo\Tenancy\Adoption\AdoptionContributor;
use Thallo\Tenancy\Adoption\AdoptionContributorRegistry;
use Thallo\Tenancy\Cache\CacheTransition;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\FinalizationProbe;
use Thallo\Tenancy\Enablement\TenancyEnablement;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Commerce-Slice-1 Task 10: {@see CommerceAdoptionContributor} driven through the REAL
 * `TenancyEnablement::confirm()` state machine — mirrors
 * {@see \App\Tests\Integration\Tenancy\AdoptionContributorTest} exactly (same harness, same
 * `service()` shape), the only genuine consumer of the T4 adoption seam that exists so far.
 *
 * Carry-forward #1 (the retrofit write-barrier): confirm()'s RETROFITTING step runs every
 * registered contributor's `adopt()` with {@see RetrofitMaintenanceGuard}'s barrier UP the whole
 * time. `RetrofitWriteBarrierInterceptor` only refuses builder mutations against tables listed in
 * `ThalloTenantTables` (core Thallo tables) — verified by reading the interceptor: the owned-table
 * match runs BEFORE it ever consults `guard->active()`, so a mutation against any table NOT in
 * that list returns immediately, barrier state irrelevant. `thallo_commerce_product_links` and
 * every Commerce table are outside that list, so this contributor's real UPDATE write against
 * the link table — and `TenantAdopter`'s real writes against Commerce tables — pass through the
 * barrier unmodified. `testConfirmAdoptsSentinelLinkAndCommerceRowsBeforeEnforcementWithTheRetrofit
 * BarrierUp` proves this with a REAL write (not a read-only probe, unlike T4's own seam test):
 * it wraps the real contributor to record `guard->active()` at call time (must be true) AND lets
 * the real link-table UPDATE execute for real (must not throw `RetrofitInProgressException`,
 * and the row must actually move to the new tenant).
 */
final class CommerceAdoptionEnablementTest extends RetrofitHarnessTestCase
{
    protected static function includeTenancyExtensionOnEngineBoot(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        if (self::$engineApp !== null) {
            $this->guard()->end();
        }
        parent::setUp();

        foreach (
            [
            'tenancy.enable_step',
            'tenancy.enable_failure',
            'tenancy.enable_failed_from',
            'tenancy.enable_pending_slug',
            'tenancy.enable_pending_name',
            'tenancy.enabled',
            'tenancy.provisioning_tenant_uuid',
            'tenancy.default_tenant_uuid',
            ] as $key
        ) {
            $this->flags()->forget($key);
        }
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM tenant_memberships');
        $pdo->exec('DELETE FROM tenants');
        $pdo->exec('DELETE FROM thallo_commerce_product_links');
        $pdo->exec('DELETE FROM commerce_products');
    }

    public function testConfirmAdoptsSentinelLinkAndCommerceRowsBeforeEnforcementWithTheRetrofitBarrierUp(): void
    {
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        $connection = $this->connection();

        $connection->table('thallo_commerce_product_links')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'product_uuid' => Utils::generateNanoID(),
            'entry_uuid' => Utils::generateNanoID(),
        ]);
        $connection->table('commerce_products')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'slug' => 'enablement-barrier-proof',
            'name' => 'Enablement Barrier Proof Product',
        ]);

        $real = new CommerceAdoptionContributor($connection, new TenantAdopter());
        $observer = $this->barrierObservingContributor($real);
        $registry = new AdoptionContributorRegistry();
        $registry->register($observer);

        $activation = new RecordingExtensionActivation();
        $service = $this->service($boot1, $activation, $registry);

        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $service->begin()->step);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $service->begin()->step);
        $status = $service->confirm('commerce-adopt-barrier', 'Commerce Adopt Barrier', 'user00000001');

        self::assertSame(EnablementStep::RELOADING, $status->step, (string) $this->store()->failure());
        self::assertSame(1, $observer->calls);
        self::assertTrue(
            $observer->barrierWasActiveDuringAdopt,
            'the retrofit write-barrier must be UP while the contributor runs',
        );

        $defaultTenant = $this->flags()->defaultTenantUuid();
        self::assertNotNull($defaultTenant);
        self::assertSame(
            0,
            (int) $connection->table('thallo_commerce_product_links')->where('tenant_uuid', '')->count(),
        );
        self::assertSame(
            1,
            (int) $connection->table('thallo_commerce_product_links')
                ->where('tenant_uuid', $defaultTenant)->count(),
            'a REAL write to the pack-owned link table must have succeeded with the barrier up',
        );
        self::assertSame(
            0,
            (int) $connection->table('commerce_products')->where('tenant_uuid', '')->count(),
        );
        self::assertSame(
            1,
            (int) $connection->table('commerce_products')->where('tenant_uuid', $defaultTenant)->count(),
            'Commerce\'s own TenantAdopter write must also have succeeded with the barrier up',
        );
    }

    public function testThrowingContributorRecordsRetryableRetrofittingFailureThenRetryAdoptsForReal(): void
    {
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        $connection = $this->connection();

        $connection->table('thallo_commerce_product_links')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'product_uuid' => Utils::generateNanoID(),
            'entry_uuid' => Utils::generateNanoID(),
        ]);
        $connection->table('commerce_products')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'slug' => 'enablement-retry-proof',
            'name' => 'Enablement Retry Proof Product',
        ]);

        $real = new CommerceAdoptionContributor($connection, new TenantAdopter());
        $flaky = $this->throwOnceThenDelegateContributor($real);
        $registry = new AdoptionContributorRegistry();
        $registry->register($flaky);

        $activation = new RecordingExtensionActivation();
        $service = $this->service($boot1, $activation, $registry);

        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $service->begin()->step);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $service->begin()->step);

        $failed = $service->confirm('commerce-adopt-retry', 'Commerce Adopt Retry', 'user00000001');
        self::assertSame(EnablementStep::FAILED, $failed->step);
        self::assertSame(EnablementStep::RETROFITTING, $this->store()->failedFrom());
        self::assertSame(1, $flaky->calls);
        // The failed attempt must not have adopted anything (the contributor threw before
        // TenantAdopter or the link update ran).
        self::assertSame(
            1,
            (int) $connection->table('thallo_commerce_product_links')->where('tenant_uuid', '')->count(),
        );

        self::assertSame(EnablementStep::RETROFITTING, $service->retry()->step);

        $retried = $service->confirm('commerce-adopt-retry', 'Commerce Adopt Retry', 'user00000001');
        self::assertSame(EnablementStep::RELOADING, $retried->step, (string) $this->store()->failure());
        self::assertSame(2, $flaky->calls, 'retry() must re-run confirm(), which re-invokes the contributor');

        $defaultTenant = $this->flags()->defaultTenantUuid();
        self::assertSame(
            0,
            (int) $connection->table('thallo_commerce_product_links')->where('tenant_uuid', '')->count(),
            'the SECOND (successful) invocation must have genuinely adopted the sentinel link row',
        );
        self::assertSame(
            1,
            (int) $connection->table('thallo_commerce_product_links')
                ->where('tenant_uuid', $defaultTenant)->count(),
        );
        self::assertSame(
            1,
            (int) $connection->table('commerce_products')->where('tenant_uuid', $defaultTenant)->count(),
        );
    }

    public function testFinalizationProbeFailsWhenTheLinkTableIsUnregisteredAndPassesWhenTheFullSetIs(): void
    {
        $contributor = new CommerceAdoptionContributor($this->connection(), new TenantAdopter());
        $tables = $contributor->tables();
        self::assertContains('thallo_commerce_product_links', $tables);
        self::assertContains('commerce_products', $tables);

        $registry = new AdoptionContributorRegistry();
        $registry->register($contributor);

        $failingProbe = new FinalizationProbe(
            $this->container()->get(SystemFlags::class),
            $this->connection(),
            $this->container()->get(TenantRuntimeReadiness::class),
            $this->container()->get(TenantCacheSegment::class),
            null,
            $this->fakeEnforcementProbe([]), // every core table registered, none of the contributor's
            null,
            null,
            $registry,
        );
        self::assertFalse($failingProbe->report($this->appContext())['enforcement']);

        $passingProbe = new FinalizationProbe(
            $this->container()->get(SystemFlags::class),
            $this->connection(),
            $this->container()->get(TenantRuntimeReadiness::class),
            $this->container()->get(TenantCacheSegment::class),
            null,
            $this->fakeEnforcementProbe($tables),
            null,
            null,
            $registry,
        );
        self::assertTrue($passingProbe->report($this->appContext())['enforcement']);
    }

    // --- helpers -------------------------------------------------------------------------------------

    private function service(
        ApplicationContext $context,
        RecordingExtensionActivation $activation,
        AdoptionContributorRegistry $registry,
    ): TenancyEnablement {
        $container = $context->getContainer();

        return new TenancyEnablement(
            $context,
            $container->get(EnablementStore::class),
            $container->get(EnablementLock::class),
            $container->get(SystemFlags::class),
            $activation,
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

    private function guard(): RetrofitMaintenanceGuard
    {
        return $this->container()->get(RetrofitMaintenanceGuard::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function store(): EnablementStore
    {
        return $this->container()->get(EnablementStore::class);
    }

    /** @param list<string> $extraRegistered */
    private function fakeEnforcementProbe(array $extraRegistered): TenantEnforcementProbe
    {
        $registered = array_fill_keys(ThalloTenantTables::tableNames(), true);
        foreach ($extraRegistered as $table) {
            $registered[$table] = true;
        }

        return new class ($registered) implements TenantEnforcementProbe {
            /** @param array<string,bool> $registered */
            public function __construct(private readonly array $registered)
            {
            }

            public function isRegistered(string $table): bool
            {
                return $this->registered[$table] ?? false;
            }

            /** @return list<string> */
            public function registeredTables(): array
            {
                return array_keys(array_filter($this->registered));
            }
        };
    }

    /**
     * Wraps a real contributor, recording whether the retrofit write-barrier was active at the
     * moment `adopt()` ran, then delegates to it for real (no interception of the real write).
     */
    private function barrierObservingContributor(AdoptionContributor $inner): object
    {
        $guard = $this->guard();

        return new class ($inner, $guard) implements AdoptionContributor {
            public int $calls = 0;
            public bool $barrierWasActiveDuringAdopt = false;

            public function __construct(
                private readonly AdoptionContributor $inner,
                private readonly RetrofitMaintenanceGuard $guard,
            ) {
            }

            public function id(): string
            {
                return $this->inner->id();
            }

            /** @return list<string> */
            public function tables(): array
            {
                return $this->inner->tables();
            }

            public function adopt(ApplicationContext $context, string $tenantUuid): void
            {
                $this->calls++;
                $this->barrierWasActiveDuringAdopt = $this->guard->active();
                $this->inner->adopt($context, $tenantUuid);
            }
        };
    }

    /** Throws on the FIRST call, delegates for real to the inner contributor on every call after. */
    private function throwOnceThenDelegateContributor(AdoptionContributor $inner): object
    {
        return new class ($inner) implements AdoptionContributor {
            public int $calls = 0;

            public function __construct(private readonly AdoptionContributor $inner)
            {
            }

            public function id(): string
            {
                return $this->inner->id();
            }

            /** @return list<string> */
            public function tables(): array
            {
                return $this->inner->tables();
            }

            public function adopt(ApplicationContext $context, string $tenantUuid): void
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('commerce adoption boom');
                }
                $this->inner->adopt($context, $tenantUuid);
            }
        };
    }
}
