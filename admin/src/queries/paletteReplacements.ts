import { authFetch } from '@/api/authFetch'
import type { ReplacementBatch } from '@/editor/paletteReplacements'
import { runtimeConfig } from '@/runtime/config'

/**
 * The completed replacements in `(after, through]` (custom palette spec §5.3) — the range an
 * editor's ledger is missing. Rejects with `status: 410` when the range reaches pruned history.
 */
export async function fetchPaletteReplacements(
  after: number,
  through: number,
): Promise<ReplacementBatch> {
  const json = await authFetch(
    `${runtimeConfig.apiBase}/appearance/palette/replacements?after=${after}&through=${through}`,
  )
  return (json as { data: { replacements: ReplacementBatch } }).data.replacements
}
