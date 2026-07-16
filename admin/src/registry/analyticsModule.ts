import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Analytics admin nav — gated on the `thallo.analytics` capability. The whole "Analytics" entry
// disappears from the sidebar when the pack is disabled or removed (the backend 404s those routes
// too — see the pack's RemovabilityTest).
const main: NavigationMenuItem[] = [
  {
    label: 'Analytics',
    icon: 'i-lucide-chart-line',
    to: '/analytics',
  },
]

export const analyticsModule: AdminModule = { id: 'analytics', requires: ['thallo.analytics'], nav: { main } }
