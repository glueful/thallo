import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

// A 422 on save ("body: is required") used to be a toast only; the empty field stayed unmarked.
// FieldEditor takes the per-field messages and renders each one under its field.

vi.mock('@/fields/registry', () => ({
  fieldComponent: () =>
    defineComponent({
      props: { field: { type: Object, required: true }, modelValue: null },
      setup: (props) => () => h('div', { 'data-test': `stub-${(props.field as { name: string }).name}` }),
    }),
}))

import FieldEditor from '@/components/FieldEditor.vue'

describe('FieldEditor errors', () => {
  it('renders each field error under its field and nothing for clean fields', () => {
    const wrapper = mount(FieldEditor, {
      props: {
        schema: [
          { name: 'title', type: 'string' },
          { name: 'body', type: 'blocks' },
        ],
        modelValue: {},
        errors: { body: 'is required' },
      },
    })

    const bodyError = wrapper.find('[data-test="field-error-body"]')
    expect(bodyError.exists()).toBe(true)
    expect(bodyError.text()).toContain('is required')
    expect(wrapper.find('[data-test="field-error-title"]').exists()).toBe(false)
  })
})
