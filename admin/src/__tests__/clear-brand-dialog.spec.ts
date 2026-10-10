// Clear and Replace with… (custom palette spec §4, §4.2): Clear only when nothing current uses the
// colour; otherwise Replace with a destination the rules allow, a text colour asked for when the
// destination has none of its own and something uses the slot's, and both modes' contrast shown.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { paletteFixture } from './helpers/classEditorSchema'
import type { PaletteUsage } from '@/queries/palette'

const api = vi.hoisted(() => ({
  fetchPaletteUsage: vi.fn(),
  clearBrand: vi.fn(),
  replaceBrand: vi.fn(),
  previewPalette: vi.fn(),
}))
vi.mock('@/queries/palette', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/palette')>()),
  ...api,
}))

import ClearBrandDialog from '@/pages/appearance/components/ClearBrandDialog.vue'
import { PaletteConflict } from '@/queries/palette'

const COLOURS = [
  'background',
  'surface',
  'surface-2',
  'text',
  'muted',
  'line',
  'accent',
  'accent-contrast',
  'transparent',
  'white',
  'black',
  'brand-1',
  'brand-1-contrast',
  'brand-2',
  'brand-2-contrast',
  'brand-3',
  'brand-3-contrast',
  'brand-12',
  'brand-12-contrast',
]

function usage(over: {
  blocking?: Partial<PaletteUsage['blocking']>
  historical?: Partial<PaletteUsage['historical']>
}) {
  return {
    slot: 1,
    blocking: {
      entries: [],
      regions: [],
      layouts: [],
      saved_sections: [],
      style_classes: [],
      total: 0,
      contrast_references: false,
      ...over.blocking,
    },
    historical: { entries: [], total: 0, ...over.historical },
  }
}

