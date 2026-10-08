import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { FieldOption } from '@/queries/fieldOptions'

// A string field whose choices come from the server (search block spec §3.9): available choices are
// offered, unavailable ones are shown disabled with why, and a stored value is never rewritten —
// not while loading, not after a failure, not when its feature is off or gone.
const options = ref<FieldOption[] | undefined>(undefined)
const status = ref<'pending' | 'success' | 'error'>('success')
vi.mock('@/queries/fieldOptions', () => ({
  useFieldOptions: () => ({ data: options, status }),
}))

import OptionsSourceField from '@/fields/components/OptionsSourceField.vue'
import { ALL, optionItems } from '@/fields/optionsSourceItems'

const SCOPES: FieldOption[] = [
  { value: '', label: 'All results', available: true, reason: null },
  { value: 'entries', label: 'Pages & posts', available: true, reason: null },
  { value: 'products', label: 'Products', available: false, reason: 'Requires Commerce' },
]
const field = { name: 'scope', type: 'string' as const, optionsSource: 'thallo-search.scopes' }
const mountField = (modelValue: string) =>
  mount(OptionsSourceField, { props: { field, modelValue } })

describe('a field with server-provided choices', () => {
  beforeEach(() => {
    options.value = SCOPES
    status.value = 'success'
  })

  it('offers the choices, an unavailable one disabled with its reason', () => {
    const items = optionItems(SCOPES)
    expect(items.map((i) => i.label)).toEqual([
      'All results',
      'Pages & posts',
      'Products (requires commerce)',
    ])
    expect(items.find((i) => i.value === 'products')?.disabled).toBe(true)
    expect(items[0]?.value).toBe(ALL)
    const wrapper = mountField('')
    expect(wrapper.find('[data-test="options-source-scope"]').exists()).toBe(false)
  })

  it('keeps a stored choice that is now unavailable, says why, and changes nothing', () => {
    const wrapper = mountField('products')
    const notice = wrapper.get('[data-test="options-source-scope"]')
    expect(notice.text()).toContain('Products')
    expect(notice.text()).toContain('requires commerce')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('says when a stored choice is no longer provided at all', () => {
    const wrapper = mountField('reviews')
    expect(wrapper.get('[data-test="options-source-scope"]').text()).toContain(
      'is no longer provided by any installed feature',
    )
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('while loading or after a failure, shows the stored value and never replaces it with All results', () => {
    for (const s of ['pending', 'error'] as const) {
      status.value = s
      options.value = undefined
      const wrapper = mountField('products')
      expect(wrapper.get('[data-test="options-source-scope"]').text()).toContain(
        'Couldn’t load the choices',
      )
      expect(wrapper.get('[data-test="options-source-scope"]').text()).toContain('products')
      expect(wrapper.findComponent({ name: 'SelectRoot' }).exists()).toBe(false)
      expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    }
  })

  it('a choice made emits the stored value, and All results stores the empty scope', async () => {
    const wrapper = mountField('entries')
    const select = wrapper.findComponent({ name: 'SelectRoot' })
    await select.vm.$emit('update:modelValue', 'products')
    await select.vm.$emit('update:modelValue', ALL)
    expect(wrapper.emitted('update:modelValue')).toEqual([['products'], ['']])
  })
})

// Product grid spec §5.3: a `multiple` options-source field is a multi-select whose value is a list,
// at most `maxItems`, and a stored value that is no longer a choice is kept and shown unavailable.
const CATEGORIES: FieldOption[] = [
  { value: 'men', label: 'Men', available: true, reason: null },
  { value: 'women', label: 'Women', available: true, reason: null },
]
// The multi-select stands in as a named stub, as tenantSwitcher.spec.ts does.
const USelectMenu = {
  name: 'USelectMenu',
  props: {
    modelValue: { type: Array, default: undefined },
    items: { type: Array, default: () => [] },
    multiple: Boolean,
    valueKey: { type: String, default: undefined },
    loading: Boolean,
  },
  emits: ['update:modelValue'],
  template: '<div />',
}
const mountMulti = (extra: Record<string, unknown>, modelValue: unknown) =>
  mount(OptionsSourceField, {
    global: { stubs: { SelectMenu: USelectMenu } },
    props: {
      field: {
        name: 'categories',
        type: 'string' as const,
        optionsSource: 'thallo-commerce.categories',
        multiple: true,
        ...extra,
      },
      modelValue,
    },
  })

describe('a multiple options-source field', () => {
  beforeEach(() => {
    options.value = CATEGORIES
    status.value = 'success'
  })

  it('selects several values and emits the list', async () => {
    const w = mountMulti({}, ['men'])
    const select = w.findComponent({ name: 'USelectMenu' })
    expect(select.props('multiple')).toBe(true)
    expect(select.props('modelValue')).toEqual(['men'])
    await select.vm.$emit('update:modelValue', ['men', 'women'])
    const emitted = w.emitted('update:modelValue') ?? []
    expect(emitted[emitted.length - 1]).toEqual([['men', 'women']])
  })

  it('keeps a stored value that is no longer an option and shows it unavailable', () => {
    const w = mountMulti({}, ['gone', 'men'])
    expect(w.find('[data-test="options-source-categories-gone"]').exists()).toBe(true)
    expect(w.findComponent({ name: 'USelectMenu' }).props('modelValue')).toEqual(['gone', 'men'])
  })

  it('never emits more than maxItems and says so at the limit', async () => {
    const twenty = Array.from({ length: 20 }, (_, i) => `c${i}`)
    const w = mountMulti({ maxItems: 20 }, twenty)
    expect(w.find('[data-test="options-source-limit-categories"]').exists()).toBe(true)
    await w.findComponent({ name: 'USelectMenu' }).vm.$emit('update:modelValue', [...twenty, 'c20'])
    const emitted = (w.emitted('update:modelValue') ?? []) as string[][][]
    expect(emitted[emitted.length - 1]?.[0]).toHaveLength(20)
  })

  it('reads a non-list value as no selection', () => {
    const w = mountMulti({}, 'men')
    expect(w.findComponent({ name: 'USelectMenu' }).props('modelValue')).toEqual([])
  })
})
