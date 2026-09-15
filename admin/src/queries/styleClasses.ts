import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

// Style classes (visual builder spec §4): site-owned, theme-independent records a block
// composes through `settings.classes`. The list is one snapshot and names its generation;
// a save carries the version it loaded; delete archives so old revisions still restore.

export interface StyleClass {
  id: string
  version: number
  name: string
  description: string | null
  /** The §1 schema: sparse breakpoints and resets included; no capabilities, no targets. */
  style: Record<string, unknown>
  archived: boolean
  archived_at: string | null
  /** The job holding the class, if any (spec §4.5). */
  locked_by_job: string | null
  created_at: string | null
  updated_at: string | null
}

export interface StyleClassList {
  /** The site style generation the listed classes belong to (spec §4.3). */
  generation: number
  classes: StyleClass[]
}

export interface StyleClassUsage {
  /** One reference per occurrence of the class id in one stored document. */
  references: number
  by_source: {
    entry_drafts: number
    entry_published: number
    entry_versions: number
    regions: number
  }
  active: number
  dormant: number
  properties: Record<string, { active: number; dormant: number }>
}

export async function fetchStyleClasses(): Promise<StyleClassList> {
  const { data, error, response } = await client.GET('/style-classes')
  if (error) throw toApiError(error, response)
  const body = (data as unknown as { data?: { generation?: number; style_classes?: StyleClass[] } })
    ?.data
  return {
    generation: Number(body?.generation ?? 0),
    classes: (body?.style_classes ?? []).map((c) => ({ ...c, archived: Boolean(c.archived) })),
  }
}

export function useStyleClasses() {
  return useQuery({ key: qk.styleClasses, query: fetchStyleClasses })
}

export async function fetchStyleClassUsage(id: string): Promise<StyleClassUsage> {
  const { data, error, response } = await client.GET('/style-classes/{id}/usage', {
    params: { path: { id } },
  })
  if (error) throw toApiError(error, response)
  return (data as unknown as { data: { usage: StyleClassUsage } }).data.usage
}

export function useStyleClassUsage(id: () => string) {
  return useQuery({
    key: () => qk.styleClassUsage(id()),
    query: () => fetchStyleClassUsage(id()),
  })
}

export interface StyleClassPayload {
  name: string
  description?: string | null
  style: Record<string, unknown>
}

export function useStyleClassMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qk.styleClasses() })

  const create = useMutation({
    mutation: async (body: StyleClassPayload): Promise<StyleClass> => {
      const { data, error, response } = await client.POST('/style-classes', { body: body as never })
      if (error) throw toApiError(error, response)
      return (data as unknown as { data: { style_class: StyleClass } }).data.style_class
    },
    onSettled: invalidate,
  })

  const update = useMutation({
    mutation: async ({
      id,
      version,
      ...body
    }: Partial<StyleClassPayload> & { id: string; version: number }): Promise<StyleClass> => {
      const { data, error, response } = await client.PATCH('/style-classes/{id}', {
        params: { path: { id } },
        body: { version, ...body } as never,
      })
      if (error) throw toApiError(error, response)
      return (data as unknown as { data: { style_class: StyleClass } }).data.style_class
    },
    onSettled: invalidate,
  })

  const archive = useMutation({
    mutation: async (id: string) => {
      const { error, response } = await client.DELETE('/style-classes/{id}', {
        params: { path: { id } },
      })
      if (error) throw toApiError(error, response)
    },
    onSettled: invalidate,
  })

  /** A lift that could not preserve appearance deletes its never-referenced record outright. */
  const deleteUnreferenced = useMutation({
    mutation: async (id: string) => {
      const { error, response } = await client.DELETE('/style-classes/{id}', {
        params: { path: { id }, query: { unreferenced: 1 } as never },
      })
      if (error) throw toApiError(error, response)
    },
    onSettled: invalidate,
  })

  return { create, update, archive, deleteUnreferenced }
}
