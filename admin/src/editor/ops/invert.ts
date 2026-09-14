// The inverse of every operation (visual builder spec §3.1): each op carries what its undo
// needs, so inversion is a pure rewrite that keeps the op's identity and transaction. Most
// inverses are one op; undoing a multi-insert is one removal per inserted block, last first.
import type { Operation, OperationMeta } from './types'

function meta(op: Operation): OperationMeta {
  return { op_id: op.op_id, transaction_id: op.transaction_id, at: op.at, session: op.session }
}

export function invertOperation(op: Operation): Operation[] {
  switch (op.type) {
    case 'SetField':
    case 'SetSetting':
    case 'SetAdvanced':
    case 'SetPageSettings':
      return [{ ...op, from: op.to, to: op.from } as Operation]
    case 'ApplyStyleClass':
      return [{ ...op, type: 'RemoveStyleClass' }]
    case 'RemoveStyleClass':
      return [{ ...op, type: 'ApplyStyleClass' }]
    case 'ReorderStyleClasses':
      return [{ ...op, from: op.to, to: op.from }]
    case 'DetachStyleClass':
      // Detach toggles membership (see the applier): the same op with the styles swapped
      // re-attaches the class at its index and restores the instance style.
      return [{ ...op, from_style: op.to_style, to_style: op.from_style }]
    case 'InsertBlock':
      return [{ ...op, type: 'RemoveBlock' }]
    case 'RemoveBlock':
      return [{ ...op, type: 'InsertBlock' }]
    case 'InsertBlocks':
      return op.blocks
        .map((block, i) => ({
          ...op,
          type: 'RemoveBlock' as const,
          position: { ...op.position, index: op.position.index + i },
          block,
        }))
        .reverse()
    case 'MoveBlock':
      return [{ ...op, from: op.to, to: op.from }]
    case 'DuplicateBlock':
      return [{ ...meta(op), type: 'RemoveBlock', position: op.position, block: op.block }]
  }
}
