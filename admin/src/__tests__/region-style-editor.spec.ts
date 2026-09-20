// A chrome region's Style tab: the block inspector's Style tab over a region's own style record,
// offering what the SERVER says a region may be styled with. A region is not a block: it has no
// style classes, so nothing offers to save one.
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { classEditorSchema } from './helpers/classEditorSchema'

vi.mock('@/queries/styleSchema', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/styleSchema')>()),
  useStyleSchema: () => ({ data: ref(classEditorSchema()) }),
}))

import RegionStyleEditor from '@/pages/regions/components/RegionStyleEditor.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'

type Style = Record<string, unknown>
const CAPS = ['spacing', 'shadow', 'radius', 'colors', 'border', 'backdrop']
const token = (value: string) => ({ type: 'token', value })
const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

/** Mounted as the page mounts it: every emission is fed back as the prop, a tick later. */
function mountEditor(style: Style, breakpoint = 'lg') {
  localStorage.clear()
  resetFolds()
  const emissions: Style[] = []
  const w = mount(RegionStyleEditor, {
    props: {
      region: 'header',
      modelValue: style,
      capabilities: CAPS,
      activeBreakpoint: breakpoint,
      'onUpdate:modelValue': (next: Style) => {
        emissions.push(clone(next))
        void w.setProps({ modelValue: next })
      },
    } as never,
    attachTo: document.body,
  })
  return Object.assign(w, { emissions, last: () => emissions[emissions.length - 1] })
}

describe('a region’s Style tab', () => {
  it('offers what the server declared for a region, and nothing else', () => {
    const w = mountEditor({})
    for (const group of ['spacing', 'colors', 'effects']) {
      expect(w.find(`[data-test="style-group-${group}"]`).exists(), group).toBe(true)
    }
    for (const path of ['border.sides', 'colors.surface_opacity', 'backdrop.blur', 'shadow']) {
      expect(w.find(`[data-test="style-field-${path}"]`).exists(), path).toBe(true)
    }
    for (const group of ['typography', 'visibility', 'text', 'marker', 'tabs']) {
      expect(w.find(`[data-test="style-group-${group}"]`).exists(), group).toBe(false)
    }
    // A region has no style classes to save to.
    expect(w.find('[data-test="save-as-style-class"]').exists()).toBe(false)
    w.unmount()
  })

  it('writes a responsive value at the breakpoint being edited, and a bare one bare', async () => {
    const w = mountEditor({}, 'md')
    await w.find('[data-test="style-field-shadow"] [data-test="token-shadow.md"]').trigger('click')
    await flushPromises()
    await w
      .find('[data-test="style-field-colors.surface_opacity"] [data-test="choice-80"]')
      .trigger('click')
    await flushPromises()
    expect(w.last()).toEqual({
      shadow: { md: token('shadow.md') },
      colors: { surface_opacity: { type: 'choice', value: '80' } },
    })
    w.unmount()
  })

  it('linked padding: one click writes all four sides and all four survive', async () => {
    const w = mountEditor({ radius: token('radius.lg') }, 'base')
    const padding = w.find('[data-test="box-padding"]')
    await padding.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
    await padding.find('[data-test="box-panel"] [data-test="token-spacing.xl"]').trigger('click')
    await flushPromises()
    const side = { base: token('spacing.xl') }
    expect(w.last()).toEqual({
      radius: token('radius.lg'),
      spacing: { padding: { top: side, right: side, bottom: side, left: side } },
    })
    w.unmount()
  })

  it('removing the last value leaves an empty record, which the server stores as no style', async () => {
    const w = mountEditor({ radius: token('radius.lg') })
    await w.find('[data-test="style-field-radius"] [data-test="style-clear"]').trigger('click')
    await flushPromises()
    expect(w.last()).toEqual({})
    w.unmount()
  })
})
