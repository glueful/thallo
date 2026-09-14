// The editor session (visual builder spec §3.1): every operation names the session that made
// it, and ids are allocated once so a redo reuses them.
import { newBlockId } from '@/fields/components/blocks/useBlockListOps'

export interface EditorSession {
  id: string
  startedAt: string
}

export function newEditorSession(now: () => Date = () => new Date()): EditorSession {
  return { id: newBlockId(), startedAt: now().toISOString() }
}

export function newOperationId(): string {
  return newBlockId()
}

export function newTransactionId(): string {
  return newBlockId()
}
