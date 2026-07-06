import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

const cacheInvalidate = vi.hoisted(() => vi.fn())
vi.mock('@pinia/colada', () => ({
  useQueryCache: () => ({ invalidateQueries: cacheInvalidate }),
  useQuery: () => ({ data: { value: undefined }, status: { value: 'idle' } }),
  useMutation: () => ({ mutate: vi.fn() }),
}))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ refreshUntilChanged: vi.fn() }),
}))

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

describe('useExtensionInstall', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('installs synchronously and invalidates the catalog on success', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ data: { status: 'installed', package: 'glueful/entrada', error: null } }),
    )
    const { useExtensionInstall } = await import('@/queries/extensions')
    const { install } = useExtensionInstall()

    const result = await install('glueful/entrada')

    expect(result.status).toBe('installed')
    expect(cacheInvalidate).toHaveBeenCalled()
  })

  it('surfaces a failed install without invalidating the catalog', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse(
        { data: { status: 'failed', package: 'glueful/entrada', error: 'composer require failed' } },
        200,
      ),
    )
    const { useExtensionInstall } = await import('@/queries/extensions')
    const { install } = useExtensionInstall()

    const result = await install('glueful/entrada')

    expect(result.status).toBe('failed')
    expect(result.error).toBe('composer require failed')
    expect(cacheInvalidate).not.toHaveBeenCalled()
  })

  it('marks a package as installing only while the request is in flight', async () => {
    let resolveFetch: (r: Response) => void = () => {}
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockReturnValue(
      new Promise<Response>((resolve) => {
        resolveFetch = resolve
      }),
    )
    const { useExtensionInstall } = await import('@/queries/extensions')
    const { install, installing } = useExtensionInstall()

    const promise = install('glueful/entrada')
    expect(installing('glueful/entrada')).toBe(true)

    resolveFetch(jsonResponse({ data: { status: 'installed', package: 'glueful/entrada' } }))
    await promise
    expect(installing('glueful/entrada')).toBe(false)
  })
})
