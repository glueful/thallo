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
// commerceOrders.spec.ts's identical pattern — this suite moved here in Task 8 (this module is
// now the ONE invoice-data query/type implementation, consumed by both the order-detail page's
// formatted modal and the printable invoice/receipt page).
describe('commerce invoice-data query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  // ── Invoice data: GET /commerce/orders/{uuid}/invoice-data ──────────────────────────────────
  // Mirrors InvoiceData::build()'s exact key set field-for-field (Invoices/InvoiceData.php) —
  // `*_minor` amounts stay genuine integers (never coerced through Number()), `refunds` is
  // whatever the server already filtered to completed-only, `schema_version` is passed through
  // rather than assumed, and (commerce v1.9.1, spec §2.6.1) `order.currency_exponent` is the
  // order's OWN historically-correct exponent.

  function invoiceDataBody(overrides: Record<string, unknown> = {}) {
    return {
      success: true,
      message: 'Invoice data retrieved',
      data: {
        schema_version: 2,
        seller: { name: 'Acme Supply Co.', address: '1 Market St', tax_id: 'TAX-1' },
        buyer: { email: 'buyer@example.com', addresses: { shipping: { country: 'US' }, billing: null } },
        order: {
          number: 'ORD-2002',
          dates: { placed_at: '2026-01-01 00:00:00', created_at: '2026-01-01 00:00:00', updated_at: null },
          currency: 'USD',
          currency_exponent: 2,
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

    const { fetchOrderInvoiceData } = await import('@/queries/commerceInvoice')
    const invoice = await fetchOrderInvoiceData('o1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('GET')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/orders/o1/invoice-data')

    expect(invoice.schema_version).toBe(2)
    expect(invoice.seller).toEqual({ name: 'Acme Supply Co.', address: '1 Market St', tax_id: 'TAX-1' })
    expect(invoice.buyer.email).toBe('buyer@example.com')
    expect(invoice.buyer.addresses).toEqual({ shipping: { country: 'US' }, billing: null })
    expect(invoice.order).toEqual({
      number: 'ORD-2002',
      dates: { placed_at: '2026-01-01 00:00:00', created_at: '2026-01-01 00:00:00', updated_at: null },
      currency: 'USD',
      currency_exponent: 2,
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

  it('normalizes order.currency_exponent from the payload, defaulting to 2 only when absent', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(invoiceDataBody({ order: {
      number: 'ORD-JPY',
      dates: { placed_at: '2026-01-01 00:00:00', created_at: '2026-01-01 00:00:00', updated_at: null },
      currency: 'JPY',
      currency_exponent: 0,
      status: 'paid',
    } })))

    const { fetchOrderInvoiceData } = await import('@/queries/commerceInvoice')
    const invoice = await fetchOrderInvoiceData('o1')
    expect(invoice.order.currency).toBe('JPY')
    expect(invoice.order.currency_exponent).toBe(0)
  })

  it('defaults currency_exponent to 2 for a payload predating the field', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse(invoiceDataBody({ order: {
      number: 'ORD-OLD',
      dates: { placed_at: null, created_at: null, updated_at: null },
      currency: 'USD',
      status: 'paid',
    } })))

    const { fetchOrderInvoiceData } = await import('@/queries/commerceInvoice')
    const invoice = await fetchOrderInvoiceData('o1')
    expect(invoice.order.currency_exponent).toBe(2)
  })

  it('fetchOrderInvoiceData normalizes a null seller identity to present-as-null, never missing', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(invoiceDataBody({ seller: { name: null, address: null, tax_id: null } })),
    )

    const { fetchOrderInvoiceData } = await import('@/queries/commerceInvoice')
    const invoice = await fetchOrderInvoiceData('o1')
    expect(invoice.seller).toEqual({ name: null, address: null, tax_id: null })
  })

  it('fetchOrderInvoiceData defaults lines/refunds to empty arrays when absent', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(invoiceDataBody({ lines: [], refunds: [] })),
    )

    const { fetchOrderInvoiceData } = await import('@/queries/commerceInvoice')
    const invoice = await fetchOrderInvoiceData('o1')
    expect(invoice.lines).toEqual([])
    expect(invoice.refunds).toEqual([])
  })

  it('fetchOrderInvoiceData throws ApiError for a 404 order', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchOrderInvoiceData } = await import('@/queries/commerceInvoice')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchOrderInvoiceData('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── One implementation: commerceOrders.ts no longer owns this endpoint (Task 8) ─────────────

  it('commerceOrders.ts no longer exports the invoice-data fetcher/query — commerceInvoice.ts is the ONE implementation', async () => {
    const commerceOrders = await import('@/queries/commerceOrders')
    expect('fetchOrderInvoiceData' in commerceOrders).toBe(false)
    expect('useOrderInvoiceData' in commerceOrders).toBe(false)

    const commerceInvoice = await import('@/queries/commerceInvoice')
    expect(typeof commerceInvoice.fetchOrderInvoiceData).toBe('function')
    expect(typeof commerceInvoice.useOrderInvoiceData).toBe('function')
  })
})
