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
      'create' | 'update' | 'remove' | 'bulkStatus',
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
})
