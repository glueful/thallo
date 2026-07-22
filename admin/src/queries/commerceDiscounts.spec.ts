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

// The api client captures globalThis.fetch at creation, so stub fetch BEFORE importing the
// fetcher (reset the module graph each test, then dynamic-import after stubbing). Mirrors
// commerceOrders.spec.ts/commerceCatalog.spec.ts.
describe('commerce discounts query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  function discountBody(overrides: Record<string, unknown> = {}) {
    return {
      uuid: 'd1',
      code: 'SAVE10',
      type: 'percentage',
      value: 1000,
      min_subtotal: null,
      usage_limit: null,
      once_per_buyer: false,
      usage_count: 0,
      status: 'active',
      starts_at: null,
      ends_at: null,
      product_scope: null,
      created_at: '2026-01-01 00:00:00',
      updated_at: null,
      ...overrides,
    }
  }

  // ── fetchDiscounts: envelope, filters, pagination ───────────────────────────────────────────

  it('parses the real Response::paginated envelope and normalizes discounts', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Discounts retrieved',
        data: [discountBody()],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchDiscounts } = await import('@/queries/commerceDiscounts')
    const page = await fetchDiscounts({ page: 1, perPage: 24 })

    expect(page.discounts).toHaveLength(1)
    expect(page.discounts[0]!.uuid).toBe('d1')
    expect(page.discounts[0]!.code).toBe('SAVE10')
    expect(page.discounts[0]!.type).toBe('percentage')
    expect(page.discounts[0]!.value).toBe(1000)
    expect(page.total).toBe(1)
    expect(page.current_page).toBe(1)
    expect(page.per_page).toBe(24)
  })

  it('sends status/q/page/per_page as query params, omitting empty filters (the exact DiscountListQuery shape)', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 2, per_page: 10, total: 0 }))

    const { fetchDiscounts } = await import('@/queries/commerceDiscounts')
    await fetchDiscounts({ status: 'active', q: 'sale', page: 2, perPage: 10 })

    const requested = fetchMock.mock.calls[0]![0]
    const requestedUrl = typeof requested === 'string' ? requested : (requested as Request).url
    const url = new URL(requestedUrl, 'http://localhost')
    expect(url.pathname).toBe('/v1/admin/commerce/discounts')
    expect(url.searchParams.get('status')).toBe('active')
    expect(url.searchParams.get('q')).toBe('sale')
    expect(url.searchParams.get('page')).toBe('2')
    expect(url.searchParams.get('per_page')).toBe('10')
  })

  it('omits status/q entirely when no filter is set', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 1, per_page: 24, total: 0 }))

    const { fetchDiscounts } = await import('@/queries/commerceDiscounts')
    await fetchDiscounts({})

    const requested = fetchMock.mock.calls[0]![0]
    const url = new URL(typeof requested === 'string' ? requested : (requested as Request).url, 'http://localhost')
    expect(url.searchParams.has('status')).toBe(false)
    expect(url.searchParams.has('q')).toBe(false)
  })

  it('defaults an empty page to zero total and the requested paging', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

    const { fetchDiscounts } = await import('@/queries/commerceDiscounts')
    const page = await fetchDiscounts({ page: 3, perPage: 50 })

    expect(page.discounts).toEqual([])
    expect(page.total).toBe(0)
    expect(page.current_page).toBe(3)
    expect(page.per_page).toBe(50)
  })

  it('throws ApiError when the list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchDiscounts } = await import('@/queries/commerceDiscounts')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchDiscounts()).rejects.toBeInstanceOf(ApiError)
  })

  // ── normalization: strict types, no Number() coercion of amounts ────────────────────────────

  it('normalizes a fixed discount with usage limit, once_per_buyer, and an active window', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Discounts retrieved',
        data: [
          discountBody({
            uuid: 'd2',
            code: 'FLAT5',
            type: 'fixed',
            value: 500,
            min_subtotal: 2000,
            usage_limit: 100,
            once_per_buyer: true,
            usage_count: 42,
            starts_at: '2026-01-01 00:00:00',
            ends_at: '2026-01-31 00:00:00',
          }),
        ],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchDiscounts } = await import('@/queries/commerceDiscounts')
    const page = await fetchDiscounts()
    const d = page.discounts[0]!

    expect(d.type).toBe('fixed')
    expect(d.value).toBe(500)
    expect(d.min_subtotal).toBe(2000)
    expect(d.usage_limit).toBe(100)
    expect(d.once_per_buyer).toBe(true)
    expect(d.usage_count).toBe(42)
    expect(d.starts_at).toBe('2026-01-01 00:00:00')
    expect(d.ends_at).toBe('2026-01-31 00:00:00')
  })

  it('normalizes a discount with no usage limit or window to safe null defaults', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Discounts retrieved',
        data: [discountBody({ uuid: 'd3' })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchDiscounts } = await import('@/queries/commerceDiscounts')
    const page = await fetchDiscounts()
    const d = page.discounts[0]!

    expect(d.min_subtotal).toBeNull()
    expect(d.usage_limit).toBeNull()
    expect(d.once_per_buyer).toBe(false)
    expect(d.starts_at).toBeNull()
    expect(d.ends_at).toBeNull()
    expect(d.product_scope).toBeNull()
  })

  it('never coerces amounts through Number() — a malformed value becomes the neutral 0 fallback', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Discounts retrieved',
        data: [discountBody({ uuid: 'd4', value: '1000', min_subtotal: '2000' })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchDiscounts } = await import('@/queries/commerceDiscounts')
    const page = await fetchDiscounts()
    const d = page.discounts[0]!

    expect(d.value).toBe(0)
    expect(d.min_subtotal).toBeNull()
  })

  // ── fetchDiscount (show) ─────────────────────────────────────────────────────────────────────

  it('fetches and normalizes a single discount', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Discount retrieved', data: discountBody({ uuid: 'd1' }) }),
    )

    const { fetchDiscount } = await import('@/queries/commerceDiscounts')
    const discount = await fetchDiscount('d1')

    expect(discount.uuid).toBe('d1')
    expect(discount.code).toBe('SAVE10')
  })

  it('throws ApiError for a 404 discount', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchDiscount } = await import('@/queries/commerceDiscounts')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchDiscount('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── createDiscount: exact CreateDiscountData body ───────────────────────────────────────────

  it('createDiscount posts the exact CreateDiscountData body and normalizes the created discount', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Discount created', data: discountBody({ uuid: 'd5' }) }, 201),
    )

    const { createDiscount } = await import('@/queries/commerceDiscounts')
    const discount = await createDiscount({
      code: 'SAVE10',
      type: 'percentage',
      value: 1000,
      min_subtotal: null,
      usage_limit: null,
      once_per_buyer: false,
      status: 'active',
      starts_at: null,
      ends_at: null,
    })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/discounts')
    expect(await request.clone().json()).toEqual({
      code: 'SAVE10',
      type: 'percentage',
      value: 1000,
      min_subtotal: null,
      usage_limit: null,
      once_per_buyer: false,
      status: 'active',
      starts_at: null,
      ends_at: null,
    })
    expect(discount.uuid).toBe('d5')
  })

  // AdminDiscountController manually catches its own ValidationException and calls
  // Response::validation($e->firstErrors()) -> Response::error(...) -> the
  // {error: {details: {field: message}}} envelope (NOT the global handler's {errors: {field: [msg]}}
  // shape) — mirrors commerceCatalog.ts's identical business-rule-422 precedent (errors.ts's
  // fieldErrorsFromDetails() docblock).
  it('createDiscount surfaces a 422 invalid-type rejection as a keyed field error', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { type: 'Type must be percentage or fixed.' },
          },
        },
        422,
      ),
    )

    const { createDiscount } = await import('@/queries/commerceDiscounts')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createDiscount({ code: 'BAD', type: 'free_shipping' as never, value: 500 })
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).status).toBe(422)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors.type).toBe(
      'Type must be percentage or fixed.',
    )
  })

  // ── updateDiscount: exact PATCH endpoint + body ─────────────────────────────────────────────

  it('updateDiscount PATCHes the exact endpoint with the given body and normalizes the result', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Discount updated', data: discountBody({ uuid: 'd1', status: 'inactive' }) }),
    )

    const { updateDiscount } = await import('@/queries/commerceDiscounts')
    const discount = await updateDiscount('d1', { status: 'inactive' })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('PATCH')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/discounts/d1')
    expect(await request.clone().json()).toEqual({ status: 'inactive' })
    expect(discount.status).toBe('inactive')
  })

  it('updateDiscount surfaces a 404 for an unknown or since-deleted discount', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { updateDiscount } = await import('@/queries/commerceDiscounts')
    const { ApiError } = await import('@/api/errors')
    await expect(updateDiscount('missing', { status: 'inactive' })).rejects.toBeInstanceOf(ApiError)
  })

  // ── deleteDiscount: exact DELETE endpoint + the redeemed-discount 409 ──────────────────────

  it('deleteDiscount DELETEs the exact endpoint', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    const { deleteDiscount } = await import('@/queries/commerceDiscounts')
    await deleteDiscount('d1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('DELETE')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/discounts/d1')
  })

  it('deleteDiscount surfaces the server 409 message verbatim for a redeemed discount', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'This discount has been redeemed and cannot be deleted. Disable it via status instead.',
          error: { code: 409, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1' },
        },
        409,
      ),
    )

    const { deleteDiscount } = await import('@/queries/commerceDiscounts')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await deleteDiscount('d1')
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
    expect((caught as InstanceType<typeof ApiError>).message).toBe(
      'This discount has been redeemed and cannot be deleted. Disable it via status instead.',
    )
  })
})
