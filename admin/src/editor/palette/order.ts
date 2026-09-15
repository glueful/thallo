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
  const rank = (t: BlockType): string => t.category?.trim() || '￿'
  return [...matching].sort((a, b) => rank(a).localeCompare(rank(b)))
}
