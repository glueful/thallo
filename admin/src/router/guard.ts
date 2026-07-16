import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { runtimeConfig } from '@/runtime/config'
import { useSessionStore } from '@/stores/session'
import { useCapabilitiesStore } from '@/stores/capabilities'

declare module 'vue-router' {
  interface RouteMeta {
    // Protected pages opt in via definePage({ meta: { requiresAuth: true } }).
    requiresAuth?: boolean
    /**
     * Capability id (e.g. 'thallo.forms') that must be enabled for this route to be reachable.
     *
     * **Important:** a `requiresCapability` route should also set `requiresAuth: true`.
     * The capability gate only runs when the user is authenticated
     * (`cap !== undefined && session.isAuthenticated`), so a cap-gated route
     * without `requiresAuth` would be reachable while logged out — the guard
     * would skip the capability check entirely and return `true`.
     */
    requiresCapability?: string
  }
}

/**
 * Global navigation guard, extracted as a pure function so it can be unit-tested without a router.
 *
 * Order matters: the install gate runs first (a fresh instance has no admin to authenticate
 * against, so everything funnels to /setup), then the auth gate, then the capability gate.
 *
 * Returns `true` to allow navigation, a redirect target, or a Promise of either.
 * The install + auth branches are synchronous; only the capability branch returns a Promise.
 */
export function installAndAuthGuard(
  to: RouteLocationNormalized,
): true | RouteLocationRaw | Promise<true | RouteLocationRaw> {
  // (1) Install gate. Until setup has run, force everything to /setup; once installed, /setup is inert.
  if (!runtimeConfig.installed && to.path !== '/setup') return { path: '/setup' }
  if (runtimeConfig.installed && to.path === '/setup') return { path: '/login' }

  // (2) Auth gate. Protected pages opt in via meta.requiresAuth; /login bounces home when signed in.
  const session = useSessionStore()
  if (to.meta.requiresAuth && !session.isAuthenticated) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }
  if (to.path === '/login' && session.isAuthenticated) return { path: '/' }

  // (3) Capability gate: a disabled pack's route is unreachable by direct URL.
  //     Only THIS branch is async — it returns a Promise; the branches above stay synchronous.
  //
  //     "Unknown" is never treated as "disabled": the redirect fires only when discovery
  //     genuinely SUCCEEDED (`ready`) and the capability is absent. On `error` the route is
  //     allowed to resolve — the layout's capability boundary renders a Retry panel instead
  //     of the page, and the real feature endpoints stay authorized server-side — so a
  //     transient discovery failure can't masquerade as "this pack doesn't exist".
  const cap = to.meta.requiresCapability
  if (cap !== undefined && session.isAuthenticated) {
    const caps = useCapabilitiesStore()
    return caps.ensureLoaded().then((): true | RouteLocationRaw => {
      if (caps.status === 'error') return true
      return caps.isEnabled(cap) ? true : { path: '/' }
    })
  }

  return true
}
