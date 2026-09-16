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

/** The curated category order shared by the Blocks tab and the block-types page. */
export const CATEGORY_ORDER = ['Layout', 'Content', 'Media', 'Items']

/**
 * Group a list into category sections: known categories lead in the curated order, any others
 * follow alphabetically, and uncategorised types collect under "Other" at the end. Order within
 * a group is the input's.
 */
export function groupByCategory(types: BlockType[]): { category: string; items: BlockType[] }[] {
  const groups = new Map<string, BlockType[]>()
  for (const t of types) {
    const key = t.category?.trim() || 'Other'
    ;(groups.get(key) ?? groups.set(key, []).get(key)!).push(t)
  }
  const rank = (c: string): number => {
    if (c === 'Other') return CATEGORY_ORDER.length + 1
    const i = CATEGORY_ORDER.indexOf(c)
    return i === -1 ? CATEGORY_ORDER.length : i
  }
  return [...groups.keys()]
    .sort((a, b) => rank(a) - rank(b) || a.localeCompare(b))
    .map((category) => ({ category, items: groups.get(category)! }))
}
