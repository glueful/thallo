// Fill empty cells (container-layout spec §11.3): a grid's last row completed with column
// containers, as one change. Its own preparation conditions — not the structure picker's "new and
// empty": Fill acts on existing, populated containers.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createGridFill, type GridFillDeps } from '@/editor/structure/gridFill'
import * as legality from '@/editor/structure/legality'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { EditorDocument, OperationBody } from '@/editor/ops/types'
import type { LegalityContext, SlotTypeSummary } from '@/editor/structure/legality'
import type { Breakpoint } from '@/style/types'

const choice = (value: string) => ({ type: 'choice', value })
const heading = (id: string, span?: string): BlockInstance =>
  ({
    id,
    type: 'heading',
    data: {},
    settings: span ? { style: { layout: { span: { base: choice(span) } } } } : {},
  }) as unknown as BlockInstance
const grid = (id: string, content: BlockInstance[] = [], display = 'grid'): BlockInstance =>
  ({
    id,
    type: 'container',
    data: { content },
    settings: {
      style: { layout: { display: { base: choice(display) }, columns: { base: choice('3') } } },
    },
  }) as unknown as BlockInstance
const plain = (id: string, content: BlockInstance[]): BlockInstance =>
  ({ id, type: 'container', data: { content }, settings: {} }) as unknown as BlockInstance
/** `target` wrapped in `depth - 1` plain containers, so it sits at that depth. */
const atDepth = (depth: number, target: BlockInstance): BlockInstance =>
  depth <= 1 ? target : plain(`wrap${depth}`, [atDepth(depth - 1, target)])

function legalityContext(): LegalityContext {
  const types: SlotTypeSummary[] = [
    { slug: 'container', label: 'Container', slots: { content: { blockTypes: [] } } },
    { slug: 'heading', label: 'Heading', slots: {} },
  ]
  return {
    regionsOf: (slug) => Object.keys(types.find((t) => t.slug === slug)?.slots ?? {}),
    blockTypes: () => types,
    rootSlots: () => ({ body: { blockTypes: [] } }),
    maxDepth: 5,
  }
}

function harness(body: BlockInstance[], overrides: Partial<GridFillDeps> = {}) {
  const doc = { value: { fields: { body } } as EditorDocument }
  const committed: OperationBody[][] = []
  const notified: string[] = []
  const breakpoint = { value: 'base' as Breakpoint }
  let changes = 0
  let n = 0
  const factory = vi.fn(async (slug: string) => {
    n += 1
    return { id: `f${n}`, type: slug, data: { content: [] }, settings: {} }
  })
  const deps: GridFillDeps = {
    doc: () => doc.value,
    legality: legalityContext,
    classesFor: () => [],
    activeBreakpoint: () => breakpoint.value,
    factory: factory as unknown as GridFillDeps['factory'],
    commit: async (ops) => {
      committed.push(ops)
    },
    notify: (message) => notified.push(message),
    changed: () => {
      changes += 1
    },
    ...overrides,
  }
  return {
    fill: createGridFill(deps),
    doc,
    committed,
    notified,
    breakpoint,
    factory,
    changes: () => changes,
  }
}

function gate() {
  let open: () => void = () => {}
  const held = new Promise<void>((resolve) => (open = resolve))
  let n = 0
  const factory = vi.fn(async (slug: string) => {
    await held
    n += 1
    return { id: `g${n}`, type: slug, data: { content: [] }, settings: {} }
  })
  return { factory: factory as unknown as GridFillDeps['factory'], release: () => open() }
}

const inserts = (ops: OperationBody[]) =>
  ops.map((op) => op as Extract<OperationBody, { type: 'InsertBlock' }>)

beforeEach(() => vi.restoreAllMocks())

