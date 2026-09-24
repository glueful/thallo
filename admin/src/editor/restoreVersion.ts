// Restoring a published version into the draft. The draft takes the version's fields — every
// one, so a field the version did not have is removed — except the settings-schema stamp, which
// describes the draft's own representation rather than its content and stays the draft's.
import type { OperationBody } from '@/editor/ops/types'

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
