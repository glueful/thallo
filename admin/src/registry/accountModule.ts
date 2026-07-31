import type { AdminModule } from './adminModules'

/**
 * Account admin module (public-account-surface plan Task 4). Contributes ONE child to the shared
 * core Settings group — never a top-level nav entry — gated on `thallo.accounts`, so the entry
 * appears and disappears with the capability without moving or duplicating the Settings parent. The
 * page route additionally declares `requiresCapability: 'thallo.accounts'`, so direct SPA navigation
 * is guarded by VERIFIED capability state, not merely sidebar visibility.
 */
export const accountModule: AdminModule = {
  id: 'account',
  requires: ['thallo.accounts'],
  nav: {
    settings: [{ label: 'Accounts', icon: 'i-lucide-user-round', to: '/settings/accounts' }],
  },
}
