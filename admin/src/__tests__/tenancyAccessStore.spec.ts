import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const { fetchTenancyAccess } = vi.hoisted(() => ({ fetchTenancyAccess: vi.fn() }))
vi.mock('@/queries/tenancyAccess', () => ({ fetchTenancyAccess }))

import { useTenancyAccessStore } from '@/stores/tenancyAccess'

const granted = {
  manage_platform: true,
  access_any: true,
  manage_members: true,
  manage_domains: true,
}

describe('tenancy access store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchTenancyAccess.mockReset()
  })

  it('loads access and fails closed on refresh error', async () => {
    fetchTenancyAccess.mockResolvedValueOnce(granted)
    const store = useTenancyAccessStore()
    await store.ensureLoaded()
    expect(store.access.manage_platform).toBe(true)

    fetchTenancyAccess.mockRejectedValueOnce(new Error('network'))
    await store.refresh()
    expect(store.access).toEqual({
      manage_platform: false,
      access_any: false,
      manage_members: false,
      manage_domains: false,
    })
  })

  it('discards a delayed response from an older tenant generation', async () => {
    let resolveOld!: (value: typeof granted) => void
    fetchTenancyAccess.mockImplementationOnce(
      () => new Promise<typeof granted>((resolve) => (resolveOld = resolve)),
    )
    const store = useTenancyAccessStore()
    const old = store.refresh()

    fetchTenancyAccess.mockResolvedValueOnce(granted)
    await store.refresh()
    resolveOld({
      manage_platform: false,
      access_any: false,
      manage_members: false,
      manage_domains: false,
    })
    await old

    expect(store.access).toEqual(granted)
  })
})
