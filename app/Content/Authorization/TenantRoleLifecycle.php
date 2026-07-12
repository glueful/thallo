<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleAuthority;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleLock;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;
use Thallo\Contracts\Tenancy\WriteBarrier;

final class TenantRoleLifecycle
{
    private const SLUG_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly CapabilityCatalog $catalog,
        private readonly TenantRoleRepository $roles,
        private readonly TenantRoleOverrideRepository $overrides,
        private readonly MembershipRoleAuthority $authority,
        private readonly MembershipRoleLock $lock,
        private readonly TenancyLifecycleAudit $audit,
        private readonly WriteBarrier $barrier,
    ) {
    }

    public function create(string $tenantUuid, string $slug, string $name, ?string $actorUuid): void
    {
        $slug = strtolower(trim($slug));
        $name = trim($name);
        if (
            preg_match(self::SLUG_PATTERN, $slug) !== 1
            || in_array($slug, $this->catalog->reservedRoles(), true)
            || $name === ''
        ) {
            throw new TenantRoleLifecycleException('Custom role is invalid.', ['role' => 'Invalid role slug or name.']);
        }
        $this->mutate($tenantUuid, $slug, $actorUuid, 'tenant.role_created', function () use (
            $tenantUuid,
            $slug,
            $name,
            $actorUuid,
        ): void {
            if ($this->roles->find($tenantUuid, $slug) !== null) {
                throw new TenantRoleLifecycleException('Role already exists.', ['slug' => 'Role already exists.']);
            }
            $this->connection->table('tenant_roles')->insert([
                'tenant_uuid' => $tenantUuid,
                'slug' => $slug,
                'name' => $name,
                'status' => 'active',
                'created_by' => $actorUuid,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });
    }

    public function rename(string $tenantUuid, string $slug, string $name, ?string $actorUuid): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new TenantRoleLifecycleException('Role name is required.', ['name' => 'Role name is required.']);
        }
        $this->mutate($tenantUuid, $slug, $actorUuid, 'tenant.role_renamed', function () use (
            $tenantUuid,
            $slug,
            $name,
        ): void {
            $this->requireCustomRole($tenantUuid, $slug);
            $this->connection->table('tenant_roles')->where('tenant_uuid', '=', $tenantUuid)
                ->where('slug', '=', $slug)->update(['name' => $name, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        });
    }

    public function disable(string $tenantUuid, string $slug, ?string $actorUuid): void
    {
        $this->setStatus($tenantUuid, $slug, 'disabled', $actorUuid, 'tenant.role_disabled');
    }

    public function enable(string $tenantUuid, string $slug, ?string $actorUuid): void
    {
        $this->setStatus($tenantUuid, $slug, 'active', $actorUuid, 'tenant.role_enabled');
    }

    public function delete(
        string $tenantUuid,
        string $slug,
        ?string $reassignTo,
        ?string $actorUuid,
    ): void {
        $this->barrier->runWritable(fn () => $this->connection->transaction(function () use (
            $tenantUuid,
            $slug,
            $reassignTo,
            $actorUuid,
        ): void {
            $lockRoles = [$slug];
            if ($reassignTo !== null && trim($reassignTo) !== '') {
                $reassignTo = trim($reassignTo);
                $lockRoles[] = $reassignTo;
            } else {
                $reassignTo = null;
            }
            $this->lock->lockMany($this->context, $tenantUuid, $lockRoles);
            $this->requireCustomRole($tenantUuid, $slug);
            $members = $this->connection->table('tenant_memberships')
                ->where('tenant_uuid', '=', $tenantUuid)->where('role', '=', $slug)->count();
            if ($members > 0 && $reassignTo === null) {
                throw new TenantRoleLifecycleException(
                    'Role still has memberships.',
                    ['reassign_to' => 'Choose a replacement role.'],
                );
            }
            if ($reassignTo !== null && !$this->authority->isAssignable($this->context, $tenantUuid, $reassignTo)) {
                throw new TenantRoleLifecycleException(
                    'Replacement role is not assignable.',
                    ['reassign_to' => 'Replacement role is not assignable.'],
                );
            }
            if ($members > 0 && $reassignTo !== null) {
                $this->connection->table('tenant_memberships')->where('tenant_uuid', '=', $tenantUuid)
                    ->where('role', '=', $slug)->update([
                        'role' => $reassignTo,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
            }
            $this->overrides->deleteRoleOverridesInTransaction($tenantUuid, $slug);
            $this->connection->table('tenant_roles')->where('tenant_uuid', '=', $tenantUuid)
                ->where('slug', '=', $slug)->delete();
            $this->overrides->bumpVersionInTransaction($tenantUuid);
            $this->connection->afterCommit(function () use (
                $tenantUuid,
                $slug,
                $reassignTo,
                $members,
                $actorUuid,
            ): void {
                if ($members > 0) {
                    $this->audit->record('tenant.role_memberships_reassigned', $actorUuid, $tenantUuid, [
                        'role_slug' => $slug, 'reassign_to' => $reassignTo, 'members' => $members,
                    ]);
                }
                $this->audit->record('tenant.role_deleted', $actorUuid, $tenantUuid, ['role_slug' => $slug]);
            });
        }));
    }

    private function setStatus(
        string $tenantUuid,
        string $slug,
        string $status,
        ?string $actorUuid,
        string $action,
    ): void {
        $this->mutate($tenantUuid, $slug, $actorUuid, $action, function () use (
            $tenantUuid,
            $slug,
            $status,
        ): void {
            $this->requireCustomRole($tenantUuid, $slug);
            $this->connection->table('tenant_roles')->where('tenant_uuid', '=', $tenantUuid)
                ->where('slug', '=', $slug)->update([
                    'status' => $status,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
        });
    }

    private function mutate(
        string $tenantUuid,
        string $slug,
        ?string $actorUuid,
        string $action,
        callable $operation,
    ): void {
        $this->barrier->runWritable(fn () => $this->connection->transaction(function () use (
            $tenantUuid,
            $slug,
            $actorUuid,
            $action,
            $operation,
        ): void {
            $this->lock->lock($this->context, $tenantUuid, $slug);
            $operation();
            $this->overrides->bumpVersionInTransaction($tenantUuid);
            $this->connection->afterCommit(fn () => $this->audit->record(
                $action,
                $actorUuid,
                $tenantUuid,
                ['role_slug' => $slug],
            ));
        }));
    }

    /** @return array{tenant_uuid:string,slug:string,name:string,status:string} */
    private function requireCustomRole(string $tenantUuid, string $slug): array
    {
        $role = $this->roles->find($tenantUuid, $slug);
        if ($role === null) {
            throw new TenantRoleLifecycleException('Custom role was not found.', ['slug' => 'Role not found.']);
        }
        return $role;
    }
}
