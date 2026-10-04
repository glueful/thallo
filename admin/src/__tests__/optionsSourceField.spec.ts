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
