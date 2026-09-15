import type { BlockType } from '@/queries/blockTypes'

// The one order for every block picker (Phase C.1): a FLAT, type-to-filter tile list with no
// category headings — categories only cluster the tiles so related blocks stay adjacent (named
// categories alphabetical, uncategorised last; stable within a category). The query matches the
// label, the slug and the description, case-insensitively.
export function orderTypes(types: BlockType[], query: string): BlockType[] {
  const q = query.trim().toLowerCase()
  const matching =
    q === ''
      ? types
      : types.filter(
          (t) =>
            t.label.toLowerCase().includes(q) ||
            t.slug.toLowerCase().includes(q) ||
            (t.description ?? '').toLowerCase().includes(q),
        )
  // A type whose label (or slug) carries the query ranks before one matched by description only,
  // so typing a block's name offers that block first; categories order the rest.
  const byName = (t: BlockType): number =>
    q !== '' && (t.label.toLowerCase().includes(q) || t.slug.toLowerCase().includes(q)) ? 0 : 1
  const rank = (t: BlockType): string => t.category?.trim() || '\uffff'
  return [...matching].sort((a, b) => byName(a) - byName(b) || rank(a).localeCompare(rank(b)))
}
