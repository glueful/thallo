import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Subscriptions admin module (thallo-subscriptions Phase B, Task 11) -- gated on
// `thallo.subscriptions`, contributing ONE top-level group with Plans and Billing as children.
// Mirrors `commerceModule.ts`'s group-with-children convention (a new top-level admin area gets
// its own group, not a Settings-contribution the way `accountModule.ts`'s single Settings child
// does) -- Subscriptions is a standalone area with two distinct working surfaces, not a single
// settings panel.
const main: NavigationMenuItem[] = [
  {
    label: 'Subscriptions',
    icon: 'i-lucide-credit-card',
    defaultOpen: false,
    children: [
      { label: 'Plans', to: '/subscriptions/plans' },
      { label: 'Billing', to: '/subscriptions/billing' },
    ],
  },
]

export const subscriptionsModule: AdminModule = {
  id: 'subscriptions',
  requires: ['thallo.subscriptions'],
  nav: { main },
}
