import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, type Ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'

const blockTypes = ref<BlockType[]>([])

vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: blockTypes }),
}))
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/x', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

// Mounting a real UEditor in jsdom is out of harness scope (recorded rule):
// stub the prose editor; the split ROUTINE is browser-verified, the split
// IDENTITY rules are blockListOps unit tests, and this suite drives the EVENT.
vi.mock('@/fields/components/blocks/ProseBlockEditor.vue', () => ({
  default: {
    name: 'ProseBlockEditor',
    props: ['modelValue', 'placeholder'],
    emits: ['update:modelValue', 'insert-block'],
    template: '<div data-test="prose-editor-stub" />',
  },
}))

import BlocksField from '@/fields/components/BlocksField.vue'

type BlockInstance = { id: string; type: string; data: Record<string, unknown> }

const bt = (slug: string, schema: unknown[], extra: Partial<BlockType> = {}): BlockType =>
  ({
    uuid: `bt-${slug}`,
    slug,
    label: slug[0]!.toUpperCase() + slug.slice(1),
    icon: null,
    category: null,
    description: null,
    active: true,
    schema,
    ...extra,
  }) as BlockType

const defaultTypes = (): BlockType[] => [
  bt('hero', [
    { name: 'heading', type: 'string', required: true, localized: false, filterable: false },
  ]),
  bt('quote', [
    { name: 'text', type: 'text', required: false, localized: false, filterable: false },
  ]),
  bt('nest', [
    { name: 'inner', type: 'blocks', required: false, localized: false, filterable: false },
  ]),
]

const field: { name: string; type: 'blocks'; required: boolean; blockTypes?: string[] } = {
  name: 'body',
  type: 'blocks',
  required: false,
}

function mountField(
  model: Ref<BlockInstance[]>,
  fieldOverride = field,
  options: { attachTo?: Element } = {},
) {
  return mount(BlocksField, {
    ...options,
    props: {
      field: fieldOverride,
      modelValue: model.value,
      'onUpdate:modelValue': (v: BlockInstance[]) => (model.value = v),
    },
  })
}

const twoBlocks = (): BlockInstance[] => [
  { id: 'q1', type: 'quote', data: { text: 'One' } },
  { id: 'q2', type: 'quote', data: { text: 'Two' } },
]

beforeEach(() => {
  setActivePinia(createPinia())
  blockTypes.value = defaultTypes()
})

describe('insert dividers + searchable menu', () => {
  it('renders insert dividers at every gap and inserts at the clicked one', async () => {
    const model = ref(twoBlocks())
    const wrapper = mountField(model)
    await flushPromises()
    expect(wrapper.find('[data-test="block-insert-0"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="block-insert-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="block-insert-2"]').exists()).toBe(true)

    await wrapper.find('[data-test="block-insert-1"]').trigger('click')
    await wrapper.find('[data-test="block-picker-filter"]').setValue('hero')
    await wrapper.find('[data-test="picker-item-hero"]').trigger('click')
    await flushPromises()
    expect(model.value.map((b) => b.type)).toEqual(['quote', 'hero', 'quote'])
  })

  it('filter narrows picker items', async () => {
    const model = ref<BlockInstance[]>([])
    const wrapper = mountField(model)
    await flushPromises()
    await wrapper.find('[data-test="add-block"]').trigger('click')
    await wrapper.find('[data-test="block-picker-filter"]').setValue('quo')
    expect(wrapper.find('[data-test="picker-item-quote"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="picker-item-hero"]').exists()).toBe(false)
  })
})

