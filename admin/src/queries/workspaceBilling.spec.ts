import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

function lastCall(fetchMock: ReturnType<typeof vi.fn>): { url: string; init: RequestInit } {
  const [url, init] = fetchMock.mock.calls[0]! as [string, RequestInit | undefined]
  return { url, init: init ?? {} }
}

function bodyOf(init: RequestInit): unknown {
  return init.body ? JSON.parse(init.body as string) : undefined
}

// Task 19 (Phase C, workspace self-serve checkout plan, spec §5.3): the workspace billing query
// layer, wrapping `SelfBillingController` (Tasks 15-17). Mirrors `subscriptionsBilling.spec.ts`'s
// per-test fresh-module convention (module state reset by `setup.ts`'s `beforeEach`).
describe('workspace billing query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  describe('fetchWorkspaceBillingMeta', () => {
    it('parses a full ready-engine meta payload', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          data: {
            engine: 'ready',
            self_serve_checkout_enabled: true,
            workspace_uuid: 't1',
            subscription: {
              status: 'active',
              plan_key: 'pro',
              current_period_end: '2099-01-01 00:00:00',
              provider_managed: true,
            },
            origination: null,
            operator_contact_required: false,
            operator_contact_reason: null,
            purchasable_plans: [{ plan_key: 'pro', name: 'Pro' }],
          },
        }),
      )
      const { fetchWorkspaceBillingMeta } = await import('@/queries/workspaceBilling')
      const meta = await fetchWorkspaceBillingMeta()
      expect(meta).toEqual({
        engine: 'ready',
        self_serve_checkout_enabled: true,
        workspace_uuid: 't1',
        subscription: {
          status: 'active',
          plan_key: 'pro',
          current_period_end: '2099-01-01 00:00:00',
          provider_managed: true,
        },
        origination: null,
        operator_contact_required: false,
        operator_contact_reason: null,
        purchasable_plans: [{ plan_key: 'pro', name: 'Pro' }],
      })
    })

    it('parses engine_disabled with everything else absent, normalizing to closed defaults', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({ data: { engine: 'engine_disabled', workspace_uuid: 't1' } }),
      )
      const { fetchWorkspaceBillingMeta } = await import('@/queries/workspaceBilling')
      const meta = await fetchWorkspaceBillingMeta()
      expect(meta.engine).toBe('engine_disabled')
      expect(meta.self_serve_checkout_enabled).toBe(false)
      expect(meta.subscription).toBeNull()
      expect(meta.origination).toBeNull()
      expect(meta.operator_contact_required).toBe(false)
      expect(meta.operator_contact_reason).toBeNull()
      expect(meta.purchasable_plans).toEqual([])
    })

    it('parses a live initializing origination (no checkout_url yet)', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          data: {
            engine: 'ready',
            workspace_uuid: 't1',
            origination: { status: 'initializing', checkout_url: null },
          },
        }),
      )
      const { fetchWorkspaceBillingMeta } = await import('@/queries/workspaceBilling')
      const meta = await fetchWorkspaceBillingMeta()
      expect(meta.origination).toEqual({ status: 'initializing', checkout_url: null })
    })

    it('parses a blocked guard as operator_contact_required with a reason, no origination', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          data: {
            engine: 'ready',
            workspace_uuid: 't1',
            operator_contact_required: true,
            operator_contact_reason: 'rejected',
          },
        }),
      )
      const { fetchWorkspaceBillingMeta } = await import('@/queries/workspaceBilling')
      const meta = await fetchWorkspaceBillingMeta()
      expect(meta.operator_contact_required).toBe(true)
      expect(meta.operator_contact_reason).toBe('rejected')
      expect(meta.origination).toBeNull()
    })

    it('GETs the exact /billing/meta endpoint', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: { engine: 'ready', workspace_uuid: 't1' } }))
      const { fetchWorkspaceBillingMeta } = await import('@/queries/workspaceBilling')
      await fetchWorkspaceBillingMeta()
      const { url } = lastCall(fetchMock)
      expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/billing/meta')
    })
  })

  describe('startWorkspaceCheckout', () => {
    it('POSTs plan_key with the Idempotency-Key header and parses a pending result', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(
        jsonResponse({ data: { status: 'pending', checkout_url: 'https://pay.example/session' } }),
      )
      const { startWorkspaceCheckout } = await import('@/queries/workspaceBilling')
      const result = await startWorkspaceCheckout('pro', 'a'.repeat(32))
      expect(result).toEqual({ status: 'pending', checkout_url: 'https://pay.example/session' })

      const { url, init } = lastCall(fetchMock)
      expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/billing/checkout')
      expect(init.method).toBe('POST')
      expect((init.headers as Record<string, string>)['Idempotency-Key']).toBe('a'.repeat(32))
      expect(bodyOf(init)).toEqual({ plan_key: 'pro' })
    })

    it('resolves normally (never throws) on a 202 initializing response', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(
        jsonResponse({ data: { status: 'initializing', checkout_url: null } }, 202),
      )
      const { startWorkspaceCheckout } = await import('@/queries/workspaceBilling')
      const result = await startWorkspaceCheckout('pro', 'a'.repeat(32))
      expect(result).toEqual({ status: 'initializing', checkout_url: null })
    })

    it('a settled provider_observed replay carries a null checkout_url', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: { status: 'provider_observed', checkout_url: null } }))
      const { startWorkspaceCheckout } = await import('@/queries/workspaceBilling')
      const result = await startWorkspaceCheckout('pro', 'a'.repeat(32))
      expect(result).toEqual({ status: 'provider_observed', checkout_url: null })
    })
  })

  describe('cancelWorkspaceSubscription', () => {
    it('POSTs the exact {mode} body', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: { mode: 'stop_renewal' } }))
      const { cancelWorkspaceSubscription } = await import('@/queries/workspaceBilling')
      const result = await cancelWorkspaceSubscription('stop_renewal')
      expect(result).toEqual({ mode: 'stop_renewal' })

      const { url, init } = lastCall(fetchMock)
      expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/billing/cancel')
      expect(bodyOf(init)).toEqual({ mode: 'stop_renewal' })
    })
  })

  describe('abandonWorkspaceCheckout', () => {
    it('POSTs with no body and parses the returned status', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: { status: 'abandoned' } }))
      const { abandonWorkspaceCheckout } = await import('@/queries/workspaceBilling')
      const result = await abandonWorkspaceCheckout()
      expect(result).toEqual({ status: 'abandoned' })

      const { url, init } = lastCall(fetchMock)
      expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/billing/checkout/abandon')
      expect(init.method).toBe('POST')
    })
  })

  describe('generateIdempotencyToken', () => {
    it('generates a 32-character token from the pinned charset (matches the server pattern)', async () => {
      const { generateIdempotencyToken } = await import('@/queries/workspaceBilling')
      const token = generateIdempotencyToken()
      expect(token).toHaveLength(32)
      expect(token).toMatch(/^[A-Za-z0-9._~-]{32}$/)
    })

    it('generates distinct tokens across calls', async () => {
      const { generateIdempotencyToken } = await import('@/queries/workspaceBilling')
      const a = generateIdempotencyToken()
      const b = generateIdempotencyToken()
      expect(a).not.toBe(b)
    })
  })

  // ── CheckoutAttemptTracker: one token per deliberate click, retained across retries, rotated
  // ── only after a terminal outcome or a /meta-observed different live attempt.
  describe('CheckoutAttemptTracker', () => {
    it('mints a token on first ensureToken() and retains the SAME one across retries', async () => {
      const { CheckoutAttemptTracker } = await import('@/queries/workspaceBilling')
      const tracker = new CheckoutAttemptTracker()
      expect(tracker.token).toBeNull()
      const first = tracker.ensureToken()
      const second = tracker.ensureToken()
      expect(second).toBe(first)
      expect(tracker.token).toBe(first)
    })

    it('rotates after markTerminal()', async () => {
      const { CheckoutAttemptTracker } = await import('@/queries/workspaceBilling')
      const tracker = new CheckoutAttemptTracker()
      const first = tracker.ensureToken()
      tracker.markTerminal()
      expect(tracker.token).toBeNull()
      const second = tracker.ensureToken()
      expect(second).not.toBe(first)
    })

    it('never rotates while /meta keeps reporting the SAME live attempt (same checkout_url)', async () => {
      const { CheckoutAttemptTracker } = await import('@/queries/workspaceBilling')
      const tracker = new CheckoutAttemptTracker()
      const token = tracker.ensureToken()
      tracker.observeMeta({ status: 'pending', checkout_url: 'https://pay.example/a' })
      tracker.observeMeta({ status: 'pending', checkout_url: 'https://pay.example/a' })
      tracker.observeMeta({ status: 'pending', checkout_url: 'https://pay.example/a' })
      expect(tracker.token).toBe(token)
    })

    it('an initializing observation (no checkout_url yet) alone does not rotate', async () => {
      const { CheckoutAttemptTracker } = await import('@/queries/workspaceBilling')
      const tracker = new CheckoutAttemptTracker()
      const token = tracker.ensureToken()
      tracker.observeMeta({ status: 'initializing', checkout_url: null })
      tracker.observeMeta({ status: 'initializing', checkout_url: null })
      expect(tracker.token).toBe(token)
    })

    it('never rotates from repeated null observations when no live origination was ever seen for this token', async () => {
      const { CheckoutAttemptTracker } = await import('@/queries/workspaceBilling')
      const tracker = new CheckoutAttemptTracker()
      const token = tracker.ensureToken()
      tracker.observeMeta(null)
      tracker.observeMeta(null)
      expect(tracker.token).toBe(token)
    })

    // Code review fix: a live origination this token was tracking disappearing entirely (guard
    // released out-of-band -- operator resolution, expiry, a race with another actor) must rotate
    // even when it never got as far as having a `checkout_url` (still `initializing`) -- silently
    // keeping the token here re-submits it for whatever plan the next click picks, which 409s
    // `idempotency_conflict` forever once the plan differs even once.
    it('rotates when a previously-live (still-initializing) origination disappears entirely', async () => {
      const { CheckoutAttemptTracker } = await import('@/queries/workspaceBilling')
      const tracker = new CheckoutAttemptTracker()
      tracker.ensureToken()
      tracker.observeMeta({ status: 'initializing', checkout_url: null })
      tracker.observeMeta(null)
      expect(tracker.token).toBeNull()
    })

    it('rotates when a previously-live PENDING origination (with a checkout_url) disappears entirely', async () => {
      const { CheckoutAttemptTracker } = await import('@/queries/workspaceBilling')
      const tracker = new CheckoutAttemptTracker()
      tracker.ensureToken()
      tracker.observeMeta({ status: 'pending', checkout_url: 'https://pay.example/a' })
      tracker.observeMeta(null)
      expect(tracker.token).toBeNull()
    })

    it('rotates when /meta reports a DIFFERENT live attempt (checkout_url changes)', async () => {
      const { CheckoutAttemptTracker } = await import('@/queries/workspaceBilling')
      const tracker = new CheckoutAttemptTracker()
      tracker.ensureToken()
      tracker.observeMeta({ status: 'pending', checkout_url: 'https://pay.example/a' })
      tracker.observeMeta({ status: 'pending', checkout_url: 'https://pay.example/b' })
      expect(tracker.token).toBeNull()
    })

    it('a fresh ensureToken() after a rotation mints a new token', async () => {
      const { CheckoutAttemptTracker } = await import('@/queries/workspaceBilling')
      const tracker = new CheckoutAttemptTracker()
      const first = tracker.ensureToken()
      tracker.observeMeta({ status: 'pending', checkout_url: 'https://pay.example/a' })
      tracker.observeMeta({ status: 'pending', checkout_url: 'https://pay.example/b' })
      const second = tracker.ensureToken()
      expect(second).not.toBe(first)
    })
  })

  describe('isTerminalCheckoutStatus / isTerminalCheckoutErrorCode', () => {
    it('treats provider_observed and dispatched as terminal; pending/initializing are not', async () => {
      const { isTerminalCheckoutStatus } = await import('@/queries/workspaceBilling')
      expect(isTerminalCheckoutStatus('provider_observed')).toBe(true)
      expect(isTerminalCheckoutStatus('dispatched')).toBe(true)
      expect(isTerminalCheckoutStatus('pending')).toBe(false)
      expect(isTerminalCheckoutStatus('initializing')).toBe(false)
    })

    it('treats checkout_failed/expired/abandoned/idempotency_conflict as terminal error codes; others are not', async () => {
      const { isTerminalCheckoutErrorCode } = await import('@/queries/workspaceBilling')
      expect(isTerminalCheckoutErrorCode('checkout_failed')).toBe(true)
      expect(isTerminalCheckoutErrorCode('checkout_expired')).toBe(true)
      expect(isTerminalCheckoutErrorCode('checkout_abandoned')).toBe(true)
      // Code review fix: an orphaned token (its live origination released out-of-band) that gets
      // resubmitted for a different plan always 409s this code -- it must rotate the token too,
      // or every further click reuses the same doomed token forever.
      expect(isTerminalCheckoutErrorCode('idempotency_conflict')).toBe(true)
      expect(isTerminalCheckoutErrorCode('checkout_pending')).toBe(false)
      expect(isTerminalCheckoutErrorCode(null)).toBe(false)
    })
  })

  describe('navigateToCheckout', () => {
    it('assigns window.location to the given url', async () => {
      const { navigateToCheckout } = await import('@/queries/workspaceBilling')
      // jsdom's window.location.assign is non-configurable -- spyOn can't redefine it directly,
      // so swap the whole `location` object for the duration of this one test.
      const original = window.location
      const assignMock = vi.fn()
      Object.defineProperty(window, 'location', {
        configurable: true,
        value: { ...original, assign: assignMock },
      })
      navigateToCheckout('https://pay.example/session')
      expect(assignMock).toHaveBeenCalledWith('https://pay.example/session')
      Object.defineProperty(window, 'location', { configurable: true, value: original })
    })
  })
})
