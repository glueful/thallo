// Whole-candidate-tree legality (visual builder spec §5.2): before any structural commit, one
// complete candidate tree is built — the moving set removed in document order, every block then
// inserted at a destination index interpreted against the reduced tree — and validated: every
// block exists, a multi-selection is siblings of one slot, the destination slot exists (a root
// field or a parent's blocks field), no destination lies inside a moving subtree, the depth cap
// holds for every moved root, the destination slot's allow-list admits every moved type (the
// builder always enforces it; `enforce_block_types` is the server's switch), and the resulting
// destination slot respects its cap. The rules are shared with the server validator through
// `tests/fixtures/structure/legality`.
//
// `checkInsertSubtree` and `checkInsertSequence` extend the same rules to a candidate assembled
// outside a block type's own starter — a preset's columns, a paste, the structure picker's
// factory instances — where the subtree can be welcome at its destination and still be illegal
// inside itself (container-layout spec §6.3).
import type { BlockInstance, RegionResolver } from '@/fields/components/blocks/useBlockListOps'
import { createBlockListOps } from '@/fields/components/blocks/useBlockListOps'
import type { EditorDocument, Position } from '@/editor/ops/types'

export type LegalityReason =
  | 'unknown-block'
  | 'not-siblings'
  | 'no-slot'
  | 'cycle'
  | 'depth'
  | 'type-not-allowed'
  | 'source-slot'

export type Legality = { ok: true } | { ok: false; reason: LegalityReason; message: string }

export interface SlotDefinition {
  /** Allowed block type slugs; empty = any active type. */
  blockTypes: string[]
}

export interface SlotTypeSummary {
  slug: string
  label: string
  /** Slot name => definition, for every blocks-typed field of the type. */
  slots: Record<string, SlotDefinition>
}

export interface LegalityContext {
  regionsOf: RegionResolver
  /** Every block type the site knows, with its slots. */
  blockTypes: () => SlotTypeSummary[]
  /** The document's root blocks fields with their own allow-lists. */
  rootSlots: () => Record<string, SlotDefinition>
  maxDepth: number
}

export interface MoveIntent {
  block: string
  to: Position
}

/** The tabs authoring cap (theme-runtime spec §4): a destination `items` slot of a tabs block. */
export const TABS_MAX_ITEMS = 12

function asList(value: unknown): BlockInstance[] {
  return Array.isArray(value) ? (value as BlockInstance[]) : []
}

interface Located {
  block: BlockInstance
  parent: string | null
  slot: string | null
  depth: number
  order: number
}

function index(doc: EditorDocument, ctx: LegalityContext): Map<string, Located> {
  const out = new Map<string, Located>()
  let order = 0
  const walk = (
    list: BlockInstance[],
    parent: string | null,
    slot: string | null,
    depth: number,
  ) => {
    for (const block of list) {
      out.set(block.id, { block, parent, slot, depth, order: order++ })
      for (const region of ctx.regionsOf(block.type)) {
        walk(asList(block.data[region]), block.id, region, depth + 1)
      }
    }
  }
  for (const field of Object.keys(ctx.rootSlots())) walk(asList(doc.fields[field]), null, field, 1)
  return out
}

/** Nesting height of a subtree: a leaf = 1. */
export function subtreeHeight(block: BlockInstance, regionsOf: RegionResolver): number {
  let deepest = 0
  for (const region of regionsOf(block.type)) {
    for (const child of asList(block.data[region])) {
      deepest = Math.max(deepest, subtreeHeight(child, regionsOf))
    }
  }
  return 1 + deepest
}

function contains(block: BlockInstance, id: string, regionsOf: RegionResolver): boolean {
  if (block.id === id) return true
  for (const region of regionsOf(block.type)) {
    for (const child of asList(block.data[region])) if (contains(child, id, regionsOf)) return true
  }
  return false
}

function slotDefinition(
  position: Position,
  located: Map<string, Located>,
  ctx: LegalityContext,
): SlotDefinition | null {
  if (position.parent === null) {
    return position.slot === null ? null : (ctx.rootSlots()[position.slot] ?? null)
  }
  const parent = located.get(position.parent)
  if (!parent || position.slot === null) return null
  const type = ctx.blockTypes().find((t) => t.slug === parent.block.type)
  return type?.slots[position.slot] ?? null
}

function typeLabel(slug: string, ctx: LegalityContext): string {
  return ctx.blockTypes().find((t) => t.slug === slug)?.label ?? slug
}

/**
 * The complete candidate tree: the moving set removed in document order, then each block
 * inserted at its destination, indices interpreted against the reduced tree.
 */