describe('availability', () => {
  it('is not shown for a container that is not a grid at the breakpoint', () => {
    const h = harness([grid('g1', [], 'flex')])
    expect(h.fill.availability('g1', 'base')).toMatchObject({ visible: false, enabled: false })
  })

  it('counts the cells an appended block can reach', () => {
    expect(harness([grid('g1')]).fill.availability('g1', 'base')).toMatchObject({
      visible: true,
      enabled: true,
      cells: 3,
    })
    const spans = harness([grid('g1', [heading('a', '2'), heading('b', '2')])])
    expect(spans.fill.availability('g1', 'base').cells).toBe(1)
  })

  it('is disabled, with its reason, when the last row is full', () => {
    const h = harness([grid('g1', [heading('a'), heading('b'), heading('c')])])
    expect(h.fill.availability('g1', 'base')).toEqual({
      visible: true,
      enabled: false,
      cells: 0,
      reason: 'No empty cells in the last row',
    })
  })

  it('needs room for the cell AND a block inside it: depth three, not four', () => {
    // A cell under a depth-four grid would sit at five — legal, and impossible to fill.
    expect(harness([atDepth(3, grid('g1'))]).fill.availability('g1', 'base').enabled).toBe(true)
    const four = harness([atDepth(4, grid('g1'))]).fill.availability('g1', 'base')
    expect(four.enabled).toBe(false)
    expect(four.reason).toBe(
      'A cell here could not hold a block: blocks nest at most 5 levels deep',
    )
  })

  it("carries legality's own reason when the candidate is refused", () => {
    const closed: LegalityContext = {
      ...legalityContext(),
      blockTypes: (): SlotTypeSummary[] => [
        { slug: 'container', label: 'Container', slots: { content: { blockTypes: ['heading'] } } },
        { slug: 'heading', label: 'Heading', slots: {} },
      ],
    }
    const h = harness([grid('g1')], { legality: () => closed })
    const a = h.fill.availability('g1', 'base')
    expect(a.enabled).toBe(false)
    expect(a.reason).toBeTruthy()
  })
})

