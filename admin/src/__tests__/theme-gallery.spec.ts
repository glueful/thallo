import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ThemeGallery from '@/pages/appearance/components/ThemeGallery.vue'
import type { ThemeCard } from '@/queries/templates'

// A theme was a name in a select. It is now a card: what it looks like, what it is called, who
// made it — and choosing one is still only a choice, applied by the page's Save like every other
// field here.
const card = (name: string, more: Partial<ThemeCard> = {}): ThemeCard => ({
  name,
  title: name,
  version: null,
  description: null,
  author: null,
  tags: [],
  colors: null,
  screenshot_url: null,
  ...more,
})
const DEFAULT = card('default', {
  title: 'Default',
  version: '1.0.0',
  description: 'Thallo’s own theme.',
  author: 'Thallo',
  tags: ['general', 'light and dark'],
  screenshot_url: '/_thallo/theme-screenshot/default?v=1',
})
const AURORA = card('aurora', { colors: { background: '#0b1020', accent: '#7c3aed' } })

function mountGallery(modelValue = 'default', live = 'default', cards = [DEFAULT, AURORA]) {
  return mount(ThemeGallery, {
    props: { cards, modelValue, live },
    global: { stubs: { UBadge: { template: '<span><slot /></span>' } } },
  })
}
const theme = (w: ReturnType<typeof mountGallery>, name: string) =>
  w.find(`[data-test="theme-option-${name}"]`)

describe('ThemeGallery', () => {
  it('shows each theme as a card: its screenshot, title, version, author and description', () => {
    const w = mountGallery()
    const def = theme(w, 'default')
    expect(def.find('img').attributes('src')).toBe('/_thallo/theme-screenshot/default?v=1')
    expect(def.text()).toContain('Default')
    expect(def.text()).toContain('1.0.0')
    expect(def.text()).toContain('Thallo')
    expect(def.text()).toContain('Thallo’s own theme.')
    expect(def.text()).toContain('general')
  })

  it('draws a thumbnail from the theme’s own colours when it ships no screenshot', () => {
    const w = mountGallery()
    const aurora = theme(w, 'aurora')
    expect(aurora.find('img').exists()).toBe(false)
    const drawn = aurora.find('[data-test="theme-thumbnail-drawn"]')
    expect(drawn.exists()).toBe(true)
    expect(drawn.attributes('style')).toContain('--thumb-bg: #0b1020')
    expect(drawn.attributes('style')).toContain('--thumb-accent: #7c3aed')
  })

  it('falls back to the drawn thumbnail when the screenshot does not load', async () => {
    const w = mountGallery()
    await theme(w, 'default').find('img').trigger('error')
    expect(theme(w, 'default').find('img').exists()).toBe(false)
    expect(theme(w, 'default').find('[data-test="theme-thumbnail-drawn"]').exists()).toBe(true)
  })

  it('is a radio group: one theme is chosen, and the live one is marked', () => {
    const w = mountGallery('aurora', 'default')
    expect(w.find('[role="radiogroup"]').exists()).toBe(true)
    expect(theme(w, 'default').attributes('aria-checked')).toBe('false')
    expect(theme(w, 'aurora').attributes('aria-checked')).toBe('true')
    expect(theme(w, 'default').find('[data-test="theme-live"]').exists()).toBe(true)
    expect(theme(w, 'aurora').find('[data-test="theme-live"]').exists()).toBe(false)
    // Chosen but not live yet: the card says what will make it so.
    expect(theme(w, 'aurora').find('[data-test="theme-pending"]').text()).toContain('Save')
    expect(theme(w, 'default').find('[data-test="theme-pending"]').exists()).toBe(false)
  })

  it('chooses a theme on click and with the arrow keys', async () => {
    const w = mountGallery()
    await theme(w, 'aurora').trigger('click')
    expect(w.emitted('update:modelValue')).toEqual([['aurora']])

    await theme(w, 'default').trigger('keydown', { key: 'ArrowRight' })
    expect(w.emitted('update:modelValue')![1]).toEqual(['aurora'])
    // Only the chosen card is in the tab order, as in any radio group.
    expect(theme(w, 'default').attributes('tabindex')).toBe('0')
    expect(theme(w, 'aurora').attributes('tabindex')).toBe('-1')
  })
})
