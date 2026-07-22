import { describe, it, expect, vi, beforeEach } from 'vitest'

// Pins the Task 16 invalidation contract: `create` invalidates only the list (a brand-new review
// has no existing detail-key consumer yet); `approve`/`spam`/`remove` invalidate BOTH the single
// review key and the list (each changes `status`, a field the list itself renders); `bulk`
// invalidates ONLY the list, never N individual detail keys — mirrors
// useCommerceProductMutations()'s `bulkStatus` (commerceCatalogInvalidation.spec.ts precedent). The
// colada layer is mocked so each mutation's onSettled can be driven directly (mirrors
// commerceDiscountsInvalidation.spec.ts).

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

const cacheInvalidate = vi.hoisted(() => vi.fn())
vi.mock('@pinia/colada', () => ({
  useQueryCache: () => ({ invalidateQueries: cacheInvalidate }),
  useQuery: () => ({ data: { value: undefined }, status: { value: 'idle' } }),
  useMutation: (options: { onSettled?: () => void }) => ({ mutate: vi.fn(), ...options }),
}))

describe('useCommerceReviewMutations invalidation', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
  })

  async function bundle() {
    const { useCommerceReviewMutations } = await import('@/queries/commerceReviews')
    const { qk } = await import('@/queries/keys')
    const mutations = useCommerceReviewMutations() as unknown as Record<
      'create' | 'approve' | 'spam' | 'remove' | 'bulk',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  it('create invalidates ONLY the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.create.onSettled?.(undefined, undefined, {
      product_uuid: 'p1',
      rating: 5,
      body: 'Great!',
      author_name: 'Jane',
      author_email: 'jane@example.com',
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceReviews() }]])
  })

  it('approve invalidates the review detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.approve.onSettled?.(undefined, undefined, 'r1')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceReview('r1') }],
      [{ key: qk.commerceReviews() }],
    ])
  })

  it('approve still invalidates both keys when the mutation itself failed (a 409, say)', async () => {
    const { mutations, qk } = await bundle()
    mutations.approve.onSettled?.(undefined, new Error("Review status is 'approved'; expected pending."), 'r2')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceReview('r2') }],
      [{ key: qk.commerceReviews() }],
    ])
  })

  it('spam invalidates the review detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.spam.onSettled?.(undefined, undefined, 'r3')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceReview('r3') }],
      [{ key: qk.commerceReviews() }],
    ])
  })

  it('spam still invalidates both keys when the mutation itself failed (already spam)', async () => {
    const { mutations, qk } = await bundle()
    mutations.spam.onSettled?.(undefined, new Error("Review status is 'spam'; expected pending or approved."), 'r4')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceReview('r4') }],
      [{ key: qk.commerceReviews() }],
    ])
  })

  it('remove invalidates the review detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.remove.onSettled?.(undefined, undefined, 'r5')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceReview('r5') }],
      [{ key: qk.commerceReviews() }],
    ])
  })

  it('remove still invalidates both keys when the mutation itself failed (an approved review 404)', async () => {
    const { mutations, qk } = await bundle()
    mutations.remove.onSettled?.(undefined, new Error('Resource not found.'), 'r6')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceReview('r6') }],
      [{ key: qk.commerceReviews() }],
    ])
  })

  it('bulk invalidates ONLY the list, never one key per acted-on uuid', async () => {
    const { mutations, qk } = await bundle()
    mutations.bulk.onSettled?.(undefined, undefined, { action: 'approve', uuids: ['r7', 'r8', 'r9'] })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceReviews() }]])
  })

  it('bulk still invalidates the list when the mutation itself failed', async () => {
    const { mutations, qk } = await bundle()
    mutations.bulk.onSettled?.(undefined, new Error('Validation failed'), { action: 'delete', uuids: ['r10'] })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceReviews() }]])
  })
})
