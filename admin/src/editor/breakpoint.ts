// The active breakpoint (visual builder spec §3.4): editor state every responsive control binds
// to. It is synced with the stage viewport preset in both directions but never inferred from
// the iframe's actual width — the editor decides which breakpoint an edit addresses.
import { ref } from 'vue'
import type { Breakpoint } from '@/style/types'

export type ViewportPreset = 'desktop' | 'tablet' | 'mobile'

export const BREAKPOINT_OF_VIEWPORT: Record<ViewportPreset, Breakpoint> = {
  desktop: 'lg',
  tablet: 'md',
  mobile: 'base',
}

export const VIEWPORT_OF_BREAKPOINT: Record<Breakpoint, ViewportPreset> = {
  lg: 'desktop',
  md: 'tablet',
  base: 'mobile',
}

export const BREAKPOINT_LABELS: Record<Breakpoint, string> = {
  base: 'Base',
  md: 'Tablet',
  lg: 'Desktop',
}

/** One shared ref per editor session: the page and every inspector control read the same value. */
export const activeBreakpoint = ref<Breakpoint>('lg')

export function setActiveBreakpoint(bp: Breakpoint): void {
  activeBreakpoint.value = bp
}
