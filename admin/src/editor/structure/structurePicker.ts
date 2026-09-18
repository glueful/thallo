// The structure picker's session state (container-layout spec §6.1–§6.3).
//
// An offer is made for a container the author just inserted, empty and fresh, and it lives here —
// in editor session state, never in the document. That matters for undo: undoing the insert of a
// container must not resurrect a picker, and undoing the content that consumed an offer must not
// bring the offer back. A document-borne flag would do both.
//
// The states are pending → preparing → ended, and ended is final. Everything that can end an offer
// does so exactly once: a successful choice, a skip, the container being deleted, and — the case
// that makes the rest necessary — content arriving by any route at any moment, including while a
// choice is mid-flight.
//
// Preparing is asynchronous because the children come from the server's block factory, so between
// the click and the commit the document can change under us. Every await is followed by the same
// three questions: is this still the offer we started, does the container still exist, and is it
// still empty. A late factory answer can never resurrect an ended offer.
import { newBlockId } from '@/fields/components/blocks/useBlockListOps'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { EditorDocument, OperationBody, Position } from '@/editor/ops/types'
import type { StyleClassRef } from '@/style/types'
import { checkInsertSequence, locateBlock, type LegalityContext } from './legality'
import { planPreset, presetDepth, PRESETS, type PlannedChild } from './presets'

export type PickerState = 'pending' | 'preparing' | 'ended'

export interface OfferedPreset {
  key: string
  label: string
  enabled: boolean
  /** Why a disabled preset cannot be used, in words the tile can show. */
  reason?: string
}

export interface PublishedOffer {
  id: string
  presets: OfferedPreset[]
}

export interface PickerDeps {
  /** The live document; re-read after every await, never captured. */
  doc: () => EditorDocument
  legality: () => LegalityContext
  /** The container's style classes at plan time, so a preset can see what it must outrank. */
  classesFor: (id: string) => StyleClassRef[]
  /** The server block factory: structure and defaults for a fresh block of a type. */
  factory: (slug: string) => Promise<BlockInstance>
  /** Commit the whole plan as one transaction. Awaited: the offer ends only once it has landed. */
  commit: (operations: OperationBody[]) => Promise<void> | void
  /** Publish the live offers to the stage. Called on every change, with the complete list. */
  publish: (offers: PublishedOffer[]) => void
  notify: (message: string) => void
}

interface Offer {
  id: string
  state: PickerState
  /** Bumped whenever the offer changes; a stale token means an await outlived its choice. */
  token: number
  /** Presets refused by the last attempt, with the reason, until something changes. */
  refused: Map<string, string>
}

/** The order the tiles are offered in. */
const ORDER = [
  'stack',
  'row',
  'cols-50-50',
  'cols-33-67',
  'cols-67-33',
  'cols-25-75',
  'cols-75-25',
  'cols-thirds',
  'cols-25-50-25',
  'cols-50-25-25',
  'cols-25-25-50',
  'cols-quarters',
  'grid-2x2',
  'section',
  'section-split',
]

function asList(value: unknown): BlockInstance[] {
  return Array.isArray(value) ? (value as BlockInstance[]) : []
}

/** Whether a container still holds nothing: the offer's precondition, re-read never remembered. */
function isEmpty(block: BlockInstance, ctx: LegalityContext): boolean {
  return ctx.regionsOf(block.type).every((region) => asList(block.data[region]).length === 0)
}

/** A planned child as a real block: the factory's instance, with the preset's settings merged. */
function instanceFor(
  planned: PlannedChild,
  made: BlockInstance,
  children: BlockInstance[],
): BlockInstance {
  const data: Record<string, unknown> = { ...(made.data ?? {}), ...(planned.data ?? {}) }
  if (children.length > 0) data.content = children
  return {
    ...made,
    id: made.id,
    data,
    settings: { ...(made.settings ?? {}), style: planned.settings.style },
  } as BlockInstance
}

