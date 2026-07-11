import { describe, expect, it } from 'vitest'
import type { NavigationMenuItem } from '@nuxt/ui'
import { shapeTenancyNav } from '@/navigation/shapeTenancyNav'

const base: NavigationMenuItem[] = [
  {
    label: 'Tenants',
    to: '/tenants',
    children: [
      { label: 'All tenants', to: '/tenants' },
      { label: 'Domains', to: '/tenants/_selected/domains' },
      { label: 'Members', to: '/tenants/_selected/members' },
    ],
  },
  {
    label: 'Settings',
    children: [
      { label: 'General', to: '/settings/general' },
      { label: 'Tenancy', to: '/settings/tenancy' },
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
    const shaped = shapeTenancyNav(base, none, null, true)
    expect(shaped.find((item) => item.label === 'Tenants')).toBeUndefined()
    expect(shaped.find((item) => item.label === 'Settings')?.children).toHaveLength(1)
  })

  it('gives owners concrete selected-tenant links without all-tenants', () => {
    const shaped = shapeTenancyNav(
      base,
      { ...none, manage_domains: true, manage_members: true },
      'tenant000001',
      true,
    )
    const tenants = shaped.find((item) => item.label === 'Tenants')
    expect(tenants?.children?.map((child) => child.to)).toEqual([
      '/tenants/tenant000001/domains',
      '/tenants/tenant000001/members',
    ])
    expect(tenants?.to).toBe('/tenants/tenant000001/domains')
  })

  it('shows platform navigation and never leaks placeholder links', () => {
    const shaped = shapeTenancyNav(
      base,
      { ...none, manage_platform: true, manage_domains: true },
      'tenant000002',
      true,
    )
    expect(JSON.stringify(shaped)).not.toContain('_selected')
    expect(shaped.find((item) => item.label === 'Settings')?.children).toHaveLength(2)
  })
})
