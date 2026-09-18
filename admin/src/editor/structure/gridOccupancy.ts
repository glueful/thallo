// Which cells of a grid an appended block can reach, at one breakpoint (container-layout spec
// §11.3). Pure: no Vue, no document mutation — the same function answers the inspector's button,
// the stage's, and the recount Fill makes after the factory has answered.
//
// A grid places its items in order and never back-fills (sparse row flow), so the free cells that
// matter are the ones AFTER the last item. A hole earlier in the grid — a three-track grid with
// two children spanning 2 has one at the end of each row — is unreachable by appending, and is
// not counted.
import { resolve } from '@/style/resolver'
import { propertyDefinition } from '@/style/schema'
import type { Breakpoint, Resolution, StyleClassRef } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

/** The tracks a `layout.columns` value makes: `3` → 3, `12` → 12, `1-2-1` → 3. */
export function trackCount(columns: string): number {
  if (columns.includes('-')) return columns.split('-').length
  const count = Number(columns)
  return Number.isInteger(count) && count > 0 ? count : 1
}

/** A `layout.span` clamped to the tracks there are, as the rendered grid clamps it (spec §3.7). */
export function effectiveSpan(span: string | null, tracks: number): number {
  if (span === null) return 1
  if (span === 'full') return tracks
  const wanted = Number(span)
  if (!Number.isInteger(wanted) || wanted < 1) return 1
  return Math.min(wanted, tracks)
}

function styleOf(block: BlockInstance): Record<string, unknown> {
  const style = (block.settings as { style?: unknown } | undefined)?.style
  return typeof style === 'object' && style !== null ? (style as Record<string, unknown>) : {}
}

/** The choice in force for `path` at `breakpoint` through the real cascade, or null. */
function choiceAt(
  path: string,
  block: BlockInstance,
  breakpoint: Breakpoint,
  classes: StyleClassRef[],
): string | null {
  const def = propertyDefinition(path)
  if (def === null) return null
  const resolutions = resolve(path, classes, styleOf(block), def) as Record<string, Resolution>
  const value = resolutions[def.responsive ? breakpoint : 'base']?.value
  return value && value.type === 'choice' ? value.value : null
}

/**
 * Free cells after the last item, by ordinary append placement. `tracks` for a grid with no
 * visible child, 0 when the last row is full. Tracks, spans and visibility are read at the
 * breakpoint through the cascade — inherited and class-supplied values included — and a child
 * hidden there occupies nothing.
 */
export function lastRowFree(
  container: BlockInstance,
  breakpoint: Breakpoint,
  classesFor: (id: string) => StyleClassRef[],
): number {
  const tracks = trackCount(
    choiceAt('layout.columns', container, breakpoint, classesFor(container.id)) ?? '1',
  )
  const children = Array.isArray(container.data.content)
    ? (container.data.content as BlockInstance[])
    : []
  // The browser's sparse row flow: a cursor along the row; an item that does not fit what is
  // left wraps to a new row; a full row starts the next.
  let cursor = 0
  let placed = 0
  for (const child of children) {
    const classes = classesFor(child.id)
    if (choiceAt('visibility', child, breakpoint, classes) === 'hidden') continue
    const span = effectiveSpan(choiceAt('layout.span', child, breakpoint, classes), tracks)
    if (cursor + span > tracks) cursor = 0
    cursor += span
    if (cursor === tracks) cursor = 0
    placed++
  }
  if (placed === 0) return tracks
  return cursor === 0 ? 0 : tracks - cursor
}
