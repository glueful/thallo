import { describe, it, expect } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import JsonField from '@/fields/components/JsonField.vue'

// The MODEL carries PARSED json (FieldValidator rejects raw strings for json
// fields); the textarea is only a buffer. Invalid text never emits.
describe('JsonField', () => {
  const field = { name: 'params', type: 'json' as const }

  it('emits the parsed object for valid JSON and nothing for invalid text', async () => {
    const wrapper = mount(JsonField, { props: { field, modelValue: undefined } })
    const textarea = wrapper.find('textarea')

    await textarea.setValue('{"name": "Acme Co"}')
    await flushPromises()
    const emitted = wrapper.emitted('update:modelValue')!
    expect(emitted[emitted.length - 1]![0]).toEqual({ name: 'Acme Co' })

    await textarea.setValue('{"name": ')
    await flushPromises()
    // No new emission for the invalid buffer; the error shows instead.
    expect(wrapper.emitted('update:modelValue')!.length).toBe(emitted.length)
    expect(wrapper.text()).toContain('Invalid JSON.')
    wrapper.unmount()
  })

  it('renders an existing object back as pretty JSON and clears to undefined', async () => {
    const wrapper = mount(JsonField, { props: { field, modelValue: { since: '2020' } } })
    const textarea = wrapper.find('textarea')
    expect((textarea.element as HTMLTextAreaElement).value).toContain('"since": "2020"')

    await textarea.setValue('')
    await flushPromises()
    const emitted = wrapper.emitted('update:modelValue')!
    expect(emitted[emitted.length - 1]![0]).toBeUndefined()
    wrapper.unmount()
  })

  it('shows a legacy raw-string value verbatim (self-heals on next valid edit)', () => {
    const wrapper = mount(JsonField, { props: { field, modelValue: '{"x": 1}' } })
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('{"x": 1}')
    wrapper.unmount()
  })
})
