// The style class editor as a whole (container-layout spec §12): what its tabs say about the
// class's declarations, and — the editor's half of §12.5 — that what it emits differs from what
// it was given only where the author made a change.
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { classEditorSchema } from './helpers/classEditorSchema'

vi.mock('@/queries/styleSchema', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/styleSchema')>()),
  useStyleSchema: () => ({ data: ref(classEditorSchema()) }),
}))

import StyleClassEditor from '@/pages/settings/style-classes/components/StyleClassEditor.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'

const choice = (value: string) => ({ type: 'choice', value })
const token = (value: string) => ({ type: 'token', value })
const RESET = { type: 'reset' }

type Style = Record<string, unknown>
/** The payloads shared with the PHP suite: what is proven emitted here is proven accepted there. */
const PAYLOADS = JSON.parse(
  readFileSync(
    join(__dirname, '../../../tests/fixtures/style-classes/editor-payloads.json'),
    'utf8',
  ),
) as Record<
  'valid' | 'bare_reset' | 'invalid_value' | 'wrapped_non_responsive' | 'unknown_path',
  Style
>
const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

/**
 * Mounted as the page mounts it — `v-model`: every emission is fed back as the prop, a tick later,
 * exactly as a parent's ref would. One click that writes two declarations has to survive that.
 */
function mountEditor(style: Style) {
  localStorage.clear()
  resetFolds()
  const emissions: Style[] = []
  const w = mount(StyleClassEditor, {
    props: {
      modelValue: style,
      'onUpdate:modelValue': (next: Style) => {
        emissions.push(clone(next))
        void w.setProps({ modelValue: next })
      },
    },
    attachTo: document.body,
  })
  return Object.assign(w, { emissions, last: () => emissions[emissions.length - 1] })
}
type Editor = ReturnType<typeof mountEditor>

async function openTab(w: Editor, name: 'Style' | 'Layout') {
  const tab = w
    .find('[data-test="style-class-tabs"]')
    .findAll('button[role="tab"]')
    .find((b) => b.text() === name)!
  await tab.trigger('mousedown', { button: 0 })
  await tab.trigger('click')
  await flushPromises()
}
const layoutPanel = (w: Editor) => w.find('[data-test="class-layout-tab"]')

/**
 * A class with something of everything: the shared `valid` payload, both mode families, a style
 * property at three breakpoints, differing gaps, and a path this editor has never heard of.
 */
