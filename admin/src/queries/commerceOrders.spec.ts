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

  // ── Lifecycle actions: cancel / mark-paid / fulfill (Task 13b) ──────────────────────────────
  // Exact request bodies: cancel and mark-paid send NO body at all (AdminOrderController::cancel()/
  // markPaid() take only `Request $request, string $uuid` — no DTO param); fulfill sends exactly
  // `{ tracking_ref }` (FulfillOrderData's only field).

  function fulfilledOrderBody(overrides: Record<string, unknown> = {}) {
    return {
      success: true,
      message: 'Order fulfilled',
      data: {
        uuid: 'o1',
        order_number: 'ORD-1001',
        status: 'fulfilled',
        fulfillment_status: 'fulfilled',
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
        updated_at: '2026-01-02 00:00:00',
        ...overrides,
      },
    }
  }

  it('cancelOrder posts to the exact endpoint with no request body and returns the canceled order', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(fulfilledOrderBody({ status: 'canceled', fulfillment_status: 'unfulfilled' })))

    const { cancelOrder } = await import('@/queries/commerceOrders')
    const order = await cancelOrder('o1')

    const requested = fetchMock.mock.calls[0]!
    const request = requested[0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/orders/o1/cancel')
    expect(await request.clone().text()).toBe('')
    expect(order.status).toBe('canceled')
  })

  it('markOrderPaid posts to the exact endpoint with no request body and returns the paid order', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(fulfilledOrderBody({ status: 'paid', fulfillment_status: 'unfulfilled' })))

    const { markOrderPaid } = await import('@/queries/commerceOrders')
    const order = await markOrderPaid('o1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/orders/o1/mark-paid')
    expect(await request.clone().text()).toBe('')
    expect(order.status).toBe('paid')
  })

  it('fulfillOrder posts the exact { tracking_ref } body and returns the fulfilled order', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(fulfilledOrderBody()))

    const { fulfillOrder } = await import('@/queries/commerceOrders')
    const order = await fulfillOrder('o1', { tracking_ref: 'TRACK-123' })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/orders/o1/fulfill')
    expect(await request.clone().json()).toEqual({ tracking_ref: 'TRACK-123' })
    expect(order.status).toBe('fulfilled')
    expect(order.fulfillment_status).toBe('fulfilled')
  })

  it('fulfillOrder sends tracking_ref: null when omitted', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(fulfilledOrderBody()))

    const { fulfillOrder } = await import('@/queries/commerceOrders')
    await fulfillOrder('o1', { tracking_ref: null })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(await request.clone().json()).toEqual({ tracking_ref: null })
  })

  it('surfaces the server 409 message for an illegal transition (cancel)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Invalid order transition fulfilled -> canceled.',
          error: { code: 409, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1' },
        },
        409,
      ),
    )

    const { cancelOrder } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await cancelOrder('o1')
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
    expect((caught as InstanceType<typeof ApiError>).message).toBe('Invalid order transition fulfilled -> canceled.')
  })

  it('surfaces the server 409 message for an illegal transition (mark-paid)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        { success: false, message: 'Invalid order transition paid -> paid.' },
        409,
      ),
    )

    const { markOrderPaid } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await markOrderPaid('o1')
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
    expect((caught as InstanceType<typeof ApiError>).message).toBe('Invalid order transition paid -> paid.')
  })

  it('surfaces the server 409 message for an illegal transition (fulfill)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        { success: false, message: 'Invalid order transition pending_payment -> fulfilled.' },
        409,
      ),
    )

    const { fulfillOrder } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await fulfillOrder('o1', {})
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
    expect((caught as InstanceType<typeof ApiError>).message).toBe(
      'Invalid order transition pending_payment -> fulfilled.',
    )
  })

  // ── Lifecycle guard functions — mirrors OrderStateMachine::ALLOWED exactly ──────────────────

  it('canCancelOrder is legal from pending_payment and paid only', async () => {
    const { canCancelOrder } = await import('@/queries/commerceOrders')
    expect(canCancelOrder('pending_payment')).toBe(true)
    expect(canCancelOrder('paid')).toBe(true)
    expect(canCancelOrder('fulfilled')).toBe(false)
    expect(canCancelOrder('canceled')).toBe(false)
    expect(canCancelOrder('refunded')).toBe(false)
  })

  it('canMarkOrderPaid is legal from pending_payment only', async () => {
    const { canMarkOrderPaid } = await import('@/queries/commerceOrders')
    expect(canMarkOrderPaid('pending_payment')).toBe(true)
    expect(canMarkOrderPaid('paid')).toBe(false)
    expect(canMarkOrderPaid('fulfilled')).toBe(false)
    expect(canMarkOrderPaid('canceled')).toBe(false)
    expect(canMarkOrderPaid('refunded')).toBe(false)
  })

  it('canFulfillOrder is legal from paid only', async () => {
    const { canFulfillOrder } = await import('@/queries/commerceOrders')
    expect(canFulfillOrder('paid')).toBe(true)
    expect(canFulfillOrder('pending_payment')).toBe(false)
    expect(canFulfillOrder('fulfilled')).toBe(false)
    expect(canFulfillOrder('canceled')).toBe(false)
    expect(canFulfillOrder('refunded')).toBe(false)
  })

  it('canRefundOrder is legal from paid and fulfilled only', async () => {
    const { canRefundOrder } = await import('@/queries/commerceOrders')
    expect(canRefundOrder('paid')).toBe(true)
    expect(canRefundOrder('fulfilled')).toBe(true)
    expect(canRefundOrder('pending_payment')).toBe(false)
    expect(canRefundOrder('canceled')).toBe(false)
    expect(canRefundOrder('refunded')).toBe(false)
  })

  // ── Refunds (Task 13c): create, per-order list, cross-order list/show ──────────────────────

  function refundBody(overrides: Record<string, unknown> = {}) {
    return {
      success: true,
      message: 'Refund recorded',
      data: {
        uuid: 'r1',
        order_uuid: 'o1',
        amount: 1234,
        currency: 'USD',
        method: 'manual',
        status: 'completed',
        reason: 'customer request',
        restocked: false,
        initiated_by: 'admin1',
        completed_at: '2026-01-03 00:00:00',
        lines: [],
        ...overrides,
      },
    }
  }

  it('createRefund posts the exact body and Idempotency-Key header, and parses the refund', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(refundBody()))

    const { createRefund } = await import('@/queries/commerceOrders')
    const refund = await createRefund(
      'o1',
      { amount: 1234, reason: 'customer request', restock: false },
      'idem-key-1',
    )

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/orders/o1/refunds')
    expect(request.headers.get('idempotency-key')).toBe('idem-key-1')
    expect(await request.clone().json()).toEqual({
      amount: 1234,
      reason: 'customer request',
      lines: null,
      restock: false,
    })
    expect(refund.uuid).toBe('r1')
    expect(refund.amount).toBe(1234)
    expect(refund.status).toBe('completed')
    expect(refund.reason).toBe('customer request')
  })

  it('createRefund sends reason: null and restock: false when omitted', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(refundBody()))

    const { createRefund } = await import('@/queries/commerceOrders')
    await createRefund('o1', { amount: 1234 }, 'idem-key-2')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(await request.clone().json()).toEqual({
      amount: 1234,
      reason: null,
      lines: null,
      restock: false,
    })
  })

  it('normalizes a refund missing created_at/updated_at/provider_ref (the immediate store() response shape)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Refund recorded',
        data: {
          uuid: 'r2',
          order_uuid: 'o1',
          amount: 500,
          currency: 'USD',
          method: 'manual',
          status: 'completed',
          reason: null,
          restocked: false,
          initiated_by: null,
          lines: [],
          // created_at/updated_at/completed_at/failure_reason deliberately absent —
          // RefundService::buildRow() never sets them on the in-memory row it returns.
        },
      }),
    )

    const { createRefund } = await import('@/queries/commerceOrders')
    const refund = await createRefund('o1', { amount: 500 }, 'idem-key-3')

    expect(refund.created_at).toBeNull()
    expect(refund.updated_at).toBeNull()
    expect(refund.completed_at).toBeNull()
    expect(refund.failure_reason).toBeNull()
  })

  it('surfaces the server 409 message verbatim for a concurrent/idempotency conflict', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'This idempotency key was already used with a different request.',
          error: { code: 409, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1' },
        },
        409,
      ),
    )

    const { createRefund } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createRefund('o1', { amount: 100 }, 'idem-key-4')
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
    expect((caught as InstanceType<typeof ApiError>).message).toBe(
      'This idempotency key was already used with a different request.',
    )
  })

  it('surfaces a 422 amount-ceiling rejection as a keyed field error (error.details.refund)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_2',
            details: { refund: 'amount: exceeds the remaining refundable balance.' },
          },
        },
        422,
      ),
    )

    const { createRefund } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createRefund('o1', { amount: 999999 }, 'idem-key-5')
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).status).toBe(422)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors.refund).toBe(
      'amount: exceeds the remaining refundable balance.',
    )
  })

  it('surfaces a 422 restock-without-lines rejection under error.details.lines', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_3',
            details: { lines: 'lines: required when restock is requested.' },
          },
        },
        422,
      ),
    )

    const { createRefund } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createRefund('o1', { amount: 100, restock: true }, 'idem-key-6')
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).fieldErrors.lines).toBe(
      'lines: required when restock is requested.',
    )
  })

  it('fetchOrderRefunds GETs the exact per-order endpoint and attaches lines', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Refunds retrieved',
        data: [
          refundBody({
            uuid: 'r1',
            lines: [{ order_line_uuid: 'l1', quantity: 1, amount: 500 }],
          }).data,
        ],
      }),
    )

    const { fetchOrderRefunds } = await import('@/queries/commerceOrders')
    const refunds = await fetchOrderRefunds('o1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('GET')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/orders/o1/refunds')
    expect(refunds).toHaveLength(1)
    expect(refunds[0]!.uuid).toBe('r1')
    expect(refunds[0]!.lines).toEqual([{ order_line_uuid: 'l1', quantity: 1, amount: 500 }])
  })

  it('fetchOrderRefunds defaults to an empty list when the order has none', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Refunds retrieved', data: [] }),
    )

    const { fetchOrderRefunds } = await import('@/queries/commerceOrders')
    const refunds = await fetchOrderRefunds('o1')
    expect(refunds).toEqual([])
  })

  it('fetchOrderRefunds throws ApiError for a 404 order', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchOrderRefunds } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchOrderRefunds('missing')).rejects.toBeInstanceOf(ApiError)
  })

  it('fetchRefunds sends the exact RefundListQuery param set, omitting empty filters', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ data: [], current_page: 1, per_page: 24, total: 0 }),
    )

    const { fetchRefunds } = await import('@/queries/commerceOrders')
    await fetchRefunds({ status: 'completed', order: 'o1', from: '2026-01-01', to: '2026-01-31' })

    const requested = fetchMock.mock.calls[0]![0]
    const url = new URL(typeof requested === 'string' ? requested : (requested as Request).url, 'http://localhost')
    expect(url.pathname).toBe('/v1/admin/commerce/refunds')
    expect(url.searchParams.get('status')).toBe('completed')
    expect(url.searchParams.get('order')).toBe('o1')
    expect(url.searchParams.get('from')).toBe('2026-01-01')
    expect(url.searchParams.get('to')).toBe('2026-01-31')
  })

  it('fetchRefunds omits every filter param entirely when none are set', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 1, per_page: 24, total: 0 }))

    const { fetchRefunds } = await import('@/queries/commerceOrders')
    await fetchRefunds({})

    const requested = fetchMock.mock.calls[0]![0]
    const url = new URL(typeof requested === 'string' ? requested : (requested as Request).url, 'http://localhost')
    expect(url.searchParams.has('status')).toBe(false)
    expect(url.searchParams.has('order')).toBe(false)
    expect(url.searchParams.has('from')).toBe(false)
    expect(url.searchParams.has('to')).toBe(false)
  })

  it('fetchRefunds parses the paginated envelope and normalizes rows without lines (paginatedForTenant never attaches them)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Refunds retrieved',
        data: [refundBody().data],
        current_page: 2,
        per_page: 10,
        total: 15,
      }),
    )

    const { fetchRefunds } = await import('@/queries/commerceOrders')
    const page = await fetchRefunds({ page: 2, perPage: 10 })

    expect(page.refunds).toHaveLength(1)
    expect(page.refunds[0]!.lines).toEqual([])
    expect(page.total).toBe(15)
    expect(page.current_page).toBe(2)
    expect(page.per_page).toBe(10)
  })

  it('fetchRefund GETs the exact show endpoint and normalizes without lines', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(refundBody()))

    const { fetchRefund } = await import('@/queries/commerceOrders')
    const refund = await fetchRefund('r1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('GET')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/refunds/r1')
    expect(refund.uuid).toBe('r1')
    expect(refund.lines).toEqual([])
  })

  it('fetchRefund throws ApiError for a 404 refund', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchRefund } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchRefund('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── Order notes (Task 13d): GET/POST /commerce/orders/{uuid}/notes ─────────────────────────
  // `notes()` filters `eventsForOrder()` to `type === 'note.added'` rows and returns them in the
  // SAME ascending-id (chronological) order as the underlying event query — never re-sorted here.
  // `addNote()`'s 200 response is `{ order_uuid, note: {...} }`, not a full event row (mirrors
  // createRefund()'s "immediate response before the DB fills in its own defaults" precedent) —
  // the fetcher resolves to void; callers rely on invalidation to refetch the notes list.

  function noteEventBody(overrides: Record<string, unknown> = {}) {
    return {
      uuid: 'ev1',
      order_uuid: 'o1',
      type: 'note.added',
      payload: { body: 'Called customer, confirmed address.', visibility: 'internal', notify: false, actor_uuid: 'admin1' },
      actor_uuid: 'admin1',
      visibility: 'internal',
      created_at: '2026-01-02 00:00:00',
      ...overrides,
    }
  }

  it('fetchOrderNotes GETs the exact endpoint and normalizes body/visibility/actor from the event payload', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Notes retrieved', data: [noteEventBody()] }),
    )

    const { fetchOrderNotes } = await import('@/queries/commerceOrders')
    const notes = await fetchOrderNotes('o1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('GET')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/orders/o1/notes')
    expect(notes).toHaveLength(1)
    expect(notes[0]).toEqual({
      uuid: 'ev1',
      body: 'Called customer, confirmed address.',
      visibility: 'internal',
      notify: false,
      actor_uuid: 'admin1',
      created_at: '2026-01-02 00:00:00',
    })
  })

  it('fetchOrderNotes preserves the chronological (ascending) order returned by the server', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Notes retrieved',
        data: [
          noteEventBody({ uuid: 'ev1', payload: { body: 'first', visibility: 'internal', notify: false } }),
          noteEventBody({ uuid: 'ev2', payload: { body: 'second', visibility: 'internal', notify: false } }),
        ],
      }),
    )

    const { fetchOrderNotes } = await import('@/queries/commerceOrders')
    const notes = await fetchOrderNotes('o1')
    expect(notes.map((n) => n.body)).toEqual(['first', 'second'])
  })

  it('fetchOrderNotes defaults to an empty list when the order has no notes', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Notes retrieved', data: [] }),
    )

    const { fetchOrderNotes } = await import('@/queries/commerceOrders')
    expect(await fetchOrderNotes('o1')).toEqual([])
  })

  it('fetchOrderNotes throws ApiError for a 404 order', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchOrderNotes } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchOrderNotes('missing')).rejects.toBeInstanceOf(ApiError)
  })

  it('addOrderNote posts the exact CreateOrderNoteData body, defaulting visibility/notify', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Note added',
        data: { order_uuid: 'o1', note: { body: 'Shipped a day late.', visibility: 'internal', notify: false, actor_uuid: 'admin1' } },
      }),
    )

    const { addOrderNote } = await import('@/queries/commerceOrders')
    await addOrderNote('o1', { body: 'Shipped a day late.' })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/orders/o1/notes')
    expect(await request.clone().json()).toEqual({
      body: 'Shipped a day late.',
      visibility: 'internal',
      notify: false,
    })
  })

  it('addOrderNote forwards an explicit visibility/notify when passed', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Note added',
        data: { order_uuid: 'o1', note: { body: 'Refund issued, notifying customer.', visibility: 'customer', notify: true, actor_uuid: 'admin1' } },
      }),
    )

    const { addOrderNote } = await import('@/queries/commerceOrders')
    await addOrderNote('o1', { body: 'Refund issued, notifying customer.', visibility: 'customer', notify: true })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(await request.clone().json()).toEqual({
      body: 'Refund issued, notifying customer.',
      visibility: 'customer',
      notify: true,
    })
  })

  it('addOrderNote surfaces a 422 notify/visibility mismatch as a keyed field error (error.details.notify)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { notify: 'notify requires visibility to be customer.' },
          },
        },
        422,
      ),
    )

    const { addOrderNote } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await addOrderNote('o1', { body: 'x', visibility: 'internal', notify: true })
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).status).toBe(422)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors.notify).toBe(
      'notify requires visibility to be customer.',
    )
  })

  it('addOrderNote throws ApiError for a 404 order', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { addOrderNote } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    await expect(addOrderNote('missing', { body: 'x' })).rejects.toBeInstanceOf(ApiError)
  })

  // ── Invoice data (Task 13d): GET /commerce/orders/{uuid}/invoice-data ───────────────────────
  // Mirrors InvoiceData::build()'s exact key set field-for-field (Invoices/InvoiceData.php) —
  // `*_minor` amounts stay genuine integers (never coerced through Number()), `refunds` is
  // whatever the server already filtered to completed-only, and `schema_version` is passed through
  // rather than assumed.

  function invoiceDataBody(overrides: Record<string, unknown> = {}) {
    return {
      success: true,
      message: 'Invoice data retrieved',
      data: {
        schema_version: 1,
        seller: { name: 'Acme Supply Co.', address: '1 Market St', tax_id: 'TAX-1' },
        buyer: { email: 'buyer@example.com', addresses: { shipping: { country: 'US' }, billing: null } },
        order: {
          number: 'ORD-2002',
          dates: { placed_at: '2026-01-01 00:00:00', created_at: '2026-01-01 00:00:00', updated_at: null },
          currency: 'USD',
          status: 'paid',
        },
        lines: [
          { name: 'Widget', sku: 'SKU-1', quantity: 2, unit_minor: 1000, subtotal_minor: 2000, addons: [] },
        ],
        totals: {
          subtotal_minor: 2000,
          discount_minor: 0,
          shipping_minor: 500,
          tax_minor: 0,
          grand_minor: 2500,
          refunded_minor: 0,
        },
        refunds: [{ date: '2026-01-15 10:00:00', amount_minor: 500, method: 'original' }],
        ...overrides,
      },
    }
  }

  it('fetchOrderInvoiceData GETs the exact endpoint and normalizes the full payload', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(invoiceDataBody()))

    const { fetchOrderInvoiceData } = await import('@/queries/commerceOrders')
    const invoice = await fetchOrderInvoiceData('o1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('GET')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/orders/o1/invoice-data')

    expect(invoice.schema_version).toBe(1)
    expect(invoice.seller).toEqual({ name: 'Acme Supply Co.', address: '1 Market St', tax_id: 'TAX-1' })
    expect(invoice.buyer.email).toBe('buyer@example.com')
    expect(invoice.buyer.addresses).toEqual({ shipping: { country: 'US' }, billing: null })
    expect(invoice.order).toEqual({
      number: 'ORD-2002',
      dates: { placed_at: '2026-01-01 00:00:00', created_at: '2026-01-01 00:00:00', updated_at: null },
      currency: 'USD',
      status: 'paid',
    })
    expect(invoice.lines).toEqual([
      { name: 'Widget', sku: 'SKU-1', quantity: 2, unit_minor: 1000, subtotal_minor: 2000, addons: [] },
    ])
    expect(invoice.totals).toEqual({
      subtotal_minor: 2000,
      discount_minor: 0,
      shipping_minor: 500,
      tax_minor: 0,
      grand_minor: 2500,
      refunded_minor: 0,
    })
    expect(invoice.refunds).toEqual([{ date: '2026-01-15 10:00:00', amount_minor: 500, method: 'original' }])
    for (const v of Object.values(invoice.totals)) expect(typeof v).toBe('number')
  })

  it('fetchOrderInvoiceData normalizes a null seller identity to present-as-null, never missing', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(invoiceDataBody({ seller: { name: null, address: null, tax_id: null } })),
    )

    const { fetchOrderInvoiceData } = await import('@/queries/commerceOrders')
    const invoice = await fetchOrderInvoiceData('o1')
    expect(invoice.seller).toEqual({ name: null, address: null, tax_id: null })
  })

  it('fetchOrderInvoiceData defaults lines/refunds to empty arrays when absent', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(invoiceDataBody({ lines: [], refunds: [] })),
    )

    const { fetchOrderInvoiceData } = await import('@/queries/commerceOrders')
    const invoice = await fetchOrderInvoiceData('o1')
    expect(invoice.lines).toEqual([])
    expect(invoice.refunds).toEqual([])
  })

  it('fetchOrderInvoiceData throws ApiError for a 404 order', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchOrderInvoiceData } = await import('@/queries/commerceOrders')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchOrderInvoiceData('missing')).rejects.toBeInstanceOf(ApiError)
  })
})
