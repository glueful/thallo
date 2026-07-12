<?php

declare(strict_types=1);

namespace App\Content\Authorization;

final class EffectiveRoleEvaluator
{
    public function __construct(
        private readonly RoleMatrix $baseline,
        private readonly TenantRoleOverrideRepository $overrides,
        private readonly CapabilityCatalog $catalog,
        private readonly ?TenantRoleRepository $roles = null,
    ) {
    }

    /** @return list<string> */
    public function capabilitiesForUncached(string $tenantUuid, string $role): array
    {
        if (!in_array($role, $this->catalog->reservedRoles(), true)) {
            if ($this->roles === null || !$this->roles->isActive($tenantUuid, $role)) {
                return [];
            }
            $effective = [];
            foreach (($this->overrides->overridesFor($tenantUuid)[$role] ?? []) as $capability => $effect) {
                if ($effect === 'grant' && $this->catalog->isGrantable($capability)) {
                    $effective[] = $capability;
                }
            }
            sort($effective);
            return $effective;
        }
        $matrix = $this->baseline->capabilities();
        $effective = array_fill_keys($matrix[$role] ?? [], true);
        foreach (($this->overrides->overridesFor($tenantUuid)[$role] ?? []) as $capability => $effect) {
            if (!$this->catalog->has($capability)) {
                continue;
            }
            if ($effect === 'revoke') {
                unset($effective[$capability]);
            } elseif ($effect === 'grant' && $this->catalog->isGrantable($capability)) {
                $effective[$capability] = true;
            }
        }
        if ($role === 'owner') {
            foreach ($this->catalog->ownerFloor() as $capability) {
                $effective[$capability] = true;
            }
        }
        $result = array_keys($effective);
        sort($result);
        return $result;
    }
}
