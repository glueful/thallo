import { describe, it, expect } from 'vitest'
import { EMPTY_SELECTION, extend, reconcile, single, toggle } from '@/editor/selection'
import type { EditorDocument } from '@/editor/ops/types'

const regionsOf = (type: string): string[] => (type === 'section' ? ['content'] : [])
const ctx = { regionsOf, blockFields: () => ['body'] }
const b = (id: string, type = 'heading', data: Record<string, unknown> = {}) => ({
  id,
  type,
  data,
  settings: {},
})
const doc: EditorDocument = {
  fields: {
    body: [
      b('a1'),
      b('a2'),
      b('sec', 'section', { content: [b('c1'), b('c2'), b('c3'), b('c4')] }),
      b('a3'),
    ],
  },
}

describe('sibling multi-selection (visual builder spec §5.5)', () => {
  it('a click selects one block and anchors on it; an unknown id clears', () => {
    expect(single('c2', doc, ctx)).toEqual({
      ids: ['c2'],
      parent: 'sec',
      slot: 'content',
      anchor: 'c2',
    })
    expect(single('a1', doc, ctx)).toEqual({
      ids: ['a1'],
      parent: null,
      slot: 'body',
      anchor: 'a1',
    })
    expect(single('nope', doc, ctx)).toEqual(EMPTY_SELECTION)
  })

  it('shift extends to a range from the anchor in document order, in either direction', () => {
    const from = single('c3', doc, ctx)
    expect(extend(from, 'c1', doc, ctx).ids).toEqual(['c1', 'c2', 'c3'])
    expect(extend(from, 'c4', doc, ctx).ids).toEqual(['c3', 'c4'])
    expect(extend(from, 'c4', doc, ctx).anchor).toBe('c3')
    // Extending again re-ranges from the same anchor, never accumulates.
    const wide = extend(from, 'c1', doc, ctx)
    expect(extend(wide, 'c4', doc, ctx).ids).toEqual(['c3', 'c4'])
  })

  it('a block from another slot starts a new selection; no anchor means a plain click', () => {
    const inner = single('c2', doc, ctx)
    expect(extend(inner, 'a3', doc, ctx)).toEqual({
      ids: ['a3'],
      parent: null,
      slot: 'body',
      anchor: 'a3',
    })
    expect(toggle(inner, 'a1', doc, ctx).ids).toEqual(['a1'])
    expect(extend(EMPTY_SELECTION, 'c2', doc, ctx)).toEqual(inner)
    // The section itself is a sibling of a1, not of its own children.
    expect(toggle(single('a1', doc, ctx), 'sec', doc, ctx).ids).toEqual(['a1', 'sec'])
  })

  it('cmd toggles siblings in and out, keeps document order, and moves the anchor when it leaves', () => {
    let sel = single('c3', doc, ctx)
    sel = toggle(sel, 'c1', doc, ctx)
    expect(sel.ids).toEqual(['c1', 'c3'])
    expect(sel.anchor).toBe('c3')
    sel = toggle(sel, 'c3', doc, ctx)
    expect(sel).toEqual({ ids: ['c1'], parent: 'sec', slot: 'content', anchor: 'c1' })
    expect(toggle(sel, 'c1', doc, ctx)).toEqual(EMPTY_SELECTION)
  })

  it('reconcile drops ids the document no longer holds in that slot and re-orders', () => {
    const sel = extend(single('c1', doc, ctx), 'c4', doc, ctx)
    const moved: EditorDocument = {
      fields: {
        body: [b('a1'), b('c2'), b('sec', 'section', { content: [b('c4'), b('c1')] }), b('a3')],
      },
    }
    expect(reconcile(sel, moved, ctx)).toEqual({
      ids: ['c4', 'c1'],
      parent: 'sec',
      slot: 'content',
      anchor: 'c1',
    })
    const gone: EditorDocument = { fields: { body: [b('a1')] } }
    expect(reconcile(sel, gone, ctx)).toEqual(EMPTY_SELECTION)
  })
})
