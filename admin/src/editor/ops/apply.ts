// One pure applier per operation (visual builder spec §3.1). The existing pure list operations
// are the appliers for structure; settings and fields are addressed by path. Every applier
// returns a new document (structural sharing where untouched) and never throws on a
// no-op: an operation that no longer matches the tree leaves the document unchanged.
import {
  createBlockListOps,
  type BlockInstance,
  type RegionResolver,
} from '@/fields/components/blocks/useBlockListOps'
import { propertyDefinition } from '@/style/schema'
import type { ChangeValue, EditorDocument, Operation, Position } from './types'

type Json = Record<string, unknown>

/** The blocks-typed page fields (the block roots), from the content type's schema. */
export type BlockFieldsResolver = () => string[]

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
    if (typeof node !== 'object' || node === null || !(segment in (node as Json))) {
      return { present: false }
    }
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

export function createOperationApplier(
  regionsOf: RegionResolver,
  blockFields: BlockFieldsResolver,
) {
  const ops = createBlockListOps(regionsOf)

  const rootList = (doc: EditorDocument, slot: string | null): BlockInstance[] =>
    slot !== null && Array.isArray(doc.fields[slot]) ? (doc.fields[slot] as BlockInstance[]) : []

  /** The root field whose tree contains `id`, or null. */
  const rootOf = (doc: EditorDocument, id: string): string | null => {
    for (const field of blockFields()) {
      if (ops.findById(rootList(doc, field), id)) return field
    }
    return null
  }

  const withRoot = (doc: EditorDocument, field: string, list: BlockInstance[]): EditorDocument =>
    rootList(doc, field) === list ? doc : { fields: { ...doc.fields, [field]: list } }

  /** Rebuild the block with `id` wherever it lives; unchanged when absent. */
  const patchBlock = (
    doc: EditorDocument,
    id: string,
    fn: (block: BlockInstance) => BlockInstance,
  ): EditorDocument => {
    const field = rootOf(doc, id)
    if (field === null) return doc
    const list = rootList(doc, field)
    const found = ops.findById(list, id)!
    const patched = fn(found)
    if (patched === found) return doc
    const at = ops.locateById(list, id)!
    const next = ops.insertAt(
      ops.removeById(list, id),
      { parentId: at.parentId, region: at.region, index: at.index },
      patched,
    )
    return withRoot(doc, field, next)
  }

  /** Insert `block` at `position`, in whichever root list the position names or contains. */
  const insertAt = (
    doc: EditorDocument,
    position: Position,
    block: BlockInstance,
  ): EditorDocument => {
    // The list operations address a root list as (null, null); the slot names the field.
    const target = {
      parentId: position.parent,
      region: position.parent === null ? null : position.slot,
      index: position.index,
    }
    const field = position.parent === null ? position.slot : rootOf(doc, position.parent)
    if (field === null) return doc
    return withRoot(doc, field, ops.insertAt(rootList(doc, field), target, clone(block)))
  }

  const removeById = (doc: EditorDocument, id: string): EditorDocument => {
    const field = rootOf(doc, id)
    if (field === null) return doc
    return withRoot(doc, field, ops.removeById(rootList(doc, field), id))
  }

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
    return next.fields === doc.fields ? doc : next
  }

  function apply(doc: EditorDocument, op: Operation): EditorDocument {
    switch (op.type) {
      case 'SetField':
        return patchBlock(doc, op.block, (b) => ({
          ...b,
          data: setPath(b.data, [op.field], op.to),
        }))
      case 'SetSetting':
        return patchBlock(doc, op.block, (b) => ({
          ...b,
          settings: setPath(b.settings, settingSegments(op.path, op.breakpoint), op.to),
        }))
      case 'SetAdvanced':
        return patchBlock(doc, op.block, (b) => ({
          ...b,
          settings: setPath(b.settings, ['advanced', ...op.path.split('.')], op.to),
        }))
      case 'ApplyStyleClass':
        return patchBlock(doc, op.block, (b) => {
          const list = classes(b).filter((id) => id !== op.class_id)
          list.splice(Math.max(0, Math.min(op.index, list.length)), 0, op.class_id)
          return withClasses(b, list)
        })
      case 'RemoveStyleClass':
        return patchBlock(doc, op.block, (b) =>
          withClasses(
            b,
            classes(b).filter((id) => id !== op.class_id),
          ),
        )
      case 'ReorderStyleClasses':
        return patchBlock(doc, op.block, (b) => withClasses(b, [...op.to]))
      case 'DetachStyleClass':
        // Toggles membership so the op is its own inverse with the styles swapped: a block
        // carrying the class loses it and takes `to_style`; a block without it gets the class
        // back at `index` and takes `to_style` (the instance style from before the detach).
        return patchBlock(doc, op.block, (b) => {
          const current = classes(b)
          const list = current.includes(op.class_id)
            ? current.filter((id) => id !== op.class_id)
            : [...current]
          if (!current.includes(op.class_id)) {
            list.splice(Math.max(0, Math.min(op.index, list.length)), 0, op.class_id)
          }
          const toggled = withClasses(b, list)
          const settings: Json = { ...toggled.settings }
          if (Object.keys(op.to_style).length === 0) delete settings.style
          else settings.style = clone(op.to_style)
          return { ...toggled, settings }
        })
      case 'InsertBlock':
        return insertAt(doc, op.position, op.block)
      case 'InsertBlocks': {
        let next = doc
        op.blocks.forEach((block, i) => {
          next = insertAt(next, { ...op.position, index: op.position.index + i }, block)
        })
        return next
      }
      case 'RemoveBlock':
        return removeById(doc, op.block.id)
      case 'MoveBlock': {
        const field = rootOf(doc, op.block)
        if (field === null) return doc
        const block = ops.findById(rootList(doc, field), op.block)!
        return insertAt(removeById(doc, op.block), op.to, block)
      }
      case 'DuplicateBlock':
        return insertAt(doc, op.position, op.block)
      case 'SetPageSettings':
        return { fields: setPath(doc.fields, [op.field], op.to) }
    }
  }

  return { applyOperation, listOps: ops, rootOf, rootList }
}

export type OperationApplier = ReturnType<typeof createOperationApplier>
