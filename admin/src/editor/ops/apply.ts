// One pure applier per operation (visual builder spec §3.1). The existing pure list operations
// are the appliers for structure; settings and fields are addressed by path. Every applier
// returns a new document (structural sharing where untouched) and never throws on a
// no-op: an operation that no longer matches the tree leaves it unchanged.
import {
  createBlockListOps,
  type BlockInstance,
  type RegionResolver,
} from '@/fields/components/blocks/useBlockListOps'
import { propertyDefinition } from '@/style/schema'
import type { ChangeValue, EditorDocument, Operation, Position } from './types'

type Json = Record<string, unknown>

function clone<T>(value: T): T {
  return value === undefined ? value : (JSON.parse(JSON.stringify(value)) as T)
}

/** Set (or, for an absent change, delete) a dotted path inside an object, pruning empty parents. */
export function setPath(target: Json, segments: string[], change: ChangeValue<unknown>): Json {
  const [head, ...rest] = segments
  if (head === undefined) return target
  const next: Json = { ...target }
  if (rest.length === 0) {
    if (change.present) next[head] = clone(change.value)
    else delete next[head]
    return next
  }
  const inner = typeof next[head] === 'object' && next[head] !== null ? (next[head] as Json) : {}
  const updated = setPath(inner, rest, change)
  if (Object.keys(updated).length === 0) delete next[head]
  else next[head] = updated
  return next
}

export function readPath(target: Json, segments: string[]): ChangeValue<unknown> {
  let node: unknown = target
  for (const segment of segments) {
    if (typeof node !== 'object' || node === null || !(segment in (node as Json)))
      return { present: false }
    node = (node as Json)[segment]
  }
  return { present: true, value: node === undefined ? null : node }
}

/** The settings path a `SetSetting` addresses: `style.<path>[.<breakpoint>]`. */
export function settingSegments(path: string, breakpoint: string | null): string[] {
  const segments = ['style', ...path.split('.')]
  const def = propertyDefinition(path)
  if (breakpoint !== null && (def === null || def.responsive)) segments.push(breakpoint)
  return segments
}

export function createOperationApplier(regionsOf: RegionResolver) {
  const ops = createBlockListOps(regionsOf)

  const patchBlock = (
    blocks: BlockInstance[],
    id: string,
    fn: (block: BlockInstance) => BlockInstance,
  ): BlockInstance[] => {
    const found = ops.findById(blocks, id)
    if (!found) return blocks
    const patched = fn(found)
    if (patched === found) return blocks
    const at = ops.locateById(blocks, id)
    if (!at) return blocks
    return ops.insertAt(
      ops.removeById(blocks, id),
      { parentId: at.parentId, region: at.region, index: at.index },
      patched,
    )
  }

  const target = (at: Position) => ({ parentId: at.parent, region: at.slot, index: at.index })

  const classes = (block: BlockInstance): string[] =>
    Array.isArray(block.settings.classes) ? (block.settings.classes as string[]) : []

  const withClasses = (block: BlockInstance, list: string[]): BlockInstance => {
    const settings: Json = { ...block.settings }
    if (list.length === 0) delete settings.classes
    else settings.classes = list
    return { ...block, settings }
  }

  function applyOperation(doc: EditorDocument, op: Operation): EditorDocument {
    const next = apply(doc, op)
    return next.blocks === doc.blocks && next.page === doc.page ? doc : next
  }

  function apply(doc: EditorDocument, op: Operation): EditorDocument {
    switch (op.type) {
      case 'SetField':
        return {
          ...doc,
          blocks: patchBlock(doc.blocks, op.block, (b) => ({
            ...b,
            data: setPath(b.data, [op.field], op.to),
          })),
        }
      case 'SetSetting':
        return {
          ...doc,
          blocks: patchBlock(doc.blocks, op.block, (b) => ({
            ...b,
            settings: setPath(b.settings, settingSegments(op.path, op.breakpoint), op.to),
          })),
        }
      case 'SetAdvanced':
        return {
          ...doc,
          blocks: patchBlock(doc.blocks, op.block, (b) => ({
            ...b,
            settings: setPath(b.settings, ['advanced', ...op.path.split('.')], op.to),
          })),
        }
      case 'ApplyStyleClass':
        return {
          ...doc,
          blocks: patchBlock(doc.blocks, op.block, (b) => {
            const list = classes(b).filter((id) => id !== op.class_id)
            list.splice(Math.max(0, Math.min(op.index, list.length)), 0, op.class_id)
            return withClasses(b, list)
          }),
        }
      case 'RemoveStyleClass':
        return {
          ...doc,
          blocks: patchBlock(doc.blocks, op.block, (b) =>
            withClasses(
              b,
              classes(b).filter((id) => id !== op.class_id),
            ),
          ),
        }
      case 'ReorderStyleClasses':
        return {
          ...doc,
          blocks: patchBlock(doc.blocks, op.block, (b) => withClasses(b, [...op.to])),
        }
      case 'DetachStyleClass':
        // Toggles membership so the op is its own inverse with the styles swapped: a block
        // carrying the class loses it and takes `to_style`; a block without it gets the class
        // back at `index` and takes `to_style` (the instance style from before the detach).
        return {
          ...doc,
          blocks: patchBlock(doc.blocks, op.block, (b) => {
            const current = classes(b)
            const list = current.includes(op.class_id)
              ? current.filter((id) => id !== op.class_id)
              : [...current]
            if (!current.includes(op.class_id))
              list.splice(Math.max(0, Math.min(op.index, list.length)), 0, op.class_id)
            const toggled = withClasses(b, list)
            const settings: Json = { ...toggled.settings }
            if (Object.keys(op.to_style).length === 0) delete settings.style
            else settings.style = clone(op.to_style)
            return { ...toggled, settings }
          }),
        }
      case 'InsertBlock':
        return { ...doc, blocks: ops.insertAt(doc.blocks, target(op.position), clone(op.block)) }
      case 'InsertBlocks': {
        let blocks = doc.blocks
        op.blocks.forEach((block, i) => {
          blocks = ops.insertAt(
            blocks,
            { ...target(op.position), index: op.position.index + i },
            clone(block),
          )
        })
        return { ...doc, blocks }
      }
      case 'RemoveBlock':
        return { ...doc, blocks: ops.removeById(doc.blocks, op.block.id) }
      case 'MoveBlock':
        return { ...doc, blocks: ops.moveAcross(doc.blocks, op.block, target(op.to)) }
      case 'DuplicateBlock':
        return { ...doc, blocks: ops.insertAt(doc.blocks, target(op.position), clone(op.block)) }
      case 'SetPageSettings':
        return { ...doc, page: setPath(doc.page, [op.field], op.to) }
    }
  }

  return { applyOperation, listOps: ops }
}

export type OperationApplier = ReturnType<typeof createOperationApplier>
