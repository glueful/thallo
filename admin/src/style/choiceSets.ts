// A choice that names a set (StyleSchema::sets): which sides a border is drawn on, which corners the
// Corners size rounds. `all`, or one, two or three members, each in the members' order joined by `-`,
// so every set has exactly one name. The Style tab picks the members as toggles.

/** Clockwise from the top: the sides a border is drawn on, the corners a radius rounds. */
export const SIDES = ['top', 'right', 'bottom', 'left']
export const CORNERS = ['tl', 'tr', 'br', 'bl']

/** `all`, then every proper non-empty subset: the singles, the pairs, then the threes. */
export function sets(members: readonly string[]): string[] {
  const bySize: string[][][] = []
  for (let mask = 1; mask < (1 << members.length) - 1; mask++) {
    const set = members.filter((_, i) => mask & (1 << i))
    ;(bySize[set.length] ??= []).push(set)
  }
  const out = ['all']
  for (const group of bySize) {
    if (group === undefined) continue
    group.sort((a, b) => {
      for (let i = 0; i < a.length; i++) {
        const order = members.indexOf(a[i]!) - members.indexOf(b[i]!)
        if (order !== 0) return order
      }
      return 0
    })
    for (const set of group) out.push(set.join('-'))
  }
  return out
}

/** The members a value names: every one for `all` or no value. */
export function membersOf(value: string | null, members: readonly string[]): string[] {
  if (value === null || value === 'all') return [...members]
  const named = value.split('-')
  return members.filter((m) => named.includes(m))
}

/** The one value naming these members. */
export function valueOf(selected: readonly string[], members: readonly string[]): string {
  const set = members.filter((m) => selected.includes(m))
  return set.length === members.length ? 'all' : set.join('-')
}

/**
 * The paths picked as a set, each with its members, what each toggle reads as, and why the last one
 * on cannot be turned off (none is the setting it modifies at None).
 */
export const CHOICE_SETS: Record<
  string,
  { members: readonly string[]; labels: Record<string, string>; lastOn: string }
> = {
  'border.sides': {
    members: SIDES,
    labels: { top: 'Top', right: 'Right', bottom: 'Bottom', left: 'Left' },
    lastOn: 'At least one side: for no border, set Border to None.',
  },
  corners: {
    members: CORNERS,
    labels: { tl: 'Top left', tr: 'Top right', br: 'Bottom right', bl: 'Bottom left' },
    lastOn: 'At least one corner: for square corners, set Corners to None.',
  },
}
