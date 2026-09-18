// The style class editor as a whole (container-layout spec §12): what its tabs say about the
// class's declarations, and — the editor's half of §12.5 — that what it emits differs from what
// it was given only where the author made a change.
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { classEditorSchema } from './helpers/classEditorSchema'

vi.mock('@/queries/styleSchema', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/styleSchema')>()),
  useStyleSchema: () => ({ data: ref(classEditorSchema()) }),
}))

import StyleClassEditor from '@/pages/settings/style-classes/components/StyleClassEditor.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'

const token = (value: string) => ({ type: 'token', value })
const RESET = { type: 'reset' }

function mountEditor(style: Record<string, unknown>) {
  localStorage.clear()
  resetFolds()
  return mount(StyleClassEditor, { props: { modelValue: style }, attachTo: document.body })
}

describe('the class Style tab says what the class declares', () => {
  it('an untouched property is not set in this class — never "theme"', () => {
    const w = mountEditor({})
    const states = w.findAll('[data-test="style-state"]').map((s) => s.text())
    expect(states.length).toBeGreaterThan(0)
    expect(states).not.toContain('theme')
    const typography = w.find('[data-test="style-field-typography.size"] [data-test="style-state"]')
    expect(typography.text()).toBe('Not set in this class')
    w.unmount()
  })

  it('offers Remove and Use theme default, and neither of the block inspector actions', () => {
    const w = mountEditor({ shadow: { base: token('shadow.md') } })
    const row = w.find('[data-test="style-field-shadow"]')
    expect(row.find('[data-test="style-state"]').text()).toBe('Set')
    expect(row.find('[data-test="style-remove"]').exists()).toBe(true)
    expect(row.find('[data-test="style-use-theme-default"]').exists()).toBe(true)
    expect(w.find('[data-test="style-reset"]').exists()).toBe(false)
    expect(w.find('[data-test="style-clear"]').exists()).toBe(false)
    w.unmount()
  })

  it('a non-responsive style property applies at all sizes', () => {
    const w = mountEditor({ radius: RESET })
    const row = w.find('[data-test="style-field-radius"]')
    expect(row.find('[data-test="style-all-sizes"]').text()).toBe('Applies at all sizes')
    expect(row.find('[data-test="style-state"]').text()).toBe('Theme default, set here')
    w.unmount()
  })
})
