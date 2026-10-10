import { describe, expect, it } from 'vitest'
import { applyPaletteRewrites, mapPaletteTokens } from '@/editor/paletteRewrites'

const tok = (v: string) => ({ type: 'token', value: v })
const heading = (id: string, colour: string, text = 'x') => ({
  id,
  type: 'heading',
  data: { text },
  settings: { style: { colors: { text: tok(colour) } } },
})
const rw = (id: string) => ({
  location: `${id}:settings.style.colors.text`,
  from: 'color.brand-1',
  to: 'color.accent',
})
const colourOf = (doc: any, i: number) => doc.body[i].settings.style.colors.text.value

describe('applyPaletteRewrites', () => {
  it('follows the block through a reorder of two blocks with the same colour', () => {
    const { doc } = applyPaletteRewrites(
      { body: [heading('b', 'color.brand-1'), heading('a', 'color.brand-1')] },
      [rw('a')],
    )
    expect(colourOf(doc, 1)).toBe('color.accent')
    expect(colourOf(doc, 0)).toBe('color.brand-1')
  })
  it('skips a deleted block even when another block takes its index', () => {
    const { doc, applied } = applyPaletteRewrites({ body: [heading('b', 'color.brand-1')] }, [
      rw('a'),
    ])
    expect(colourOf(doc, 0)).toBe('color.brand-1')
    expect(applied).toHaveLength(0)
  })
  it('keeps an edit made during the request', () => {
    const { doc, applied } = applyPaletteRewrites({ body: [heading('a', 'color.muted')] }, [
      rw('a'),
    ])
    expect(colourOf(doc, 0)).toBe('color.muted')
    expect(applied).toHaveLength(0)
  })
  it('finds nested blocks and token content fields', () => {
    const doc = {
      body: [
        {
          id: 's',
          type: 'section',
          data: {
            children: [
              { id: 't', type: 'animated_text', data: { prefix_color: tok('color.brand-1') } },
            ],
          },
        },
      ],
    }
    const { doc: out } = applyPaletteRewrites(doc, [
      { location: 't:data.prefix_color', from: 'color.brand-1', to: 'color.accent' },
    ])
    expect((out as any).body[0].data.children[0].data.prefix_color.value).toBe('color.accent')
  })
  it('resolves page-level locations as plain paths', () => {
    const { doc } = applyPaletteRewrites(
      { _presentation: { style: { colors: { surface: tok('color.brand-1') } } } },
      [
        {
          location: '_presentation.style.colors.surface',
          from: 'color.brand-1',
          to: 'color.accent',
        },
      ],
    )
    expect((doc as any)._presentation.style.colors.surface.value).toBe('color.accent')
  })
})

describe('mapPaletteTokens', () => {
  it('maps every token node, deep, and nothing else', () => {
    const out = mapPaletteTokens(
      { a: tok('color.brand-1'), b: [{ c: tok('color.brand-1-contrast') }], d: 'color.brand-1' },
      { 'color.brand-1': 'color.accent', 'color.brand-1-contrast': 'color.accent-contrast' },
    ) as any
    expect(out.a.value).toBe('color.accent')
    expect(out.b[0].c.value).toBe('color.accent-contrast')
    expect(out.d).toBe('color.brand-1')
  })
})