function everything(): Style {
  const style = clone(PAYLOADS.valid) as { layout: Style } & Style
  Object.assign(style.layout, {
    direction: { base: choice('row'), lg: choice('column') },
    wrap: { md: choice('wrap') },
    gap: { column: { base: token('spacing.sm') }, row: { base: token('spacing.lg') } },
    ...(PAYLOADS.unknown_path.layout as Style),
  })
  style.spacing = {
    padding: { top: { base: token('spacing.sm'), md: token('spacing.lg'), lg: RESET } },
  }
  return style
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

describe('two tabs, one class', () => {
  it('opens on Style, offers Layout, and keeps each property on one tab only', async () => {
    const w = mountEditor({})
    const tabs = w.find('[data-test="style-class-tabs"]').findAll('button[role="tab"]')
    expect(tabs.map((t) => t.text())).toEqual(['Style', 'Layout'])
    expect(tabs[0]!.attributes('aria-selected')).toBe('true')

    await openTab(w, 'Layout')
    // A layout property is on the Layout tab and nowhere else; a style property the reverse.
    expect(w.findAll('[data-test="style-field-width"]')).toHaveLength(1)
    expect(layoutPanel(w).find('[data-test="style-field-width"]').exists()).toBe(true)
    expect(w.findAll('[data-test="style-field-shadow"]')).toHaveLength(1)
    expect(layoutPanel(w).find('[data-test="style-field-shadow"]').exists()).toBe(false)
    w.unmount()
  })

  it('the breakpoint is shared: chosen on one tab, it is the one being edited on the other', async () => {
    const w = mountEditor({ layout: { direction: { md: choice('row') } } })
    await openTab(w, 'Layout')
    await w.find('[data-test="class-layout-breakpoint-md"]').trigger('click')
    expect(
      layoutPanel(w)
        .find('[data-test="style-field-layout.direction"] [data-test="style-state"]')
        .text(),
    ).toBe('Set')
    await openTab(w, 'Style')
    expect(w.find('[data-test="group-breakpoint-md"]').attributes('aria-pressed')).toBe('true')
    w.unmount()
  })

  it('says, on both tabs and not dismissibly, that a declaration applies only where it is supported', async () => {
    const w = mountEditor({})
    const note = () => w.find('[data-test="style-class-capability-note"]')
    const words =
      'A declaration applies only to blocks that support that property. On a block that does not, it is kept and unused.'
    expect(note().text()).toBe(words)
    expect(note().find('button').exists()).toBe(false)
    await openTab(w, 'Layout')
    expect(note().text()).toBe(words)
    expect(note().isVisible()).toBe(true)
    w.unmount()
  })

  it('keeps the repair path above both tabs', async () => {
    const w = mountEditor(clone(PAYLOADS.invalid_value))
    expect(w.find('[data-test="style-class-needs-attention"]').exists()).toBe(true)
    await openTab(w, 'Layout')
    const group = w.find('[data-test="style-class-needs-attention"]')
    expect(group.isVisible()).toBe(true)
    expect(group.find('[data-test="invalid-choice-replace"]').exists()).toBe(true)
    expect(group.find('[data-test="invalid-choice-remove"]').exists()).toBe(true)
    w.unmount()
  })
})

describe("the editor's output: one edit changes one declaration (spec §12.5)", () => {
  it('looking changes nothing: every tab, every breakpoint, no emission', async () => {
    const w = mountEditor(everything())
    for (const tab of ['Layout', 'Style', 'Layout'] as const) {
      await openTab(w, tab)
      for (const bp of ['md', 'lg', 'base'] as const) {
        const chip = tab === 'Layout' ? `class-layout-breakpoint-${bp}` : `group-breakpoint-${bp}`
        await w.find(`[data-test="${chip}"]`).trigger('click')
      }
    }
    expect(w.emissions).toEqual([])
    w.unmount()
  })

  it('an unlinked gap side: exactly that declaration is added, and nothing else differs', async () => {
    const original = everything()
    const w = mountEditor(clone(original))
    await openTab(w, 'Layout')
    await w.find('[data-test="class-layout-breakpoint-md"]').trigger('click')

    // The sides differ, so the box is already unlinked — asserted, because a linked box writes both.
    const link = layoutPanel(w).find('[data-test="box-link"]')
    expect(link.attributes('aria-pressed')).toBe('false')
    await layoutPanel(w).find('[data-test="box-cell-layout.gap.row"]').trigger('click')
    await layoutPanel(w)
      .find('[data-test="box-panel"] [data-test="token-spacing.xl"]')
      .trigger('click')
    await flushPromises()

    const expected = clone(original) as { layout: { gap: { row: Style } } }
    expected.layout.gap.row.md = token('spacing.xl')
    expect(w.last()).toEqual(expected)
    expect(w.emissions).toHaveLength(1)
    // Said out loud, though the deep comparison already holds them:
    expect((w.last() as typeof expected).layout.gap).toEqual({
      column: { base: token('spacing.sm') },
      row: { base: token('spacing.lg'), md: token('spacing.xl') },
    })
    expect((w.last() as { layout: Style }).layout.nonesuch).toEqual(
      (PAYLOADS.unknown_path.layout as Style).nonesuch,
    )
    w.unmount()
  })

  it('a LINKED gap: both sides are written — the intended edit — and both survive, with everything else', async () => {
    const original = everything()
    ;(original as { layout: { gap: Style } }).layout.gap = {
      column: { base: token('spacing.lg') },
      row: { base: token('spacing.lg') },
    }
    const w = mountEditor(clone(original))
    await openTab(w, 'Layout')
    await w.find('[data-test="class-layout-breakpoint-md"]').trigger('click')
    expect(layoutPanel(w).find('[data-test="box-link"]').attributes('aria-pressed')).toBe('true')
    await layoutPanel(w).find('[data-test="box-cell-layout.gap.row"]').trigger('click')
    await layoutPanel(w)
      .find('[data-test="box-panel"] [data-test="token-spacing.xl"]')
      .trigger('click')
    await flushPromises()

    const expected = clone(original) as { layout: { gap: { row: Style; column: Style } } }
    expected.layout.gap.row.md = token('spacing.xl')
    expected.layout.gap.column.md = token('spacing.xl')
    expect(w.last()).toEqual(expected)
    w.unmount()
  })

  it('linked padding on the Style tab: all four sides are written and all four survive', async () => {
    // One click, four writes, each emitted before the parent has fed the last one back.
    const original = everything()
    delete (original as { spacing?: unknown }).spacing
    const w = mountEditor(clone(original))
    const padding = w.find('[data-test="box-padding"]')
    expect(padding.find('[data-test="box-link"]').attributes('aria-pressed')).toBe('true')
    await padding.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
    await padding.find('[data-test="box-panel"] [data-test="token-spacing.xl"]').trigger('click')
    await flushPromises()
    const side = { base: token('spacing.xl') }
    expect(w.last()).toEqual({
      ...clone(original),
      spacing: { padding: { top: side, right: side, bottom: side, left: side } },
    })
    w.unmount()
  })

  it('the prop is the authority once the tick has passed: an emission the parent did not take is not built on', async () => {
    localStorage.clear()
    resetFolds()
    // A parent that ignores what it is handed — a refused edit, a reload that put the old value back.
    const w = mount(StyleClassEditor, { props: { modelValue: {} }, attachTo: document.body })
    const shadow = () => w.find('[data-test="style-field-shadow"]')
    await shadow().find('[data-test="token-shadow.md"]').trigger('click')
    await flushPromises()
    await w.find('[data-test="style-field-radius"] [data-test="token-radius.md"]').trigger('click')
    const events = w.emitted('update:modelValue')!
    expect(events[events.length - 1]![0]).toEqual({ radius: token('radius.md') }) // no shadow
    w.unmount()
  })

  it('a single-row control, the same: direction at md', async () => {
    const original = everything()
    const w = mountEditor(clone(original))
    await openTab(w, 'Layout')
    await w.find('[data-test="class-layout-breakpoint-md"]').trigger('click')
    await layoutPanel(w)
      .find('[data-test="style-field-layout.direction"] [data-test="choice-row-reverse"]')
      .trigger('click')
    await flushPromises()
    const expected = clone(original) as { layout: { direction: Style } }
    expected.layout.direction.md = choice('row-reverse')
    expect(w.last()).toEqual(expected)
    expect(w.emissions).toHaveLength(1)
    w.unmount()
  })

  it("switching the class's mode writes the mode and keeps the other family's settings", async () => {
    const original = everything()
    const w = mountEditor(clone(original))
    await openTab(w, 'Layout')
    await layoutPanel(w)
      .find('[data-test="style-field-layout.display"] [data-test="choice-flex"]')
      .trigger('click')
    await flushPromises()
    const expected = clone(original) as { layout: { display: Style } }
    expected.layout.display.base = choice('flex')
    expect(w.last()).toEqual(expected)
    w.unmount()
  })

  it('an edit on the Style tab leaves every layout declaration as it was, a stored invalid one included', async () => {
    const original = clone(PAYLOADS.invalid_value)
    const w = mountEditor(clone(original))
    // Padding's top is set and the other sides are not, so the box is unlinked: one side alone.
    const padding = w.find('[data-test="box-padding"]')
    expect(padding.find('[data-test="box-link"]').attributes('aria-pressed')).toBe('false')
    await padding.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
    await padding.find('[data-test="box-panel"] [data-test="token-spacing.xl"]').trigger('click')
    await flushPromises()
    expect(w.emissions).toHaveLength(1)
    const last = w.last() as { layout: { display: Style }; spacing: { padding: { top: Style } } }
    expect(last.layout).toEqual(original.layout) // `block` at md, untouched
    expect(last.spacing.padding.top.base).toEqual(token('spacing.xl'))
    w.unmount()
  })
})

describe('a non-responsive property is emitted bare (spec §12.4)', () => {
  it("layout.overflow from md: no breakpoint key, and the shapes are the shared fixture's", async () => {
    const w = mountEditor({})
    await openTab(w, 'Layout')
    await w.find('[data-test="class-layout-breakpoint-md"]').trigger('click')
    const overflow = () => layoutPanel(w).find('[data-test="style-field-layout.overflow"]')

    await overflow().find('[data-test="choice-hidden"]').trigger('click')
    await flushPromises()
    expect(w.last()).toEqual({ layout: { overflow: (PAYLOADS.valid.layout as Style).overflow } })
    expect(w.last()).not.toEqual(PAYLOADS.wrapped_non_responsive) // never the shape the server refuses

    await overflow().find('[data-test="style-use-theme-default"]').trigger('click')
    await flushPromises()
    expect(w.last()).toEqual(PAYLOADS.bare_reset)
    w.unmount()
  })

  it('Remove deletes the key', async () => {
    const w = mountEditor({
      layout: { overflow: choice('hidden'), display: { base: choice('grid') } },
    })
    await openTab(w, 'Layout')
    await layoutPanel(w)
      .find('[data-test="style-field-layout.overflow"] [data-test="style-remove"]')
      .trigger('click')
    await flushPromises()
    expect(w.last()).toEqual({ layout: { display: { base: choice('grid') } } })
    w.unmount()
  })

  it('radius, on the Style tab, the same way', async () => {
    const w = mountEditor({})
    await w.find('[data-test="group-breakpoint-lg"]').trigger('click')
    await w.find('[data-test="style-field-radius"] [data-test="token-radius.md"]').trigger('click')
    await flushPromises()
    expect(w.last()).toEqual({ radius: PAYLOADS.valid.radius })
    w.unmount()
  })
})

describe('the shared fixture is what the editor emits', () => {
  it('an edit and its inverse bring the valid payload back exactly', async () => {
    const w = mountEditor(clone(PAYLOADS.valid))
    await openTab(w, 'Layout')
    const wrap = () => layoutPanel(w).find('[data-test="style-field-layout.wrap"]')
    await wrap().find('[data-test="choice-wrap"]').trigger('click')
    await flushPromises()
    expect(w.last()).not.toEqual(PAYLOADS.valid)
    await wrap().find('[data-test="style-remove"]').trigger('click')
    await flushPromises()
    expect(w.last()).toEqual(PAYLOADS.valid)
    w.unmount()
  })
})
