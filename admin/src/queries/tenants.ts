import { useQuery } from '@pinia/colada'
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
