import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export type TenantRole = string
export const TENANT_ROLES: readonly TenantRole[] = ['owner', 'admin', 'member', 'viewer']

export interface AssignableTenantRole {
  slug: string
  name: string
  builtin: boolean
}

export async function fetchAssignableRoles(): Promise<AssignableTenantRole[]> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/roles/assignable`)
  const data = (json.data ?? json) as { roles?: AssignableTenantRole[] }
  return data.roles ?? []
}

export interface TenantMember {
  // Kept as the mutation key (set-role / remove); never shown in the UI.
  user_uuid: string
  role: TenantRole
  status: string
  // Friendly identity attached server-side (null when the user can't be resolved).
  name?: string | null
  email?: string | null
  username?: string | null
}

const qk = (tenantUuid: string) => ['tenancy', 'members', tenantUuid] as const

export async function fetchMembers(tenantUuid: string): Promise<TenantMember[]> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${tenantUuid}/members`)
  const data = (json.data ?? json) as { members?: TenantMember[] }
  return data.members ?? []
}

export async function addMember(
  tenantUuid: string,
  email: string,
  role: TenantRole,
): Promise<void> {
  await authFetch(`${runtimeConfig.apiBase}/tenancy/tenants/${tenantUuid}/members`, {
    method: 'POST',
    body: JSON.stringify({ email, role }),
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
      mutation: (input: { email: string; role: TenantRole }) =>
        addMember(toValue(tenantUuid), input.email, input.role),
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
