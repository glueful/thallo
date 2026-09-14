import { describe, expect, it } from 'vitest'
import { createOperationApplier } from '@/editor/ops/apply'
import { diffDocuments } from '@/editor/ops/diff'
import { invertOperation } from '@/editor/ops/invert'
import type { EditorDocument, Operation } from '@/editor/ops/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

const regionsOf = (type: string): string[] => (type === 'section' ? ['content'] : [])
const blockFields = () => ['body', 'aside']
const applier = createOperationApplier(regionsOf, blockFields)
const meta = { op_id: 'o', transaction_id: 't', at: '', session: 's' }

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

const base = (): EditorDocument => ({
  fields: {
    title: 'T',
    body: [
      block('a', 'heading', { text: 'A' }),
      block('s', 'section', { title: 'S', content: [block('c1'), block('c2')] }),
      block('b', 'heading', { text: 'B' }),
    ],
    aside: [block('x')],
  },
})

/** The derived ops replay to `next`, and their inverses (last first) replay back to `prev`. */
function derive(prev: EditorDocument, next: EditorDocument): Operation[] {
  const ops = diffDocuments(prev, next, regionsOf, blockFields).map((body) => ({
    ...meta,
    ...body,
  }))
  let forward = prev
  for (const op of ops) forward = applier.applyOperation(forward, op)
  expect(forward.fields).toEqual(next.fields)
  let back = forward
  for (let i = ops.length - 1; i >= 0; i--) {
    for (const inverse of invertOperation(ops[i]!)) back = applier.applyOperation(back, inverse)
  }
  expect(back.fields).toEqual(prev.fields)
  return ops
}

describe('diffDocuments derives intent from what changed', () => {
  it('an edited field is one SetField; a page field is one SetPageSettings', () => {
    const next = base()
    ;(next.fields.body as BlockInstance[])[0]!.data.text = 'A2'
    next.fields.title = 'T2'
    const ops = derive(base(), next)
    expect(ops.map((o) => o.type)).toEqual(['SetPageSettings', 'SetField'])
    expect(ops[1]).toMatchObject({
      block: 'a',
      field: 'text',
      from: { present: true, value: 'A' },
      to: { present: true, value: 'A2' },
    })
  })

  it('settings become SetSetting per path and breakpoint, SetAdvanced and class reorders', () => {
    const next = base()
    ;(next.fields.body as BlockInstance[])[0]!.settings = {
      style: {
        spacing: { padding: { top: { md: { type: 'token', value: 'spacing.lg' } } } },
        radius: { type: 'reset' },
      },
      advanced: { anchor: 'intro' },
      classes: ['c1'],
    }
    const ops = derive(base(), next)
    expect(ops.map((o) => o.type)).toEqual([
      'SetSetting',
      'SetSetting',
      'SetAdvanced',
      'ReorderStyleClasses',
    ])
    expect(ops[0]).toMatchObject({ path: 'spacing.padding.top', breakpoint: 'md' })
    expect(ops[1]).toMatchObject({ path: 'radius', breakpoint: null })
  })

  it('a removed block is one RemoveBlock carrying its subtree; an inserted one is one InsertBlock', () => {
    const removed = base()
    removed.fields.body = (removed.fields.body as BlockInstance[]).filter((b) => b.id !== 's')
    const ops = derive(base(), removed)
    expect(ops).toHaveLength(1)
    expect(ops[0]).toMatchObject({
      type: 'RemoveBlock',
      position: { parent: null, slot: 'body', index: 1 },
    })
    expect((ops[0] as { block: BlockInstance }).block.id).toBe('s')

    const inserted = base()
    ;((inserted.fields.body as BlockInstance[])[1]!.data.content as BlockInstance[]).push(
      block('c3'),
    )
    const ins = derive(base(), inserted)
    expect(ins).toHaveLength(1)
    expect(ins[0]).toMatchObject({
      type: 'InsertBlock',
      position: { parent: 's', slot: 'content', index: 2 },
    })
  })

  it('a reorder within a list and a move across containers are MoveBlocks', () => {
    const reordered = base()
    const body = reordered.fields.body as BlockInstance[]
    reordered.fields.body = [body[2]!, body[0]!, body[1]!]
    const ops = derive(base(), reordered)
    expect(ops).toHaveLength(1)
    expect(ops[0]).toMatchObject({
      type: 'MoveBlock',
      block: 'b',
      from: { index: 2 },
      to: { index: 0 },
    })

    const across = base()
    const section = (across.fields.body as BlockInstance[])[1]!
    const c1 = (section.data.content as BlockInstance[])[0]!
    section.data.content = (section.data.content as BlockInstance[]).filter((b) => b.id !== 'c1')
    across.fields.aside = [c1, ...(across.fields.aside as BlockInstance[])]
    const moves = derive(base(), across)
    expect(moves.map((o) => o.type)).toEqual(['MoveBlock'])
    expect(moves[0]).toMatchObject({
      block: 'c1',
      from: { parent: 's', slot: 'content' },
      to: { parent: null, slot: 'aside', index: 0 },
    })
  })

  it('a shape the derivation does not model records whole fields and still round-trips', () => {
    const next = base()
    // The same block id twice: not a tree the derivation models.
    next.fields.body = [...(next.fields.body as BlockInstance[]), block('a')]
    const ops = derive(base(), next)
    expect(ops.map((o) => o.type)).toEqual(['SetPageSettings'])
    expect(ops[0]).toMatchObject({ field: 'body' })
  })

  it('identical documents yield nothing', () => {
    expect(diffDocuments(base(), base(), regionsOf, blockFields)).toEqual([])
  })
})