describe('fill', () => {
  it('fills an empty grid with a row of column containers, as one change', async () => {
    const h = harness([grid('g1')])
    await h.fill.fill('g1', 'base')
    expect(h.committed).toHaveLength(1)
    const ops = inserts(h.committed[0]!)
    expect(ops.map((op) => [op.type, op.position])).toEqual([
      ['InsertBlock', { parent: 'g1', slot: 'content', index: 0 }],
      ['InsertBlock', { parent: 'g1', slot: 'content', index: 1 }],
      ['InsertBlock', { parent: 'g1', slot: 'content', index: 2 }],
    ])
    // Each is a column container (spec §6.5) and nothing else, with an id of its own — the ids
    // ride in the recorded operations, which is what redo replays.
    const ids = ops.map((op) => op.block.id)
    expect(new Set(ids).size).toBe(3)
    for (const op of ops) {
      expect(op.block.type).toBe('container')
      expect(op.block.settings).toEqual({
        style: {
          layout: {
            display: { base: choice('flex'), md: choice('flex'), lg: choice('flex') },
            direction: { base: choice('column'), md: choice('column'), lg: choice('column') },
            gap: { row: { base: { type: 'token', value: 'spacing.md' } } },
          },
        },
      })
    }
    expect(h.factory).toHaveBeenCalledTimes(1)
  })

  it('appends after what is there: one child leaves two, a span of two leaves one', async () => {
    const one = harness([grid('g1', [heading('a')])])
    await one.fill.fill('g1', 'base')
    expect(inserts(one.committed[0]!).map((op) => op.position.index)).toEqual([1, 2])

    const spanned = harness([grid('g1', [heading('a', '2'), heading('b', '2')])])
    await spanned.fill.fill('g1', 'base')
    expect(inserts(spanned.committed[0]!).map((op) => op.position.index)).toEqual([2])
  })

  it('writes nothing where it is not available', async () => {
    const full = harness([grid('g1', [heading('a'), heading('b'), heading('c')])])
    await full.fill.fill('g1', 'base')
    const deep = harness([atDepth(4, grid('g1'))])
    await deep.fill.fill('g1', 'base')
    const flex = harness([grid('g1', [], 'flex')])
    await flex.fill.fill('g1', 'base')
    expect([full.committed, deep.committed, flex.committed]).toEqual([[], [], []])
    expect(full.factory).not.toHaveBeenCalled()
  })

  it('recounts after the factory answers: a block added meanwhile is not filled over', async () => {
    const g = gate()
    const h = harness([grid('g1')], { factory: g.factory })
    const pending = h.fill.fill('g1', 'base')
    h.doc.value = { fields: { body: [grid('g1', [heading('late')])] } } as EditorDocument
    g.release()
    await pending
    expect(inserts(h.committed[0]!).map((op) => op.position.index)).toEqual([1, 2])
  })

  it('re-runs EVERY condition before committing: a grid moved to depth four while loading', async () => {
    // Everything is judged again after the await: the grid that was at depth three when Fill was
    // pressed is at four by the time the factory answers, where a cell would sit at five.
    const sequence = vi.spyOn(legality, 'checkInsertSequence')
    const g = gate()
    const h = harness([atDepth(3, grid('g1'))], { factory: g.factory })
    const pending = h.fill.fill('g1', 'base')
    h.doc.value = { fields: { body: [atDepth(4, grid('g1'))] } } as EditorDocument
    g.release()
    await pending
    expect(h.committed).toEqual([])
    expect(h.notified).toEqual([])
    // Legality agrees: a cell is a container, and a block that holds blocks needs a level below
    // it — the server refuses its list at the cap even while it is empty. Fill's own condition
    // says the same thing first, in the author's terms.
    const verdict = legality.checkInsertSequence(
      h.doc.value,
      [{ position: { parent: 'g1', slot: 'content', index: 0 }, block: plain('x', []) }],
      legalityContext(),
    )
    expect(verdict.ok ? '' : verdict.reason).toBe('depth')
    sequence.mockRestore()
  })

  it('cancels quietly when the target goes, leaves grid mode, or the breakpoint changes', async () => {
    for (const change of ['gone', 'flex', 'breakpoint'] as const) {
      const g = gate()
      const h = harness([grid('g1')], { factory: g.factory })
      const pending = h.fill.fill('g1', 'base')
      if (change === 'gone') h.doc.value = { fields: { body: [] } } as EditorDocument
      if (change === 'flex')
        h.doc.value = { fields: { body: [grid('g1', [], 'flex')] } } as EditorDocument
      if (change === 'breakpoint') h.breakpoint.value = 'md'
      g.release()
      await pending
      expect(h.committed, change).toEqual([])
      expect(h.notified, change).toEqual([])
    }
  })

  it('ignores a second request while one is preparing, and says so while it is', async () => {
    const g = gate()
    const h = harness([grid('g1')], { factory: g.factory })
    const first = h.fill.fill('g1', 'base')
    expect(h.fill.preparing('g1')).toBe(true)
    const second = h.fill.fill('g1', 'base')
    g.release()
    await Promise.all([first, second])
    expect(h.committed).toHaveLength(1)
    expect(h.fill.preparing('g1')).toBe(false)
    expect(h.changes()).toBeGreaterThanOrEqual(2) // into preparing, and out of it
  })

  it('a factory failure is reported, writes nothing, and a later request works', async () => {
    let fail = true
    const factory = vi.fn(async (slug: string) => {
      if (fail) throw new Error('offline')
      return { id: 'ok1', type: slug, data: { content: [] }, settings: {} }
    })
    const h = harness([grid('g1')], { factory: factory as unknown as GridFillDeps['factory'] })
    await h.fill.fill('g1', 'base')
    expect(h.committed).toEqual([])
    expect(h.notified).toHaveLength(1)
    expect(h.fill.preparing('g1')).toBe(false)
    fail = false
    await h.fill.fill('g1', 'base')
    expect(h.committed).toHaveLength(1)
  })

  it('judges the candidate with the insertion validator, never the one for blocks already in the tree', async () => {
    const sequence = vi.spyOn(legality, 'checkInsertSequence')
    const moves = vi.spyOn(legality, 'checkMoves')
    const h = harness([grid('g1')])
    await h.fill.fill('g1', 'base')
    expect(sequence).toHaveBeenCalled()
    expect(moves).not.toHaveBeenCalled()
    const judged = sequence.mock.calls[sequence.mock.calls.length - 1]![1]
    expect(judged).toHaveLength(3)
  })
})

describe('what the stage is told (spec §11.3)', () => {
  // The stage draws its button on an EMPTY grid's placeholder, so the list names empty grids
  // only — each with exactly what `availability` says, which is what the inspector reads too.
  it('names every empty grid with its availability, in document order, and nothing else', () => {
    const h = harness([
      grid('empty-a'),
      plain('plain-empty', []),
      grid('populated', [heading('h1')]),
      atDepth(4, grid('empty-deep')),
      grid('flex-one', [], 'flex'),
    ])
    expect(h.fill.stageStates('base')).toEqual([
      { id: 'empty-a', enabled: true, preparing: false },
      {
        id: 'empty-deep',
        enabled: false,
        preparing: false,
        reason: 'A cell here could not hold a block: blocks nest at most 5 levels deep',
      },
    ])
  })

  it('says preparing while a fill is loading', async () => {
    const held = gate()
    const h = harness([grid('empty-a')], { factory: held.factory })
    const pending = h.fill.fill('empty-a', 'base')
    expect(h.fill.stageStates('base')).toEqual([{ id: 'empty-a', enabled: true, preparing: true }])
    held.release()
    await pending
  })
})
