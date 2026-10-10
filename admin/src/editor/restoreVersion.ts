// Restoring a published version into the draft. The draft takes the version's fields — every
// one, so a field the version did not have is removed — except the settings-schema stamp, which
// describes the draft's own representation rather than its content and stays the draft's.
import type { OperationBody } from '@/editor/ops/types'
import type { PaletteRestoreResponse } from '@/editor/paletteReplacements'

const STAMP = '_schema'

/** The draft's fields once the version is restored into it. */
export function restoredFields(
  current: Record<string, unknown>,
  version: Record<string, unknown>,
): Record<string, unknown> {
  const { [STAMP]: _versionStamp, ...content } = version
  return STAMP in current ? { [STAMP]: current[STAMP], ...content } : content
}

/**
 * The page-settings operations that turn the draft into the restored one: one per field that
 * differs, so a restore is a single transaction and a single undo.
 */
export function restoreOps(
  current: Record<string, unknown>,
  version: Record<string, unknown>,
): OperationBody[] {
  const next = restoredFields(current, version)
  const names = [...new Set([...Object.keys(next), ...Object.keys(current)])]
  const ops: OperationBody[] = []
  for (const field of names) {
    if (JSON.stringify(current[field]) === JSON.stringify(next[field])) continue
    ops.push({
      type: 'SetPageSettings',
      field,
      from: field in current ? { present: true, value: current[field] } : { present: false },
      to: field in next ? { present: true, value: next[field] } : { present: false },
    })
  }
  return ops
}

/**
 * Restore to draft through the server (custom palette spec §4.5): the server loads the version by
 * id and stores it as the draft; the editor then installs it — the response's replacement records
 * reach existing history first, the restored fields are caught up to the ledger, and the restore
 * lands as one transaction already persisted, so undo and redo after it are ordinary edits.
 */
export async function restoreVersionThroughServer<
  R extends PaletteRestoreResponse & {
    draft: { fields: Record<string, unknown>; lock_version: number }
  },
>(deps: {
  current(): Record<string, unknown>
  /** POST …/draft/{locale}/restore with the ledger's boundary. */
  request(paletteThrough: number): Promise<R>
  paletteThrough(): number
  restoreFromResponse(
    res: PaletteRestoreResponse,
    restored: Record<string, unknown>,
    apply: (fields: Record<string, unknown>) => Promise<void>,
  ): Promise<void>
  applyOps(ops: OperationBody[]): Promise<void>
  markPersisted(): void
}): Promise<R> {
  const res = await deps.request(deps.paletteThrough())
  await deps.restoreFromResponse(res, res.draft.fields, async (fields) => {
    const ops = restoreOps(deps.current(), fields)
    if (ops.length > 0) await deps.applyOps(ops)
    deps.markPersisted()
  })
  return res
}
