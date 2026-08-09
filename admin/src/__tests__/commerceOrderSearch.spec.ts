import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, ref, type Ref } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'

// Mirrors analyticsEnabledGate.spec.ts's established pattern for testing a pinia-colada query
// directly: mock authFetch + runtimeConfig, install a real Pinia + PiniaColada plugin, and drive
// the composable through a tiny host component.

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

const sessionState = vi.hoisted(() => ({ accessToken: 'test-token' as string | null }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => sessionState }))

import {
  ORDER_SEARCH_DEFAULTS,
  parseOrderSearchQuery,
  serializeOrderSearchQuery,
  fetchOrderSearch,
  useOrderSearch,
  downloadOrdersCsv,
  ExportTooLargeError,
  type OrderSearchFilters,
} from '@/queries/commerceOrderSearch'

function mountQuery(state: Ref<OrderSearchFilters>) {
  const Host = defineComponent({
    setup() {
      useOrderSearch(state)
      return () => h('div')
    },
  })
  return mount(Host, { global: { plugins: [createPinia(), PiniaColada] } })
}

describe('fetchOrderSearch', () => {
  beforeEach(() => {
    authFetch.mockReset().mockResolvedValue({
      data: [
        {
          uuid: 'o1',
          order_number: 'ORD-1001',
          status: 'paid',
          fulfillment_status: 'unfulfilled',
          email: 'a@example.com',
          currency: 'USD',
          grand_total: 5900,
          placed_at: '2026-01-01 00:00:00',
        },
      ],
      current_page: 1,
      per_page: 24,
      total: 1,
    })
  })

  it('calls the search endpoint with status/fulfillment/date/q/page/per_page params', async () => {
    await fetchOrderSearch({
      ...ORDER_SEARCH_DEFAULTS,
      status: 'paid',
      fulfillment: 'fulfilled',
      placedFrom: '2026-01-01',
      placedTo: '2026-01-31',
      q: 'ORD-1',
      page: 2,
      perPage: 50,
    })

    expect(authFetch).toHaveBeenCalledTimes(1)
    const url = authFetch.mock.calls[0]![0] as string
    expect(url).toContain('/v1/admin/commerce/orders/search')
    expect(url).toContain('status=paid')
    expect(url).toContain('fulfillment_status=fulfilled')
    expect(url).toContain('placed_from=2026-01-01')
    expect(url).toContain('placed_to=2026-01-31')
    expect(url).toContain('q=ORD-1')
    expect(url).toContain('page=2')
    expect(url).toContain('per_page=50')
  })

  it('omits status/fulfillment/dates/q from the request when unset (defaults)', async () => {
    await fetchOrderSearch(ORDER_SEARCH_DEFAULTS)
    const url = authFetch.mock.calls[0]![0] as string
    expect(url).not.toContain('status=')
    expect(url).not.toContain('fulfillment_status=')
    expect(url).not.toContain('placed_from=')
    expect(url).not.toContain('placed_to=')
    expect(url).not.toMatch(/[?&]q=/)
  })

  it('normalizes rows into the CommerceOrder shape the table expects', async () => {
    const page = await fetchOrderSearch(ORDER_SEARCH_DEFAULTS)
    expect(page.orders).toHaveLength(1)
    expect(page.orders[0]).toMatchObject({
      uuid: 'o1',
      order_number: 'ORD-1001',
      status: 'paid',
      fulfillment_status: 'unfulfilled',
      email: 'a@example.com',
      grand_total: 5900,
    })
    expect(page.total).toBe(1)
    expect(page.current_page).toBe(1)
    expect(page.per_page).toBe(24)
  })
})

describe('useOrderSearch query key', () => {
  beforeEach(() => {
    authFetch.mockReset().mockResolvedValue({ data: [], current_page: 1, per_page: 24, total: 0 })
  })

  it('refetches when a scalar filter value changes, but not for an equal-value new object', async () => {
    const state = ref<OrderSearchFilters>({ ...ORDER_SEARCH_DEFAULTS })
    mountQuery(state)
    await flushPromises()
    expect(authFetch).toHaveBeenCalledTimes(1)

    // A brand-new object with the SAME scalar values must not be treated as a cache miss —
    // the key is built from normalized scalars, never object identity.
    state.value = { ...ORDER_SEARCH_DEFAULTS }
    await flushPromises()
    expect(authFetch).toHaveBeenCalledTimes(1)

    // Changing an actual scalar value does refetch.
    state.value = { ...ORDER_SEARCH_DEFAULTS, status: 'paid' }
    await flushPromises()
    expect(authFetch).toHaveBeenCalledTimes(2)
  })
})

