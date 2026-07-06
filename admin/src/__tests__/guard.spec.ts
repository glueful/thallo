import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mutable doubles the guard reads through the mocked modules. Declared via vi.hoisted so they
// exist before the hoisted vi.mock factories run (which reference them).
const { cfg, session, caps } = vi.hoisted(() => ({
  cfg: { installed: false },
  session: { isAuthenticated: false },
  caps: {
    ensureLoaded: vi.fn().mockResolvedValue(undefined),
    isEnabled: (_: string): boolean => false,
  },
}))

vi.mock('@/runtime/config', () => ({ runtimeConfig: cfg }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => session }))
vi.mock('@/stores/capabilities', () => ({ useCapabilitiesStore: () => caps }))

import { installAndAuthGuard } from '@/router/guard'

function to(path: string, meta: Record<string, unknown> = {}) {
  return { path, fullPath: path, meta } as any
}

describe('install + auth guard', () => {
  beforeEach(() => {
    cfg.installed = false
    session.isAuthenticated = false
    caps.isEnabled = (_: string): boolean => false
  })

  it('redirects everything to /setup when not installed', () => {
    expect(installAndAuthGuard(to('/'))).toEqual({ path: '/setup' })
  })

  it('allows /setup when not installed', () => {
    expect(installAndAuthGuard(to('/setup'))).toBe(true)
  })

  it('redirects /setup to /login once installed', () => {
    cfg.installed = true
    expect(installAndAuthGuard(to('/setup'))).toEqual({ path: '/login' })
  })

  it('redirects a protected route to /login when unauthenticated', () => {
    cfg.installed = true
    expect(installAndAuthGuard(to('/content/page', { requiresAuth: true }))).toEqual({
      path: '/login',
      query: { redirect: '/content/page' },
    })
  })

  it('allows a protected route when authenticated', () => {
    cfg.installed = true
    session.isAuthenticated = true
    expect(installAndAuthGuard(to('/content/page', { requiresAuth: true }))).toBe(true)
  })

  it('bounces /login to / when already authenticated', () => {
    cfg.installed = true
    session.isAuthenticated = true
    expect(installAndAuthGuard(to('/login'))).toEqual({ path: '/' })
  })

  it('redirects a capability-gated route to / when the capability is disabled', async () => {
    cfg.installed = true
    session.isAuthenticated = true
    caps.isEnabled = () => false
    await expect(
      installAndAuthGuard(to('/forms', { requiresAuth: true, requiresCapability: 'thallo.forms' })),
    ).resolves.toEqual({ path: '/' })
  })

  it('allows a capability-gated route when the capability is enabled', async () => {
    cfg.installed = true
    session.isAuthenticated = true
    caps.isEnabled = (id: string) => id === 'thallo.forms'
    await expect(
      installAndAuthGuard(to('/forms', { requiresAuth: true, requiresCapability: 'thallo.forms' })),
    ).resolves.toBe(true)
  })

  it('allows a route with no requiresCapability (synchronous, unchanged)', () => {
    cfg.installed = true
    session.isAuthenticated = true
    expect(installAndAuthGuard(to('/', { requiresAuth: true }))).toBe(true)
  })
})