export function candidateTree(
  doc: EditorDocument,
  moves: MoveIntent[],
  ctx: LegalityContext,
): EditorDocument {
  const ops = createBlockListOps(ctx.regionsOf)
  const located = index(doc, ctx)
  const ordered = [...moves].sort(
    (a, b) => (located.get(a.block)?.order ?? 0) - (located.get(b.block)?.order ?? 0),
  )
  const blocks = new Map<string, BlockInstance>()
  let fields: Record<string, unknown> = { ...doc.fields }
  for (const move of ordered) {
    const found = located.get(move.block)
    if (!found) continue
    blocks.set(move.block, found.block)
    for (const field of Object.keys(ctx.rootSlots())) {
      fields = { ...fields, [field]: ops.removeById(asList(fields[field]), move.block) }
    }
  }
  const rootOf = (id: string): string | null => {
    for (const field of Object.keys(ctx.rootSlots())) {
      if (ops.findById(asList(fields[field]), id)) return field
    }
    return null
  }
  for (const move of ordered) {
    const block = blocks.get(move.block)
    if (!block) continue
    const field = move.to.parent === null ? move.to.slot : rootOf(move.to.parent)
    if (field === null) continue
    const target = {
      parentId: move.to.parent,
      region: move.to.parent === null ? null : move.to.slot,
      index: move.to.index,
    }
    fields = { ...fields, [field]: ops.insertAt(asList(fields[field]), target, block) }
  }
  return { fields }
}

function check(
  doc: EditorDocument,
  placements: { block: BlockInstance; to: Position; moving: boolean }[],
  ctx: LegalityContext,
): Legality {
  const located = index(doc, ctx)
  const movingIds = new Set(placements.filter((p) => p.moving).map((p) => p.block.id))
  // Siblings of one slot.
  const sources = placements.filter((p) => p.moving).map((p) => located.get(p.block.id))
  if (sources.some((s) => s === undefined)) {
    return {
      ok: false,
      reason: 'unknown-block',
      message: 'That block is no longer in the document',
    }
  }
  const first = sources[0]
  if (first && sources.some((s) => s!.parent !== first.parent || s!.slot !== first.slot)) {
    return { ok: false, reason: 'not-siblings', message: 'Select blocks in one list' }
  }
  for (const placement of placements) {
    const def = slotDefinition(placement.to, located, ctx)
    if (def === null) {
      return { ok: false, reason: 'no-slot', message: 'That slot no longer exists' }
    }
    if (placement.to.parent !== null) {
      for (const p of placements) {
        if (p.moving && contains(p.block, placement.to.parent, ctx.regionsOf)) {
          return { ok: false, reason: 'cycle', message: 'A block cannot hold itself' }
        }
      }
    }
    const parentDepth =
      placement.to.parent === null ? 0 : (located.get(placement.to.parent)?.depth ?? 0)
    if (parentDepth + subtreeHeight(placement.block, ctx.regionsOf) > ctx.maxDepth) {
      return {
        ok: false,
        reason: 'depth',
        message: `Would nest deeper than ${ctx.maxDepth} levels`,
      }
    }
    if (def.blockTypes.length > 0 && !def.blockTypes.includes(placement.block.type)) {
      const accepts = def.blockTypes.map((slug) => typeLabel(slug, ctx)).join(', ')
      return {
        ok: false,
        reason: 'type-not-allowed',
        message: `${placement.to.slot ?? 'This slot'} accepts ${accepts} only`,
      }
    }
    // The tabs cap: net additions to a tabs block's items.
    if (placement.to.parent !== null && placement.to.slot === 'items') {
      const parent = located.get(placement.to.parent)
      if (parent && parent.block.type === 'tabs') {
        const staying = asList(parent.block.data.items).filter((b) => !movingIds.has(b.id)).length
        const arriving = placements.filter(
          (p) => p.to.parent === placement.to.parent && p.to.slot === 'items',
        ).length
        if (staying + arriving > TABS_MAX_ITEMS) {
          return {
            ok: false,
            reason: 'source-slot',
            message: `Tabs supports at most ${TABS_MAX_ITEMS} items`,
          }
        }
      }
    }
  }
  return { ok: true }
}

/** Legality of moving `moves` (a multi-selection of siblings, or one block). */
export function checkMoves(
  doc: EditorDocument,
  moves: MoveIntent[],
  ctx: LegalityContext,
): Legality {
  const located = index(doc, ctx)
  const placements = moves.map((m) => ({
    block: located.get(m.block)?.block ?? { id: m.block, type: '', data: {}, settings: {} },
    to: m.to,
    moving: true,
  }))
  return check(doc, placements, ctx)
}

/** Legality of inserting a whole subtree (a starter's height counts) at `position`. */
export function checkInsert(
  doc: EditorDocument,
  position: Position,
  block: BlockInstance,
  ctx: LegalityContext,
): Legality {
  return check(doc, [{ block, to: position, moving: false }], ctx)
}

