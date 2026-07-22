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
// commerceDiscounts.spec.ts.
describe('commerce settings (shipping zones/locations/methods) query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  function locationBody(overrides: Record<string, unknown> = {}) {
    return { kind: 'country', value: 'US', ...overrides }
  }

  function methodBody(overrides: Record<string, unknown> = {}) {
    return {
      uuid: 'm1',
      zone_uuid: 'z1',
      kind: 'flat',
      label: 'Standard',
      config: { amount: 500 },
      position: 0,
      enabled: true,
      warnings: [],
      created_at: '2026-01-01 00:00:00',
      updated_at: null,
      ...overrides,
    }
  }

  function zoneBody(overrides: Record<string, unknown> = {}) {
    return {
      uuid: 'z1',
      name: 'Domestic',
      position: 0,
      revision: 0,
      locations: [locationBody()],
      methods: [methodBody()],
      shadows_later_zones: false,
      created_at: '2026-01-01 00:00:00',
      updated_at: null,
      ...overrides,
    }
  }

  // ── fetchShippingZones: envelope, params, ordering, normalization ───────────────────────────

  it('parses the real Response::paginated envelope and normalizes zones with their embedded locations/methods', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping zones retrieved',
        data: [zoneBody()],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchShippingZones } = await import('@/queries/commerceSettings')
    const page = await fetchShippingZones({ page: 1, perPage: 24 })

    expect(page.zones).toHaveLength(1)
    const zone = page.zones[0]!
    expect(zone.uuid).toBe('z1')
    expect(zone.name).toBe('Domestic')
    expect(zone.locations).toEqual([{ kind: 'country', value: 'US' }])
    expect(zone.methods).toHaveLength(1)
    expect(zone.methods[0]!.kind).toBe('flat')
    expect(zone.methods[0]!.config).toEqual({ amount: 500 })
    expect(page.total).toBe(1)
    expect(page.current_page).toBe(1)
    expect(page.per_page).toBe(24)
  })

  it('sends page/per_page as the exact ShippingZoneListQuery param set', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 2, per_page: 10, total: 0 }))

    const { fetchShippingZones } = await import('@/queries/commerceSettings')
    await fetchShippingZones({ page: 2, perPage: 10 })

    const requested = fetchMock.mock.calls[0]![0]
    const requestedUrl = typeof requested === 'string' ? requested : (requested as Request).url
    const url = new URL(requestedUrl, 'http://localhost')
    expect(url.pathname).toBe('/v1/admin/commerce/shipping/zones')
    expect(url.searchParams.get('page')).toBe('2')
    expect(url.searchParams.get('per_page')).toBe('10')
  })

  it('preserves the server-returned (position ASC, uuid ASC) evaluation order — never re-sorts', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping zones retrieved',
        data: [
          zoneBody({ uuid: 'z-catchall', name: 'Catch-all', position: 0 }),
          zoneBody({ uuid: 'z-us', name: 'United States', position: 1 }),
        ],
        current_page: 1,
        per_page: 24,
        total: 2,
      }),
    )

    const { fetchShippingZones } = await import('@/queries/commerceSettings')
    const page = await fetchShippingZones()

    expect(page.zones.map((z) => z.uuid)).toEqual(['z-catchall', 'z-us'])
  })

  it('normalizes shadows_later_zones true for an everywhere zone positioned before others', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping zones retrieved',
        data: [zoneBody({ locations: [], shadows_later_zones: true })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchShippingZones } = await import('@/queries/commerceSettings')
    const page = await fetchShippingZones()

    expect(page.zones[0]!.shadows_later_zones).toBe(true)
    expect(page.zones[0]!.locations).toEqual([])
  })

  it('defaults an empty page to zero total and the requested paging', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

    const { fetchShippingZones } = await import('@/queries/commerceSettings')
    const page = await fetchShippingZones({ page: 3, perPage: 50 })

    expect(page.zones).toEqual([])
    expect(page.total).toBe(0)
    expect(page.current_page).toBe(3)
    expect(page.per_page).toBe(50)
  })

  it('throws ApiError when the list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchShippingZones } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchShippingZones()).rejects.toBeInstanceOf(ApiError)
  })

  // ── fetchShippingZone (show): omits shadows_later_zones, normalizes to false ────────────────

  it('fetches and normalizes a single zone, defaulting the absent shadows_later_zones to false', async () => {
    const raw = zoneBody()
    delete (raw as Record<string, unknown>).shadows_later_zones
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Shipping zone retrieved', data: raw }),
    )

    const { fetchShippingZone } = await import('@/queries/commerceSettings')
    const zone = await fetchShippingZone('z1')

    expect(zone.uuid).toBe('z1')
    expect(zone.shadows_later_zones).toBe(false)
  })

  it('throws ApiError for a 404 zone', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchShippingZone } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchShippingZone('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── createShippingZone: exact CreateZoneData body ───────────────────────────────────────────

  it('createShippingZone posts the exact CreateZoneData body and normalizes the created zone', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Shipping zone created', data: zoneBody({ locations: [], methods: [] }) }, 201),
    )

    const { createShippingZone } = await import('@/queries/commerceSettings')
    const zone = await createShippingZone({ name: 'Domestic', position: null })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/shipping/zones')
    expect(await request.clone().json()).toEqual({ name: 'Domestic', position: null })
    expect(zone.uuid).toBe('z1')
  })

  // AdminShippingZoneController manually catches its own ValidationException and calls
  // Response::validation($e->firstErrors()) -> Response::error(...) -> the
  // {error: {details: {field: message}}} envelope — mirrors commerceDiscounts.ts's identical
  // business-rule-422 precedent (errors.ts's fieldErrorsFromDetails() docblock).
  it('createShippingZone surfaces a 422 duplicate-name rejection as a keyed field error', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { name: 'Name already in use.' },
          },
        },
        422,
      ),
    )

    const { createShippingZone } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createShippingZone({ name: 'Domestic' })
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).status).toBe(422)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors.name).toBe('Name already in use.')
  })

  // ── updateShippingZone: exact PATCH endpoint + partial body ─────────────────────────────────

  it('updateShippingZone PATCHes the exact endpoint with only the given keys and normalizes the result', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping zone updated',
        data: zoneBody({ name: 'Domestic Shipping', position: 5, revision: 1 }),
      }),
    )

    const { updateShippingZone } = await import('@/queries/commerceSettings')
    const zone = await updateShippingZone('z1', { name: 'Domestic Shipping', position: 5 })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('PATCH')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/shipping/zones/z1')
    expect(await request.clone().json()).toEqual({ name: 'Domestic Shipping', position: 5 })
    expect(zone.name).toBe('Domestic Shipping')
    expect(zone.position).toBe(5)
    expect(zone.revision).toBe(1)
  })

  it('updateShippingZone surfaces a 422 name-conflict rejection', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1', details: { name: 'Name already in use.' } },
        },
        422,
      ),
    )

    const { updateShippingZone } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(updateShippingZone('z2', { name: 'Domestic' })).rejects.toBeInstanceOf(ApiError)
  })

  it('updateShippingZone surfaces a 404 for an unknown or since-deleted zone', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { updateShippingZone } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(updateShippingZone('missing', { name: 'x' })).rejects.toBeInstanceOf(ApiError)
  })

  // ── deleteShippingZone: exact DELETE endpoint ────────────────────────────────────────────────

  it('deleteShippingZone DELETEs the exact endpoint', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    const { deleteShippingZone } = await import('@/queries/commerceSettings')
    await deleteShippingZone('z1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('DELETE')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/shipping/zones/z1')
  })

  // ── setShippingZoneLocations: exact PUT body + postcode/country validation responses ────────

  it('setShippingZoneLocations PUTs the exact endpoint + body and normalizes the fresh, server-uppercased set', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping zone locations updated',
        data: [{ kind: 'country', value: 'US' }],
      }),
    )

    const { setShippingZoneLocations } = await import('@/queries/commerceSettings')
    const locations = await setShippingZoneLocations('z1', [{ kind: 'country', value: 'us' }])

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('PUT')
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/shipping/zones/z1/locations',
    )
    expect(await request.clone().json()).toEqual({ locations: [{ kind: 'country', value: 'us' }] })
    expect(locations).toEqual([{ kind: 'country', value: 'US' }])
  })

  it('setShippingZoneLocations sends an empty list as a valid "everywhere zone" request', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Shipping zone locations updated', data: [] }),
    )

    const { setShippingZoneLocations } = await import('@/queries/commerceSettings')
    const locations = await setShippingZoneLocations('z1', [])

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(await request.clone().json()).toEqual({ locations: [] })
    expect(locations).toEqual([])
  })

  it('surfaces the exact postcode-without-country 422 field error verbatim', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: {
              locations: 'A zone with postcode_pattern locations must also include at least one country location.',
            },
          },
        },
        422,
      ),
    )

    const { setShippingZoneLocations } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setShippingZoneLocations('z1', [{ kind: 'postcode_pattern', value: '90210' }])
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).status).toBe(422)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors.locations).toBe(
      'A zone with postcode_pattern locations must also include at least one country location.',
    )
  })

  it('surfaces the exact malformed-country 422 field error verbatim', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { 'locations.0.value': 'country value must be an ISO-3166 alpha-2 code.' },
          },
        },
        422,
      ),
    )

    const { setShippingZoneLocations } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await setShippingZoneLocations('z1', [{ kind: 'country', value: 'USA' }])
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).fieldErrors['locations.0.value']).toBe(
      'country value must be an ISO-3166 alpha-2 code.',
    )
  })

  // ── fetchShippingZoneMethods (nested index) ─────────────────────────────────────────────────

  it('fetchShippingZoneMethods GETs the nested endpoint and normalizes the methods, ordered as returned', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping methods retrieved',
        data: [methodBody({ uuid: 'm-first', label: 'First', position: 0 }), methodBody({ uuid: 'm-second', label: 'Second', position: 1 })],
      }),
    )

    const { fetchShippingZoneMethods } = await import('@/queries/commerceSettings')
    const methods = await fetchShippingZoneMethods('z1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/shipping/zones/z1/methods',
    )
    expect(methods.map((m) => m.uuid)).toEqual(['m-first', 'm-second'])
  })

  // ── createShippingMethod: exact CreateMethodData body per kind ──────────────────────────────

  it('createShippingMethod posts the exact CreateMethodData body for a flat method', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Shipping method created', data: methodBody() }, 201),
    )

    const { createShippingMethod } = await import('@/queries/commerceSettings')
    const method = await createShippingMethod('z1', {
      kind: 'flat',
      label: 'Standard',
      config: { amount: 500 },
      position: null,
      enabled: null,
    })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/shipping/zones/z1/methods',
    )
    expect(await request.clone().json()).toEqual({
      kind: 'flat',
      label: 'Standard',
      config: { amount: 500 },
      position: null,
      enabled: null,
    })
    expect(method.config).toEqual({ amount: 500 })
    expect(method.warnings).toEqual([])
  })

  it('createShippingMethod normalizes warnings from an unknown per_class_table slug', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping method created',
        data: methodBody({
          kind: 'per_class_table',
          config: { default_amount: 500, classes: { fragile: 1000 } },
          warnings: ['Unknown shipping class slug: fragile'],
        }),
      }, 201),
    )

    const { createShippingMethod } = await import('@/queries/commerceSettings')
    const method = await createShippingMethod('z1', {
      kind: 'per_class_table',
      label: 'By class',
      config: { default_amount: 500, classes: { fragile: 1000 } },
    })

    expect(method.warnings).toEqual(['Unknown shipping class slug: fragile'])
  })

  it('createShippingMethod surfaces a 422 for a negative config amount', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { 'config.amount': 'config.amount must be a non-negative integer.' },
          },
        },
        422,
      ),
    )

    const { createShippingMethod } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createShippingMethod('z1', { kind: 'flat', label: 'Standard', config: { amount: -1 } })
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).fieldErrors['config.amount']).toBe(
      'config.amount must be a non-negative integer.',
    )
  })

  it('createShippingMethod surfaces a 404 for an unknown zone', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { createShippingMethod } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(
      createShippingMethod('missing', { kind: 'flat', label: 'Standard', config: { amount: 500 } }),
    ).rejects.toBeInstanceOf(ApiError)
  })

  // ── fetchShippingMethod (show) ───────────────────────────────────────────────────────────────

  it('fetches and normalizes a single method', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Shipping method retrieved', data: methodBody({ uuid: 'm1' }) }),
    )

    const { fetchShippingMethod } = await import('@/queries/commerceSettings')
    const method = await fetchShippingMethod('m1')

    expect(method.uuid).toBe('m1')
    expect(method.enabled).toBe(true)
  })

  it('throws ApiError for a 404 method', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchShippingMethod } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchShippingMethod('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── updateShippingMethod: exact PATCH endpoint + partial body (kind never sent) ─────────────

  it('updateShippingMethod PATCHes the exact endpoint with only the given keys and normalizes the result', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Shipping method updated', data: methodBody({ enabled: false }) }),
    )

    const { updateShippingMethod } = await import('@/queries/commerceSettings')
    const method = await updateShippingMethod('m1', { enabled: false })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('PATCH')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/shipping/methods/m1')
    expect(await request.clone().json()).toEqual({ enabled: false })
    expect(method.enabled).toBe(false)
  })

  it('updateShippingMethod re-validates config against the existing (immutable) kind, surfacing the 422', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: { code: 422, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1', details: { 'config.amount': 'config.amount must be a non-negative integer.' } },
        },
        422,
      ),
    )

    const { updateShippingMethod } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(updateShippingMethod('m1', { config: { amount: -5 } })).rejects.toBeInstanceOf(ApiError)
  })

  it('updateShippingMethod surfaces a 404 for an unknown method', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { updateShippingMethod } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(updateShippingMethod('missing', { enabled: false })).rejects.toBeInstanceOf(ApiError)
  })

  // ── deleteShippingMethod: exact DELETE endpoint ─────────────────────────────────────────────

  it('deleteShippingMethod DELETEs the exact endpoint', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    const { deleteShippingMethod } = await import('@/queries/commerceSettings')
    await deleteShippingMethod('m1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('DELETE')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/shipping/methods/m1')
  })

  // ── Normalization edge cases: strict types, no Number() coercion of amounts ─────────────────

  it('normalizes a method with a malformed (non-object) config to an empty object, never crashing', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping zones retrieved',
        data: [zoneBody({ methods: [methodBody({ config: 'not-an-object' })] })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchShippingZones } = await import('@/queries/commerceSettings')
    const page = await fetchShippingZones()

    expect(page.zones[0]!.methods[0]!.config).toEqual({})
  })

  it('normalizes enabled: false and an absent warnings array to []', async () => {
    const raw = methodBody({ enabled: false })
    delete (raw as Record<string, unknown>).warnings
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping zones retrieved',
        data: [zoneBody({ methods: [raw] })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchShippingZones } = await import('@/queries/commerceSettings')
    const page = await fetchShippingZones()

    expect(page.zones[0]!.methods[0]!.enabled).toBe(false)
    expect(page.zones[0]!.methods[0]!.warnings).toEqual([])
  })

  it('normalizes a zone with no locations or methods to empty arrays', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping zones retrieved',
        data: [zoneBody({ locations: [], methods: [] })],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchShippingZones } = await import('@/queries/commerceSettings')
    const page = await fetchShippingZones()

    expect(page.zones[0]!.locations).toEqual([])
    expect(page.zones[0]!.methods).toEqual([])
  })
})

