import { beforeEach, describe, expect, it, vi } from 'vitest'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { fetchEnablementStatus, confirmEnablement } from '@/queries/tenancyEnablement'
import { activateResolution } from '@/queries/tenancyResolution'
import { createTenant, repairTenantSeed } from '@/queries/tenants'
import { addDomain, reverifyDomain } from '@/queries/tenantDomains'
import { addMember, setMemberRole } from '@/queries/tenantMembers'

describe('tenancy queries', () => {
  beforeEach(() => authFetch.mockReset())

  it('unwraps enablement status and sends confirm payload', async () => {
    authFetch.mockResolvedValueOnce({ data: { tenancy: { step: 'off' } } })
    expect((await fetchEnablementStatus()).step).toBe('off')
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/tenancy/status')

    authFetch.mockResolvedValueOnce({ data: { tenancy: { step: 'retrofitting' } } })
    await confirmEnablement({ slug: 'default', name: 'Default' })
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/tenancy/confirm', {
      method: 'POST',
      body: JSON.stringify({ slug: 'default', name: 'Default' }),
    })
  })

  it('sends explicit resolution retry and seed repair actions', async () => {
    authFetch.mockResolvedValueOnce({ data: { resolution: { step: 'inactive' } } })
    await activateResolution(true)
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/tenancy/resolution/activate', {
      method: 'POST',
      body: JSON.stringify({ retry: true }),
    })

    authFetch.mockResolvedValueOnce({ data: {} })
    await repairTenantSeed('tenant000001')
    expect(authFetch).toHaveBeenLastCalledWith('/v1/admin/tenancy/tenants/tenant000001/seed', {
      method: 'POST',
      body: '{}',
    })
  })

  it('creates tenants without an owner override', async () => {
    authFetch.mockResolvedValueOnce({ data: { uuid: 'tenant000001', status: 'active' } })
    await createTenant({ slug: 'acme', name: 'Acme' })
    const init = authFetch.mock.calls[0]?.[1] as RequestInit
    expect(JSON.parse(String(init.body))).toEqual({ slug: 'acme', name: 'Acme' })
  })

  it('uses the tenant target in domain and member paths', async () => {
    authFetch.mockResolvedValue({ data: { uuid: 'domain000001', token: 'x', txt_record: '_x' } })
    await addDomain('tenant000001', 'www.example.com')
    expect(authFetch.mock.calls[0]?.[0]).toBe('/v1/admin/tenancy/tenants/tenant000001/domains')

    await addMember('tenant000001', 'user00000001', 'member')
    await setMemberRole('tenant000001', 'user00000001', 'admin')
    expect(authFetch.mock.calls[1]?.[0]).toBe('/v1/admin/tenancy/tenants/tenant000001/members')
    expect(authFetch.mock.calls[2]?.[0]).toBe(
      '/v1/admin/tenancy/tenants/tenant000001/members/user00000001',
    )
  })

  it('uses the dedicated domain re-verification action', async () => {
    authFetch.mockResolvedValue({ data: {} })
    await reverifyDomain('domain000001')
    expect(authFetch).toHaveBeenCalledWith('/v1/admin/tenancy/domains/domain000001/reverify', {
      method: 'POST',
      body: '{}',
    })
  })
})
