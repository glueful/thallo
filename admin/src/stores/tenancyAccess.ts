import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchTenancyAccess, type TenancyAccess } from '@/queries/tenancyAccess'

const emptyAccess = (): TenancyAccess => ({
  manage_platform: false,
  access_any: false,
  manage_members: false,
  manage_domains: false,
  manage_roles: false,
  manage_billing: false,
})

export const useTenancyAccessStore = defineStore('tenancyAccess', () => {
  const access = ref<TenancyAccess>(emptyAccess())
  const loaded = ref(false)
  let generation = 0
  let inflight: Promise<void> | null = null

  async function run(): Promise<void> {
    const current = ++generation
    try {
      const next = await fetchTenancyAccess()
      if (current === generation) {
        access.value = next
        loaded.value = true
      }
    } catch {
      if (current === generation) {
        access.value = emptyAccess()
        loaded.value = true
      }
    }
  }

  function ensureLoaded(force = false): Promise<void> {
    if (loaded.value && !force) return Promise.resolve()
    inflight ??= run().finally(() => {
      inflight = null
    })
    return inflight
  }

  // Stale-while-revalidate: the PREVIOUS flags stay in place until the new answer lands.
  // Blanking here made every workspace-page mount (ensureTargetSelected revalidates on
  // entry) momentarily read as "no tenant access", which dropped and re-added the whole
  // Workspaces sidebar group — the flash. Fail-closed blanking belongs to IDENTITY
  // changes, and every such caller (the selection/operator-mode watcher, the switcher's
  // 403 recovery) already calls reset() first; the generation guard in run() keeps a
  // stale response from clobbering a newer one.
  async function refresh(): Promise<void> {
    await run()
  }

  function reset(): void {
    generation++
    access.value = emptyAccess()
    loaded.value = false
    inflight = null
  }

  return { access, loaded, ensureLoaded, refresh, reset }
})
