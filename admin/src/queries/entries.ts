import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

// The OpenAPI spec types entry rows as `unknown[]`, so we pin the known contract here
// (EntryRepository::listForType / EntryListItemData on the backend).
export interface EntryListRow {
  uuid: string
  display_title: string
  status: 'draft' | 'scheduled' | 'published' | (string & {})
  locales: string[]
  updated_at: string | null
}

export interface EntryListPage {
  entries: EntryListRow[]
  total: number
  current_page: number
  per_page: number
}

export async function fetchEntries(params: {
  type: string
  page: number
  perPage: number
  q?: string
}): Promise<EntryListPage> {
  const { data, error, response } = await client.GET('/entries', {
    params: {
      query: {
        type: params.type,
        page: params.page,
        perPage: params.perPage,
        q: params.q || undefined,
      },
    },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data
  return {
    entries: (d?.entries ?? []) as EntryListRow[],
    total: d?.total ?? 0,
    current_page: d?.current_page ?? params.page,
    per_page: d?.per_page ?? params.perPage,
  }
}

export function useEntries(
  type: MaybeRefOrGetter<string>,
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  q: MaybeRefOrGetter<string | undefined>,
) {
  return useQuery({
    // page + q are part of the key so each page/filter is cached independently and refetches on change.
    key: () => [...qk.entries(toValue(type)), toValue(page), toValue(q) ?? ''],
    query: () =>
      fetchEntries({
        type: toValue(type),
        page: toValue(page),
        perPage: toValue(perPage),
        q: toValue(q),
      }),
  })
}

/** Create a blank entry for a content type (seeded with an empty draft); returns its UUID. */
export async function createEntry(type: string): Promise<string> {
  const { data, error, response } = await client.POST('/entries', {
    body: { content_type: type },
  })
  if (error) throw toApiError(error, response)
  return String(data?.data?.entry?.uuid ?? '')
}

export function useCreateEntry() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (type: string) => createEntry(type),
    onSettled: (_data, _error, type) => {
      cache.invalidateQueries({ key: qk.entries(type) })
      cache.invalidateQueries({ key: qk.home() })
    },
  })
}

/** Soft-delete an entry. Fails with 409 (ENTRY_REFERENCED) if other content still references it. */
export async function deleteEntry(uuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/entries/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
}

export function useDeleteEntry() {
  const cache = useQueryCache()
  return useMutation({
    // `type` rides along so we can invalidate the right list cache on completion.
    mutation: (vars: { uuid: string; type: string }) => deleteEntry(vars.uuid),
    onSettled: (_data, _error, vars) => {
      cache.invalidateQueries({ key: qk.entries(vars.type) })
      cache.invalidateQueries({ key: qk.home() })
    },
  })
}

// ── Per-entry locales (localization UI) ───────────────────────────────────────────────────────────
/** A pending/failed schedule summary for one locale (mirrors EntryRepository::localeSummary). */
export interface EntryLocaleSchedule {
  publish: string | null
  unpublish: string | null
  last_failure: { action: string; run_at: string | null; reason: string } | null
}

/** One locale an entry exists in (EntryRepository::localeSummary). */
export interface EntryLocaleSummary {
  locale: string
  has_draft: boolean
  is_published: boolean
  route_slug: string | null
  draft_updated_at: string | null
  published_at: string | null
  scheduled: EntryLocaleSchedule | null
}

export async function fetchEntryLocales(uuid: string): Promise<EntryLocaleSummary[]> {
  const { data, error, response } = await client.GET('/entries/{uuid}/locales', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  return (data?.data?.locales ?? []) as EntryLocaleSummary[]
}

export function useEntryLocales(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.entryLocales(toValue(uuid)),
    query: () => fetchEntryLocales(toValue(uuid)),
  })
}

/** Create a draft for `locale`, optionally seeding by copying `sourceLocale`. `overwrite` replaces an existing draft. */
export async function createLocaleDraft(
  uuid: string,
  locale: string,
  sourceLocale?: string,
  overwrite = false,
): Promise<void> {
  const { error, response } = await client.POST('/entries/{uuid}/locales/{locale}', {
    params: { path: { uuid, locale } },
    body: { source_locale: sourceLocale ?? null, overwrite },
  })
  if (error) throw toApiError(error, response)
}

export function useCreateLocaleDraft() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (vars: {
      uuid: string
      locale: string
      sourceLocale?: string
      overwrite?: boolean
    }) => createLocaleDraft(vars.uuid, vars.locale, vars.sourceLocale, vars.overwrite ?? false),
    onSettled: (_data, _error, vars) => {
      cache.invalidateQueries({ key: ['entry-locales', vars.uuid] })
      cache.invalidateQueries({ key: qk.draft(vars.uuid, vars.locale) })
    },
  })
}
