<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Thallo\Core\Setup\InstallRoleGrants;
use Thallo\Core\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;

/**
 * "Superuser has full access" was a description, not a fact: Aegis seeds its own 15 rows and
 * nothing granted Thallo's or any pack's permissions to the install roles. The grantor persists
 * the declared catalog and grants the install roles each permission once: at install everything,
 * and on a later provision only what is new, so a revocation made since stays revoked.
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

    public function testAFreshInstallGivesSuperuserEverythingAndAdministratorAllButWhatIsWithheld(): void
    {
        $this->freshInstall();

        $report = $this->grants()->apply();

        $all = $this->allSlugs();
        self::assertContains('content.manage', $all, 'the catalog sync persisted Thallo\'s core slugs');
        self::assertContains('styles.manage', $all, 'style classes have their own permission (visual builder §4.1)');
        self::assertContains('audit.view', $all, 'extension declarations (audit) are persisted too');
        self::assertContains('analytics.read', $all, 'migration-seeded pack permissions are present');

        self::assertSame($all, $this->roleSlugs('superuser'));
        // An administrator runs ONE site: configuring the system, and authority ACROSS workspaces,
        // are withheld.
        $withheld = ['system.config', 'tenancy.access_any', 'tenancy.manage'];
        self::assertSame(array_values(array_diff($all, $withheld)), $this->roleSlugs('administrator'));
        self::assertSame(count($all), $report->granted['superuser']);
    }

    public function testARevokedPermissionStaysRevokedOnTheNextProvision(): void
    {
        // Provision used to grant whatever a role lacked, so an operator's revocation came back on
        // every upgrade.
        $this->freshInstall();
        $this->grants()->apply();
        $this->revoke('administrator', 'content.manage');

        $this->grants()->apply();

        self::assertNotContains('content.manage', $this->roleSlugs('administrator'));
    }

    public function testAPermissionAddedLaterReachesTheInstallRoles(): void
    {
        $this->dropPermission('brandnew.manage');
        $this->freshInstall();
        $this->grants()->apply();
        (new PermissionRepository(null, $this->appContext()))->create([
            'name' => 'Brand new',
            'slug' => 'brandnew.manage',
            'description' => 'A permission a pack added on upgrade',
            'category' => 'Test',
            'is_system' => true,
        ]);

        $report = $this->grants()->apply();

        self::assertContains('brandnew.manage', $this->roleSlugs('superuser'));
        self::assertContains('brandnew.manage', $this->roleSlugs('administrator'));
        self::assertSame(1, $report->granted['superuser']);
        $this->dropPermission('brandnew.manage');
    }

    private function dropPermission(string $slug): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->prepare(
            'DELETE FROM role_permissions WHERE permission_uuid IN (SELECT uuid FROM permissions WHERE slug = ?)'
        )->execute([$slug]);
        $pdo->prepare('DELETE FROM permissions WHERE slug = ?')->execute([$slug]);
    }

    public function testTheFirstProvisionOnAnExistingSiteKeepsItsRevocations(): void
    {
        // An existing site has grants but no record of what provision offered. What exists before
        // the catalog sync is taken as already offered; only what the sync adds is granted.
        $this->freshInstall();
        $this->grants()->apply();
        $this->revoke('administrator', 'content.manage');
        $this->channel()->forget(InstallRoleGrants::LEDGER_KEY);

        $this->grants()->apply();

        self::assertNotContains('content.manage', $this->roleSlugs('administrator'));
    }

    /** @return list<string> */
    private function allSlugs(): array
    {
        $all = array_map(
            static fn ($p): string => $p->getSlug(),
            (new PermissionRepository(null, $this->appContext()))->findAllPermissions(),
        );
        sort($all);
        return $all;
    }

    private function channel(): \Thallo\Contracts\Settings\SystemChannel
    {
        return $this->container()->get(\Thallo\Contracts\Settings\SystemChannel::class);
    }

    /** No record of grants, and the install roles hold nothing: the state web setup starts from. */
    private function freshInstall(): void
    {
        $this->channel()->forget(InstallRoleGrants::LEDGER_KEY);
        $roles = new RoleRepository(null, $this->appContext());
        $rolePerms = new RolePermissionRepository(null, $this->appContext());
        foreach (['superuser', 'administrator'] as $slug) {
            $uuid = (string) $roles->findRoleBySlug($slug)?->getUuid();
            foreach ($rolePerms->getRolePermissions($uuid) as $rp) {
                $rolePerms->revokePermissionFromRole($uuid, (string) $rp->getPermissionUuid());
            }
        }
    }

    private function revoke(string $role, string $slug): void
    {
        $roleUuid = (new RoleRepository(null, $this->appContext()))->findRoleBySlug($role)?->getUuid();
        $permUuid = (new PermissionRepository(null, $this->appContext()))->findPermissionBySlug($slug)?->getUuid();
        self::assertNotNull($roleUuid);
        self::assertNotNull($permUuid);
        (new RolePermissionRepository(null, $this->appContext()))->revokePermissionFromRole($roleUuid, $permUuid);
    }

    public function testActivatesTheRbacProviderWhenBootSkippedIt(): void
    {
        // thallo:provision runs migrations in-process; Aegis decides at BOOT whether to activate
        // its provider (RBAC tables must already exist), so on a fresh install the process that
        // just created the tables has no active provider. The grantor must activate it itself.
        $manager = $this->container()->get('permission.manager');
        $manager->clearProvider();
        self::assertNull($manager->getProvider(), 'precondition: no active provider');
        $this->freshInstall();

        $report = $this->grants()->apply();

        self::assertNotNull($manager->getProvider(), 'the grantor activated the RBAC provider');
        self::assertGreaterThanOrEqual(1, $report->granted['superuser']);
        self::assertContains('content.manage', $this->roleSlugs('superuser'));
    }

    public function testASecondRunGrantsNothingNew(): void
    {
        $this->grants()->apply();
        $again = $this->grants()->apply();

        self::assertSame(['superuser' => 0, 'administrator' => 0], $again->granted);
    }
}
