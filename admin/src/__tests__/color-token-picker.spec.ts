// The colour picker (custom palette spec §5.2): a swatch and a name for every colour; brand colours
// their own group after the theme's, in the author's order, their text colours folded behind a
// disclosure, and a link to Appearance's Colours tab for a manager; removed, never-issued and
// replacing colours hidden from new choices; a stored reference to an unavailable colour named by
// what it was, with Choose another and Clear; and "site default" inside a scoped skin.
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TokenScaleControl from '@/editor/inspector/controls/TokenScaleControl.vue'
import { paletteFixture } from './helpers/classEditorSchema'

const names = [
  'background',
  'surface',
  'accent',
  'accent-contrast',
  'transparent',
  'brand-1',
  'brand-1-contrast',
  'brand-2',
  'brand-2-contrast',
  'brand-3',
  'brand-3-contrast',
]

const RouterLink = { props: ['to'], template: '<a :href="to"><slot /></a>' }

function picker(props: Record<string, unknown> = {}) {
  return mount(TokenScaleControl, {
    props: {
      domain: 'color',
      names,
      values: {},
      modelValue: null,
      palette: paletteFixture(),
      ...props,
    },
    global: { stubs: { RouterLink } },
  })
}
const openTexts = async (w: ReturnType<typeof picker>) =>
  w.get('[data-test="brand-text-toggle"]').trigger('click')

