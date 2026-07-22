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
// commerceCatalog.spec.ts.
describe('commerce orders query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  // ── fetchOrders: envelope, filters, pagination ──────────────────────────────────────────────

  it('parses the real Response::paginated envelope and normalizes orders', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Orders retrieved',
        data: [
          {
            uuid: 'o1',
            order_number: 'ORD-1001',
            status: 'paid',
            fulfillment_status: 'unfulfilled',
            email: 'buyer@example.com',
            user_uuid: null,
            currency: 'USD',
            subtotal: 5000,
            discount_total: 0,
            shipping_total: 500,
            tax_total: 400,
            grand_total: 5900,
            refunded_total: 0,
            discount_code: null,
            shipping_method: 'standard',
            addresses: null,
            placed_at: '2026-01-01 00:00:00',
            created_at: '2026-01-01 00:00:00',
            updated_at: null,
          },
        ],
        current_page: 1,
        per_page: 24,
        total: 1,
        total_pages: 1,
        has_next_page: false,
        has_previous_page: false,
      }),
    )

    const { fetchOrders } = await import('@/queries/commerceOrders')
    const page = await fetchOrders({ page: 1, perPage: 24 })

    expect(page.orders).toHaveLength(1)
    expect(page.orders[0]!.uuid).toBe('o1')
    expect(page.orders[0]!.order_number).toBe('ORD-1001')
    expect(page.orders[0]!.grand_total).toBe(5900)
    // List rows never include lines/events — mirrors CommerceProduct's list-omits-variants note.
    expect(page.orders[0]!.lines).toEqual([])
    expect(page.orders[0]!.events).toEqual([])
    expect(page.total).toBe(1)
    expect(page.current_page).toBe(1)
    expect(page.per_page).toBe(24)
  })

  it('sends status/page/per_page as query params, omitting empty filters (the exact OrderListQuery shape)', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 2, per_page: 10, total: 0 }))

    const { fetchOrders } = await import('@/queries/commerceOrders')
    await fetchOrders({ status: 'paid', page: 2, perPage: 10 })

    const requested = fetchMock.mock.calls[0]![0]
    const requestedUrl = typeof requested === 'string' ? requested : (requested as Request).url
    const url = new URL(requestedUrl, 'http://localhost')
    expect(url.searchParams.get('status')).toBe('paid')
    expect(url.searchParams.get('page')).toBe('2')
    expect(url.searchParams.get('per_page')).toBe('10')
    // OrderListQuery has no `type`/`q` field — never sent.
    expect(url.searchParams.has('type')).toBe(false)
    expect(url.searchParams.has('q')).toBe(false)
  })

  it('omits the status param entirely when no filter is set', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 1, per_page: 24, total: 0 }))

    const { fetchOrders } = await import('@/queries/commerceOrders')
    await fetchOrders({})

    const requested = fetchMock.mock.calls[0]![0]
    const requestedUrl = typeof requested === 'string' ? requested : (requested as Request).url
    const url = new URL(requestedUrl, 'http://localhost')
    expect(url.searchParams.has('status')).toBe(false)
  })

  it('defaults an empty page to zero total and the requested paging', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

    const { fetchOrders } = await import('@/queries/commerceOrders')
    const page = await fetchOrders({ page: 3, perPage: 50 })

    expect(page.orders).toEqual([])
    expect(page.total).toBe(0)
    expect(page.current_page).toBe(3)
    expect(page.per_page).toBe(50)
  })

  it('throws ApiError when the list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchOrders } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchOrders()).rejects.toBeInstanceOf(ApiError)
  })

  // ── fetchOrder: detail normalization — lines, totals (minor units), events, addresses ───────

  it('fetches and normalizes a single order with its lines, totals, and events', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Order retrieved',
        data: {
          uuid: 'o1',
          order_number: 'ORD-1001',
          status: 'paid',
          fulfillment_status: 'unfulfilled',
          email: 'buyer@example.com',
          user_uuid: 'u1',
          currency: 'USD',
          subtotal: 123456,
          discount_total: 1000,
          shipping_total: 500,
          tax_total: 400,
          grand_total: 123356,
          refunded_total: 0,
          discount_code: 'SAVE10',
          shipping_method: 'standard',
          addresses: {
            shipping: { first_name: 'Ada', last_name: 'Lovelace', address1: '1 Main St', city: 'Springfield' },
            billing: { name: 'Ada Lovelace', line1: '1 Main St' },
          },
          placed_at: '2026-01-01 00:00:00',
          created_at: '2026-01-01 00:00:00',
          updated_at: '2026-01-02 00:00:00',
          events: [
            { uuid: 'e1', order_uuid: 'o1', type: 'placed', payload: { number: 'ORD-1001' }, actor_uuid: null, visibility: 'internal', created_at: '2026-01-01 00:00:00' },
            { uuid: 'e2', order_uuid: 'o1', type: 'status:paid', payload: null, actor_uuid: null, visibility: 'internal', created_at: '2026-01-01 01:00:00' },
          ],
          lines: [
            {
              uuid: 'l1',
              product_name: 'Widget',
              sku: 'SKU-1',
              quantity: 2,
              unit_price: 61728,
              line_total: 123456,
              option_values: { size: 'M' },
              addons: [{ name: 'Gift wrap', price_delta: 500 }],
            },
          ],
        },
      }),
    )

    const { fetchOrder } = await import('@/queries/commerceOrders')
    const order = await fetchOrder('o1')

    expect(order.uuid).toBe('o1')
    expect(order.order_number).toBe('ORD-1001')
    expect(order.grand_total).toBe(123356)
    expect(order.subtotal).toBe(123456)

    expect(order.lines).toHaveLength(1)
    expect(order.lines[0]!.product_name).toBe('Widget')
    expect(order.lines[0]!.quantity).toBe(2)
    expect(order.lines[0]!.unit_price).toBe(61728)
    expect(order.lines[0]!.line_total).toBe(123456)
    expect(order.lines[0]!.option_values).toEqual({ size: 'M' })
    expect(order.lines[0]!.addons).toEqual([{ name: 'Gift wrap', price_delta: 500 }])

    expect(order.events).toHaveLength(2)
    expect(order.events[0]!.type).toBe('placed')
    expect(order.events[1]!.type).toBe('status:paid')

    expect(order.addresses?.shipping).toEqual({
      first_name: 'Ada',
      last_name: 'Lovelace',
      address1: '1 Main St',
      city: 'Springfield',
    })
    expect(order.addresses?.billing).toEqual({ name: 'Ada Lovelace', line1: '1 Main St' })
  })

  it('normalizes an order with no addresses/lines/events to safe empty defaults', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Order retrieved',
        data: {
          uuid: 'o2',
          order_number: 'ORD-1002',
          status: 'pending_payment',
          fulfillment_status: 'unfulfilled',
          email: 'guest@example.com',
          user_uuid: null,
          currency: 'USD',
          subtotal: 1000,
          discount_total: 0,
          shipping_total: 0,
          tax_total: 0,
          grand_total: 1000,
          refunded_total: 0,
          discount_code: null,
          shipping_method: null,
          addresses: null,
          placed_at: null,
          created_at: '2026-01-01 00:00:00',
          updated_at: null,
        },
      }),
    )

    const { fetchOrder } = await import('@/queries/commerceOrders')
    const order = await fetchOrder('o2')

    expect(order.addresses).toBeNull()
    expect(order.lines).toEqual([])
    expect(order.events).toEqual([])
    expect(order.user_uuid).toBeNull()
  })

  it('never coerces amounts through Number() — a malformed grand_total becomes the neutral 0 fallback', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Order retrieved',
        data: {
          uuid: 'o3',
          order_number: 'ORD-1003',
          status: 'paid',
          fulfillment_status: 'unfulfilled',
          email: 'buyer@example.com',
          user_uuid: null,
          currency: 'USD',
          subtotal: '5000', // string, not a number — must NOT be coerced
          discount_total: 0,
          shipping_total: 0,
          tax_total: 0,
          grand_total: null,
          refunded_total: 0,
          discount_code: null,
          shipping_method: null,
          addresses: null,
          placed_at: null,
          created_at: null,
          updated_at: null,
        },
      }),
    )

    const { fetchOrder } = await import('@/queries/commerceOrders')
    const order = await fetchOrder('o3')

    expect(order.subtotal).toBe(0)
    expect(order.grand_total).toBe(0)
  })

  it('throws ApiError for a 404 order', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { fetchOrder } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchOrder('missing')).rejects.toBeInstanceOf(ApiError)
  })
})
