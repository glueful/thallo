<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Workflow\Concerns;

use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

/**
 * Grants a permission slug to a user via a per-user test role (permission + role +
 * role_permissions via SQL, user link via Aegis). Use FRESH user uuids per test — the
 * permission provider may cache per-user lookups within a process.
 */
trait GrantsPermissions
{
    private function grantPermission(string $userUuid, string $slug): void
    {
        $db = $this->connection();
        $perm = $db->table('permissions')->select(['uuid'])->where('slug', '=', $slug)->first();
        $permUuid = $perm !== null ? (string) $perm['uuid'] : Utils::generateNanoID();
        if ($perm === null) {
            $db->table('permissions')->insert([
                'uuid' => $permUuid, 'slug' => $slug, 'name' => $slug,
                'category' => 'test', 'description' => $slug, 'is_system' => false,
            ]);
        }
        $roleSlug = 'testrole-' . substr(hash('sha256', $userUuid . $slug), 0, 8);
        $role = $db->table('roles')->select(['uuid'])->where('slug', '=', $roleSlug)->first();
        $roleUuid = $role !== null ? (string) $role['uuid'] : Utils::generateNanoID();
        if ($role === null) {
            $db->table('roles')->insert([
                'uuid' => $roleUuid, 'slug' => $roleSlug, 'name' => $roleSlug,
            ]);
            $db->table('role_permissions')->insert([
                'uuid' => Utils::generateNanoID(),
                'role_uuid' => $roleUuid,
                'permission_uuid' => $permUuid,
            ]);
        }
        $assigned = $this->container()->get(AegisPermissionProvider::class)
            ->assignRole($userUuid, $roleSlug);
        if (!$assigned) {
            self::fail("could not assign test role {$roleSlug} to {$userUuid}");
        }
    }
}
