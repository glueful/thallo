import { describe, it, expect } from 'vitest'
import { groupByCategory, orderTypes } from '@/editor/palette/order'
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
  bt('container', 'Layout', 'A wrapper'),
  bt('button', 'Content', 'An action'),
]

describe('the palette order (one rule for the Blocks tab and the insert menu)', () => {
  it('clusters by category, named categories alphabetical, uncategorised last, stable within', () => {
    expect(orderTypes(types, '').map((t) => t.slug)).toEqual([
      'hero',
      'button',
      'container',
      'zebra',
    ])
  })

  it('a label match ranks before a description-only match, whatever the category order', () => {
    const withNoise = [bt('animated', 'Content', 'a reveal heading'), ...types]
    expect(orderTypes(withNoise, 'hero').map((t) => t.slug)).toEqual(['hero'])
    expect(
      orderTypes([bt('outline', 'Layout', 'a hero-like band'), bt('hero', 'Content')], 'hero').map(
        (t) => t.slug,
      ),
    ).toEqual(['hero', 'outline'])
    expect(
      orderTypes([...withNoise, bt('heading', 'Content')], 'heading').map((t) => t.slug),
    ).toEqual(['heading', 'animated', 'hero']) // hero's description says heading too
  })

  it('matches the query against label, slug and description, case-insensitively', () => {
    expect(orderTypes(types, 'HERO').map((t) => t.slug)).toEqual(['hero'])
    expect(orderTypes(types, 'action').map((t) => t.slug)).toEqual(['button'])
    expect(orderTypes(types, 'cont').map((t) => t.slug)).toEqual(['container'])
    expect(orderTypes(types, '  ')).toHaveLength(4)
    expect(orderTypes(types, 'nothing')).toEqual([])
  })
})

describe('grouping by category (the block-types page rule)', () => {
  it('known categories lead in the curated order, others follow alphabetically, Other last', () => {
    const all = [
      bt('zebra', null),
      bt('container', 'Layout'),
      bt('gallery', 'Media'),
      bt('hero', 'Content'),
      bt('tab', 'Items'),
      bt('shop', 'Commerce'),
      bt('button', 'Content'),
    ]
    expect(groupByCategory(all).map((g) => [g.category, g.items.map((t) => t.slug)])).toEqual([
      ['Layout', ['container']],
      ['Content', ['hero', 'button']],
      ['Media', ['gallery']],
      ['Items', ['tab']],
      ['Commerce', ['shop']],
      ['Other', ['zebra']],
    ])
    expect(groupByCategory([])).toEqual([])
  })
})
