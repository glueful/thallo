<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Tests\Support\TenantOracleTestCase;
use Thallo\Navigation\MenuRepository;

final class MenuTenantScopeTest extends TenantOracleTestCase
{
    private function repo(): MenuRepository
    {
        return $this->container()->get(MenuRepository::class);
    }

    public function testListMenusIsScopedToTenant(): void
    {
        // DISTINCT slugs per tenant (isolation proof — the narrow unique(slug) stays; see harness pin).
        $this->runAsTenant(self::$tenantAUuid, function (): void {
            $this->repo()->createMenu('menu-a1', 'A one');
            $this->repo()->createMenu('menu-a2', 'A two');
        });
        $this->runAsTenant(self::$tenantBUuid, function (): void {
            $this->repo()->createMenu('menu-b1', 'B one');
        });

        // listMenus() is raw SQL: unscoped it would return ALL tenants' menus. Scoped → own only.
        $aSlugs = $this->runAsTenant(self::$tenantAUuid, fn () => array_column($this->repo()->listMenus(), 'slug'));
        $bSlugs = $this->runAsTenant(self::$tenantBUuid, fn () => array_column($this->repo()->listMenus(), 'slug'));

        sort($aSlugs);
        self::assertSame(['menu-a1', 'menu-a2'], $aSlugs);
        self::assertSame(['menu-b1'], $bSlugs);
    }

    public function testFindMenuIsScopedToTenant(): void
    {
        $this->runAsTenant(self::$tenantAUuid, fn () => $this->repo()->createMenu('only-a', 'Only A'));

        self::assertNotNull($this->runAsTenant(self::$tenantAUuid, fn () => $this->repo()->findMenu('only-a')));
        // Builder read is auto-scoped by the tenancy hook: tenant B cannot see tenant A's menu.
        self::assertNull($this->runAsTenant(self::$tenantBUuid, fn () => $this->repo()->findMenu('only-a')));
    }
}
