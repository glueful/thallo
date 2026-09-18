// What the Layout tab needs to know about a block's layout at one breakpoint
// (container-layout spec §3.3, §5).
//
// Two questions, both answered through the same cascade the renderer uses, so the inspector and
// the page never disagree about what is in force:
//
//   - `effectiveDisplay` — the mode a container arranges its children in RIGHT NOW. The controls
//     follow it: a grid parent offers tracks, a flex parent offers direction and wrap.
//   - `dormantPaths` — the settings that mode ignores but the block still carries. Switching mode
//     never deletes anything, so the tab has to say what is being kept and is currently inert,
//     or an author loses track of values that reappear when they switch back.
//
// Both count values a style class supplies, not only local declarations: a track count arriving
// from a class is just as retained, and just as dormant, as one written here.
import { declarationOrigin, resolve } from '@/style/resolver'
import { propertyDefinition, styleProperties } from '@/style/schema'
import { BREAKPOINTS } from '@/style/types'
import type { Breakpoint, Resolution, StyleClassRef } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

/**
 * The modes a container can arrange its children in (spec §11.1). The theme's own default is a
 * flex column — the stack block flow was — so there is no third mode for "stack".
 */
export type LayoutDisplay = 'flex' | 'grid'

/** Whose dormancy is being asked about: the block as a parent, or as an item of a given parent. */
export type LayoutRole = 'parent' | LayoutDisplay

/** Properties that only bite in one mode (spec §3.3). Everything else applies in both. */
const PARENT_ONLY: Record<'flex' | 'grid', readonly string[]> = {
  flex: ['layout.direction', 'layout.wrap'],
  grid: ['layout.columns'],
}
const ITEM_ONLY: Record<'flex' | 'grid', readonly string[]> = {
  flex: ['layout.basis', 'layout.grow', 'layout.shrink'],
  grid: ['layout.span'],
}

function styleOf(block: BlockInstance): Record<string, unknown> {
  const style = (block.settings as { style?: unknown } | undefined)?.style
  return typeof style === 'object' && style !== null ? (style as Record<string, unknown>) : {}
}

function resolutionAt(
  path: string,
  block: BlockInstance,
  breakpoint: Breakpoint,
  classes: StyleClassRef[],
): Resolution | null {
  const def = propertyDefinition(path)
  if (def === null) return null
  const resolutions = resolve(path, classes, styleOf(block), def) as Record<string, Resolution>
  return resolutions[def.responsive ? breakpoint : 'base'] ?? null
}

/**
 * The display a container has in force at `breakpoint`, through the real cascade: a narrower
 * declaration inherits upward, a class can supply it, and a reset lands back on the theme default.
 */
export function effectiveDisplay(
  block: BlockInstance,
  breakpoint: Breakpoint,
  classes: StyleClassRef[],
): LayoutDisplay {
  const resolution = resolutionAt('layout.display', block, breakpoint, classes)
  const value = resolution?.value
  if (value && value.type === 'choice' && (value.value === 'flex' || value.value === 'grid')) {
    return value.value
  }
  // Nothing declared, a reset, or a stored value the contract does not offer: the theme default.
  return 'flex'
}

/**
 * The paths this block carries a value for — its own or a class's — that the mode in force
 * ignores. `role` is `parent` to judge the block's own arrangement settings, or the parent's
 * display to judge its item settings.
 */
export function dormantPaths(
  block: BlockInstance,
  breakpoint: Breakpoint,
  classes: StyleClassRef[],
  role: LayoutRole,
): string[] {
  const mode = role === 'parent' ? effectiveDisplay(block, breakpoint, classes) : role
  const table = role === 'parent' ? PARENT_ONLY : ITEM_ONLY
  const dormant = table[mode === 'flex' ? 'grid' : 'flex']

  const held: string[] = []
  for (const path of dormant) {
    const resolution = resolutionAt(path, block, breakpoint, classes)
    // Theme-default and reset hold nothing; an explicit or inherited value is what gets kept.
    if (resolution && resolution.value !== null) held.push(path)
  }
  // Listed in the contract's own order, so the disclosure reads the same way the controls do
  // whichever mode is in force.
  const order = styleProperties().map((p) => p.path)
  return held.sort((a, b) => order.indexOf(a) - order.indexOf(b))
}

/**
 * A stored choice the contract no longer offers, where the cascade says it is in force (spec
 * §11.1). Generic over choice paths and naming no value: it is whatever is stored that the
 * property's choices do not include.
 */
export interface InvalidChoice {
  path: string
  /** The stored value, verbatim. */
  value: string
  /** Where it is DECLARED — from `declarationOrigin`, never the breakpoint being edited. */
  breakpoint: Breakpoint
  source: 'instance' | { classId: string }
}

/** The value Replace writes for a path. A path absent from it offers Remove only. */
export const REPLACEMENT: Record<string, string> = { 'layout.display': 'flex' }

/** The invalid choice in force for `path` at `breakpoint`, or null when what is in force is offered. */
export function invalidChoiceAt(
  path: string,
  block: BlockInstance,
  breakpoint: Breakpoint,
  classes: StyleClassRef[],
): InvalidChoice | null {
  const def = propertyDefinition(path)
  const value = resolutionAt(path, block, breakpoint, classes)?.value
  if (!def || !def.choices || !value || value.type !== 'choice') return null
  if (def.choices.includes(value.value)) return null
  const origin = declarationOrigin(path, classes, styleOf(block), def, breakpoint)
  if (origin === null) return null
  return {
    path,
    value: value.value,
    breakpoint: origin.breakpoint,
    source:
      origin.source === 'instance' ? 'instance' : { classId: origin.source.slice('class:'.length) },
  }
}

/**
 * Every invalid choice a style record declares ITSELF, at every breakpoint — the class editor's
 * view, where there is no cascade to ask: the record is the class, and each declaration in it is
 * one the class must repair. In the contract's property order.
 */
export function invalidChoicesIn(style: unknown): Omit<InvalidChoice, 'source'>[] {
  const out: Omit<InvalidChoice, 'source'>[] = []
  const record = (typeof style === 'object' && style !== null ? style : {}) as Record<
    string,
    unknown
  >
  for (const def of styleProperties()) {
    if (!def.choices) continue
    let node: unknown = record
    for (const part of def.path.split('.')) {
      node =
        typeof node === 'object' && node !== null
          ? (node as Record<string, unknown>)[part]
          : undefined
    }
    if (typeof node !== 'object' || node === null) continue
    const declared: [Breakpoint, unknown][] = def.responsive
      ? BREAKPOINTS.map(
          (bp) => [bp, (node as Record<string, unknown>)[bp]] as [Breakpoint, unknown],
        )
      : [['base', node]]
    for (const [bp, raw] of declared) {
      const v = raw as { type?: unknown; value?: unknown } | undefined
      if (
        v &&
        v.type === 'choice' &&
        typeof v.value === 'string' &&
        !def.choices.includes(v.value)
      ) {
        out.push({ path: def.path, value: v.value, breakpoint: bp })
      }
    }
  }
  return out
}
