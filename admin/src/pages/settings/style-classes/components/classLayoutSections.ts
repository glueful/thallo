// Where each layout property sits on the class Layout tab (container-layout spec §12.2).
//
// The tab renders every Layout path the tab map gives it, so a property added to the contract is
// editable the day it lands — an unplaced one falls to the end of Box. That keeps the editor
// complete; THIS list keeps the placement deliberate, and a test holds it equal to the contract's
// Layout paths so a new property fails there until someone decides where it goes.
//
// A family is a group under a permanent label saying where its settings apply. The labels are
// never conditional: a class has no mode and no parent to make them so.

export interface ClassLayoutGroup {
  /** Present for a labelled family; absent for rows every mode (or parent) uses. */
  family?: 'flex' | 'grid' | 'grid-parent' | 'flex-parent'
  label?: string
  paths: readonly string[]
}
export interface ClassLayoutSection {
  key: 'container' | 'box' | 'item'
  label: string
  groups: readonly ClassLayoutGroup[]
}

export const CLASS_LAYOUT_SECTIONS: readonly ClassLayoutSection[] = [
  {
    key: 'container',
    label: 'Container',
    groups: [
      { paths: ['layout.display'] },
      { family: 'flex', label: 'Applies in Flex', paths: ['layout.direction', 'layout.wrap'] },
      { family: 'grid', label: 'Applies in Grid', paths: ['layout.columns'] },
      {
        paths: [
          'alignment.content',
          'layout.align_items',
          'layout.gap.column',
          'layout.gap.row',
          'layout.content_width',
          'layout.gutter',
        ],
      },
    ],
  },
  {
    key: 'box',
    label: 'Box',
    groups: [{ paths: ['width', 'alignment.self', 'layout.min_height', 'layout.overflow'] }],
  },
  {
    key: 'item',
    label: 'As an item',
    groups: [
      { family: 'grid-parent', label: 'Applies in a Grid parent', paths: ['layout.span'] },
      {
        family: 'flex-parent',
        label: 'Applies in a Flex parent',
        paths: ['layout.basis', 'layout.grow', 'layout.shrink'],
      },
      { paths: ['layout.align_self'] },
    ],
  },
]

/** Every path the list names, in order. */
export function placedLayoutPaths(): string[] {
  return CLASS_LAYOUT_SECTIONS.flatMap((section) => section.groups.flatMap((g) => [...g.paths]))
}

/** The Layout paths nobody has placed: what the fallback renders, and what the test refuses. */
export function unplacedLayoutPaths(layoutPaths: readonly string[]): string[] {
  const placed = new Set(placedLayoutPaths())
  return layoutPaths.filter((path) => !placed.has(path))
}

/** The settings of each mode's family, for the retained-settings note (spec §12.3). */
export const FAMILY_PATHS: Record<'flex' | 'grid', readonly string[]> = {
  flex: ['layout.direction', 'layout.wrap'],
  grid: ['layout.columns'],
}
