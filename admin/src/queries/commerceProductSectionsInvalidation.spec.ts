import { describe, it, expect, vi, beforeEach } from 'vitest'

// Single-page product editor plan, Task C1 — the product-section invalidation matrix layered on
// top of commerceCatalogInvalidation.spec.ts's existing per-mutation pins (that file still pins
// each mutation's exact onSettled call ORDER/COUNT; this file is the one place the SIX
// qk.commerceProductSection(uuid, section) keys are asserted against the full
// COMMERCE_PRODUCT_SECTIONS vocabulary programmatically, so a future seventh section can't drift
// out of sync with a hand-maintained list). The colada layer is mocked so each mutation's
// onSettled can be driven directly (commerceCatalogInvalidation.spec.ts precedent).
//
// The negative case at the bottom is the other half of the contract: product-LINK mutations
// (`useCommerceLinkMutations()` in commerceLinking.ts) never touch qk.commerceProduct() or any
// qk.commerceProductSection() key — they don't advance `catalog_revision` (Global Constraints /
// task brief, spec-pinned), so C3's revision coordinator must never treat a link/unlink as a
// reason to refetch a product's sections.

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

type ProductMutationName =
  | 'create'
  | 'update'
  | 'bulkStatus'
  | 'createVariant'
  | 'updateVariant'
  | 'bulkPrice'
  | 'setChildren'
  | 'stockAdjust'
  | 'attachMedia'
  | 'updateMedia'
  | 'detachMedia'
  | 'reorderMedia'
  | 'setCategories'
  | 'setTags'
  | 'setAttributes'
  | 'createAddon'
  | 'updateAddon'
  | 'removeAddon'
  | 'attachDownload'
  | 'updateDownload'
  | 'removeDownload'
  | 'remove'

