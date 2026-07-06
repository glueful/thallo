import type { InjectionKey, ComputedRef, Reactive, Ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { BlockInstance, BlockListOps } from './useBlockListOps'

// The root BlocksField owns the WHOLE tree and provides this context; BlockList/
// BlockCard (any nesting level) consume it. Every mutation flows through apply()
// — ONE model emission per operation at the root, the single writer.
export interface BlocksContext {
  bySlug: ComputedRef<Map<string, BlockType>>
  /**
   * Picker types for ONE list (stage-toolbar spec §5): active types ∩ that
   * list's own blocks-field allowlist. Root list (null, null) = the entry
   * field's allowlist; a nested region = the containing block type's
   * blocks-typed schema field for that region. Empty allowlist = all active.
   */
  pickerTypesForList: (parentId: string | null, region: string | null) => BlockType[]
  /** Blocks-typed field names (container regions) of a block-type schema. */
  regionsOf: (slug: string) => string[]
  apply: (fn: (tree: BlockInstance[]) => BlockInstance[]) => void
  ops: BlockListOps
  /** Shared expand/collapse state, keyed by block id. */
  expanded: Reactive<Record<string, boolean>>
  /** Outline -> expand ancestors, scroll into view, focus the header. */
  selectBlock: (id: string) => void
  /** Field-scoped sortable group name (cross-container drag within ONE field). */
  dragGroup: string
  /** Drag drop handler (root-provided; reads target identity from event.to). */
  onDragEnd: (event: {
    item: HTMLElement
    to: HTMLElement
    from: HTMLElement
    newIndex?: number
  }) => void
  /**
   * Bumped after EVERY drag end (commit or reject): sortable mutates each
   * touched list's LOCAL mirror, and a cross-container reject leaves the
   * NON-handling list's mirror stale — every BlockList re-derives on this tick.
   */
  dragVersion: Ref<number>
  maxDepth: number
}

export const BlocksContextKey: InjectionKey<BlocksContext> = Symbol('thallo-blocks-context')
