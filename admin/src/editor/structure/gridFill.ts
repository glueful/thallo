// Fill empty cells (container-layout spec §11.3): a grid's last row completed with column
// containers, so each column is a real place an author can fill on its own.
//
// It reuses the block factory and the page's transaction path, and NOT the structure picker's
// qualification: the picker needs a container that is new and empty, and Fill acts on existing,
// populated ones. Its own conditions live in one function, `availability`, which runs when a
// button is drawn and again — in full — immediately before anything is committed.
import { newBlockId } from '@/fields/components/blocks/useBlockListOps'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { EditorDocument, OperationBody } from '@/editor/ops/types'
import type { Breakpoint, StyleClassRef } from '@/style/types'
import { effectiveDisplay } from '@/editor/inspector/layoutContext'
import { checkInsertSequence, locateBlock, type LegalityContext } from './legality'
import { lastRowFree } from './gridOccupancy'
import { columnChild } from './presets'

export interface GridFillDeps {
  /** The live document; re-read after every await, never captured. */
  doc: () => EditorDocument
  legality: () => LegalityContext
  classesFor: (id: string) => StyleClassRef[]
  activeBreakpoint: () => Breakpoint
  /** The server block factory: structure and defaults for a fresh block of a type. */
  factory: (slug: string) => Promise<BlockInstance>
  /** Commit the whole fill as one transaction, so undo takes every cell back in one step. */
  commit: (operations: OperationBody[]) => Promise<void> | void
  notify: (message: string) => void
  /** Preparing started or ended: whoever shows a Fill button redraws it. */
  changed: () => void
}

export interface FillAvailability {
  /** Whether Fill applies at all: the container is a grid at the breakpoint. */
  visible: boolean
  enabled: boolean
  /** The cells an appended block can reach (spec §11.3): what a fill would add. */
  cells: number
  reason?: string
}

/** One Fill button on the stage, as published: the stage draws this and decides nothing. */
export interface StageFillState {
  id: string
  enabled: boolean
  preparing: boolean
  reason?: string
}

const SLOT = 'content'
const HIDDEN: FillAvailability = { visible: false, enabled: false, cells: 0 }

/** A cell as it will be inserted: the factory's container carrying the column's settings. */
function cellFrom(made: BlockInstance): BlockInstance {
  const column = columnChild('', 0)
  return {
    ...(JSON.parse(JSON.stringify(made)) as BlockInstance),
    id: newBlockId(),
    settings: { ...made.settings, style: column.settings.style },
  } as BlockInstance
}

export function createGridFill(deps: GridFillDeps) {
  const preparing = new Set<string>()

  /** The candidate as `checkInsertSequence` judges it: each cell appended in order. */
  function candidate(id: string, container: BlockInstance, cells: BlockInstance[]) {
    const start = Array.isArray(container.data[SLOT])
      ? (container.data[SLOT] as unknown[]).length
      : 0
    return cells.map((block, index) => ({
      position: { parent: id, slot: SLOT, index: start + index },
      block,
    }))
  }

  /**
   * Every condition, in order. `cells`, when given, are the real instances about to be committed;
   * without them the candidate is judged on stand-ins of the same type, which is what a button
   * can know before anything is fetched.
   */
  function availability(
    id: string,
    breakpoint: Breakpoint,
    cells?: BlockInstance[],
  ): FillAvailability {
    const ctx = deps.legality()
    const found = locateBlock(deps.doc(), id, ctx)
    if (!found) return HIDDEN
    if (effectiveDisplay(found.block, breakpoint, deps.classesFor(id)) !== 'grid') return HIDDEN

    const free = lastRowFree(found.block, breakpoint, deps.classesFor)
    if (free === 0) {
      return { visible: true, enabled: false, cells: 0, reason: 'No empty cells in the last row' }
    }
    // Room for the cell AND a block inside it. A cell is a container, and a block that holds
    // blocks needs a level below it: legality refuses it at the cap too (the server refuses its
    // list there even while it is empty). This says so first, in the author's terms.
    if (found.depth + 2 > ctx.maxDepth) {
      return {
        visible: true,
        enabled: false,
        cells: free,
        reason: `A cell here could not hold a block: blocks nest at most ${ctx.maxDepth} levels deep`,
      }
    }
    const judged =
      cells ??
      Array.from(
        { length: free },
        (_, i) =>
          ({
            id: `cell-${i}`,
            type: columnChild('', 0).type,
            data: { [SLOT]: [] },
            settings: {},
          }) as unknown as BlockInstance,
      )
    const verdict = checkInsertSequence(
      deps.doc(),
      candidate(id, found.block, judged.slice(0, free)),
      ctx,
    )
    if (!verdict.ok) return { visible: true, enabled: false, cells: free, reason: verdict.message }
    return { visible: true, enabled: true, cells: free }
  }

  return {
    availability: (id: string, breakpoint: Breakpoint): FillAvailability =>
      availability(id, breakpoint),

    preparing: (id: string): boolean => preparing.has(id),

    /**
     * What the stage is told: every EMPTY grid — the only place its button can be drawn, on the
     * placeholder — with exactly what `availability` says, so it cannot differ from the inspector.
     */
    stageStates(breakpoint: Breakpoint): StageFillState[] {
      const ctx = deps.legality()
      const out: StageFillState[] = []
      const walk = (blocks: unknown): void => {
        if (!Array.isArray(blocks)) return
        for (const block of blocks as BlockInstance[]) {
          const content = block.data[SLOT]
          if (Array.isArray(content) && content.length === 0) {
            const fill = availability(block.id, breakpoint)
            if (fill.visible) {
              out.push({
                id: block.id,
                enabled: fill.enabled,
                preparing: preparing.has(block.id),
                ...(fill.reason !== undefined ? { reason: fill.reason } : {}),
              })
            }
          }
          for (const region of ctx.regionsOf(block.type)) walk(block.data[region])
        }
      }
      const doc = deps.doc()
      for (const field of Object.keys(ctx.rootSlots())) walk(doc.fields[field])
      return out
    },

    async fill(id: string, breakpoint: Breakpoint): Promise<void> {
      // One request per container: a second while one is preparing is ignored, not queued.
      if (preparing.has(id)) return
      if (!availability(id, breakpoint).enabled) return

      preparing.add(id)
      deps.changed()
      try {
        let made: BlockInstance
        try {
          made = await deps.factory(columnChild('', 0).type)
        } catch {
          deps.notify("Couldn't fill the empty cells")
          return
        }

        // Everything is re-read after the await. The target may be gone, may have left grid mode,
        // may have gained content — or moved deeper, which subtree legality alone would not catch.
        if (deps.activeBreakpoint() !== breakpoint) return
        const ctx = deps.legality()
        const found = locateBlock(deps.doc(), id, ctx)
        if (!found) return
        const count = availability(id, breakpoint).cells
        const cells = Array.from({ length: count }, () => cellFrom(made))
        const now = availability(id, breakpoint, cells)
        if (!now.enabled) return

        await deps.commit(
          candidate(id, found.block, cells).map(({ position, block }) => ({
            type: 'InsertBlock' as const,
            position,
            block,
          })),
        )
      } finally {
        preparing.delete(id)
        deps.changed()
      }
    },
  }
}
