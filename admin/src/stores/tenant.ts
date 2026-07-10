import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchMyTenants, type TenantSummary } from '@/queries/tenants'
import type { PersistOptions } from '@/plugins/pinia-persist-plugin'

const tenantStoreOptions: { persist: PersistOptions } = {
  persist: {
    enabled: true,
    strategies: [{ key: 'thallo_tenant', storage: localStorage, paths: ['selectedUuid'] }],
  },
}

export const useTenantStore = defineStore(
  'tenant',
  () => {
    const selectedUuid = ref<string | null>(null)
    const tenants = ref<TenantSummary[]>([])
    const loaded = ref(false)
    let inflight: Promise<void> | null = null

    function select(uuid: string): void {
      selectedUuid.value = uuid
    }

    function clearSelection(): void {
      selectedUuid.value = null
    }

    async function load(): Promise<void> {
      try {
        tenants.value = await fetchMyTenants()
        if (!tenants.value.some((tenant) => tenant.uuid === selectedUuid.value)) {
          selectedUuid.value = tenants.value[0]?.uuid ?? null
        }
      } catch {
        tenants.value = []
        selectedUuid.value = null
      } finally {
        loaded.value = true
      }
    }

    function ensureLoaded(force = false): Promise<void> {
      if (loaded.value && !force) return Promise.resolve()
      inflight ??= load().finally(() => {
        inflight = null
      })
      return inflight
    }

    function reset(): void {
      tenants.value = []
      selectedUuid.value = null
      loaded.value = false
      inflight = null
    }

    return { selectedUuid, tenants, loaded, select, clearSelection, ensureLoaded, reset }
  },
  tenantStoreOptions,
)
