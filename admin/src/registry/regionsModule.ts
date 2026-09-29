import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Global chrome regions (header/footer block lists) — always-on: the edit API is
// core app surface; the regions render wherever thallo-render is active, and the
// data round-trips regardless. Lives under the shared "Site" group.
const site: NavigationMenuItem[] = [
  {
    label: 'Header & footer',
    // A page between a top bar and a bottom bar: the site's header and footer, apart from the
    // Layouts entry's template icon.
    icon: 'i-lucide-panels-top-bottom',
    to: '/regions',
  },
]

export const regionsModule: AdminModule = { id: 'regions', nav: { site } }
