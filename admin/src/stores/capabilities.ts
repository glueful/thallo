import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

interface CapabilityRow {
  id: string
}

export type CapabilityStatus = 'idle' | 'loading' | 'ready' | 'error'

// Bounded automatic recovery for a failed INITIAL load: two retries, backing off.
// Deliberately small — the window-focus refresh and the manual Retry panel are the
// long-tail recovery paths; this only papers over the boot-time blip.
const AUTO_RETRY_DELAYS_MS = [2_000, 6_000] as const

/**
 * Enabled capability ids, loaded post-auth from GET /v1/admin/capabilities (Phase B).
 * Drives capability-gated nav (the admin module registry) and route gating.
 *
 * Availability is a four-state machine, NOT a boolean: `idle | loading | ready | error`.
 * "We don't know yet" (loading) and "we couldn't find out" (error) are never collapsed
 * into "no capabilities" — `isEnabled()` still answers `false` in those states (gating
 * stays fail-closed), but consumers that would take a DESTRUCTIVE rendering decision
 * (hide a module, redirect a route, show "isn't enabled") must branch on `status`:
 * skeleton while `loading`, a Retry surface on `error`, and only trust the answer at
 * `ready`. A failed load keeps the last successful set (empty on first boot), schedules
 * a bounded automatic retry, and `retry()` is the manual recovery for the error panels.
 */
export const useCapabilitiesStore = defineStore('capabilities', () => {
  const enabledIds = ref<Set<string>>(new Set())
  const status = ref<CapabilityStatus>('idle')
  /** True once the initial fetch has SETTLED (ready or error) — the skeleton gate. */
  const settled = computed(() => status.value === 'ready' || status.value === 'error')
  let inflight: Promise<void> | null = null
  let autoRetriesUsed = 0
  let autoRetryTimer: ReturnType<typeof setTimeout> | null = null

  function isEnabled(id: string): boolean {
    return enabledIds.value.has(id)
  }

  async function fetchEnabledIds(): Promise<Set<string>> {
    const json = await authFetch(`${runtimeConfig.apiBase}/capabilities`)
    const data = (json.data ?? json) as Record<string, unknown>
    const rows = Array.isArray(data.capabilities) ? (data.capabilities as CapabilityRow[]) : []
    return new Set(rows.map((r) => r.id))
  }

  function clearAutoRetry(): void {
    if (autoRetryTimer !== null) {
      clearTimeout(autoRetryTimer)
      autoRetryTimer = null
    }
  }

  async function load(): Promise<void> {
    if (status.value !== 'ready') status.value = 'loading'
    try {
      enabledIds.value = await fetchEnabledIds()
      status.value = 'ready'
      autoRetriesUsed = 0
      clearAutoRetry()
    } catch {
      // Keep the previous set (empty on first boot) — an error is NOT an empty
      // capability list. Schedule a bounded automatic retry.
      status.value = 'error'
      if (autoRetriesUsed < AUTO_RETRY_DELAYS_MS.length) {
        const delay = AUTO_RETRY_DELAYS_MS[autoRetriesUsed]!
        autoRetriesUsed += 1
        clearAutoRetry()
        autoRetryTimer = setTimeout(() => {
          autoRetryTimer = null
          void run()
        }, delay)
      }
    }
  }

  /** Single-flight wrapper: concurrent callers share one request. */
  function run(): Promise<void> {
    inflight ??= load().finally(() => {
      inflight = null
    })
    return inflight
  }

  /**
   * Idempotent boot fetch. Resolves immediately at `ready`; at `error` it also resolves
   * WITHOUT refetching — recovery belongs to the auto-retry timer, the focus refresh,
   * and `retry()` — so a navigation storm can't hammer a failing endpoint. The router
   * guard awaits this, then branches on `status`.
   */
  function ensureLoaded(): Promise<void> {
    if (status.value === 'ready' || status.value === 'error') return inflight ?? Promise.resolve()
    return run()
  }

  /** Manual recovery (the Retry panels): resets the auto-retry budget and refetches. */
  function retry(): Promise<void> {
    clearAutoRetry()
    autoRetriesUsed = 0
    return run()
  }

  // Background refetch (window focus): converge an open tab on a server-side pack
  // enable/disable without a manual reload. Unlike load(), a failure at `ready` keeps
  // the PREVIOUS set — a transient network blip during a refetch must not blank the
  // whole gated nav (and must not demote `ready` to `error`). Before `ready`, a
  // refresh is just the initial load / a retry.
  async function refresh(): Promise<void> {
    if (status.value !== 'ready') {
      return retry()
    }
    try {
      enabledIds.value = await fetchEnabledIds()
    } catch {
      // keep the previous set
    }
  }

  function sameSet(a: Set<string>, b: Set<string>): boolean {
    if (a.size !== b.size) return false
    for (const id of a) if (!b.has(id)) return false
    return true
  }

  // After an enable/disable, the backend can keep serving the PREVIOUS capability list for a
  // few seconds (dev extension-cache TTL), so a single refetch usually loses the race. Poll
  // until the set actually changes, then stop; bounded so a toggle that never changes the
  // capability list (an extension with no thallo capability) can't poll forever.
  async function refreshUntilChanged(maxAttempts = 6, intervalMs = 1200): Promise<void> {
    const before = new Set(enabledIds.value)
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
      await new Promise((resolve) => setTimeout(resolve, intervalMs))
      await refresh()
      if (!sameSet(enabledIds.value, before)) return
    }
  }

  // Clear the cached set so the next ensureLoaded() reloads. Called on login/logout so a second
  // account in the same tab (SPA nav, no reload) never inherits the previous user's capabilities.
  function reset(): void {
    enabledIds.value = new Set()
    status.value = 'idle'
    inflight = null
    autoRetriesUsed = 0
    clearAutoRetry()
  }

  return {
    enabledIds,
    status,
    settled,
    isEnabled,
    load,
    ensureLoaded,
    retry,
    refresh,
    refreshUntilChanged,
    reset,
  }
})
