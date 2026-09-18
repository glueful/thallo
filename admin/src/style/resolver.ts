import { BREAKPOINTS } from './types'
import type { Breakpoint, PropertyDefinition, Resolution, StyleClassRef, StyleValue } from './types'

// Thallo's breakpoint-first cascade (visual builder spec §1.6), mirrored from
// `Thallo\Render\Style\CascadeResolver` and pinned by `resolver-fixtures/v1`: managed layers in
// rising precedence are each reusable class in list order, then the instance; the theme default
// is the fallback outside them. For a target breakpoint, walk from it down to `base`; at the
// first breakpoint where any layer declares the property exactly, take the highest-precedence
// declaration; a reset there terminates resolution to the theme default.

interface Layer {
  layer: string
  declarations: Partial<Record<Breakpoint, StyleValue>>
}

function isValue(v: unknown): v is StyleValue {
  return typeof v === 'object' && v !== null && typeof (v as { type?: unknown }).type === 'string'
}

function declarations(
  style: Record<string, unknown>,
  property: string,
  responsive: boolean,
): Layer['declarations'] {
  let node: unknown = style
  for (const part of property.split('.')) {
    if (typeof node !== 'object' || node === null || !(part in (node as object))) return {}
    node = (node as Record<string, unknown>)[part]
  }
  if (typeof node !== 'object' || node === null) return {}
  if (!responsive) return isValue(node) ? { base: node } : {}
  const out: Layer['declarations'] = {}
  for (const bp of BREAKPOINTS) {
    const v = (node as Record<string, unknown>)[bp]
    if (isValue(v)) out[bp] = v
  }
  return out
}

function resolveAt(
  target: Breakpoint,
  layers: Layer[],
  breakpoints: readonly Breakpoint[],
): Resolution {
  for (let i = breakpoints.indexOf(target); i >= 0; i--) {
    const bp = breakpoints[i]!
    for (const layer of layers) {
      const value = layer.declarations[bp]
      if (value === undefined) continue
      if (value.type === 'reset')
        return { value: null, source: layer.layer, state: 'reset', breakpoint: target }
      return {
        value,
        source: layer.layer,
        state: bp === target ? 'explicit' : 'inherited',
        breakpoint: target,
      }
    }
  }
  return { value: null, source: 'theme-default', state: 'theme-default', breakpoint: target }
}

/** Resolve one property for a block: its ordered classes (lowest precedence first) and its own style. */
export function resolve(
  property: string,
  classes: StyleClassRef[],
  instance: Record<string, unknown>,
  def: PropertyDefinition,
): Record<Breakpoint, Resolution> | Record<'base', Resolution> {
  const layers = layersOf(property, classes, instance, def)
  const breakpoints: readonly Breakpoint[] = def.responsive ? BREAKPOINTS : ['base']
  const out = {} as Record<Breakpoint, Resolution>
  for (const target of breakpoints) out[target] = resolveAt(target, layers, breakpoints)
  return out
}

/** The managed layers in falling precedence: the instance, then each class from last to first. */
function layersOf(
  property: string,
  classes: StyleClassRef[],
  instance: Record<string, unknown>,
  def: PropertyDefinition,
): Layer[] {
  const layers: Layer[] = [
    { layer: 'instance', declarations: declarations(instance, property, def.responsive) },
  ]
  for (let i = classes.length - 1; i >= 0; i--) {
    const c = classes[i]!
    layers.push({
      layer: `class:${c.id}`,
      declarations: declarations(c.style ?? {}, property, def.responsive),
    })
  }
  return layers
}

/** Where a value in force is declared: the layer (`instance` or `class:<id>`) and its breakpoint. */
export interface DeclarationOrigin {
  breakpoint: Breakpoint
  source: string
}

/**
 * Where the value in force at `target` is DECLARED. `Resolution.breakpoint` cannot answer this —
 * it is always the breakpoint that was asked about, so an `md` declaration inherited at `lg`
 * reports `lg` — and a repair has to write where the declaration is. The same walk as `resolveAt`
 * over the same layers, so the two cannot disagree; a reset is its own origin, since it is what
 * holds the property there. Null where only the theme default is in force.
 */
export function declarationOrigin(
  property: string,
  classes: StyleClassRef[],
  instance: Record<string, unknown>,
  def: PropertyDefinition,
  target: Breakpoint,
): DeclarationOrigin | null {
  const layers = layersOf(property, classes, instance, def)
  const breakpoints: readonly Breakpoint[] = def.responsive ? BREAKPOINTS : ['base']
  const from = def.responsive ? breakpoints.indexOf(target) : 0
  for (let i = from; i >= 0; i--) {
    const bp = breakpoints[i]!
    for (const layer of layers) {
      if (layer.declarations[bp] !== undefined) return { breakpoint: bp, source: layer.layer }
    }
  }
  return null
}
