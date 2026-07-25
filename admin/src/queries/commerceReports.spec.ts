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
// commerceCustomers.spec.ts/commerceOrders.spec.ts.
describe('commerce reports query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  function requestedUrl(fetchMock: ReturnType<typeof vi.fn>): URL {
    const requested = fetchMock.mock.calls[0]![0]
    return new URL(typeof requested === 'string' ? requested : (requested as Request).url, 'http://localhost')
  }

  // ── fetchCommerceReportSales: envelope, params ────────────────────────────────────────────────

  describe('fetchCommerceReportSales', () => {
    it('parses the real Response::success envelope and normalizes every summary/series field', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          success: true,
          message: 'Sales report retrieved',
          data: {
            currency: 'USD',
            window: { from: '2026-06-24', to: '2026-07-22', group: 'day' },
            summary: {
              gross_minor: 100000,
              refunds_minor: 5000,
              net_minor: 95000,
              orders_count: 40,
              aov_minor: 2500,
              pending_orders: 3,
              discount_minor: 1200,
              shipping_minor: 800,
              tax_minor: 900,
            },
            series: [
              {
                bucket: '2026-07-22',
                gross_minor: 5000,
                refunds_minor: 0,
                net_minor: 5000,
                orders_count: 2,
                aov_minor: 2500,
              },
            ],
          },
        }),
      )

      const { fetchCommerceReportSales } = await import('@/queries/commerceReports')
      const report = await fetchCommerceReportSales({ from: '2026-06-24', to: '2026-07-22' })

      expect(report.currency).toBe('USD')
      expect(report.window).toEqual({ from: '2026-06-24', to: '2026-07-22', group: 'day' })
      expect(report.summary).toEqual({
        gross_minor: 100000,
        refunds_minor: 5000,
        net_minor: 95000,
        orders_count: 40,
        aov_minor: 2500,
        pending_orders: 3,
        discount_minor: 1200,
        shipping_minor: 800,
        tax_minor: 900,
      })
      expect(report.series).toHaveLength(1)
      expect(report.series[0]!.bucket).toBe('2026-07-22')
    })

    it('sends from/to/group as query params, omitting absent ones', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: {} }))

      const { fetchCommerceReportSales } = await import('@/queries/commerceReports')
      await fetchCommerceReportSales({ from: '2026-06-24', to: '2026-07-22', group: 'week' })

      const url = requestedUrl(fetchMock)
      expect(url.pathname).toBe('/v1/admin/commerce/reports/sales')
      expect(url.searchParams.get('from')).toBe('2026-06-24')
      expect(url.searchParams.get('to')).toBe('2026-07-22')
      expect(url.searchParams.get('group')).toBe('week')
    })

    it('omits from/to/group entirely when none are set', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: {} }))

      const { fetchCommerceReportSales } = await import('@/queries/commerceReports')
      await fetchCommerceReportSales()

      const url = requestedUrl(fetchMock)
      expect(url.searchParams.has('from')).toBe(false)
      expect(url.searchParams.has('to')).toBe(false)
      expect(url.searchParams.has('group')).toBe(false)
    })

    it('defaults every field on a bare/empty data payload', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

      const { fetchCommerceReportSales } = await import('@/queries/commerceReports')
      const report = await fetchCommerceReportSales()

      expect(report.currency).toBe('USD')
      expect(report.window).toEqual({ from: '', to: '', group: 'day' })
      expect(report.summary.gross_minor).toBe(0)
      expect(report.summary.pending_orders).toBe(0)
      expect(report.series).toEqual([])
    })

    it('never coerces amounts through Number() — a malformed gross_minor becomes the neutral 0 fallback', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({ data: { summary: { gross_minor: '100000', net_minor: null } } }),
      )

      const { fetchCommerceReportSales } = await import('@/queries/commerceReports')
      const report = await fetchCommerceReportSales()
      expect(report.summary.gross_minor).toBe(0)
      expect(report.summary.net_minor).toBe(0)
    })

    it('throws ApiError on failure', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({ message: 'Validation failed' }, 422),
      )

      const { fetchCommerceReportSales } = await import('@/queries/commerceReports')
      const { ApiError } = await import('@/api/errors')
      await expect(fetchCommerceReportSales()).rejects.toBeInstanceOf(ApiError)
    })
  })

  // ── fetchCommerceReportProducts: envelope, params ─────────────────────────────────────────────

  describe('fetchCommerceReportProducts', () => {
    function productBody(overrides: Record<string, unknown> = {}) {
      return {
        variant_uuid: 'var1',
        sku: 'SKU-1',
        product_name: 'Widget',
        quantity: 5,
        revenue_minor: 10000,
        attributed_refunded_minor: 500,
        attributed_refunded_quantity: 1,
        ...overrides,
      }
    }

    it('parses the real Response::paginated envelope and normalizes items', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          success: true,
          message: 'Product report retrieved',
          data: [productBody()],
          current_page: 1,
          per_page: 10,
          total: 1,
        }),
      )

      const { fetchCommerceReportProducts } = await import('@/queries/commerceReports')
      const page = await fetchCommerceReportProducts({ page: 1, perPage: 10 })

      expect(page.items).toHaveLength(1)
      expect(page.items[0]!.variant_uuid).toBe('var1')
      expect(page.items[0]!.revenue_minor).toBe(10000)
      expect(page.total).toBe(1)
      expect(page.current_page).toBe(1)
      expect(page.per_page).toBe(10)
    })

    it('sends from/to/sort/page/per_page as query params (the exact ProductsReportQuery shape)', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 1, per_page: 10, total: 0 }))

      const { fetchCommerceReportProducts } = await import('@/queries/commerceReports')
      await fetchCommerceReportProducts({
        from: '2026-06-24',
        to: '2026-07-22',
        sort: 'revenue',
        page: 1,
        perPage: 10,
      })

      const url = requestedUrl(fetchMock)
      expect(url.pathname).toBe('/v1/admin/commerce/reports/products')
      expect(url.searchParams.get('from')).toBe('2026-06-24')
      expect(url.searchParams.get('to')).toBe('2026-07-22')
      expect(url.searchParams.get('sort')).toBe('revenue')
      expect(url.searchParams.get('page')).toBe('1')
      expect(url.searchParams.get('per_page')).toBe('10')
    })

    it('defaults an empty page to zero total and the requested paging', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

      const { fetchCommerceReportProducts } = await import('@/queries/commerceReports')
      const page = await fetchCommerceReportProducts({ page: 2, perPage: 20 })

      expect(page.items).toEqual([])
      expect(page.total).toBe(0)
      expect(page.current_page).toBe(2)
      expect(page.per_page).toBe(20)
    })

    it('never coerces amounts through Number() — a malformed revenue_minor becomes the neutral 0 fallback', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          data: [productBody({ revenue_minor: '10000', attributed_refunded_minor: null })],
          current_page: 1,
          per_page: 10,
          total: 1,
        }),
      )

      const { fetchCommerceReportProducts } = await import('@/queries/commerceReports')
      const page = await fetchCommerceReportProducts()
      expect(page.items[0]!.revenue_minor).toBe(0)
      expect(page.items[0]!.attributed_refunded_minor).toBe(0)
    })

    it('throws ApiError on failure', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ message: 'Forbidden' }, 403))

      const { fetchCommerceReportProducts } = await import('@/queries/commerceReports')
      const { ApiError } = await import('@/api/errors')
      await expect(fetchCommerceReportProducts()).rejects.toBeInstanceOf(ApiError)
    })
  })

  // ── fetchCommerceReportCustomers: envelope, params ────────────────────────────────────────────

  describe('fetchCommerceReportCustomers', () => {
    it('parses the real Response::success envelope and normalizes counts (no money fields at all)', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          success: true,
          message: 'Customer report retrieved',
          data: {
            window: { from: '2026-06-24', to: '2026-07-22', group: 'day' },
            summary: { new_customers: 12, returning_customers: 8, total_customers: 20 },
            series: [{ bucket: '2026-07-22', new_customers: 1, returning_customers: 0 }],
          },
        }),
      )

      const { fetchCommerceReportCustomers } = await import('@/queries/commerceReports')
      const report = await fetchCommerceReportCustomers({ from: '2026-06-24', to: '2026-07-22' })

      expect(report.window).toEqual({ from: '2026-06-24', to: '2026-07-22', group: 'day' })
      expect(report.summary).toEqual({ new_customers: 12, returning_customers: 8, total_customers: 20 })
      expect(report.series).toHaveLength(1)
      expect(report).not.toHaveProperty('currency')
    })

    it('sends from/to/group as query params', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: {} }))

      const { fetchCommerceReportCustomers } = await import('@/queries/commerceReports')
      await fetchCommerceReportCustomers({ from: '2026-06-24', to: '2026-07-22', group: 'month' })

      const url = requestedUrl(fetchMock)
      expect(url.pathname).toBe('/v1/admin/commerce/reports/customers')
      expect(url.searchParams.get('from')).toBe('2026-06-24')
      expect(url.searchParams.get('to')).toBe('2026-07-22')
      expect(url.searchParams.get('group')).toBe('month')
    })

    it('defaults every field on a bare/empty data payload', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

      const { fetchCommerceReportCustomers } = await import('@/queries/commerceReports')
      const report = await fetchCommerceReportCustomers()

      expect(report.window).toEqual({ from: '', to: '', group: 'day' })
      expect(report.summary).toEqual({ new_customers: 0, returning_customers: 0, total_customers: 0 })
      expect(report.series).toEqual([])
    })

    it('throws ApiError on failure', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ message: 'Forbidden' }, 403))

      const { fetchCommerceReportCustomers } = await import('@/queries/commerceReports')
      const { ApiError } = await import('@/api/errors')
      await expect(fetchCommerceReportCustomers()).rejects.toBeInstanceOf(ApiError)
    })
  })

  // ── fetchCommerceReportStock: envelope, params ────────────────────────────────────────────────

  describe('fetchCommerceReportStock', () => {
    function stockBody(overrides: Record<string, unknown> = {}) {
      return {
        variant_uuid: 'var1',
        sku: 'SKU-1',
        product_name: 'Widget',
        quantity: 2,
        status: 'low_stock',
        threshold: 3,
        ...overrides,
      }
    }

    it('parses the real Response::paginated envelope and normalizes items', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({
          success: true,
          message: 'Stock report retrieved',
          data: [stockBody(), stockBody({ variant_uuid: 'var2', quantity: 0, status: 'out_of_stock' })],
          current_page: 1,
          per_page: 10,
          total: 2,
        }),
      )

      const { fetchCommerceReportStock } = await import('@/queries/commerceReports')
      const page = await fetchCommerceReportStock({ page: 1, perPage: 10 })

      expect(page.items).toHaveLength(2)
      expect(page.items[0]!.status).toBe('low_stock')
      expect(page.items[1]!.status).toBe('out_of_stock')
      expect(page.items[0]!.threshold).toBe(3)
      expect(page.total).toBe(2)
    })

    it('sends status/threshold/page/per_page as query params, omitting absent ones', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 1, per_page: 10, total: 0 }))

      const { fetchCommerceReportStock } = await import('@/queries/commerceReports')
      await fetchCommerceReportStock({ status: 'out_of_stock', threshold: 5, page: 1, perPage: 10 })

      const url = requestedUrl(fetchMock)
      expect(url.pathname).toBe('/v1/admin/commerce/reports/stock')
      expect(url.searchParams.get('status')).toBe('out_of_stock')
      expect(url.searchParams.get('threshold')).toBe('5')
      expect(url.searchParams.get('page')).toBe('1')
      expect(url.searchParams.get('per_page')).toBe('10')
    })

    it('omits status/threshold entirely when neither is set (point-in-time, no from/to ever sent)', async () => {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
      fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 1, per_page: 10, total: 0 }))

      const { fetchCommerceReportStock } = await import('@/queries/commerceReports')
      await fetchCommerceReportStock()

      const url = requestedUrl(fetchMock)
      expect(url.searchParams.has('status')).toBe(false)
      expect(url.searchParams.has('threshold')).toBe(false)
      expect(url.searchParams.has('from')).toBe(false)
      expect(url.searchParams.has('to')).toBe(false)
    })

    it('normalizes an unrecognized status to the low_stock fallback', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
        jsonResponse({ data: [stockBody({ status: 'weird' })], current_page: 1, per_page: 10, total: 1 }),
      )

      const { fetchCommerceReportStock } = await import('@/queries/commerceReports')
      const page = await fetchCommerceReportStock()
      expect(page.items[0]!.status).toBe('low_stock')
    })

    it('defaults an empty page to zero total and the requested paging', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

      const { fetchCommerceReportStock } = await import('@/queries/commerceReports')
      const page = await fetchCommerceReportStock({ page: 3, perPage: 50 })

      expect(page.items).toEqual([])
      expect(page.total).toBe(0)
      expect(page.current_page).toBe(3)
      expect(page.per_page).toBe(50)
    })

    it('throws ApiError on failure', async () => {
      ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ message: 'Forbidden' }, 403))

      const { fetchCommerceReportStock } = await import('@/queries/commerceReports')
      const { ApiError } = await import('@/api/errors')
      await expect(fetchCommerceReportStock()).rejects.toBeInstanceOf(ApiError)
    })
  })
})