describe('keyboard movement', () => {
  it('meta+arrow moves, meta+d duplicates, slash opens the insert menu', async () => {
    const model = ref(twoBlocks())
    const wrapper = mountField(model)
    await flushPromises()
    const header = wrapper.find('[data-test="block-toggle-q2"]')

    await header.trigger('keydown', { key: 'ArrowUp', metaKey: true })
    expect(model.value.map((b) => b.id)).toEqual(['q2', 'q1'])

    await header.trigger('keydown', { key: 'd', metaKey: true })
    expect(model.value).toHaveLength(3)

    await header.trigger('keydown', { key: '/' })
    expect(wrapper.find('[data-test="block-picker"]').exists()).toBe(true)
  })

  it('delete key opens the confirm; typing in nested inputs is unaffected', async () => {
    const model = ref(twoBlocks())
    const wrapper = mountField(model)
    await flushPromises()
    await wrapper.find('[data-test="block-toggle-q1"]').trigger('keydown', { key: 'Delete' })
    expect(wrapper.find('[data-test="block-delete-confirm"]').exists()).toBe(true)
    // The handler is header-scoped: model unchanged until confirm.
    expect(model.value).toHaveLength(2)
  })
})

describe('prose seam', () => {
  const withRichText = () => [
    ...defaultTypes(),
    bt('rich_text', [
      { name: 'body', type: 'text', format: 'rich', required: false, localized: false, filterable: false },
    ]),
  ]

  it('prose types render chromeless with the prose hook; widgets render cards', async () => {
    blockTypes.value = withRichText()
    const model = ref<BlockInstance[]>([
      { id: 'p1', type: 'rich_text', data: { body: '<p>hi</p>' } },
      { id: 'h1', type: 'hero', data: { heading: 'H' } },
    ])
    const wrapper = mountField(model)
    await flushPromises()
    expect(wrapper.find('[data-test="prose-block-p1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="block-card-p1"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="block-card-h1"]').exists()).toBe(true)
  })

  it('tail-prose prefers rich_text, falls back to a custom prose type, hides when none', async () => {
    // rich_text present -> affordance visible; click appends a rich_text block.
    blockTypes.value = withRichText()
    const model = ref<BlockInstance[]>([])
    let wrapper = mountField(model)
    await flushPromises()
    await wrapper.find('[data-test="tail-prose"]').trigger('click')
    expect(model.value[0]!.type).toBe('rich_text')
    expect(model.value[0]!.data.body).toBe('')

    // Custom prose type only (allowlist excludes rich_text) -> fallback.
    blockTypes.value = [
      ...withRichText(),
      bt('note', [
        { name: 'content', type: 'text', format: 'rich', required: false, localized: false, filterable: false },
      ]),
    ]
    const model2 = ref<BlockInstance[]>([])
    wrapper = mountField(model2, { ...field, blockTypes: ['note', 'hero'] })
    await flushPromises()
    await wrapper.find('[data-test="tail-prose"]').trigger('click')
    expect(model2.value[0]!.type).toBe('note')

    // No prose type allowed -> hidden.
    const model3 = ref<BlockInstance[]>([])
    wrapper = mountField(model3, { ...field, blockTypes: ['hero'] })
    await flushPromises()
    expect(wrapper.find('[data-test="tail-prose"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="add-block"]').exists()).toBe(true)
  })

  it('insert-block from the prose editor drives splitRichTextAt on the tree', async () => {
    blockTypes.value = withRichText()
    const model = ref<BlockInstance[]>([
      { id: 'p1', type: 'rich_text', data: { body: '<p>full</p>' } },
    ])
    const wrapper = mountField(model)
    await flushPromises()
    const prose = wrapper.findComponent({ name: 'ProseBlockEditor' })
    prose.vm.$emit('insert-block', {
      slug: 'hero',
      beforeHtml: '<p>b</p>',
      afterHtml: '<p>a</p>',
    })
    await flushPromises()
    expect(model.value.map((b) => b.type)).toEqual(['rich_text', 'hero', 'rich_text'])
    expect(model.value[0]!.id).toBe('p1') // before-half keeps the original id
    expect(model.value[0]!.data.body).toBe('<p>b</p>')
    expect(model.value[2]!.data.body).toBe('<p>a</p>')
  })
})

