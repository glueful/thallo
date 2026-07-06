<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Tests\Support\AppTestCase;

final class NavigationMigrationSmokeTest extends AppTestCase
{
    public function testTablesExist(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach (['navigation_menus', 'navigation_items'] as $table) {
            self::assertNotNull(
                $pdo->query("SELECT to_regclass('public.{$table}')")->fetchColumn(),
                "{$table} exists after migrations",
            );
        }
    }

    public function testAdministratorHoldsNavigationManage(): void
    {
        $granted = $this->connection()->getPDO()->query(
            "SELECT COUNT(*) FROM role_permissions rp
               JOIN roles r ON r.uuid = rp.role_uuid
               JOIN permissions p ON p.uuid = rp.permission_uuid
              WHERE r.slug = 'administrator' AND p.slug = 'navigation.manage'"
        )->fetchColumn();
        self::assertSame(1, (int) $granted, 'administrator holds navigation.manage');
    }
}
