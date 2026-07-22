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
// commerceDiscounts.spec.ts/commerceOrders.spec.ts.
describe('commerce reviews query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  function reviewBody(overrides: Record<string, unknown> = {}) {
    return {
      uuid: 'r1',
      product_uuid: 'p1',
      user_uuid: null,
      author_name: 'Jane Doe',
      author_email: 'jane@example.com',
      rating: 5,
      body: 'Great product, would buy again.',
      status: 'pending',
      created_at: '2026-01-01 00:00:00',
      updated_at: null,
      ...overrides,
    }
  }

  // ── fetchReviews: envelope, filters, pagination ─────────────────────────────────────────────

  it('parses the real Response::paginated envelope and normalizes reviews', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Reviews retrieved',
        data: [reviewBody()],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchReviews } = await import('@/queries/commerceReviews')
    const page = await fetchReviews({ page: 1, perPage: 24 })

    expect(page.reviews).toHaveLength(1)
    expect(page.reviews[0]!.uuid).toBe('r1')
    expect(page.reviews[0]!.author_name).toBe('Jane Doe')
    expect(page.reviews[0]!.rating).toBe(5)
    expect(page.reviews[0]!.status).toBe('pending')
    expect(page.total).toBe(1)
    expect(page.current_page).toBe(1)
    expect(page.per_page).toBe(24)
  })

  it('sends status/product/page/per_page as query params, omitting empty filters (the exact ReviewListQuery shape)', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 2, per_page: 10, total: 0 }))

    const { fetchReviews } = await import('@/queries/commerceReviews')
    await fetchReviews({ status: 'pending', product: 'p1', page: 2, perPage: 10 })

    const requested = fetchMock.mock.calls[0]![0]
    const requestedUrl = typeof requested === 'string' ? requested : (requested as Request).url
    const url = new URL(requestedUrl, 'http://localhost')
    expect(url.pathname).toBe('/v1/admin/commerce/reviews')
    expect(url.searchParams.get('status')).toBe('pending')
    expect(url.searchParams.get('product')).toBe('p1')
    expect(url.searchParams.get('page')).toBe('2')
    expect(url.searchParams.get('per_page')).toBe('10')
  })

  it('omits status/product entirely when no filter is set', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 1, per_page: 24, total: 0 }))

    const { fetchReviews } = await import('@/queries/commerceReviews')
    await fetchReviews({})

    const requested = fetchMock.mock.calls[0]![0]
    const url = new URL(typeof requested === 'string' ? requested : (requested as Request).url, 'http://localhost')
    expect(url.searchParams.has('status')).toBe(false)
    expect(url.searchParams.has('product')).toBe(false)
  })

  it('defaults an empty page to zero total and the requested paging', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

    const { fetchReviews } = await import('@/queries/commerceReviews')
    const page = await fetchReviews({ page: 3, perPage: 50 })

    expect(page.reviews).toEqual([])
    expect(page.total).toBe(0)
    expect(page.current_page).toBe(3)
    expect(page.per_page).toBe(50)
  })

  it('throws ApiError when the list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchReviews } = await import('@/queries/commerceReviews')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchReviews()).rejects.toBeInstanceOf(ApiError)
  })

  // ── normalization: strict types, no Number() coercion of the rating ─────────────────────────

  it('normalizes a review with a known user_uuid and approved status', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Reviews retrieved',
        data: [reviewBody({ uuid: 'r2', user_uuid: 'u1', status: 'approved', rating: 4 })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchReviews } = await import('@/queries/commerceReviews')
    const page = await fetchReviews()
    const r = page.reviews[0]!

    expect(r.user_uuid).toBe('u1')
    expect(r.status).toBe('approved')
    expect(r.rating).toBe(4)
  })

  it('normalizes a review with no user_uuid to null', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Reviews retrieved',
        data: [reviewBody({ uuid: 'r3' })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchReviews } = await import('@/queries/commerceReviews')
    const page = await fetchReviews()
    expect(page.reviews[0]!.user_uuid).toBeNull()
  })

  it('never coerces the rating through Number() — a malformed value becomes the neutral 0 fallback', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Reviews retrieved',
        data: [reviewBody({ uuid: 'r4', rating: '5' })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchReviews } = await import('@/queries/commerceReviews')
    const page = await fetchReviews()
    expect(page.reviews[0]!.rating).toBe(0)
  })

  // ── fetchReview (show) ───────────────────────────────────────────────────────────────────────

  it('fetches and normalizes a single review', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Review retrieved', data: reviewBody({ uuid: 'r1' }) }),
    )

    const { fetchReview } = await import('@/queries/commerceReviews')
    const review = await fetchReview('r1')

    expect(review.uuid).toBe('r1')
    expect(review.author_name).toBe('Jane Doe')
  })

  it('throws ApiError for a 404 review', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchReview } = await import('@/queries/commerceReviews')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchReview('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── createReview: exact CreateReviewData body ───────────────────────────────────────────────

  it('createReview posts the exact CreateReviewData body and normalizes the created review', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Review created', data: reviewBody({ uuid: 'r5' }) }, 201),
    )

    const { createReview } = await import('@/queries/commerceReviews')
    const review = await createReview({
      product_uuid: 'p1',
      rating: 5,
      body: 'Great product, would buy again.',
      author_name: 'Jane Doe',
      author_email: 'jane@example.com',
    })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/reviews')
    expect(await request.clone().json()).toEqual({
      product_uuid: 'p1',
      rating: 5,
      body: 'Great product, would buy again.',
      author_name: 'Jane Doe',
      author_email: 'jane@example.com',
      user_uuid: null,
    })
    expect(review.uuid).toBe('r5')
  })

  it('createReview surfaces a 422 unknown-product rejection as a keyed field error', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { product_uuid: 'product_uuid must reference an existing product.' },
          },
        },
        422,
      ),
    )

    const { createReview } = await import('@/queries/commerceReviews')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createReview({
        product_uuid: 'no-such-product',
        rating: 5,
        body: 'x',
        author_name: 'Jane',
        author_email: 'jane@example.com',
      })
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).status).toBe(422)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors.product_uuid).toBe(
      'product_uuid must reference an existing product.',
    )
  })

  // ── approveReview / spamReview: exact endpoints, no body ────────────────────────────────────

  it('approveReview POSTs the exact endpoint and normalizes the approved review', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Review approved', data: reviewBody({ uuid: 'r1', status: 'approved' }) }),
    )

    const { approveReview } = await import('@/queries/commerceReviews')
    const review = await approveReview('r1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/reviews/r1/approve')
    expect(review.status).toBe('approved')
  })

  it('approveReview surfaces the 409 "not pending" message verbatim', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: "Review status is 'approved'; expected pending.",
          error: { code: 409, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1' },
        },
        409,
      ),
    )

    const { approveReview } = await import('@/queries/commerceReviews')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await approveReview('r1')
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
    expect((caught as InstanceType<typeof ApiError>).message).toBe("Review status is 'approved'; expected pending.")
  })

  it('spamReview POSTs the exact endpoint and normalizes the spammed review', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Review marked as spam', data: reviewBody({ uuid: 'r1', status: 'spam' }) }),
    )

    const { spamReview } = await import('@/queries/commerceReviews')
    const review = await spamReview('r1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/reviews/r1/spam')
    expect(review.status).toBe('spam')
  })

  it('spamReview surfaces the 409 "already spam" message verbatim', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: "Review status is 'spam'; expected pending or approved.",
          error: { code: 409, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1' },
        },
        409,
      ),
    )

    const { spamReview } = await import('@/queries/commerceReviews')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await spamReview('r1')
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
  })

  // ── deleteReview: exact DELETE endpoint + the approved-review 404 ──────────────────────────

  it('deleteReview DELETEs the exact endpoint', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    const { deleteReview } = await import('@/queries/commerceReviews')
    await deleteReview('r1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('DELETE')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/reviews/r1')
  })

  it('deleteReview surfaces the 404 for an approved review (non-revealing, same as unknown)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { deleteReview } = await import('@/queries/commerceReviews')
    const { ApiError } = await import('@/api/errors')
    await expect(deleteReview('r1')).rejects.toBeInstanceOf(ApiError)
  })

  // ── bulkModerateReviews: exact BulkReviewData body ──────────────────────────────────────────

  it('bulkModerateReviews posts the exact {action, uuids} body and returns applied/failed', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Bulk review moderation processed',
        data: { applied: ['r1', 'r2'], failed: [{ uuid: 'r3', reason: 'not_found' }] },
      }),
    )

    const { bulkModerateReviews } = await import('@/queries/commerceReviews')
    const result = await bulkModerateReviews('approve', ['r1', 'r2', 'r3'])

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/reviews/bulk')
    expect(await request.clone().json()).toEqual({ action: 'approve', uuids: ['r1', 'r2', 'r3'] })
    expect(result).toEqual({ applied: ['r1', 'r2'], failed: [{ uuid: 'r3', reason: 'not_found' }] })
  })

  it('bulkModerateReviews defaults applied/failed to empty arrays when the envelope carries none', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

    const { bulkModerateReviews } = await import('@/queries/commerceReviews')
    const result = await bulkModerateReviews('delete', ['r1'])

    expect(result).toEqual({ applied: [], failed: [] })
  })

  it('throws ApiError on a bulk validation failure (e.g. >100 uuids)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Validation failed' }, 422),
    )

    const { bulkModerateReviews } = await import('@/queries/commerceReviews')
    const { ApiError } = await import('@/api/errors')
    await expect(bulkModerateReviews('approve', ['r1'])).rejects.toBeInstanceOf(ApiError)
  })
})