describe('colour token picker', () => {
  it('shows swatches and author names', async () => {
    const w = picker()
    await openTexts(w)
    const gold = w.find('[data-test="token-color.brand-1"]')
    expect(gold.text()).toContain('Gold dark')
    expect(gold.find('[data-test="swatch"]').attributes('style')).toContain('rgb(138, 106, 42)')
    expect(w.find('[data-test="token-color.brand-1-contrast"]').text()).toContain(
      'Gold dark — text',
    )
    expect(
      w.find('[data-test="token-color.surface"] [data-test="swatch"]').attributes('style'),
    ).toContain('rgb(246, 247, 249)')
    expect(
      w.find('[data-test="token-color.transparent"] [data-test="swatch"]').classes(),
    ).toContain('swatch-checker')
  })

  it('gives a text colour the black or white disc its fill pairs with', async () => {
    const w = picker()
    await openTexts(w)
    // Gold dark is dark: white text; Rose is light: black text
    expect(
      w.find('[data-test="token-color.brand-1-contrast"] [data-test="swatch"]').attributes('style'),
    ).toContain('rgb(255, 255, 255)')
    expect(
      w.find('[data-test="token-color.brand-2-contrast"] [data-test="swatch"]').attributes('style'),
    ).toContain('rgb(0, 0, 0)')
  })

  it('hides unset and replacing slots from new choices but keeps reserved ones', () => {
    const w = picker({
      palette: paletteFixture({
        'brand-2': { state: 'replacing' },
        'brand-3': null,
        'brand-1': { reserved: true },
      }),
    })
    expect(w.find('[data-test="token-color.brand-2"]').exists()).toBe(false)
    expect(w.find('[data-test="token-color.brand-2-contrast"]').exists()).toBe(false)
    expect(w.find('[data-test="token-color.brand-3"]').exists()).toBe(false)
    expect(w.find('[data-test="token-color.brand-1"]').exists()).toBe(true)
  })

  it('shows a stored reference to a never-issued id as unavailable, with choose-another and clear', async () => {
    const w = picker({
      modelValue: 'color.brand-3',
      palette: paletteFixture({ 'brand-3': null }),
    })
    const notice = w.find('[data-test="unavailable-colour"]')
    expect(notice.text()).toContain('Unavailable colour: Brand 3')
    expect(notice.text()).toContain('No colour applied')
    expect(notice.find('[data-test="unavailable-choose"]').exists()).toBe(true)
    await notice.find('[data-test="unavailable-clear"]').trigger('click')
    expect(w.emitted('clear')).toHaveLength(1)
  })

  it('labels a slot being replaced with its destination', () => {
    const w = picker({
      modelValue: 'color.brand-2',
      palette: paletteFixture({
        'brand-2': {
          state: 'replacing',
          replacing: {
            to: 'color.accent',
            to_label: 'Accent',
            contrast_to: 'color.accent-contrast',
            contrast_to_label: 'Accent — text',
          },
        },
      }),
    })
    expect(w.find('[data-test="replacing-colour"]').text()).toContain('being replaced by Accent')
  })

  it('captions swatches site default inside a scoped skin', () => {
    expect(picker({ scopedSkin: true }).find('[data-test="swatch-site-default"]').exists()).toBe(
      true,
    )
    expect(picker().find('[data-test="swatch-site-default"]').exists()).toBe(false)
  })

  it('keeps the plain segmented control for other domains', () => {
    const w = mount(TokenScaleControl, {
      props: {
        domain: 'spacing',
        names: ['sm', 'lg'],
        values: {},
        modelValue: null,
        palette: paletteFixture(),
      },
    })
    expect(w.find('[data-test="swatch"]').exists()).toBe(false)
    expect(w.find('[data-test="token-spacing.sm"]').text()).toBe('sm')
  })

  // ── The brand group (custom palette spec §5.2) ─────────────────────────────────────────────────
  const listed = (over: Parameters<typeof paletteFixture>[0] = {}) =>
    paletteFixture({
      'brand-1': null,
      'brand-2': null,
      'brand-3': null,
      'brand-4': { name: 'Gold', hex: '#8a6a2a', state: 'configured', reserved: false },
      'brand-12': { name: 'Sky', hex: '#38bdf8', state: 'configured', reserved: false },
      'brand-7': { name: 'Teal', state: 'removed' },
      ...over,
    })
  const NAMES = ['accent', 'text', 'brand-4', 'brand-4-contrast', 'brand-12', 'brand-12-contrast']

  it('groups the brand colours after the theme’s, in order, with their text colours folded away', async () => {
    const w = picker({ names: NAMES, palette: listed() })
    const group = w.get('[data-test="brand-group"]')
    expect(group.text()).toContain('Brand colours')
    expect(
      group.findAll('[data-test^="token-color.brand-"]').map((b) => b.attributes('data-test')),
    ).toEqual(['token-color.brand-4', 'token-color.brand-12'])
    expect(w.find('[data-test="brand-text-colours"]').exists()).toBe(false)
    await openTexts(w)
    expect(w.get('[data-test="brand-text-colours"]').text()).toContain('Gold — text')
  })

  it('opens the text colours when the stored value is one', () => {
    const w = picker({ names: NAMES, palette: listed(), modelValue: 'color.brand-12-contrast' })
    expect(w.find('[data-test="brand-text-colours"]').exists()).toBe(true)
  })

  it('links to the Colours tab for a manager, and only for a manager', () => {
    const link = picker({ names: NAMES, palette: listed() }).get(
      '[data-test="manage-brand-colours"]',
    )
    expect(link.text()).toBe('Manage brand colours')
    expect(link.attributes('href')).toBe('/appearance?tab=colours')
    const editor = picker({ names: NAMES, palette: { ...listed(), can_manage: false } })
    expect(editor.find('[data-test="manage-brand-colours"]').exists()).toBe(false)
  })

  it('with no brand colours shows only the link to a manager, and nothing to anyone else', () => {
    const none = { ...listed({ 'brand-4': null, 'brand-12': null, 'brand-7': null }), order: [] }
    const theme = ['accent', 'text']
    expect(
      picker({ names: theme, palette: none }).get('[data-test="brand-group"]').text(),
    ).toContain('Manage brand colours')
    expect(
      picker({ names: theme, palette: { ...none, can_manage: false } })
        .find('[data-test="brand-group"]')
        .exists(),
    ).toBe(false)
  })

  it('hides the brand group when brand colours are off', () => {
    const off = { ...listed({ 'brand-4': null, 'brand-12': null }), limit: 0, order: [] }
    expect(
      picker({ names: ['accent', 'text'], palette: off })
        .find('[data-test="brand-group"]')
        .exists(),
    ).toBe(false)
  })

  it('names an unavailable colour: removed by its name, never issued by its number', () => {
    const removed = picker({ names: NAMES, palette: listed(), modelValue: 'color.brand-7' })
    expect(removed.get('[data-test="unavailable-colour"]').text()).toContain(
      'Unavailable colour: Teal (removed)',
    )
    expect(removed.get('[data-test="unavailable-colour"]').text()).toContain('No colour applied')
    const foreign = picker({
      names: NAMES,
      palette: listed(),
      modelValue: 'color.brand-99-contrast',
    })
    expect(foreign.get('[data-test="unavailable-colour"]').text()).toContain(
      'Unavailable colour: Brand 99',
    )
  })

  it('treats a brand id above three like any other', () => {
    const w = picker({ names: NAMES, palette: listed() })
    const sky = w.get('[data-test="token-color.brand-12"]')
    expect(sky.text()).toContain('Sky')
    expect(sky.get('[data-test="swatch"]').attributes('style')).toContain('rgb(56, 189, 248)')
  })

  it('leaves the Manage link out where the picker already sits in Appearance', () => {
    const w = picker({ names: NAMES, palette: listed(), manageLink: false })
    expect(w.find('[data-test="brand-group"]').exists()).toBe(true)
    expect(w.find('[data-test="manage-brand-colours"]').exists()).toBe(false)
  })
})
