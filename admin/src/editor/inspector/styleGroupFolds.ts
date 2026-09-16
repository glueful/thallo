import { reactive } from 'vue'

// Which Style tab groups the user has folded. One choice per browser, shared by every block:
// folding Spacing while tuning a heading keeps it folded on the next block and the next session.
const KEY = 'thallo.style-tab.folded'

function load(): string[] {
  try {
    const raw = localStorage.getItem(KEY)
    const parsed: unknown = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed.filter((k): k is string => typeof k === 'string') : []
  } catch {
    return []
  }
}

function save(keys: string[]): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(keys))
  } catch {
    // Storage denied or full: the fold still holds for this page.
  }
}

const folded = reactive(new Set<string>(load()))

export function isFolded(key: string): boolean {
  return folded.has(key)
}

export function toggleFold(key: string): void {
  if (folded.has(key)) folded.delete(key)
  else folded.add(key)
  save([...folded])
}

/** Test seam: forget every fold and re-read storage. */
export function resetFolds(): void {
  folded.clear()
  for (const key of load()) folded.add(key)
}
