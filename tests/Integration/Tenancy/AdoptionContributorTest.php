<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RecordingExtensionActivation;
use App\Tests\Support\RetrofitHarnessTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
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
 * Drives the SP1 enablement machine's tenant-adoption contributor seam through
 * {@see TenancyEnablement::confirm()} against the narrow throwaway PostgreSQL DB (control-plane bound,
 * enforcement extension NOT booted on this process — matches EnableFullMachineAcceptanceTest's first
 * boot). Because SchemaRetrofit::run() is idempotent, each confirm()-driving test resets only the
 * per-operation enablement + retrofit identity state in setUp() (mirrors SchemaRetrofitTest).
 */
final class AdoptionContributorTest extends RetrofitHarnessTestCase
{
    protected static function includeTenancyExtensionOnEngineBoot(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        // A prior successful/failed confirm() in this class leaves the retrofit barrier UP; lower it
        // BEFORE parent::setUp(), whose cleanup issues owned-table builder deletes the interceptor
        // would otherwise refuse (mirrors SchemaRetrofitTest::setUp()).
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
    }

    // --- AdoptionContributorRegistry (pure) -------------------------------------------------------

    public function testRegistryListsRegisteredContributorsInRegistrationOrder(): void
    {
        $registry = new AdoptionContributorRegistry();
        $a = $this->stubContributor('a');
        $b = $this->stubContributor('b');
        $registry->register($a);
        $registry->register($b);

        self::assertSame([$a, $b], $registry->all());
    }

    public function testRegistryRejectsDuplicateId(): void
    {
        $registry = new AdoptionContributorRegistry();
        $registry->register($this->stubContributor('dup'));

        $this->expectException(\LogicException::class);
        $registry->register($this->stubContributor('dup'));
    }

    // --- FinalizationProbe extension ---------------------------------------------------------------

    public function testFinalizationProbeFailsWhenAContributorTableIsUnregistered(): void
    {
        $registry = new AdoptionContributorRegistry();
        $registry->register($this->stubContributor('stub', ['thallo_adoption_stub_table']));

        $probe = new FinalizationProbe(
            $this->container()->get(SystemFlags::class),
            $this->container()->get(Connection::class),
            $this->container()->get(TenantRuntimeReadiness::class),
            $this->container()->get(TenantCacheSegment::class),
            null,
            $this->fakeEnforcementProbe([]), // every owned table registered, contributor table is NOT
            null,
            null,
            $registry,
        );

        $report = $probe->report($this->appContext());
        self::assertFalse($report['enforcement']);
    }

    public function testFinalizationProbePassesWhenEveryContributorTableIsRegistered(): void
    {
        $registry = new AdoptionContributorRegistry();
        $registry->register($this->stubContributor('stub', ['thallo_adoption_stub_table']));

        $probe = new FinalizationProbe(
            $this->container()->get(SystemFlags::class),
            $this->container()->get(Connection::class),
            $this->container()->get(TenantRuntimeReadiness::class),
            $this->container()->get(TenantCacheSegment::class),
            null,
            $this->fakeEnforcementProbe(['thallo_adoption_stub_table']),
            null,
            null,
            $registry,
        );

        $report = $probe->report($this->appContext());
        self::assertTrue($report['enforcement']);
    }

    public function testFinalizationProbeWithNoRegistryIsUnaffectedByContributors(): void
    {
        // No registry injected (soft default) — behaves exactly as before this seam existed.
        $probe = new FinalizationProbe(
            $this->container()->get(SystemFlags::class),
            $this->container()->get(Connection::class),
            $this->container()->get(TenantRuntimeReadiness::class),
            $this->container()->get(TenantCacheSegment::class),
            null,
            $this->fakeEnforcementProbe([]),
        );

        $report = $probe->report($this->appContext());
        self::assertTrue($report['enforcement']);
    }

    // --- TenancyEnablement::confirm() contributor invocation ---------------------------------------

