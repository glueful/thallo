import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import CanvasOutline from '@/pages/content/[type]/[uuid]/design/components/CanvasOutline.vue'
import MoveToDialog from '@/pages/content/[type]/[uuid]/design/components/MoveToDialog.vue'
import type { LegalityContext, SlotTypeSummary } from '@/editor/structure/legality'

// Visual builder spec §5.5: the outline reorders and reparents with the same rules — every row
// knows its zone, an empty slot is a row a block can be dropped into — and M opens Move to…,
// which offers only legal destinations.
const type = (slug: string, label: string, slots: string[] = []): BlockType =>
  ({
    uuid: `bt-${slug}`,
    slug,
    label,
    icon: null,
    category: null,
    description: null,
    active: true,
    schema: slots.map((name) => ({
      name,
      type: 'blocks',
      required: false,
      localized: false,
      filterable: false,
    })),
    style_capabilities: null,
    style_targets: null,
    flags: null,
    starter_content: null,
  }) as BlockType
const blockTypes = ref<BlockType[]>([
  type('section', 'Section', ['content']),
  type('heading', 'Heading'),
  type('columns', 'Columns', ['col_1', 'col_2', 'col_3']),
])
vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: blockTypes }),
}))

const block = (id: string, t = 'heading', data: Record<string, unknown> = {}) => ({
  id,
  type: t,
  data,
  settings: {},
})
const fields = {
  body: [
    block('a'),
    block('s', 'section', { content: [block('x')] }),
    block('e', 'section', { content: [] }),
  ],
}
const schema = [
  { name: 'title', type: 'string' },
  { name: 'body', type: 'blocks' },
] as never

function mountOutline(selected: string | null = null) {
  return mount(CanvasOutline, {
    props: { fields, schema, selected },
    global: { stubs: { VueDraggable: { template: '<div><slot /></div>' } } },
  })
}

describe('the canvas outline', () => {
  it('renders every block as a row and an empty slot as a droppable row', () => {
    const w = mountOutline()
    expect(w.find('[data-test="canvas-outline-item-x"]').exists()).toBe(true)
    expect(w.find('[data-test="canvas-outline-slot-e-content"]').text()).toContain(
      'content (empty)',
    )
  })

  it('a drag end resolves to the zone of the row the block now precedes, or an empty slot', () => {
    const w = mountOutline()
    const vm = w.vm as unknown as {
      onDragEnd: (field: string, e: { item: HTMLElement; newIndex?: number }) => void
    }
    const item = (id: string) => {
      const el = document.createElement('div')
      el.dataset.outlineId = id
      return el
    }
    // Rows without a: [s, x, e, e:content(empty)]; landing at 1 = before x.
    vm.onDragEnd('body', { item: item('a'), newIndex: 1 })
    expect(w.emitted('drop')?.[0]).toEqual(['a', { parent: 's', slot: 'content', index: 0 }])
    // Landing at 3 = on the empty slot row: into e.content.
    vm.onDragEnd('body', { item: item('a'), newIndex: 3 })
    expect(w.emitted('drop')?.[1]).toEqual(['a', { parent: 'e', slot: 'content', index: 0 }])
    // Landing past the last row: after the last block row.
    vm.onDragEnd('body', { item: item('a'), newIndex: 4 })
    expect(w.emitted('drop')?.[2]).toEqual(['a', { parent: null, slot: 'body', index: 3 }])
  })

  it('a row click emits its modifiers and every selected id is highlighted', async () => {
    const w = mount(CanvasOutline, {
      props: { fields, schema, selected: 'a', selectedIds: ['a', 'x'] },
      global: { stubs: { VueDraggable: { template: '<div><slot /></div>' } } },
    })
    expect(w.find('[data-test="canvas-outline-item-a"]').classes()).toContain('bg-elevated')
    expect(w.find('[data-test="canvas-outline-item-x"]').classes()).toContain('bg-elevated')
    expect(w.find('[data-test="canvas-outline-item-s"]').classes()).not.toContain('bg-elevated')
    await w.find('[data-test="canvas-outline-item-x"]').trigger('click', { shiftKey: true })
    await w.find('[data-test="canvas-outline-item-x"]').trigger('click', { metaKey: true })
    await w.find('[data-test="canvas-outline-item-x"]').trigger('click')
    expect(w.emitted('select')).toEqual([
      ['x', { shift: true, meta: false }],
      ['x', { shift: false, meta: true }],
      ['x', { shift: false, meta: false }],
    ])
  })

  it('a two-column columns block shows no col_3 row; a three-column one does', () => {
    const cols = (layout: string) => ({
      fields: {
        body: [
          {
            id: 'c',
            type: 'columns',
            data: { layout, col_1: [], col_2: [], col_3: [] },
            settings: {},
          },
        ],
      },
      schema,
      selected: null,
    })
    const two = mount(CanvasOutline, {
      props: cols('2'),
      global: { stubs: { VueDraggable: { template: '<div><slot /></div>' } } },
    })
    expect(two.find('[data-test="canvas-outline-slot-c-col_2"]').exists()).toBe(true)
    expect(two.find('[data-test="canvas-outline-slot-c-col_3"]').exists()).toBe(false)
    const three = mount(CanvasOutline, {
      props: cols('3'),
      global: { stubs: { VueDraggable: { template: '<div><slot /></div>' } } },
    })
    expect(three.find('[data-test="canvas-outline-slot-c-col_3"]').exists()).toBe(true)
  })

  it('an empty-slot row is a button that asks to insert into that slot', async () => {
    const w = mountOutline()
    const row = w.find('[data-test="canvas-outline-slot-e-content"]')
    expect(row.element.tagName).toBe('BUTTON')
    await row.trigger('click')
    expect(w.emitted('insertRequest')?.[0]).toEqual(['e', 'content'])
  })

  it('M opens Move to… for the selected block', async () => {
    const w = mountOutline('a')
    await w.find('[data-test="canvas-outline"]').trigger('keydown', { key: 'm' })
    expect(w.emitted('moveTo')?.[0]).toEqual(['a'])
  })
})

describe('the Move to… dialog', () => {
  const types: SlotTypeSummary[] = [
    { slug: 'section', label: 'Section', slots: { content: { blockTypes: [] } } },
    { slug: 'heading', label: 'Heading', slots: {} },
  ]
  const legality: LegalityContext = {
    regionsOf: (slug) => (slug === 'section' ? ['content'] : []),
    blockTypes: () => types,
    rootSlots: () => ({ body: { blockTypes: [] } }),
    maxDepth: 5,
  }

  it('lists only destinations the block may legally move to and confirms a zone', async () => {
    const w = mount(MoveToDialog, {
      props: { open: true, blockId: 's', doc: { fields }, legality },
      global: {
        stubs: { UModal: { template: '<div><slot name="body" /><slot name="footer" /></div>' } },
      },
    })
    const vm = w.vm as unknown as {
      destinations: { key: string }[]
      destinationKey: string | null
      position: number
      confirm: () => void
    }
    // The section cannot move into its own slot; the root and the other section's slot remain.
    expect(vm.destinations.map((d) => d.key)).toEqual(['root:body', 'e:content'])
    vm.destinationKey = 'e:content'
    vm.position = 0
    await w.vm.$nextTick()
    vm.confirm()
    expect(w.emitted('confirm')?.[0]).toEqual([{ parent: 'e', slot: 'content', index: 0 }])
  })
})
