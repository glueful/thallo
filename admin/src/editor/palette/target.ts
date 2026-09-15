// Insertion targets (Phase C.1): an INTENT the Blocks tab remembers, resolved to a position at the
// moment of use — never an index stored ahead of time. "after Hero" keeps Hero's id and re-locates
// it; "into Columns › col_2" recomputes the slot's end; a gap is pinned to the history sequence it
// was armed at and dies with the next structural change.
import { createBlockListOps, type BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { EditorDocument, Position } from '@/editor/ops/types'
import { checkInsert, type Legality, type LegalityContext } from '@/editor/structure/legality'

export type InsertTarget =
  | { kind: 'after'; block: string }
  | { kind: 'into'; parent: string | null; field: string }
  | { kind: 'at'; position: Position; sequence: number }

export interface ResolvedTarget {
  position: Position
  label: string
}

function asList(value: unknown): BlockInstance[] {
  return Array.isArray(value) ? (value as BlockInstance[]) : []
}

function labelOf(ctx: LegalityContext, slug: string): string {
  return ctx.blockTypes().find((t) => t.slug === slug)?.label ?? slug
}

/** Where `id` sits now, with the block itself. */
function locate(
  doc: EditorDocument,
  id: string,
  ctx: LegalityContext,
): { position: Position; block: BlockInstance } | null {
  const ops = createBlockListOps(ctx.regionsOf)
  for (const field of Object.keys(ctx.rootSlots())) {
    const found = ops.locateById(asList(doc.fields[field]), id)
    if (found) {
      return {
        position: {
          parent: found.parentId,
          slot: found.parentId === null ? field : found.region,
          index: found.index,
        },
        block: found.list[found.index]!,
      }
    }
  }
  return null
}

export function resolveTarget(
  target: InsertTarget,
  doc: EditorDocument,
  ctx: LegalityContext,
  sequence: number,
): ResolvedTarget | null {
  switch (target.kind) {
    case 'after': {
      const at = locate(doc, target.block, ctx)
      if (!at) return null
      return {
        position: { ...at.position, index: at.position.index + 1 },
        label: `after ${labelOf(ctx, at.block.type)}`,
      }
    }
    case 'into': {
      if (target.parent === null) {
        if (!(target.field in ctx.rootSlots())) return null
        return {
          position: {
            parent: null,
            slot: target.field,
            index: asList(doc.fields[target.field]).length,
          },
          label: `at the end of ${target.field}`,
        }
      }
      const parent = locate(doc, target.parent, ctx)
      if (!parent) return null
      return {
        position: {
          parent: target.parent,
          slot: target.field,
          index: asList(parent.block.data[target.field]).length,
        },
        label: `into ${labelOf(ctx, parent.block.type)} › ${target.field}`,
      }
    }
    case 'at': {
      if (target.sequence !== sequence) return null
      const p = target.position
      const where =
        p.parent === null
          ? `of ${p.slot}`
          : `of ${labelOf(ctx, locate(doc, p.parent, ctx)?.block.type ?? '')} › ${p.slot}`
      return { position: p, label: `at position ${p.index + 1} ${where}` }
    }
  }
}

/**
 * The tile's preflight against a position: a height-one placeholder of the type. Exact for the
 * slot allow-list and for a starter without children; OPTIMISTIC for depth and slot constraints
 * when the type's starter nests blocks — the real factory instance is judged again at commit.
 */
export function tilePreflight(
  slug: string,
  position: Position,
  doc: EditorDocument,
  ctx: LegalityContext,
): Legality {
  return checkInsert(doc, position, { id: '', type: slug, data: {}, settings: {} }, ctx)
}
