import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BrandColorField from '@/pages/appearance/components/BrandColorField.vue'

// The accent is one of the colour families, or the site's own brand colour. Choosing "Brand
// colour" opens a colour and a hex field; what is typed becomes the accent only while it IS a
// colour; and the field says, before anything is saved, how that colour will read.
const mountField = (modelValue: string) => mount(BrandColorField, { props: { modelValue } })
type Field = ReturnType<typeof mountField>
/** The select's options render in a portal, so jsdom drives the Reka root (the suite's pattern). */
const select = (w: Field) => w.findComponent({ name: 'SelectRoot' })
const choose = (w: Field, value: string) => select(w).vm.$emit('update:modelValue', value)
/** `data-test` lands on the input's wrapper or on the input itself, as the UI kit decides. */
const hexInput = (w: Field) => {
  const el = w.find('[data-test="brand-hex"]')
  return el.element.tagName === 'INPUT' ? el : el.find('input')
}
const last = (w: Field) => {
  const calls = w.emitted('update:modelValue')!
  return calls[calls.length - 1]![0]
}

describe('BrandColorField', () => {
  it('offers the families and the brand colour; a family needs nothing more', () => {
    const w = mountField('teal')
    expect(w.find('[data-test="theme-accent"]').text()).toContain('teal')
    expect(mountField('#0a7c66').find('[data-test="theme-accent"]').text()).toContain(
      'Brand colour…',
    )
    expect(w.find('[data-test="brand-hex"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-report"]').exists()).toBe(false)
    expect(w.find('[data-test="theme-accent-swatch"]').attributes('style')).toContain(
      'rgb(20, 184, 166)',
    )
  })

  it('switching to the brand colour starts from the colour the site has now', async () => {
    const w = mountField('teal')
    choose(w, 'custom')
    await w.vm.$nextTick()
    expect(last(w)).toBe('#14b8a6')
  })

  it('a hex is the accent only while it is a colour', async () => {
    const w = mountField('#0a7c66')
    expect(select(w).props('modelValue')).toBe('custom')
    const hex = hexInput(w)
    await hex.setValue('#1E3A8A')
    expect(last(w)).toBe('#1e3a8a')
    await hex.setValue('#1e3a')
    expect(last(w)).toBe('#1e3a8a') // half typed: the accent keeps the last colour
    expect(w.find('[data-test="brand-hex-error"]').exists()).toBe(true)
    // The colour well writes the same value.
    await w.find('[data-test="brand-picker"]').setValue('#facc15')
    expect(last(w)).toBe('#facc15')
  })

  it('says how the colour will read before it is saved', async () => {
    const navy = mountField('#1e3a8a')
    expect(navy.find('[data-test="brand-report"]').text()).toContain('white text')
    expect(navy.find('[data-test="brand-report-text"]').attributes('data-level')).toBe('good')

    const yellow = mountField('#facc15')
    expect(yellow.find('[data-test="brand-report"]').text()).toContain('black text')
    const text = yellow.find('[data-test="brand-report-text"]')
    expect(text.attributes('data-level')).toBe('poor')
    expect(text.text()).toMatch(/hard to read/i)
    // The sample is the button a visitor will see: the colour, with the ink the site will use.
    const sample = yellow.find('[data-test="brand-sample"]').attributes('style')
    expect(sample).toContain('rgb(250, 204, 21)')
    expect(sample).toContain('rgb(0, 0, 0)')
  })

  it('going back to a family leaves the brand colour behind', async () => {
    const w = mountField('#0a7c66')
    choose(w, 'rose')
    await w.vm.$nextTick()
    expect(last(w)).toBe('rose')
  })
})
