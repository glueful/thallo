import { describe, it, expect } from 'vitest'
import { brandReport, contrast, normalizeHex } from '@/style/contrast'

// The admin says, before anything is saved, how a brand colour will read — with the same sums the
// server uses to choose the ink (ThemeColors::contrast). The two must agree, so the figures here
// are the server test's own.
describe('brand colour contrast', () => {
  it('is the WCAG ratio', () => {
    expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 1)
    expect(contrast('#336699', '#336699')).toBeCloseTo(1, 3)
    expect(contrast('#767676', '#ffffff')).toBeCloseTo(4.54, 2)
    expect(contrast('#1e3a8a', '#ffffff')).toBe(contrast('#ffffff', '#1e3a8a'))
  })

  it('reads a hex as the server does, and nothing else as one', () => {
    expect(normalizeHex('#0A7C66')).toBe('#0a7c66')
    expect(normalizeHex('#abc')).toBe('#aabbcc')
    expect(normalizeHex('0a7c66')).toBe('#0a7c66') // pasted without its hash
    for (const bad of ['', '#12', '#12345', '#gggggg', 'red', '#fff;color:red']) {
      expect(normalizeHex(bad), bad).toBeNull()
    }
  })

  it('reports the ink a button gets, and whether the colour reads as text on the page', () => {
    const navy = brandReport('#1e3a8a')
    expect(navy.ink).toBe('#ffffff')
    expect(navy.onAccent).toBeGreaterThanOrEqual(4.5)
    expect(navy.asText).toBe('good')

    const yellow = brandReport('#facc15')
    expect(yellow.ink).toBe('#000000')
    expect(yellow.onAccent).toBeGreaterThanOrEqual(4.5) // a label is always readable
    expect(yellow.asText).toBe('poor') // but yellow links on white are not

    expect(brandReport('#ea580c').asText).toBe('large') // 3.6:1 — fine for headings, thin for body text
  })
})
