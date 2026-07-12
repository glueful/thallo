<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { open, useVisibleNav } from '../navigation/sidebar'
import { registerCoreModule } from '@/registry/coreModule'
import { registerCollectionsModule } from '@/registry/collectionsModule'
import { registerAnalyticsModule } from '@/registry/analyticsModule'
import { registerWorkflowModule } from '@/registry/workflowModule'
import { registerNavigationModule } from '@/registry/navigationModule'
import { registerRegionsModule } from '@/registry/regionsModule'
import { registerTemplatesModule } from '@/registry/templatesModule'
import { registerSubmissionsModule } from '@/registry/submissionsModule'
import { registerTenancyModule } from '@/registry/tenancyModule'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { useContentTypes } from '@/queries/contentTypes'
import { useUnreadCount } from '@/queries/formSubmissions'
import { useTenantStore } from '@/stores/tenant'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import { useTenancyAccessLifecycle } from '@/composables/useTenancyAccessLifecycle'
import { useTenancyEnablement } from '@/queries/tenancyEnablement'
import { shapeTenancyNav } from '@/navigation/shapeTenancyNav'

registerCoreModule()
registerCollectionsModule()
registerAnalyticsModule()
registerWorkflowModule()
registerNavigationModule()
registerRegionsModule()
registerTemplatesModule()
registerSubmissionsModule()
registerTenancyModule()
const caps = useCapabilitiesStore()
caps.ensureLoaded() // post-auth: this layout only renders for authenticated users
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
const tenancyEnabled = computed(
  () =>
    (enablementStatus.value?.enabled ?? false) ||
    tenancyAccess.access.manage_domains ||
    tenancyAccess.access.manage_members ||
    tenancyAccess.access.manage_roles === true,
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
    caps.isEnabled('thallo.tenancy'),
    tenancyEnabled.value,
  )
})
const utilityItems = computed(() => nav.value[1])
</script>

<template>
  <UDashboardGroup unit="rem" storage="local">
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      collapsible
      :min-size="16"
      :default-size="16"
      :max-size="16"
      class="bg-elevated/25 border-r-0"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed }">
        <AppLogo v-if="!collapsed" class="w-auto h-10 shrink-0" :show-text="true" />
        <TenantSwitcher v-if="!collapsed" />
        <UDashboardSidebarCollapse :class="collapsed ? 'mx-auto' : 'ms-auto'" />
      </template>

      <template #default="{ collapsed }">
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
      class="flex-1 flex flex-col min-w-0 min-h-0 bg-white rounded-2xl m-3 ring ring-default dark:bg-default"
    >
      <RouterView />
    </div>
  </UDashboardGroup>
</template>
