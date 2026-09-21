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

/**
 * The width each stage preset hands the page. Tablet is exactly where the md breakpoint begins, so
 * this must be the page's VIEWPORT, not the frame's outer box: a frame that draws a border inside
 * its width gives the page 766px, and a Tablet stage then shows the phone layout. `STAGE_FRAME_EDGE`
 * draws the frame's edge outside it for that reason.
 */
export const STAGE_WIDTHS: Record<ViewportPreset, string> = {
  desktop: '100%',
  tablet: '768px',
  mobile: '390px',
}

/** The frame's edge as a ring: a ring takes no room, a border would come out of the viewport. */
export const STAGE_FRAME_EDGE = 'rounded ring ring-default bg-white'

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
