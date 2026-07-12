<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Content\Authorization\EffectiveRoleMatrix;
use App\Content\Authorization\TenantRoleLifecycle;
use App\Content\Authorization\TenantRoleLifecycleException;
use App\Content\Authorization\TenantRolePolicyMutator;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Helpers\Utils;

final class TenantRoleLifecycleTest extends AppTestCase
{
    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantUuid = Utils::generateNanoID(12);
        $this->connection()->table('tenants')->insert([
            'uuid' => $this->tenantUuid,
            'slug' => 'role-' . strtolower(substr($this->tenantUuid, 0, 6)),
            'name' => 'Role Test',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->connection()->table('tenant_memberships')->where('tenant_uuid', '=', $this->tenantUuid)->forceDelete();
        $this->connection()->table('tenant_role_overrides')
            ->where('tenant_uuid', '=', $this->tenantUuid)->forceDelete();
        $this->connection()->table('tenant_roles')->where('tenant_uuid', '=', $this->tenantUuid)->forceDelete();
        $this->connection()->table('tenant_role_policy')->where('tenant_uuid', '=', $this->tenantUuid)->forceDelete();
        $this->connection()->table('tenants')->where('uuid', '=', $this->tenantUuid)->forceDelete();
        parent::tearDown();
    }

    public function testCustomRoleAssignmentDisableAndReassignmentDelete(): void
    {
        $lifecycle = $this->container()->get(TenantRoleLifecycle::class);
        $policy = $this->container()->get(TenantRolePolicyMutator::class);
        $admin = $this->container()->get(TenantAdministration::class);
        $matrix = $this->container()->get(EffectiveRoleMatrix::class);

        $lifecycle->create($this->tenantUuid, 'reviewer', 'Reviewer', 'actor0000001');
        $policy->reconcile($this->tenantUuid, 'reviewer', ['content.view'], [], 'actor0000001');
        $admin->addMember($this->appContext(), $this->tenantUuid, 'member000001', 'reviewer');
        self::assertTrue($matrix->allows($this->tenantUuid, 'reviewer', 'content.view'));

        $lifecycle->disable($this->tenantUuid, 'reviewer', 'actor0000001');
        self::assertFalse($matrix->allows($this->tenantUuid, 'reviewer', 'content.view'));
        try {
            $admin->addMember($this->appContext(), $this->tenantUuid, 'member000002', 'reviewer');
            self::fail('Disabled role must not be assignable.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            $lifecycle->delete($this->tenantUuid, 'reviewer', null, 'actor0000001');
            self::fail('Assigned role must require reassignment.');
        } catch (TenantRoleLifecycleException $exception) {
            self::assertArrayHasKey('reassign_to', $exception->errors);
        }
        $lifecycle->delete($this->tenantUuid, 'reviewer', 'viewer', 'actor0000001');
        $membership = $this->connection()->table('tenant_memberships')
            ->where('tenant_uuid', '=', $this->tenantUuid)->where('user_uuid', '=', 'member000001')->first();
        self::assertSame('viewer', $membership['role'] ?? null);
        self::assertSame(0, $this->connection()->table('tenant_roles')
            ->where('tenant_uuid', '=', $this->tenantUuid)->count());
    }
}
