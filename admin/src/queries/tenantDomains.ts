import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export interface TenantDomain {
  uuid: string
  host: string
  verification_status: string
  status: string
  last_checked_at: string | null
  last_check_status: string | null
  consecutive_failures: number
}

export interface AddedTenantDomain {
  uuid: string
  token: string
  txt_record: string
}

const qk = (tenantUuid: string) => ['tenancy', 'domains', tenantUuid] as const

export async function fetchDomains(tenantUuid: string): Promise<TenantDomain[]> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${tenantUuid}/domains`)
  const data = (json.data ?? json) as { domains?: TenantDomain[] }
  return data.domains ?? []
}

export async function addDomain(tenantUuid: string, host: string): Promise<AddedTenantDomain> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${tenantUuid}/domains`, {
    method: 'POST',
    body: JSON.stringify({ host }),
  })
  return (json.data ?? json) as unknown as AddedTenantDomain
}

async function mutateDomain(uuid: string, action: string, method = 'POST'): Promise<void> {
  await authFetch(`${runtimeConfig.apiBase}/tenancy/domains/${uuid}${action}`, {
    method,
    body: method === 'DELETE' ? undefined : '{}',
  })
}

export const verifyDomain = (uuid: string) => mutateDomain(uuid, '/verify')
export const reverifyDomain = (uuid: string) => mutateDomain(uuid, '/reverify')
export const enableDomain = (uuid: string) => mutateDomain(uuid, '/enable')
export const disableDomain = (uuid: string) => mutateDomain(uuid, '/disable')
export const removeDomain = (uuid: string) => mutateDomain(uuid, '', 'DELETE')

export function useTenantDomains(
  tenantUuid: MaybeRefOrGetter<string>,
  enabled: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => qk(toValue(tenantUuid)),
    query: () => fetchDomains(toValue(tenantUuid)),
    enabled,
  })
}

export function useTenantDomainMutations(tenantUuid: MaybeRefOrGetter<string>) {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qk(toValue(tenantUuid)) })
  return {
    add: useMutation({
      mutation: (host: string) => addDomain(toValue(tenantUuid), host),
      onSettled: invalidate,
    }),
    verify: useMutation({ mutation: verifyDomain, onSettled: invalidate }),
    reverify: useMutation({ mutation: reverifyDomain, onSettled: invalidate }),
    enable: useMutation({ mutation: enableDomain, onSettled: invalidate }),
    disable: useMutation({ mutation: disableDomain, onSettled: invalidate }),
    remove: useMutation({ mutation: removeDomain, onSettled: invalidate }),
  }
}
