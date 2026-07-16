import { computed, ref, type ComputedRef } from 'vue'
import type { NavigationMenuItem } from '@nuxt/ui'
import { visibleNav } from '@/registry/adminModules'
import { useCapabilitiesStore } from '@/stores/capabilities'

export const open = ref(false)

/**
 * The two-group sidebar nav ([main, utilities]) over the static manifest, filtered by the
 * capability store's PRESENTATION hint (`isVisible`: persisted last-known snapshot, replaced
 * by every verified answer) — a returning session paints the complete nav on the first frame.
 * Display-only: guards and feature pages act on `isEnabled` (verified) instead.
 */
export function useVisibleNav(): ComputedRef<[NavigationMenuItem[], NavigationMenuItem[]]> {
  const caps = useCapabilitiesStore()
  return computed(() => visibleNav((id) => caps.isVisible(id)))
}
