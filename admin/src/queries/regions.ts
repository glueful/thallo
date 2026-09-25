import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { ApplyPreviewOptions, ApplyPreviewResult, RevisionPair } from '@/editor/stage/types'

// Global chrome regions (global-regions spec): header/footer block lists with
// server-owned palettes — the picker filters on what the API declares, nothing
// is hardcoded client-side.
export interface RegionData {
  slug: string
  blocks: BlockInstance[]
  settings: Record<string, unknown>
  palette: string[]
  settings_keys: string[]
  /** What this region may be styled with (its Style tab): declared by the server. */
  style_capabilities: string[]
  /** The stored version a save names as expected (regions-stage spec §4.5); null = no row yet. */
  lock_version: number | null
}

const qk = () => ['regions'] as const

export async function fetchRegions(): Promise<RegionData[]> {
  const { data, error, response } = await client.GET('/regions')
  if (error) throw toApiError(error, response)
  // The endpoint declares no response schema — the generated type is opaque.
  const payload = data as unknown as { data?: { regions?: RegionData[] } } | undefined
  return payload?.data?.regions ?? []
}

export function useRegions() {
  return useQuery({ key: qk(), query: fetchRegions })
}

export function useSaveRegion() {
  const cache = useQueryCache()
  return useMutation({
    mutation: async (vars: {
      slug: string
      blocks: BlockInstance[]
      settings: Record<string, unknown>
      /** Both regions' versions as loaded: a save against a moved one answers 409. */
      expected: Record<string, number | null>
    }) => {
      const { data, error, response } = await client.PUT('/regions/{slug}', {
        params: { path: { slug: vars.slug } },
        body: { blocks: vars.blocks, settings: vars.settings, expected: vars.expected } as never,
      })
      if (error) throw toApiError(error, response)
      return data
    },
    onSettled: () => cache.invalidateQueries({ key: qk() }),
  })
}

/** One region as the server stores it: its blocks and its own settings. */
export interface RegionContent {
  blocks: BlockInstance[]
  settings: Record<string, unknown>
}

/** Both regions in the server's shape — what the stage applies and a save posts. */
export type RegionsPayload = Record<'header' | 'footer', RegionContent>

export interface RegionSession {
  token: string
  /** Null when rendered delivery is off. */
  themeUrl: string | null
  /** The session's baseline: both regions as saved when it was opened, with their versions. */
  regions: Record<string, RegionContent & { lock_version: number | null }>
  /** Whether the page the session shows hides the header or the footer. */
  hidden: Record<'header' | 'footer', boolean>
}

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

/**
 * Open a stage session for the header and footer (regions stage spec §4.1): a published page — the
 * homepage unless `page` names another — with both saved regions pinned as its baseline.
 */
export async function mintRegionSession(page?: string): Promise<RegionSession> {
  const { data, error, response } = await client.POST('/regions/preview/session', {
    body: (page === undefined ? {} : { page }) as never,
  })
  if (error) throw toApiError(error, response)
  const d = record((data as unknown as { data?: unknown })?.data)
  const hidden = record(d.hidden)
  const regions: RegionSession['regions'] = {}
  for (const [slug, raw] of Object.entries(record(d.regions))) {
    const r = record(raw)
    regions[slug] = {
      blocks: Array.isArray(r.blocks) ? (r.blocks as BlockInstance[]) : [],
      settings: record(r.settings),
      lock_version: typeof r.lock_version === 'number' ? r.lock_version : null,
    }
  }
  return {
    token: typeof d.token === 'string' ? d.token : '',
    themeUrl: typeof d.theme_url === 'string' ? d.theme_url : null,
    regions,
    hidden: { header: hidden.header === true, footer: hidden.footer === true },
  }
}

/**
 * Apply both regions to a stage session as its next working-copy revision (regions stage spec
 * §4.3) — validated as a save would be, never written. A stale pair is a 409
 * PREVIEW_REVISION_STALE carrying the current pair; an expired session a 410.
 */
export async function applyRegions(
  token: string,
  regions: RegionsPayload,
  options: ApplyPreviewOptions,
): Promise<ApplyPreviewResult> {
  const { data, error, response } = await client.POST('/regions/preview/apply', {
    body: {
      token,
      regions,
      epoch: options.epoch,
      base_revision: options.base_revision,
      operations: options.operations,
    } as never,
  })
  if (error) throw toApiError(error, response)
  const d = record((data as unknown as { data?: unknown })?.data)
  return {
    epoch: String(d.epoch ?? ''),
    revision: Number(d.revision ?? 0),
    baseline: Number(d.baseline ?? 0),
    style_generation: Number(d.style_generation ?? 0),
    applied_at: String(d.applied_at ?? ''),
    // The regions stage always refreshes the page: its chrome has no fragment path.
    fragments: null,
  }
}

export interface SaveRegionsResult {
  /** Both regions as committed, with their new versions. */
  regions: Record<string, RegionContent & { lock_version: number | null }>
  /** Whether the save cleared the stage's working copy (the accepted pair matched). */
  previewCleared: boolean
}

/**
 * Save the header and footer in one call (regions stage spec §4.5): the dirty regions, both
 * regions' versions as loaded (a moved one answers 409 REGION_VERSION_CONFLICT), and — from the
 * stage — its token and the accepted pair the save was made from.
 */
export async function saveRegions(body: {
  regions: Partial<RegionsPayload>
  expected: Record<'header' | 'footer', number | null>
  token?: string | null
  preview_revision?: RevisionPair | null
}): Promise<SaveRegionsResult> {
  const { data, error, response } = await client.PUT('/regions', { body: body as never })
  if (error) throw toApiError(error, response)
  const d = record((data as unknown as { data?: unknown })?.data)
  const regions: SaveRegionsResult['regions'] = {}
  for (const [slug, raw] of Object.entries(record(d.regions))) {
    const r = record(raw)
    regions[slug] = {
      blocks: Array.isArray(r.blocks) ? (r.blocks as BlockInstance[]) : [],
      settings: record(r.settings),
      lock_version: typeof r.lock_version === 'number' ? r.lock_version : null,
    }
  }
  return { regions, previewCleared: d.preview_cleared === true }
}