/**
 * Legality of inserting a subtree, judged all the way down (container-layout spec §6.3).
 *
 * `checkInsert` asks whether the subtree's ROOT may sit at the destination. That is enough for a
 * starter, whose children are the ones its own type ships, but not for a candidate assembled
 * elsewhere: a preset's columns, a paste, a block built by the factory. Such a subtree can be
 * welcome at its destination and still be illegal inside itself — a gallery whose items hold a
 * heading, a tabs block over its cap — and inserting it would write a document the server refuses.
 *
 * So every descendant is checked against the slot it actually lands in, with the destination's
 * depth carried down so the cap counts from where the subtree is going.
 */
export function checkInsertSubtree(
  doc: EditorDocument,
  position: Position,
  block: BlockInstance,
  ctx: LegalityContext,
): Legality {
  const root = checkInsert(doc, position, block, ctx)
  if (!root.ok) return root
  return checkDescendants(block, ctx)
}

/** Every slot inside a subtree, against its own type's allow-list and caps. */
function checkDescendants(block: BlockInstance, ctx: LegalityContext): Legality {
  const type = ctx.blockTypes().find((t) => t.slug === block.type)
  for (const region of ctx.regionsOf(block.type)) {
    const children = asList(block.data[region])
    const def = type?.slots[region]
    if (def && def.blockTypes.length > 0) {
      for (const child of children) {
        if (!def.blockTypes.includes(child.type)) {
          const accepts = def.blockTypes.map((slug) => typeLabel(slug, ctx)).join(', ')
          return {
            ok: false,
            reason: 'type-not-allowed',
            message: `${region} accepts ${accepts} only`,
          }
        }
      }
    }
    if (block.type === 'tabs' && region === 'items' && children.length > TABS_MAX_ITEMS) {
      return {
        ok: false,
        reason: 'source-slot',
        message: `Tabs supports at most ${TABS_MAX_ITEMS} items`,
      }
    }
    for (const child of children) {
      const verdict = checkDescendants(child, ctx)
      if (!verdict.ok) return verdict
    }
  }
  return { ok: true }
}

/**
 * The document with `block` inserted at `position`, or null when the position names a parent the
 * document does not hold. The insert is the same one the editor performs, so a sequence can be
 * judged against the tree the previous insert produced.
 */
export function insertCandidate(
  doc: EditorDocument,
  position: Position,
  block: BlockInstance,
  ctx: LegalityContext,
): EditorDocument | null {
  const ops = createBlockListOps(ctx.regionsOf)
  const fields = { ...doc.fields }
  let field: string | null = null
  if (position.parent === null) {
    field = position.slot
    if (field === null || !(field in fields)) return null
  } else {
    for (const name of Object.keys(ctx.rootSlots())) {
      if (ops.findById(asList(fields[name]), position.parent)) {
        field = name
        break
      }
    }
    if (field === null) return null
  }
  const target = {
    parentId: position.parent,
    region: position.parent === null ? null : position.slot,
    index: position.index,
  }
  return { fields: { ...fields, [field]: ops.insertAt(asList(fields[field]), target, block) } }
}

/**
 * Legality of a SEQUENCE of inserts, each judged against the document the previous ones produced
 * (container-layout spec §6.3). A preset inserts several blocks; the second one's destination may
 * be a block the first one added, and its slot's cap counts what is already there.
 */
export function checkInsertSequence(
  doc: EditorDocument,
  inserts: { position: Position; block: BlockInstance }[],
  ctx: LegalityContext,
): Legality {
  let current = doc
  for (const insert of inserts) {
    const verdict = checkInsertSubtree(current, insert.position, insert.block, ctx)
    if (!verdict.ok) return verdict
    const next = insertCandidate(current, insert.position, insert.block, ctx)
    if (next === null) {
      return { ok: false, reason: 'no-slot', message: 'That slot no longer exists' }
    }
    current = next
  }
  return { ok: true }
}

/**
 * A block and its depth — a root block is at depth one — so a subtree's height can be judged
 * against the cap before anything is fetched or built (container-layout spec §6.3, §11.3).
 */
export function locateBlock(
  doc: EditorDocument,
  id: string,
  ctx: LegalityContext,
): { block: BlockInstance; depth: number } | null {
  const list = (value: unknown): BlockInstance[] =>
    Array.isArray(value) ? (value as BlockInstance[]) : []
  const walk = (
    blocks: BlockInstance[],
    depth: number,
  ): { block: BlockInstance; depth: number } | null => {
    for (const block of blocks) {
      if (block.id === id) return { block, depth }
      for (const region of ctx.regionsOf(block.type)) {
        const hit = walk(list(block.data[region]), depth + 1)
        if (hit) return hit
      }
    }
    return null
  }
  for (const field of Object.keys(ctx.rootSlots())) {
    const hit = walk(list(doc.fields[field]), 1)
    if (hit) return hit
  }
  return null
}
