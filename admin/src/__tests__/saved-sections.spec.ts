import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import { belongsIn, type Pattern } from '@/queries/patterns'
import { ApiError } from '@/api/errors'
import type { StyleSchemaResult } from '@/queries/styleSchema'

// Saved sections: a block saved from the stage's inspector into the Blocks tab's library, and
// renamed or deleted from its card there. The endpoints are mocked; the library query is not in
// play — a save refreshes it, which these specs see as the mutation having been called.

const saved = vi.hoisted(() => ({ save: vi.fn(), rename: vi.fn(), remove: vi.fn() }))
vi.mock('@/queries/patterns', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/patterns')>()),
  useSavedSections: () => saved,
}))
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))
vi.mock('@/queries/navigation', () => ({ useNavMenus: () => ({ data: ref([]) }) }))

import BlocksPalette from '@/editor/palette/BlocksPalette.vue'
import BlockInspector from '@/editor/inspector/BlockInspector.vue'

const bt = (slug: string): BlockType =>
  ({
    uuid: slug,
    slug,
    label: slug,
    icon: null,
    category: 'Content',
    active: true,
    schema: [],
    style_capabilities: [],
    style_targets: null,
  }) as unknown as BlockType
const pattern = (slug: string, extra: Partial<Pattern> = {}): Pattern => ({
  slug,
  kind: 'section',
  label: slug,
  category: 'Saved',
  description: `${slug} described`,
  blocks: [{ type: 'container', data: {}, settings: {} }],
  ...extra,
})
const SAVED = pattern('saved-abc123def456', {
  label: 'Promo card',
  saved: true,
  id: 'abc123def456',
})

function palette(extra: Record<string, unknown> = {}) {
  return mount(BlocksPalette, {
    props: {
      types: [bt('heading')],
      patterns: [pattern('hero-centered', { category: 'Hero' }), SAVED],
      target: null,
      stale: false,
      clickable: () => ({ ok: true }),
      pageClickable: () => ({ ok: true }),
      ...extra,
    },
  })
}

beforeEach(() => {
  for (const fn of Object.values(saved)) fn.mockReset()
  for (const fn of Object.values(notify)) fn.mockReset()
})

describe('saved sections in the Blocks tab', () => {
  it('a saved section is a card of its own: no picture to fetch, its description shown', async () => {
    const w = palette()
    await w.find('[data-test="palette-view-sections"]').trigger('click')
    const card = w.find(`[data-test="pattern-card-${SAVED.slug}"]`)
    expect(card.find('img').exists()).toBe(false)
    expect(card.text()).toContain('Promo card')
    expect(card.text()).toContain(`${SAVED.slug} described`)
    // The shipped one keeps its picture.
    expect(w.find('[data-test="pattern-card-hero-centered"] img').exists()).toBe(true)
  })

  it('is renamed in place from its card', async () => {
    saved.rename.mockResolvedValue(undefined)
    const w = palette()
    await w.find('[data-test="palette-view-sections"]').trigger('click')
    await w.find(`[data-test="saved-section-rename-${SAVED.id}"]`).trigger('click')
    const input = w.find(`[data-test="saved-section-name-${SAVED.id}"]`)
    expect((input.element as HTMLInputElement).value).toBe('Promo card')
    await input.setValue('Spring promo')
    await w.find(`[data-test="saved-section-rename-save-${SAVED.id}"]`).trigger('submit')
    await flushPromises()
    expect(saved.rename).toHaveBeenCalledWith(SAVED.id, { name: 'Spring promo' })
  })

  it('is deleted from its card after a confirmation', async () => {
    saved.remove.mockResolvedValue(undefined)
    const w = palette()
    await w.find('[data-test="palette-view-sections"]').trigger('click')
    await w.find(`[data-test="saved-section-delete-${SAVED.id}"]`).trigger('click')
    expect(saved.remove).not.toHaveBeenCalled()
    await w.find(`[data-test="saved-section-delete-confirm-${SAVED.id}"]`).trigger('click')
    await flushPromises()
    expect(saved.remove).toHaveBeenCalledWith(SAVED.id)
    expect(notify.success).toHaveBeenCalled()
  })

  it('the Pages view can carry another name: the header and footer call it Templates', () => {
    const w = palette({ pagesLabel: 'Templates' })
    expect(w.find('[data-test="palette-view-pages"]').text()).toBe('Templates')
  })

  it('and its filter and its empty state say templates too', async () => {
    const w = palette({ pagesLabel: 'Templates' })
    await w.find('[data-test="palette-view-pages"]').trigger('click')
    expect(w.find('input').attributes('placeholder')).toBe('Filter templates…')
    expect(w.text()).toContain('No templates match.')
  })

  it('where no page can be inserted (the header and footer), there is no Pages view', () => {
    const w = palette({ pageClickable: undefined })
    expect(w.find('[data-test="palette-view-sections"]').exists()).toBe(true)
    expect(w.find('[data-test="palette-view-pages"]').exists()).toBe(false)
  })
})

