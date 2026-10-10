import { describe, expect, it, vi } from 'vitest'
import { createEditorHistory } from '@/editor/ops/history'
import { absent, present, type EditorDocument, type OperationBody } from '@/editor/ops/types'
import { restoreOps } from '@/editor/restoreVersion'
import {
  createPaletteReconciler,
  createReplacementLedger,
  type ReplacementBatch,
  type ReplacementRecord,
} from '@/editor/paletteReplacements'
import type { PaletteRewrite } from '@/editor/paletteRewrites'

// History reconciled by ordered, identified replacement records (custom palette spec §4.5, §5.3):
// complete batches only, each record once and in order, restores never mapped through records
// they postdate, an expired range refusing all reconciliation.

const tok = (v: string) => ({ type: 'token' as const, value: v })
const heading = (id: string, colour: string, text = 'x') => ({
  id,
  type: 'heading',
  data: { text },
  settings: { style: { colors: { text: tok(colour) } } },
})
const rw = (id: string): PaletteRewrite => ({
  location: `${id}:settings.style.colors.text`,
  from: 'color.brand-1',
  to: 'color.accent',
})
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const colourOf = (fields: any, i: number) => fields.body[i].settings.style.colors.text.value
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const textOf = (fields: any, i: number) => fields.body[i].data.text
const A: ReplacementRecord = {
  id: 'jobA',
  slot: 1,
  map: { 'color.brand-1': 'color.brand-2' },
  completed_generation: 5,
}
const B: ReplacementRecord = {
  id: 'jobB',
  slot: 2,
  map: { 'color.brand-2': 'color.accent' },
  completed_generation: 7,
}
const C: ReplacementRecord = {
  id: 'jobC',
  slot: 1,
  map: { 'color.brand-1': 'color.surface' },
  completed_generation: 9,
}
const batch = (after: number, through: number, records: ReplacementRecord[]): ReplacementBatch => ({
  after,
  through,
  records,
})
const setSetting = (block: string, path: string, from: unknown, to: unknown): OperationBody => ({
  type: 'SetSetting',
  block,
  path,
  breakpoint: null,
  from: present(from as never),
  to: present(to as never),
})
const insertBlock = (index: number, block: ReturnType<typeof heading>): OperationBody => ({
  type: 'InsertBlock',
  position: { parent: null, slot: 'body', index },
  block,
})
const removeBlock = (index: number, block: ReturnType<typeof heading>): OperationBody => ({
  type: 'RemoveBlock',
  position: { parent: null, slot: 'body', index },
  block,
})

/** The stage editor's palette wiring over a bare history: the same reconciler, an injectable gap fetch. */
function editorOver(
  initial: EditorDocument,
  opts: {
    paletteGeneration: number
    fetchRange?: (after: number, through: number) => Promise<ReplacementBatch>
  },
) {
  const history = createEditorHistory(initial, {
    session: 's1',
    regionsOf: () => [],
    blockFields: () => ['body'],
  })
  const ledger = createReplacementLedger(opts.paletteGeneration)
  const reconciler = createPaletteReconciler({
    history,
    currentFields: () => history.document.fields,
    replaceFields: (next) => history.adopt({ ...history.document, fields: next }),
    ledger,
    fetchRange: opts.fetchRange ?? (async () => Promise.reject(new Error('no gap fetch expected'))),
  })
  const transaction = (ops: OperationBody[]) => {
    history.beginTransaction()
    for (const op of ops) history.record(op)
    history.commit()
  }
  return {
    history,
    get document() {
      return history.document
    },
    record: (op: OperationBody) => transaction([op]),
    beginTyping: (block: string, field: string, from: string, to: string) => {
      history.beginTransaction()
      history.record({ type: 'SetField', block, field, from: present(from), to: present(to) })
    },
    commitTyping: () => history.commit(),
    undo: () => history.undo(),
    redo: () => history.redo(),
    canUndo: () => history.canUndo(),
    canRedo: () => history.canRedo(),
    isDirty: () => history.isDirty,
    currentSequence: () => history.currentSequence,
    markSaved: (sequence: number) => history.markSaved(sequence),
    applyBatch: reconciler.applyBatch,
    adoptPaletteResult: reconciler.adoptPaletteResult,
    restoreFromResponse: (
      res: {
        palette_generation: number
        palette_replacements: ReplacementBatch | { expired: true }
      },
      fields: Record<string, unknown>,
    ) =>
      reconciler.restoreFromResponse(res, fields, (caughtUp) => {
        transaction(restoreOps(history.document.fields, caughtUp))
        history.markSaved() // the server stored the restore
      }),
    paletteThrough: () => ledger.through,
    paletteExpired: () => ledger.expired,
  }
}

