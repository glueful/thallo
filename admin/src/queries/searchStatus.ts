import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

// Settings › Search (search block spec §3.8): GET /v1/admin/search/status and POST /rebuild.
// Requires content.manage, as Settings › General does.

export interface SearchKindStatus {
  kind: string
  label: string
  available: boolean
  reason: string | null
  status: 'pending' | 'building' | 'ready' | 'out_of_date' | 'failed'
  format: 'legacy' | 'v2'
  documents: number
  processed: number
  last_success_at: string | null
  last_error: string | null
  demand_pending: boolean
  stalled: boolean
}

export interface SearchStatus {
  engine: { ready: boolean; message: string | null; version: string | null }
  kinds: SearchKindStatus[]
}

const base = () => `${runtimeConfig.apiBase}/search`
const key = () => ['search', 'status'] as const

export async function fetchSearchStatus(): Promise<SearchStatus> {
  const json = await authFetch(`${base()}/status`)
  return (json.data ?? json) as SearchStatus
}

export async function requestRebuild(kind: string | null): Promise<{ recorded: boolean }> {
  const json = await authFetch(`${base()}/rebuild`, {
    method: 'POST',
    body: JSON.stringify(kind === null ? {} : { kind }),
  })
  return (json.data ?? json) as { recorded: boolean }
}

export function useSearchStatus(enabled: () => boolean = () => true) {
  return useQuery({ key, query: fetchSearchStatus, enabled })
}

export function useSearchRebuild() {
  const cache = useQueryCache()
  return useMutation({
    mutation: requestRebuild,
    onSettled: () => cache.invalidateQueries({ key: key() }),
  })
}

/** A kind is being worked on while it is pending, building, or has demand waiting. */
export const isActive = (k: SearchKindStatus): boolean =>
  k.available && (k.status === 'pending' || k.status === 'building' || k.demand_pending)
