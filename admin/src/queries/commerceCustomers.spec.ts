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
// commerceOrders.spec.ts/commerceReviews.spec.ts.
describe('commerce customers query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  function customerBody(overrides: Record<string, unknown> = {}) {
    return {
      key_type: 'user',
      key: 'usercustu001',
      user_uuid: 'usercustu001',
      email: 'ada@example.com',
      orders_count: 2,
      total_spent_minor: 1500,
      refunded_minor: 0,
      first_order_at: '2026-01-01 00:00:00',
      last_order_at: '2026-01-05 00:00:00',
      ...overrides,
    }
  }

  // ── fetchCustomers: envelope, filters, pagination ───────────────────────────────────────────

  it('parses the real Response::paginated envelope and normalizes customers', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Customers retrieved',
        data: [customerBody()],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchCustomers } = await import('@/queries/commerceCustomers')
    const page = await fetchCustomers({ page: 1, perPage: 24 })

    expect(page.customers).toHaveLength(1)
    expect(page.customers[0]!.key).toBe('usercustu001')
    expect(page.customers[0]!.key_type).toBe('user')
    expect(page.customers[0]!.orders_count).toBe(2)
    expect(page.customers[0]!.total_spent_minor).toBe(1500)
    // No `username` key on the fixture -- normalizes to null, never left undefined/optional.
    expect(page.customers[0]!.username).toBeNull()
    expect(page.total).toBe(1)
    expect(page.current_page).toBe(1)
    expect(page.per_page).toBe(24)
  })

  it('normalizes an email-keyed (guest) customer, whose key is the normalized email', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Customers retrieved',
        data: [
          customerBody({
            key_type: 'email',
            key: 'guest@example.com',
            user_uuid: null,
            email: 'guest@example.com',
          }),
        ],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchCustomers } = await import('@/queries/commerceCustomers')
    const page = await fetchCustomers()

    expect(page.customers[0]!.key_type).toBe('email')
    expect(page.customers[0]!.user_uuid).toBeNull()
  })

  it('includes an enriched username when the backend attaches one', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ data: [customerBody({ username: 'ada' })], current_page: 1, per_page: 24, total: 1 }),
    )

    const { fetchCustomers } = await import('@/queries/commerceCustomers')
    const page = await fetchCustomers()
    expect(page.customers[0]!.username).toBe('ada')
  })

  it('sends email/sort/direction/page/per_page as query params, omitting empty filters (the exact CustomerListQuery shape)', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 2, per_page: 10, total: 0 }))

    const { fetchCustomers } = await import('@/queries/commerceCustomers')
    await fetchCustomers({ email: 'ada', sort: 'total_spent', direction: 'asc', page: 2, perPage: 10 })

    const requested = fetchMock.mock.calls[0]![0]
    const requestedUrl = typeof requested === 'string' ? requested : (requested as Request).url
    const url = new URL(requestedUrl, 'http://localhost')
    expect(url.pathname).toBe('/v1/admin/commerce/customers')
    expect(url.searchParams.get('email')).toBe('ada')
    expect(url.searchParams.get('sort')).toBe('total_spent')
    expect(url.searchParams.get('direction')).toBe('asc')
    expect(url.searchParams.get('page')).toBe('2')
    expect(url.searchParams.get('per_page')).toBe('10')
  })

  it('omits email/sort/direction entirely when none are set', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 1, per_page: 24, total: 0 }))

    const { fetchCustomers } = await import('@/queries/commerceCustomers')
    await fetchCustomers({})

    const requested = fetchMock.mock.calls[0]![0]
    const url = new URL(typeof requested === 'string' ? requested : (requested as Request).url, 'http://localhost')
    expect(url.searchParams.has('email')).toBe(false)
    expect(url.searchParams.has('sort')).toBe(false)
    expect(url.searchParams.has('direction')).toBe(false)
  })

  it('defaults an empty page to zero total and the requested paging', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

    const { fetchCustomers } = await import('@/queries/commerceCustomers')
    const page = await fetchCustomers({ page: 3, perPage: 50 })

    expect(page.customers).toEqual([])
    expect(page.total).toBe(0)
    expect(page.current_page).toBe(3)
    expect(page.per_page).toBe(50)
  })

  it('never coerces amounts through Number() — a malformed total_spent_minor becomes the neutral 0 fallback', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        data: [customerBody({ total_spent_minor: '1500', refunded_minor: null })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchCustomers } = await import('@/queries/commerceCustomers')
    const page = await fetchCustomers()
    expect(page.customers[0]!.total_spent_minor).toBe(0)
    expect(page.customers[0]!.refunded_minor).toBe(0)
  })

  it('throws ApiError when the list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchCustomers } = await import('@/queries/commerceCustomers')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchCustomers()).rejects.toBeInstanceOf(ApiError)
  })

  // ── fetchCustomer: envelope, by= param, path key, orders, addresses ─────────────────────────

  it('GETs the exact endpoint with path key and by=user query param', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Customer retrieved', data: customerBody() }),
    )

    const { fetchCustomer } = await import('@/queries/commerceCustomers')
    await fetchCustomer('usercustu001', 'user')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('GET')
    const url = new URL(request.url, 'http://localhost')
    expect(url.pathname).toBe('/v1/admin/commerce/customers/usercustu001')
    expect(url.searchParams.get('by')).toBe('user')
  })

  it('URL-encodes an email key (which always contains "@") in the path', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Customer retrieved',
        data: customerBody({ key_type: 'email', key: 'guest@example.com', user_uuid: null }),
      }),
    )

    const { fetchCustomer } = await import('@/queries/commerceCustomers')
    await fetchCustomer('guest@example.com', 'email')

    const request = fetchMock.mock.calls[0]![0] as Request
    const url = new URL(request.url, 'http://localhost')
    expect(url.pathname).toBe('/v1/admin/commerce/customers/guest%40example.com')
    expect(url.searchParams.get('by')).toBe('email')
  })

  it('normalizes recent orders attached to the detail payload', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Customer retrieved',
        data: customerBody({
          orders: [
            {
              uuid: 'o1',
              order_number: 'ORD-1001',
              status: 'paid',
              fulfillment_status: 'unfulfilled',
              email: 'ada@example.com',
              user_uuid: 'usercustu001',
              currency: 'USD',
              subtotal: 1000,
              discount_total: 0,
              shipping_total: 0,
              tax_total: 0,
              grand_total: 1000,
              refunded_total: 0,
              placed_at: '2026-01-01 00:00:00',
              created_at: '2026-01-01 00:00:00',
              // Internal columns the raw commerce_orders row also carries — must NOT surface.
              tenant_uuid: 't1',
              guest_token_hash: 'x'.repeat(64),
              fulfillment_revision: 3,
            },
          ],
        }),
      }),
    )

    const { fetchCustomer } = await import('@/queries/commerceCustomers')
    const customer = await fetchCustomer('usercustu001', 'user')

    expect(customer.orders).toHaveLength(1)
    expect(customer.orders[0]!.uuid).toBe('o1')
    expect(customer.orders[0]!.order_number).toBe('ORD-1001')
    expect(customer.orders[0]!.grand_total).toBe(1000)
    expect(customer.orders[0]).not.toHaveProperty('tenant_uuid')
    expect(customer.orders[0]).not.toHaveProperty('guest_token_hash')
    expect(customer.orders[0]).not.toHaveProperty('fulfillment_revision')
  })

  it('defaults orders to an empty array when the detail carries none', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Customer retrieved', data: customerBody() }),
    )

    const { fetchCustomer } = await import('@/queries/commerceCustomers')
    const customer = await fetchCustomer('usercustu001', 'user')
    expect(customer.orders).toEqual([])
  })

  it('normalizes the address book for a user-keyed customer', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Customer retrieved',
        data: customerBody({
          addresses: [
            {
              uuid: 'addr1',
              label: 'Home',
              address: { country: 'US', city: 'Springfield' },
              is_default_shipping: true,
              is_default_billing: false,
              created_at: '2026-01-01 00:00:00',
              updated_at: null,
            },
          ],
        }),
      }),
    )

    const { fetchCustomer } = await import('@/queries/commerceCustomers')
    const customer = await fetchCustomer('usercustu001', 'user')

    expect(customer.addresses).toHaveLength(1)
    expect(customer.addresses![0]!.uuid).toBe('addr1')
    expect(customer.addresses![0]!.address).toEqual({ country: 'US', city: 'Springfield' })
    expect(customer.addresses![0]!.is_default_shipping).toBe(true)
  })

  it('normalizes an empty (but present) address book to an empty array, distinct from absent', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Customer retrieved', data: customerBody({ addresses: [] }) }),
    )

    const { fetchCustomer } = await import('@/queries/commerceCustomers')
    const customer = await fetchCustomer('usercustu001', 'user')
    expect(customer.addresses).toEqual([])
  })

  it('normalizes addresses to null when the key entirely absent (by=email never carries it)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Customer retrieved',
        data: customerBody({ key_type: 'email', key: 'guest@example.com', user_uuid: null }),
      }),
    )

    const { fetchCustomer } = await import('@/queries/commerceCustomers')
    const customer = await fetchCustomer('guest@example.com', 'email')
    expect(customer.addresses).toBeNull()
  })

  it('throws ApiError for a 404 (unknown key or cross-tenant, non-revealing)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchCustomer } = await import('@/queries/commerceCustomers')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchCustomer('missing', 'user')).rejects.toBeInstanceOf(ApiError)
  })
})
