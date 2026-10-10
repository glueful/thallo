import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import type { ExpiredBatch, ReplacementBatch } from '@/editor/paletteReplacements'
import type { PaletteRewrite } from '@/editor/paletteRewrites'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

export interface DraftData {
  fields: Record<string, unknown>
  lock_version: number
  /** The palette generation the draft was read at (custom palette spec §5.3): an editor's ledger baseline. */
  palette_generation: number
}

/** What a save or restore response says about the palette (custom palette spec §4.5, §5.3). */
export interface PaletteResultFields {
  palette_rewrites?: PaletteRewrite[]
  palette_generation?: number
  palette_replacements?: ReplacementBatch | ExpiredBatch
}

export interface SaveDraftBody {
  fields: Record<string, unknown>
  lock_version: number
  /** The preview revision the save was submitted from (visual builder spec §3.5); null = none. */
  preview_revision?: number | null
  /** The editor's palette ledger boundary: the save's response sends the records newer than it. */
  palette_through?: number | null
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
    palette_generation:
      (data?.data as { palette_generation?: number } | undefined)?.palette_generation ?? 0,
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
      ...(body.palette_through == null ? {} : { palette_through: body.palette_through }),
    } as never,
  })
  if (error) throw toApiError(error, response)
  return data as typeof data & { data?: PaletteResultFields }
}

export interface RestoreDraftResult extends PaletteResultFields {
  draft: { fields: Record<string, unknown>; lock_version: number }
  palette_generation: number
  palette_replacements: ReplacementBatch | ExpiredBatch
}

/**
 * Restore a retained version into the draft, on the server (custom palette spec §4.5): the client
 * names the version, never its content; the version's brand colours stay trusted for later saves.
 */
export async function restoreDraft(
  uuid: string,
  locale: string,
  versionUuid: string,
  lockVersion: number,
  paletteThrough: number | null,
): Promise<RestoreDraftResult> {
  const json = await authFetch(
    `${runtimeConfig.apiBase}/entries/${encodeURIComponent(uuid)}/draft/${encodeURIComponent(locale)}/restore`,
    {
      method: 'POST',
      body: JSON.stringify({
        version_uuid: versionUuid,
        lock_version: lockVersion,
        ...(paletteThrough === null ? {} : { palette_through: paletteThrough }),
      }),
    },
  )
  return (json as { data: RestoreDraftResult }).data
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
