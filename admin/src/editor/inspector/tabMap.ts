// Which inspector tab a managed property belongs to (container-layout spec §5).
//
// Membership is per PROPERTY, not per capability group, and this map is the only place that
// decides it. The alignment group is why: text alignment is styling, while placement and the
// distribution of a container's children are layout decisions, and a group-level rule could not
// split them. Width moves too — how wide a box is belongs with how it sits in its parent.

export type InspectorTab = 'style' | 'layout'

/**
 * Paths and path prefixes that belong to the Layout tab. A prefix ending in `.` matches a whole
 * family, so a property added to the contract later lands on the right tab without an edit here.
 */
const LAYOUT: readonly string[] = ['layout.', 'width', 'alignment.self', 'alignment.content']

/** Everything not claimed by Layout is styling: an unknown path stays visible rather than vanish. */
export function tabOf(path: string): InspectorTab {
  for (const entry of LAYOUT) {
    if (entry.endsWith('.') ? path.startsWith(entry) : path === entry) return 'layout'
  }
  return 'style'
}

/** The declared paths that belong to one tab, in the order they were given. */
export function pathsForTab(paths: Iterable<string>, tab: InspectorTab): string[] {
  return [...paths].filter((path) => tabOf(path) === tab)
}

/** Whether a block showing these paths has anything to put on the tab. */
export function hasTab(paths: Iterable<string>, tab: InspectorTab): boolean {
  for (const path of paths) if (tabOf(path) === tab) return true
  return false
}
