import { describe, expect, it, vi, beforeEach } from 'vitest'
const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))
import { fetchPublicOrigin, savePublicOrigin } from '@/queries/publicOrigin'

describe('publicOrigin query', () => {
  beforeEach(() => authFetch.mockReset())

  it('fetches status', async () => {
    authFetch.mockResolvedValue({ data: { public_origin: { base_domain: 'a.example' } } })
    const s = await fetchPublicOrigin()
    expect(authFetch).toHaveBeenCalledWith('/v1/admin/tenancy/public-origin')
    expect(s.base_domain).toBe('a.example')
  })

  it('saves via PUT', async () => {
    authFetch.mockResolvedValue({ data: { public_origin: { base_domain: 'a.example' } } })
    await savePublicOrigin({ base_domain: 'a.example', default_hosts: ['a.example'] })
    expect(authFetch).toHaveBeenCalledWith(
      '/v1/admin/tenancy/public-origin',
      expect.objectContaining({ method: 'PUT' }),
    )
  })
})
