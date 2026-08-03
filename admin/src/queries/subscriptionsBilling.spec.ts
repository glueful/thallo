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

// `authFetch` (unlike the typed `client`, which builds a `Request`) calls global `fetch(path,
// init)` with a plain string URL and a separate `RequestInit` -- so the mock's recorded call args
// are `[url: string, init: RequestInit]`, never a `Request` instance.
function lastCall(fetchMock: ReturnType<typeof vi.fn>): { url: string; init: RequestInit } {
  const [url, init] = fetchMock.mock.calls[0]! as [string, RequestInit | undefined]
  return { url, init: init ?? {} }
}

function bodyOf(init: RequestInit): unknown {
  return init.body ? JSON.parse(init.body as string) : undefined
}

// Query layer for the thallo-subscriptions admin SPA module (Task 11), wrapping the Task 8 Plans
// admin API and the Task 9 workspace billing admin API + meta. Mirrors `queries/extensions.spec.ts`
// / `queries/tenants.ts`'s un-typed-`authFetch` convention (this pack has no OpenAPI codegen yet),
// stubbing global `fetch` before each dynamic import (module state reset by `setup.ts`'s
// `beforeEach`, mirroring `commerceOrders.spec.ts`'s identical per-test-fresh-client rationale).
describe('subscriptions billing query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  // ── Meta ─────────────────────────────────────────────────────────────────────

  describe('fetchSubscriptionsMeta', () => {
    it('parses engine: ready, tenancy_enabled: true, default_tenant_uuid: null (tenancy has no single default)', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          success: true,
          message: 'Subscriptions status retrieved',
          data: { engine: 'ready', tenancy_enabled: true, default_tenant_uuid: null },
        }),
      )
      const { fetchSubscriptionsMeta } = await import('@/queries/subscriptionsBilling')
      const meta = await fetchSubscriptionsMeta()
      expect(meta).toEqual({ engine: 'ready', tenancy_enabled: true, default_tenant_uuid: null })
    })

    it('parses engine_disabled with tenancy off and a null default (fresh install, no default established)', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          data: { engine: 'engine_disabled', tenancy_enabled: false, default_tenant_uuid: null },
        }),
      )
      const { fetchSubscriptionsMeta } = await import('@/queries/subscriptionsBilling')
      const meta = await fetchSubscriptionsMeta()
      expect(meta.engine).toBe('engine_disabled')
      expect(meta.default_tenant_uuid).toBeNull()
    })

    it('parses schema_not_ready with tenancy off and a real default uuid', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          data: { engine: 'schema_not_ready', tenancy_enabled: false, default_tenant_uuid: 't_default' },
        }),
      )
      const { fetchSubscriptionsMeta } = await import('@/queries/subscriptionsBilling')
      const meta = await fetchSubscriptionsMeta()
      expect(meta).toEqual({
        engine: 'schema_not_ready',
        tenancy_enabled: false,
        default_tenant_uuid: 't_default',
      })
    })

    it('GETs the exact /meta endpoint', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(
        jsonResponse({ data: { engine: 'ready', tenancy_enabled: false, default_tenant_uuid: 't1' } }),
      )
      const { fetchSubscriptionsMeta } = await import('@/queries/subscriptionsBilling')
      await fetchSubscriptionsMeta()
      const { url } = lastCall(fetchMock)
      expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/subscriptions/meta')
    })
  })

  // ── Plans ────────────────────────────────────────────────────────────────────

  function planRow(overrides: Record<string, unknown> = {}) {
    return {
      uuid: 'p1',
      plan_key: 'pro',
      display_name: 'Pro',
      description: null,
      entitlements: { seats: 10, api: true, support: null },
      provider_price_id: null,
      status: 'active',
      sort_order: 1,
      audience: 'tenant',
      owner_tenant_uuid: '',
      created_at: '2026-01-01 00:00:00',
      updated_at: '2026-01-01 00:00:00',
      ...overrides,
    }
  }

  it('fetchPlans parses the plans envelope and normalizes entitlements/status/sort_order', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Plans retrieved', data: { plans: [planRow()] } }),
    )
    const { fetchPlans } = await import('@/queries/subscriptionsBilling')
    const plans = await fetchPlans()
    expect(plans).toHaveLength(1)
    expect(plans[0]!.plan_key).toBe('pro')
    expect(plans[0]!.entitlements).toEqual({ seats: 10, api: true, support: null })
    expect(plans[0]!.status).toBe('active')
    expect(plans[0]!.sort_order).toBe(1)
  })

  it('fetchPlans defaults to an empty list when the envelope has no plans', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))
    const { fetchPlans } = await import('@/queries/subscriptionsBilling')
    expect(await fetchPlans()).toEqual([])
  })

  it('createPlan POSTs the exact CreatePlanInput body and normalizes the created plan', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ success: true, message: 'Plan created', data: planRow() }, 201))

    const { createPlan } = await import('@/queries/subscriptionsBilling')
    const plan = await createPlan({
      plan_key: 'pro',
      display_name: 'Pro',
      entitlements: { seats: 10 },
      status: 'active',
    })

    const { url, init } = lastCall(fetchMock)
    expect(init.method).toBe('POST')
    expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/subscriptions/plans')
    expect(bodyOf(init)).toEqual({
      plan_key: 'pro',
      display_name: 'Pro',
      entitlements: { seats: 10 },
      status: 'active',
    })
    expect(plan.plan_key).toBe('pro')
  })

  it('updatePlan PATCHes /plans/{key} and never sends plan_key', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: planRow({ display_name: 'Pro v2' }) }))

    const { updatePlan } = await import('@/queries/subscriptionsBilling')
    const plan = await updatePlan('pro', { display_name: 'Pro v2' })

    const { url, init } = lastCall(fetchMock)
    expect(init.method).toBe('PATCH')
    expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/subscriptions/plans/pro')
    expect(bodyOf(init)).toEqual({ display_name: 'Pro v2' })
    expect(plan.display_name).toBe('Pro v2')
  })

  it('updatePlan surfaces the engine 422 plan_key-immutability message verbatim', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'plan_key is immutable.',
          error: { code: 422, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1' },
        },
        422,
      ),
    )
    const { updatePlan } = await import('@/queries/subscriptionsBilling')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await updatePlan('pro', { display_name: 'x' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).status).toBe(422)
    expect((caught as InstanceType<typeof ApiError>).message).toBe('plan_key is immutable.')
  })

  it('archivePlan POSTs /plans/{key}/archive and returns the archived plan', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: planRow({ status: 'archived' }) }))

    const { archivePlan } = await import('@/queries/subscriptionsBilling')
    const plan = await archivePlan('pro')

    const { url, init } = lastCall(fetchMock)
    expect(init.method).toBe('POST')
    expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/subscriptions/plans/pro/archive')
    expect(plan.status).toBe('archived')
  })

  it('importPlansConfig POSTs /plans/import-config with the given body and normalizes imported plans', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Plans imported', data: { plans: [planRow(), planRow({ plan_key: 'free' })] } }),
    )

    const { importPlansConfig } = await import('@/queries/subscriptionsBilling')
    const imported = await importPlansConfig({ force: true, status: 'draft' })

    const { url, init } = lastCall(fetchMock)
    expect(init.method).toBe('POST')
    expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/subscriptions/plans/import-config')
    expect(bodyOf(init)).toEqual({ force: true, status: 'draft' })
    expect(imported).toHaveLength(2)
  })

  it('every plans action surfaces the engine_disabled structured 409 code', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'subscriptions engine unavailable',
          error: {
            code: 409,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { code: 'engine_disabled' },
          },
        },
        409,
      ),
    )
    const { fetchPlans } = await import('@/queries/subscriptionsBilling')
    const { apiErrorCode } = await import('@/api/errors')
    let caught: unknown
    try {
      await fetchPlans()
    } catch (e) {
      caught = e
    }
    expect(apiErrorCode(caught)).toBe('engine_disabled')
  })

  // ── Workspace directory ──────────────────────────────────────────────────────

  function tenantRow(overrides: Record<string, unknown> = {}) {
    return {
      uuid: 't1',
      slug: 'acme',
      name: 'Acme Co',
      status: 'active',
      deleted_at: null,
      deleted_from_status: null,
      purge_after: null,
      ...overrides,
    }
  }

  function subscriptionSummary(overrides: Record<string, unknown> = {}) {
    return {
      status: 'active',
      plan_key: 'pro',
      plan_display_name: 'Pro',
      trial_ends_at: null,
      grace_ends_at: null,
      provider_managed: false,
      ...overrides,
    }
  }

  it('fetchWorkspaces parses Response::paginated exactly (current_page/per_page/total/total_pages/has_next_page/has_previous_page)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Workspaces retrieved',
        data: [{ tenant: tenantRow(), subscription: subscriptionSummary() }],
        current_page: 2,
        per_page: 10,
        total: 15,
        total_pages: 2,
        has_next_page: false,
        has_previous_page: true,
      }),
    )
    const { fetchWorkspaces } = await import('@/queries/subscriptionsBilling')
    const page = await fetchWorkspaces({ page: 2, perPage: 10 })

    expect(page.rows).toHaveLength(1)
    expect(page.rows[0]!.tenant.uuid).toBe('t1')
    expect(page.rows[0]!.subscription).toEqual(subscriptionSummary())
    expect(page.current_page).toBe(2)
    expect(page.per_page).toBe(10)
    expect(page.total).toBe(15)
    expect(page.total_pages).toBe(2)
    expect(page.has_next_page).toBe(false)
    expect(page.has_previous_page).toBe(true)
  })

  it('fetchWorkspaces normalizes a null subscription to null (workspace with no subscription)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ data: [{ tenant: tenantRow(), subscription: null }], total: 1, current_page: 1, per_page: 20 }),
    )
    const { fetchWorkspaces } = await import('@/queries/subscriptionsBilling')
    const page = await fetchWorkspaces()
    expect(page.rows[0]!.subscription).toBeNull()
  })

  it('fetchWorkspaces sends page/per_page as query params and NO ?uuids= filter', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], total: 0, current_page: 1, per_page: 20 }))

    const { fetchWorkspaces } = await import('@/queries/subscriptionsBilling')
    await fetchWorkspaces({ page: 3, perPage: 50 })

    const { url: rawUrl } = lastCall(fetchMock)
    const url = new URL(rawUrl, 'http://localhost')
    expect(url.pathname).toBe('/v1/admin/subscriptions/workspaces')
    expect(url.searchParams.get('page')).toBe('3')
    expect(url.searchParams.get('per_page')).toBe('50')
    expect(url.searchParams.has('uuids')).toBe(false)
  })

  it('fetchWorkspaces omits page/per_page entirely when not provided', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], total: 0, current_page: 1, per_page: 20 }))
    const { fetchWorkspaces } = await import('@/queries/subscriptionsBilling')
    await fetchWorkspaces()
    const { url: rawUrl } = lastCall(fetchMock)
    const url = new URL(rawUrl, 'http://localhost')
    expect(url.searchParams.has('page')).toBe(false)
    expect(url.searchParams.has('per_page')).toBe(false)
  })

  // ── Workspace detail + overrides (active AND expired) ────────────────────────

  it('fetchWorkspace parses tenant/subscription/overrides, keeping BOTH active and expired override rows', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Workspace retrieved',
        data: {
          tenant: tenantRow(),
          subscription: subscriptionSummary(),
          overrides: [
            { entitlement: 'seats', value: 25, expires_at: '2099-01-01 00:00:00', reason: 'promo', created_at: '2026-01-01 00:00:00', updated_at: '2026-01-01 00:00:00' },
            { entitlement: 'api', value: true, expires_at: '2020-01-01 00:00:00', reason: 'trial extension (expired)', created_at: '2019-01-01 00:00:00', updated_at: '2019-01-01 00:00:00' },
          ],
        },
      }),
    )
    const { fetchWorkspace, isOverrideExpired } = await import('@/queries/subscriptionsBilling')
    const detail = await fetchWorkspace('t1')

    expect(detail.tenant.uuid).toBe('t1')
    expect(detail.overrides).toHaveLength(2)
    expect(detail.overrides[0]).toEqual({
      entitlement: 'seats',
      value: 25,
      expires_at: '2099-01-01 00:00:00',
      reason: 'promo',
      created_at: '2026-01-01 00:00:00',
      updated_at: '2026-01-01 00:00:00',
    })
    expect(isOverrideExpired(detail.overrides[0]!)).toBe(false)
    expect(isOverrideExpired(detail.overrides[1]!)).toBe(true)
  })

  it('isOverrideExpired treats a null expires_at as never expiring', async () => {
    const { isOverrideExpired } = await import('@/queries/subscriptionsBilling')
    expect(
      isOverrideExpired({
        entitlement: 'seats',
        value: 1,
        expires_at: null,
        reason: null,
        created_at: null,
        updated_at: null,
      }),
    ).toBe(false)
  })

  it('fetchWorkspace GETs the exact endpoint', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: { tenant: tenantRow(), subscription: null, overrides: [] } }))
    const { fetchWorkspace } = await import('@/queries/subscriptionsBilling')
    await fetchWorkspace('t1')
    const { url } = lastCall(fetchMock)
    expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/subscriptions/workspaces/t1')
  })

  // ── Set plan / cancel ────────────────────────────────────────────────────────

  it('setWorkspacePlan PUTs the exact {plan_key} body', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ success: true, message: 'Plan updated', data: { status: 'active' } }))

    const { setWorkspacePlan } = await import('@/queries/subscriptionsBilling')
    await setWorkspacePlan('t1', 'pro')

    const { url, init } = lastCall(fetchMock)
    expect(init.method).toBe('PUT')
    expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/subscriptions/workspaces/t1/plan')
    expect(bodyOf(init)).toEqual({ plan_key: 'pro' })
  })

  it('setWorkspacePlan surfaces the provider_managed_subscription structured 409 code and verbatim message', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'this subscription is managed by a payment provider and cannot be changed locally',
          error: {
            code: 409,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { code: 'provider_managed_subscription' },
          },
        },
        409,
      ),
    )
    const { setWorkspacePlan } = await import('@/queries/subscriptionsBilling')
    const { apiErrorCode, ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setWorkspacePlan('t1', 'pro')
    } catch (e) {
      caught = e
    }
    expect(apiErrorCode(caught)).toBe('provider_managed_subscription')
    expect((caught as InstanceType<typeof ApiError>).message).toBe(
      'this subscription is managed by a payment provider and cannot be changed locally',
    )
  })

  it('cancelWorkspaceSubscription POSTs the exact {at_period_end} body, defaulting to true', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: { status: 'canceled' } }))

    const { cancelWorkspaceSubscription } = await import('@/queries/subscriptionsBilling')
    await cancelWorkspaceSubscription('t1')

    const { url, init } = lastCall(fetchMock)
    expect(init.method).toBe('POST')
    expect(new URL(url, 'http://localhost').pathname).toBe('/v1/admin/subscriptions/workspaces/t1/cancel')
    expect(bodyOf(init)).toEqual({ at_period_end: true })
  })

  it('cancelWorkspaceSubscription forwards at_period_end: false when passed', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: { status: 'canceled' } }))
    const { cancelWorkspaceSubscription } = await import('@/queries/subscriptionsBilling')
    await cancelWorkspaceSubscription('t1', false)
    const { init } = lastCall(fetchMock)
    expect(bodyOf(init)).toEqual({ at_period_end: false })
  })

  it('the workspace-scoped default_workspace_missing structured 409 code surfaces from a single-store workspace read', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'no default workspace is established yet',
          error: {
            code: 409,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { code: 'default_workspace_missing' },
          },
        },
        409,
      ),
    )
    const { fetchWorkspace } = await import('@/queries/subscriptionsBilling')
    const { apiErrorCode, ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await fetchWorkspace('whatever')
    } catch (e) {
      caught = e
    }
    expect(apiErrorCode(caught)).toBe('default_workspace_missing')
    expect((caught as InstanceType<typeof ApiError>).message).toBe('no default workspace is established yet')
  })

  // ── Overrides: upsert / delete ───────────────────────────────────────────────

  it('upsertWorkspaceOverride PUTs the exact {value, expires_at, reason} body and normalizes the echoed row', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Override saved',
        data: { entitlement: 'seats', value: 25, expires_at: '2099-01-01', reason: 'promo' },
      }),
    )

    const { upsertWorkspaceOverride } = await import('@/queries/subscriptionsBilling')
    const override = await upsertWorkspaceOverride('t1', 'seats', {
      value: 25,
      expires_at: '2099-01-01',
      reason: 'promo',
    })

    const { url, init } = lastCall(fetchMock)
    expect(init.method).toBe('PUT')
    expect(new URL(url, 'http://localhost').pathname).toBe(
      '/v1/admin/subscriptions/workspaces/t1/overrides/seats',
    )
    expect(bodyOf(init)).toEqual({ value: 25, expires_at: '2099-01-01', reason: 'promo' })
    expect(override.entitlement).toBe('seats')
    expect(override.value).toBe(25)
  })

  it('upsertWorkspaceOverride defaults expires_at/reason to null when omitted', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ data: { entitlement: 'api', value: true, expires_at: null, reason: null } }),
    )
    const { upsertWorkspaceOverride } = await import('@/queries/subscriptionsBilling')
    await upsertWorkspaceOverride('t1', 'api', { value: true })
    const { init } = lastCall(fetchMock)
    expect(bodyOf(init)).toEqual({ value: true, expires_at: null, reason: null })
  })

  it('deleteWorkspaceOverride DELETEs the exact endpoint', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ success: true, message: 'Override removed', data: [] }))

    const { deleteWorkspaceOverride } = await import('@/queries/subscriptionsBilling')
    await deleteWorkspaceOverride('t1', 'seats')

    const { url, init } = lastCall(fetchMock)
    expect(init.method).toBe('DELETE')
    expect(new URL(url, 'http://localhost').pathname).toBe(
      '/v1/admin/subscriptions/workspaces/t1/overrides/seats',
    )
  })
})
