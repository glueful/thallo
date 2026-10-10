// The colour picker (custom palette spec §5.2): a swatch and a name for every colour, the author's
// brand names, unset and replacing slots hidden from new choices, a stored reference to an unset slot
// shown as unavailable with Choose another and Clear, and "site default" inside a scoped skin.
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
  })
}

describe('colour token picker', () => {
  it('shows swatches and author names', () => {
    const w = picker()
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

  it('gives a text colour the black or white disc its fill pairs with', () => {
    const w = picker()
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

  it('shows a stored reference to an unset slot as unavailable, with choose-another and clear', async () => {
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
})
