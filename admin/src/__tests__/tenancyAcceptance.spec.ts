import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { NavigationMenuItem } from '@nuxt/ui'
import { shapeTenancyNav } from '@/navigation/shapeTenancyNav'
import { useTenantStore } from '@/stores/tenant'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

const baseNavigation: NavigationMenuItem[] = [
  {
    label: 'Workspaces',
    to: '/workspaces',
    children: [
      { label: 'All workspaces', to: '/workspaces' },
      { label: 'Domains', to: '/workspaces/_selected/domains' },
      { label: 'Members', to: '/workspaces/_selected/members' },
    ],
  },
  { label: 'Settings', children: [{ label: 'Workspaces', to: '/settings/workspaces' }] },
]

const noAccess = {
  manage_platform: false,
  access_any: false,
  manage_members: false,
  manage_domains: false,
}

describe('tenancy administration acceptance', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    authFetch.mockReset()
    localStorage.clear()
  })

  it('keeps tenancy navigation closed until the server grants an effective capability', () => {
    const closedNavigation = shapeTenancyNav(baseNavigation, noAccess, null, true, true)
    expect(closedNavigation.find((item) => item.label === 'Workspaces')).toBeUndefined()
    expect(closedNavigation.find((item) => item.label === 'Settings')?.children).toEqual([])

    const ownerNavigation = shapeTenancyNav(
      baseNavigation,
      { ...noAccess, manage_members: true, manage_domains: true },
      'tenant000001',
      true,
      true,
    )
    const ownerTenants = ownerNavigation.find((item) => item.label === 'Workspaces')
    expect(ownerTenants?.children?.map((item) => item.label)).toEqual(['Domains', 'Members'])
    expect(ownerNavigation.find((item) => item.label === 'Settings')?.children).toEqual([])

    const operatorNavigation = shapeTenancyNav(
      baseNavigation,
      { ...noAccess, manage_platform: true },
      'tenant000001',
      true,
      true,
    )
    expect(operatorNavigation.find((item) => item.label === 'Workspaces')?.children).toHaveLength(1)
    expect(operatorNavigation.find((item) => item.label === 'Settings')?.children).toHaveLength(1)
  })

  it('keeps operator mode request-local and clears it across every tenant boundary', () => {
    const tenant = useTenantStore()

    tenant.setOperatorMode(true)
    expect(localStorage.getItem('thallo_tenant') ?? '').not.toContain('operatorMode')

    tenant.select('tenant000001')
    expect(tenant.operatorMode).toBe(false)
    tenant.setOperatorMode(true)
    tenant.clearSelection()
    expect(tenant.operatorMode).toBe(false)
    tenant.setOperatorMode(true)
    tenant.reset()
    expect(tenant.operatorMode).toBe(false)
  })
})
