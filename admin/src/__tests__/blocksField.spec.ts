import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'

const blockTypes = ref<BlockType[]>([])

vi.mock('@/queries/blockTypes', () => ({
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

  it('groups the picker by category with uncategorized under Other, last', async () => {
    const wrapper = mount(BlocksField, { props: { field, modelValue: [] } })
    await flushPromises()
    await wrapper.find('[data-test="add-block"]').trigger('click')
    // hero is "Layout"; quote is uncategorized → "Other". Named categories first.
    const layout = wrapper.find('[data-test="picker-group-Layout"]')
    const other = wrapper.find('[data-test="picker-group-Other"]')
    expect(layout.exists()).toBe(true)
    expect(other.exists()).toBe(true)
    const html = wrapper.find('[data-test="block-picker"]').html()
    expect(html.indexOf('picker-group-Layout')).toBeLessThan(html.indexOf('picker-group-Other'))
  })

  it('shows an inactive badge for blocks whose type was deactivated', async () => {
    const wrapper = mount(BlocksField, {
      props: { field, modelValue: [{ id: 'z', type: 'legacy', data: {} }] },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="block-inactive-z"]').exists()).toBe(true)
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
})
