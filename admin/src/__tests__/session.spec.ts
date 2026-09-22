import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

describe('session store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.stubGlobal('fetch', vi.fn())
  })

  it('starts unauthenticated; setSession authenticates', async () => {
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    expect(s.isAuthenticated).toBe(false)
    s.setSession('tok', 'rtok', { uuid: 'u1', email: 'a@b.c' })
    expect(s.isAuthenticated).toBe(true)
    expect(s.accessToken).toBe('tok')
    expect(s.refreshToken).toBe('rtok')
  })

  it('login stores the access + refresh tokens from the response envelope', async () => {
    ;(globalThis.fetch as any).mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            access_token: 'jwt',
            refresh_token: 'rjwt',
            user: { uuid: 'u1', email: 'a@b.c' },
          },
        }),
        { status: 200 },
      ),
    )
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    await s.login('a@b.c', 'pw')
    expect(s.isAuthenticated).toBe(true)
    expect(s.accessToken).toBe('jwt')
    expect(s.refreshToken).toBe('rjwt')
  })

  it('login hands back a two-factor challenge instead of failing, and stays signed out', async () => {
    // Login answers { two_factor_required, challenge_token, expires_in, delivered_to } when the
    // account has 2FA on. The store read it as a malformed session and threw, locking the admin out.
    ;(globalThis.fetch as any).mockResolvedValue(
      new Response(
        JSON.stringify({
          success: true,
          message: 'Two-factor verification required',
          data: {
            two_factor_required: true,
            challenge_token: 'chal-1',
            expires_in: 300,
            delivered_to: 'a***@b.c',
          },
        }),
        { status: 200 },
      ),
    )
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()

    const challenge = await s.login('a@b.c', 'pw')

    expect(challenge).toEqual({ token: 'chal-1', expiresIn: 300, deliveredTo: 'a***@b.c' })
    expect(s.isAuthenticated).toBe(false)
  })

  it('completing the challenge posts the code and stores the session', async () => {
    const fetchMock = globalThis.fetch as any
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            access_token: 'jwt',
            refresh_token: 'rjwt',
            user: { uuid: 'u1', email: 'a@b.c' },
          },
        }),
        { status: 200 },
      ),
    )
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()

    await s.completeTwoFactor('chal-1', '123456')

    const req = fetchMock.mock.calls.at(-1)[0] as Request
    expect(new URL(req.url).pathname).toBe('/v1/2fa/verify')
    expect(await req.clone().json()).toEqual({ challenge_token: 'chal-1', code: '123456' })
    expect(s.isAuthenticated).toBe(true)
    expect(s.accessToken).toBe('jwt')
  })

  it('refresh posts the stored refresh token and rotates it', async () => {
    const fetchMock = globalThis.fetch as any
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ access_token: 'jwt2', refresh_token: 'rjwt2' }), {
        status: 200,
      }),
    )
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    s.setSession('jwt', 'rjwt', { uuid: 'u1', email: 'a@b.c' })

    const ok = await s.refresh()

    expect(ok).toBe(true)
    expect(s.accessToken).toBe('jwt2')
    expect(s.refreshToken).toBe('rjwt2')
    // The refresh token was sent in the body, not via a cookie. The core (openapi-fetch) client
    // calls fetch(Request), so read the body off the Request object.
    const req = fetchMock.mock.calls.at(-1)[0] as Request
    expect(await req.clone().json()).toEqual({ refresh_token: 'rjwt' })
  })

  it('refresh returns false when there is no stored refresh token', async () => {
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    expect(await s.refresh()).toBe(false)
  })

  it('clear() wipes the session', async () => {
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    s.setSession('tok', 'rtok', { uuid: 'u1', email: 'a@b.c' })
    s.clear()
    expect(s.isAuthenticated).toBe(false)
    expect(s.accessToken).toBeNull()
    expect(s.refreshToken).toBeNull()
  })

  // The persist plugin re-serializes the nulled state on a 100ms DEBOUNCE — a tab closed
  // inside that window would otherwise leave the pre-logout blob (access + refresh token)
  // restorable. clear() must purge the persisted snapshot synchronously, not wait for the
  // plugin, and must sweep again after the debounce so the trailing nulls-write leaves no
  // residual blob behind.
  it('clear() purges the persisted token snapshot synchronously and after the debounce', async () => {
    vi.useFakeTimers()
    try {
      localStorage.setItem('thallo_session', 'encrypted-blob-with-tokens')
      const { useSessionStore } = await import('@/stores/session')
      const s = useSessionStore()
      s.setSession('tok', 'rtok', { uuid: 'u1', email: 'a@b.c' })

      s.clear()

      // Synchronous removal: tokens are unrecoverable even if the tab dies right now.
      expect(localStorage.getItem('thallo_session')).toBeNull()

      // Simulate the plugin's trailing debounced write recreating the key with nulls…
      localStorage.setItem('thallo_session', 'encrypted-nulls-blob')
      await vi.advanceTimersByTimeAsync(250)
      // …the post-debounce sweep removes the residue too.
      expect(localStorage.getItem('thallo_session')).toBeNull()
    } finally {
      vi.useRealTimers()
      localStorage.clear()
    }
  })

  it('logout clears the persisted snapshot even when the API call fails', async () => {
    ;(globalThis.fetch as any).mockRejectedValue(new Error('network down'))
    localStorage.setItem('thallo_session', 'encrypted-blob-with-tokens')
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    s.setSession('tok', 'rtok', { uuid: 'u1', email: 'a@b.c' })

    await s.logout().catch(() => undefined)

    expect(s.isAuthenticated).toBe(false)
    expect(localStorage.getItem('thallo_session')).toBeNull()
    localStorage.clear()
  })
})
