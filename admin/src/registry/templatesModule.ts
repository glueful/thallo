import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// Templates (DB-edited theme overrides) nav — gated on the `lemma.render` capability;
// the backend routes are additionally behind the RENDER_DB_TEMPLATES kill-switch (the
// screen surfaces the resulting 404s as load errors). Lives under the shared
// expandable "Site" group next to Navigation.
const site: NavigationMenuItem[] = [
  {
    label: 'Templates',
    icon: 'i-lucide-file-code-2',
    to: '/templates',
  },
]

export function registerTemplatesModule(): void {
  registerAdminModule({ id: 'templates', requires: ['lemma.render'], nav: { site } })
}
