import { describe, expect, it } from 'vitest'
import type { NavigationMenuItem } from '@nuxt/ui'
import { shapeTenancyNav } from '@/navigation/shapeTenancyNav'

const base: NavigationMenuItem[] = [
  {
    label: 'Workspaces',
    to: '/workspaces',
    children: [
      { label: 'All workspaces', to: '/workspaces' },
      { label: 'Domains', to: '/workspaces/_selected/domains' },
      { label: 'Members', to: '/workspaces/_selected/members' },
    ],
  },
  {
    label: 'Settings',
    children: [
      { label: 'General', to: '/settings/general' },
      { label: 'Workspaces', to: '/settings/workspaces' },
    ],
  },
]

const none = {
  manage_platform: false,
  access_any: false,
  manage_members: false,
  manage_domains: false,
}

describe('tenancy navigation shaping', () => {
  it('hides all tenancy navigation without access', () => {
    const shaped = shapeTenancyNav(base, none, null, true, true)
    expect(shaped.find((item) => item.label === 'Workspaces')).toBeUndefined()
    expect(shaped.find((item) => item.label === 'Settings')?.children).toHaveLength(1)
  })

  it('hides the Tenants management module while tenancy is off, even for an operator', () => {
    // installed (feature) true, enabled (lifecycle) false: an operator holds tenancy.manage
    // from install, but there is nothing to manage until tenancy is switched on.
    const shaped = shapeTenancyNav(base, { ...none, manage_platform: true }, null, true, false)
    expect(shaped.find((item) => item.label === 'Workspaces')).toBeUndefined()
  })

  it('keeps Settings -> Tenancy reachable while tenancy is off so an operator can enable it', () => {
    const shaped = shapeTenancyNav(base, { ...none, manage_platform: true }, null, true, false)
    const settings = shaped.find((item) => item.label === 'Settings')
    expect(settings?.children?.map((child) => child.to)).toContain('/settings/workspaces')
  })

  it('hides Settings -> Tenancy when the tenancy feature is not installed', () => {
    const shaped = shapeTenancyNav(base, { ...none, manage_platform: true }, null, false, false)
    const settings = shaped.find((item) => item.label === 'Settings')
    expect(settings?.children?.map((child) => child.to)).not.toContain('/settings/workspaces')
  })

  it('gives owners concrete selected-tenant links without all-tenants', () => {
    const shaped = shapeTenancyNav(
      base,
      { ...none, manage_domains: true, manage_members: true },
      'tenant000001',
      true,
      true,
    )
    const tenants = shaped.find((item) => item.label === 'Workspaces')
    expect(tenants?.children?.map((child) => child.to)).toEqual([
      '/workspaces/tenant000001/domains',
      '/workspaces/tenant000001/members',
    ])
    expect(tenants?.to).toBe('/workspaces/tenant000001/domains')
  })

  it('shows platform navigation and never leaks placeholder links', () => {
    const shaped = shapeTenancyNav(
      base,
      { ...none, manage_platform: true, manage_domains: true },
      'tenant000002',
      true,
      true,
    )
    expect(JSON.stringify(shaped)).not.toContain('_selected')
    expect(shaped.find((item) => item.label === 'Settings')?.children).toHaveLength(2)
  })
})
