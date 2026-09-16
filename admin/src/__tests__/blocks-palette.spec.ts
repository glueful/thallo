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
  it('renders the tiles in the palette order and filters them', async () => {
    const w = mountPalette()
    const tiles = () =>
      w.findAll('[data-test^="palette-card-"]').map((t) => t.attributes('data-test'))
    expect(tiles()).toEqual(['palette-card-heading', 'palette-card-button', 'palette-card-section'])
    await w.find('[data-test="palette-search"]').setValue('sec')
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
    // Enter inserts the first CLICKABLE tile: heading is first but refused, so button.
    await w.find('[data-test="palette-search"]').trigger('keydown', { key: 'Enter' })
    expect(w.emitted('insert')?.[0]).toEqual(['button'])
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
