import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Site › Appearance: the live theme, its colours, the design settings, the logos and site icon —
// how the site looks, beside the other pages that shape it (Header & footer, the Theme editor).
// Always-on: these are core general settings; the Theme card hides itself when the render pack's
// themes endpoint is unavailable.
const site: NavigationMenuItem[] = [
  {
    label: 'Appearance',
    icon: 'i-lucide-palette',
    to: '/appearance',
  },
]

export const appearanceModule: AdminModule = { id: 'appearance', nav: { site } }
