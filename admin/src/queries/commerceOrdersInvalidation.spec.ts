import { describe, it, expect, vi, beforeEach } from 'vitest'

// Pins the T13b invalidation contract: EVERY lifecycle action (cancel / mark-paid / fulfill)
// invalidates BOTH the order's own detail query and the list — unlike commerceCatalog.ts's
// per-product-only mutations (variant/media/stock), a lifecycle transition changes `status`
// (and for fulfill, `fulfillment_status`), fields OrdersTable itself renders. The colada layer is
// mocked so each mutation's onSettled can be driven directly (commerceCatalogInvalidation.spec.ts
// precedent).

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

describe('useCommerceOrderMutations invalidation', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
    capturedMutations.length = 0
  })

  async function bundle() {
    const { useCommerceOrderMutations } = await import('@/queries/commerceOrders')
    const { qk } = await import('@/queries/keys')
    // The colada mock spreads each mutation's options onto its return value, exposing
    // onSettled; the real return type doesn't carry it, hence the cast.
    const mutations = useCommerceOrderMutations() as unknown as Record<
      'cancel' | 'markPaid' | 'fulfill',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  it('cancel invalidates the order detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.cancel.onSettled?.(undefined, undefined, 'o1')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceOrder('o1') }],
      [{ key: qk.commerceOrders() }],
    ])
  })

  it('markPaid invalidates the order detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.markPaid.onSettled?.(undefined, undefined, 'o2')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceOrder('o2') }],
      [{ key: qk.commerceOrders() }],
    ])
  })

  it('fulfill invalidates the order detail AND the list', async () => {
    const { mutations, qk } = await bundle()
    mutations.fulfill.onSettled?.(undefined, undefined, {
      uuid: 'o3',
      input: { tracking_ref: 'TRACK-1' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceOrder('o3') }],
      [{ key: qk.commerceOrders() }],
    ])
  })

  it('fulfill invalidates using the owning uuid even when tracking_ref is omitted', async () => {
    const { mutations, qk } = await bundle()
    mutations.fulfill.onSettled?.(undefined, undefined, { uuid: 'o4', input: {} })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceOrder('o4') }],
      [{ key: qk.commerceOrders() }],
    ])
  })

  // Invalidation must still run on FAILURE (vars are always known regardless of the mutation's
  // own success/failure) — mirrors commerceCatalogInvalidation.spec.ts's stockAdjust/media note:
  // every mutation's vars carry the acted-on uuid explicitly, never inferred from the response.

  it('cancel still invalidates when the mutation itself failed (a 409, say)', async () => {
    const { mutations, qk } = await bundle()
    mutations.cancel.onSettled?.(undefined, new Error('Invalid order transition'), 'o5')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceOrder('o5') }],
      [{ key: qk.commerceOrders() }],
    ])
  })
})
