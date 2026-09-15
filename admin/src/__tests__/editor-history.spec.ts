import { describe, expect, it } from 'vitest'
import { createEditorHistory } from '@/editor/ops/history'
import { absent, present, type EditorDocument } from '@/editor/ops/types'
import type { StyleValue } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

const regionsOf = (type: string): string[] => (type === 'section' ? ['content'] : [])

function history(
  initial?: EditorDocument,
  overrides: { maxEntries?: number; maxBytes?: number } = {},
) {
  const doc: EditorDocument = initial ?? {
    fields: { body: [{ id: 'h1', type: 'heading', data: { text: '' }, settings: {} }] },
  }
  return createEditorHistory(doc, {
    session: 's1',
    regionsOf,
    blockFields: () => ['body'],
    ...overrides,
  })
}

const token = (name: string): StyleValue => ({ type: 'token', value: `spacing.${name}` })
const padding = (h: ReturnType<typeof history>, from: StyleValue | null, to: StyleValue) =>
  h.record({
    type: 'SetSetting',
    block: 'h1',
    path: 'spacing.padding.top',
    breakpoint: 'base',
    from: from === null ? absent() : present(from),
    to: present(to),
  })

describe('transactions commit the minimal delta', () => {
  it('a slider drag sm→md→lg→xl commits one op from sm to xl', () => {
    const h = history()
    h.beginTransaction()
    padding(h, null, token('sm'))
    padding(h, token('sm'), token('md'))
    padding(h, token('md'), token('lg'))
    padding(h, token('lg'), token('xl'))
    const entry = h.commit()
    expect(entry?.ops).toHaveLength(1)
    expect(entry?.ops[0]).toMatchObject({
      type: 'SetSetting',
      from: { present: false },
      to: { present: true, value: token('xl') },
    })
    expect((h.document.fields.body as BlockInstance[])[0]!.settings).toEqual({
      style: { spacing: { padding: { top: { base: token('xl') } } } },
    })
    expect(h.currentSequence).toBe(1)
  })

  it('typing commits one SetField from the start text to the final text', () => {
    const h = history()
    for (const [from, to] of [
      ['', 'H'],
      ['H', 'He'],
      ['He', 'Hel'],
      ['Hel', 'Hello'],
    ]) {
      h.record({
        type: 'SetField',
        block: 'h1',
        field: 'text',
        from: present(from),
        to: present(to),
      })
    }
    const entry = h.commit()
    expect(entry?.ops).toEqual([
      expect.objectContaining({ type: 'SetField', from: present(''), to: present('Hello') }),
    ])
  })

  it('a transaction that ends where it started records nothing', () => {
    const h = history()
    padding(h, null, token('sm'))
    h.record({
      type: 'SetSetting',
      block: 'h1',
      path: 'spacing.padding.top',
      breakpoint: 'base',
      from: present(token('sm')),
      to: absent(),
    })
    expect(h.commit()).toBeNull()
    expect(h.entries()).toEqual([])
    expect(h.isDirty).toBe(false)
  })

  it('cancel restores the start and leaves no entry', () => {
    const h = history()
    const before = h.document
    h.beginTransaction()
    padding(h, null, token('sm'))
    h.record({ type: 'SetField', block: 'h1', field: 'text', from: present(''), to: present('x') })
    h.cancel()
    expect(h.document).toEqual(before)
    expect(h.entries()).toEqual([])
    expect(h.canUndo()).toBe(false)
  })
})

