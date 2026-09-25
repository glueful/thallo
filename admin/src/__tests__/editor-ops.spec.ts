import { describe, expect, it } from 'vitest'
import { createOperationApplier, readPath, setPath } from '@/editor/ops/apply'
import { invertOperation } from '@/editor/ops/invert'
import { absent, present, type EditorDocument, type Operation } from '@/editor/ops/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

const regionsOf = (type: string): string[] => (type === 'section' ? ['content'] : [])
const { applyOperation } = createOperationApplier(regionsOf, () => ['body'])

const meta = { op_id: 'op1', transaction_id: 'tx1', at: '2026-09-14T00:00:00.000Z', session: 's1' }
const block = (
  id: string,
  type = 'heading',
  data: Record<string, unknown> = {},
): BlockInstance => ({
  id,
  type,
  data,
  settings: {},
})
const blocks = (d: EditorDocument): BlockInstance[] => d.fields.body as BlockInstance[]
const root = (index: number) => ({ parent: null, slot: 'body', index })

function doc(): EditorDocument {
  return {
    fields: {
      title: 'Page',
      body: [
        block('h1', 'heading', { text: 'Hello' }),
        block('s1', 'section', {
          title: 'S',
          content: [block('h2', 'heading', { text: 'Inner' })],
        }),
      ],
    },
  }
}

/** Apply then apply every inverse (last first): the document must come back byte-identical. */
function roundTrip(op: Operation, start: EditorDocument = doc()): EditorDocument {
  const after = applyOperation(start, op)
  expect(after).not.toEqual(start)
  let back = after
  for (const inverse of invertOperation(op)) back = applyOperation(back, inverse)
  expect(back).toEqual(start)
  return after
}

