import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { Pattern } from '@/queries/patterns'

// A pattern that needs a product (a shop section or template holding Featured product or Add to
// cart) says so on its card: the product is chosen after inserting it. One that needs nothing
// says nothing.

vi.mock('@/queries/navigation', () => ({ useNavMenus: () => ({ data: ref([]) }) }))

import BlocksPalette from '@/editor/palette/BlocksPalette.vue'

const NOTE = 'Choose a product after inserting it'

const pattern = (slug: string, extra: Partial<Pattern> = {}): Pattern => ({
  slug,
  kind: 'section',
  scope: 'page',
  label: slug,
  category: 'Shop',
  description: `${slug} described`,
  blocks: [{ type: 'container', data: {}, settings: {} }],
  ...extra,
})

function palette() {
  return mount(BlocksPalette, {
    props: {
      types: [],
      patterns: [
        pattern('shop-featured-spotlight', { requires: 'product' }),
        pattern('shop-sale-banner', { requires: null }),
        pattern('shop-landing', { kind: 'page', requires: 'product' }),
        pattern('shop-sale', { kind: 'page', requires: null }),
      ],
      target: null,
      stale: false,
      clickable: () => ({ ok: true }),
      pageClickable: () => ({ ok: true }),
    },
  })
}

describe('a pattern that needs a product', () => {
  it('a section card says to choose the product after inserting it', async () => {
    const w = palette()
    await w.find('[data-test="palette-view-sections"]').trigger('click')
    const needs = w.find('[data-test="pattern-card-shop-featured-spotlight"]')
    expect(needs.find('[data-test="pattern-requires"]').text()).toBe(NOTE)
    const free = w.find('[data-test="pattern-card-shop-sale-banner"]')
    expect(free.exists()).toBe(true)
    expect(free.find('[data-test="pattern-requires"]').exists()).toBe(false)
    expect(free.text()).not.toContain(NOTE)
  })

  it('a template card says so too', async () => {
    const w = palette()
    await w.find('[data-test="palette-view-pages"]').trigger('click')
    const needs = w.find('[data-test="pattern-card-shop-landing"]')
    expect(needs.find('[data-test="pattern-requires"]').text()).toBe(NOTE)
    const free = w.find('[data-test="pattern-card-shop-sale"]')
    expect(free.exists()).toBe(true)
    expect(free.text()).not.toContain(NOTE)
  })
})
