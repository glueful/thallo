<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/** Grants platform tenant-management and cross-tenant access to the install administrator. */
final class GrantTenancyOperatorToAdministrator implements MigrationInterface
{
    private const PERMISSIONS = [
        'tenancy.manage' => 'Manage tenants',
        'tenancy.access_any' => 'Access any tenant',
    ];

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();
        $permissions = $this->ensurePermissions();
        $role = $this->db->table('roles')->select(['uuid'])->where('slug', '=', 'administrator')->first();
        if ($role === null) {
            return;
        }
        $this->assign((string) $role['uuid'], $permissions);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();
        $permissions = array_column(
            $this->db->table('permissions')->select(['uuid'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get(),
            'uuid',
        );
        $role = $this->db->table('roles')->select(['uuid'])->where('slug', '=', 'administrator')->first();
        if ($role === null || $permissions === []) {
            return;
        }
        foreach ($permissions as $permissionUuid) {
            $this->db->table('role_permissions')
                ->where('role_uuid', '=', (string) $role['uuid'])
                ->where('permission_uuid', '=', (string) $permissionUuid)
                ->delete();
        }
    }

    public function getDescription(): string
    {
        return 'Grant tenancy.manage/access_any to the administrator role.';
    }

    /** @return array<string,string> */
    private function ensurePermissions(): array
    {
        $bySlug = [];
        foreach (
            $this->db->table('permissions')->select(['uuid', 'slug'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get() as $row
        ) {
            $bySlug[(string) $row['slug']] = (string) $row['uuid'];
        }

        $insert = [];
        foreach (self::PERMISSIONS as $slug => $name) {
            if (isset($bySlug[$slug])) {
                continue;
            }
            $uuid = Utils::generateNanoID(12);
            $bySlug[$slug] = $uuid;
            $insert[] = [
                'uuid' => $uuid,
                'slug' => $slug,
                'name' => $name,
                'category' => 'tenancy',
                'description' => $name,
                'is_system' => true,
            ];
        }
        if ($insert !== []) {
            $this->db->table('permissions')->insertBatch($insert);
        }
        return $bySlug;
    }

    /** @param array<string,string> $permissions */
    private function assign(string $roleUuid, array $permissions): void
    {
        $existing = [];
        foreach (
            $this->db->table('role_permissions')->select(['permission_uuid'])
                ->where('role_uuid', '=', $roleUuid)->get() as $row
        ) {
            $existing[(string) $row['permission_uuid']] = true;
        }

        $insert = [];
        foreach ($permissions as $permissionUuid) {
            if (isset($existing[$permissionUuid])) {
                continue;
            }
            $insert[] = [
                'uuid' => Utils::generateNanoID(12),
                'role_uuid' => $roleUuid,
                'permission_uuid' => $permissionUuid,
            ];
        }
        if ($insert !== []) {
            $this->db->table('role_permissions')->insertBatch($insert);
        }
    }
}
