import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BlocksPalette from '@/editor/palette/BlocksPalette.vue'
import type { BlockType } from '@/queries/blockTypes'
import type { Pattern } from '@/queries/patterns'

// The Blocks tab also holds the section and page library. A SECTION is one block, so it behaves
// exactly as a block tile does — the same click, Enter and drag, down the same path, named apart
// by a `pattern:` key. A PAGE is several sections: it is inserted whole, by click.
const bt = (slug: string): BlockType =>
  ({ uuid: slug, slug, label: slug, icon: null, category: 'Content', active: true }) as BlockType
const pattern = (
  slug: string,
  kind: 'section' | 'page',
  category: string,
  label: string,
): Pattern => ({
  slug,
  kind,
  label,
  category,
  description: `${label} described`,
  blocks: [{ type: 'container', data: {}, settings: {} }],
})
const PATTERNS = [
  pattern('hero-centered', 'section', 'Hero', 'Centred hero'),
  pattern('faq', 'section', 'FAQ', 'FAQ'),
  pattern('page-landing', 'page', 'Pages', 'Landing page'),
]

function mountPalette(extra: Record<string, unknown> = {}) {
  return mount(BlocksPalette, {
    props: {
      types: [bt('heading')],
      patterns: PATTERNS,
      target: null,
      stale: false,
      clickable: () => ({ ok: true }),
      ...extra,
    },
  })
}
/** A click as the keyboard makes it (detail 0) or as the mouse does (detail 1). */
const click = (el: Element, detail: 0 | 1) =>
  el.dispatchEvent(new MouseEvent('click', { detail, bubbles: true }))
const view = (w: ReturnType<typeof mountPalette>, name: string) =>
  w.find(`[data-test="palette-view-${name}"]`)

describe('the section and page library in the Blocks tab', () => {
  it('opens on the blocks, and offers Sections and Pages beside them', async () => {
    const w = mountPalette()
    expect(view(w, 'blocks').attributes('aria-pressed')).toBe('true')
    expect(w.find('[data-test="palette-card-heading"]').exists()).toBe(true)
    expect(w.find('[data-test="pattern-card-faq"]').exists()).toBe(false)

    await view(w, 'sections').trigger('click')
    expect(w.find('[data-test="palette-card-heading"]').exists()).toBe(false)
    expect(
      w.findAll('[data-test^="pattern-group-"]').map((g) => g.attributes('data-test')),
    ).toEqual(['pattern-group-Hero', 'pattern-group-FAQ'])
    const card = w.find('[data-test="pattern-card-hero-centered"]')
    expect(card.text()).toContain('Centred hero')
    expect(card.find('img').attributes('src')).toContain('pattern-thumbs/hero-centered.jpg')
    // Its place is reserved before it loads: a card that grew when its picture arrived would
    // move every card below it, under the pointer.
    expect(card.find('img').attributes('width')).toBe('600')
    expect(Number(card.find('img').attributes('height'))).toBeGreaterThan(100)
    expect(card.attributes('title')).toBe('Centred hero described')

    await view(w, 'pages').trigger('click')
    expect(w.findAll('[data-test^="pattern-card-"]').map((c) => c.attributes('data-test'))).toEqual(
      ['pattern-card-page-landing'],
    )
  })

  it('shows no switch while there is no library, so the tab is the palette it always was', () => {
    const w = mountPalette({ patterns: [] })
    expect(w.find('[data-test="palette-views"]').exists()).toBe(false)
    expect(w.find('[data-test="palette-card-heading"]').exists()).toBe(true)
  })

  it('a section goes down the palette’s own path: keyboard insert and pointer-down, by its key', async () => {
    const w = mountPalette()
    await view(w, 'sections').trigger('click')
    const card = w.find('[data-test="pattern-card-faq"]')
    click(card.element, 0) // keyboard activation
    expect(w.emitted('insert')).toEqual([['pattern:faq']])
    click(card.element, 1) // a mouse click was the pointer path's to judge
    expect(w.emitted('insert')).toHaveLength(1)
    card.element.dispatchEvent(new MouseEvent('pointerdown', { button: 0, bubbles: true }))
    expect(w.emitted('pointer-down')![0]![0]).toBe('pattern:faq')
  })

  it('the search filters the library by name, category and description; Enter inserts the first', async () => {
    const w = mountPalette()
    await view(w, 'sections').trigger('click')
    await w.find('[data-test="palette-search"]').setValue('quest')
    expect(w.findAll('[data-test^="pattern-card-"]')).toHaveLength(0)
    await w.find('[data-test="palette-search"]').setValue('faq')
    expect(w.findAll('[data-test^="pattern-card-"]')).toHaveLength(1)
    await w.find('[data-test="palette-search"]').trigger('keydown', { key: 'Enter' })
    expect(w.emitted('insert')).toEqual([['pattern:faq']])
    await w.find('[data-test="palette-search"]').setValue('zzz')
    expect(w.text()).toContain('No sections match.')
  })

  it('a refused section is inert and says why; a page is inserted whole, by click', async () => {
    const w = mountPalette({
      clickable: (key: string) =>
        key === 'pattern:faq'
          ? { ok: false, reason: 'depth', message: 'Too deep here' }
          : { ok: true },
    })
    await view(w, 'sections').trigger('click')
    const faq = w.find('[data-test="pattern-card-faq"]')
    expect(faq.attributes('aria-disabled')).toBe('true')
    expect(faq.attributes('title')).toBe('Too deep here')
    click(faq.element, 0)
    expect(w.emitted('insert')).toBeUndefined()

    await view(w, 'pages').trigger('click')
    click(w.find('[data-test="pattern-card-page-landing"]').element, 1)
    expect(w.emitted('insert-page')).toEqual([['page-landing']])
    expect(w.emitted('pointer-down')).toBeUndefined()
  })

  it('draws a plain card when a thumbnail does not load', async () => {
    const w = mountPalette()
    await view(w, 'sections').trigger('click')
    const card = w.find('[data-test="pattern-card-faq"]')
    await card.find('img').trigger('error')
    expect(card.find('img').exists()).toBe(false)
    expect(card.find('[data-test="pattern-thumb-missing"]').exists()).toBe(true)
  })
})
