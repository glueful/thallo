import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

export interface DraftData {
  fields: Record<string, unknown>
  lock_version: number
}

export interface SaveDraftBody {
  fields: Record<string, unknown>
  lock_version: number
  /** The preview revision the save was submitted from (visual builder spec §3.5); null = none. */
  preview_revision?: number | null
}

export async function fetchDraft(uuid: string, locale: string): Promise<DraftData> {
  const { data, error, response } = await client.GET('/entries/{uuid}/draft/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
  const draft = data?.data?.draft
  return {
    fields: (draft?.fields ?? {}) as Record<string, unknown>,
    lock_version: draft?.lock_version ?? 0,
  }
}

export function useDraft(uuid: MaybeRefOrGetter<string>, locale: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.draft(toValue(uuid), toValue(locale)),
    query: () => fetchDraft(toValue(uuid), toValue(locale)),
  })
}

export async function saveDraft(uuid: string, locale: string, body: SaveDraftBody) {
  const { data, error, response } = await client.PUT('/entries/{uuid}/draft/{locale}', {
    params: { path: { uuid, locale } },
    // The spec types `fields` as unknown[]; the backend expects a keyed object — cast through.
    body: {
      fields: body.fields as unknown as unknown[],
      lock_version: body.lock_version,
      preview_revision: body.preview_revision ?? null,
    },
  })
  if (error) throw toApiError(error, response)
  return data
}

// `locale` is a MaybeRefOrGetter so a single editor instance can switch locales and have saves +
// invalidation always target the locale that's active at mutate time.
export function useSaveDraft(uuid: string, locale: MaybeRefOrGetter<string>, type: string) {
  const cache = useQueryCache()
  return useMutation({
    mutation: (body: SaveDraftBody) => saveDraft(uuid, toValue(locale), body),
    // Refresh the draft (new lock_version) and the entries list (display title / status may change).
    onSettled() {
      cache.invalidateQueries({ key: qk.draft(uuid, toValue(locale)) })
      cache.invalidateQueries({ key: qk.entries(type) })
    },
  })
}
