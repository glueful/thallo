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
      'cancel' | 'markPaid' | 'fulfill' | 'refund' | 'addNote',
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

  // ── Refund (Task 13c): order detail + orders list + BOTH refund-specific keys ───────────────
  // A completed refund changes the order's own status/refunded_total (detail + list, same as every
  // other lifecycle action) AND invalidates the two refund keys this task adds: the per-order list
  // (`commerceOrderRefunds`) the detail page's Refunds section reads, and the cross-order list
  // (`commerceRefunds`) no page consumes yet but which the brief pins explicitly.

  it('refund invalidates the order detail, the orders list, AND both refund keys', async () => {
    const { mutations, qk } = await bundle()
    mutations.refund.onSettled?.(undefined, undefined, {
      uuid: 'o6',
      input: { amount: 500 },
      idempotencyKey: 'idem-1',
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceOrder('o6') }],
      [{ key: qk.commerceOrders() }],
      [{ key: qk.commerceOrderRefunds('o6') }],
      [{ key: qk.commerceRefunds() }],
    ])
  })

  it('refund still invalidates all four keys when the mutation itself failed (a 422, say)', async () => {
    const { mutations, qk } = await bundle()
    mutations.refund.onSettled?.(
      undefined,
      new Error('amount: exceeds the remaining refundable balance.'),
      { uuid: 'o7', input: { amount: 999999 }, idempotencyKey: 'idem-2' },
    )

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceOrder('o7') }],
      [{ key: qk.commerceOrders() }],
      [{ key: qk.commerceOrderRefunds('o7') }],
      [{ key: qk.commerceRefunds() }],
    ])
  })

  // ── addNote (Task 13d): ONLY the notes key ──────────────────────────────────────────────────
  // Unlike every lifecycle action above, adding a note changes no field OrdersTable or the order
  // detail's own primary fields render through `qk.commerceOrder()` — it invalidates ONLY its own
  // `qk.commerceOrderNotes()` key, mirroring commerceCatalog.ts's variant/media/stock mutations
  // that invalidate a single narrow key rather than cascading to the list.

  it('addNote invalidates ONLY the order notes key', async () => {
    const { mutations, qk } = await bundle()
    mutations.addNote.onSettled?.(undefined, undefined, {
      uuid: 'o8',
      input: { body: 'Called customer.' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceOrderNotes('o8') }]])
  })

  it('addNote still invalidates the notes key when the mutation itself failed (a 422, say)', async () => {
    const { mutations, qk } = await bundle()
    mutations.addNote.onSettled?.(
      undefined,
      new Error('notify requires visibility to be customer.'),
      { uuid: 'o9', input: { body: 'x', visibility: 'internal', notify: true } },
    )

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceOrderNotes('o9') }]])
  })
})
