import { describe, expect, it } from 'vitest'
import { holdsPageHeading } from '@/queries/patterns'

describe('holdsPageHeading', () => {
  it('finds an h1 on a top-level block', () => {
    expect(holdsPageHeading([{ id: 'a', type: 'hero', data: { heading_level: 'h1' } }])).toBe(true)
  })

  it('finds an h1 nested inside a container', () => {
    const section = {
      id: 's',
      type: 'container',
      data: { items: [{ id: 'h', type: 'heading', data: { heading_level: 'h1', text: 'Hi' } }] },
    }
    expect(holdsPageHeading([section])).toBe(true)
  })

  it('leaves a page without an h1 alone', () => {
    expect(
      holdsPageHeading([
        { id: 'a', type: 'hero', data: { heading_level: 'h2' } },
        { id: 'b', type: 'rich_text', data: { html: '<h1>not a field</h1>' } },
      ]),
    ).toBe(false)
  })
})
