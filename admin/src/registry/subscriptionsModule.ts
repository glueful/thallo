import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Subscriptions admin module (thallo-subscriptions Phase B, Task 11) -- gated on
// `thallo.subscriptions`, contributing ONE top-level group with Plans and Billing as children.
// Mirrors `commerceModule.ts`'s group-with-children convention (a new top-level admin area gets
// its own group, not a Settings-contribution the way `accountModule.ts`'s single Settings child
// does) -- Subscriptions is a standalone area with two distinct working surfaces, not a single
// settings panel.
//
// Task 19 (Phase C, spec §5.3): a THIRD, distinct child -- "Workspace billing" at `/billing` --
// for the workspace-scoped self-serve billing page, alongside the two existing platform-scoped
// pages. The registry declares all three unconditionally; `shapeTenancyNav.ts` is the ONLY place
// that filters them, by two disjoint access flags (platform Plans/Billing by `manage_platform`,
// Workspace billing by `manage_billing`) -- this static module-level `requires` only proves the
// `thallo.subscriptions` pack is installed, never which of those two authorities the signed-in
// user actually holds.
const main: NavigationMenuItem[] = [
  {
    label: 'Subscriptions',
    icon: 'i-lucide-credit-card',
    defaultOpen: false,
    children: [
      { label: 'Plans', to: '/subscriptions/plans' },
      { label: 'Billing', to: '/subscriptions/billing' },
      { label: 'Workspace billing', to: '/billing' },
    ],
  },
]

export const subscriptionsModule: AdminModule = {
  id: 'subscriptions',
  requires: ['thallo.subscriptions'],
  nav: { main },
}
