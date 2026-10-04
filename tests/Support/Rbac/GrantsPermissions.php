<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Rbac;

use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * A user holding exactly the permissions a test names, through real Aegis grants — the harness
 * cannot mint bearer tokens, so requests carry the identity as the auth middleware leaves it.
 * Requires the host test to provide connection() and container() (AppTestCase does).
 */
trait GrantsPermissions
{
    /** @var list<string> */
    private array $grantedUsers = [];
    /** @var list<string> */
    private array $grantedRoles = [];

    /** @param list<string> $permissions */
    protected function userWith(string $role, array $permissions): string
    {
        // A role left by an interrupted earlier run goes first (roles soft-delete; this is a hard one).
        $this->purgeRoles('slug', [$role]);
        $roleUuid = Utils::generateNanoID(12);
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid, 'name' => $role, 'slug' => $role, 'description' => 'test role',
            'level' => 30, 'is_system' => false, 'status' => 'active',
        ]);
        $this->grantedRoles[] = $roleUuid;
        $repository = new PermissionRepository($this->connection());
        $assign = new RolePermissionRepository($this->connection());
        foreach ($permissions as $slug) {
            $permission = $repository->findPermissionBySlug($slug);
            self::assertNotNull($permission, "seeded permission {$slug} must exist");
            $assign->assignPermissionToRole($roleUuid, $permission->getUuid(), []);
        }
        $user = Utils::generateNanoID(12);
        $this->grantedUsers[] = $user;
        $provider = $this->container()->get(AegisPermissionProvider::class);
        self::assertTrue($provider->assignRole($user, $role));
        $provider->invalidateAllCache();
        return $user;
    }

    protected function requestAs(?string $user, string $method = 'GET', string $path = '/x'): Request
    {
        $request = Request::create($path, $method);
        if ($user !== null) {
            $request->attributes->set('auth.user', new UserIdentity(uuid: $user, roles: [], username: 'tester'));
        }
        return $request;
    }

    /** @param list<string> $values */
    private function purgeRoles(string $column, array $values): void
    {
        $pdo = $this->connection()->getPDO();
        foreach ($values as $value) {
            $find = $pdo->prepare("SELECT uuid FROM roles WHERE {$column} = ?");
            $find->execute([$value]);
            foreach ($find->fetchAll(\PDO::FETCH_COLUMN) as $uuid) {
                $pdo->prepare('DELETE FROM role_permissions WHERE role_uuid = ?')->execute([$uuid]);
                $pdo->prepare('DELETE FROM user_roles WHERE role_uuid = ?')->execute([$uuid]);
                $pdo->prepare('DELETE FROM roles WHERE uuid = ?')->execute([$uuid]);
            }
        }
    }

    protected function scrubGrants(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach ($this->grantedUsers as $user) {
            $pdo->prepare('DELETE FROM user_roles WHERE user_uuid = ?')->execute([$user]);
        }
        $this->purgeRoles('uuid', $this->grantedRoles);
        $this->grantedUsers = [];
        $this->grantedRoles = [];
        $this->container()->get(AegisPermissionProvider::class)->invalidateAllCache();
    }
}
