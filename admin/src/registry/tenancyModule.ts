import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

const main: NavigationMenuItem[] = [
  {
    label: 'Tenants',
    icon: 'i-lucide-building-2',
    to: '/tenants',
    children: [
      { label: 'All tenants', icon: 'i-lucide-list', to: '/tenants' },
      { label: 'Domains', icon: 'i-lucide-globe-2', to: '/tenants/_selected/domains' },
      { label: 'Members', icon: 'i-lucide-users', to: '/tenants/_selected/members' },
    ],
  },
]

export function registerTenancyModule(): void {
  registerAdminModule({ id: 'tenancy', requires: ['thallo.tenancy'], nav: { main } })
}
