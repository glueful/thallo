import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import JsonField from '@/fields/components/JsonField.vue'
import type { FieldDef } from '@/fields/types'

// A links block's items are JSON underneath — [{ label, url, icon?, active? }] — and a list of
// links on screen: a row per link, added, removed and moved without writing JSON by hand.
const field = (format?: FieldDef['format']): FieldDef => ({ name: 'items', type: 'json', format })
const mountField = (value: unknown, format: FieldDef['format'] = 'link-list') =>
  mount(JsonField, { props: { field: field(format), modelValue: value } })
const last = (w: ReturnType<typeof mountField>) =>
  w.emitted('update:modelValue')!.slice(-1)[0]![0] as Record<string, unknown>[]

describe('a link-list json field', () => {
  it('is rows of label and URL, not a JSON text box', () => {
    const w = mountField([
      { label: 'Design view', url: '/docs/design-view' },
      { label: 'Themes', url: '/docs/themes' },
    ])
    expect(w.find('textarea').exists()).toBe(false)
    const rows = w.findAll('[data-test="link-row"]')
    expect(rows).toHaveLength(2)
    const [label, url] = rows[1]!.findAll('input')
    expect((label!.element as HTMLInputElement).value).toBe('Themes')
    expect((url!.element as HTMLInputElement).value).toBe('/docs/themes')
  })

  it('edits a link in place, keeping what the row does not show', async () => {
    const w = mountField([{ label: 'Docs', url: '/docs', icon: 'book', active: true }])
    await w.findAll('[data-test="link-row"] input')[0]!.setValue('Documentation')
    expect(last(w)).toEqual([{ label: 'Documentation', url: '/docs', icon: 'book', active: true }])
  })

  it('a link can open in a new tab, as a menu item can', async () => {
    const w = mountField([
      { label: 'GitHub', url: 'https://github.com/glueful/thallo', icon: 'code' },
    ])
    const toggle = w.find('[data-test="link-new-tab"]')
    expect(toggle.attributes('aria-pressed')).toBe('false')
    await toggle.trigger('click')
    expect(last(w)).toEqual([
      { label: 'GitHub', url: 'https://github.com/glueful/thallo', icon: 'code', new_tab: true },
    ])

    // Switched off, the link carries no flag at all: it opens where it is, the default.
    await w.setProps({ modelValue: last(w) })
    expect(w.find('[data-test="link-new-tab"]').attributes('aria-pressed')).toBe('true')
    await w.find('[data-test="link-new-tab"]').trigger('click')
    expect(last(w)).toEqual([
      { label: 'GitHub', url: 'https://github.com/glueful/thallo', icon: 'code' },
    ])
  })

  it('adds, moves and removes links', async () => {
    const w = mountField([{ label: 'A', url: '/a' }])
    await w.find('[data-test="link-add"]').trigger('click')
    expect(last(w)).toEqual([
      { label: 'A', url: '/a' },
      { label: '', url: '' },
    ])

    await w.setProps({
      modelValue: [
        { label: 'A', url: '/a' },
        { label: 'B', url: '/b' },
      ],
    })
    await w.findAll('[data-test="link-up"]')[1]!.trigger('click')
    expect(last(w)).toEqual([
      { label: 'B', url: '/b' },
      { label: 'A', url: '/a' },
    ])

    await w.setProps({
      modelValue: [
        { label: 'B', url: '/b' },
        { label: 'A', url: '/a' },
      ],
    })
    await w.findAll('[data-test="link-remove"]')[0]!.trigger('click')
    expect(last(w)).toEqual([{ label: 'A', url: '/a' }])
  })

  it('starts empty with nothing but an Add button', () => {
    const w = mountField(undefined)
    expect(w.findAll('[data-test="link-row"]')).toHaveLength(0)
    expect(w.find('[data-test="link-add"]').exists()).toBe(true)
  })
})

describe('a plain json field', () => {
  it('is still the JSON text box', () => {
    const w = mount(JsonField, { props: { field: field(), modelValue: { a: 1 } } })
    expect(w.find('textarea').exists()).toBe(true)
    expect(w.find('[data-test="link-list"]').exists()).toBe(false)
  })
})