let wrapper: VueWrapper | null = null
async function mountDialog(props: {
  id: number
  name: string
  palette?: ReturnType<typeof paletteFixture>
}) {
  wrapper = mount(ClearBrandDialog, {
    props: {
      open: true,
      colours: COLOURS,
      palette: paletteFixture(),
      look: {
        palette: {
          neutral_custom: null,
          dark_base: null,
          brands: [],
        },
      },
      ...props,
    },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}
const q = (sel: string) => document.querySelector(sel) as HTMLElement | null
async function click(sel: string) {
  const el = q(sel)
  expect(el, sel).not.toBeNull()
  el!.click()
  await flushPromises()
}

const WRITTEN = '{"revision":4,"colors":[],"removed":[{"id":1,"name":"Gold dark"}]}'

describe('Clear brand dialog', () => {
  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset())
    api.clearBrand.mockResolvedValue(WRITTEN)
    api.replaceBrand.mockResolvedValue({ id: 'job1' })
    api.previewPalette.mockResolvedValue({
      rows: [],
      swatches: {},
      values: { light: {}, dark: {} },
    })
  })
  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.innerHTML = ''
  })

  it('clears an unused colour, mentioning history', async () => {
    api.fetchPaletteUsage.mockResolvedValue(usage({ historical: { total: 12 } }))
    const w = await mountDialog({ id: 1, name: 'Gold dark' })
    expect(api.fetchPaletteUsage).toHaveBeenCalledWith(1)
    expect(document.body.textContent).toContain("Gold dark isn't used on any current page.")
    expect(document.body.textContent).toContain('12 older versions also use Gold dark')
    await click('[data-test="clear-confirm"]')
    expect(api.clearBrand).toHaveBeenCalledWith(1)
    // The list this Clear wrote travels with `done`, so the page can take it as its new base.
    expect(w.emitted('done')).toEqual([[{ cleared: 1, brandColors: WRITTEN }]])
  })

  it('offers only Replace when the colour is in use, excluding forbidden destinations', async () => {
    api.fetchPaletteUsage.mockResolvedValue(
      usage({
        blocking: {
          total: 3,
          entries: [{ uuid: 'e1', title: 'Home', locale: 'en', draft: true, published: false }],
          regions: ['header'],
          contrast_references: false,
        },
      }),
    )
    await mountDialog({
      id: 1,
      name: 'Gold dark',
      palette: paletteFixture({
        'brand-3': { state: 'removed' },
        'brand-2': { state: 'replacing' },
        'brand-12': { name: 'Teal', hex: '#0f766e', state: 'configured', reserved: false },
      }),
    })
    expect(q('[data-test="clear-confirm"]')).toBeNull()
    expect(document.body.textContent).toContain('Home')
    expect(document.body.textContent).toContain('Header')
    for (const t of [
      'color.brand-1',
      'color.brand-1-contrast',
      'color.brand-3',
      'color.accent-contrast',
      'color.brand-2',
      'color.brand-2-contrast',
      'color.brand-12-contrast',
      'color.transparent',
    ]) {
      expect(q(`[data-test="replace-to"] [data-test="token-${t}"]`), t).toBeNull()
    }
    expect(q('[data-test="replace-to"] [data-test="token-color.accent"]')).not.toBeNull()
    expect(q('[data-test="replace-to"] [data-test="token-color.brand-12"]')).not.toBeNull()
    // already in Appearance: no link to it
    expect(q('[data-test="manage-brand-colours"]')).toBeNull()
  })

  it('requires a text colour for a destination with no pair when text on the slot exists, with both-mode ratios', async () => {
    api.fetchPaletteUsage.mockResolvedValue(
      usage({ blocking: { total: 1, contrast_references: true } }),
    )
    api.previewPalette.mockResolvedValue({
      rows: [],
      swatches: {},
      values: {
        light: { surface: '#f6f7f9', text: '#0f172a' },
        dark: { surface: '#111a2e', text: '#e2e8f0' },
      },
    })
    const w = await mountDialog({ id: 1, name: 'Gold dark' })
    await click('[data-test="replace-to"] [data-test="token-color.surface"]')
    expect(q('[data-test="replace-confirm"]')!.hasAttribute('disabled')).toBe(true)
    expect(document.body.textContent).toContain('Text on Gold dark becomes')
    await click('[data-test="contrast-to"] [data-test="token-color.text"]')
    expect(q('[data-test="contrast-ratio-light"]')!.textContent).toMatch(/\d+(\.\d+)?:1/)
    expect(q('[data-test="contrast-ratio-dark"]')).not.toBeNull()
    await click('[data-test="replace-confirm"]')
    expect(api.replaceBrand).toHaveBeenCalledWith(1, 'color.surface', 'color.text')
    expect(w.emitted('done')).toEqual([[]]) // a started replacement carries no list
  })

  it('treats a brand colour of any id as carrying its own text colour', async () => {
    api.fetchPaletteUsage.mockResolvedValue(
      usage({ blocking: { total: 1, contrast_references: true } }),
    )
    await mountDialog({
      id: 1,
      name: 'Gold dark',
      palette: paletteFixture({
        'brand-12': { name: 'Teal', hex: '#0f766e', state: 'configured', reserved: false },
      }),
    })
    await click('[data-test="replace-to"] [data-test="token-color.brand-12"]')
    expect(q('[data-test="contrast-to"]')).toBeNull()
  })

  it('maps the text colour automatically for a destination with a pair', async () => {
    api.fetchPaletteUsage.mockResolvedValue(
      usage({ blocking: { total: 1, contrast_references: true } }),
    )
    await mountDialog({ id: 1, name: 'Gold dark' })
    await click('[data-test="replace-to"] [data-test="token-color.accent"]')
    expect(q('[data-test="contrast-to"]')).toBeNull()
    await click('[data-test="replace-confirm"]')
    expect(api.replaceBrand).toHaveBeenCalledWith(1, 'color.accent', undefined)
  })

  it('shows a conflict inline', async () => {
    api.fetchPaletteUsage.mockResolvedValue(usage({}))
    api.clearBrand.mockRejectedValue(
      new PaletteConflict('Gold dark is part of a running replacement'),
    )
    const w = await mountDialog({ id: 1, name: 'Gold dark' })
    await click('[data-test="clear-confirm"]')
    expect(q('[data-test="palette-conflict"]')!.textContent).toContain(
      'part of a running replacement',
    )
    expect(w.emitted('done')).toBeUndefined()
  })
})
