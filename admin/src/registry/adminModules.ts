import type { NavigationMenuItem } from '@nuxt/ui'
import { adminManifest } from './manifest'

export interface AdminModuleNav {
  main?: NavigationMenuItem[]
  utilities?: NavigationMenuItem[]
  /**
   * Items for the shared expandable "Site" group (site-facing surfaces: navigation menus,
   * and future render/theme/SEO-site modules). The registry renders ONE Site parent for
   * all contributing modules — modules must not create their own Site group.
   */
  site?: NavigationMenuItem[]
}

export interface AdminModule {
  id: string
  /** Capability ids that must ALL be visible for this module to show. Empty/absent = always-on. */
  requires?: string[]
  nav?: AdminModuleNav
}

function moduleVisible(module: AdminModule, isVisible: (id: string) => boolean): boolean {
  return (module.requires ?? []).every((id) => isVisible(id))
}

/**
 * Assemble the two-group sidebar ([main, utilities]) from the visible modules, in manifest
 * order. Visible modules' `site` items are gathered into ONE expandable "Site" group appended
 * to main (omitted entirely when no visible module contributes).
 *
 * Pure over its inputs: the module list is the STATIC `adminManifest` — menus are declared,
 * never registered at runtime, so labels/routes/ordering always come from current code and
 * there is no registration-timing constraint. `isVisible` decides per-item visibility; the
 * sidebar passes the capability store's `isVisible()` (the cached-then-verified presentation
 * hint). Router guards and feature pages never use this path — they act only on VERIFIED
 * capability state (`isEnabled()`).
 *
 * @param modules test seam — defaults to the static manifest
 */
export function visibleNav(
  isVisible: (id: string) => boolean,
  modules: readonly AdminModule[] = adminManifest,
): [NavigationMenuItem[], NavigationMenuItem[]] {
  const main: NavigationMenuItem[] = []
  const utilities: NavigationMenuItem[] = []
  const site: NavigationMenuItem[] = []
  for (const m of modules) {
    if (!moduleVisible(m, isVisible)) continue
    if (m.nav?.main) main.push(...m.nav.main)
    if (m.nav?.utilities) utilities.push(...m.nav.utilities)
    if (m.nav?.site) site.push(...m.nav.site)
  }
  if (site.length > 0) {
    main.push({
      label: 'Site',
      icon: 'i-lucide-globe',
      defaultOpen: false,
      children: site,
    })
  }
  return [main, utilities]
}
