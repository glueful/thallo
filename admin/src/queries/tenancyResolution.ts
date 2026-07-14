import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export type ResolutionStep =
  | 'inactive'
  | 'mapping_hosts'
  | 'verifying_wiring'
  | 'rebuilding_routes'
  | 'awaiting_fresh_boot'
  | 'full'
  | 'failed'

export interface ResolutionStatus {
  step: ResolutionStep
  mode: string
  failure: string | null
  fresh_boot_required: boolean
  origin_restart_required: boolean
}

export const qkResolution = () => ['tenancy', 'resolution'] as const

function unwrap(json: Record<string, unknown>): ResolutionStatus {
  const data = (json.data ?? json) as { resolution?: ResolutionStatus }
  if (!data.resolution) throw new Error('Malformed resolution status response.')
  return data.resolution
}

export async function fetchResolutionStatus(): Promise<ResolutionStatus> {
  return unwrap(await authFetch(`${runtimeConfig.apiBase}/tenancy/resolution`))
}

export async function activateResolution(retry = false): Promise<ResolutionStatus> {
  return unwrap(
    await authFetch(`${runtimeConfig.apiBase}/tenancy/resolution/activate`, {
      method: 'POST',
      body: JSON.stringify({ retry }),
    }),
  )
}

export async function deactivateResolution(): Promise<ResolutionStatus> {
  return unwrap(
    await authFetch(`${runtimeConfig.apiBase}/tenancy/resolution/deactivate`, {
      method: 'POST',
      body: '{}',
    }),
  )
}

export async function resetResolution(): Promise<ResolutionStatus> {
  return unwrap(
    await authFetch(`${runtimeConfig.apiBase}/tenancy/resolution/reset`, {
      method: 'POST',
      body: '{}',
    }),
  )
}

export function useTenancyResolution() {
  return useQuery({ key: qkResolution(), query: fetchResolutionStatus })
}

export function useTenancyResolutionMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qkResolution() })
  return {
    activate: useMutation({ mutation: activateResolution, onSettled: invalidate }),
    deactivate: useMutation({ mutation: deactivateResolution, onSettled: invalidate }),
    reset: useMutation({ mutation: resetResolution, onSettled: invalidate }),
  }
}
