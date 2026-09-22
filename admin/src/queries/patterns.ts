import { useQuery } from '@pinia/colada'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { allocateIds, type FactoryBlock } from './blockFactory'
import { qk } from './keys'
import thumbSizes from '@/editor/palette/patternThumbSizes.json'

// The section and page library (the designer's Blocks tab): ready-made sections and starter
// pages, served as block trees WITHOUT ids. A pattern is nothing new to the editor — inserting
// one is inserting ordinary blocks — so everything here is about turning the server's tree into
// blocks the editor owns: fresh ids, every time, all the way down.

export interface Pattern {
  slug: string
  /** A section is one block; a page is several sections. */
  kind: 'section' | 'page'
  label: string
  category: string
  description: string
  blocks: PatternBlock[]
}

/** A factory block whose nested lists are pattern blocks too. */
export interface PatternBlock extends FactoryBlock {
  data: Record<string, unknown> & { content?: PatternBlock[] }
}

export async function fetchPatterns(): Promise<Pattern[]> {
  const json = await authFetch(`${runtimeConfig.apiBase}/patterns`)
  const patterns: unknown = ((json.data ?? json) as { patterns?: unknown }).patterns
  return Array.isArray(patterns) ? (patterns as Pattern[]) : []
}

export function usePatterns() {
  return useQuery({ key: qk.patterns, query: fetchPatterns })
}

/** The pattern's blocks as the editor's own: a fresh id on each, and on every block inside. */
export function instantiate(pattern: Pattern): BlockInstance[] {
  return pattern.blocks.map((block) => allocateIds(block))
}

/**
 * Whether inserted blocks carry the page's own heading: a block, at any depth, whose
 * `heading_level` is `h1`. The starter pages open with such a hero or page header, and the theme
 * prints the entry title as an h1 too unless the page's Show title is off.
 */
export function holdsPageHeading(blocks: readonly unknown[]): boolean {
  const visit = (value: unknown): boolean => {
    if (Array.isArray(value)) return value.some(visit)
    if (value === null || typeof value !== 'object') return false
    const record = value as Record<string, unknown>
    const data = record.data
    if (data !== null && typeof data === 'object') {
      if ((data as Record<string, unknown>).heading_level === 'h1') return true
      return Object.values(data as Record<string, unknown>).some(visit)
    }
    return false
  }
  return blocks.some(visit)
}

// A section rides the palette's one insert path (click, Enter, drag) beside the block types. What
// travels down that path is a string, so a section is named apart from a block type's slug.
const KEY = 'pattern:'
export const patternKey = (slug: string): string => KEY + slug
export const isPatternKey = (key: string): boolean => key.startsWith(KEY)
export const patternSlug = (key: string): string => key.slice(KEY.length)

/**
 * A thumbnail's own size, recorded when it was built, so its place is reserved before it loads.
 * Undefined for a pattern with no thumbnail: the card then shows the plain fallback's height.
 */
export const patternThumbnailSize = (slug: string): [number, number] | undefined =>
  (thumbSizes as unknown as Record<string, [number, number]>)[slug]

/** The thumbnail the admin ships for a pattern (built by scripts/build-pattern-thumbnails). */
export const patternThumbnail = (slug: string): string =>
  `${import.meta.env.BASE_URL}pattern-thumbs/${slug}.jpg`
