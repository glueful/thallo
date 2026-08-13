<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { open, useVisibleNav } from '../navigation/sidebar'
import CapabilityErrorPanel from '@/components/CapabilityErrorPanel.vue'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { useContentTypes } from '@/queries/contentTypes'
import { useUnreadCount } from '@/queries/formSubmissions'
import { useTenantStore } from '@/stores/tenant'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import { useTenancyAccessLifecycle } from '@/composables/useTenancyAccessLifecycle'
import { useTenancyEnablement } from '@/queries/tenancyEnablement'
import { inferTenancyEnabledForNavigation, shapeTenancyNav } from '@/navigation/shapeTenancyNav'

// Menus are DECLARED, not registered: the sidebar reads the static manifest
// (src/registry/manifest.ts) through useVisibleNav(), so structure exists before first
// render and per-item visibility is the only dynamic axis.
const caps = useCapabilitiesStore()
void caps.ensureLoaded() // post-auth: this layout only renders for authenticated users
const route = useRoute()

// Shared capability boundary: when the routed page declares `meta.requiresCapability`
// and discovery ERRORED, render ONE Retry panel instead of the page — the guard lets
// such navigations resolve (unknown must never become "disabled"/redirect), and this
// boundary is the single error surface so capability pages don't each implement one.
const capabilityBlocked = computed(
  () => route.meta.requiresCapability !== undefined && caps.status === 'error',
)
const retryingCaps = ref(false)
async function retryCaps(): Promise<void> {
  retryingCaps.value = true
  try {
    await caps.retry()
  } finally {
    retryingCaps.value = false
  }
}
const tenant = useTenantStore()
const tenancyAccess = useTenancyAccessStore()
useTenancyAccessLifecycle()

// Converge an open tab on server-side pack enable/disable without a manual reload:
// re-fetch capabilities whenever the window regains focus (the toggle usually happens in a
// terminal — alt-tabbing back is the natural "is it gone yet?" moment). Throttled so focus
// flapping doesn't spam the endpoint; the nav is a computed over the store, so a changed
// set re-renders the sidebar immediately.
let lastCapsRefresh = 0
function refreshCapsOnFocus(): void {
  if (document.visibilityState === 'hidden') return
  const now = Date.now()
  if (now - lastCapsRefresh < 5_000) return
  lastCapsRefresh = now
  void caps.refresh()
}
onMounted(() => {
  window.addEventListener('focus', refreshCapsOnFocus)
  document.addEventListener('visibilitychange', refreshCapsOnFocus)
})
onBeforeUnmount(() => {
  window.removeEventListener('focus', refreshCapsOnFocus)
  document.removeEventListener('visibilitychange', refreshCapsOnFocus)
})

const nav = useVisibleNav()
const { data: contentTypes } = useContentTypes()
// The authoritative "tenancy is switched on" signal — distinct from the `thallo.tenancy`
// capability (which only means the pack is installed). /tenancy/status is operator-guarded, so
// the fetch is gated on manage_platform; owners never need it because their domain/member access
// only resolves when tenancy is already on.
const { data: enablementStatus } = useTenancyEnablement(() => tenancyAccess.access.manage_platform)
const tenancyEnabled = computed(() =>
  inferTenancyEnabledForNavigation(
    enablementStatus.value?.enabled ?? false,
    tenant.selectedUuid,
    tenancyAccess.access,
  ),
)
// Live unread count for the Submissions badge (module registration is non-reactive, so
// the badge is injected here — the same seam the Content children use).
const { data: unreadSubmissions } = useUnreadCount()

// nav.value[0] = main nav; inject live content types into the Content section's children,
// and the live unread count as the Submissions badge (both unchanged for other items).
const mainItems = computed(() => {
  const enriched = nav.value[0].map((item) => {
    if (item.label === 'Content') {
      return {
        ...item,
        children: (contentTypes.value ?? []).map((ct) => ({
          label: ct.name ?? ct.slug ?? 'Untitled',
          icon: 'i-lucide-file-text',
          to: `/content/${ct.slug}`,
        })),
      }
    }
    if (item.label === 'Submissions') {
      const count = unreadSubmissions.value ?? 0
      return count > 0 ? { ...item, badge: String(count) } : item
    }
    return item
  })
  return shapeTenancyNav(
    enriched,
    tenancyAccess.access,
    tenant.selectedUuid,
    // Presentation hint (isVisible, not isEnabled): this only shapes the sidebar; the
    // tenancy routes themselves stay behind verified capability + server authorization.
    caps.isVisible('thallo.tenancy'),
    tenancyEnabled.value,
  )
})
const utilityItems = computed(() => nav.value[1])
</script>

<template>
  <UDashboardGroup unit="rem" storage="local" data-print-root>
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      collapsible
      :min-size="16"
      :default-size="16"
      :max-size="16"
      data-print-chrome
      class="bg-elevated/25 border-r-0"
      :ui="{
        footer: 'lg:border-t lg:border-default',
        header: 'h-auto min-h-(--ui-header-height) flex-col items-stretch gap-3 py-3',
      }"
    >
      <template #header="{ collapsed }">
        <div class="flex items-center gap-1.5">
          <AppLogo v-if="!collapsed" class="h-10 w-auto shrink-0" :show-text="true" />
          <UDashboardSidebarCollapse :class="collapsed ? 'mx-auto' : 'ms-auto'" />
        </div>

        <TenantSwitcher :collapsed="collapsed" />
      </template>

      <template #default="{ collapsed }">
        <!-- No skeleton: the STATIC manifest renders core items on the first frame, and
             pack-gated items resolve from the persisted last-known capability snapshot
             (isVisible) — so a returning session paints the complete, correct nav
             immediately. Only a genuinely first-ever session sees gated items arrive
             once, when discovery lands. -->
        <UNavigationMenu
          :collapsed="collapsed"
          :items="mainItems"
          orientation="vertical"
          tooltip
          popover
          :ui="{ link: 'my-1.5' }"
        />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="utilityItems"
          orientation="vertical"
          tooltip
          class="mt-auto"
          :ui="{ link: 'my-1.5' }"
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>
    <div
      data-print-shell
      class="flex-1 flex flex-col min-w-0 min-h-0 overflow-hidden bg-white rounded-2xl m-3 ring ring-default dark:bg-default"
    >
      <CapabilityErrorPanel v-if="capabilityBlocked" :retrying="retryingCaps" @retry="retryCaps" />
      <RouterView v-else />
    </div>
  </UDashboardGroup>
</template>
