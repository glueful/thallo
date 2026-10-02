<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Glueful\Helpers\Utils;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\RestoresPermissionRows;

/**
 * Migration 016 repairs the install roles of a site whose first provision recorded every
 * migration-seeded permission as offered and granted none (Aegis had seeded the roles, which read
 * as an existing site): superuser is given everything it lacks, and administrator the commerce
 * permissions and templates.manage. It withholds what administrator is meant to lack, touches no
 * other role, and is safe to run twice.
 */
final class InstallRolesRepairMigrationTest extends AppTestCase
{
    use RestoresPermissionRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPermissionRows();
    }

    protected function tearDown(): void
    {
        $this->restorePermissionRows();
        parent::tearDown();
    }

    /** @return list<string> */
    private function permissionsFor(string $roleSlug): array
    {
        $stmt = $this->connection()->getPDO()->prepare(
            'SELECT p.slug FROM roles r
             JOIN role_permissions rp ON rp.role_uuid = r.uuid
             JOIN permissions p ON p.uuid = rp.permission_uuid
             WHERE r.slug = :slug ORDER BY p.slug'
        );
        $stmt->execute(['slug' => $roleSlug]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function allPermissions(): array
    {
        $slugs = $this->connection()->getPDO()->query('SELECT slug FROM permissions ORDER BY slug');
        return array_values(array_map('strval', $slugs->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function uuidOf(string $table, string $slug): string
    {
        $row = $this->connection()->table($table)->where('slug', '=', $slug)->first();
        self::assertIsArray($row, "{$table} {$slug}");
        return (string) $row['uuid'];
    }

    public function testItGivesTheInstallRolesWhatTheFirstProvisionMissed(): void
    {
        // The state the bad first run left: both roles hold one permission and nothing else.
        $pdo = $this->connection()->getPDO();
        $pdo->exec(
            "DELETE FROM role_permissions WHERE role_uuid IN "
            . "(SELECT uuid FROM roles WHERE slug IN ('superuser', 'administrator'))"
        );
        foreach (['superuser', 'administrator'] as $role) {
            $this->connection()->table('role_permissions')->insert([
                'uuid' => Utils::generateNanoID(),
                'role_uuid' => $this->uuidOf('roles', $role),
                'permission_uuid' => $this->uuidOf('permissions', 'audit.view'),
            ]);
        }
        $manager = $this->permissionsFor('workspace_manager');

        require_once dirname(__DIR__, 3)
            . '/core/database/dependent-migrations/016_GrantInstallRolesWhatTheFirstProvisionMissed.php';
        $migration = new \GrantInstallRolesWhatTheFirstProvisionMissed();
        $schema = $this->connection()->getSchemaBuilder();
        $migration->up($schema);
        $migration->up($schema);

        self::assertSame($this->allPermissions(), $this->permissionsFor('superuser'), 'superuser holds everything');
        $administrator = $this->permissionsFor('administrator');
        foreach (['commerce.view', 'commerce.manage', 'templates.manage', 'audit.view'] as $slug) {
            self::assertContains($slug, $administrator);
        }
        foreach (['system.config', 'tenancy.access_any', 'tenancy.manage'] as $slug) {
            self::assertNotContains($slug, $administrator, "{$slug} stays withheld from administrator");
        }
        self::assertCount(4, $administrator, 'administrator gets only the three, no more');
        self::assertSame($manager, $this->permissionsFor('workspace_manager'), 'other roles are untouched');
    }
}
