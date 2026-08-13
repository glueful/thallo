import { describe, it, expect, vi, beforeEach } from 'vitest'

// Payment links Task 13/14: the SPA's payment-link query surface. Task 14 moved it from raw
// `authFetch` onto the typed `client` now that `docs/openapi.json`/`schema.d.ts` document all
// four routes. The typed client captures `globalThis.fetch` at module-load time, so every test
// here stubs `fetch` and dynamic-imports the module after the stub is in place (mirrors
// commerceInvoice.spec.ts / commerceOrders.spec.ts) — and asserts on the `Request` object openapi-
// fetch hands to `fetch`, not a bare path string.

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

const TOKEN = 'a'.repeat(64)

function linkBody(overrides: Record<string, unknown> = {}) {
  return {
    link_uuid: 'link-1',
    status: 'active',
    expires_at: '2026-08-19 12:00:00',
    provider_session_issued: false,
    ...overrides,
  }
}

function receiptBody(overrides: Record<string, unknown> = {}) {
  return {
    delivery_uuid: 'del-1',
    order_uuid: 'o1',
    link_uuid: 'link-1',
    mode: 'current',
    status: 'sent',
    error_code: null,
    provider_message_id: 'msg-1',
    replayed: false,
    created_at: '2026-08-12 10:00:00',
    updated_at: '2026-08-12 10:00:01',
    ...overrides,
  }
}

