import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// The first-party (core) admin nav, registered as an always-on module (no `requires`).
// Pack modules register their own nav with a `requires` capability id elsewhere.
const main: NavigationMenuItem[] = [
  {
    label: 'Home',
    icon: 'i-lucide-house',
    to: '/',
  },
  {
    // `children` ships empty here because this is a plain module (no setup context to call a
    // composable). `layouts/default.vue` fetches the live content types via `useContentTypes()`
    // and reactively replaces this node's children at render time — each type links to
    // `/content/{slug}` (a fresh install ships the seeded "Pages" type as the day-one entry).
    label: 'Content',
    icon: 'i-lucide-layers',
    defaultOpen: true,
    children: [],
  },
  {
    label: 'Media',
    icon: 'i-lucide-image',
    to: '/media',
  },
  {
    label: 'Extensions',
    icon: 'i-lucide-blocks',
    to: '/extensions',
  },
  {
    label: 'Users & Access',
    icon: 'i-lucide-users',
    children: [
      {
        label: 'Users',
        icon: 'i-lucide-user',
        to: '/users',
      },
      {
        label: 'Roles & Permissions',
        icon: 'i-lucide-shield-check',
        to: '/roles-permissions',
      },
      {
        label: 'Audit Log',
        icon: 'i-lucide-scroll-text',
        to: '/audit-log',
      },
    ],
  },
  {
    label: 'Developers',
    icon: 'i-lucide-code-xml',
    children: [
      {
        label: 'API Keys',
        icon: 'i-lucide-key-round',
        to: '/developers/api-keys',
      },
      {
        label: 'Webhooks',
        icon: 'i-lucide-webhook',
        to: '/developers/webhooks',
      },
      {
        label: 'API Reference',
        icon: 'i-lucide-book-open',
        to: 'https://thallodev.dev/docs/',
        target: '_blank',
      },
      {
        label: 'Documentation',
        icon: 'i-lucide-library',
        to: 'https://thallo.dev/',
        target: '_blank',
      },
    ],
  },
  {
    label: 'Settings',
    icon: 'i-lucide-settings',
    children: [
      {
        label: 'Content Types',
        icon: 'i-lucide-shapes',
        to: '/settings/content-types',
      },
      {
        label: 'Block Types',
        icon: 'i-lucide-blocks',
        to: '/settings/block-types',
      },
      {
        label: 'General',
        icon: 'i-lucide-sliders-horizontal',
        to: '/settings/general',
      },
      {
        label: 'Languages',
        icon: 'i-lucide-languages',
        to: '/settings/languages',
      },
      {
        label: 'Redirects',
        icon: 'i-lucide-signpost',
        to: '/settings/redirects',
      },
      {
        label: 'Email',
        icon: 'i-lucide-mail',
        to: '/settings/email',
      },
      {
        label: 'Signup',
        icon: 'i-lucide-user-plus',
        to: '/settings/signup',
      },
      {
        label: 'Import / Export',
        icon: 'i-lucide-arrow-down-up',
        to: '/settings/import-export',
      },
      {
        label: 'Workspaces',
        icon: 'i-lucide-briefcase-business',
        to: '/settings/workspaces',
      },
    ],
  },
  {
    label: 'Utilities',
    icon: 'i-lucide-wrench',
    children: [
      {
        label: 'Scheduled Tasks',
        icon: 'i-lucide-calendar-clock',
        to: '/utilities/scheduled-tasks',
      },
      {
        label: 'Health',
        icon: 'i-lucide-heart-pulse',
        to: '/utilities/health',
      },
      {
        label: 'Cache',
        icon: 'i-lucide-database-zap',
        to: '/utilities/cache',
      },
    ],
  },
]

export function registerCoreModule(): void {
  registerAdminModule({ id: 'core', nav: { main } })
}
