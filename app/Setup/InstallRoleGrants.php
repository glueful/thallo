<?php

declare(strict_types=1);

namespace App\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;
use Glueful\Extensions\ExtensionManager;
use Glueful\Interfaces\Permission\PermissionCatalogSyncInterface;
use Glueful\Permissions\Catalog\PermissionRegistry;

/**
 * Makes "superuser has full access" true.
 *
 * Aegis seeds the install roles with its own permissions only; Thallo's packs seed theirs by
 * migration and Thallo's core catalog is declared through the provider — and nothing granted
 * any of it to a role, so the first admin could not manage content models, read the audit
 * log or see analytics. apply() persists the declared catalog into the RBAC provider (the same
 * mechanics as `permissions:sync`) and then grants every permission row to `superuser`, and
 * every row but `system.config` to `administrator`. Additive and idempotent: web setup and
 * `thallo:create-admin` run it once at install, `thallo:provision` re-runs it so a pack added
 * on upgrade reaches the install roles too. Operator revocations are not re-applied blindly —
 * only rows a role has never held are granted.
 */
final class InstallRoleGrants
{
    /** @var array<string, list<string>> role slug => permission slugs withheld from that role */
    public const ROLE_EXCLUSIONS = [
        'superuser' => [],
        'administrator' => ['system.config'],
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ExtensionManager $extensions,
    ) {
    }

    public function apply(): InstallRoleGrantsReport
    {
        $declared = $this->syncCatalog();

        $granted = [];
        foreach (self::ROLE_EXCLUSIONS as $role => $except) {
            $granted[$role] = $this->grantAll($role, $except);
        }

        return new InstallRoleGrantsReport($declared, $granted);
    }

    /** Persist every declared permission (framework core, app, extensions) into the provider. */
    private function syncCatalog(): int
    {
        $container = $this->context->getContainer();
        $this->extensions->aggregatePermissionCatalog();
        $registry = $container->get(PermissionRegistry::class);

        $provider = $container->has('permission.manager')
            ? $container->get('permission.manager')->getProvider()
            : null;
        if (!$provider instanceof PermissionCatalogSyncInterface) {
            throw new \RuntimeException(
                'No persistent RBAC provider with catalog sync is active — enable glueful/aegis and run migrations.'
            );
        }

        $permissions = array_map(static fn ($p): array => $p->toArray(), $registry->permissions());
        $roles = array_map(static fn ($r): array => $r->toArray(), $registry->roles());
        $provider->syncCatalog(array_values($permissions), array_values($roles));

        return count($permissions);
    }

    /**
     * @param list<string> $except
     * @return int permissions newly granted
     */
    private function grantAll(string $roleSlug, array $except): int
    {
        $roles = new RoleRepository(null, $this->context);
        $permissions = new PermissionRepository(null, $this->context);
        $rolePermissions = new RolePermissionRepository(null, $this->context);

        $role = $roles->findRoleBySlug($roleSlug);
        if ($role === null) {
            throw new \RuntimeException("Seeded role '{$roleSlug}' is missing — run `php glueful migrate:run`.");
        }
        $roleUuid = $role->getUuid();

        $held = [];
        foreach ($rolePermissions->getRolePermissions($roleUuid) as $rp) {
            $held[$rp->getPermissionUuid()] = true;
        }

        $granted = 0;
        foreach ($permissions->findAllPermissions() as $permission) {
            if (in_array($permission->getSlug(), $except, true) || isset($held[$permission->getUuid()])) {
                continue;
            }
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid());
            $granted++;
        }

        if ($granted > 0) {
            // role_permissions was written directly — cached decisions are stale.
            $provider = $this->context->getContainer()->get('permission.manager')->getProvider();
            if ($provider !== null && method_exists($provider, 'invalidateAllCache')) {
                $provider->invalidateAllCache();
            }
        }

        return $granted;
    }
}
