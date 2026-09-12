import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// ── Cache (Thallo\Core\Http\Controllers\CacheAdminController, /v1/admin/cache) ─────────────────────────────

export interface CacheStat {
  key: string
  value: string
}

export interface CacheStatus {
  driver: string
  prefix: string
  tags_enabled: boolean
  key_count: number
  stats: CacheStat[]
}

const qk = () => ['utilities', 'cache'] as const

export async function fetchCacheStatus(): Promise<CacheStatus> {
  const { data, error, response } = await client.GET('/cache')
  if (error) throw toApiError(error, response)
  return (data?.data?.cache ?? {}) as CacheStatus
}

export function useCacheStatus() {
  return useQuery({ key: qk(), query: fetchCacheStatus })
}

/** Clear everything (no arg) or just one content type's delivery cache. */
export async function clearCache(contentType?: string): Promise<CacheStatus> {
  const { data, error, response } = await client.POST('/cache/clear', {
    body: contentType ? { content_type: contentType } : {},
  })
  if (error) throw toApiError(error, response)
  return (data?.data?.cache ?? {}) as CacheStatus
}

export function useCacheMutations() {
  const cache = useQueryCache()
  const clear = useMutation({
    mutation: (contentType?: string) => clearCache(contentType),
    onSettled: () => cache.invalidateQueries({ key: qk() }),
  })
  return { clear }
}
