import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { useCapabilitiesStore } from '@/stores/capabilities'

describe('capabilities store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    authFetch.mockReset()
  })

  it('loads enabled capability ids from the endpoint', async () => {
    authFetch.mockResolvedValue({
      data: { capabilities: [{ id: 'thallo.forms' }, { id: 'thallo.render' }] },
    })
    const store = useCapabilitiesStore()
    expect(store.loaded).toBe(false)
    await store.load()
    expect(authFetch).toHaveBeenCalledWith('/v1/admin/capabilities')
    expect(store.loaded).toBe(true)
    expect(store.isEnabled('thallo.forms')).toBe(true)
    expect(store.isEnabled('thallo.render')).toBe(true)
    expect(store.isEnabled('thallo.nope')).toBe(false)
  })

  it('fails closed on error (empty enabled set, loaded=true)', async () => {
    authFetch.mockRejectedValue(new Error('403'))
    const store = useCapabilitiesStore()
    await store.load()
    expect(store.loaded).toBe(true)
    expect(store.isEnabled('thallo.forms')).toBe(false)
  })

  it('ensureLoaded loads at most once', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [] } })
    const store = useCapabilitiesStore()
    await Promise.all([store.ensureLoaded(), store.ensureLoaded()])
    await store.ensureLoaded()
    expect(authFetch).toHaveBeenCalledTimes(1)
  })

  it('refresh replaces the set with the latest server answer', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.workflow' }] } })
    const store = useCapabilitiesStore()
    await store.ensureLoaded()
    expect(store.isEnabled('thallo.workflow')).toBe(true)

    // The pack was disabled server-side; a focus refetch must drop the nav entry.
    authFetch.mockResolvedValue({ data: { capabilities: [] } })
    await store.refresh()
    expect(store.isEnabled('thallo.workflow')).toBe(false)
  })

  it('refresh keeps the previous set on a transient failure (no nav blanking)', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.workflow' }] } })
    const store = useCapabilitiesStore()
    await store.ensureLoaded()

    authFetch.mockRejectedValue(new Error('network blip'))
    await store.refresh()
    expect(store.isEnabled('thallo.workflow')).toBe(true)
  })

  it('refresh before the initial load just performs the initial load', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.seo' }] } })
    const store = useCapabilitiesStore()
    await store.refresh()
    expect(store.loaded).toBe(true)
    expect(store.isEnabled('thallo.seo')).toBe(true)
    expect(authFetch).toHaveBeenCalledTimes(1)
  })

  it('refreshUntilChanged polls past stale answers and stops once the set changes', async () => {
    vi.useFakeTimers()
    try {
      authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.workflow' }] } })
      const store = useCapabilitiesStore()
      await store.ensureLoaded()

      // Backend keeps serving the stale (pre-toggle) list twice, then the fresh one.
      authFetch
        .mockResolvedValueOnce({ data: { capabilities: [{ id: 'thallo.workflow' }] } })
        .mockResolvedValueOnce({ data: { capabilities: [{ id: 'thallo.workflow' }] } })
        .mockResolvedValue({ data: { capabilities: [] } })

      const done = store.refreshUntilChanged(6, 1200)
      await vi.advanceTimersByTimeAsync(1200) // attempt 1: stale
      expect(store.isEnabled('thallo.workflow')).toBe(true)
      await vi.advanceTimersByTimeAsync(1200) // attempt 2: stale
      await vi.advanceTimersByTimeAsync(1200) // attempt 3: fresh → stops
      await done
      expect(store.isEnabled('thallo.workflow')).toBe(false)
      expect(authFetch).toHaveBeenCalledTimes(4) // initial load + 3 polls, no further attempts
    } finally {
      vi.useRealTimers()
    }
  })

  it('refreshUntilChanged gives up after maxAttempts when nothing changes', async () => {
    vi.useFakeTimers()
    try {
      authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.seo' }] } })
      const store = useCapabilitiesStore()
      await store.ensureLoaded()

      const done = store.refreshUntilChanged(3, 1000)
      await vi.advanceTimersByTimeAsync(3000)
      await done
      expect(authFetch).toHaveBeenCalledTimes(4) // initial load + exactly 3 bounded polls
      expect(store.isEnabled('thallo.seo')).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })

  // Regression: reset() must drop the cached set AND clear the loaded flag so the next
  // ensureLoaded() re-fetches — otherwise a second account in the same tab inherits the
  // previous user's capabilities.
  it('reset clears the set and forces the next ensureLoaded to reload', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.forms' }] } })
    const store = useCapabilitiesStore()
    await store.ensureLoaded()
    expect(store.isEnabled('thallo.forms')).toBe(true)

    store.reset()
    expect(store.loaded).toBe(false)
    expect(store.isEnabled('thallo.forms')).toBe(false)

    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.render' }] } })
    await store.ensureLoaded()
    expect(authFetch).toHaveBeenCalledTimes(2)
    expect(store.isEnabled('thallo.forms')).toBe(false)
    expect(store.isEnabled('thallo.render')).toBe(true)
  })
})
