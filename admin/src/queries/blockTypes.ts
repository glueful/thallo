import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'
import type { ContentTypeField } from './contentTypes'

// The global block-type registry (block-builder spec §1): reusable block schemas the
// `blocks` field type composes. Slugs are immutable (the blocks/{slug}.twig template
// contract); removal is deactivation only.

/** Mirrors the backend App\Content\Blocks\BlockDepth::MAX (nesting amendment §A2). */
export const MAX_BLOCK_DEPTH = 3

export interface BlockType {
  uuid: string
  slug: string
  label: string
  icon: string | null
  /** Free-form picker grouping ("Layout", "Content", …); null groups under "Other". */
  category: string | null
  description: string | null
  active: boolean
  schema: ContentTypeField[]
}

export async function fetchBlockTypes(): Promise<BlockType[]> {
  const { data, error, response } = await client.GET('/block-types')
  if (error) throw toApiError(error, response)
  return ((data as unknown as { data?: { block_types?: BlockType[] } })?.data?.block_types ??
    []) as BlockType[]
}

export function useBlockTypes() {
  return useQuery({ key: qk.blockTypes, query: fetchBlockTypes })
}

export interface BlockTypePayload {
  label: string
  icon?: string | null
  category?: string | null
  description?: string | null
  schema: ContentTypeField[]
}

export function useBlockTypeMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qk.blockTypes() })

  const create = useMutation({
    mutation: async (body: BlockTypePayload & { slug: string }) => {
      const { error, response } = await client.POST('/block-types', {
        body: body as never,
      })
      if (error) throw toApiError(error, response)
    },
    onSettled: invalidate,
  })

  const update = useMutation({
    mutation: async ({ slug, ...body }: BlockTypePayload & { slug: string }) => {
      const { error, response } = await client.PATCH('/block-types/{slug}', {
        params: { path: { slug } },
        body: body as never,
      })
      if (error) throw toApiError(error, response)
    },
    onSettled: invalidate,
  })

  const setActive = useMutation({
    mutation: async ({ slug, active }: { slug: string; active: boolean }) => {
      const path = active ? '/block-types/{slug}/activate' : '/block-types/{slug}/deactivate'
      const { error, response } = await client.POST(path, { params: { path: { slug } } })
      if (error) throw toApiError(error, response)
    },
    onSettled: invalidate,
  })

  return { create, update, setActive }
}
