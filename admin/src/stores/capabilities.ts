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

// Last-known capability snapshot, persisted so a returning session paints the complete
// sidebar on the FIRST frame instead of waiting for discovery. Keyed by cache-schema
// version + API base: localStorage is already origin-scoped by the browser, and the
// apiBase segment covers dev setups where one SPA origin talks to different installs.
// Installation-keyed on purpose — capabilities are installation-wide pack availability,
// not caller permissions, so the hint legitimately survives logout/login. NEVER caller
// authorization; nothing security-relevant may be persisted here.
const CACHE_VERSION = 'v1'
const MAX_CACHED_IDS = 100
const CAPABILITY_ID_SHAPE = /^[a-z][a-z0-9._-]{0,63}$/i

function cacheKey(): string {
  return `thallo.capabilities.${CACHE_VERSION}:${runtimeConfig.apiBase}`
}

/**
 * Sanitizing hydration: a corrupted/foreign blob must never throw during boot (the one
 * moment this cache exists to make serene) and can only yield inert id strings — the ids
 * feed membership checks against manifest-declared ids, nothing else.
 */
function readCachedIds(): Set<string> {
  try {
    const raw = globalThis.localStorage?.getItem(cacheKey())
    if (raw === null || raw === undefined) return new Set()
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return new Set()
    return new Set(
      parsed
        .filter((v): v is string => typeof v === 'string' && CAPABILITY_ID_SHAPE.test(v))
        .slice(0, MAX_CACHED_IDS),
    )
  } catch {
    return new Set()
  }
}

/** Written ONLY from verified server responses — the cache can never launder itself. */
function writeCachedIds(ids: Set<string>): void {
  try {
    globalThis.localStorage?.setItem(cacheKey(), JSON.stringify([...ids].slice(0, MAX_CACHED_IDS)))
  } catch {
    // quota/privacy-mode failures degrade to a cold next boot, nothing more
  }
}

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
 *
 * Two id sets with DISJOINT consumers (never mix them):
 * - `visibleIds` / `isVisible()` — the PRESENTATION HINT: hydrated synchronously from the
 *   persisted last-known snapshot, replaced by every verified server answer. Consumed by
 *   the sidebar manifest only. Hydration never advances `status`, so nothing that awaits
 *   or branches on discovery is affected by cache presence.
 * - `enabledIds` / `isEnabled()` — VERIFIED server state only. Consumed by the router
 *   guard, feature pages, and anything that ACTS on capability state. A stale cache can
 *   at worst briefly show a menu entry; clicking it still awaits verified discovery.
 */
export const useCapabilitiesStore = defineStore('capabilities', () => {
  const enabledIds = ref<Set<string>>(new Set())
  const visibleIds = ref<Set<string>>(readCachedIds())
  const status = ref<CapabilityStatus>('idle')
  /** True once the initial fetch has SETTLED (ready or error) — the state-branch gate. */
  const settled = computed(() => status.value === 'ready' || status.value === 'error')
  let inflight: Promise<void> | null = null
  let autoRetriesUsed = 0
  let autoRetryTimer: ReturnType<typeof setTimeout> | null = null

  /** Verified server truth — router guard / feature pages / anything that acts. */
  function isEnabled(id: string): boolean {
    return enabledIds.value.has(id)
  }

  /** Presentation hint (cache-then-verified) — the sidebar manifest ONLY. */
  function isVisible(id: string): boolean {
    return visibleIds.value.has(id)
  }

  /** The single point where server truth lands: verified, visible, and the cache move together. */
  function acceptVerified(ids: Set<string>): void {
    enabledIds.value = ids
    visibleIds.value = new Set(ids)
    writeCachedIds(ids)
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
      acceptVerified(await fetchEnabledIds())
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
      acceptVerified(await fetchEnabledIds())
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

  // Clear the VERIFIED set so the next ensureLoaded() reloads. Called on login/logout so a
  // second account in the same tab (SPA nav, no reload) never inherits the previous session's
  // verified state. `visibleIds` deliberately survives: it is the installation-wide
  // presentation hint (pack availability, not caller permissions), so the next session keeps
  // its warm first paint; the post-login fetch reconciles it.
  function reset(): void {
    enabledIds.value = new Set()
    status.value = 'idle'
    inflight = null
    autoRetriesUsed = 0
    clearAutoRetry()
  }

  return {
    enabledIds,
    visibleIds,
    status,
    settled,
    isEnabled,
    isVisible,
    load,
    ensureLoaded,
    retry,
    refresh,
    refreshUntilChanged,
    reset,
  }
})
