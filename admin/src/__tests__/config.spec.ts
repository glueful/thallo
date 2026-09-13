import { describe, it, expect, vi } from 'vitest'

describe('runtime config loader', () => {
  it('fetches /admin/config and exposes the typed runtime config', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          apiBase: '/v1/admin',
          sitePreviewUrl: 'https://x/preview',
          defaultLocale: 'en',
          installed: true,
          apiDocsPath: '/api-docs',
        }),
        { status: 200 },
      ),
    )
    vi.stubGlobal('fetch', fetchMock)
    const { loadRuntimeConfig } = await import('@/runtime/config')
    const cfg = await loadRuntimeConfig()
    // Single same-origin fetch of the dynamic runtime-config route.
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock.mock.calls[0][0]).toBe('/admin/config')
    expect(cfg.apiBase).toBe('/v1/admin')
    expect(cfg.sitePreviewUrl).toBe('https://x/preview')
    expect(cfg.installed).toBe(true)
    expect(cfg.apiDocsPath).toBe('/api-docs')
  })

  it('falls back to the framework default API-docs path when the backend omits it', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          new Response(JSON.stringify({ apiBase: '/v1/admin', installed: true }), { status: 200 }),
        ),
    )
    const { loadRuntimeConfig } = await import('@/runtime/config')
    expect((await loadRuntimeConfig()).apiDocsPath).toBe('/api-docs')
  })
})
