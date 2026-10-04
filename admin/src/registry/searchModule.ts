import type { AdminModule } from './adminModules'

/**
 * Settings › Search (search block spec §3.8): ONE child of the shared core Settings group, gated on
 * `thallo.search`, so it leaves the sidebar while Search is off. The page route also declares
 * `requiresCapability: 'thallo.search'`, so direct navigation is guarded by verified state.
 */
export const searchModule: AdminModule = {
  id: 'search',
  requires: ['thallo.search'],
  nav: {
    settings: [{ label: 'Search', icon: 'i-lucide-search', to: '/settings/search' }],
  },
}
