import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ColumnsLayoutField from '@/fields/components/blocks/ColumnsLayoutField.vue'

const PRESETS = ['50-50', '33-67', '67-33', '33-33-33', '25-50-25']

describe('ColumnsLayoutField', () => {
  it('emits BOTH layout (segment count) and widths when a preset is chosen', async () => {
    const wrapper = mount(ColumnsLayoutField, {
      props: { layout: '2', widths: '50-50', presets: PRESETS },
    })
    await wrapper.find('[data-test="columns-preset-25-50-25"]').trigger('click')
    expect(wrapper.emitted('select')?.[0]).toEqual([{ layout: '3', widths: '25-50-25' }])
  })

  it('marks the current ratio as selected', () => {
    const wrapper = mount(ColumnsLayoutField, {
      props: { layout: '2', widths: '33-67', presets: PRESETS },
    })
    expect(wrapper.find('[data-test="columns-preset-33-67"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('[data-test="columns-preset-50-50"]').attributes('aria-pressed')).toBe('false')
  })

  it('falls back to the equal split for the current layout when no ratio is set', () => {
    const wrapper = mount(ColumnsLayoutField, {
      props: { layout: '3', widths: '', presets: PRESETS },
    })
    expect(wrapper.find('[data-test="columns-preset-33-33-33"]').attributes('aria-pressed')).toBe('true')
  })
})
