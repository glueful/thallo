import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

const cacheInvalidate = vi.hoisted(() => vi.fn())
const refreshUntilChanged = vi.hoisted(() => vi.fn())
vi.mock('@pinia/colada', () => ({
  useQueryCache: () => ({ invalidateQueries: cacheInvalidate }),
  useQuery: () => ({ data: { value: undefined }, status: { value: 'idle' } }),
  useMutation: () => ({ mutate: vi.fn() }),
}))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ refreshUntilChanged }),
}))

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

/** Route the install POST + a scripted sequence of poll GETs. */
function fetchDriver(pollStatuses: string[]) {
  let poll = 0
  return (url: string, init?: RequestInit): Promise<Response> => {
    const method = init?.method ?? 'GET'
    if (method === 'POST' && url.endsWith('/extensions/install')) {
      return Promise.resolve(jsonResponse({ data: { jobId: 'job-1' } }))
    }
    if (method === 'GET' && url.includes('/extensions/install/job-1')) {
      const status = pollStatuses[Math.min(poll++, pollStatuses.length - 1)]
      return Promise.resolve(
        jsonResponse({
          data: {
            id: 'job-1',
            package: 'glueful/audit',
            status,
            output: '',
            error: status === 'failed' ? 'composer require failed' : null,
            enableError: status === 'installed_not_enabled' ? 'missing dependency' : null,
          },
        }),
      )
    }
    throw new Error(`unexpected ${method} ${url}`)
  }
}

describe('useExtensionInstall', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
    refreshUntilChanged.mockClear()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('polls running → succeeded, then invalidates the catalog and converges capabilities', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockImplementation(
      fetchDriver(['running', 'succeeded']),
    )
    const { useExtensionInstall } = await import('@/queries/extensions')
    const { install, state } = useExtensionInstall(1) // fast poll

    const result = await install('glueful/audit')

    expect(result).toBe('succeeded')
    expect(state['glueful/audit'].status).toBe('succeeded')
    expect(cacheInvalidate).toHaveBeenCalled()
    expect(refreshUntilChanged).toHaveBeenCalled()
  })

  it('surfaces installed_not_enabled without converging capabilities', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockImplementation(
      fetchDriver(['installed_not_enabled']),
    )
    const { useExtensionInstall } = await import('@/queries/extensions')
    const { install, state } = useExtensionInstall(1)

    const result = await install('glueful/audit')

    expect(result).toBe('installed_not_enabled')
    expect(state['glueful/audit'].error).toBe('missing dependency')
    expect(cacheInvalidate).toHaveBeenCalled()
    expect(refreshUntilChanged).not.toHaveBeenCalled() // not enabled → nothing to converge
  })

  it('returns failed when the job fails', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockImplementation(fetchDriver(['failed']))
    const { useExtensionInstall } = await import('@/queries/extensions')
    const { install } = useExtensionInstall(1)

    expect(await install('glueful/audit')).toBe('failed')
    expect(refreshUntilChanged).not.toHaveBeenCalled()
  })

  it('marks a package as installing while the job is in flight', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockImplementation(
      fetchDriver(['queued', 'running', 'succeeded']),
    )
    const { useExtensionInstall } = await import('@/queries/extensions')
    const { install, installing } = useExtensionInstall(1)

    const promise = install('glueful/audit')
    // synchronously after kickoff the state is 'starting' → installing() is true
    expect(installing('glueful/audit')).toBe(true)
    await promise
    expect(installing('glueful/audit')).toBe(false)
  })
})
