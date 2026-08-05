import { describe, expect, it } from 'vitest'
import type { NavigationMenuItem } from '@nuxt/ui'
import { inferTenancyEnabledForNavigation, shapeTenancyNav } from '@/navigation/shapeTenancyNav'

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
      { label: 'Signup', to: '/settings/signup' },
      { label: 'Workspaces', to: '/settings/workspaces' },
    ],
  },
  {
    label: 'Subscriptions',
    icon: 'i-lucide-credit-card',
    defaultOpen: false,
    children: [
      { label: 'Plans', to: '/subscriptions/plans' },
      { label: 'Billing', to: '/subscriptions/billing' },
      { label: 'Workspace billing', to: '/billing' },
    ],
  },
]

const none = {
  manage_platform: false,
  access_any: false,
  manage_members: false,
  manage_domains: false,
  manage_billing: false,
}

describe('tenancy navigation shaping', () => {
  it('does not mistake single-store member authority for active tenancy', () => {
    expect(inferTenancyEnabledForNavigation(false, null, { ...none, manage_members: true })).toBe(
      false,
    )
    expect(
      inferTenancyEnabledForNavigation(false, 'tenant000001', { ...none, manage_members: true }),
    ).toBe(true)
    expect(inferTenancyEnabledForNavigation(true, null, none)).toBe(true)
  })

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

  it('shows single-store signup while tenancy is off', () => {
    const off = shapeTenancyNav(base, none, null, true, false)
    expect(
      off.find((item) => item.label === 'Settings')?.children?.map((child) => child.to),
    ).toContain('/settings/signup')

    const on = shapeTenancyNav(base, { ...none, manage_members: true }, 'tenant000001', true, true)
    expect(
      on.find((item) => item.label === 'Settings')?.children?.map((child) => child.to),
    ).not.toContain('/settings/signup')
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
    // Expand-only parent: no navigational `to`, clicking toggles the group.
    expect(tenants?.to).toBeUndefined()
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
    // Even for a platform operator the parent is expand-only; the list lives on "All workspaces".
    expect(shaped.find((item) => item.label === 'Workspaces')?.to).toBeUndefined()
    expect(shaped.find((item) => item.label === 'Settings')?.children).toHaveLength(2)
  })

  // Task 19 (spec §5.3): the Subscriptions group's three children are gated by TWO distinct
  // authorities -- platform Plans/Billing by `manage_platform`, workspace Billing by
  // `manage_billing` -- and the group itself disappears entirely when neither authority is held.
  describe('Subscriptions group authority matrix', () => {
    it('owner/delegate (manage_billing only) sees ONLY workspace billing', () => {
      const shaped = shapeTenancyNav(base, { ...none, manage_billing: true }, null, true, true)
      const group = shaped.find((item) => item.label === 'Subscriptions')
      expect(group?.children?.map((c) => c.to)).toEqual(['/billing'])
    })

    it('platform-only operator (manage_platform only) sees ONLY the platform Plans/Billing pages', () => {
      const shaped = shapeTenancyNav(base, { ...none, manage_platform: true }, null, true, true)
      const group = shaped.find((item) => item.label === 'Subscriptions')
      expect(group?.children?.map((c) => c.to)).toEqual(['/subscriptions/plans', '/subscriptions/billing'])
    })

    it('neither authority: the Subscriptions group is dropped entirely', () => {
      const shaped = shapeTenancyNav(base, none, null, true, true)
      expect(shaped.find((item) => item.label === 'Subscriptions')).toBeUndefined()
    })

    it('dual authority (both manage_platform and manage_billing) sees all three children', () => {
      const shaped = shapeTenancyNav(
        base,
        { ...none, manage_platform: true, manage_billing: true },
        null,
        true,
        true,
      )
      const group = shaped.find((item) => item.label === 'Subscriptions')
      expect(group?.children?.map((c) => c.to)).toEqual([
        '/subscriptions/plans',
        '/subscriptions/billing',
        '/billing',
      ])
    })
  })
})
