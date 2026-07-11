import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

const main: NavigationMenuItem[] = [
  {
    label: 'Workspaces',
    icon: 'i-lucide-building-2',
    to: '/workspaces',
    children: [
      { label: 'All workspaces', icon: 'i-lucide-list', to: '/workspaces' },
      { label: 'Domains', icon: 'i-lucide-globe-2', to: '/workspaces/_selected/domains' },
      { label: 'Members', icon: 'i-lucide-users', to: '/workspaces/_selected/members' },
    ],
  },
]

export function registerTenancyModule(): void {
  registerAdminModule({ id: 'tenancy', requires: ['thallo.tenancy'], nav: { main } })
}
