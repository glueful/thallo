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
vi.mock('@/queries/navigation', () => ({
  useNavMenus: () => ({
    data: ref([
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
      { slug: 'footer-menu', name: 'Footer', item_count: 1, lock_version: 0 },
    ]),
  }),
}))

// The server block factory (visual builder spec §5.5): stubbed per slug — a fresh id, the
// canonical defaults the server would send, and the starter merged in.
const factoryStarter: Record<string, Record<string, unknown>> = {
  hero: { headline: 'Headline', links: [] },
  card: { title: 'Card', body: [] },
}
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))
vi.mock('@/queries/blockFactory', () => ({
  useBlockFactory: () => ({
    make: vi.fn(),
    instance: vi.fn(async (slug: string) => {
      if (slug === 'broken') throw new Error('Block type not found.')
      return {
        id: 'f' + Math.random().toString(36).slice(2, 13).padEnd(11, '0'),
        type: slug,
        data: { ...(factoryStarter[slug] ?? {}) },
        settings: {},
      }
    }),
  }),
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
    style_capabilities: null,
    style_targets: null,
    flags: {},
    starter_content: null,
    schema: [
      { name: 'heading', type: 'string', required: true, localized: false, filterable: false },
    ],
  },
  {
    uuid: 'bt2',
    slug: 'quote',
    label: 'Quote',
    icon: null,
    category: null,
    description: null,
    active: true,
    style_capabilities: null,
    style_targets: null,
    flags: {},
    starter_content: null,
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
    style_capabilities: null,
    style_targets: null,
    flags: {},
    starter_content: null,
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
    style_capabilities: null,
    style_targets: null,
    flags: {},
    starter_content: null,
    schema: [
      { name: 'content', type: 'blocks', required: false, localized: false, filterable: false },
    ],
  },
]

const field = { name: 'body', type: 'blocks' as const, required: false }

// Tabs pair (theme-runtime spec §4): mirrors the starter schema — the authoring
// cap is editor+validator enforced, NOT declared in the schema.
const tabsTypes = (): BlockType[] => [
  ...defaultTypes(),
  {
    uuid: 'bt6',
    slug: 'tabs',
    label: 'Tabs',
    icon: null,
    category: 'Content',
    description: null,
    active: true,
    style_capabilities: null,
    style_targets: null,
    flags: {},
    starter_content: null,
    schema: [
      {
        name: 'items',
        type: 'blocks',
        required: false,
        localized: false,
        filterable: false,
        block_types: ['tab'],
      },
    ],
  },
  {
    uuid: 'bt7',
    slug: 'tab',
    label: 'Tab',
    icon: null,
    category: 'Items',
    description: null,
    active: true,
    style_capabilities: null,
    style_targets: null,
    flags: {},
    starter_content: null,
    schema: [
      { name: 'label', type: 'string', required: true, localized: false, filterable: false },
    ],
  },
]

const tabItems = (count: number): { id: string; type: string; data: Record<string, unknown> }[] =>
  Array.from({ length: count }, (_, i) => ({
    id: `tabitem${String(i + 1).padStart(5, '0')}`,
    type: 'tab',
    data: { label: `T${i + 1}` },
  }))

describe('BlocksField', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    blockTypes.value = defaultTypes()
  })

  it('adds a block from the picker (active types only) with a generated id', async () => {
    const model = ref<
      {
        id: string
        type: string
        data: Record<string, unknown>
        settings: Record<string, unknown>
      }[]
    >([])
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

  it('insertion awaits the factory: the new block carries the server defaults and starter', async () => {
    const model = ref<
      {
        id: string
        type: string
        data: Record<string, unknown>
        settings: Record<string, unknown>
      }[]
    >([])
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model.value,
        'onUpdate:modelValue': (v: typeof model.value) => (model.value = v),
      },
    })
    await flushPromises()
    await wrapper.find('[data-test="add-block"]').trigger('click')
    await wrapper.find('[data-test="picker-item-hero"]').trigger('click')
    await flushPromises()
    expect(model.value).toHaveLength(1)
    expect(model.value[0]!.data).toEqual({ headline: 'Headline', links: [] })
    await wrapper.setProps({ modelValue: model.value })

    // insertAfter goes through the same factory.
    const api = wrapper.vm as unknown as {
      insertAfter: (id: string, slug: string) => Promise<string | null>
    }
    const newId = await api.insertAfter(model.value[0]!.id, 'hero')
    expect(newId).not.toBeNull()
    expect(model.value[1]!.id).toBe(newId)
    expect(model.value[1]!.data).toEqual({ headline: 'Headline', links: [] })
    wrapper.unmount()
  })

  it('a factory failure warns and inserts nothing; the tree is untouched', async () => {
    let model: {
      id: string
      type: string
      data: Record<string, unknown>
      settings: Record<string, unknown>
    }[] = [{ id: 'aaa000000001', type: 'hero', data: { heading: 'One' }, settings: {} }]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const api = wrapper.vm as unknown as {
      insertAfter: (id: string, slug: string) => Promise<string | null>
    }
    await expect(api.insertAfter('aaa000000001', 'broken')).resolves.toBeNull()
    expect(model.map((b) => b.id)).toEqual(['aaa000000001'])
    expect(notify.error).toHaveBeenCalledWith(expect.anything(), "Couldn't add block")
    wrapper.unmount()
  })

  it('shift- and cmd-click on a card header emit a select intent instead of toggling', async () => {
    const model = [
      { id: 'aaa000000001', type: 'hero', data: {}, settings: {} },
      { id: 'bbb000000002', type: 'hero', data: {}, settings: {} },
    ]
    const wrapper = mount(BlocksField, { props: { field, modelValue: model } })
    await flushPromises()
    const header = wrapper.find('[data-test="block-toggle-bbb000000002"]')
    await header.trigger('click', { shiftKey: true })
    await header.trigger('click', { metaKey: true })
    expect(wrapper.emitted('select')).toEqual([
      ['bbb000000002', { shift: true, meta: false }],
      ['bbb000000002', { shift: false, meta: true }],
    ])
    wrapper.unmount()
  })

  it('with paletteInsert a gap, the Add block button and the header / emit an insert-request position and open no menu', async () => {
    const model = [
      {
        id: 'aaa000000001',
        type: 'section',
        data: { content: [{ id: 'inner0000001', type: 'hero', data: {}, settings: {} }] },
        settings: {},
      },
      { id: 'bbb000000002', type: 'hero', data: {}, settings: {} },
    ]
    const wrapper = mount(BlocksField, { props: { field, modelValue: model, paletteInsert: true } })
    await flushPromises()
    await wrapper.find('[data-test="block-insert-1"]').trigger('click')
    await wrapper.find('[data-test="add-block"]').trigger('click')
    // The nested list's gap names the parent and its region.
    wrapper
      .findAll('[data-test="block-toggle-aaa000000001"]')[0]!
      .element.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    await wrapper.findAll('[data-test="block-insert-0"]')[1]!.trigger('click') // the nested list's gap
    await wrapper.find('[data-test="block-toggle-bbb000000002"]').trigger('keydown', { key: '/' })
    expect(wrapper.find('[data-test="block-picker"]').exists()).toBe(false)
    expect(wrapper.emitted('insert-request')).toEqual([
      [{ parent: null, slot: 'body', index: 1 }],
      [{ parent: null, slot: 'body', index: 2 }],
      [{ parent: 'aaa000000001', slot: 'content', index: 0 }],
      [{ parent: null, slot: 'body', index: 2 }],
    ])
    wrapper.unmount()
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
    const model = ref<
      {
        id: string
        type: string
        data: Record<string, unknown>
        settings: Record<string, unknown>
      }[]
    >([
      { id: 'a', type: 'hero', data: { heading: 'One' }, settings: {} },
      { id: 'b', type: 'quote', data: { text: 'Two' }, settings: {} },
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
      props: { field, modelValue: [{ id: 'z', type: 'legacy', data: {}, settings: {} }] },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="block-inactive-z"]').exists()).toBe(true)
  })

  it('recurses: adds a child block inside a section', async () => {
    const model = ref<
      {
        id: string
        type: string
        data: Record<string, unknown>
        settings: Record<string, unknown>
      }[]
    >([{ id: 's1', type: 'section', data: { content: [] }, settings: {} }])
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

  it('shows the max-depth notice instead of an editor at depth 5', async () => {
    expect(MAX_BLOCK_DEPTH).toBe(5) // the three surfaces agree (spec §5.2)
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: [{ id: 's1', type: 'section', data: { content: [] }, settings: {} }],
        depth: 5,
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
        style_capabilities: null,
        style_targets: null,
        flags: {},
        starter_content: null,
        schema: [
          {
            name: 'author',
            type: 'reference',
            required: false,
            localized: false,
            filterable: false,
            reference_type: 'blog',
          },
        ],
      },
    ]
    const wrapper = mount(BlocksField, {
      props: { field, modelValue: [{ id: 'c', type: 'author_card', data: {}, settings: {} }] },
      global: { stubs: { ReferenceField: true } }, // the picker itself has its own spec
    })
    await flushPromises()
    await wrapper.find('[data-test="block-toggle-c"]').trigger('click')
    const nested = wrapper.findComponent({ name: 'ReferenceField' })
    expect(nested.exists()).toBe(true)
    expect((nested.props('field') as { referenceType?: string }).referenceType).toBe('blog')
  })

  it("nested insert menus use the REGION's own allowlist, not the root field's", async () => {
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
    const model = ref<
      {
        id: string
        type: string
        data: Record<string, unknown>
        settings: Record<string, unknown>
      }[]
    >([{ id: 'sec00000001', type: 'section', data: { content: [] }, settings: {} }])
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
    let model: {
      id: string
      type: string
      data: Record<string, unknown>
      settings: Record<string, unknown>
    }[] = [
      { id: 'aaa000000001', type: 'quote', data: { text: 'A' }, settings: {} },
      { id: 'bbb000000002', type: 'quote', data: { text: 'B' }, settings: {} },
      { id: 'sec00000001', type: 'section', data: { content: [] }, settings: {} },
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
      insertAfter: (id: string, slug: string) => Promise<string | null>
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
    const newId = await api.insertAfter('bbb000000002', 'quote')
    expect(newId).not.toBeNull()
    expect(model[1]!.id).toBe(newId)
    expect(model[1]!.type).toBe('quote')
    await wrapper.setProps({ modelValue: model })

    // pickerTypesFor at the root list: all active types (open root allowlist).
    expect(
      api
        .pickerTypesFor('bbb000000002')
        .map((t) => t.slug)
        .sort(),
    ).toEqual(['hero', 'quote', 'section'])

    // deleteBlock: true then the block is gone; unknown id -> false.
    expect(api.deleteBlock('bbb000000002')).toBe(true)
    expect(model.some((b) => b.id === 'bbb000000002')).toBe(false)
    expect(api.deleteBlock('missing')).toBe(false)
    wrapper.unmount()
  })

  it('patchBlockData patches one field through the tree; blockTypeById resolves types', async () => {
    let model: {
      id: string
      type: string
      data: Record<string, unknown>
      settings: Record<string, unknown>
    }[] = [{ id: 'aaa000000001', type: 'quote', data: { text: 'A' }, settings: {} }]
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
    let model: {
      id: string
      type: string
      data: Record<string, unknown>
      settings: Record<string, unknown>
    }[] = [
      {
        id: 'sec00000001',
        type: 'section',
        data: { content: [{ id: 'inner0000001', type: 'quote', data: {}, settings: {} }] },
        settings: {},
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

  it('the navigation block renders a menu SELECT (nav-v2 spec §2); other types keep plain inputs', async () => {
    blockTypes.value = [
      ...defaultTypes(),
      {
        uuid: 'bt9',
        slug: 'navigation',
        label: 'Navigation',
        icon: null,
        category: 'Layout',
        description: null,
        active: true,
        style_capabilities: null,
        style_targets: null,
        flags: {},
        starter_content: null,
        schema: [
          { name: 'menu', type: 'string', required: true, localized: false, filterable: false },
        ],
      },
    ]
    const model = ref<
      {
        id: string
        type: string
        data: Record<string, unknown>
        settings: Record<string, unknown>
      }[]
    >([{ id: 'navblk000001', type: 'navigation', data: { menu: 'main' }, settings: {} }])
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model.value,
        'onUpdate:modelValue': (v: typeof model.value) => (model.value = v),
      },
    })
    await flushPromises()
    // Expand the card so its schema form renders.
    await wrapper.find('[data-test="block-toggle-navblk000001"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="nav-menu-select"]').exists()).toBe(true)

    // A hero block's string field stays a plain input — no select.
    const heroModel = ref<
      {
        id: string
        type: string
        data: Record<string, unknown>
        settings: Record<string, unknown>
      }[]
    >([{ id: 'heroblk00001', type: 'hero', data: { heading: 'H' }, settings: {} }])
    const heroWrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: heroModel.value,
        'onUpdate:modelValue': (v: typeof heroModel.value) => (heroModel.value = v),
      },
    })
    await flushPromises()
    await heroWrapper.find('[data-test="block-toggle-heroblk00001"]').trigger('click')
    await flushPromises()
    expect(heroWrapper.find('[data-test="nav-menu-select"]').exists()).toBe(false)
    wrapper.unmount()
    heroWrapper.unmount()
  })

  it('folds grouped fields into collapsible sections; ungrouped fields render flat', async () => {
    blockTypes.value = [
      {
        uuid: 'btg',
        slug: 'grouped',
        label: 'Grouped',
        icon: null,
        category: null,
        description: null,
        active: true,
        style_capabilities: null,
        style_targets: null,
        flags: {},
        starter_content: null,
        schema: [
          { name: 'title', type: 'string', required: false, localized: false, filterable: false },
          {
            name: 'bg',
            type: 'string',
            required: false,
            localized: false,
            filterable: false,
            group: 'Style',
          },
          {
            name: 'pad',
            type: 'number',
            required: false,
            localized: false,
            filterable: false,
            group: 'Style',
          },
          {
            name: 'link_url',
            type: 'string',
            required: false,
            localized: false,
            filterable: false,
            group: 'Style',
          },
        ],
      },
    ]
    const model = ref<
      {
        id: string
        type: string
        data: Record<string, unknown>
        settings: Record<string, unknown>
      }[]
    >([{ id: 'g1', type: 'grouped', data: {}, settings: {} }])
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model.value,
        'onUpdate:modelValue': (v: typeof model.value) => (model.value = v),
      },
    })
    await flushPromises()
    await wrapper.find('[data-test="block-toggle-g1"]').trigger('click')
    await flushPromises()

    // The Style group is a collapsible <details>; its grouped fields live inside it.
    const group = wrapper.find('[data-test="block-group-Style"]')
    expect(group.exists()).toBe(true)
    expect(group.element.tagName.toLowerCase()).toBe('details')
    expect(group.text()).toContain('bg')
    expect(group.text()).toContain('pad')
    // snake_case field names render as human-readable labels.
    expect(group.text()).toContain('link url')
    // The ungrouped `title` renders flat — present in the card, NOT inside the group.
    expect(wrapper.text()).toContain('title')
    expect(group.text()).not.toContain('title')

    // A block that declares no groups renders no group sections at all (flat, as before).
    const flat = ref<
      {
        id: string
        type: string
        data: Record<string, unknown>
        settings: Record<string, unknown>
      }[]
    >([{ id: 'h1', type: 'hero', data: { heading: 'H' }, settings: {} }])
    const flatWrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: flat.value,
        'onUpdate:modelValue': (v: typeof flat.value) => (flat.value = v),
      },
    })
    await flushPromises()
    await flatWrapper.find('[data-test="block-toggle-h1"]').trigger('click')
    await flushPromises()
    expect(flatWrapper.find('details').exists()).toBe(false)
    wrapper.unmount()
    flatWrapper.unmount()
  })

  // ── Tabs authoring cap (theme-runtime spec §4) ─────────────────────────────
  // The editor gates NET ADDITIONS into a full tabs items list; rearrangement
  // is never blocked. The server enforces the same cap at save.

  it('a FULL tabs items list offers no picker types; 11 items keep the allowlist', async () => {
    blockTypes.value = tabsTypes()
    const full = mount(BlocksField, {
      props: {
        field,
        modelValue: [
          { id: 'tabsblk00001', type: 'tabs', data: { items: tabItems(12) }, settings: {} },
        ],
      },
    })
    await flushPromises()
    const fullApi = full.vm as unknown as { pickerTypesFor: (id: string) => { slug: string }[] }
    expect(fullApi.pickerTypesFor('tabitem00001')).toEqual([])
    full.unmount()

    const spare = mount(BlocksField, {
      props: {
        field,
        modelValue: [
          { id: 'tabsblk00001', type: 'tabs', data: { items: tabItems(11) }, settings: {} },
        ],
      },
    })
    await flushPromises()
    const spareApi = spare.vm as unknown as { pickerTypesFor: (id: string) => { slug: string }[] }
    expect(spareApi.pickerTypesFor('tabitem00001').map((t) => t.slug)).toEqual(['tab'])
    spare.unmount()
  })

  it('insertAfter and duplicateBlock no-op when the destination tabs list is full', async () => {
    blockTypes.value = tabsTypes()
    let model: {
      id: string
      type: string
      data: Record<string, unknown>
      settings: Record<string, unknown>
    }[] = [{ id: 'tabsblk00001', type: 'tabs', data: { items: tabItems(12) }, settings: {} }]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const api = wrapper.vm as unknown as {
      insertAfter: (id: string, slug: string) => Promise<string | null>
      duplicateBlock: (id: string) => { newId: string } | null
    }
    const before = JSON.stringify(model)
    await expect(api.insertAfter('tabitem00003', 'tab')).resolves.toBeNull()
    expect(api.duplicateBlock('tabitem00003')).toBeNull()
    expect(JSON.stringify(model)).toBe(before) // tree untouched
    wrapper.unmount()
  })

  it('cross-list drag into a full tabs list no-ops; same-list reorder still commits', async () => {
    blockTypes.value = tabsTypes()
    const fakeEl = (dataset: Record<string, string>): HTMLElement =>
      ({ dataset }) as unknown as HTMLElement
    let model: {
      id: string
      type: string
      data: Record<string, unknown>
      settings: Record<string, unknown>
    }[] = [
      { id: 'tabsblk00001', type: 'tabs', data: { items: tabItems(12) }, settings: {} },
      { id: 'loose0000001', type: 'tab', data: { label: 'X' }, settings: {} },
    ]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const vm = wrapper.vm as unknown as {
      onDragEnd: (e: {
        item: HTMLElement
        to: HTMLElement
        from: HTMLElement
        newIndex?: number
      }) => void
    }

    // Cross-list (root -> full tabs items): a NET ADDITION — rejected, no mutation.
    const before = JSON.stringify(model)
    vm.onDragEnd({
      item: fakeEl({ blockId: 'loose0000001' }),
      to: fakeEl({ listParent: 'tabsblk00001', listRegion: 'items' }),
      from: fakeEl({ listParent: '', listRegion: '' }),
      newIndex: 0,
    })
    await flushPromises()
    expect(JSON.stringify(model)).toBe(before)
    const notice = wrapper.find('[data-test="drop-rejected"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('12 items') // the cap message, not the depth one

    // Same-list reorder of the FULL list: net count unchanged — always allowed.
    vm.onDragEnd({
      item: fakeEl({ blockId: 'tabitem00001' }),
      to: fakeEl({ listParent: 'tabsblk00001', listRegion: 'items' }),
      from: fakeEl({ listParent: 'tabsblk00001', listRegion: 'items' }),
      newIndex: 11,
    })
    await flushPromises()
    const items = model[0]!.data.items as { id: string }[]
    expect(items).toHaveLength(12)
    expect(items[11]!.id).toBe('tabitem00001')
    wrapper.unmount()
  })
})
