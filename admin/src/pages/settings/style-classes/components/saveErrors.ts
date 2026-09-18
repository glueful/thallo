// A refused style class save, in the author's terms (container-layout spec §12.5). The server
// names each refused field by key — `style.layout.display.md` — and the message is its own; this
// only says which property and which breakpoint that key is. Nothing is reinterpreted: a key this
// page cannot map (an unknown path the class still carries, a field that is not a style) is shown
// as the key itself, because the raw key is then the only true thing to say.
import { propertyDefinition } from '@/style/schema'
import { BREAKPOINTS } from '@/style/types'
import type { Breakpoint } from '@/style/types'
import { LAYOUT_LABELS } from '@/editor/inspector/layoutLabels'

export interface SaveError {
  key: string
  label: string
  breakpoint: Breakpoint | null
  message: string
}

const PREFIX = 'style.'

function labelOf(path: string): string {
  const named = LAYOUT_LABELS[path]
  if (named) return named
  const words = path.replace(/[._]/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}

export function describeSaveErrors(fieldErrors: Record<string, string>): SaveError[] {
  return Object.entries(fieldErrors).map(([key, message]) => {
    if (key.startsWith(PREFIX)) {
      const path = key.slice(PREFIX.length)
      if (propertyDefinition(path)) return { key, label: labelOf(path), breakpoint: null, message }
      const cut = path.lastIndexOf('.')
      const tail = path.slice(cut + 1) as Breakpoint
      const head = path.slice(0, cut)
      if (cut > 0 && BREAKPOINTS.includes(tail) && propertyDefinition(head)) {
        return { key, label: labelOf(head), breakpoint: tail, message }
      }
    }
    return { key, label: key, breakpoint: null, message }
  })
}
