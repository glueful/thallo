import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// Collections admin nav — gated on the `thallo.collections` capability. Mirrors coreModule.ts, but
// registers with a `requires` so the whole "Collections" group disappears from the sidebar when the
// pack is disabled or removed (the backend 404s those routes too — see RemovabilityTest).
const main: NavigationMenuItem[] = [
  {
    label: 'Collections',
    icon: 'i-lucide-database',
    to: '/collections',
    // children: [
    //   {
    //     label: 'Schema',
    //     icon: 'i-lucide-table-properties',
    //     to: '/collections',
    //   },
    //   {
    //     label: 'Data',
    //     icon: 'i-lucide-table',
    //     to: '/collections/data',
    //   },
    // ],
  },
]

export function registerCollectionsModule(): void {
  registerAdminModule({ id: 'collections', requires: ['thallo.collections'], nav: { main } })
}
