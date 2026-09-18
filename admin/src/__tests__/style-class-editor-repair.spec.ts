// "Open class" sends an author here to repair a layout the contract no longer offers (container-
// layout spec §11.1). The editor's one tab filters to style paths, so without this group the page
// could not show the value, let alone fix it. The repair path only — editing layout in a class
// generally is not part of this.
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

vi.mock('@/queries/styleSchema', () => ({
  useStyleSchema: () => ({ data: ref({ version: 3, properties: [], vocabulary: {} }) }),
}))

import StyleClassEditor from '@/pages/settings/style-classes/components/StyleClassEditor.vue'

const choice = (value: string) => ({ type: 'choice', value })
const mountEditor = (style: Record<string, unknown>) =>
  mount(StyleClassEditor, {
    props: { modelValue: style },
    global: { stubs: { StyleTab: true } },
  })
const lastStyle = (w: ReturnType<typeof mountEditor>) => {
  const events = w.emitted('update:modelValue') ?? []
  return events[events.length - 1]![0] as Record<string, unknown>
}

describe('the class editor repairs what it stores', () => {
  const stale = { layout: { display: { base: choice('flex'), md: choice('block') } } }

  it('names the invalid value and the breakpoint it sits at', () => {
    const w = mountEditor(stale)
    const group = w.find('[data-test="style-class-needs-attention"]')
    expect(group.exists()).toBe(true)
    const notice = w.find('[data-test="invalid-choice-layout.display"]')
    expect(notice.text()).toContain('block')
    expect(notice.text()).toContain('md')
  })

  it('Replace leaves a style the endpoint accepts: flex at md, no block anywhere', async () => {
    const w = mountEditor(stale)
    await w.find('[data-test="invalid-choice-replace"]').trigger('click')
    expect(lastStyle(w)).toEqual({
      layout: { display: { base: choice('flex'), md: choice('flex') } },
    })
    expect(JSON.stringify(lastStyle(w))).not.toContain('block')
  })

  it('Remove deletes that one declaration and keeps the rest', async () => {
    const w = mountEditor(stale)
    await w.find('[data-test="invalid-choice-remove"]').trigger('click')
    expect(lastStyle(w)).toEqual({ layout: { display: { base: choice('flex') } } })
  })

  it('repairs each invalid declaration independently, a non-responsive one included', async () => {
    const w = mountEditor({
      layout: { display: { md: choice('block') }, overflow: choice('scroll') },
    })
    expect(w.findAll('[data-test^="invalid-choice-layout."]')).toHaveLength(2)
    // Overflow has no designated replacement: Remove is the only way out, and it removes the
    // bare value — a non-responsive property is not stored under a breakpoint.
    const overflow = w.find('[data-test="invalid-choice-layout.overflow"]')
    expect(overflow.find('[data-test="invalid-choice-replace"]').exists()).toBe(false)
    await overflow.find('[data-test="invalid-choice-remove"]').trigger('click')
    expect(lastStyle(w)).toEqual({ layout: { display: { md: choice('block') } } })
  })

  it('shows no group for a class whose choices are all offered', () => {
    const w = mountEditor({ layout: { display: { base: choice('grid') } } })
    expect(w.find('[data-test="style-class-needs-attention"]').exists()).toBe(false)
  })
})
