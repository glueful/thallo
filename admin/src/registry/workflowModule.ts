import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// Review-queue nav — gated on the `thallo.workflow` capability. The whole entry disappears
// from the sidebar when the pack is disabled or removed (the backend 404s those routes too
// — see the pack's WorkflowRemovabilityTest).
const main: NavigationMenuItem[] = [
  {
    label: 'Review queue',
    icon: 'i-lucide-list-checks',
    to: '/workflow',
  },
]

export function registerWorkflowModule(): void {
  registerAdminModule({ id: 'workflow', requires: ['thallo.workflow'], nav: { main } })
}