describe('saving a section from the inspector', () => {
  const schema = {
    version: 1,
    breakpoints: { base: 0, md: 768, lg: 1024 },
    properties: [],
    advanced: [],
    vocabulary: { version: 1, domains: {}, values: {} },
  } as unknown as StyleSchemaResult
  const block = { id: 'blockaaa0001', type: 'heading', data: { text: 'Hi' }, settings: {} }

  it('saves the selected block under the name given', async () => {
    saved.save.mockResolvedValue(pattern('saved-new'))
    const w = mount(BlockInspector, {
      props: { block, blockType: bt('heading'), schema, classes: [], activeBreakpoint: 'base' },
    })
    await w.find('[data-test="save-as-section"]').trigger('click')
    await w.find('[data-test="save-section-name"]').setValue('Big hello')
    await w.find('[data-test="save-section-category"]').setValue('Greetings')
    await w.find('[data-test="save-section-form"]').trigger('submit')
    await flushPromises()
    expect(saved.save).toHaveBeenCalledWith(block, {
      name: 'Big hello',
      category: 'Greetings',
      description: '',
      scope: 'page',
    })
    expect(notify.success).toHaveBeenCalled()
    expect(w.find('[data-test="save-section-form"]').exists()).toBe(false)
  })

  it('saved from the header or footer, it remembers the region it belongs to', async () => {
    saved.save.mockResolvedValue(pattern('saved-new'))
    const w = mount(BlockInspector, {
      props: {
        block,
        blockType: bt('heading'),
        schema,
        classes: [],
        activeBreakpoint: 'base',
        sectionPlace: { scope: 'region', region: 'footer' },
      },
    })
    await w.find('[data-test="save-as-section"]').trigger('click')
    await w.find('[data-test="save-section-name"]').setValue('Footer bar')
    await w.find('[data-test="save-section-form"]').trigger('submit')
    await flushPromises()
    expect(saved.save.mock.calls[0]![1]).toMatchObject({ scope: 'region', region: 'footer' })
  })

  it('a block the server refuses says why in the form', async () => {
    saved.save.mockRejectedValue(
      new ApiError(
        'Validation failed',
        422,
        { 'block.type': "'heading' is not allowed in the header region" },
        {},
      ),
    )
    const w = mount(BlockInspector, {
      props: {
        block,
        blockType: bt('heading'),
        schema,
        classes: [],
        activeBreakpoint: 'base',
        sectionPlace: { scope: 'region', region: 'header' },
      },
    })
    await w.find('[data-test="save-as-section"]').trigger('click')
    await w.find('[data-test="save-section-name"]').setValue('Big hello')
    await w.find('[data-test="save-section-form"]').trigger('submit')
    await flushPromises()
    expect(w.find('[data-test="save-section-refused"]').text()).toContain(
      "'heading' is not allowed in the header region",
    )
  })

  it('a name is needed first, and a selection of several blocks cannot be saved as one', async () => {
    const w = mount(BlockInspector, {
      props: { block, blockType: bt('heading'), schema, classes: [], activeBreakpoint: 'base' },
    })
    await w.find('[data-test="save-as-section"]').trigger('click')
    await w.find('[data-test="save-section-form"]').trigger('submit')
    await flushPromises()
    expect(saved.save).not.toHaveBeenCalled()

    const several = mount(BlockInspector, {
      props: {
        block,
        blockType: bt('heading'),
        blocks: [block, { ...block, id: 'blockbbb0002' }],
        blockTypes: [bt('heading'), bt('heading')],
        schema,
        classes: [],
        activeBreakpoint: 'base',
      },
    })
    expect(several.find('[data-test="save-as-section"]').exists()).toBe(false)
  })
})

describe('where a library entry is offered', () => {
  it('a page body takes page sections (and older entries with no scope); a region only its own', () => {
    const body = pattern('hero')
    const scoped = pattern('band', { scope: 'page' })
    const header = pattern('bar', { scope: 'region', region: 'header' })
    const footer = pattern('cols', { scope: 'region', region: 'footer' })
    const page = { scope: 'page' } as const
    expect([body, scoped, header, footer].filter((p) => belongsIn(p, page))).toEqual([body, scoped])
    const inHeader = { scope: 'region', region: 'header' } as const
    expect([body, scoped, header, footer].filter((p) => belongsIn(p, inHeader))).toEqual([header])
  })
})
