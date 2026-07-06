import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { toApiError } from '@/api/errors'
import { core } from '@/api/client'
import type { PersistOptions } from '@/plugins/pinia-persist-plugin'

export interface SessionUser {
  uuid: string
  email: string
}

// Glueful auth is COOKIELESS: login returns the access + refresh tokens in the JSON body, and the
// refresh endpoint expects the refresh token in the request BODY (there is no httpOnly cookie). So
// we hold BOTH tokens client-side and send the access token as a Bearer header and the refresh
// token in the refresh body. These auth routes live OUTSIDE the typed /v1/admin surface, so they go
// through the `core` openapi-fetch client (baseUrl '', full spec paths) — typed, no hand-written
// path strings to drift.

// Encrypted-localStorage persistence (maintainer's accepted tradeoff). The access + refresh tokens
// and user are persisted; everything else is derived. Because the backend is cookieless, the
// refresh token lives here too — client-side encryption-at-rest is obfuscation, not secrecy, so
// short token lifetimes + rotation are the real defense. The router guard always re-checks
// isAuthenticated, so restore is best-effort.
//
// SECURITY DECISION (accepted risk, v1). localStorage tokens mean an XSS can steal credentials,
// including the durable refresh token.
//   Reason:     Thallo admin uses Glueful's cookieless token flow (tokens returned in the JSON body,
//               refresh sent in the request body). A real fix needs framework-level httpOnly
//               refresh-cookie support — it cannot be done as a SPA-only patch, and half-measures
//               (encrypting localStorage "harder", or moving only the access token to memory while
//               the refresh token stays persisted) do not change the threat model, so we don't.
//   Mitigation: prioritize XSS prevention, keep access-token lifetime short, rotate refresh tokens,
//               and never log tokens.
//   Future:     design a framework auth mode for an httpOnly refresh cookie + in-memory access token.
//
// Declared as a named const (not a fresh object literal at the defineStore call site) so the
// `persist` key — contributed by the pinia-persist-plugin module augmentation — passes the
// options type structurally without tripping an excess-property check.
const sessionStoreOptions: { persist: PersistOptions } = {
  persist: {
    enabled: true,
    strategies: [
      {
        key: 'thallo_session',
        storage: localStorage,
        encrypt: { secret: import.meta.env.VITE_ADMIN_PERSIST_SECRET ?? 'thallo-admin-dev' },
        mergeStrategy: 'shallow',
        debounce: 100,
      },
    ],
  },
}

// Pull the token fields out of an auth response, tolerating either the framework's success envelope
// ({ data: { access_token, ... } }, used by login) or a flat DTO body (used by refresh-token).
function readAuthBody(json: unknown): {
  access: string | null
  refresh: string | null
  user: SessionUser | null
} {
  const root = (json ?? {}) as { data?: unknown }
  const body = (root.data ?? json ?? {}) as {
    access_token?: unknown
    refresh_token?: unknown
    user?: unknown
  }
  return {
    access: typeof body.access_token === 'string' ? body.access_token : null,
    refresh: typeof body.refresh_token === 'string' ? body.refresh_token : null,
    user: (body.user ?? null) as SessionUser | null,
  }
}

export const useSessionStore = defineStore(
  'session',
  () => {
    const accessToken = ref<string | null>(null)
    const refreshToken = ref<string | null>(null)
    const user = ref<SessionUser | null>(null)
    const isAuthenticated = computed(() => accessToken.value !== null)

    function setSession(access: string, refresh: string | null, u: SessionUser) {
      accessToken.value = access
      refreshToken.value = refresh
      user.value = u
    }
    function clear() {
      accessToken.value = null
      refreshToken.value = null
      user.value = null
      // Drop the previous user's capability set so the next account reloads its own (lazy import
      // avoids a store<->store cycle). NOT called on token refresh — only on identity changes.
      void import('@/stores/capabilities').then((m) => m.useCapabilitiesStore().reset())
    }

    async function login(email: string, password: string): Promise<void> {
      // The endpoint accepts username OR email in the `username` field.
      const { data, error, response } = await core.POST('/v1/auth/login', {
        body: { username: email, password },
      })
      // Surface the backend's own message (toApiError falls back to a cause-neutral generic only
      // when the response carries none); login.vue adds the "Sign in failed" title.
      if (error) throw toApiError(error, response)
      const { access, refresh, user: u } = readAuthBody(data)
      if (access === null || u === null) throw new Error('Malformed login response.')
      setSession(access, refresh, u)
      // New identity → force the capability set to reload for this user on the next guard check.
      const { useCapabilitiesStore } = await import('@/stores/capabilities')
      useCapabilitiesStore().reset()
    }

    // Mint a fresh access token by POSTing the stored refresh token in the body (no cookie). Refresh
    // tokens are one-time/rotating, so the response carries a NEW refresh token we must store for
    // next time. Returns true on success, false otherwise.
    async function refresh(): Promise<boolean> {
      const token = refreshToken.value
      if (token === null || token === '') return false
      try {
        const { data, error } = await core.POST('/v1/auth/refresh-token', {
          body: { refresh_token: token },
        })
        if (error || data === undefined) return false
        const { access, refresh: nextRefresh, user: u } = readAuthBody(data)
        if (access === null) return false
        accessToken.value = access
        if (nextRefresh !== null) refreshToken.value = nextRefresh
        if (u !== null) user.value = u
        return true
      } catch {
        return false
      }
    }

    async function logout(): Promise<void> {
      try {
        // The core client attaches the Bearer; the server identifies the session to terminate from it.
        await core.POST('/v1/auth/logout', {})
      } finally {
        clear()
      }
    }

    return {
      accessToken,
      refreshToken,
      user,
      isAuthenticated,
      setSession,
      clear,
      login,
      refresh,
      logout,
    }
  },
  sessionStoreOptions,
)
