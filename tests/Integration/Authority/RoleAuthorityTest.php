<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Support\RoleAuthority;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

final class RoleAuthorityTest extends AppTestCase
{
    /** @var list<string> */
    private array $users = [];

    protected function tearDown(): void
    {
        $this->removeUsers();
        parent::tearDown();
    }

    private function makeUser(string $status = 'active'): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'u_' . $uuid,
            'email' => $uuid . '@authority.test',
            'password' => 'x',
            'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->users[] = $uuid;
        return $uuid;
    }

    private function removeUsers(): void
    {
        foreach ($this->users as $uuid) {
            $this->connection()->table('user_permissions')->where('user_uuid', '=', $uuid)->delete();
            $this->connection()->table('user_roles')->where('user_uuid', '=', $uuid)->delete();
            $this->connection()->table('users')->where('uuid', '=', $uuid)->delete();
        }
        $this->users = [];
    }

    public function testCanonicalSuperuserRequiresActiveUserRoleAndAssignment(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $authority = $this->container()->get(RoleAuthority::class);
        $active = $this->makeUser();
        $inactive = $this->makeUser('inactive');
        $deleted = $this->makeUser();
        $expired = $this->makeUser();
        foreach ([$active, $inactive, $deleted, $expired] as $uuid) {
            self::assertTrue($aegis->assignRole($uuid, 'superuser'));
        }
        $this->connection()->table('users')->where('uuid', '=', $deleted)
            ->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);
        $role = $this->connection()->table('roles')->select(['uuid'])
            ->where('slug', '=', 'superuser')->first();
        self::assertIsArray($role);
        $this->connection()->table('user_roles')
            ->where('user_uuid', '=', $expired)
            ->where('role_uuid', '=', (string) $role['uuid'])
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        self::assertTrue($authority->isCanonicalSuperuser($active));
        self::assertFalse($authority->isCanonicalSuperuser($inactive));
        self::assertFalse($authority->isCanonicalSuperuser($deleted));
        self::assertFalse($authority->isCanonicalSuperuser($expired));
    }

    public function testCrossWorkspaceRolesAndHolderCountsExcludeInactiveUsers(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $authority = $this->container()->get(RoleAuthority::class);
        $active = $this->makeUser();
        $inactive = $this->makeUser('inactive');
        self::assertTrue($aegis->assignRole($active, 'workspace_manager'));
        self::assertTrue($aegis->assignRole($inactive, 'workspace_manager'));

        self::assertContains('workspace_manager', $authority->crossWorkspaceRoleSlugs());
        self::assertContains('superuser', $authority->crossWorkspaceRoleSlugs());
        self::assertNotContains('administrator', $authority->crossWorkspaceRoleSlugs());
        self::assertSame(['workspace_manager'], $authority->targetCrossWorkspaceRoleSlugs($active));
        self::assertSame([], $authority->targetCrossWorkspaceRoleSlugs($inactive));
        self::assertGreaterThanOrEqual(1, $authority->activeCrossWorkspaceHolderCount());
    }

    public function testPermissionSubsetUsesEffectiveAegisPermissionsIncludingDirectGrants(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $authority = $this->container()->get(RoleAuthority::class);
        $user = $this->makeUser();
        self::assertFalse($authority->actorHoldsAllPermissionsOf($user, 'workspace_manager'));
        self::assertTrue($aegis->assignPermission($user, 'tenancy.manage', 'thallo'));
        self::assertTrue($aegis->assignPermission($user, 'tenancy.access_any', 'thallo'));
        self::assertTrue($authority->actorHoldsAllPermissionsOf($user, 'workspace_manager'));
    }
}
