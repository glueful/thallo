import { describe, it, expect, vi, beforeEach } from 'vitest'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { fetchAccountSettings, saveAccountRedirects, isSafeReturnPath } from '@/queries/accountSettings'

describe('account settings query layer', () => {
  beforeEach(() => authFetch.mockReset())

  it('fetchAccountSettings unwraps data (pages + redirects) from the right endpoint', async () => {
    authFetch.mockResolvedValue({
      data: {
        pages: [{ label: 'Sign in', path: '/account/login' }],
        after_login: '/account/orders',
        after_logout: null,
      },
    })

    const s = await fetchAccountSettings()

    expect(s.pages).toEqual([{ label: 'Sign in', path: '/account/login' }])
    expect(s.after_login).toBe('/account/orders')
    expect(s.after_logout).toBeNull()
    expect(authFetch.mock.calls[0][0]).toBe('/v1/admin/settings/accounts')
  })

  it('saveAccountRedirects PUTs the pair and clears an override with null', async () => {
    authFetch.mockResolvedValue({ data: { pages: [], after_login: '/x', after_logout: null } })

    await saveAccountRedirects('/x', null)

    const [url, opts] = authFetch.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/v1/admin/settings/accounts')
    expect(opts.method).toBe('PUT')
    expect(JSON.parse(opts.body as string)).toEqual({ after_login: '/x', after_logout: null })
  })

  it('rejects a malformed response (no pages array)', async () => {
    authFetch.mockResolvedValue({ data: { after_login: '/x' } })
    await expect(fetchAccountSettings()).rejects.toThrow(/Malformed/)
  })

  describe('isSafeReturnPath (client mirror of AccountReturnPath)', () => {
    it('accepts safe application-relative paths (and blank)', () => {
      for (const p of ['', '/', '/account', '/account/orders', '/account/orders?tab=recent']) {
        expect(isSafeReturnPath(p)).toBe(true)
      }
    })

    it('rejects every open-redirect shape', () => {
      for (const p of [
        '//evil.example',
        'https://evil.example',
        'javascript:alert(1)',
        '/\\evil',
        'account/orders',
        '%2f%2fevil',
        '/foo%00bar',
        ' /account',
      ]) {
        expect(isSafeReturnPath(p)).toBe(false)
      }
    })
  })
})
