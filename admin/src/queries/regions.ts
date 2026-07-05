import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

// Global chrome regions (global-regions spec): header/footer block lists with
// server-owned palettes — the picker filters on what the API declares, nothing
// is hardcoded client-side.
export interface RegionData {
  slug: string
  blocks: BlockInstance[]
  settings: Record<string, unknown>
  palette: string[]
  settings_keys: string[]
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

export function usePreviewRegions() {
  return useMutation({
    mutation: async (vars: {
      regions: Partial<Record<string, { blocks: BlockInstance[]; settings: Record<string, unknown> }>>
    }) => {
      const { data, error, response } = await client.POST('/regions/preview', {
        body: { regions: vars.regions } as never,
      })
      if (error) throw toApiError(error, response)
      const payload = data as unknown as { data?: { html?: string } } | undefined
      return payload?.data?.html ?? ''
    },
  })
}

export function useSaveRegion() {
  const cache = useQueryCache()
  return useMutation({
    mutation: async (vars: {
      slug: string
      blocks: BlockInstance[]
      settings: Record<string, unknown>
    }) => {
      const { data, error, response } = await client.PUT('/regions/{slug}', {
        params: { path: { slug: vars.slug } },
        body: { blocks: vars.blocks, settings: vars.settings } as never,
      })
      if (error) throw toApiError(error, response)
      return data
    },
    onSettled: () => cache.invalidateQueries({ key: qk() }),
  })
}
