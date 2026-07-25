import { describe, it, expect, vi, beforeEach } from 'vitest'

// Pins the Task 14 invalidation contract: `create` invalidates only the list (a brand-new
// discount has no existing detail-key consumer yet); `update`/`remove` invalidate BOTH the single
// discount key and the list — mirrors useCommerceProductMutations()'s create/update/remove
// reasoning exactly (commerceCatalogInvalidation.spec.ts precedent). The colada layer is mocked so
// each mutation's onSettled can be driven directly.

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

describe('useCommerceDiscountMutations invalidation', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
  })

  async function bundle() {
    const { useCommerceDiscountMutations } = await import('@/queries/commerceDiscounts')
    const { qk } = await import('@/queries/keys')
    const mutations = useCommerceDiscountMutations() as unknown as Record<
      'create' | 'update' | 'remove',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  it('create invalidates ONLY the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.create.onSettled?.(undefined, undefined, {
      code: 'SAVE10',
      type: 'percentage',
      value: 1000,
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceDiscounts() }]])
  })

  it('update invalidates the discount detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.update.onSettled?.(undefined, undefined, { uuid: 'd1', input: { status: 'inactive' } })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceDiscount('d1') }],
      [{ key: qk.commerceDiscounts() }],
    ])
  })

  it('update still invalidates both keys when the mutation itself failed (a 404, say)', async () => {
    const { mutations, qk } = await bundle()
    mutations.update.onSettled?.(undefined, new Error('Resource not found.'), {
      uuid: 'd2',
      input: { status: 'inactive' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceDiscount('d2') }],
      [{ key: qk.commerceDiscounts() }],
    ])
  })

  it('remove invalidates the discount detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.remove.onSettled?.(undefined, undefined, 'd3')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceDiscount('d3') }],
      [{ key: qk.commerceDiscounts() }],
    ])
  })

  it('remove still invalidates both keys when the mutation itself failed (a 409 redeemed discount)', async () => {
    const { mutations, qk } = await bundle()
    mutations.remove.onSettled?.(
      undefined,
      new Error('This discount has been redeemed and cannot be deleted.'),
      'd4',
    )

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceDiscount('d4') }],
      [{ key: qk.commerceDiscounts() }],
    ])
  })
})
