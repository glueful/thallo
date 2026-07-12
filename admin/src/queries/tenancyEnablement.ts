import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export type EnablementStep =
  | 'off'
  | 'installing'
  | 'awaiting_install'
  | 'enabling_extension'
  | 'awaiting_provider_boot'
  | 'migrating_extension'
  | 'awaiting_confirm'
  | 'retrofitting'
  | 'enabling_enforcement'
  | 'reloading'
  | 'finalizing'
  | 'on'
  | 'disabling'
  | 'disabled_widened'
  | 'failed'

export interface EnablementStatus {
  step: EnablementStep
  enabled: boolean
  schema_state: string
  progress: number
  reloading: boolean
  mode: string
  pending_slug: string | null
  pending_name: string | null
  failure: string | null
  cli_fallback: string | null
}

export const qkEnablement = () => ['tenancy', 'enablement'] as const

function unwrap(json: Record<string, unknown>): EnablementStatus {
  const data = (json.data ?? json) as { tenancy?: EnablementStatus }
  if (!data.tenancy) throw new Error('Malformed tenancy status response.')
  return data.tenancy
}

export async function fetchEnablementStatus(): Promise<EnablementStatus> {
  return unwrap(await authFetch(`${runtimeConfig.apiBase}/tenancy/status`))
}

async function action(name: string, body?: Record<string, unknown>): Promise<EnablementStatus> {
  return unwrap(
    await authFetch(`${runtimeConfig.apiBase}/tenancy/${name}`, {
      method: 'POST',
      body: JSON.stringify(body ?? {}),
    }),
  )
}

export const beginEnablement = () => action('begin')
export const retryEnablement = () => action('retry')
export const cancelEnablement = () => action('cancel')
export const finalizeEnablement = () => action('finalize')
export const disableEnablement = () => action('disable')
export const confirmEnablement = (input: { slug: string; name: string }) => action('confirm', input)

// `enabled` gates the fetch: /tenancy/status is content_permission:tenancy.manage-guarded, so
// callers that render for non-operators (e.g. the sidebar) pass `() => access.manage_platform`
// to avoid a guaranteed 403. Defaults to always-on for the operator-only lifecycle page.
export function useTenancyEnablement(enabled: MaybeRefOrGetter<boolean> = true) {
  return useQuery({
    key: qkEnablement(),
    query: fetchEnablementStatus,
    enabled: () => toValue(enabled),
  })
}

export function useTenancyEnablementMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qkEnablement() })
  return {
    begin: useMutation({ mutation: beginEnablement, onSettled: invalidate }),
    confirm: useMutation({ mutation: confirmEnablement, onSettled: invalidate }),
    retry: useMutation({ mutation: retryEnablement, onSettled: invalidate }),
    cancel: useMutation({ mutation: cancelEnablement, onSettled: invalidate }),
    finalize: useMutation({ mutation: finalizeEnablement, onSettled: invalidate }),
    disable: useMutation({ mutation: disableEnablement, onSettled: invalidate }),
  }
}
