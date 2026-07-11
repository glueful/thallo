<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Support\RoleAssignmentException;
use App\Support\UserRoleAssignmentPolicy;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

final class RoleAssignmentPolicyTest extends AppTestCase
{
    /** @var list<string> */
    private array $users = [];

    protected function tearDown(): void
    {
        foreach ($this->users as $uuid) {
            $this->connection()->table('user_roles')->where('user_uuid', '=', $uuid)->delete();
            $this->connection()->table('users')->where('uuid', '=', $uuid)->delete();
        }
        $this->users = [];
        parent::tearDown();
    }

    private function makeUser(string ...$roles): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'u_' . $uuid,
            'email' => $uuid . '@policy.test',
            'password' => 'x',
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->users[] = $uuid;
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        foreach ($roles as $role) {
            self::assertTrue($aegis->assignRole($uuid, $role));
        }
        return $uuid;
    }

    public function testSuperuserIsApiImmutableForAdditionAndRemoval(): void
    {
        $policy = $this->container()->get(UserRoleAssignmentPolicy::class);
        $actor = $this->makeUser('superuser');
        foreach ([[[], ['superuser']], [['superuser'], []]] as [$current, $desired]) {
            try {
                $policy->assertCanSyncRoles($actor, $this->makeUser(), $current, $desired);
                self::fail('superuser mutation should be denied');
            } catch (RoleAssignmentException $e) {
                self::assertSame(403, $e->status);
            }
        }
    }

    public function testOnlyCanonicalSuperuserMayAssignWorkspaceManager(): void
    {
        $policy = $this->container()->get(UserRoleAssignmentPolicy::class);
        $admin = $this->makeUser('administrator');
        $target = $this->makeUser();
        $this->expectException(RoleAssignmentException::class);
        $policy->assertCanSyncRoles($admin, $target, [], ['workspace_manager']);
    }

    public function testCanonicalSuperuserMayAssignWorkspaceManager(): void
    {
        $policy = $this->container()->get(UserRoleAssignmentPolicy::class);
        $policy->assertCanSyncRoles(
            $this->makeUser('superuser'),
            $this->makeUser(),
            [],
            ['workspace_manager'],
        );
        self::addToAssertionCount(1);
    }

    public function testCustomLevel100RoleIsNotCanonicalSuperuser(): void
    {
        $this->connection()->table('roles')->insert([
            'uuid' => Utils::generateNanoID(12),
            'name' => 'Custom Root ' . Utils::generateNanoID(4),
            'slug' => 'custom_root_' . strtolower(Utils::generateNanoID(4)),
            'level' => 100,
            'is_system' => false,
            'status' => 'active',
        ]);
        $custom = $this->connection()->table('roles')->where('name', 'LIKE', 'Custom Root %')
            ->orderBy('id', 'DESC')->first();
        self::assertIsArray($custom);
        $actor = $this->makeUser('administrator', (string) $custom['slug']);

        $this->expectException(RoleAssignmentException::class);
        $this->container()->get(UserRoleAssignmentPolicy::class)->assertCanSyncRoles(
            $actor,
            $this->makeUser(),
            [],
            ['workspace_manager'],
        );
    }

    public function testRemovingLowerRoleDoesNotRequireItsPermissions(): void
    {
        $this->container()->get(UserRoleAssignmentPolicy::class)->assertCanSyncRoles(
            $this->makeUser('administrator'),
            $this->makeUser('editor'),
            ['editor'],
            [],
        );
        self::addToAssertionCount(1);
    }
}
