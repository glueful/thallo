// Appearance's tabs: which exist, which one a URL opens, and which tab holds a settings key.
import { describe, it, expect } from 'vitest'
import {
  TAB_KEYS,
  availableTabs,
  tabFromQuery,
  tabsHolding,
} from '@/pages/appearance/appearanceTabs'

describe('appearance tabs', () => {
  it('lists the five tabs in order, and drops Theme when there are no themes', () => {
    expect(availableTabs(true)).toEqual(['theme', 'colours', 'design', 'typefaces', 'logos'])
    expect(availableTabs(false)).toEqual(['colours', 'design', 'typefaces', 'logos'])
  })

  it('opens the queried tab, and falls back to the first tab for unknown or hidden ones', () => {
    const all = availableTabs(true)
    expect(tabFromQuery('logos', all)).toBe('logos')
    expect(tabFromQuery(undefined, all)).toBe('theme')
    expect(tabFromQuery('nope', all)).toBe('theme')
    expect(tabFromQuery(['colours'], all)).toBe('theme')
    expect(tabFromQuery('theme', availableTabs(false))).toBe('colours')
  })

  it('maps settings keys, nested field names included, to the tabs that hold them', () => {
    expect([...tabsHolding(['site_favicon'])]).toEqual(['logos'])
    expect([...tabsHolding(['theme_brand_colors.colors.1.name', 'theme_radius'])].sort()).toEqual([
      'colours',
      'design',
    ])
    expect([...tabsHolding(['theme_font_text_family'])]).toEqual(['typefaces'])
    expect(tabsHolding(['site_name']).size).toBe(0)
  })

  it('gives every key the page owns to exactly one tab', () => {
    const keys = Object.values(TAB_KEYS).flat()
    expect(new Set(keys).size).toBe(keys.length)
  })
})
