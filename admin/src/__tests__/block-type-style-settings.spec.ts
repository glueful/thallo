// A block type made in the admin can be given style settings: the admin chooses which SETTING
// GROUPS the block supports, from the list the SERVER offers, and the block's template emits
// them on its outermost element. A code-declared type's groups are shown, never offered for edit.
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import BlockTypeStyleSettings from '@/pages/settings/block-types/components/BlockTypeStyleSettings.vue'

const OPTIONS = ['spacing', 'colors', 'radius', 'layout.item', 'brand-new-group']

// The template row renders a link, which needs a router.
const router = createRouter({
  history: createMemoryHistory(),
  routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
})

function mountPicker(props: Record<string, unknown>) {
  return mount(BlockTypeStyleSettings, {
    props: { slug: 'promo', options: OPTIONS, modelValue: [], ...props } as never,
    global: { plugins: [router] },
  })
}
/** The last `update:modelValue` payload. */
const lastEmitted = (w: ReturnType<typeof mountPicker>) => {
  const all = w.emitted('update:modelValue')!
  return all[all.length - 1]
}
const box = (w: ReturnType<typeof mountPicker>, group: string) =>
  w.find(`[data-test="style-group-option-${group}"] input[type="checkbox"]`)

describe('a block type’s style settings', () => {
  it('offers the server’s groups, named for people — and an unknown one by its own name', () => {
    const w = mountPicker({})
    expect(w.findAll('[data-test^="style-group-option-"]')).toHaveLength(OPTIONS.length)
    expect(w.find('[data-test="style-group-option-spacing"]').text()).toContain('Spacing')
    expect(w.find('[data-test="style-group-option-layout.item"]').text()).toContain(
      'Sizing in a parent layout',
    )
    // The server may learn a group before this page does: it is still offered.
    expect(w.find('[data-test="style-group-option-brand-new-group"]').text()).toContain(
      'brand-new-group',
    )
  })

  it('emits the chosen groups in the SERVER’S order, whatever order they were ticked in', async () => {
    const w = mountPicker({ modelValue: ['radius'] })
    expect((box(w, 'radius').element as HTMLInputElement).checked).toBe(true)
    await box(w, 'spacing').setValue(true)
    expect(lastEmitted(w)).toEqual([['spacing', 'radius']])
    await w.setProps({ modelValue: ['spacing', 'radius'] })
    await box(w, 'radius').setValue(false)
    expect(lastEmitted(w)).toEqual([['spacing']])
  })

  it('says what the template has to emit, with the block’s own template name, once any is chosen', async () => {
    const none = mountPicker({})
    expect(none.find('[data-test="style-template-hint"]').exists()).toBe(false)

    const some = mountPicker({ modelValue: ['spacing'] })
    const hint = some.find('[data-test="style-template-hint"]').text()
    expect(hint).toContain('blocks/promo.twig')
    expect(hint).toContain("{{ style_classes('root') }}")
    expect(hint).toContain("{{ style_attrs('root') }}")
    expect(hint).toContain('Saving is refused until it does')

    // A type being created has no template yet: nothing is refused, and the hint does not say so.
    const creating = mountPicker({ modelValue: ['spacing'], creating: true })
    const newHint = creating.find('[data-test="style-template-hint"]').text()
    expect(newHint).toContain('When you write the block’s template')
    expect(newHint).not.toContain('Saving is refused')
  })

  it('shows a code-declared type’s groups read-only, with the reason', () => {
    const w = mountPicker({ modelValue: ['spacing', 'colors'], codeDeclared: true })
    expect(w.find('[data-test="style-code-declared"]').text()).toContain('declared by Thallo')
    for (const input of w.findAll('input[type="checkbox"]')) {
      expect((input.element as HTMLInputElement).disabled).toBe(true)
    }
  })

  it('shows the server’s refusal where the choice was made', () => {
    const w = mountPicker({ modelValue: ['spacing'], error: 'blocks/promo.twig does not emit…' })
    expect(w.find('[data-test="style-settings-error"]').text()).toContain('does not emit')
  })

  it('links a saved block type to its template in the Theme editor', async () => {
    // The template could not be started from the admin; the Theme editor opens it, or a starter.
    const w = mountPicker({})
    const link = w.find('[data-test="block-template-open"]')
    expect(link.exists()).toBe(true)
    expect(link.attributes('href')).toBe('/templates?path=blocks/promo.twig')

    const creating = mountPicker({ creating: true })
    expect(creating.find('[data-test="block-template-open"]').exists()).toBe(false)
  })
})
