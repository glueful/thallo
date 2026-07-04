import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { MAX_BLOCK_DEPTH, type BlockType } from '@/queries/blockTypes'

const blockTypes = ref<BlockType[]>([])

vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  // MAX_BLOCK_DEPTH (and the rest) stay real; only the query hook is mocked.
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: blockTypes }),
}))
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/x', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import BlocksField from '@/fields/components/BlocksField.vue'

const defaultTypes = (): BlockType[] => [
  {
    uuid: 'bt1',
    slug: 'hero',
    label: 'Hero',
    icon: 'i-lucide-star',
    category: 'Layout',
    description: null,
    active: true,
    schema: [{ name: 'heading', type: 'string', required: true, localized: false, filterable: false }],
  },
  {
    uuid: 'bt2',
    slug: 'quote',
    label: 'Quote',
    icon: null,
    category: null,
    description: null,
    active: true,
    schema: [{ name: 'text', type: 'text', required: false, localized: false, filterable: false }],
  },
  {
    uuid: 'bt3',
    slug: 'legacy',
    label: 'Legacy',
    icon: null,
    category: null,
    description: null,
    active: false,
    schema: [],
  },
  {
    uuid: 'bt5',
    slug: 'section',
    label: 'Section',
    icon: null,
    category: 'Layout',
    description: null,
    active: true,
    schema: [
      { name: 'content', type: 'blocks', required: false, localized: false, filterable: false },
    ],
  },
]

const field = { name: 'body', type: 'blocks' as const, required: false }