// ── Task 15b: shipping classes ──────────────────────────────────────────────────────────────

describe('commerce settings (shipping classes) query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  function classBody(overrides: Record<string, unknown> = {}) {
    return {
      uuid: 'c1',
      slug: 'fragile',
      name: 'Fragile',
      revision: 0,
      created_at: '2026-01-01 00:00:00',
      updated_at: null,
      ...overrides,
    }
  }

  // ── fetchShippingClasses: envelope, params, normalization ───────────────────────────────────

  it('parses the real Response::paginated envelope and normalizes classes', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping classes retrieved',
        data: [classBody()],
        current_page: 1,
        per_page: 24,
        total: 1,
      }),
    )

    const { fetchShippingClasses } = await import('@/queries/commerceSettings')
    const page = await fetchShippingClasses({ page: 1, perPage: 24 })

    expect(page.classes).toHaveLength(1)
    const cls = page.classes[0]!
    expect(cls.uuid).toBe('c1')
    expect(cls.slug).toBe('fragile')
    expect(cls.name).toBe('Fragile')
    expect(page.total).toBe(1)
    expect(page.current_page).toBe(1)
    expect(page.per_page).toBe(24)
  })

  it('sends q/page/per_page as the exact ShippingClassListQuery param set', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ data: [], current_page: 2, per_page: 10, total: 0 }))

    const { fetchShippingClasses } = await import('@/queries/commerceSettings')
    await fetchShippingClasses({ q: 'frag', page: 2, perPage: 10 })

    const requested = fetchMock.mock.calls[0]![0]
    const requestedUrl = typeof requested === 'string' ? requested : (requested as Request).url
    const url = new URL(requestedUrl, 'http://localhost')
    expect(url.pathname).toBe('/v1/admin/commerce/shipping/classes')
    expect(url.searchParams.get('q')).toBe('frag')
    expect(url.searchParams.get('page')).toBe('2')
    expect(url.searchParams.get('per_page')).toBe('10')
  })

  it('defaults an empty page to zero total and the requested paging', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonResponse({ data: {} }))

    const { fetchShippingClasses } = await import('@/queries/commerceSettings')
    const page = await fetchShippingClasses({ page: 3, perPage: 50 })

    expect(page.classes).toEqual([])
    expect(page.total).toBe(0)
    expect(page.current_page).toBe(3)
    expect(page.per_page).toBe(50)
  })

  it('throws ApiError when the list request fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchShippingClasses } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchShippingClasses()).rejects.toBeInstanceOf(ApiError)
  })

  // ── fetchShippingClass (show) ────────────────────────────────────────────────────────────────

  it('fetches and normalizes a single class', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, message: 'Shipping class retrieved', data: classBody() }),
    )

    const { fetchShippingClass } = await import('@/queries/commerceSettings')
    const cls = await fetchShippingClass('c1')

    expect(cls.uuid).toBe('c1')
    expect(cls.slug).toBe('fragile')
  })

  it('throws ApiError for a 404 class', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { fetchShippingClass } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchShippingClass('missing')).rejects.toBeInstanceOf(ApiError)
  })

  // ── createShippingClass: exact CreateShippingClassData body ─────────────────────────────────

  it('createShippingClass posts the exact CreateShippingClassData body and normalizes the created class', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, message: 'Shipping class created', data: classBody() }, 201),
    )

    const { createShippingClass } = await import('@/queries/commerceSettings')
    const cls = await createShippingClass({ slug: 'fragile', name: 'Fragile' })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe('/v1/admin/commerce/shipping/classes')
    expect(await request.clone().json()).toEqual({ slug: 'fragile', name: 'Fragile' })
    expect(cls.uuid).toBe('c1')
  })

  it('createShippingClass surfaces a 422 duplicate-slug rejection as a keyed field error', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { slug: 'Slug already in use.' },
          },
        },
        422,
      ),
    )

    const { createShippingClass } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await createShippingClass({ slug: 'fragile', name: 'Fragile' })
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).status).toBe(422)
    expect((caught as InstanceType<typeof ApiError>).fieldErrors.slug).toBe('Slug already in use.')
  })

  // ── updateShippingClass: exact PATCH endpoint + name-only body ──────────────────────────────

  it('updateShippingClass PATCHes the exact endpoint with only name and normalizes the result', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'Shipping class updated',
        data: classBody({ name: 'Extra Fragile', revision: 1 }),
      }),
    )

    const { updateShippingClass } = await import('@/queries/commerceSettings')
    const cls = await updateShippingClass('c1', { name: 'Extra Fragile' })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('PATCH')
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/shipping/classes/c1',
    )
    expect(await request.clone().json()).toEqual({ name: 'Extra Fragile' })
    expect(cls.name).toBe('Extra Fragile')
    expect(cls.revision).toBe(1)
  })

  it('updateShippingClass surfaces a 422 for an attempted slug change', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Validation failed',
          error: {
            code: 422,
            timestamp: '2026-01-01T00:00:00Z',
            request_id: 'req_1',
            details: { slug: 'slug is immutable and cannot be changed after creation.' },
          },
        },
        422,
      ),
    )

    const { updateShippingClass } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await updateShippingClass('c1', { name: 'x', slug: 'new-slug' } as never)
    } catch (e) {
      caught = e
    }
    expect((caught as InstanceType<typeof ApiError>).fieldErrors.slug).toBe(
      'slug is immutable and cannot be changed after creation.',
    )
  })

  it('updateShippingClass surfaces a 404 for an unknown or since-deleted class', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { updateShippingClass } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(updateShippingClass('missing', { name: 'x' })).rejects.toBeInstanceOf(ApiError)
  })

  // ── deleteShippingClass: exact DELETE endpoint + the referenced-class 409 ───────────────────

  it('deleteShippingClass DELETEs the exact endpoint', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    const { deleteShippingClass } = await import('@/queries/commerceSettings')
    await deleteShippingClass('c1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('DELETE')
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/shipping/classes/c1',
    )
  })

  it('deleteShippingClass surfaces the server 409 message verbatim when still referenced by a variant', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'This shipping class is still assigned to one or more variants. Detach it first.',
          error: { code: 409, timestamp: '2026-01-01T00:00:00Z', request_id: 'req_1' },
        },
        409,
      ),
    )

    const { deleteShippingClass } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    let caught: unknown
    try {
      await deleteShippingClass('c1')
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(ApiError)
    expect((caught as InstanceType<typeof ApiError>).status).toBe(409)
    expect((caught as InstanceType<typeof ApiError>).message).toBe(
      'This shipping class is still assigned to one or more variants. Detach it first.',
    )
  })

  it('deleteShippingClass surfaces a 404 for an unknown class', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Resource not found.' }, 404),
    )

    const { deleteShippingClass } = await import('@/queries/commerceSettings')
    const { ApiError } = await import('@/api/errors')
    await expect(deleteShippingClass('missing')).rejects.toBeInstanceOf(ApiError)
  })
})
