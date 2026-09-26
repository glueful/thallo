import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Type layouts (type layouts spec §6.1): a layout designs every page of a kind at once — edited on
// the site's real theme output, so it needs rendered delivery. Lives under the shared "Site" group,
// after the header and footer.
const site: NavigationMenuItem[] = [
  {
    label: 'Layouts',
    icon: 'i-lucide-layout-template',
    to: '/layouts',
  },
]

export const layoutsModule: AdminModule = {
  id: 'layouts',
  requires: ['thallo.render'],
  nav: { site },
}
