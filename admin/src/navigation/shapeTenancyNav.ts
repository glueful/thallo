import type { NavigationMenuItem } from '@nuxt/ui'
import type { TenancyAccess } from '@/queries/tenancyAccess'

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
  const anyTenantAccess = access.manage_platform || access.manage_domains || access.manage_members
  const domainsPath = selectedUuid ? `/workspaces/${selectedUuid}/domains` : null
  const membersPath = selectedUuid ? `/workspaces/${selectedUuid}/members` : null
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
        return []
      })
      const firstTarget = children[0]?.to
      if (!access.manage_platform && !firstTarget) continue
      shaped.push({ ...item, to: access.manage_platform ? '/workspaces' : firstTarget, children })
      continue
    }

    if (item.label === 'Settings') {
      shaped.push({
        ...item,
        children: (item.children ?? []).filter(
          (child) =>
            child.to !== '/settings/workspaces' || (tenancyInstalled && access.manage_platform),
        ),
      })
      continue
    }

    shaped.push(item)
  }

  return shaped
}
