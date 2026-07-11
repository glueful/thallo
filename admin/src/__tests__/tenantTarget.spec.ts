import { beforeEach, describe, expect, it, vi } from 'vitest'

const { router, tenant, access } = vi.hoisted(() => ({
  router: { push: vi.fn().mockResolvedValue(undefined) },
  tenant: {
    selectedUuid: 'tenant000001' as string | null,
    tenants: [{ uuid: 'tenant000001' }, { uuid: 'tenant000002' }],
    ensureLoaded: vi.fn().mockResolvedValue(undefined),
    select: vi.fn<(uuid: string) => void>(),
  },
  access: { refresh: vi.fn().mockResolvedValue(undefined) },
}))
tenant.select.mockImplementation((uuid) => (tenant.selectedUuid = uuid))

vi.mock('vue-router', () => ({ useRouter: () => router }))
vi.mock('@/stores/tenant', () => ({ useTenantStore: () => tenant }))
vi.mock('@/stores/tenancyAccess', () => ({ useTenancyAccessStore: () => access }))

import { useTenantTarget } from '@/composables/useTenantTarget'

describe('tenant target synchronization', () => {
  beforeEach(() => {
    tenant.selectedUuid = 'tenant000001'
    tenant.select.mockClear()
    access.refresh.mockClear()
    router.push.mockClear()
  })

  it('selects and refreshes access before navigation', async () => {
    const order: string[] = []
    tenant.select.mockImplementation((uuid) => {
      order.push('select')
      tenant.selectedUuid = uuid
    })
    access.refresh.mockImplementation(async () => {
      order.push('refresh')
    })
    router.push.mockImplementation(async () => {
      order.push('navigate')
    })

    await useTenantTarget().selectThenNavigate('tenant000002', 'domains')
    expect(order).toEqual(['select', 'refresh', 'navigate'])
  })

  it('refuses unavailable route targets without querying or navigating', async () => {
    const target = useTenantTarget()
    expect(await target.ensureTargetSelected('tenant000009')).toBe(false)
    await target.selectThenNavigate('tenant000009', 'members')
    expect(access.refresh).not.toHaveBeenCalled()
    expect(router.push).not.toHaveBeenCalled()
  })
})
