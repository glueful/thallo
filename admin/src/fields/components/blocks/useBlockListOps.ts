import { MAX_BLOCK_DEPTH } from '@/queries/blockTypes'

// Pure, id-addressed operations over a blocks tree ({id,type,data} lists; nested
// container regions live at data[<blocks-field name>]). Every function returns a
// NEW tree (structural sharing where untouched) — the root BlocksField assigns the
// result to its model in ONE emission, which keeps operations undo-friendly for
// any future history layer. Ids are client nanoids, unique across the tree, so
// id-addressing needs no path bookkeeping.
export interface BlockInstance {
  id: string
  type: string
  data: Record<string, unknown>
}

/** Names of the blocks-typed fields (container regions) of a block-type schema. */
export type RegionResolver = (typeSlug: string) => string[]

export interface DropTarget {
  parentId: string | null
  region: string | null
}

export interface InsertTarget extends DropTarget {
  index: number
}

export function newBlockId(): string {
  return Array.from(crypto.getRandomValues(new Uint8Array(12)))
    .map((b) => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36])
    .join('')
}

/** "Empty" prose: no text content once tags are stripped (e.g. '<p></p>'). */
export function isEmptyHtml(html: string): boolean {
  return html.replace(/<[^>]*>/g, '').trim() === ''
}

