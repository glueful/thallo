<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Tests\Support\AppTestCase;

final class AuthorityRolesMigrationTest extends AppTestCase
{
    /** @return list<string> */
    private function permissionsFor(string $roleSlug): array
    {
        $stmt = $this->connection()->getPDO()->prepare(
            'SELECT p.slug FROM roles r
             JOIN role_permissions rp ON rp.role_uuid = r.uuid
             JOIN permissions p ON p.uuid = rp.permission_uuid
             WHERE r.slug = :slug'
        );
        $stmt->execute(['slug' => $roleSlug]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testCanonicalAuthorityRoleShape(): void
    {
        $role = $this->connection()->table('roles')->where('slug', '=', 'workspace_manager')->first();
        self::assertIsArray($role);
        self::assertSame(90, (int) $role['level']);
        self::assertTrue((bool) $role['is_system']);

        $workspace = $this->permissionsFor('workspace_manager');
        sort($workspace);
        self::assertSame(['tenancy.access_any', 'tenancy.manage'], $workspace);
        self::assertContains('tenancy.access_any', $this->permissionsFor('superuser'));
        self::assertContains('tenancy.manage', $this->permissionsFor('superuser'));
        self::assertNotContains('tenancy.access_any', $this->permissionsFor('administrator'));
        self::assertNotContains('tenancy.manage', $this->permissionsFor('administrator'));
    }

    public function testUpIsIdempotentAndDownRestoresPriorShape(): void
    {
        require_once dirname(__DIR__, 3)
            . '/database/dependent-migrations/013_CreateTenancyAuthorityRoles.php';
        $migration = new \CreateTenancyAuthorityRoles();
        $schema = $this->connection()->getSchemaBuilder();
        try {
            $migration->up($schema);
            $migration->up($schema);
            self::assertSame(
                1,
                $this->connection()->table('roles')->where('slug', '=', 'workspace_manager')->count(),
            );

            $migration->down($schema);
            self::assertNull($this->connection()->table('roles')->where('slug', '=', 'workspace_manager')->first());
            self::assertContains('tenancy.access_any', $this->permissionsFor('administrator'));
            self::assertNotContains('tenancy.access_any', $this->permissionsFor('superuser'));
        } finally {
            // Migrations construct their own Connection, so restore explicitly rather than assuming
            // participation in the test connection's transaction.
            $migration->up($schema);
        }
    }
}
