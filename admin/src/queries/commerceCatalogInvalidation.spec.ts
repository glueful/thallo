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
        'setChildren' | 'stockAdjust' | 'attachMedia' | 'updateMedia' | 'detachMedia' | 'reorderMedia' |
        'setCategories' | 'setTags' | 'setAttributes' | 'createAddon' | 'updateAddon' | 'removeAddon',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  async function categoryBundle() {
    const { useCommerceCategoryMutations } = await import('@/queries/commerceCatalog')
    const { qk } = await import('@/queries/keys')
    const mutations = useCommerceCategoryMutations() as unknown as Record<
      'create' | 'update' | 'remove',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  async function tagBundle() {
    const { useCommerceTagMutations } = await import('@/queries/commerceCatalog')
    const { qk } = await import('@/queries/keys')
    const mutations = useCommerceTagMutations() as unknown as Record<
      'create' | 'update' | 'remove',
      { onSettled?: (d?: unknown, e?: unknown, vars?: unknown) => void }
    >
    return { mutations, qk }
  }

  async function attributeBundle() {
    const { useCommerceAttributeMutations } = await import('@/queries/commerceCatalog')
    const { qk } = await import('@/queries/keys')
    const mutations = useCommerceAttributeMutations() as unknown as Record<
      'create' | 'update' | 'remove' | 'createValue' | 'updateValue' | 'removeValue',
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

  // Task 10d: product category assignment invalidates ONLY the owning product — the category
  // LIST (`useCommerceCategories()`) shows no per-category product count, so a product's own
  // assignment changing never makes anything it renders stale.

  it('setCategories invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.setCategories.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      categoryUuids: ['cat00000001'],
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  // Task 10d: category CRUD mutations invalidate the shared category list only.

  it('category create invalidates the category list only', async () => {
    const { mutations, qk } = await categoryBundle()
    mutations.create.onSettled?.(undefined, undefined, undefined)

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceCategories() }]])
  })

  it('category update invalidates the category list only', async () => {
    const { mutations, qk } = await categoryBundle()
    mutations.update.onSettled?.(undefined, undefined, { uuid: 'cat00000001', input: {} })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceCategories() }]])
  })

  it('category remove invalidates the category list only', async () => {
    const { mutations, qk } = await categoryBundle()
    mutations.remove.onSettled?.(undefined, undefined, 'cat00000001')

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceCategories() }]])
  })

  // Task 19a: product tag assignment invalidates ONLY the owning product — the tag LIST
  // (`useCommerceTags()`) shows no per-tag product count, so a product's own assignment
  // changing never makes anything it renders stale (mirrors setCategories above).

  it('setTags invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.setTags.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      tagUuids: ['tag00000001'],
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  // Task 19a: tag CRUD mutations invalidate the shared tag list only.

  it('tag create invalidates the tag list only', async () => {
    const { mutations, qk } = await tagBundle()
    mutations.create.onSettled?.(undefined, undefined, undefined)

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceTags() }]])
  })

  it('tag update invalidates the tag list only', async () => {
    const { mutations, qk } = await tagBundle()
    mutations.update.onSettled?.(undefined, undefined, { uuid: 'tag00000001', input: { name: 'New' } })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceTags() }]])
  })

  it('tag remove invalidates the tag list only', async () => {
    const { mutations, qk } = await tagBundle()
    mutations.remove.onSettled?.(undefined, undefined, 'tag00000001')

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceTags() }]])
  })

  // Task 19b: product attribute assignment invalidates ONLY the owning product — the attribute
  // LIST (`useCommerceAttributes()`) shows no per-attribute product count, so a product's own
  // assignment changing never makes anything it renders stale (mirrors setTags/setCategories).

  it('setAttributes invalidates the owning product only', async () => {
    const { mutations, qk } = await bundle()
    mutations.setAttributes.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      rows: [{ attribute_uuid: 'attr00000001', values: ['red'] }],
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProduct('prod00000001') }]])
  })

  // Task 19b: attribute CRUD AND value CRUD both invalidate the shared attribute list only — a
  // value has no independent read path (it's embedded in its owning attribute's row), so a value
  // mutation has nothing narrower to invalidate than the whole list.

  it('attribute create invalidates the attribute list only', async () => {
    const { mutations, qk } = await attributeBundle()
    mutations.create.onSettled?.(undefined, undefined, undefined)

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceAttributes() }]])
  })

  it('attribute update invalidates the attribute list only', async () => {
    const { mutations, qk } = await attributeBundle()
    mutations.update.onSettled?.(undefined, undefined, { uuid: 'attr00000001', input: { name: 'New' } })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceAttributes() }]])
  })

  it('attribute remove invalidates the attribute list only', async () => {
    const { mutations, qk } = await attributeBundle()
    mutations.remove.onSettled?.(undefined, undefined, 'attr00000001')

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceAttributes() }]])
  })

  it('attribute createValue invalidates the attribute list only', async () => {
    const { mutations, qk } = await attributeBundle()
    mutations.createValue.onSettled?.(undefined, undefined, {
      attributeUuid: 'attr00000001',
      input: { slug: 'red', value: 'Red' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceAttributes() }]])
  })

  it('attribute updateValue invalidates the attribute list only', async () => {
    const { mutations, qk } = await attributeBundle()
    mutations.updateValue.onSettled?.(undefined, undefined, { uuid: 'val00000001', input: { value: 'Crimson' } })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceAttributes() }]])
  })

  it('attribute removeValue invalidates the attribute list only', async () => {
    const { mutations, qk } = await attributeBundle()
    mutations.removeValue.onSettled?.(undefined, undefined, 'val00000001')

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceAttributes() }]])
  })

  // Task 19c: product add-ons are PER-PRODUCT (no tenant-wide list of their own, unlike tags/
  // categories/attributes), so every one of create/update/remove invalidates ONLY
  // qk.commerceProductAddons(productUuid) — never the product detail: no admin product endpoint
  // embeds `addons` in its payload, same reasoning as variants/media/stock above.

  it('createAddon invalidates the owning product’s add-on list only', async () => {
    const { mutations, qk } = await bundle()
    mutations.createAddon.onSettled?.(undefined, undefined, {
      productUuid: 'prod00000001',
      input: { name: 'Gift wrap', field_type: 'checkbox' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProductAddons('prod00000001') }]])
  })

  it('updateAddon invalidates the owning product’s add-on list only', async () => {
    const { mutations, qk } = await bundle()
    mutations.updateAddon.onSettled?.(undefined, undefined, {
      uuid: 'addon0000001',
      productUuid: 'prod00000001',
      input: { name: 'Deluxe gift wrap' },
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProductAddons('prod00000001') }]])
  })

  it('removeAddon invalidates the owning product’s add-on list only', async () => {
    const { mutations, qk } = await bundle()
    mutations.removeAddon.onSettled?.(undefined, undefined, {
      uuid: 'addon0000001',
      productUuid: 'prod00000001',
    })

    expect(cacheInvalidate.mock.calls).toEqual([[{ key: qk.commerceProductAddons('prod00000001') }]])
  })
})
