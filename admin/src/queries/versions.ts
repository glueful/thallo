import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

export interface VersionRow {
  uuid: string
  version?: number
  created_at?: string
  created_by?: string | null
  [k: string]: unknown
}

// The versions-list response body isn't typed in the spec; cast to the known contract.
export async function fetchVersions(uuid: string, locale: string): Promise<VersionRow[]> {
  const { data, error, response } = await client.GET('/entries/{uuid}/versions/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
  return (
    (data as unknown as { data?: { versions?: VersionRow[] } } | undefined)?.data?.versions ?? []
  )
}

export function useVersions(uuid: MaybeRefOrGetter<string>, locale: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => [...qk.versions(toValue(uuid)), toValue(locale)],
    query: () => fetchVersions(toValue(uuid), toValue(locale)),
  })
}

export async function rollbackEntry(uuid: string, locale: string, versionUuid: string) {
  const { data, error, response } = await client.POST('/entries/{uuid}/rollback/{locale}', {
    params: { path: { uuid, locale } },
    body: { version_uuid: versionUuid },
  })
  if (error) throw toApiError(error, response)
  return data
}

export function useRollback(uuid: string, locale: string, type: string) {
  const cache = useQueryCache()
  return useMutation({
    mutation: (versionUuid: string) => rollbackEntry(uuid, locale, versionUuid),
    // Rollback re-pins the live version (the draft is untouched) and may change the list display.
    onSettled() {
      cache.invalidateQueries({ key: qk.versions(uuid) })
      cache.invalidateQueries({ key: qk.draft(uuid, locale) })
      cache.invalidateQueries({ key: qk.entries(type) })
    },
  })
}
