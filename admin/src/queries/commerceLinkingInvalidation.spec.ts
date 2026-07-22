import { describe, it, expect, vi, beforeEach } from 'vitest'

// Pins the Task 12 invalidation contract: `link`/`unlink` BOTH invalidate the affected product's
// link projection (qk.commerceLink(productUuid)) AND the affected entry's by-entry lookup
// (qk.commerceLinkByEntry(entryUuid)) — including, on a relink, the OLD entry's by-entry key, so
// its editor-side Commerce tab doesn't keep showing a stale link. Same colada-mock pattern as
// commerceCatalogInvalidation.spec.ts: the colada layer is mocked so each mutation's onSettled can
// be driven directly.

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
  useMutation: (options: { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }) => ({
    mutate: vi.fn(),
    ...options,
  }),
}))

describe('useCommerceLinkMutations invalidation', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
  })

  async function bundle() {
    const { useCommerceLinkMutations } = await import('@/queries/commerceLinking')
    const { qk } = await import('@/queries/keys')
    const mutations = useCommerceLinkMutations() as unknown as Record<
      'link' | 'unlink',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  it('link (first-time) invalidates the product link AND the new entry’s by-entry lookup only', async () => {
    const { mutations, qk } = await bundle()
    mutations.link.onSettled?.(undefined, undefined, { productUuid: 'p1', entryUuid: 'entry1' })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceLink('p1') }],
      [{ key: qk.commerceLinkByEntry('entry1') }],
    ])
  })

  it('link (relink) ALSO invalidates the OLD entry’s by-entry lookup', async () => {
    const { mutations, qk } = await bundle()
    mutations.link.onSettled?.(undefined, undefined, {
      productUuid: 'p1',
      entryUuid: 'entry2',
      expectedEntryUuid: 'entry1',
      previousEntryUuid: 'entry1',
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceLink('p1') }],
      [{ key: qk.commerceLinkByEntry('entry2') }],
      [{ key: qk.commerceLinkByEntry('entry1') }],
    ])
  })

  it('unlink invalidates the product link AND the (known) entry’s by-entry lookup', async () => {
    const { mutations, qk } = await bundle()
    mutations.unlink.onSettled?.(undefined, undefined, { productUuid: 'p1', entryUuid: 'entry1' })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceLink('p1') }],
      [{ key: qk.commerceLinkByEntry('entry1') }],
    ])
  })

  it('unlink of a never-linked product invalidates the product link only (no entry uuid to invalidate)', async () => {
    const { mutations, qk } = await bundle()
    mutations.unlink.onSettled?.(undefined, undefined, { productUuid: 'p1' })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceLink('p1') }]])
  })
})
