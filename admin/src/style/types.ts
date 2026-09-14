// Visual builder spec §1.1–1.6: the typed value model as the admin sees it. The property table
// itself is served by the style-schema endpoint (slice A4); `schema.ts` carries the resolver's
// minimal mirror (responsiveness per path) so fixtures can run without the network.

export type Breakpoint = 'base' | 'md' | 'lg'
export const BREAKPOINTS: readonly Breakpoint[] = ['base', 'md', 'lg'] as const

export type StyleValue =
  | { type: 'token'; value: string }
  | { type: 'choice'; value: string }
  | { type: 'identifier'; value: string }
  | { type: 'reset' }

export type ResolutionState = 'explicit' | 'inherited' | 'theme-default' | 'reset'

export interface Resolution {
  /** The managed value; null for theme-default and reset. */
  value: StyleValue | null
  /** `instance`, `class:<id>` or `theme-default`. */
  source: string
  state: ResolutionState
  breakpoint: Breakpoint
}

export interface StyleClassRef {
  id: string
  style: Record<string, unknown>
}

export interface PropertyDefinition {
  path: string
  group: string
  responsive: boolean
  tokenDomain: string | null
  choices: string[] | null
}
