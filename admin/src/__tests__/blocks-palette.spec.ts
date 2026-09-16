import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BlocksPalette from '@/editor/palette/BlocksPalette.vue'
import type { BlockType } from '@/queries/blockTypes'

const bt = (slug: string, category: string | null): BlockType =>
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
    flags: null,
    starter_content: null,
  }) as BlockType
const types = [bt('section', 'Layout'), bt('heading', 'Content'), bt('button', 'Content')]

function mountPalette(extra: Record<string, unknown> = {}) {
  return mount(BlocksPalette, {
    props: {
      types,
      target: null,
      stale: false,
      clickable: () => ({ ok: true }),
      ...extra,
    },
  })
}

describe('the Blocks tab (Phase C.1)', () => {
  it('renders the tiles grouped by category, two to a row, and filters within the groups', async () => {
    const w = mountPalette()
    const tiles = () =>
      w.findAll('[data-test^="palette-card-"]').map((t) => t.attributes('data-test'))
    const groups = () =>
      w.findAll('[data-test^="palette-group-"]').map((g) => g.attributes('data-test'))
    expect(groups()).toEqual(['palette-group-Layout', 'palette-group-Content'])
    expect(tiles()).toEqual(['palette-card-section', 'palette-card-heading', 'palette-card-button'])
    expect(w.find('[data-test="palette-group-Content"] h4').text()).toBe('Content')
    expect(w.find('[data-test="palette-group-Content"] .grid').classes()).toContain('grid-cols-2')
    // The tile is a bordered card with a hover and focus treatment and a grab cursor.
    const tile = w.find('[data-test="palette-card-heading"]')
    for (const cls of ['border', 'rounded-md', 'cursor-grab']) expect(tile.classes()).toContain(cls)
    expect(tile.classes().some((c) => c.startsWith('hover:'))).toBe(true)
    expect(tile.classes().some((c) => c.startsWith('focus-visible:'))).toBe(true)

    await w.find('[data-test="palette-search"]').setValue('sec')
    expect(groups()).toEqual(['palette-group-Layout']) // empty groups hide
    expect(tiles()).toEqual(['palette-card-section'])
  })

  it('a refused tile is inert to click and Enter, titled with the reason, and still draggable', async () => {
    const w = mountPalette({
      clickable: (slug: string) =>
        slug === 'heading'
          ? { ok: false, reason: 'type-not-allowed', message: 'Not allowed here' }
          : { ok: true },
      target: { label: 'into Section › content' },
    })
    const heading = w.find('[data-test="palette-card-heading"]')
    expect(heading.attributes('aria-disabled')).toBe('true')
    expect(heading.attributes('title')).toBe('Not allowed here')
    await heading.trigger('click')
    expect(w.emitted('insert')).toBeUndefined()
    heading.element.dispatchEvent(new MouseEvent('pointerdown', { button: 0, bubbles: true }))
    await w.vm.$nextTick()
    expect(w.emitted('pointer-down')?.[0]?.[0]).toBe('heading')
    // Enter inserts the first CLICKABLE tile in group order: section (Layout) leads.
    await w.find('[data-test="palette-search"]').trigger('keydown', { key: 'Enter' })
    expect(w.emitted('insert')?.[0]).toEqual(['section'])
    await w.find('[data-test="palette-card-button"]').trigger('click')
    expect(w.emitted('insert')?.[1]).toEqual(['button'])
  })

  it('the strip shows the armed target, its cancel and Escape clear it, and a stale target says so', async () => {
    const w = mountPalette({ target: { label: 'after Hero' } })
    expect(w.find('[data-test="palette-target"]').text()).toContain('Inserting after Hero')
    await w.find('[data-test="palette-target-cancel"]').trigger('click')
    expect(w.emitted('clear-target')).toHaveLength(1)
    await w.find('[data-test="palette-search"]').trigger('keydown', { key: 'Escape' })
    expect(w.emitted('clear-target')).toHaveLength(2)
    await w.setProps({ target: null, stale: true })
    expect(w.find('[data-test="palette-target"]').text()).toContain('That place is gone')
    await w.setProps({ stale: false })
    expect(w.find('[data-test="palette-target"]').exists()).toBe(false)
  })
})