export function createBlockListOps(regionsOf: RegionResolver) {
  const asList = (value: unknown): BlockInstance[] =>
    Array.isArray(value) ? (value as BlockInstance[]) : []

  /**
   * Map over every list in the tree (root + every container region), rebuilding
   * only the paths a transform actually changed (identity-compare per level).
   */
  function mapLists(
    list: BlockInstance[],
    fn: (list: BlockInstance[], parentId: string | null, region: string | null) => BlockInstance[],
    parentId: string | null = null,
    region: string | null = null,
  ): BlockInstance[] {
    return fn(list, parentId, region).map((block) => {
      let data = block.data
      let changed = false
      for (const r of regionsOf(block.type)) {
        const inner = asList(data[r])
        const next = mapLists(inner, fn, block.id, r)
        if (next !== inner) {
          data = { ...data, [r]: next }
          changed = true
        }
      }
      return changed ? { ...block, data } : block
    })
  }

  function findById(tree: BlockInstance[], id: string): BlockInstance | null {
    for (const block of tree) {
      if (block.id === id) return block
      for (const r of regionsOf(block.type)) {
        const hit = findById(asList(block.data[r]), id)
        if (hit) return hit
      }
    }
    return null
  }

  function insertAt(tree: BlockInstance[], target: InsertTarget, block: BlockInstance): BlockInstance[] {
    return mapLists(tree, (list, parentId, region) => {
      if (parentId !== target.parentId || region !== target.region) return list
      const next = [...list]
      next.splice(Math.max(0, Math.min(target.index, next.length)), 0, block)
      return next
    })
  }

  function removeById(tree: BlockInstance[], id: string): BlockInstance[] {
    return mapLists(tree, (list) =>
      list.some((b) => b.id === id) ? list.filter((b) => b.id !== id) : list,
    )
  }

  /**
   * Deep copy with FRESH ids for the block and every nested block — and
   * structuredClone for non-region object values, so nothing in the copy aliases
   * the original (the shallow {...data} bug this replaces shared nested arrays).
   */
  function reIdSubtree(block: BlockInstance): BlockInstance {
    const regions = regionsOf(block.type)
    const data: Record<string, unknown> = {}
    for (const [key, value] of Object.entries(block.data)) {
      if (regions.includes(key)) {
        data[key] = asList(value).map(reIdSubtree)
      } else if (typeof value === 'object' && value !== null) {
        data[key] = structuredClone(value)
      } else {
        data[key] = value
      }
    }
    return { id: newBlockId(), type: block.type, data }
  }

  function duplicateById(tree: BlockInstance[], id: string): BlockInstance[] {
    return mapLists(tree, (list) => {
      const index = list.findIndex((b) => b.id === id)
      if (index < 0) return list
      const next = [...list]
      next.splice(index + 1, 0, reIdSubtree(list[index]!))
      return next
    })
  }

  function patchDataById(tree: BlockInstance[], id: string, name: string, value: unknown): BlockInstance[] {
    return mapLists(tree, (list) =>
      list.some((b) => b.id === id)
        ? list.map((b) => (b.id === id ? { ...b, data: { ...b.data, [name]: value } } : b))
        : list,
    )
  }

  function moveById(tree: BlockInstance[], id: string, delta: number): BlockInstance[] {
    return mapLists(tree, (list) => {
      const from = list.findIndex((b) => b.id === id)
      if (from < 0) return list
      const to = from + delta
      if (to < 0 || to >= list.length) return list
      const next = [...list]
      const [item] = next.splice(from, 1)
      next.splice(to, 0, item!)
      return next
    })
  }

  function moveAcross(tree: BlockInstance[], id: string, target: InsertTarget): BlockInstance[] {
    const block = findById(tree, id)
    if (!block) return tree
    return insertAt(removeById(tree, id), target, block)
  }

  /** Nesting height of a block's own subtree: a leaf = 1. */
  function subtreeDepth(block: BlockInstance): number {
    let deepest = 0
    for (const r of regionsOf(block.type)) {
      for (const child of asList(block.data[r])) {
        deepest = Math.max(deepest, subtreeDepth(child))
      }
    }
    return 1 + deepest
  }

  /** Depth of the block with `id` (root list = 1); 0 when absent. */
  function depthOf(tree: BlockInstance[], id: string, depth = 1): number {
    for (const block of tree) {
      if (block.id === id) return depth
      for (const r of regionsOf(block.type)) {
        const found = depthOf(asList(block.data[r]), id, depth + 1)
        if (found > 0) return found
      }
    }
    return 0
  }

  /** True when targetId is dragged's OWN id or lives anywhere inside its subtree. */
  function isSelfOrDescendant(dragged: BlockInstance, targetId: string): boolean {
    if (dragged.id === targetId) return true
    for (const r of regionsOf(dragged.type)) {
      for (const child of asList(dragged.data[r])) {
        if (isSelfOrDescendant(child, targetId)) return true
      }
    }
    return false
  }

  /**
   * Spec §2 (pinned): targetDepth + draggedSubtreeDepth - 1 <= MAX_BLOCK_DEPTH.
   * targetDepth = the depth blocks INSIDE the target list sit at (root = 1; a
   * region of a block at depth d hosts depth d + 1). Rejects, IN ORDER: unknown
   * drag id; target parent that is the dragged block or inside its own subtree
   * (a container can never be dropped into itself); target parent missing from
   * the tree (depthOf returns 0 — checked BEFORE the +1 so it can never
   * masquerade as root depth); then the depth formula.
   */
  function canDropAt(tree: BlockInstance[], dragId: string, target: DropTarget): boolean {
    const dragged = findById(tree, dragId)
    if (!dragged) return false
    let targetDepth = 1
    if (target.parentId !== null) {
      if (isSelfOrDescendant(dragged, target.parentId)) return false
      const parentDepth = depthOf(tree, target.parentId)
      if (parentDepth === 0) return false // missing parent — never depth-1 by accident
      targetDepth = parentDepth + 1
    }
    return targetDepth + subtreeDepth(dragged) - 1 <= MAX_BLOCK_DEPTH
  }

  /**
   * Slash-to-widget split (spec §3, pinned identity rules): replace the prose
   * block with [before?, widget, after?] in ONE pass — the before half KEEPS the
   * original id when non-empty; the widget and after half are fresh; empty
   * halves produce nothing.
   */
  function splitRichTextAt(
    tree: BlockInstance[],
    id: string,
    richFieldName: string,
    beforeHtml: string,
    afterHtml: string,
    newBlock: BlockInstance,
  ): BlockInstance[] {
    return mapLists(tree, (list) => {
      const index = list.findIndex((b) => b.id === id)
      if (index < 0) return list
      const original = list[index]!
      const replacement: BlockInstance[] = []
      if (!isEmptyHtml(beforeHtml)) {
        replacement.push({ ...original, data: { ...original.data, [richFieldName]: beforeHtml } })
      }
      replacement.push(newBlock)
      if (!isEmptyHtml(afterHtml)) {
        replacement.push({
          id: newBlockId(),
          type: original.type,
          data: { ...original.data, [richFieldName]: afterHtml },
        })
      }
      const next = [...list]
      next.splice(index, 1, ...replacement)
      return next
    })
  }

  return {
    findById,
    insertAt,
    removeById,
    duplicateById,
    patchDataById,
    moveById,
    moveAcross,
    subtreeDepth,
    depthOf,
    canDropAt,
    splitRichTextAt,
  }
}

export type BlockListOps = ReturnType<typeof createBlockListOps>
