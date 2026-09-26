import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BlocksPalette from '@/editor/palette/BlocksPalette.vue'
import { visibleTypes } from '@/editor/palette/order'
import type { BlockType } from '@/queries/blockTypes'

const bt = (slug: string, category: string | null, layoutOnly = false): BlockType =>
  ({
    uuid: `bt-${slug}`,
    slug,
    label: slug[0]!.toUpperCase() + slug.slice(1),
    icon: null,
    category,
    description: null,
    active: true,
    schema: [],
    style_capabilities: null,
    style_targets: null,
    flags: layoutOnly ? { layout_only: true } : null,
    starter_content: null,
  }) as BlockType
const types = [
  bt('section', 'Layout'),
  bt('heading', 'Content'),
  bt('entry_title', 'Fields', true),
  bt('entry_date', 'Fields', true),
]

function groupsOf(extra: Record<string, unknown> = {}): string[] {
  const w = mount(BlocksPalette, {
    props: { types, target: null, stale: false, clickable: () => ({ ok: true }), ...extra },
  })
  return w.findAll('[data-test^="palette-group-"]').map((g) => g.attributes('data-test')!)
}

describe('the palette on a layout (type layouts)', () => {
  it('leadCategory puts that category first', () => {
    expect(groupsOf({ leadCategory: 'Fields' })).toEqual([
      'palette-group-Fields',
      'palette-group-Layout',
      'palette-group-Content',
    ])
  })

  it('without leadCategory the order is unchanged', () => {
    expect(groupsOf()).toEqual([
      'palette-group-Layout',
      'palette-group-Content',
      'palette-group-Fields',
    ])
  })

  it('field blocks are hidden unless the page asks for them', () => {
    expect(visibleTypes(types, false).map((t) => t.slug)).toEqual(['section', 'heading'])
    expect(visibleTypes(types, true).map((t) => t.slug)).toEqual([
      'section',
      'heading',
      'entry_title',
      'entry_date',
    ])
  })
})
