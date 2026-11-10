// Where an edit made at the active breakpoint lands. Styles cascade mobile first — base, then md,
// then lg — but the editor opens on Desktop, so an edit saved for lg alone left phones and tablets
// with none of the design (a home page with no side margins and no columns below 1024px).
//
// So a value set at Desktop or Tablet, on a block with no value for the property at any size, is
// saved at base as well as at the size being edited: every size gets it, and the size being edited
// keeps its own copy, so tuning the phone later leaves it as it was. Everything else — editing
// base, a later edit, removing a value, a property that is not responsive — writes exactly where
// it was asked to.
//
// Order and Push are the exception: they arrange a row per device (the menu button first on a
// phone, the logo first on Desktop), so an edit changes the size being edited and no other. Nothing
// is copied to base, and each larger size without a value of its own is pinned to what it showed —
// the value it inherited, or the neutral one — since it would otherwise inherit the edit.
import { propertyDefinition } from '@/style/schema'
import type { Breakpoint, StyleValue } from '@/style/types'
import { readPath, settingSegments } from './apply'

export type ResponsiveWrite = [Breakpoint | null, StyleValue | null]

const ORDER: Breakpoint[] = ['base', 'md', 'lg']

/** The per-device settings, each with the value that leaves a block where it is. */
const PER_DEVICE: Record<string, string> = { 'layout.order': 'default', 'layout.push': 'none' }

export function responsiveWrites(
  settings: Record<string, unknown>,
  path: string,
  bp: Breakpoint | null,
  value: StyleValue | null,
  part?: string,
): ResponsiveWrite[] {
  const def = propertyDefinition(path)
  if (bp === null || value === null || (def !== null && !def.responsive)) {
    return [[bp, value]]
  }
  const at = (b: Breakpoint) => readPath(settings, settingSegments(path, b, part))
  if (path in PER_DEVICE) {
    return [[bp, value], ...pins(at, bp, PER_DEVICE[path]!)]
  }
  if (bp === 'base' || ORDER.some((b) => at(b).present)) {
    return [[bp, value]]
  }
  return [
    ['base', value],
    [bp, value],
  ]
}

/** Each size above `bp` without a value of its own, pinned to what it shows now. */
function pins(
  at: (b: Breakpoint) => ReturnType<typeof readPath>,
  bp: Breakpoint,
  neutral: string,
): ResponsiveWrite[] {
  let shown: StyleValue = { type: 'choice', value: neutral }
  const writes: ResponsiveWrite[] = []
  for (const b of ORDER) {
    const own = at(b)
    if (own.present) {
      shown = own.value as StyleValue
    } else if (ORDER.indexOf(b) > ORDER.indexOf(bp)) {
      writes.push([b, shown])
    }
  }
  return writes
}