describe('BlocksField', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    blockTypes.value = defaultTypes()
  })

  it('adds a block from the picker (active types only) with a generated id', async () => {
    const model = ref<{ id: string; type: string; data: Record<string, unknown> }[]>([])
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model.value,
        'onUpdate:modelValue': (v: typeof model.value) => (model.value = v),
      },
    })
    await flushPromises()
    await wrapper.find('[data-test="add-block"]').trigger('click')
    expect(wrapper.find('[data-test="picker-item-hero"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="picker-item-legacy"]').exists()).toBe(false) // inactive hidden
    await wrapper.find('[data-test="picker-item-hero"]').trigger('click')
    await flushPromises()
    const value = model.value as { id: string; type: string }[]
    expect(value).toHaveLength(1)
    expect(value[0]!.type).toBe('hero')
    expect(value[0]!.id.length).toBeGreaterThanOrEqual(8)
  })

  it('respects the field blockTypes allowlist in the picker', async () => {
    const wrapper = mount(BlocksField, {
      props: { field: { ...field, blockTypes: ['quote'] }, modelValue: [] },
    })
    await flushPromises()
    await wrapper.find('[data-test="add-block"]').trigger('click')
    expect(wrapper.find('[data-test="picker-item-quote"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="picker-item-hero"]').exists()).toBe(false)
  })

  it('reorders with the move buttons and deletes with confirm', async () => {
    const model = ref<{ id: string; type: string; data: Record<string, unknown> }[]>([
      { id: 'a', type: 'hero', data: { heading: 'One' } },
      { id: 'b', type: 'quote', data: { text: 'Two' } },
    ])
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model.value,
        'onUpdate:modelValue': (v: typeof model.value) => (model.value = v),
      },
    })
    await flushPromises()
    await wrapper.find('[data-test="block-move-down-a"]').trigger('click')
    expect(model.value[0]!.id).toBe('b')

    await wrapper.setProps({ modelValue: model.value })
    await wrapper.find('[data-test="block-delete-b"]').trigger('click')
    await wrapper.find('[data-test="block-delete-confirm"]').trigger('click')
    expect(model.value.some((b) => b.id === 'b')).toBe(false)
  })

  it('renders a flat tile grid — category orders the tiles, no headings', async () => {
    const wrapper = mount(BlocksField, { props: { field, modelValue: [] } })
    await flushPromises()
    await wrapper.find('[data-test="add-block"]').trigger('click')
    const picker = wrapper.find('[data-test="block-picker"]')
    // No category headings render at all.
    expect(picker.html()).not.toContain('picker-group-')
    // hero is "Layout"; quote is uncategorized → sorts last: clustering
    // survives the flattening even without labels.
    const html = picker.html()
    expect(html.indexOf('picker-item-hero')).toBeGreaterThan(-1)
    expect(html.indexOf('picker-item-hero')).toBeLessThan(html.indexOf('picker-item-quote'))
  })

  it('shows an inactive badge for blocks whose type was deactivated', async () => {
    const wrapper = mount(BlocksField, {
      props: { field, modelValue: [{ id: 'z', type: 'legacy', data: {} }] },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="block-inactive-z"]').exists()).toBe(true)
  })

  it('recurses: adds a child block inside a section', async () => {
    const model = ref<{ id: string; type: string; data: Record<string, unknown> }[]>([
      { id: 's1', type: 'section', data: { content: [] } },
    ])
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model.value,
        'onUpdate:modelValue': (v: typeof model.value) => (model.value = v),
      },
    })
    await flushPromises()
    await wrapper.find('[data-test="block-toggle-s1"]').trigger('click')
    await flushPromises() // async component resolution
    await flushPromises()
    // The nested BlocksField renders its own add-block button. DOM order: the
    // nested button (inside the expanded card) precedes the outer list's button.
    const addButtons = wrapper.findAll('[data-test="add-block"]')
    expect(addButtons.length).toBeGreaterThanOrEqual(2)
    await addButtons[0]!.trigger('click')
    await wrapper.findAll('[data-test="picker-item-hero"]')[0]!.trigger('click')
    await flushPromises()
    const content = model.value[0]!.data.content as { type: string }[]
    expect(content).toHaveLength(1)
    expect(content[0]!.type).toBe('hero')
  })

  it('shows the max-depth notice instead of an editor at depth 3', async () => {
    expect(MAX_BLOCK_DEPTH).toBe(3) // §A2 mirror assertion
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: [{ id: 's1', type: 'section', data: { content: [] } }],
        depth: 3,
      },
    })
    await flushPromises()
    await wrapper.find('[data-test="block-toggle-s1"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="max-depth-notice"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="add-block"]')).toHaveLength(1) // only the outer one
  })

  it('normalizes snake_case block-schema fields for nested widgets (reference target)', async () => {
    // P1 pin: a reference field inside a block must reach ReferenceField as
    // camelCase FieldDef — field.referenceType drives the entry picker.
    blockTypes.value = [
      {
        uuid: 'bt4',
        slug: 'author_card',
        label: 'Author card',
        icon: null,
        description: null,
        category: null,
        active: true,
        schema: [{
          name: 'author',
          type: 'reference',
          required: false,
          localized: false,
          filterable: false,
          reference_type: 'blog',
        }],
      },
    ]
    const wrapper = mount(BlocksField, {
      props: { field, modelValue: [{ id: 'c', type: 'author_card', data: {} }] },
      global: { stubs: { ReferenceField: true } }, // the picker itself has its own spec
    })
    await flushPromises()
    await wrapper.find('[data-test="block-toggle-c"]').trigger('click')
    const nested = wrapper.findComponent({ name: 'ReferenceField' })
    expect(nested.exists()).toBe(true)
    expect((nested.props('field') as { referenceType?: string }).referenceType).toBe('blog')
  })

  it('nested insert menus use the REGION\'s own allowlist, not the root field\'s', async () => {
    // A `section` whose content region declares its OWN block_types allowlist
    // (stage-toolbar spec §5) — local fixture so other tests keep the open region.
    blockTypes.value = defaultTypes().map((t) =>
      t.slug === 'section'
        ? {
            ...t,
            schema: [
              {
                name: 'content',
                type: 'blocks',
                required: false,
                localized: false,
                filterable: false,
                block_types: ['quote'],
              },
            ],
          }
        : t,
    )
    const model = ref<{ id: string; type: string; data: Record<string, unknown> }[]>([
      { id: 'sec00000001', type: 'section', data: { content: [] } },
    ])
    const wrapper = mount(BlocksField, {
      props: {
        field, // root field: NO allowlist -> all active types at root
        modelValue: model.value,
        'onUpdate:modelValue': (v: typeof model.value) => (model.value = v),
      },
    })
    await flushPromises()

    // Expand the section card, open the NESTED region's add button first (DOM
    // order: nested precedes outer): its menu offers ONLY the region allowlist.
    await wrapper.find('[data-test="block-toggle-sec00000001"]').trigger('click')
    await flushPromises()
    await flushPromises()
    const addButtons = wrapper.findAll('[data-test="add-block"]')
    expect(addButtons.length).toBeGreaterThanOrEqual(2)
    await addButtons[0]!.trigger('click')
    const nestedPicker = wrapper.find('[data-test="block-picker"]')
    expect(nestedPicker.exists()).toBe(true)
    expect(nestedPicker.find('[data-test="picker-item-quote"]').exists()).toBe(true)
    expect(nestedPicker.find('[data-test="picker-item-hero"]').exists()).toBe(false)
    expect(nestedPicker.find('[data-test="picker-item-section"]').exists()).toBe(false)

    // The ROOT list's menu still offers all active types.
    await addButtons[addButtons.length - 1]!.trigger('click')
    const pickers = wrapper.findAll('[data-test="block-picker"]')
    const rootPicker = pickers[pickers.length - 1]!
    expect(rootPicker.find('[data-test="picker-item-hero"]').exists()).toBe(true)
    expect(rootPicker.find('[data-test="picker-item-quote"]').exists()).toBe(true)
    expect(rootPicker.find('[data-test="picker-item-section"]').exists()).toBe(true)
  })

  it('canvas structural methods: move/duplicate/delete/insertAfter/pickerTypesFor', async () => {
    let model: { id: string; type: string; data: Record<string, unknown> }[] = [
      { id: 'aaa000000001', type: 'quote', data: { text: 'A' } },
      { id: 'bbb000000002', type: 'quote', data: { text: 'B' } },
      { id: 'sec00000001', type: 'section', data: { content: [] } },
    ]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const api = wrapper.vm as unknown as {
      moveBlock: (id: string, delta: number) => { beforeId: string } | { afterId: string } | null
      duplicateBlock: (id: string) => { newId: string; idMap: Record<string, string> } | null
      deleteBlock: (id: string) => boolean
      insertAfter: (id: string, slug: string) => string | null
      pickerTypesFor: (id: string) => { slug: string }[]
    }

    // moveBlock down: neighbor is the sibling now following it.
    expect(api.moveBlock('aaa000000001', 1)).toEqual({ beforeId: 'sec00000001' })
    expect(model.map((b) => b.id)).toEqual(['bbb000000002', 'aaa000000001', 'sec00000001'])
    await wrapper.setProps({ modelValue: model })

    // Boundary no-op: first block up -> null, model untouched.
    expect(api.moveBlock('bbb000000002', -1)).toBeNull()
    expect(model.map((b) => b.id)).toEqual(['bbb000000002', 'aaa000000001', 'sec00000001'])

    // Move to LIST END -> afterId (the sibling now preceding it).
    expect(api.moveBlock('aaa000000001', 1)).toEqual({ afterId: 'sec00000001' })
    await wrapper.setProps({ modelValue: model })

    // duplicateBlock: fresh id, idMap keyed by the source id.
    const dup = api.duplicateBlock('bbb000000002')
    expect(dup).not.toBeNull()
    expect(dup!.idMap['bbb000000002']).toBe(dup!.newId)
    expect(model[1]!.id).toBe(dup!.newId)
    await wrapper.setProps({ modelValue: model })

    // insertAfter: sibling position, returns the new id.
    const newId = api.insertAfter('bbb000000002', 'quote')
    expect(newId).not.toBeNull()
    expect(model[1]!.id).toBe(newId)
    expect(model[1]!.type).toBe('quote')
    await wrapper.setProps({ modelValue: model })

    // pickerTypesFor at the root list: all active types (open root allowlist).
    expect(api.pickerTypesFor('bbb000000002').map((t) => t.slug).sort()).toEqual([
      'hero',
      'quote',
      'section',
    ])

    // deleteBlock: true then the block is gone; unknown id -> false.
    expect(api.deleteBlock('bbb000000002')).toBe(true)
    expect(model.some((b) => b.id === 'bbb000000002')).toBe(false)
    expect(api.deleteBlock('missing')).toBe(false)
    wrapper.unmount()
  })

  it('moveBlockTo places a block next to a SAME-LIST reference; cross-list denied', async () => {
    let model: { id: string; type: string; data: Record<string, unknown> }[] = [
      { id: 'aaa000000001', type: 'quote', data: { text: 'A' } },
      { id: 'bbb000000002', type: 'quote', data: { text: 'B' } },
      {
        id: 'sec00000001',
        type: 'section',
        data: { content: [{ id: 'inner0000001', type: 'quote', data: {} }] },
      },
    ]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const api = wrapper.vm as unknown as {
      moveBlockTo: (id: string, n: { beforeId: string } | { afterId: string }) => boolean
    }

    // afterId at list end: aaa moves after sec.
    expect(api.moveBlockTo('aaa000000001', { afterId: 'sec00000001' })).toBe(true)
    expect(model.map((b) => b.id)).toEqual(['bbb000000002', 'sec00000001', 'aaa000000001'])
    await wrapper.setProps({ modelValue: model })

    // beforeId back to the front.
    expect(api.moveBlockTo('aaa000000001', { beforeId: 'bbb000000002' })).toBe(true)
    expect(model.map((b) => b.id)).toEqual(['aaa000000001', 'bbb000000002', 'sec00000001'])
    await wrapper.setProps({ modelValue: model })

    // Cross-list reference (nested block) -> denied, NO mutation.
    const before = model.map((b) => b.id)
    expect(api.moveBlockTo('aaa000000001', { beforeId: 'inner0000001' })).toBe(false)
    expect(model.map((b) => b.id)).toEqual(before)
    // Unknown ids -> denied.
    expect(api.moveBlockTo('missing', { beforeId: 'bbb000000002' })).toBe(false)
    expect(api.moveBlockTo('aaa000000001', { beforeId: 'missing' })).toBe(false)
    wrapper.unmount()
  })

  it('patchBlockData patches one field through the tree; blockTypeById resolves types', async () => {
    let model: { id: string; type: string; data: Record<string, unknown> }[] = [
      { id: 'aaa000000001', type: 'quote', data: { text: 'A' } },
    ]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const api = wrapper.vm as unknown as {
      patchBlockData: (id: string, f: string, v: unknown) => boolean
      blockTypeById: (id: string) => string | null
    }
    expect(api.blockTypeById('aaa000000001')).toBe('quote')
    expect(api.blockTypeById('missing')).toBeNull()
    expect(api.patchBlockData('aaa000000001', 'text', '<p>typed</p>')).toBe(true)
    expect(model[0]!.data.text).toBe('<p>typed</p>')
    expect(api.patchBlockData('missing', 'text', 'x')).toBe(false)
    wrapper.unmount()
  })

  it('pickerTypesFor a block INSIDE a region uses the region allowlist', async () => {
    blockTypes.value = defaultTypes().map((t) =>
      t.slug === 'section'
        ? {
            ...t,
            schema: [
              {
                name: 'content',
                type: 'blocks',
                required: false,
                localized: false,
                filterable: false,
                block_types: ['quote'],
              },
            ],
          }
        : t,
    )
    let model: { id: string; type: string; data: Record<string, unknown> }[] = [
      {
        id: 'sec00000001',
        type: 'section',
        data: { content: [{ id: 'inner0000001', type: 'quote', data: {} }] },
      },
    ]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const api = wrapper.vm as unknown as { pickerTypesFor: (id: string) => { slug: string }[] }
    expect(api.pickerTypesFor('inner0000001').map((t) => t.slug)).toEqual(['quote'])
    expect(api.pickerTypesFor('missing')).toEqual([])
    wrapper.unmount()
  })
})
