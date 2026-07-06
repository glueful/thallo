<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;

/**
 * Verifies the pack's 003 migration seeds the collections.* permissions and grants them to the
 * existing Aegis `administrator` role (the migration runs after Aegis seeds the role ladder).
 */
final class CollectionsPermissionsSeededTest extends AppTestCase
{
    private const SLUGS = ['collections.manage', 'collections.schema.manage', 'collections.data.manage'];

    public function testCollectionsPermissionsAreSeededAsSystemPermissions(): void
    {
        $perms = $this->connection()->table('permissions')
            ->select(['slug', 'category', 'is_system'])
            ->whereIn('slug', self::SLUGS)
            ->get();

        self::assertCount(3, $perms, 'All three collections.* permissions must be seeded');
        foreach ($perms as $p) {
            self::assertSame('collections', $p['category']);
            self::assertTrue((bool) $p['is_system'], "{$p['slug']} must be a system permission");
        }
    }

    public function testCollectionsPermissionsAreGrantedToAdministrator(): void
    {
        $db = $this->connection();

        $adminUuid = array_column(
            $db->table('roles')->select(['uuid'])->where('slug', 'administrator')->get(),
            'uuid',
        )[0] ?? null;
        self::assertNotNull($adminUuid, 'administrator role must exist (seeded by Aegis)');

        $permUuids = array_column(
            $db->table('permissions')->select(['uuid'])->whereIn('slug', self::SLUGS)->get(),
            'uuid',
        );

        $granted = $db->table('role_permissions')
            ->select(['permission_uuid'])
            ->where('role_uuid', $adminUuid)
            ->whereIn('permission_uuid', $permUuids)
            ->get();

        self::assertCount(3, $granted, 'All three collections.* permissions must be granted to administrator');
    }
}
