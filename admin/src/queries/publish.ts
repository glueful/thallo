import { useMutation, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

export async function publishEntry(uuid: string, locale: string) {
  const { data, error, response } = await client.POST('/entries/{uuid}/publish/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function unpublishEntry(uuid: string, locale: string) {
  const { data, error, response } = await client.POST('/entries/{uuid}/unpublish/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
  return data
}

export function usePublish(uuid: string, locale: string, type: string) {
  const cache = useQueryCache()
  return useMutation({
    mutation: (action: 'publish' | 'unpublish') =>
      action === 'publish' ? publishEntry(uuid, locale) : unpublishEntry(uuid, locale),
    // Publication state changes the entry's status badge in the list AND the
    // editor's per-locale summaries (PublishPanel status line, LocaleSwitcher).
    onSettled() {
      cache.invalidateQueries({ key: qk.entry(uuid) })
      cache.invalidateQueries({ key: qk.entries(type) })
      cache.invalidateQueries({ key: qk.entryLocales(uuid) })
    },
  })
}
