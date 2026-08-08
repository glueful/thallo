<script setup lang="ts">
// Task 15a (admin-commerce-area plan, slice 3): Settings shell — started with only the completed
// Shipping zones tab. Task 15b added the Shipping classes tab; Task 15c adds the Tax rates tab
// below, completing phase P5 (no incomplete tabs, mirrors the nav's "append only once green"
// discipline).
import { computed, ref } from 'vue'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import StorePanel from './components/StorePanel.vue'
import StorePagesCard from './components/StorePagesCard.vue'
import ZonesPanel from './components/ZonesPanel.vue'
import ClassesPanel from './components/ClassesPanel.vue'
import TaxRatesPanel from './components/TaxRatesPanel.vue'
import EmailsPanel from './components/EmailsPanel.vue'
import MarketplacePanel from './components/MarketplacePanel.vue'

const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)

// Platform Payments settings moved to the app-owned `/settings/payments` page
// (platform-payments-settings spec, Task 7) — gateway credentials are no longer a
// commerce-scoped concern. The pointer below only reaches operators who actually hold the
// platform authority the destination page itself requires (`manage_platform`); anyone else
// would only hit a 403, so the link stays hidden rather than dangling.
const accessStore = useTenancyAccessStore()
const showPaymentsLink = computed(() => accessStore.access.manage_platform)

// Store leads (store-settings spec §3.5): the runtime store configuration is the tab merchants
// reach for first; the shipping/tax CRUD tabs follow.
const tab = ref('store')
const tabItems = [
  { label: 'Store', value: 'store' },
  { label: 'Emails', value: 'emails' },
  { label: 'Marketplace', value: 'marketplace' },
  { label: 'Shipping zones', value: 'zones' },
  { label: 'Shipping classes', value: 'classes' },
  { label: 'Tax rates', value: 'rates' },
]
</script>

<template>
  <UDashboardPanel id="commerce-settings">
    <template #header>
      <UDashboardNavbar title="Settings" />
    </template>

    <template #body>
      <p
        v-if="showPaymentsLink"
        class="mb-4 flex items-center gap-2 rounded-md border border-default px-3 py-2 text-sm text-muted"
        data-test="commerce-payments-moved-link"
      >
        <UIcon name="i-lucide-credit-card" class="size-4" />
        <span>
          Payment gateway settings moved to
          <RouterLink to="/settings/payments" class="font-medium text-default hover:underline">
            Settings › Payments</RouterLink
          >.
        </span>
      </p>

      <UTabs v-model="tab" variant="link" :items="tabItems" :content="false" class="mb-4" data-test="settings-tabs" />

      <template v-if="tab === 'store'">
        <StorePagesCard />
        <StorePanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'emails'">
        <EmailsPanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'marketplace'">
        <MarketplacePanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'zones'">
        <ZonesPanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'classes'">
        <ClassesPanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'rates'">
        <TaxRatesPanel :can-manage="canManage" />
      </template>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
