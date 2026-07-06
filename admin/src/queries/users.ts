import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

// Users are read-only via the API: GET /v1/users (paginated list) + GET /v1/users/{uuid}. The list
// is off by default (USERS_USER_LIST_ENABLED) and needs the `users.view` permission. Each row is a
// merged account + nested public `profile`; the exact columns are config-driven, so we pin loosely.
export interface UserProfile {
  first_name?: string
  last_name?: string
  [k: string]: unknown
}

export interface UserRole {
  uuid: string
  name: string
  slug: string
}

export interface UserRow {
  uuid: string
  username?: string
  email?: string
  status?: string
  email_verified_at?: string | null
  two_factor_enabled?: boolean
  created_at?: string
  // Attached server-side by the aegis UserRecordEnricher (the `users.record_enricher` seam).
  roles?: UserRole[]
  profile?: UserProfile | null
  [k: string]: unknown
}

export interface UsersPage {
  users: UserRow[]
  total: number
  current_page: number
  per_page: number
}

export async function fetchUsers(params: {
  page: number
  perPage: number
  search?: string
}): Promise<UsersPage> {
  const q = new URLSearchParams({ page: String(params.page), per_page: String(params.perPage) })
  if (params.search) q.set('search', params.search)
  // Flat framework pagination envelope: rows are `data`, the meta is at the response root
  // (current_page/per_page/total/…), via Response::successWithMeta() — same shape as /rbac/*.
  const json = await authFetch(`/v1/users?${q.toString()}`)
  const rows = Array.isArray(json.data) ? (json.data as UserRow[]) : []
  return {
    users: rows,
    total: Number(json.total ?? rows.length),
    current_page: Number(json.current_page ?? params.page),
    per_page: Number(json.per_page ?? params.perPage),
  }
}

export function useUsers(
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  search: MaybeRefOrGetter<string | undefined>,
) {
  return useQuery({
    key: () => ['users', toValue(page), toValue(search) ?? ''],
    query: () =>
      fetchUsers({ page: toValue(page), perPage: toValue(perPage), search: toValue(search) }),
  })
}

/** A single enriched user record (profile + roles), GET /v1/users/{uuid}. */
export async function fetchUser(uuid: string): Promise<UserRow> {
  const json = await authFetch(`/v1/users/${encodeURIComponent(uuid)}`)
  return (json.data ?? json) as UserRow
}

export function useUser(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['users', 'detail', toValue(uuid) ?? ''],
    query: () => fetchUser(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

/** Display name for a user row: profile name → username → email → short uuid. */
export function userDisplayName(u: UserRow): string {
  const full = [u.profile?.first_name, u.profile?.last_name].filter(Boolean).join(' ').trim()
  return full || u.username || u.email || u.uuid.slice(0, 8)
}

// ── Admin user management (app-owned, Thallo's /v1/admin/users; create needs users.create,
// delete needs users.delete). The list/read still comes from glueful/users (/v1/users). ──
export interface CreateUserInput {
  username: string
  email: string
  password: string
  first_name?: string
  last_name?: string
  role_slugs?: string[]
}

/** Partial update — only supplied fields change (`role_slugs` omitted ⇒ roles untouched). */
export interface UpdateUserInput {
  username?: string
  email?: string
  status?: string
  first_name?: string
  last_name?: string
  role_slugs?: string[]
}

export function useUserAdminMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: ['users'] })

  const create = useMutation({
    mutation: (input: CreateUserInput) =>
      authFetch(`${runtimeConfig.apiBase}/users`, {
        method: 'POST',
        body: JSON.stringify(input),
      }),
    onSettled: invalidate,
  })
  const update = useMutation({
    mutation: (vars: { uuid: string; input: UpdateUserInput }) =>
      authFetch(`${runtimeConfig.apiBase}/users/${encodeURIComponent(vars.uuid)}`, {
        method: 'PATCH',
        body: JSON.stringify(vars.input),
      }),
    onSettled: invalidate,
  })
  const remove = useMutation({
    mutation: (uuid: string) =>
      authFetch(`${runtimeConfig.apiBase}/users/${encodeURIComponent(uuid)}`, { method: 'DELETE' }),
    onSettled: invalidate,
  })

  return { create, update, remove }
}
