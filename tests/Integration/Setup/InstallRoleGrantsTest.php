<?php

declare(strict_types=1);

namespace App\Tests\Integration\Setup;

use App\Setup\InstallRoleGrants;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;

/**
 * "Superuser has full access" was a description, not a fact: Aegis seeds its own 15 rows and
 * nothing granted Thallo's or any pack's permissions to the install roles. The grantor persists
 * the declared catalog and then grants every permission row to superuser, and every row but
 * system.config to administrator — additively and idempotently, so provision can re-run it.
 */
final class InstallRoleGrantsTest extends AppTestCase
{
    private function grants(): InstallRoleGrants
    {
        return $this->container()->get(InstallRoleGrants::class);
    }

    /** @return list<string> */
    private function roleSlugs(string $role): array
    {
        $roles = new RoleRepository(null, $this->appContext());
        $perms = new PermissionRepository(null, $this->appContext());
        $rolePerms = new RolePermissionRepository(null, $this->appContext());
        $uuid = $roles->findRoleBySlug($role)?->getUuid();
        self::assertNotNull($uuid, "seeded role {$role} exists");
        $slugs = [];
        foreach ($rolePerms->getRolePermissions($uuid) as $rp) {
            $p = $perms->findPermissionByUuid((string) $rp->getPermissionUuid());
            if ($p !== null) {
                $slugs[] = $p->getSlug();
            }
        }
        sort($slugs);
        return $slugs;
    }

    public function testSuperuserGetsEveryPermissionAndAdministratorEverythingButSystemConfig(): void
    {
        // Order-independent: an earlier install in this process may already have granted
        // everything, so take one grant away and prove apply() puts it back.
        $this->revokeFromSuperuser('content.manage');

        $report = $this->grants()->apply();

        $all = array_map(
            static fn ($p): string => $p->getSlug(),
            (new PermissionRepository(null, $this->appContext()))->findAllPermissions(),
        );
        sort($all);
        self::assertContains('content.manage', $all, 'the catalog sync persisted Thallo\'s core slugs');
        self::assertContains('audit.view', $all, 'extension declarations (audit) are persisted too');
        self::assertContains('analytics.read', $all, 'migration-seeded pack permissions are present');

        self::assertSame($all, $this->roleSlugs('superuser'));
        self::assertSame(
            array_values(array_diff($all, ['system.config'])),
            $this->roleSlugs('administrator'),
        );
        self::assertGreaterThanOrEqual(1, $report->granted['superuser'], 'the revoked grant was restored');
    }

    private function revokeFromSuperuser(string $slug): void
    {
        $roles = new RoleRepository(null, $this->appContext());
        $perms = new PermissionRepository(null, $this->appContext());
        $role = $roles->findRoleBySlug('superuser')?->getUuid();
        $perm = $perms->findPermissionBySlug($slug)?->getUuid();
        if ($role !== null && $perm !== null) {
            (new RolePermissionRepository(null, $this->appContext()))->revokePermissionFromRole($role, $perm);
        }
    }

    public function testASecondRunGrantsNothingNew(): void
    {
        $this->grants()->apply();
        $again = $this->grants()->apply();

        self::assertSame(['superuser' => 0, 'administrator' => 0], $again->granted);
    }
}
