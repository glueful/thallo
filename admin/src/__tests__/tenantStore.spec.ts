import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useQueryCache } from '@pinia/colada'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { useTenantStore } from '@/stores/tenant'

describe('tenant store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    authFetch.mockReset()
    localStorage.clear()
  })

  it('loads tenants and selects the first when no valid selection exists', async () => {
    authFetch.mockResolvedValue({
      data: {
        tenants: [
          { uuid: 'tenant000001', slug: 'alpha', name: 'Alpha', status: 'active' },
          { uuid: 'tenant000002', slug: 'beta', name: 'Beta', status: 'active' },
        ],
      },
    })
    const store = useTenantStore()
    store.selectedUuid = 'stale0000000'

    await store.ensureLoaded()

    expect(store.selectedUuid).toBe('tenant000001')
    expect(store.tenants).toHaveLength(2)
  })

  it('preserves a valid selected tenant and can clear it after revocation', async () => {
    authFetch.mockResolvedValue({
      data: {
        tenants: [{ uuid: 'tenant000002', slug: 'beta', name: 'Beta', status: 'active' }],
      },
    })
    const store = useTenantStore()
    store.select('tenant000002')
    await store.ensureLoaded()
    expect(store.selectedUuid).toBe('tenant000002')

    store.clearSelection()
    expect(store.selectedUuid).toBeNull()
  })

  it('invalidates the query cache on an actual switch so views refetch for the new workspace', () => {
    const invalidate = vi.spyOn(useQueryCache(), 'invalidateQueries').mockResolvedValue(undefined)
    const store = useTenantStore()
    store.select('tenant000001')

    // First selection from null → a real change → refetch everything for the new workspace.
    expect(invalidate).toHaveBeenCalledTimes(1)

    // Re-selecting the SAME workspace is a no-op — no needless cache-wide refetch.
    store.select('tenant000001')
    expect(invalidate).toHaveBeenCalledTimes(1)

    // Switching to a different workspace invalidates again.
    store.select('tenant000002')
    expect(invalidate).toHaveBeenCalledTimes(2)
  })

  it('keeps operator mode in memory and resets it at every tenant boundary', () => {
    const store = useTenantStore()
    expect(store.operatorMode).toBe(false)

    store.setOperatorMode(true)
    store.select('tenant000001')
    expect(store.operatorMode).toBe(false)

    store.setOperatorMode(true)
    store.clearSelection()
    expect(store.operatorMode).toBe(false)

    store.setOperatorMode(true)
    store.reset()
    expect(store.operatorMode).toBe(false)
    expect(localStorage.getItem('thallo_tenant') ?? '').not.toContain('operatorMode')
  })
})
