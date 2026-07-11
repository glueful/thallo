import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export type TenantRole = 'owner' | 'admin' | 'member' | 'viewer'
export const TENANT_ROLES: readonly TenantRole[] = ['owner', 'admin', 'member', 'viewer']

export interface TenantMember {
  user_uuid: string
  role: TenantRole
  status: string
}

const qk = (tenantUuid: string) => ['tenancy', 'members', tenantUuid] as const

export async function fetchMembers(tenantUuid: string): Promise<TenantMember[]> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${tenantUuid}/members`)
  const data = (json.data ?? json) as { members?: TenantMember[] }
  return data.members ?? []
}

export async function addMember(
  tenantUuid: string,
  userUuid: string,
  role: TenantRole,
): Promise<void> {
  await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${tenantUuid}/members`, {
    method: 'POST',
    body: JSON.stringify({ user_uuid: userUuid, role }),
  })
}

export async function setMemberRole(
  tenantUuid: string,
  userUuid: string,
  role: TenantRole,
): Promise<void> {
  await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${tenantUuid}/members/${userUuid}`, {
    method: 'PATCH',
    body: JSON.stringify({ role }),
  })
}

export async function removeMember(tenantUuid: string, userUuid: string): Promise<void> {
  await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${tenantUuid}/members/${userUuid}`, {
    method: 'DELETE',
  })
}

export function useTenantMembers(
  tenantUuid: MaybeRefOrGetter<string>,
  enabled: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => qk(toValue(tenantUuid)),
    query: () => fetchMembers(toValue(tenantUuid)),
    enabled,
  })
}

export function useTenantMemberMutations(tenantUuid: MaybeRefOrGetter<string>) {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qk(toValue(tenantUuid)) })
  return {
    add: useMutation({
      mutation: (input: { user_uuid: string; role: TenantRole }) =>
        addMember(toValue(tenantUuid), input.user_uuid, input.role),
      onSettled: invalidate,
    }),
    setRole: useMutation({
      mutation: (input: { user_uuid: string; role: TenantRole }) =>
        setMemberRole(toValue(tenantUuid), input.user_uuid, input.role),
      onSettled: invalidate,
    }),
    remove: useMutation({
      mutation: (userUuid: string) => removeMember(toValue(tenantUuid), userUuid),
      onSettled: invalidate,
    }),
  }
}
