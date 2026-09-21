import { describe, it, expect } from 'vitest'
import { instantiate, isPatternKey, patternKey, patternSlug, type Pattern } from './patterns'

// A pattern is a tree of ordinary blocks that arrives with no ids: the editor mints them, for the
// block and everything inside it, every time — two inserts of one section are two sets of blocks.
const SECTION: Pattern = {
  slug: 'features-grid',
  kind: 'section',
  label: 'Feature grid',
  category: 'Features',
  description: 'A heading and three feature cards.',
  blocks: [
    {
      type: 'container',
      data: {
        element: 'section',
        content: [
          { type: 'heading', data: { text: 'Hi', level: 'h2' }, settings: {} },
          {
            type: 'container',
            data: { content: [{ type: 'feature', data: { title: 'One' }, settings: {} }] },
            settings: {
              style: { layout: { display: { base: { type: 'choice', value: 'grid' } } } },
            },
          },
        ],
      },
      settings: {},
    },
  ],
}

describe('patterns', () => {
  it('gives every block of a pattern a fresh id, all the way down, and keeps what it says', () => {
    const [a] = instantiate(SECTION)
    const [b] = instantiate(SECTION)
    const ids = (block: typeof a): string[] => [
      block!.id,
      ...((block!.data.content as (typeof a)[] | undefined) ?? []).flatMap(ids),
    ]
    const all = [...ids(a), ...ids(b)]
    expect(all).toHaveLength(8)
    expect(new Set(all).size).toBe(8)
    for (const id of all) expect(id).toMatch(/^[A-Za-z0-9_-]{12}$/)

    const grid = (a!.data.content as (typeof a)[])[1]!
    expect(grid.settings).toEqual(SECTION.blocks[0]!.data.content![1]!.settings)
    expect((grid.data.content as (typeof a)[])[0]!.data.title).toBe('One')
    expect(a!.data.element).toBe('section')
  })

  it('names a pattern apart from a block type on the palette’s one insert path', () => {
    expect(patternKey('faq')).toBe('pattern:faq')
    expect(isPatternKey('pattern:faq')).toBe(true)
    expect(isPatternKey('faq')).toBe(false)
    expect(patternSlug('pattern:faq')).toBe('faq')
  })
})
