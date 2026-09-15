import { resolve } from './resolver'
import { styleProperties } from './schema'
import { BREAKPOINTS } from './types'
import type { Breakpoint, Resolution, StyleClassRef, StyleValue } from './types'

// "Save as style class" (visual builder spec §4.5): lift a block's EXPLICIT declarations —
// never inherited or resolved ones — into a new class appended last, and confirm through the
// resolver that the lift preserves appearance before the reference is inserted.

export interface LiftedDeclaration {
  path: string
  breakpoint: Breakpoint | null
  value: StyleValue
}

function isValue(v: unknown): v is StyleValue {
  return typeof v === 'object' && v !== null && typeof (v as { type?: unknown }).type === 'string'
}

/** The instance's own declarations, in table order: what a lift moves into the class. */
export function liftedDeclarations(style: Record<string, unknown>): LiftedDeclaration[] {
  const out: LiftedDeclaration[] = []
  for (const def of styleProperties()) {
    let node: unknown = style
    for (const part of def.path.split('.')) {
      if (typeof node !== 'object' || node === null || !(part in (node as object))) {
        node = undefined
        break
      }
      node = (node as Record<string, unknown>)[part]
    }
    if (node === undefined) continue
    if (!def.responsive) {
      if (isValue(node)) out.push({ path: def.path, breakpoint: null, value: node })
      continue
    }
    for (const bp of BREAKPOINTS) {
      const v = (node as Record<string, unknown>)[bp]
      if (isValue(v)) out.push({ path: def.path, breakpoint: bp, value: v })
    }
  }
  return out
}

function outcome(r: Resolution): string {
  if (r.state === 'reset') return 'reset'
  return r.value === null ? 'theme-default' : JSON.stringify(r.value)
}

/**
 * Whether every property the block can take resolves to the same effective outcome at every
 * breakpoint before the lift (classes + instance) and after it (classes + the new class last,
 * instance emptied).
 */
export function liftPreservesAppearance(
  classes: StyleClassRef[],
  instance: Record<string, unknown>,
  lifted: StyleClassRef,
  allowed: ReadonlySet<string>,
): boolean {
  for (const def of styleProperties()) {
    if (!allowed.has(def.path)) continue
    const before = resolve(def.path, classes, instance, def) as Record<string, Resolution>
    const after = resolve(def.path, [...classes, lifted], {}, def) as Record<string, Resolution>
    const breakpoints: readonly Breakpoint[] = def.responsive ? BREAKPOINTS : ['base']
    for (const bp of breakpoints) if (outcome(before[bp]!) !== outcome(after[bp]!)) return false
  }
  return true
}
