import { onBeforeUnmount, watch } from 'vue'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import { useTenantStore } from '@/stores/tenant'

export function useTenancyAccessLifecycle(): void {
  const tenant = useTenantStore()
  const access = useTenancyAccessStore()
  let initialized = false

  const stop = watch(
    () => [tenant.selectedUuid, tenant.operatorMode] as const,
    () => {
      if (!initialized) return
      access.reset()
      void access.refresh()
    },
  )

  void tenant.ensureLoaded().then(async () => {
    initialized = true
    await access.refresh()
  })

  onBeforeUnmount(stop)
}
