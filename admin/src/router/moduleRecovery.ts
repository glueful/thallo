// A route's page component is loaded on demand. When that load fails — a dev server too busy to
// answer, or a deploy that replaced the chunk an open tab still names — Vue Router starts nowhere:
// neither the page nor the sign-in form renders, and nothing retries. Reloading the route fetches
// the module (or the new build's chunk) afresh. Once per route, remembered briefly in session
// storage, so a module that keeps failing shows its error instead of reloading forever.

const KEY = 'thallo:route-module-reload'
const WINDOW_MS = 30_000

const FAILURE_MESSAGES = [
  'Failed to fetch dynamically imported module', // Chromium
  'Importing a module script failed', // WebKit
  'error loading dynamically imported module', // Gecko
]

export function isModuleLoadFailure(error: unknown): boolean {
  return (
    error instanceof Error && FAILURE_MESSAGES.some((message) => error.message.includes(message))
  )
}

interface RecoveryDeps {
  storage?: Storage
  reload?: (href: string) => void
  now?: () => number
}

/** Reload `href` after a failed page-module load, unless it was just tried. True when it reloads. */
export function recoverFromModuleLoadFailure(
  error: unknown,
  href: string,
  deps: RecoveryDeps = {},
): boolean {
  if (!isModuleLoadFailure(error)) return false
  const now = (deps.now ?? Date.now)()
  try {
    const storage = deps.storage ?? window.sessionStorage
    const last = JSON.parse(storage.getItem(KEY) ?? 'null') as { href?: string; at?: number } | null
    if (last?.href === href && typeof last.at === 'number' && now - last.at < WINDOW_MS)
      return false
    storage.setItem(KEY, JSON.stringify({ href, at: now }))
  } catch {
    return false // cannot remember reloading, so cannot promise not to loop
  }
  ;(deps.reload ?? ((target: string) => window.location.assign(target)))(href)
  return true
}
