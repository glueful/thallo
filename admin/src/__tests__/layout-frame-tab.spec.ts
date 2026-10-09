import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LayoutFrameTab from '@/pages/layouts/components/LayoutFrameTab.vue'

// The Frame tab's Styles (type layouts spec §6.4): a page's own Styles — padding, margin and a
// background — for every page of the kind, written into the Frame settings under `style`.
const vocabulary = {
  domains: { spacing: ['none', 'sm', 'lg'], color: ['surface', 'accent'] },
  values: { 'spacing.lg': 'var(--space-4)', 'color.surface': 'var(--surface)' },
}
function mountTab(settings: Record<string, unknown> = {}) {
  return mount(LayoutFrameTab, {
    props: { settings, vocabulary, activeBreakpoint: 'lg' as const },
  })
}
const lastSettings = (w: ReturnType<typeof mountTab>) => {
  const all = w.emitted('update:settings')!
  return all[all.length - 1]![0] as Record<string, unknown>
}

describe('the Frame tab', () => {
  it('pads every page of the kind at the active breakpoint, beside its other settings', async () => {
    const w = mountTab({ width: 'full' })
    const styles = w.find('[data-test="page-styles"]')
    expect(styles.exists()).toBe(true)
    await styles.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
    await styles.find('[data-test="token-spacing.lg"]').trigger('click')
    const lg = { lg: { type: 'token', value: 'spacing.lg' } }
    expect(lastSettings(w)).toEqual({
      width: 'full',
      style: { spacing: { padding: { top: lg, right: lg, bottom: lg, left: lg } } },
    })
  })

  it('paints a background, one value for every breakpoint', async () => {
    const w = mountTab()
    await w.find('[data-test="page-styles"] [data-test="token-color.surface"]').trigger('click')
    expect(lastSettings(w)).toEqual({
      style: { colors: { surface: { type: 'token', value: 'color.surface' } } },
    })
  })

  it('stores no style once nothing is set', async () => {
    const w = mountTab({
      header: 'hidden',
      style: { colors: { surface: { type: 'token', value: 'color.surface' } } },
    })
    await w.find('[data-test="page-styles"] [data-test="style-clear"]').trigger('click')
    expect(lastSettings(w)).toEqual({ header: 'hidden' })
  })

  it('switches the breakpoint it edits', async () => {
    const w = mountTab()
    await w.find('[data-test="page-breakpoint-md"]').trigger('click')
    expect(w.emitted('update:activeBreakpoint')).toEqual([['md']])
  })
})
