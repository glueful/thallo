import { useMutation, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

// ── Documentation (Thallo\Core\Content\Http\Controllers\DocsSetupController, /v1/admin/docs/setup) ──
//
// "Set up documentation": makes the content type a docs section needs and lets the site list it.
// Saying it again changes nothing; a type that already exists is never rewritten, and what it
// lacks comes back as `missing`.

export interface DocsSetupInput {
  /** The type's slug, which is the URL. Absent: `docs`. */
  type?: string
  /** The sidebar's groups, in order. Absent: the default five. */
  sections?: string[]
}

export interface DocsSetupResult {
  type: string
  created: boolean
  listed: boolean
  missing: string[]
  url: string
}

export async function setupDocs(input: DocsSetupInput): Promise<DocsSetupResult> {
  const { data, error, response } = await client.POST('/docs/setup', { body: input as never })
  if (error) throw toApiError(error, response)
  return (data as { data?: DocsSetupResult } | undefined)?.data as DocsSetupResult
}

export function useSetupDocs() {
  const cache = useQueryCache()
  return useMutation({
    mutation: setupDocs,
    // A new content type: everything that lists the types has to see it.
    onSettled: () => cache.invalidateQueries({ key: qk.contentTypes() }),
  })
}