describe('undo, redo and the saved position', () => {
  it('undo and redo walk the sequence; a new edit clears redo, replay does not', () => {
    const h = history()
    padding(h, null, token('sm'))
    h.commit()
    h.record({ type: 'SetField', block: 'h1', field: 'text', from: present(''), to: present('a') })
    h.commit()
    expect(h.currentSequence).toBe(2)
    expect(h.undo()).toBe(true)
    expect((h.document.fields.body as BlockInstance[])[0]!.data.text).toBe('')
    expect(h.currentSequence).toBe(1)
    expect(h.canRedo()).toBe(true)
    expect(h.redo()).toBe(true)
    expect((h.document.fields.body as BlockInstance[])[0]!.data.text).toBe('a')
    expect(h.undo()).toBe(true)
    // Replay keeps the redo stack; a fresh edit clears it.
    expect(h.canRedo()).toBe(true)
    h.record({ type: 'SetField', block: 'h1', field: 'text', from: present(''), to: present('b') })
    h.commit()
    expect(h.canRedo()).toBe(false)
    expect(h.currentSequence).toBe(3)
    expect(h.entries().map((e) => e.sequence)).toEqual([1, 3])
  })

  it('undo settles the active transaction first', () => {
    const h = history()
    padding(h, null, token('sm'))
    h.commit()
    h.record({ type: 'SetField', block: 'h1', field: 'text', from: present(''), to: present('a') })
    expect(h.undo()).toBe(true) // commits "a", then reverts it
    expect((h.document.fields.body as BlockInstance[])[0]!.data.text).toBe('')
    expect(h.currentSequence).toBe(1)
    expect(h.redo()).toBe(true)
    expect((h.document.fields.body as BlockInstance[])[0]!.data.text).toBe('a')
  })

  it('undoing past the saved position makes the document dirty again', () => {
    const h = history()
    padding(h, null, token('sm'))
    h.commit()
    h.markSaved()
    expect(h.isDirty).toBe(false)
    h.record({ type: 'SetField', block: 'h1', field: 'text', from: present(''), to: present('a') })
    h.commit()
    expect(h.isDirty).toBe(true)
    h.undo()
    expect(h.isDirty).toBe(false)
    h.undo()
    expect(h.isDirty).toBe(true)
    expect(h.currentSequence).toBe(0)
  })

  it('a save submitted from an earlier revision marks that position, not the current one', () => {
    const h = history()
    padding(h, null, token('sm'))
    h.commit() // 1
    h.record({ type: 'SetField', block: 'h1', field: 'text', from: present(''), to: present('a') })
    h.commit() // 2
    h.markSaved(1)
    expect(h.savedSequence).toBe(1)
    expect(h.isDirty).toBe(true)
  })

  it('eviction past the saved sequence keeps the document dirty and forbids undo to clean', () => {
    const h = history(undefined, { maxEntries: 2 })
    padding(h, null, token('sm'))
    h.commit() // 1
    h.markSaved()
    for (const [from, to] of [
      ['sm', 'md'],
      ['md', 'lg'],
      ['lg', 'xl'],
    ]) {
      padding(h, token(from!), token(to!))
      h.commit() // 2, 3, 4
    }
    expect(h.entries().map((e) => e.sequence)).toEqual([3, 4])
    expect(h.baseSequence).toBe(2)
    expect(h.savedSequence).toBe(1)
    expect(h.isDirty).toBe(true)
    expect(h.undo()).toBe(true)
    expect(h.undo()).toBe(true)
    expect(h.currentSequence).toBe(2)
    expect(h.undo()).toBe(false)
    expect(h.isDirty).toBe(true)
    h.markSaved()
    expect(h.isDirty).toBe(false)
  })

  it('history is bounded by bytes as well as entries', () => {
    const h = history(undefined, { maxBytes: 600 })
    for (let i = 0; i < 10; i++) {
      h.record({
        type: 'SetField',
        block: 'h1',
        field: 'text',
        from: present('x'.repeat(i)),
        to: present('x'.repeat(i + 1)),
      })
      h.commit()
    }
    expect(h.entries().reduce((sum, e) => sum + e.bytes, 0)).toBeLessThanOrEqual(600)
    expect(h.entries().length).toBeLessThan(10)
    expect(h.currentSequence).toBe(10)
  })
})

describe('a rejected transaction is discarded from the tip', () => {
  it('discardTip inverts the tip entry when it carries that transaction and leaves no redo', () => {
    const h = history()
    const t1 = h.beginTransaction()
    padding(h, null, token('sm'))
    h.commit()
    const t2 = h.beginTransaction()
    padding(h, token('sm'), token('lg'))
    h.commit()
    expect(h.currentSequence).toBe(2)

    expect(h.discardTip(t1)).toBe(false) // not the tip
    expect(h.discardTip(t2)).toBe(true)
    expect(h.currentSequence).toBe(1)
    expect(h.canRedo()).toBe(false) // nothing to redo into: the entry is gone, not undone
    const block = (h.document.fields.body as BlockInstance[])[0]!
    expect(block.settings).toEqual({
      style: { spacing: { padding: { top: { base: token('sm') } } } },
    })
    expect(h.entries().map((e) => e.sequence)).toEqual([1])
  })

  it('discardTip refuses while a transaction is open or after a later entry', () => {
    const h = history()
    const t1 = h.beginTransaction()
    padding(h, null, token('sm'))
    h.commit()
    h.beginTransaction()
    padding(h, token('sm'), token('lg'))
    expect(h.discardTip(t1)).toBe(false) // an open transaction sits above it
    h.commit()
    expect(h.discardTip(t1)).toBe(false)
    expect(h.currentSequence).toBe(2)
  })
})
