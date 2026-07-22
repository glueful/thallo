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
// collections.spec.ts.
describe('commerce catalog query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('parses the real Response::paginated envelope and normalizes products', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Products retrieved',
        data: [
          {
            uuid: 'p1',
            slug: 'first-product',
            name: 'First product',
            description: null,
            type: 'physical',
            status: 'active',
            tax_class: null,
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

    const { fetchProducts } = await import('@/queries/commerceCatalog')
    const page = await fetchProducts({ page: 1, perPage: 24 })

    expect(page.products).toHaveLength(1)
    expect(page.products[0]!.uuid).toBe('p1')
    expect(page.products[0]!.slug).toBe('first-product')
    expect(page.products[0]!.variants).toEqual([])
    expect(page.total).toBe(1)
    expect(page.current_page).toBe(1)
    expect(page.per_page).toBe(24)
  })

  it('sends status/type/q/page/per_page as query params, omitting empty filters', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 2, per_page: 10, total: 0 }))

    const { fetchProducts } = await import('@/queries/commerceCatalog')
    await fetchProducts({ status: 'active', type: 'physical', q: 'wid', page: 2, perPage: 10 })

    const requested = fetchMock.mock.calls[0]![0]
    const requestedUrl =
      typeof requested === 'string' ? requested : (requested as Request).url
    const url = new URL(requestedUrl, 'http://localhost')
    expect(url.searchParams.get('status')).toBe('active')
    expect(url.searchParams.get('type')).toBe('physical')
    expect(url.searchParams.get('q')).toBe('wid')
    expect(url.searchParams.get('page')).toBe('2')
    expect(url.searchParams.get('per_page')).toBe('10')
  })

  it('defaults an empty page to zero total and the requested paging', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

    const { fetchProducts } = await import('@/queries/commerceCatalog')
    const page = await fetchProducts({ page: 3, perPage: 50 })

    expect(page.products).toEqual([])
    expect(page.total).toBe(0)
    expect(page.current_page).toBe(3)
    expect(page.per_page).toBe(50)
  })

  it('throws ApiError when the list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchProducts } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchProducts()).rejects.toBeInstanceOf(ApiError)
  })

  it('fetches and normalizes a single product with its variants', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Product retrieved',
        data: {
          uuid: 'p1',
          slug: 'first-product',
          name: 'First product',
          description: 'A thing',
          type: 'physical',
          status: 'active',
          tax_class: null,
          created_at: '2026-01-01 00:00:00',
          updated_at: '2026-01-02 00:00:00',
          variants: [
            {
              uuid: 'v1',
              sku: 'SKU-1',
              price: 1999,
              compare_at_price: null,
              currency: 'USD',
              status: 'active',
              position: 0,
            },
          ],
        },
      }),
    )

    const { fetchProduct } = await import('@/queries/commerceCatalog')
    const product = await fetchProduct('p1')

    expect(product.uuid).toBe('p1')
    expect(product.description).toBe('A thing')
    expect(product.variants).toHaveLength(1)
    expect(product.variants[0]!.price).toBe(1999)
    expect(product.variants[0]!.currency).toBe('USD')
  })

  it('throws ApiError for a 404 product', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { fetchProduct } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchProduct('missing')).rejects.toBeInstanceOf(ApiError)
  })

  it('creates a product and returns the normalized created record', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Product created',
          data: {
            uuid: 'new-1',
            slug: 'new-product',
            name: 'New product',
            description: null,
            type: 'physical',
            status: 'draft',
            tax_class: null,
            created_at: '2026-01-01 00:00:00',
            updated_at: null,
            variants: [
              {
                uuid: 'v-new',
                sku: 'SKU-NEW',
                price: 500,
                compare_at_price: null,
                currency: 'USD',
                status: 'active',
                position: 0,
              },
            ],
          },
        },
        201,
      ),
    )

    const { createProduct } = await import('@/queries/commerceCatalog')
    const product = await createProduct({
      slug: 'new-product',
      name: 'New product',
      variants: [{ sku: 'SKU-NEW', price: 500, currency: 'USD' }],
    })

    expect(product.uuid).toBe('new-1')
    expect(product.variants[0]!.sku).toBe('SKU-NEW')
  })

  it('throws ApiError with field errors on a validation failure', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockImplementation(() =>
      Promise.resolve(
        jsonResponse(
          { success: false, message: 'Validation failed', errors: { slug: ['Slug already in use.'] } },
          422,
        ),
      ),
    )

    const { createProduct } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createProduct({ slug: 'dup', name: 'Dup', variants: [{ sku: 'X', price: 1, currency: 'USD' }] })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      slug: 'Slug already in use.',
    })
  })

  it('updates a product by uuid', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Product updated',
        data: {
          uuid: 'p1',
          slug: 'first-product',
          name: 'Renamed product',
          description: null,
          type: 'physical',
          status: 'active',
          tax_class: null,
          created_at: '2026-01-01 00:00:00',
          updated_at: '2026-01-03 00:00:00',
          variants: [],
        },
      }),
    )

    const { updateProduct } = await import('@/queries/commerceCatalog')
    const product = await updateProduct('p1', { name: 'Renamed product' })
    expect(product.name).toBe('Renamed product')
  })

  it('deletes a product with no return value', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(new Response(null, { status: 204 }))

    const { deleteProduct } = await import('@/queries/commerceCatalog')
    await expect(deleteProduct('p1')).resolves.toBeUndefined()
  })

  it('throws ApiError when delete fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { deleteProduct } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(deleteProduct('missing')).rejects.toBeInstanceOf(ApiError)
  })

  it('bulk-updates product status and reports applied/failed uuids', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Bulk product status update processed',
        data: { applied: ['p1', 'p2'], failed: [{ uuid: 'p3', reason: 'not_found' }] },
      }),
    )

    const { bulkStatusUpdate } = await import('@/queries/commerceCatalog')
    const result = await bulkStatusUpdate(['p1', 'p2', 'p3'], 'active')

    expect(result.applied).toEqual(['p1', 'p2'])
    expect(result.failed).toEqual([{ uuid: 'p3', reason: 'not_found' }])
  })

  it('throws ApiError on a bulk-status validation failure', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: false, message: 'Validation failed', errors: { status: ['bad'] } }, 422),
    )

    const { bulkStatusUpdate } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(bulkStatusUpdate(['p1'], 'bogus')).rejects.toBeInstanceOf(ApiError)
  })
})
