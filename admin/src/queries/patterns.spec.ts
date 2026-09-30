import { describe, it, expect } from 'vitest'
import {
  belongsIn,
  instantiate,
  isPatternKey,
  patternKey,
  patternSlug,
  type Pattern,
} from './patterns'
import { qk } from './keys'

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

describe('the layout place (sections and templates design §3)', () => {
  const layoutPattern = (surface: string, extra: Partial<Pattern> = {}): Pattern => ({
    slug: `${surface}-part`,
    kind: 'section',
    label: 'Part',
    category: 'Article',
    description: '',
    blocks: [],
    scope: 'layout',
    surface,
    ...extra,
  })

  it('a layout pattern belongs in its own surface only', () => {
    expect(belongsIn(layoutPattern('entry'), { scope: 'layout', surface: 'entry' })).toBe(true)
    expect(belongsIn(layoutPattern('entry'), { scope: 'layout', surface: 'listing' })).toBe(false)
    expect(belongsIn(layoutPattern('entry'), { scope: 'page' })).toBe(false)
    expect(belongsIn(layoutPattern('entry'), { scope: 'region', region: 'header' })).toBe(false)
  })

  it('a page pattern is not in a layout place', () => {
    const page: Pattern = { ...layoutPattern('entry'), scope: 'page', surface: null }
    expect(belongsIn(page, { scope: 'layout', surface: 'entry' })).toBe(false)
  })

  it('a layout’s library is keyed by its surface and target, under the library’s key', () => {
    expect(qk.layoutPatterns('entry', 'post')).toEqual(['patterns', 'layout', 'entry', 'post'])
    expect(qk.layoutPatterns('entry', 'post').slice(0, 1)).toEqual([...qk.patterns()])
  })
})
