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
  manage_billing: true,
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
      manage_roles: false,
      manage_billing: false,
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
      manage_billing: false,
    })
    await old

    expect(store.access).toEqual(granted)
  })

  // Regression (the Workspaces-group sidebar flash): every workspace-page mount revalidates
  // via refresh(); blanking access during that in-flight window read as "no tenant access"
  // and dropped/re-added the whole Workspaces nav group. refresh() must keep the previous
  // flags until the new answer lands — fail-closed blanking belongs to reset(), which every
  // identity-change caller already invokes first.
  it('refresh keeps the previous flags while the refetch is in flight', async () => {
    fetchTenancyAccess.mockResolvedValueOnce(granted)
    const store = useTenancyAccessStore()
    await store.ensureLoaded()
    expect(store.access.manage_members).toBe(true)

    let resolveNext!: (value: typeof granted) => void
    fetchTenancyAccess.mockImplementationOnce(
      () => new Promise<typeof granted>((resolve) => (resolveNext = resolve)),
    )
    const inflight = store.refresh()

    // Mid-flight: nothing blanked — the sidebar keeps its Workspaces group.
    expect(store.access.manage_members).toBe(true)
    expect(store.access.manage_platform).toBe(true)

    resolveNext({ ...granted, manage_members: false })
    await inflight
    expect(store.access.manage_members).toBe(false) // new answer applied
    expect(store.access.manage_platform).toBe(true)
  })

  it('reset() still blanks immediately (fail-closed identity change)', async () => {
    fetchTenancyAccess.mockResolvedValueOnce(granted)
    const store = useTenancyAccessStore()
    await store.ensureLoaded()

    store.reset()

    expect(store.access.manage_platform).toBe(false)
    expect(store.access.manage_members).toBe(false)
    expect(store.loaded).toBe(false)
  })
})
