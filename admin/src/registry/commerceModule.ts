import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Commerce admin module — Task 9 (admin-commerce-area plan, slice 3) registered this scaffold-only
// (gated on `thallo.commerce`, no `nav`) so Task 10/11 could land query/page work under a stable,
// already-gated module id without the sidebar showing a dead-end entry before there was anything
// behind it. Task 12 atomically adds `nav` here — Commerce → Products ONLY — now that Products AND
// bidirectional linking have both landed: design spec §6/§9's first user-visible activation
// boundary. Later phases (Orders, etc.) append their OWN children to this same `main` array rather
// than registering a second top-level Commerce entry.
const main: NavigationMenuItem[] = [
  {
    label: 'Commerce',
    icon: 'i-lucide-shopping-cart',
    defaultOpen: false,
    children: [{ label: 'Products', to: '/commerce/products' }],
  },
]

export const commerceModule: AdminModule = { id: 'commerce', requires: ['thallo.commerce'], nav: { main } }
