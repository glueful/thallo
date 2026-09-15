import { describe, it, expect } from 'vitest'
import { orderTypes } from '@/editor/palette/order'
import type { BlockType } from '@/queries/blockTypes'

const bt = (slug: string, category: string | null, description: string | null = null): BlockType =>
  ({
    uuid: `bt-${slug}`,
    slug,
    label: slug[0]!.toUpperCase() + slug.slice(1),
    icon: null,
    category,
    description,
    active: true,
    schema: [],
    style_capabilities: null,
    style_targets: null,
    flags: null,
    starter_content: null,
  }) as BlockType

const types = [
  bt('zebra', null, 'striped'),
  bt('hero', 'Content', 'Big heading'),
  bt('section', 'Layout'),
  bt('button', 'Content', 'An action'),
  bt('columns', 'Layout'),
]

describe('the palette order (one rule for the Blocks tab and the insert menu)', () => {
  it('clusters by category, named categories alphabetical, uncategorised last, stable within', () => {
    expect(orderTypes(types, '').map((t) => t.slug)).toEqual([
      'hero',
      'button',
      'section',
      'columns',
      'zebra',
    ])
  })

  it('matches the query against label, slug and description, case-insensitively', () => {
    expect(orderTypes(types, 'HERO').map((t) => t.slug)).toEqual(['hero'])
    expect(orderTypes(types, 'action').map((t) => t.slug)).toEqual(['button'])
    expect(orderTypes(types, 'col').map((t) => t.slug)).toEqual(['columns'])
    expect(orderTypes(types, '  ')).toHaveLength(5)
    expect(orderTypes(types, 'nothing')).toEqual([])
  })
})
