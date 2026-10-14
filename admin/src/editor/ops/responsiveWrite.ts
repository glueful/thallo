// Where an edit made at the active breakpoint lands. Styles cascade mobile first — base, then md,
// then lg — but the editor opens on Desktop, so an edit saved for lg alone left phones and tablets
// with none of the design (a home page with no side margins and no columns below 1024px).
//
// So a value set at Desktop or Tablet, on a block with no value for the property at any size, is
// saved at base as well as at the size being edited: every size gets it, and the size being edited
// keeps its own copy, so tuning the phone later leaves it as it was. Everything else — editing
// base, a later edit, removing a value, a property that is not responsive — writes exactly where
// it was asked to.
import { propertyDefinition } from '@/style/schema'
import type { Breakpoint, StyleValue } from '@/style/types'
import { readPath, settingSegments } from './apply'

export type ResponsiveWrite = [Breakpoint | null, StyleValue | null]

const ORDER: Breakpoint[] = ['base', 'md', 'lg']

export function responsiveWrites(
  settings: Record<string, unknown>,
  path: string,
  bp: Breakpoint | null,
  value: StyleValue | null,
  part?: string,
): ResponsiveWrite[] {
  const def = propertyDefinition(path)
  if (bp === null || bp === 'base' || value === null || (def !== null && !def.responsive)) {
    return [[bp, value]]
  }
  const declared = ORDER.some((at) => readPath(settings, settingSegments(path, at, part)).present)
  return declared
    ? [[bp, value]]
    : [
        ['base', value],
        [bp, value],
      ]
}
