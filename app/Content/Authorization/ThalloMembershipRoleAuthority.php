<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleAuthority;

final class ThalloMembershipRoleAuthority implements MembershipRoleAuthority
{
    public function __construct(
        private readonly CapabilityCatalog $catalog,
        private readonly TenantRoleRepository $roles,
    ) {
    }

    public function isAssignable(ApplicationContext $context, string $tenantUuid, string $role): bool
    {
        return in_array($role, $this->catalog->reservedRoles(), true)
            || $this->roles->isActive($tenantUuid, $role);
    }
}
