import { resolve } from './resolver'
import { propertyDefinition, styleProperties } from './schema'
import { BREAKPOINTS } from './types'
import type { Breakpoint, Resolution, StyleClassRef, StyleValue } from './types'

// Detach (visual builder spec §4.4), mirrored from `Thallo\Contracts\Style\DetachTransformation`
// and pinned by `packages/thallo-render/detach-fixtures/v1`: remove a style class reference while
// preserving every managed effective value at every breakpoint. Resolve every property the block
// has the capability for with and without the class; where the outcome differs, write the
// previous resolution into the instance at that breakpoint (`reset` where the previous outcome
// was a reset); normalise by dropping empty maps. Pure.

/** The capability paths a declaration expands to: a path, or a group naming its paths. */
export function capabilityPaths(declaration: readonly string[] | null | undefined): Set<string> {
  const out = new Set<string>()
  for (const entry of declaration ?? []) {
    if (propertyDefinition(entry) !== null) out.add(entry)
    else for (const row of styleProperties()) if (row.group === entry) out.add(row.path)
  }
  return out
}

/** The effective outcome: a managed value, a reset, or the theme default. */
function outcome(r: Resolution): string {
  if (r.state === 'reset') return 'reset'
  return r.value === null ? 'theme-default' : JSON.stringify(r.value)
}

function setAt(
  target: Record<string, unknown>,
  segments: string[],
  value: StyleValue,
): Record<string, unknown> {
  const [key, ...rest] = segments as [string, ...string[]]
  const next: Record<string, unknown> = { ...target }
  if (rest.length === 0) {
    next[key] = value
    return next
  }
  const child = target[key]
  next[key] = setAt(
    typeof child === 'object' && child !== null ? (child as Record<string, unknown>) : {},
    rest,
    value,
  )
  return next
}

function normalise(style: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(style)) {
    if (typeof value === 'object' && value !== null && !('type' in (value as object))) {
      const inner = normalise(value as Record<string, unknown>)
      if (Object.keys(inner).length === 0) continue
      out[key] = inner
      continue
    }
    out[key] = value
  }
  return out
}

/**
 * The block's `settings.style` after detaching `classId`.
 *
 * @param classes the block's ordered classes
 * @param instance the block's `settings.style` before
 * @param allowed the block's capability paths (see `capabilityPaths`)
 */
export function detachStyleClass(
  classes: StyleClassRef[],
  instance: Record<string, unknown>,
  classId: string,
  allowed: ReadonlySet<string>,
): Record<string, unknown> {
  const without = classes.filter((c) => c.id !== classId)
  if (without.length === classes.length) return normalise(instance)
  let out = instance
  for (const def of styleProperties()) {
    if (!allowed.has(def.path)) continue
    const before = resolve(def.path, classes, instance, def) as Record<string, Resolution>
    const after = resolve(def.path, without, instance, def) as Record<string, Resolution>
    const breakpoints: readonly Breakpoint[] = def.responsive ? BREAKPOINTS : ['base']
    for (const bp of breakpoints) {
      const was = before[bp]!
      if (outcome(was) === outcome(after[bp]!)) continue
      const write: StyleValue | null = was.state === 'reset' ? { type: 'reset' } : was.value
      if (write === null) continue // removing a layer never turns a theme default into a declaration
      const segments = def.path.split('.')
      if (def.responsive) segments.push(bp)
      out = setAt(out, segments, write)
    }
  }
  return normalise(out)
}
