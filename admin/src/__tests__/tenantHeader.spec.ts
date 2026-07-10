import { beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
  session: { accessToken: 'token' as string | null },
  tenant: {
    selectedUuid: 'tenant000001' as string | null,
    clearSelection: vi.fn(),
    ensureLoaded: vi.fn().mockResolvedValue(undefined),
  },
}))
vi.mock('@/stores/session', () => ({ useSessionStore: () => stores.session }))
vi.mock('@/stores/tenant', () => ({ useTenantStore: () => stores.tenant }))
vi.mock('@/api/errors', () => ({ responseError: vi.fn() }))

import { authFetch } from '@/api/authFetch'

describe('tenant request header', () => {
  beforeEach(() => {
    stores.tenant.selectedUuid = 'tenant000001'
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: {} }), { status: 200 })),
    )
  })

  it('attaches X-Tenant-Id when a tenant is selected', async () => {
    await authFetch('/v1/admin/content-types')
    const init = vi.mocked(fetch).mock.calls[0]?.[1]
    const headers = (init?.headers ?? {}) as Record<string, string>
    expect(headers['X-Tenant-Id']).toBe('tenant000001')
  })

  it('omits X-Tenant-Id when no tenant is selected', async () => {
    stores.tenant.selectedUuid = null
    await authFetch('/v1/admin/content-types')
    const init = vi.mocked(fetch).mock.calls[0]?.[1]
    const headers = (init?.headers ?? {}) as Record<string, string>
    expect(headers['X-Tenant-Id']).toBeUndefined()
  })
})
