// Editor operations (visual builder spec §3.1): history records intent, never snapshots. Every
// operation carries its identity and is fully reversible on its own — removed and duplicated
// subtrees travel with the op, ids are allocated once and reused on redo, absent is distinct
// from null, and moves record both ends.
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { Breakpoint, StyleValue } from '@/style/types'

/** Absent versus null: `undefined` never crosses JSON, so "not set" is its own shape. */
export type ChangeValue<T> = { present: false } | { present: true; value: T | null }

export function absent<T = never>(): ChangeValue<T> {
  return { present: false }
}

export function present<T>(value: T | null): ChangeValue<T> {
  return { present: true, value }
}

/**
 * A place in the tree: a root list (`parent: null`, `slot` = the blocks-typed page field) or a
 * parent block's region (`parent` = its id, `slot` = the region name).
 */
export interface Position {
  parent: string | null
  slot: string | null
  index: number
}

export interface OperationMeta {
  op_id: string
  transaction_id: string
  /** ISO-8601 timestamp of the recording. */
  at: string
  /** The editor session that made the change. */
  session: string
}

export type AdvancedPath = 'anchor' | 'css_classes' | 'attributes' | 'accessibility.label'

export type OperationBody =
  | {
      type: 'SetField'
      block: string
      field: string
      from: ChangeValue<unknown>
      to: ChangeValue<unknown>
    }
  | {
      type: 'SetSetting'
      block: string
      /** A §1.3 property path, e.g. `spacing.padding.top`. */
      path: string
      /** The breakpoint for a responsive property; null for a non-responsive one. */
      breakpoint: Breakpoint | null
      from: ChangeValue<StyleValue>
      to: ChangeValue<StyleValue>
    }
  | {
      type: 'SetAdvanced'
      block: string
      path: AdvancedPath
      from: ChangeValue<unknown>
      to: ChangeValue<unknown>
    }
  | { type: 'ApplyStyleClass'; block: string; class_id: string; index: number }
  | { type: 'RemoveStyleClass'; block: string; class_id: string; index: number }
  | { type: 'ReorderStyleClasses'; block: string; from: string[]; to: string[] }
  | {
      /** Remove a class and bake its declarations into the instance style. */
      type: 'DetachStyleClass'
      block: string
      class_id: string
      index: number
      from_style: Record<string, unknown>
      to_style: Record<string, unknown>
    }
  | { type: 'InsertBlock'; position: Position; block: BlockInstance }
  | { type: 'InsertBlocks'; position: Position; blocks: BlockInstance[] }
  | { type: 'RemoveBlock'; position: Position; block: BlockInstance }
  | { type: 'MoveBlock'; block: string; from: Position; to: Position }
  | { type: 'DuplicateBlock'; source: string; position: Position; block: BlockInstance }
  | { type: 'SetPageSettings'; field: string; from: ChangeValue<unknown>; to: ChangeValue<unknown> }

export type Operation = OperationMeta & OperationBody

/**
 * What the editor edits: the entry's persisted fields. Blocks-typed fields are the block
 * roots (addressed by `Position.slot`); every other field is a page setting.
 */
export interface EditorDocument {
  fields: Record<string, unknown>
}