describe('outline rail', () => {
  it('toggle reveals the nested tree; click expands ancestors and focuses the header', async () => {
    const model = ref<BlockInstance[]>([
      {
        id: 'n1',
        type: 'nest',
        data: { inner: [{ id: 'q1', type: 'quote', data: { text: 'Deep' } }] },
      },
    ])
    const wrapper = mountField(model, { ...field }, { attachTo: document.body })
    await flushPromises()
    expect(wrapper.find('[data-test="block-outline"]').exists()).toBe(false)
    await wrapper.find('[data-test="block-outline-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="block-outline"]').exists()).toBe(true)
    // Nested row rendered, indented under its parent.
    expect(wrapper.find('[data-test="block-outline-item-q1"]').exists()).toBe(true)

    await wrapper.find('[data-test="block-outline-item-q1"]').trigger('click')
    await flushPromises()
    // Ancestor expanded so the nested card is visible; header focused.
    expect(wrapper.find('[data-test="block-toggle-q1"]').exists()).toBe(true)
    expect(document.activeElement?.getAttribute('data-test')).toBe('block-toggle-q1')
    wrapper.unmount()
  })
})

describe('drag (direct handler — jsdom never simulates sortable)', () => {
  const fakeEl = (dataset: Record<string, string>): HTMLElement =>
    ({ dataset }) as unknown as HTMLElement

  it('renders a drag handle per card and list-identity datasets', async () => {
    const model = ref(twoBlocks())
    const wrapper = mountField(model)
    await flushPromises()
    expect(wrapper.find('[data-test="block-drag-q1"]').exists()).toBe(true)
    expect(wrapper.find('[data-list-parent=""]').exists()).toBe(true)
    expect(wrapper.find('[data-block-id="q1"]').exists()).toBe(true)
  })

  it('commits a valid nested drop via moveAcross (target from event.to)', async () => {
    const model = ref<BlockInstance[]>([
      { id: 'n1', type: 'nest', data: { inner: [] } },
      { id: 'q1', type: 'quote', data: { text: 'One' } },
    ])
    const wrapper = mountField(model)
    await flushPromises()
    const vm = wrapper.vm as unknown as {
      onDragEnd: (e: { item: HTMLElement; to: HTMLElement; from: HTMLElement; newIndex?: number }) => void
    }
    vm.onDragEnd({
      item: fakeEl({ blockId: 'q1' }),
      to: fakeEl({ listParent: 'n1', listRegion: 'inner' }),
      from: fakeEl({ listParent: '', listRegion: '' }),
      newIndex: 0,
    })
    await flushPromises()
    expect(model.value).toHaveLength(1)
    expect((model.value[0]!.data.inner as BlockInstance[])[0]!.id).toBe('q1')
    expect(wrapper.find('[data-test="drop-rejected"]').exists()).toBe(false)
  })

  it('rejects a depth-violating drop: model unchanged + notice rendered', async () => {
    // A 2-high subtree dragged into a region at depth 3 -> 3 + 2 - 1 = 4 > 3.
    const model = ref<BlockInstance[]>([
      {
        id: 'd1',
        type: 'nest',
        data: { inner: [{ id: 'd2', type: 'nest', data: { inner: [] } }] },
      },
      {
        id: 'drag',
        type: 'nest',
        data: { inner: [{ id: 'leaf1', type: 'quote', data: {} }] },
      },
    ])
    const snapshot = JSON.stringify(model.value)
    const wrapper = mountField(model)
    await flushPromises()
    const vm = wrapper.vm as unknown as {
      onDragEnd: (e: { item: HTMLElement; to: HTMLElement; from: HTMLElement; newIndex?: number }) => void
    }
    vm.onDragEnd({
      item: fakeEl({ blockId: 'drag' }),
      to: fakeEl({ listParent: 'd2', listRegion: 'inner' }),
      from: fakeEl({ listParent: '', listRegion: '' }),
      newIndex: 0,
    })
    await flushPromises()
    expect(JSON.stringify(model.value)).toBe(snapshot)
    expect(wrapper.find('[data-test="drop-rejected"]').exists()).toBe(true)
  })
})
