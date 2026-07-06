import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// Global chrome regions (header/footer block lists) — always-on: the edit API is
// core app surface; the regions render wherever thallo-render is active, and the
// data round-trips regardless. Lives under the shared "Site" group.
const site: NavigationMenuItem[] = [
  {
    label: 'Header & footer',
    icon: 'i-lucide-layout-panel-top',
    to: '/regions',
  },
]

export function registerRegionsModule(): void {
  registerAdminModule({ id: 'regions', nav: { site } })
}
