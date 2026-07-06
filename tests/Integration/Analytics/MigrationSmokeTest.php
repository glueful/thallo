<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class MigrationSmokeTest extends AppTestCase
{
    public function testAnalyticsTablesExist(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        self::assertTrue($schema->hasTable('analytics_facts'));
        self::assertTrue($schema->hasTable('analytics_daily'));
        self::assertTrue($schema->hasTable('analytics_active_actors'));
    }

    public function testAnalyticsReadPermissionSeeded(): void
    {
        $row = $this->connection()->table('permissions')->where('slug', 'analytics.read')->first();
        self::assertNotNull($row);
    }

    public function testAnalyticsReadGrantedToAdministrator(): void
    {
        $perm = $this->connection()->table('permissions')->where('slug', 'analytics.read')->first();
        $role = $this->connection()->table('roles')->where('slug', 'administrator')->first();
        self::assertNotNull($perm);
        self::assertNotNull($role);
        $grant = $this->connection()->table('role_permissions')
            ->where('role_uuid', $role['uuid'])->where('permission_uuid', $perm['uuid'])->first();
        self::assertNotNull($grant, 'administrator must be granted analytics.read (App migration 004)');
    }
}
