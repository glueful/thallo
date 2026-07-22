import { describe, it, expect, vi, beforeEach } from 'vitest'

// Pins the T10a invalidation contract: create/bulkStatus invalidate the LIST only;
// update/remove invalidate BOTH the product detail and the list. The colada layer is
// mocked so each mutation's onSettled can be driven directly (extensions.spec.ts precedent).

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

const cacheInvalidate = vi.hoisted(() => vi.fn())
const capturedMutations = vi.hoisted(
  () => [] as Array<{ onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }>,
)
vi.mock('@pinia/colada', () => ({
  useQueryCache: () => ({ invalidateQueries: cacheInvalidate }),
  useQuery: () => ({ data: { value: undefined }, status: { value: 'idle' } }),
  useMutation: (options: { onSettled?: () => void }) => {
    capturedMutations.push(options)
    return { mutate: vi.fn(), ...options }
  },
}))

describe('useCommerceProductMutations invalidation', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
    capturedMutations.length = 0
  })

  async function bundle() {
    const { useCommerceProductMutations } = await import('@/queries/commerceCatalog')
    const { qk } = await import('@/queries/keys')
    // The colada mock spreads each mutation's options onto its return value, exposing
    // onSettled; the real return type doesn't carry it, hence the cast.
    const mutations = useCommerceProductMutations() as unknown as Record<
      'create' | 'update' | 'remove' | 'bulkStatus' | 'createVariant' | 'updateVariant' | 'bulkPrice' |
        'setChildren' | 'stockAdjust' | 'attachMedia' | 'updateMedia' | 'detachMedia' | 'reorderMedia',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  it('create invalidates the products list only', async () => {
    const { mutations, qk } = await bundle()
    mutations.create.onSettled?.(undefined, undefined, undefined)

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProducts() }]])
  })

  it('bulkStatus invalidates the products list only', async () => {
    const { mutations, qk } = await bundle()
    mutations.bulkStatus.onSettled?.(undefined, undefined, undefined)

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProducts() }]])
  })

  it('update invalidates the product detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.update.onSettled?.(undefined, undefined, { uuid: 'prod00000001', input: {} })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceProduct('prod00000001') }],
      [{ key: qk.commerceProducts() }],
    ])
  })

  it('remove invalidates the product detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.remove.onSettled?.(undefined, undefined, 'prod00000002')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceProduct('prod00000002') }],
      [{ key: qk.commerceProducts() }],
    ])
  })

  // Task 10b: variant/children/stock mutations invalidate ONLY the owning product, never the
  // list — no field ProductsTable renders (name/slug/type/status/updated_at) is affected by a
  // variant, the children set, or stock. Every one of these mutation's vars carries the owning
  // `productUuid` explicitly (never inferred from the mutation's own response), so the pinned
  // invalidation still runs correctly even on failure.

  it('createVariant invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.createVariant.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      input: { sku: 'SKU-1', price: 100, currency: 'USD' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  it('updateVariant invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.updateVariant.onSettled?.(undefined, undefined, {
      uuid: 'var00000001',
      productUuid: 'prod00000001',
      input: { price: 200 },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  it('bulkPrice invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.bulkPrice.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      items: [{ uuid: 'var00000001', price: 300 }],
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  it('setChildren invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.setChildren.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      childUuids: ['prod00000002'],
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  it('stockAdjust invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.stockAdjust.onSettled?.(undefined, undefined, {
      variantUuid: 'var00000001',
      productUuid: 'prod00000001',
      input: { delta: -5, reason: 'damaged' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  // Task 10c: media mutations invalidate ONLY the owning product, same reasoning as variants
  // above — no field ProductsTable renders comes from product media.

  it('attachMedia invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.attachMedia.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      input: { blob_uuid: 'blob00000001' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  it('updateMedia invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.updateMedia.onSettled?.(undefined, undefined, {
      uuid: 'media0000001',
      productUuid: 'prod00000001',
      input: { alt: 'Updated' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  it('detachMedia invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.detachMedia.onSettled?.(undefined, undefined, {
      uuid: 'media0000001',
      productUuid: 'prod00000001',
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  it('reorderMedia invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.reorderMedia.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      orderedUuids: ['media0000001', 'media0000002'],
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })
})
