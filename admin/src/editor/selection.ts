// Sibling multi-selection (visual builder spec §5.5): a selection is a set of blocks from ONE
// slot — the anchor's — kept in document order. Shift extends to a range from the anchor,
// cmd/ctrl toggles a sibling, and a block from another slot starts a new selection. Every
// mutation front door takes the selection: a group moves, duplicates, removes and styles as one
// transaction.
import { createBlockListOps, type BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { RegionResolver } from '@/fields/components/blocks/useBlockListOps'
import type { EditorDocument } from '@/editor/ops/types'

export interface Selection {
  /** The selected ids in document order; empty when nothing is selected. */
  ids: string[]
  parent: string | null
  slot: string | null
  /** The block a range extends from; the first selected block after a toggle removes it. */
  anchor: string | null
}

export interface SelectionContext {
  regionsOf: RegionResolver
  /** The document's root blocks fields. */
  blockFields: () => string[]
}

export const EMPTY_SELECTION: Selection = { ids: [], parent: null, slot: null, anchor: null }

function asList(value: unknown): BlockInstance[] {
  return Array.isArray(value) ? (value as BlockInstance[]) : []
}

interface Located {
  parent: string | null
  slot: string | null
  index: number
  siblings: BlockInstance[]
}

/** Where `id` sits: its parent, slot and the whole sibling list. */
function locate(doc: EditorDocument, id: string, ctx: SelectionContext): Located | null {
  const ops = createBlockListOps(ctx.regionsOf)
  for (const field of ctx.blockFields()) {
    const list = asList(doc.fields[field])
    const found = ops.locateById(list, id)
    if (found) {
      return {
        parent: found.parentId,
        slot: found.parentId === null ? field : found.region,
        index: found.index,
        siblings: found.list,
      }
    }
  }
  return null
}

/** Exactly one block selected (a plain click); an unknown id clears the selection. */
export function single(id: string, doc: EditorDocument, ctx: SelectionContext): Selection {
  const at = locate(doc, id, ctx)
  if (at === null) return EMPTY_SELECTION
  return { ids: [id], parent: at.parent, slot: at.slot, anchor: id }
}

/** Shift-click: every sibling between the anchor and `id`, inclusive, in document order. */
export function extend(
  sel: Selection,
  id: string,
  doc: EditorDocument,
  ctx: SelectionContext,
): Selection {
  const at = locate(doc, id, ctx)
  if (at === null) return EMPTY_SELECTION
  if (sel.anchor === null || at.parent !== sel.parent || at.slot !== sel.slot) {
    return single(id, doc, ctx)
  }
  const anchorIndex = at.siblings.findIndex((b) => b.id === sel.anchor)
  if (anchorIndex === -1) return single(id, doc, ctx)
  const [from, to] = anchorIndex <= at.index ? [anchorIndex, at.index] : [at.index, anchorIndex]
  return {
    ids: at.siblings.slice(from, to + 1).map((b) => b.id),
    parent: at.parent,
    slot: at.slot,
    anchor: sel.anchor,
  }
}

/** Cmd/ctrl-click: add a sibling, or remove one already selected. */
export function toggle(
  sel: Selection,
  id: string,
  doc: EditorDocument,
  ctx: SelectionContext,
): Selection {
  const at = locate(doc, id, ctx)
  if (at === null) return EMPTY_SELECTION
  if (sel.anchor === null || at.parent !== sel.parent || at.slot !== sel.slot) {
    return single(id, doc, ctx)
  }
  const chosen = new Set(sel.ids)
  if (chosen.has(id)) chosen.delete(id)
  else chosen.add(id)
  const ids = at.siblings.map((b) => b.id).filter((sibling) => chosen.has(sibling))
  if (ids.length === 0) return EMPTY_SELECTION
  const anchor = sel.anchor !== null && chosen.has(sel.anchor) ? sel.anchor : ids[0]!
  return { ids, parent: at.parent, slot: at.slot, anchor }
}

/**
 * The selection re-read against a changed document: ids that left the slot (or the document)
 * drop out, order follows the document, and an emptied selection is empty.
 */
export function reconcile(sel: Selection, doc: EditorDocument, ctx: SelectionContext): Selection {
  if (sel.ids.length === 0) return EMPTY_SELECTION
  const first = sel.ids.map((id) => locate(doc, id, ctx)).find((at) => at !== null) ?? null
  if (first === null) return EMPTY_SELECTION
  const chosen = new Set(sel.ids)
  const ids = first.siblings.map((b) => b.id).filter((id) => chosen.has(id))
  const anchor = sel.anchor !== null && ids.includes(sel.anchor) ? sel.anchor : ids[0]!
  return { ids, parent: first.parent, slot: first.slot, anchor }
}
