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
  useMutation: (opts: { mutation: (...args: unknown[]) => unknown; onSettled?: () => void }) => ({
    mutateAsync: async (...args: unknown[]) => {
      try {
        return await opts.mutation(...args)
      } finally {
        opts.onSettled?.()
      }
    },
    isLoading: { value: false },
  }),
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

const row = (over: Record<string, unknown> = {}) => ({
  id: 'thallo.search',
  label: 'Search',
  requires: [],
  owning_package: 'glueful/meilisearch',
  requested: false,
  available: true,
  reason: null,
  remedy: null,
  effective: false,
  ...over,
})

describe('capabilityManagement queries', () => {
  beforeEach(() => {
    vi.resetModules()
    cacheInvalidate.mockClear()
    refreshUntilChanged.mockClear()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('fetches the management rows from /capabilities/manage', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ data: { capabilities: [row()] } }),
    )
    const { fetchCapabilityManagement } = await import('@/queries/capabilityManagement')

    const rows = await fetchCapabilityManagement()

    expect(rows).toHaveLength(1)
    expect(rows[0]?.id).toBe('thallo.search')
    const url = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]?.[0]
    expect(String(url)).toContain('/v1/admin/capabilities/manage')
  })

  it('PUTs the flip to the exact capability id and converges the capability store', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ data: { id: 'thallo.search', requested: true, effective: true } }),
    )
    const { useCapabilityStateMutations } = await import('@/queries/capabilityManagement')
    const { setState } = useCapabilityStateMutations()

    await setState.mutateAsync({ id: 'thallo.search', enabled: true })

    const [url, init] = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0] ?? []
    expect(String(url)).toContain('/v1/admin/capabilities/thallo.search')
    expect((init as RequestInit).method).toBe('PUT')
    expect(JSON.parse(String((init as RequestInit).body))).toEqual({ enabled: true })
    expect(cacheInvalidate).toHaveBeenCalled()
    expect(refreshUntilChanged).toHaveBeenCalled()
  })

  it('surfaces a server refusal (409) as a thrown error', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: false, message: 'Cannot enable thallo.search: engine down' }, 409),
    )
    const { setCapabilityState } = await import('@/queries/capabilityManagement')

    await expect(setCapabilityState('thallo.search', true)).rejects.toThrow()
  })

  it('blocks enable only for unavailable capabilities, carrying reason and remedy', async () => {
    const { enableBlockedReason } = await import('@/queries/capabilityManagement')

    expect(enableBlockedReason(row())).toBeNull()
    const blocked = enableBlockedReason(
      row({
        available: false,
        reason: 'glueful/meilisearch is installed but not enabled.',
        remedy: 'php glueful extensions:enable glueful/meilisearch',
      }),
    )
    expect(blocked).toContain('not enabled')
    expect(blocked).toContain('php glueful extensions:enable glueful/meilisearch')
  })
})
