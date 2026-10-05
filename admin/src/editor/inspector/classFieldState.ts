// What ONE style class declares for a property at a breakpoint (container-layout spec §12.4).
//
// A class has no context: no block, no parent, no other class. So this asks the cascade with the
// class's own style as the only layer, and the states it reports are the class's — an absent
// declaration is "not set in this class", never "the theme's", because what is in force on any
// block the class is applied to is decided somewhere this editor cannot see.
import { declarationOrigin, resolve } from '@/style/resolver'
import type { Breakpoint, PropertyDefinition, Resolution, StyleValue } from '@/style/types'

export type ClassFieldKind =
  | 'set'
  | 'reset-here'
  | 'inherited'
  | 'inherited-reset'
  | 'not-set'
  | 'invalid'

export interface ClassFieldState {
  kind: ClassFieldKind
  /**
   * The declaring breakpoint: the one being edited for `set` and `reset-here`, an earlier one for
   * the inherited kinds. Null where nothing is declared, and for every non-responsive property —
   * it has no breakpoints to name.
   */
  from: Breakpoint | null
  /** The declared value; null for the reset kinds and `not-set`. For `invalid`, what is stored. */
  value: StyleValue | null
  label: string
  /** Whether a declaration exists AT the breakpoint being edited (non-responsive: at all). */
  declaredHere: boolean
}

const NOT_SET: ClassFieldState = {
  kind: 'not-set',
  from: null,
  value: null,
  label: 'Not set in this class',
  declaredHere: false,
}

function isValue(node: unknown): node is StyleValue {
  return (
    typeof node === 'object' &&
    node !== null &&
    typeof (node as { type?: unknown }).type === 'string'
  )
}

/** Whatever is stored at the property's path, shaped or not. */
function stored(style: Record<string, unknown>, path: string): unknown {
  let node: unknown = style
  for (const part of path.split('.')) {
    if (typeof node !== 'object' || node === null) return undefined
    node = (node as Record<string, unknown>)[part]
  }
  return node
}

/** A typeface property (block typeface spec §1): its value is a font ID. */
export function isFontProperty(def: PropertyDefinition): boolean {
  return def.kinds?.includes('font') ?? def.path === 'typography.family'
}

/** A typeface ID's shape: a reserved built-in, or a library family's 12 letters and digits. */
const FONT_ID = /^(?:theme|serif|humanist|geometric|slab|mono|system|[A-Za-z0-9]{12})$/

/** Whether the contract offers this value for the property. Tokens are judged only when named. */
function offered(def: PropertyDefinition, value: StyleValue, tokens?: readonly string[]): boolean {
  if (value.type === 'reset') return true
  if (isFontProperty(def)) return value.type === 'font' && FONT_ID.test(value.value)
  if (def.choices !== null) return value.type === 'choice' && def.choices.includes(value.value)
  if (def.tokenDomain !== null) {
    return value.type === 'token' && (tokens === undefined || tokens.includes(value.value))
  }
  return true
}

export function classFieldState(
  def: PropertyDefinition,
  style: Record<string, unknown>,
  breakpoint: Breakpoint,
  /** The vocabulary's names for the property's token domain; absent, tokens are not judged. */
  tokens?: readonly string[],
  /** The font library's families by ID, so a removed one is named as such (block typeface §4.4). */
  fonts?: ReadonlyMap<string, { name: string; removed: boolean }>,
): ClassFieldState {
  if (!def.responsive) {
    // Stored under a breakpoint, a non-responsive property resolves to nothing — the resolver
    // reads a bare value only — and would read as not set over a declaration the server refuses.
    const node = stored(style, def.path)
    if (typeof node === 'object' && node !== null && !isValue(node)) {
      return { kind: 'invalid', from: null, value: null, label: 'Invalid', declaredHere: true }
    }
  }

  const target: Breakpoint = def.responsive ? breakpoint : 'base'
  // The resolver answers "what", and reports an inherited reset exactly like one authored here;
  // `declarationOrigin` answers "where". The same walk over the same single layer.
  const origin = declarationOrigin(def.path, [], style, def, target)
  if (origin === null) return NOT_SET

  const resolution = (resolve(def.path, [], style, def) as Record<string, Resolution>)[target]!
  const declaredHere = !def.responsive || origin.breakpoint === breakpoint
  const from = def.responsive ? origin.breakpoint : null

  if (resolution.state === 'reset') {
    return declaredHere
      ? { kind: 'reset-here', from, value: null, label: 'Theme default, set here', declaredHere }
      : {
          kind: 'inherited-reset',
          from,
          value: null,
          label: `Theme default, from ${origin.breakpoint}`,
          declaredHere,
        }
  }

  const value = resolution.value
  if (value === null) return NOT_SET
  if (!offered(def, value, tokens)) {
    return { kind: 'invalid', from, value, label: 'Invalid', declaredHere }
  }
  const family = value.type === 'font' ? fonts?.get(value.value) : undefined
  const setLabel = family?.removed ? `Set — removed typeface: ${family.name}` : 'Set'
  return declaredHere
    ? { kind: 'set', from, value, label: setLabel, declaredHere }
    : { kind: 'inherited', from, value, label: `Inherited from ${origin.breakpoint}`, declaredHere }
}
