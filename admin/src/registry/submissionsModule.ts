import type { NavigationMenuItem } from '@nuxt/ui'
import type { AdminModule } from './adminModules'

// Form submissions triage — always-on (the form block ships in core, so there is no
// capability to gate on). The live unread-count badge is injected reactively in
// layouts/default.vue; module registration itself is non-reactive.
const main: NavigationMenuItem[] = [
  {
    label: 'Submissions',
    icon: 'i-lucide-inbox',
    to: '/submissions',
  },
]

export const submissionsModule: AdminModule = { id: 'submissions', nav: { main } }
