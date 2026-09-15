// One drag coordinator (visual builder spec §5.1, §5.3): owns drag state and operation
// generation for every source — the palette, the outline, the stage, the inspector list — with
// surface adapters doing only pointer handling, hit testing and geometry. The tree is untouched
// until drop: movement produces a proposal (judged on the whole candidate tree), drop yields one
// operation or one transaction, cancel discards the session. A second `begin` cancels the first.
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { createBlockListOps } from '@/fields/components/blocks/useBlockListOps'
import { newBlockId } from '@/fields/components/blocks/useBlockListOps'
import type { EditorDocument, OperationBody, Position } from '@/editor/ops/types'
import {
  candidateTree,
  checkInsert,
  checkMoves,
  type Legality,
  type LegalityContext,
  type MoveIntent,
} from './legality'

export type DragSource = 'palette' | 'outline' | 'stage' | 'list'

export type DragPayload =
  /** Existing blocks: a single block or a sibling multi-selection, in document order. */
  | { blocks: string[] }
  /** A new block (with its ids already allocated) from the palette. */
  | { block: BlockInstance }

export interface DropZone {
  parent: string | null
  slot: string | null
  index: number
}

export interface Proposal {
  verdict: Legality
  /** The destination of the first moved (or inserted) block. */
  position: Position
}

export interface DragState {
  session: string
  source: DragSource
  payload: DragPayload
  proposal: Proposal | null
}

export interface DragCoordinator {
  begin(source: DragSource, payload: DragPayload): string
  propose(zone: DropZone): Proposal | null
  /** One op, one transaction, or null when nothing legal is proposed. Ends the session. */
  drop(): OperationBody[] | null
  cancel(): void
  state(): DragState | null
}

function asList(value: unknown): BlockInstance[] {
  return Array.isArray(value) ? (value as BlockInstance[]) : []
}

export function createDragCoordinator(ctx: {
  doc: () => EditorDocument
  legality: () => LegalityContext
}): DragCoordinator {
  let state: DragState | null = null

  const intents = (zone: DropZone, blocks: string[]): MoveIntent[] =>
    blocks.map((block, k) => ({
      block,
      to: { parent: zone.parent, slot: zone.slot, index: zone.index + k },
    }))

  /** The list a position names in `doc`: a root field, or a parent's slot. */
  const listAt = (doc: EditorDocument, position: Position, legality: LegalityContext) => {
    const ops = createBlockListOps(legality.regionsOf)
    if (position.parent === null)
      return position.slot === null ? [] : asList(doc.fields[position.slot])
    for (const field of Object.keys(legality.rootSlots())) {
      const parent = ops.findById(asList(doc.fields[field]), position.parent)
      if (parent && position.slot !== null) return asList(parent.data[position.slot])
    }
    return []
  }

  /** Where `id` sits in `doc`. */
  const locate = (doc: EditorDocument, id: string, legality: LegalityContext): Position | null => {
    const ops = createBlockListOps(legality.regionsOf)
    for (const field of Object.keys(legality.rootSlots())) {
      const list = asList(doc.fields[field])
      const found = ops.locateById(list, id)
      if (found) {
        return {
          parent: found.parentId,
          slot: found.parentId === null ? field : found.region,
          index: found.index,
        }
      }
    }
    return null
  }

  return {
    begin(source, payload) {
      const session = newBlockId()
      state = { session, source, payload, proposal: null }
      return session
    },

    propose(zone) {
      if (state === null) return null
      const doc = ctx.doc()
      const legality = ctx.legality()
      const position: Position = { parent: zone.parent, slot: zone.slot, index: zone.index }
      const verdict =
        'blocks' in state.payload
          ? checkMoves(doc, intents(zone, state.payload.blocks), legality)
          : checkInsert(doc, position, state.payload.block, legality)
      state.proposal = { verdict, position }
      return state.proposal
    },

    drop() {
      const current = state
      state = null
      if (current === null || current.proposal === null || !current.proposal.verdict.ok) return null
      const doc = ctx.doc()
      const legality = ctx.legality()
      const to = current.proposal.position
      if ('block' in current.payload) {
        return [{ type: 'InsertBlock', position: to, block: current.payload.block }]
      }
      // A group is one transaction of MoveBlock primitives (spec §3.1), each op's `from` the
      // position at the moment it applies and its `to.index` counted against the working tree,
      // so replaying them in order reproduces the candidate tree exactly.
      const moves = intents(
        { parent: to.parent, slot: to.slot, index: to.index },
        current.payload.blocks,
      )
      const candidate = candidateTree(doc, moves, legality)
      const finalList = listAt(candidate, to, legality).map((b) => b.id)
      const ops: OperationBody[] = []
      let working = doc
      const applier = createBlockListOps(legality.regionsOf)
      for (const block of current.payload.blocks) {
        const from = locate(working, block, legality)
        if (from === null) continue
        // Remove the block from the working tree, then count the destination list's items that
        // precede it in the candidate order.
        let reduced: EditorDocument = working
        for (const field of Object.keys(legality.rootSlots())) {
          reduced = {
            fields: {
              ...reduced.fields,
              [field]: applier.removeById(asList(reduced.fields[field]), block),
            },
          }
        }
        // Walk the destination list as it stands: items still to move are skipped over (they
        // will be re-placed by a later op), and the first settled item that follows this block
        // in the candidate order marks the insertion point.
        const destination = listAt(reduced, to, legality).map((b) => b.id)
        const rank = finalList.indexOf(block)
        const later = current.payload.blocks.slice(current.payload.blocks.indexOf(block) + 1)
        const pending = new Set(later)
        let index = 0
        for (const id of destination) {
          if (pending.has(id) || finalList.indexOf(id) < rank) index++
          else break
        }
        const target = { parent: to.parent, slot: to.slot, index }
        ops.push({ type: 'MoveBlock', block, from, to: target })
        // Apply to the working tree for the next op's `from`.
        const moved = applier.findById(listAt(working, from, legality), block)
        if (moved) {
          let next = reduced
          const field =
            to.parent === null
              ? to.slot
              : (Object.keys(legality.rootSlots()).find((f) =>
                  applier.findById(asList(reduced.fields[f]), to.parent!),
                ) ?? null)
          if (field !== null) {
            const listTarget = {
              parentId: to.parent,
              region: to.parent === null ? null : to.slot,
              index,
            }
            next = {
              fields: {
                ...reduced.fields,
                [field]: applier.insertAt(asList(reduced.fields[field]), listTarget, moved),
              },
            }
          }
          working = next
        }
      }
      return ops
    },

    cancel() {
      state = null
    },

    state() {
      return state
    },
  }
}
