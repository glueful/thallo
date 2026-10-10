// Replacement records (custom palette spec §4.5, §5.3): each completed brand colour replacement,
// sent to editors as complete batches over a palette generation range. One ledger per editor
// session holds a contiguous boundary: every record at or below it has been applied (or was
// already in the loaded document), none above it has. A batch that starts beyond the boundary may
// be missing a record, so nothing in it is applied until the missing range is fetched whole; a
// range below pruned history expires the ledger, and an expired ledger refuses all reconciliation
// until the editor reloads.
import type { EditorDocument } from './ops/types'
import { applyPaletteRewrites, mapPaletteTokens, type PaletteRewrite } from './paletteRewrites'

export interface ReplacementRecord {
  id: string
  slot: number
  map: Record<string, string>
  completed_generation: number
}

export interface ReplacementBatch {
  after: number
  through: number
  records: ReplacementRecord[]
}

/** A save's batch when the range reached below pruned history. */
export interface ExpiredBatch {
  expired: true
}

export function createReplacementLedger(baseline: number) {
  let through = baseline
  let expired = false
  /** completed_generation → record, for catching a restore up to the boundary. */
  const applied = new Map<number, ReplacementRecord>()
  return {
    get through() {
      return through
    },
    get expired() {
      return expired
    },
    /** Records this editor applied with after < completed_generation <= upTo, in order. */
    appliedBetween(after: number, upTo: number): ReplacementRecord[] {
      return [...applied.values()]
        .filter((r) => r.completed_generation > after && r.completed_generation <= upTo)
        .sort((a, b) => a.completed_generation - b.completed_generation)
    },
    remember(record: ReplacementRecord): void {
      applied.set(record.completed_generation, record)
    },
    /** 'apply' when the batch covers the boundary, 'gap' when it starts beyond it, 'stale' when it adds nothing. */
    accept(batch: ReplacementBatch): 'apply' | 'gap' | 'stale' {
      if (expired || batch.through <= through) return 'stale'
      return batch.after <= through ? 'apply' : 'gap'
    },
    /** The batch's records above the boundary, in completion order. */
    take(batch: ReplacementBatch): ReplacementRecord[] {
      return batch.records
        .filter((r) => r.completed_generation > through && r.completed_generation <= batch.through)
        .sort((a, b) => a.completed_generation - b.completed_generation)
    },
    /** Forwards only. */
    advance(next: number): void {
      through = Math.max(through, next)
    },
    expire(): void {
      expired = true
    },
    reset(generation: number): void {
      through = generation
      expired = false
      applied.clear()
    },
  }
}

export type ReplacementLedger = ReturnType<typeof createReplacementLedger>

export interface ReconcilableHistory {
  reconcileTokens(mapping: Record<string, string>): void
  adopt(next: EditorDocument): void
  lockUndo(locked: boolean): void
}

export interface PaletteSaveOutcome {
  palette_rewrites?: PaletteRewrite[]
  palette_replacements?: ReplacementBatch | ExpiredBatch
}

/** A save response's palette fields, read from its untyped `data` (an older server sends none). */
export function paletteOutcomeOf(data: Record<string, unknown>): PaletteSaveOutcome {
  const out: PaletteSaveOutcome = {}
  if (Array.isArray(data.palette_rewrites))
    out.palette_rewrites = data.palette_rewrites as PaletteRewrite[]
  const batch = data.palette_replacements
  if (typeof batch === 'object' && batch !== null) {
    out.palette_replacements = batch as ReplacementBatch | ExpiredBatch
  }
  return out
}

export interface PaletteRestoreResponse {
  palette_generation: number
  palette_replacements: ReplacementBatch | ExpiredBatch
}

/**
 * One implementation for every editor and its specs: complete batches only, in order, once. Every
 * batch application and every restore installation runs through one queue, so a batch arriving
 * mid-restore (even during the restore's gap fetch) waits its turn, and the reverse.
 */
export function createPaletteReconciler(deps: {
  /** Null for an editor without op history (the form page, saved sections). */
  history: ReconcilableHistory | null
  currentFields(): Record<string, unknown>
  /** Sets the fields model as an adoption: recorded as nothing, not a new edit. */
  replaceFields(next: Record<string, unknown>): void
  ledger: ReplacementLedger
  /** GET …/appearance/palette/replacements; rejects with `status: 410` when the range expired. */
  fetchRange(after: number, through: number): Promise<ReplacementBatch>
  /** Told when the ledger expires, so the editor can show its reload notice. */
  onExpire?(): void
}) {
  let queue: Promise<void> = Promise.resolve()

  function serial(work: () => Promise<void>): Promise<void> {
    const run = queue.then(work, work)
    queue = run.catch(() => undefined)
    return run
  }

  function apply(batch: ReplacementBatch): void {
    for (const record of deps.ledger.take(batch)) {
      deps.history?.reconcileTokens(record.map)
      deps.replaceFields(mapPaletteTokens(deps.currentFields(), record.map))
      deps.ledger.remember(record)
    }
    deps.ledger.advance(batch.through)
  }

  function expire(): void {
    deps.ledger.expire()
    deps.history?.lockUndo(true) // no partial reconciliation: no undo or redo until reload
    deps.onExpire?.()
  }

  async function applyBatchNow(batch: ReplacementBatch | ExpiredBatch): Promise<void> {
    if ('expired' in batch) return expire()
    const verdict = deps.ledger.accept(batch)
    if (verdict === 'stale') return
    if (verdict === 'apply') return apply(batch)
    try {
      const filled = await deps.fetchRange(deps.ledger.through, batch.through) // the missing range, whole
      if (deps.ledger.accept(filled) === 'apply') apply(filled)
    } catch (e: unknown) {
      if ((e as { status?: number }).status === 410) return expire()
      throw e
    }
  }

  return {
    applyBatch(batch: ReplacementBatch | ExpiredBatch): Promise<void> {
      return serial(() => applyBatchNow(batch))
    },
    /**
     * A save's result: the rewrites are facts about the stored document and are adopted now (only
     * where the value still equals `from`); the records queue.
     */
    adoptPaletteResult(outcome: PaletteSaveOutcome): Promise<void> {
      const rewrites = outcome.palette_rewrites ?? []
      if (rewrites.length > 0) {
        const { doc, applied } = applyPaletteRewrites(deps.currentFields(), rewrites)
        if (applied.length > 0) deps.replaceFields(doc)
      }
      const replacements = outcome.palette_replacements
      return replacements ? serial(() => applyBatchNow(replacements)) : Promise.resolve()
    },
    /**
     * Install a restore whose write held generation R: existing history up to R first, then the
     * restored fields caught up through records this editor already applied above R (never applied
     * to history twice), then the restore itself; the boundary only moves forward. An expiry on
     * the way stops it: nothing installed, nothing advanced — the server has the restore, and a
     * reload shows it.
     */
    restoreFromResponse(
      res: PaletteRestoreResponse,
      restoredFields: Record<string, unknown>,
      applyRestore: (fields: Record<string, unknown>) => void | Promise<void>,
    ): Promise<void> {
      return serial(async () => {
        await applyBatchNow(res.palette_replacements)
        if (deps.ledger.expired) return
        const generation = res.palette_generation
        let fields = restoredFields
        for (const record of deps.ledger.appliedBetween(generation, deps.ledger.through)) {
          fields = mapPaletteTokens(fields, record.map)
        }
        await applyRestore(fields)
        deps.ledger.advance(generation)
      })
    },
  }
}

export type PaletteReconciler = ReturnType<typeof createPaletteReconciler>
