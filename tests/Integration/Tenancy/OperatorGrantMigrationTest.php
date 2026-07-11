<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;

final class OperatorGrantMigrationTest extends AppTestCase
{
    public function testMigrationCreatesAndIdempotentlyGrantsOperatorPermissions(): void
    {
        require_once dirname(__DIR__, 3)
            . '/database/dependent-migrations/013_GrantTenancyOperatorToAdministrator.php';
        $migration = new \GrantTenancyOperatorToAdministrator();
        $schema = $this->connection()->getSchemaBuilder();

        $migration->up($schema);
        $migration->up($schema);

        $permissions = $this->connection()->table('permissions')
            ->whereIn('slug', ['tenancy.manage', 'tenancy.access_any'])
            ->get();
        self::assertCount(2, $permissions);
        $role = $this->connection()->table('roles')->where('slug', '=', 'administrator')->first();
        self::assertNotNull($role);
        $grants = $this->connection()->table('role_permissions')
            ->where('role_uuid', '=', (string) $role['uuid'])
            ->whereIn('permission_uuid', array_column($permissions, 'uuid'))
            ->get();
        self::assertCount(2, $grants);

        $migration->down($schema);
        self::assertCount(0, $this->connection()->table('role_permissions')
            ->where('role_uuid', '=', (string) $role['uuid'])
            ->whereIn('permission_uuid', array_column($permissions, 'uuid'))
            ->get());
        self::assertCount(2, $this->connection()->table('permissions')
            ->whereIn('slug', ['tenancy.manage', 'tenancy.access_any'])
            ->get());

        // Restore production migration state for the process-shared test database.
        $migration->up($schema);
    }
}
