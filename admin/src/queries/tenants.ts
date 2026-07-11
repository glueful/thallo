import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import type { MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export interface TenantSummary {
  uuid: string
  slug: string
  name: string
  status: string
}

export const qkMyTenants = () => ['tenancy', 'my-tenants'] as const

export async function fetchMyTenants(): Promise<TenantSummary[]> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/my-tenants`)
  const data = (json.data ?? json) as { tenants?: TenantSummary[] }
  return Array.isArray(data.tenants) ? data.tenants : []
}

export function useMyTenants() {
  return useQuery({ key: qkMyTenants(), query: fetchMyTenants })
}

export const qkAllTenants = () => ['tenancy', 'all-tenants'] as const

export async function fetchAllTenants(status?: string): Promise<TenantSummary[]> {
  const suffix = status ? `?status=${encodeURIComponent(status)}` : ''
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants${suffix}`)
  const data = (json.data ?? json) as { tenants?: TenantSummary[] }
  return data.tenants ?? []
}

export async function createTenant(input: {
  slug: string
  name: string
}): Promise<{ uuid: string; status: string }> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants`, {
    method: 'POST',
    body: JSON.stringify(input),
  })
  return (json.data ?? json) as { uuid: string; status: string }
}

async function tenantAction(uuid: string, action: string): Promise<void> {
  await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${uuid}/${action}`, {
    method: 'POST',
    body: '{}',
  })
}

export const suspendTenant = (uuid: string) => tenantAction(uuid, 'suspend')
export const reactivateTenant = (uuid: string) => tenantAction(uuid, 'reactivate')
export const repairTenantSeed = (uuid: string) => tenantAction(uuid, 'seed')

export function useAllTenants(enabled: MaybeRefOrGetter<boolean> = true) {
  return useQuery({ key: qkAllTenants(), query: () => fetchAllTenants(), enabled })
}

export function useTenantMutations() {
  const cache = useQueryCache()
  const invalidate = () => {
    cache.invalidateQueries({ key: qkAllTenants() })
    cache.invalidateQueries({ key: qkMyTenants() })
  }
  return {
    create: useMutation({ mutation: createTenant, onSettled: invalidate }),
    suspend: useMutation({ mutation: suspendTenant, onSettled: invalidate }),
    reactivate: useMutation({ mutation: reactivateTenant, onSettled: invalidate }),
    repair: useMutation({ mutation: repairTenantSeed, onSettled: invalidate }),
  }
}