describe('commerce payment-link query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  // ── GET /orders/{uuid}/payment-link (show) ───────────────────────────────────────────────────

  it('fetchOrderPaymentLink GETs the exact endpoint and normalizes link + exposure', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        data: {
          link: linkBody({ provider_session_issued: true }),
          exposure: {
            reason: 'session_exposed',
            blocks_automatic_cancellation: true,
            requires_risk_acknowledgement: true,
          },
        },
      }),
    )
    const { fetchOrderPaymentLink } = await import('./commercePaymentLinks')
    const result = await fetchOrderPaymentLink('o1')

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('GET')
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/orders/o1/payment-link',
    )
    expect(result.link).toEqual({
      link_uuid: 'link-1',
      status: 'active',
      expires_at: '2026-08-19 12:00:00',
      provider_session_issued: true,
    })
    expect(result.exposure).toEqual({
      reason: 'session_exposed',
      blocks_automatic_cancellation: true,
      requires_risk_acknowledgement: true,
    })
  })

  it('fetchOrderPaymentLink tolerates a null link and a missing exposure block', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ success: true, data: { link: null } }))
    const { fetchOrderPaymentLink } = await import('./commercePaymentLinks')
    const result = await fetchOrderPaymentLink('o1')

    expect(result.link).toBeNull()
    expect(result.exposure).toEqual({
      reason: 'none',
      blocks_automatic_cancellation: false,
      requires_risk_acknowledgement: false,
    })
  })

  it('fetchOrderPaymentLink never surfaces a token even if the wire somehow carried one', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        data: {
          link: { ...linkBody(), token: TOKEN, url: `https://shop.test/pay/${TOKEN}` },
          exposure: { reason: 'active_link', blocks_automatic_cancellation: true, requires_risk_acknowledgement: false },
        },
      }),
    )
    const { fetchOrderPaymentLink } = await import('./commercePaymentLinks')
    const result = await fetchOrderPaymentLink('o1')

    expect(JSON.stringify(result)).not.toContain(TOKEN)
    expect(Object.keys(result.link ?? {})).toEqual([
      'link_uuid',
      'status',
      'expires_at',
      'provider_session_issued',
    ])
  })

  // ── POST /orders/{uuid}/payment-link (mint) ──────────────────────────────────────────────────

  it('createOrderPaymentLink POSTs the clamped ttl and returns the ONE-TIME url', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse(
        { success: true, data: { url: `https://shop.test/pay/${TOKEN}`, link: linkBody() } },
        201,
      ),
    )
    const { createOrderPaymentLink } = await import('./commercePaymentLinks')
    const result = await createOrderPaymentLink('o1', 7)

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/orders/o1/payment-link',
    )
    expect(await request.clone().json()).toEqual({ ttl_days: 7 })
    expect(result.url).toBe(`https://shop.test/pay/${TOKEN}`)
    expect(result.link.link_uuid).toBe('link-1')
  })

  it('createOrderPaymentLink omits ttl_days entirely when none is given', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, data: { url: 'https://shop.test/pay/x', link: linkBody() } }, 201),
    )
    const { createOrderPaymentLink } = await import('./commercePaymentLinks')
    await createOrderPaymentLink('o1', null)

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(await request.clone().json()).toEqual({})
  })

  it('createOrderPaymentLink surfaces the refusal reason from error.details', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Only admin-origin orders can carry a payment link.',
          error: { details: { reason: 'order_not_admin_origin' } },
        },
        409,
      ),
    )
    const { createOrderPaymentLink, paymentLinkRefusalReason } = await import('./commercePaymentLinks')

    // One call, one caught error — the mocked Response's body can only be read once (the typed
    // client reads the error body directly rather than cloning it, unlike the pre-migration
    // authFetch path), so a second call against the same mocked value would see an already-used
    // stream. Mirrors subscriptionsBilling.spec.ts's identical single-catch idiom.
    let caught: unknown
    try {
      await createOrderPaymentLink('o1', 7)
    } catch (e) {
      caught = e
    }
    expect(caught).toMatchObject({ status: 409 })
    expect(paymentLinkRefusalReason(caught)).toBe('order_not_admin_origin')
  })

  // ── DELETE /orders/{uuid}/payment-link (revoke) ──────────────────────────────────────────────

  it('revokeOrderPaymentLink DELETEs the exact endpoint', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(jsonResponse({ success: true, data: { order_uuid: 'o1' } }))
    const { revokeOrderPaymentLink } = await import('./commercePaymentLinks')
    await revokeOrderPaymentLink('o1')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('DELETE')
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/orders/o1/payment-link',
    )
  })

  // ── POST /orders/{uuid}/payment-link/send ────────────────────────────────────────────────────

  it('sendOrderPaymentLink posts mode=current with the token and the Idempotency-Key header', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'the payment link was emailed.',
        data: { receipt: receiptBody(), link: linkBody(), url: null, recovery: null },
      }),
    )
    const { sendOrderPaymentLink } = await import('./commercePaymentLinks')
    const envelope = await sendOrderPaymentLink('o1', { mode: 'current', token: TOKEN }, 'key-0123456789abcdef')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url, 'http://localhost').pathname).toBe(
      '/v1/admin/commerce/orders/o1/payment-link/send',
    )
    expect(request.headers.get('idempotency-key')).toBe('key-0123456789abcdef')
    expect(await request.clone().json()).toEqual({ mode: 'current', token: TOKEN })
    expect(envelope.http_status).toBe(200)
    expect(envelope.receipt.status).toBe('sent')
    expect(envelope.url).toBeNull()
  })

  it('sendOrderPaymentLink posts mode=regenerate with ttl_days and never a token', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        message: 'the payment link was emailed.',
        data: { receipt: receiptBody({ mode: 'regenerate' }), link: linkBody(), url: null, recovery: null },
      }),
    )
    const { sendOrderPaymentLink } = await import('./commercePaymentLinks')
    await sendOrderPaymentLink('o1', { mode: 'regenerate', ttl_days: 14 }, 'key-0123456789abcdef')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(await request.clone().json()).toEqual({
      mode: 'regenerate',
      ttl_days: 14,
    })
  })

  it('sendOrderPaymentLink returns the 502 delivery-failure envelope (url intact) instead of throwing', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'the payment link was created but could not be emailed; copy the link and send it manually.',
          data: {
            receipt: receiptBody({ mode: 'regenerate', status: 'failed', error_code: 'send_failed' }),
            link: linkBody(),
            url: `https://shop.test/pay/${TOKEN}`,
            recovery: null,
          },
        },
        502,
      ),
    )
    const { sendOrderPaymentLink } = await import('./commercePaymentLinks')
    const envelope = await sendOrderPaymentLink('o1', { mode: 'regenerate' }, 'key-0123456789abcdef')

    expect(envelope.http_status).toBe(502)
    expect(envelope.receipt.status).toBe('failed')
    expect(envelope.url).toBe(`https://shop.test/pay/${TOKEN}`)
  })

  it('sendOrderPaymentLink returns a REPLAYED failure envelope with its recovery instruction', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'Replayed: the payment link could not be emailed.',
          data: {
            receipt: receiptBody({ status: 'failed', error_code: 'send_failed', replayed: true }),
            link: null,
            url: null,
            recovery: 'use_a_new_idempotency_key_or_regenerate',
          },
        },
        502,
      ),
    )
    const { sendOrderPaymentLink } = await import('./commercePaymentLinks')
    const envelope = await sendOrderPaymentLink('o1', { mode: 'current', token: TOKEN }, 'key-0123456789abcdef')

    expect(envelope.receipt.replayed).toBe(true)
    expect(envelope.recovery).toBe('use_a_new_idempotency_key_or_regenerate')
    expect(envelope.url).toBeNull()
  })

  it('sendOrderPaymentLink throws a reason-carrying refusal when the body has no receipt', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: 'This payment link is no longer the order’s current one.',
          error: { details: { reason: 'payment_link_changed' } },
        },
        409,
      ),
    )
    const { sendOrderPaymentLink, paymentLinkRefusalReason } = await import('./commercePaymentLinks')

    try {
      await sendOrderPaymentLink('o1', { mode: 'current', token: TOKEN }, 'key-0123456789abcdef')
      expect.unreachable('a refusal without a receipt must throw')
    } catch (e) {
      expect(paymentLinkRefusalReason(e)).toBe('payment_link_changed')
    }
  })

  // ── Pure helpers ─────────────────────────────────────────────────────────────────────────────

  it('clampPaymentLinkTtl clamps to 1..30 and falls back to the default for non-numbers', async () => {
    const { clampPaymentLinkTtl, PAYMENT_LINK_TTL_DEFAULT } = await import('./commercePaymentLinks')

    expect(clampPaymentLinkTtl(0)).toBe(1)
    expect(clampPaymentLinkTtl(-5)).toBe(1)
    expect(clampPaymentLinkTtl(1)).toBe(1)
    expect(clampPaymentLinkTtl(30)).toBe(30)
    expect(clampPaymentLinkTtl(31)).toBe(30)
    expect(clampPaymentLinkTtl(7.6)).toBe(7)
    expect(clampPaymentLinkTtl(Number.NaN)).toBe(PAYMENT_LINK_TTL_DEFAULT)
    expect(clampPaymentLinkTtl('')).toBe(PAYMENT_LINK_TTL_DEFAULT)
    expect(clampPaymentLinkTtl(null)).toBe(PAYMENT_LINK_TTL_DEFAULT)
    expect(PAYMENT_LINK_TTL_DEFAULT).toBe(7)
  })

  it('paymentLinkTokenFromUrl parses with the URL API and shape-gates the final segment', async () => {
    const { paymentLinkTokenFromUrl } = await import('./commercePaymentLinks')

    expect(paymentLinkTokenFromUrl(`https://shop.test/pay/${TOKEN}`)).toBe(TOKEN)
    expect(paymentLinkTokenFromUrl(`https://shop.test/deep/path/${TOKEN}/`)).toBe(TOKEN)
    expect(paymentLinkTokenFromUrl(`https://shop.test/pay/${TOKEN}?utm=1#x`)).toBe(TOKEN)
    // Not a URL at all — never fall back to splitting the raw string.
    expect(paymentLinkTokenFromUrl(`pay/${TOKEN}`)).toBeNull()
    expect(paymentLinkTokenFromUrl('not a url')).toBeNull()
    expect(paymentLinkTokenFromUrl('')).toBeNull()
    // Right shape, wrong alphabet/length.
    expect(paymentLinkTokenFromUrl(`https://shop.test/pay/${'A'.repeat(64)}`)).toBeNull()
    expect(paymentLinkTokenFromUrl(`https://shop.test/pay/${'a'.repeat(63)}`)).toBeNull()
    expect(paymentLinkTokenFromUrl(`https://shop.test/pay/${'a'.repeat(65)}`)).toBeNull()
    expect(paymentLinkTokenFromUrl('https://shop.test/')).toBeNull()
    // Scheme-gated: `new URL()` parses these happily, but a token lifted out of one is a
    // credential from somewhere this store never published to.
    expect(paymentLinkTokenFromUrl(`ftp://shop.test/pay/${TOKEN}`)).toBeNull()
    expect(paymentLinkTokenFromUrl(`file:///tmp/pay/${TOKEN}`)).toBeNull()
    expect(paymentLinkTokenFromUrl(`http://shop.test/pay/${TOKEN}`)).toBe(TOKEN)
  })

  it('newPaymentLinkIdempotencyKey produces distinct 16..128 printable-ASCII keys', async () => {
    const { newPaymentLinkIdempotencyKey } = await import('./commercePaymentLinks')

    const a = newPaymentLinkIdempotencyKey()
    const b = newPaymentLinkIdempotencyKey()
    expect(a).not.toBe(b)
    for (const key of [a, b]) {
      expect(key.length).toBeGreaterThanOrEqual(16)
      expect(key.length).toBeLessThanOrEqual(128)
      expect(/^[\x21-\x7e]+$/.test(key)).toBe(true)
    }
  })
})
