import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// The vendored icon inventory (icon-picker spec §1): the picker's source of
// truth is what the render pack SHIPS — exactly what icon() can render —
// never the admin's own icon set. Cached per set for the session. Brand
// PREVIEWS ride along as raw vendored SVGs (the admin's icon pipeline has no
// simple-icons collection); lucide previews use the admin's i-lucide-* set.
export type IconSetName = 'lucide' | 'brands'

export interface IconInventory {
  icons: string[]
  /** name → raw vendored SVG markup (brands only; review-gated at import). */
  svgs: Record<string, string>
}

export async function fetchIcons(set: IconSetName): Promise<IconInventory> {
  const query: Record<string, string> = { set }
  if (set === 'brands') query.include = 'svg'
  const { data, error, response } = await client.GET('/icons', {
    params: { query } as never,
  })
  if (error) throw toApiError(error, response)
  const payload = data as unknown as
    | { data?: { icons?: string[]; svgs?: Record<string, string> } }
    | undefined
  return { icons: payload?.data?.icons ?? [], svgs: payload?.data?.svgs ?? {} }
}

export function useIcons(set: () => IconSetName) {
  return useQuery({ key: () => ['icons', set()], query: () => fetchIcons(set()) })
}
