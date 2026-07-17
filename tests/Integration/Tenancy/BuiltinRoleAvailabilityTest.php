<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Content\Authorization\BuiltinRoleAvailabilityRepository;
use App\Content\Authorization\EffectiveRoleMatrix;
use App\Content\Authorization\TenantRoleLifecycle;
use App\Content\Authorization\TenantRoleLifecycleException;
use App\Content\Authorization\TenantRolePolicyMutator;
use App\Content\Authorization\ThalloMembershipRoleAuthority;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Helpers\Utils;

/**
 * Per-workspace availability of the BUILT-IN roles: a workspace can retire the
 * defaults it never uses (admin/member/viewer) — they vanish from every assignment
 * surface but stay code-defined vocabulary — while `owner` is never disableable and
 * untouched workspaces keep the four-role default experience (absent row = active).
 */
final class BuiltinRoleAvailabilityTest extends AppTestCase
{
    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantUuid = Utils::generateNanoID(12);
        $this->connection()->table('tenants')->insert([
            'uuid' => $this->tenantUuid,
            'slug' => 'avail-' . strtolower(substr($this->tenantUuid, 0, 6)),
            'name' => 'Availability Test',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (
            ['tenant_memberships', 'tenant_role_overrides', 'tenant_roles',
             'tenant_role_policy', 'tenant_role_availability'] as $table
        ) {
            $this->connection()->table($table)->where('tenant_uuid', '=', $this->tenantUuid)->forceDelete();
        }
        $this->connection()->table('tenants')->where('uuid', '=', $this->tenantUuid)->forceDelete();
        parent::tearDown();
    }

    private function lifecycle(): TenantRoleLifecycle
    {
        return $this->container()->get(TenantRoleLifecycle::class);
    }

    private function authority(): ThalloMembershipRoleAuthority
    {
        return $this->container()->get(ThalloMembershipRoleAuthority::class);
    }

    public function testDisabledBuiltinLeavesEveryAssignmentSurfaceAndReEnableRestoresIt(): void
    {
        $authority = $this->authority();
        $matrix = $this->container()->get(EffectiveRoleMatrix::class);

        // Baseline: all four built-ins assignable, member grants content.view.
        $slugs = array_column($authority->assignableRoles($this->tenantUuid), 'slug');
        self::assertSame(['owner', 'admin', 'member', 'viewer'], $slugs);
        self::assertTrue($matrix->allows($this->tenantUuid, 'member', 'content.view'));

        $this->lifecycle()->disableBuiltin($this->tenantUuid, 'member', null, null, 'actor0000001');

        // Gone from the picker projection and from isAssignable...
        $slugs = array_column($authority->assignableRoles($this->tenantUuid), 'slug');
        self::assertSame(['owner', 'admin', 'viewer'], $slugs);
        self::assertFalse($authority->isAssignable($this->appContext(), $this->tenantUuid, 'member'));
        // ...and fail-closed: a stale reference to the disabled role holds NOTHING.
        self::assertFalse($matrix->allows($this->tenantUuid, 'member', 'content.view'));
        self::assertSame([], $matrix->capabilitiesFor($this->tenantUuid, 'member'));

        // The tenancy engine refuses to assign it too (single authority).
        try {
            $this->container()->get(TenantAdministration::class)
                ->addMember($this->appContext(), $this->tenantUuid, 'member000009', 'member');
            self::fail('Disabled built-in must not be assignable.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->lifecycle()->enableBuiltin($this->tenantUuid, 'member', 'actor0000001');
        self::assertTrue($authority->isAssignable($this->appContext(), $this->tenantUuid, 'member'));
        self::assertTrue($matrix->allows($this->tenantUuid, 'member', 'content.view'));
    }

    public function testOwnerCanNeverBeDisabled(): void
    {
        try {
            $this->lifecycle()->disableBuiltin($this->tenantUuid, 'owner', null, null, 'actor0000001');
            self::fail('Owner must not be disableable.');
        } catch (TenantRoleLifecycleException $exception) {
            self::assertArrayHasKey('role', $exception->errors);
        }
        self::assertFalse(
            $this->container()->get(BuiltinRoleAvailabilityRepository::class)
                ->isDisabled($this->tenantUuid, 'owner'),
        );
    }

    public function testCustomRolesAreRejectedByTheBuiltinPath(): void
    {
        $this->lifecycle()->create($this->tenantUuid, 'strategist', 'Strategist', 'actor0000001');
        $this->expectException(TenantRoleLifecycleException::class);
        $this->lifecycle()->disableBuiltin($this->tenantUuid, 'strategist', null, null, 'actor0000001');
    }

    public function testDisableRefusesWhileMembersHoldTheRoleUnlessReassigned(): void
    {
        $admin = $this->container()->get(TenantAdministration::class);
        $admin->addMember($this->appContext(), $this->tenantUuid, 'member000001', 'member');

        try {
            $this->lifecycle()->disableBuiltin($this->tenantUuid, 'member', null, null, 'actor0000001');
            self::fail('Held role must require reassignment.');
        } catch (TenantRoleLifecycleException $exception) {
            self::assertArrayHasKey('reassign_to', $exception->errors);
        }

        // Atomic reassignment to another assignable role, then the disable lands.
        $this->lifecycle()->disableBuiltin($this->tenantUuid, 'member', 'viewer', null, 'actor0000001');
        $row = $this->connection()->table('tenant_memberships')
            ->where('tenant_uuid', '=', $this->tenantUuid)
            ->where('user_uuid', '=', 'member000001')->first();
        self::assertSame('viewer', (string) $row['role']);
    }

    public function testDisableRejectsAnUnassignableOrIdenticalReplacement(): void
    {
        $admin = $this->container()->get(TenantAdministration::class);
        $admin->addMember($this->appContext(), $this->tenantUuid, 'member000002', 'viewer');

        try {
            $this->lifecycle()->disableBuiltin($this->tenantUuid, 'viewer', 'viewer', null, 'actor0000001');
            self::fail('Identical replacement must be rejected.');
        } catch (TenantRoleLifecycleException $exception) {
            self::assertArrayHasKey('reassign_to', $exception->errors);
        }
        try {
            $this->lifecycle()->disableBuiltin($this->tenantUuid, 'viewer', 'nosuchrole0', null, 'actor0000001');
            self::fail('Unassignable replacement must be rejected.');
        } catch (TenantRoleLifecycleException $exception) {
            self::assertArrayHasKey('reassign_to', $exception->errors);
        }
    }

    public function testOverridesSurviveADisableEnableCycle(): void
    {
        $policy = $this->container()->get(TenantRolePolicyMutator::class);
        $matrix = $this->container()->get(EffectiveRoleMatrix::class);

        // Grant viewer an extra capability, then disable + re-enable the role.
        $policy->reconcile($this->tenantUuid, 'viewer', ['content.create'], [], 'actor0000001');
        self::assertTrue($matrix->allows($this->tenantUuid, 'viewer', 'content.create'));

        $this->lifecycle()->disableBuiltin($this->tenantUuid, 'viewer', null, null, 'actor0000001');
        self::assertFalse($matrix->allows($this->tenantUuid, 'viewer', 'content.create'));

        $this->lifecycle()->enableBuiltin($this->tenantUuid, 'viewer', 'actor0000001');
        self::assertTrue($matrix->allows($this->tenantUuid, 'viewer', 'content.create'));
        self::assertTrue($matrix->allows($this->tenantUuid, 'viewer', 'content.view'));
    }

    public function testAvailabilityIsPerWorkspace(): void
    {
        $otherTenant = Utils::generateNanoID(12);
        $this->connection()->table('tenants')->insert([
            'uuid' => $otherTenant,
            'slug' => 'avail2-' . strtolower(substr($otherTenant, 0, 5)),
            'name' => 'Other',
            'status' => 'active',
        ]);
        try {
            $this->lifecycle()->disableBuiltin($this->tenantUuid, 'admin', null, null, 'actor0000001');
            self::assertFalse($this->authority()->isAssignable($this->appContext(), $this->tenantUuid, 'admin'));
            // The neighbor workspace keeps the default four-role experience untouched.
            self::assertTrue($this->authority()->isAssignable($this->appContext(), $otherTenant, 'admin'));
        } finally {
            foreach (['tenant_role_availability', 'tenant_role_policy'] as $table) {
                $this->connection()->table($table)->where('tenant_uuid', '=', $otherTenant)->forceDelete();
            }
            $this->connection()->table('tenants')->where('uuid', '=', $otherTenant)->forceDelete();
        }
    }

    // Member signup hands out a role at activation; disabling THAT role must demand a
    // replacement signup role atomically — otherwise signups start failing silently.
    // Note the default: with signup enabled and no explicit setting, the signup role
    // IS 'viewer', so disabling viewer hits this guard even without configuration.
    public function testDisablingTheConfiguredSignupRoleRequiresAReplacement(): void
    {
        $signup = $this->container()->get(\App\Signup\SignupConfig::class);
        $signup->setMemberSignup($this->tenantUuid, true, 'viewer');

        try {
            try {
                $this->lifecycle()->disableBuiltin($this->tenantUuid, 'viewer', null, null, 'actor0000001');
                self::fail('Disabling the signup role must require a replacement.');
            } catch (TenantRoleLifecycleException $exception) {
                self::assertArrayHasKey('signup_role', $exception->errors);
            }
            // Nothing changed: role still assignable, signup still viewer.
            self::assertTrue($this->authority()->isAssignable($this->appContext(), $this->tenantUuid, 'viewer'));
            self::assertSame('viewer', $signup->memberSignupRole($this->tenantUuid));

            // With a replacement, the disable lands and signup re-targets atomically.
            $this->lifecycle()->disableBuiltin($this->tenantUuid, 'viewer', null, 'member', 'actor0000001');
            self::assertFalse($this->authority()->isAssignable($this->appContext(), $this->tenantUuid, 'viewer'));
            self::assertSame('member', $signup->memberSignupRole($this->tenantUuid));
        } finally {
            $this->connection()->table('settings')
                ->where('key', 'LIKE', 'signup.members.%')->forceDelete();
        }
    }
}
