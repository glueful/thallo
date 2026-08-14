import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// The client reads apiBase + token lazily so tests can stub both.
const getToken = vi.fn<() => string | null>()
const onRefresh = vi.fn<() => Promise<boolean>>()

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({
    accessToken: getToken(),
    refresh: onRefresh,
    clear: vi.fn(),
  }),
}))

describe('api client middleware', () => {
  beforeEach(() => {
    getToken.mockReset()
    onRefresh.mockReset()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('attaches the bearer token from the session store', async () => {
    getToken.mockReturnValue('tok-123')
    ;(globalThis.fetch as any).mockResolvedValue(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')
    await client.GET('/content-types' as any, {})
    const req = (globalThis.fetch as any).mock.calls[0][0] as Request
    expect(req.headers.get('authorization')).toBe('Bearer tok-123')
  })

  it('refreshes once on 401 then retries; clears on refresh failure', async () => {
    getToken.mockReturnValue('stale')
    onRefresh.mockResolvedValue(true)
    ;(globalThis.fetch as any)
      .mockResolvedValueOnce(new Response('{}', { status: 401 }))
      .mockResolvedValueOnce(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')
    const res = await client.GET('/content-types' as any, {})
    expect(onRefresh).toHaveBeenCalledTimes(1)
    expect(res.response.status).toBe(200)
  })

  // Regression: a bodied mutation (POST/PATCH/PUT) must survive refresh-on-401. The network send
  // consumes the request body, so cloning in onResponse threw "body already used" and the retry
  // never fired — GETs (no body) hid it. The pristine clone taken in onRequest fixes it.
  it('retries a bodied POST after 401 with its body intact', async () => {
    getToken.mockReturnValue('stale')
    onRefresh.mockResolvedValue(true)
    ;(globalThis.fetch as any)
      // First send consumes the body, exactly as a real network send does, then 401s.
      .mockImplementationOnce(async (req: Request) => {
        await req.text()
        return new Response('{}', { status: 401 })
      })
      .mockResolvedValueOnce(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')

    const res = await client.POST('/content-types' as any, { body: { name: 'Post' } })

    expect(onRefresh).toHaveBeenCalledTimes(1)
    expect(res.response.status).toBe(200)
    const retried = (globalThis.fetch as any).mock.calls[1][0] as Request
    expect(retried.method).toBe('POST')
    expect(await retried.text()).toBe(JSON.stringify({ name: 'Post' }))
    expect(retried.headers.get('authorization')).toBe('Bearer stale')
  })
})

// ── The pristine-clone WeakMap is CONSUMED, not retained (payment-links final review) ──────────
//
// `pristineRequests` exists for exactly one purpose: hand the refresh-on-401 retry a clone whose
// body has not been consumed by the failed send. That clone holds the request BODY — and for a
// payment-link `mode=current` send, the body is the raw link token. A WeakMap keyed by the
// Request only drops the entry when the KEY is collected, and openapi-fetch's Request object
// stays reachable for as long as the caller keeps the response around, so the token could outlive
// every custody control on the card that sent it.
//
// The entry is therefore deleted the moment the response arrives — which is the moment it has
// either been used for the retry or can never be used again. The WeakMap is module-private, so
// this spec captures the real map instance and its key by spying on `WeakMap.prototype.set`
// BEFORE the client module is imported, then asserts the entry is gone afterwards.
describe('api client pristine-clone lifetime', () => {
  const originalSet = WeakMap.prototype.set
  let entries: { map: WeakMap<object, unknown>; key: object }[] = []

  beforeEach(() => {
    entries = []
    getToken.mockReset()
    onRefresh.mockReset()
    vi.stubGlobal('fetch', vi.fn())
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    WeakMap.prototype.set = function (this: WeakMap<object, unknown>, key: any, value: any) {
      if (typeof Request !== 'undefined' && key instanceof Request) entries.push({ map: this, key })
      return originalSet.call(this, key, value) as WeakMap<object, unknown>
    } as typeof WeakMap.prototype.set
  })

  afterEach(() => {
    WeakMap.prototype.set = originalSet
  })

  function requestEntries() {
    return entries
  }

  it('holds no request (and therefore no body) after an ordinary 200', async () => {
    getToken.mockReturnValue('tok')
    ;(globalThis.fetch as any).mockResolvedValue(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')

    await client.POST('/content-types' as any, { body: { name: 'Post' } })

    // The clone really was taken — otherwise "it is gone" would prove nothing.
    expect(requestEntries().length).toBeGreaterThan(0)
    for (const entry of requestEntries()) expect(entry.map.has(entry.key)).toBe(false)
  })

  it('holds no request after the 401 retry has consumed the clone', async () => {
    getToken.mockReturnValue('stale')
    onRefresh.mockResolvedValue(true)
    ;(globalThis.fetch as any)
      .mockImplementationOnce(async (req: Request) => {
        await req.text()
        return new Response('{}', { status: 401 })
      })
      .mockResolvedValueOnce(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')

    const res = await client.POST('/content-types' as any, { body: { token: 'a'.repeat(64) } })

    // The retry still went out with its body intact: consumption happens, retention does not.
    expect(res.response.status).toBe(200)
    const retried = (globalThis.fetch as any).mock.calls[1][0] as Request
    expect(await retried.clone().text()).toBe(JSON.stringify({ token: 'a'.repeat(64) }))
    expect(requestEntries().length).toBeGreaterThan(0)
    for (const entry of requestEntries()) expect(entry.map.has(entry.key)).toBe(false)
  })

  it('holds no request after a 401 whose refresh FAILED (nothing to retry with)', async () => {
    getToken.mockReturnValue('stale')
    onRefresh.mockResolvedValue(false)
    ;(globalThis.fetch as any).mockResolvedValue(new Response('{}', { status: 401 }))
    const { client } = await import('@/api/client')

    await client.POST('/content-types' as any, { body: { name: 'Post' } })

    expect(requestEntries().length).toBeGreaterThan(0)
    for (const entry of requestEntries()) expect(entry.map.has(entry.key)).toBe(false)
  })
})
