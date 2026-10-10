import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import type { ReplacementBatch } from '@/editor/paletteReplacements'
import { qk } from './keys'

// The style schema (visual builder spec §1.3, §3.4): the one runtime source the inspector
// generates its controls from, plus the active theme's vocabulary values for previews.

export type StyleValueKind = 'token' | 'choice' | 'identifier' | 'font' | 'reset'

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
  /** The workspace's palette (custom palette spec §5.2, §5.3); absent from an older server. */
  palette?: {
    /** How many brand colours the deployment allows (0: brand colours are off). */
    limit: number
    /** The configured (and replacing) brand colours, in the author's order. */
    order: string[]
    /** Whether the reader may manage brand colours (content.manage). */
    can_manage?: boolean
    slots: Record<string, PaletteSlot>
    swatches: Record<string, string>
    labels: Record<string, string>
    color_mode?: boolean
    generation: number
    replacements: ReplacementBatch
  }
}

/** One brand colour as the pickers see it (custom palette spec §5.2); a never-issued id is absent. */
export interface PaletteSlot {
  name: string
  hex?: string | null
  state: 'configured' | 'replacing' | 'removed'
  reserved?: boolean
  replacing?: null | {
    to: string
    to_label: string
    contrast_to: string | null
    contrast_to_label: string | null
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
