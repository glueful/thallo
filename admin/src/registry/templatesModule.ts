import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// Theme editor (per-theme templates, custom.css, read-only theme files, theme
// cloning) — gated on the `lemma.render` capability; the backend routes are
// additionally behind the RENDER_DB_TEMPLATES kill-switch (the screen surfaces
// the resulting 404s as load errors). Lives under the shared expandable "Site"
// group next to Navigation. Labeled "Theme editor" (not "Templates"): the whole
// surface is theme-scoped, and Settings → General owns the LIVE theme picker.
const site: NavigationMenuItem[] = [
  {
    label: 'Theme editor',
    icon: 'i-lucide-file-code-2',
    to: '/templates',
  },
]

export function registerTemplatesModule(): void {
  registerAdminModule({ id: 'templates', requires: ['lemma.render'], nav: { site } })
}
