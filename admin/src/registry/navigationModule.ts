import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Navigation (menu builder) nav — gated on the `thallo.navigation` capability; disappears
// when the pack is disabled or removed (the backend 404s those routes too — see the
// pack's NavigationRemovabilityTest). Lives under the shared expandable "Site" group,
// alongside future site-facing modules (render/themes etc.).
const site: NavigationMenuItem[] = [
  {
    label: 'Navigation',
    icon: 'i-lucide-menu',
    to: '/navigation',
  },
]

export const navigationModule: AdminModule = { id: 'navigation', requires: ['thallo.navigation'], nav: { site } }
