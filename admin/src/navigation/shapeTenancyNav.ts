import type { NavigationMenuItem } from '@nuxt/ui'
import type { TenancyAccess } from '@/queries/tenancyAccess'

export function shapeTenancyNav(
  items: NavigationMenuItem[],
  access: TenancyAccess,
  selectedUuid: string | null,
  tenancyEnabled: boolean,
): NavigationMenuItem[] {
  const anyTenantAccess = access.manage_platform || access.manage_domains || access.manage_members
  const domainsPath = selectedUuid ? `/tenants/${selectedUuid}/domains` : null
  const membersPath = selectedUuid ? `/tenants/${selectedUuid}/members` : null
  const shaped: NavigationMenuItem[] = []

  for (const item of items) {
    if (item.label === 'Tenants') {
      if (!tenancyEnabled || !anyTenantAccess) continue
      const children = (item.children ?? []).flatMap((child) => {
        if (child.to === '/tenants') return access.manage_platform ? [child] : []
        if (child.to === '/tenants/_selected/domains') {
          return access.manage_domains && domainsPath ? [{ ...child, to: domainsPath }] : []
        }
        if (child.to === '/tenants/_selected/members') {
          return access.manage_members && membersPath ? [{ ...child, to: membersPath }] : []
        }
        return []
      })
      const firstTarget = children[0]?.to
      if (!access.manage_platform && !firstTarget) continue
      shaped.push({ ...item, to: access.manage_platform ? '/tenants' : firstTarget, children })
      continue
    }

    if (item.label === 'Settings') {
      shaped.push({
        ...item,
        children: (item.children ?? []).filter(
          (child) => child.to !== '/settings/tenancy' || (tenancyEnabled && access.manage_platform),
        ),
      })
      continue
    }

    shaped.push(item)
  }

  return shaped
}