describe('parseOrderSearchQuery (URL hydration contract)', () => {
  it('adopts a fully valid query verbatim', () => {
    const result = parseOrderSearchQuery({
      status: 'paid',
      fulfillment: 'fulfilled',
      placed_from: '2026-01-01',
      placed_to: '2026-01-31',
      q: 'ORD-1',
      page: '3',
      per_page: '50',
    })
    expect(result).toEqual({
      q: 'ORD-1',
      status: 'paid',
      fulfillment: 'fulfilled',
      placedFrom: '2026-01-01',
      placedTo: '2026-01-31',
      page: 3,
      perPage: 50,
    })
  })

  it('falls back to every default when the query is empty', () => {
    expect(parseOrderSearchQuery({})).toEqual(ORDER_SEARCH_DEFAULTS)
  })

  it('discards an invalid status enum back to null', () => {
    expect(parseOrderSearchQuery({ status: 'bogus' }).status).toBeNull()
  })

  it('discards an invalid fulfillment enum back to null', () => {
    expect(parseOrderSearchQuery({ fulfillment: 'bogus' }).fulfillment).toBeNull()
  })

  it.each([
    ['2026-02-30', 'impossible day-of-month'],
    ['2026-13-01', 'impossible month'],
    ['26-01-01', 'two-digit year'],
    ['2026/01/01', 'wrong separators'],
    ['not-a-date', 'garbage'],
  ])('discards an impossible/malformed placed_from "%s" (%s) back to null', (value) => {
    expect(parseOrderSearchQuery({ placed_from: value }).placedFrom).toBeNull()
  })

  it('discards an impossible placed_to back to null', () => {
    expect(parseOrderSearchQuery({ placed_to: '2026-02-30' }).placedTo).toBeNull()
  })

  it('accepts a valid placed_to', () => {
    expect(parseOrderSearchQuery({ placed_to: '2026-02-28' }).placedTo).toBe('2026-02-28')
  })

  it('discards page 0 back to the default page', () => {
    expect(parseOrderSearchQuery({ page: '0' }).page).toBe(ORDER_SEARCH_DEFAULTS.page)
  })

  it('discards a negative page back to the default page', () => {
    expect(parseOrderSearchQuery({ page: '-1' }).page).toBe(ORDER_SEARCH_DEFAULTS.page)
  })

  it('discards a non-numeric page back to the default page', () => {
    expect(parseOrderSearchQuery({ page: 'abc' }).page).toBe(ORDER_SEARCH_DEFAULTS.page)
  })

  it('discards per_page 101 back to the default per_page', () => {
    expect(parseOrderSearchQuery({ per_page: '101' }).perPage).toBe(ORDER_SEARCH_DEFAULTS.perPage)
  })

  it('discards per_page 0 back to the default per_page', () => {
    expect(parseOrderSearchQuery({ per_page: '0' }).perPage).toBe(ORDER_SEARCH_DEFAULTS.perPage)
  })

  it('accepts per_page at the boundaries (1 and 100)', () => {
    expect(parseOrderSearchQuery({ per_page: '1' }).perPage).toBe(1)
    expect(parseOrderSearchQuery({ per_page: '100' }).perPage).toBe(100)
  })

  it('accepts page at large valid values', () => {
    expect(parseOrderSearchQuery({ page: '999' }).page).toBe(999)
  })

  it('passes q through as-is (the server enforces the 200-char cap with a 422)', () => {
    expect(parseOrderSearchQuery({ q: 'anything' }).q).toBe('anything')
  })
})

describe('serializeOrderSearchQuery (canonical URL contract)', () => {
  it('omits every default/null value for the default filter set', () => {
    expect(serializeOrderSearchQuery(ORDER_SEARCH_DEFAULTS)).toEqual({})
  })

  it('includes only the non-default fields', () => {
    expect(
      serializeOrderSearchQuery({ ...ORDER_SEARCH_DEFAULTS, status: 'paid', page: 3 }),
    ).toEqual({ status: 'paid', page: '3' })
  })

  it('serializes every field when all are non-default', () => {
    expect(
      serializeOrderSearchQuery({
        q: 'ORD-1',
        status: 'paid',
        fulfillment: 'fulfilled',
        placedFrom: '2026-01-01',
        placedTo: '2026-01-31',
        page: 2,
        perPage: 50,
      }),
    ).toEqual({
      q: 'ORD-1',
      status: 'paid',
      fulfillment: 'fulfilled',
      placed_from: '2026-01-01',
      placed_to: '2026-01-31',
      page: '2',
      per_page: '50',
    })
  })

  it('round-trips through parseOrderSearchQuery for a non-default filter set', () => {
    const filters: OrderSearchFilters = {
      q: 'ada',
      status: 'paid',
      fulfillment: 'unfulfilled',
      placedFrom: '2026-01-01',
      placedTo: '2026-01-31',
      page: 4,
      perPage: 10,
    }
    expect(parseOrderSearchQuery(serializeOrderSearchQuery(filters))).toEqual(filters)
  })
})

