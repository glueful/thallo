<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { open, useVisibleNav } from '../navigation/sidebar'
import { registerCoreModule } from '@/registry/coreModule'
import { registerCollectionsModule } from '@/registry/collectionsModule'
import { registerAnalyticsModule } from '@/registry/analyticsModule'
import { registerWorkflowModule } from '@/registry/workflowModule'
import { registerNavigationModule } from '@/registry/navigationModule'
import { registerTemplatesModule } from '@/registry/templatesModule'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { useContentTypes } from '@/queries/contentTypes'

registerCoreModule()
registerCollectionsModule()
registerAnalyticsModule()
registerWorkflowModule()
registerNavigationModule()
registerTemplatesModule()
const caps = useCapabilitiesStore()
caps.ensureLoaded() // post-auth: this layout only renders for authenticated users

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

// nav.value[0] = main nav; inject live content types into the Content section's children (unchanged behavior).
const mainItems = computed(() =>
  nav.value[0].map((item) =>
    item.label === 'Content'
      ? {
          ...item,
          children: (contentTypes.value ?? []).map((ct) => ({
            label: ct.name ?? ct.slug ?? 'Untitled',
            icon: 'i-lucide-file-text',
            to: `/content/${ct.slug}`,
          })),
        }
      : item,
  ),
)
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