    public function testZeroContributorsConfirmReachesReloadingUnchanged(): void
    {
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        $activation = new RecordingExtensionActivation();
        $service = $this->service($boot1, $activation, new AdoptionContributorRegistry());

        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $service->begin()->step);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $service->begin()->step);
        self::assertSame(
            EnablementStep::RELOADING,
            $service->confirm('adopt-empty', 'Adopt Empty', 'user00000001')->step,
        );
    }

    public function testContributorAdoptRunsExactlyOnceAfterRetrofitBeforeCasViaSystemContext(): void
    {
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        $container = $boot1->getContainer();
        $store = $container->get(EnablementStore::class);
        $flags = $container->get(SystemFlags::class);

        /** @var list<array<string,mixed>> $captured */
        $captured = [];
        $contributor = $this->recordingContributor(
            'stub',
            [],
            function (ApplicationContext $ctx, string $tenantUuid) use (&$captured, $store, $flags): void {
                $statement = $ctx->getContainer()->get(Connection::class)->getPDO()
                    ->prepare('SELECT COUNT(*) FROM tenants WHERE uuid = ?');
                $statement->execute([$tenantUuid]);

                $captured[] = [
                    'tenantUuid' => $tenantUuid,
                    'step' => $store->step(),
                    'schemaState' => $flags->schemaState(),
                    'defaultTenantUuid' => $flags->defaultTenantUuid(),
                    'tenantExists' => ((int) $statement->fetchColumn()) === 1,
                    'bypass' => $ctx->getRequestState('tenancy.bypass'),
                    'tenant' => $ctx->getRequestState('tenancy.tenant'),
                ];
            },
        );
        $registry = new AdoptionContributorRegistry();
        $registry->register($contributor);

        $activation = new RecordingExtensionActivation();
        $service = $this->service($boot1, $activation, $registry);

        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $service->begin()->step);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $service->begin()->step);
        $status = $service->confirm('adopt-once', 'Adopt Once', 'user00000001');

        self::assertSame(EnablementStep::RELOADING, $status->step, (string) $store->failure());
        self::assertCount(1, $captured);
        self::assertSame($flags->defaultTenantUuid(), $captured[0]['tenantUuid']);
        self::assertSame($captured[0]['tenantUuid'], $captured[0]['defaultTenantUuid']);
        self::assertTrue($captured[0]['tenantExists'], 'default tenant row must exist when adopt() runs');
        self::assertSame('widened', $captured[0]['schemaState']);
        self::assertSame(
            EnablementStep::RETROFITTING,
            $captured[0]['step'],
            'adopt() must run before the CAS to ENABLING_ENFORCEMENT',
        );
        self::assertSame(
            'system',
            $captured[0]['bypass'],
            'adopt() must run inside TenantContextRunner::runAsSystem()',
        );
        self::assertNull($captured[0]['tenant']);
    }

    public function testThrowingContributorRecordsRetrofittingFailureAndRetryReRunsIt(): void
    {
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        $container = $boot1->getContainer();
        $store = $container->get(EnablementStore::class);

        $callCount = 0;
        $contributor = $this->recordingContributor(
            'flaky',
            [],
            function () use (&$callCount): void {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('adoption boom');
                }
            },
        );
        $registry = new AdoptionContributorRegistry();
        $registry->register($contributor);

        $activation = new RecordingExtensionActivation();
        $service = $this->service($boot1, $activation, $registry);

        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $service->begin()->step);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $service->begin()->step);

        $failed = $service->confirm('adopt-retry', 'Adopt Retry', 'user00000001');
        self::assertSame(EnablementStep::FAILED, $failed->step);
        self::assertSame(EnablementStep::RETROFITTING, $store->failedFrom());
        self::assertNotNull($store->failure());
        self::assertSame(1, $callCount);

        self::assertSame(EnablementStep::RETROFITTING, $service->retry()->step);

        $retried = $service->confirm('adopt-retry', 'Adopt Retry', 'user00000001');
        self::assertSame(EnablementStep::RELOADING, $retried->step, (string) $store->failure());
        self::assertSame(2, $callCount, 'retry() must re-run confirm(), which re-invokes the contributor');
    }

    // --- helpers -------------------------------------------------------------------------------------

    private function service(
        ApplicationContext $context,
        RecordingExtensionActivation $activation,
        ?AdoptionContributorRegistry $registry = null,
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

    /** @param list<string> $tables */
    private function stubContributor(string $id, array $tables = []): AdoptionContributor
    {
        return new class ($id, $tables) implements AdoptionContributor {
            /** @param list<string> $tables */
            public function __construct(private readonly string $id, private readonly array $tables)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            /** @return list<string> */
            public function tables(): array
            {
                return $this->tables;
            }

            public function adopt(ApplicationContext $context, string $tenantUuid): void
            {
            }
        };
    }

    /**
     * @param list<string> $tables
     * @param \Closure(ApplicationContext, string): void $onAdopt
     */
    private function recordingContributor(string $id, array $tables, \Closure $onAdopt): AdoptionContributor
    {
        return new class ($id, $tables, $onAdopt) implements AdoptionContributor {
            /**
             * @param list<string> $tables
             * @param \Closure(ApplicationContext, string): void $onAdopt
             */
            public function __construct(
                private readonly string $id,
                private readonly array $tables,
                private readonly \Closure $onAdopt,
            ) {
            }

            public function id(): string
            {
                return $this->id;
            }

            /** @return list<string> */
            public function tables(): array
            {
                return $this->tables;
            }

            public function adopt(ApplicationContext $context, string $tenantUuid): void
            {
                ($this->onAdopt)($context, $tenantUuid);
            }
        };
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
}
