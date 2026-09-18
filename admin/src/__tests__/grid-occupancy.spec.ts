// Which cells an appended block can reach (container-layout spec §11.3). A grid places its items
// in order and never back-fills, so the only free cells that matter are the ones after the last
// item — a hole earlier in the grid stays a hole, whatever is appended.
import { describe, expect, it } from 'vitest'
import { effectiveSpan, lastRowFree, trackCount } from '@/editor/structure/gridOccupancy'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { StyleClassRef } from '@/style/types'

const choice = (value: string) => ({ type: 'choice', value })
const child = (id: string, style: Record<string, unknown> = {}): BlockInstance =>
  ({ id, type: 'heading', data: {}, settings: { style } }) as unknown as BlockInstance
const span = (n: string, bp = 'base') => ({ layout: { span: { [bp]: choice(n) } } })
const grid = (columns: Record<string, unknown>, children: BlockInstance[]): BlockInstance =>
  ({
    id: 'g1',
    type: 'container',
    data: { content: children },
    settings: { style: { layout: { display: { base: choice('grid') }, columns } } },
  }) as unknown as BlockInstance
const none = (): StyleClassRef[] => []

describe('trackCount', () => {
  it('reads a count as that many tracks and a split as its parts', () => {
    expect(trackCount('3')).toBe(3)
    expect(trackCount('12')).toBe(12)
    expect(trackCount('1-2-1')).toBe(3)
    expect(trackCount('2-1')).toBe(2)
    expect(trackCount('1')).toBe(1)
  })
})

describe('effectiveSpan', () => {
  it('clamps to the tracks there are, as the rendered grid does (spec §3.7)', () => {
    expect(effectiveSpan('2', 3)).toBe(2)
    expect(effectiveSpan('full', 3)).toBe(3)
    expect(effectiveSpan('6', 3)).toBe(3)
    expect(effectiveSpan(null, 3)).toBe(1)
    expect(effectiveSpan('2', 1)).toBe(1)
  })
})

describe('lastRowFree', () => {
  const three = { base: choice('3') }

  it('an empty grid offers a full row', () => {
    expect(lastRowFree(grid(three, []), 'base', none)).toBe(3)
  })

  it('counts what is left of the last row', () => {
    expect(lastRowFree(grid(three, [child('a')]), 'base', none)).toBe(2)
    expect(lastRowFree(grid(three, [child('a', span('2'))]), 'base', none)).toBe(1)
    expect(lastRowFree(grid(three, [child('a'), child('b'), child('c')]), 'base', none)).toBe(0)
  })

  it('leaves earlier holes alone: two children spanning 2 of 3 leave ONE reachable cell', () => {
    // [A A _] [B B _] — an appended block lands after B, in row two. Row one's hole is not
    // reachable by appending, so it is not counted: the answer is 1, not 2.
    const g = grid(three, [child('a', span('2')), child('b', span('2'))])
    expect(lastRowFree(g, 'base', none)).toBe(1)
  })

  it('a span that does not fit what is left of a row wraps, as the browser places it', () => {
    // [A _ _] then B spanning 3 cannot follow A: it takes row two whole.
    const g = grid(three, [child('a'), child('b', span('full'))])
    expect(lastRowFree(g, 'base', none)).toBe(0)
  })

  it('a span larger than the tracks counts as the tracks', () => {
    expect(lastRowFree(grid(three, [child('a', span('6'))]), 'base', none)).toBe(0)
  })

  it('a child hidden at the breakpoint occupies nothing there', () => {
    const hidden = child('a', { visibility: { md: choice('hidden') } })
    const g = grid(three, [hidden, child('b')])
    expect(lastRowFree(g, 'base', none)).toBe(1) // both placed
    expect(lastRowFree(g, 'md', none)).toBe(2) // only b
    expect(lastRowFree(g, 'lg', none)).toBe(2) // hidden is inherited upward
  })

  it('resolves tracks through the cascade: inherited from base, and supplied by a class', () => {
    const responsive = grid({ base: choice('1'), md: choice('3') }, [child('a')])
    expect(lastRowFree(responsive, 'base', none)).toBe(0) // one track, one child: the row is full
    expect(lastRowFree(responsive, 'lg', none)).toBe(2) // md's three, inherited at lg

    const bare = {
      id: 'g2',
      type: 'container',
      data: { content: [child('a')] },
      settings: { classes: ['tiles'], style: { layout: { display: { base: choice('grid') } } } },
    } as unknown as BlockInstance
    const classes = (id: string): StyleClassRef[] =>
      id === 'g2' ? [{ id: 'tiles', style: { layout: { columns: { base: choice('4') } } } }] : []
    expect(lastRowFree(bare, 'base', classes)).toBe(3)
  })

  it('reads a span a style class supplies to a child', () => {
    const g = grid(three, [child('wide')])
    const classes = (id: string): StyleClassRef[] =>
      id === 'wide' ? [{ id: 'two', style: { layout: { span: { base: choice('2') } } } }] : []
    expect(lastRowFree(g, 'base', classes)).toBe(1)
  })

  it('with no track count set there is one track, so any child fills the row', () => {
    expect(lastRowFree(grid({}, []), 'base', none)).toBe(1)
    expect(lastRowFree(grid({}, [child('a')]), 'base', none)).toBe(0)
  })
})
