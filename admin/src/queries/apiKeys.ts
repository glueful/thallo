import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// ── API keys (App\Http\Controllers\ApiKeyAdminController, /v1/admin/api-keys) ────────────────────
//
// Calls go through the typed `client` (openapi-fetch): paths, query params and request bodies are
// validated against the generated schema. Responses come back in the `{ success, message, data }`
// envelope; the generator can't type nested-DTO list items (renders `unknown[]`), so list rows are
// cast to the hand-written row interfaces below.

export type ApiKeyStatus = 'active' | 'expired' | 'revoked'

export interface ApiKey {
  uuid: string
  name: string
  /** Public, non-secret part of the key (env tag + 8 chars) — safe to display. */
  key_prefix: string
  owner_uuid: string
  owner_label: string | null
  tenant_uuid: string | null
  tenant_name: string | null
  scopes: string[]
  allowed_ips: string[]
  status: ApiKeyStatus
  /** True when this key was minted by rotating an earlier one. */
  is_rotated: boolean
  expires_at: string | null
  revoked_at: string | null
  created_at: string | null
}

export interface ApiKeyPage {
  api_keys: ApiKey[]
  total: number
  current_page: number
  per_page: number
}

export interface CreateApiKeyInput {
  name: string
  scopes?: string[]
  allowed_ips?: string[]
  expires_at?: string | null
  tenant_uuid?: string | null
}

/** Create/rotate return the plaintext key exactly once — it is never retrievable again. */
export interface SecretResult {
  api_key: ApiKey | null
  plain: string
}

export interface RotateResult extends SecretResult {
  old_expires_at: string
}

export async function fetchApiKeys(params: {
  page: number
  perPage: number
  status?: string
  q?: string
}): Promise<ApiKeyPage> {
  const { data, error, response } = await client.GET('/api-keys', {
    params: {
      query: {
        page: params.page,
        per_page: params.perPage,
        ...(params.status ? { status: params.status as ApiKeyStatus } : {}),
        ...(params.q ? { q: params.q } : {}),
      },
    },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data
  return {
    api_keys: (d?.api_keys ?? []) as ApiKey[],
    total: Number(d?.total ?? 0),
    current_page: Number(d?.current_page ?? params.page),
    per_page: Number(d?.per_page ?? params.perPage),
  }
}

export function useApiKeyList(
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  status: MaybeRefOrGetter<string | undefined>,
  q: MaybeRefOrGetter<string | undefined>,
) {
  return useQuery({
    key: () => ['api-keys', toValue(page), toValue(status) ?? '', toValue(q) ?? ''],
    query: () =>
      fetchApiKeys({
        page: toValue(page),
        perPage: toValue(perPage),
        status: toValue(status) || undefined,
        q: toValue(q) || undefined,
      }),
  })
}

export async function fetchApiKey(uuid: string): Promise<ApiKey> {
  const { data, error, response } = await client.GET('/api-keys/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  return (data?.data?.api_key ?? {}) as ApiKey
}

export function useApiKey(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['api-keys', 'detail', toValue(uuid) ?? ''],
    query: () => fetchApiKey(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

export async function createApiKey(input: CreateApiKeyInput): Promise<SecretResult> {
  const { data, error, response } = await client.POST('/api-keys', {
    body: {
      name: input.name,
      scopes: input.scopes,
      allowed_ips: input.allowed_ips,
      expires_at: input.expires_at,
      tenant_uuid: input.tenant_uuid,
    },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data
  return { api_key: (d?.api_key ?? null) as ApiKey | null, plain: String(d?.plain ?? '') }
}

export async function rotateApiKey(uuid: string, graceHours?: number): Promise<RotateResult> {
  const { data, error, response } = await client.POST('/api-keys/{uuid}/rotate', {
    params: { path: { uuid } },
    body: { grace_hours: graceHours },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data
  return {
    api_key: (d?.api_key ?? null) as ApiKey | null,
    plain: String(d?.plain ?? ''),
    old_expires_at: String(d?.old_expires_at ?? ''),
  }
}

export async function revokeApiKey(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/api-keys/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

/** Replace a key's scopes wholesale (PATCH /api-keys/{uuid}/scopes) — the key value is unchanged. */
export async function updateApiKeyScopes(uuid: string, scopes: string[]): Promise<void> {
  const { error, response } = await client.PATCH('/api-keys/{uuid}/scopes', {
    params: { path: { uuid } },
    body: { scopes } as never,
  })
  if (error) throw toApiError(error, response)
}

export async function updateApiKeyTenant(uuid: string, tenantUuid: string | null): Promise<ApiKey> {
  const { data, error, response } = await client.PATCH('/api-keys/{uuid}/tenant', {
    params: { path: { uuid } },
    body: { tenant_uuid: tenantUuid },
  })
  if (error) throw toApiError(error, response)
  return (data?.data?.api_key ?? {}) as ApiKey
}

export function useApiKeyMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: ['api-keys'] })

  const create = useMutation({ mutation: createApiKey, onSettled: invalidate })
  const rotate = useMutation({
    mutation: (vars: { uuid: string; graceHours?: number }) =>
      rotateApiKey(vars.uuid, vars.graceHours),
    onSettled: invalidate,
  })
  const revoke = useMutation({ mutation: revokeApiKey, onSettled: invalidate })
  const updateScopes = useMutation({
    mutation: (vars: { uuid: string; scopes: string[] }) =>
      updateApiKeyScopes(vars.uuid, vars.scopes),
    onSettled: invalidate,
  })
  const updateTenant = useMutation({
    mutation: (vars: { uuid: string; tenantUuid: string | null }) =>
      updateApiKeyTenant(vars.uuid, vars.tenantUuid),
    onSettled: invalidate,
  })

  return { create, rotate, revoke, updateScopes, updateTenant }
}

const STATUS_META: Record<ApiKeyStatus, { label: string; color: 'success' | 'warning' | 'error' }> =
  {
    active: { label: 'Active', color: 'success' },
    expired: { label: 'Expired', color: 'warning' },
    revoked: { label: 'Revoked', color: 'error' },
  }

export function apiKeyStatusMeta(status: ApiKeyStatus) {
  return STATUS_META[status] ?? STATUS_META.active
}

export function formatDate(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(String(v).replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}

export function formatDateTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(String(v).replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
