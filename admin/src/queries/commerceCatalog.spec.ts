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
    const requestedUrl = typeof requested === 'string' ? requested : (requested as Request).url
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
          options: { Size: ['S', 'M'] },
          variants: [
            {
              uuid: 'v1',
              sku: 'SKU-1',
              price: 1999,
              compare_at_price: null,
              currency: 'USD',
              status: 'active',
              position: 0,
              option_values: { Size: 'S' },
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
    // Task C7: option axes/values round-trip through normalization untouched.
    expect(product.options).toEqual({ Size: ['S', 'M'] })
    expect(product.variants[0]!.option_values).toEqual({ Size: 'S' })
  })

  it('defaults options/option_values to empty objects when the wire payload omits them', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Product retrieved',
        data: {
          uuid: 'p2',
          slug: 'no-axes',
          name: 'No axes',
          description: null,
          type: 'physical',
          status: 'active',
          tax_class: null,
          created_at: null,
          updated_at: null,
          variants: [
            {
              uuid: 'v1',
              sku: 'SKU-1',
              price: 500,
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
    const product = await fetchProduct('p2')

    expect(product.options).toEqual({})
    expect(product.variants[0]!.option_values).toEqual({})
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
          {
            success: false,
            message: 'Validation failed',
            errors: { slug: ['Slug already in use.'] },
          },
          422,
        ),
      ),
    )

    const { createProduct } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createProduct({
        slug: 'dup',
        name: 'Dup',
        variants: [{ sku: 'X', price: 1, currency: 'USD' }],
      })
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
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 204 }),
    )

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
      jsonResponse(
        { success: false, message: 'Validation failed', errors: { status: ['bad'] } },
        422,
      ),
    )

    const { bulkStatusUpdate } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(bulkStatusUpdate(['p1'], 'bogus')).rejects.toBeInstanceOf(ApiError)
  })

  // ── Task 10b: variant lifecycle, bulk price, children, stock ──────────────────────────────

  it('creates a variant and returns the normalized record', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Variant created',
          data: {
            uuid: 'v2',
            sku: 'SKU-2',
            price: 2500,
            compare_at_price: null,
            currency: 'USD',
            status: 'active',
            position: 1,
          },
        },
        201,
      ),
    )

    const { createProductVariant } = await import('@/queries/commerceCatalog')
    const variant = await createProductVariant('p1', { sku: 'SKU-2', price: 2500, currency: 'USD' })

    expect(variant.uuid).toBe('v2')
    expect(variant.sku).toBe('SKU-2')
    expect(variant.price).toBe(2500)
  })

  it('surfaces the "cannot add variant to a non-purchasable product" constraint from a 422', async () => {
    // Response::validation() shape (Http/Response.php) — the shape AdminProductController's
    // manually-caught ValidationException actually returns for this business rule, distinct
    // from the global handler's top-level `errors` shape used for DTO hydration failures.
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: { product_uuid: "Cannot add variants to a 'grouped' product." },
          },
        },
        422,
      ),
    )

    const { createProductVariant } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createProductVariant('p1', { sku: 'SKU-3', price: 100, currency: 'USD' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      product_uuid: "Cannot add variants to a 'grouped' product.",
    })
  })

  it('updates a variant by uuid', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Variant updated',
        data: {
          uuid: 'v1',
          sku: 'SKU-1',
          price: 3000,
          compare_at_price: null,
          currency: 'USD',
          status: 'active',
          position: 0,
        },
      }),
    )

    const { updateProductVariant } = await import('@/queries/commerceCatalog')
    const variant = await updateProductVariant('v1', { price: 3000 })
    expect(variant.price).toBe(3000)
  })

  it('surfaces a duplicate-SKU constraint from a 422 on variant update', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, details: { sku: 'SKU already in use.' } },
        },
        422,
      ),
    )

    const { updateProductVariant } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await updateProductVariant('v1', { sku: 'TAKEN' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      sku: 'SKU already in use.',
    })
  })

  it('sends the bulk-price request body exactly as { items }', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Bulk variant price update processed',
        data: { applied: ['v1', 'v2'], failed: [] },
      }),
    )

    const { bulkUpdateVariantPrices } = await import('@/queries/commerceCatalog')
    const result = await bulkUpdateVariantPrices([
      { uuid: 'v1', price: 1000 },
      { uuid: 'v2', price: 2000 },
    ])

    expect(result.applied).toEqual(['v1', 'v2'])
    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({
      items: [
        { uuid: 'v1', price: 1000 },
        { uuid: 'v2', price: 2000 },
      ],
    })
  })

  it('throws ApiError on a bulk-price validation failure (hydration-level, top-level errors)', async () => {
    // BulkPriceData/BulkPriceItemData are ValidatesSelf DTOs — their own-request checks (dup
    // uuids, >100 items, negative price) run during hydration and escape uncaught, so THIS
    // failure uses the global handler's top-level `errors` shape, not `error.details`.
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'The given data was invalid.',
          errors: { items: ['items must not contain duplicate uuids.'] },
        },
        422,
      ),
    )

    const { bulkUpdateVariantPrices } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(bulkUpdateVariantPrices([{ uuid: 'v1', price: -5 }])).rejects.toBeInstanceOf(
      ApiError,
    )
  })

  it('sets product children and returns the normalized child products', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Product children updated',
        data: [
          {
            uuid: 'child-1',
            slug: 'child-one',
            name: 'Child One',
            description: null,
            type: 'physical',
            status: 'active',
            tax_class: null,
            created_at: null,
            updated_at: null,
          },
        ],
      }),
    )

    const { setProductChildren } = await import('@/queries/commerceCatalog')
    const children = await setProductChildren('p1', ['child-1'])
    expect(children).toHaveLength(1)
    expect(children[0]!.uuid).toBe('child-1')
  })

  it('sends the children request body exactly as { child_uuids }', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Product children updated', data: [] }),
    )

    const { setProductChildren } = await import('@/queries/commerceCatalog')
    await setProductChildren('p1', ['child-1', 'child-2'])

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ child_uuids: ['child-1', 'child-2'] })
  })

  // Task C1: `expected_revision` is optional on the five replacement mutations — omitted sends
  // today's body byte-for-byte (no `expected_revision` key at all, not even `undefined`);
  // supplied, it rides along on the wire (Global Constraints: "absent ⇒ today's serialize-only
  // behavior byte-for-byte").
  it('omits expected_revision from the children body when not supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { setProductChildren } = await import('@/queries/commerceCatalog')
    await setProductChildren('p1', ['child-1'])

    const req = fetchMock.mock.calls[0]![0] as Request
    const body = await req.clone().json()
    expect(body).toEqual({ child_uuids: ['child-1'] })
    expect('expected_revision' in body).toBe(false)
  })

  it('sends expected_revision in the children body when supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { setProductChildren } = await import('@/queries/commerceCatalog')
    await setProductChildren('p1', ['child-1'], 5)

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ child_uuids: ['child-1'], expected_revision: 5 })
  })

  it('surfaces a stale expected_revision as a 409 with no ApiError field errors', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: false, message: 'Product was modified by another request.' }, 409),
    )

    const { setProductChildren } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setProductChildren('p1', ['child-1'], 1)
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
  })

  it('surfaces the "only grouped products can have children" constraint from a 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, details: { type: 'Only grouped products can have children.' } },
        },
        422,
      ),
    )

    const { setProductChildren } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setProductChildren('p1', ['child-1'])
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      type: 'Only grouped products can have children.',
    })
  })

  it('sends the stock-adjust request body exactly, including reason', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Stock adjusted',
        data: { variant_uuid: 'v1', quantity: 42 },
      }),
    )

    const { adjustVariantStock } = await import('@/queries/commerceCatalog')
    const result = await adjustVariantStock('v1', { delta: -3, reason: 'damaged' })

    expect(result).toEqual({ variant_uuid: 'v1', quantity: 42 })
    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ delta: -3, reason: 'damaged' })
  })

  it('surfaces the "stock cannot go below zero" constraint from a 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, details: { quantity: 'Stock cannot go below zero.' } },
        },
        422,
      ),
    )

    const { adjustVariantStock } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await adjustVariantStock('v1', { delta: -1000, reason: 'adjustment' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      quantity: 'Stock cannot go below zero.',
    })
  })

  // ── Task 10c: media attach/update/detach/reorder ──────────────────────────────────────────

  it('attaches media and returns the normalized record', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Media attached',
          data: {
            uuid: 'm1',
            product_uuid: 'p1',
            variant_uuid: null,
            blob_uuid: 'blob-1',
            role: 'gallery',
            position: 0,
            alt: null,
          },
        },
        201,
      ),
    )

    const { attachProductMedia } = await import('@/queries/commerceCatalog')
    const media = await attachProductMedia('p1', { blob_uuid: 'blob-1' })

    expect(media.uuid).toBe('m1')
    expect(media.blob_uuid).toBe('blob-1')
    expect(media.role).toBe('gallery')
    expect(media.position).toBe(0)
  })

  it('sends the attach request body exactly as given', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Media attached',
        data: {
          uuid: 'm1',
          product_uuid: 'p1',
          variant_uuid: null,
          blob_uuid: 'blob-1',
          role: 'cover',
          position: 0,
          alt: 'Front view',
        },
      }),
    )

    const { attachProductMedia } = await import('@/queries/commerceCatalog')
    await attachProductMedia('p1', { blob_uuid: 'blob-1', role: 'cover', alt: 'Front view' })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({
      blob_uuid: 'blob-1',
      role: 'cover',
      alt: 'Front view',
    })
  })

  it('surfaces the "blob already attached" constraint from a 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: { blob_uuid: 'This blob is already attached to the product.' },
          },
        },
        422,
      ),
    )

    const { attachProductMedia } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await attachProductMedia('p1', { blob_uuid: 'blob-1' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      blob_uuid: 'This blob is already attached to the product.',
    })
  })

  it('updates a media row by uuid', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Media updated',
        data: {
          uuid: 'm1',
          product_uuid: 'p1',
          variant_uuid: null,
          blob_uuid: 'blob-1',
          role: 'cover',
          position: 0,
          alt: 'Updated alt',
        },
      }),
    )

    const { updateProductMedia } = await import('@/queries/commerceCatalog')
    const media = await updateProductMedia('m1', { alt: 'Updated alt', role: 'cover' })
    expect(media.alt).toBe('Updated alt')
    expect(media.role).toBe('cover')
  })

  it('throws ApiError when a media update fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { updateProductMedia } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(updateProductMedia('missing', { alt: 'x' })).rejects.toBeInstanceOf(ApiError)
  })

  it('detaches media with no return value', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 204 }),
    )

    const { detachProductMedia } = await import('@/queries/commerceCatalog')
    await expect(detachProductMedia('m1')).resolves.toBeUndefined()
  })

  it('throws ApiError when detach fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { detachProductMedia } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(detachProductMedia('missing')).rejects.toBeInstanceOf(ApiError)
  })

  it('reorders media and returns the normalized ordered list', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Media reordered',
        data: [
          {
            uuid: 'm2',
            product_uuid: 'p1',
            variant_uuid: null,
            blob_uuid: 'blob-2',
            role: 'gallery',
            position: 0,
            alt: null,
          },
          {
            uuid: 'm1',
            product_uuid: 'p1',
            variant_uuid: null,
            blob_uuid: 'blob-1',
            role: 'cover',
            position: 1,
            alt: null,
          },
        ],
      }),
    )

    const { reorderProductMedia } = await import('@/queries/commerceCatalog')
    const media = await reorderProductMedia('p1', ['m2', 'm1'])
    expect(media.map((m) => m.uuid)).toEqual(['m2', 'm1'])
  })

  it('sends the reorder request body as positions with every uuid and its index', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Media reordered', data: [] }),
    )

    const { reorderProductMedia } = await import('@/queries/commerceCatalog')
    await reorderProductMedia('p1', ['m3', 'm1', 'm2'])

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({
      positions: [
        { uuid: 'm3', position: 0 },
        { uuid: 'm1', position: 1 },
        { uuid: 'm2', position: 2 },
      ],
    })
  })

  it('sends expected_revision in the reorder body when supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { reorderProductMedia } = await import('@/queries/commerceCatalog')
    await reorderProductMedia('p1', ['m1'], 9)

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({
      positions: [{ uuid: 'm1', position: 0 }],
      expected_revision: 9,
    })
  })

  it('omits expected_revision from the reorder body when not supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { reorderProductMedia } = await import('@/queries/commerceCatalog')
    await reorderProductMedia('p1', ['m1'])

    const req = fetchMock.mock.calls[0]![0] as Request
    expect('expected_revision' in (await req.clone().json())).toBe(false)
  })

  it('surfaces the "unknown media item" constraint from a reorder 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: { 'positions.0.uuid': 'Unknown media item for this product.' },
          },
        },
        422,
      ),
    )

    const { reorderProductMedia } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await reorderProductMedia('p1', ['not-attached'])
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      'positions.0.uuid': 'Unknown media item for this product.',
    })
  })

  // ── Task 10d: category CRUD + product assignment ──────────────────────────────────────────

  it('parses the flat, unpaginated category list envelope', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Categories retrieved',
        data: [
          {
            uuid: 'cat1',
            parent_uuid: null,
            slug: 'root',
            name: 'Root',
            description: null,
            position: 0,
          },
          {
            uuid: 'cat2',
            parent_uuid: 'cat1',
            slug: 'child',
            name: 'Child',
            description: 'A child',
            position: 1,
          },
        ],
      }),
    )

    const { fetchCategories } = await import('@/queries/commerceCatalog')
    const categories = await fetchCategories()

    expect(categories).toHaveLength(2)
    expect(categories[0]).toEqual({
      uuid: 'cat1',
      parent_uuid: null,
      slug: 'root',
      name: 'Root',
      description: null,
      position: 0,
    })
    expect(categories[1]!.parent_uuid).toBe('cat1')
  })

  it('defaults to an empty category list when the envelope has no data array', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: null }))

    const { fetchCategories } = await import('@/queries/commerceCatalog')
    await expect(fetchCategories()).resolves.toEqual([])
  })

  it('throws ApiError when the category list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchCategories } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchCategories()).rejects.toBeInstanceOf(ApiError)
  })

  it('creates a category and returns the normalized record', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Category created',
          data: {
            uuid: 'new-cat',
            parent_uuid: null,
            slug: 'new-cat',
            name: 'New Cat',
            description: null,
            position: 0,
          },
        },
        201,
      ),
    )

    const { createCategory } = await import('@/queries/commerceCatalog')
    const category = await createCategory({ slug: 'new-cat', name: 'New Cat' })

    expect(category.uuid).toBe('new-cat')
    expect(category.name).toBe('New Cat')
  })

  it('sends the create-category request body exactly as given', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Category created',
        data: {
          uuid: 'c1',
          parent_uuid: 'root1',
          slug: 'c1',
          name: 'C1',
          description: 'Desc',
          position: 2,
        },
      }),
    )

    const { createCategory } = await import('@/queries/commerceCatalog')
    await createCategory({
      slug: 'c1',
      name: 'C1',
      description: 'Desc',
      parent_uuid: 'root1',
      position: 2,
    })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({
      slug: 'c1',
      name: 'C1',
      description: 'Desc',
      parent_uuid: 'root1',
      position: 2,
    })
  })

  it('surfaces a duplicate-slug constraint from a 422 on category create', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, details: { slug: 'Slug already in use.' } },
        },
        422,
      ),
    )

    const { createCategory } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createCategory({ slug: 'dup', name: 'Dup' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      slug: 'Slug already in use.',
    })
  })

  it('updates a category by uuid', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Category updated',
        data: {
          uuid: 'c1',
          parent_uuid: null,
          slug: 'c1',
          name: 'Renamed',
          description: null,
          position: 0,
        },
      }),
    )

    const { updateCategory } = await import('@/queries/commerceCatalog')
    const category = await updateCategory('c1', { name: 'Renamed' })
    expect(category.name).toBe('Renamed')
  })

  it('surfaces a cycle-depth constraint from a 422 on category update', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: { parent_uuid: 'parent_uuid would create a cycle in the category tree.' },
          },
        },
        422,
      ),
    )

    const { updateCategory } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await updateCategory('c1', { parent_uuid: 'c1' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      parent_uuid: 'parent_uuid would create a cycle in the category tree.',
    })
  })

  it('deletes a category with no return value', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 204 }),
    )

    const { deleteCategory } = await import('@/queries/commerceCatalog')
    await expect(deleteCategory('c1')).resolves.toBeUndefined()
  })

  it('throws ApiError when category delete fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { deleteCategory } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(deleteCategory('missing')).rejects.toBeInstanceOf(ApiError)
  })

  it('sets product categories and returns the normalized attached list', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Product categories updated',
        data: [
          {
            uuid: 'cat1',
            parent_uuid: null,
            slug: 'root',
            name: 'Root',
            description: null,
            position: 0,
          },
        ],
      }),
    )

    const { setProductCategories } = await import('@/queries/commerceCatalog')
    const categories = await setProductCategories('p1', ['cat1'])
    expect(categories).toHaveLength(1)
    expect(categories[0]!.uuid).toBe('cat1')
  })

  it('sends the set-categories request body exactly as { category_uuids }', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Product categories updated', data: [] }),
    )

    const { setProductCategories } = await import('@/queries/commerceCatalog')
    await setProductCategories('p1', ['cat1', 'cat2'])

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ category_uuids: ['cat1', 'cat2'] })
  })

  it('sends expected_revision in the set-categories body when supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { setProductCategories } = await import('@/queries/commerceCatalog')
    await setProductCategories('p1', ['cat1'], 3)

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ category_uuids: ['cat1'], expected_revision: 3 })
  })

  it('omits expected_revision from the set-categories body when not supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { setProductCategories } = await import('@/queries/commerceCatalog')
    await setProductCategories('p1', ['cat1'])

    const req = fetchMock.mock.calls[0]![0] as Request
    expect('expected_revision' in (await req.clone().json())).toBe(false)
  })

  it('surfaces the "must reference existing categories" constraint from a set-categories 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: {
              category_uuids: 'category_uuids must reference existing categories in this tenant.',
            },
          },
        },
        422,
      ),
    )

    const { setProductCategories } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setProductCategories('p1', ['missing'])
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      category_uuids: 'category_uuids must reference existing categories in this tenant.',
    })
  })

  // ── Task 19a: tag CRUD + product assignment ───────────────────────────────────────────────
  // Unlike categories, tags are FLAT (no parent/description/position) and the list IS paginated
  // (`TagRepository::paginatedFor()`), so these mirror both the category CRUD tests AND
  // fetchProducts's paginated-envelope tests.

  it('parses the real Response::paginated envelope and normalizes tags', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Tags retrieved',
        data: [
          { uuid: 'tag1', slug: 'sale', name: 'Sale' },
          { uuid: 'tag2', slug: 'new', name: 'New' },
        ],
        current_page: 2,
        per_page: 10,
        total: 12,
      }),
    )

    const { fetchTags } = await import('@/queries/commerceCatalog')
    const page = await fetchTags({ page: 2, perPage: 10 })

    expect(page.tags).toHaveLength(2)
    expect(page.tags[0]).toEqual({ uuid: 'tag1', slug: 'sale', name: 'Sale' })
    expect(page.total).toBe(12)
    expect(page.current_page).toBe(2)
    expect(page.per_page).toBe(10)
  })

  it('defaults to an empty tag list when the envelope has no data array', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: null }))

    const { fetchTags } = await import('@/queries/commerceCatalog')
    await expect(fetchTags()).resolves.toEqual({
      tags: [],
      total: 0,
      current_page: 1,
      per_page: 24,
    })
  })

  it('sends the q/page/per_page query params exactly as given', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Tags retrieved', data: [] }),
    )

    const { fetchTags } = await import('@/queries/commerceCatalog')
    await fetchTags({ q: 'sal', page: 3, perPage: 50 })

    const req = fetchMock.mock.calls[0]![0] as Request
    const url = new URL(req.url)
    expect(url.searchParams.get('q')).toBe('sal')
    expect(url.searchParams.get('page')).toBe('3')
    expect(url.searchParams.get('per_page')).toBe('50')
  })

  it('throws ApiError when the tag list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchTags } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchTags()).rejects.toBeInstanceOf(ApiError)
  })

  it('creates a tag and returns the normalized record', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Tag created',
          data: { uuid: 'new-tag', slug: 'new-tag', name: 'New Tag' },
        },
        201,
      ),
    )

    const { createTag } = await import('@/queries/commerceCatalog')
    const tag = await createTag({ slug: 'new-tag', name: 'New Tag' })

    expect(tag.uuid).toBe('new-tag')
    expect(tag.name).toBe('New Tag')
  })

  it('sends the create-tag request body exactly as { slug, name }', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Tag created',
        data: { uuid: 't1', slug: 't1', name: 'T1' },
      }),
    )

    const { createTag } = await import('@/queries/commerceCatalog')
    await createTag({ slug: 't1', name: 'T1' })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ slug: 't1', name: 'T1' })
  })

  it('surfaces a duplicate-slug constraint from a 422 on tag create', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, details: { slug: 'Slug already in use.' } },
        },
        422,
      ),
    )

    const { createTag } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createTag({ slug: 'dup', name: 'Dup' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      slug: 'Slug already in use.',
    })
  })

  it('updates a tag by uuid', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Tag updated',
        data: { uuid: 't1', slug: 't1', name: 'Renamed' },
      }),
    )

    const { updateTag } = await import('@/queries/commerceCatalog')
    const tag = await updateTag('t1', { name: 'Renamed' })
    expect(tag.name).toBe('Renamed')
  })

  it('sends the update-tag request body as { name } only — slug is immutable and never sent', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Tag updated',
        data: { uuid: 't1', slug: 't1', name: 'Renamed' },
      }),
    )

    const { updateTag } = await import('@/queries/commerceCatalog')
    await updateTag('t1', { name: 'Renamed' })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ name: 'Renamed' })
  })

  it('surfaces the slug-immutability constraint from a 422 on tag update', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: { slug: 'slug is immutable and cannot be changed after creation.' },
          },
        },
        422,
      ),
    )

    const { updateTag } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await updateTag('t1', { name: 'Renamed' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      slug: 'slug is immutable and cannot be changed after creation.',
    })
  })

  it('deletes a tag with no return value', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 204 }),
    )

    const { deleteTag } = await import('@/queries/commerceCatalog')
    await expect(deleteTag('t1')).resolves.toBeUndefined()
  })

  it('throws ApiError when tag delete fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { deleteTag } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(deleteTag('missing')).rejects.toBeInstanceOf(ApiError)
  })

  it('sets product tags and returns the normalized attached list', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Product tags updated',
        data: [{ uuid: 'tag1', slug: 'sale', name: 'Sale' }],
      }),
    )

    const { setProductTags } = await import('@/queries/commerceCatalog')
    const tags = await setProductTags('p1', ['tag1'])
    expect(tags).toHaveLength(1)
    expect(tags[0]!.uuid).toBe('tag1')
  })

  it('sends the set-tags request body exactly as { tag_uuids }', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Product tags updated', data: [] }),
    )

    const { setProductTags } = await import('@/queries/commerceCatalog')
    await setProductTags('p1', ['tag1', 'tag2'])

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ tag_uuids: ['tag1', 'tag2'] })
  })

  it('sends expected_revision in the set-tags body when supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { setProductTags } = await import('@/queries/commerceCatalog')
    await setProductTags('p1', ['tag1'], 8)

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ tag_uuids: ['tag1'], expected_revision: 8 })
  })

  it('omits expected_revision from the set-tags body when not supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { setProductTags } = await import('@/queries/commerceCatalog')
    await setProductTags('p1', ['tag1'])

    const req = fetchMock.mock.calls[0]![0] as Request
    expect('expected_revision' in (await req.clone().json())).toBe(false)
  })

  it('surfaces the "must reference existing tags" constraint from a set-tags 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: { tag_uuids: 'tag_uuids must reference existing tags in this tenant.' },
          },
        },
        422,
      ),
    )

    const { setProductTags } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setProductTags('p1', ['missing'])
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      tag_uuids: 'tag_uuids must reference existing tags in this tenant.',
    })
  })

  // ── Task 19b: attribute + value CRUD, product attribute assignment ─────────────────────────
  // Attributes are paginated like tags, but each row embeds a `values` sub-collection
  // (`AttributeService::list()` batch-loads them) and — unlike tags — BOTH slug and name stay
  // editable after creation (no immutability trap), so the update tests below assert the FULL
  // form is sent rather than a name-only payload.

  it('parses the real Response::paginated envelope and normalizes attributes with embedded values', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Attributes retrieved',
        data: [
          {
            uuid: 'attr1',
            slug: 'color',
            name: 'Color',
            position: 0,
            values: [
              { uuid: 'val1', slug: 'red', value: 'Red', position: 0 },
              { uuid: 'val2', slug: 'blue', value: 'Blue', position: 1 },
            ],
          },
        ],
        current_page: 2,
        per_page: 10,
        total: 12,
      }),
    )

    const { fetchAttributes } = await import('@/queries/commerceCatalog')
    const page = await fetchAttributes({ page: 2, perPage: 10 })

    expect(page.attributes).toHaveLength(1)
    expect(page.attributes[0]!.uuid).toBe('attr1')
    expect(page.attributes[0]!.values).toEqual([
      { uuid: 'val1', slug: 'red', value: 'Red', position: 0 },
      { uuid: 'val2', slug: 'blue', value: 'Blue', position: 1 },
    ])
    expect(page.total).toBe(12)
    expect(page.current_page).toBe(2)
    expect(page.per_page).toBe(10)
  })

  it('defaults to an empty attribute list when the envelope has no data array', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: null }))

    const { fetchAttributes } = await import('@/queries/commerceCatalog')
    await expect(fetchAttributes()).resolves.toEqual({
      attributes: [],
      total: 0,
      current_page: 1,
      per_page: 24,
    })
  })

  it('defaults an attribute row with no values array to an empty values list', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Attributes retrieved',
        data: [{ uuid: 'attr1', slug: 'color', name: 'Color', position: 0 }],
      }),
    )

    const { fetchAttributes } = await import('@/queries/commerceCatalog')
    const page = await fetchAttributes()
    expect(page.attributes[0]!.values).toEqual([])
  })

  it('sends the q/page/per_page query params exactly as given for attributes', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Attributes retrieved', data: [] }),
    )

    const { fetchAttributes } = await import('@/queries/commerceCatalog')
    await fetchAttributes({ q: 'col', page: 3, perPage: 50 })

    const req = fetchMock.mock.calls[0]![0] as Request
    const url = new URL(req.url)
    expect(url.searchParams.get('q')).toBe('col')
    expect(url.searchParams.get('page')).toBe('3')
    expect(url.searchParams.get('per_page')).toBe('50')
  })

  it('throws ApiError when the attribute list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchAttributes } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchAttributes()).rejects.toBeInstanceOf(ApiError)
  })

  it('creates an attribute and returns the normalized record', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Attribute created',
          data: { uuid: 'new-attr', slug: 'size', name: 'Size', position: 0, values: [] },
        },
        201,
      ),
    )

    const { createAttribute } = await import('@/queries/commerceCatalog')
    const attribute = await createAttribute({ slug: 'size', name: 'Size' })

    expect(attribute.uuid).toBe('new-attr')
    expect(attribute.name).toBe('Size')
    expect(attribute.values).toEqual([])
  })

  it('sends the create-attribute request body exactly as { slug, name, position }', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Attribute created',
        data: { uuid: 'a1', slug: 'a1', name: 'A1', values: [] },
      }),
    )

    const { createAttribute } = await import('@/queries/commerceCatalog')
    await createAttribute({ slug: 'a1', name: 'A1', position: 2 })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ slug: 'a1', name: 'A1', position: 2 })
  })

  it('surfaces a duplicate-slug constraint from a 422 on attribute create', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, details: { slug: 'Slug already in use.' } },
        },
        422,
      ),
    )

    const { createAttribute } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createAttribute({ slug: 'dup', name: 'Dup' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      slug: 'Slug already in use.',
    })
  })

  it('updates an attribute by uuid, sending slug/name/position — attribute slug stays editable', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Attribute updated',
        data: { uuid: 'attr1', slug: 'colour', name: 'Colour', position: 1, values: [] },
      }),
    )

    const { updateAttribute } = await import('@/queries/commerceCatalog')
    const attribute = await updateAttribute('attr1', {
      slug: 'colour',
      name: 'Colour',
      position: 1,
    })
    expect(attribute.name).toBe('Colour')
    expect(attribute.slug).toBe('colour')
  })

  it('sends the update-attribute request body exactly as given, including slug', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Attribute updated',
        data: { uuid: 'attr1', slug: 'colour', name: 'Colour', values: [] },
      }),
    )

    const { updateAttribute } = await import('@/queries/commerceCatalog')
    await updateAttribute('attr1', { slug: 'colour', name: 'Colour', position: 1 })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ slug: 'colour', name: 'Colour', position: 1 })
  })

  it('deletes an attribute with no return value', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 204 }),
    )

    const { deleteAttribute } = await import('@/queries/commerceCatalog')
    await expect(deleteAttribute('attr1')).resolves.toBeUndefined()
  })

  it('throws ApiError when attribute delete fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { deleteAttribute } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(deleteAttribute('missing')).rejects.toBeInstanceOf(ApiError)
  })

  it('creates an attribute value and returns the normalized record', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Attribute value created',
          data: { uuid: 'val1', attribute_uuid: 'attr1', slug: 'red', value: 'Red', position: 0 },
        },
        201,
      ),
    )

    const { createAttributeValue } = await import('@/queries/commerceCatalog')
    const value = await createAttributeValue('attr1', { slug: 'red', value: 'Red' })

    expect(value.uuid).toBe('val1')
    expect(value.value).toBe('Red')
  })

  it('sends the create-value request body exactly as { slug, value, position } to the owning attribute', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Attribute value created',
        data: { uuid: 'val1', attribute_uuid: 'attr1', slug: 'red', value: 'Red', position: 0 },
      }),
    )

    const { createAttributeValue } = await import('@/queries/commerceCatalog')
    await createAttributeValue('attr1', { slug: 'red', value: 'Red', position: 0 })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(req.url).toContain('/commerce/attributes/attr1/values')
    expect(await req.clone().json()).toEqual({ slug: 'red', value: 'Red', position: 0 })
  })

  it('surfaces the composite-conflict "slug already in use for this attribute" 422 on value create', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, details: { slug: 'Slug already in use for this attribute.' } },
        },
        422,
      ),
    )

    const { createAttributeValue } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createAttributeValue('attr1', { slug: 'red', value: 'Red' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      slug: 'Slug already in use for this attribute.',
    })
  })

  it('updates an attribute value by uuid', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Attribute value updated',
        data: { uuid: 'val1', attribute_uuid: 'attr1', slug: 'red', value: 'Crimson', position: 0 },
      }),
    )

    const { updateAttributeValue } = await import('@/queries/commerceCatalog')
    const value = await updateAttributeValue('val1', { value: 'Crimson' })
    expect(value.value).toBe('Crimson')
  })

  it('surfaces the composite-conflict "slug already in use for this attribute" 422 on value update', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, details: { slug: 'Slug already in use for this attribute.' } },
        },
        422,
      ),
    )

    const { updateAttributeValue } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await updateAttributeValue('val1', { slug: 'red' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      slug: 'Slug already in use for this attribute.',
    })
  })

  it('deletes an attribute value with no return value', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 204 }),
    )

    const { deleteAttributeValue } = await import('@/queries/commerceCatalog')
    await expect(deleteAttributeValue('val1')).resolves.toBeUndefined()
  })

  it('throws ApiError when attribute value delete fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { deleteAttributeValue } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(deleteAttributeValue('missing')).rejects.toBeInstanceOf(ApiError)
  })

  it('sets product attributes and returns the normalized attached rows', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Product attributes updated',
        data: [
          {
            uuid: 'pa1',
            product_uuid: 'p1',
            attribute_uuid: 'attr1',
            attribute_slug: 'color',
            attribute_name: 'Color',
            name: null,
            values: ['red'],
            used_for_variants: true,
            visible: true,
            position: 0,
          },
        ],
      }),
    )

    const { setProductAttributes } = await import('@/queries/commerceCatalog')
    const rows = await setProductAttributes('p1', [
      { attribute_uuid: 'attr1', values: ['red'], used_for_variants: true, visible: true },
    ])

    expect(rows).toHaveLength(1)
    expect(rows[0]).toEqual({
      uuid: 'pa1',
      product_uuid: 'p1',
      attribute_uuid: 'attr1',
      attribute_slug: 'color',
      attribute_name: 'Color',
      name: null,
      values: ['red'],
      used_for_variants: true,
      visible: true,
      position: 0,
    })
  })

  it('sends the set-attributes request body exactly as { attributes: [...] }, including custom rows', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Product attributes updated', data: [] }),
    )

    const { setProductAttributes } = await import('@/queries/commerceCatalog')
    await setProductAttributes('p1', [
      { attribute_uuid: 'attr1', values: ['red'], used_for_variants: true, visible: true },
      { name: 'Material', values: ['Cotton', 'Wool'], used_for_variants: false, visible: true },
    ])

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({
      attributes: [
        { attribute_uuid: 'attr1', values: ['red'], used_for_variants: true, visible: true },
        { name: 'Material', values: ['Cotton', 'Wool'], used_for_variants: false, visible: true },
      ],
    })
  })

  it('sends expected_revision in the set-attributes body when supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { setProductAttributes } = await import('@/queries/commerceCatalog')
    await setProductAttributes('p1', [{ attribute_uuid: 'attr1', values: ['red'] }], 2)

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({
      attributes: [{ attribute_uuid: 'attr1', values: ['red'] }],
      expected_revision: 2,
    })
  })

  it('omits expected_revision from the set-attributes body when not supplied', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { setProductAttributes } = await import('@/queries/commerceCatalog')
    await setProductAttributes('p1', [{ attribute_uuid: 'attr1', values: ['red'] }])

    const req = fetchMock.mock.calls[0]![0] as Request
    expect('expected_revision' in (await req.clone().json())).toBe(false)
  })

  it('surfaces the composite-conflict "must not reference the same attribute more than once" 422 on set', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: {
              attributes: 'attributes must not reference the same attribute more than once.',
            },
          },
        },
        422,
      ),
    )

    const { setProductAttributes } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setProductAttributes('p1', [
        { attribute_uuid: 'attr1', values: [] },
        { attribute_uuid: 'attr1', values: [] },
      ])
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      attributes: 'attributes must not reference the same attribute more than once.',
    })
  })

  it('surfaces the "must reference existing attributes" constraint from a set-attributes 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: {
              attributes: 'attributes must reference existing attributes in this tenant.',
            },
          },
        },
        422,
      ),
    )

    const { setProductAttributes } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setProductAttributes('p1', [{ attribute_uuid: 'missing', values: [] }])
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      attributes: 'attributes must reference existing attributes in this tenant.',
    })
  })

  // ── Task 19c: product add-ons — PER-PRODUCT (not a tenant-wide taxonomy like tags/categories/
  // attributes): `GET /commerce/products/{uuid}/addons` IS a real per-product admin read path,
  // unlike every other product-scoped sub-resource above (media/children/tags/categories/
  // attributes assignment, which have none — see `CommerceAddon`'s docblock).

  it('parses the real Response::success envelope and normalizes a mix of checkbox and select add-ons', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Add-ons retrieved',
        data: [
          {
            uuid: 'addon1',
            product_uuid: 'p1',
            name: 'Gift wrap',
            field_type: 'checkbox',
            required: false,
            choices: null,
            price_delta: 300,
            position: 0,
            status: 'active',
          },
          {
            uuid: 'addon2',
            product_uuid: 'p1',
            name: 'Color',
            field_type: 'select',
            required: true,
            choices: [
              { key: 'red', label: 'Red', price_delta: 100 },
              { key: 'blue', label: 'Blue', price_delta: 200 },
            ],
            price_delta: 0,
            position: 1,
            status: 'active',
          },
        ],
      }),
    )

    const { fetchProductAddons } = await import('@/queries/commerceCatalog')
    const rows = await fetchProductAddons('p1')

    expect(rows).toHaveLength(2)
    expect(rows[0]).toEqual({
      uuid: 'addon1',
      product_uuid: 'p1',
      name: 'Gift wrap',
      field_type: 'checkbox',
      required: false,
      choices: null,
      price_delta: 300,
      position: 0,
      status: 'active',
    })
    expect(rows[1]!.choices).toEqual([
      { key: 'red', label: 'Red', price_delta: 100 },
      { key: 'blue', label: 'Blue', price_delta: 200 },
    ])
  })

  it('defaults to an empty add-on list when the envelope has no data array', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: null }))

    const { fetchProductAddons } = await import('@/queries/commerceCatalog')
    await expect(fetchProductAddons('p1')).resolves.toEqual([])
  })

  it('throws ApiError when the add-on list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchProductAddons } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchProductAddons('p1')).rejects.toBeInstanceOf(ApiError)
  })

  it('creates a checkbox add-on and returns the normalized record', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Add-on created',
          data: {
            uuid: 'new-addon',
            product_uuid: 'p1',
            name: 'Gift wrap',
            field_type: 'checkbox',
            required: false,
            choices: null,
            price_delta: 300,
            position: 0,
            status: 'active',
          },
        },
        201,
      ),
    )

    const { createProductAddon } = await import('@/queries/commerceCatalog')
    const addonRow = await createProductAddon('p1', {
      name: 'Gift wrap',
      field_type: 'checkbox',
      price_delta: 300,
    })

    expect(addonRow.uuid).toBe('new-addon')
    expect(addonRow.price_delta).toBe(300)
  })

  it('sends the create-addon request body exactly as given, to the owning product path', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Add-on created',
        data: {
          uuid: 'a1',
          product_uuid: 'p1',
          name: 'Color',
          field_type: 'select',
          required: true,
          choices: [{ key: 'red', label: 'Red', price_delta: 100 }],
          price_delta: 0,
          position: 2,
          status: 'active',
        },
      }),
    )

    const { createProductAddon } = await import('@/queries/commerceCatalog')
    await createProductAddon('p1', {
      name: 'Color',
      field_type: 'select',
      required: true,
      choices: [{ key: 'red', label: 'Red', price_delta: 100 }],
      price_delta: 0,
      position: 2,
      status: 'active',
    })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(req.url).toContain('/commerce/products/p1/addons')
    expect(await req.clone().json()).toEqual({
      name: 'Color',
      field_type: 'select',
      required: true,
      choices: [{ key: 'red', label: 'Red', price_delta: 100 }],
      price_delta: 0,
      position: 2,
      status: 'active',
    })
  })

  it('surfaces "select addons require a non-empty choices list" from a create 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: { choices: 'select addons require a non-empty choices list.' },
          },
        },
        422,
      ),
    )

    const { createProductAddon } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createProductAddon('p1', { name: 'Color', field_type: 'select' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      choices: 'select addons require a non-empty choices list.',
    })
  })

  it('surfaces "product not found" from a create 404', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: false, message: 'Resource not found.' }, 404),
    )

    const { createProductAddon } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(
      createProductAddon('missing', { name: 'Gift wrap', field_type: 'checkbox' }),
    ).rejects.toBeInstanceOf(ApiError)
  })

  it('updates an add-on by uuid and returns the normalized record', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Add-on updated',
        data: {
          uuid: 'a1',
          product_uuid: 'p1',
          name: 'Deluxe gift wrap',
          field_type: 'checkbox',
          required: false,
          choices: null,
          price_delta: 500,
          position: 0,
          status: 'inactive',
        },
      }),
    )

    const { updateProductAddon } = await import('@/queries/commerceCatalog')
    const addonRow = await updateProductAddon('a1', {
      name: 'Deluxe gift wrap',
      price_delta: 500,
      status: 'inactive',
    })
    expect(addonRow.name).toBe('Deluxe gift wrap')
    expect(addonRow.status).toBe('inactive')
  })

  it('sends the update-addon request body exactly as given, to /commerce/addons/{uuid}', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Add-on updated',
        data: {
          uuid: 'a1',
          product_uuid: 'p1',
          name: 'Gift wrap',
          field_type: 'checkbox',
          required: false,
          choices: null,
          price_delta: 350,
          position: 1,
          status: 'active',
        },
      }),
    )

    const { updateProductAddon } = await import('@/queries/commerceCatalog')
    await updateProductAddon('a1', {
      name: 'Gift wrap',
      field_type: 'checkbox',
      required: false,
      choices: null,
      price_delta: 350,
      position: 1,
      status: 'active',
    })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(req.url).toContain('/commerce/addons/a1')
    expect(await req.clone().json()).toEqual({
      name: 'Gift wrap',
      field_type: 'checkbox',
      required: false,
      choices: null,
      price_delta: 350,
      position: 1,
      status: 'active',
    })
  })

  it('surfaces a duplicate-choice-key constraint from an update 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, details: { 'choices.1.key': 'Duplicate choice key.' } },
        },
        422,
      ),
    )

    const { updateProductAddon } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await updateProductAddon('a1', {
        field_type: 'select',
        choices: [
          { key: 'red', label: 'Red', price_delta: 100 },
          { key: 'red', label: 'Crimson', price_delta: 150 },
        ],
      })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      'choices.1.key': 'Duplicate choice key.',
    })
  })

  it('deletes an add-on with no return value', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 204 }),
    )

    const { deleteProductAddon } = await import('@/queries/commerceCatalog')
    await expect(deleteProductAddon('a1')).resolves.toBeUndefined()
  })

  it('throws ApiError when add-on delete fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { deleteProductAddon } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(deleteProductAddon('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── Task 19d: variant downloads — PER-VARIANT (not per-product like add-ons): `GET
  // /commerce/variants/{uuid}/downloads` IS a real per-variant admin read path, same "real GET"
  // reasoning as fetchProductAddons above.

  it('parses the real Response::success envelope and normalizes downloads, ordered by position', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Downloads retrieved',
        data: [
          {
            uuid: 'd1',
            variant_uuid: 'v1',
            blob_uuid: 'blob-1',
            name: 'Ebook (PDF)',
            download_limit: 3,
            expiry_days: 30,
            position: 0,
            status: 'active',
          },
          {
            uuid: 'd2',
            variant_uuid: 'v1',
            blob_uuid: 'blob-2',
            name: 'Bonus chapter',
            download_limit: null,
            expiry_days: null,
            position: 1,
            status: 'inactive',
          },
        ],
      }),
    )

    const { fetchVariantDownloads } = await import('@/queries/commerceCatalog')
    const rows = await fetchVariantDownloads('v1')

    expect(rows).toHaveLength(2)
    expect(rows[0]).toEqual({
      uuid: 'd1',
      variant_uuid: 'v1',
      blob_uuid: 'blob-1',
      name: 'Ebook (PDF)',
      download_limit: 3,
      expiry_days: 30,
      position: 0,
      status: 'active',
    })
    // null download_limit/expiry_days is a REAL value (unlimited/never), preserved exactly.
    expect(rows[1]!.download_limit).toBeNull()
    expect(rows[1]!.expiry_days).toBeNull()
  })

  it('defaults to an empty download list when the envelope has no data array', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: null }))

    const { fetchVariantDownloads } = await import('@/queries/commerceCatalog')
    await expect(fetchVariantDownloads('v1')).resolves.toEqual([])
  })

  it('throws ApiError when the download list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchVariantDownloads } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchVariantDownloads('v1')).rejects.toBeInstanceOf(ApiError)
  })

  it('sends the attach-download request body exactly as given, to the owning variant path', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Download attached',
          data: {
            uuid: 'new-download',
            variant_uuid: 'v1',
            blob_uuid: 'blob-1',
            name: 'Ebook (PDF)',
            download_limit: 3,
            expiry_days: 30,
            position: 0,
            status: 'active',
          },
        },
        201,
      ),
    )

    const { attachVariantDownload } = await import('@/queries/commerceCatalog')
    const row = await attachVariantDownload('v1', {
      blob_uuid: 'blob-1',
      name: 'Ebook (PDF)',
      download_limit: 3,
      expiry_days: 30,
      position: 0,
    })

    expect(row.uuid).toBe('new-download')
    const req = fetchMock.mock.calls[0]![0] as Request
    expect(req.url).toContain('/commerce/variants/v1/downloads')
    expect(await req.clone().json()).toEqual({
      blob_uuid: 'blob-1',
      name: 'Ebook (PDF)',
      download_limit: 3,
      expiry_days: 30,
      position: 0,
    })
  })

  it('surfaces "blob_uuid must reference an existing, active, private blob" from an attach 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            details: { blob_uuid: 'blob_uuid must reference an existing, active, private blob.' },
          },
        },
        422,
      ),
    )

    const { attachVariantDownload } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await attachVariantDownload('v1', { blob_uuid: 'blob-1', name: 'Ebook' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors).toEqual({
      blob_uuid: 'blob_uuid must reference an existing, active, private blob.',
    })
  })

  it('surfaces "variant not found" from an attach 404', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: false, message: 'Resource not found.' }, 404),
    )

    const { attachVariantDownload } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(
      attachVariantDownload('missing', { blob_uuid: 'blob-1', name: 'Ebook' }),
    ).rejects.toBeInstanceOf(ApiError)
  })

  it('sends the update-download request body exactly as given, to /commerce/downloads/{uuid}, and returns the normalized record', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Download updated',
        data: {
          uuid: 'd1',
          variant_uuid: 'v1',
          blob_uuid: 'blob-1',
          name: 'Ebook (2nd edition)',
          download_limit: null,
          expiry_days: null,
          position: 1,
          status: 'inactive',
        },
      }),
    )

    const { updateDownload } = await import('@/queries/commerceCatalog')
    const row = await updateDownload('d1', {
      name: 'Ebook (2nd edition)',
      download_limit: null,
      expiry_days: null,
      position: 1,
      status: 'inactive',
    })

    expect(row.name).toBe('Ebook (2nd edition)')
    expect(row.download_limit).toBeNull()
    expect(row.status).toBe('inactive')

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(req.url).toContain('/commerce/downloads/d1')
    expect(req.method).toBe('PATCH')
    // Explicit nulls for download_limit/expiry_days are REAL values (unlimited/never) and must be
    // sent, not omitted (the backend distinguishes an absent key from an explicit null).
    expect(await req.clone().json()).toEqual({
      name: 'Ebook (2nd edition)',
      download_limit: null,
      expiry_days: null,
      position: 1,
      status: 'inactive',
    })
  })

  it('deletes a download with no return value', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 204 }),
    )

    const { deleteDownload } = await import('@/queries/commerceCatalog')
    await expect(deleteDownload('d1')).resolves.toBeUndefined()
  })

  it('throws ApiError when download delete fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Not found' }, 404),
    )

    const { deleteDownload } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    await expect(deleteDownload('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── Task 19d: grants — STAGED plumbing (see CommerceGrant's docblock in commerceCatalog.ts):
  // there is no admin listing endpoint for a grant, so these three mutations are never wired into
  // a component, but the query layer against the real, shipped endpoints is still pinned exactly.

  it('revokeGrant POSTs to /commerce/grants/{uuid}/revoke with no body and returns the normalized projection', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Grant revoked',
        data: {
          grant_uuid: 'g1',
          order_uuid: 'o1',
          name: 'Ebook (PDF)',
          remaining: 2,
          expires_at: '2026-02-01 00:00:00',
          mint_count: 1,
          last_minted_at: '2026-01-15 00:00:00',
          revoked_at: '2026-01-20 00:00:00',
          refund_access_override_at: null,
          refund_access_override_by: null,
        },
      }),
    )

    const { revokeGrant } = await import('@/queries/commerceCatalog')
    const grant = await revokeGrant('g1')

    expect(grant.grant_uuid).toBe('g1')
    expect(grant.revoked_at).toBe('2026-01-20 00:00:00')
    const req = fetchMock.mock.calls[0]![0] as Request
    expect(req.method).toBe('POST')
    expect(req.url).toContain('/commerce/grants/g1/revoke')
    expect(await req.clone().text()).toBe('')
  })

  it('surfaces a 409 when revoking an already-revoked grant', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: false, message: 'Grant is already revoked.' }, 409),
    )

    const { revokeGrant } = await import('@/queries/commerceCatalog')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await revokeGrant('g1')
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).message).toBe('Grant is already revoked.')
  })

  it('setGrantRefundOverride PUTs to /commerce/grants/{uuid}/refund-access-override with no body', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Override set',
        data: {
          grant_uuid: 'g1',
          order_uuid: 'o1',
          name: 'Ebook (PDF)',
          remaining: 2,
          expires_at: null,
          mint_count: 1,
          last_minted_at: null,
          revoked_at: null,
          refund_access_override_at: '2026-01-20 00:00:00',
          refund_access_override_by: 'admin1',
        },
      }),
    )

    const { setGrantRefundOverride } = await import('@/queries/commerceCatalog')
    const grant = await setGrantRefundOverride('g1')

    expect(grant.refund_access_override_by).toBe('admin1')
    const req = fetchMock.mock.calls[0]![0] as Request
    expect(req.method).toBe('PUT')
    expect(req.url).toContain('/commerce/grants/g1/refund-access-override')
  })

  it('clearGrantRefundOverride DELETEs to /commerce/grants/{uuid}/refund-access-override', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Override cleared',
        data: {
          grant_uuid: 'g1',
          order_uuid: 'o1',
          name: 'Ebook (PDF)',
          remaining: 2,
          expires_at: null,
          mint_count: 1,
          last_minted_at: null,
          revoked_at: null,
          refund_access_override_at: null,
          refund_access_override_by: null,
        },
      }),
    )

    const { clearGrantRefundOverride } = await import('@/queries/commerceCatalog')
    const grant = await clearGrantRefundOverride('g1')

    expect(grant.refund_access_override_at).toBeNull()
    const req = fetchMock.mock.calls[0]![0] as Request
    expect(req.method).toBe('DELETE')
    expect(req.url).toContain('/commerce/grants/g1/refund-access-override')
  })
})