describe('commerce product-section invalidation matrix (Task C1)', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
  })

  async function bundle() {
    const { useCommerceProductMutations } = await import('@/queries/commerceCatalog')
    const { qk, COMMERCE_PRODUCT_SECTIONS } = await import('@/queries/keys')
    // The colada mock spreads each mutation's options onto its return value, exposing onSettled;
    // the real return type doesn't carry it, hence the cast (commerceCatalogInvalidation.spec.ts
    // precedent).
    const mutations = useCommerceProductMutations() as unknown as Record<
      ProductMutationName,
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk, COMMERCE_PRODUCT_SECTIONS }
  }

  it.each([
    ['update', { uuid: 'prod00000001', input: {} }],
    [
      'createVariant',
      { productUuid: 'prod00000001', input: { sku: 'SKU-1', price: 100, currency: 'USD' } },
    ],
    ['updateVariant', { uuid: 'var00000001', productUuid: 'prod00000001', input: { price: 200 } }],
    ['bulkPrice', { productUuid: 'prod00000001', items: [{ uuid: 'var00000001', price: 300 }] }],
    ['setChildren', { productUuid: 'prod00000001', childUuids: ['prod00000002'] }],
    [
      'stockAdjust',
      { variantUuid: 'var00000001', productUuid: 'prod00000001', input: { delta: -5, reason: 'damaged' } },
    ],
    ['attachMedia', { productUuid: 'prod00000001', input: { blob_uuid: 'blob00000001' } }],
    ['updateMedia', { uuid: 'media0000001', productUuid: 'prod00000001', input: { alt: 'Updated' } }],
    ['detachMedia', { uuid: 'media0000001', productUuid: 'prod00000001' }],
    ['reorderMedia', { productUuid: 'prod00000001', orderedUuids: ['media0000001', 'media0000002'] }],
    ['setCategories', { productUuid: 'prod00000001', categoryUuids: ['cat00000001'] }],
    ['setTags', { productUuid: 'prod00000001', tagUuids: ['tag00000001'] }],
    ['setAttributes', { productUuid: 'prod00000001', rows: [{ attribute_uuid: 'attr00000001', values: ['red'] }] }],
    ['createAddon', { productUuid: 'prod00000001', input: { name: 'Gift wrap', field_type: 'checkbox' } }],
    ['updateAddon', { uuid: 'addon0000001', productUuid: 'prod00000001', input: { name: 'Deluxe gift wrap' } }],
    ['removeAddon', { uuid: 'addon0000001', productUuid: 'prod00000001' }],
  ] as const)(
    '%s invalidates qk.commerceProduct(uuid) and all six section keys',
    async (name, vars) => {
      const { mutations, qk, COMMERCE_PRODUCT_SECTIONS } = await bundle()
      mutations[name].onSettled?.(undefined, undefined, vars)

      expect(cacheInvalidate.mock.calls).toContainEqual([{ key: qk.commerceProduct('prod00000001') }])
      for (const section of COMMERCE_PRODUCT_SECTIONS) {
        expect(cacheInvalidate.mock.calls).toContainEqual([
          { key: qk.commerceProductSection('prod00000001', section) },
        ])
      }
    },
  )

  // Download mutations are PER-VARIANT — `productUuid` is OPTIONAL on their vars (see
  // useCommerceProductMutations()'s own docblock). Supplying it opts a caller into the same
  // product+sections invalidation as every mutation above.
  it.each(['attachDownload', 'updateDownload', 'removeDownload'] as const)(
    '%s invalidates qk.commerceProduct(uuid) and all six section keys when productUuid is supplied',
    async (name) => {
      const { mutations, qk, COMMERCE_PRODUCT_SECTIONS } = await bundle()
      const varsByName = {
        attachDownload: {
          variantUuid: 'var00000001',
          productUuid: 'prod00000001',
          input: { blob_uuid: 'blob00000001', name: 'Ebook (PDF)' },
        },
        updateDownload: {
          uuid: 'down00000001',
          variantUuid: 'var00000001',
          productUuid: 'prod00000001',
          input: { name: 'Ebook (2nd edition)' },
        },
        removeDownload: {
          uuid: 'down00000001',
          variantUuid: 'var00000001',
          productUuid: 'prod00000001',
        },
      } as const

      mutations[name].onSettled?.(undefined, undefined, varsByName[name])

      expect(cacheInvalidate.mock.calls).toContainEqual([{ key: qk.commerceProduct('prod00000001') }])
      for (const section of COMMERCE_PRODUCT_SECTIONS) {
        expect(cacheInvalidate.mock.calls).toContainEqual([
          { key: qk.commerceProductSection('prod00000001', section) },
        ])
      }
    },
  )

  it('attachDownload invalidates ONLY the downloads list when productUuid is omitted (byte-for-byte pre-C1)', async () => {
    const { mutations, qk } = await bundle()
    mutations.attachDownload.onSettled?.(undefined, undefined, {
      variantUuid: 'var00000001',
      input: { blob_uuid: 'blob00000001', name: 'Ebook (PDF)' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceVariantDownloads('var00000001') }]])
  })

  it('create never touches any section key — no product uuid exists yet', async () => {
    const { mutations, qk, COMMERCE_PRODUCT_SECTIONS } = await bundle()

    mutations.create.onSettled?.(undefined, undefined, undefined)

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProducts() }]])
    for (const section of COMMERCE_PRODUCT_SECTIONS) {
      expect(cacheInvalidate.mock.calls).not.toContainEqual([
        { key: qk.commerceProductSection('prod00000001', section) },
      ])
    }
  })

  it('bulkStatus never touches any section key — it targets a set of uuids, not one product', async () => {
    const { mutations, qk, COMMERCE_PRODUCT_SECTIONS } = await bundle()

    mutations.bulkStatus.onSettled?.(undefined, undefined, {
      uuids: ['prod00000001', 'prod00000002'],
      status: 'archived',
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProducts() }]])
    for (const uuid of ['prod00000001', 'prod00000002']) {
      for (const section of COMMERCE_PRODUCT_SECTIONS) {
        expect(cacheInvalidate.mock.calls).not.toContainEqual([
          { key: qk.commerceProductSection(uuid, section) },
        ])
      }
    }
  })

  it('remove never touches any section key — the product is gone', async () => {
    const { mutations, qk, COMMERCE_PRODUCT_SECTIONS } = await bundle()

    mutations.remove.onSettled?.(undefined, undefined, 'prod00000002')

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceProduct('prod00000002') }],
      [{ key: qk.commerceProducts() }],
    ])
    for (const section of COMMERCE_PRODUCT_SECTIONS) {
      expect(cacheInvalidate.mock.calls).not.toContainEqual([
        { key: qk.commerceProductSection('prod00000002', section) },
      ])
    }
  })

  // ── Negative case: product-link mutations don't advance catalog_revision ───────────────────
  //
  // `useCommerceLinkMutations()` (commerceLinking.ts) uses its OWN qk.commerceLink()/
  // qk.commerceLinkByEntry() keys and never invalidates qk.commerceProduct() or any
  // qk.commerceProductSection() key — pack-owned linked-content mutations don't advance
  // catalog_revision (Global Constraints / task brief), so C3's revision coordinator must never
  // treat a link/unlink as a reason to refetch a product's sections.

  it('link does not invalidate qk.commerceProduct or any section key', async () => {
    const { useCommerceLinkMutations } = await import('@/queries/commerceLinking')
    const { qk, COMMERCE_PRODUCT_SECTIONS } = await import('@/queries/keys')
    const mutations = useCommerceLinkMutations() as unknown as Record<
      'link' | 'unlink',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >

    mutations.link.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      entryUuid: 'entry0000001',
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceLink('prod00000001') }],
      [{ key: qk.commerceLinkByEntry('entry0000001') }],
    ])
    expect(cacheInvalidate.mock.calls).not.toContainEqual([{ key: qk.commerceProduct('prod00000001') }])
    for (const section of COMMERCE_PRODUCT_SECTIONS) {
      expect(cacheInvalidate.mock.calls).not.toContainEqual([
        { key: qk.commerceProductSection('prod00000001', section) },
      ])
    }
  })

  it('unlink does not invalidate qk.commerceProduct or any section key', async () => {
    const { useCommerceLinkMutations } = await import('@/queries/commerceLinking')
    const { qk, COMMERCE_PRODUCT_SECTIONS } = await import('@/queries/keys')
    const mutations = useCommerceLinkMutations() as unknown as Record<
      'link' | 'unlink',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >

    mutations.unlink.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      entryUuid: 'entry0000001',
    })

    expect(cacheInvalidate.mock.calls).toEqual([
      [{ key: qk.commerceLink('prod00000001') }],
      [{ key: qk.commerceLinkByEntry('entry0000001') }],
    ])
    expect(cacheInvalidate.mock.calls).not.toContainEqual([{ key: qk.commerceProduct('prod00000001') }])
    for (const section of COMMERCE_PRODUCT_SECTIONS) {
      expect(cacheInvalidate.mock.calls).not.toContainEqual([
        { key: qk.commerceProductSection('prod00000001', section) },
      ])
    }
  })
})
