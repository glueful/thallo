import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

interface CapabilityRow {
  id: string
}

/**
 * Enabled capability ids, loaded post-auth from GET /v1/admin/capabilities (Phase B).
 * Drives capability-gated nav (the admin module registry) and route gating. Fails closed:
 * on error the enabled set is empty, so nothing pack-gated shows while core stays visible.
 */
export const useCapabilitiesStore = defineStore('capabilities', () => {
  const enabledIds = ref<Set<string>>(new Set())
  const loaded = ref(false)
  let inflight: Promise<void> | null = null

  function isEnabled(id: string): boolean {
    return enabledIds.value.has(id)
  }

  async function fetchEnabledIds(): Promise<Set<string>> {
    const json = await authFetch(`${runtimeConfig.apiBase}/capabilities`)
    const data = (json.data ?? json) as Record<string, unknown>
    const rows = Array.isArray(data.capabilities) ? (data.capabilities as CapabilityRow[]) : []
    return new Set(rows.map((r) => r.id))
  }

  async function load(): Promise<void> {
    try {
      enabledIds.value = await fetchEnabledIds()
    } catch {
      enabledIds.value = new Set()
    } finally {
      loaded.value = true
    }
  }

  // Background refetch (window focus): converge an open tab on a server-side pack
  // enable/disable without a manual reload. Unlike load(), a failure keeps the PREVIOUS
  // set — a transient network blip during a refetch must not blank the whole gated nav.
  async function refresh(): Promise<void> {
    if (!loaded.value) {
      return ensureLoaded()
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

  function ensureLoaded(): Promise<void> {
    if (loaded.value) return Promise.resolve()
    inflight ??= load().finally(() => {
      inflight = null
    })
    return inflight
  }

  // Clear the cached set so the next ensureLoaded() reloads. Called on login/logout so a second
  // account in the same tab (SPA nav, no reload) never inherits the previous user's capabilities.
  function reset(): void {
    enabledIds.value = new Set()
    loaded.value = false
    inflight = null
  }

  return { enabledIds, loaded, isEnabled, load, ensureLoaded, refresh, refreshUntilChanged, reset }
})
