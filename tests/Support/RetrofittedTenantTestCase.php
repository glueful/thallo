<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\Retrofit\SchemaRetrofit;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Acceptance base. Boot1 (parent, scoping OFF): run the real retrofit under the barrier → widened
 * schema, schema_state=widened, barrier UP. Then set tenancy.enabled=1, RESET all process-global hooks
 * (boot() has no idempotency guard — a second boot would otherwise stack a duplicate guard/stamper/
 * interceptor), and BOOT2 FRESH against the SAME throwaway DB so the read-hook/stamper/guard + table
 * registration arm. Lower the barrier through boot2 (emulating Phase E's transition to `on`) and seed
 * two tenants. All accessors resolve from boot2.
 */
abstract class RetrofittedTenantTestCase extends RetrofitHarnessTestCase
{
    protected static ?ApplicationContext $onApp = null;
    protected static string $defaultTenantUuid = '';
    protected static string $tenantAUuid = '';
    protected static string $tenantBUuid = '';

    protected function setUp(): void
    {
        parent::setUp();
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.enabled', '1');
        $flags->put('tenancy.enable_step', 'on');
        $flags->put('tenancy.schema_state', 'widened');
        if (self::$defaultTenantUuid !== '') {
            $flags->put('tenancy.default_tenant_uuid', self::$defaultTenantUuid);
        }
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$engineApp === null) {
            return;
        }

        // Boot1: real retrofit (raises the barrier inside run()).
        $report = self::$engineApp->getContainer()->get(SchemaRetrofit::class)
            ->run('tenant-1', 'Tenant 1', 'user00000001');
        self::$defaultTenantUuid = $report->defaultTenantUuid();

        // Flip enablement (write via boot1), then reset hooks and BOOT2 FRESH so scoping arms cleanly.
        self::$engineApp->getContainer()->get(SystemFlags::class)->put('tenancy.enabled', '1');
        self::resetTenancyGlobals();
        /** @var array{enabled: list<string>} $base */
        $base = require dirname(__DIR__, 2) . '/config/serviceproviders.php';
        $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'];
        self::$onApp = self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers]);

        // Lower the barrier THROUGH boot2 (Phase E's transition to `on`), then optionally seed
        // tenants A/B for isolation suites. Finalization acceptance overrides the hook so it starts
        // with the one real default tenant and can prove bootstrap readiness before adding tenant two.
        self::$onApp->getContainer()->get(RetrofitMaintenanceGuard::class)->end();
        self::$onApp->getContainer()->get(SystemFlags::class)->put('tenancy.enable_step', 'on');
        if (static::seedAdditionalTenants()) {
            /** @var TenantProvisioner $provisioner */
            $provisioner = self::$onApp->getContainer()->get(TenantProvisioner::class);
            self::$tenantAUuid = Utils::generateNanoID(12);
            self::$tenantBUuid = Utils::generateNanoID(12);
            $provisioner->provisionDefault(
                self::$onApp,
                self::$tenantAUuid,
                'tenant-a',
                'Tenant A',
                'user00000001',
            );
            $provisioner->provisionDefault(
                self::$onApp,
                self::$tenantBUuid,
                'tenant-b',
                'Tenant B',
                'user00000001',
            );
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$onApp = null;
        parent::tearDownAfterClass(); // resets hooks + drops throwaway DB + restores env
    }

    protected function container(): ContainerInterface
    {
        return self::$onApp?->getContainer() ?? parent::container();
    }

    protected function appContext(): ApplicationContext
    {
        return self::$onApp ?? parent::appContext();
    }

    protected function connection(): Connection
    {
        return $this->container()->get(Connection::class);
    }

    protected function runAsTenant(string $tenantUuid, callable $fn): mixed
    {
        return $this->container()->get(TenantContextRunner::class)->runAsTenant($tenantUuid, $fn);
    }

    protected function runAsSystem(callable $fn): mixed
    {
        return $this->container()->get(TenantContextRunner::class)->runAsSystem($fn);
    }

    protected static function seedAdditionalTenants(): bool
    {
        return true;
    }
}
