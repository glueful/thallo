<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Tenancy\Retrofit\DefaultTenant;
use Thallo\Tenancy\Retrofit\PreexistingTenantException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Exercises the operation-scoped default-tenant provisioning against the narrow throwaway DB
 * (tenancy bound, scoping off). The harness seeds users row `user00000001` — used as the owner.
 */
final class DefaultTenantTest extends RetrofitHarnessTestCase
{
    private function defaultTenant(): DefaultTenant
    {
        return $this->container()->get(DefaultTenant::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clear any operation-scoped / pointer state left by a prior test in this class.
        $this->flags()->forget('tenancy.provisioning_tenant_uuid');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM tenant_memberships');
        $pdo->exec('DELETE FROM tenants');
    }

    public function testEnsureCreatesTenantOwnerMembershipAndPointer(): void
    {
        $uuid = $this->defaultTenant()->ensure('primary', 'Primary', 'user00000001');

        self::assertNotSame('', $uuid);
        self::assertSame($uuid, $this->defaultTenant()->uuid());
        self::assertSame($uuid, $this->flags()->get('tenancy.default_tenant_uuid'));

        $tenant = $this->connection()->table('tenants')->where('uuid', $uuid)->first();
        self::assertNotNull($tenant);
        self::assertSame('primary', $tenant['slug']);
        self::assertSame('Primary', $tenant['name']);
        self::assertSame('active', $tenant['status']);

        $membership = $this->connection()->table('tenant_memberships')
            ->where('tenant_uuid', $uuid)
            ->where('user_uuid', 'user00000001')
            ->first();
        self::assertNotNull($membership);
        self::assertSame('owner', $membership['role']);
        self::assertSame('active', $membership['status']);
    }

    public function testCrashThenRetryReusesIntendedUuid(): void
    {
        // Simulate a crash AFTER the provisioning uuid was recorded but BEFORE completion: the
        // intended identity is persisted; the created tenant must adopt THAT uuid, not a new one.
        $intended = 'intended0001';
        $this->flags()->put('tenancy.provisioning_tenant_uuid', $intended);

        $uuid = $this->defaultTenant()->ensure('primary', 'Primary', 'user00000001');

        self::assertSame($intended, $uuid);
        self::assertNotNull(
            $this->connection()->table('tenants')->where('uuid', $intended)->first()
        );
        // Exactly one tenant — the retry did not mint a second.
        self::assertSame(1, $this->connection()->table('tenants')->count());
    }

    public function testPreexistingTenantBlocksFreshEnablement(): void
    {
        // A pre-existing tenant with NO provisioning uuid recorded (a fresh enablement over an
        // install that already has tenant rows) must be refused, not adopted.
        $this->connection()->getPDO()->exec(
            "INSERT INTO tenants (uuid, slug, name, status)
             VALUES ('preexist0001', 'legacy', 'Legacy', 'active')"
        );

        $this->expectException(PreexistingTenantException::class);
        $this->defaultTenant()->ensure('primary', 'Primary', 'user00000001');
    }
}