describe('editor operations apply and invert to the identical tree', () => {
  it('SetField, SetSetting, SetAdvanced and SetPageSettings round-trip, absent included', () => {
    const after = roundTrip({
      ...meta,
      type: 'SetField',
      block: 'h2',
      field: 'text',
      from: present('Inner'),
      to: present('Changed'),
    })
    const inner = (blocks(after)[1]!.data.content as BlockInstance[])[0]!
    expect(inner.data.text).toBe('Changed')

    const set = roundTrip({
      ...meta,
      type: 'SetSetting',
      block: 'h1',
      path: 'spacing.padding.top',
      breakpoint: 'md',
      from: absent(),
      to: present({ type: 'token', value: 'spacing.lg' }),
    })
    expect(blocks(set)[0]!.settings).toEqual({
      style: { spacing: { padding: { top: { md: { type: 'token', value: 'spacing.lg' } } } } },
    })

    const radius = roundTrip({
      ...meta,
      type: 'SetSetting',
      block: 'h1',
      path: 'radius',
      breakpoint: null,
      from: absent(),
      to: present({ type: 'reset' }),
    })
    expect(blocks(radius)[0]!.settings).toEqual({ style: { radius: { type: 'reset' } } })

    const part = roundTrip({
      ...meta,
      type: 'SetSetting',
      block: 'h1',
      part: 'link',
      path: 'typography.size',
      breakpoint: 'base',
      from: absent(),
      to: present({ type: 'token', value: 'typography.size.sm' }),
    })
    expect(blocks(part)[0]!.settings).toEqual({
      parts: {
        link: { typography: { size: { base: { type: 'token', value: 'typography.size.sm' } } } },
      },
    })

    const adv = roundTrip({
      ...meta,
      type: 'SetAdvanced',
      block: 'h1',
      path: 'accessibility.label',
      from: absent(),
      to: present('Intro'),
    })
    expect(blocks(adv)[0]!.settings).toEqual({ advanced: { accessibility: { label: 'Intro' } } })

    const page = roundTrip({
      ...meta,
      type: 'SetPageSettings',
      field: 'slug',
      from: absent(),
      to: present('hello'),
    })
    expect(page.fields.slug).toBe('hello')
  })

  it('an absent target deletes the key and prunes empty parents; null is a value', () => {
    const withValue = setPath({}, ['style', 'radius'], present({ type: 'reset' }))
    expect(setPath(withValue, ['style', 'radius'], absent())).toEqual({})
    expect(setPath({}, ['a', 'b'], present(null))).toEqual({ a: { b: null } })
    expect(readPath({ a: { b: null } }, ['a', 'b'])).toEqual({ present: true, value: null })
    expect(readPath({ a: {} }, ['a', 'b'])).toEqual({ present: false })
    expect(JSON.parse(JSON.stringify(absent()))).toEqual({ present: false })
    expect(JSON.parse(JSON.stringify(present(null)))).toEqual({ present: true, value: null })
  })

  it('structure: insert, remove, move across containers and duplicate round-trip', () => {
    const fresh = block('n1', 'heading', { text: 'New' })
    const inserted = roundTrip({
      ...meta,
      type: 'InsertBlock',
      position: { parent: 's1', slot: 'content', index: 0 },
      block: fresh,
    })
    expect((blocks(inserted)[1]!.data.content as BlockInstance[]).map((b) => b.id)).toEqual([
      'n1',
      'h2',
    ])

    const atRoot = roundTrip({ ...meta, type: 'InsertBlock', position: root(2), block: fresh })
    expect(blocks(atRoot).map((b) => b.id)).toEqual(['h1', 's1', 'n1'])

    roundTrip({
      ...meta,
      type: 'RemoveBlock',
      position: root(0),
      block: block('h1', 'heading', { text: 'Hello' }),
    })

    const moved = roundTrip({
      ...meta,
      type: 'MoveBlock',
      block: 'h2',
      from: { parent: 's1', slot: 'content', index: 0 },
      to: root(0),
    })
    expect(blocks(moved).map((b) => b.id)).toEqual(['h2', 'h1', 's1'])

    const copy = block('h1copy', 'heading', { text: 'Hello' })
    const dup = roundTrip({
      ...meta,
      type: 'DuplicateBlock',
      source: 'h1',
      position: root(1),
      block: copy,
    })
    expect(blocks(dup).map((b) => b.id)).toEqual(['h1', 'h1copy', 's1'])
  })

  it('InsertBlocks carries allocated subtrees, inverts to one removal each and reuses ids on redo', () => {
    const op: Operation = {
      ...meta,
      type: 'InsertBlocks',
      position: root(1),
      blocks: [block('a'), block('b', 'section', { content: [block('c')] })],
    }
    const after = roundTrip(op)
    expect(blocks(after).map((b) => b.id)).toEqual(['h1', 'a', 'b', 's1'])
    const inverses = invertOperation(op)
    expect(inverses.map((i) => i.type)).toEqual(['RemoveBlock', 'RemoveBlock'])
    expect(inverses.map((i) => (i as { block: BlockInstance }).block.id)).toEqual(['b', 'a'])
    // A redo replays the same op: the same ids, no fresh allocation.
    expect(
      blocks(applyOperation(applyOperation(after, inverses[0]!), op)).map((b) => b.id),
    ).toContain('b')
    expect(JSON.parse(JSON.stringify(op))).toEqual(op)
  })

  it('style classes: apply, remove, reorder and detach round-trip', () => {
    const start: EditorDocument = {
      fields: { body: [{ ...block('h1'), settings: { classes: ['a', 'c'] } }] },
    }
    const applied = roundTrip(
      { ...meta, type: 'ApplyStyleClass', block: 'h1', class_id: 'b', index: 1 },
      start,
    )
    expect(blocks(applied)[0]!.settings.classes).toEqual(['a', 'b', 'c'])
    roundTrip({ ...meta, type: 'RemoveStyleClass', block: 'h1', class_id: 'a', index: 0 }, start)
    roundTrip(
      { ...meta, type: 'ReorderStyleClasses', block: 'h1', from: ['a', 'c'], to: ['c', 'a'] },
      start,
    )
    const detached = roundTrip(
      {
        ...meta,
        type: 'DetachStyleClass',
        block: 'h1',
        class_id: 'c',
        index: 1,
        from_style: {},
        to_style: { radius: { type: 'token', value: 'radius.lg' } },
      },
      start,
    )
    expect(blocks(detached)[0]!.settings).toEqual({
      classes: ['a'],
      style: { radius: { type: 'token', value: 'radius.lg' } },
    })
  })

  it('an operation that no longer matches the tree is a no-op', () => {
    const start = doc()
    expect(
      applyOperation(start, {
        ...meta,
        type: 'SetField',
        block: 'ghost',
        field: 'x',
        from: absent(),
        to: present(1),
      }),
    ).toBe(start)
    expect(
      applyOperation(start, {
        ...meta,
        type: 'InsertBlock',
        position: { parent: 'ghost', slot: 'content', index: 0 },
        block: block('n'),
      }),
    ).toBe(start)
  })
})