export function createStructurePicker(deps: PickerDeps) {
  const offers = new Map<string, Offer>()
  let sequence = 0

  const live = (): PublishedOffer[] =>
    [...offers.values()]
      .filter((offer) => offer.state !== 'ended')
      .map((offer) => ({ id: offer.id, presets: presetsFor(offer) }))

  const republish = () => deps.publish(live())

  /** Which presets this container can take right now, and why not where it cannot. */
  function presetsFor(offer: Offer): OfferedPreset[] {
    const ctx = deps.legality()
    const found = locateBlock(deps.doc(), offer.id, ctx)
    return ORDER.filter((key) => PRESETS[key]).map((key) => {
      const refusal = offer.refused.get(key)
      if (refusal !== undefined)
        return { key, label: PRESETS[key]!.label, enabled: false, reason: refusal }
      // The cheap check the tile can show before anything is fetched: would the subtree fit?
      const depth = presetDepth(key)
      if (found && found.depth + depth - 1 > ctx.maxDepth) {
        return {
          key,
          label: PRESETS[key]!.label,
          enabled: false,
          reason: `Would nest deeper than ${ctx.maxDepth} levels`,
        }
      }
      return { key, label: PRESETS[key]!.label, enabled: true }
    })
  }

  function end(id: string): void {
    const offer = offers.get(id)
    if (!offer || offer.state === 'ended') return
    offer.state = 'ended'
    offer.token = ++sequence
    republish()
  }

  /** Build the real instances for a plan's children, depth first, allocating ids as we go. */
  async function build(children: PlannedChild[]): Promise<BlockInstance[]> {
    const out: BlockInstance[] = []
    for (const planned of children) {
      const made = await deps.factory(planned.type)
      const nested = await build(planned.children ?? [])
      out.push(instanceFor(planned, { ...made, id: made.id || newBlockId() }, nested))
    }
    return out
  }

  return {
    /** Offer the picker for a container. A second offer for the same container is not a reset. */
    offer(id: string): void {
      if (offers.has(id)) return
      offers.set(id, { id, state: 'pending', token: ++sequence, refused: new Map() })
      republish()
    },

    state(id: string): PickerState {
      return offers.get(id)?.state ?? 'ended'
    },

    /** The live offers, for a caller that needs to re-publish them (a stage reload). */
    offers: live,

    skip(id: string): void {
      end(id)
    },

    /**
     * Content arriving consumes the offer, whatever put it there and whenever it happens
     * (spec §6.1). Undoing that content does not reopen the offer: only the arrival is watched.
     */
    onDocumentChange(operations: OperationBody[]): void {
      for (const op of operations) {
        const into: Position | null =
          op.type === 'InsertBlock' || op.type === 'InsertBlocks' || op.type === 'DuplicateBlock'
            ? op.position
            : op.type === 'MoveBlock'
              ? op.to
              : null
        if (into?.parent != null && offers.has(into.parent)) end(into.parent)
      }
    },

    async choose(id: string, key: string): Promise<void> {
      const offer = offers.get(id)
      // Only a pending offer accepts a choice: a repeat while preparing is ignored, and an ended
      // offer rejects a late click from a stage that has not caught up.
      if (!offer || offer.state !== 'pending') return
      if (!PRESETS[key]) return

      const token = ++sequence
      offer.state = 'preparing'
      offer.token = token
      const stale = () => offer.token !== token || offer.state === 'ended'

      const ctx = deps.legality()
      const before = locateBlock(deps.doc(), id, ctx)
      if (!before || !isEmpty(before.block, ctx)) {
        end(id)
        return
      }

      let children: BlockInstance[]
      try {
        children = await build(planPreset(key, before.block, deps.classesFor(id))?.children ?? [])
      } catch {
        // Nothing has been written, so the offer simply stands and the author can retry.
        if (!stale()) offer.state = 'pending'
        deps.notify("Couldn't build that structure")
        return
      }
      if (stale()) return

      // Everything is re-read after the await: the container may have gone or gained content while
      // the factory was answering.
      const context = deps.legality()
      const found = locateBlock(deps.doc(), id, context)
      if (!found) {
        end(id)
        return
      }
      if (!isEmpty(found.block, context)) {
        end(id)
        return
      }

      const plan = planPreset(key, found.block, deps.classesFor(id))
      if (!plan) {
        offer.state = 'pending'
        return
      }

      // Judged on the REAL instances, in order, so a factory answer that differs from the
      // placeholder cannot slip a forbidden subtree past the tile's cheap check (spec §6.3).
      const verdict = checkInsertSequence(
        deps.doc(),
        children.map((block, index) => ({
          position: { parent: id, slot: 'content', index },
          block,
        })),
        context,
      )
      if (!verdict.ok) {
        offer.state = 'pending'
        offer.refused.set(key, verdict.message)
        republish()
        return
      }

      const operations: OperationBody[] = [
        ...plan.operations,
        ...children.map((block, index) => ({
          type: 'InsertBlock' as const,
          position: { parent: id, slot: 'content', index },
          block,
        })),
      ]
      // An empty plan still consumes the offer: the author answered the question (spec §6.3).
      if (operations.length > 0) await deps.commit(operations)
      end(id)
    },
  }
}
