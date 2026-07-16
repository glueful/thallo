import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
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

  afterEach(() => {
    // Clear any pending auto-retry timer so it cannot fire into a later test.
    useCapabilitiesStore().reset()
    vi.useRealTimers()
  })

  it('loads enabled capability ids from the endpoint (idle → loading → ready)', async () => {
    authFetch.mockResolvedValue({
      data: { capabilities: [{ id: 'thallo.forms' }, { id: 'thallo.render' }] },
    })
    const store = useCapabilitiesStore()
    expect(store.status).toBe('idle')
    expect(store.settled).toBe(false)
    const loading = store.load()
    expect(store.status).toBe('loading')
    await loading
    expect(authFetch).toHaveBeenCalledWith('/v1/admin/capabilities')
    expect(store.status).toBe('ready')
    expect(store.settled).toBe(true)
    expect(store.isEnabled('thallo.forms')).toBe(true)
    expect(store.isEnabled('thallo.render')).toBe(true)
    expect(store.isEnabled('thallo.nope')).toBe(false)
  })

  // The core invariant of the flicker/reload fix: an error is NOT a successfully loaded
  // empty capability set. Gating still answers false (fail-closed), but status says 'error'
  // so guards/pages can render Retry instead of "disabled".
  it('an initial-load failure settles as error, never as a loaded-empty set', async () => {
    vi.useFakeTimers()
    authFetch.mockRejectedValue(new Error('403'))
    const store = useCapabilitiesStore()
    await store.load()
    expect(store.status).toBe('error')
    expect(store.settled).toBe(true)
    expect(store.isEnabled('thallo.forms')).toBe(false)
  })

  it('automatically retries a failed initial load (bounded), recovering without a reload', async () => {
    vi.useFakeTimers()
    authFetch.mockRejectedValueOnce(new Error('blip'))
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.navigation' }] } })
    const store = useCapabilitiesStore()
    await store.load()
    expect(store.status).toBe('error')

    await vi.advanceTimersByTimeAsync(2_000) // first auto-retry
    expect(store.status).toBe('ready')
    expect(store.isEnabled('thallo.navigation')).toBe(true)
    expect(authFetch).toHaveBeenCalledTimes(2)
  })

  it('gives up automatic retries after the bounded budget; manual retry() still recovers', async () => {
    vi.useFakeTimers()
    authFetch.mockRejectedValue(new Error('down'))
    const store = useCapabilitiesStore()
    await store.load()
    await vi.advanceTimersByTimeAsync(2_000) // auto-retry 1 fails
    await vi.advanceTimersByTimeAsync(6_000) // auto-retry 2 fails
    await vi.advanceTimersByTimeAsync(60_000) // no further automatic attempts
    expect(store.status).toBe('error')
    expect(authFetch).toHaveBeenCalledTimes(3)

    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.seo' }] } })
    await store.retry()
    expect(store.status).toBe('ready')
    expect(store.isEnabled('thallo.seo')).toBe(true)
  })

  it('ensureLoaded loads at most once and shares the in-flight request', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [] } })
    const store = useCapabilitiesStore()
    await Promise.all([store.ensureLoaded(), store.ensureLoaded()])
    await store.ensureLoaded()
    expect(authFetch).toHaveBeenCalledTimes(1)
  })

  it('ensureLoaded at error resolves WITHOUT refetching (guards cannot hammer a failing endpoint)', async () => {
    vi.useFakeTimers()
    authFetch.mockRejectedValue(new Error('down'))
    const store = useCapabilitiesStore()
    await store.load()
    expect(store.status).toBe('error')
    const calls = authFetch.mock.calls.length

    await store.ensureLoaded()
    await store.ensureLoaded()
    expect(store.status).toBe('error')
    expect(authFetch).toHaveBeenCalledTimes(calls)
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

  it('refresh keeps the previous set AND ready status on a transient failure (no nav blanking)', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.workflow' }] } })
    const store = useCapabilitiesStore()
    await store.ensureLoaded()

    authFetch.mockRejectedValue(new Error('network blip'))
    await store.refresh()
    expect(store.isEnabled('thallo.workflow')).toBe(true)
    expect(store.status).toBe('ready')
  })

  it('refresh before the initial load just performs the initial load', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.seo' }] } })
    const store = useCapabilitiesStore()
    await store.refresh()
    expect(store.status).toBe('ready')
    expect(store.isEnabled('thallo.seo')).toBe(true)
    expect(authFetch).toHaveBeenCalledTimes(1)
  })

  it('refresh at error status acts as a retry (focus refetch recovers a failed boot)', async () => {
    vi.useFakeTimers()
    authFetch.mockRejectedValue(new Error('down'))
    const store = useCapabilitiesStore()
    await store.load()
    expect(store.status).toBe('error')

    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.navigation' }] } })
    await store.refresh()
    expect(store.status).toBe('ready')
    expect(store.isEnabled('thallo.navigation')).toBe(true)
  })

  it('refreshUntilChanged polls past stale answers and stops once the set changes', async () => {
    vi.useFakeTimers()
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
  })

  it('refreshUntilChanged gives up after maxAttempts when nothing changes', async () => {
    vi.useFakeTimers()
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.seo' }] } })
    const store = useCapabilitiesStore()
    await store.ensureLoaded()

    const done = store.refreshUntilChanged(3, 1000)
    await vi.advanceTimersByTimeAsync(3000)
    await done
    expect(authFetch).toHaveBeenCalledTimes(4) // initial load + exactly 3 bounded polls
    expect(store.isEnabled('thallo.seo')).toBe(true)
  })

  // Regression: reset() must drop the cached set AND the status so the next
  // ensureLoaded() re-fetches — otherwise a second account in the same tab inherits the
  // previous user's capabilities.
  it('reset clears the set, status, and pending auto-retry; the next ensureLoaded reloads', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.forms' }] } })
    const store = useCapabilitiesStore()
    await store.ensureLoaded()
    expect(store.isEnabled('thallo.forms')).toBe(true)

    store.reset()
    expect(store.status).toBe('idle')
    expect(store.settled).toBe(false)
    expect(store.isEnabled('thallo.forms')).toBe(false)

    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'thallo.render' }] } })
    await store.ensureLoaded()
    expect(authFetch).toHaveBeenCalledTimes(2)
    expect(store.isEnabled('thallo.forms')).toBe(false)
    expect(store.isEnabled('thallo.render')).toBe(true)
  })
})
