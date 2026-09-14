import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

// The style schema (visual builder spec §1.3, §3.4): the one runtime source the inspector
// generates its controls from, plus the active theme's vocabulary values for previews.

export type StyleValueKind = 'token' | 'choice' | 'identifier' | 'reset'

export interface StylePropertyRow {
  path: string
  group: string
  kinds: StyleValueKind[]
  responsive: boolean
  token_domain: string | null
  choices: string[] | null
}

export interface StyleSchemaResult {
  version: number
  breakpoints: Record<string, number>
  properties: StylePropertyRow[]
  advanced: string[]
  vocabulary: {
    version: number
    domains: Record<string, string[]>
    values: Record<string, string>
  }
}

export async function fetchStyleSchema(): Promise<StyleSchemaResult> {
  const { data, error, response } = await client.GET('/render/style-schema')
  if (error) throw toApiError(error, response)
  return (data as unknown as { data: StyleSchemaResult }).data
}

export function useStyleSchema() {
  return useQuery({ key: qk.styleSchema, query: fetchStyleSchema, staleTime: 5 * 60_000 })
}
