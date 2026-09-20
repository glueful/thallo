<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Authority;

use Glueful\Helpers\Utils;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Migration 015 takes cross-workspace authority back off the `administrator` role on an install
 * where a provision had re-granted it (InstallRoleGrants granted the role everything it did not
 * hold; the two tenancy permissions are withheld there now). It touches that role and those two
 * permissions only, and is safe to run twice.
 */
final class AdministratorCrossWorkspaceRevokeMigrationTest extends AppTestCase
{
    private const PERMISSIONS = ['tenancy.access_any', 'tenancy.manage'];

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

    private function uuidOf(string $table, string $slug): string
    {
        $row = $this->connection()->table($table)->where('slug', '=', $slug)->first();
        self::assertIsArray($row, "{$table} {$slug}");
        return (string) $row['uuid'];
    }

    public function testItRevokesTheRegrantedPermissionsFromAdministratorsAndNobodyElse(): void
    {
        // The state a provision left behind: the administrator role holding both again.
        $administrator = $this->uuidOf('roles', 'administrator');
        foreach (self::PERMISSIONS as $slug) {
            if (!in_array($slug, $this->permissionsFor('administrator'), true)) {
                $this->connection()->table('role_permissions')->insert([
                    'uuid' => Utils::generateNanoID(),
                    'role_uuid' => $administrator,
                    'permission_uuid' => $this->uuidOf('permissions', $slug),
                ]);
            }
        }
        $before = $this->permissionsFor('administrator');
        self::assertContains('tenancy.access_any', $before);
        $superuser = $this->permissionsFor('superuser');
        $manager = $this->permissionsFor('workspace_manager');

        require_once dirname(__DIR__, 3)
            . '/core/database/dependent-migrations/015_RevokeCrossWorkspaceAuthorityFromAdministrator.php';
        $migration = new \RevokeCrossWorkspaceAuthorityFromAdministrator();
        $schema = $this->connection()->getSchemaBuilder();
        $migration->up($schema);
        $migration->up($schema);

        $after = $this->permissionsFor('administrator');
        sort($after);
        $expected = array_values(array_diff($before, self::PERMISSIONS));
        sort($expected);
        self::assertSame($expected, $after, 'those two, and nothing else, left the role');
        self::assertEqualsCanonicalizing($superuser, $this->permissionsFor('superuser'));
        self::assertEqualsCanonicalizing($manager, $this->permissionsFor('workspace_manager'));
        self::assertContains('tenancy.access_any', $this->permissionsFor('workspace_manager'));
    }
}