void absent

describe('palette history reconciliation', () => {
  it('a chained pair applied in order maps Brand 1 to Accent, and again changes nothing', async () => {
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text')] } },
      { paletteGeneration: 4 },
    )
    ed.record(setSetting('a', 'colors.text', tok('color.text'), tok('color.brand-1')))
    await ed.applyBatch(batch(4, 8, [B, A])) // delivered out of order within one batch
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
    await ed.applyBatch(batch(4, 8, [A, B])) // a delayed duplicate delivery
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
    ed.undo()
    ed.redo()
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
  })

  it('B in one response and A in a later one ends at Accent, never Brand 2', async () => {
    const fetchRange = vi.fn(async () => batch(4, 8, [A, B])) // the gap fetch
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text')] } },
      { paletteGeneration: 4, fetchRange },
    )
    ed.record(setSetting('a', 'colors.text', tok('color.text'), tok('color.brand-1')))
    await ed.applyBatch(batch(6, 8, [B])) // starts beyond the boundary
    expect(fetchRange).toHaveBeenCalledWith(4, 8)
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
    await ed.applyBatch(batch(4, 6, [A])) // A's own late delivery: stale now
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
  })

  it('an expired range refuses reconciliation and locks undo and redo', async () => {
    const fetchRange = vi.fn(async () => {
      throw Object.assign(new Error('expired'), { status: 410, code: 'PALETTE_HISTORY_EXPIRED' })
    })
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text')] } },
      { paletteGeneration: 4, fetchRange },
    )
    ed.record(setSetting('a', 'colors.text', tok('color.text'), tok('color.brand-1')))
    await ed.applyBatch(batch(6, 8, [B]))
    expect(colourOf(ed.document.fields, 0)).toBe('color.brand-1') // nothing partially applied
    expect(ed.paletteExpired()).toBe(true)
    expect(ed.canUndo()).toBe(false)
    expect(ed.canRedo()).toBe(false)
  })

  it('a reused slot maps only what predates its second replacement', async () => {
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text')] } },
      { paletteGeneration: 8 },
    ) // A and B already in the loaded document
    ed.record(setSetting('a', 'colors.text', tok('color.text'), tok('color.brand-1'))) // the NEW Brand 1 (Ink)
    await ed.applyBatch(batch(8, 10, [C]))
    expect(colourOf(ed.document.fields, 0)).toBe('color.surface') // C only, never A
  })

  it('a restore after completion is never mapped through records it postdates', async () => {
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text')] } },
      { paletteGeneration: 4 },
    )
    // the restore response: record A completed at 5, the restore's write held generation 6
    await ed.restoreFromResponse(
      { palette_generation: 6, palette_replacements: batch(4, 6, [A]) },
      { body: [heading('a', 'color.brand-1')] },
    )
    expect(colourOf(ed.document.fields, 0)).toBe('color.brand-1')
    await ed.applyBatch(batch(0, 6, [A])) // a later schema refresh: stale
    expect(colourOf(ed.document.fields, 0)).toBe('color.brand-1')
    ed.undo()
    ed.redo()
    expect(colourOf(ed.document.fields, 0)).toBe('color.brand-1')
  })

  it('a newer batch applied BEFORE a delayed restore response: the restored content is caught up, history is not mapped twice, the ledger never moves back', async () => {
    // restore committed at 4; replacement A' (brand-1 → accent) completed at 5; the editor sees 5 first
    const A2 = {
      id: 'jobA2',
      slot: 1,
      map: { 'color.brand-1': 'color.accent' },
      completed_generation: 5,
    }
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text')] } },
      { paletteGeneration: 3 },
    )
    ed.record(setSetting('a', 'colors.text', tok('color.text'), tok('color.brand-1')))
    const reconcile = vi.spyOn(ed.history, 'reconcileTokens')
    await ed.applyBatch(batch(3, 5, [A2])) // the schema refresh
    expect(reconcile).toHaveBeenCalledTimes(1)
    await ed.restoreFromResponse(
      { palette_generation: 4, palette_replacements: batch(3, 4, []) },
      { body: [heading('a', 'color.brand-1', 'Restored text')] },
    )
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent') // restored at 4, caught up through 5
    expect(textOf(ed.document.fields, 0)).toBe('Restored text') // a real restore transaction
    expect(reconcile).toHaveBeenCalledTimes(1) // history not mapped again
    expect(ed.paletteThrough()).toBe(5) // not moved back to 4
    ed.undo() // undoes the restore transaction, not the earlier edit
    expect(textOf(ed.document.fields, 0)).toBe('x') // the previous text is back
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent') // and the colour stays reconciled
  })

  it("a newer batch arriving DURING the restore's gap fetch waits, then applies to the restored content once", async () => {
    let releaseFetch!: (b: ReturnType<typeof batch>) => void
    let fetchStarted!: () => void
    const started = new Promise<void>((r) => {
      fetchStarted = r
    })
    const fetchRange = vi.fn(
      () =>
        new Promise<ReturnType<typeof batch>>((r) => {
          releaseFetch = r
          fetchStarted()
        }),
    )
    const A2 = {
      id: 'jobA2',
      slot: 1,
      map: { 'color.brand-1': 'color.accent' },
      completed_generation: 6,
    }
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text')] } },
      { paletteGeneration: 2, fetchRange },
    )
    const restoring = ed.restoreFromResponse(
      { palette_generation: 4, palette_replacements: batch(3, 4, []) },
      { body: [heading('a', 'color.brand-1')] },
    ) // gap (2, 3]
    await started // the gap fetch is really in flight
    const refreshing = ed.applyBatch(batch(4, 6, [A2])) // arrives mid-fetch, queues behind the restore
    releaseFetch(batch(2, 4, []))
    await Promise.all([restoring, refreshing])
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent') // applied after the restore installed
    expect(ed.paletteThrough()).toBe(6)
    expect(fetchRange).toHaveBeenCalledTimes(1)
  })

  it("expiry during a restore's gap fetch stops the restore: nothing installed, nothing advanced, reload required", async () => {
    let fetchStarted!: () => void
    const started = new Promise<void>((r) => {
      fetchStarted = r
    })
    const fetchRange = vi.fn(async () => {
      fetchStarted()
      throw Object.assign(new Error('expired'), { status: 410, code: 'PALETTE_HISTORY_EXPIRED' })
    })
    const A2 = {
      id: 'jobA2',
      slot: 1,
      map: { 'color.brand-1': 'color.accent' },
      completed_generation: 3,
    }
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text', 'Before')] } },
      { paletteGeneration: 2, fetchRange },
    )
    await ed.applyBatch(batch(2, 3, [A2])) // a retained record the catch-up must NOT use
    const restoring = ed.restoreFromResponse(
      { palette_generation: 6, palette_replacements: batch(4, 6, []) },
      { body: [heading('a', 'color.brand-1', 'Restored')] },
    ) // gap (3, 4]
    await started
    await restoring
    expect(ed.paletteExpired()).toBe(true)
    expect(textOf(ed.document.fields, 0)).toBe('Before') // the restore was not installed
    expect(ed.paletteThrough()).toBe(3) // not advanced
    expect(ed.canUndo()).toBe(false)
    expect(ed.canRedo()).toBe(false)
  })

  it('reconciles whole blocks carried by insert, remove and duplicate operations', async () => {
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text')] } },
      { paletteGeneration: 4 },
    )
    ed.record(insertBlock(1, heading('b', 'color.brand-1')))
    ed.record(removeBlock(1, heading('b', 'color.brand-1')))
    await ed.applyBatch(batch(4, 5, [{ ...A, map: { 'color.brand-1': 'color.accent' } }]))
    ed.undo()
    expect(colourOf(ed.document.fields, 1)).toBe('color.accent')
    ed.undo()
    ed.redo()
    expect(colourOf(ed.document.fields, 1)).toBe('color.accent')
  })

  it('adopting a save result keeps an active, uncommitted text transaction and its undo record', async () => {
    const ed = editorOver(
      { fields: { body: [heading('a', 'color.text', 'old')] } },
      { paletteGeneration: 4 },
    )
    ed.record(setSetting('a', 'colors.text', tok('color.text'), tok('color.brand-1')))
    const submitted = ed.currentSequence()
    ed.markSaved(submitted)
    ed.beginTyping('a', 'text', 'old', 'new') // records into the ACTIVE transaction; not committed
    await ed.adoptPaletteResult({
      palette_rewrites: [rw('a')],
      palette_replacements: batch(4, 4, []),
    })
    expect(textOf(ed.document.fields, 0)).toBe('new')
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
    ed.commitTyping() // the debounce fires
    expect(ed.isDirty()).toBe(true)
    ed.undo()
    expect(textOf(ed.document.fields, 0)).toBe('old')
    expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
  })
})
