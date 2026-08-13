import { describe, it, expect, vi, beforeEach } from 'vitest'

// `GET /commerce/meta` normalization. Payment links Task 12 made `email_available` a MANDATORY
// field of the server's response (CommerceMetaController emits it unconditionally); Task 13's
// payment-link card reads it as one of the three independent Send preconditions, so it is a
// required field of `CommerceMeta` here — normalized to a hard boolean, never undefined.

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

describe('commerce meta query', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('normalizes email_available from the wire', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        data: {
          currency: 'GHS',
          currency_exponent: 2,
          shop_index_url: 'https://shop.test/',
          low_stock_threshold: 3,
          can_view: true,
          can_manage: true,
          can_attach_user: false,
          email_available: true,
        },
      }),
    )
    const { fetchCommerceMeta } = await import('./commerceMeta')
    const meta = await fetchCommerceMeta()

    expect(meta.email_available).toBe(true)
  })

  it('defaults email_available to false when the key is absent or not a boolean', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ success: true, data: { currency: 'USD' } }))
    const { fetchCommerceMeta } = await import('./commerceMeta')
    const meta = await fetchCommerceMeta()

    expect(meta.email_available).toBe(false)
  })
})
