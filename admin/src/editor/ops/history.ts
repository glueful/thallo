// Transactions and history (visual builder spec §3.2). A transaction collects operations as an
// interaction proceeds (each applied to the document at once, so the canvas follows a drag) and
// commits as the minimal semantic delta: one op per addressed field, setting or advanced path,
// from its first `from` to its last `to`, no-ops dropped. Positions are monotonic sequence
// numbers: `baseSequence` (the oldest undoable state), `currentSequence`, `savedSequence`; the
// document is dirty whenever current differs from saved, and eviction that moves the base past
// the saved sequence keeps it dirty until the next save because undo can no longer reach it.
import type { RegionResolver } from '@/fields/components/blocks/useBlockListOps'
import { createOperationApplier, type BlockFieldsResolver } from './apply'
import { invertOperation } from './invert'
import { newOperationId, newTransactionId } from './session'
import type { EditorDocument, Operation, OperationBody } from './types'

export const HISTORY_MAX_ENTRIES = 200
export const HISTORY_MAX_BYTES = 2 * 1024 * 1024

export interface HistoryEntry {
  sequence: number
  transaction_id: string
  ops: Operation[]
  bytes: number
}

export interface EditorHistoryOptions {
  session: string
  regionsOf: RegionResolver
  blockFields: BlockFieldsResolver
  maxEntries?: number
  maxBytes?: number
  now?: () => Date
}

