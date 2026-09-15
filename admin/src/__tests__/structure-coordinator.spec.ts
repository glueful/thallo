import { describe, expect, it } from 'vitest'
import { createDragCoordinator } from '@/editor/structure/coordinator'
import type { LegalityContext, SlotTypeSummary } from '@/editor/structure/legality'
import { createOperationApplier } from '@/editor/ops/apply'
import type { EditorDocument, Operation, OperationBody } from '@/editor/ops/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

// Visual builder spec §5.1, §5.3, §5.6: one coordinator generates every structural operation;
// the tree is untouched until drop; a group commits as one transaction that replays to the
// candidate tree exactly.
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
const section = (id: string, content: BlockInstance[]) => block(id, 'section', { content })
const regionsOf = (slug: string) => (slug === 'section' ? ['content'] : [])
const types: SlotTypeSummary[] = [
  { slug: 'section', label: 'Section', slots: { content: { blockTypes: [] } } },
  { slug: 'heading', label: 'Heading', slots: {} },
]
const legality = (): LegalityContext => ({
  regionsOf,
  blockTypes: () => types,
  rootSlots: () => ({ body: { blockTypes: [] } }),
  maxDepth: 5,
})
const applier = createOperationApplier(regionsOf, () => ['body'])
const meta = { op_id: 'o', transaction_id: 't', at: '', session: 's' }
const replay = (doc: EditorDocument, ops: OperationBody[]): EditorDocument => {
  let out = doc
  for (const body of ops) out = applier.applyOperation(out, { ...meta, ...body } as Operation)
  return out
}
const ids = (doc: EditorDocument, path: (string | number)[]): string[] => {
  let node: unknown = doc.fields
  for (const p of path) node = (node as Record<string | number, unknown>)[p]
  return (node as BlockInstance[]).map((b) => b.id)
}

function harness(fields: Record<string, unknown>) {
  const doc: EditorDocument = { fields }
  const snapshot = JSON.stringify(doc)
  const coordinator = createDragCoordinator({ doc: () => doc, legality })
  return { doc, snapshot, coordinator }
}

describe('the drag coordinator', () => {
  it('a palette drop is one InsertBlock at the zone; the outline, the stage and the list all yield MoveBlock', () => {
    const { doc, coordinator } = harness({ body: [block('a'), section('s', [])] })
    coordinator.begin('palette', { block: block('new') })
    expect(coordinator.propose({ parent: 's', slot: 'content', index: 0 })?.verdict.ok).toBe(true)
    const insert = coordinator.drop()!
    expect(insert).toEqual([
      {
        type: 'InsertBlock',
        position: { parent: 's', slot: 'content', index: 0 },
        block: block('new'),
      },
    ])
    expect(ids(replay(doc, insert), ['body', 1, 'data', 'content'])).toEqual(['new'])

    for (const source of ['outline', 'stage', 'list'] as const) {
      coordinator.begin(source, { blocks: ['a'] })
      coordinator.propose({ parent: 's', slot: 'content', index: 0 })
      const ops = coordinator.drop()!
      expect(ops.map((o) => o.type)).toEqual(['MoveBlock'])
      expect(ops[0]).toMatchObject({
        block: 'a',
        from: { parent: null, slot: 'body', index: 0 },
        to: { parent: 's', slot: 'content', index: 0 },
      })
      expect(ids(replay(doc, ops), ['body', 0, 'data', 'content'])).toEqual(['a'])
    }
  })

  it('adjacent siblings moved downward in place get indices that replay to the candidate', () => {
    const { doc, coordinator } = harness({ body: [block('a'), block('b'), block('c'), block('d')] })
    coordinator.begin('outline', { blocks: ['a', 'b'] })
    coordinator.propose({ parent: null, slot: 'body', index: 1 })
    const ops = coordinator.drop()!
    expect(ops.map((o) => o.type)).toEqual(['MoveBlock', 'MoveBlock'])
    expect(ids(replay(doc, ops), ['body'])).toEqual(['c', 'a', 'b', 'd'])
  })

  it('siblings moved across containers shift their indices after the removal', () => {
    const { doc, coordinator } = harness({
      body: [block('a'), block('b'), section('s', [block('x')]), block('c')],
    })
    coordinator.begin('stage', { blocks: ['a', 'b'] })
    coordinator.propose({ parent: 's', slot: 'content', index: 1 })
    const ops = coordinator.drop()!
    const after = replay(doc, ops)
    expect(ids(after, ['body'])).toEqual(['s', 'c'])
    expect(ids(after, ['body', 0, 'data', 'content'])).toEqual(['x', 'a', 'b'])
    expect(ops[0]).toMatchObject({ from: { parent: null, slot: 'body', index: 0 } })
    expect(ops[1]).toMatchObject({ from: { parent: null, slot: 'body', index: 0 } }) // b moved up after a left
  })

  it('an illegal proposal drops nothing and names the reason; cancel leaves the document byte-identical', () => {
    const { doc, snapshot, coordinator } = harness({
      body: [section('outer', [section('inner', [])])],
    })
    coordinator.begin('stage', { blocks: ['outer'] })
    const proposal = coordinator.propose({ parent: 'inner', slot: 'content', index: 0 })!
    expect(proposal.verdict).toMatchObject({ ok: false, reason: 'cycle' })
    expect(coordinator.drop()).toBeNull()
    expect(coordinator.state()).toBeNull()

    coordinator.begin('outline', { blocks: ['outer'] })
    coordinator.propose({ parent: null, slot: 'body', index: 0 })
    coordinator.cancel()
    expect(coordinator.state()).toBeNull()
    expect(coordinator.drop()).toBeNull()
    expect(JSON.stringify(doc)).toBe(snapshot)
  })

  it('a second begin cancels the first session', () => {
    const { coordinator } = harness({ body: [block('a')] })
    const first = coordinator.begin('stage', { blocks: ['a'] })
    const second = coordinator.begin('outline', { blocks: ['a'] })
    expect(second).not.toBe(first)
    expect(coordinator.state()?.session).toBe(second)
    expect(coordinator.state()?.source).toBe('outline')
  })
})
