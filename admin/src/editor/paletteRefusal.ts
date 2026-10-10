// A save the palette refused (custom palette spec §4.5): the 422's `palette` details, location →
// message ("Brand 2 isn't in the palette"). A location is `<block id>:<path>` inside a block, or a
// plain path outside one.
import { apiErrorDetails } from '@/api/errors'

export interface PaletteRefusalItem {
  location: string
  /** The block the colour is on, when it is on one. */
  block: string | null
  message: string
}

export function paletteRefusalOf(e: unknown): PaletteRefusalItem[] | null {
  const palette = apiErrorDetails(e)?.palette
  if (typeof palette !== 'object' || palette === null) return null
  const items = Object.entries(palette as Record<string, unknown>).map(([location, message]) => {
    const colon = location.indexOf(':')
    return {
      location,
      block: colon === -1 ? null : location.slice(0, colon),
      message: String(message),
    }
  })
  return items.length > 0 ? items : null
}

/** What to tell the author: what is refused, and what to do about it. */
export function paletteRefusalText(items: PaletteRefusalItem[]): string {
  const first = items[0]!
  const more = items.length > 1 ? ` (and ${items.length - 1} more)` : ''
  return `${first.message}${more}. Choose another colour where the picker shows it as unavailable.`
}

/**
 * Handle a save the palette refused: read the palette again, select the block (where the editor
 * can), and say what to do. False when the error is something else.
 */
export function handlePaletteRefusal(
  e: unknown,
  ctx: {
    warning: (title: string, description: string) => void
    refresh?: () => unknown
    select?: (blockId: string) => void
  },
): boolean {
  const refused = paletteRefusalOf(e)
  if (!refused) return false
  void ctx.refresh?.()
  const block = refused.find((r) => r.block !== null)?.block
  if (block && ctx.select) ctx.select(block)
  ctx.warning("A colour isn't in the palette", paletteRefusalText(refused))
  return true
}
