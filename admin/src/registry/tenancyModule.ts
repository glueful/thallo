import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

const main: NavigationMenuItem[] = [
  {
    label: 'Workspaces',
    icon: 'i-lucide-briefcase-business',
    children: [
      // `exact` so it stays inactive on nested routes like /workspaces/{uuid}/members — otherwise
      // prefix matching lights it up alongside the Domains/Members/Roles child you're actually on.
      { label: 'All workspaces', icon: 'i-lucide-list', to: '/workspaces', exact: true },
      { label: 'Domains', icon: 'i-lucide-globe-2', to: '/workspaces/_selected/domains' },
      { label: 'Members', icon: 'i-lucide-users', to: '/workspaces/_selected/members' },
      { label: 'Roles', icon: 'i-lucide-shield-check', to: '/workspaces/_selected/roles' },
    ],
  },
]

export function registerTenancyModule(): void {
  registerAdminModule({ id: 'tenancy', requires: ['thallo.tenancy'], nav: { main } })
}