function deepEqual(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

/** The identity two ops share when they address the same thing and can coalesce. */
function coalesceKey(op: Operation): string | null {
  switch (op.type) {
    case 'SetField':
      return `field:${op.block}:${op.field}`
    case 'SetSetting':
      return `setting:${op.block}:${op.part ?? ''}:${op.path}:${op.breakpoint ?? ''}`
    case 'SetAdvanced':
      return `advanced:${op.block}:${op.path}`
    case 'SetPageSettings':
      return `page:${op.field}`
    case 'ReorderStyleClasses':
      return `classes:${op.block}`
    default:
      // Apply, Remove and Detach never coalesce: each is its own intent, and a detach
      // materialises values whose from/to must survive as recorded (spec §4.4).
      return null
  }
}

function isNoop(op: Operation): boolean {
  switch (op.type) {
    case 'SetField':
    case 'SetSetting':
    case 'SetAdvanced':
    case 'SetPageSettings':
    case 'ReorderStyleClasses':
      return deepEqual(op.from, op.to)
    case 'MoveBlock':
      return deepEqual(op.from, op.to)
    default:
      return false
  }
}

/** Fold a transaction to its minimal semantic delta, keeping first-seen order. */
export function normaliseTransaction(ops: Operation[]): Operation[] {
  const out: Operation[] = []
  const byKey = new Map<string, number>()
  for (const op of ops) {
    const key = coalesceKey(op)
    const at = key === null ? undefined : byKey.get(key)
    if (key !== null && at !== undefined) {
      const first = out[at] as Operation & { to: unknown }
      out[at] = { ...op, from: (first as { from: unknown }).from } as Operation
      continue
    }
    out.push(op)
    if (key !== null) byKey.set(key, out.length - 1)
  }
  return out.filter((op) => !isNoop(op))
}

export function createEditorHistory(initial: EditorDocument, options: EditorHistoryOptions) {
  const applier = createOperationApplier(options.regionsOf, options.blockFields)
  const maxEntries = options.maxEntries ?? HISTORY_MAX_ENTRIES
  const maxBytes = options.maxBytes ?? HISTORY_MAX_BYTES
  const now = options.now ?? (() => new Date())

  let document = initial
  /** Committed entries in sequence order; entries past `currentSequence` are the redo stack. */
  let entries: HistoryEntry[] = []
  let baseSequence = 0
  let currentSequence = 0
  let savedSequence = 0
  let nextSequence = 1
  let active: { id: string; ops: Operation[] } | null = null

  const applyAll = (ops: Operation[]) => {
    for (const op of ops) document = applier.applyOperation(document, op)
  }

  const evict = () => {
    let bytes = entries.reduce((sum, e) => sum + e.bytes, 0)
    while (entries.length > 0 && (entries.length > maxEntries || bytes > maxBytes)) {
      const dropped = entries.shift()!
      bytes -= dropped.bytes
      baseSequence = dropped.sequence
    }
    if (entries.length === 0) baseSequence = currentSequence
  }

  function beginTransaction(): string {
    if (active !== null) commit()
    active = { id: newTransactionId(), ops: [] }
    return active.id
  }

  /** Record an operation in the active transaction (starting one if needed) and apply it now. */
  function record(body: OperationBody): Operation {
    if (active === null) beginTransaction()
    const op: Operation = {
      op_id: newOperationId(),
      transaction_id: active!.id,
      at: now().toISOString(),
      session: options.session,
      ...body,
    }
    document = applier.applyOperation(document, op)
    active!.ops.push(op)
    return op
  }

  /** Commit the active transaction as one history entry; nothing is recorded for a no-op. */
  function commit(): HistoryEntry | null {
    if (active === null) return null
    const ops = normaliseTransaction(active.ops)
    active = null
    if (ops.length === 0) return null
    // New edits clear redo.
    entries = entries.filter((e) => e.sequence <= currentSequence)
    const entry: HistoryEntry = {
      sequence: nextSequence++,
      transaction_id: ops[0]!.transaction_id,
      ops,
      bytes: JSON.stringify(ops).length,
    }
    entries.push(entry)
    currentSequence = entry.sequence
    evict()
    return entry
  }

  /** Abandon the active transaction: restore its start, record nothing. */
  function cancel(): void {
    if (active === null) return
    const ops = active.ops
    active = null
    for (let i = ops.length - 1; i >= 0; i--) applyAll(invertOperation(ops[i]!))
  }

  /**
   * Drop the tip entry when it carries `transactionId` (a rejected apply, visual builder spec
   * §5.3): its ops are inverted on the document and the entry is removed — never a redo entry,
   * since the server refused it. False when a transaction is open, the tip is another
   * transaction, or the tip was undone.
   */
  function discardTip(transactionId: string): boolean {
    if (active !== null) return false
    const tip = entries[entries.length - 1]
    if (!tip || tip.sequence !== currentSequence || tip.transaction_id !== transactionId)
      return false
    for (let i = tip.ops.length - 1; i >= 0; i--) applyAll(invertOperation(tip.ops[i]!))
    entries.pop()
    currentSequence = entries.length > 0 ? entries[entries.length - 1]!.sequence : baseSequence
    if (savedSequence === tip.sequence) savedSequence = currentSequence
    return true
  }

  function canUndo(): boolean {
    return (active !== null && active.ops.length > 0) || currentSequence > baseSequence
  }

  function canRedo(): boolean {
    return active === null && entries.some((e) => e.sequence > currentSequence)
  }

  /** Undo settles the active transaction first, then reverts the latest entry. */
  function undo(): boolean {
    if (active !== null) commit()
    const entry = entries.find((e) => e.sequence === currentSequence)
    if (!entry || currentSequence <= baseSequence) return false
    for (let i = entry.ops.length - 1; i >= 0; i--) applyAll(invertOperation(entry.ops[i]!))
    const index = entries.indexOf(entry)
    currentSequence = index > 0 ? entries[index - 1]!.sequence : baseSequence
    return true
  }

  /** Replay the next entry: the same ops again, so allocated ids are reused. */
  function redo(): boolean {
    if (active !== null) return false
    const entry = entries.find((e) => e.sequence > currentSequence)
    if (!entry) return false
    applyAll(entry.ops)
    currentSequence = entry.sequence
    return true
  }

  /** Mark the state at `sequence` (default: the current one) as saved. */
  function markSaved(sequence: number = currentSequence): void {
    savedSequence = sequence
  }

  /**
   * Adopt a document loaded from the server (a re-hydration after a save) as the current
   * document without recording anything; the saved position is `markSaved`'s business and
   * entries stay undoable by id and path.
   */
  function rebase(next: EditorDocument): void {
    if (active !== null) cancel()
    document = next
  }

  return {
    get document() {
      return document
    },
    get baseSequence() {
      return baseSequence
    },
    get currentSequence() {
      return currentSequence
    },
    get savedSequence() {
      return savedSequence
    },
    get isDirty() {
      return currentSequence !== savedSequence
    },
    get activeTransaction() {
      return active?.id ?? null
    },
    entries: () => entries.slice(),
    beginTransaction,
    record,
    commit,
    cancel,
    discardTip,
    canUndo,
    canRedo,
    undo,
    redo,
    markSaved,
    rebase,
    applier,
  }
}

export type EditorHistory = ReturnType<typeof createEditorHistory>