describe('ExportTooLargeError', () => {
  it('is a genuine Error subclass carrying the server message', () => {
    const err = new ExportTooLargeError('Export exceeds 10,000 rows — narrow your filters.')
    expect(err).toBeInstanceOf(Error)
    expect(err).toBeInstanceOf(ExportTooLargeError)
    expect(err.name).toBe('ExportTooLargeError')
    expect(err.message).toBe('Export exceeds 10,000 rows — narrow your filters.')
  })
})

describe('downloadOrdersCsv', () => {
  let createObjectURL: ReturnType<typeof vi.fn>
  let revokeObjectURL: ReturnType<typeof vi.fn>
  let clickSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    createObjectURL = vi.fn().mockReturnValue('blob:mock-url')
    revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL })
    clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    sessionState.accessToken = 'test-token'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    clickSpy.mockRestore()
  })

  it('checks for a 422 BEFORE reading the body as a blob, and throws ExportTooLargeError with the exact server message', async () => {
    const blobSpy = vi.fn()
    const res = {
      status: 422,
      ok: false,
      json: () => Promise.resolve({ message: 'Export exceeds 10,000 rows — narrow your filters.' }),
      blob: blobSpy,
    } as unknown as Response
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(res))

    const err = await downloadOrdersCsv(ORDER_SEARCH_DEFAULTS).catch((e: unknown) => e)
    expect(err).toBeInstanceOf(ExportTooLargeError)
    expect((err as Error).message).toBe('Export exceeds 10,000 rows — narrow your filters.')
    expect(blobSpy).not.toHaveBeenCalled()
  })

  it('falls back to a generic message when a 422 body carries no message', async () => {
    const res = {
      status: 422,
      ok: false,
      json: () => Promise.resolve({}),
      blob: vi.fn(),
    } as unknown as Response
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(res))

    const err = await downloadOrdersCsv(ORDER_SEARCH_DEFAULTS).catch((e: unknown) => e)
    expect(err).toBeInstanceOf(ExportTooLargeError)
    expect((err as Error).message).toContain('Export exceeds 10,000 rows')
  })

  it('downloads the CSV, creating and revoking an object URL, on success', async () => {
    const blob = new Blob(['a,b\n1,2'], { type: 'text/csv' })
    const res = {
      status: 200,
      ok: true,
      json: () => Promise.resolve({}),
      blob: () => Promise.resolve(blob),
    } as unknown as Response
    const fetchMock = vi.fn().mockResolvedValue(res)
    vi.stubGlobal('fetch', fetchMock)
    const createElementSpy = vi.spyOn(document, 'createElement')

    await downloadOrdersCsv(ORDER_SEARCH_DEFAULTS)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toContain('/v1/admin/commerce/orders/export')
    expect((init as RequestInit).headers).toMatchObject({ authorization: 'Bearer test-token' })

    expect(createObjectURL).toHaveBeenCalledWith(blob)
    expect(clickSpy).toHaveBeenCalledTimes(1)
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:mock-url')

    const anchor = createElementSpy.mock.results.find(
      (r) => (r.value as HTMLElement).tagName === 'A',
    )?.value as HTMLAnchorElement
    expect(anchor.download).toBe('orders-export.csv')
  })

  it('sends no authorization header when there is no session token', async () => {
    sessionState.accessToken = null
    const blob = new Blob(['x'], { type: 'text/csv' })
    const fetchMock = vi.fn().mockResolvedValue({
      status: 200,
      ok: true,
      json: () => Promise.resolve({}),
      blob: () => Promise.resolve(blob),
    } as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await downloadOrdersCsv(ORDER_SEARCH_DEFAULTS)

    const [, init] = fetchMock.mock.calls[0]!
    expect((init as RequestInit).headers).toEqual({})
  })

  it('throws a generic error for a non-422 failure, via the shared responseError path', async () => {
    const res = {
      status: 500,
      ok: false,
      clone() {
        return this
      },
      json: () => Promise.resolve({ message: 'Server error' }),
      blob: vi.fn(),
    } as unknown as Response
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(res))

    await expect(downloadOrdersCsv(ORDER_SEARCH_DEFAULTS)).rejects.toThrow('Server error')
  })
})
