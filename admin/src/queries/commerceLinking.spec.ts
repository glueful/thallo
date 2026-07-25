import { describe, it, expect, vi, beforeEach } from 'vitest'

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
describe('commerce linking query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  // ── fetchProductLink — showByProduct's always-200 projection ──────────────────────────────

  it('parses the projection with storefront_url and a null link for an unlinked product', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Success',
        data: { product_uuid: 'p1', storefront_url: 'https://shop.test/shop/products/widget', link: null },
      }),
    )

    const { fetchProductLink } = await import('@/queries/commerceLinking')
    const projection = await fetchProductLink('p1')

    expect(projection).toEqual({
      product_uuid: 'p1',
      storefront_url: 'https://shop.test/shop/products/widget',
      link: null,
    })
  })

  it('parses the projection with the link row when linked', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Success',
        data: {
          product_uuid: 'p1',
          storefront_url: 'https://shop.test/shop/products/widget',
          link: {
            uuid: 'link1',
            product_uuid: 'p1',
            entry_uuid: 'entry1',
            created_at: '2026-01-01 00:00:00',
            updated_at: '2026-01-01 00:00:00',
          },
        },
      }),
    )

    const { fetchProductLink } = await import('@/queries/commerceLinking')
    const projection = await fetchProductLink('p1')

    expect(projection.link).toEqual({
      uuid: 'link1',
      product_uuid: 'p1',
      entry_uuid: 'entry1',
      created_at: '2026-01-01 00:00:00',
      updated_at: '2026-01-01 00:00:00',
    })
  })

  it('throws ApiError when the product link request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Product not found.' }, 404),
    )

    const { fetchProductLink } = await import('@/queries/commerceLinking')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchProductLink('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── fetchEntryLink — showByEntry: 404 is the ORDINARY "not linked" case, not a failure ─────

  it('resolves to null on a 404 (the ordinary "not linked" case), never throwing', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Link not found.' }, 404),
    )

    const { fetchEntryLink } = await import('@/queries/commerceLinking')
    await expect(fetchEntryLink('entry1')).resolves.toBeNull()
  })

  it('parses the link row when the entry IS linked', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Success',
        data: {
          uuid: 'link1',
          product_uuid: 'p1',
          entry_uuid: 'entry1',
          created_at: '2026-01-01 00:00:00',
          updated_at: null,
        },
      }),
    )

    const { fetchEntryLink } = await import('@/queries/commerceLinking')
    const link = await fetchEntryLink('entry1')

    expect(link).toEqual({
      uuid: 'link1',
      product_uuid: 'p1',
      entry_uuid: 'entry1',
      created_at: '2026-01-01 00:00:00',
      updated_at: null,
    })
  })

  it('throws ApiError for a genuine non-404 failure (e.g. 403)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchEntryLink } = await import('@/queries/commerceLinking')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchEntryLink('entry1')).rejects.toBeInstanceOf(ApiError)
  })

  // ── searchLinkEntries ────────────────────────────────────────────────────────────────────

  it('parses the five-field entry search projection', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Entries retrieved.',
        data: [{ uuid: 'entry1', title: 'About Us', content_type: 'page', status: 'draft', locale: 'en' }],
      }),
    )

    const { searchLinkEntries } = await import('@/queries/commerceLinking')
    const rows = await searchLinkEntries('about')

    expect(rows).toEqual([
      { uuid: 'entry1', title: 'About Us', content_type: 'page', status: 'draft', locale: 'en' },
    ])
  })

  it('sends q as a query parameter', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }))

    const { searchLinkEntries } = await import('@/queries/commerceLinking')
    await searchLinkEntries('widget')

    const requested = fetchMock.mock.calls[0]![0]
    const url = new URL(typeof requested === 'string' ? requested : (requested as Request).url, 'http://localhost')
    expect(url.searchParams.get('q')).toBe('widget')
  })

  it('throws ApiError when entry search fails (e.g. a 422 under 2 characters)', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Validation failed', errors: { q: ['too short'] } }, 422),
    )

    const { searchLinkEntries } = await import('@/queries/commerceLinking')
    const { ApiError } = await import('@/api/errors')
    await expect(searchLinkEntries('a')).rejects.toBeInstanceOf(ApiError)
  })

  // ── setProductLink — PUT body exactness with/without expected_entry_uuid ───────────────────

  it('sends only entry_uuid on a first-time link (no expected_entry_uuid on the wire)', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: true,
          message: 'Success',
          data: { uuid: 'link1', product_uuid: 'p1', entry_uuid: 'entry1', created_at: null, updated_at: null },
        },
        201,
      ),
    )

    const { setProductLink } = await import('@/queries/commerceLinking')
    const link = await setProductLink('p1', { entryUuid: 'entry1' })

    expect(link.entry_uuid).toBe('entry1')
    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ entry_uuid: 'entry1' })
  })

  it('includes expected_entry_uuid on the wire when relinking', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Success',
        data: { uuid: 'link2', product_uuid: 'p1', entry_uuid: 'entry2', created_at: null, updated_at: null },
      }),
    )

    const { setProductLink } = await import('@/queries/commerceLinking')
    await setProductLink('p1', { entryUuid: 'entry2', expectedEntryUuid: 'entry1' })

    const req = fetchMock.mock.calls[0]![0] as Request
    expect(await req.clone().json()).toEqual({ entry_uuid: 'entry2', expected_entry_uuid: 'entry1' })
  })

  it('surfaces a 409 LinkConflictException as a typed ApiError', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: false, message: 'expected_entry_uuid does not match the product’s current link.' }, 409),
    )

    const { setProductLink } = await import('@/queries/commerceLinking')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setProductLink('p1', { entryUuid: 'entry2', expectedEntryUuid: 'stale' })
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
  })

  // ── unlinkProduct ────────────────────────────────────────────────────────────────────────

  it('unlinks with no return value', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Success', data: { product_uuid: 'p1' } }),
    )

    const { unlinkProduct } = await import('@/queries/commerceLinking')
    await expect(unlinkProduct('p1')).resolves.toBeUndefined()
  })

  it('throws ApiError when unlink fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Could not unlink product: the link kept changing under concurrent modification.' }, 409),
    )

    const { unlinkProduct } = await import('@/queries/commerceLinking')
    const { ApiError } = await import('@/api/errors')
    await expect(unlinkProduct('p1')).rejects.toBeInstanceOf(ApiError)
  })
})
