<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Support\RoleAssignmentException;
use App\Support\UserRoleAssignmentPolicy;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

/**
 * The level-ceiling escalation guard for user role assignment (fix for the privilege-escalation
 * hole where any users.edit holder could assign `superuser`). Runs against the real seeded Aegis
 * role ladder (superuser 100 / administrator 80 / editor 50 / user 10) and the `users.roles.manage`
 * permission granted by migration 008.
 */
final class UserRoleAssignmentPolicyTest extends AppTestCase
{
    /** @var list<string> */
    private array $seeded = [];

    protected function tearDown(): void
    {
        foreach ($this->seeded as $uuid) {
            $this->connection()->table('user_roles')->where('user_uuid', $uuid)->delete();
            $this->connection()->table('users')->where('uuid', $uuid)->delete();
        }
        $this->seeded = [];
        parent::tearDown();
    }

    public function testAdministratorCannotAssignSuperuser(): void
    {
        $admin = $this->userWithRole('administrator');
        $target = $this->seedUser();

        $this->expectException(RoleAssignmentException::class);
        $this->policy()->assertCanSyncRoles($admin, $target, [], ['superuser']);
    }

    public function testAdministratorCannotAssignPeerAdministrator(): void
    {
        // Ceiling is strict: an actor cannot grant a role at OR above their own level.
        $admin = $this->userWithRole('administrator');
        $target = $this->seedUser();

        $this->expectException(RoleAssignmentException::class);
        $this->policy()->assertCanSyncRoles($admin, $target, [], ['administrator']);
    }

    public function testAdministratorCanAssignLowerRole(): void
    {
        $admin = $this->userWithRole('administrator');
        $target = $this->seedUser();

        // editor (50) is below administrator (80) — allowed, no exception.
        $this->policy()->assertCanSyncRoles($admin, $target, [], ['editor']);
        $this->addToAssertionCount(1);
    }

    public function testAdministratorCannotChangeOwnRoles(): void
    {
        $admin = $this->userWithRole('administrator');

        $this->expectException(RoleAssignmentException::class);
        $this->policy()->assertCanSyncRoles($admin, $admin, ['administrator'], ['administrator', 'editor']);
    }

    public function testUnknownRoleSlugIsUnprocessable(): void
    {
        $admin = $this->userWithRole('administrator');
        $target = $this->seedUser();

        try {
            $this->policy()->assertCanSyncRoles($admin, $target, [], ['not-a-real-role']);
            self::fail('expected a RoleAssignmentException for an unknown slug');
        } catch (RoleAssignmentException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testActorWithoutManagePermissionIsForbidden(): void
    {
        // An editor holds neither users.edit nor users.roles.manage — the permission gate denies.
        $editor = $this->userWithRole('editor');
        $target = $this->seedUser();

        try {
            $this->policy()->assertCanSyncRoles($editor, $target, [], ['user']);
            self::fail('expected a 403 for an actor without users.roles.manage');
        } catch (RoleAssignmentException $e) {
            self::assertSame(403, $e->status);
        }
    }

    public function testSuperuserIsExemptFromCeiling(): void
    {
        $su = $this->userWithRole('superuser');
        $target = $this->seedUser();

        // A superuser may assign superuser (exempt) and change their own roles.
        $this->policy()->assertCanSyncRoles($su, $target, [], ['superuser']);
        $this->addToAssertionCount(1);
    }

    public function testNoRoleChangeIsAlwaysAllowed(): void
    {
        // Re-sending the same set (e.g. a profile-only edit) is a no-op — never blocked, even for a
        // low-privilege actor with no manage permission.
        $editor = $this->userWithRole('editor');
        $this->policy()->assertCanSyncRoles($editor, $editor, ['editor'], ['editor']);
        $this->addToAssertionCount(1);
    }

    private function policy(): UserRoleAssignmentPolicy
    {
        return $this->container()->get(UserRoleAssignmentPolicy::class);
    }

    private function userWithRole(string $slug): string
    {
        $uuid = $this->seedUser();
        /** @var AegisPermissionProvider $aegis */
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $aegis->assignRole($uuid, $slug);
        return $uuid;
    }

    private function seedUser(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'role_' . substr($uuid, 0, 8),
            'email' => $uuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->seeded[] = $uuid;
        return $uuid;
    }
}
