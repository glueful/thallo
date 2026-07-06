<?php

declare(strict_types=1);

namespace App\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Models\Role;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;

/**
 * The policy governing WHO may assign WHICH roles to a user — the escalation guard the flat-role
 * model needs.
 *
 * Aegis's own {@see \Glueful\Extensions\Aegis\Services\RoleService} rejects cross-role assignment
 * for Thallo because its guard walks the `parent_uuid` hierarchy, and Thallo roles are flat. So the
 * app owns the rule instead, keyed on the numeric role `level` (Aegis: superuser 100, administrator
 * 80, editor 50, user 10):
 *
 *   - Changing roles at all requires the dedicated `users.roles.manage` permission (distinct from
 *     `users.edit`, which only covers profile fields).
 *   - A non-superuser actor may not assign or revoke a role whose level is >= their own highest
 *     level (so an administrator can grant `editor` but never `administrator` or `superuser`), and
 *     may not change their OWN roles at all.
 *   - A superuser (level >= 100) is exempt from the ceiling and the self-guard (can assign anything,
 *     including superuser), but still needs the permission.
 *   - An unknown role slug is a 422.
 *
 * Only the DIFF is checked (roles being added or removed): re-sending a user's unchanged role set
 * alongside a profile edit is a no-op and never blocked, so existing edit flows are unaffected.
 */
final class UserRoleAssignmentPolicy
{
    private const MANAGE_PERMISSION = 'users.roles.manage';
    private const SUPERUSER_LEVEL = 100;

    private ?RoleRepository $roles = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AegisPermissionProvider $aegis,
    ) {
    }

    /**
     * Assert the actor may change the target user's roles from $currentSlugs to $desiredSlugs.
     *
     * @param list<string> $currentSlugs the target's current role slugs
     * @param list<string> $desiredSlugs the requested role slugs
     * @throws RoleAssignmentException 403 (permission/ceiling/self) or 422 (unknown slug)
     */
    public function assertCanSyncRoles(
        string $actorUuid,
        string $targetUuid,
        array $currentSlugs,
        array $desiredSlugs,
    ): void {
        $added = array_values(array_diff($desiredSlugs, $currentSlugs));
        $removed = array_values(array_diff($currentSlugs, $desiredSlugs));
        if ($added === [] && $removed === []) {
            return; // no role change requested — nothing to authorize
        }

        if (!$this->canManageRoles($actorUuid)) {
            throw RoleAssignmentException::forbidden('You do not have permission to manage user roles.');
        }

        $actorLevel = $this->maxLevel($actorUuid);
        $isSuperuser = $actorLevel >= self::SUPERUSER_LEVEL;

        if (!$isSuperuser && $actorUuid === $targetUuid) {
            throw RoleAssignmentException::forbidden('You cannot change your own roles.');
        }

        foreach (array_merge($added, $removed) as $slug) {
            $role = $this->roles()->findRoleBySlug($slug);
            if ($role === null) {
                throw RoleAssignmentException::unprocessable("Unknown role '{$slug}'.");
            }
            if (!$isSuperuser && $role->getLevel() >= $actorLevel) {
                throw RoleAssignmentException::forbidden(
                    "You cannot assign or revoke the role '{$slug}' (it is at or above your own level)."
                );
            }
        }
    }

    private function canManageRoles(string $actorUuid): bool
    {
        try {
            return $this->aegis->can($actorUuid, self::MANAGE_PERMISSION, 'thallo');
        } catch (\Throwable) {
            return false;
        }
    }

    /** The actor's highest role level; 0 when the actor holds no resolvable roles (fail-closed). */
    private function maxLevel(string $actorUuid): int
    {
        $max = 0;
        foreach ($this->aegis->getUserRoles($actorUuid) as $role) {
            if ($role instanceof Role) {
                $max = max($max, $role->getLevel());
            }
        }
        return $max;
    }

    private function roles(): RoleRepository
    {
        // Built the same way AegisPermissionProvider builds it internally (RoleRepository is not
        // a first-class autowired service).
        return $this->roles ??= new RoleRepository(null, $this->context);
    }
}
