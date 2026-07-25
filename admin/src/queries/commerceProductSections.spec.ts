import { describe, it, expect, vi, beforeEach } from 'vitest'

// Normalizer specs for the six per-product section reads (single-page product editor plan, Task
// C1). UNLIKE commerceCatalog.spec.ts's fetchers (which normalize leniently — a malformed field
// falls back to a neutral default), every normalizer here THROWS on a field that doesn't match the
// wire contract, per the module's own docblock: these envelopes feed the five replacement
// mutations' payloads, so a silently defaulted/skipped item would reintroduce the "wipe" class of
// bug the whole `{revision, items}` contract exists to prevent.

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
// commerceCatalog.spec.ts.
describe('commerce product sections query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  function mockResponse(body: unknown, status = 200) {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse(body, status))
  }

  // ── Categories ───────────────────────────────────────────────────────────

  it('categories: parses a valid envelope and normalizes items', async () => {
    mockResponse({
      success: true,
      message: 'Product categories retrieved',
      data: { revision: 4, items: [{ uuid: 'cat1', name: 'Shirts', slug: 'shirts' }] },
    })

    const { fetchProductCategoriesSection } = await import('@/queries/commerceProductSections')
    const envelope = await fetchProductCategoriesSection('prod1')

    expect(envelope.revision).toBe(4)
    expect(envelope.items).toEqual([{ uuid: 'cat1', name: 'Shirts', slug: 'shirts' }])
  })

  it('categories: throws when an item field has the wrong type', async () => {
    mockResponse({ data: { revision: 1, items: [{ uuid: 'cat1', name: 42, slug: 'shirts' }] } })

    const { fetchProductCategoriesSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductCategoriesSection('prod1')).rejects.toThrow(/'name' is not a string/)
  })

  // ── Tags ─────────────────────────────────────────────────────────────────

  it('tags: parses a valid envelope and normalizes items', async () => {
    mockResponse({ data: { revision: 2, items: [{ uuid: 'tag1', name: 'Summer', slug: 'summer' }] } })

    const { fetchProductTagsSection } = await import('@/queries/commerceProductSections')
    const envelope = await fetchProductTagsSection('prod1')

    expect(envelope.revision).toBe(2)
    expect(envelope.items).toEqual([{ uuid: 'tag1', name: 'Summer', slug: 'summer' }])
  })

  it('tags: throws when an item field is missing', async () => {
    mockResponse({ data: { revision: 1, items: [{ uuid: 'tag1', name: 'Summer' }] } })

    const { fetchProductTagsSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductTagsSection('prod1')).rejects.toThrow(/'slug' is not a string/)
  })

  // ── Attributes ───────────────────────────────────────────────────────────

  it('attributes: parses a valid envelope, including a null attribute_uuid custom row', async () => {
    mockResponse({
      data: {
        revision: 7,
        items: [
          {
            attribute_uuid: null,
            name: 'Gift-wrap style',
            values: ['glossy', 'matte'],
            used_for_variants: false,
            visible: true,
            position: 0,
          },
        ],
      },
    })

    const { fetchProductAttributesSection } = await import('@/queries/commerceProductSections')
    const envelope = await fetchProductAttributesSection('prod1')

    expect(envelope.revision).toBe(7)
    expect(envelope.items).toEqual([
      {
        attribute_uuid: null,
        name: 'Gift-wrap style',
        values: ['glossy', 'matte'],
        used_for_variants: false,
        visible: true,
        position: 0,
      },
    ])
  })

  it('attributes: throws when values contains a non-string element', async () => {
    mockResponse({
      data: {
        revision: 1,
        items: [
          {
            attribute_uuid: 'attr1',
            name: null,
            values: ['red', 7],
            used_for_variants: true,
            visible: true,
            position: 0,
          },
        ],
      },
    })

    const { fetchProductAttributesSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductAttributesSection('prod1')).rejects.toThrow(
      /'values' is not an array of strings/,
    )
  })

  it('attributes: throws when used_for_variants is not a boolean', async () => {
    mockResponse({
      data: {
        revision: 1,
        items: [
          {
            attribute_uuid: 'attr1',
            name: null,
            values: [],
            used_for_variants: 1,
            visible: true,
            position: 0,
          },
        ],
      },
    })

    const { fetchProductAttributesSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductAttributesSection('prod1')).rejects.toThrow(
      /'used_for_variants' is not a boolean/,
    )
  })

  // ── Media ────────────────────────────────────────────────────────────────

  it('media: parses a valid envelope, including a null variant_uuid', async () => {
    mockResponse({
      data: {
        revision: 3,
        items: [
          { uuid: 'med1', blob_uuid: 'blob1', role: 'cover', position: 0, alt: null, variant_uuid: null },
        ],
      },
    })

    const { fetchProductMediaSection } = await import('@/queries/commerceProductSections')
    const envelope = await fetchProductMediaSection('prod1')

    expect(envelope.items).toEqual([
      { uuid: 'med1', blob_uuid: 'blob1', role: 'cover', position: 0, alt: null, variant_uuid: null },
    ])
  })

  it('media: throws when variant_uuid is a number instead of a string or null', async () => {
    mockResponse({
      data: {
        revision: 1,
        items: [
          { uuid: 'med1', blob_uuid: 'blob1', role: 'gallery', position: 0, alt: null, variant_uuid: 5 },
        ],
      },
    })

    const { fetchProductMediaSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductMediaSection('prod1')).rejects.toThrow(
      /'variant_uuid' is not a string or null/,
    )
  })

  // ── Children ─────────────────────────────────────────────────────────────

  it('children: parses a valid envelope, including an attached tombstone', async () => {
    mockResponse({
      data: {
        revision: 9,
        items: [
          { uuid: 'child1', name: 'Bundle part', slug: 'bundle-part', status: 'active', deleted: false, position: 0 },
          { uuid: 'child2', name: 'Retired part', slug: 'retired-part', status: 'archived', deleted: true, position: 1 },
        ],
      },
    })

    const { fetchProductChildrenSection } = await import('@/queries/commerceProductSections')
    const envelope = await fetchProductChildrenSection('prod1')

    expect(envelope.items[1]).toEqual({
      uuid: 'child2',
      name: 'Retired part',
      slug: 'retired-part',
      status: 'archived',
      deleted: true,
      position: 1,
    })
  })

  it('children: throws when deleted is not a boolean', async () => {
    mockResponse({
      data: {
        revision: 1,
        items: [
          { uuid: 'child1', name: 'Bundle part', slug: 'bundle-part', status: 'active', deleted: 0, position: 0 },
        ],
      },
    })

    const { fetchProductChildrenSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductChildrenSection('prod1')).rejects.toThrow(/'deleted' is not a boolean/)
  })

  // ── Stock ────────────────────────────────────────────────────────────────

  it('stock: parses a valid envelope', async () => {
    mockResponse({
      data: { revision: 12, items: [{ variant_uuid: 'var1', tracked: true, quantity: 5 }] },
    })

    const { fetchProductStockSection } = await import('@/queries/commerceProductSections')
    const envelope = await fetchProductStockSection('prod1')

    expect(envelope.items).toEqual([{ variant_uuid: 'var1', tracked: true, quantity: 5 }])
  })

  it('stock: throws when quantity is not a number', async () => {
    mockResponse({
      data: { revision: 1, items: [{ variant_uuid: 'var1', tracked: true, quantity: '5' }] },
    })

    const { fetchProductStockSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductStockSection('prod1')).rejects.toThrow(/'quantity' is not a number/)
  })

  // ── Envelope-level guards (shared across all six sections — exercised via one fetcher each) ──

  it('throws when the envelope is missing revision entirely', async () => {
    mockResponse({ data: { items: [] } })

    const { fetchProductCategoriesSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductCategoriesSection('prod1')).rejects.toThrow(
      /'revision' must be a non-negative integer/,
    )
  })

  it('throws when revision is a negative integer', async () => {
    mockResponse({ data: { revision: -1, items: [] } })

    const { fetchProductTagsSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductTagsSection('prod1')).rejects.toThrow(
      /'revision' must be a non-negative integer/,
    )
  })

  it('throws when revision is not an integer', async () => {
    mockResponse({ data: { revision: 1.5, items: [] } })

    const { fetchProductAttributesSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductAttributesSection('prod1')).rejects.toThrow(
      /'revision' must be a non-negative integer/,
    )
  })

  it('throws when revision is a numeric string instead of a number (no coercion)', async () => {
    mockResponse({ data: { revision: '3', items: [] } })

    const { fetchProductMediaSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductMediaSection('prod1')).rejects.toThrow(
      /'revision' must be a non-negative integer/,
    )
  })

  it('throws when items is missing (not an array)', async () => {
    mockResponse({ data: { revision: 0 } })

    const { fetchProductChildrenSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductChildrenSection('prod1')).rejects.toThrow(/'items' must be an array/)
  })

  it('throws when items is an object instead of an array', async () => {
    mockResponse({ data: { revision: 0, items: {} } })

    const { fetchProductStockSection } = await import('@/queries/commerceProductSections')
    await expect(fetchProductStockSection('prod1')).rejects.toThrow(/'items' must be an array/)
  })

  it('accepts revision: 0 for a brand-new product with no assignments yet', async () => {
    mockResponse({ data: { revision: 0, items: [] } })

    const { fetchProductCategoriesSection } = await import('@/queries/commerceProductSections')
    const envelope = await fetchProductCategoriesSection('prod1')

    expect(envelope).toEqual({ revision: 0, items: [] })
  })
})
