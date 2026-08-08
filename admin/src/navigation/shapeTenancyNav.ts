import type { NavigationMenuItem } from '@nuxt/ui'
import type { TenancyAccess } from '@/queries/tenancyAccess'

export function inferTenancyEnabledForNavigation(
  reportedEnabled: boolean,
  selectedUuid: string | null,
  access: TenancyAccess,
): boolean {
  return (
    reportedEnabled ||
    (selectedUuid !== null &&
      (access.manage_domains || access.manage_members || access.manage_roles === true))
  )
}

export function shapeTenancyNav(
  items: NavigationMenuItem[],
  access: TenancyAccess,
  selectedUuid: string | null,
  // Two distinct signals, deliberately not conflated: `tenancyInstalled` = the tenancy pack is
  // present + switchboard-enabled (capability registry); `tenancyEnabled` = tenancy has been
  // switched on through the enablement lifecycle. The Settings -> Tenancy page (where you enable
  // it) needs only the former; the Tenants management module needs the latter.
  tenancyInstalled: boolean,
  tenancyEnabled: boolean,
): NavigationMenuItem[] {
  const anyTenantAccess =
    access.manage_platform || access.manage_domains || access.manage_members || access.manage_roles
  const domainsPath = selectedUuid ? `/workspaces/${selectedUuid}/domains` : null
  const membersPath = selectedUuid ? `/workspaces/${selectedUuid}/members` : null
  const rolesPath = selectedUuid ? `/workspaces/${selectedUuid}/roles` : null
  const shaped: NavigationMenuItem[] = []

  for (const item of items) {
    if (item.label === 'Workspaces') {
      if (!tenancyEnabled || !anyTenantAccess) continue
      const children = (item.children ?? []).flatMap((child) => {
        if (child.to === '/workspaces') return access.manage_platform ? [child] : []
        if (child.to === '/workspaces/_selected/domains') {
          return access.manage_domains && domainsPath ? [{ ...child, to: domainsPath }] : []
        }
        if (child.to === '/workspaces/_selected/members') {
          return access.manage_members && membersPath ? [{ ...child, to: membersPath }] : []
        }
        if (child.to === '/workspaces/_selected/roles') {
          return access.manage_roles && rolesPath ? [{ ...child, to: rolesPath }] : []
        }
        return []
      })
      const firstTarget = children[0]?.to
      if (!access.manage_platform && !firstTarget) continue
      // Expand-only parent: strip any `to` so clicking toggles the group instead of navigating.
      // The destinations still live on the children (e.g. `/workspaces` via "All workspaces").
      shaped.push({ ...item, to: undefined, children })
      continue
    }

    // Task 19 (spec §5.3): the Subscriptions group's three children answer to TWO disjoint
    // authorities -- platform Plans/Billing gated by `manage_platform` (the same platform
    // authority the Workspaces group above uses), Workspace billing gated by `manage_billing`
    // (Task 14's per-workspace delegable flag). A static module capability
    // (`thallo.subscriptions`, checked upstream by `visibleNav`) only proves the PACK is
    // installed -- it cannot substitute for either loaded access flag, so an install with the
    // pack enabled but neither authority held drops the whole group, exactly like Workspaces
    // above.
    if (item.label === 'Subscriptions') {
      const children = (item.children ?? []).flatMap((child) => {
        if (child.to === '/subscriptions/plans' || child.to === '/subscriptions/billing') {
          return access.manage_platform ? [child] : []
        }
        if (child.to === '/billing') {
          return access.manage_billing ? [child] : []
        }
        return []
      })
      if (children.length === 0) continue
      shaped.push({ ...item, children })
      continue
    }

    if (item.label === 'Settings') {
      shaped.push({
        ...item,
        children: (item.children ?? []).filter((child) => {
          if (child.to === '/settings/workspaces') {
            return tenancyInstalled && access.manage_platform
          }
          // Platform Payments settings (platform-payments-settings spec, Task 7): gated on the
          // SAME authority flag as Workspaces (`manage_platform` = holding `tenancy.manage`),
          // but — unlike Workspaces — independent of `tenancyInstalled`: payment settlement is
          // an app-owned platform concern, not a multi-workspace tenancy feature, so it stays
          // reachable even on an install that never turns tenancy on.
          if (child.to === '/settings/payments') {
            return access.manage_platform
          }
          if (child.to === '/settings/signup') {
            return !tenancyEnabled
          }
          return true
        }),
      })
      continue
    }

    shaped.push(item)
  }

  return shaped
}
