import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Commerce admin module — Task 9 (admin-commerce-area plan, slice 3) registered this scaffold-only
// (gated on `thallo.commerce`, no `nav`) so Task 10/11 could land query/page work under a stable,
// already-gated module id without the sidebar showing a dead-end entry before there was anything
// behind it. Task 12 atomically added `nav` here — Commerce → Products ONLY — now that Products AND
// bidirectional linking have both landed: design spec §6/§9's first user-visible activation
// boundary. Task 13a appends Orders to the SAME `main` array rather than registering a second
// top-level Commerce entry — later Orders sub-tasks (13b/c/d) build on the same list/detail pages,
// not new nav entries. Task 14 appends Discounts the same way. Task 15a appends Settings ONLY once
// its first tab (Shipping zones) is green — 15b/15c (classes, tax rates) extend the SAME Settings
// page later without a further nav change. Task 16 appends Reviews the same way now that
// moderation (approve/spam/delete/bulk) is green.
const main: NavigationMenuItem[] = [
  {
    label: 'Commerce',
    icon: 'i-lucide-shopping-cart',
    defaultOpen: false,
    children: [
      { label: 'Products', to: '/commerce/products' },
      { label: 'Orders', to: '/commerce/orders' },
      { label: 'Discounts', to: '/commerce/discounts' },
      { label: 'Settings', to: '/commerce/settings' },
      { label: 'Reviews', to: '/commerce/reviews' },
    ],
  },
]

export const commerceModule: AdminModule = { id: 'commerce', requires: ['thallo.commerce'], nav: { main } }
